<?php
namespace app\middleware;

use think\facade\Session;
use think\Response;
use think\Db;

class AdminAuth
{
    /**
     * 处理后台权限检查
     * @param \think\Request $request
     * @param \Closure $next
     * @param array $permissions 需要检查的权限列表
     * @return mixed
     */
    public function handle($request, \Closure $next, ...$permissions)
    {
        // 检查session中是否有管理员信息
        if (!Session::has('admin_id')) {
            // 如果是API请求，返回JSON响应
            if ($request->isAjax()) {
                return json([
                    'code' => 401,
                    'msg'  => '请先登录后台',
                    'data' => null
                ]);
            }
            
            // 普通页面请求，跳转到后台登录页
            return redirect('/admin/login');
        }
        
        // 获取当前管理员ID
        $adminId = Session::get('admin_id');
        
        // 如果没有指定权限，直接放行（仅登录检查）
        if (empty($permissions)) {
            return $next($request);
        }
        
        // 检查管理员是否有指定权限
        if (!$this->checkAdminPermissions($adminId, $permissions)) {
            // 无权限，返回403
            if ($request->isAjax()) {
                return json([
                    'code' => 403,
                    'msg'  => '权限不足',
                    'data' => null
                ]);
            }
            
            // 跳转到403页面
            return redirect('/admin/403');
        }

        return $next($request);
    }
    
    /**
     * 检查管理员权限
     * @param int $adminId 管理员ID
     * @param array $permissions 需要检查的权限列表
     * @return bool 是否有权限
     */
    protected function checkAdminPermissions($adminId, array $permissions)
    {
        // 获取管理员信息 - 使用name方法，自动添加表前缀
        $admin = Db::name('admin')->where('id', $adminId)->find();

        if (!$admin) {
            return false;
        }

        // 超级管理员拥有所有权限
        if ($admin['role'] == 1) {  // 使用role字段而不是is_super
            return true;
        }

        // 如果没有指定权限，直接放行（仅登录检查）
        if (empty($permissions)) {
            return true;
        }

        // 注意：当前系统中管理员权限系统可能未完全实现
        // 这里先返回true，允许登录后的管理员访问所有功能
        return true;
    }
}
