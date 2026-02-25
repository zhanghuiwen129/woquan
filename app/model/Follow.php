<?php

namespace app\model;

use think\Model;

class Follow extends Model
{
    protected $name = 'follows';
    protected $autoWriteTimestamp = false;
    
    // 获取推荐关注用户
    public static function getRecommendedUsers($currentUserId = null, $limit = 10)
    {
        $query = self::field('user.*')
            ->join('user', 'user.id = follows.following_id')
            ->group('user.id')
            ->orderRaw('count(follows.id) desc') // 按被关注次数排序
            ->limit($limit);
        
        // 如果有当前用户ID，排除已关注的用户
        if ($currentUserId) {
            $followingIds = self::where('follower_id', $currentUserId)
                ->column('following_id');
            
            if (!empty($followingIds)) {
                $query->where('user.id', 'not in', $followingIds);
            }
            
            // 排除当前用户自己
            $query->where('user.id', '<>', $currentUserId);
        }
        
        return $query->select()->toArray();
    }
}
