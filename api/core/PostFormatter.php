<?php

declare(strict_types=1);

/**
 * Hublog 动态数据格式化器
 * 负责将原始动态/评论数据转换为前端可用的结构
 */

namespace Hublog\Core;

class PostFormatter
{
    /**
     * V72：批量为列表动态补充 favorited（当前用户是否已收藏）。
     * 首页时间轴 / 圈子 / 话题 / 搜索等列表前端要亮收藏星标，
     * 逐条查会 N+1，这里收集 id 后一次 IN 查询补全。
     * 游客（$currentUserId=0）一律 false。
     */
    public static function attachFavorited(array $list, int $currentUserId): array
    {
        $map = [];
        if ($list && $currentUserId > 0) {
            $ids = [];
            foreach ($list as $item) {
                if (!empty($item['id'])) $ids[] = (int)$item['id'];
            }
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $rows = Database::fetchAll(
                    "SELECT post_id FROM favorites WHERE user_id = ? AND post_id IN ({$ph})",
                    array_merge([$currentUserId], $ids)
                );
                foreach ($rows as $r) {
                    $map[(int)$r['post_id']] = true;
                }
            }
        }
        foreach ($list as &$item) {
            $item['favorited'] = !empty($map[(int)($item['id'] ?? 0)]);
        }
        unset($item);
        return $list;
    }

    /**
     * 单条动态批量格式化
     *
     * @param array $post         原始动态数据（含作者字段）
     * @param int   $currentUserId 当前登录用户 ID
     * @param array $likes        点赞列表
     * @param array $comments     评论列表（原始结构）
     * @param array $blockedSet   被下架 URL 集合（键为 URL）
     * @return array 格式化后的动态数据
     */
    public static function formatPostBatch(array $post, int $currentUserId, array $likes, array $comments, array $blockedSet = []): array
    {
        $liked = false;
        foreach ($likes as $like) {
            if ($like['id'] == $currentUserId) {
                $liked = true;
                break;
            }
        }

        $images = $post['images'] ? json_decode($post['images'], true) : [];
        $videos = $post['videos'] ? json_decode($post['videos'], true) : [];
        $attachments = $post['attachments'] ? json_decode($post['attachments'], true) : [];
        // 站点根绝对路径：不能写裸 'assets/...'（会被前端拼到站点根，而站点根没有该目录），
        // 也不能写 'templates/...'（前端 normalizeAssets 不认 templates 前缀，App 端会解析到沙箱失败）
        $blockedImage = defined('UPLOAD_BLOCKED_IMAGE_URL') ? UPLOAD_BLOCKED_IMAGE_URL : '/templates/moments/assets/images/blocked.svg';

        foreach ($images as &$img) {
            if (isset($blockedSet[$img])) {
                $img = $blockedImage;
            }
        }
        unset($img);

        // 视频：兼容字符串URL格式和对象格式[{url,cover,duration}]
        if (!is_array($videos)) $videos = [];
        foreach ($videos as &$v) {
            if (is_string($v)) {
                $videoUrl = $v;
                $v = ['url' => $videoUrl, 'cover' => '', 'duration' => 0];
            } elseif (is_array($v)) {
                if (empty($v['url'])) continue;
                $v += ['cover' => '', 'duration' => 0];
            } else {
                $v = null;
                continue;
            }
            if (isset($blockedSet[$v['url']])) {
                $v['blocked'] = true;
            }
            if (!empty($v['cover']) && isset($blockedSet[$v['cover']])) {
                $v['cover'] = $blockedImage;
            }
        }
        unset($v);
        $videos = array_values(array_filter($videos, fn($v) => is_array($v) && !empty($v['url'])));

        foreach ($attachments as &$att) {
            if (is_array($att) && isset($blockedSet[$att['url']])) {
                $att['blocked'] = true;
            }
        }
        unset($att);

        $isAnonymous = (bool)($post['is_anonymous'] ?? 0);

        // 匿名帖的点赞人不得暴露真实身份（liked 判定已在上方基于原始 likes 完成）。
        if ($isAnonymous && $likes !== []) {
            $likes = array_map(static function (array $like): array {
                return [
                    'id' => 0,
                    'username' => 'anonymous',
                    'nickname' => '匿名用户',
                ];
            }, $likes);
        }

        // V54：已注销账号（动态按策略保留）统一显示为「已注销用户」——
        // 昵称唯一索引会让第二个注销账号变成「已注销用户_2」，这里收敛掉后缀
        $deletedAuthor = !$isAnonymous
            && Helper::isDeletedAccount($post['username'] ?? null, $post['nickname'] ?? null);

        // V83：动态详情页下发的「是否可标记神评」开关（由 PostController::detail 按帖子上下文算好）
        $canMarkComments = !empty($post['can_mark_comments']);

        $userData = $isAnonymous ? [
            'id' => 0,
            'username' => 'anonymous',
            'nickname' => '匿名用户',
            'avatar' => null,
            'role' => 'anonymous',
            'bio' => null,
            'realname_verified' => 0,
            'is_following' => false
        ] : [
            'id' => $post['user_id'],
            'username' => $post['username'],
            'nickname' => $deletedAuthor ? '已注销用户' : $post['nickname'],
            'avatar' => $post['avatar'],
            'role' => $post['role'] ?? 'user',
            // V51：个性签名 + 关注状态（列表页头部展示 / 关注按钮）
            'bio' => $deletedAuthor ? null : ($post['bio'] ?? null),
            // V51：实名认证状态（App 端昵称旁展示认证徽章）
            'realname_verified' => $deletedAuthor ? 0 : (int)($post['realname_verified'] ?? 0),
            // V82：手机号验证状态（App 端昵称旁与实名徽章并列展示手机验证徽章）
            'phone_verified' => $deletedAuthor ? 0 : (int)(!empty($post['phone_verified_at'])),
            // V70：会员等级 + 头像框（App 端头像处展示会员徽章 / 头像框）
            'vip_level' => $deletedAuthor ? 0 : (int)($post['vip_level'] ?? 0),
            'avatar_frame' => $deletedAuthor ? 'none' : ($post['avatar_frame'] ?? 'none'),
            'is_following' => !empty($post['is_following']),
            // V83：内联徽章（名字旁展示，与实名徽章同一位置；动态详情/列表统一从此读取）
            'badges' => $deletedAuthor ? [] : ($post['inline_badges'] ?? [])
        ];

        // P2-1/P2-6：优先读 posts 表冗余列 like_count/comment_count，不再 count($likes)/count($comments)；
        // 冗余列缺失（老库未完成 V40 迁移）时回退 count()，确保不爆 Notice。
        $redundantLikeCount = null;
        if (isset($post['like_count'])) {
            $redundantLikeCount = (int)$post['like_count'];
        }
        $redundantCommentCount = null;
        if (isset($post['comment_count'])) {
            $redundantCommentCount = (int)$post['comment_count'];
        }

        $formatted = [
            'id' => $post['id'],
            'category_id' => isset($post['category_id']) ? (int)$post['category_id'] : null,
            'title' => $post['title'] ?? null,
            'content' => $post['content'],
            'images' => $images,
            'videos' => $videos,
            'attachments' => $attachments,
            'location' => $post['location'] ?? null,
            'location_lat' => isset($post['location_lat']) ? (float)$post['location_lat'] : null,
            'location_lng' => isset($post['location_lng']) ? (float)$post['location_lng'] : null,
            'is_anonymous' => $isAnonymous,
            // V83：原创声明（前端发布页编辑回填 + 详情页可展示「原创」标）
            'is_original' => !empty($post['is_original']),
            // V57：链接分享卡片
            'link' => !empty($post['link_url'])
                ? [
                    'url' => $post['link_url'],
                    'title' => $post['link_title'] ?? null,
                    'desc' => $post['link_desc'] ?? null,
                    'site' => $post['link_site'] ?? null,
                ]
                : null,
            // V56：可见范围 / 精华 / 权重（App 端展示可见性标签、圈主管理页识别精华帖）
            'visibility' => $post['visibility'] ?? 'public',
            'is_featured' => (bool)($post['is_featured'] ?? 0),
            'origin_post' => null, // 单条详情由 PostController 回填（需要按当前用户判可见）
            'is_pinned' => (bool)($post['is_pinned'] ?? 0),
            'pinned_at' => $post['pinned_at'] ?? null,
            'created_at' => $post['created_at'],
            'time_ago' => Helper::timeAgo($post['created_at']),
            'user' => $userData,
            'likes' => $likes,
            'comments' => array_map(function ($c) use ($canMarkComments) {
                return self::formatComment($c, ['can_mark_comments' => $canMarkComments]);
            }, $comments),
            'like_count' => $redundantLikeCount ?? count($likes),
            'comment_count' => $redundantCommentCount ?? count($comments),
            'view_count' => (int)($post['view_count'] ?? 0),
            // V51：话题标签 / 所属圈子（圈子已删除或 p.circle_id=0 时为 null）
            'tags' => is_array($post['tags'] ?? null) ? $post['tags'] : [],
            'circle' => !empty($post['circle_id']) && !empty($post['circle_name'])
                ? ['id' => (int)$post['circle_id'], 'name' => $post['circle_name']]
                : null,
            // V59：帖型标记（normal/poll/question/team/checkin），列表与详情统一带出
            'post_type' => $post['post_type'] ?? 'normal',
            'liked' => $liked
        ];

        // V97：内容付费墙通用裁剪（未开通时 resolve 短路，零成本）。
        // 覆盖所有走 formatPostBatch 的列表/详情入口，杜绝付费正文从列表泄露。
        ContentPayService::scrubFormatted($formatted, $post, $currentUserId);
        return $formatted;
    }

    /**
     * 评论格式化（含 AI 徽章字段、神评标记）
     *
     * @param array $comment 原始评论数据（已含 badges / is_god，来自 PostRepository）
     * @param array $opts    ['can_mark_comments' => bool] 当前用户是否可标记该帖评论为神评
     * @return array 格式化后的评论数据
     */
    public static function formatComment(array $comment, array $opts = []): array
    {
        $canMark = !empty($opts['can_mark_comments']);
        return [
            'id' => $comment['id'],
            'parent_id' => $comment['parent_id'] ?? null,
            'content' => $comment['content'],
            'image' => $comment['image'] ?? null,
            // V57：多图评论，兼容老的单图 image 字段
            'images' => self::parseCommentImages($comment),
            'is_ai' => !empty($comment['is_ai']),
            'created_at' => $comment['created_at'],
            'time_ago' => Helper::timeAgo($comment['created_at']),
            'liked' => !empty($comment['liked']),
            'like_count' => (int)($comment['like_count'] ?? 0),
            // V83：神评标记 + 是否可标记（仅动态详情页有意义，列表页恒为 false）
            'is_god' => !empty($comment['is_god']),
            'can_mark' => $canMark && empty($comment['is_ai']) && (int)($comment['user_id'] ?? 0) > 0,
            'user' => [
                'id' => $comment['user_id'] ?? 0,
                'username' => $comment['username'],
                // V54：已注销账号的评论同样保留，作者统一显示「已注销用户」
                'nickname' => Helper::isDeletedAccount($comment['username'] ?? null, $comment['nickname'] ?? null)
                    ? '已注销用户'
                    : $comment['nickname'],
                'avatar' => $comment['avatar'],
                // V71：下发会员等级/头像框，Web 端与 App 端才能在评论处显示会员徽章
                'vip_level' => (int)($comment['vip_level'] ?? 0),
                'avatar_frame' => $comment['avatar_frame'] ?? 'none',
                // V83：评论作者内联徽章（名字旁展示，与实名徽章同一位置）
                'badges' => $comment['badges'] ?? []
            ]
        ];
    }

    /**
     * V57：把评论的图片字段统一成数组
     *
     * 老数据只有 image（单张），新数据用 images（JSON 数组），
     * 这里合并输出，前端只认 images 就行，不用再判断两套字段。
     *
     * @param array $comment
     * @return string[]
     */
    public static function parseCommentImages(array $comment): array
    {
        $urls = [];
        $raw = $comment['images'] ?? null;
        if (!empty($raw)) {
            if (is_array($raw)) {
                $urls = $raw;
            } else {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) {
                    $urls = $decoded;
                }
            }
        }
        // 老单图兜底
        $single = $comment['image'] ?? null;
        if ($single && !in_array($single, $urls, true)) {
            $urls[] = $single;
        }

        $clean = [];
        foreach ($urls as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $url = trim($url);
            if (strlen($url) > 500 || in_array($url, $clean, true)) {
                continue;
            }
            $isRelative = str_starts_with($url, '/') && !str_starts_with($url, '//');
            $isHttp = preg_match('#^https?://#i', $url) === 1;
            if (!$isRelative && !$isHttp) {
                continue;
            }
            $clean[] = $url;
        }

        return $clean;
    }

    /**
     * 单条动态详情格式化（复用批量格式化逻辑，自动加载点赞/评论/下架信息）
     *
     * @param array $post         原始动态数据（含作者字段）
     * @param int   $currentUserId 当前登录用户 ID
     * @return array 格式化后的动态数据
     */
    public static function formatPostSingle(array $post, int $currentUserId): array
    {
        $postId = $post['id'];

        $likes = Cache::remember("post_likes_{$postId}", function () use ($postId) {
            return Database::fetchAll(
                "SELECT u.id, u.username, u.nickname
                 FROM likes l
                 INNER JOIN users u ON l.user_id = u.id
                 WHERE l.post_id = ? ORDER BY l.created_at ASC",
                [$postId]
            );
        }, 60);

        // P2-2 修复：评论缓存键并入 $currentUserId。
        // 原 key="post_comments_{postId}" 与用户无关，但内部 liked 字段（是否点赞过这条评论）
        // 是当前用户专属，导致用户 A 点赞后的缓存被用户 B 读取（B 看到「自己点过赞」的幻觉）。
        // 为降低缓存副本数，将「全用户共享的原始评论 + like_count」与「当前用户的 liked 状态」拆两层：
        //   - 共享层：Cache key = post_comments_raw_{postId}（600s，10 分钟）
        //   - 用户 liked：对登录用户在缓存读取后单独补全（IN 查 comment_likes），不写入共享缓存
        $sharedComments = Cache::remember("post_comments_raw_{$postId}", function () use ($postId) {
            // is_pinned 由 V47 迁移添加。升级未完成时不让整条查询挂掉，
            // 拿不到该列就退回不带置顶的旧查询。
            try {
                $list = Database::fetchAll(
                    "SELECT c.id, c.user_id, c.parent_id, c.content, c.image, c.is_ai, c.created_at,
                            COALESCE(c.is_pinned, 0) AS is_pinned,
                            u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
                     FROM comments c
                     INNER JOIN users u ON c.user_id = u.id
                     WHERE c.post_id = ?
                       AND c.status = 1 AND (c.audit_state IS NULL OR c.audit_state <> 1)
                     ORDER BY COALESCE(c.is_pinned, 0) DESC, c.created_at ASC",
                    [$postId]
                );
            } catch (\Throwable $e) {
                $list = Database::fetchAll(
                    "SELECT c.id, c.user_id, c.parent_id, c.content, c.image, c.is_ai, c.created_at,
                            0 AS is_pinned,
                            u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
                     FROM comments c
                     INNER JOIN users u ON c.user_id = u.id
                     WHERE c.post_id = ?
                       AND c.status = 1 AND (c.audit_state IS NULL OR c.audit_state <> 1)
                     ORDER BY c.created_at ASC",
                    [$postId]
                );
            }
            // like_count 是全用户共享的聚合值，放在共享层即可
            if (!empty($list)) {
                $ids = array_unique(array_column($list, 'id'));
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $counts = Database::fetchAll(
                    "SELECT comment_id, COUNT(*) AS cnt FROM comment_likes WHERE comment_id IN ({$placeholders}) GROUP BY comment_id",
                    $ids
                );
                $cntMap = [];
                foreach ($counts as $row) $cntMap[$row['comment_id']] = (int)$row['cnt'];
                foreach ($list as &$c) {
                    $c['like_count'] = $cntMap[$c['id']] ?? 0;
                }
                unset($c);
            }
            return $list;
        }, 600); // 共享层延长到 10 分钟，评论增删时会 Cache::delete(post_comments_raw_*) 精确失效

        // 当前用户专属：liked 状态（登录用户才去查，避免 N 次全表扫）
        $comments = $sharedComments;
        if ($currentUserId > 0 && !empty($comments)) {
            $ids = array_unique(array_column($comments, 'id'));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $mine = Database::fetchAll(
                "SELECT comment_id FROM comment_likes WHERE comment_id IN ({$placeholders}) AND user_id = ?",
                array_merge($ids, [$currentUserId])
            );
            $likedMap = [];
            foreach ($mine as $row) $likedMap[$row['comment_id']] = true;
            foreach ($comments as &$c) {
                $c['liked'] = !empty($likedMap[$c['id']]);
            }
            unset($c);
        } else {
            // 游客：liked 固定为 false
            foreach ($comments as &$c) {
                $c['liked'] = false;
            }
            unset($c);
        }

        $blockedUrls = Database::fetchAll(
            "SELECT url FROM uploads WHERE post_id = ? AND status = 0",
            [$postId]
        );
        $blockedSet = array_flip(array_column($blockedUrls, 'url'));

        // V83：神评标记 + 评论作者内联徽章（此链路评论为重新查询，需补齐）
        $commentIds = array_unique(array_column($comments, 'id'));
        if (!empty($commentIds)) {
            $cph = implode(',', array_fill(0, count($commentIds), '?'));
            $godRows = Database::fetchAll(
                "SELECT comment_id FROM comment_marks WHERE mark_type = 'god' AND comment_id IN ({$cph})",
                $commentIds
            );
            $godSet = array_flip(array_column($godRows, 'comment_id'));
            $authorIds = array_values(array_unique(array_column($comments, 'user_id')));
            $badgeMap = BadgeService::getInlineBadgesBatch($authorIds);
            foreach ($comments as &$c) {
                $c['is_god'] = isset($godSet[$c['id']]);
                $c['badges'] = $badgeMap[$c['user_id']] ?? [];
            }
            unset($c);
        }

        return self::formatPostBatch($post, $currentUserId, $likes, $comments, $blockedSet);
    }
}
