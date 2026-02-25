<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use app\model\Software as SoftwareModel;
use app\model\Version;
use think\facade\View;
use think\facade\Request;
use think\facade\Session;
use think\facade\Redirect;

class Software extends AdminController
{

    
    // 软件列表
    public function index()
    {
        // 获取所有软件
        $software_list = SoftwareModel::getAllSoftware();
        
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'software_list' => $software_list,
        ]);
        
        return View::fetch('admin/software/index');
    }
    
    // 软件详情
    public function detail($id)
    {
        // 获取软件详情
        $software = SoftwareModel::getSoftwareById($id);
        if (!$software) {
            return "软件不存在";
        }
        
        // 获取软件版本信息
        $versions = Version::getVersionsBySoftwareId($id);
        
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'software' => $software,
            'versions' => $versions,
        ]);
        
        return View::fetch('admin/software/detail');
    }
    
    // 添加软件页面
    public function add()
    {
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
        ]);
        
        return View::fetch('admin/software/add');
    }
    
    // 保存软件
    public function save()
    {
        // 获取表单数据
        $data = Request::post();
        
        // 验证数据
        if (empty($data['software_name']) || empty($data['software_version'])) {
            return "软件名称和版本不能为空";
        }
        
        // 添加软件
        $software = SoftwareModel::addSoftware($data);
        if ($software) {
            return redirect('/admin/software/index.html');
        } else {
            return "添加软件失败";
        }
    }
    
    // 编辑软件页面
    public function edit($id)
    {
        // 获取软件详情
        $software = SoftwareModel::getSoftwareById($id);
        if (!$software) {
            return "软件不存在";
        }
        
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'software' => $software,
        ]);
        
        return View::fetch('admin/software/edit');
    }
    
    // 更新软件
    public function update()
    {
        // 获取表单数据
        $data = Request::post();
        $id = $data['id'];
        
        // 验证数据
        if (empty($id) || empty($data['software_name'])) {
            return "软件ID和名称不能为空";
        }
        
        // 更新软件
        $result = SoftwareModel::updateSoftware($id, $data);
        if ($result) {
            return redirect('/admin/software/index.html');
        } else {
            return "更新软件失败";
        }
    }
    
    // 删除软件
    public function delete($id)
    {
        // 删除软件
        $result = SoftwareModel::deleteSoftware($id);
        if ($result) {
            return redirect('/admin/software/index.html');
        } else {
            return "删除软件失败";
        }
    }
    
    // 安装软件
    public function install($id)
    {
        // 更新软件状态为已安装
        $result = SoftwareModel::updateSoftware($id, ['status' => 1]);
        if ($result) {
            return redirect('/admin/software/index.html');
        } else {
            return "安装软件失败";
        }
    }
    
    // 卸载软件
    public function uninstall($id)
    {
        // 更新软件状态为未安装
        $result = SoftwareModel::updateSoftware($id, ['status' => 0]);
        if ($result) {
            return redirect('/admin/software/index.html');
        } else {
            return "卸载软件失败";
        }
    }
}
