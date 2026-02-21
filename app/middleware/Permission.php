<?php
namespace app\middleware;

use think\facade\Session;
use think\facade\Cookie;
use think\Db;

class Permission
{
    /**
     * 处理权限检查
     * @param \think\Request $request
     * @param \Closure $next
     * @param array $permissions 需要检查的权限列表
     * @return mixed
     */
    public function handle($request, \Closure $next, ...$permissions)
    {
        // 检查用户是否登录
        $userId = Session::get('user_id') ?: Cookie::get('user_id');
        
        if (empty($userId)) {
            // 未登录，返回401
            if ($request->isAjax() || strpos($request->pathinfo(), 'api/') === 0) {
                return json([
                    'code' => 401,
                    'msg'  => '请先登录',
                    'data' => null
                ]);
            }
            return redirect('/login');
        }
        
        // 如果没有指定权限，直接放行（仅登录检查）
        if (empty($permissions)) {
            return $next($request);
        }
        
        // 获取用户角色
        $userRole = $this->getUserRole($userId);
        
        // 检查是否有admin权限
        if ($userRole === 'admin') {
            return $next($request);
        }
        
        // 检查用户是否有指定权限
        if (!$this->checkUserPermissions($userId, $permissions)) {
            // 无权限，返回403
            if ($request->isAjax() || strpos($request->pathinfo(), 'api/') === 0) {
                return json([
                    'code' => 403,
                    'msg'  => '权限不足',
                    'data' => null
                ]);
            }
            return redirect('/403');
        }
        
        return $next($request);
    }
    
    /**
     * 获取用户角色
     * @param int $userId 用户ID
     * @return string 用户角色
     */
    protected function getUserRole($userId)
    {
        // 从数据库获取用户角色
        $user = Db::table('pt_user')->where('id', $userId)->find();
        return $user ? $user['role'] : 'user';
    }
    
    /**
     * 检查用户是否有指定权限
     * @param int $userId 用户ID
     * @param array $permissions 权限列表
     * @return bool 是否有权限
     */
    protected function checkUserPermissions($userId, array $permissions)
    {
        // 这里可以实现更复杂的权限检查逻辑
        // 例如，从数据库中获取用户的所有权限，然后检查是否包含需要的权限
        // 目前简化实现，仅检查用户角色
        $userRole = $this->getUserRole($userId);
        
        // 管理员拥有所有权限
        if ($userRole === 'admin') {
            return true;
        }
        
        // 用户角色权限映射
        $rolePermissions = [
            'editor' => ['publish_article', 'edit_article', 'delete_article'],
            'moderator' => ['publish_article', 'edit_article', 'delete_article', 'moderate_comments'],
            'user' => ['publish_comment', 'like_article']
        ];
        
        // 检查用户角色是否有权限
        if (isset($rolePermissions[$userRole])) {
            foreach ($permissions as $permission) {
                if (in_array($permission, $rolePermissions[$userRole])) {
                    return true;
                }
            }
        }
        
        return false;
    }
}
