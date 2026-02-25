<?php

namespace app\helper;

use think\facade\Cache;

class CacheHelper
{
    protected static $prefix = 'app_';
    
    protected static $defaultExpire = 3600;
    
    public static function get($key, $default = null)
    {
        $cacheKey = self::$prefix . $key;
        return Cache::get($cacheKey, $default);
    }
    
    public static function set($key, $value, $expire = null)
    {
        $cacheKey = self::$prefix . $key;
        $expire = $expire ?? self::$defaultExpire;
        return Cache::set($cacheKey, $value, $expire);
    }
    
    public static function remember($key, $callback, $expire = null)
    {
        $cacheKey = self::$prefix . $key;
        $expire = $expire ?? self::$defaultExpire;
        
        $value = Cache::get($cacheKey);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        Cache::set($cacheKey, $value, $expire);
        
        return $value;
    }
    
    public static function delete($key)
    {
        $cacheKey = self::$prefix . $key;
        return Cache::delete($cacheKey);
    }
    
    public static function clear()
    {
        return Cache::clear();
    }
    
    public static function tags($tags)
    {
        return Cache::tag($tags);
    }
    
    public static function tag($name)
    {
        return self::$prefix . $name;
    }
}
