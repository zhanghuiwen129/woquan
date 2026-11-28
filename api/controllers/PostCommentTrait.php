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
use Hublog\Core\Exception\HublogException;
use Hublog\Core\SsePush;

trait PostCommentTrait
{
    public function like(Request $request): Response
    {
        $user = Auth::check($request);
        if ($user['role'] !== 'admin' && !$this->checkUserPermission('allow_like')) {
            return Response::error('点赞功能未开启', 403);
        }

        $postId = $request->getInt('post_id');
        if (!$postId) {
            return Response::error('参数错误', 400);
        }

        // P1-7：先查动态是否存在且对当前用户可见，避免无效操作导致 500 / 越权写入
        $postExists = PostRepository::findActivePostId($postId, (int)$user['id']);
        if (!$postExists) {
            return Response::error('动态不存在', 404);
        }

        // P1-7：解决 TOCTOU 竞态，用 SELECT 再判断的方式不安全，直接 INSERT 加唯一键
        // 依赖 uk_post_user 唯一键防止 500；INSERT IGNORE 与计数增减事务已整体下沉 Repository
        $liked = PostRepository::togglePostLike($postId, (int)$user['id'], Helper::now());

        Cache::delete("post_likes_{$postId}");
        // P0-11：版本号升版替代全量前缀删除
        Cache::bumpNsVersion('timeline');
        // 点赞/取消点赞会影响获赞数和点赞数的缓存
        $this->clearCache('user_profile_');
        Hook::doAction('post_liked', $postId, $user['id'], $liked);

        // V83：点赞是高频操作，节流 120s 只重算一次（点赞数徽章 / 热评徽章）
        BadgeService::evaluateOnAction((int)$user['id']);

        $likes = PostRepository::fetchPostLikerUsers($postId);
        // 匿名帖的点赞人列表不暴露真实身份（count 仍按原始行数）
        if (!empty($postExists['is_anonymous'])) {
            $likes = array_map(static function (): array {
                return ['id' => 0, 'username' => 'anonymous', 'nickname' => '匿名用户'];
            }, $likes);
        }

        // 点赞成功时创建通知（非核心副作用）
        if ($liked) {
            $this->createNotification($postId, $user['id'], 'like');
        }

        return Response::success([
            'liked' => $liked,
            'like_count' => count($likes),
            'likes' => $likes
        ]);
    }

    /**
     * 获取动态的点赞用户列表
     */

    public function postLikes(Request $request): Response
    {
        $viewer = Auth::user($request);
        $viewerId = (int)($viewer['id'] ?? 0);
        $postId = $request->getInt('post_id');
        if (!$postId) {
            return Response::error('参数错误', 400);
        }
        // 不可见的帖子连点赞人列表都不能探到
        $post = PostRepository::findActivePostId($postId, $viewerId);
        if ($post === null) {
            return Response::error('动态不存在', 404);
        }
        $page = max(1, $request->getInt('page', 1));
        $limit = min(50, max(5, $request->getInt('limit', 20)));
        $offset = ($page - 1) * $limit;

        $total = PostRepository::countPostLikers($postId);
        $list = PostRepository::fetchPostLikerPage($postId, $offset, $limit);
        if (!empty($post['is_anonymous'])) {
            $list = array_map(static function (): array {
                return [
                    'id' => 0, 'username' => 'anonymous', 'nickname' => '匿名用户',
                    'avatar' => null, 'bio' => null, 'vip_level' => 0, 'avatar_frame' => 'none',
                    'follower_count' => 0, 'post_count' => 0,
                ];
            }, $list);
        }
        $pages = $limit > 0 ? (int) ceil($total / $limit) : 0;

        return Response::success([
            'list' => $list,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $pages,
            ],
        ]);
    }

    /**
     * 置顶 / 取消置顶
     */
    /**
     * POST /api/posts/comment/pin —— 置顶 / 取消置顶评论
     *
     * 权限：动态作者本人或管理员。一条动态同时只允许一条置顶评论，
     * 所以置顶新评论前先把该动态下其它置顶清掉。
     */

    public function pinComment(Request $request): Response
    {
        $user = Auth::check($request);
        $commentId = $request->getInt('comment_id');
        $postId = $request->getInt('post_id');
        if (!$commentId || !$postId) {
            return Response::error('参数错误', 400);
        }

        $post = PostRepository::findPostOwnerForPin($postId);
        if (!$post) {
            return Response::error('动态不存在', 404);
        }
        if ((int)$post['user_id'] !== (int)$user['id'] && $user['role'] !== 'admin') {
            return Response::error('只有动态作者可以置顶评论', 403);
        }

        $comment = PostRepository::findCommentForPin($commentId, $postId);
        if (!$comment) {
            return Response::error('评论不存在', 404);
        }

        $willPin = empty($comment['is_pinned']);
        try {
            PostRepository::setPinnedComment($postId, $commentId, $willPin);
        } catch (\Throwable $e) {
            // V47 迁移未执行时 is_pinned 列不存在，明确提示而不是静默失败
            return Response::error('置顶失败，请确认已执行数据库升级', 500);
        }

        Cache::delete("post_comments_{$postId}");
        Cache::delete("post_comments_raw_{$postId}");
        return Response::success(['pinned' => $willPin], $willPin ? '已置顶' : '已取消置顶');
    }

    public function commentLike(Request $request): Response
    {
        $user = Auth::check($request);
        if ($user['role'] !== 'admin' && !$this->checkUserPermission('allow_like')) {
            return Response::error('点赞功能未开启', 403);
        }
        $commentId = $request->getInt('comment_id');
        if (!$commentId) {
            return Response::error('参数错误', 400);
        }
        $comment = PostRepository::findCommentBasic($commentId);
        if (!$comment) {
            return Response::error('评论不存在', 404);
        }

        // 兼容处理：comment_likes 表不存在时降级
        $tableExists = PostRepository::commentLikesTableExists();
        if (!$tableExists) {
            return Response::success([
                'liked' => false,
                'like_count' => 0,
                'unsupported' => true,
            ], '评论点赞功能暂未支持');
        }

        $exists = PostRepository::findCommentLike($commentId, (int)$user['id']);
        $liked = PostRepository::toggleCommentLike(
            $commentId,
            (int)$user['id'],
            $exists ? (int)$exists['id'] : null,
            Helper::now()
        );

        $count = PostRepository::countCommentLikes($commentId);

        Cache::delete("post_comments_{$comment['post_id']}");
        Cache::delete("post_comments_raw_{$comment['post_id']}");
        Cache::delete("post_{$comment['post_id']}");
        // P0-11：版本号升版替代全量前缀删除（评论点赞影响热门榜与信息流计数）
        Cache::bumpNsVersion('timeline');
        Cache::bumpNsVersion('popular');

        if ($liked && $comment['user_id'] !== $user['id']) {
            try {
                PostRepository::insertNotification([
                    'user_id' => $comment['user_id'],
                    'sender_id' => $user['id'],
                    'type' => 'comment_like',
                    'post_id' => $comment['post_id'],
                    'comment_id' => $commentId,
                    'is_read' => 0,
                    'created_at' => Helper::now(),
                ]);
                // P1-3：实时推送给被点赞者
                try {
                    SsePush::push((int)$comment['user_id'], 'notification', [
                        'type' => 'comment_like',
                        'post_id' => $comment['post_id'],
                        'comment_id' => $commentId,
                        'sender_id' => $user['id'],
                    ]);
                } catch (\Throwable $_) {}
            } catch (\Throwable $_) {
                // 通知写入失败忽略
            }
        }

        // V83：评论点赞双向影响徽章——点赞者的「点赞数」，被赞者的「热评数」
        //      高频操作，节流 120s
        BadgeService::evaluateOnAction((int)$user['id']);
        if (!empty($comment['user_id']) && (int)$comment['user_id'] !== (int)$user['id']) {
            BadgeService::evaluateOnAction((int)$comment['user_id']);
        }

        return Response::success([
            'liked' => $liked,
            'like_count' => $count,
        ]);
    }

    /**
     * 创建通知
     * 给动态作者或回复对象发送点赞/评论/回复通知
     */

    private function createNotification(int $postId, int $senderId, string $type, ?int $commentId = null, ?string $content = null, ?int $extraReceiverId = null): void
    {
        try {
            $post = PostRepository::findPostUserIdForNotify($postId);
            if (!$post) {
                return;
            }

            // 不给自己发通知
            if ($post['user_id'] !== $senderId) {
                PostRepository::insertNotification([
                    'user_id' => $post['user_id'],
                    'sender_id' => $senderId,
                    'type' => $type,
                    'post_id' => $postId,
                    'comment_id' => $commentId,
                    'content' => $content,
                    'is_read' => 0,
                    'created_at' => Helper::now()
                ]);
                // P1-3：实时推送给被通知者
                try {
                    SsePush::push((int)$post['user_id'], 'notification', [
                        'type' => $type,
                        'post_id' => $postId,
                        'comment_id' => $commentId,
                        'sender_id' => $senderId,
                        'content' => $content,
                    ]);
                } catch (\Throwable $_) {}
            }

            // 如果有额外接收人（回复场景），也给被回复的人发通知
            if ($extraReceiverId && $extraReceiverId !== $senderId && $extraReceiverId !== $post['user_id']) {
                PostRepository::insertNotification([
                    'user_id' => $extraReceiverId,
                    'sender_id' => $senderId,
                    'type' => 'reply',
                    'post_id' => $postId,
                    'comment_id' => $commentId,
                    'content' => $content,
                    'is_read' => 0,
                    'created_at' => Helper::now()
                ]);
                // P1-3：实时推送给被通知者
                try {
                    SsePush::push((int)$extraReceiverId, 'notification', [
                        'type' => 'reply',
                        'post_id' => $postId,
                        'comment_id' => $commentId,
                        'sender_id' => $senderId,
                        'content' => $content,
                    ]);
                } catch (\Throwable $_) {}
            }
        } catch (\Throwable $e) {
            // 通知写入失败不影响主流程
            error_log('[Hublog Notification] ' . $e->getMessage()); // @allow-error-log
        }
    }

    /**
     * V57：解析评论配图
     *
     * 入参可能是 uni-app 的 `images: [...]`（multipart 下会变成同名多值或 JSON 字符串）、
     * 也可能是老客户端的单张 `image`。这里统一收敛成「最多 9 张、协议白名单、去空去重」的数组。
     *
     * @return string[]
     */

    private static function parseCommentImages(Request $request): array
    {
        $raw = $request->get('images');

        $list = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if (is_string($v) && $v !== '') {
                    $list[] = $v;
                }
            }
        } elseif (is_string($raw) && $raw !== '') {
            // 两种常见形态：JSON 数组 / 逗号分隔
            $trimmed = trim($raw);
            if ($trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $v) {
                        if (is_string($v) && $v !== '') {
                            $list[] = $v;
                        }
                    }
                }
            } else {
                foreach (explode(',', $trimmed) as $v) {
                    $v = trim($v);
                    if ($v !== '') {
                        $list[] = $v;
                    }
                }
            }
        }

        // 协议白名单：只允许 http/https 与站点相对路径，挡掉 javascript:/data: 之类
        $clean = [];
        $seen = [];
        foreach ($list as $url) {
            $url = trim($url);
            if ($url === '' || count($clean) >= 9) {
                continue;
            }
            if (strlen($url) > 500) {
                continue;
            }
            $isRelative = str_starts_with($url, '/') && !str_starts_with($url, '//');
            $isHttp = preg_match('#^https?://#i', $url) === 1;
            if (!$isRelative && !$isHttp) {
                continue;
            }
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = 1;
            $clean[] = $url;
        }

        return $clean;
    }

    public function comment(Request $request): Response
    {
        $user = Auth::check($request);
        if ($user['role'] !== 'admin' && !$this->checkUserPermission('allow_comment')) {
            return Response::error('评论功能未开启', 403);
        }

        // ====== V71：全站禁言前置校验 ======
        try {
            Moderation::assertNotMuted($user);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        }

        $data = (new Validator($request->all()))
            ->required('post_id')
            ->int('post_id', 1)
            ->string('content', 0, 1000)
            ->validate();

        // ====== V57：图片评论（多图，最多 9 张）======
        // 兼容三种传法：images[] 数组 / images JSON 字符串 / image 单图（老客户端）
        $image = $request->getString('image');
        $imageList = self::parseCommentImages($request);
        $empty = empty($data['content']) && empty($image) && empty($imageList);
        if ($empty) {
            return Response::error('评论内容不能为空', 400);
        }

        $post = PostRepository::findActivePostId((int)$data['post_id'], (int)$user['id']);
        if (!$post) {
            return Response::error('动态不存在', 404);
        }

        $parentId = $request->getInt('parent_id', 0);
        $parentReceiverId = null;
        if ($parentId > 0) {
            $parent = PostRepository::findParentComment($parentId, (int)$data['post_id']);
            if (!$parent) {
                return Response::error('父评论不存在', 404);
            }
            $parentReceiverId = (int)$parent['user_id'];
        }

        // 老单图字段兜底：只给了 image 没给 images 时，把它并进图片列表
        if (empty($imageList) && !empty($image)) {
            $imageList = [$image];
        } elseif (empty($imageList) && !empty($image)) {
            $imageList = [$image];
        }
        $imageJson = $imageList ? json_encode($imageList, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        // ====== V71：评论正文敏感词过滤 ======
        // 评论没有 status 字段，命中 review 级只记日志放行（后台在命中记录里能看到）；
        // block 级直接拦下；replace 级打码后照发。
        $fComment = Moderation::filter(
            (string)($data['content'] ?? ''),
            'comment',
            (int)$user['id'],
            (int)$data['post_id']
        );
        if (!$fComment['ok']) {
            return Response::error($fComment['msg'], 403);
        }
        $commentContent = Security::purify($fComment['text']);

        // ====== P2-12：评论去重（防重复提交/网络抖动重发）======
        // 内容 + 图片 + 用户（前200字） + 相同 parent_id + 5秒内 = 视为重复提交
        $idempotentWindow = 5; // 秒
        // V71：用过滤并 purify 后的正文算 hash，保证与落库内容一致（旧实现用原始 content，永远对不上）
        $contentHash = substr(md5($commentContent . '|' . ($imageJson ?? '')), 0, 16);
        $existing = PostRepository::findRecentDuplicateComment(
            (int)$data['post_id'],
            (int)$user['id'],
            $parentId,
            $contentHash,
            $idempotentWindow
        );
        if ($existing) {
            // 重复了则直接返回已有记录，不再 insert + 增加 comment_count
            $comment = PostRepository::findCommentWithUser((int)$existing['id']);
            return Response::success(['comment' => $comment], '评论发布成功（去重命中）');
        }

        $commentData = [
            'post_id' => $data['post_id'],
            'user_id' => $user['id'],
            'parent_id' => $parentId > 0 ? $parentId : null,
            'content' => $commentContent,
            'created_at' => Helper::now()
        ];
        if (!empty($image)) {
            $commentData['image'] = Security::purify($image);
        }
        if (!empty($imageList)) {
            $commentData['images'] = $imageJson;
        }
        $commentId = PostRepository::insertComment($commentData);

        // P2-1/P2-6：评论成功后 posts.comment_count +1，用 GREATEST/LEAST 防溢出
        PostRepository::incrementPostCommentCount((int)$data['post_id']);

        // 给动态作者或被回复用户发送通知
        $this->createNotification(
            $data['post_id'],
            $user['id'],
            $parentId > 0 ? 'reply' : 'comment',
            $commentId,
            substr(Security::purify($data['content']), 0, 240),
            $parentReceiverId
        );

        // Process comment mentions
        $mentions = $request->getArray('mentions');
        if (!empty($mentions)) {
            $settings = $this->getSiteSettings();
            $mentionMaxCount = (int)($settings['mention_max_count'] ?? 10);
            $mentions = array_slice(array_unique($mentions), 0, $mentionMaxCount);
            foreach ($mentions as $mentioned) {
                // 前端传的是 username（从正文 @xxx 提取的），必须按 username 查出 user_id 再入库。
                // 原先直接 (int)$mentioned，字符串强转得到 0，于是往 mentions/notifications
                // 里写一堆 user_id=0 的脏数据，被 @ 的人一条通知都收不到。
                $mentionedUserId = 0;
                $target = PostRepository::findActiveUserByUsername((string)$mentioned);
                if ($target) {
                    $mentionedUserId = (int)$target['id'];
                } elseif (is_numeric($mentioned)) {
                    // 兼容：个别端可能直接传 user_id
                    $mentionedUserId = (int)PostRepository::findActiveUserId((int)$mentioned);
                }
                // 查不到（用户不存在/已禁用）或 @ 自己，直接跳过，不写脏数据
                if ($mentionedUserId <= 0 || $mentionedUserId === (int)$user['id']) {
                    continue;
                }
                PostRepository::insertMention(
                    (int)$data['post_id'],
                    $commentId,
                    $mentionedUserId,
                    (int)$user['id'],
                    'comment',
                    Helper::now()
                );
                // Create notification for mentioned user
                // P0-A：notifications 表无 title 列，写入会抛 1054；评论主链路无事务，
                // 异常冒泡会让「评论已落库但接口返回 500」的半成功状态。这里去掉越界的
                // title 字段，并单独包 try/catch，保证通知写入失败绝不影响评论发布主流程。
                try {
                    PostRepository::insertNotification([
                        'user_id' => (int)$mentionedUserId,
                        'type' => 'mention',
                        // 通知内容必须用过滤并 purify 后的正文，避免原始 HTML/敏感词进入通知
                        'content' => mb_substr($commentContent, 0, 100),
                        'post_id' => $data['post_id'],
                        'comment_id' => $commentId,
                        'sender_id' => $user['id'],
                        'is_read' => 0,
                        'created_at' => Helper::now()
                    ]);
                } catch (\Throwable $e) {
                    \Hublog\Core\Logger::error('[Hublog CommentMention] 通知写入失败: ' . $e->getMessage());
                }
                // P1-3：实时推送给被 @ 的人
                try {
                    SsePush::push((int)$mentionedUserId, 'notification', [
                        'type' => 'mention',
                        'post_id' => $data['post_id'],
                        'comment_id' => $commentId,
                        'sender_id' => $user['id'],
                    ]);
                } catch (\Throwable $_) {}
            }
        }

        Cache::delete("post_comments_{$data['post_id']}");
        Cache::delete("post_comments_raw_{$data['post_id']}");
        Cache::delete("post_{$data['post_id']}");
        // P0-11：版本号升版替代全量前缀删除
        Cache::bumpNsVersion('timeline');
        // 评论数变化也影响用户资料缓存
        $this->clearCache('user_profile_');
        Hook::doAction('post_commented', $data['post_id'], $user['id'], $commentId);

        // V83：评论后重算统计类徽章（评论数/楼中楼回复/长评论/沙发等）。
        //      评论较低频，不节流；失败只记日志。
        BadgeService::evaluateOnAction((int)$user['id'], 0);

        $comment = PostRepository::findCommentWithUser($commentId);

        $post = PostRepository::findPostAuthorWithUser((int)$data['post_id']);

        return Response::success([
            'comment' => $this->formatComment($comment),
            'post_user_id' => $post['user_id'] ?? 0
        ], '评论成功');
    }

    public function deleteComment(Request $request): Response
    {
        $user = Auth::check($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }

        $comment = PostRepository::findCommentForDelete($id);
        if (!$comment) {
            return Response::error('评论不存在', 404);
        }
        if ($comment['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            return Response::error('无权限', 403);
        }

        // P2-8：删除评论时，id=? 或 parent_id=? 的都要删（递归子评论）
        // 理论上数据库 FK comments.parent_id ON DELETE CASCADE 会自动级联
        // 但 comment_likes/notifications/reports(target_type=comment) 等需要手动处理
        // 为了稳妥起见，手动 BFS 收集 / 删除 / 更新计数
        // 防止评论楼中楼过深导致 PHP 栈溢出，限制 16 层递归深度
        // 手动删 comments 表（即使有 CASCADE 也手动执行更可靠）
        $allIds = $this->collectCommentSubtree($id);

        // 计数回退 / comment_likes / notifications / reports / comments 的清理顺序与事务边界原样下沉
        PostRepository::deleteCommentCascade((int)$comment['post_id'], $allIds);

        Cache::delete("post_comments_{$comment['post_id']}");
        Cache::delete("post_comments_raw_{$comment['post_id']}");
        Cache::delete("post_{$comment['post_id']}");
        // P0-11：版本号升版替代全量前缀删除
        Cache::bumpNsVersion('timeline');
        $this->clearCache('user_profile_');
        Cache::bumpNsVersion('popular');

        return Response::success(null, '删除成功');
    }

    /**
     * P2-8：BFS 收集某条评论及其所有子评论的 ID
     * 用 BFS 迭代实现，不用 WITH RECURSIVE CTE（MySQL 5.7 不支持 CTE）
     * 限制 16 层深度，防止楼中楼无限嵌套导致死循环
     *
     * @param int $rootId 根评论 ID
     * @return int[] 所有子评论 ID 数组（含 root）
     */

    private function collectCommentSubtree(int $rootId): array
    {
        $visited = [$rootId];
        $queue = [$rootId];
        $depth = 0;
        $maxDepth = 16;

        while (!empty($queue) && $depth < $maxDepth) {
            // 收集当前层 parent 的子评论 ID
            $parents = array_values($queue);
            $queue = [];
            $rows = PostRepository::fetchCommentChildIds($parents);
            foreach ($rows as $row) {
                $cid = (int)$row['id'];
                if (!in_array($cid, $visited, true)) {
                    $visited[] = $cid;
                    $queue[] = $cid;
                }
            }
            $depth++;
        }
        return array_values(array_map('intval', $visited));
    }

    // ==================== 格式化方法（委托给 PostFormatter） ====================

    private function formatPostBatch(array $post, int $currentUserId, array $likes, array $comments, array $blockedSet = []): array
    {
        return PostFormatter::formatPostBatch($post, $currentUserId, $likes, $comments, $blockedSet);
    }

    private function formatPost(array $post, int $currentUserId): array
    {
        return PostFormatter::formatPostSingle($post, $currentUserId);
    }

    private function formatComment(array $comment): array
    {
        return PostFormatter::formatComment($comment);
    }
}
