<?php

namespace app\model;

use think\Model;

class Version extends Model
{
    protected $name = 'versions';
    protected $autoWriteTimestamp = false;
    
    // 获取所有版本
    public static function getAllVersions()
    {
        return self::select();
    }
    
    // 根据ID获取版本
    public static function getVersionById($id)
    {
        return self::find($id);
    }
    
    // 根据软件ID获取版本
    public static function getVersionsBySoftwareId($software_id)
    {
        return self::where('software_id', $software_id)->order('release_date', 'desc')->select();
    }
    
    // 获取最新版本
    public static function getLatestVersion($software_id)
    {
        return self::where('software_id', $software_id)->where('is_latest', 1)->find();
    }
    
    // 添加版本
    public static function addVersion($data)
    {
        $data['create_time'] = time();
        $data['update_time'] = time();
        
        // 如果设置为最新版本，更新其他版本为非最新
        if (isset($data['is_latest']) && $data['is_latest']) {
            self::where('software_id', $data['software_id'])->update(['is_latest' => 0]);
        }
        
        return self::create($data);
    }
    
    // 更新版本信息
    public static function updateVersion($id, $data)
    {
        $version = self::find($id);
        if (!$version) return false;
        
        $data['update_time'] = time();
        
        // 如果设置为最新版本，更新其他版本为非最新
        if (isset($data['is_latest']) && $data['is_latest']) {
            self::where('software_id', $version->software_id)->update(['is_latest' => 0]);
        }
        
        return $version->update($data);
    }
    
    // 删除版本
    public static function deleteVersion($id)
    {
        return self::where('id', $id)->delete();
    }
    
    // 获取版本状态
    public function getIsLatestText()
    {
        return $this->is_latest ? '最新版本' : '历史版本';
    }
}
