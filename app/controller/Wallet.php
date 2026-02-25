<?php
namespace app\controller;

use think\facade\View;
use think\facade\Request;
use think\facade\Session;
use think\facade\Db;
use think\facade\Cache;
use app\model\UserCurrency;
use app\model\CurrencyType;
use app\model\CurrencyLog;

/**
 * 钱包控制器
 * 处理用户钱包、积分、充值、提现等操作
 * 底层基于货币系统实现
 */
class Wallet extends BaseFrontendController
{
    /**
     * 钱包首页
     */
    public function index()
    {
        $userId = Session::get('user_id');
        
        if (!$userId) {
            return redirect('/login');
        }

        // 获取所有货币类型的余额
        $currencies = CurrencyType::getActiveTypes();
        $walletBalances = [];
        
        foreach ($currencies as $currency) {
            $userCurrency = UserCurrency::getUserCurrency($userId, $currency->id);
            $walletBalances[] = [
                'currency_id' => $currency->id,
                'name' => $currency->name,
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'balance' => $userCurrency ? $userCurrency->amount : '0.00'
            ];
        }
        
        // 获取主要货币作为默认显示
        $primaryCurrency = CurrencyType::getPrimaryCurrency();
        $primaryBalance = 0;
        if ($primaryCurrency) {
            $userCurrency = UserCurrency::getUserCurrency($userId, $primaryCurrency->id);
            $primaryBalance = $userCurrency ? $userCurrency->amount : '0.00';
        }
        
        // 获取最近交易记录（从货币日志中获取）
        $recentLogs = CurrencyLog::getUserLogs($userId, null, null, 1, 10);
        
        View::assign([
            'walletBalances' => $walletBalances,
            'primaryBalance' => $primaryBalance,
            'primaryCurrency' => $primaryCurrency,
            'transactions' => $recentLogs,
            'current_url' => '/wallet'
        ]);
        
        return View::fetch('wallet/index');
    }

    /**
     * 充值页面
     */
    public function recharge()
    {
        $userId = Session::get('user_id');
        
        if (!$userId) {
            return redirect('/login');
        }

        // 获取可用的货币类型（用于选择充值哪种货币）
        $currencies = CurrencyType::getActiveTypes();
        
        // 获取充值配置
        $rechargePackages = $this->getRechargePackages();
        
        View::assign([
            'currencies' => $currencies,
            'rechargePackages' => $rechargePackages,
            'current_url' => '/wallet/recharge'
        ]);
        
        return View::fetch('wallet/recharge');
    }

    /**
     * 提现页面
     */
    public function withdraw()
    {
        $userId = Session::get('user_id');
        
        if (!$userId) {
            return redirect('/login');
        }

        // 获取主要货币
        $primaryCurrency = CurrencyType::getPrimaryCurrency();
        if (!$primaryCurrency) {
            return redirect('/wallet')->with('error', '系统未配置主要货币');
        }
        
        // 获取用户货币余额
        $userCurrency = UserCurrency::getUserCurrency($userId, $primaryCurrency->id);
        $balance = $userCurrency ? $userCurrency->amount : '0.00';
        
        // 获取提现方式
        $withdrawMethods = [
            ['id' => 'alipay', 'name' => '支付宝', 'icon' => 'fa-alipay'],
            ['id' => 'wechat', 'name' => '微信', 'icon' => 'fa-weixin'],
            ['id' => 'bank', 'name' => '银行卡', 'icon' => 'fa-credit-card']
        ];
        
        View::assign([
            'currency' => $primaryCurrency,
            'balance' => $balance,
            'withdrawMethods' => $withdrawMethods,
            'current_url' => '/wallet/withdraw'
        ]);
        
        return View::fetch('wallet/withdraw');
    }

    /**
     * 交易记录页面
     */
    public function transactions()
    {
        $userId = Session::get('user_id');
        
        if (!$userId) {
            return redirect('/login');
        }

        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $currencyId = Request::param('currency_id', 0);
        $type = Request::param('type', '');
        
        // 从货币日志获取交易记录
        $transactions = CurrencyLog::getUserLogs($userId, $currencyId ?: null, $type ?: null, $page, $limit);
        
        // 获取货币类型列表用于筛选
        $currencies = CurrencyType::getActiveTypes();
        
        View::assign([
            'transactions' => $transactions,
            'currencies' => $currencies,
            'currencyId' => $currencyId,
            'type' => $type,
            'page' => $page,
            'limit' => $limit,
            'current_url' => '/wallet/transactions'
        ]);
        
        return View::fetch('wallet/transactions');
    }

    /**
     * API: 获取用户钱包信息
     */
    public function getWalletInfo()
    {
        $userId = Session::get('user_id');

        if (!$userId) {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => null]);
        }

        try {
            // 获取所有货币余额
            $currencies = CurrencyType::getActiveTypes();

            if (empty($currencies)) {
                // 如果没有货币类型数据，返回空数组
                return json(['code' => 200, 'msg' => '暂无货币类型', 'data' => []]);
            }

            $walletData = [];

            foreach ($currencies as $currency) {
                $userCurrency = UserCurrency::getUserCurrency($userId, $currency->id);
                $walletData[] = [
                    'currency_id' => $currency->id,
                    'name' => $currency->name,
                    'code' => $currency->code ?? '',
                    'symbol' => $currency->symbol,
                    'balance' => $userCurrency ? (string)$userCurrency->amount : '0.00'
                ];
            }

            return json(['code' => 200, 'msg' => '获取成功', 'data' => $walletData]);
        } catch (\Exception $e) {
            // 捕获异常，返回错误信息
            return json([
                'code' => 500,
                'msg' => '获取钱包信息失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * API: 创建充值订单
     */
    public function createRechargeOrder()
    {
        $userId = Session::get('user_id');
        
        if (!$userId) {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => null]);
        }

        $amount = Request::post('amount');
        $currencyId = Request::post('currency_id');
        
        if (empty($amount) || $amount <= 0) {
            return json(['code' => 400, 'msg' => '充值金额无效', 'data' => null]);
        }

        // 如果没有指定货币ID，使用主要货币
        if (!$currencyId) {
            $primaryCurrency = CurrencyType::getPrimaryCurrency();
            if (!$primaryCurrency) {
                return json(['code' => 400, 'msg' => '系统未配置主要货币', 'data' => null]);
            }
            $currencyId = $primaryCurrency->id;
        }

        // 验证货币是否存在且启用
        $currency = CurrencyType::getTypeById($currencyId);
        if (!$currency || $currency->status != 1) {
            return json(['code' => 400, 'msg' => '货币类型无效', 'data' => null]);
        }

        try {
            // 创建充值订单
            $orderNo = 'R' . date('YmdHis') . bin2hex(random_bytes(2));
            
            $orderData = [
                'user_id' => $userId,
                'order_no' => $orderNo,
                'type' => 'recharge',
                'currency_id' => $currencyId,
                'amount' => $amount,
                'status' => 'pending',
                'create_time' => time(),
                'update_time' => time()
            ];
            
            Db::name('wallet_order')->insert($orderData);
            
            // 返回订单信息
            return json([
                'code' => 200,
                'msg' => '订单创建成功',
                'data' => [
                    'order_no' => $orderNo,
                    'amount' => $amount,
                    'currency_id' => $currencyId,
                    'currency_name' => $currency->name,
                    'symbol' => $currency->symbol
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '订单创建失败: ' . $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * API: 创建提现申请
     */
    public function createWithdraw()
    {
        $userId = Session::get('user_id');
        
        if (!$userId) {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => null]);
        }

        $amount = Request::post('amount');
        $currencyId = Request::post('currency_id');
        $method = Request::post('method'); // alipay, wechat, bank
        $account = Request::post('account');
        $realName = Request::post('real_name');
        
        if (empty($amount) || $amount <= 0) {
            return json(['code' => 400, 'msg' => '提现金额无效', 'data' => null]);
        }

        if (empty($method) || empty($account)) {
            return json(['code' => 400, 'msg' => '提现信息不完整', 'data' => null]);
        }

        // 如果没有指定货币ID，使用主要货币
        if (!$currencyId) {
            $primaryCurrency = CurrencyType::getPrimaryCurrency();
            if (!$primaryCurrency) {
                return json(['code' => 400, 'msg' => '系统未配置主要货币', 'data' => null]);
            }
            $currencyId = $primaryCurrency->id;
        }

        try {
            // 使用货币系统检查并扣除余额
            $result = UserCurrency::decreaseUserCurrency($userId, $currencyId, $amount, 'withdraw', '提现申请');
            
            if (!$result) {
                return json(['code' => 400, 'msg' => '余额不足或操作失败', 'data' => null]);
            }
            
            // 创建提现记录
            $withdrawNo = 'W' . date('YmdHis') . bin2hex(random_bytes(2));
            $withdrawData = [
                'user_id' => $userId,
                'withdraw_no' => $withdrawNo,
                'currency_id' => $currencyId,
                'amount' => $amount,
                'method' => $method,
                'account' => $account,
                'real_name' => $realName,
                'status' => 'pending',
                'create_time' => time(),
                'update_time' => time()
            ];
            
            Db::name('wallet_withdraw')->insert($withdrawData);
            
            return json([
                'code' => 200,
                'msg' => '提现申请提交成功',
                'data' => [
                    'withdraw_no' => $withdrawNo,
                    'amount' => $amount
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '提现申请失败: ' . $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * API: 充值回调（模拟）
     */
    public function rechargeCallback()
    {
        $orderNo = Request::post('order_no');
        
        if (empty($orderNo)) {
            return json(['code' => 400, 'msg' => '订单号无效', 'data' => null]);
        }

        try {
            $order = Db::name('wallet_order')->where('order_no', $orderNo)->find();
            
            if (!$order) {
                return json(['code' => 404, 'msg' => '订单不存在', 'data' => null]);
            }

            if ($order['status'] == 'success') {
                return json(['code' => 200, 'msg' => '订单已处理', 'data' => null]);
            }

            // 更新订单状态
            Db::name('wallet_order')->where('order_no', $orderNo)->update([
                'status' => 'success',
                'update_time' => time()
            ]);

            // 使用货币系统增加用户余额
            $currencyId = $order['currency_id'] ?? null;
            if (!$currencyId) {
                $primaryCurrency = CurrencyType::getPrimaryCurrency();
                if (!$primaryCurrency) {
                    return json(['code' => 500, 'msg' => '系统未配置主要货币', 'data' => null]);
                }
                $currencyId = $primaryCurrency->id;
            }
            
            UserCurrency::increaseUserCurrency($order['user_id'], $currencyId, $order['amount'], 'recharge', '充值', $orderNo, 'wallet_order');
            
            return json(['code' => 200, 'msg' => '充值成功', 'data' => null]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '处理失败: ' . $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * 获取充值套餐
     */
    private function getRechargePackages()
    {
        // 这里可以从数据库或配置中读取
        return [
            ['id' => 1, 'amount' => 10, 'points' => 1000, 'bonus' => 0],
            ['id' => 2, 'amount' => 30, 'points' => 3000, 'bonus' => 500],
            ['id' => 3, 'amount' => 50, 'points' => 5000, 'bonus' => 1000],
            ['id' => 4, 'amount' => 100, 'points' => 10000, 'bonus' => 3000],
            ['id' => 5, 'amount' => 200, 'points' => 20000, 'bonus' => 8000],
        ];
    }
}
