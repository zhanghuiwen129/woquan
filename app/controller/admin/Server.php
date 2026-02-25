<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use app\model\Server;
use think\facade\View;
use think\facade\Request;
use think\facade\Session;
use think\facade\Redirect;

class Server extends AdminController
{

    
    // 服务器列表
    public function index()
    {
        // 获取所有服务器
        $servers = Server::getAllServers();
        
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'servers' => $servers,
        ]);
        
        return View::fetch('admin/server/index');
    }
    
    // 服务器详情
    public function detail($id)
    {
        // 获取服务器详情
        $server = Server::getServerById($id);
        if (!$server) {
            return "服务器不存在";
        }
        
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'server' => $server,
        ]);
        
        return View::fetch('admin/server/detail');
    }
    
    // 添加服务器页面
    public function add()
    {
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
        ]);
        
        return View::fetch('admin/server/add');
    }
    
    // 保存服务器
    public function save()
    {
        // 获取表单数据
        $data = Request::post();
        
        // 验证数据
        if (empty($data['server_name']) || empty($data['server_ip'])) {
            return "服务器名称和IP不能为空";
        }
        
        // 检查IP是否已存在
        $existing = Server::getServerByIp($data['server_ip']);
        if ($existing) {
            return "该IP的服务器已存在";
        }
        
        // 添加服务器
        $server = Server::addServer($data);
        if ($server) {
            return redirect('/admin/server/index.html');
        } else {
            return "添加服务器失败";
        }
    }
    
    // 编辑服务器页面
    public function edit($id)
    {
        // 获取服务器详情
        $server = Server::getServerById($id);
        if (!$server) {
            return "服务器不存在";
        }
        
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'server' => $server,
        ]);
        
        return View::fetch('admin/server/edit');
    }
    
    // 更新服务器
    public function update()
    {
        // 获取表单数据
        $data = Request::post();
        $id = $data['id'];
        
        // 验证数据
        if (empty($id) || empty($data['server_name']) || empty($data['server_ip'])) {
            return "服务器ID、名称和IP不能为空";
        }
        
        // 检查IP是否已存在（排除当前服务器）
        $existing = Server::getServerByIp($data['server_ip']);
        if ($existing && $existing->id != $id) {
            return "该IP的服务器已存在";
        }
        
        // 更新服务器
        $result = Server::updateServer($id, $data);
        if ($result) {
            return redirect('/admin/server/index.html');
        } else {
            return "更新服务器失败";
        }
    }
    
    // 删除服务器
    public function delete($id)
    {
        // 删除服务器
        $result = Server::deleteServer($id);
        if ($result) {
            return redirect('/admin/server/index.html');
        } else {
            return "删除服务器失败";
        }
    }
}
