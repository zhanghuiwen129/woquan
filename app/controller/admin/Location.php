<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Location extends AdminController
{
    
    public function index()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');

        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['u.username|u.nickname', 'like', "%{$escapedKeyword}%"];
        }

        try {
            $locations = Db::name('user_locations')
                ->alias('l')
                ->leftJoin('user u', 'l.user_id = u.id')
                ->where($where)
                ->order('l.update_time desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);
        } catch (\Exception $e) {
            $locations = new \think\Paginator([], $limit, $page);
        }

        View::assign([
            'locations' => $locations,
            'keyword' => $keyword,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/location/index');
    }

    public function list()
    {
        try {
            $page = Request::get('page', 1);
            $limit = Request::get('limit', 20);
            $keyword = Request::get('keyword', '');

            $where = [];
            if ($keyword) {
                $where[] = ['username|nickname', 'like', "%{$keyword}%"];
            }

            $total = Db::name('user_locations')->where($where)->count();
            $locations = Db::name('user_locations')
                ->where($where)
                ->order('update_time', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            // 格式化时间
            foreach ($locations as &$location) {
                $location['update_time'] = isset($location['update_time']) ? date('Y-m-d H:i:s', $location['update_time']) : '-';
                $location['address'] = $location['address'] ?? '-';
            }

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $locations,
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

    public function heatmap()
    {
        try {
            $locations = Db::name('user_locations')
                ->field('latitude, longitude, count(*) as count')
                ->group('latitude, longitude')
                ->select();
        } catch (\Exception $e) {
            $locations = [];
        }

        View::assign([
            'locations' => $locations,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/location/heatmap');
    }
}
