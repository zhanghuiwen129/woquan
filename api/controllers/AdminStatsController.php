<?php
declare(strict_types=1);
/**
 * Hublog 后台「统计与榜单」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
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

class AdminStatsController extends AdminController
{
    public function siteStats(Request $request): Response
    {
        // 不使用缓存，统计数据需实时准确（PC 端侧边栏展示）
        // 四条纯 COUNT 与 AdminRepository 既有方法 SQL 逐字一致，直接复用
        $totalPosts = AdminRepository::countActivePosts();
        $totalComments = AdminRepository::countAllComments();
        $totalLikes = AdminRepository::countAllLikes();
        $totalUsers = AdminRepository::countActiveUsers();

        return Response::success([
            'total_posts' => $totalPosts,
            'total_comments' => $totalComments,
            'total_likes' => $totalLikes,
            'total_users' => $totalUsers,
        ]);
    }

    /**
     * 用户排行榜（综合评分：动态数×3 + 评论数×2 + 获赞数×4 + 收藏数×2 + 粉丝数×3）
     * GET /site/user-ranking?limit=10
     */
    public function userRanking(Request $request): Response
    {
        $limit = min($request->getInt('limit', 10), 50);

        // 缓存键带 _v2：多加 bio 之后换 key，避免旧缓存把 bio 字段吞掉（缓存 10 分钟）
        $result = Cache::remember("user_ranking_{$limit}_v3", function () use ($limit) {
            // 综合评分 = 动态数*3 + 评论数*2 + 获赞数*4 + 收藏数*2 + 粉丝数*3
            return AdminRepository::fetchUserRanking($limit);
        }, 600); // 缓存 10 分钟

        return Response::success($result);
    }

    /**
     * V65：排行榜补全 —— 用户 / 动态 / 圈子 / 话题 四类榜 + 日 / 周 / 月 / 总榜维度。
     *
     * 背景：原来只有 userRanking 一张「活跃用户榜」且无时间窗，docs/Hublog_功能缺口盘点.md
     * 把「排行榜补全」列为成本最低、收益最直接的一块。这里用同一套参数把四张榜一次性补齐，
     * 前端 ranking.vue 加 tab 切换即可复用。
     *
     * GET /api/site/ranking?type=user|post|circle|topic&period=day|week|month|all&limit=20
     */
    public function ranking(Request $request): Response
    {
        $type = $request->getString('type') ?: 'user';
        if (!in_array($type, ['user', 'post', 'circle', 'topic'], true)) {
            $type = 'user';
        }
        $period = $request->getString('period') ?: 'all';
        if (!in_array($period, ['day', 'week', 'month', 'all'], true)) {
            $period = 'all';
        }
        $limit = min(max($request->getInt('limit', 20), 1), 50);

        // 缓存键带 _v1：第一批上线，先用独立 key 避免与旧接口缓存串味（10 分钟）
        $result = Cache::remember("site_ranking_{$type}_{$period}_{$limit}_v2", function () use ($type, $period, $limit) {
            switch ($type) {
                case 'post':
                    return self::rankingPosts($period, $limit);
                case 'circle':
                    return self::rankingCircles($period, $limit);
                case 'topic':
                    return self::rankingTopics($period, $limit);
                default:
                    return self::rankingUsers($period, $limit);
            }
        }, 600);

        // V83：用户榜补内联徽章。刻意放在缓存之外——
        // 徽章解锁/冻结是实时事件，若跟着 10 分钟缓存一起被冻住，用户刚拿到徽章却半天不显示。
        if ($type === 'user' && is_array($result)) {
            BadgeService::decorateUsersWithInlineBadges($result);
            // 头像/封面是站点根相对路径，App 端 <image> 加载不了
            MediaHelper::processUsers($result);
        } elseif ($type === 'circle' && is_array($result)) {
            // 圈子榜封面同理
            MediaHelper::processCircles($result);
        }

        return Response::success($result);
    }

    /**
     * 时间窗白名单与 SQL 已下沉到 AdminRepository::rankingWindow()；
     * 周期参数仍由 ranking() 入口白名单校验，仓储内再做一次白名单映射。
     */

    /** 用户榜（活跃榜，V65 加了时间窗；逻辑与老的 userRanking 保持一条线） */
    private static function rankingUsers(string $period, int $limit): array
    {
        return AdminRepository::fetchRankingUsers($period, $limit);
    }

    /** 动态榜（热帖：赞*3 + 评论*2 + 收藏*5，可按时间窗取） */
    private static function rankingPosts(string $period, int $limit): array
    {
        return AdminRepository::fetchRankingPosts($period, $limit);
    }

    /** 圈子榜（活跃圈：窗口内动态数为主，成员数为辅） */
    private static function rankingCircles(string $period, int $limit): array
    {
        return AdminRepository::fetchRankingCircles($period, $limit);
    }

    /** 话题榜（窗口内带该标签的动态数） */
    private static function rankingTopics(string $period, int $limit): array
    {
        return AdminRepository::fetchRankingTopics($period, $limit);
    }

    // ==================== V67 财务 / 会员管理 ====================

    /**
     * 钱包流水（后台）
     * GET /api/admin/wallet-logs?type=&keyword=&page=&limit=
     */
}
