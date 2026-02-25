<?php

namespace app\model;

use think\Model;
use think\facade\Request;

/**
 * 管理员模型类
 */
class Admin extends Model
{
    /**
     * 对应的数据表名
     * @var string
     */
    protected $name = 'admin';

    /**
     * 是否自动写入时间戳
     * @var boolean
     */
    protected $autoWriteTimestamp = true;
    
    /**
     * 创建时间字段
     * @var string
     */
    protected $createTime = 'create_time';
    
    /**
     * 更新时间字段
     * @var string
     */
    protected $updateTime = 'update_time';

    /**
     * 超级管理员角色
     * @var integer
     */
    public const ROLE_SUPER_ADMIN = 1;
    
    /**
     * 普通管理员角色
     * @var integer
     */
    public const ROLE_NORMAL_ADMIN = 2;

    /**
     * 启用状态
     * @var integer
     */
    public const STATUS_ENABLED = 1;
    
    /**
     * 禁用状态
     * @var integer
     */
    public const STATUS_DISABLED = 0;

    /**
     * 根据用户名查找管理员
     *
     * @param string $username 用户名
     * @return mixed|null 管理员对象或null
     */
    public static function getByUsername($username)
    {
        return self::where('username', $username)->find();
    }

    /**
     * 验证管理员密码
     *
     * @param string $password 原始密码
     * @param string $hashedPassword 加密后的密码
     * @return boolean
     */
    public static function verifyPassword($password, $hashedPassword)
    {
        return password_verify($password, $hashedPassword);
    }

    /**
     * 加密密码
     *
     * @param string $password 原始密码
     * @return string 加密后的密码
     */
    public static function encryptPassword($password)
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * 更新登录信息
     *
     * @param string $ip IP地址
     * @return boolean
     */
    public function updateLoginInfo($ip)
    {
        $this->last_login_ip = $ip;
        $this->last_login_time = time();
        $this->login_count = $this->login_count + 1;
        return $this->save();
    }

    /**
     * 修改密码
     *
     * @param string $newPassword 新密码
     * @return boolean
     */
    public function changePassword($newPassword)
    {
        $this->password = self::encryptPassword($newPassword);
        return $this->save();
    }

    /**
     * 静态方法：修改管理员密码
     * @param integer $adminId 管理员ID
     * @param string  $oldPassword 旧密码
     * @param string  $newPassword 新密码
     * @return boolean 成功返回true，失败返回false
     */
    public static function changeAdminPassword($adminId, $oldPassword, $newPassword)
    {
        $admin = self::find($adminId);
        if (!$admin) {
            return false;
        }

        // 验证旧密码
        if (!self::verifyPassword($oldPassword, $admin->password)) {
            return false;
        }

        // 更新密码
        $admin->password = self::encryptPassword($newPassword);
        return $admin->save();
    }

    /**
     * 检查是否是超级管理员
     *
     * @return boolean
     */
    public function isSuperAdmin()
    {
        return $this->role == self::ROLE_SUPER_ADMIN;
    }

    /**
     * 检查是否启用
     *
     * @return boolean
     */
    public function isEnabled()
    {
        return $this->status == self::STATUS_ENABLED;
    }

    /**
     * 获取角色名称
     *
     * @return string
     */
    public function getRoleName()
    {
        $roles = [
            self::ROLE_SUPER_ADMIN => '超级管理员',
            self::ROLE_NORMAL_ADMIN => '普通管理员'
        ];
        return $roles[$this->role] ?? '未知角色';
    }

    /**
     * 获取状态名称
     *
     * @return string
     */
    public function getStatusName()
    {
        $statuses = [
            self::STATUS_ENABLED => '正常',
            self::STATUS_DISABLED => '禁用'
        ];
        return $statuses[$this->status] ?? '未知状态';
    }

    /**
     * 验证管理员登录
     * @param string $username 用户名
     * @param string $password 密码
     * @return array|null 验证成功返回管理员信息，失败返回null
     */
    public static function validateLogin($username, $password)
    {
        // 查找管理员
        $admin = self::where('username', $username)->where('status', self::STATUS_ENABLED)->find();
        
        if ($admin && self::verifyPassword($password, $admin['password'])) {
            // 更新登录信息
            $admin->updateLoginInfo(Request::ip());
            return $admin->toArray();
        }
        
        return null;
    }
}
