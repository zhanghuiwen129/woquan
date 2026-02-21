<?php

namespace app\model;

use think\Model;

class UserVip extends Model
{
    protected $name = 'user_vip';
    protected $autoWriteTimestamp = false;
    
    // 关联VIP等级
    public function vipLevel()
    {
        return $this->belongsTo(VipLevel::class, 'level_id', 'id');
    }
    
    // 获取用户当前的VIP信息
    public static function getUserVipInfo($userId)
    {
        return self::where('user_id', $userId)
            ->order('create_time DESC')
            ->find();
    }
    
    // 检查用户是否是VIP
    public static function isVip($userId)
    {
        $userVip = self::getUserVipInfo($userId);
        if (!$userVip) {
            return false;
        }
        
        // 永久VIP或未过期
        return $userVip->is_permanent == 1 || $userVip->end_time > time();
    }
    
    // 检查用户是否有某个特权
    public static function hasPrivilege($userId, $privilege)
    {
        if (!self::isVip($userId)) {
            return false;
        }
        
        $userVip = self::getUserVipInfo($userId);
        $vipLevel = $userVip->vipLevel;
        
        if (!$vipLevel) {
            return false;
        }
        
        $privileges = $vipLevel->getPrivilegesList();
        return in_array($privilege, $privileges);
    }
    
    // 获取用户当前VIP等级
    public static function getCurrentLevel($userId)
    {
        $userVip = self::getUserVipInfo($userId);
        if (!$userVip || (!$userVip->is_permanent && $userVip->end_time <= time())) {
            return null;
        }
        
        return $userVip->vipLevel;
    }
}
