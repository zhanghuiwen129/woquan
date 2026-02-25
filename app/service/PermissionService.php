<?php
namespace app\service;

use think\facade\Session;
use think\Db;

class PermissionService
{
    /**
     * 检查用户是否有权限
     * @param int $userId 用户ID
     * @param string|array $permission 权限名称或权限数组
     * @return bool 是否有权限
     */
    public static function checkUserPermission($userId, $permission)
    {
        if (empty($userId) || empty($permission)) {
            return false;
        }
        
        // 获取用户角色
        $user = Db::table('pt_user')->where('id', $userId)->find();
        
        if (!$user) {
            return false;
        }
        
        // 管理员拥有所有权限
        if ($user['role'] === 'admin') {
            return true;
        }
        
        // 用户角色权限映射
        $rolePermissions = [
            'editor' => ['publish_article', 'edit_article', 'delete_article'],
            'moderator' => ['publish_article', 'edit_article', 'delete_article', 'moderate_comments'],
            'user' => ['publish_comment', 'like_article']
        ];
        
        // 检查用户角色是否有权限
        if (isset($rolePermissions[$user['role']])) {
            if (is_string($permission)) {
                return in_array($permission, $rolePermissions[$user['role']]);
            } elseif (is_array($permission)) {
                foreach ($permission as $perm) {
                    if (in_array($perm, $rolePermissions[$user['role']])) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * 检查管理员是否有权限
     * @param int $adminId 管理员ID
     * @param string|array $permission 权限名称或权限数组
     * @return bool 是否有权限
     */
    public static function checkAdminPermission($adminId, $permission)
    {
        if (empty($adminId) || empty($permission)) {
            return false;
        }
        
        // 获取管理员信息
        $admin = Db::table('pt_admin')->where('id', $adminId)->find();
        
        if (!$admin) {
            return false;
        }
        
        // 超级管理员拥有所有权限
        if ($admin['is_super'] == 1) {
            return true;
        }
        
        // 获取管理员角色
        $roleId = $admin['role_id'];
        
        // 从角色权限表中获取权限
        $rolePermissions = Db::table('pt_role_permission')
            ->alias('rp')
            ->join('pt_permission p', 'rp.permission_id = p.id')
            ->where('rp.role_id', $roleId)
            ->column('p.name');
        
        // 检查是否有匹配的权限
        if (is_string($permission)) {
            return in_array($permission, $rolePermissions);
        } elseif (is_array($permission)) {
            foreach ($permission as $perm) {
                if (in_array($perm, $rolePermissions)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 获取当前登录用户的权限列表
     * @return array 权限列表
     */
    public static function getUserPermissions()
    {
        $userId = Session::get('user_id');
        
        if (empty($userId)) {
            return [];
        }
        
        // 获取用户角色
        $user = Db::table('pt_user')->where('id', $userId)->find();
        
        if (!$user) {
            return [];
        }
        
        // 管理员拥有所有权限
        if ($user['role'] === 'admin') {
            return ['publish_article', 'edit_article', 'delete_article', 'moderate_comments', 'publish_comment', 'like_article'];
        }
        
        // 用户角色权限映射
        $rolePermissions = [
            'editor' => ['publish_article', 'edit_article', 'delete_article'],
            'moderator' => ['publish_article', 'edit_article', 'delete_article', 'moderate_comments'],
            'user' => ['publish_comment', 'like_article']
        ];
        
        return isset($rolePermissions[$user['role']]) ? $rolePermissions[$user['role']] : [];
    }
    
    /**
     * 获取当前登录管理员的权限列表
     * @return array 权限列表
     */
    public static function getAdminPermissions()
    {
        $adminId = Session::get('admin_id');
        
        if (empty($adminId)) {
            return [];
        }
        
        // 获取管理员信息
        $admin = Db::table('pt_admin')->where('id', $adminId)->find();
        
        if (!$admin) {
            return [];
        }
        
        // 超级管理员拥有所有权限
        if ($admin['is_super'] == 1) {
            // 获取所有权限
            return Db::table('pt_permission')->column('name');
        }
        
        // 获取管理员角色
        $roleId = $admin['role_id'];
        
        // 从角色权限表中获取权限
        return Db::table('pt_role_permission')
            ->alias('rp')
            ->join('pt_permission p', 'rp.permission_id = p.id')
            ->where('rp.role_id', $roleId)
            ->column('p.name');
    }
    
    /**
     * 获取用户角色
     * @param int $userId 用户ID
     * @return string 用户角色
     */
    public static function getUserRole($userId)
    {
        if (empty($userId)) {
            return 'user';
        }
        
        // 获取用户角色
        $user = Db::table('pt_user')->where('id', $userId)->find();
        
        return $user ? $user['role'] : 'user';
    }
    
    /**
     * 获取管理员角色
     * @param int $adminId 管理员ID
     * @return string 管理员角色
     */
    public static function getAdminRole($adminId)
    {
        if (empty($adminId)) {
            return '';
        }
        
        // 获取管理员信息
        $admin = Db::table('pt_admin')->where('id', $adminId)->find();
        
        if (!$admin) {
            return '';
        }
        
        // 获取角色信息
        $role = Db::table('pt_role')->where('id', $admin['role_id'])->find();
        
        return $role ? $role['name'] : '';
    }
}
