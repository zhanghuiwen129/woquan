<?php
/**
 * Hublog 智能搜索插件
 * 支持 MySQL LIKE 全文搜索 + 向量语义搜索
 *
 * 触发流程：
 *   1. 插件加载时注册 routes_register 钩子，添加 /api/search/semantic 路由
 *   2. 接收 q/page/limit 参数，先执行 LIKE 搜索，若启用向量搜索追加语义搜索
 *   3. 在 post_created 钩子中把 embedding 生成入队（AsyncTask → jobs），
 *      cron worker 在独立请求里生成向量；插件默认关闭
 *
 * @name 智能搜索
 * @description MySQL LIKE 全文搜索 + Embedding 向量语义搜索，理解关键词背后的含义
 * @version 1.0.0
 * @author woquan
 */

namespace Hublog\Plugin\SmartSearch;

use Hublog\Core\Hook;
use Hublog\Core\Database;
use Hublog\Core\Logger;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Router;
use Hublog\Core\Auth;
use Hublog\Core\AsyncTask;
use Hublog\Core\VisibilityPolicy;

class Plugin
{
    public function init(): void
    {
        Hook::addAction('routes_register', [self::class, 'registerRoutes']);
        Hook::addAction('post_created', [self::class, 'onPostCreated'], 15);
    }

    public static function registerRoutes(Router $router): void
    {
        // 中间件由 routes_register 的 group 基线提供（api/index.php）：
        // RateLimit / Csrf / License / FeatureGuard。插件不重复声明，避免重复执行。
        $router->get('/api/search/semantic', [self::class, 'semanticSearch']);
    }

    public static function semanticSearch(Request $request): Response
    {
        $q = trim($request->getString('q'));
        if ($q === '') {
            return Response::success(Helper::paginatedResponse([], 1, 20, 0));
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(10, 20);

        $currentUser = Auth::user($request);
        $userId = (int)($currentUser['id'] ?? 0);
        // 可见性修复后结果因人而异：缓存键必须并入 userId，否则 A 的私有结果会喂给 B。
        $cacheKey = 'semantic_search_' . md5($q) . "_{$page}_{$limit}_{$userId}";
        [$visWhere, $visParams] = VisibilityPolicy::where($userId);
        $result = Cache::remember($cacheKey, function () use ($q, $page, $limit, $offset, $userId, $visWhere, $visParams) {
            $config = self::getSearchConfig();

            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $likeIds = Database::fetchAll(
                "SELECT p.id FROM posts p WHERE p.status = 1 AND (p.title LIKE ? OR p.content LIKE ?) AND {$visWhere} ORDER BY p.created_at DESC LIMIT ?, ?",
                array_merge([$like, $like], $visParams, [0, 100])
            );
            $likeIdMap = [];
            foreach ($likeIds as $row) {
                $likeIdMap[(int)$row['id']] = true;
            }

            $vectorResults = [];
            if (($config['smart_search_enabled'] ?? '0') === '1'
                && !empty($config['smart_search_api_url'])
                && !empty($config['smart_search_api_key'])) {
                try {
                    $vectorResults = self::vectorSearch($q, $config, $userId);
                } catch (\Throwable $e) {
                    Logger::warning('向量搜索失败，降级为普通搜索', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $scoreMap = [];
            foreach ($likeIdMap as $id => $_) {
                $scoreMap[$id] = ($scoreMap[$id] ?? 0) + 1.0;
            }
            foreach ($vectorResults as $id => $score) {
                $scoreMap[$id] = ($scoreMap[$id] ?? 0) + $score;
            }

            if (empty($scoreMap)) {
                $total = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM posts p WHERE p.status = 1 AND (p.title LIKE ? OR p.content LIKE ?) AND {$visWhere}",
                    array_merge([$like, $like], $visParams)
                );
                return Helper::paginatedResponse([], $page, $limit, $total);
            }

            arsort($scoreMap);
            $allIds = array_keys($scoreMap);
            $total = count($allIds);
            $pageIds = array_slice($allIds, $offset, $limit);

            if (empty($pageIds)) {
                return Helper::paginatedResponse([], $page, $limit, $total);
            }

            $list = self::loadPostsBatch($pageIds, $userId, $visWhere, $visParams);
            return Helper::paginatedResponse($list, $page, $limit, $total);
        }, 60);

        return Response::success($result);
    }

    private static function vectorSearch(string $q, array $config, int $userId): array
    {
        $embedding = self::callEmbeddingApi($q, $config);
        if ($embedding === null || empty($embedding)) {
            return [];
        }

        $embeddingJson = json_encode($embedding);
        if ($embeddingJson === false) {
            return [];
        }

        // 向量候选也必须过可见性：私有/圈内/好友帖的 embedding 不得出现在公开检索结果。
        [$visWhere, $visParams] = VisibilityPolicy::where($userId);
        $posts = Database::fetchAll(
            "SELECT p.id, p.embedding FROM posts p
             WHERE p.status = 1 AND p.embedding IS NOT NULL AND p.embedding != ''
               AND {$visWhere} LIMIT 500",
            $visParams
        );

        $results = [];
        $queryVec = $embedding;
        $queryNorm = self::vecNorm($queryVec);

        foreach ($posts as $post) {
            $stored = json_decode($post['embedding'], true);
            if (!is_array($stored) || count($stored) !== count($queryVec)) {
                continue;
            }
            $postNorm = self::vecNorm($stored);
            if ($postNorm == 0) {
                continue;
            }
            $similarity = self::cosineSimilarity($queryVec, $stored, $queryNorm, $postNorm);
            if ($similarity > 0.5) {
                $results[(int)$post['id']] = round($similarity, 4);
            }
        }

        arsort($results);
        return array_slice($results, 0, 100, true);
    }

    private static function callEmbeddingApi(string $text, array $config): ?array
    {
        $apiUrl = $config['smart_search_api_url'] ?? '';
        $apiKey = $config['smart_search_api_key'] ?? '';
        $model = $config['smart_search_model'] ?? 'text-embedding-v1';

        if ($apiUrl === '' || $apiKey === '') {
            return null;
        }

        if (!function_exists('curl_init')) {
            Logger::warning('智能搜索: 服务器未启用 cURL 扩展');
            return null;
        }

        $payload = [
            'model' => $model,
            'input' => $text,
            'encoding_format' => 'float'
        ];

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            Logger::warning('Embedding API 调用失败', [
                'http_code' => $httpCode,
                'error' => $error
            ]);
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['data'][0]['embedding'])) {
            return null;
        }

        return $data['data'][0]['embedding'];
    }

    private static function vecNorm(array $vec): float
    {
        $sum = 0;
        foreach ($vec as $v) {
            $sum += $v * $v;
        }
        return sqrt($sum);
    }

    private static function cosineSimilarity(array $a, array $b, float $normA, float $normB): float
    {
        if ($normA == 0 || $normB == 0) {
            return 0;
        }
        $dot = 0;
        $len = count($a);
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
        }
        return $dot / ($normA * $normB);
    }

    private static function loadPostsBatch(array $postIds, int $currentUserId = 0, string $visWhere = '', array $visParams = []): array
    {
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

        $list = [];
        $orderMap = array_flip($postIds);
        foreach ($posts as $post) {
            // DB 层 visWhere 是主闸；这里逐行 canView 兜底（默认拒绝），
            // 防止未来新增候选来源（如向量召回）绕过 SQL 条件。
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

    public static function onPostCreated(int $postId, int $userId): void
    {
        try {
            $config = self::getSearchConfig();
            if (($config['smart_search_enabled'] ?? '0') !== '1') {
                return;
            }
            if (empty($config['smart_search_api_url']) || empty($config['smart_search_api_key'])) {
                return;
            }

            // 只做一次廉价的「帖子是否存在」校验；生成 embedding（外部 API）
            // 入队由 cron worker 在独立请求执行。
            // 【为什么删掉 fastcgi_finish_request()】post_created 钩子是在
            // PostController 内部触发的，此时响应体还没 echo，直接 flush 会把
            // 空 body 发给客户端（发帖接口解析失败）。见 api/core/Plugin.php 顶部警告。
            $exists = Database::fetchColumn(
                "SELECT id FROM posts WHERE id = ? AND status = 1 LIMIT 1",
                [$postId]
            );
            if (!$exists) {
                return;
            }

            AsyncTask::defer(
                'smart_search',
                ['post_id' => $postId],
                'smart_search_post_' . $postId
            );
        } catch (\Throwable $e) {
            Logger::error('智能搜索入队异常', [
                'post_id' => $postId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 异步队列适配入口：由 AsyncTask 白名单调用。
     *
     * @param array $payload ['post_id'=>int]
     * @return bool 是否处理成功（false 会让任务标记 failed，便于运维发现）
     */
    public static function runFromPayload(array $payload): bool
    {
        $postId = (int)($payload['post_id'] ?? 0);
        if ($postId <= 0) {
            Logger::error('智能搜索任务参数非法', ['post_id' => $postId]);
            return false;
        }

        try {
            $config = self::getSearchConfig();
            if (($config['smart_search_enabled'] ?? '0') !== '1') {
                return true;
            }
            if (empty($config['smart_search_api_url']) || empty($config['smart_search_api_key'])) {
                return true;
            }

            $post = Database::fetchOne(
                "SELECT id, title, content FROM posts WHERE id = ? AND status = 1 LIMIT 1",
                [$postId]
            );
            if (!$post) {
                return true;
            }

            $plainContent = strip_tags((string)$post['content']);
            $plainContent = trim((string)preg_replace('/\s+/', ' ', $plainContent));
            if ($plainContent === '' && empty($post['title'])) {
                return true;
            }

            $text = !empty($post['title']) ? $post['title'] . ' ' . $plainContent : $plainContent;
            $text = mb_substr($text, 0, 2000);

            $embedding = self::callEmbeddingApi($text, $config);
            if ($embedding === null) {
                Logger::warning('Embedding 向量生成失败', ['post_id' => $postId]);
                return false;
            }

            Database::update('posts', [
                'embedding' => json_encode($embedding)
            ], 'id = ?', [$postId]);

            Logger::info('Embedding 向量生成成功', ['post_id' => $postId]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('智能搜索 Embedding 异常', [
                'post_id' => $postId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private static function getSearchConfig(): array
    {
        return Cache::remember('smart_search_settings', function () {
            $rows = Database::fetchAll(
                "SELECT `key`, `value` FROM settings WHERE `key` LIKE 'smart_search_%'"
            );
            $cfg = [];
            foreach ($rows as $row) {
                $cfg[$row['key']] = $row['value'];
            }
            return $cfg;
        }, 3600);
    }
}