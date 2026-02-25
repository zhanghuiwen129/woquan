<?php
namespace app\service;

use think\facade\Db;
use think\facade\Session;

/**
 * 动态服务类
 */
class MomentsService
{
    /**
     * 获取动态列表
     * @param array $params 查询参数
     * @param int|null $currentUserId 当前用户ID
     * @return array
     */
    public static function getMomentsList($params = [], $currentUserId = null)
    {
        $type = $params['type'] ?? 0;
        $sort = $params['sort'] ?? 'time';
        $page = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 10;
        $offset = ($page - 1) * $limit;
        
        // 使用ThinkPHP的Db类查询动态列表
        $query = Db::name('moments')
            ->field('id, user_id, nickname, avatar, content, images, videos, location, likes, comments, create_time, privacy, is_anonymous, type as moment_type, is_top')
            ->where('status', 1);
        
        // 根据类型筛选
        if ($type > 0) {
            $query->where('type', $type);
        }
        
        // 只显示当前用户可见的动态
        // 隐私设置: 1-公开, 2-私密, 3-仅好友, 4-部分可见
        if ($currentUserId) {
            // 获取当前用户关注的好友列表
            $followingIds = Db::name('follows')
                ->where('follower_id', $currentUserId)
                ->where('status', 1)
                ->column('following_id');

            // 可见的动态包括：公开的、自己的、关注的人的(仅好友可见)
            $query->where(function($q) use ($currentUserId, $followingIds) {
                $q->where('privacy', 1) // 公开
                  ->whereOr('user_id', $currentUserId) // 自己的(包括私密)
                  ->whereOr(function($subQ) use ($currentUserId, $followingIds) {
                      $subQ->where('privacy', 3) // 仅好友可见
                           ->whereIn('user_id', $followingIds);
                  });
            });
        } else {
            // 未登录用户只能查看公开动态
            $query->where('privacy', 1);
        }
        
        // 排序
        if ($sort === 'hot') {
            $query->order('likes desc, comments desc, create_time desc');
        } else {
            $query->order('is_top desc, create_time desc');
        }
        
        // 计算总记录数
        $totalCount = $query->count();
        
        // 分页
        $moments = $query->limit($offset, $limit)->select();
        
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
            'total' => $totalCount,
            'page' => $page,
            'limit' => $limit
        ];
    }
    
    /**
     * 获取动态详情
     * @param int $momentId 动态ID
     * @param int|null $currentUserId 当前用户ID
     * @return array|null
     */
    public static function getMomentDetail($momentId, $currentUserId = null)
    {
        // 查询动态详情
        $moment = Db::name('moments')
            ->field('id, user_id, nickname, avatar, content, images, videos, location, likes, comments, create_time, privacy, is_anonymous, type as moment_type')
            ->where('id', $momentId)
            ->where('status', 1)
            ->find();
        
        if (!$moment) {
            return null;
        }
        
        // 检查动态是否对当前用户可见
        if ($currentUserId) {
            // 公开动态、自己的动态或好友的仅好友可见动态
            if ($moment['privacy'] != 1 && $moment['user_id'] != $currentUserId) {
                $isFriend = Db::name('follows')
                    ->where('follower_id', $currentUserId)
                    ->where('following_id', $moment['user_id'])
                    ->where('status', 1)
                    ->find();
                
                if (!$isFriend || $moment['privacy'] != 3) {
                    return null;
                }
            }
        } else {
            // 未登录用户只能查看公开动态
            if ($moment['privacy'] != 1) {
                return null;
            }
        }
        
        // 检查当前用户是否点赞
        if ($currentUserId) {
            $isLiked = Db::name('likes')
                ->where('user_id', $currentUserId)
                ->where('target_id', $momentId)
                ->where('target_type', 1)
                ->find();
            $moment['is_liked'] = $isLiked ? 1 : 0;
        }
        
        // 获取动态评论
        $comments = Db::name('comments')
            ->where('moment_id', $momentId)
            ->where('status', 1)
            ->order('is_top desc, create_time asc')
            ->select();
        
        $moment['comments'] = $comments;
        
        return $moment;
    }
    
    /**
     * 获取热门动态
     * @param array $params 查询参数
     * @param int|null $currentUserId 当前用户ID
     * @return array
     */
    public static function getHotMoments($params = [], $currentUserId = null)
    {
        $page = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 10;
        $offset = ($page - 1) * $limit;
        
        // 获取用户的好友列表
        $friends = [];
        if ($currentUserId) {
            $friends = Db::name('follows')
                ->where('user_id', $currentUserId)
                ->where('status', 1)
                ->column('friend_id');
        }
        
        // 查询热门动态（按点赞数和评论数排序）
        $query = Db::name('moments')
            ->where('status', 1)
            ->where(function($query) use ($currentUserId, $friends) {
                // 只显示公开的、好友的或自己的动态
                $query->where('privacy', 1) // 公开动态
                      ->whereOr('user_id', $currentUserId) // 自己的动态
                      ->whereOr(function($query) use ($friends) {
                          $query->where('privacy', 3) // 仅好友可见
                                ->whereIn('user_id', $friends);
                      });
            })
            ->orderRaw('(likes * 2 + comments) desc') // 综合热度排序
            ->order('create_time', 'desc');
        
        // 计算总记录数
        $total = $query->count();
        
        // 获取分页数据
        $moments = $query->limit($offset, $limit)->select();
        
        // 处理动态数据
        if (!empty($moments)) {
            foreach ($moments as &$moment) {
                // 检查当前用户是否已点赞
                if ($currentUserId) {
                    $isLiked = Db::name('likes')
                        ->where('user_id', $currentUserId)
                        ->where('target_id', $moment['id'])
                        ->where('target_type', 1)
                        ->find();
                    $moment['is_liked'] = $isLiked ? 1 : 0;

                    // 检查当前用户是否已收藏
                    $isCollected = Db::name('collections')
                        ->where([
                            ['moment_id', '=', $moment['id']],
                            ['user_id', '=', $currentUserId]
                        ])->find();
                    $moment['is_collected'] = $isCollected ? 1 : 0;
                } else {
                    $moment['is_liked'] = 0;
                    $moment['is_collected'] = 0;
                }

                // 格式化时间
                $moment['publish_time'] = $moment['create_time'];
            }
        }
        
        return [
            'list' => $moments,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ];
    }
}
