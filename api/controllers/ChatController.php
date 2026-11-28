<?php
/**
 * Hublog 即时通讯控制器
 * 提供会话列表、消息历史、发送消息、已读标记、轮询新消息等 RESTful 接口
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Auth;
use Hublog\Core\Validator;
use Hublog\Core\ChatService;
use Hublog\Core\ChatRepository;
use Hublog\Core\Helper;
use Hublog\Core\Cache;
use Hublog\Core\Upload;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;

class ChatController extends BaseController
{
    /**
     * GET /chat/conversations — 当前用户会话列表
     */
    public function conversations(Request $request): Response
    {
        $user = Auth::check($request);
        ['page' => $page, 'limit' => $limit] = $request->pagination(20);
        $result = ChatService::getConversations($user['id'], $page, $limit);
        return Response::success($result);
    }

    /**
     * DELETE /chat/conversation — 删除（隐藏）自己的会话
     *
     * 只把当前用户在 conversation_members 上的记录标 deleted_at，
     * 不影响对方，也不删消息；下次收到新消息时可再恢复。
     */
    public function deleteConversation(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = $request->getInt('conversation_id');
        if (!$conversationId) {
            return Response::error('conversation_id 不能为空', 400);
        }

        // 必须是自己的会话
        $member = ChatRepository::findMemberId($conversationId, (int)$user['id']);
        if (!$member) {
            return Response::error('会话不存在或无权操作', 404);
        }

        // hard=1：真删数据库记录（用户明确要求「不是只从列表里消失」）
        $hard = ((string)$request->get('hard', '0')) === '1'
            || $request->get('hard') === true
            || $request->get('hard') === 1;

        if ($hard) {
            // V99：软删能力不齐时直接 501，绝不退回物理删成员行
            if (!ChatService::columnExists('conversation_members', 'deleted_at')) {
                return Response::error('服务端未升级到 V48/V52，暂不支持彻底删除会话', 501);
            }

            // V52：彻底删除 = 软删除。消息行永久保留（可恢复 / 可审计 / 可取证），
            //      只对当前用户不可见；对方的消息完全不受影响。
            $ip = $request->getIp();
            $ua = mb_substr((string)($request->getHeader('User-Agent') ?? ''), 0, 255);

            try {
                // 1. 自己发的消息打 self 标记（行保留 + 写删除审计快照）
                $res = ChatService::softDeleteOwnMessages($conversationId, (int)$user['id'], $ip, $ua);
                // 2. 成员行也只打标记 —— 回收站靠它把会话列出来，物理删了就找不回来
                ChatRepository::markMemberDeleted($conversationId, (int)$user['id'], Helper::now());
            } catch (HublogException $e) {
                return Response::error($e->getMessage(), $e->getCode() ?: 500);
            } catch (\Throwable $e) {
                return Response::error('删除失败，请重试', 500);
            }

            \Hublog\Core\ChatService::invalidateUnreadCache((int)$user['id']);
            return Response::success(
                ['deleted_messages' => (int)($res['deleted_messages'] ?? 0)],
                '已删除，可在「最近删除」中恢复'
            );
        }

        if (!ChatService::columnExists('conversation_members', 'deleted_at')) {
            return Response::error('服务端未升级到 V48，暂不支持删除会话', 501);
        }

        try {
            ChatRepository::markMemberDeleted($conversationId, (int)$user['id'], Helper::now());
        } catch (\Throwable $e) {
            return Response::error('删除失败，请重试', 500);
        }

        \Hublog\Core\ChatService::invalidateUnreadCache((int)$user['id']);
        return Response::success(null, '已删除会话');
    }

    /**
     * GET /chat/deleted — 回收站：我删掉的会话列表
     */
    public function deletedConversations(Request $request): Response
    {
        $user = Auth::check($request);
        ['page' => $page, 'limit' => $limit] = $request->pagination(20);
        $result = ChatService::recycleBin((int)$user['id'], $page, $limit);
        return Response::success($result);
    }

    /**
     * POST /chat/restore — 恢复回收站里的会话（会话重新出现，自己删掉的消息也回来）
     */
    public function restoreConversation(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = $request->getInt('conversation_id');
        if (!$conversationId) {
            return Response::error('conversation_id 不能为空', 400);
        }

        $member = ChatRepository::findMemberId($conversationId, (int)$user['id']);
        if (!$member) {
            return Response::error('会话不存在或无权操作', 404);
        }

        $ip = $request->getIp();
        $ua = mb_substr((string)($request->getHeader('User-Agent') ?? ''), 0, 255);

        try {
            $res = ChatService::restoreOwnMessages($conversationId, (int)$user['id'], $ip, $ua);
            ChatRepository::clearMemberDeleted($conversationId, (int)$user['id']);
        } catch (\Throwable $e) {
            return Response::error('恢复失败，请重试', 500);
        }

        \Hublog\Core\ChatService::invalidateUnreadCache((int)$user['id']);
        return Response::success(
            ['restored_messages' => (int)($res['restored_messages'] ?? 0)],
            '已恢复'
        );
    }

    /**
     * GET /chat/messages — 会话消息历史（分页）
     */
    public function messages(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = $request->getInt('conversation_id');
        if (!$conversationId) {
            return Response::error('缺少会话ID', 400);
        }
        ['page' => $page, 'limit' => $limit] = $request->pagination(30);
        // P1-4：可选游标参数 before_id（不传走原页码分页，兼容老客户端）
        $beforeId = $request->getInt('before_id', 0);
        $result = ChatService::getMessages($conversationId, $user['id'], $page, $limit, $beforeId);
        return Response::success($result);
    }

    /**
     * GET /chat/new-messages — 轮询获取新消息
     */
    public function newMessages(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = $request->getInt('conversation_id');
        $lastMessageId = $request->getInt('last_message_id', 0);
        if (!$conversationId) {
            return Response::error('缺少会话ID', 400);
        }
        $messages = ChatService::getNewMessages($conversationId, $user['id'], $lastMessageId);
        // 自动标记已读到最后一条消息
        if (!empty($messages)) {
            $lastId = end($messages)['id'];
            ChatService::markAsRead($conversationId, $user['id'], $lastId);
        }
        return Response::success(['list' => $messages]);
    }

    /**
     * POST /chat/send — 发送消息（支持文本、图片、视频、文件）
     * 图片自动压缩、视频自动转码（FFmpeg 可用时）
     */
    public function send(Request $request): Response
    {
        $user = Auth::check($request);

        $validator = new Validator($request->all());
        $validator->required('conversation_id')->int('conversation_id');
        $data = $validator->validate();

        $conversationId = (int)$data['conversation_id'];
        $content = $request->getString('content');
        $type = $request->getString('type', 'text');

        // 未授权时按每日配额限量试用（0 点重置），配额用完才拦，给出的文案带具体数字
        $quota = \Hublog\Core\License::checkQuota('chat', (int)$user['id']);
        if (!$quota['ok']) {
            return Response::error($quota['message'], 402);
        }

        $allowedTypes = ['text', 'image', 'video', 'file', 'voice'];
        if (!in_array($type, $allowedTypes, true)) {
            return Response::error('不支持的消息类型', 400);
        }

        // 上传落盘前先校验会话归属：后端 sendMessage() 里的校验在流程末尾，
        // 非成员持任意 conversation_id 反复 POST 会在返回 403 前把文件写进磁盘/uploads 表。
        if (!\Hublog\Core\ChatService::validateMembership($conversationId, (int)$user['id'])) {
            return Response::error('无权在此会话发送消息', 403);
        }

        // ====== V71：全站禁言 + 私信敏感词过滤 ======
        // 禁言对本会话（私聊/群聊）一视同仁；敏感词只过文本消息，图片/语音/文件不过。
        try {
            Moderation::assertNotMuted($user);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        }

        // ====== V58：P1-4 —— 陌生人私信拦截 ======
        // 仅私聊生效（群聊属于圈子内部通信）；对方关闭「允许陌生人私信」且自己不是其粉丝时拦下。
        $conv = ChatRepository::findConversationForSend((int)$user['id'], $conversationId);

        // 群内禁言：到期前不能发言（群主/管理员不在禁言范围）
        if ($conv && $conv['type'] === 'group' && !empty($conv['my_muted_until'])
            && strtotime((string)$conv['my_muted_until']) > time()) {
            $leftMin = (int)ceil((strtotime((string)$conv['my_muted_until']) - time()) / 60);
            return Response::error("你已被群禁言，剩余 {$leftMin} 分钟", 403);
        }

        if ($conv && $conv['type'] === 'private' && (int)$conv['peer_id'] > 0) {
            $peerId = (int)$conv['peer_id'];
            if ($peerId !== (int)$user['id']) {
                $allowStranger = ($conv['peer_allow_stranger'] ?? 1) === null || (int)$conv['peer_allow_stranger'] === 1;
                if (!$allowStranger && !(int)$conv['is_fans']) {
                    return Response::error('对方开启了隐私设置，暂时无法给 TA 发私信', 403);
                }
            }
        }

        $extra = null;

        // 读取后台配置的私信上传限制
        $maxImageSize = (int)(\Hublog\Core\License::getSetting('chat_max_image_size', '10485760') ?: 10485760);
        $maxVideoSize = (int)(\Hublog\Core\License::getSetting('chat_max_video_size', '52428800') ?: 52428800);
        $imageWidth = (int)(\Hublog\Core\License::getSetting('chat_max_image_width', '1600') ?: 1600);
        $videoCompress = (int)(\Hublog\Core\License::getSetting('chat_video_compress', '1') ?: 1);

        // 图片消息：加强压缩
        if ($type === 'image') {
            $file = $request->getFile('image');
            if (!$file) {
                return Response::error('请选择图片', 400);
            }
            if (($file['size'] ?? 0) > $maxImageSize) {
                return Response::error('图片超过最大限制 ' . round($maxImageSize / 1024 / 1024, 1) . 'MB', 400);
            }
            // 使用较小的最大尺寸加强压缩：1600px（后台可配），强制压缩
            // P2-11：image() 返回 [url, file_hash]，取第 0 位
            [$url] = Upload::image($file, \UPLOAD_IMAGE_DIR, $imageWidth, $imageWidth, true, $maxImageSize);
            $extra = ['url' => $url];
            $content = '';
        } elseif ($type === 'video') {
            $file = $request->getFile('video');
            if (!$file) {
                return Response::error('请选择视频', 400);
            }
            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v', '3gp', '3g2', 'mpg', 'mpeg'], true)) {
                return Response::error('不支持的视频格式', 400);
            }

            $tmpPath = $file['tmp_name'];
            $finalTmp = $tmpPath;

            // V94：视频压缩已移出请求热路径。
            // 旧实现在这里同步跑 ffmpeg（最坏 30 分钟占住 worker），
            // 私信发视频要干等到超时。现在改为：视频原样落盘后入队，
            // 由 cron worker 异步压缩并原地替换（见 JobQueue）。
            $needCompress = ($videoCompress === 1) && $file['size'] > 500 * 1024;

            if ($file['size'] > $maxVideoSize) {
                if ($finalTmp !== $tmpPath && file_exists($finalTmp) && $finalTmp !== $tmpPath) {
                    @unlink($finalTmp);
                }
                return Response::error('视频超过最大限制 ' . round($maxVideoSize / 1024 / 1024, 1) . 'MB' . ($videoCompress ? '（建议开启视频压缩）' : ''), 400);
            }

            // Upload::file() 返回 [url, hash]，必须解构取第 0 位
            [$url] = Upload::file($file, defined('UPLOAD_VIDEO_DIR') ? \UPLOAD_VIDEO_DIR : 'uploads/videos', $maxVideoSize, null, ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v', '3gp', '3g2', 'mpg', 'mpeg'], 'video');

            // 异步压缩：登记任务，不阻塞本次响应
            if ($needCompress) {
                $physicalPath = \Hublog\Core\PathResolver::basePath() . parse_url($url, PHP_URL_PATH);
                if (file_exists($physicalPath)) {
                    try {
                        $jobId = \Hublog\Core\JobQueue::enqueue(
                            \Hublog\Core\JobQueue::TYPE_COMPRESS_VIDEO,
                            [
                                'source_path' => $physicalPath,
                                'target_path' => $physicalPath,
                            ],
                            'compress_video:' . $physicalPath
                        );
                        error_log('[Hublog ChatController] 已登记视频压缩任务 job_id=' . $jobId); // @allow-error-log
                    } catch (\Throwable $e) {
                        // 入队失败只影响压缩，不影响发送
                        error_log('[Hublog ChatController] 压缩任务入队失败: ' . $e->getMessage()); // @allow-error-log
                    }
                }
            }

            $extra = ['url' => $url];
            $content = '';
        } elseif ($type === 'file') {
            $file = $request->getFile('file');
            if (!$file) {
                return Response::error('请选择文件', 400);
            }
            // S2 修复：原调用漏传 $allowedExts，Upload::file() 会整段跳过扩展名校验，
            // 导致 .html/.svg 能落到同源的 uploads/attachments/ 形成存储型 XSS。
            // 附件 + 常见图片/音频；危险后缀由 Upload::FORBIDDEN_EXTS 无条件拦截。
            $fileExts = array_merge(Upload::defaultAllowedExts(), [
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic',
                'mp3', 'm4a', 'aac', 'wav', 'amr', 'ogg', 'opus',
            ]);
            [$url] = Upload::file($file, \UPLOAD_ATTACHMENT_DIR, $maxImageSize, null, $fileExts);
            $extra = ['url' => $url];
            $content = '';
        } elseif ($type === 'voice') {
            // 语音消息：字段名为 voice，时长（秒）由客户端随表单一起提交到 extra.duration
            $file = $request->getFile('voice');
            if (!$file) {
                return Response::error('请选择语音文件', 400);
            }
            $maxVoiceSize = (int)(\Hublog\Core\License::getSetting('chat_max_voice_size', '2097152') ?: 2097152);
            if (($file['size'] ?? 0) > $maxVoiceSize) {
                return Response::error('语音超过最大限制 ' . round($maxVoiceSize / 1024 / 1024, 1) . 'MB', 400);
            }
            [$url] = Upload::file(
                $file,
                defined('UPLOAD_ATTACHMENT_DIR') ? \UPLOAD_ATTACHMENT_DIR : 'uploads/attachments',
                $maxVoiceSize,
                null,
                ['mp3', 'm4a', 'aac', 'wav', 'amr', 'ogg', 'opus', '3gp'],
                'voice'
            );
            $duration = (int)$request->getFloat('duration');
            if ($duration < 1) {
                $duration = 1;
            }
            if ($duration > 600) {
                $duration = 600;
            }
            $extra = ['url' => $url, 'duration' => $duration];
            $content = '';
        } elseif ($type === 'text') {
            $content = trim($content);
            if ($content === '') {
                return Response::error('消息内容不能为空', 400);
            }
        }

        if ($type === 'text' && $content !== '') {
            $fMsg = Moderation::filter($content, 'chat', (int)$user['id'], $conversationId);
            if (!$fMsg['ok']) {
                return Response::error($fMsg['msg'], 403);
            }
            $content = $fMsg['text'];
        }

        // V98：客户端幂等键。Request::getString() 同时覆盖 app.request 的 JSON body
        // 与图片/视频 FormData 提交（见 Request::parseInput()），三条发送链路都能取到。
        $clientMsgId = trim($request->getString('client_msg_id', ''));
        if (strlen($clientMsgId) > 64) {
            return Response::error('幂等键 client_msg_id 不能超过 64 字符', 400);
        }

        $message = ChatService::sendMessage($conversationId, $user['id'], $content, $type, $extra, $clientMsgId);
        return Response::success($message, '发送成功');
    }

    /**
     * PUT /chat/read — 标记会话消息已读
     */
    public function markRead(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = $request->getInt('conversation_id');
        $messageId = $request->getInt('message_id');
        if (!$conversationId || !$messageId) {
            return Response::error('参数错误', 400);
        }
        // S10：原实现只校验登录、不校验会话归属，任意登录用户可把他人会话
        // 的消息标为已读（篡改 conversation_members 与 notifications）。
        if (!ChatService::validateMembership($conversationId, (int)$user['id'])) {
            return Response::error('无权操作该会话', 403);
        }
        // S10+：成员校验之外还需校验 message_id 归属本会话。否则会话成员传一个
        // 别的会话的大 id（或任意超大 id）即可把本会话 last_read_message_id 顶到
        // 高位，把本会话全部未读一次性清零（含自己尚未读到的消息）。
        if (!ChatRepository::messageBelongsToConversation($messageId, $conversationId)) {
            return Response::error('消息不属于该会话', 400);
        }
        ChatService::markAsRead($conversationId, $user['id'], $messageId);

        // 单条已读回执：聊天页要逐条显示「已读 / 未读」
        // 只更新对方发给我的、且 id <= 已读位点的消息
        ChatRepository::tryMarkIncomingMessagesRead($conversationId, (int)$user['id'], $messageId);

        return Response::success(null, '已标记已读');
    }

    /**
     * POST /api/chat/typing —— 广播「正在输入」状态
     *
     * 只走 SSE 实时通道，不落库、不触发离线推送（PushService 对未知事件直接忽略）。
     * 前端节流调用（建议 3 秒一次，停止输入即视为结束）。
     */
    public function typing(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = (int)$request->getInt('conversation_id');
        if (!$conversationId) {
            return Response::error('参数错误', 400);
        }

        if (!\Hublog\Core\ChatService::validateMembership($conversationId, (int)$user['id'])) {
            return Response::error('无权访问该会话', 403);
        }

        // S5：服务端 3 秒节流，防止单个成员高频广播刷屏（锁内读-写原子）
        $throttleKey = "chat_typing_{$conversationId}_{$user['id']}";
        $allowed = Cache::withLock('lock_' . $throttleKey, static function () use ($throttleKey): bool {
            if (Cache::has($throttleKey)) {
                return false;
            }
            Cache::set($throttleKey, 1, 3);
            return true;
        });
        if (!$allowed) {
            return Response::error('正在输入状态发送过于频繁', 429);
        }

        // 广播给会话中除自己以外的成员
        $others = ChatRepository::fetchOtherMemberUserIds($conversationId, (int)$user['id']);
        foreach ($others as $m) {
            \Hublog\Core\SsePush::push((int)$m['user_id'], 'typing', [
                'conversation_id' => $conversationId,
                'user_id'         => (int)$user['id'],
                // SSE data 里的展示字段先做 HTML 实体编码，避免客户端拼 innerHTML 时形成 XSS
                'nickname'        => \Hublog\Core\Security::escape((string)($user['nickname'] ?? '')),
            ]);
        }

        return Response::success(null);
    }

    /**
     * DELETE /api/chat/message —— 撤回自己的消息
     *
     * 与「删除」区分：撤回是软删除（写 recalled_at），对方仍能看到
     * 「xx 撤回了一条消息」，符合主流 IM 行为；超过时间窗则不允许撤回。
     */
    public function recallMessage(Request $request): Response
    {
        $user = Auth::check($request);
        $messageId = (int)$request->getInt('message_id');
        if (!$messageId) {
            return Response::error('参数错误', 400);
        }

        $msg = ChatRepository::findMessageForRecall($messageId);
        if (!$msg) {
            return Response::error('消息不存在', 404);
        }
        if ((int)$msg['sender_id'] !== (int)$user['id']) {
            return Response::error('只能撤回自己发送的消息', 403);
        }
        if (!empty($msg['recalled_at'])) {
            return Response::success(null, '消息已撤回');
        }

        // 撤回窗口：2 分钟（与微信一致）。超时后只能删除不能撤回
        $recallWindow = 120;
        $createdTs = strtotime((string)$msg['created_at']);
        if ($createdTs && time() - $createdTs > $recallWindow) {
            return Response::error('发送超过 2 分钟的消息不能撤回', 400);
        }

        try {
            ChatRepository::recallMessage($messageId, Helper::now());
        } catch (\Throwable $e) {
            return Response::error('撤回失败，请重试', 500);
        }

        \Hublog\Core\ChatService::invalidateUnreadCache((int)$user['id']);
        return Response::success(null, '已撤回');
    }

    /**
     * GET /chat/unread-count — 总未读消息数
     */
    public function unreadCount(Request $request): Response
    {
        $user = Auth::check($request);
        $count = ChatService::getTotalUnread($user['id']);
        return Response::success(['count' => $count]);
    }

    /**
     * POST /chat/private — 创建或获取与指定用户的私聊会话
     */
    public function createPrivate(Request $request): Response
    {
        $user = Auth::check($request);

        $validator = new Validator($request->all());
        $validator->required('user_id')->int('user_id');
        $data = $validator->validate();

        $otherUserId = (int)$data['user_id'];
        // 未授权时限制可拥有的会话总数（新建会话才检查，已有会话继续聊不受影响）
        $convQuota = \Hublog\Core\License::checkQuota('conversation', (int)$user['id']);
        if (!$convQuota['ok']) {
            return Response::error($convQuota['message'], 402);
        }
        $result = ChatService::getOrCreatePrivateConversation($user['id'], $otherUserId);

        return Response::success([
            'conversation_id' => $result['id'],
            'created' => $result['created']
        ], $result['created'] ? '会话已创建' : '会话已存在');
    }

    /**
     * PUT /chat/settings — 切换当前用户在某会话中的免打扰/置顶设置
     */
    public function updateMemberSettings(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = $request->getInt('conversation_id');
        if (!$conversationId) {
            return Response::error('缺少会话ID', 400);
        }

        $member = ChatRepository::findMemberAllByConversationAndUser($conversationId, (int)$user['id']);
        if (!$member) {
            return Response::error('会话不存在', 404);
        }

        $updates = [];
        if ($request->has('muted')) {
            $updates['muted'] = $request->getInt('muted', 0) ? 1 : 0;
        }
        if ($request->has('pinned')) {
            // 兼容老库无 pinned 列
            try {
                $hasPinned = ChatRepository::pinnedColumnExists();
            } catch (\Throwable $e) {
                $hasPinned = false;
            }
            if ($hasPinned) {
                $updates['pinned'] = $request->getInt('pinned', 0) ? 1 : 0;
            } else {
                return Response::error('当前数据库版本不支持置顶，请刷新页面后重试', 500);
            }
        }
        if (empty($updates)) {
            return Response::error('没有需要更新的字段', 400);
        }
        try {
            ChatRepository::updateMemberSettings((int)$member['id'], $updates);
        } catch (\Throwable $e) {
            // 兼容老数据库没有 pinned/muted 字段的情况；SQL 细节只进日志，不回显给客户端
            \Hublog\Core\Logger::error('[Hublog Chat updateMemberSettings] ' . $e->getMessage());
            $detail = (defined('APP_DEBUG') && APP_DEBUG) ? (': ' . $e->getMessage()) : '';
            return Response::error('设置更新失败，请稍后重试' . $detail, 500);
        }

        $fresh = ChatRepository::findMemberAllById((int)$member['id']);
        return Response::success([
            'muted'   => (int)($fresh['muted'] ?? 0),
            'pinned'  => (int)($fresh['pinned'] ?? 0),
        ], '已更新');
    }

    // ===================== V58 / P1-3：圈子群聊（成员 / 公告 / 禁言 / 踢人 / 解散） =====================

    /**
     * 当前用户在某群的 role（owner/admin/member）；非成员返回 null
     */
    private function groupRole(int $conversationId, int $userId): ?string
    {
        // V72：JOIN conversations 校验 type='group'——私聊成员同样写在
        // conversation_members（role='member'），原先只查成员表会把私聊当群，
        // 前端探测 group/info 时误判 isGroup，还能对私聊发起禁言/踢人等越权操作
        return ChatRepository::findGroupRole($conversationId, $userId);
    }

    /**
     * GET /chat/group/members — 群成员列表（含禁言到期时间）
     */
    public function groupMembers(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = (int)$request->getInt('conversation_id');
        if (!$conversationId) {
            return Response::error('缺少会话ID', 400);
        }
        if (!$this->groupRole($conversationId, (int)$user['id'])) {
            return Response::error('你不是该群成员', 403);
        }

        $members = ChatRepository::fetchGroupMembers($conversationId);
        return Response::success($members);
    }

    /**
     * GET /chat/group/info — 群信息（群名/公告/成员数/我的角色），群聊页用
     */
    public function groupInfo(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = (int)$request->getInt('conversation_id');
        if (!$conversationId) {
            return Response::error('缺少会话ID', 400);
        }
        $role = $this->groupRole($conversationId, (int)$user['id']);
        if (!$role) {
            return Response::error('你不是该群成员', 403);
        }

        $conv = ChatRepository::findGroupInfo($conversationId);
        if (!$conv) {
            return Response::error('会话不存在', 404);
        }
        // V146：圈子群聊的群头像 = 圈子当前封面（建群时 conversations.avatar 写的是空串，
        // 群设置页/群信息一直显示灰色默认头像，换封面也不跟着变）
        if (($conv['type'] ?? '') === 'group' && !empty($conv['circle_id']) && empty($conv['avatar'])) {
            $cover = ChatRepository::findCircleCover((int)$conv['circle_id']);
            if ($cover) {
                $conv['avatar'] = $cover;
            }
        }
        $myMuted = ChatRepository::findMemberMutedUntil($conversationId, (int)$user['id']);
        return Response::success(array_merge($conv, [
            'my_role'      => $role,
            'my_muted_until'=> $myMuted['muted_until'] ?? null,
        ]));
    }

    /**
     * PUT /chat/group/announce — 群公告（仅群主/管理员）
     */
    public function groupAnnounce(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = (int)$request->getInt('conversation_id');
        $content = mb_substr(trim((string)$request->getString('content')), 0, 1000);

        $role = $this->groupRole($conversationId, (int)$user['id']);
        if ($role !== 'owner' && $role !== 'admin') {
            return Response::error('仅群主或群管理员可以发布群公告', 403);
        }

        ChatRepository::updateAnnouncement($conversationId, $content !== '' ? $content : null, Helper::now());
        return Response::success(
            ['announcement' => $content, 'announcement_at' => Helper::now()],
            $content !== '' ? '群公告已发布' : '群公告已清空'
        );
    }

    /**
     * POST /chat/group/mute — 群内禁言（minutes=0 解禁）；最多 30 天
     */
    public function groupMute(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = (int)$request->getInt('conversation_id');
        $targetId = (int)$request->getInt('user_id');
        $minutes = max(0, min(43200, (int)$request->getInt('minutes', 60)));

        if (!$conversationId || !$targetId) {
            return Response::error('参数错误', 400);
        }
        $role = $this->groupRole($conversationId, (int)$user['id']);
        if ($role !== 'owner' && $role !== 'admin') {
            return Response::error('无权操作', 403);
        }
        $targetRole = $this->groupRole($conversationId, $targetId);
        if (!$targetRole) {
            return Response::error('该用户不在群里', 404);
        }
        if ($targetRole === 'owner') {
            return Response::error('不能禁言群主', 403);
        }
        if ($targetRole === 'admin' && $role !== 'owner') {
            return Response::error('不能禁言其他管理员，请联系群主', 403);
        }

        $mutedUntil = $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null;
        ChatRepository::muteMember($conversationId, $targetId, $mutedUntil);
        return Response::success(
            ['user_id' => $targetId, 'muted_until' => $mutedUntil],
            $minutes > 0 ? "已禁言 {$minutes} 分钟" : '已解除禁言'
        );
    }

    /**
     * POST /chat/group/kick — 移出群聊（仅群主/管理员）
     */
    public function groupKick(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = (int)$request->getInt('conversation_id');
        $targetId = (int)$request->getInt('user_id');
        if (!$conversationId || !$targetId) {
            return Response::error('参数错误', 400);
        }

        $role = $this->groupRole($conversationId, (int)$user['id']);
        if ($role !== 'owner' && $role !== 'admin') {
            return Response::error('无权操作', 403);
        }
        $targetRole = $this->groupRole($conversationId, $targetId);
        if (!$targetRole) {
            return Response::error('该用户不在群里', 404);
        }
        if ($targetRole === 'owner') {
            return Response::error('不能移出群主', 403);
        }
        if ($targetRole === 'admin' && $role !== 'owner') {
            return Response::error('不能移出其他管理员，请联系群主', 403);
        }

        $left = ChatRepository::kickMember($conversationId, $targetId);

        return Response::success(['left' => $left], '已移出群聊');
    }

    /**
     * POST /chat/group/dissolve — 解散群（仅群主；会一并清空群成员）
     */
    public function groupDissolve(Request $request): Response
    {
        $user = Auth::check($request);
        $conversationId = (int)$request->getInt('conversation_id');
        if (!$conversationId) {
            return Response::error('缺少会话ID', 400);
        }
        if ($this->groupRole($conversationId, (int)$user['id']) !== 'owner') {
            return Response::error('仅群主可以解散群聊', 403);
        }

        try {
            ChatService::dissolveGroupAsOwner(
                $conversationId,
                (int)$user['id'],
                $request->getIp(),
                mb_substr((string)($request->getHeader('User-Agent') ?? ''), 0, 255)
            );
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 500);
        }

        return Response::success(null, '群聊已解散');
    }
}
