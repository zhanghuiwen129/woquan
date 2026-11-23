<?php
/**
 * Hublog 动态收藏控制器
 * 支持收藏切换、收藏列表、收藏状态查询
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\FavoriteRepository;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\PostFormatter;
use Hublog\Core\VisibilityPolicy;

class FavoriteController extends BaseController
{
    /**
     * 切换收藏状态
     * POST /api/favorites/toggle
     * 参数：post_id
     */
    public function toggle(Request $request): Response
    {
        $user = Auth::check($request);
        $postId = $request->getInt('post_id');
        if (!$postId) {
            return Response::error('参数错误', 400);
        }

        // 动态存在性守卫与完整事务均已下沉到仓储；null 表示动态不存在/不可见
        $favorited = FavoriteRepository::toggleFavorite($postId, (int)$user['id'], Helper::now());
        if ($favorited === null) {
            return Response::error('动态不存在', 404);
        }

        // 清理该用户的收藏列表缓存
        $this->clearCache('favorites_user_v2_' . $user['id'] . '_');

        return Response::success(['favorited' => $favorited], $favorited ? '已收藏' : '已取消收藏');
    }

    /**
     * 获取当前用户的收藏列表
     * GET /api/favorites/list
     * 参数：page、limit
     */
    public function list(Request $request): Response
    {
        $user = Auth::check($request);
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(10, 20);

        $userId = $user['id'];
        // v2：旧键缓存的是未按可见性过滤的收藏列表，禁止复用。
        [$favVisWhere, $favVisParams] = VisibilityPolicy::where((int)$userId);
        $cacheKey = "favorites_user_v2_{$userId}_{$page}_{$limit}";
        $result = Cache::remember($cacheKey, function () use ($userId, $page, $limit, $offset, $favVisWhere, $favVisParams) {
            // 1. 取该用户收藏且动态仍可见的 post_id 列表（列表/计数共用仓储 WHERE）
            $rows = FavoriteRepository::fetchVisibleFavoritePage((int)$userId, $offset, $limit, $favVisWhere, $favVisParams);

            $total = FavoriteRepository::countVisibleFavorites((int)$userId, $favVisWhere, $favVisParams);

            if (empty($rows)) {
                return Helper::paginatedResponse([], $page, $limit, $total);
            }

            // 保留收藏顺序与收藏时间
            $favoritedAtMap = [];
            $postIds = [];
            foreach ($rows as $row) {
                $postIds[] = (int)$row['post_id'];
                $favoritedAtMap[(int)$row['post_id']] = $row['favorited_at'];
            }

            $list = $this->loadPostsBatch($postIds, $userId, $favVisWhere, $favVisParams, $favoritedAtMap);

            return Helper::paginatedResponse($list, $page, $limit, $total);
        }, 60);

        return Response::success($result);
    }

    /**
     * 检查当前用户是否已收藏指定动态
     * GET /api/favorites/check
     * 参数：post_id
     */
    public function check(Request $request): Response
    {
        $user = Auth::check($request);
        $postId = $request->getInt('post_id');
        if (!$postId) {
            return Response::error('参数错误', 400);
        }

        $exists = FavoriteRepository::findFavoriteRow($postId, (int)$user['id']);

        return Response::success(['favorited' => (bool)$exists]);
    }

    /**
     * 批量加载动态详情、点赞、评论，并按指定顺序格式化
     * 额外附加 favorited_at 字段表示收藏时间
     */
    private function loadPostsBatch(
        array $postIds,
        int $currentUserId,
        string $visWhere,
        array $visParams,
        array $favoritedAtMap = []
    ): array {
        $posts = FavoriteRepository::fetchFavoritePosts($postIds, $visWhere, $visParams);

        // 批量获取点赞
        $likesRaw = FavoriteRepository::fetchLikeRows($postIds);
        $likesMap = [];
        foreach ($likesRaw as $l) {
            $likesMap[$l['post_id']][] = ['id' => $l['id'], 'username' => $l['username'], 'nickname' => $l['nickname']];
        }

        // 批量获取评论
        $commentsRaw = FavoriteRepository::fetchCommentRows($postIds);
        $commentsMap = [];
        foreach ($commentsRaw as $c) {
            $commentsMap[$c['post_id']][] = $c;
        }

        // 批量获取被下架的图片/附件
        $blockedUrls = FavoriteRepository::fetchBlockedUploadUrls($postIds);
        $blockedSet = array_flip(array_column($blockedUrls, 'url'));

        // 以传入的 post_id 顺序为准，保证收藏列表按收藏时间倒序
        $postsMap = [];
        foreach ($posts as $post) {
            $postsMap[(int)$post['id']] = $post;
        }

        $list = [];
        foreach ($postIds as $pid) {
            if (!isset($postsMap[$pid])) {
                continue;
            }
            $post = $postsMap[$pid];
            $formatted = $this->formatPostBatch($post, $currentUserId, $likesMap[$pid] ?? [], $commentsMap[$pid] ?? [], $blockedSet);
            if (isset($favoritedAtMap[$pid])) {
                $formatted['favorited_at'] = $favoritedAtMap[$pid];
                // V72：收藏列表里的动态必然处于已收藏状态，卡片星标直接点亮
                $formatted['favorited'] = true;
            }
            $list[] = $formatted;
        }
        return $list;
    }

    private function formatPostBatch(array $post, int $currentUserId, array $likes, array $comments, array $blockedSet = []): array
    {
        // 统一调用 PostFormatter，避免与核心服务实现脱节
        // 旧本地实现缺少 videos / location / is_anonymous / 评论 is_ai 等字段
        return PostFormatter::formatPostBatch($post, $currentUserId, $likes, $comments, $blockedSet);
    }
}
