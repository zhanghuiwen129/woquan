<?php
namespace app\service;

use think\facade\Db;
use think\facade\Session;

/**
 * 话题服务类
 */
class TopicService
{
    /**
     * 获取热门话题列表
     * @param array $params 查询参数
     * @param int|null $currentUserId 当前用户ID
     * @return array
     */
    public static function getHotTopics($params = [], $currentUserId = null)
    {
        $page = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 10;
        $offset = ($page - 1) * $limit;
        
        // 查询热门话题
        $topicsQuery = Db::name('topics')
            ->field('id, name, description, cover as image, post_count as posts_count, follower_count as followers_count, create_time, status')
            ->where('status', 1) // 只显示已发布的话题
            ->order('follower_count', 'desc')
            ->order('post_count', 'desc')
            ->limit($offset, $limit);
        
        $topics = $topicsQuery->select();
        
        // 计算总记录数
        $total = Db::name('topics')
            ->where('status', 1)
            ->count();
        
        // 检查当前用户是否关注了这些话题
        if ($currentUserId) {
            // 获取用户关注的所有话题ID
            $followedTopicIds = Db::name('topic_follows')
                ->where('user_id', $currentUserId)
                ->column('topic_id');
            
            // 为每个话题设置关注状态
            foreach ($topics as &$topic) {
                $topic['is_following'] = in_array($topic['id'], $followedTopicIds) ? 1 : 0;
            }
        } else {
            // 未登录用户，默认未关注
            foreach ($topics as &$topic) {
                $topic['is_following'] = 0;
            }
        }
        
        return [
            'list' => $topics,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'has_more' => ($offset + $limit) < $total
        ];
    }
    
    /**
     * 获取话题详情
     * @param int $topicId 话题ID
     * @param int|null $currentUserId 当前用户ID
     * @return array|null
     */
    public static function getTopicDetail($topicId, $currentUserId = null)
    {
        // 获取话题详情
        $topic = Db::name('topics')
            ->where('id', $topicId)
            ->where('status', 1)
            ->find();
        
        if (!$topic) {
            return null;
        }
        
        // 获取话题粉丝数
        $followerCount = Db::name('topic_follows')
            ->where('topic_id', $topicId)
            ->count();

        // 检查当前用户是否关注该话题
        $isFollowing = false;
        if ($currentUserId) {
            $isFollowing = Db::name('topic_follows')
                ->where('topic_id', $topicId)
                ->where('user_id', $currentUserId)
                ->find() ? true : false;
        }
        
        // 获取话题相关动态
        $moments = Db::name('moments')
            ->where('topic_id', $topicId)
            ->where('status', 1)
            ->order('create_time', 'desc')
            ->limit(10)
            ->select();
        
        return [
            'topic' => $topic,
            'moments' => $moments,
            'followerCount' => $followerCount,
            'isFollowing' => $isFollowing
        ];
    }
    
    /**
     * 关注话题
     * @param int $topicId 话题ID
     * @param int $userId 用户ID
     * @return bool
     */
    public static function followTopic($topicId, $userId)
    {
        // 检查是否已经关注
        $isFollowing = Db::name('topic_follows')
            ->where('topic_id', $topicId)
            ->where('user_id', $userId)
            ->find();
        
        if ($isFollowing) {
            return true; // 已经关注，直接返回成功
        }
        
        // 开始事务
        Db::startTrans();
        
        try {
            // 插入关注记录
            Db::name('topic_follows')->insert([
                'topic_id' => $topicId,
                'user_id' => $userId,
                'create_time' => time()
            ]);
            
            // 更新话题粉丝数
            Db::name('topics')
                ->where('id', $topicId)
                ->inc('follower_count')
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
     * 取消关注话题
     * @param int $topicId 话题ID
     * @param int $userId 用户ID
     * @return bool
     */
    public static function unfollowTopic($topicId, $userId)
    {
        // 检查是否已经关注
        $isFollowing = Db::name('topic_follows')
            ->where('topic_id', $topicId)
            ->where('user_id', $userId)
            ->find();
        
        if (!$isFollowing) {
            return true; // 未关注，直接返回成功
        }
        
        // 开始事务
        Db::startTrans();
        
        try {
            // 删除关注记录
            Db::name('topic_follows')
                ->where('topic_id', $topicId)
                ->where('user_id', $userId)
                ->delete();
            
            // 更新话题粉丝数
            Db::name('topics')
                ->where('id', $topicId)
                ->dec('follower_count')
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
