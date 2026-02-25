<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;

class Log extends AdminController
{
    
    public function index()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        
        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['username', 'like', "%{$escapedKeyword}%"];
        }
        
        $logs = Db::name('admin_log')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'logs' => $logs,
            'keyword' => $keyword,
        ]);
        
        return View::fetch('admin/log/index');
    }
    
    public function operation()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        
        $where = [];
        if ($keyword) {
            $where[] = ['username', 'like', "%{$keyword}%"];
        }
        
        $logs = Db::name('operation_log')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'logs' => $logs,
            'keyword' => $keyword,
        ]);
        
        return View::fetch('admin/log/operation');
    }
    
    public function error()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        
        $logs = Db::name('error_log')
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'logs' => $logs
        ]);
        
        return View::fetch('admin/log/error');
    }
    
    public function slowQuery()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        
        $logs = Db::name('slow_query_log')
            ->order('execute_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'logs' => $logs
        ]);
        
        return View::fetch('admin/log/slow-query');
    }
}
