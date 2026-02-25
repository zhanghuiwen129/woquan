<?php
namespace app\service;

use think\facade\Db;
use think\facade\Session;

/**
 * 用户服务类
 */
class UserService
{
    /**
     * 获取用户信息
     * @param int $userId 用户ID
     * @param int|null $currentUserId 当前用户ID
     * @return array|null
     */
    public static function getUserInfo($userId, $currentUserId = null)
    {
        // 获取用户基本信息
        $user = Db::name('user')
            ->where('id', $userId)
            ->find();
        
        if (!$user) {
            return null;
        }
        
        // 获取用户统计信息
        $followingCount = Db::name('user_followers')
            ->where('user_id', $userId)
            ->count();
        
        $followerCount = Db::name('user_followers')
            ->where('follower_id', $userId)
            ->count();
        
        $momentsCount = Db::name('moments')
            ->where('user_id', $userId)
            ->count();
        
        // 检查当前用户是否关注该用户
        $isFollowing = false;
        if ($currentUserId && $currentUserId != $userId) {
            $isFollowing = Db::name('user_followers')
                ->where('user_id', $userId)
                ->where('follower_id', $currentUserId)
                ->find() ? true : false;
        }
        
        return [
            'user' => $user,
            'followingCount' => $followingCount,
            'followerCount' => $followerCount,
            'momentsCount' => $momentsCount,
            'isFollowing' => $isFollowing
        ];
    }
    
    /**
     * 获取用户关注列表
     * @param int $userId 用户ID
     * @param int|null $currentUserId 当前用户ID
     * @return array
     */
    public static function getUserFollowingList($userId, $currentUserId = null)
    {
        // 获取用户关注列表
        $following = Db::name('user_followers')
            ->alias('uf')
            ->join('user u', 'uf.follower_id = u.id')
            ->where('uf.user_id', $userId)
            ->field('u.id, u.username, u.nickname, u.avatar, u.bio')
            ->order('uf.create_time', 'desc')
            ->select();
        
        // 检查当前用户是否关注了这些用户
        if ($currentUserId) {
            // 获取当前用户关注的所有用户ID
            $myFollowingIds = Db::name('user_followers')
                ->where('user_id', $currentUserId)
                ->column('follower_id');
            
            // 为每个用户设置关注状态
            foreach ($following as &$user) {
                $user['is_following'] = in_array($user['id'], $myFollowingIds) ? 1 : 0;
            }
        } else {
            // 未登录用户，默认未关注
            foreach ($following as &$user) {
                $user['is_following'] = 0;
            }
        }
        
        return $following;
    }
    
    /**
     * 获取用户粉丝列表
     * @param int $userId 用户ID
     * @param int|null $currentUserId 当前用户ID
     * @return array
     */
    public static function getUserFollowersList($userId, $currentUserId = null)
    {
        // 获取用户粉丝列表
        $followers = Db::name('user_followers')
            ->alias('uf')
            ->join('user u', 'uf.user_id = u.id')
            ->where('uf.follower_id', $userId)
            ->field('u.id, u.username, u.nickname, u.avatar, u.bio')
            ->order('uf.create_time', 'desc')
            ->select();
        
        // 检查当前用户是否关注了这些用户
        if ($currentUserId) {
            // 获取当前用户关注的所有用户ID
            $myFollowingIds = Db::name('user_followers')
                ->where('user_id', $currentUserId)
                ->column('follower_id');
            
            // 为每个用户设置关注状态
            foreach ($followers as &$user) {
                $user['is_following'] = in_array($user['id'], $myFollowingIds) ? 1 : 0;
            }
        } else {
            // 未登录用户，默认未关注
            foreach ($followers as &$user) {
                $user['is_following'] = 0;
            }
        }
        
        return $followers;
    }
    
    /**
     * 获取用户动态列表
     * @param int $userId 用户ID
     * @param int|null $currentUserId 当前用户ID
     * @param array $params 查询参数
     * @return array
     */
    public static function getUserMomentsList($userId, $currentUserId = null, $params = [])
    {
        $page = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 10;
        $offset = ($page - 1) * $limit;
        
        // 获取用户动态列表
        $momentsQuery = Db::name('moments')
            ->field('id, user_id, nickname, avatar, content, images, videos, location, likes, comments, create_time, privacy, is_anonymous, type as moment_type')
            ->where('user_id', $userId)
            ->where('status', 1);
        
        // 检查动态是否对当前用户可见
        if ($currentUserId && $currentUserId != $userId) {
            // 公开动态或好友的仅好友可见动态
            $isFriend = Db::name('user_followers')
                ->where('user_id', $userId)
                ->where('follower_id', $currentUserId)
                ->find();
            
            if ($isFriend) {
                // 好友可以查看公开和仅好友可见的动态
                $momentsQuery->where(function($query) {
                    $query->where('privacy', 1) // 公开
                          ->whereOr('privacy', 3); // 仅好友可见
                });
            } else {
                // 非好友只能查看公开动态
                $momentsQuery->where('privacy', 1);
            }
        }
        
        // 计算总记录数
        $total = $momentsQuery->count();
        
        // 获取分页数据
        $moments = $momentsQuery
            ->order('create_time', 'desc')
            ->limit($offset, $limit)
            ->select();
        
        // 检查当前用户对每条动态的点赞状态
        if ($currentUserId) {
            foreach ($moments as &$moment) {
                // 检查是否点赞
                $isLiked = Db::name('likes')
                    ->where('user_id', $currentUserId)
                    ->where('target_id', $moment['id'])
                    ->where('target_type', 1)
                    ->find();
                $moment['is_liked'] = $isLiked ? 1 : 0;
            }
        }
        
        return [
            'list' => $moments,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ];
    }
    
    /**
     * 获取推荐关注用户
     * @param int|null $currentUserId 当前用户ID
     * @param int $limit 数量限制
     * @return array
     */
    public static function getRecommendedUsers($currentUserId = null, $limit = 10)
    {
        // 查询推荐用户（根据粉丝数和动态数排序）
        $query = Db::name('user')
            ->field('id, username, nickname, avatar, bio, follower_count')
            ->where('status', 1);
        
        // 排除当前用户
        if ($currentUserId) {
            $query->where('id', '<>', $currentUserId);
            
            // 排除已关注的用户
            $followingIds = Db::name('user_followers')
                ->where('user_id', $currentUserId)
                ->column('follower_id');
            
            if (!empty($followingIds)) {
                $query->where('id', 'NOT IN', $followingIds);
            }
        }
        
        // 根据粉丝数排序
        $users = $query
            ->order('follower_count', 'desc')
            ->limit($limit)
            ->select();
        
        return $users;
    }
    
    /**
     * 关注用户
     * @param int $userId 被关注用户ID
     * @param int $followerId 关注者ID
     * @return bool
     */
    public static function followUser($userId, $followerId)
    {
        // 检查是否已经关注
        $isFollowing = Db::name('user_followers')
            ->where('user_id', $userId)
            ->where('follower_id', $followerId)
            ->find();
        
        if ($isFollowing) {
            return true; // 已经关注，直接返回成功
        }
        
        // 开始事务
        Db::startTrans();
        
        try {
            // 插入关注记录
            Db::name('user_followers')->insert([
                'user_id' => $userId,
                'follower_id' => $followerId,
                'create_time' => time()
            ]);
            
            // 更新用户粉丝数
            Db::name('user')
                ->where('id', $userId)
                ->inc('follower_count')
                ->update();
            
            // 更新关注者的关注数
            Db::name('user')
                ->where('id', $followerId)
                ->inc('following_count')
                ->update();
            
            // 提交事务
            Db::commit();
            
            return true;
        } catch (Exception $e) {
            // 回滚事务
            Db::rollback();
            
            return false;
        }
    }
    
    /**
     * 取消关注用户
     * @param int $userId 被关注用户ID
     * @param int $followerId 关注者ID
     * @return bool
     */
    public static function unfollowUser($userId, $followerId)
    {
        // 检查是否已经关注
        $isFollowing = Db::name('user_followers')
            ->where('user_id', $userId)
            ->where('follower_id', $followerId)
            ->find();
        
        if (!$isFollowing) {
            return true; // 未关注，直接返回成功
        }
        
        // 开始事务
        Db::startTrans();
        
        try {
            // 删除关注记录
            Db::name('user_followers')
                ->where('user_id', $userId)
                ->where('follower_id', $followerId)
                ->delete();
            
            // 更新用户粉丝数
            Db::name('user')
                ->where('id', $userId)
                ->dec('follower_count')
                ->update();
            
            // 更新关注者的关注数
            Db::name('user')
                ->where('id', $followerId)
                ->dec('following_count')
                ->update();
            
            // 提交事务
            Db::commit();
            
            return true;
        } catch (Exception $e) {
            // 回滚事务
            Db::rollback();
            
            return false;
        }
    }
}
