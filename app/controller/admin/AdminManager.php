<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Session;
use app\model\Admin;

class AdminManager extends AdminController
{
    // 管理员列表页面
    public function index()
    {
        if (!Session::has('admin_id')) {
            return redirect('/admin/login.html');
        }

        $admins = Admin::getAdminList();
        View::assign('admins', $admins);
        return View::fetch('admin/admin_list');
    }

    // 添加管理员页面
    public function create()
    {
        if (!Session::has('admin_id')) {
            return redirect('/admin/login.html');
        }

        return View::fetch('admin/admin_create');
    }

    // 处理添加管理员请求
    public function store()
    {
        if (!Session::has('admin_id')) {
            return json([
                'code' => 401,
                'msg'  => '请先登录',
                'data' => null
            ]);
        }

        $data = Request::param();
        
        // 验证数据
        if (empty($data['username']) || empty($data['password'])) {
            return json([
                'code' => 400,
                'msg'  => '用户名和密码不能为空',
                'data' => null
            ]);
        }

        if (strlen($data['password']) < 6) {
            return json([
                'code' => 400,
                'msg'  => '密码长度至少需要6位',
                'data' => null
            ]);
        }

        // 检查用户名是否已存在
        $existingAdmin = Admin::where('username', $data['username'])->find();
        if ($existingAdmin) {
            return json([
                'code' => 400,
                'msg'  => '用户名已存在',
                'data' => null
            ]);
        }

        // 创建管理员
        $result = Admin::createAdmin($data);
        
        if ($result) {
            return json([
                'code' => 200,
                'msg'  => '管理员创建成功',
                'data' => null
            ]);
        } else {
            return json([
                'code' => 500,
                'msg'  => '管理员创建失败',
                'data' => null
            ]);
        }
    }

    // 编辑管理员页面
    public function edit($id)
    {
        if (!Session::has('admin_id')) {
            return redirect('/admin/login.html');
        }

        $admin = Admin::find($id);
        if (!$admin) {
            return redirect('/admin/admin-manager.html')->with('error', '管理员不存在');
        }

        View::assign('admin', $admin);
        return View::fetch('admin/admin_edit');
    }

    // 处理编辑管理员请求
    public function update($id)
    {
        if (!Session::has('admin_id')) {
            return json([
                'code' => 401,
                'msg'  => '请先登录',
                'data' => null
            ]);
        }

        $data = Request::param();
        $admin = Admin::find($id);
        
        if (!$admin) {
            return json([
                'code' => 404,
                'msg'  => '管理员不存在',
                'data' => null
            ]);
        }

        // 更新管理员信息
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 6) {
                return json([
                    'code' => 400,
                    'msg'  => '密码长度至少需要6位',
                    'data' => null
                ]);
            }
            $admin->password = $data['password'];
        }

        if (!empty($data['name'])) {
            $admin->name = $data['name'];
        }

        if (isset($data['status'])) {
            $admin->status = $data['status'];
        }

        $result = $admin->save();
        
        if ($result) {
            return json([
                'code' => 200,
                'msg'  => '管理员更新成功',
                'data' => null
            ]);
        } else {
            return json([
                'code' => 500,
                'msg'  => '管理员更新失败',
                'data' => null
            ]);
        }
    }

    // 删除管理员
    public function delete($id)
    {
        if (!Session::has('admin_id')) {
            return json([
                'code' => 401,
                'msg'  => '请先登录',
                'data' => null
            ]);
        }

        // 不能删除自己
        if ($id == Session::get('admin_id')) {
            return json([
                'code' => 400,
                'msg'  => '不能删除当前登录的管理员',
                'data' => null
            ]);
        }

        $admin = Admin::find($id);
        if (!$admin) {
            return json([
                'code' => 404,
                'msg'  => '管理员不存在',
                'data' => null
            ]);
        }

        $result = $admin->delete();
        
        if ($result) {
            return json([
                'code' => 200,
                'msg'  => '管理员删除成功',
                'data' => null
            ]);
        } else {
            return json([
                'code' => 500,
                'msg'  => '管理员删除失败',
                'data' => null
            ]);
        }
    }

    // 切换管理员状态
    public function toggleStatus($id)
    {
        if (!Session::has('admin_id')) {
            return json([
                'code' => 401,
                'msg'  => '请先登录',
                'data' => null
            ]);
        }

        // 不能禁用自己
        if ($id == Session::get('admin_id')) {
            return json([
                'code' => 400,
                'msg'  => '不能禁用当前登录的管理员',
                'data' => null
            ]);
        }

        $admin = Admin::find($id);
        if (!$admin) {
            return json([
                'code' => 404,
                'msg'  => '管理员不存在',
                'data' => null
            ]);
        }

        $admin->status = $admin->status == 1 ? 0 : 1;
        $result = $admin->save();
        
        if ($result) {
            $statusText = $admin->status == 1 ? '启用' : '禁用';
            return json([
                'code' => 200,
                'msg'  => "管理员{$statusText}成功",
                'data' => ['status' => $admin->status]
            ]);
        } else {
            return json([
                'code' => 500,
                'msg'  => '操作失败',
                'data' => null
            ]);
        }
    }
}
