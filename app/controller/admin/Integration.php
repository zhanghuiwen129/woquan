<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;

class Integration extends AdminController
{
    
    public function login()
    {
        $config = Db::name('login_integration')->find();
        
        View::assign([
            'config' => $config
        ]);
        
        return View::fetch('admin/integration/login');
    }
    
    public function payment()
    {
        $config = Db::name('payment_integration')->find();
        
        View::assign([
            'config' => $config
        ]);
        
        return View::fetch('admin/integration/payment');
    }
    
    public function storage()
    {
        $config = Db::name('storage_integration')->find();
        
        View::assign([
            'config' => $config
        ]);
        
        return View::fetch('admin/integration/storage');
    }
    
    public function map()
    {
        $config = Db::name('map_integration')->find();
        
        View::assign([
            'config' => $config
        ]);
        
        return View::fetch('admin/integration/map');
    }
}
