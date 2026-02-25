<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Redirect;
use think\facade\Session;
use app\model\VipLevel;
use app\model\UserVip;
use app\model\VipOrder;

/**
 * VIP会员管理控制器
 */
class Vip extends AdminController
{

    
    // VIP等级列表页面
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
        
        $vipLevels = Db::name('vip_levels')
            ->where($where)
            ->order('sort ASC, id ASC')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'vipLevels' => $vipLevels,
            'keyword' => $keyword,
        ]);
        
        return View::fetch('admin/vip/index');
    }
    
    // 添加VIP等级页面
    public function addLevel()
    {
        return View::fetch('admin/vip/add_level');
    }
    
    // 保存VIP等级
    public function saveLevel()
    {
        $data = Request::only([
            'name', 'max_moments', 'max_images', 
            'price_month', 'price_quarter', 'price_year', 'price_permanent',
            'privileges', 'sort', 'status'
        ]);
        
        // 验证参数
        if (empty($data['name'])) {
            return json(['code' => 400, 'msg' => '请输入等级名称']);
        }
        
        // 处理特权
        if (isset($data['privileges'])) {
            $data['privileges'] = json_encode(explode(',', $data['privileges']), JSON_UNESCAPED_UNICODE);
        } else {
            $data['privileges'] = '[]';
        }
        
        $result = Db::name('vip_levels')->insert($data);
        
        if ($result !== false) {
            return json(['code' => 200, 'msg' => '添加成功']);
        }
        
        return json(['code' => 500, 'msg' => '添加失败']);
    }
    
    // 编辑VIP等级页面
    public function editLevel()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return redirect('/admin/vip');
        }
        
        $vipLevel = Db::name('vip_levels')->where('id', $id)->find();
        if (!$vipLevel) {
            return redirect('/admin/vip');
        }
        
        // 处理特权
        if (!empty($vipLevel['privileges'])) {
            $vipLevel['privileges'] = implode(',', json_decode($vipLevel['privileges'], true));
        }
        
        View::assign('vipLevel', $vipLevel);
        return View::fetch('admin/vip/edit_level');
    }
    
    // 更新VIP等级
    public function updateLevel()
    {
        $id = Request::param('id/d');
        $data = Request::only([
            'name', 'max_moments', 'max_images', 
            'price_month', 'price_quarter', 'price_year', 'price_permanent',
            'privileges', 'sort', 'status'
        ]);
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        // 验证参数
        if (empty($data['name'])) {
            return json(['code' => 400, 'msg' => '请输入等级名称']);
        }
        
        // 处理特权
        if (isset($data['privileges'])) {
            $data['privileges'] = json_encode(explode(',', $data['privileges']), JSON_UNESCAPED_UNICODE);
        } else {
            $data['privileges'] = '[]';
        }
        
        $result = Db::name('vip_levels')->where('id', $id)->update($data);
        
        if ($result !== false) {
            return json(['code' => 200, 'msg' => '更新成功']);
        }
        
        return json(['code' => 500, 'msg' => '更新失败']);
    }
    
    // 删除VIP等级
    public function deleteLevel()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        // 检查是否有用户使用该等级
        $count = Db::name('user_vip')->where('level_id', $id)->count();
        if ($count > 0) {
            return json(['code' => 400, 'msg' => '该等级已有用户使用，无法删除']);
        }
        
        $result = Db::name('vip_levels')->where('id', $id)->delete();
        
        if ($result) {
            return json(['code' => 200, 'msg' => '删除成功']);
        }
        
        return json(['code' => 500, 'msg' => '删除失败']);
    }
    
    // 用户VIP记录列表
    public function userVipList()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        
        $where = [];
        if ($keyword) {
            $where[] = ['u.nickname|u.username', 'like', "%{$keyword}%"];
        }
        
        $userVips = Db::name('user_vip')
            ->alias('uv')
            ->field('uv.*, u.nickname, u.username, vl.name as level_name')
            ->join('user u', 'uv.user_id = u.id')
            ->join('vip_levels vl', 'uv.level_id = vl.id')
            ->where($where)
            ->order('uv.create_time DESC')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'userVips' => $userVips,
            'keyword' => $keyword,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/vip/user_vip_list');
    }
    
    // VIP订单列表
    public function orderList()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $payStatus = Request::param('pay_status/d', -1);
        
        $where = [];
        if ($keyword) {
            $where[] = ['vo.order_no|u.nickname', 'like', "%{$keyword}%"];
        }
        if ($payStatus >= 0) {
            $where[] = ['vo.pay_status', '=', $payStatus];
        }
        
        $orders = Db::name('vip_orders')
            ->alias('vo')
            ->field('vo.*, u.nickname, vl.name as level_name')
            ->join('user u', 'vo.user_id = u.id')
            ->join('vip_levels vl', 'vo.level_id = vl.id')
            ->where($where)
            ->order('vo.create_time DESC')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'orders' => $orders,
            'keyword' => $keyword,
            'payStatus' => $payStatus,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/vip/order_list');
    }
    
    // 手动设置用户VIP
    public function setUserVip()
    {
        $userId = Request::param('user_id/d');
        $levelId = Request::param('level_id/d');
        $durationType = Request::param('duration_type');
        
        if (!$userId || !$levelId || !in_array($durationType, ['month', 'quarter', 'year', 'permanent'])) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        // 检查用户和等级是否存在
        $user = Db::name('user')->where('id', $userId)->find();
        $vipLevel = Db::name('vip_levels')->where('id', $levelId)->find();
        
        if (!$user || !$vipLevel) {
            return json(['code' => 404, 'msg' => '用户或VIP等级不存在']);
        }
        
        $isPermanent = $durationType == 'permanent' ? 1 : 0;
        $startTime = time();
        $endTime = $isPermanent ? 0 : $this->calculateEndTime($startTime, $durationType);
        
        // 更新或创建用户VIP记录
        $userVip = Db::name('user_vip')->where('user_id', $userId)->find();
        if ($userVip) {
            $result = Db::name('user_vip')->where('user_id', $userId)->update([
                'level_id' => $levelId,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'is_permanent' => $isPermanent,
                'auto_renew' => 0,
            ]);
        } else {
            $result = Db::name('user_vip')->insert([
                'user_id' => $userId,
                'level_id' => $levelId,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'is_permanent' => $isPermanent,
                'auto_renew' => 0,
                'create_time' => time(),
            ]);
        }
        
        if ($result !== false) {
            return json(['code' => 200, 'msg' => '设置成功']);
        }
        
        return json(['code' => 500, 'msg' => '设置失败']);
    }
    
    // 计算结束时间
    protected function calculateEndTime($startTime, $durationType)
    {
        switch ($durationType) {
            case 'month':
                return strtotime('+1 month', $startTime);
            case 'quarter':
                return strtotime('+3 months', $startTime);
            case 'year':
                return strtotime('+1 year', $startTime);
            default:
                return $startTime;
        }
    }
    
    // 获取VIP统计信息
    public function statistics()
    {
        $today = strtotime(date('Y-m-d'));
        $yesterday = strtotime('-1 day', $today);
        
        $stats = [
            'total_levels' => Db::name('vip_levels')->count(),
            'total_vip_users' => Db::name('user_vip')->distinct(true)->count('user_id'),
            'expiring_vip' => Db::name('user_vip')->where('is_permanent', 0)->where('end_time', '>', $today)->where('end_time', '<', strtotime('+30 days', $today))->count(),
            'today_orders' => Db::name('vip_orders')->where('create_time', '>=', $today)->count(),
            'total_orders' => Db::name('vip_orders')->count(),
            'completed_orders' => Db::name('vip_orders')->where('pay_status', 1)->count(),
        ];
        
        return json(['code' => 200, 'data' => $stats]);
    }
}
