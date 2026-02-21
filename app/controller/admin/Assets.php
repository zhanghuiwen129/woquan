<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Assets extends AdminController
{

    public function recharge()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status/d', -1);
        $dateRange = Request::param('dateRange', '');

        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['u.username|u.nickname', 'like', "%{$escapedKeyword}%"];
        }
        if ($status >= 0) {
            $where[] = ['r.status', '=', $status];
        }
        if ($dateRange) {
            $now = time();
            if ($dateRange == 'today') {
                $where[] = ['r.create_time', '>=', strtotime(date('Y-m-d'))];
            } elseif ($dateRange == 'week') {
                $where[] = ['r.create_time', '>=', strtotime('-7 days', $now)];
            } elseif ($dateRange == 'month') {
                $where[] = ['r.create_time', '>=', strtotime('-30 days', $now)];
            }
        }

        $records = Db::name('recharge_records')
            ->alias('r')
            ->field('r.*, u.username, u.nickname')
            ->leftJoin('user u', 'r.user_id = u.id')
            ->where($where)
            ->order('r.create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        // 计算统计数据
        $stats = [
            'total_amount' => 0,
            'pending_count' => 0,
            'success_count' => 0,
            'cancel_count' => 0
        ];

        $allRecords = Db::name('recharge_records')
            ->alias('r')
            ->field('r.status, r.amount')
            ->leftJoin('user u', 'r.user_id = u.id')
            ->where($where)
            ->select();

        foreach ($allRecords as $record) {
            if ($record['status'] == 1) {
                $stats['total_amount'] += floatval($record['amount']);
                $stats['success_count']++;
            } elseif ($record['status'] == 0) {
                $stats['pending_count']++;
            } elseif ($record['status'] == 2) {
                $stats['cancel_count']++;
            }
        }

        if (Request::isAjax()) {
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $records->items(),
                    'total' => $records->total(),
                    'stats' => $stats
                ]
            ]);
        }

        View::assign([
            'active' => 'assets_recharge',
            'page_title' => '充值记录',
            'records' => $records,
            'keyword' => $keyword,
            'status' => $status,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/assets/recharge');
    }

    public function withdraw()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status/d', -1);
        $method = Request::param('method', '');
        $dateRange = Request::param('dateRange', '');

        $where = [];
        if ($keyword) {
            $where[] = ['u.username|u.nickname', 'like', "%{$keyword}%"];
        }
        if ($status >= 0) {
            $where[] = ['w.status', '=', $status];
        }
        if ($dateRange) {
            $now = time();
            if ($dateRange == 'today') {
                $where[] = ['w.create_time', '>=', strtotime(date('Y-m-d'))];
            } elseif ($dateRange == 'week') {
                $where[] = ['w.create_time', '>=', strtotime('-7 days', $now)];
            } elseif ($dateRange == 'month') {
                $where[] = ['w.create_time', '>=', strtotime('-30 days', $now)];
            }
        }

        $records = Db::name('withdraw_records')
            ->alias('w')
            ->field('w.*, u.username, u.nickname')
            ->leftJoin('user u', 'w.user_id = u.id')
            ->where($where)
            ->order('w.create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        // 计算统计数据
        $stats = [
            'total_amount' => 0,
            'pending_count' => 0,
            'success_count' => 0,
            'reject_count' => 0
        ];

        $allRecords = Db::name('withdraw_records')
            ->alias('w')
            ->field('w.status, w.amount, w.fee')
            ->leftJoin('user u', 'w.user_id = u.id')
            ->where($where)
            ->select();

        foreach ($allRecords as $record) {
            if ($record['status'] == 1) {
                $stats['total_amount'] += floatval($record['amount']);
                $stats['success_count']++;
            } elseif ($record['status'] == 0) {
                $stats['pending_count']++;
            } elseif ($record['status'] == 2) {
                $stats['reject_count']++;
            }
        }

        if (Request::isAjax()) {
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $records->items(),
                    'total' => $records->total(),
                    'stats' => $stats
                ]
            ]);
        }

        View::assign([
            'active' => 'assets_withdraw',
            'page_title' => '提现记录',
            'records' => $records,
            'keyword' => $keyword,
            'status' => $status,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/assets/withdraw');
    }

    /**
     * 确认充值订单
     */
    public function confirmRecharge()
    {
        $id = Request::post('id');

        if (!$id) {
            return json(['code' => 400, 'msg' => '订单ID无效']);
        }

        $order = Db::name('recharge_records')->where('id', $id)->find();

        if (!$order) {
            return json(['code' => 404, 'msg' => '订单不存在']);
        }

        if ($order['status'] == 1) {
            return json(['code' => 400, 'msg' => '订单已支付']);
        }

        Db::startTrans();
        try {
            // 更新订单状态
            Db::name('recharge_records')->where('id', $id)->update([
                'status' => 1,
                'pay_time' => time()
            ]);

            // 增加用户余额
            $currencyId = 1;
            $primaryCurrency = \app\model\CurrencyType::getPrimaryCurrency();
            if ($primaryCurrency) {
                $currencyId = $primaryCurrency->id;
            }

            \app\model\UserCurrency::increaseUserCurrency(
                $order['user_id'],
                $currencyId,
                $order['amount'],
                'recharge',
                '充值',
                $order['transaction_id'],
                'recharge_records'
            );

            Db::commit();
            return json(['code' => 200, 'msg' => '订单已确认']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 取消充值订单
     */
    public function cancelRecharge()
    {
        $id = Request::post('id');

        if (!$id) {
            return json(['code' => 400, 'msg' => '订单ID无效']);
        }

        $order = Db::name('recharge_records')->where('id', $id)->find();

        if (!$order) {
            return json(['code' => 404, 'msg' => '订单不存在']);
        }

        if ($order['status'] == 1) {
            return json(['code' => 400, 'msg' => '订单已支付，无法取消']);
        }

        Db::name('recharge_records')->where('id', $id)->update([
            'status' => 2
        ]);

        return json(['code' => 200, 'msg' => '订单已取消']);
    }

    /**
     * 通过提现申请
     */
    public function approveWithdraw()
    {
        $id = Request::post('id');
        $remark = Request::post('remark', '');

        if (!$id) {
            return json(['code' => 400, 'msg' => '提现ID无效']);
        }

        $withdraw = Db::name('withdraw_records')->where('id', $id)->find();

        if (!$withdraw) {
            return json(['code' => 404, 'msg' => '提现记录不存在']);
        }

        if ($withdraw['status'] == 1) {
            return json(['code' => 400, 'msg' => '提现已通过']);
        }

        if ($withdraw['status'] == 2) {
            return json(['code' => 400, 'msg' => '提现已被拒绝']);
        }

        Db::startTrans();
        try {
            // 更新提现状态
            Db::name('withdraw_records')->where('id', $id)->update([
                'status' => 1,
                'remark' => $remark,
                'handle_time' => time()
            ]);

            Db::commit();
            return json(['code' => 200, 'msg' => '提现已通过']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 拒绝提现申请
     */
    public function rejectWithdraw()
    {
        $id = Request::post('id');
        $remark = Request::post('remark', '');

        if (!$id) {
            return json(['code' => 400, 'msg' => '提现ID无效']);
        }

        if (empty($remark)) {
            return json(['code' => 400, 'msg' => '请填写拒绝理由']);
        }

        $withdraw = Db::name('withdraw_records')->where('id', $id)->find();

        if (!$withdraw) {
            return json(['code' => 404, 'msg' => '提现记录不存在']);
        }

        if ($withdraw['status'] == 1) {
            return json(['code' => 400, 'msg' => '提现已通过，无法拒绝']);
        }

        Db::startTrans();
        try {
            // 更新提现状态
            Db::name('withdraw_records')->where('id', $id)->update([
                'status' => 2,
                'remark' => $remark,
                'handle_time' => time()
            ]);

            // 退还冻结的余额到用户账户
            $currencyId = 1;
            $primaryCurrency = \app\model\CurrencyType::getPrimaryCurrency();
            if ($primaryCurrency) {
                $currencyId = $primaryCurrency->id;
            }

            \app\model\UserCurrency::increaseUserCurrency(
                $withdraw['user_id'],
                $currencyId,
                $withdraw['amount'],
                'withdraw_reject',
                '提现拒绝退款',
                '',
                'withdraw_records'
            );

            Db::commit();
            return json(['code' => 200, 'msg' => '提现已拒绝，金额已退还']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }
}
