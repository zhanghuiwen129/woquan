<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Call extends AdminController
{
    
    public function index()
    {
        try {
            $page = Request::param('page', 1);
            $limit = Request::param('limit', 20);
            $keyword = Request::param('keyword', '');
            $callType = Request::param('call_type/d', -1);
            $status = Request::param('status/d', -1);

            // 构建查询条件
            $where = [];
            if (!empty($keyword)) {
                $escapedKeyword = Db::escape($keyword);
                $where[] = ['caller_name|callee_name', 'like', '%' . $escapedKeyword . '%'];
            }
            if ($callType > 0) {
                $where[] = ['call_type', '=', $callType];
            }
            if ($status >= 0) {
                $where[] = ['status', '=', $status];
            }

            // 查询通话记录
            $calls = Db::name('call_records')
                ->where($where)
                ->order('create_time', 'desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);

            View::assign([
                'calls' => $calls,
                'keyword' => $keyword,
                'callType' => $callType,
                'status' => $status,
                'admin_name' => Session::get('admin_username', '管理员'),
                'page_title' => '通话管理'
            ]);

            return View::fetch('admin/call/index');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }
    
    public function delete()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = Db::name('calls')->where('id', $id)->delete();
            
            if ($result) {
                return json(['code' => 200, 'msg' => '删除成功']);
            }
            
            return json(['code' => 500, 'msg' => '删除失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }
    
    public function statistics()
    {
        $today = strtotime(date('Y-m-d'));
        $monthStart = strtotime(date('Y-m-01'));

        // 检查表是否存在
        $tableExists = Db::query("SHOW TABLES LIKE 'call_records'");
        $hasData = false;

        if ($tableExists) {
            $hasData = true;
            $stats = [
                'total_calls' => Db::name('call_records')->count(),
                'today_calls' => Db::name('call_records')->where('create_time', '>=', $today)->count(),
                'month_calls' => Db::name('call_records')->where('create_time', '>=', $monthStart)->count(),
                'avg_duration' => Db::name('call_records')->where('status', 1)->avg('duration') ?: 0,
                'video_calls' => Db::name('call_records')->where('call_type', 2)->count(),
                'voice_calls' => Db::name('call_records')->where('call_type', 1)->count(),
            ];
        } else {
            $stats = [
                'total_calls' => 0,
                'today_calls' => 0,
                'month_calls' => 0,
                'avg_duration' => 0,
                'video_calls' => 0,
                'voice_calls' => 0,
            ];
        }

        return json(['code' => 200, 'data' => $stats]);
    }

    public function list()
    {
        try {
            $page = Request::get('page', 1);
            $limit = Request::get('limit', 20);
            $keyword = Request::get('keyword', '');
            $callType = Request::get('call_type/d', -1);
            $status = Request::get('status/d', -1);

            // 检查表是否存在
            $tableExists = Db::query("SHOW TABLES LIKE 'call_records'");

            if (!$tableExists) {
                return json([
                    'code' => 200,
                    'msg' => '暂无数据',
                    'data' => [],
                    'total' => 0
                ]);
            }

            // 构建查询条件
            $where = [];
            if (!empty($keyword)) {
                $where[] = ['caller_name|callee_name', 'like', '%' . $keyword . '%'];
            }
            if ($callType > 0) {
                $where[] = ['call_type', '=', $callType];
            }
            if ($status >= 0) {
                $where[] = ['status', '=', $status];
            }

            // 查询通话记录
            $list = Db::name('call_records')
                ->where($where)
                ->order('create_time', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            // 格式化时间
            foreach ($list as &$item) {
                $item['create_time'] = isset($item['create_time']) ? date('Y-m-d H:i:s', $item['create_time']) : '-';
            }

            $total = Db::name('call_records')->where($where)->count();

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $list,
                'total' => $total
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败：' . $e->getMessage(),
                'data' => [],
                'total' => 0
            ]);
        }
    }
}
