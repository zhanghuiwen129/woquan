<?php

namespace app\model;

use think\Model;
use think\facade\Cache;

class SystemConfig extends Model
{
    protected $name = 'system_config';  // 使用name属性，ThinkPHP会自动添加前缀
    protected $autoWriteTimestamp = false;
    protected static $systemCacheKey = 'system_config_all';
    protected static $systemCacheExpire = 300; // 缓存5分钟

    // 框架会自动根据配置的前缀添加到表名中

    // 获取所有配置（带缓存）
    public static function getAllConfigs()
    {
        try {
            // 先从缓存读取
            $configs = Cache::get(self::$systemCacheKey);
            
            if ($configs === null) {
                // 缓存不存在，从数据库查询
                $configs = self::select();
                
                // 存入缓存
                Cache::set(self::$systemCacheKey, $configs, self::$systemCacheExpire);
            }
            
            return $configs;
        } catch (\Exception $e) {
            error_log('获取系统配置失败: ' . $e->getMessage());
            return [];
        }
    }

    // 清除配置缓存
    public static function clearCache()
    {
        Cache::delete(self::$systemCacheKey);
    }
    
    // 根据配置键获取配置值
    public static function getConfigByKey($config_key)
    {
        $config = self::where('config_key', $config_key)->find();
        return $config ? $config->config_value : null;
    }
    
    // 添加配置
    public static function addConfig($data)
    {
        $data['create_time'] = time();
        $data['update_time'] = time();
        $result = self::create($data);
        // 清除缓存
        self::clearCache();
        return $result;
    }
    
    // 更新配置
    public static function updateConfig($id, $data)
    {
        $data['update_time'] = time();
        $result = self::where('id', $id)->update($data);
        // 清除缓存
        self::clearCache();
        return $result;
    }
    
    // 根据配置键更新配置值，如果不存在则创建
    public static function updateConfigByKey($config_key, $config_value)
    {
        // 检查配置是否存在
        $config = self::where('config_key', $config_key)->find();
        
        if ($config) {
            // 更新现有配置
            $data = [
                'config_value' => $config_value,
                'update_time' => time(),
            ];
            $result = self::where('config_key', $config_key)->update($data);
        } else {
            // 创建新配置
            $data = [
                'config_name' => $config_key,  // 使用config_key作为config_name
                'config_key' => $config_key,
                'config_value' => $config_value,
                'create_time' => time(),
                'update_time' => time(),
            ];
            $result = self::create($data);
        }
        // 清除缓存
        self::clearCache();
        return $result;
    }
    
    // 删除配置
    public static function deleteConfig($id)
    {
        $result = self::where('id', $id)->delete();
        // 清除缓存
        self::clearCache();
        return $result;
    }
}
