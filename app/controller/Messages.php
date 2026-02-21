<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;
use app\service\CacheService;
use app\service\ChatLogService;

class Messages extends BaseFrontendController
{
    /**
     * 消息列表页面
     */
    public function index()
    {
        $userId = session('user_id') ?: cookie('user_id');
        $type = input('type', 'all');

        if (empty($userId)) {
            // 未登录时仍然显示页面,但currentUser为null
            $currentUser = null;
            $isLogin = false;
        } else {
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
            $isLogin = true;
        }

        View::assign([
            'currentUser' => $currentUser,
            'isLogin' => $isLogin,
            'current_url' => '/messages',
            'type' => $type
        ]);
        return View::fetch('index/message');
    }

    /**
     * 获取未读消息数量
     */
    public function getUnreadCount()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        
        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $messageUnreadCount = CacheService::getUnreadCount($userId);

            $notificationUnreadCount = Db::name('notifications')
                ->where('user_id', $userId)
                ->where('is_read', 0)
                ->count();

            $totalUnreadCount = $messageUnreadCount + $notificationUnreadCount;

            return $this->success(['unread_count' => $totalUnreadCount, 'message_unread_count' => $messageUnreadCount, 'notification_unread_count' => $notificationUnreadCount], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取消息列表
     */
    public function getMessageList()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        
        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $lastMessages = CacheService::getMessageList($userId);

            $chatList = [];
            $processedPairs = [];

            foreach ($lastMessages as $message) {
                $otherId = ($message['sender_id'] == $userId) ? $message['receiver_id'] : $message['sender_id'];
                $pairKey = min($userId, $otherId) . '_' . max($userId, $otherId);

                if (isset($processedPairs[$pairKey])) {
                    continue;
                }
                $processedPairs[$pairKey] = true;

                $user = CacheService::getUserInfo($otherId);

                $unreadCount = Db::name('messages')
                    ->where('sender_id', $otherId)
                    ->where('receiver_id', $userId)
                    ->where('is_read', 0)
                    ->count();

                $chatList[] = [
                    'user_id' => $otherId,
                    'nickname' => $user['nickname'] ?? '',
                    'avatar' => $user['avatar'] ?? '',
                    'last_message' => $message,
                    'unread_count' => $unreadCount
                ];
            }

            return $this->success(['list' => $chatList], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取聊天历史
     */
    public function getChatHistory()
    {
        $userId = session('user_id') ?: cookie('user_id');
        $otherUserId = input('other_user_id');
        $page = (int)input('page', 1);
        $pageSize = (int)input('page_size', 50);

        // 确保转换为整数
        $userId = (int)$userId;
        $otherUserId = (int)$otherUserId;

        if (empty($userId)) {
            return $this->unauthorized();
        }

        if (empty($otherUserId)) {
            return $this->badRequest('缺少参数');
        }

        try {
            $messages = CacheService::getChatHistory($userId, $otherUserId, $page, $pageSize);

            return $this->success(['list' => $messages], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取新消息
     */
    public function getNewMessages()
    {
        $userId = session('user_id') ?: cookie('user_id');
        $otherUserId = input('other_user_id');
        $lastMessageId = input('last_message_id', 0);
        $lastMessageTime = input('last_message_time', 0);

        if (empty($userId)) {
            return $this->unauthorized();
        }

        if (empty($otherUserId)) {
            return $this->badRequest('缺少参数');
        }

        try {
            $query = Db::name('messages')
                ->where(function($query) use ($userId, $otherUserId) {
                    $query->where('sender_id', $userId)->where('receiver_id', $otherUserId);
                })
                ->whereOr(function($query) use ($userId, $otherUserId) {
                    $query->where('sender_id', $otherUserId)->where('receiver_id', $userId);
                });

            // 使用 last_message_time 而不是 last_message_id 来过滤
            if ($lastMessageTime > 0) {
                $query = $query->where('create_time', '>', $lastMessageTime);
            } else if ($lastMessageId > 0) {
                // 兼容旧版本，使用 last_message_id
                $query = $query->where('id', '>', $lastMessageId);
            }

            $messages = $query
                ->order('create_time', 'asc')
                ->select()
                ->toArray();

            // 过滤掉撤回的消息（撤回消息不应该作为新消息返回）
            $messages = array_filter($messages, function($msg) {
                // 如果是撤回消息，但recall_time和create_time相差很大（超过5分钟），说明这是旧的撤回操作
                if ($msg['is_recalled'] == 1) {
                    return false; // 不返回撤回消息
                }
                return true;
            });

            return $this->success(['list' => array_values($messages)], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 发送消息
     */
    public function sendMessage()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));

        if (empty($userId)) {
            return $this->unauthorized();
        }

        // 获取POST数据
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data || !isset($data['receiver_id'])) {
            return $this->badRequest('无效的请求数据');
        }

        $receiverId = $data['receiver_id'];
        $content = $data['content'] ?? '';
        $messageType = $data['message_type'] ?? 1;
        $fileUrl = $data['file_url'] ?? '';
        $replyToId = $data['reply_to_id'] ?? 0;
        $fileName = $data['file_name'] ?? '';
        $fileSize = $data['file_size'] ?? 0;
        $voiceDuration = $data['voice_duration'] ?? 0;

        try {
            // 插入消息
            $messageData = [
                'sender_id' => $userId,
                'receiver_id' => $receiverId,
                'content' => $content,
                'message_type' => $messageType,
                'file_url' => $fileUrl,
                'reply_to_id' => $replyToId,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'voice_duration' => $voiceDuration,
                'is_read' => 0,
                'send_status' => 1,
                'send_time' => time(),
                'create_time' => time()
            ];

            $messageId = Db::name('messages')->insertGetId($messageData);

            // 记录聊天日志
            ChatLogService::logSendMessage($userId, $receiverId, $messageId, $messageData);

            CacheService::clearMessageList($userId);
            CacheService::clearMessageList($receiverId);
            CacheService::clearChatHistory($userId, $receiverId);
            CacheService::clearUnreadCount($receiverId);

            $sender = Db::name('user')->where('id', $userId)->find();

            return $this->success(['message_id' => $messageId, 'message' => array_merge($messageData, ['id' => $messageId, 'sender_nickname' => $sender['nickname'] ?? '', 'sender_avatar' => $sender['avatar'] ?? ''])], '发送成功');
        } catch (\Exception $e) {
            return $this->error('发送失败: ' . $e->getMessage());
        }
    }

    /**
     * 标记已读
     */
    public function markAsRead()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));

        // 支持 sender_id 和 user_id 两种参数名
        $senderId = (int)input('sender_id') ?: (int)input('user_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        if (empty($senderId)) {
            return $this->badRequest('用户ID不能为空');
        }

        try {
            Db::name('messages')
                ->where('sender_id', $senderId)
                ->where('receiver_id', $userId)
                ->where('is_read', 0)
                ->update([
                    'is_read' => 1,
                    'read_time' => time()
                ]);

            CacheService::clearMessageList($userId);
            CacheService::clearChatHistory($userId, $senderId);
            CacheService::clearUnreadCount($userId);

            return $this->success(null, '标记成功');
        } catch (\Exception $e) {
            return $this->error('标记失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除消息
     */
    public function deleteMessage()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $messageId = (int)input('message_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $message = Db::name('messages')->where('id', $messageId)->find();

            if (!$message) {
                return $this->notFound('消息不存在');
            }

            // 只能删除自己发送的消息
            if ($message['sender_id'] != $userId) {
                return $this->forbidden('无权限删除');
            }

            Db::name('messages')->where('id', $messageId)->delete();

            // 记录聊天日志
            ChatLogService::logDeleteMessage($userId, $messageId);

            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 撤回消息
     */
    public function recallMessage()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $messageId = (int)input('message_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $message = Db::name('messages')->where('id', $messageId)->find();

            if (!$message) {
                return $this->notFound('消息不存在');
            }

            // 只能撤回自己发送的消息
            if ($message['sender_id'] != $userId) {
                return $this->forbidden('无权限撤回');
            }

            // 检查是否在2分钟内
            if (time() - $message['create_time'] > 120) {
                return $this->badRequest('超过撤回时间限制(2分钟)');
            }

            Db::name('messages')->where('id', $messageId)->update([
                'is_recalled' => 1,
                'recall_time' => time()
            ]);

            // 记录聊天日志
            ChatLogService::logRecallMessage($userId, $messageId);

            CacheService::clearMessageList($userId);
            CacheService::clearMessageList($message['receiver_id']);
            CacheService::clearChatHistory($userId, $message['receiver_id']);

            return $this->success(null, '撤回成功');
        } catch (\Exception $e) {
            return $this->error('撤回失败: ' . $e->getMessage());
        }
    }

    public function getReadReceipt()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $messageId = (int)input('message_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $message = Db::name('messages')->where('id', $messageId)->find();

            if (!$message) {
                return $this->notFound('消息不存在');
            }

            if ($message['sender_id'] != $userId) {
                return $this->forbidden('无权限查看');
            }

            $receipt = [
                'is_read' => $message['is_read'],
                'read_time' => $message['read_time'] ?? null
            ];

            return $this->success($receipt, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    public function getBatchReadReceipts()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $messageIds = input('message_ids', []);

        if (empty($userId)) {
            return $this->unauthorized();
        }

        if (empty($messageIds) || !is_array($messageIds)) {
            return $this->badRequest('缺少参数');
        }

        try {
            $messages = Db::name('messages')
                ->whereIn('id', $messageIds)
                ->where('sender_id', $userId)
                ->field('id,is_read,read_time')
                ->select()
                ->toArray();

            $receipts = [];
            foreach ($messages as $msg) {
                $receipts[$msg['id']] = [
                    'is_read' => $msg['is_read'],
                    'read_time' => $msg['read_time'] ?? null
                ];
            }

            return $this->success($receipts, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    public function getReplyMessage()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $messageId = (int)input('message_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        if (empty($messageId)) {
            return $this->badRequest('缺少参数');
        }

        try {
            $message = Db::name('messages')->where('id', $messageId)->find();

            if (!$message) {
                return $this->notFound('消息不存在');
            }

            $sender = CacheService::getUserInfo($message['sender_id']);

            $replyMessage = [
                'id' => $message['id'],
                'content' => $message['content'],
                'sender_id' => $message['sender_id'],
                'sender_nickname' => $sender['nickname'] ?? '',
                'sender_avatar' => $sender['avatar'] ?? '',
                'message_type' => $message['message_type'],
                'file_url' => $message['file_url'] ?? '',
                'create_time' => $message['create_time']
            ];

            return $this->success($replyMessage, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    public function searchMessages()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $keyword = input('keyword');
        $otherUserId = (int)input('other_user_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        if (empty($keyword)) {
            return $this->badRequest('缺少搜索关键词');
        }

        try {
            $where = [
                ['content', 'like', "%{$keyword}%"]
            ];

            if ($otherUserId) {
                $where[] = function($query) use ($userId, $otherUserId) {
                    $query->where(function($q) use ($userId, $otherUserId) {
                        $q->where('sender_id', $userId)->where('receiver_id', $otherUserId);
                    })->whereOr(function($q) use ($userId, $otherUserId) {
                        $q->where('sender_id', $otherUserId)->where('receiver_id', $userId);
                    });
                };
            } else {
                $where[] = function($query) use ($userId) {
                    $query->where('sender_id', $userId)->whereOr('receiver_id', $userId);
                };
            }

            $messages = Db::name('messages')
                ->where($where)
                ->where('is_recalled', 0)
                ->order('create_time', 'desc')
                ->limit(100)
                ->select()
                ->toArray();

            return $this->success(['list' => $messages, 'total' => count($messages)], '搜索成功');
        } catch (\Exception $e) {
            return $this->error('搜索失败: ' . $e->getMessage());
        }
    }

    /**
     * 转发消息
     */
    public function forwardMessage()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $messageId = (int)input('message_id');
        $receiverIds = input('receiver_ids', []);

        if (empty($userId)) {
            return $this->unauthorized();
        }

        if (empty($messageId) || empty($receiverIds)) {
            return $this->badRequest('缺少必要参数');
        }

        try {
            $originalMessage = Db::name('messages')->where('id', $messageId)->find();

            if (!$originalMessage) {
                return $this->notFound('原消息不存在');
            }

            if (!is_array($receiverIds)) {
                $receiverIds = explode(',', $receiverIds);
            }

            $successCount = 0;
            foreach ($receiverIds as $receiverId) {
                $messageData = [
                    'sender_id' => $userId,
                    'receiver_id' => $receiverId,
                    'content' => $originalMessage['content'],
                    'message_type' => $originalMessage['message_type'],
                    'file_url' => $originalMessage['file_url'] ?? '',
                    'file_name' => $originalMessage['file_name'] ?? '',
                    'file_size' => $originalMessage['file_size'] ?? 0,
                    'voice_duration' => $originalMessage['voice_duration'] ?? 0,
                    'is_read' => 0,
                    'send_status' => 1,
                    'send_time' => time(),
                    'create_time' => time()
                ];

                Db::name('messages')->insert($messageData);
                $successCount++;
                
                // 记录聊天日志
                ChatLogService::logForwardMessage($userId, $receiverId, $messageId);
            }

            return $this->success(['success_count' => $successCount], '转发成功');
        } catch (\Exception $e) {
            return $this->error('转发失败: ' . $e->getMessage());
        }
    }

    /**
     * 置顶消息
     */
    public function pinMessage()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $messageId = (int)input('message_id');
        $isPinned = (int)input('is_pinned', 0);

        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $message = Db::name('messages')->where('id', $messageId)->find();

            if (!$message) {
                return $this->notFound('消息不存在');
            }

            // 只能置顶自己发送的消息
            if ($message['sender_id'] != $userId) {
                return $this->forbidden('无权限操作');
            }

            Db::name('messages')->where('id', $messageId)->update([
                'is_pinned' => $isPinned,
                'pin_time' => $isPinned ? time() : null
            ]);

            // 记录聊天日志
            ChatLogService::logPinMessage($userId, $messageId, (bool)$isPinned);

            return $this->success(null, $isPinned ? '置顶成功' : '取消置顶成功');
        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 收藏消息
     */
    public function favoriteMessage()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $messageId = (int)input('message_id');
        $action = input('action', 'add'); // add 或 remove

        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $message = Db::name('messages')->where('id', $messageId)->find();

            if (!$message) {
                return $this->notFound('消息不存在');
            }

            if ($action === 'add') {
                // 添加到收藏
                Db::name('favorites')->insert([
                    'user_id' => $userId,
                    'target_id' => $messageId,
                    'target_type' => 3, // 3 表示消息
                    'create_time' => time()
                ]);
                
                // 记录聊天日志
                ChatLogService::logFavoriteMessage($userId, $messageId);
            } else {
                // 取消收藏
                Db::name('favorites')
                    ->where('user_id', $userId)
                    ->where('target_id', $messageId)
                    ->where('target_type', 3)
                    ->delete();
            }

            return $this->success(null, $action === 'add' ? '收藏成功' : '取消收藏成功');
        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取收藏列表
     */
    public function getFavorites()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $page = (int)input('page', 1);
        $pageSize = (int)input('page_size', 20);

        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $favorites = Db::name('favorites f')
                ->alias('f')
                ->join('messages m', 'f.target_id = m.id')
                ->join('user u', 'm.sender_id = u.id')
                ->where('f.user_id', $userId)
                ->where('f.target_type', 3)
                ->field('m.*, u.nickname, u.avatar')
                ->order('f.create_time', 'desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            return $this->success(['list' => $favorites], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 屏蔽用户
     */
    public function blockUser()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $blockId = (int)input('block_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            // 检查是否已存在
            $exists = Db::name('blacklist')
                ->where('user_id', $userId)
                ->where('block_id', $blockId)
                ->find();

            if ($exists) {
                return $this->badRequest('已经屏蔽过该用户');
            }

            Db::name('blacklist')->insert([
                'user_id' => $userId,
                'block_id' => $blockId,
                'create_time' => time()
            ]);

            return $this->success(null, '屏蔽成功');
        } catch (\Exception $e) {
            return $this->error('屏蔽失败: ' . $e->getMessage());
        }
    }

    /**
     * 取消屏蔽
     */
    public function unblockUser()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $blockId = (int)input('block_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            Db::name('blacklist')
                ->where('user_id', $userId)
                ->where('block_id', $blockId)
                ->delete();

            return $this->success(null, '取消屏蔽成功');
        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 设置免打扰
     */
    public function setMute()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $otherUserId = (int)input('other_user_id');
        $isMute = (int)input('is_mute', 0);

        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            // 检查是否已存在设置
            $exists = Db::name('chat_settings')
                ->where('user_id', $userId)
                ->where('other_user_id', $otherUserId)
                ->find();

            if ($exists) {
                Db::name('chat_settings')
                    ->where('user_id', $userId)
                    ->where('other_user_id', $otherUserId)
                    ->update(['is_mute' => $isMute]);
            } else {
                Db::name('chat_settings')->insert([
                    'user_id' => $userId,
                    'other_user_id' => $otherUserId,
                    'is_mute' => $isMute,
                    'create_time' => time()
                ]);
            }

            return $this->success(null, '设置成功');
        } catch (\Exception $e) {
            return $this->error('设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取聊天设置
     */
    public function getChatSettings()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $otherUserId = (int)input('other_user_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $settings = Db::name('chat_settings')
                ->where('user_id', $userId)
                ->where('other_user_id', $otherUserId)
                ->find();

            if (!$settings) {
                $settings = [
                    'is_mute' => 0
                ];
            }

            return $this->success($settings, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取链接预览
     */
    public function getLinkPreview()
    {
        $url = input('url');

        if (empty($url)) {
            return $this->badRequest('缺少URL参数');
        }

        try {
            // 简单的链接预览
            $preview = [
                'title' => '链接预览',
                'description' => parse_url($url, PHP_URL_HOST),
                'image' => '',
                'url' => $url
            ];

            return $this->success($preview, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 消息收藏页面
     */
    public function favorites()
    {
        $userId = session('user_id') ?: cookie('user_id');
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign([
            'currentUser' => $currentUser,
            'isLogin' => !empty($userId),
            'current_url' => '/messages/favorites'
        ]);
        return View::fetch('index/message-favorites');
    }
}
