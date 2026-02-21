<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Favorite extends AdminController
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
        
        $favorites = Db::name('favorites')
            ->alias('f')
            ->leftJoin('user u', 'f.user_id = u.id')
            ->where($where)
            ->order('f.create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'favorites' => $favorites,
            'keyword' => $keyword,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/favorite/index');
    }
    
    public function statistics()
    {
        $totalFavorites = Db::name('favorites')->count();
        $todayFavorites = Db::name('favorites')
            ->where('create_time', '>=', strtotime(date('Y-m-d')))
            ->count();
        
        $statistics = [
            'total' => $totalFavorites,
            'today' => $todayFavorites,
        ];
        
        View::assign([
            'statistics' => $statistics,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/favorite/statistics');
    }
    
    public function hot()
    {
        $hotItems = Db::name('favorites')
            ->field('target_id, target_type, count(*) as count')
            ->group('target_id, target_type')
            ->order('count desc')
            ->limit(50)
            ->select();
        
        View::assign([
            'hotItems' => $hotItems,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/favorite/hot');
    }
}
