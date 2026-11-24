<?php

declare(strict_types=1);

/**
 * Hublog 动态数据仓储
 * 封装动态的批量查询、单条查询及带缓存的 COUNT 查询
 */

namespace Hublog\Core;

class PostRepository
{
    /**
     * 批量查询动态的点赞、评论与被下架上传记录
     *
     * @param array $postIds 动态 ID 列表
     * @return array [$likesMap, $commentsMap, $blockedSet]
     */
    public static function fetchBatchMeta(array $postIds, int $currentUserId = 0): array
    {
        if (empty($postIds)) {
            return [[], [], []];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));

        $likesRaw = Database::fetchAll(
            "SELECT l.post_id, u.id, u.username, u.nickname
             FROM likes l
             INNER JOIN users u ON l.user_id = u.id
             WHERE l.post_id IN ({$placeholders})
             ORDER BY l.created_at ASC",
            $postIds
        );
        $likesMap = [];
        foreach ($likesRaw as $l) {
            $likesMap[$l['post_id']][] = ['id' => $l['id'], 'username' => $l['username'], 'nickname' => $l['nickname']];
        }

        $commentsRaw = Database::fetchAll(
            "SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content, c.image, c.is_ai, c.created_at,
                    u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
             FROM comments c
             INNER JOIN users u ON c.user_id = u.id
             WHERE c.post_id IN ({$placeholders})
             ORDER BY c.created_at ASC",
            $postIds
        );

        $commentIds = array_unique(array_column($commentsRaw, 'id'));
        $commentLikeCounts = [];
        $commentLikedByUser = [];
        if (!empty($commentIds)) {
            $cPlaceholders = implode(',', array_fill(0, count($commentIds), '?'));
            $counts = Database::fetchAll(
                "SELECT comment_id, COUNT(*) AS cnt FROM comment_likes WHERE comment_id IN ({$cPlaceholders}) GROUP BY comment_id",
                $commentIds
            );
            foreach ($counts as $row) {
                $commentLikeCounts[$row['comment_id']] = (int)$row['cnt'];
            }
            if ($currentUserId > 0) {
                $myLikes = Database::fetchAll(
                    "SELECT comment_id FROM comment_likes WHERE comment_id IN ({$cPlaceholders}) AND user_id = ?",
                    array_merge($commentIds, [$currentUserId])
                );
                foreach ($myLikes as $row) {
                    $commentLikedByUser[$row['comment_id']] = true;
                }
            }
        }

        // V83：神评标记（comment_marks）批量查询，避免 N+1
        $godSet = [];
        if (!empty($commentIds)) {
            $godRows = Database::fetchAll(
                "SELECT comment_id FROM comment_marks WHERE mark_type = 'god' AND comment_id IN ({$cPlaceholders})",
                $commentIds
            );
            $godSet = array_flip(array_column($godRows, 'comment_id'));
        }

        // V83：评论作者内联徽章（名字旁展示，与实名徽章同一位置），一次批量查询
        $commentAuthorIds = array_values(array_unique(array_column($commentsRaw, 'user_id')));
        $commentBadgeMap = BadgeService::getInlineBadgesBatch($commentAuthorIds);

        $commentsMap = [];
        foreach ($commentsRaw as $c) {
            $c['like_count'] = $commentLikeCounts[$c['id']] ?? 0;
            $c['liked'] = !empty($commentLikedByUser[$c['id']]);
            $c['is_god'] = isset($godSet[$c['id']]);
            $c['badges'] = $commentBadgeMap[$c['user_id']] ?? [];
            $commentsMap[$c['post_id']][] = $c;
        }

        $blockedUrls = Database::fetchAll(
            "SELECT url FROM uploads WHERE post_id IN ({$placeholders}) AND status = 0",
            $postIds
        );
        $blockedSet = array_flip(array_column($blockedUrls, 'url'));

        return [$likesMap, $commentsMap, $blockedSet];
    }

    /**
     * 根据 ID 查询单条动态（带作者信息）
     *
     * @param int $id 动态 ID
     * @param bool $includePending V169：是否连带查出「因审核待审」的动态（status=0 且 audit_state=1）。
     *        仅用于「作者本人查看自己的待审动态」，调用方必须自行校验作者身份；
     *        管理员下架（status=0 且 audit_state=0）始终查不出来。
     * @return array|null 动态数据或 null
     */
    public static function findById(int $id, bool $includePending = false): ?array
    {
        $statusWhere = $includePending
            ? '(p.status = 1 OR (p.status = 0 AND p.audit_state = 1))'
            : 'p.status = 1';

        $post = Database::fetchOne(
            "SELECT p.id, p.user_id, p.category_id, p.title, p.content, p.images, p.videos, p.attachments, p.is_pinned, p.pinned_at, p.created_at, p.updated_at,
                    p.location, p.location_lat, p.location_lng, p.is_anonymous, p.is_original, p.like_count, p.comment_count, p.view_count, p.circle_id, p.post_type,
                    p.link_url, p.link_title, p.link_desc, p.link_site, p.origin_id, p.is_featured,
                    p.status, p.audit_state, p.scheduled_at, p.visibility,
                    u.username, u.nickname, u.avatar, u.role, u.bio, u.realname_verified, u.phone_verified_at, u.vip_level, u.avatar_frame,
                    c.name AS circle_name
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             LEFT JOIN circles c ON p.circle_id = c.id AND c.status = 1
             WHERE p.id = ? AND {$statusWhere} LIMIT 1",
            [$id]
        );

        if ($post) {
            // V51：详情页同样补充话题标签
            $post['tags'] = array_column(
                Database::fetchAll("SELECT tag FROM post_tags WHERE post_id = ? ORDER BY id ASC", [$id]),
                'tag'
            );
            // V83：作者内联徽章（名字旁展示）
            $post['inline_badges'] = BadgeService::getInlineBadges((int)$post['user_id']);
        }

        return $post ?: null;
    }

    /**
     * 根据 ID 列表批量查询动态（保持传入 ID 的顺序）
     *
     * @param array $postIds 动态 ID 列表
     * @return array 动态列表
     */
    public static function fetchByIds(array $postIds, int $currentUserId = 0): array
    {
        if (empty($postIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));

        $posts = Database::fetchAll(
            "SELECT p.id, p.user_id, p.category_id, p.title, p.content, p.images, p.videos, p.attachments, p.is_pinned, p.pinned_at, p.created_at, p.updated_at,
                    p.location, p.location_lat, p.location_lng, p.is_anonymous, p.is_original, p.like_count, p.comment_count, p.view_count, p.circle_id, p.post_type,
                    p.link_url, p.link_title, p.link_desc, p.link_site, p.origin_id, p.is_featured, p.visibility,
                    u.username, u.nickname, u.avatar, u.role, u.bio, u.realname_verified, u.phone_verified_at, u.vip_level, u.avatar_frame,
                    c.name AS circle_name
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             LEFT JOIN circles c ON p.circle_id = c.id AND c.status = 1
             WHERE p.id IN ({$placeholders})
             ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC",
            $postIds
        );

        if (empty($posts)) {
            return [];
        }

        // V51：批量补充话题标签（post_tags），避免 N+1
        $tagsRaw = Database::fetchAll(
            "SELECT post_id, tag FROM post_tags WHERE post_id IN ({$placeholders}) ORDER BY id ASC",
            $postIds
        );
        $tagsMap = [];
        foreach ($tagsRaw as $row) {
            $tagsMap[$row['post_id']][] = $row['tag'];
        }

        // V51：当前用户对这些动态作者的关注状态（一次查询，避免 N+1）
        $followMap = [];
        if ($currentUserId > 0) {
            $authorIds = array_values(array_unique(array_column($posts, 'user_id')));
            $authorPlaceholders = implode(',', array_fill(0, count($authorIds), '?'));
            $followRows = Database::fetchAll(
                "SELECT following_id FROM follows WHERE follower_id = ? AND following_id IN ({$authorPlaceholders})",
                array_merge([$currentUserId], $authorIds)
            );
            $followMap = array_flip(array_column($followRows, 'following_id'));
        }

        foreach ($posts as &$post) {
            $post['tags'] = $tagsMap[$post['id']] ?? [];
            $post['is_following'] = isset($followMap[$post['user_id']]);
        }
        unset($post);

        // V83：作者内联徽章（名字旁展示，与实名徽章同一位置），一次批量查询避免 N+1
        $authorIdsAll = array_values(array_unique(array_column($posts, 'user_id')));
        $inlineBadgeMap = BadgeService::getInlineBadgesBatch($authorIdsAll);
        foreach ($posts as &$post) {
            $post['inline_badges'] = $inlineBadgeMap[$post['user_id']] ?? [];
        }
        unset($post);

        return $posts;
    }

    /**
     * 带缓存的 COUNT 查询
     *
     * @param string $where  SQL WHERE 子句（含占位符）
     * @param array  $params 绑定参数
     * @return int 匹配行数
     */
    public static function getPostCount(string $where, array $params): int
    {
        // P2-3 修复：与 PostController 的 timeline 清理前缀对齐
        // 原 key='post_count_*' 永远不会被命中失效 → 发布/删除动态后总数缓存不更新 → 翻页错位
        // P0-11：键内嵌 timeline 命名空间版本号，失效 = bumpNsVersion('timeline') 换版本
        $cacheKey = 'timeline_total_v' . Cache::nsVersion('timeline') . '_' . md5($where . serialize($params));

        return (int)Cache::remember($cacheKey, function () use ($where, $params) {
            return (int)Database::fetchColumn("SELECT COUNT(*) FROM posts p {$where}", $params);
        }, 300);
    }

    // =====================================================================
    // 以下为「内联 SQL 收敛批次一 C」新增方法：PostCrudTrait 内联调用下沉。
    // 仅承载数据访问；权限门禁 / 参数校验 / 缓存边界 / 响应包装仍留控制器。
    // 现有 4 个方法（fetchBatchMeta / findById / fetchByIds / getPostCount）
    // 的签名与行为保持不变，PostFeedTrait / PostTypeTrait 等共享方不受影响。
    // =====================================================================

    /**
     * create()/detail() —— 转发来源原帖查询。
     * 对应 buildOriginPost()；posts 行查询按铁律带 p.post_type。
     */
    public static function findOriginPost(int $originId): ?array
    {
        return Database::fetchOne(
            "SELECT p.id, p.content, p.images, p.user_id, p.visibility, p.circle_id, p.post_type,
                    u.username, u.nickname, u.avatar, u.role, u.vip_level, u.avatar_frame
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.id = ? AND p.status = 1 AND (p.audit_state IS NULL OR p.audit_state <> 1) LIMIT 1",
            [$originId]
        );
    }

    /**
     * 轻量圈子成员判定（不感知付费订阅）。保留独立 SQL 供既有调用方使用；
     * 已修复原 !== null 带来的 fail-open。
     */
    public static function isCircleMemberForView(int $circleId, int $userId): bool
    {
        if ($circleId <= 0 || $userId <= 0) {
            return false;
        }
        $value = Database::fetchColumn(
            "SELECT 1 FROM circle_members WHERE circle_id = ? AND user_id = ? AND status = 1 LIMIT 1",
            [$circleId, $userId]
        );
        // fetchColumn 无行返回 false，原 !== null 导致恒真，此处修掉 fail-open
        return $value !== false && $value !== null;
    }

    /**
     * V96：圈子动态详情的付费感知可见性判定。
     *
     * 口径与 VisibilityPolicy::where() 的子查询完全一致：
     * 有效成员（status=1）+ 圈子启用 + 免费圈/圈主放行 + 付费圈订阅有效。
     * 实际判定委托给唯一权威 VisibilityPolicy。
     */
    public static function isCircleVisibleForView(int $circleId, int $userId): bool
    {
        return VisibilityPolicy::isCircleVisible($circleId, $userId);
    }

    /** canViewPost() —— 好友动态可见性判定（单向关注，统一走权威策略） */
    public static function isFollowerForView(int $followerId, int $followingId): bool
    {
        return VisibilityPolicy::isFollower($followerId, $followingId);
    }

    /**
     * create() —— 发帖后按 ID 回显，不过滤 status（SELECT p.* 已含 p.post_type）。
     * 审核插件可能把 status 置 0，这里必须能查出最终状态。
     */
    public static function findAnyWithAuthorById(int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT p.*, u.username, u.nickname, u.avatar, u.role, u.vip_level, u.avatar_frame
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.id = ?",
            [$postId]
        );
    }

    /** create() —— 每日发帖配额：VIP 等级 */
    public static function findVipLevel(int $userId): ?array
    {
        return Database::fetchOne("SELECT vip_level FROM users WHERE id = ?", [$userId]);
    }

    /** create() —— 每日发帖配额：当日已发数（COUNT(*) 聚合保持，不加 post_type） */
    public static function countTodayPosts(int $userId, string $date): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM posts WHERE user_id = ? AND status = 1 AND DATE(created_at) = ?",
            [$userId, $date]
        );
    }

    /** create() —— 分类存在性校验 */
    public static function findActiveCategory(int $categoryId): ?array
    {
        return Database::fetchOne("SELECT id FROM categories WHERE id = ? AND status = 1 LIMIT 1", [$categoryId]);
    }

    /** create() —— @提及白名单：IN 占位符按数组长度生成，值仍走绑定 */
    public static function fetchMentionableUsernames(array $usernames): array
    {
        if (empty($usernames)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($usernames), '?'));
        return Database::fetchAll(
            "SELECT username FROM users WHERE allow_mention = 1 AND status = 1 AND username IN ({$ph})",
            $usernames
        );
    }

    /** create() —— 分类关注者推送：帖子状态（posts 行查询带 p.post_type） */
    public static function findPostForCategoryNotify(int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT p.id, p.status, p.audit_state, p.user_id, p.category_id, p.visibility, p.post_type
             FROM posts p WHERE p.id = ? LIMIT 1",
            [$postId]
        );
    }

    /** create() —— 分类关注者推送：分类名 */
    public static function findCategory(int $categoryId): ?array
    {
        return Database::fetchOne("SELECT id, name FROM categories WHERE id = ? LIMIT 1", [$categoryId]);
    }

    /** create() —— 分类关注者推送：关注者列表 */
    public static function fetchCategoryFollowerIds(int $categoryId): array
    {
        return Database::fetchAll("SELECT user_id FROM category_follows WHERE category_id = ?", [$categoryId]);
    }

    /** 写通知：分类关注者推送 / @提及共用 */
    public static function insertNotification(array $data): void
    {
        Database::insert('notifications', $data);
    }

    /**
     * 通用事务边界：跨 trait 的编排通过回调传入，begin/commit/rollBack/rethrow 留在此处。
     * persistPost() 的 @提及 / 标签 / uploads / 投票由多个 trait 协作完成，
     * 无法整体搬进单个 Repository 方法，故以回调方式保证事务完整下沉且不改编排。
     */
    public static function transaction(callable $fn): mixed
    {
        Database::beginTransaction();
        try {
            $result = $fn();
            Database::commit();
            return $result;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** create() —— 写 posts 主表 */
    public static function insertPost(array $postData): int
    {
        return Database::insert('posts', $postData);
    }

    /**
     * create() —— 帖型附加表（问答 / 组队 / 打卡），与主表同事务。
     * Helper::now() 在每处 insert 本地调用，保持原实现的逐点取值时序。
     */
    public static function insertPostTypeExtras(int $postId, string $postType, ?array $typeConfig, int $authorId): void
    {
        if ($postType === 'question') {
            Database::insert('questions', [
                'post_id' => $postId,
                'question' => $typeConfig['question'],
                'created_at' => Helper::now()
            ]);
        } elseif ($postType === 'team') {
            $teamId = (int)Database::insert('teams', [
                'post_id' => $postId,
                'headline' => $typeConfig['headline'],
                'need_count' => $typeConfig['need_count'],
                'joined_count' => 0,
                'deadline' => $typeConfig['deadline'],
                'city' => $typeConfig['city'],
                'contact' => $typeConfig['contact'],
                'created_at' => Helper::now()
            ]);
            // 发起人本身占一个位置，前端据此把第一条标为「发起人」
            Database::insert('team_members', [
                'team_id' => $teamId,
                'post_id' => $postId,
                'user_id' => $authorId,
                'joined_at' => Helper::now()
            ]);
            Database::update('teams', ['joined_count' => 1], 'id = ?', [$teamId]);
        } elseif ($postType === 'checkin') {
            Database::insert('checkin_posts', [
                'post_id' => $postId,
                'goal' => $typeConfig['goal'],
                'target_days' => $typeConfig['target_days'],
                'created_at' => Helper::now()
            ]);
        }
    }

    /** detail() —— 登录用户浏览去重：INSERT IGNORE，返回是否新增浏览 */
    public static function recordUserView(int $postId, int $viewerId, string $viewerIp, string $viewDate, string $now): bool
    {
        $stmt = Database::getInstance()->prepare(
            "INSERT IGNORE INTO post_view_logs (post_id, viewer_id, viewer_ip, view_date, created_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$postId, $viewerId, $viewerIp, $viewDate, $now]);
        return $stmt->rowCount() > 0;
    }

    /** detail() —— 游客浏览是否已计数 */
    public static function hasGuestViewed(int $postId, string $viewerIp, string $viewDate): bool
    {
        return (bool)Database::fetchColumn(
            "SELECT 1 FROM post_view_logs WHERE post_id = ? AND viewer_ip = ? AND view_date = ? AND viewer_id IS NULL LIMIT 1",
            [$postId, $viewerIp, $viewDate]
        );
    }

    /** detail() —— 新增游客浏览记录 */
    public static function insertGuestView(int $postId, string $viewerIp, string $viewDate, string $now): void
    {
        Database::getInstance()->prepare(
            "INSERT INTO post_view_logs (post_id, viewer_id, viewer_ip, view_date, created_at)
             VALUES (?, NULL, ?, ?, ?)"
        )->execute([$postId, $viewerIp, $viewDate, $now]);
    }

    /** detail() —— 浏览量 +1 */
    public static function incrementPostViewCount(int $postId): void
    {
        Database::getInstance()->prepare(
            "UPDATE posts SET view_count = view_count + 1 WHERE id = ?"
        )->execute([$postId]);
    }

    /** detail() —— 编辑态回显 @提及用户名 */
    public static function fetchPostMentionUsernames(int $postId): array
    {
        return Database::fetchAll(
            "SELECT u.username FROM mentions m
             INNER JOIN users u ON u.id = m.user_id
             WHERE m.post_id = ? AND m.comment_id IS NULL",
            [$postId]
        );
    }

    /** create()/update() 共用 —— 圈子发帖门禁：圈子信息 */
    public static function findCircleForPost(int $circleId): ?array
    {
        return Database::fetchOne(
            "SELECT id, owner_id, status, post_permission, limited, removed_at, pay_type, post_review FROM circles WHERE id = ? LIMIT 1",
            [$circleId]
        );
    }

    /** create()/update() 共用 —— 圈子发帖门禁：成员身份 */
    public static function findCircleMemberForPost(int $circleId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT role, status FROM circle_members WHERE circle_id = ? AND user_id = ?",
            [$circleId, $userId]
        );
    }

    /** create()/update() 共用 —— 圈子动态计数增减（GREATEST 防止为负） */
    public static function adjustCirclePostCount(int $circleId, int $delta, string $now): void
    {
        Database::query(
            "UPDATE circles SET post_count = GREATEST(post_count + ?, 0), updated_at = ? WHERE id = ?",
            [$delta, $now, $circleId]
        );
    }

    /** delete() —— 删前回显（posts 行查询带 p.post_type） */
    public static function findPostForDelete(int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT p.id, p.user_id, p.images, p.videos, p.post_type FROM posts p WHERE p.id = ? LIMIT 1",
            [$postId]
        );
    }

    /** delete() —— 物理文件清理：帖子关联的 uploads */
    public static function fetchPostUploadUrls(int $postId): array
    {
        return Database::fetchAll("SELECT url FROM uploads WHERE post_id = ?", [$postId]);
    }

    /** delete() —— URL 是否被其它动态引用 */
    public static function countOtherUploadRefs(string $url, int $postId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM uploads WHERE url = ? AND post_id IS NOT NULL AND post_id != ?",
            [$url, $postId]
        );
    }

    /** delete() —— 评论 ID 收集（级联清理用） */
    public static function fetchPostCommentIds(int $postId): array
    {
        return Database::fetchAll("SELECT id FROM comments WHERE post_id = ?", [$postId]);
    }

    /**
     * delete() —— 级联删除事务：DELETE 顺序与 poll 查询逐字保留。
     * 全部写语句在同一事务内，catch 中 rollBack 后 throw，由控制器转成原错误响应。
     */
    public static function deletePostCascade(int $postId, array $commentIds): void
    {
        Database::beginTransaction();
        try {
            // 1. reports：target_type=post 直接删 + target_type=comment 按评论 ID 删
            Database::delete('reports', 'target_type = ? AND target_id = ?', ['post', $postId]);
            if (!empty($commentIds)) {
                $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
                Database::delete(
                    'reports',
                    "target_type = ? AND target_id IN ({$placeholders})",
                    array_merge(['comment'], $commentIds)
                );
            }

            // 2. comment_likes：无 FK，手动清理
            if (!empty($commentIds)) {
                $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
                Database::delete("comment_likes", "comment_id IN ({$placeholders})", $commentIds);
            }

            // 3~4. post_view_logs / uploads：无 FK，手动清理
            Database::delete('post_view_logs', 'post_id = ?', [$postId]);
            Database::delete('uploads', 'post_id = ?', [$postId]);

            // 4.1 类型帖附属数据：无指向 posts 的外键，不删会留下孤儿记录
            Database::delete('question_answers', 'post_id = ?', [$postId]);
            Database::delete('questions', 'post_id = ?', [$postId]);
            Database::delete('teams', 'post_id = ?', [$postId]);
            Database::delete('team_members', 'post_id = ?', [$postId]);
            Database::delete('checkin_posts', 'post_id = ?', [$postId]);
            Database::delete('checkin_logs', 'post_id = ?', [$postId]);
            $pollRow = Database::fetchOne("SELECT id FROM polls WHERE post_id = ? LIMIT 1", [$postId]);
            if ($pollRow) {
                Database::delete('poll_options', 'poll_id = ?', [(int)$pollRow['id']]);
                Database::delete('poll_votes', 'poll_id = ?', [(int)$pollRow['id']]);
                Database::delete('polls', 'post_id = ?', [$postId]);
            }

            // 4.2 V97 内容付费：删帖清理孤儿数据（订单保留审计，只删商品/条目/权益）。
            // best-effort：老库/测试库可能尚未建 content_* 表，缺表时跳过，绝不阻断删帖主流程。
            try {
                Database::delete('content_column_items', 'post_id = ?', [$postId]);
                Database::delete('content_products', "product_type = 'post' AND target_id = ?", [$postId]);
                Database::delete('content_entitlements', "product_type = 'post' AND target_id = ?", [$postId]);
                // content_orders 保留（资金流水审计），post_type=post 的订单仅留历史
            } catch (\Throwable $e) {
                Logger::error('[PostRepository deletePostCascade content_*] ' . $e->getMessage());
            }

            // 5. 评论 / 点赞 / 主表
            Database::delete('comments', 'post_id = ?', [$postId]);
            Database::delete('likes', 'post_id = ?', [$postId]);
            Database::delete('posts', 'id = ?', [$postId]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** create() —— post_tags 写入（INSERT IGNORE 幂等） */
    public static function insertPostTags(int $postId, array $tags): void
    {
        $stmt = Database::getInstance()->prepare(
            "INSERT IGNORE INTO post_tags (post_id, tag, created_at) VALUES (?, ?, NOW())"
        );
        foreach ($tags as $tag) {
            $stmt->execute([$postId, $tag]);
        }
    }

    /** create() —— topics 登记与计数（INSERT IGNORE 幂等） */
    public static function upsertTopics(array $tags): void
    {
        $topicStmt = Database::getInstance()->prepare(
            "INSERT IGNORE INTO topics (name, slug, status, created_at) VALUES (?, ?, 1, NOW())"
        );
        $countStmt = Database::getInstance()->prepare(
            "UPDATE topics SET post_count = post_count + 1 WHERE name = ?"
        );
        foreach ($tags as $tag) {
            $topicStmt->execute([$tag, $tag]);
            $countStmt->execute([$tag]);
        }
    }

    /** create() —— @提及用户查询（IN 占位符按数组长度生成，值走绑定） */
    public static function fetchUsersByUsernames(array $usernames): array
    {
        if (empty($usernames)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($usernames), '?'));
        return Database::fetchAll(
            "SELECT id, username, nickname FROM users WHERE username IN ({$placeholders}) AND status = 1",
            $usernames
        );
    }

    /** create() —— @提及记录写入 */
    public static function insertMention(int $postId, ?int $commentId, int $userId, int $senderId, string $type, string $now): void
    {
        Database::insert('mentions', [
            'post_id' => $postId,
            'comment_id' => $commentId,
            'user_id' => $userId,
            'mentioned_by' => $senderId,
            'type' => $type,
            'created_at' => $now
        ]);
    }

    // =====================================================================
    // 内联 SQL 收敛批次二 A 组：PostTypeTrait 投票（poll）路径。
    // 仅新增方法；上方批次一方法保持零改动。posts 行查询统一带 p.post_type。
    // =====================================================================

    /** createPollForPost() —— 建投票主表，返回新投票 ID */
    public static function insertPoll(int $postId, string $question, mixed $multi, mixed $maxChoice, ?string $expiresAt, string $now): int
    {
        return Database::insert('polls', [
            'post_id' => $postId,
            'question' => $question,
            'multi' => $multi,
            'max_choice' => $maxChoice,
            'expires_at' => $expiresAt,
            'is_anonymous' => 0,
            'created_at' => $now
        ]);
    }

    /** createPollForPost() —— 建投票选项 */
    public static function insertPollOption(int $pollId, string $content, int $sortOrder, string $now): int
    {
        return Database::insert('poll_options', [
            'poll_id' => $pollId,
            'content' => $content,
            'sort_order' => $sortOrder,
            'created_at' => $now
        ]);
    }

    /** createPollForPost() / pollVote() —— 投票记录写入（发布者首票 / 用户投票共用） */
    public static function insertPollVote(int $pollId, int $optionId, int $userId, int $postId, string $now): void
    {
        Database::insert('poll_votes', [
            'poll_id' => $pollId,
            'option_id' => $optionId,
            'user_id' => $userId,
            'post_id' => $postId,
            'created_at' => $now
        ]);
    }

    /** buildPollData() —— 按 post_id 定位投票（V63 主路径） */
    public static function findPollByPostId(int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT id, question, multi, max_choice, expires_at FROM polls WHERE post_id = ? LIMIT 1",
            [$postId]
        );
    }

    /** buildPollData() —— 老数据兜底：按投票记录反查 poll_id */
    public static function findPollIdByVotePost(int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT poll_id FROM poll_votes WHERE post_id = ? ORDER BY id DESC LIMIT 1",
            [$postId]
        );
    }

    /** buildPollData() —— 老数据兜底：按 poll_id 取完整摘要 */
    public static function findPollById(int $pollId): ?array
    {
        return Database::fetchOne(
            "SELECT id, question, multi, max_choice, expires_at FROM polls WHERE id = ? LIMIT 1",
            [$pollId]
        );
    }

    /** pollVote() —— 投票接口用的精简摘要（字段清单与原 SQL 一致） */
    public static function findPollSummaryByPostId(int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT id, multi, max_choice, expires_at FROM polls WHERE post_id = ? LIMIT 1",
            [$postId]
        );
    }

    /** pollVote() —— 投票接口老数据兜底摘要 */
    public static function findPollSummaryById(int $pollId): ?array
    {
        return Database::fetchOne(
            "SELECT id, multi, max_choice, expires_at FROM polls WHERE id = ? LIMIT 1",
            [$pollId]
        );
    }

    /** buildPollData() —— 选项清单，排序逐字保留 */
    public static function fetchPollOptions(int $pollId): array
    {
        return Database::fetchAll(
            "SELECT id, content FROM poll_options WHERE poll_id = ? ORDER BY sort_order ASC, id ASC",
            [$pollId]
        );
    }

    /** buildPollData() —— 各选项票数（GROUP BY 聚合，COUNT(*) 保持） */
    public static function countPollVotesByOption(int $pollId): array
    {
        return Database::fetchAll(
            "SELECT option_id, COUNT(*) AS cnt FROM poll_votes WHERE poll_id = ? GROUP BY option_id",
            [$pollId]
        );
    }

    /** buildPollData() —— 当前用户已投选项 */
    public static function fetchPollMyVotedOptions(int $pollId, int $userId): array
    {
        return Database::fetchAll(
            "SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ?",
            [$pollId, $userId]
        );
    }

    /**
     * 写接口通用动态存在性 + 可见性守卫。
     * viewerId 必须显式传当前用户，默认 0（游客）意味着只有 public 可通过。
     */
    public static function findActivePostId(int $postId, int $viewerId = 0): ?array
    {
        $row = Database::fetchOne(
            "SELECT p.id, p.user_id, p.post_type, p.visibility, p.circle_id, p.is_anonymous
             FROM posts p WHERE p.id = ? AND p.status = 1 LIMIT 1",
            [$postId]
        );
        return ($row && VisibilityPolicy::canView($row, $viewerId)) ? $row : null;
    }

    /** pollVote() —— 合法选项 ID 清单 */
    public static function fetchPollOptionIds(int $pollId): array
    {
        return Database::fetchAll("SELECT id FROM poll_options WHERE poll_id = ?", [$pollId]);
    }

    /** pollVote() —— 已投选项（IN 占位符按数组长度生成，值走绑定） */
    public static function fetchExistingPollVotes(int $pollId, int $userId, array $optionIds): array
    {
        if (empty($optionIds)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($optionIds), '?'));
        return Database::fetchAll(
            "SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ? AND option_id IN ({$ph})",
            array_merge([$pollId, $userId], $optionIds)
        );
    }

    /** pollVote() —— 当前用户在某投票下已投票数（多选上限判定） */
    public static function countUserPollVotes(int $pollId, int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM poll_votes WHERE poll_id = ? AND user_id = ?",
            [$pollId, $userId]
        );
    }

    /** pollVote() —— 撤回单个选项的投票；三个值全部绑定 */
    public static function deletePollVote(int $pollId, int $userId, int $optionId): void
    {
        Database::delete('poll_votes', 'poll_id = ? AND user_id = ? AND option_id = ?', [$pollId, $userId, $optionId]);
    }

    /** findTypePost() —— 帖型附加表存在性判定；表名仅来自白名单 + 正则校验 */
    public static function findTypePostRow(int $postId, string $type, int $viewerId = 0): ?array
    {
        $table = ['question' => 'questions', 'team' => 'teams', 'checkin' => 'checkin_posts'][$type] ?? null;
        if ($table === null || !preg_match('/^[a-z_]+$/', $table)) {
            return null;
        }
        $row = Database::fetchOne(
            "SELECT p.id, p.user_id, p.post_type, p.visibility, p.circle_id
             FROM posts p INNER JOIN {$table} t ON t.post_id = p.id
             WHERE p.id = ? AND p.status = 1 AND (p.audit_state IS NULL OR p.audit_state <> 1) LIMIT 1",
            [$postId]
        );
        return ($row && VisibilityPolicy::canView($row, $viewerId)) ? $row : null;
    }

    // =====================================================================
    // 内联 SQL 收敛批次二 B 组：PostTypeTrait 问答 / 组队 / 打卡路径。
    // =====================================================================

    /** buildQuestionData() —— 问题主行（含兼容字段） */
    public static function findQuestionByPostId(int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT question, status, answer, answered_by, answered_at FROM questions WHERE post_id = ? LIMIT 1",
            [$postId]
        );
    }

    /** buildQuestionData() —— 多回答列表（JOIN 用户，LIMIT 值走绑定） */
    public static function fetchQuestionAnswers(int $postId): array
    {
        return Database::fetchAll(
            "SELECT a.id, a.user_id, a.content, a.is_accepted, a.accepted_at, a.created_at,
                    u.nickname, u.username, u.avatar, u.vip_level
             FROM question_answers a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.post_id = ?
             ORDER BY a.is_accepted DESC, a.id ASC
             LIMIT ?",
            [$postId, 50]
        );
    }

    /** questionAnswer() —— 同一用户是否已答过 */
    public static function findQuestionAnswerByUser(int $postId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM question_answers WHERE post_id = ? AND user_id = ? LIMIT 1",
            [$postId, $userId]
        );
    }

    /** questionAnswer() —— 写独立回答表，返回新回答 ID */
    public static function insertQuestionAnswer(int $postId, int $userId, string $content, string $now): int
    {
        return Database::insert('question_answers', [
            'post_id' => $postId,
            'user_id' => $userId,
            'content' => $content,
            'created_at' => $now
        ]);
    }

    /** questionAnswer() —— 兼容旧字段：仅首次回答时回写 */
    public static function findQuestionLegacyAnswer(int $postId): ?array
    {
        return Database::fetchOne("SELECT answer FROM questions WHERE post_id = ? LIMIT 1", [$postId]);
    }

    /** questionAnswer() —— 首次回答同步 questions 旧字段 */
    public static function updateQuestionLegacyAnswer(int $postId, string $answer, int $userId, string $now): void
    {
        Database::update('questions', [
            'answer' => $answer,
            'answered_by' => $userId,
            'answered_at' => $now
        ], 'post_id = ?', [$postId]);
    }

    /** questionAccept() —— 问题状态回显 */
    public static function findQuestionStatus(int $postId): ?array
    {
        return Database::fetchOne("SELECT status FROM questions WHERE post_id = ? LIMIT 1", [$postId]);
    }

    /** questionAccept() —— 待采纳回答校验 */
    public static function findQuestionAnswer(int $answerId, int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT id, user_id, content FROM question_answers WHERE id = ? AND post_id = ? LIMIT 1",
            [$answerId, $postId]
        );
    }

    /**
     * questionAccept() —— 采纳事务：清旧采纳 → 置新采纳 → 同步 questions 兼容字段。
     * 两个 now 分次传入，保持原实现逐点取 Helper::now() 的时序。
     */
    public static function acceptQuestionAnswer(
        int $postId,
        int $answerId,
        ?string $answerContent,
        int $answerUserId,
        string $acceptedAt,
        string $answeredAt
    ): void {
        Database::beginTransaction();
        try {
            Database::update('question_answers', ['is_accepted' => 0, 'accepted_at' => null], 'post_id = ?', [$postId]);
            Database::update('question_answers', ['is_accepted' => 1, 'accepted_at' => $acceptedAt], 'id = ?', [$answerId]);
            Database::update('questions', [
                'status' => 1,
                'answer' => $answerContent,
                'answered_by' => $answerUserId,
                'answered_at' => $answeredAt
            ], 'post_id = ?', [$postId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** buildTeamData() —— 组队主行 */
    public static function findTeamByPostId(int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT headline, need_count, joined_count, deadline, city, contact FROM teams WHERE post_id = ? LIMIT 1",
            [$postId]
        );
    }

    /** buildTeamData() / teamJoin() / teamLeave() —— 成员身份（同一 SQL 三处复用） */
    public static function findTeamMember(int $postId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM team_members WHERE post_id = ? AND user_id = ? LIMIT 1",
            [$postId, $userId]
        );
    }

    /** buildTeamData() —— 成员清单（JOIN 用户，LIMIT 值走绑定） */
    public static function fetchTeamMembers(int $postId): array
    {
        return Database::fetchAll(
            "SELECT m.user_id, m.joined_at, u.nickname, u.username, u.avatar
             FROM team_members m LEFT JOIN users u ON u.id = m.user_id
             WHERE m.post_id = ? ORDER BY m.id ASC LIMIT ?",
            [$postId, 30]
        );
    }

    /** teamJoin() —— 报名前回显队伍容量 */
    public static function findTeamForJoin(int $postId): ?array
    {
        return Database::fetchOne("SELECT id, need_count, joined_count FROM teams WHERE post_id = ? LIMIT 1", [$postId]);
    }

    /** teamLeave() —— 退出前回显队伍 ID 与人数 */
    public static function findTeamForLeave(int $postId): ?array
    {
        return Database::fetchOne("SELECT id, joined_count FROM teams WHERE post_id = ? LIMIT 1", [$postId]);
    }

    /** teamLeave() —— 原代码该 COUNT 结果未被使用，仍原样保留查询以保持行为 */
    public static function countTeamMembersForUser(int $postId, int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM team_members WHERE post_id = ? AND user_id = ?",
            [$postId, $userId]
        );
    }

    /** teamJoin() —— 报名 + 人数 +1 事务 */
    public static function joinTeam(int $teamId, int $postId, int $userId, int $joinedCount, string $now): void
    {
        Database::beginTransaction();
        try {
            Database::insert('team_members', [
                'team_id' => $teamId,
                'post_id' => $postId,
                'user_id' => $userId,
                'joined_at' => $now
            ]);
            Database::update('teams', ['joined_count' => $joinedCount + 1], 'id = ?', [$teamId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** teamLeave() —— 退队 + 人数 -1 事务；队伍不存在时只删成员行 */
    public static function leaveTeam(int $memberId, int $teamId, int $joinedCount): void
    {
        Database::beginTransaction();
        try {
            Database::delete('team_members', 'id = ?', [$memberId]);
            if ($teamId > 0) {
                Database::update(
                    'teams',
                    ['joined_count' => max(0, $joinedCount - 1)],
                    'id = ?',
                    [$teamId]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** buildCheckinData() —— 打卡配置 */
    public static function findCheckinConfig(int $postId): ?array
    {
        return Database::fetchOne("SELECT goal, target_days FROM checkin_posts WHERE post_id = ? LIMIT 1", [$postId]);
    }

    /** buildCheckinData() —— 我的累计打卡数（COUNT(*) 保持） */
    public static function countMyCheckins(int $postId, int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM checkin_logs WHERE post_id = ? AND user_id = ?",
            [$postId, $userId]
        );
    }

    /** buildCheckinData() —— 今日是否已打卡 */
    public static function countTodayCheckins(int $postId, int $userId, string $date): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM checkin_logs WHERE post_id = ? AND user_id = ? AND checkin_date = ?",
            [$postId, $userId, $date]
        );
    }

    /** checkinDo() —— 写打卡日志；唯一键冲突由控制器转成原错误文案 */
    public static function insertCheckinLog(int $postId, int $userId, ?string $content, string $date, string $now): void
    {
        Database::insert('checkin_logs', [
            'post_id' => $postId,
            'user_id' => $userId,
            'content' => $content,
            'checkin_date' => $date,
            'created_at' => $now
        ]);
    }

    // =====================================================================
    // 内联 SQL 收敛批次二 C 组：PostCommentTrait 点赞 / 评论路径。
    // 通知写入复用批次一已有的 insertNotification() / insertMention()。
    // =====================================================================

    /**
     * like() —— 点赞 / 取消点赞完整事务。
     * INSERT IGNORE 的 rowCount 决定 liked；posts.like_count 用 LEAST/GREATEST 防溢出。
     */
    public static function togglePostLike(int $postId, int $userId, string $now): bool
    {
        Database::beginTransaction();
        try {
            $stmt = Database::getInstance()->prepare(
                "INSERT IGNORE INTO likes (post_id, user_id, created_at) VALUES (?, ?, ?)"
            );
            $stmt->execute([$postId, $userId, $now]);
            $inserted = $stmt->rowCount();
            $liked = $inserted > 0;
            if ($liked) {
                Database::getInstance()->prepare(
                    "UPDATE posts SET like_count = LEAST(like_count + 1, 4294967295) WHERE id = ?"
                )->execute([$postId]);
            } else {
                $deleted = Database::delete('likes', 'post_id = ? AND user_id = ?', [$postId, $userId]);
                if ($deleted > 0) {
                    Database::getInstance()->prepare(
                        "UPDATE posts SET like_count = GREATEST(like_count - 1, 0) WHERE id = ?"
                    )->execute([$postId]);
                }
            }
            Database::commit();
            return $liked;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** like() —— 点赞用户简要清单（响应内使用） */
    public static function fetchPostLikerUsers(int $postId): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.username, u.nickname
             FROM likes l
             INNER JOIN users u ON l.user_id = u.id
             WHERE l.post_id = ? ORDER BY l.created_at ASC",
            [$postId]
        );
    }

    /** postLikes() —— 点赞总数（与分页列表共用 l.post_id 条件） */
    public static function countPostLikers(int $postId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM likes l INNER JOIN users u ON l.user_id = u.id WHERE l.post_id = ?",
            [$postId]
        );
    }

    /** postLikes() —— 点赞用户分页；limit/offset 绑定，字段清单与子查询原样保留 */
    public static function fetchPostLikerPage(int $postId, int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.bio, u.vip_level, u.avatar_frame,
                    (SELECT COUNT(*) FROM follows f WHERE f.following_id = u.id) AS follower_count,
                    (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id AND p.status = 1) AS post_count
             FROM likes l
             INNER JOIN users u ON l.user_id = u.id
             WHERE l.post_id = ?
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?",
            [$postId, $limit, $offset]
        );
    }

    /** pinComment() —— 动态作者权限回显；posts 行查询带 p.post_type */
    public static function findPostOwnerForPin(int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT p.id, p.user_id, p.post_type FROM posts p WHERE p.id = ? LIMIT 1",
            [$postId]
        );
    }

    /** pinComment() —— 待置顶评论 */
    public static function findCommentForPin(int $commentId, int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT id, is_pinned FROM comments WHERE id = ? AND post_id = ? LIMIT 1",
            [$commentId, $postId]
        );
    }

    /** pinComment() —— 清旧置顶 + 置新评论事务；异常抛出由控制器转原 500 文案 */
    public static function setPinnedComment(int $postId, int $commentId, bool $willPin): void
    {
        Database::beginTransaction();
        try {
            Database::update('comments', ['is_pinned' => 0], 'post_id = ?', [$postId]);
            if ($willPin) {
                Database::update('comments', ['is_pinned' => 1], 'id = ?', [$commentId]);
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** comment() —— 父评论校验（字段清单与原 SQL 一致：id, user_id） */
    public static function findParentComment(int $parentId, int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT id, user_id FROM comments WHERE id = ? AND post_id = ? LIMIT 1",
            [$parentId, $postId]
        );
    }

    /** commentLike() —— 评论基本信息 */
    public static function findCommentBasic(int $commentId): ?array
    {
        return Database::fetchOne("SELECT id, post_id, user_id FROM comments WHERE id = ? LIMIT 1", [$commentId]);
    }

    /** commentLike() —— 兼容迁移未执行场景：表不存在时返回 false（原 catch 语义） */
    public static function commentLikesTableExists(): bool
    {
        try {
            return (bool)Database::fetchColumn("SHOW TABLES LIKE 'comment_likes'");
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** commentLike() —— 当前用户是否已赞该评论 */
    public static function findCommentLike(int $commentId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM comment_likes WHERE comment_id = ? AND user_id = ? LIMIT 1",
            [$commentId, $userId]
        );
    }

    /** commentLike() —— 评论点赞切换事务（无事务语义变化） */
    public static function toggleCommentLike(int $commentId, int $userId, ?int $likeId, string $now): bool
    {
        $liked = false;
        Database::beginTransaction();
        try {
            if ($likeId !== null) {
                Database::delete('comment_likes', 'id = ?', [$likeId]);
            } else {
                Database::insert('comment_likes', [
                    'comment_id' => $commentId,
                    'user_id' => $userId,
                    'created_at' => $now
                ]);
                $liked = true;
            }
            Database::commit();
            return $liked;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** commentLike() —— 评论点赞总数 */
    public static function countCommentLikes(int $commentId): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM comment_likes WHERE comment_id = ?", [$commentId]);
    }

    /** createNotification() —— 通知收件人（posts 行查询带 p.post_type） */
    public static function findPostUserIdForNotify(int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT p.user_id, p.post_type FROM posts p WHERE p.id = ? LIMIT 1",
            [$postId]
        );
    }

    /**
     * comment() —— P2-12 评论去重：5 秒窗口作为绑定值，其余表达式逐字保留。
     */
    public static function findRecentDuplicateComment(int $postId, int $userId, int $parentId, string $hash, int $window): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM comments
             WHERE post_id = ? AND user_id = ?
               AND COALESCE(parent_id, 0) = ?
               AND LEFT(MD5(CONCAT(content, '|', COALESCE(images, ''))), 16) = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
             ORDER BY id DESC LIMIT 1",
            [$postId, $userId, $parentId, $hash, $window]
        );
    }

    /** comment() —— 写评论主表，返回新评论 ID */
    public static function insertComment(array $commentData): int
    {
        return Database::insert('comments', $commentData);
    }

    /** comment() —— 评论数 +1，GREATEST/LEAST 防溢出 */
    public static function incrementPostCommentCount(int $postId): void
    {
        Database::getInstance()->prepare(
            "UPDATE posts SET comment_count = LEAST(comment_count + 1, 4294967295) WHERE id = ?"
        )->execute([$postId]);
    }

    /** comment() —— @提及：用户名查活跃用户 */
    public static function findActiveUserByUsername(string $username): ?array
    {
        return Database::fetchOne("SELECT id FROM users WHERE username = ? AND status = 1 LIMIT 1", [$username]);
    }

    /** comment() —— @提及：兼容直接传 user_id 的旧端 */
    public static function findActiveUserId(int $userId): ?int
    {
        $value = Database::fetchColumn("SELECT id FROM users WHERE id = ? AND status = 1 LIMIT 1", [$userId]);
        return $value === false || $value === null ? null : (int)$value;
    }

    /** comment() —— 评论 + 作者信息回显（去重命中与发布成功两处复用） */
    public static function findCommentWithUser(int $commentId): ?array
    {
        return Database::fetchOne(
            "SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content, c.image, c.images, c.is_ai, c.created_at,
                    u.username, u.nickname, u.avatar
             FROM comments c
             INNER JOIN users u ON c.user_id = u.id
             WHERE c.id = ?",
            [$commentId]
        );
    }

    /** comment() —— 动态作者回显；posts 行查询带 p.post_type */
    public static function findPostAuthorWithUser(int $postId): ?array
    {
        return Database::fetchOne(
            "SELECT p.user_id, u.username, u.nickname, u.avatar, p.post_type
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.id = ? AND p.status = 1 LIMIT 1",
            [$postId]
        );
    }

    /** deleteComment() —— 删除前权限回显 */
    public static function findCommentForDelete(int $commentId): ?array
    {
        return Database::fetchOne("SELECT id, user_id, post_id FROM comments WHERE id = ? LIMIT 1", [$commentId]);
    }

    /** collectCommentSubtree() —— BFS 逐层取子评论 ID（IN 占位符按层数生成） */
    public static function fetchCommentChildIds(array $parentIds): array
    {
        if (empty($parentIds)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($parentIds), '?'));
        return Database::fetchAll(
            "SELECT id FROM comments WHERE parent_id IN ({$ph})",
            $parentIds
        );
    }

    /** deleteComment() —— 评论级联清理事务：计数回退 → 关联清理 → 删除评论本身 */
    public static function deleteCommentCascade(int $postId, array $allIds): void
    {
        Database::beginTransaction();
        try {
            if (!empty($allIds)) {
                $placeholders = implode(',', array_fill(0, count($allIds), '?'));
                Database::getInstance()->prepare(
                    "UPDATE posts SET comment_count = GREATEST(comment_count - ?, 0) WHERE id = ?"
                )->execute([count($allIds), $postId]);

                Database::delete('comment_likes', "comment_id IN ({$placeholders})", $allIds);
                Database::delete('notifications', "comment_id IN ({$placeholders})", $allIds);
                Database::delete(
                    'reports',
                    "target_type = ? AND target_id IN ({$placeholders})",
                    array_merge(['comment'], $allIds)
                );
                Database::delete('comments', "id IN ({$placeholders})", $allIds);
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // =====================================================================
    // 内联 SQL 收敛批次四 A 组：PostFeedTrait 信息流查询下沉。
    // 只新增方法；上方批次一/二/三的 98 个方法签名与 SQL 保持零改动。
    // 所有涉及 posts 的行查询均追加 p.post_type（无害列，调用方只取 id）；
    // COUNT(*) / MAX() 聚合保持原样，不为凑铁律改变聚合列。
    // 分页计数与列表共用控制器生成的同一套 WHERE/params，避免翻页错位。
    // =====================================================================

    /**
     * timeline()/buildTimelineWhere() —— username 解析（含已注销账号的动态保留展示口径）。
     * 与批次一 findActiveUserByUsername() 的 SQL 口径不同（此处放行 deleted_at 非空），
     * 故单独建方法，绝不复用。
     */
    public static function findUsernameForTimeline(string $username): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM users WHERE username = ? AND (status = 1 OR deleted_at IS NOT NULL) LIMIT 1",
            [$username]
        );
    }

    /** buildTimelineWhere() —— 分类校验：带 parent_id，与 findActiveCategory() 字段集不同 */
    public static function findCategoryWithParentForFeed(int $categoryId): ?array
    {
        return Database::fetchOne(
            "SELECT id, parent_id FROM categories WHERE id = ? AND status = 1 LIMIT 1",
            [$categoryId]
        );
    }

    /** buildTimelineWhere()/category() —— 子分类列表（两处 SQL 逐字相同，共用） */
    public static function fetchActiveChildCategories(int $categoryId): array
    {
        return Database::fetchAll("SELECT id FROM categories WHERE parent_id = ? AND status = 1", [$categoryId]);
    }

    /**
     * timeline() —— 置顶优先的 ID 分页。
     * WHERE 由控制器生成并与计数共用；字段清单在原文 p.id 后追加 p.post_type。
     */
    public static function fetchTimelinePostIds(string $where, array $params, int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT p.id, p.post_type FROM posts p {$where} ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** timelineCursor() —— 非置顶 id 游标流，ORDER BY p.id DESC，与原 SQL 一致 */
    public static function fetchCursorPostIds(string $where, array $params, int $limit): array
    {
        return Database::fetchAll(
            "SELECT p.id, p.post_type FROM posts p {$where} ORDER BY p.id DESC LIMIT ?",
            array_merge($params, [$limit])
        );
    }

    /** timelineCursor() —— 首屏置顶集合，排序为 pinned_at DESC, id DESC（与流查询不同，独立方法） */
    public static function fetchCursorPinnedPostIds(string $where, array $params, int $limit): array
    {
        return Database::fetchAll(
            "SELECT p.id, p.post_type FROM posts p {$where} AND p.is_pinned = 1 ORDER BY p.pinned_at DESC, p.id DESC LIMIT ?",
            array_merge($params, [$limit])
        );
    }

    /** pinned() —— 置顶详情长字段清单，仅追加 p.post_type；u.id as uid 别名原样保留 */
    public static function fetchVisiblePinnedPosts(string $visWhere, array $visParams): array
    {
        return Database::fetchAll(
            "SELECT p.id, p.user_id, p.category_id, p.title, p.content, p.images, p.videos, p.attachments, p.created_at, p.updated_at, p.status, p.is_pinned, p.pinned_at,
                    p.location, p.location_lat, p.location_lng, p.is_anonymous, p.declaration, p.post_type,
                    u.id as uid, u.username, u.nickname, u.avatar, u.role, u.status as ustatus, u.vip_level, u.avatar_frame
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.status = 1 AND p.is_pinned = 1 AND {$visWhere}
             ORDER BY p.created_at DESC",
            $visParams
        );
    }

    /** topic() —— post_tags 索引命中总数（COUNT(*) 聚合保持，带可见性片段） */
    public static function countTopicIndexPosts(string $topic, string $visWhere, array $visParams): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*)
             FROM post_tags pt
             INNER JOIN posts p ON p.id = pt.post_id
             WHERE pt.tag = ? AND p.status = 1 AND {$visWhere}",
            array_merge([$topic], $visParams)
        );
    }

    /** topic() —— 游标路径 ID：LIMIT ?，参数顺序 tag → 可见性 → cursor → limit */
    public static function fetchTopicPostIdsByCursor(
        string $topic,
        int $cursor,
        int $limit,
        string $visWhere,
        array $visParams
    ): array {
        return Database::fetchAll(
            "SELECT pt.post_id AS id, p.post_type FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id
                     WHERE pt.tag = ? AND p.status = 1 AND {$visWhere} AND pt.post_id < ?
                     ORDER BY pt.post_id DESC LIMIT ?",
            array_merge([$topic], $visParams, [$cursor, $limit])
        );
    }

    /** topic() —— 分页路径 ID：LIMIT ?, ? 且追加可见性片段，参数顺序 tag → 可见性 → offset → limit */
    public static function fetchTopicPostIdsByPage(string $topic, string $visWhere, array $visParams, int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT pt.post_id AS id, p.post_type FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id
                     WHERE pt.tag = ? AND p.status = 1 AND {$visWhere}
                     ORDER BY pt.post_id DESC LIMIT ?, ?",
            array_merge([$topic], $visParams, [$offset, $limit])
        );
    }

    /** topic() —— LIKE 兜底总数（COUNT(*) 保持，LIKE 的前后 % 由参数绑定） */
    public static function countTopicLikePosts(string $pattern, string $visWhere, array $visParams): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM posts p WHERE p.status = 1 AND p.content LIKE ? AND {$visWhere}",
            array_merge([$pattern], $visParams)
        );
    }

    /** topic() —— LIKE 兜底分页 ID，排序 p.id DESC 与索引路径的 pt.post_id DESC 不同 */
    public static function fetchTopicLikePostIds(string $pattern, string $visWhere, array $visParams, int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT p.id, p.post_type FROM posts p WHERE p.status = 1 AND p.content LIKE ? AND {$visWhere} ORDER BY p.id DESC LIMIT ?, ?",
            array_merge([$pattern], $visParams, [$offset, $limit])
        );
    }

    /** resolveTopicMeta() —— 话题元信息（表 topics，不涉及 posts 行） */
    public static function findTopicMeta(string $slug, string $topic): ?array
    {
        return Database::fetchOne(
            "SELECT id, name, slug, description, cover, post_count, status FROM topics WHERE slug = ? OR name = ? LIMIT 1",
            [$slug, $topic]
        );
    }

    /** category() —— 分类回显（字段清单与 findCategoryWithParentForFeed 不同，独立方法） */
    public static function findCategoryForFeed(int $categoryId): ?array
    {
        return Database::fetchOne("SELECT id, parent_id, name, status FROM categories WHERE id = ? LIMIT 1", [$categoryId]);
    }

    /** 分类 ID 白名单：占位符按数组长度生成，值仍走绑定；非整数直接剔除 */
    private static function normalizeCategoryIds(array $categoryIds): array
    {
        $ids = [];
        foreach ($categoryIds as $id) {
            $value = (int)$id;
            if ($value > 0 && !in_array($value, $ids, true)) {
                $ids[] = $value;
            }
        }
        return $ids;
    }

    /** category() —— 分类总数，IN 占位符按白名单长度生成 */
    public static function countCategoryPosts(array $categoryIds, string $visWhere, array $visParams): int
    {
        $ids = self::normalizeCategoryIds($categoryIds);
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM posts p WHERE p.status = 1 AND p.category_id IN ({$placeholders}) AND {$visWhere}",
            array_merge($ids, $visParams)
        );
    }

    /** category() —— 分类列表 ID，WHERE 与 countCategoryPosts() 同源 */
    public static function fetchCategoryPostIds(array $categoryIds, string $visWhere, array $visParams, int $offset, int $limit): array
    {
        $ids = self::normalizeCategoryIds($categoryIds);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return Database::fetchAll(
            "SELECT p.id, p.post_type FROM posts p WHERE p.status = 1 AND p.category_id IN ({$placeholders}) AND {$visWhere} ORDER BY p.created_at DESC LIMIT ?, ?",
            array_merge($ids, $visParams, [$offset, $limit])
        );
    }

    /**
     * latestIds() —— 三段 UNION 聚合取最大 ID（MAX 聚合不加 post_type）
     *
     * R6：三段各接入一份 VisibilityPolicy 条件，避免私密/好友/圈子动态把最新 ID 顶高，
     * 导致游客或无权用户在增量检查里永远看到「有新内容」。参数按段顺序 post→comment→like。
     */
    public static function fetchLatestActivityIds(int $viewerId = 0): array
    {
        [$visWhere, $visParams] = VisibilityPolicy::where($viewerId);
        $sql = "SELECT 'post' AS t, MAX(id) AS latest FROM posts p WHERE status = 1 AND {$visWhere}
                UNION ALL
                SELECT 'comment', MAX(c.id) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.status = 1 AND {$visWhere}
                UNION ALL
                SELECT 'like', MAX(l.id) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.status = 1 AND {$visWhere}";
        return Database::fetchAll($sql, array_merge($visParams, $visParams, $visParams));
    }

    /** checkNew() —— username 解析（status=1，与 findUsernameForTimeline 口径不同） */
    public static function findUsernameForCheckNew(string $username): ?array
    {
        return Database::fetchOne("SELECT id FROM users WHERE username = ? AND status = 1 LIMIT 1", [$username]);
    }

    /**
     * checkNew() —— 三类新增计数聚合。
     * 原入口用 `if ($userId)` 决定是否拼 user_id 条件；该守卫随 SQL 一并下沉，
     * 避免仓储内部调用绕过入口条件。参数顺序 post → comment → like 逐字保留。
     */
    public static function fetchNewActivityCounts(
        ?int $userId,
        int $sinceId,
        int $sinceCommentId,
        int $sinceLikeId,
        int $viewerId = 0
    ): array {
        // R6：viewerId 用当前登录用户（<=0 视为游客），三段 UNION 各拼接一份可见性条件与参数。
        [$visWhere, $visParams] = VisibilityPolicy::where($viewerId);
        $postParams = [];
        $commentParams = [];
        $likeParams = [];
        if ($userId) {
            $postParams[] = $userId;
            $commentParams[] = $userId;
            $likeParams[] = $userId;
        }
        $postParams[] = $sinceId;
        $commentParams[] = $sinceCommentId;
        $likeParams[] = $sinceLikeId;

        // 参数顺序逐段 post → comment → like；每段内部：user(可选) → since → 该段可见性参数
        $sql = "SELECT 'post' AS t, COUNT(*) AS cnt, MAX(p.id) AS latest_id FROM posts p WHERE p.status = 1"
            . ($userId ? " AND p.user_id = ?" : "") . " AND p.id > ? AND {$visWhere}
                UNION ALL
                SELECT 'comment', COUNT(*), MAX(c.id) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.status = 1"
            . ($userId ? " AND p.user_id = ?" : "") . " AND c.id > ? AND {$visWhere}
                UNION ALL
                SELECT 'like', COUNT(*), MAX(l.id) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.status = 1"
            . ($userId ? " AND p.user_id = ?" : "") . " AND l.id > ? AND {$visWhere}";

        $postParams = array_merge($postParams, $visParams);
        $commentParams = array_merge($commentParams, $visParams);
        $likeParams = array_merge($likeParams, $visParams);

        return Database::fetchAll($sql, array_merge($postParams, $commentParams, $likeParams));
    }

    /**
     * myInterestTags() —— 点赞/评论过的动态所带标签聚合。
     * 聚合查询（GROUP BY pt.tag），字段清单 pt.tag/COUNT(*) 原样保留。
     */
    public static function fetchMyInterestTagRows(int $userId): array
    {
        return Database::fetchAll(
            "SELECT pt.tag, COUNT(*) AS c
             FROM post_tags pt
             WHERE pt.post_id IN (
                 SELECT l.post_id FROM likes l WHERE l.user_id = ?
                 UNION
                 SELECT c.post_id FROM comments c WHERE c.user_id = ? AND c.post_id > 0
             )
             GROUP BY pt.tag
             ORDER BY c DESC, pt.tag ASC
             LIMIT 20",
            [$userId, $userId]
        );
    }

    /** hotTags() —— 冷启动热门标签聚合，LIMIT 值走绑定 */
    public static function fetchHotTagRows(int $limit): array
    {
        return Database::fetchAll(
            "SELECT pt.tag, COUNT(*) AS c
             FROM post_tags pt
             INNER JOIN posts p ON p.id = pt.post_id
             WHERE p.status = 1 AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY pt.tag
             ORDER BY c DESC, pt.tag ASC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * similarUserIds() —— 相似兴趣用户聚合。
     * 入口 `if (empty($tags)) return []` 随方法一起下沉，内部自调用不会绕过。
     */
    public static function fetchSimilarUserRows(array $tags, int $excludeUserId, int $limit): array
    {
        if (empty($tags)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        return Database::fetchAll(
            "SELECT actor.user_id, COUNT(*) AS weight
             FROM (
                 SELECT l.user_id AS user_id FROM likes l
                 INNER JOIN post_tags pt ON pt.post_id = l.post_id
                 WHERE pt.tag IN ({$placeholders})
                 UNION ALL
                 SELECT c.user_id AS user_id FROM comments c
                 INNER JOIN post_tags pt ON pt.post_id = c.post_id
                 WHERE pt.tag IN ({$placeholders}) AND c.post_id > 0 AND c.user_id > 0
             ) actor
             WHERE actor.user_id <> ?
             GROUP BY actor.user_id
             ORDER BY weight DESC
             LIMIT ?",
            array_merge($tags, $tags, [$excludeUserId, $limit])
        );
    }

    /**
     * recommendPostIds() —— 推荐打分候选。
     * 原控制器根据 tags / similarUserIds 动态拼两个 CASE 片段；拼装与空集守卫
     * 一并下沉，SQL 主体、信号权重、LEAST(...,168)*0.15 与时间窗逐字保留。
     * 参数顺序：follows(me) → likes(me) → 兴趣标签(CASE 两次引用，翻倍) →
     * 相似用户 → ff(me) → fa(me) → me → offset → limit。
     */
    public static function fetchRecommendedPostIds(int $userId, int $offset, int $limit, array $tags, array $similarUserIds): array
    {
        $tagScore = '0';
        $tagParams = [];
        if ($tags) {
            $tagIn = implode(',', array_fill(0, count($tags), '?'));
            $sub = "(SELECT COUNT(DISTINCT tag) FROM post_tags pt WHERE pt.post_id = p.id AND pt.tag IN ({$tagIn}))";
            $tagScore = "(CASE WHEN {$sub} >= 2 THEN 18 WHEN {$sub} >= 1 THEN 9 ELSE 0 END)";
            $tagParams = array_merge($tags, $tags);
        }
        $similarScore = '0';
        $similarParams = [];
        if ($similarUserIds) {
            $simIn = implode(',', array_fill(0, count($similarUserIds), '?'));
            $similarScore = "(CASE WHEN p.user_id IN ({$simIn}) THEN 6 ELSE 0 END)";
            $similarParams = $similarUserIds;
        }

        $sql = "
            SELECT p.id, p.post_type,
                   (
                       (CASE WHEN p.user_id IN (SELECT f.following_id FROM follows f WHERE f.follower_id = ?) THEN 30 ELSE 0 END)
                     + (CASE WHEN p.user_id IN (SELECT l.user_id FROM likes l WHERE l.user_id = ?) THEN 8 ELSE 0 END)
                     + {$tagScore}
                     + {$similarScore}
                     - LEAST(TIMESTAMPDIFF(HOUR, p.created_at, NOW()), 168) * 0.15
                   ) AS score
            FROM posts p
            LEFT JOIN feed_feedback ff
                   ON ff.user_id = ? AND ff.action = 'dislike' AND ff.target_type = 'post'
                  AND CAST(ff.target_value AS UNSIGNED) = p.id
            LEFT JOIN feed_feedback fa
                   ON fa.user_id = ? AND fa.action = 'author'
                  AND CAST(fa.target_value AS UNSIGNED) = p.user_id
            WHERE p.status = 1
              AND p.visibility = 'public'
              AND p.user_id <> ?
              AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND ff.id IS NULL
              AND fa.id IS NULL
            ORDER BY score DESC, p.created_at DESC
            LIMIT ?, ?";

        $params = array_merge([$userId, $userId], $tagParams, $similarParams, [$userId, $userId, $userId]);
        $params[] = $offset;
        $params[] = $limit;

        return Database::fetchAll($sql, $params);
    }

    /** feedFeedback() —— 先删后插（原实现无事务，保持不新增事务） */
    public static function replaceFeedFeedback(int $userId, string $action, string $type, string $value, string $now): void
    {
        Database::delete('feed_feedback', 'user_id = ? AND action = ? AND target_type = ? AND target_value = ?', [
            $userId, $action, $type, $value
        ]);
        Database::insert('feed_feedback', [
            'user_id' => $userId,
            'action' => $action,
            'target_type' => $type,
            'target_value' => $value,
            'created_at' => $now,
        ]);
    }

    /**
     * popular() —— 热门榜 ID。排序字段由 type 白名单在本方法内生成（原 match 一并下沉），
     * 非法 type 走 default 分支，与控制器原 match 完全一致。
     */
    public static function fetchPopularPostIds(string $type, string $visWhere, array $visParams, int $limit): array
    {
        $orderField = match ($type) {
            'likes' => '(SELECT COUNT(*) FROM likes WHERE post_id = p.id)',
            'favorites' => '(SELECT COUNT(*) FROM favorites WHERE post_id = p.id)',
            default => '(SELECT COUNT(*) FROM comments WHERE post_id = p.id)',
        };
        $sql = "SELECT p.id, p.post_type FROM posts p WHERE p.status = 1 AND {$visWhere} ORDER BY {$orderField} DESC, p.created_at DESC LIMIT ?";
        return Database::fetchAll($sql, array_merge($visParams, [$limit]));
    }

    /** topics() —— 后台话题带 keyword 搜索；与无关键词分支字段同但 WHERE 不同，独立方法 */
    public static function searchTopics(string $like, int $limit): array
    {
        return Database::fetchAll(
            "SELECT name, post_count FROM topics
                     WHERE status = 1 AND name LIKE ?
                     ORDER BY post_count DESC, id ASC LIMIT ?",
            [$like, $limit]
        );
    }

    /** topics() —— 后台话题全量候选（无 keyword） */
    public static function fetchTopics(int $limit): array
    {
        return Database::fetchAll(
            "SELECT name, post_count FROM topics
                     WHERE status = 1
                     ORDER BY post_count DESC, id ASC LIMIT ?",
            [$limit]
        );
    }

    /** topics() —— 回退：post_tags 真实使用量聚合（GROUP BY，聚合不加 post_type） */
    public static function fetchHotTopicTags(int $limit): array
    {
        return Database::fetchAll(
            "SELECT pt.tag AS name, COUNT(*) AS post_count
                     FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id AND p.status = 1
                     GROUP BY pt.tag
                     ORDER BY post_count DESC, pt.tag ASC
                     LIMIT ?",
            [$limit]
        );
    }

    // =====================================================================
    // 内联 SQL 收敛批次四 B 组：PostUpdateTrait 编辑 / 转发路径下沉。
    // 事务起点与回滚全部在 persistPostUpdate() 内；控制器只保留权限、校验与缓存。
    // =====================================================================

    /** update() —— 作者可编辑范围（status=1 / 定时 / 审核待审），字段清单逐字保留 */
    public static function findPostForEdit(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT id, user_id, status, audit_state, scheduled_at, circle_id, post_type FROM posts
             WHERE id = ? AND (
                    status = 1
                    OR (status = 0 AND scheduled_at IS NOT NULL)
                    OR (status = 0 AND audit_state = 1)
             ) LIMIT 1",
            [$id]
        );
    }

    /** update() —— 管理员兜底：字段清单更短，与 findPostForEdit 交叉断言防误合并 */
    public static function findAnyPostForAdminEdit(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT id, user_id, status, circle_id, post_type FROM posts WHERE id = ? LIMIT 1",
            [$id]
        );
    }

    /** update() —— 编辑后最终状态回显；行查询追加 p.post_type */
    public static function findPostStatusAfterEdit(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT p.status, p.audit_state, p.post_type FROM posts p WHERE p.id = ? LIMIT 1",
            [$id]
        );
    }

    /** resolveUpdateFields() —— 可见范围校验取圈子 ID；原 fetchColumn 改为行返回，调用方取列 */
    public static function findPostCircleIdForVisibility(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT p.circle_id, p.post_type FROM posts p WHERE p.id = ? LIMIT 1",
            [$id]
        );
    }

    /** resolveUpdateFields() —— 私密 + 定时组合拦截的既有值回显 */
    public static function findPostVisibilityState(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT p.visibility, p.scheduled_at, p.post_type FROM posts p WHERE p.id = ? LIMIT 1",
            [$id]
        );
    }

    /** persistPostUpdate() —— 标签刷新时取库内 title；行查询追加 p.post_type */
    public static function findPostTitleForTags(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT p.title, p.post_type FROM posts p WHERE p.id = ? LIMIT 1",
            [$id]
        );
    }

    /**
     * persistPostUpdate() —— 编辑前已有的 @提及用户名（DISTINCT 去重后判定「新增」）。
     * 与批次一 fetchPostMentionUsernames() 的 SQL 仅差 DISTINCT，语义不可互相替代，
     * 按批次二教训单独建方法，不因「看着像」复用。
     */
    public static function fetchExistingEditMentionUsernames(int $postId): array
    {
        return Database::fetchAll(
            "SELECT DISTINCT u.username FROM mentions m
                         JOIN users u ON u.id = m.user_id
                         WHERE m.post_id = ? AND m.comment_id IS NULL",
            [$postId]
        );
    }

    /**
     * update() —— 编辑落库完整事务。
     * 顺序与原实现逐字一致：posts 更新 → @提及刷新（仅新增发通知）→ uploads 重绑 →
     * post_tags 先删后刷。mention / notification 的单条写失败仍按原实现吞掉继续，
     * 不扩大事务回滚范围；$refreshTags 由控制器传入（PostCrudTrait 的私有标签提取逻辑），
     * 但其调用与事务边界一并留在仓储内部。
     */
    public static function persistPostUpdate(
        int $id,
        int $userId,
        array $updateData,
        ?array $newMentions,
        array $newImages,
        array $newVideos,
        array $newAttachments,
        callable $refreshTags
    ): void {
        // 动态 UPDATE 列名白名单 + 正则双校验，字段名不进参数数组。
        $allowedFields = [
            'content', 'title', 'category_id', 'circle_id', 'images', 'videos', 'attachments',
            'location', 'location_lat', 'location_lng', 'is_anonymous', 'is_original',
            'declaration', 'scheduled_at', 'status', 'audit_state', 'visibility', 'updated_at',
        ];
        foreach (array_keys($updateData) as $field) {
            $name = (string)$field;
            if (!in_array($name, $allowedFields, true) || !preg_match('/^[a-z_]+$/', $name)) {
                throw new \InvalidArgumentException('非法更新字段: ' . $name);
            }
        }

        Database::beginTransaction();
        try {
            Database::update('posts', $updateData, 'id = ?', [$id]);

            if ($newMentions !== null) {
                $existingMentions = array_map('strval', array_column(
                    self::fetchExistingEditMentionUsernames($id) ?: [],
                    'username'
                ));
                $notifyOnly = array_values(array_diff($newMentions, $existingMentions));

                Database::delete('mentions', 'post_id = ? AND comment_id IS NULL', [$id]);

                foreach ($newMentions as $name) {
                    $target = self::findActiveUserByUsername((string)$name);
                    if (!$target || (int)$target['id'] === $userId) {
                        continue;
                    }
                    try {
                        self::insertMention($id, null, (int)$target['id'], $userId, 'post', Helper::now());
                    } catch (\Throwable $e) {
                        // 重复提及忽略（原行为：吞掉继续）
                        continue;
                    }

                    if (in_array($name, $notifyOnly, true)) {
                        try {
                            self::insertNotification([
                                'user_id'    => (int)$target['id'],
                                'sender_id'  => $userId,
                                'type'       => 'mention',
                                'post_id'    => $id,
                                'comment_id' => null,
                                'content'    => '在动态中提到了你',
                                'is_read'    => 0,
                                'created_at' => Helper::now()
                            ]);
                        } catch (\Throwable $e) {
                            // 通知写入失败不影响主流程（原行为）
                        }
                    }
                }
            }

            UploadRepository::resetAndBindPostUploads($userId, $id, $newImages, $newVideos, $newAttachments);

            Database::delete('post_tags', 'post_id = ?', [$id]);
            $titleForRefresh = $updateData['title'] ?? null;
            if ($titleForRefresh === null) {
                $titleRow = self::findPostTitleForTags($id);
                $titleForRefresh = $titleRow['title'] ?? null;
            }
            $contentForTagsRefresh = ($updateData['content'] ?? '') . ' ' . ($titleForRefresh ?? '');
            $refreshTags($contentForTagsRefresh);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** repost() —— 原动态回显；SELECT p.* 已含 p.post_type，仅取已发布且非待审的行 */
    public static function findPostForRepost(int $originId): ?array
    {
        return Database::fetchOne(
            "SELECT p.* FROM posts p
             WHERE p.id = ? AND p.status = 1 AND (p.audit_state IS NULL OR p.audit_state <> 1) LIMIT 1",
            [$originId]
        );
    }

    /**
     * repost() —— 目标圈子校验；与 findCircleForPost() 字段集不同
     * （此处无 limited/removed_at），单独建方法防相似 SQL 误合并。
     */
    public static function findCircleForRepost(int $circleId): ?array
    {
        return Database::fetchOne(
            "SELECT id, owner_id, post_permission, status FROM circles WHERE id = ? LIMIT 1",
            [$circleId]
        );
    }

    /** repost() —— 圈子 post_count +1，无 GREATEST，与 adjustCirclePostCount() 不同 */
    public static function incrementCirclePostCountForRepost(int $circleId, string $now): void
    {
        Database::query(
            "UPDATE circles SET post_count = post_count + 1, updated_at = ? WHERE id = ?",
            [$now, $circleId]
        );
    }
}
