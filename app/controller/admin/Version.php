<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use app\model\Version as VersionModel;
use app\model\Software as SoftwareModel;
use think\facade\View;
use think\facade\Request;
use think\facade\Session;
use think\facade\Redirect;

class Version extends AdminController
{

    
    // 版本列表
    public function index()
    {
        try {
            // 获取所有版本
            $versions = VersionModel::getAllVersions();

            // 获取所有软件用于显示名称
            $softwareList = SoftwareModel::getAllSoftware();
            $softwareMap = [];
            foreach ($softwareList as $software) {
                $softwareMap[$software->id] = $software->software_name;
            }
        } catch (\Exception $e) {
            $versions = [];
            $softwareList = [];
            $softwareMap = [];
        }

        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username', '管理员'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'page_title' => '版本管理',
            'versions' => $versions,
            'softwareMap' => $softwareMap,
        ]);

        return View::fetch('admin/version/index');
    }
    
    // 版本详情
    public function detail($id)
    {
        // 获取版本详情
        $version = VersionModel::getVersionById($id);
        if (!$version) {
            return "版本不存在";
        }

        // 获取软件信息
        $software = SoftwareModel::getSoftwareById($version->software_id);

        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username', '管理员'),
            'version' => $version,
            'software' => $software,
        ]);

        return View::fetch('admin/version/detail');
    }
    
    // 添加版本页面
    public function add()
    {
        // 获取所有软件用于选择
        $softwareList = SoftwareModel::getAllSoftware();

        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username', '管理员'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'page_title' => '添加版本',
            'softwareList' => $softwareList,
        ]);

        return View::fetch('admin/version/add');
    }
    
    // 保存版本
    public function save()
    {
        // 获取表单数据
        $data = Request::post();
        
        // 验证数据
        if (empty($data['software_id']) || empty($data['version_number'])) {
            return "软件ID和版本号不能为空";
        }
        
        // 处理发布日期
        if (!empty($data['release_date'])) {
            $data['release_date'] = strtotime($data['release_date']);
        } else {
            $data['release_date'] = time();
        }
        
        // 处理是否为最新版本
        $data['is_latest'] = isset($data['is_latest']) && $data['is_latest'] ? 1 : 0;
        
        // 添加版本
        $version = VersionModel::addVersion($data);
        if ($version) {
            return redirect('/admin/version/index.html');
        } else {
            return "添加版本失败";
        }
    }
    
    // 编辑版本页面
    public function edit($id)
    {
        // 获取版本详情
        $version = VersionModel::getVersionById($id);
        if (!$version) {
            return "版本不存在";
        }

        // 获取所有软件
        $softwareList = SoftwareModel::getAllSoftware();

        // 处理日期显示
        if ($version->release_date) {
            $version->release_date = date('Y-m-d', $version->release_date);
        }

        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username', '管理员'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'page_title' => '编辑版本',
            'version' => $version,
            'softwareList' => $softwareList,
        ]);

        return View::fetch('admin/version/edit');
    }
    
    // 更新版本
    public function update()
    {
        // 获取表单数据
        $data = Request::post();
        $id = $data['id'];
        
        // 验证数据
        if (empty($id) || empty($data['software_id']) || empty($data['version_number'])) {
            return "版本ID、软件ID和版本号不能为空";
        }
        
        // 处理发布日期
        if (!empty($data['release_date'])) {
            $data['release_date'] = strtotime($data['release_date']);
        }
        
        // 处理是否为最新版本
        $data['is_latest'] = isset($data['is_latest']) && $data['is_latest'] ? 1 : 0;
        
        // 更新版本
        $result = VersionModel::updateVersion($id, $data);
        if ($result) {
            return redirect('/admin/version/index.html');
        } else {
            return "更新版本失败";
        }
    }
    
    // 删除版本
    public function delete($id)
    {
        $loginCheck = $this->checkLogin();
        if ($loginCheck !== true) {
            return $loginCheck;
        }
        
        // 删除版本
        $result = VersionModel::deleteVersion($id);
        if ($result) {
            return redirect('/admin/version/index.html');
        } else {
            return "删除版本失败";
        }
    }
}
