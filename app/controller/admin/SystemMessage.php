<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class SystemMessage extends AdminController
{
    
    public function index()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $type = Request::param('type', '');
        
        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['title', 'like', "%{$escapedKeyword}%"];
        }
        if ($type) {
            $where[] = ['type', '=', $type];
        }
        
        $messages = Db::name('system_messages')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'messages' => $messages,
            'keyword' => $keyword,
            'type' => $type,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/system-message/index');
    }
    
    public function templates()
    {
        $templates = Db::name('message_templates')
            ->order('create_time desc')
            ->select();
        
        View::assign([
            'templates' => $templates,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/system-message/templates');
    }
}
