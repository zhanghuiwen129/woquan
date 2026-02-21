<?php

namespace app\model;

use think\Model;

class CurrencyType extends Model
{
    protected $name = 'currency_types';
    protected $autoWriteTimestamp = false;
    
    // 获取所有启用的货币类型
    public static function getActiveTypes()
    {
        return self::where('status', 1)
            ->order('sort ASC, is_primary DESC')
            ->select();
    }
    
    // 获取主要货币
    public static function getPrimaryCurrency()
    {
        return self::where('is_primary', 1)
            ->where('status', 1)
            ->find();
    }
    
    // 获取货币类型信息
    public static function getTypeById($id)
    {
        return self::where('id', $id)->find();
    }
    
    // 设置主要货币
    public function setAsPrimary()
    {
        // 首先将所有货币的主要标识设为0
        self::where('id', '<>', $this->id)->update(['is_primary' => 0]);
        
        // 然后将当前货币设为主要货币
        $this->is_primary = 1;
        $this->save();
        
        return true;
    }
}
