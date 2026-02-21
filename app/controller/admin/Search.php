<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Search extends AdminController
{
    
    public function index()
    {
        $keyword = Request::param('keyword', '');
        $startDate = Request::param('start_date', '');
        $endDate = Request::param('end_date', '');

        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['keyword', 'like', "%{$escapedKeyword}%"];
        }
        if ($startDate) {
            $where[] = ['create_time', '>=', strtotime($startDate)];
        }
        if ($endDate) {
            $where[] = ['create_time', '<=', strtotime($endDate . ' 23:59:59')];
        }

        $searches = Db::name('search_logs')
            ->where($where)
            ->order('count desc, create_time desc')
            ->paginate([
                'list_rows' => 20,
                'page' => Request::param('page', 1)
            ]);

        View::assign([
            'searches' => $searches,
            'keyword' => $keyword,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'admin_name' => Session::get('admin_username', '管理员'),
            'page_title' => '搜索管理'
        ]);

        return View::fetch('admin/search/index');
    }
    
    public function hot()
    {
        try {
            $hotKeywords = Db::name('search_logs')
                ->order('count desc')
                ->limit(50)
                ->select();
        } catch (\Exception $e) {
            $hotKeywords = [];
        }

        View::assign([
            'hotKeywords' => $hotKeywords,
            'admin_name' => Session::get('admin_username', '管理员'),
            'page_title' => '热门搜索'
        ]);

        return View::fetch('admin/search/hot');
    }
    
    public function history()
    {
        $history = Db::name('search_history')
            ->order('create_time desc')
            ->paginate([
                'list_rows' => 20,
                'page' => Request::param('page', 1)
            ]);

        View::assign([
            'history' => $history,
            'admin_name' => Session::get('admin_username', '管理员'),
            'page_title' => '搜索历史'
        ]);

        return View::fetch('admin/search/history');
    }
}
