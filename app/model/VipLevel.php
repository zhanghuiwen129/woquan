<?php

namespace app\model;

use think\Model;

class VipLevel extends Model
{
    protected $name = 'vip_levels';
    protected $autoWriteTimestamp = false;
    
    // 获取所有启用的VIP等级
    public static function getActiveLevels()
    {
        return self::where('status', 1)
            ->order('sort ASC')
            ->select();
    }
    
    // 获取VIP等级信息
    public static function getLevelById($id)
    {
        return self::where('id', $id)->find();
    }
    
    // 获取VIP等级的特权列表
    public function getPrivilegesList()
    {
        if (empty($this->privileges)) {
            return [];
        }
        return json_decode($this->privileges, true) ?: [];
    }
    
    // 设置VIP等级的特权列表
    public function setPrivilegesList(array $privileges)
    {
        $this->privileges = json_encode($privileges, JSON_UNESCAPED_UNICODE);
    }
}
