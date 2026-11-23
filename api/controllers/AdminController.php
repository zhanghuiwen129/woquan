<?php
/**
 * Hublog 管理员后台控制器
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\AdminRepository;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Security;
use Hublog\Core\Helper;
use Hublog\Core\PathResolver;
use Hublog\Core\Upload;
use Hublog\Core\Upgrade;
use Hublog\Core\Watermark;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\BadgeService;
use Hublog\Core\MediaHelper;
use Hublog\Core\IntegrationStatus;

class AdminController extends BaseController
{
    public function dashboard(Request $request): Response
    {
        $this->requireAdmin($request);

        $stats = Cache::remember('admin_dashboard', function () {
            return [
                'users' => [
                    'total' => AdminRepository::countAllUsers(),
                    'today' => AdminRepository::countTodayUsers(),
                    'active' => AdminRepository::countActiveUsers()
                ],
                'posts' => [
                    'total' => AdminRepository::countAllPosts(),
                    'today' => AdminRepository::countTodayPosts(),
                    'active' => AdminRepository::countActivePosts()
                ],
                'comments' => [
                    'total' => AdminRepository::countAllComments(),
                    'today' => AdminRepository::countTodayComments()
                ],
                'likes' => [
                    'total' => AdminRepository::countAllLikes(),
                    'today' => AdminRepository::countTodayLikes()
                ]
            ];
        }, 300);

        $serverInfo = [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
            'mysql_version' => AdminRepository::fetchMysqlVersion(),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit'),
            'disk_free' => function_exists('disk_free_space') && disk_free_space(__DIR__) !== false ? $this->formatSize((int)disk_free_space(__DIR__)) : '未知'
        ];

        $currentVersion = defined('APP_VERSION') ? APP_VERSION : 'v1.8';
        $latestVersion = $currentVersion;
        $hasNew = false;
        $releasePage = 'https://gitee.com/aiwoquan/hubloggaidewoquanjiapp/releases';

        $release = $this->fetchGiteeApi('https://gitee.com/api/v5/repos/aiwoquan/hubloggaidewoquanjiapp/releases/latest');
        $fetched = false;
        if ($release && !empty($release['tag_name'])) {
            $latestVersion = 'v' . ltrim($release['tag_name'], 'vV');
            $fetched = true;
        } else {
            $tags = $this->fetchGiteeApi('https://gitee.com/api/v5/repos/aiwoquan/hubloggaidewoquanjiapp/tags');
            if (!empty($tags) && is_array($tags) && !empty($tags[0]['name'])) {
                $latestVersion = 'v' . ltrim($tags[0]['name'], 'vV');
                $fetched = true;
            }
        }
        $hasNew = $fetched && version_compare(ltrim($latestVersion, 'vV'), ltrim($currentVersion, 'vV'), '>');

        $versionInfo = [
            'current' => $currentVersion,
            'latest' => $latestVersion,
            'has_new' => $hasNew,
            'fetched' => $fetched,
            'release_page' => $releasePage
        ];

        return Response::success(array_merge($stats, [
            'server' => $serverInfo,
            'version' => $versionInfo,
            'capabilities' => IntegrationStatus::checkAll(),
        ]));
    }

    public function posts(Request $request): Response
    {
        $this->requireAdmin($request);
        $status = $request->getInt('status', -1);
        $keyword = $request->getString('keyword');
        $categoryId = $request->getInt('category_id', 0);

        $formatter = function ($post) {
            $post['images'] = $post['images'] ? json_decode($post['images'], true) : [];
            // 后台编辑弹窗需要原视频列表（含封面/时长），一并解码返回
            $post['videos'] = $post['videos'] ? json_decode($post['videos'], true) : [];
            $post['display'] = !empty($post['title']) ? $post['title'] : mb_substr(strip_tags($post['content']), 0, 100) . '...';
            $post['is_pinned'] = (bool)$post['is_pinned'];
            return $post;
        };

        // 同 paginate()，但额外附带 status_counts 供后台状态页签显示数量
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20);
        $items = AdminRepository::fetchAdminPosts($status, $keyword, $categoryId, $offset, $limit);
        $items = array_map($formatter, $items);
        $total = AdminRepository::countAdminPosts($status, $keyword, $categoryId);
        $result = Helper::paginatedResponse($items, $page, $limit, $total);
        $result['status_counts'] = $this->postStatusCounts();
        return Response::success($result);
    }

    /**
     * 内容管理页签统计：全量/已发布/受限（status!=1 一律计入受限）
     * 附在 /admin/posts 响应的 status_counts 字段，供后台快速筛选页签展示数量。
     */
    private function postStatusCounts(): array
    {
        $counts = ['all' => 0, 'published' => 0, 'restricted' => 0];
        try {
            foreach (AdminRepository::fetchPostStatusCountsRows() as $row) {
                $counts['all'] += (int)$row['cnt'];
                if ((int)$row['status'] === 1) {
                    $counts['published'] += (int)$row['cnt'];
                } else {
                    $counts['restricted'] += (int)$row['cnt'];
                }
            }
        } catch (\Throwable $e) {
            // 统计失败不阻塞列表
        }
        return $counts;
    }

    public function updatePostStatus(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        $status = $request->getInt('status');

        if (!$id || !in_array($status, [0, 1], true)) {
            return Response::error('参数错误', 400);
        }

        AdminRepository::updatePostStatus($id, $status, Helper::now());
        // P0-11：timeline 命名空间升版。原 clearCache('timeline_v2_') 是早已失效的
        // 前缀（现役键为 timeline_v4_*），删了等于没删；timeline_total_* 同属该命名空间
        Cache::bumpNsVersion('timeline');
        Cache::delete("post_likes_{$id}");
        Cache::delete("post_comments_{$id}");
        Cache::delete("post_comments_raw_{$id}");
        Cache::delete("post_{$id}");

        return Response::success(null, '操作成功');
    }

    /**
     * 管理员置顶/取消置顶动态
     */
    public function pinPost(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        $pinned = $request->getInt('pinned', 0);

        if (!$id || !in_array($pinned, [0, 1], true)) {
            return Response::error('参数错误', 400);
        }

        $post = AdminRepository::findPostIdRow($id);
        if (!$post) {
            return Response::error('动态不存在', 404);
        }

        AdminRepository::updatePostPinned($id, $pinned, Helper::now());

        // P0-11：timeline 命名空间升版（原 clearCache('timeline_v2_') 是废弃前缀，清了等于没清）
        Cache::bumpNsVersion('timeline');
        Cache::delete("post_{$id}");

        return Response::success(null, $pinned ? '置顶成功' : '取消置顶成功');
    }

    /**
     * V65：全站精华 / 运营推荐位 —— 列表（后台管理页取数）
     * GET /api/admin/featured?slot=home
     */
    public function featuredList(Request $request): Response
    {
        $this->requireAdmin($request);
        $slot = $request->getString('slot') ?: 'home';
        if (!in_array($slot, ['home', 'circle'], true)) {
            $slot = 'home';
        }
        $rows = AdminRepository::fetchFeaturedList($slot);
        return Response::success($rows);
    }

    /**
     * V65：全站精华 / 运营推荐位 —— 推荐 / 取消推荐（toggle）
     * POST /api/admin/featured/toggle {post_id, slot, remark, sort_order}
     *      已推荐过则取消；start_at / end_at 由这里补成「立即 ~ 永久」。
     */
    public function featuredToggle(Request $request): Response
    {
        $this->requireAdmin($request);
        $postId = $request->getInt('post_id');
        $slot = $request->getString('slot') ?: 'home';
        if (!in_array($slot, ['home', 'circle'], true)) {
            $slot = 'home';
        }
        if ($postId <= 0) {
            return Response::error('参数错误', 400);
        }

        // 取消推荐必须在最前：存量违规记录（private/软删圈）也要能清掉
        $exist = AdminRepository::findFeaturedByPostAndSlot($postId, $slot);
        if ($exist) {
            AdminRepository::deleteFeaturedById((int)$exist['id']);
            // P0-11：timeline 命名空间升版（原 clearCache('timeline_v2_') 是废弃前缀，清了等于没清）
            Cache::bumpNsVersion('timeline');
            return Response::success(['featured' => false], '已取消推荐');
        }

        $post = AdminRepository::findFeatureablePostRow($postId);
        if (!$post) {
            return Response::error('该动态不存在或不可公开推荐', 400);
        }

        $now = Helper::now();
        try {
            AdminRepository::insertFeaturedRow(
                $postId,
                (int)(Auth::user($request)['id'] ?? 0),
                $slot,
                (int)$request->getInt('sort_order', 0),
                $request->getString('remark') ?: null,
                $now
            );
        } catch (\Throwable $e) {
            error_log('[Hublog featuredToggle] ' . $e->getMessage()); // @allow-error-log
            return Response::error('推荐失败，请重试', 500);
        }

        // P0-11：timeline 命名空间升版（原 clearCache('timeline_v2_') 是废弃前缀，清了等于没清）
        Cache::bumpNsVersion('timeline');
        return Response::success(['featured' => true], '已加入全站精选');
    }

    public function comments(Request $request): Response
    {
        $this->requireAdmin($request);
        // 原为 $this->paginate($request, $listSql, [], $countSql, [])：
        // 无 formatter / decorator，LIMIT ?, ? 参数顺序为 offset, limit。
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20);
        $items = AdminRepository::fetchAdminComments($offset, $limit);
        $total = AdminRepository::countAllComments();
        return Response::success(Helper::paginatedResponse($items, $page, $limit, $total));
    }

    public function deleteComment(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }

        $comment = AdminRepository::findCommentPostId($id);
        AdminRepository::deleteCommentWithChildren($id);
        if ($comment) {
            Cache::delete("post_comments_{$comment['post_id']}");
            Cache::delete("post_comments_raw_{$comment['post_id']}");
        }

        return Response::success(null, '删除成功');
    }


}
