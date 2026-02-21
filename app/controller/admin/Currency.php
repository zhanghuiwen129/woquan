<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;
use app\model\CurrencyType;
use app\model\UserCurrency;
use app\model\CurrencyLog;

/**
 * 货币/积分管理控制器
 */
class Currency extends AdminController
{

    
    // 货币类型列表页面
    public function index()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        
        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['name', 'like', "%{$escapedKeyword}%"];
        }
        
        $currencyTypes = Db::name('currency_types')
            ->where($where)
            ->order('sort ASC, is_primary DESC')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'currencyTypes' => $currencyTypes,
            'keyword' => $keyword,
        ]);
        
        return View::fetch('admin/currency/index');
    }
    
    // 添加货币类型页面
    public function addType()
    {
        return View::fetch('admin/currency/add_type');
    }
    
    // 保存货币类型
    public function saveType()
    {
        $data = Request::only([
            'name', 'symbol', 'description', 'is_primary', 'sort', 'status'
        ]);
        
        // 验证参数
        if (empty($data['name'])) {
            return json(['code' => 400, 'msg' => '请输入货币名称']);
        }
        
        if (empty($data['symbol'])) {
            return json(['code' => 400, 'msg' => '请输入货币符号']);
        }
        
        Db::startTrans();
        try {
            $currencyType = new CurrencyType();
            $currencyType->name = $data['name'];
            $currencyType->symbol = $data['symbol'];
            $currencyType->description = $data['description'];
            $currencyType->is_primary = $data['is_primary'] ?? 0;
            $currencyType->sort = $data['sort'] ?? 0;
            $currencyType->status = $data['status'] ?? 1;
            $currencyType->save();
            
            // 如果设置为主要货币，更新其他货币
            if ($currencyType->is_primary == 1) {
                $currencyType->setAsPrimary();
            }
            
            Db::commit();
            return json(['code' => 200, 'msg' => '添加成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '添加失败：' . $e->getMessage()]);
        }
    }
    
    // 编辑货币类型页面
    public function editType()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return redirect('/admin/currency');
        }
        
        $currencyType = CurrencyType::getTypeById($id);
        if (!$currencyType) {
            return redirect('/admin/currency');
        }
        
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'currencyType' => $currencyType,
        ]);
        return View::fetch('admin/currency/edit_type');
    }
    
    // 更新货币类型
    public function updateType()
    {
        $id = Request::param('id/d');
        $data = Request::only([
            'name', 'symbol', 'description', 'is_primary', 'sort', 'status'
        ]);
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        // 验证参数
        if (empty($data['name'])) {
            return json(['code' => 400, 'msg' => '请输入货币名称']);
        }
        
        if (empty($data['symbol'])) {
            return json(['code' => 400, 'msg' => '请输入货币符号']);
        }
        
        Db::startTrans();
        try {
            $currencyType = CurrencyType::getTypeById($id);
            if (!$currencyType) {
                throw new \Exception('货币类型不存在');
            }
            
            $currencyType->name = $data['name'];
            $currencyType->symbol = $data['symbol'];
            $currencyType->description = $data['description'];
            $currencyType->is_primary = $data['is_primary'] ?? 0;
            $currencyType->sort = $data['sort'] ?? 0;
            $currencyType->status = $data['status'] ?? 1;
            $currencyType->save();
            
            // 如果设置为主要货币，更新其他货币
            if ($currencyType->is_primary == 1) {
                $currencyType->setAsPrimary();
            }
            
            Db::commit();
            return json(['code' => 200, 'msg' => '更新成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '更新失败：' . $e->getMessage()]);
        }
    }
    
    // 删除货币类型
    public function deleteType()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        // 检查是否有用户使用该货币
        $count = Db::name('user_currency')->where('currency_id', $id)->count();
        if ($count > 0) {
            return json(['code' => 400, 'msg' => '该货币已有用户使用，无法删除']);
        }
        
        try {
            $result = Db::name('currency_types')->where('id', $id)->delete();
            
            if ($result) {
                return json(['code' => 200, 'msg' => '删除成功']);
            }
            
            return json(['code' => 500, 'msg' => '删除失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }

    // 切换货币类型状态
    public function toggleStatus()
    {
        $id = Request::param('id/d');
        $status = Request::param('status/d', 1);
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = Db::name('currency_types')->where('id', $id)->update(['status' => $status]);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '操作成功']);
            }
            
            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
    
    // 用户货币记录列表
    public function userCurrencyList()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $currencyId = Request::param('currency_id/d', 0);
        
        $where = [];
        if ($keyword) {
            $where[] = ['u.username|u.nickname', 'like', "%{$keyword}%"];
        }
        if ($currencyId > 0) {
            $where[] = ['uc.currency_id', '=', $currencyId];
        }
        
        $userCurrencies = Db::name('user_currency uc')
            ->field('uc.*, u.username, u.nickname, ct.name as currency_name, ct.symbol')
            ->join('user u', 'uc.user_id = u.id')
            ->join('currency_types ct', 'uc.currency_id = ct.id')
            ->where($where)
            ->order('uc.amount DESC')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        // 获取货币类型列表
        $currencyTypes = CurrencyType::getActiveTypes();
        
        View::assign([
            'userCurrencies' => $userCurrencies,
            'keyword' => $keyword,
            'currencyId' => $currencyId,
            'currencyTypes' => $currencyTypes,
        ]);
        
        return View::fetch('admin/currency/user_currency_list');
    }
    
    // 货币变动日志列表
    public function logList()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $currencyId = Request::param('currency_id/d', 0);
        $type = Request::param('type', '');
        $startTime = Request::param('start_time', '');
        $endTime = Request::param('end_time', '');
        
        // 构建查询条件
        $where = [];
        if ($keyword) {
            $where[] = ['u.username|u.nickname', 'like', "%{$keyword}%"];
        }
        if ($currencyId > 0) {
            $where[] = ['cl.currency_id', '=', $currencyId];
        }
        if ($type) {
            $where[] = ['cl.type', '=', $type];
        }
        if ($startTime) {
            $where[] = ['cl.create_time', '>=', strtotime($startTime)];
        }
        if ($endTime) {
            $where[] = ['cl.create_time', '<=', strtotime($endTime . ' 23:59:59')];
        }
        
        $logs = Db::name('currency_logs cl')
            ->field('cl.*, u.username, u.nickname, ct.name as currency_name, ct.symbol')
            ->join('user u', 'cl.user_id = u.id')
            ->join('currency_types ct', 'cl.currency_id = ct.id')
            ->where($where)
            ->order('cl.create_time DESC')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        // 获取货币类型列表
        $currencyTypes = CurrencyType::getActiveTypes();
        
        // 获取所有变动类型
        $logTypes = Db::name('currency_logs')->distinct(true)->column('type');
        
        View::assign([
            'logs' => $logs,
            'keyword' => $keyword,
            'currencyId' => $currencyId,
            'type' => $type,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'currencyTypes' => $currencyTypes,
            'logTypes' => $logTypes,
        ]);
        
        return View::fetch('admin/currency/log_list');
    }
    
    // 手动调整用户货币
    public function adjustUserCurrency()
    {
        $userId = Request::param('user_id/d');
        $currencyId = Request::param('currency_id/d');
        $amount = Request::param('amount');
        $type = Request::param('type'); // increase 增加, decrease 减少
        $remark = Request::param('remark', '管理员手动调整');
        
        if (!$userId || !$currencyId || !$amount || !in_array($type, ['increase', 'decrease'])) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            // 验证用户和货币类型是否存在
            $user = Db::name('user')->where('id', $userId)->find();
            $currencyType = CurrencyType::getTypeById($currencyId);
            
            if (!$user || !$currencyType) {
                return json(['code' => 404, 'msg' => '用户或货币类型不存在']);
            }
            
            $amount = abs($amount);
            if ($type == 'decrease') {
                $amount = -$amount;
            }
            
            // 调整用户货币
            if ($amount > 0) {
                $success = UserCurrency::increaseUserCurrency($userId, $currencyId, $amount, 'admin_adjust', $remark);
            } else {
                $success = UserCurrency::decreaseUserCurrency($userId, $currencyId, abs($amount), 'admin_adjust', $remark);
            }
            
            if ($success) {
                return json(['code' => 200, 'msg' => '操作成功']);
            } else {
                return json(['code' => 400, 'msg' => '操作失败，可能是余额不足']);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
    
    // 获取货币统计信息
    public function statistics()
    {
        $today = strtotime(date('Y-m-d'));
        $yesterday = strtotime('-1 day', $today);
        
        // 货币类型数量
        $typeCount = Db::name('currency_types')->where('status', 1)->count();
        
        // 总用户数（使用货币的用户）
        $userCount = Db::name('user_currency')->distinct(true)->count('user_id');
        
        // 今日变动笔数
        $todayLogCount = Db::name('currency_logs')->where('create_time', '>=', $today)->count();
        
        // 各种货币的总金额
        $currencyStats = Db::name('user_currency uc')
            ->field('ct.id, ct.name, ct.symbol, SUM(uc.amount) as total_amount, SUM(uc.freeze_amount) as total_freeze')
            ->join('currency_types ct', 'uc.currency_id = ct.id')
            ->group('ct.id')
            ->select();
        
        return json([
            'code' => 200,
            'data' => [
                'type_count' => $typeCount,
                'user_count' => $userCount,
                'today_log_count' => $todayLogCount,
                'currency_stats' => $currencyStats
            ]
        ]);
    }

    // 积分管理
    public function points()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        
        $where = [];
        if ($keyword) {
            $where[] = ['u.username|u.nickname', 'like', "%{$keyword}%"];
        }
        
        $userPoints = Db::name('user_currency')
            ->alias('uc')
            ->leftJoin('user u', 'uc.user_id = u.id')
            ->leftJoin('currency_types ct', 'uc.currency_id = ct.id')
            ->where('ct.name', 'like', '%积分%')
            ->where($where)
            ->order('uc.amount desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'userPoints' => $userPoints,
            'keyword' => $keyword,
        ]);
        
        return View::fetch('admin/currency/points');
    }

    // 金币管理
    public function coins()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        
        $where = [];
        if ($keyword) {
            $where[] = ['u.username|u.nickname', 'like', "%{$keyword}%"];
        }
        
        $userCoins = Db::name('user_currency')
            ->alias('uc')
            ->leftJoin('user u', 'uc.user_id = u.id')
            ->leftJoin('currency_types ct', 'uc.currency_id = ct.id')
            ->where('ct.name', 'like', '%金币%')
            ->where($where)
            ->order('uc.amount desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'userCoins' => $userCoins,
            'keyword' => $keyword,
        ]);
        
        return View::fetch('admin/currency/coins');
    }
}
