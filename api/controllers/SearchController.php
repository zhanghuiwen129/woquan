<?php
/**
 * Hublog 内容搜索控制器
 * 基于 FULLTEXT 索引（ngram 分词）进行动态搜索，自动降级到 LIKE 匹配
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Auth;
use Hublog\Core\SearchRepository;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\PostFormatter;
use Hublog\Core\VisibilityPolicy;

class SearchController extends BaseController
{
    /**
     * 全文搜索动态
     * GET /api/search
     * 参数：q 关键词、page、limit、category_id、date_range(7d/30d/all)、media_type(all/image/video/text)
     * 仅检索 status=1 的动态，结果缓存 60 秒
     */
    public function search(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $userId = $currentUser['id'] ?? 0;

        $q = trim($request->getString('q'));
        if ($q === '') {
            return Response::success(Helper::paginatedResponse([], 1, 20, 0));
        }

        // P2-10 修复：关键词长度上限（100 字符 / 200 字节的保守限制）
        // FULLTEXT ngram 解析器对超长字符串会先 tokenize 再排序，超长文本（如整段正文被贴进搜索框）
        // 会造成 mysqld CPU 尖峰 + 临时表膨胀；另外对 mb_strlen < 2 的降级 LIKE 来说，
        // 200 字符的 LIKE '%...%' 也会造成全表扫描压力 → 统一入口截断。
        $maxLen = 100;
        if (mb_strlen($q) > $maxLen) {
            $q = mb_substr($q, 0, $maxLen);
        }
        // 过滤 ASCII 控制字符（\x00-\x1f 及 DEL）：部分客户端会在拷贝内容时带入零宽字符或控制符，
        // 导致 MATCH AGAINST 出现「不合法的 BOOLEAN 模式语法」错误
        $q = preg_replace('/[\x00-\x1F\x7F]/u', '', $q) ?? '';
        if ($q === '') {
            return Response::success(Helper::paginatedResponse([], 1, 20, 0));
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(10, 20);
        // P1-4：向后兼容游标——传 before_id 时按 id 游标翻页（替代 OFFSET，避免深翻页性能问题）；
        // 不传则走原 offset 分页，老客户端无感。
        $beforeId = $request->getInt('before_id', 0);

        $categoryId = $request->getInt('category_id');
        $dateRange = $request->getString('date_range'); // 7d, 30d, all
        $mediaType = $request->getString('media_type'); // all, image, video, text

        // 缓存键升 v2：v1 的缓存里可能含有未按可见范围过滤的结果，禁止复用旧键。
        $cacheKey = 'search_v2_' . md5($q) . "_{$page}_{$limit}_{$userId}_{$categoryId}_{$dateRange}_{$mediaType}_{$beforeId}";
        [$searchVisWhere, $searchVisParams] = VisibilityPolicy::where($userId);
        $result = Cache::remember($cacheKey, function () use ($q, $page, $limit, $offset, $userId, $categoryId, $dateRange, $mediaType, $beforeId, $searchVisWhere, $searchVisParams) {
            // P1-4 / V93：FULLTEXT 索引不可用时直接走 LIKE；WHERE 组装与 ? 绑定在仓储内完成
            $useFulltext = mb_strlen($q) >= 2 && self::fulltextIndexAvailable();

            // 搜索 ID（FULLTEXT 不再用 LIMIT?,?，因为外层已传 offset/limit；这里保持分页兼容）
            try {
                $ids = SearchRepository::fetchPostIds(
                    $q, $categoryId, $dateRange, $mediaType, $beforeId, $offset, $limit,
                    $useFulltext, $searchVisWhere, $searchVisParams
                );
            } catch (\Throwable $e) {
                // 老安装可能未建 FULLTEXT 索引（执行计划错误或语法错误），降级到 LIKE
                error_log('[Hublog Search] FULLTEXT 查询失败，降级到 LIKE: ' . $e->getMessage()); // @allow-error-log
                $useFulltext = false;
                $ids = SearchRepository::fetchPostIds(
                    $q, $categoryId, $dateRange, $mediaType, $beforeId, $offset, $limit,
                    false, $searchVisWhere, $searchVisParams
                );
            }

            // 统计总数（与上面同条件；FULLTEXT 失败时降级为 LIKE 口径）
            $total = SearchRepository::countPosts(
                $q, $categoryId, $dateRange, $mediaType, $beforeId, $useFulltext,
                $searchVisWhere, $searchVisParams
            );

            if (empty($ids)) {
                return Helper::paginatedResponse([], $page, $limit, $total);
            }

            $postIds = array_column($ids, 'id');
            $list = $this->loadPostsBatch($postIds, $userId, $searchVisWhere, $searchVisParams);

            $nextBeforeId = 0;
            if (!empty($postIds)) {
                $nextBeforeId = (int)$postIds[count($postIds) - 1];
            }
            $result = Helper::paginatedResponse($list, $page, $limit, $total);
            // P1-4：游标翻页元数据，前端拿到 next_before_id 直接传给下一页 before_id；
            // 老客户端不传 before_id 时也忽略这两个字段，无感。
            $result['pagination']['next_before_id'] = $nextBeforeId;
            $result['pagination']['has_more'] = count($postIds) >= $limit;
            return $result;
        }, 60);

        return Response::success($result);
    }

    /**
     * 批量加载动态详情、点赞、评论，并按搜索结果顺序格式化
     */
    private function loadPostsBatch(array $postIds, int $currentUserId, string $visWhere, array $visParams): array
    {
        // P1-5 修复：补齐 p.is_anonymous / p.location 字段，避免匿名动态在搜索结果中泄露作者身份；
        // 字段清单、JOIN、ORDER BY 与迁移前一致，post_type 为铁律追加的无害末列。
        $posts = SearchRepository::fetchPostsByIds($postIds, $visWhere, $visParams);

        // 批量获取点赞
        $likesRaw = SearchRepository::fetchLikesByPostIds($postIds);
        $likesMap = [];
        foreach ($likesRaw as $l) {
            $likesMap[$l['post_id']][] = ['id' => $l['id'], 'username' => $l['username'], 'nickname' => $l['nickname']];
        }

        // 批量获取评论（补齐 c.is_ai，避免 AI 标识丢失，与 PostRepository.fetchByIds 一致）
        $commentsRaw = SearchRepository::fetchCommentsByPostIds($postIds);
        $commentsMap = [];
        foreach ($commentsRaw as $c) {
            $commentsMap[$c['post_id']][] = $c;
        }

        // 批量获取被下架的图片/附件
        $blockedUrls = SearchRepository::fetchBlockedUploadUrls($postIds);
        $blockedSet = array_flip(array_column($blockedUrls, 'url'));

        $list = [];
        foreach ($posts as $post) {
            $list[] = $this->formatPostBatch($post, $currentUserId, $likesMap[$post['id']] ?? [], $commentsMap[$post['id']] ?? [], $blockedSet);
        }
        return $list;
    }

    private function formatPostBatch(array $post, int $currentUserId, array $likes, array $comments, array $blockedSet = []): array
    {
        // 统一调用 PostFormatter，避免与核心服务实现脱节
        // 旧本地实现缺少 videos / location / is_anonymous / 评论 is_ai 等字段
        return PostFormatter::formatPostBatch($post, $currentUserId, $likes, $comments, $blockedSet);
    }

    /**
     * posts 全文索引是否可用。
     *
     * V93：MariaDB 等数据库不支持 `WITH PARSER ngram`，索引可能从未建成；
     * 此时 MATCH...AGAINST 会抛 MySQL 1191。这里缓存 10 分钟的判定结果，
     * 避免每次搜索都去 information_schema 查一次。
     */
    private static function fulltextIndexAvailable(): bool
    {
        static $cached = null;
        static $cachedAt = 0;

        if ($cached !== null && (time() - $cachedAt) < 600) {
            return $cached;
        }

        try {
            $count = SearchRepository::countFulltextIndexes();
            $cached = $count > 0;
        } catch (\Throwable $e) {
            // 查不到就按「不可用」处理：退化成 LIKE 不会 500，比 MATCH 报错安全
            $cached = false;
        }
        $cachedAt = time();

        return $cached;
    }
}
