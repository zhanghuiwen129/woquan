<?php

namespace app\model;

use think\Model;

class Software extends Model
{
    protected $name = 'software';
    protected $autoWriteTimestamp = false;
    
    // 获取所有软件
    public static function getAllSoftware()
    {
        return self::select();
    }
    
    // 根据ID获取软件
    public static function getSoftwareById($id)
    {
        return self::find($id);
    }
    
    // 根据名称获取软件
    public static function getSoftwareByName($name)
    {
        return self::where('software_name', $name)->select();
    }
    
    // 添加软件
    public static function addSoftware($data)
    {
        $data['create_time'] = time();
        $data['update_time'] = time();
        return self::create($data);
    }
    
    // 更新软件信息
    public static function updateSoftware($id, $data)
    {
        $data['update_time'] = time();
        return self::where('id', $id)->update($data);
    }
    
    // 删除软件
    public static function deleteSoftware($id)
    {
        return self::where('id', $id)->delete();
    }
    
    // 获取软件状态
    public function getStatusText()
    {
        return $this->status ? '已安装' : '未安装';
    }
    
    // 获取更新状态
    public function getUpdateStatusText()
    {
        return $this->update_available ? '有更新' : '最新版本';
    }
}
