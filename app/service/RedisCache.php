<?php

namespace app\service;

use think\facade\Cache;

class RedisCache
{
    private static $prefix = 'social_';
    private static $defaultExpire = 3600;

    public static function get($key, $default = null)
    {
        $cacheKey = self::$prefix . $key;
        return Cache::store('redis')->get($cacheKey, $default);
    }

    public static function set($key, $value, $expire = null)
    {
        $cacheKey = self::$prefix . $key;
        $expire = $expire ?? self::$defaultExpire;
        return Cache::store('redis')->set($cacheKey, $value, $expire);
    }

    public static function delete($key)
    {
        $cacheKey = self::$prefix . $key;
        return Cache::store('redis')->delete($cacheKey);
    }

    public static function has($key)
    {
        $cacheKey = self::$prefix . $key;
        return Cache::store('redis')->has($cacheKey);
    }

    public static function remember($key, $callback, $expire = null)
    {
        $cacheKey = self::$prefix . $key;
        $expire = $expire ?? self::$defaultExpire;
        return Cache::store('redis')->remember($cacheKey, $callback, $expire);
    }

    public static function increment($key, $step = 1)
    {
        $cacheKey = self::$prefix . $key;
        return Cache::store('redis')->inc($cacheKey, $step);
    }

    public static function decrement($key, $step = 1)
    {
        $cacheKey = self::$prefix . $key;
        return Cache::store('redis')->dec($cacheKey, $step);
    }

    public static function clear()
    {
        return Cache::store('redis')->clear();
    }

    public static function getMultiple($keys, $default = null)
    {
        $cacheKeys = array_map(function($key) {
            return self::$prefix . $key;
        }, $keys);
        
        $values = Cache::store('redis')->getMultiple($cacheKeys, $default);
        
        return array_combine($keys, $values);
    }

    public static function setMultiple($values, $expire = null)
    {
        $cacheValues = [];
        foreach ($values as $key => $value) {
            $cacheValues[self::$prefix . $key] = $value;
        }
        
        $expire = $expire ?? self::$defaultExpire;
        return Cache::store('redis')->setMultiple($cacheValues, $expire);
    }

    public static function deleteMultiple($keys)
    {
        $cacheKeys = array_map(function($key) {
            return self::$prefix . $key;
        }, $keys);
        
        return Cache::store('redis')->deleteMultiple($cacheKeys);
    }

    public static function getUserInfo($userId)
    {
        return self::remember("user:info:{$userId}", function() use ($userId) {
            return \app\model\User::where('id', $userId)->find();
        }, 1800);
    }

    public static function setUserInfo($userId, $userInfo)
    {
        return self::set("user:info:{$userId}", $userInfo, 1800);
    }

    public static function deleteUserInfo($userId)
    {
        return self::delete("user:info:{$userId}");
    }

    public static function getMomentList($params, $page = 1, $limit = 10)
    {
        $cacheKey = "moments:list:" . md5(serialize($params) . $page . $limit);
        return self::remember($cacheKey, function() use ($params, $page, $limit) {
            return \app\model\Moment::getMomentList($params, $page, $limit);
        }, 300);
    }

    public static function setMomentList($params, $page, $limit, $data)
    {
        $cacheKey = "moments:list:" . md5(serialize($params) . $page . $limit);
        return self::set($cacheKey, $data, 300);
    }

    public static function deleteMomentList($params = null)
    {
        if ($params === null) {
            return self::clear();
        }
        
        $pattern = "moments:list:" . md5(serialize($params) . '*');
        return self::clearByPattern($pattern);
    }

    public static function getMomentInfo($momentId)
    {
        return self::remember("moment:info:{$momentId}", function() use ($momentId) {
            return \app\model\Moment::where('id', $momentId)->find();
        }, 600);
    }

    public static function setMomentInfo($momentId, $momentInfo)
    {
        return self::set("moment:info:{$momentId}", $momentInfo, 600);
    }

    public static function deleteMomentInfo($momentId)
    {
        return self::delete("moment:info:{$momentId}");
    }

    public static function getCommentList($momentId, $page = 1, $limit = 20)
    {
        $cacheKey = "comments:list:{$momentId}:{$page}:{$limit}";
        return self::remember($cacheKey, function() use ($momentId, $page, $limit) {
            return \app\model\Comment::getCommentList($momentId, $page, $limit);
        }, 300);
    }

    public static function setCommentList($momentId, $page, $limit, $data)
    {
        $cacheKey = "comments:list:{$momentId}:{$page}:{$limit}";
        return self::set($cacheKey, $data, 300);
    }

    public static function deleteCommentList($momentId)
    {
        return self::clearByPattern("comments:list:{$momentId}:*");
    }

    public static function getUserFollowers($userId, $page = 1, $limit = 20)
    {
        $cacheKey = "followers:list:{$userId}:{$page}:{$limit}";
        return self::remember($cacheKey, function() use ($userId, $page, $limit) {
            return \app\model\Follow::getFollowers($userId, $page, $limit);
        }, 600);
    }

    public static function getUserFollowing($userId, $page = 1, $limit = 20)
    {
        $cacheKey = "following:list:{$userId}:{$page}:{$limit}";
        return self::remember($cacheKey, function() use ($userId, $page, $limit) {
            return \app\model\Follow::getFollowing($userId, $page, $limit);
        }, 600);
    }

    public static function deleteFollowCache($userId)
    {
        self::clearByPattern("followers:list:{$userId}:*");
        self::clearByPattern("following:list:{$userId}:*");
    }

    public static function getHotTopics($limit = 10)
    {
        return self::remember("topics:hot:{$limit}", function() use ($limit) {
            return \app\model\Topic::getHotTopics($limit);
        }, 3600);
    }

    public static function getTopicInfo($topicId)
    {
        return self::remember("topic:info:{$topicId}", function() use ($topicId) {
            return \app\model\Topic::where('id', $topicId)->find();
        }, 1800);
    }

    public static function deleteTopicCache($topicId = null)
    {
        if ($topicId) {
            self::delete("topic:info:{$topicId}");
        }
        self::clearByPattern("topics:hot:*");
    }

    public static function getSystemConfig($configKey)
    {
        return self::remember("config:{$configKey}", function() use ($configKey) {
            return \think\facade\Db::name('system_config')
                ->where('config_key', $configKey)
                ->value('config_value');
        }, 7200);
    }

    public static function setSystemConfig($configKey, $value)
    {
        self::set("config:{$configKey}", $value, 7200);
    }

    public static function deleteSystemConfig($configKey = null)
    {
        if ($configKey) {
            return self::delete("config:{$configKey}");
        }
        return self::clearByPattern("config:*");
    }

    public static function getOnlineUsers()
    {
        return self::remember("online:users", function() {
            return \app\model\User::where('is_online', 1)
                ->where('last_heartbeat_time', '>', time() - 300)
                ->column('id');
        }, 60);
    }

    public static function setOnlineUser($userId)
    {
        $cacheKey = "online:user:{$userId}";
        return self::set($cacheKey, time(), 300);
    }

    public static function isUserOnline($userId)
    {
        return self::has("online:user:{$userId}");
    }

    public static function getLikeStatus($userId, $targetType, $targetId)
    {
        $cacheKey = "like:status:{$userId}:{$targetType}:{$targetId}";
        return self::remember($cacheKey, function() use ($userId, $targetType, $targetId) {
            return \app\model\Like::where('user_id', $userId)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->find();
        }, 3600);
    }

    public static function setLikeStatus($userId, $targetType, $targetId, $status)
    {
        $cacheKey = "like:status:{$userId}:{$targetType}:{$targetId}";
        return self::set($cacheKey, $status, 3600);
    }

    public static function deleteLikeStatus($userId, $targetType, $targetId)
    {
        $cacheKey = "like:status:{$userId}:{$targetType}:{$targetId}";
        return self::delete($cacheKey);
    }

    public static function getFollowStatus($followerId, $followingId)
    {
        $cacheKey = "follow:status:{$followerId}:{$followingId}";
        return self::remember($cacheKey, function() use ($followerId, $followingId) {
            return \app\model\Follow::where('follower_id', $followerId)
                ->where('following_id', $followingId)
                ->where('status', 1)
                ->find();
        }, 3600);
    }

    public static function setFollowStatus($followerId, $followingId, $status)
    {
        $cacheKey = "follow:status:{$followerId}:{$followingId}";
        return self::set($cacheKey, $status, 3600);
    }

    public static function deleteFollowStatus($followerId, $followingId)
    {
        $cacheKey = "follow:status:{$followerId}:{$followingId}";
        return self::delete($cacheKey);
    }

    private static function clearByPattern($pattern)
    {
        $redis = Cache::store('redis')->handler();
        $keys = $redis->keys(self::$prefix . $pattern);
        
        if (!empty($keys)) {
            return $redis->del($keys);
        }
        
        return true;
    }

    public static function getStats($key)
    {
        return self::remember("stats:{$key}", function() use ($key) {
            return 0;
        }, 86400);
    }

    public static function incrementStats($key, $step = 1)
    {
        return self::increment("stats:{$key}", $step);
    }

    public static function decrementStats($key, $step = 1)
    {
        return self::decrement("stats:{$key}", $step);
    }

    public static function getRateLimit($identifier, $limit = 60, $window = 60)
    {
        $cacheKey = "ratelimit:{$identifier}";
        $current = self::get($cacheKey, 0);
        
        return [
            'limit' => $limit,
            'remaining' => max(0, $limit - $current),
            'reset' => time() + $window
        ];
    }

    public static function checkRateLimit($identifier, $limit = 60, $window = 60)
    {
        $cacheKey = "ratelimit:{$identifier}";
        $current = self::get($cacheKey, 0);
        
        if ($current >= $limit) {
            return false;
        }
        
        self::increment($cacheKey);
        
        if ($current === 0) {
            self::set($cacheKey, 1, $window);
        }
        
        return true;
    }

    public static function getSearchResults($keyword, $type, $page = 1, $limit = 20)
    {
        $cacheKey = "search:{$type}:" . md5($keyword . $page . $limit);
        return self::remember($cacheKey, function() use ($keyword, $type, $page, $limit) {
            return \app\model\Search::search($keyword, $type, $page, $limit);
        }, 600);
    }

    public static function setSearchResults($keyword, $type, $page, $limit, $data)
    {
        $cacheKey = "search:{$type}:" . md5($keyword . $page . $limit);
        return self::set($cacheKey, $data, 600);
    }

    public static function deleteSearchCache($keyword = null)
    {
        if ($keyword) {
            return self::clearByPattern("search:*:" . md5($keyword) . "*");
        }
        return self::clearByPattern("search:*");
    }
}
