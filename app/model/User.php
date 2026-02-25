<?php

namespace app\model;

use think\Model;
use think\facade\Cache;

/**
 * 用户模型类
 *
 * @author System
 * @version 1.0
 */
class User extends Model
{
    /**
     * 对应的数据表名
     * @var string
     */
    protected $name = 'user';
    
    /**
     * 是否自动写入时间戳
     * @var bool
     */
    protected $autoWriteTimestamp = false;
    
    /**
     * 缓存键前缀
     * @var string
     */
    public const CACHE_KEY_PREFIX = 'user_';
    
    /**
     * 用户名缓存键前缀
     * @var string
     */
    public const CACHE_KEY_USERNAME = 'user_username_';
    
    /**
     * 用户登录验证
     *
     * @param string $username 用户名
     * @param string $password 密码
     * @return mixed|null 用户对象或false
     */
    public static function login($username, $password)
    {
        $user = self::where('username', $username)->find();
        
        if ($user) {
            $passwordValid = false;
            
            if (password_verify($password, $user->password)) {
                $passwordValid = true;
            } elseif (md5($password) === $user->password) {
                $passwordValid = true;
                if (strlen($user->password) === 32) {
                    $user->password = password_hash($password, PASSWORD_BCRYPT);
                    $user->save();
                }
            }
            
            if ($passwordValid) {
                $user->logtime = date('Y-m-d H:i:s');
                $user->logip = request()->ip();
                $user->is_online = 1;
                $user->last_heartbeat_time = time();
                $user->save();
                
                self::clearCache($user->id);
                
                return $user;
            }
        }
        
        return false;
    }
    
    /**
     * 根据ID获取用户信息（带缓存）
     *
     * @param int $id 用户ID
     * @return mixed|null 用户对象或null
     */
    public static function getUserById($id)
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $id;
        
        // 尝试从缓存获取
        $user = Cache::get($cacheKey);
        
        if (empty($user)) {
            // 缓存不存在，从数据库获取
            $user = self::find($id);
            
            // 存入缓存，有效期1小时
            if (!empty($user)) {
                Cache::set($cacheKey, $user, 3600);
            }
        }
        
        return $user;
    }
    
    /**
     * 根据用户名获取用户信息（带缓存）
     *
     * @param string $username 用户名
     * @return mixed|null 用户对象或null
     */
    public static function getUserByUsername($username)
    {
        $cacheKey = self::CACHE_KEY_USERNAME . md5($username);
        
        // 尝试从缓存获取
        $user = Cache::get($cacheKey);
        
        if (empty($user)) {
            // 缓存不存在，从数据库获取
            $user = self::where('username', $username)->find();
            
            // 存入缓存，有效期1小时
            if (!empty($user)) {
                Cache::set($cacheKey, $user, 3600);
            }
        }
        
        return $user;
    }
    
    /**
     * 获取用户头像URL
     *
     * @return string 用户头像URL
     */
    public function getAvatarUrl()
    {
        if (strpos($this->img, 'http') === 0) {
            return $this->img;
        } else {
            return '/old' . $this->img;
        }
    }
    
    /**
     * 清除用户缓存
     *
     * @param int $userId 用户ID
     * @return void
     */
    public static function clearCache($userId)
    {
        // 清除用户ID缓存
        Cache::delete(self::CACHE_KEY_PREFIX . $userId);
        
        // 获取用户信息，清除用户名缓存
        $user = self::find($userId);
        if (!empty($user)) {
            Cache::delete(self::CACHE_KEY_USERNAME . md5($user['username']));
        }
    }
    
    /**
     * 粉丝关联（别人关注我）
     */
    public function followers()
    {
        return $this->hasMany('Follow', 'following_id', 'id');
    }
    
    /**
     * 关注关联（我关注别人）
     */
    public function followings()
    {
        return $this->hasMany('Follow', 'follower_id', 'id');
    }
    
    /**
     * 动态关联
     */
    public function moments()
    {
        return $this->hasMany('Moment', 'user_id', 'id');
    }
    
    /**
     * 收藏关联
     */
    public function favorites()
    {
        return $this->hasMany('Favorite', 'user_id', 'id');
    }
    
    /**
     * 访问记录关联
     */
    public function visitors()
    {
        return $this->hasMany('CardVisitor', 'user_id', 'id');
    }
}
