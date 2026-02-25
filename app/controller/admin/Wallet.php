<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;

class Wallet extends AdminController
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
        
        $wallets = Db::name('user_wallet')
            ->alias('w')
            ->leftJoin('user u', 'w.user_id = u.id')
            ->where($where)
            ->order('w.update_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'wallets' => $wallets,
            'keyword' => $keyword,
        ]);
        
        return View::fetch('admin/wallet/index');
    }
}
