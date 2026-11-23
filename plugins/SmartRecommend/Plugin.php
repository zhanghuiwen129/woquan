<?php
/**
 * Hublog 个性化推荐插件（纯规则，无 AI）
 *
 * 基于用户行为协同过滤 + 标签内容推荐 + 时间衰减热度的综合推荐。
 * 注意：本插件是纯规则算法，不调用任何 AI / 大模型服务；不得引入 AiService。
 * 目录名与命名空间是插件标识（加载器按目录名拼类名），故不重命名，仅在此标注定位。
 *
 * @name 个性化推荐（规则）
 * @description 基于用户行为协同过滤 + 标签内容 + 时间衰减热度，返回个性化推荐动态（纯规则，无 AI）
 * @version 1.0.0
 * @author woquan
 *
 * 触发流程：
 *   1. 插件加载时注册 routes_register 钩子，添加 /api/posts/recommend 路由
 *   2. 监听 post_liked/post_commented/post_viewed 钩子记录用户行为到 user_actions 表
 *   3. 推荐接口综合多种算法加权排序返回推荐动态
 */

namespace Hublog\Plugin\SmartRecommend;

use Hublog\Core\Hook;
use Hublog\Core\Database;
use Hublog\Core\Logger;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Router;
use Hublog\Core\Auth;
use Hublog\Core\VisibilityPolicy;

class Plugin
{
    private const ACTION_WEIGHTS = [
        'like' => 3.0,
        'comment' => 2.0,
        'view' => 1.0
    ];

    private const DECAY_HALF_LIFE = 86400;

    public function init(): void
    {
        Hook::addAction('routes_register', [self::class, 'registerRoutes']);
        Hook::addAction('post_liked', [self::class, 'onPostLiked']);
        Hook::addAction('post_commented', [self::class, 'onPostCommented']);
        Hook::addAction('post_viewed', [self::class, 'onPostViewed']);
    }

    public static function registerRoutes(Router $router): void
    {
        // 中间件由 routes_register 的 group 基线提供（api/index.php）：
        // RateLimit / Csrf / License / FeatureGuard。插件不重复声明，避免重复执行。
        $router->get('/api/posts/recommend', [self::class, 'recommend']);
    }

    public static function recommend(Request $request): Response
    {
        $config = self::getRecommendConfig();
        if (($config['smart_recommend_enabled'] ?? '0') !== '1') {
            return Response::success(Helper::paginatedResponse([], 1, 20, 0));
        }

        $currentUser = Auth::user($request);
        $userId = $currentUser['id'] ?? 0;

        $maxItems = (int)($config['smart_recommend_max_items'] ?? 20);
        $limit = min($maxItems, max(1, $request->getInt('limit', 10)));

        $cacheKey = "recommend_{$userId}_{$limit}";
        [$visWhere, $visParams] = VisibilityPolicy::where($userId);
        $result = Cache::remember($cacheKey, function () use ($userId, $limit, $visWhere, $visParams) {
            $candidateScores = [];

            if ($userId > 0) {
                $cfScores = self::collaborativeFiltering($userId);
                foreach ($cfScores as $postId => $score) {
                    $candidateScores[$postId] = ($candidateScores[$postId] ?? 0) + $score * 0.5;
                }

                $contentScores = self::contentRecommendation($userId, $visWhere, $visParams);
                foreach ($contentScores as $postId => $score) {
                    $candidateScores[$postId] = ($candidateScores[$postId] ?? 0) + $score * 0.3;
                }
            }

            $hotScores = self::hotnessRecommendation($visWhere, $visParams);
            foreach ($hotScores as $postId => $score) {
                $candidateScores[$postId] = ($candidateScores[$postId] ?? 0) + $score * 0.2;
            }

            if (empty($candidateScores)) {
                $hotPostIds = array_keys($hotScores);
                if (empty($hotPostIds)) {
                    return Helper::paginatedResponse([], 1, $limit, 0);
                }
                $candidateScores = [];
                foreach ($hotPostIds as $id) {
                    $candidateScores[$id] = 0.1;
                }
            }

            arsort($candidateScores);
            $topIds = array_slice(array_keys($candidateScores), 0, $limit);

            if (empty($topIds)) {
                return Helper::paginatedResponse([], 1, $limit, 0);
            }

            $list = self::loadPostsBatch($topIds, $userId, $visWhere, $visParams);
            return Helper::paginatedResponse($list, 1, $limit, count($candidateScores));
        }, 120);

        return Response::success($result);
    }

    private static function collaborativeFiltering(int $userId): array
    {
        try {
            $userActions = Database::fetchAll(
                "SELECT post_id, action, weight FROM user_actions WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
                [$userId]
            );

            if (empty($userActions)) {
                return [];
            }

            $userPostScore = [];
            foreach ($userActions as $act) {
                $actionWeight = self::ACTION_WEIGHTS[$act['action']] ?? 1.0;
                $userPostScore[$act['post_id']] = ($userPostScore[$act['post_id']] ?? 0) + $actionWeight * (float)$act['weight'];
            }

            if (empty($userPostScore)) {
                return [];
            }

            $userPostIds = array_keys($userPostScore);
            $placeholders = implode(',', array_fill(0, count($userPostIds), '?'));

            $similarUsers = Database::fetchAll(
                "SELECT user_id, SUM(weight) as similarity FROM user_actions
                 WHERE post_id IN ({$placeholders}) AND user_id != ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY user_id
                 ORDER BY similarity DESC
                 LIMIT 50",
                array_merge($userPostIds, [$userId])
            );

            if (empty($similarUsers)) {
                return [];
            }

            $candidateScores = [];
            foreach ($similarUsers as $su) {
                $simUserId = $su['user_id'];
                $simActions = Database::fetchAll(
                    "SELECT post_id, action, weight FROM user_actions WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
                    [$simUserId]
                );

                foreach ($simActions as $act) {
                    $postId = $act['post_id'];
                    if (isset($userPostScore[$postId])) {
                        continue;
                    }
                    $actionWeight = self::ACTION_WEIGHTS[$act['action']] ?? 1.0;
                    $candidateScores[$postId] = ($candidateScores[$postId] ?? 0) + $actionWeight * (float)$act['weight'] * (float)$su['similarity'];
                }
            }

            arsort($candidateScores);
            return array_slice($candidateScores, 0, 50, true);
        } catch (\Throwable $e) {
            Logger::warning('协同过滤推荐失败', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    private static function contentRecommendation(int $userId, string $visWhere = '', array $visParams = []): array
    {
        if ($visWhere === '') {
            [$visWhere, $visParams] = VisibilityPolicy::where($userId);
        }
        try {
            $userActions = Database::fetchAll(
                "SELECT ua.post_id, ua.action FROM user_actions ua
                 WHERE ua.user_id = ? AND ua.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                 LIMIT 100",
                [$userId]
            );

            if (empty($userActions)) {
                return [];
            }

            $postIds = array_unique(array_column($userActions, 'post_id'));
            if (empty($postIds)) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($postIds), '?'));
            $tags = Database::fetchAll(
                "SELECT tag FROM post_tags WHERE post_id IN ({$placeholders})",
                $postIds
            );

            if (empty($tags)) {
                return [];
            }

            $tagCounts = [];
            foreach ($tags as $t) {
                $tag = $t['tag'];
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }

            arsort($tagCounts);
            $topTags = array_slice(array_keys($tagCounts), 0, 10);

            if (empty($topTags)) {
                return [];
            }

            $tagPlaceholders = implode(',', array_fill(0, count($topTags), '?'));
            $excludePlaceholders = implode(',', array_fill(0, count($postIds), '?'));

            $candidates = Database::fetchAll(
                "SELECT pt.post_id, pt.tag, p.created_at FROM post_tags pt
                 INNER JOIN posts p ON pt.post_id = p.id
                 WHERE pt.tag IN ({$tagPlaceholders})
                 AND p.status = 1
                 AND {$visWhere}
                 AND pt.post_id NOT IN ({$excludePlaceholders})
                 ORDER BY p.created_at DESC
                 LIMIT 200",
                array_merge($topTags, $visParams, $postIds)
            );

            $candidateScores = [];
            foreach ($candidates as $c) {
                $postId = $c['post_id'];
                $tag = $c['tag'];
                $tagWeight = $tagCounts[$tag] ?? 0;
                $decay = self::timeDecay($c['created_at']);
                $candidateScores[$postId] = ($candidateScores[$postId] ?? 0) + $tagWeight * $decay;
            }

            arsort($candidateScores);
            return array_slice($candidateScores, 0, 50, true);
        } catch (\Throwable $e) {
            Logger::warning('内容推荐失败', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    private static function hotnessRecommendation(string $visWhere = '', array $visParams = []): array
    {
        try {
            $timeThreshold = date('Y-m-d H:i:s', time() - 7 * 86400);
            if ($visWhere === '') {
                [$visWhere, $visParams] = VisibilityPolicy::where(0);
            }

            $posts = Database::fetchAll(
                "SELECT p.id, p.created_at,
                        (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
                        (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count,
                        (SELECT COUNT(*) FROM user_actions ua WHERE ua.post_id = p.id AND ua.action = 'view') AS view_count
                 FROM posts p
                 WHERE p.status = 1 AND p.created_at > ? AND {$visWhere}
                 ORDER BY p.created_at DESC
                 LIMIT 200",
                array_merge([$timeThreshold], $visParams)
            );

            $scores = [];
            foreach ($posts as $post) {
                $likeCount = (int)$post['like_count'];
                $commentCount = (int)$post['comment_count'];
                $viewCount = (int)$post['view_count'];
                $decay = self::timeDecay($post['created_at']);

                $hotScore = ($likeCount * 3 + $commentCount * 2 + $viewCount * 0.5) * $decay;
                $scores[$post['id']] = $hotScore;
            }

            arsort($scores);
            return array_slice($scores, 0, 100, true);
        } catch (\Throwable $e) {
            Logger::warning('热度推荐失败', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private static function timeDecay(string $createdAt): float
    {
        $age = time() - strtotime($createdAt);
        if ($age <= 0) {
            return 1.0;
        }
        return pow(0.5, $age / self::DECAY_HALF_LIFE);
    }

    private static function loadPostsBatch(array $postIds, int $currentUserId = 0, string $visWhere = '', array $visParams = []): array
    {
        if (empty($postIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        if ($visWhere === '') {
            [$visWhere, $visParams] = VisibilityPolicy::where($currentUserId);
        }

        $posts = Database::fetchAll(
            "SELECT p.id, p.user_id, p.category_id, p.title, p.content, p.images, p.attachments, p.summary, p.is_pinned, p.pinned_at, p.created_at, p.updated_at,
                    p.visibility, p.circle_id,
                    u.username, u.nickname, u.avatar, u.role
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.id IN ({$placeholders}) AND {$visWhere}",
            array_merge($postIds, $visParams)
        );

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
            "SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content, c.image, c.created_at,
                    u.username, u.nickname, u.avatar
             FROM comments c
             INNER JOIN users u ON c.user_id = u.id
             WHERE c.post_id IN ({$placeholders})
               AND c.status = 1 AND (c.audit_state IS NULL OR c.audit_state <> 1)
             ORDER BY c.created_at ASC",
            $postIds
        );
        $commentsMap = [];
        foreach ($commentsRaw as $c) {
            $commentsMap[$c['post_id']][] = $c;
        }

        $blockedUrls = Database::fetchAll(
            "SELECT url FROM uploads WHERE post_id IN ({$placeholders}) AND status = 0",
            $postIds
        );
        $blockedSet = array_flip(array_column($blockedUrls, 'url'));

        $orderMap = array_flip($postIds);
        $list = [];
        foreach ($posts as $post) {
            // CF / 行为表来的 post_id 不走 posts 查询，只能在这里逐行兜底；
            // canView 默认拒绝，私有/非成员圈子/非好友内容一律不返回。
            if (!VisibilityPolicy::canView($post, $currentUserId)) {
                continue;
            }
            $list[] = self::formatPost($post, $currentUserId, $likesMap[$post['id']] ?? [], $commentsMap[$post['id']] ?? [], $blockedSet);
        }

        usort($list, function ($a, $b) use ($orderMap) {
            return ($orderMap[$a['id']] ?? 0) - ($orderMap[$b['id']] ?? 0);
        });

        return $list;
    }

    private static function formatPost(array $post, int $currentUserId, array $likes, array $comments, array $blockedSet = []): array
    {
        $liked = false;
        foreach ($likes as $like) {
            if ($like['id'] == $currentUserId) {
                $liked = true;
                break;
            }
        }

        $images = $post['images'] ? json_decode($post['images'], true) : [];
        $attachments = $post['attachments'] ? json_decode($post['attachments'], true) : [];
        // 站点根绝对路径：不能写裸 'assets/...'（站点根无该目录会 404），
        // 也不能写 'templates/...'（前端 normalizeAssets 不认 templates 前缀，App 端会解析到沙箱失败）
        $blockedImage = defined('UPLOAD_BLOCKED_IMAGE_URL') ? UPLOAD_BLOCKED_IMAGE_URL : '/templates/moments/assets/images/blocked.svg';

        foreach ($images as &$img) {
            if (isset($blockedSet[$img])) {
                $img = $blockedImage;
            }
        }
        unset($img);

        foreach ($attachments as &$att) {
            if (is_array($att) && isset($blockedSet[$att['url']])) {
                $att['blocked'] = true;
            }
        }
        unset($att);

        return [
            'id' => $post['id'],
            'category_id' => isset($post['category_id']) ? (int)$post['category_id'] : null,
            'title' => $post['title'] ?? null,
            'summary' => $post['summary'] ?? null,
            'content' => $post['content'],
            'images' => $images,
            'attachments' => $attachments,
            'is_pinned' => (bool)($post['is_pinned'] ?? 0),
            'pinned_at' => $post['pinned_at'] ?? null,
            'created_at' => $post['created_at'],
            'time_ago' => Helper::timeAgo($post['created_at']),
            'user' => [
                'id' => $post['user_id'],
                'username' => $post['username'],
                'nickname' => $post['nickname'],
                'avatar' => $post['avatar'],
                'role' => $post['role'] ?? 'user'
            ],
            'likes' => $likes,
            'comments' => array_map([self::class, 'formatComment'], $comments),
            'like_count' => count($likes),
            'comment_count' => count($comments),
            'liked' => $liked
        ];
    }

    private static function formatComment(array $comment): array
    {
        return [
            'id' => $comment['id'],
            'parent_id' => $comment['parent_id'] ?? null,
            'content' => $comment['content'],
            'image' => $comment['image'] ?? null,
            'created_at' => $comment['created_at'],
            'time_ago' => Helper::timeAgo($comment['created_at']),
            'user' => [
                'id' => $comment['user_id'] ?? 0,
                'username' => $comment['username'],
                'nickname' => $comment['nickname'],
                'avatar' => $comment['avatar']
            ]
        ];
    }

    public static function onPostLiked(int $postId, int $userId, bool $liked): void
    {
        if (!$liked) {
            return;
        }
        self::recordAction($userId, $postId, 'like', 3.0);
    }

    public static function onPostCommented(int $postId, int $userId, int $commentId): void
    {
        self::recordAction($userId, $postId, 'comment', 2.0);
    }

    public static function onPostViewed(int $postId, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        self::recordAction($userId, $postId, 'view', 1.0);
    }

    private static function recordAction(int $userId, int $postId, string $action, float $weight): void
    {
        try {
            $config = self::getRecommendConfig();
            if (($config['smart_recommend_enabled'] ?? '0') !== '1') {
                return;
            }

            $exists = Database::fetchColumn(
                "SELECT id FROM user_actions WHERE user_id = ? AND post_id = ? AND action = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 1",
                [$userId, $postId, $action]
            );
            if ($exists) {
                return;
            }

            Database::insert('user_actions', [
                'user_id' => $userId,
                'post_id' => $postId,
                'action' => $action,
                'weight' => $weight,
                'created_at' => Helper::now()
            ]);

            Cache::deleteByPrefix("recommend_{$userId}_");
        } catch (\Throwable $e) {
            Logger::warning('用户行为记录失败', [
                'user_id' => $userId,
                'post_id' => $postId,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
        }
    }

    private static function getRecommendConfig(): array
    {
        return Cache::remember('smart_recommend_settings', function () {
            $rows = Database::fetchAll(
                "SELECT `key`, `value` FROM settings WHERE `key` LIKE 'smart_recommend_%'"
            );
            $cfg = [];
            foreach ($rows as $row) {
                $cfg[$row['key']] = $row['value'];
            }
            return $cfg;
        }, 3600);
    }
}