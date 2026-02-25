<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Points extends BaseFrontendController
{
    // 积分页面
    public function index()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');

        if (!$userId) {
            return redirect('/login');
        }

        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        // 配置信息已在基类中加载
        View::assign([
            'currentUser' => $currentUser,
            'isLogin' => !empty($userId),
            'current_url' => '/points'
        ]);
        return View::fetch('index/points');
    }
    /**
     * 获取用户积分信息
     */
    public function getUserPoints()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取主货币类型（积分）
            $primaryCurrency = \app\model\CurrencyType::getPrimaryCurrency();
            if (!$primaryCurrency) {
                return $this->error('主货币类型未配置');
            }

            // 获取用户积分（从 user_currency 表）
            $userCurrency = \app\model\UserCurrency::getUserCurrency($userId, $primaryCurrency->id);
            $totalPoints = $userCurrency ? $userCurrency->amount : 0;

            // 获取今日积分
            $today = strtotime(date('Y-m-d'));
            $todayPoints = \app\model\CurrencyLog::where('user_id', $userId)
                ->where('currency_id', $primaryCurrency->id)
                ->where('amount', '>', 0)
                ->where('create_time', '>=', $today)
                ->sum('amount') ?: 0;

            // 获取本周积分
            $weekStart = strtotime(date('Y-m-d', strtotime('this week')));
            $weekPoints = \app\model\CurrencyLog::where('user_id', $userId)
                ->where('currency_id', $primaryCurrency->id)
                ->where('amount', '>', 0)
                ->where('create_time', '>=', $weekStart)
                ->sum('amount') ?: 0;

            // 获取累计获得的积分
            $totalGained = \app\model\CurrencyLog::where('user_id', $userId)
                ->where('currency_id', $primaryCurrency->id)
                ->where('amount', '>', 0)
                ->sum('amount') ?: 0;

            // 计算等级（每100积分升一级）
            $level = floor($totalPoints / 100) + 1;

            return $this->success([
                'total_points' => intval($totalPoints),
                'available_points' => intval($totalPoints),
                'today_points' => intval($todayPoints),
                'week_points' => intval($weekPoints),
                'total_gained' => intval($totalGained),
                'level' => $level
            ], 'success');
        } catch (\Exception $e) {
            return $this->error('获取用户积分信息失败，请稍后重试');
        }
    }

    /**
     * 获取积分记录
     */
    public function getPointsRecords()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取请求参数
            $page = input('page', 1, 'intval');
            $limit = input('limit', 10, 'intval');
            $type = input('type', '', 'trim'); // income: 收入, expense: 支出
            $offset = ($page - 1) * $limit;

            // 获取主货币类型（积分）
            $primaryCurrency = \app\model\CurrencyType::getPrimaryCurrency();
            if (!$primaryCurrency) {
                return $this->error('主货币类型未配置');
            }

            // 构建查询条件
            $where = [
                'user_id' => $userId,
                'currency_id' => $primaryCurrency->id
            ];

            if ($type === 'income') {
                $where[] = ['amount', '>', 0];
            } elseif ($type === 'expense') {
                $where[] = ['amount', '<', 0];
            }

            // 获取积分记录总数
            $total = \app\model\CurrencyLog::where($where)->count();

            // 获取积分记录列表
            $records = \app\model\CurrencyLog::where($where)
                ->field('id, amount, type, remark as description, create_time')
                ->order('create_time', 'desc')
                ->limit($offset, $limit)
                ->select();

            // 格式化数据
            $list = [];
            foreach ($records as $record) {
                $list[] = [
                    'id' => $record->id,
                    'points' => intval($record->amount),
                    'description' => $record->description ?: '积分变动',
                    'create_time' => $record->create_time
                ];
            }

            return $this->success($list, 'success');
        } catch (\Exception $e) {
            return $this->error('获取积分记录失败，请稍后重试');
        }
    }

    /**
     * 获取积分规则
     */
    public function getPointsRules()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取积分规则列表
            $rules = Db::name('points_rule')
                ->field('id, type, name, description, points, limit_daily, limit_total, status')
                ->where('status', 1)
                ->order('sort', 'asc')
                ->select();

            return $this->success(['list' => $rules], 'success');
        } catch (\Exception $e) {
            return $this->error('获取积分规则失败: ' . $e->getMessage());
        }
    }

    /**
     * 积分兑换
     */
    public function exchangePoints()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取请求参数
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['exchange_id']) || !isset($data['quantity'])) {
                return $this->badRequest('参数错误');
            }

            // 获取兑换商品信息
            $exchangeItem = Db::name('points_exchange')
                ->where('id', $data['exchange_id'])
                ->where('status', 1)
                ->find();

            if (!$exchangeItem) {
                return $this->notFound('兑换商品不存在或已下架');
            }

            // 验证商品库存
            if ($exchangeItem['stock'] < $data['quantity']) {
                return $this->badRequest('商品库存不足');
            }

            // 计算所需积分
            $requiredPoints = $exchangeItem['points'] * $data['quantity'];

            // 获取用户积分信息
            $userPoints = Db::name('user_points')
                ->where('user_id', $userId)
                ->lock(true)
                ->find();

            if (!$userPoints) {
                return $this->badRequest('用户积分信息不存在');
            }

            // 验证积分是否足够
            if ($userPoints['available_points'] < $requiredPoints) {
                return $this->badRequest('积分不足');
            }

            // 开始事务
            Db::startTrans();

            try {
                // 更新用户积分
                Db::name('user_points')
                    ->where('user_id', $userId)
                    ->update([
                        'total_points' => Db::raw('total_points - ' . $requiredPoints),
                        'available_points' => Db::raw('available_points - ' . $requiredPoints),
                        'update_time' => time()
                    ]);

                // 更新商品库存
                Db::name('points_exchange')
                    ->where('id', $data['exchange_id'])
                    ->update([
                        'stock' => Db::raw('stock - ' . $data['quantity']),
                        'exchange_count' => Db::raw('exchange_count + ' . $data['quantity']),
                        'update_time' => time()
                    ]);

                // 记录积分变动
                Db::name('points_record')->insert([
                    'user_id' => $userId,
                    'type' => 'expense',
                    'points' => $requiredPoints,
                    'source' => 'exchange',
                    'source_id' => $data['exchange_id'],
                    'description' => '兑换商品: ' . $exchangeItem['name'] . ' x' . $data['quantity'],
                    'create_time' => time()
                ]);

                // 生成兑换订单
                $orderId = 'EX' . date('YmdHis') . str_pad((string)mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
                Db::name('points_exchange_order')->insert([
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'exchange_id' => $data['exchange_id'],
                    'exchange_name' => $exchangeItem['name'],
                    'points' => $requiredPoints,
                    'quantity' => $data['quantity'],
                    'status' => 1, // 1: 待处理
                    'create_time' => time(),
                    'update_time' => time()
                ]);

                // 提交事务
                Db::commit();

                return json([
                    'code' => 200,
                    'msg' => '兑换成功',
                    'data' => ['order_id' => $orderId]
                ]);
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->error('积分兑换失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取积分兑换商品列表
     */
    public function getExchangeItems()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $page = input('page', 1, 'intval');
            $limit = input('limit', 10, 'intval');
            $offset = ($page - 1) * $limit;

            // 获取兑换商品总数
            $total = Db::name('points_exchange')
                ->where('status', 1)
                ->count();

            // 获取兑换商品列表
            $exchangeItems = Db::name('points_exchange')
                ->where('status', 1)
                ->field('id, name, description, points, stock, exchange_count, image, status, create_time')
                ->order('sort', 'asc')
                ->order('create_time', 'desc')
                ->limit($offset, $limit)
                ->select();

            return $this->success([
                'list' => $exchangeItems,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'has_more' => ($offset + $limit) < $total
            ], 'success');
        } catch (\Exception $e) {
            return $this->error('获取积分兑换商品列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取积分兑换订单列表
     */
    public function getExchangeOrders()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取请求参数
            $page = input('page', 1, 'intval');
            $limit = input('limit', 10, 'intval');
            $status = input('status', '', 'intval');
            $offset = ($page - 1) * $limit;

            // 构建查询条件
            $where = ['user_id' => $userId];
            if ($status !== '') {
                $where['status'] = $status;
            }

            // 获取兑换订单总数
            $total = Db::name('points_exchange_order')
                ->where($where)
                ->count();

            // 获取兑换订单列表
            $orders = Db::name('points_exchange_order')
                ->where($where)
                ->field('id, order_id, exchange_name, points, quantity, status, create_time, update_time')
                ->order('create_time', 'desc')
                ->limit($offset, $limit)
                ->select();

            // 格式化时间
            foreach ($orders as &$order) {
                $order['create_time'] = date('Y-m-d H:i:s', $order['create_time']);
                $order['update_time'] = date('Y-m-d H:i:s', $order['update_time']);
            }

            return $this->success([
                'list' => $orders,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'has_more' => ($offset + $limit) < $total
            ], 'success');
        } catch (\Exception $e) {
            return $this->error('获取积分兑换订单列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 清空积分记录
     */
    public function clearHistory()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取主货币类型（积分）
            $primaryCurrency = \app\model\CurrencyType::getPrimaryCurrency();
            if (!$primaryCurrency) {
                return $this->error('主货币类型未配置');
            }

            // 删除用户的积分记录
            \app\model\CurrencyLog::where('user_id', $userId)
                ->where('currency_id', $primaryCurrency->id)
                ->delete();

            return $this->success(null, '积分记录已清空');
        } catch (\Exception $e) {
            return $this->error('清空积分记录失败: ' . $e->getMessage());
        }
    }
}
