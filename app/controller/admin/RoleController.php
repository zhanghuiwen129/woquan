<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use app\service\PermissionService;
use think\facade\Db;
use think\facade\View;

class RoleController extends AdminController
{
    // 应用权限中间件，指定需要的权限
    protected $middleware = [
        'AdminAuth' => ['except' => []],
    ];

    /**
     * 角色列表
     */
    public function index()
    {
        // 使用权限服务检查是否有权限
        if (!PermissionService::checkAdminPermission(session('admin_id'), '角色管理')) {
            return $this->error('没有权限访问此页面');
        }

        // 获取角色列表
        $roles = Db::table('pt_role')->where('status', 1)->order('sort')->select();
        
        View::assign('roles', $roles);
        return View::fetch();
    }

    /**
     * 添加角色
     */
    public function create()
    {
        // 使用权限服务检查是否有权限
        if (!PermissionService::checkAdminPermission(session('admin_id'), '添加角色')) {
            return $this->error('没有权限执行此操作');
        }

        return View::fetch();
    }

    /**
     * 保存角色
     */
    public function store()
    {
        // 使用权限服务检查是否有权限
        if (!PermissionService::checkAdminPermission(session('admin_id'), '保存角色')) {
            return json(['code' => 403, 'msg' => '没有权限执行此操作']);
        }

        $data = request()->post();
        $data['create_time'] = date('Y-m-d H:i:s');
        
        $result = Db::table('pt_role')->insert($data);
        
        if ($result) {
            return json(['code' => 200, 'msg' => '角色添加成功']);
        } else {
            return json(['code' => 500, 'msg' => '角色添加失败']);
        }
    }

    /**
     * 编辑角色
     */
    public function edit($id)
    {
        // 使用权限服务检查是否有权限
        if (!PermissionService::checkAdminPermission(session('admin_id'), '编辑角色')) {
            return $this->error('没有权限执行此操作');
        }

        $role = Db::table('pt_role')->where('id', $id)->find();
        
        if (!$role) {
            return $this->error('角色不存在');
        }
        
        View::assign('role', $role);
        return View::fetch();
    }

    /**
     * 更新角色
     */
    public function update()
    {
        // 使用权限服务检查是否有权限
        if (!PermissionService::checkAdminPermission(session('admin_id'), '更新角色')) {
            return json(['code' => 403, 'msg' => '没有权限执行此操作']);
        }

        $data = request()->post();
        $id = $data['id'];
        unset($data['id']);
        $data['update_time'] = date('Y-m-d H:i:s');
        
        $result = Db::table('pt_role')->where('id', $id)->update($data);
        
        if ($result) {
            return json(['code' => 200, 'msg' => '角色更新成功']);
        } else {
            return json(['code' => 500, 'msg' => '角色更新失败']);
        }
    }

    /**
     * 删除角色
     */
    public function delete()
    {
        // 使用权限服务检查是否有权限
        if (!PermissionService::checkAdminPermission(session('admin_id'), '删除角色')) {
            return json(['code' => 403, 'msg' => '没有权限执行此操作']);
        }

        $id = request()->post('id');
        
        $result = Db::table('pt_role')->where('id', $id)->update(['status' => 0]);
        
        if ($result) {
            return json(['code' => 200, 'msg' => '角色删除成功']);
        } else {
            return json(['code' => 500, 'msg' => '角色删除失败']);
        }
    }

    /**
     * 角色权限设置
     */
    public function permission($id)
    {
        // 使用权限服务检查是否有权限
        if (!PermissionService::checkAdminPermission(session('admin_id'), '角色管理')) {
            return $this->error('没有权限执行此操作');
        }

        if (request()->isPost()) {
            // 保存权限设置
            $permissions = request()->post('permissions', []);
            
            // 先删除旧的权限
            Db::table('pt_role_permission')->where('role_id', $id)->delete();
            
            // 保存新的权限
            if (!empty($permissions)) {
                foreach ($permissions as $permissionId) {
                    Db::table('pt_role_permission')->insert([
                        'role_id' => $id,
                        'permission_id' => $permissionId,
                        'create_time' => date('Y-m-d H:i:s')
                    ]);
                }
            }
            
            return json(['code' => 200, 'msg' => '权限设置成功']);
        }
        
        // 获取角色信息
        $role = Db::table('pt_role')->where('id', $id)->find();
        
        if (!$role) {
            return $this->error('角色不存在');
        }
        
        // 获取所有权限
        $permissions = Db::table('pt_permission')->where('status', 1)->order('parent_id, sort')->select();
        
        // 获取角色已有的权限
        $rolePermissions = Db::table('pt_role_permission')->where('role_id', $id)->column('permission_id');
        
        View::assign('role', $role);
        View::assign('permissions', $permissions);
        View::assign('rolePermissions', $rolePermissions);
        return View::fetch();
    }
}
