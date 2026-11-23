<?php
declare(strict_types=1);
/**
 * Hublog 后台「财务与会员」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\FinanceRepository;
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
use Hublog\Core\OrderNo;

class AdminFinanceController extends AdminController
{
    public function walletLogs(Request $request): Response
    {
        $this->requireAdmin($request);

        $page = max(1, $request->getInt('page', 1));
        $limit = min(100, max(1, $request->getInt('limit', 20)));
        $offset = ($page - 1) * $limit;
        $type = trim((string)$request->getString('type'));
        $keyword = trim((string)$request->getString('keyword'));

        $rows = FinanceRepository::fetchWalletLogs($type, $keyword, $offset, $limit);
        $total = FinanceRepository::countWalletLogs($type, $keyword);

        return Response::success([
            'list' => $rows,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => max(1, (int)ceil($total / $limit)),
            ],
        ], '获取成功');
    }

    /**
     * 后台手动调整余额（充值 / 扣款 / 补偿）
     * POST /api/admin/wallet-adjust  { user_id, amount, remark }
     *
     * 站点没有支付网关，所有充值都从这里入账 —— 这条接口就是钱流的唯一入口。
     */
    public function walletAdjust(Request $request): Response
    {
        $this->requireAdmin($request);

        $userId = $request->getInt('user_id');
        $amount = round((float)$request->getString('amount'), 2);
        $remark = trim((string)$request->getString('remark'));

        if ($userId <= 0) {
            return Response::error('用户 ID 必填', 400);
        }
        if (abs($amount) < 0.01) {
            return Response::error('金额不合法', 400);
        }
        if ($remark === '') {
            $remark = $amount > 0 ? '后台充值' : '后台扣款';
        }

        $user = FinanceRepository::findWalletAdjustUser($userId);
        if (!$user) {
            return Response::error('用户不存在', 404);
        }

        // 快速失败预检查（权威判断由仓储事务内的条件更新给出）
        if ((float)$user['balance'] + $amount < 0) {
            return Response::error('扣款后余额不能为负', 400);
        }

        try {
            // V147：条件原子更新、事务内余额重读、流水写入与 rollBack 已整体下沉至
            // FinanceRepository::adjustWalletBalance()，异常文案与 500 响应保持不变。
            $after = FinanceRepository::adjustWalletBalance($userId, $amount, $remark);
            return Response::success(['balance' => $after], '已调整');
        } catch (\Throwable $e) {
            error_log('[Hublog walletAdjust] ' . $e->getMessage()); // @allow-error-log
            $msg = $e->getMessage();
            return Response::error(
                $msg === '用户不存在' ? '用户不存在'
                : ($msg === '扣款后余额不能为负' ? '扣款后余额不能为负' : '调整失败'),
                500
            );
        }
    }

    /**
     * 会员订购单（后台）
     * GET /api/admin/vip-orders?page=&limit=
     */
    public function vipOrders(Request $request): Response
    {
        $this->requireAdmin($request);

        $page = max(1, $request->getInt('page', 1));
        $limit = min(100, max(1, $request->getInt('limit', 20)));
        $offset = ($page - 1) * $limit;

        $rows = FinanceRepository::fetchVipOrders($offset, $limit);
        $total = FinanceRepository::countVipOrders();

        return Response::success([
            'list' => $rows,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => max(1, (int)ceil($total / $limit)),
            ],
        ], '获取成功');
    }

    /**
     * 后台直接开通会员（人工开通 / 补偿，跳过错单流程）
     * POST /api/admin/grant-vip  { user_id, level, days, amount }
     */
    public function grantVip(Request $request): Response
    {
        $this->requireAdmin($request);

        $userId = $request->getInt('user_id');
        $level = $request->getInt('level');
        $days = max(1, $request->getInt('days', 30));
        if ($userId <= 0) {
            return Response::error('用户 ID 必填', 400);
        }
        if (!in_array($level, [1, 2, 3], true)) {
            return Response::error('会员等级不合法（1/2/3）', 400);
        }

        $user = FinanceRepository::findVipGrantUser($userId);
        if (!$user) {
            return Response::error('用户不存在', 404);
        }

        $base = ($user['vip_expire_at'] && strtotime($user['vip_expire_at']) > time())
            ? strtotime($user['vip_expire_at'])
            : time();
        $expire = $base + $days * 86400;
        $expireAt = date('Y-m-d H:i:s', $expire);

        try {
            // 单号/时间戳与过期时间仍由控制器计算；更新 + 写单 + 事务在仓储内完成
            FinanceRepository::grantVip(
                $userId,
                $level,
                $days,
                OrderNo::generate('ADM'),
                Helper::now(),
                $expireAt
            );

            // V82：会员开通 → 徽章实时校验（VIP会员/年度VIP会员）
            BadgeService::onEvent($userId, 'vip_changed');

            return Response::success(['expire_at' => $expireAt], '已开通会员');
        } catch (\Throwable $e) {
            error_log('[Hublog grantVip] ' . $e->getMessage()); // @allow-error-log
            return Response::error('开通失败', 500);
        }
    }

    // ==================== V68 积分管理 ====================

    /**
     * 积分流水（后台）
     * GET /api/admin/points-logs?type=&keyword=&page=&limit=
     */
}
