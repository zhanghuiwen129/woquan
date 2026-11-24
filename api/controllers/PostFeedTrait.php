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
use Hublog\Core\UserRepository;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\SsePush;
use Hublog\Core\ContentPayService;

trait PostFeedTrait
{
    /**
     * 获取动态时间线
     * 采用延迟关联（deferred join）+ 先查 ID 再查详情的方式优化性能
     */

    public function timeline(Request $request): Response
    {
        $currentUser = Auth::user($request);
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(10, 20);
        $username = $request->getString('username');

        // V66：推荐流（sort=recommend）——登录用户专属，游客自动退回最新
        $recommend = ($request->getString('sort') === 'recommend') && ($currentUser['id'] ?? 0) > 0;

        $userId = $currentUser['id'] ?? 0;
        $categoryId = $request->getInt('category_id');
        // V90：媒体类型过滤（App 首页「视频 / 图文 / 文字」tab）
        //   video = 含视频的动态；image = 含图片的动态；text = 纯文字（无图无视频无附件）
        $media = $request->getString('media');
        if ($media !== '' && !in_array($media, ['video', 'image', 'text'], true)) {
            $media = ''; // 非法值忽略，退回全部
        }
        // following=1：只看关注的人（App 首页「关注」流）
        $followingOnly = ($request->getString('following') === '1') || $request->has('following');
        if ($followingOnly && !$userId) {
            $followingOnly = false; // 游客没有关注关系，退回全部
        }
        // V66：推荐流个性化结果不进 remember —— 用户刚点的「不感兴趣」必须当场生效，
        //      而缓存 key 里拼不出负反馈版本号，套缓存会让他看见刚点过的内容。
        if ($recommend) {
            $ids = $this->recommendPostIds($userId, $offset, $limit);
            $postIds = array_column($ids, 'id');
            $list = $this->hydratePosts($postIds, $userId);
            return Response::success(
                Helper::paginatedResponse($list, $page, $limit, count($postIds)),
                '获取成功'
            );
        }

        // V162：游标翻页（P1-4）—— 向后兼容：仅当客户端显式传 cursor 参数时启用新协议，
        // 旧客户端（不传 cursor）继续走下方 page/offset 逻辑，响应结构完全不变。
        if ($request->has('cursor')) {
            $cursor = max(0, (int)$request->getString('cursor'));
            return $this->timelineCursor($userId, $username, $categoryId, $followingOnly, $media, $cursor, $limit);
        }

        // P0-11：键内嵌 timeline 命名空间版本号——失效靠 bumpNsVersion 换版本，
        // 旧版本 key 靠 TTL 自然过期，不再每次浏览/点赞都全量删缓存
        $cacheKey = "timeline_v4_v" . Cache::nsVersion('timeline') . "_{$page}_{$limit}_{$username}_{$categoryId}_{$userId}_" . ($followingOnly ? 'f1' : 'f0') . "_m{$media}";
        $result = Cache::remember($cacheKey, function () use ($userId, $page, $limit, $offset, $username, $categoryId, $followingOnly, $media) {
            [$where, $params] = $this->buildTimelineWhere($username, $categoryId, $followingOnly, $media, $userId);

            // 1. 先查符合条件的动态 ID（WHERE 与计数共用同一份）
            $ids = PostRepository::fetchTimelinePostIds($where, $params, $offset, $limit);

            if (empty($ids)) {
                $total = $this->getCachedPostCount($where, $params);
                return Helper::paginatedResponse([], $page, $limit, $total);
            }

            // 2. 再批量查详情数据
            $postIds = array_column($ids, 'id');
            $list = $this->hydratePosts($postIds, $userId);
            $total = $this->getCachedPostCount($where, $params);
            return Helper::paginatedResponse($list, $page, $limit, $total);
        });

        return Response::success($result, '获取成功');
    }

    /**
     * V162（P1-4）：构造 timeline 主查询的 WHERE 与参数（可见范围 / 关注 / 用户 / 分类 / 媒体过滤）。
     * 抽出来供原 page/offset 路径与游标路径共用，避免两套过滤逻辑漂移。
     * 无匹配用户（username 不存在）时返回 'WHERE 1=0' + 空参数，调用方据此得到空结果。
     *
     * @return array{0: string, 1: array<int, mixed>}
     */

    private function buildTimelineWhere(string $username, int $categoryId, bool $followingOnly, string $media, int $userId): array
    {
        $where = 'WHERE p.status = 1';
        $params = [];

        if ($followingOnly && $userId) {
            $where .= ' AND p.user_id IN (SELECT f.following_id FROM follows f WHERE f.follower_id = ?)';
            $params[] = $userId;
        }

        if ($username) {
            // V54：已注销账号（deleted_at 非空）的动态对外保留展示；
            //      被封禁账号（status=0 且 deleted_at 为空）的动态依旧不展示
            $user = PostRepository::findUsernameForTimeline($username);
            if (!$user) {
                return ['WHERE 1=0', []];
            }
            $where .= ' AND p.user_id = ?';
            $params[] = $user['id'];
        }

        if ($categoryId > 0) {
            $categoryIds = [$categoryId];
            $parent = PostRepository::findCategoryWithParentForFeed($categoryId);
            if ($parent && $parent['parent_id'] === null) {
                $children = PostRepository::fetchActiveChildCategories($categoryId);
                foreach ($children as $child) {
                    $categoryIds[] = (int)$child['id'];
                }
            }
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $where .= " AND p.category_id IN ({$placeholders})";
            $params = array_merge($params, $categoryIds);
        }

        // V90：媒体类型过滤（JSON 列空值存 NULL 或空数组 '[]'，两者都视为「无」）
        if ($media === 'video') {
            $where .= " AND p.videos IS NOT NULL AND JSON_LENGTH(p.videos) > 0";
        } elseif ($media === 'image') {
            $where .= " AND p.images IS NOT NULL AND JSON_LENGTH(p.images) > 0";
        } elseif ($media === 'text') {
            $where .= " AND (p.images IS NULL OR JSON_LENGTH(p.images) = 0)"
                    . " AND (p.videos IS NULL OR JSON_LENGTH(p.videos) = 0)"
                    . " AND (p.attachments IS NULL OR JSON_LENGTH(p.attachments) = 0)";
        }

        // V56：可见范围过滤（追加在所有业务条件之后，参数顺序天然对齐 LIMIT）
        [$visWhere, $visParams] = $this->buildVisibilityWhere($userId);
        $where .= ' AND ' . $visWhere;
        $params = array_merge($params, $visParams);

        return [$where, $params];
    }

    /**
     * V162（P1-4）：timeline 游标翻页实现。仅当客户端显式传 cursor 时由 timeline() 派发。
     *
     * 冲突根因：原顺序「置顶优先 + 其余按时间」无法用 id 游标表达（置顶帖 id 可能远小于游标，
     * 会被 id < cursor 直接过滤）。解法：置顶集合与非置顶流分离，游标只作用于非置顶流。
     *
     * 两段式响应：
     *   - 首屏（cursor=0）返回 pinned（置顶集合）+ items（非置顶流首段）+ next_cursor
     *   - 后续翻页（cursor>0）只返回 items + next_cursor，pinned 由首屏或独立 /pinned 接口提供
     * 旧客户端不传 cursor，永不进入此方法，响应结构保持原 page/offset envelope，零回归。
     *
     * 查询口径见 PostRepository::fetchCursorPostIds / fetchCursorPinnedPostIds；
     * 首屏置顶集合另按 pinned_at DESC, id DESC 取，二者不可合并。
     */

    private function timelineCursor(int $userId, string $username, int $categoryId, bool $followingOnly, string $media, int $cursor, int $limit): Response
    {
        $cacheKey = "timeline_cursor_v" . Cache::nsVersion('timeline')
            . "_{$cursor}_{$limit}_{$username}_{$categoryId}_{$userId}_"
            . ($followingOnly ? 'f1' : 'f0') . "_m{$media}";

        $result = Cache::remember($cacheKey, function () use ($userId, $username, $categoryId, $followingOnly, $media, $cursor, $limit) {
            [$where, $params] = $this->buildTimelineWhere($username, $categoryId, $followingOnly, $media, $userId);

            // 非置顶流：纯 id 游标分页，规避「置顶排序与 id 游标」冲突
            $streamWhere = $where . ' AND p.is_pinned = 0';
            $streamParams = $params;
            if ($cursor > 0) {
                $streamWhere .= ' AND p.id < ?';
                $streamParams[] = $cursor;
            }
            $ids = PostRepository::fetchCursorPostIds($streamWhere, $streamParams, $limit);
            $postIds = array_column($ids, 'id');
            $items = $this->hydratePosts($postIds, $userId);
            $nextCursor = $postIds === [] ? null : (string)end($postIds);
            $hasMore = count($postIds) >= $limit;

            // 首屏附带置顶集合（cursor>0 时不带，App 端保留首屏缓存）
            $pinned = [];
            if ($cursor === 0) {
                $pinnedIds = PostRepository::fetchCursorPinnedPostIds($where, $params, 50);
                $pinned = $this->hydratePosts(array_column($pinnedIds, 'id'), $userId);
            }

            return [
                'pinned' => $pinned,
                'items' => $items,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ];
        });

        return Response::success($result, '获取成功');
    }

    /**
     * 把一组动态 ID 组装成前端要的完整结构（点赞/评论/黑名单元数据都在这补齐）
     *
     * @param array<int, int> $postIds
     * @return array<int, array<string, mixed>>
     */

    private function hydratePosts(array $postIds, int $userId): array
    {
        if (empty($postIds)) {
            return [];
        }
        $posts = PostRepository::fetchByIds($postIds, $userId);
        [$likesMap, $commentsMap, $blockedSet] = PostRepository::fetchBatchMeta($postIds, $userId);

        $list = [];
        foreach ($posts as $post) {
            $item = PostFormatter::formatPostBatch($post, $userId, $likesMap[$post['id']] ?? [], $commentsMap[$post['id']] ?? [], $blockedSet);
            // V59 类型帖：列表也必须带上结构化数据，否则首页卡片只会显示占位文案（PostCard 的
            // postTypeSummary 依赖 p.question/p.team/p.checkin/p.poll，而这些只有 detail() 原本会附加）。
            // 之前的实现只在 detail 附加，导致问答/投票/组队/打卡在信息流里正文全空。
            $type = (string)($post['post_type'] ?? 'normal');
            if ($type === 'question') {
                $item['question'] = $this->buildQuestionData((int)$post['id'], $userId);
            } elseif ($type === 'team') {
                $item['team'] = $this->buildTeamData((int)$post['id'], $userId);
            } elseif ($type === 'checkin') {
                $item['checkin'] = $this->buildCheckinData((int)$post['id'], $userId);
            } elseif ($type === 'poll') {
                $item['poll'] = $this->buildPollData((int)$post['id'], $userId);
            }
            $list[] = $item;
        }
        $list = PostFormatter::attachFavorited($list, $userId);
        // V97：内容付费墙 —— 列表按当前用户批量裁剪正文（一次查商品/权益，避免 N+1）。
        // $posts 与 $list 同序，携带 content/user_id/post_type；只改内存副本。
        return ContentPayService::scrubBatch($list, $posts, $userId);
    }

    /**
     * V66：推荐流候选打分。
     *
     * 不做向量建模，只用四路信号 + 时间衰减：
     *   关注的人            +30
     *   我互动（点赞过）的人  +8
     *   命中兴趣标签          +9（命中≥2 个标签 +18，说明是主战场）
     *   相似兴趣用户（同聊这些话题的人） +6
     *   时间衰减            每过去 1 小时 -0.15（7 天约 -25，压不过关注分）
     * 负反馈（不感兴趣 / 屏蔽作者）在 WHERE 里直接剔掉，不进候选。
     * 冷启动兜底：个人兴趣标签为空时，改用「全站热门标签」作标签信号 + 叠加相似兴趣用户加权，
     * 避免新用户一进推荐流就退化成纯时间序（与「最新」tab 撞脸）。
     *
     * 候选窗取 30 天（不是 7 天）：本站是低活站点，7 天窗长期返回空列表，
     * 推荐 tab 变成永远的「暂无内容」。时间衰减封顶 168 小时（=7 天）：
     * 老帖最多只扣 25.2 分，不至于让 +30 的关注分被衰减吃光后排序完全退化成时间倒序。
     *
     * @return array<int, array<string, mixed>>
     */

    private function recommendPostIds(int $userId, int $offset, int $limit): array
    {
        $tags = $this->myInterestTags($userId);

        // 冷启动兜底：个人兴趣标签为空时，用「全站热门标签」替补，让推荐流起码按热门话题排序，
        // 而不是退化成纯时间序（与「最新」tab 撞脸）。
        $coldStart = empty($tags);
        if ($coldStart) {
            $tags = $this->hotTags(20);
        }

        // 相似兴趣用户：同样在聊这些话题（个人标签或冷启动热门标签）的人，给他们发的动态 +6。
        // 冷启动用户也会因此被推向「活跃创作者」的内容，缓解纯时间序观感。
        // 打分 SQL（含标签 CASE 片段与参数翻倍）已整体下沉到 PostRepository，
        // 保证相关子查询避免 COUNT(DISTINCT) 被优化掉的实现口径不被调用点改写。
        $similarUserIds = $this->similarUserIds($tags, $userId, 50);

        return PostRepository::fetchRecommendedPostIds($userId, $offset, $limit, $tags, $similarUserIds);
    }

    /**
     * V66：我的兴趣标签 = 我最近点赞 / 评论过的动态带过的标签，取权重最高的 20 个。
     * 只查标签名，不落库维护画像 —— 互动数据本身就足够，省一张表的同步成本。
     *
     * @return array<int, string>
     */

    private function myInterestTags(int $userId): array
    {
        $rows = PostRepository::fetchMyInterestTagRows($userId);

        $tags = [];
        foreach ($rows as $row) {
            $tag = trim((string)$row['tag']);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        return $tags;
    }

    /**
     * V66+：冷启动兜底用 —— 全站热门标签。
     * 取最近 30 天 status=1 动态里使用最多的标签，作为没有个人兴趣数据时的替补信号。
     *
     * @return array<int, string>
     */

    private function hotTags(int $limit = 20): array
    {
        $rows = PostRepository::fetchHotTagRows($limit);

        $tags = [];
        foreach ($rows as $row) {
            $tag = trim((string)$row['tag']);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        return $tags;
    }

    /**
     * V66+：相似兴趣用户 = 同样在聊这些话题的人。
     * 取给「带这些标签的动态」点过赞 / 评过论的用户，按互动量排序，排除自己，截断到 $limit。
     * 用作推荐打分里的 +6 加权来源；冷启动（热门标签）时即等于「聊热门话题的活跃用户」。
     *
     * @param array<int, string> $tags
     * @return array<int, int>
     */

    private function similarUserIds(array $tags, int $excludeUserId, int $limit = 50): array
    {
        // 空标签短路已随 SQL 下沉到 PostRepository::fetchSimilarUserRows()，
        // 保证仓储被其它路径内部调用时同样不会生成空的 IN ()。
        $rows = PostRepository::fetchSimilarUserRows($tags, $excludeUserId, $limit);

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row['user_id'];
        }
        return $ids;
    }

    /**
     * V66：信息流负反馈「不感兴趣」。
     *
     * 之前这条只做前端内存隐藏，刷新又冒出来；现在落库后推荐流会直接剔除它。
     * 同一个目标重复点击只更新时间，不刷一行（uk_user_action_target 唯一键兜底）。
     */

    public function feedFeedback(Request $request): Response
    {
        $user = $this->requireUser($request);

        $action = $request->getString('action') ?: 'dislike';
        $type = $request->getString('target_type');
        $value = trim((string)$request->getString('target_value'));
        if (!in_array($action, ['dislike', 'tag', 'author'], true)) {
            $action = 'dislike';
        }
        if (!in_array($type, ['post', 'tag', 'author'], true)) {
            $type = 'post';
        }
        if ($value === '') {
            return Response::error('参数缺失', 400);
        }
        if ($type === 'post' && !ctype_digit($value)) {
            return Response::error('动态 ID 不合法', 400);
        }
        if ($type === 'author' && !ctype_digit($value)) {
            return Response::error('用户 ID 不合法', 400);
        }

        try {
            // 先删后插：feed_feedback 上只有 uk_user_action_target 唯一键，
            // 直接 ON DUPLICATE KEY 依赖 MySQL 版本对 VALUES() 的支持，拆两步更稳。
            PostRepository::replaceFeedFeedback(
                (int)$user['id'],
                $action,
                $type,
                $value,
                Helper::now()
            );
        } catch (\Throwable $e) {
            error_log('[Hublog feedFeedback] ' . $e->getMessage()); // @allow-error-log
            return Response::error('操作失败，请重试', 500);
        }

        // 推荐流本身不套 remember 缓存（见 timeline），所以这一条立刻生效；
        // 用户切回「最新」时那条缓存最多脏 10 分钟，前端本地已把卡片移除，观感无碍。

        return Response::success(['action' => $action, 'target_type' => $type], '已减少此类内容');
    }

    /**
     * 获取带缓存的动态总数
     */

    private function getCachedPostCount(string $where, array $params): int
    {
        return PostRepository::getPostCount($where, $params);
    }

    /**
     * 获取置顶动态列表
     */

    public function pinned(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $userId = $currentUser ? $currentUser['id'] : 0;

        // V56：置顶动态同样受可见范围约束（作者本人与有权限的人才能看到置顶）
        [$visWhere, $visParams] = $this->buildVisibilityWhere($userId);

        $postRows = PostRepository::fetchVisiblePinnedPosts($visWhere, $visParams);

        if (empty($postRows)) {
            return Response::success([]);
        }

        $postIds = array_column($postRows, 'id');

        [$likesMap, $commentsMap, $blockedSet] = PostRepository::fetchBatchMeta($postIds, $userId);

        $list = [];
        foreach ($postRows as $post) {
            $list[] = PostFormatter::formatPostBatch($post, $userId, $likesMap[$post['id']] ?? [], $commentsMap[$post['id']] ?? [], $blockedSet);
        }
        $list = PostFormatter::attachFavorited($list, $userId);

        return Response::success($list);
    }

    /**
     * 按话题标签获取动态列表（支持 #话题# 和 ##话题## 格式）
     */

    public function topic(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $userId = $currentUser['id'] ?? 0;
        $topic = trim($request->getString('name'));
        // V95：提前解析话题元信息（头图 / 简介 / 讨论数），供各返回分支统一带上。
        $topicMeta = $this->resolveTopicMeta($topic);

        if (empty($topic)) {
            return Response::success(array_merge(Helper::paginatedResponse([], 1, 20, 0), ['topic' => null]));
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(10, 20);

        // P2-4：话题页支持游标分页，cursor = 上次最后一条的 post_id（倒序）
        //   用 id<cursor + limit 的方式代替传统的 (page-1)*limit 偏移
        //   利用 post_tags.idx_tag_post(tag, post_id) 联合索引，实现 tag 过滤 + post_id 倒序
        //   无 cursor 时回退到 page/offset 分页，page 1 时 cursor=0
        $cursor = $request->getInt('cursor');
        $useCursor = $cursor > 0;

        // P2-5：优先使用 post_tags 表的 idx_tag_post 索引，O(limit) 复杂度
        //   当结果数 >= limit 时用索引；如果为 0 条则 fallback 到 LIKE 模糊匹配
        $rows = [];
        $total = 0;
        $usePostTags = true;

        // 可见性口径统一在 count 之前生成：游标 / 分页 / LIKE 三条路径共用，
        // 避免某一分支漏掉过滤导致私密/好友可见帖出现在话题页。
        [$visWhereTopic, $visParamsTopic] = $this->buildVisibilityWhere($userId);

        // 1) 先查 post_tags 表中关联且 status=1 的动态数（同样带可见性）
        $tagTotal = PostRepository::countTopicIndexPosts($topic, $visWhereTopic, $visParamsTopic);

        if ($tagTotal > 0 || $useCursor) {
            // 2) 再按 (tag, post_id) 索引的 post_id DESC 顺序取 ID，配合 ORDER BY + LIMIT 走索引
            if ($useCursor) {
                $rows = PostRepository::fetchTopicPostIdsByCursor(
                    $topic, $cursor, $limit, $visWhereTopic, $visParamsTopic
                );
                // 游标模式下 total 直接用 tagTotal（tagTotal 已按当前用户可见性过滤）
                $total = $tagTotal;
            } else {
                // 参数顺序仍为 tag → 可见性 → 分页
                $rows = PostRepository::fetchTopicPostIdsByPage($topic, $visWhereTopic, $visParamsTopic, $offset, $limit);
                $total = $tagTotal;
            }
        }

        // 3) 如果索引查不到，fallback 到 content LIKE 模糊匹配（旧数据 hashtag 未迁移的情况）
        $pattern = '%#' . str_replace(['%', '_'], ['\\%', '\\_'], $topic) . '#%';
        if ($total === 0 && !$useCursor) {
            $usePostTags = false;
            $total = PostRepository::countTopicLikePosts($pattern, $visWhereTopic, $visParamsTopic);
        }
        if (empty($rows)) {
            if ($useCursor) {
                // cursor 模式下 post_tags 查不到就直接返回空，不倒退到 LIKE（因为 cursor 只在 post_tags 模式下使用）
            } else {
                if ($usePostTags) {
                    // post_tags 有总数但当前页为空时，说明 page 超出范围，直接返回
                } else {
                    $rows = PostRepository::fetchTopicLikePostIds($pattern, $visWhereTopic, $visParamsTopic, $offset, $limit);
                }
            }
        }

        if (empty($rows)) {
            return Response::success(array_merge(Helper::paginatedResponse([], $page, $limit, $total), ['topic' => $topicMeta]));
        }

        $postIds = array_map('intval', array_column($rows, 'id'));
        $posts = PostRepository::fetchByIds($postIds, $userId);

        [$likesMap, $commentsMap, $blockedSet] = PostRepository::fetchBatchMeta($postIds, $userId);

        $list = [];
        foreach ($posts as $post) {
            $list[] = PostFormatter::formatPostBatch($post, $userId, $likesMap[$post['id']] ?? [], $commentsMap[$post['id']] ?? [], $blockedSet);
        }
        $list = PostFormatter::attachFavorited($list, $userId);

        $resp = Helper::paginatedResponse($list, $page, $limit, $total);
        // P2-4：返回结果附带游标信息，下一页用最后一条的 post_id 作为 cursor = next_cursor
        if (!empty($list)) {
            $lastPost = $list[count($list) - 1];
            $resp['next_cursor'] = (int)($lastPost['id'] ?? 0);
        } else {
            $resp['next_cursor'] = 0;
        }
        $resp['cursor_mode'] = $useCursor;
        $resp['query_mode'] = $usePostTags ? 'post_tags_index' : 'content_like_fallback';

        // V95：附上话题元信息（头图 / 简介 / 讨论数），供前端详情页直接渲染。
        $resp['topic'] = $topicMeta;

        return Response::success($resp, '获取成功');
    }

    /**
     * V95：按话题名解析话题元信息（含头图）。
     * 话题名大小写归一与 PostCrudTrait 提取逻辑保持一致：纯 ASCII 统一小写。
     *
     * @return array{id:int,name:string,slug:string,description:string,cover:string,post_count:int,status:int}|null
     */
    private function resolveTopicMeta(string $topic): ?array
    {
        if ($topic === '') {
            return null;
        }
        try {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $topic)) ?: strtolower(bin2hex(md5($topic)));
            $row = PostRepository::findTopicMeta($slug, $topic);
            if (!$row) {
                return null;
            }
            return [
                'id'          => (int)$row['id'],
                'name'        => $row['name'],
                'slug'        => $row['slug'],
                'description' => $row['description'],
                'cover'       => $row['cover'] ?? '',
                'post_count'  => (int)$row['post_count'],
                'status'      => (int)$row['status'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 根据分类 ID 获取该分类下的动态列表
     */

    public function category(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $userId = $currentUser['id'] ?? 0;
        $categoryId = $request->getInt('id');

        if ($categoryId <= 0) {
            return Response::success(Helper::paginatedResponse([], 1, 20, 0));
        }

        $category = PostRepository::findCategoryForFeed($categoryId);
        if (!$category) {
            return Response::success(Helper::paginatedResponse([], 1, 20, 0));
        }

        $categoryIds = [(int)$category['id']];
        if ($category['parent_id'] === null) {
            $children = PostRepository::fetchActiveChildCategories($categoryId);
            foreach ($children as $child) {
                $categoryIds[] = (int)$child['id'];
            }
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(10, 20);

        // V56：可见范围过滤
        [$visWhereCat, $visParamsCat] = $this->buildVisibilityWhere($userId);

        $total = PostRepository::countCategoryPosts($categoryIds, $visWhereCat, $visParamsCat);

        if ($total === 0) {
            return Response::success(Helper::paginatedResponse([], $page, $limit, 0));
        }

        $ids = PostRepository::fetchCategoryPostIds($categoryIds, $visWhereCat, $visParamsCat, $offset, $limit);

        $postIds = array_column($ids, 'id');

        $posts = PostRepository::fetchByIds($postIds, $userId);

        [$likesMap, $commentsMap, $blockedSet] = PostRepository::fetchBatchMeta($postIds, $userId);

        $list = [];
        foreach ($posts as $post) {
            $list[] = PostFormatter::formatPostBatch($post, $userId, $likesMap[$post['id']] ?? [], $commentsMap[$post['id']] ?? [], $blockedSet);
        }
        $list = PostFormatter::attachFavorited($list, $userId);

        return Response::success(Helper::paginatedResponse($list, $page, $limit, $total, ['category' => $category]));
    }

    /**
     * 获取最新的 ID（用于增量检查）
     */

    public function latestIds(Request $request): Response
    {
        // R6：按当前访问者可见性口径取最新 ID，游客=0 只看 public
        $currentUser = Auth::user($request);
        $viewerId = (int)($currentUser['id'] ?? 0);
        $rows = PostRepository::fetchLatestActivityIds($viewerId);

        $postId = 0;
        $commentId = 0;
        $likeId = 0;

        foreach ($rows as $row) {
            switch ($row['t']) {
                case 'post':
                    $postId = (int)$row['latest'];
                    break;
                case 'comment':
                    $commentId = (int)$row['latest'];
                    break;
                case 'like':
                    $likeId = (int)$row['latest'];
                    break;
            }
        }

        return Response::success([
            'post_id' => $postId,
            'comment_id' => $commentId,
            'like_id' => $likeId
        ]);
    }

    /**
     * 检查是否有新动态/评论/点赞
     * 返回新增数量和最新 ID
     */

    public function checkNew(Request $request): Response
    {
        $sinceId = $request->getInt('since_id', 0);
        $sinceCommentId = $request->getInt('since_comment_id', 0);
        $sinceLikeId = $request->getInt('since_like_id', 0);
        $username = $request->getString('username');

        // R6：viewerId 取当前登录用户（不能用 0，否则本人私密帖在增量检查里会消失）
        $currentUser = Auth::user($request);
        $viewerId = (int)($currentUser['id'] ?? 0);
        $isAdmin = ($currentUser['role'] ?? '') === 'admin';
        $emptyResponse = static function () use ($sinceId, $sinceCommentId, $sinceLikeId): Response {
            return Response::success([
                'count' => 0,
                'latest_id' => $sinceId,
                'latest_comment_id' => $sinceCommentId,
                'latest_like_id' => $sinceLikeId
            ]);
        };

        $userId = null;
        if ($username) {
            $user = PostRepository::findUsernameForCheckNew($username);
            if (!$user) {
                return $emptyResponse();
            }
            $userId = (int)$user['id'];
            // R6：查指定用户的增量前过主页可见性门禁，不可见沿用「用户不存在」空返回结构
            if (!UserRepository::isProfileVisibleTo($userId, $viewerId, $isAdmin)) {
                return $emptyResponse();
            }
        }

        // user_id 条件的有无随 SQL 一并下沉到仓储本体；viewerId 供三段可见性过滤
        $rows = PostRepository::fetchNewActivityCounts(
            $userId,
            $sinceId,
            $sinceCommentId,
            $sinceLikeId,
            $viewerId
        );

        $postCnt = 0;
        $commentCnt = 0;
        $likeCnt = 0;
        $postLatest = $sinceId;
        $commentLatest = $sinceCommentId;
        $likeLatest = $sinceLikeId;

        foreach ($rows as $row) {
            switch ($row['t']) {
                case 'post':
                    $postCnt = (int)$row['cnt'];
                    $postLatest = (int)($row['latest_id'] ?? $sinceId);
                    break;
                case 'comment':
                    $commentCnt = (int)$row['cnt'];
                    $commentLatest = (int)($row['latest_id'] ?? $sinceCommentId);
                    break;
                case 'like':
                    $likeCnt = (int)$row['cnt'];
                    $likeLatest = (int)($row['latest_id'] ?? $sinceLikeId);
                    break;
            }
        }

        $totalCount = $postCnt + $commentCnt + $likeCnt;

        return Response::success([
            'count' => $totalCount,
            'post_count' => $postCnt,
            'comment_count' => $commentCnt,
            'like_count' => $likeCnt,
            'latest_id' => $postLatest,
            'latest_comment_id' => $commentLatest,
            'latest_like_id' => $likeLatest
        ]);
    }

    /**
     * 公开话题列表：发布页「话题」按钮的候选话题
     * GET /api/topics?limit=6&keyword=xxx
     *
     * 数据来源两级：
     *   1) 后台维护的 topics 表（status=1，按 post_count 倒序）——管理员建的官方话题优先；
     *   2) 表为空时回退到 post_tags 的真实使用量聚合 —— 新站没建话题也能选出热话题，
     *      不会点开一片空白（之前 App 端只能从热门动态里现捞 topic 字段，很不稳定）。
     * 返回 [{ name, post_count }]，前端直接用 name 拼 #话题# 写进正文，
     * 后端 extractAndSavePostTags() 会自动落 post_tags。
     */

    public function topics(Request $request): Response
    {
        $limit = (int)$request->getInt('limit');
        if ($limit <= 0) {
            $limit = 6;
        }
        $limit = min($limit, 30);
        $keyword = trim($request->getString('keyword'));

        $list = [];
        try {
            if ($keyword !== '') {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
                $list = PostRepository::searchTopics($like, $limit);
            } else {
                $list = PostRepository::fetchTopics($limit);
            }
        } catch (\Throwable $e) {
            error_log('[Hublog topics] ' . $e->getMessage()); // @allow-error-log
        }

        // 回退：后台没维护话题（且没带搜索词）时，按 post_tags 真实使用量取
        if (empty($list) && $keyword === '') {
            try {
                $list = PostRepository::fetchHotTopicTags($limit);
            } catch (\Throwable $e) {
                error_log('[Hublog topics fallback] ' . $e->getMessage()); // @allow-error-log
            }
        }

        $items = [];
        foreach ($list as $row) {
            $name = trim((string)($row['name'] ?? ''));
            // 过滤空串和单字噪声（历史正文里的 #X# 常被误抽成「工」「人」这类，当推荐话题没意义）
            if ($name === '' || mb_strlen($name, 'UTF-8') < 2) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'post_count' => (int)($row['post_count'] ?? 0)
            ];
        }

        return Response::success(['list' => $items]);
    }

    /**
     * 获取热门动态排行榜
     * GET /posts/popular?type=comments|likes|favorites&limit=10
     */

    public function popular(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $userId = $currentUser['id'] ?? 0;

        $type = $request->getString('type', 'comments');
        $limit = min($request->getInt('limit', 10), 50);
        // V141：缓存键升 v3 —— 响应结构变了（帖型帖附带 question/team/checkin/poll），
        // 不升版本号的话线上旧缓存最多 5 分钟内仍返回不带结构化数据的列表
        // P0-11：再嵌 popular 命名空间版本号（失效 = 换版本，旧 key 靠 TTL 过期）
        // v4：响应内容增加付费墙裁剪（scrubBatch），v3 的缓存可能含未裁剪的类型帖正文。
        $cacheKey = "popular_v4_v" . Cache::nsVersion('popular') . "_{$type}_{$limit}_{$userId}";

        $result = Cache::remember($cacheKey, function () use ($userId, $type, $limit) {
            // V56：可见范围过滤（热门榜公示全部可见的动态，私密/好友可见不进榜）
            // 排序字段白名单已随 SQL 下沉到 PostRepository::fetchPopularPostIds()
            [$visWhere, $visParams] = $this->buildVisibilityWhere($userId);

            // 先查 ID 再批量查详情
            $ids = PostRepository::fetchPopularPostIds($type, $visWhere, $visParams, $limit);

            if (empty($ids)) {
                return ['list' => [], 'pagination' => ['page' => 1, 'pages' => 0, 'total' => 0]];
            }

            $postIds = array_column($ids, 'id');

            // 批量获取动态详情
            $posts = PostRepository::fetchByIds($postIds, $userId);
            [$likesMap, $commentsMap, $blockedSet] = PostRepository::fetchBatchMeta($postIds, $userId);

            // 按 ID 顺序组装结果
            $postMap = [];
            foreach ($posts as $post) {
                $postMap[$post['id']] = $post;
            }

            $list = [];
            // 与 $list 同序一一对应的原始行；scrubBatch 依赖下标对齐，
            // 否则会把 A 帖的付费状态裁到 B 帖上。
            $rawForScrub = [];
            foreach ($postIds as $id) {
                if (isset($postMap[$id])) {
                    $rawForScrub[] = $postMap[$id];
                    $item = PostFormatter::formatPostBatch($postMap[$id], $userId, $likesMap[$id] ?? [], $commentsMap[$id] ?? [], $blockedSet);
                    // V59/V139/V141：帖型帖（问答/组队/打卡/投票）的正文通常为空，
                    // 真正的内容在 question/team/checkin/poll 结构化数据里。列表必须像
                    // hydratePosts() 一样附带，否则前端只能渲染出空白卡片 ——
                    // App 发现页「热门动态」与个人主页动态列表曾因此看不到这些动态。
                    $fmtType = (string)($postMap[$id]['post_type'] ?? 'normal');
                    if ($fmtType === 'question') {
                        $item['question'] = $this->buildQuestionData((int)$id, $userId);
                    } elseif ($fmtType === 'team') {
                        $item['team'] = $this->buildTeamData((int)$id, $userId);
                    } elseif ($fmtType === 'checkin') {
                        $item['checkin'] = $this->buildCheckinData((int)$id, $userId);
                    } elseif ($fmtType === 'poll') {
                        $item['poll'] = $this->buildPollData((int)$id, $userId);
                    }
                    $list[] = $item;
                }
            }
            $list = PostFormatter::attachFavorited($list, $userId);
            $list = ContentPayService::scrubBatch($list, $rawForScrub, $userId);

            return [
                'list' => $list,
                'pagination' => ['page' => 1, 'pages' => 1, 'total' => count($list)],
                'type' => $type,
            ];
        }, 300); // 缓存 5 分钟

        return Response::success($result);
    }
}
