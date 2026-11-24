<?php

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
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

trait PostUpdateTrait
{
    public function update(Request $request): Response
    {
        $user = Auth::check($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }

        // 待发布（定时）动态 status=0。这里必须把它一起放行，
        // 否则作者给定时动态改时间 / 立即发布 / 编辑内容时一律 404「动态不存在」，
        // 排完队就再也动不了。
        // V169：再放行「因审核待审」的动态（status=0 且 audit_state=1）——
        // 作者收到站内信后要能进编辑页改词重发，否则站内信闭环断在这里。
        // 管理员下架（status=0 且 audit_state=0）仍查不到，保持原有行为。
        // V134：把 post_type 查出来——帖型帖（问答/组队/打卡/投票）允许正文为空，
        // 普通帖仍要求正文至少 1 个字符（见下方 Validator）。
        $post = PostRepository::findPostForEdit($id);
        // 后台管理：管理员可编辑任意状态动态（含已下架）；作者仍仅限正常/定时动态
        if (!$post && $user['role'] === 'admin') {
            $post = PostRepository::findAnyPostForAdminEdit($id);
        }
        if (!$post) {
            return Response::error('动态不存在', 404);
        }
        if ($post['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            return Response::error('无权限', 403);
        }

        $settings = $this->getSiteSettings();
        $maxLength = (int)($settings['post_max_length'] ?? '2000');
        $maxLength = max(100, $maxLength);

        // V134：帖型帖允许清空正文（内容在帖型表单里）；普通帖清空正文仍拦。
        $isTypePost = in_array((string)($post['post_type'] ?? 'normal'), ['question', 'team', 'checkin', 'poll'], true);
        $validator = new Validator($request->all());
        $validator->string('content', $isTypePost ? 0 : 1, $maxLength);
        if ($request->has('title')) {
            $validator->string('title', 0, 255);
        }
        $data = $validator->validate();

        // 字段解析 + 归属校验 + 临时键剥离（逻辑与重构前逐字一致）。
        $out = [];
        $validationError = $this->resolveUpdateFields($request, $settings, $user, $id, $data, $out);
        if ($validationError !== null) {
            return $validationError;
        }

        // V169：命中审核级敏感词 → 转待审（status=0 + audit_state=1）。
        // 管理员已下架的动态（status=0 且 audit_state=0 且非定时）不参与这套流转，
        // 否则作者改一下就能把下架内容「洗」成待审态、重新拿回编辑权。
        $reviewHitWords = array_values(array_unique((array)($out['reviewHitWords'] ?? [])));
        $isAdminRemoved = (int)$post['status'] === 0
            && (int)($post['audit_state'] ?? 0) === 0
            && empty($post['scheduled_at']);
        if (!empty($reviewHitWords) && !$isAdminRemoved) {
            $out['updateData']['status'] = 0;
            $out['updateData']['audit_state'] = 1;
            // 内容有问题就不该再按原计划定时自动发布
            $out['updateData']['scheduled_at'] = null;
        }

        // 圈内发帖审核。待审中的圈内动态不允许变更圈子归属 ——
        // 移出后 ContentAudit 的圈内守卫不再命中，会被自动放行，形成审核绕过。
        $originalCircleId = (int)($post['circle_id'] ?? 0);
        if ($originalCircleId > 0 && (int)($post['status'] ?? 1) === 0
            && (int)($post['audit_state'] ?? 0) === 1
            && $out['newCircleId'] !== null && $out['newCircleId'] !== $originalCircleId) {
            $originCircle = PostRepository::findCircleForPost($originalCircleId);
            if ($originCircle && (int)($originCircle['post_review'] ?? 0) === 1) {
                return Response::error('待审核的动态暂不能变更所属圈子', 403);
            }
        }

        // 编辑后重新进入圈内待审，避免「审核通过后改一次内容就绕过审核」。
        // 圈主/圈管与平台管理员免审；管理员已下架的内容仍不参与流转（与敏感词重审同口径）。
        $effectiveCircleId = $out['newCircleId'] ?? $originalCircleId;
        if ($effectiveCircleId > 0 && !$isAdminRemoved) {
            $targetCircle = PostRepository::findCircleForPost($effectiveCircleId);
            $memberRow = PostRepository::findCircleMemberForPost($effectiveCircleId, (int)$user['id']);
            $isCircleManager = $memberRow && in_array($memberRow['role'], ['owner', 'admin'], true);
            if ($targetCircle && (int)($targetCircle['post_review'] ?? 0) === 1
                && !$isCircleManager && ($user['role'] ?? '') !== 'admin') {
                $out['updateData']['status'] = 0;
                $out['updateData']['audit_state'] = 1;
                $out['updateData']['scheduled_at'] = null;
            }
        }

        // 事务内落库：posts 更新 + @提及关系 + uploads 重绑 + 话题刷新
        $this->persistPostUpdate(
            $id,
            (int)$user['id'],
            $out['updateData'],
            $out['newMentions'],
            $out['newImages'],
            $out['newVideos'],
            $out['newAttachments']
        );

        // 圈子计数维护 + 缓存失效 + hook
        $this->afterPostUpdate($id, (int)$user['id'], $out['newCircleId'], (int)($post['circle_id'] ?? 0));

        // V169：命中审核级词就告诉作者是哪些词，用户才知道该改哪里
        if (!empty($reviewHitWords) && !$isAdminRemoved) {
            AuditNotice::notify($id, 'post', $reviewHitWords);
        }

        // V169：待审动态改完后由插件重审（post_updated 钩子里同步执行完），
        // 这里重查一次最终状态，如实告诉前端是「已恢复公开」还是「仍在审核中」。
        $after = PostRepository::findPostStatusAfterEdit($id);
        $pendingReview = $after
            && (int)$after['status'] === 0
            && (int)$after['audit_state'] === 1;

        return Response::success(
            ['pending_review' => $pendingReview],
            $pendingReview ? '已保存，仍在审核中' : '保存成功'
        );
    }

    /**
     * update() 字段解析与归属校验（V160 步骤拆分）。
     *
     * 逻辑与重构前逐字一致：以 has() 守卫增量更新，依次处理 content/title/category/圈子/
     * 媒体/位置/匿名/原创/自主声明/定时/可见范围/@提及，最后做上传归属校验并剥离临时 _mentions 键。
     * 任一校验失败返回 Response::error；通过则把落库需要的数据装入 $out 并返回 null。
     *
     * @param array $out 输出容器：updateData / newImages / newVideos / newAttachments / newMentions / newCircleId
     * @return Response|null
     */

    private function resolveUpdateFields(Request $request, array $settings, array $user, int $id, array $data, array &$out): ?Response
    {
        $updateData = ['updated_at' => Helper::now()];

        // V169：编辑同样要过敏感词。此前 update 完全不过滤，等于「发版时审一遍、
        // 改完就洗白」—— 用户拿审核提示改个词再保存，违规内容原样公开。
        // replace → 打码后存库；review → 落库后转待审；block → 直接拒绝保存并点名命中词。
        $reviewHitWords = [];
        if (array_key_exists('content', $data)) {
            $newContent = Security::purify($data['content']);
            $fBody = Moderation::filter($newContent, 'post', (int)$user['id'], $id);
            if (!$fBody['ok']) {
                return Response::error($this->sensitiveBlockMsg($fBody), 403);
            }
            $updateData['content'] = $fBody['text'];
            if ($fBody['action'] === 'review') {
                $reviewHitWords = array_merge($reviewHitWords, $fBody['words']);
            }
        }
        if (array_key_exists('title', $data)) {
            $title = trim($data['title']);
            $titleRequired = ($settings['title_required'] ?? '0') === '1';
            if ($titleRequired && $title === '') {
                return Response::error('标题不能为空', 400);
            }
            if ($title === '') {
                $updateData['title'] = null;
            } else {
                $fTitle = Moderation::filter($title, 'post', (int)$user['id'], $id);
                if (!$fTitle['ok']) {
                    return Response::error($this->sensitiveBlockMsg($fTitle), 403);
                }
                $updateData['title'] = Security::purify($fTitle['text']);
                if ($fTitle['action'] === 'review') {
                    $reviewHitWords = array_merge($reviewHitWords, $fTitle['words']);
                }
            }
        }
        if ($request->has('category_id')) {
            $categoryId = $request->getInt('category_id');
            if ($categoryId) {
                $category = PostRepository::findActiveCategory($categoryId);
                if (!$category) {
                    return Response::error('所选分类不存在', 400);
                }
                $updateData['category_id'] = $categoryId;
            } else {
                $updateData['category_id'] = null;
            }
        }
        // V90：编辑时同步圈子归属。create() 早已支持 circle_id，update() 之前完全忽略该字段，
        // 导致「编辑时把动态发布到圈子」静默失效：帖子仍挂在全站，圈子页看不到、
        // 列表卡片也没有圈子标签。这里补齐「校验 + 落库 + 计数」三件事，
        // 并把新值留给下面的可见范围校验复用（同一次编辑里改圈子 + 选仅圈内可见不再误报）。
        $newCircleId = null; // null = 本次请求不涉及圈子变更
        if ($request->has('circle_id')) {
            $newCircleId = $request->getInt('circle_id');
            if ($newCircleId < 0) {
                $newCircleId = 0;
            }
            if ($newCircleId > 0) {
                $circleError = $this->checkCirclePostable($newCircleId, $user);
                if ($circleError !== null) {
                    return $circleError;
                }
            }
            // 0 或空 = 撤出圈子，回到全站动态（posts.circle_id 存 NULL）
            $updateData['circle_id'] = $newCircleId > 0 ? $newCircleId : null;
        }

        $images = $request->getArray('images');
        $videos = $request->getArray('videos');
        $attachments = $request->getArray('attachments');
        // P1-6+：把媒体 URL 归一化为 uploads 表存储的规范 URL（兼容 App 端把相对路径补成绝对地址），
        // 归一化后统一用于「落库 + 归属校验 + 重新绑定」
        [$images, $videos, $attachments] = $this->normalizeOwnMedia((int)$user['id'], $images, $videos, $attachments, (int)$id);

        if ($images !== [] || $request->has('images')) {
            $settings = $this->getSiteSettings();
            $maxImages = (int)($settings['upload_max_images'] ?? '9');
            $updateData['images'] = json_encode(array_slice($images, 0, max(0, $maxImages)));
        }

        if ($videos !== [] || $request->has('videos')) {
            $settings = $this->getSiteSettings();
            $maxVideos = (int)($settings['upload_max_videos'] ?? '3');
            $updateData['videos'] = json_encode(array_slice($videos, 0, max(0, $maxVideos)));
        }

        if ($attachments !== [] || $request->has('attachments')) {
            $settings = $this->getSiteSettings();
            $maxAttachments = (int)($settings['upload_max_attachments'] ?? '5');
            $updateData['attachments'] = json_encode(array_slice($attachments, 0, max(0, $maxAttachments)));
        }

        // 编辑必须支持 location / is_anonymous / scheduled_at。
        // 这三个字段 create() 已支持，update() 之前静默忽略，
        // 导致用户改完位置/匿名/定时后提示「保存成功」但实际没变。
        if ($request->has('location')) {
            $location = trim($request->getString('location'));
            $allowLocation = ($settings['allow_location'] ?? '1') === '1';
            if ($location !== '' && !$allowLocation) {
                return Response::error('站点未开启位置功能', 403);
            }
            $updateData['location'] = $location !== '' ? $location : null;
        }
        if ($request->has('location_lat')) {
            $updateData['location_lat'] = $request->getFloat('location_lat');
        }
        if ($request->has('location_lng')) {
            $updateData['location_lng'] = $request->getFloat('location_lng');
        }
        if ($request->has('is_anonymous')) {
            $isAnonymous = $request->getBool('is_anonymous');
            $allowAnonymous = ($settings['allow_anonymous_post'] ?? '1') === '1';
            if ($isAnonymous && !$allowAnonymous) {
                return Response::error('站点未开启匿名发布', 403);
            }
            $updateData['is_anonymous'] = $isAnonymous ? 1 : 0;
        }
        // V83：原创标记编辑。与匿名同款「has() 才处理」，
        // 否则用户在发布页取消原创勾选会静默不生效（前端已保证编辑时无条件回传）。
        if ($request->has('is_original')) {
            $updateData['is_original'] = $request->getInt('is_original') ? 1 : 0;
        }
        // V89：自主声明编辑。has() 才处理；前端编辑时无条件回传，清空声明也能生效。
        // 声明选「原创」时同步点亮 is_original（原创作者徽章统计链路）；
        // 选其他项/清空时不回写 is_original，避免把历史原创标误洗掉
        if ($request->has('declaration')) {
            $declaration = $this->sanitizeDeclaration((string)$request->getString('declaration'));
            $updateData['declaration'] = $declaration;
            if ($declaration === 'original') {
                $updateData['is_original'] = 1;
            }
        }
        if ($request->has('scheduled_at')) {
            $scheduledAt = trim((string)$request->getString('scheduled_at'));
            if ($scheduledAt === '') {
                // 取消定时：立即发布
                $updateData['scheduled_at'] = null;
                $updateData['status'] = 1;
            } else {
                $scheduledTime = strtotime($scheduledAt);
                if ($scheduledTime === false) {
                    return Response::error('定时发布时间格式不正确', 400);
                }
                // 只允许未来时间；过去时间不静默转成立即发布，直接报错由前端拦截
                if ($scheduledTime <= time()) {
                    return Response::error('定时发布时间必须晚于当前时间', 400);
                }
                $updateData['scheduled_at'] = $scheduledAt;
                $updateData['status'] = 0;
            }
        }

        // V56：编辑时同步可见范围
        if ($request->has('visibility')) {
            $newVisibility = strtolower(trim((string)$request->getString('visibility'))) ?: 'public';
            if (!in_array($newVisibility, self::VISIBILITY_TYPES, true)) {
                return Response::error('可见范围取值无效', 400);
            }
            // V90：本次若同时改了圈子归属就按新值判定（否则读库里现值），
            // 避免「同一次编辑里选圈子 + 选仅圈内可见」被判矛盾而误报 400
            $effectiveCircleId = $newCircleId !== null
                ? $newCircleId
                : (int)(PostRepository::findPostCircleIdForVisibility($id)['circle_id'] ?? 0);
            if ($newVisibility === 'circle' && $effectiveCircleId <= 0) {
                return Response::error('仅圈内可见的动态必须属于某个圈子', 400);
            }
            $updateData['visibility'] = $newVisibility;
        }

        // V164（决策 A 对称拦截）：编辑后若最终为「私密 + 定时」组合，与 create() 一致直接拒绝。
        // 编辑是增量更新，必须把本次增量与既有帖合并得到最终值再判定，否则会出现
        // 「改私密但不动定时」「改定时但不动私密」漏拦，与 create 规则不一致。
        // 既有帖的 visibility / scheduled_at 仅查一次。
        $existing = PostRepository::findPostVisibilityState($id) ?: [];
        // 缺失可见范围按默认拒绝处理，不能回落 public 绕过「私密+定时」拦截
        $effectiveVisibility = $updateData['visibility'] ?? ($existing['visibility'] ?? 'private');
        $effectiveScheduled = $request->has('scheduled_at')
            ? ($updateData['scheduled_at'] !== null)
            : (!empty($existing['scheduled_at']));
        if ($effectiveVisibility === 'private' && $effectiveScheduled) {
            return Response::error('私密动态不支持定时发布', 400);
        }

        // @提及（create 已支持，编辑时同步刷新 mentions 表与通知）
        if ($request->has('mentions')) {
            $mentions = $request->getArray('mentions');
            $allowMention = ($settings['allow_mention'] ?? '0') === '1';
            $maxMentions = (int)($settings['mention_max_count'] ?? '10');
            if (!empty($mentions) && !$allowMention) {
                return Response::error('@提及功能未开启', 403);
            }
            $updateData['_mentions'] = array_slice(array_unique(array_map('strval', $mentions)), 0, $maxMentions);
        }

        // P1-6 编辑时同样校验图片/视频/附件 URL 归属权
        // 防止用户编辑时传入不属于自己的 URL
        // 注：URL 已在上方归一化为 uploads 表中存储的规范值，这里直接复用，
        //     避免再次读到客户端原始（可能是被 normalizeAssets 补成绝对地址）的值而误判
        //     编辑态传入 $id，使本动态已绑定的图片（post_id = $id）也能通过校验
        $newImages = $images;
        $newVideos = $videos;
        $newAttachments = $attachments;
        $uploadCheckError = $this->assertUploadsBelongToUser($user['id'], $newImages, $newVideos, $newAttachments, (int)$id, $user['role'] === 'admin');
        if ($uploadCheckError !== null) {
            return Response::error($uploadCheckError, 403);
        }

        // _mentions 只是传递用的临时键，不属于 posts 表字段，写库前必须剥离
        $newMentions = null;
        if (array_key_exists('_mentions', $updateData)) {
            $newMentions = $updateData['_mentions'];
            unset($updateData['_mentions']);
        }

        $out = [
            'updateData' => $updateData,
            'newImages' => $newImages,
            'newVideos' => $newVideos,
            'newAttachments' => $newAttachments,
            'newMentions' => $newMentions,
            'newCircleId' => $newCircleId,
            'reviewHitWords' => $reviewHitWords,
        ];
        return null;
    }

    /**
     * update() 事务内落库（V160 步骤拆分）：
     * posts 更新 → @提及关系（仅新增发通知）→ uploads 重绑 → 话题标签刷新。
     * 任一子步骤失败整条事务回滚。
     */

    private function persistPostUpdate(
        int $id,
        int $userId,
        array $updateData,
        ?array $newMentions,
        array $newImages,
        array $newVideos,
        array $newAttachments
    ): void {
        // 事务边界、@提及刷新、uploads 重绑、tags 刷新的调用顺序整体下沉到仓储。
        // extractAndSavePostTags 仍属 PostCrudTrait 的编排逻辑，以回调注入，
        // 但其执行与 begin/commit/rollBack 同处一个 Repository 方法内。
        PostRepository::persistPostUpdate(
            $id,
            $userId,
            $updateData,
            $newMentions,
            $newImages,
            $newVideos,
            $newAttachments,
            function (string $content) use ($id): void {
                $this->extractAndSavePostTags($id, $content);
            }
        );
    }

    /**
     * update() 后置副作用（V160 步骤拆分）：
     * 圈子归属变化计数维护、时间线/详情/评论/点赞缓存失效、post_updated hook。
     */

    private function afterPostUpdate(int $id, int $userId, ?int $newCircleId, int $oldCircleId): void
    {
        // V90：圈子归属变化时同步维护圈子动态计数（离开旧圈 -1、进入新圈 +1）
        if ($newCircleId !== null && $oldCircleId !== $newCircleId) {
            $this->adjustCirclePostCount($oldCircleId, -1);
            $this->adjustCirclePostCount($newCircleId, 1);
        }

        // 编辑完成后清理相关缓存
        // P0-11：版本号升版替代全量前缀删除（旧 key 靠 TTL 自然过期，不再每次 glob 扫描删文件）
        Cache::bumpNsVersion('timeline');
        Cache::delete("post_{$id}");
        Cache::delete("post_comments_{$id}");
        Cache::delete("post_comments_raw_{$id}");
        // P2-9：清除点赞缓存，因为 update 可能改变点赞数/点赞列表的显示
        Cache::delete("post_likes_{$id}");
        Hook::doAction('post_updated', $id, $userId);
    }

    /**
     * V56：转发动态
     * POST /posts/repost  body: post_id（原动态）、content（转发语，可空）、circle_id（目标圈子，可空）
     * 转发规则：只记录 origin_id 指向原动态，不复制图片/视频；原动态删除后展示「原动态已删除」占位。
     */

    public function repost(Request $request): Response
    {
        $user = Auth::check($request);
        if ($user['role'] !== 'admin' && !$this->checkUserPermission('allow_post')) {
            return Response::error('你没有发布权限', 403);
        }

        $originId = $request->getInt('post_id');
        if (!$originId) {
            return Response::error('参数错误', 400);
        }

        $origin = PostRepository::findPostForRepost($originId);
        if (!$origin) {
            return Response::error('原动态不存在', 404);
        }
        if (!$this->canViewPost($origin, $user['id'])) {
            return Response::error('原动态不存在', 404);
        }
        // 不给自己的动态做转发，避免自引用死循环卡片
        if ((int)$origin['user_id'] === (int)$user['id']) {
            return Response::error('不能转发自己的动态', 400);
        }

        $content = trim((string)Security::purify((string)$request->getString('content')));
        if ($content === '') {
            return Response::error('请输入转发语', 400);
        }
        $settings = $this->getSiteSettings();
        $maxLength = (int)($settings['post_max_length'] ?? '2000');
        if (mb_strlen($content) > $maxLength) {
            return Response::error('转发语超出长度限制', 400);
        }

        // P2：转发此前直接 INSERT，绕开 create() 的同步敏感词过滤，
        // block 级只能靠 post_created 钩子的 ContentAudit 兜底（异步且可吞错）。
        // 与 PostCrudTrait::create() 同款：block 硬拒，review 置待审，replace 用打码后文本。
        $fRepost = Moderation::filter($content, 'post', (int)$user['id'], 0);
        if (!$fRepost['ok']) {
            return Response::error($this->sensitiveBlockMsg($fRepost), 403);
        }
        $content = (string)$fRepost['text'];
        $repostNeedsReview = $fRepost['action'] === 'review';

        // 目标圈子：默认沿用原动态所属圈子；不传则发成全站动态
        $circleId = $request->getInt('circle_id');
        if ($circleId <= 0) {
            $circleId = (int)($origin['circle_id'] ?? 0);
        }
        if ($circleId > 0) {
            $circle = PostRepository::findCircleForRepost($circleId);
            if (!$circle || (int)$circle['status'] !== 1) {
                return Response::error('圈子不存在', 400);
            }
            $memberRow = PostRepository::findCircleMemberForPost($circleId, (int)$user['id']);
            if (!$memberRow) {
                return Response::error('你不是该圈子成员，无法转发到此圈子', 403);
            }
            // V65：圈内永久黑名单
            if ((int)$memberRow['status'] === 2) {
                return Response::error('你已被本圈子列入黑名单，无法转发到此圈子', 403);
            }
            if ($memberRow['role'] !== 'owner' && ($circle['post_permission'] ?? 'all') === 'owner') {
                return Response::error('本圈子仅圈主可发帖', 403);
            }
        }

        $postId = PostRepository::insertPost([
            'user_id' => $user['id'],
            'content' => $content,
            'circle_id' => $circleId > 0 ? $circleId : null,
            'origin_id' => $originId,
            'visibility' => 'public',
            'status' => $repostNeedsReview ? 0 : 1,
            'audit_state' => $repostNeedsReview ? 1 : 0,
            'created_at' => Helper::now(),
            'updated_at' => Helper::now()
        ]);

        if ($circleId > 0) {
            try {
                PostRepository::incrementCirclePostCountForRepost($circleId, Helper::now());
                Cache::deleteByPrefix('circles_list_');
            } catch (\Throwable $e) {
                // 计数失败不阻断主流程
            }
        }
        // P0-11：版本号升版替代全量前缀删除（旧 key 靠 TTL 自然过期，不再每次 glob 扫描删文件）
        Cache::bumpNsVersion('timeline');
        Hook::doAction('post_created', $postId, $user['id']);

        // V83：同上，转发/投票类发帖也要重算统计徽章
        BadgeService::evaluateOnAction((int)$user['id'], 0);

        $raw = PostRepository::findAnyWithAuthorById($postId);
        $post = $this->formatPost($raw, $user['id']);
        // 新帖自身的付费墙也要裁剪，防止转发回显里带出未解锁正文/媒体
        ContentPayService::scrubFormatted($post, $raw, (int)$user['id']);
        $post['origin_post'] = $this->buildOriginPost($originId, $user['id']);

        return Response::success($post, '转发成功');
    }
}
