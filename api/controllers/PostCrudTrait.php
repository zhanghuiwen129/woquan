<?php

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Database;
use Hublog\Core\Auth;
use Hublog\Core\Validator;
use Hublog\Core\Security;
use Hublog\Core\Upload;
use Hublog\Core\VideoCompress;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\BadgeService;
use Hublog\Core\Hook;
use Hublog\Core\PostRepository;
use Hublog\Core\PostFormatter;
use Hublog\Core\Moderation;
use Hublog\Core\AuditNotice;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\SsePush;
use Hublog\Core\ContentPayService;
use Hublog\Core\VisibilityPolicy;

trait PostCrudTrait
{
    /**
     * V56：可见范围过滤条件生成
     *
     * 所有对外暴露动态的列表/详情接口都必须拼接这个条件，漏一处就等于私密帖泄露。
     * 规则：
     *   - 作者本人：始终可见（任何取值）
     *   - public：所有人可见（含游客）
     *   - circle：仅该动态所属圈子的成员可见
     *   - friend：仅作者好友可见
     *   - private：仅本人可见
     *
     * @param int $userId 当前登录用户 ID，0 表示游客
     * @return array{0: string, 1: array} [WHERE 片段, 参数数组]
     */

    private function buildVisibilityWhere(int $userId): array
    {
        // 唯一权威口径：Hublog\Core\VisibilityPolicy。保留本私有方法名供测试探针调用。
        return VisibilityPolicy::where($userId);
    }

    /**
     * V56：组装「转发自」来源信息
     * 原动态已被删除/下架时返回 deleted=true，由前端渲染成占位卡片
     */

    private function buildOriginPost($originId, int $currentUserId): ?array
    {
        $originId = (int)$originId;
        if ($originId <= 0) {
            return null;
        }

        $origin = PostRepository::findOriginPost($originId);
        if (!$origin) {
            return ['id' => $originId, 'deleted' => true, 'content' => '', 'user' => null];
        }
        if (!$this->canViewPost($origin, $currentUserId)) {
            return ['id' => $originId, 'forbidden' => true, 'content' => '', 'user' => null];
        }

        // 付费上锁的原帖只返回占位：不带正文、媒体 URL 与作者身份。
        $paywall = ContentPayService::resolve($origin, $currentUserId);
        if (!empty($paywall['locked'])) {
            return [
                'id' => $originId,
                'deleted' => true,
                'locked' => true,
                'content' => '',
                'image_count' => 0,
                'user' => null,
            ];
        }

        $images = json_decode($origin['images'] ?: '[]', true) ?: [];

        return [
            'id' => (int)$origin['id'],
            'deleted' => false,
            'forbidden' => false,
            'content' => mb_substr((string)$origin['content'], 0, 140),
            'image_count' => count($images),
            'created_at' => $origin['created_at'] ?? null,
            'user' => [
                'id' => (int)$origin['user_id'],
                'username' => $origin['username'],
                'nickname' => $origin['nickname'],
                'avatar' => $origin['avatar'],
                'vip_level' => (int)($origin['vip_level'] ?? 0),
                'avatar_frame' => $origin['avatar_frame'] ?? 'none'
            ]
        ];
    }

    /**
     * V56：判断单条动态对当前用户是否可见（详情接口用）
     */

    private function canViewPost(array $post, int $userId): bool
    {
        // 唯一权威口径：缺失/非法 visibility 默认拒绝，friend 为单向关注语义。
        return VisibilityPolicy::canView($post, $userId);
    }

    /**
     * 发布动态
     */

    public function create(Request $request): Response
    {
        $user = Auth::check($request);
        if ($user['role'] !== 'admin' && !$this->checkUserPermission('allow_post')) {
            return Response::error('你没有发布权限', 403);
        }

        // V67：每日发帖配额（普通 10 条 / 会员 100 条），管理员不扣。
        $quotaError = $this->enforcePostQuota($user);
        if ($quotaError !== null) {
            return $quotaError;
        }

        $settings = $this->getSiteSettings();
        $maxLength = (int)($settings['post_max_length'] ?? '2000');
        $maxLength = max(100, $maxLength);

        $validator = new Validator($request->all());
        // 内容可以为空（有图/视频/附件时），但长度不超过 maxLength
        $validator->string('content', 0, $maxLength);
        if ($request->has('title')) {
            $validator->string('title', 0, 255);
        }
        $data = $validator->validate();

        // 全量输入校验（禁言 / 分类 / 圈子 / 链接 / 帖型 / 媒体 / 敏感词 / 提及 / 可见范围 / 定时 ……）。
        // 任一校验失败直接返回 Response::error；全部通过则把派生变量装入 $out 供后续组装使用。
        $out = [];
        $validationError = $this->validateCreateInput($request, $settings, $data, $user, $out);
        if ($validationError !== null) {
            return $validationError;
        }

        $postData = $this->buildPostData(
            $request,
            (int)$user['id'],
            $out['contentText'],
            $out['images'],
            $out['videos'],
            $out['attachments'],
            $out['location'],
            $out['locationLat'],
            $out['locationLng'],
            $out['isAnonymous'],
            $out['link'],
            $out['visibility'],
            $out['postType'],
            $out['declaration'],
            $out['isScheduled'],
            $out['needReview'],
            $out['scheduledAt'],
            $out['categoryId'],
            $out['title'],
            $out['circleId']
        );

        // P1-6 安全校验：mentions 和 uploads 都要验证归属权，防止越权使用他人上传的文件
        $uploadCheckError = $this->assertUploadsBelongToUser($user['id'], $out['images'], $out['videos'], $out['attachments']);
        if ($uploadCheckError !== null) {
            return Response::error($uploadCheckError, 403);
        }

        $postId = $this->persistPost(
            (int)$user['id'],
            $postData,
            $out['images'],
            $out['videos'],
            $out['attachments'],
            $out['allowMention'],
            $out['mentions'],
            $out['contentText'],
            $out['title'],
            $out['postType'],
            $out['typeConfig'],
            $out['pollConfig']
        );

        $this->afterPostCreated($postId, (int)$user['id'], $out['circleId']);

        // V169：命中后台敏感词库（review 级）时同样点名通知作者。
        // 插件那条路径（内置词表 / AI 判定）已在插件内自行通知，两边命中词会自动合并。
        $hitWords = (array)($out['hitWords'] ?? []);
        if (!empty($hitWords)) {
            AuditNotice::notify($postId, 'post', $hitWords);
        }

        // V95：分类（频道）关注者推送——该分类出新文章时通知所有关注者。
        // 放在发布流程末尾（审核/敏感词处理之后），仅对最终 status=1 的已发布动态生效。
        $this->notifyCategoryFollowers($postId, (int)$user['id'], $out['categoryId'] ?? null);

        // 内容审核插件（ContentAudit）在 afterPostCreated 的 post_created 钩子里把命中敏感词的内容
        // 置为 status=0 转人工审核，并给作者发站内信点名命中词。这里读取最终 status 如实返回。
        $post = $this->formatPost(PostRepository::findAnyWithAuthorById($postId), $user['id']);
        $post['pending_review'] = ((int)($post['status'] ?? 1) === 0);
        return Response::success($post, $post['pending_review'] ? '内容已提交，进入人工审核' : '发布成功');
    }

    /**
     * V67：每日发帖配额校验（普通 10 条 / 会员 100 条），管理员不扣。
     *
     * @return Response|null 超限时返回错误 Response，否则返回 null
     */

    private function enforcePostQuota(array $user): ?Response
    {
        if ($user['role'] === 'admin') {
            return null;
        }
        PaymentController::syncVip((int)$user['id']);
        $vipRow = PostRepository::findVipLevel((int)$user['id']);
        $quota = PaymentController::privileges((int)($vipRow['vip_level'] ?? 0))['post_daily'];
        $used = PostRepository::countTodayPosts((int)$user['id'], date('Y-m-d'));
        if ($used >= $quota) {
            return Response::error("今日发布已达上限（{$quota} 条），开通会员可提升上限", 402);
        }
        return null;
    }

    /**
     * create() 全量输入校验（V160 步骤拆分）。
     *
     * 逻辑与重构前逐字一致：禁言前置、分类、圈子、链接分享、帖型与投票配置、
     * 媒体归一化与上限、内容非空、标题、敏感词过滤、位置、匿名、@提及、可见范围、定时、自主声明。
     * 任一校验失败返回 Response::error；全部通过则把所有派生变量写入 $out 并返回 null。
     *
     * @param array $out 输出容器，键名见末尾组装
     * @return Response|null
     */

    private function validateCreateInput(Request $request, array $settings, array $data, array $user, array &$out): ?Response
    {
        // ====== V71：全站禁言前置校验 ======
        // 被禁言的用户一律不能产出内容（动态/评论/私信/群聊共用这一道闸）。
        // Auth::check() 已把 muted_until 带出来，不用再查一次库。
        try {
            Moderation::assertNotMuted($user);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        }

        $categoryId = $request->getInt('category_id');
        $categoryRequired = ($settings['category_required'] ?? '0') === '1';
        if ($categoryRequired && !$categoryId) {
            return Response::error('请选择分类', 400);
        }
        if ($categoryId) {
            $category = PostRepository::findActiveCategory($categoryId);
            if (!$category) {
                return Response::error('所选分类不存在', 400);
            }
        }

        // V50：发布到圈子 —— 接收 circle_id，校验圈子存在 + 当前用户是否该圈子成员 + 发帖权限
        // V90：规则抽到 checkCirclePostable()，与编辑（update）共用同一份实现，避免两处漂移
        $circleId = $request->getInt('circle_id');
        $circleReviewRequired = false;
        if ($circleId > 0) {
            $circleError = $this->checkCirclePostable($circleId, $user, $circleReviewRequired);
            if ($circleError !== null) {
                return $circleError;
            }
        } elseif ($circleId < 0) {
            // 负数视为非法，归零
            $circleId = 0;
        }

        // ====== V57：链接分享 ======
        // 前端可以只传 link_url（其余字段由后端从 URL 里猜域名），也可以全量传
        $linkUrl = trim((string)$request->getString('link_url'));
        $link = null;
        if ($linkUrl !== '') {
            if (preg_match('#^https?://#i', $linkUrl) !== 1) {
                $linkUrl = 'https://' . ltrim($linkUrl, '/');
            }
            $linkUrl = mb_substr($linkUrl, 0, 2048);
            $host = '';
            $hostPart = parse_url($linkUrl, PHP_URL_HOST);
            if (is_string($hostPart) && $hostPart !== '') {
                $host = $hostPart;
            }
            $link = [
                'link_url' => $linkUrl,
                'link_title' => mb_substr(trim((string)$request->getString('link_title')) ?: '', 0, 255) ?: null,
                'link_desc' => mb_substr(trim((string)$request->getString('link_desc')) ?: '', 0, 500) ?: null,
                'link_site' => mb_substr(trim((string)$request->getString('link_site')) ?: $host, 0, 128) ?: null,
            ];
        }

        // ====== V57：投票帖配置 ======
        // ====== V59：P1-1 进阶帖型（问答 / 组队 / 打卡）======
        // ====== V64：后台「功能开关」可逐条关闭帖型，前端隐藏入口后这里仍要挡一道 ======
        // 只挡「关闭的帖型被提交上来」，不帮前端兜底修正 —— 脏值一律降级成 normal，
        // 免得后台没开投票、用户却把问答内容塞进普通帖里新建出一条脏数据。
        $postType = strtolower(trim((string)$request->getString('post_type')));
        if (!in_array($postType, ['normal', 'poll', 'question', 'team', 'checkin'], true)) {
            $postType = 'normal';
        }
        if ($postType !== 'normal' && !$this->isPostTypeEnabled($settings, $postType)) {
            return Response::error('本站未开启「' . $postType . '」类型的动态', 403);
        }
        $typeConfig = null;
        if ($postType === 'question') {
            $qText = mb_substr(trim((string)$request->getString('question')), 0, 255);
            if ($qText === '') {
                return Response::error('请填写你想问的问题', 400);
            }
            $typeConfig = ['question' => $qText];
        } elseif ($postType === 'team') {
            $needCount = (int)$request->getInt('need_count', 0);
            if ($needCount < 0) $needCount = 0;
            if ($needCount > 1000) $needCount = 1000;
            $deadlineRaw = trim((string)$request->getString('team_deadline'));
            $deadline = ($deadlineRaw !== '' && strtotime($deadlineRaw) !== false)
                ? date('Y-m-d', strtotime($deadlineRaw)) : null;
            $typeConfig = [
                'headline' => mb_substr(trim((string)$request->getString('headline')), 0, 255) ?: null,
                'need_count' => $needCount,
                'deadline' => $deadline,
                'city' => mb_substr(trim((string)$request->getString('city')), 0, 50) ?: null,
                'contact' => mb_substr(trim((string)$request->getString('contact')), 0, 100) ?: null,
            ];
        } elseif ($postType === 'checkin') {
            $targetDays = (int)$request->getInt('target_days', 0);
            if ($targetDays < 0) $targetDays = 0;
            if ($targetDays > 3650) $targetDays = 3650;
            $typeConfig = [
                'goal' => mb_substr(trim((string)$request->getString('goal')), 0, 255) ?: null,
                'target_days' => $targetDays,
            ];
        }

        // 传参形态：poll = { options: 'A,B,C', duration_days: 7, multi: 0 }
        $pollOptionsRaw = $request->getString('poll_options');
        if ($pollOptionsRaw === '' && $request->has('poll')) {
            $pollPayload = $request->get('poll');
            if (is_string($pollPayload)) {
                $decoded = json_decode($pollPayload, true);
                $pollPayload = is_array($decoded) ? $decoded : null;
            }
            if (is_array($pollPayload)) {
                $pollOptionsRaw = $pollPayload['options'] ?? '';
            }
        }
        $pollConfig = null;
        if (trim((string)$pollOptionsRaw) !== '') {
            $options = array_values(array_filter(array_map('trim', preg_split('/[,，\r\n]/u', (string)$pollOptionsRaw))));
            $options = array_slice(array_unique(array_filter($options, fn($v) => $v !== '')), 0, 6);
            if (count($options) < 2) {
                return Response::error('投票至少需要 2 个选项', 400);
            }
            if (count($options) > 6) {
                return Response::error('投票最多 6 个选项', 400);
            }
            foreach ($options as $opt) {
                if (mb_strlen($opt) > 50) {
                    return Response::error('投票选项不能超过 50 个字', 400);
                }
            }
            $multi = $request->getBool('multi') || ($pollPayload['multi'] ?? false) === true || (int)($pollPayload['multi'] ?? 0) === 1;
            $durationDays = (int)($pollPayload['duration_days'] ?? $request->getInt('duration_days', 0));
            $question = mb_substr(trim((string)$request->getString('poll_question')), 0, 255);
            if ($question === '') {
                $question = mb_substr(trim((string)$request->getString('question')), 0, 255);
            }
            $pollConfig = [
                'question' => $question,
                'options' => $options,
                'multi' => $multi ? 1 : 0,
                'max_choice' => 1,
                'expires_at' => $durationDays > 0 ? date('Y-m-d H:i:s', time() + $durationDays * 86400) : null,
            ];
        }

        $images = $request->getArray('images');
        // V59：传了投票选项却没显式选帖型时，自动按投票帖处理（App 老版本兼容）
        if ($postType === 'normal' && $pollConfig !== null) {
            $postType = 'poll';
        }

        $videos = $request->getArray('videos');
        $attachments = $request->getArray('attachments');
        $settings = $this->getSiteSettings();
        $maxImages = (int)($settings['upload_max_images'] ?? '9');
        $maxVideos = (int)($settings['upload_max_videos'] ?? '3');
        $maxAttachments = (int)($settings['upload_max_attachments'] ?? '5');
        $images = array_slice($images, 0, max(0, $maxImages));
        $videos = array_slice($videos, 0, max(0, $maxVideos));
        $attachments = array_slice($attachments, 0, max(0, $maxAttachments));

        // P1-6+：把媒体 URL 归一化为 uploads 表存储的规范 URL（兼容 App 端把相对路径补成绝对地址）
        // 归一化后再做归属校验与落库，保证三处使用同一形式
        [$images, $videos, $attachments] = $this->normalizeOwnMedia((int)$user['id'], $images, $videos, $attachments);

        // 前端 publish.js 已做非空校验
        // 后端再次校验确保至少有一项内容
        // V134：问答/组队/打卡/投票帖允许纯文字发布——帖型表单本身有内容即放行，
        //       图片/视频/附件全凭用户喜好（想传才传），不再强制至少一项媒体。
        $hasContent = trim((string)($data['content'] ?? '')) !== '';
        $hasMedia = !empty($images) || !empty($videos) || !empty($attachments);
        $hasTypeContent = $pollConfig !== null || $this->postTypeConfigHasText($typeConfig);
        if (!$hasContent && !$hasMedia && !$hasTypeContent) {
            return Response::error('请输入内容或上传图片/视频/附件', 400);
        }

        $title = isset($data['title']) ? trim($data['title']) : '';
        $titleRequired = ($settings['title_required'] ?? '0') === '1';
        if ($titleRequired && $title === '') {
            return Response::error('标题不能为空', 400);
        }

        // ====== V71：敏感词过滤（标题 + 正文）======
        // 分级：replace=打码后照发 / block=直接拦下 / review=照发但置为待审（不进信息流）。
        // 两段分别过滤，命中级别按最严的那一段走。
        $contentText = (string)($data['content'] ?? '');
        $fTitle = Moderation::filter($title, 'post', (int)$user['id'], 0);
        if (!$fTitle['ok']) {
            return Response::error($this->sensitiveBlockMsg($fTitle), 403);
        }
        $title = $fTitle['text'];
        $fBody = Moderation::filter($contentText, 'post', (int)$user['id'], 0);
        if (!$fBody['ok']) {
            return Response::error($this->sensitiveBlockMsg($fBody), 403);
        }
        $contentText = $fBody['text'];
        $needReview = in_array('review', [$fTitle['action'], $fBody['action']], true)
            || $circleReviewRequired;
        // 命中审核级敏感词时把具体词带出去：站内信要点名，用户才知道该改哪里
        $hitWords = $needReview
            ? array_values(array_unique(array_merge(
                $fTitle['action'] === 'review' ? $fTitle['words'] : [],
                $fBody['action'] === 'review' ? $fBody['words'] : []
            )))
            : [];

        $location = trim($request->getString('location'));
        $locationLat = $request->getFloat('location_lat');
        $locationLng = $request->getFloat('location_lng');
        $allowLocation = ($settings['allow_location'] ?? '1') === '1';
        if ($location && !$allowLocation) {
            return Response::error('站点未开启位置功能', 403);
        }

        $isAnonymous = $request->getBool('is_anonymous');
        $allowAnonymous = ($settings['allow_anonymous_post'] ?? '1') === '1';
        if ($isAnonymous && !$allowAnonymous) {
            return Response::error('站点未开启匿名发布', 403);
        }

        $mentions = $request->getArray('mentions');
        $allowMention = ($settings['allow_mention'] ?? '1') === '1';
        $maxMentions = (int)($settings['mention_max_count'] ?? '10');
        if (!empty($mentions) && !$allowMention) {
            return Response::error('@提及功能未开启', 403);
        }
        // V58：P1-4 —— 被@的人若关闭「允许被@」，静默剔除而不是报错，避免帮用户发不出去
        if (!empty($mentions)) {
            $allowed = PostRepository::fetchMentionableUsernames($mentions);
            $mentions = array_column($allowed, 'username');
        }
        $mentions = array_slice(array_unique($mentions), 0, $maxMentions);

        // V161：定时发布解析必须先于可见范围校验——原守卫 `if ($visibility === 'private' && $isScheduled)`
        //       位于 $isScheduled 赋值之前，导致「私密动态不支持定时发布」从未生效（历史潜藏缺陷，本版修复）。
        $scheduledAt = $request->getString('scheduled_at');
        $isScheduled = false;
        if (!empty($scheduledAt)) {
            $scheduledTime = strtotime($scheduledAt);
            if ($scheduledTime !== false && $scheduledTime > time()) {
                $isScheduled = true;
            }
        }

        // V56：可见范围（public=所有人 / circle=仅本圈成员 / friend=仅好友 / private=仅自己）
        $visibility = strtolower(trim((string)$request->getString('visibility'))) ?: 'public';
        if (!in_array($visibility, self::VISIBILITY_TYPES, true)) {
            return Response::error('可见范围取值无效', 400);
        }
        if ($visibility === 'circle' && $circleId <= 0) {
            // 仅圈内可见必须发在某个圈子里，否则没有任何人能看见它
            return Response::error('仅圈内可见的动态必须发布到圈子', 400);
        }
        // V161：修复后 $isScheduled 已在本块之前解析，此守卫现在可正确拦截「私密 + 定时」组合
        if ($visibility === 'private' && $isScheduled) {
            return Response::error('私密动态不支持定时发布', 400);
        }

        // V89：自主声明枚举。选「原创」时同步点亮 is_original，
        // 保留「原创作者」徽章按原创帖数统计的链路（前端已不再单独传 is_original）
        $declaration = $this->sanitizeDeclaration((string)$request->getString('declaration'));

        $out = [
            'categoryId' => $categoryId,
            'circleId' => $circleId,
            'link' => $link,
            'postType' => $postType,
            'typeConfig' => $typeConfig,
            'pollConfig' => $pollConfig,
            'images' => $images,
            'videos' => $videos,
            'attachments' => $attachments,
            'title' => $title,
            'contentText' => $contentText,
            'needReview' => $needReview,
            'hitWords' => $hitWords,
            'location' => $location,
            'locationLat' => $locationLat,
            'locationLng' => $locationLng,
            'isAnonymous' => $isAnonymous,
            'allowMention' => $allowMention,
            'mentions' => $mentions,
            'visibility' => $visibility,
            'isScheduled' => $isScheduled,
            'declaration' => $declaration,
            'scheduledAt' => $scheduledAt,
        ];
        return null;
    }

    /**
     * 拼出 block 级拦截提示：默认文案 + 命中的敏感词，让用户知道是哪个词被拦。
     */
    private function sensitiveBlockMsg(array $fr): string
    {
        $msg = ($fr['msg'] ?? '') ?: '内容包含违规词汇，请修改后再试';
        if (!empty($fr['words'])) {
            $msg .= '（命中：' . implode('、', $fr['words']) . '）';
        }
        return $msg;
    }

    /**
     * 组装 posts 表写入数据（create 专用，V160 步骤拆分）
     */

    private function buildPostData(
        Request $request,
        int $userId,
        string $contentText,
        array $images,
        array $videos,
        array $attachments,
        string $location,
        ?float $locationLat,
        ?float $locationLng,
        bool $isAnonymous,
        ?array $link,
        string $visibility,
        string $postType,
        string $declaration,
        bool $isScheduled,
        bool $needReview,
        ?string $scheduledAt,
        int $categoryId,
        string $title,
        int $circleId
    ): array {
        $postData = [
            'user_id' => $userId,
            // V71：正文走过滤后的 $contentText（replace 已打码），不再直接用原始 content
            'content' => Security::purify($contentText),
            'images' => !empty($images) ? json_encode($images) : null,
            'videos' => !empty($videos) ? json_encode($videos) : null,
            'attachments' => !empty($attachments) ? json_encode($attachments) : null,
            'location' => $location !== '' ? $location : null,
            'location_lat' => $locationLat,
            'location_lng' => $locationLng,
            'is_anonymous' => $isAnonymous ? 1 : 0,
            // V57：链接分享卡片（无链接时留空字符串，避免写入 null 后老客户端判空不一致）
            'link_url' => $link['link_url'] ?? '',
            'link_title' => $link['link_title'] ?? null,
            'link_desc' => $link['link_desc'] ?? null,
            'link_site' => $link['link_site'] ?? null,
            'visibility' => $visibility,
// V59：帖型标记 normal/poll/question/team/checkin
            'post_type' => $postType,
            // V83/V89：原创标记改由自主声明 original 项驱动（老客户端传 is_original 仍兼容）
            'is_original' => ($declaration === 'original' || $request->getInt('is_original')) ? 1 : 0,
            // V89：自主声明（枚举下拉，空串=未声明；非法值一律落空串）
            'declaration' => $declaration,

            // V71：命中 review 级敏感词 → status=0 转人工审核（与定时发布的待发状态同一取值）
            'status' => ($isScheduled || $needReview) ? 0 : 1,
            // V169：命中审核级敏感词时打上 audit_state=1，与「定时待发」（audit_state=0）区分开。
            // 作者据此才能看到并编辑自己的待审动态；管理员下架的内容 audit_state 恒为 0，仍不可见。
            'audit_state' => $needReview ? 1 : 0,
            'scheduled_at' => $isScheduled ? $scheduledAt : null,
            'created_at' => Helper::now(),
            'updated_at' => Helper::now()
        ];
        if ($categoryId) {
            $postData['category_id'] = $categoryId;
        }
        if ($title !== '') {
            $postData['title'] = Security::purify($title);
        }
        // V50：发布到圈子时写入 circle_id（NULL=全站动态）
        if ($circleId > 0) {
            $postData['circle_id'] = $circleId;
        }
        return $postData;
    }

    /**
     * 事务内落库（create 专用，V160 步骤拆分）：
     * 写入 posts → 绑定 uploads → 处理 @提及 → 刷新话题标签 → 写入帖型附加表 / 投票。
     * 任一子步骤失败整条事务回滚。
     *
     * @return int 新动态 ID
     */

    private function persistPost(
        int $userId,
        array $postData,
        array $images,
        array $videos,
        array $attachments,
        bool $allowMention,
        array $mentions,
        string $contentText,
        ?string $title,
        string $postType,
        ?array $typeConfig,
        ?array $pollConfig
    ): int {
        // 事务边界下沉到 PostRepository；跨 trait 的编排（uploads / @提及 / 话题 / 投票）
        // 以回调形式传入，保证仍在同一事务内执行，异常由 Repository rollBack 后原样重抛。
        return PostRepository::transaction(function () use (
            $userId, $postData, $images, $videos, $attachments,
            $allowMention, $mentions, $contentText, $title,
            $postType, $typeConfig, $pollConfig
        ) {
            $postId = PostRepository::insertPost($postData);

            // 更新 uploads 表，绑定 post_id
            $this->recordUploads($userId, $postId, $images, $videos, $attachments);

            // 处理@提及
            if ($allowMention && !empty($mentions)) {
                $this->processMentions($postId, null, $userId, $mentions, 'post');
            }

            // P2-5：从 content 和 title 中提取#话题#标签，写入 post_tags 表（INSERT IGNORE）
            // V71：话题提取也用过滤后的文本，避免把打码前的敏感词固化进 topic 表
            $contentForTags = $contentText . ' ' . ($title ?? '');
            $this->extractAndSavePostTags($postId, $contentForTags);

            // V57：建投票（同一个事务里，建失败整条动态回滚）
            // V59：问答 / 组队 / 打卡 附加表（与投票同一事务，失败整条回滚）
            PostRepository::insertPostTypeExtras($postId, $postType, $typeConfig, $userId);

            if ($pollConfig) {
                $this->createPollForPost($postId, $userId, $pollConfig);
            }

            return $postId;
        });
    }

    /**
     * 发帖成功后的后置副作用（V160 步骤拆分）：
     * 时间线缓存升版、圈子计数 +1、发帖 hook、徽章重算。
     */

    private function afterPostCreated(int $postId, int $userId, int $circleId): void
    {
        // P0-11：版本号升版替代全量前缀删除（旧 key 靠 TTL 自然过期，不再每次 glob 扫描删文件）
        Cache::bumpNsVersion('timeline');
        // V50：发布到圈子时累加 post_count（圈子内动态计数）
        // V90：统一走 adjustCirclePostCount()，与编辑时的增减共用一处实现
        $this->adjustCirclePostCount($circleId, 1);
        Hook::doAction('post_created', $postId, $userId);

        // V83：发帖后重算统计类徽章（有效帖/图文帖/视频帖/原创/话题帖数量等）。
        //      发帖是低频操作不节流；徽章失败只记日志，不影响发布结果。
        BadgeService::evaluateOnAction($userId, 0);
    }

    /**
     * V95：分类（频道）关注者推送。
     *
     * 某分类发布新文章时，给该分类的全部关注者（排除作者本人）发送一条
     * type='category' 的站内通知，并通过 SSE 实时推送给在线关注者。
     * 仅对最终 status=1（已发布）的动态生效；待审/下架内容不触发推送。
     *
     * @param int      $postId     新动态 ID
     * @param int      $authorId   作者用户 ID
     * @param int|null $categoryId 分类 ID（无分类则直接跳过）
     */
    private function notifyCategoryFollowers(int $postId, int $authorId, ?int $categoryId): void
    {
        if (!$categoryId) {
            return;
        }
        try {
            $post = PostRepository::findPostForCategoryNotify($postId);
            // 仅已发布的 public 动态才推送：待审/下架/非公开内容不面向频道全部关注者广播。
            if (!$post
                || (int)$post['status'] !== 1
                || (int)($post['audit_state'] ?? 0) === 1
                || (string)($post['visibility'] ?? '') !== 'public') {
                return;
            }

            $cat = PostRepository::findCategory($categoryId);
            if (!$cat) {
                return;
            }
            $catName = $cat['name'] ?? '';

            $followers = PostRepository::fetchCategoryFollowerIds($categoryId);
            if (empty($followers)) {
                return;
            }

            $now = Helper::now();
            $content = $catName ? ('你关注的频道「' . $catName . '」有新文章') : '你关注的频道有新文章';

            foreach ($followers as $f) {
                $fid = (int)$f['user_id'];
                if ($fid === $authorId) {
                    continue; // 不通知作者本人
                }
                PostRepository::insertNotification([
                    'user_id'    => $fid,
                    'sender_id'  => $authorId,
                    'type'       => 'category',
                    'post_id'    => $postId,
                    'comment_id' => null,
                    'content'    => $content,
                    'is_read'    => 0,
                    'created_at' => $now,
                ]);
                try {
                    SsePush::push($fid, 'notification', [
                        'type'      => 'category',
                        'sender_id' => $authorId,
                        'post_id'   => $postId,
                    ]);
                } catch (\Throwable $_) {
                    // 实时推送失败不影响通知落库
                }
            }
        } catch (\Throwable $e) {
            error_log('[Hublog CategoryFollow Notification] ' . $e->getMessage()); // @allow-error-log
        }
    }

    public function detail(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }

        $post = Cache::remember("post_{$id}", function () use ($id) {
            return PostRepository::findById($id);
        }, 60);

        // V169：作者本人查看自己「因审核待审」的动态（status=0 且 audit_state=1）。
        // 上面的常规查法只认 status=1，这里按作者身份补查一次，并走独立缓存键 ——
        // 绝不能把待审内容写进 post_{$id}，那个键所有人都能读到。
        $meId = (int)($currentUser['id'] ?? 0);
        $isPendingReview = false;
        if (!$post && $meId > 0) {
            $pending = Cache::remember("post_pending_{$id}", function () use ($id) {
                return PostRepository::findById($id, true);
            }, 60);
            if ($pending
                && (int)$pending['user_id'] === $meId
                && (int)($pending['status'] ?? 1) === 0
                && (int)($pending['audit_state'] ?? 0) === 1) {
                $post = $pending;
                $isPendingReview = true;
            }
        }

        if (!$post) {
            return Response::error('动态不存在', 404);
        }

        // 可见性门禁必须在任何副作用之前：不可见请求不写 post_view_logs、
        // 不 increment view_count、不刷缓存、不触发 post_viewed hook。
        $meId = (int)($currentUser['id'] ?? 0);
        if (!$this->canViewPost($post, $meId)) {
            return Response::error('动态不存在', 404);
        }

        // P2-7：浏览量统计，按 IP + 用户去重，支持匿名 user_id
        // 方案：每次 GET 请求，UPDATE posts 计数 + INSERT post_view_logs 记录
        // 说明：1 条动态 100 个用户浏览就是 200 条 SQL，性能可接受
        // 利用 post_view_logs 的 INSERT IGNORE，affectedRows>0 时才 +1 view_count
        // 这样保证同一用户同一天只算一次浏览，减少 SQL 次数
        // 口径说明：去重维度含 view_date（按天）——2026-10-23 按产品要求核查过：
        // 同一 IP/用户当天内重复浏览不叠加；跨天再浏览会重新 +1（当前产品口径，未改）。
        $viewerId = empty($currentUser['id']) ? null : (int)$currentUser['id'];
        $viewerIp = (string)$request->getIp();
        $viewDate = date('Y-m-d');

        // 说明：Migration 中的 uk_post_view 唯一键设计为（viewer_id IS NOT NULL 时 viewer_id+view_date+post_id
        // viewer_id IS NULL 时 viewer_ip+view_date+post_id），但 SQL 层面不能直接用 OR 唯一键
        // 所以用两条独立逻辑分别处理：(post_id, viewer_id, view_date) 和 (post_id, viewer_ip, view_date, viewer_id IS NULL) 两种情况
        if ($viewerId !== null) {
            // 登录用户：用 user_id + 日期 去重，插入浏览记录
            $isNewView = PostRepository::recordUserView($id, $viewerId, $viewerIp, $viewDate, Helper::now());
        } else {
            // 游客：(post_id, viewer_ip, viewer_id IS NULL, view_date) 组合，因为 NULL 不参与 UNIQUE 约束，所以不能用 viewer_id=0 代替
            // 所以 viewer_id=NULL 时，先用 viewer_ip+view_date+post_id 查询是否已存在，再决定是否插入
            $isNewView = !PostRepository::hasGuestViewed($id, $viewerIp, $viewDate);
            if ($isNewView) {
                PostRepository::insertGuestView($id, $viewerIp, $viewDate, Helper::now());
            }
        }

        if ($isNewView) {
            // 新浏览则更新动态表的 view_count
            PostRepository::incrementPostViewCount($id);
            // 删除缓存，确保下次读取到最新的 view_count 值
            Cache::delete("post_{$id}");
            // P0-11：列表缓存（首页信息流 / 发现页热门榜）里带着旧 view_count。
            // 原实现每次浏览都 deleteByPrefix 全站 timeline/popular：命中率归零 +
            // 惊群 + 文件驱动下每次失效 glob 扫描全部缓存文件。
            // 现改为版本号合并升版：浏览量进列表有至多 20 秒延迟（cosmetic），
            // 换取列表缓存真实命中；内容变更（发帖/删帖/点赞/评论）仍立即升版。
            Cache::bumpNsVersionThrottled('timeline', 20);
            Cache::bumpNsVersionThrottled('popular', 20);
            // $post 是加量前查出来的，本地 +1，让详情接口当场返回最新浏览量
            $post['view_count'] = (int)($post['view_count'] ?? 0) + 1;
        }

        Hook::doAction('post_viewed', $id, $currentUser['id'] ?? 0);

        // V83：当前用户是否可把本帖评论标记为「神评」（帖子作者 / 圈子管理员·圈主 / 平台管理员；评论作者本人不可标）
        $post['can_mark_comments'] = $this->canMarkGodComment($post, $currentUser);

        $formatted = PostFormatter::formatPostSingle($post, $currentUser['id'] ?? 0);
        // V56：转发来源（原动态被删除时只留提示，不报 500）
        $formatted['origin_post'] = $this->buildOriginPost($post['origin_id'] ?? null, $currentUser['id'] ?? 0);
        // V57：投票帖（非投票帖返回 null，前端不用判断字段存不存在）
        $formatted['poll'] = $this->buildPollData($id, (int)($currentUser['id'] ?? 0));
        // V59：进阶帖型附加数据（非对应帖型返回 null，前端不用判断字段是否存在）
        $fmtType = (string)($post['post_type'] ?? 'normal');
        $formatted['post_type'] = $fmtType;
        if ($fmtType === 'question') {
            $formatted['question'] = $this->buildQuestionData($id, (int)($currentUser['id'] ?? 0));
        } elseif ($fmtType === 'team') {
            $formatted['team'] = $this->buildTeamData($id, (int)($currentUser['id'] ?? 0));
        } elseif ($fmtType === 'checkin') {
            $formatted['checkin'] = $this->buildCheckinData($id, (int)($currentUser['id'] ?? 0));
        }

        // 编辑态需要知道原动态 @ 了谁：正文里现在是昵称，前端靠正则反查不出来，
        // 不返回的话编辑保存时会把 mentions 关系清空（update 是先删后插）
        $formatted['mentions'] = array_column(
            PostRepository::fetchPostMentionUsernames($id) ?: [],
            'username'
        );

        // V169：作者看到的待审动态带 pending_review 标识，前端据此显示「审核中」
        $formatted['pending_review'] = $isPendingReview;

        // V97：内容付费墙 —— 在 canViewPost 之后按当前用户裁剪正文，
        // 返回 200 + paywall 对象（不是 404），前端据此显示购买卡。
        // 只改内存副本 $formatted，不动共享缓存 post_{id}（那里存的是原始全文）。
        ContentPayService::scrubSingle($formatted, $post, (int)($currentUser['id'] ?? 0));

        return Response::success($formatted);
    }

    /**
     * V90：校验「把动态发布/编辑到某圈子」是否被允许。
     * 返回 null 表示通过，否则返回应直接抛给客户端的错误响应。
     * create() 与 update() 共用同一份判定，避免两处规则各自漂移。
     *
     * @param int   $circleId 目标圈子 id（>0）
     * @param array $user     当前登录用户
     */

    private function checkCirclePostable(int $circleId, array $user, ?bool &$reviewRequired = null): ?Response
    {
        // V71：多带 limited / removed_at —— 圈子被限流或下架时禁止发帖
        $circle = PostRepository::findCircleForPost($circleId);
        if (!$circle || (int)$circle['status'] !== 1 || Moderation::circleRemoved($circle)) {
            return Response::error('圈子不存在', 400);
        }
        try {
            Moderation::assertCircleWritable($circle);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        }
        $memberRow = PostRepository::findCircleMemberForPost($circleId, (int)$user['id']);
        if (!$memberRow) {
            return Response::error('你不是该圈子成员，无法发帖', 403);
        }
        // V65：圈内永久黑名单
        if ((int)$memberRow['status'] === 2) {
            return Response::error('你已被本圈子列入黑名单，暂时无法发帖', 403);
        }
        // V67：付费圈子 / 会员门槛圈子 —— 成员身份不够，还得过付费这一关
        [$canCirclePost, $circleReason] = PaymentController::circlePostAllowed(
            $circleId,
            $circle,
            (int)$user['id']
        );
        if (!$canCirclePost) {
            return Response::error($circleReason, 402);
        }
        // 圈主始终可发；其他成员按 post_permission 校验
        $myRole = $memberRow['role'];
        $perm = $circle['post_permission'] ?? 'all';
        if ($myRole !== 'owner') {
            if ($perm === 'owner') {
                return Response::error('本圈子仅圈主可发帖', 403);
            }
            if ($perm === 'admin' && $myRole !== 'admin') {
                return Response::error('本圈子仅管理员可发帖', 403);
            }
        }

        // 圈内发帖审核。圈主/圈管/平台管理员免审，普通成员发帖需在圈内待审列表通过后才公开。
        if ($reviewRequired !== null) {
            $reviewRequired = (int)($circle['post_review'] ?? 0) === 1
                && $myRole === 'member'
                && ($user['role'] ?? '') !== 'admin';
        }
        return null;
    }

    /**
     * V90：调整圈子动态计数（$delta 可正可负，兜底不为负）。
     * 发帖 +1、编辑导致归属变化时旧圈 -1 / 新圈 +1；失败只记日志，不阻断主流程。
     */

    private function adjustCirclePostCount(int $circleId, int $delta): void
    {
        if ($circleId <= 0 || $delta === 0) {
            return;
        }
        try {
            PostRepository::adjustCirclePostCount($circleId, $delta, Helper::now());
            Cache::deleteByPrefix('circles_list_');
        } catch (\Throwable $e) {
            // 计数失败不阻断主流程
        }
    }

    public function delete(Request $request): Response
    {
        $user = Auth::check($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }

        $post = PostRepository::findPostForDelete($id);
        if (!$post) {
            return Response::error('动态不存在', 404);
        }
        if ($post['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            return Response::error('无权限', 403);
        }

        // 删除动态时同时清理 URL 物理文件 + 评论 + 点赞 + 相关数据
        $uploads = PostRepository::fetchPostUploadUrls($id);
        $uploadUrls = array_column($uploads, 'url');
        $imageUrls = json_decode($post['images'] ?? '[]', true) ?: [];
        $videoData = json_decode($post['videos'] ?? '[]', true) ?: [];
        $videoUrls = [];
        foreach ($videoData as $v) {
            if (is_string($v)) {
                $videoUrls[] = $v;
            } elseif (is_array($v)) {
                if (!empty($v['url'])) $videoUrls[] = $v['url'];
                if (!empty($v['cover'])) $videoUrls[] = $v['cover'];
            }
        }
        $urls = array_values(array_unique(array_merge($uploadUrls, $imageUrls, $videoUrls)));

        // 检查每个 URL 是否被其他动态引用，只删独有的
        $urlsToDelete = [];
        foreach ($urls as $url) {
            $others = PostRepository::countOtherUploadRefs((string)$url, $id);
            if ($others === 0) {
                $urlsToDelete[] = $url;
            }
        }

        // P1-8：删除时先收集所有评论 ID，手动处理（因为 parent_id CASCADE 可能有问题）
        // comment_likes / reports 等关联数据也需要按评论 ID 手动清理
        $allCommentIds = PostRepository::fetchPostCommentIds($id);
        $commentIds = array_map('intval', array_column($allCommentIds, 'id'));

        // 级联删除事务整体下沉到 PostRepository（reports → comment_likes → 浏览记录 → uploads
        // → 类型帖附属表 → 评论/点赞 → posts）；catch rollBack 后 throw，错误文案由本层处。
        PostRepository::deletePostCascade($id, $commentIds);

        // 删除物理文件（只删不被其他动态引用的），失败不影响主流程
        foreach ($urlsToDelete as $url) {
            try {
                Upload::deleteUpload($url);
            } catch (\Throwable $e) {
                // 文件删除失败记录日志，不抛出异常中断主流程
                error_log("[Hublog DeletePost] 删除文件失败: {$url} - " . $e->getMessage()); // @allow-error-log
            }
        }

        // P0-11：版本号升版替代全量前缀删除（旧 key 靠 TTL 自然过期，不再每次 glob 扫描删文件）
        Cache::bumpNsVersion('timeline');
        $this->clearCache('user_profile_');
        Cache::delete("post_{$id}");
        Cache::delete("post_comments_{$id}");
        Cache::delete("post_comments_raw_{$id}");
        Cache::delete("post_likes_{$id}");
        Hook::doAction('post_deleted', $id, $user['id']);

        return Response::success(null, '删除成功');
    }

    /**
     * P2-5：从动态内容/标题中提取#话题#标签（hashtag），写入 post_tags 表
     * 利用唯一键 uk_post_tag(post_id, tag) + INSERT IGNORE 防重复
     * - 支持 #中文 #English #a_b #123 等格式
     * - 每个 hashtag 长度 1-30 字符
     * - 每条动态最多提取 5 个话题标签
     */

    private function extractAndSavePostTags(int $postId, string $content): void
    {
        if ($content === '' || $postId <= 0) return;

        // 匹配 #开头（中文/英文/数字/下划线）1-30 字符，可选结尾 #，兼容 #话题# 和 #话题 两种写法
        if (!preg_match_all('/#([A-Za-z0-9_\x{4e00}-\x{9fa5}]{1,29})#?/u', $content, $m)) {
            return;
        }
        $tags = array_values(array_unique($m[1]));
        // 最多取 5 个，过滤空标签，确保 tag 长度 1-30
        $tags = array_slice(array_values(array_filter($tags, fn($t) => $t !== '' && mb_strlen($t) <= 30)), 0, 5);
        if (empty($tags)) return;

        PostRepository::insertPostTags($postId, $tags);

        // P2-10：用户自建的话题也登记进 topics 表（slug 唯一键 + INSERT IGNORE 幂等），
        // 这样用户在发布页现起的话题，下次别人点「话题」按钮时也能从列表里选到，
        // 不再只靠后台手动建。失败只记日志，绝不影响发帖主流程。
        try {
            PostRepository::upsertTopics($tags);
        } catch (\Throwable $e) {
            error_log('[Hublog topics sync] ' . $e->getMessage()); // @allow-error-log
        }
    }

    /**
     * 处理@提及，写入 mentions 表并发送通知
     */

    private function processMentions(int $postId, ?int $commentId, int $senderId, array $usernames, string $type): void
    {
        if (empty($usernames)) {
            return;
        }

        $users = PostRepository::fetchUsersByUsernames($usernames);

        if (empty($users)) {
            return;
        }

        foreach ($users as $user) {
            $userId = (int)$user['id'];
            if ($userId === $senderId) {
                continue;
            }

            try {
                PostRepository::insertMention($postId, $commentId, $userId, $senderId, $type, Helper::now());
            } catch (\Throwable $e) {
                // 重复提及忽略
                continue;
            }

            try {
                PostRepository::insertNotification([
                    'user_id' => $userId,
                    'sender_id' => $senderId,
                    'type' => 'mention',
                    'post_id' => $postId,
                    'comment_id' => $commentId,
                    'content' => mb_substr($type === 'post' ? '在动态中提到了你' : '在评论中提到了你', 0, 255),
                    'is_read' => 0,
                    'created_at' => Helper::now()
                ]);
                // P1-3：实时推送给被 @ 的人
                try {
                    SsePush::push($userId, 'notification', [
                        'type' => 'mention',
                        'post_id' => $postId,
                        'comment_id' => $commentId,
                        'sender_id' => $senderId,
                    ]);
                } catch (\Throwable $_) {}
            } catch (\Throwable $e) {
                error_log('[Mention notification] ' . $e->getMessage()); // @allow-error-log
            }
        }
    }
}
