<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;
use think\facade\Request;

class Topics extends BaseFrontendController
{
    /**
     * 话题详情页面
     */
    public function index($id = null)
    {
        // 获取话题ID，支持路由参数和查询参数
        if (empty($id)) {
            $id = input('id');
        }

        if (empty($id)) {
            return $this->redirect('/');
        }

        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        // 获取话题详情
        $topic = Db::name('topics')
            ->where('id', $id)
            ->where('status', 1)
            ->find();
        
        if (!$topic) {
            return redirect('/404');
        }
        
        // 获取话题相关动态
        $moments = Db::name('moment_topics')
            ->alias('mt')
            ->join('moments m', 'mt.moment_id = m.id')
            ->field('m.id, m.user_id, m.nickname, m.avatar, m.content, m.images, m.location, m.likes, m.comments, m.create_time, m.visibility')
            ->where('mt.topic_id', $id)
            ->where('m.status', 1)
            ->order('m.create_time', 'desc')
            ->limit(10)
            ->select();
        
        // 获取话题粉丝数
        $followerCount = Db::name('topic_follows')
            ->where('topic_id', $id)
            ->count();
        
        // 检查当前用户是否关注该话题
        $isFollowing = false;
        if (!empty($userId)) {
            $isFollowing = Db::name('topic_follows')
                ->where('topic_id', $id)
                ->where('user_id', $userId)
                ->find() ? true : false;
        }

        // 配置信息已在基类中加载
        $config = View::get('config', []);

        // 分配模板变量
        View::assign([
            'topic' => $topic,
            'moments' => $moments,
            'followerCount' => $followerCount,
            'isFollowing' => $isFollowing,
            'currentUser' => $currentUser,
            'isLogin' => !empty($userId)
        ]);
        
        // 渲染模板
        return View::fetch('index/topic');
    }
    
    /**
     * 获取热门话题列表
     */
    public function getHotTopics()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $page = input('page', 1);
            $page = max(1, intval($page));
            $limit = input('limit', 10);
            $limit = max(1, min(50, intval($limit)));
            $offset = ($page - 1) * $limit;

            // 查询热门话题
            $topicsQuery = Db::name('topics')
                ->field('id, name, description, cover as image, post_count as posts_count, follower_count as followers_count, create_time, status')
                ->where('status', 1)
                ->order('post_count', 'desc')
                  ->order('follower_count', 'desc');

            // 获取总数
            $total = $topicsQuery->count();

            // 获取分页数据
            $topics = $topicsQuery->limit($offset, $limit)->select();

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;

            // 如果用户已登录，获取用户对话题的关注状态
            if ($currentUserId && !empty($topics)) {
                $topicIds = array_column($topics, 'id');
                $followedTopicIds = Db::name('topic_follows')
                    ->where('user_id', $currentUserId)
                    ->where('topic_id', 'in', $topicIds)
                    ->column('topic_id');

                foreach ($topics as &$topic) {
                    $topic['is_following'] = in_array($topic['id'], $followedTopicIds) ? 1 : 0;
                }
            } else {
                // 未登录用户或无话题，设置默认关注状态
                foreach ($topics as &$topic) {
                    $topic['is_following'] = 0;
                }
            }

            return $this->success([
                'list' => $topics,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'has_more' => ($offset + $limit) < $total
            ]);
        } catch (\Exception $e) {
            return $this->error('获取热门话题失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取话题详情
     */
    public function getTopicDetail()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $topicId = input('topic_id') ?: input('id');

            if (!$topicId) {
                return json(['code' => 400, 'msg' => '话题ID不能为空']);
            }

            // 获取话题详情
            $topic = Db::name('topics')
                ->field('id, name, description, cover as image, post_count as posts_count, follower_count as followers_count, create_time, status')
                ->where('id', $topicId)
                ->where('status', 1)
                ->find();

            if (!$topic) {
                return json(['code' => 404, 'msg' => '话题不存在或已被删除']);
            }

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;

            // 如果用户已登录，获取用户对话题的关注状态
            if ($currentUserId) {
                $isFollowing = Db::name('topic_follows')
                    ->where('user_id', $currentUserId)
                    ->where('topic_id', $topicId)
                    ->find() ? 1 : 0;
                $topic['is_following'] = $isFollowing;
            } else {
                $topic['is_following'] = 0;
            }

            return $this->success($topic);
        } catch (\Exception $e) {
            return $this->error('获取话题详情失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取话题相关动态
     */
    public function getTopicMoments()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $topicId = input('topic_id') ?: input('id');
            $page = input('page', 1);
            $page = max(1, intval($page));
            $limit = input('limit', 10);
            $limit = max(1, min(50, intval($limit)));
            $offset = ($page - 1) * $limit;

            if (!$topicId) {
                return json(['code' => 400, 'msg' => '话题ID不能为空']);
            }

            // 检查话题是否存在
            $topic = Db::name('topics')
                ->where('id', $topicId)
                ->where('status', 1)
                ->find();

            if (!$topic) {
                return json(['code' => 404, 'msg' => '话题不存在或已被删除']);
            }

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;

            // 查询话题相关动态
            $momentsQuery = Db::name('moment_topics')
                ->alias('mt')
                ->join('moments m', 'mt.moment_id = m.id')
                ->field('m.id, m.user_id, m.nickname, m.avatar, m.content, m.images, m.location, m.likes, m.comments, m.create_time, m.visibility')
                ->where('mt.topic_id', $topicId)
                ->where('m.status', 1)
                ->where(function($query) use ($currentUserId) {
                    if ($currentUserId) {
                        // 已登录用户可以看到公开动态和自己的动态
                        $query->where('m.visibility', 1)
                            ->whereOr('m.user_id', $currentUserId);
                    } else {
                        // 未登录用户只能看到公开动态
                        $query->where('m.visibility', 1);
                    }
                })
                ->order('m.create_time', 'desc');

            // 获取总数
            $total = $momentsQuery->count();

            // 获取分页数据
            $moments = $momentsQuery->limit($offset, $limit)->select();

            // 如果当前用户已登录，获取用户对动态的点赞状态
            if ($currentUserId && !empty($moments)) {
                $momentIds = array_column($moments, 'id');
                $likedMomentIds = Db::name('likes')
                    ->where('user_id', $currentUserId)
                    ->where('target_id', 'in', $momentIds)
                    ->where('target_type', 1)
                    ->column('target_id');

                foreach ($moments as &$moment) {
                    $moment['is_liked'] = in_array($moment['id'], $likedMomentIds) ? 1 : 0;
                }
            } else {
                // 未登录用户或无动态，设置默认点赞状态
                foreach ($moments as &$moment) {
                    $moment['is_liked'] = 0;
                }
            }

            return $this->success([
                'topic' => $topic,
                'list' => $moments,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'has_more' => ($offset + $limit) < $total
            ]);
        } catch (\Exception $e) {
            return $this->error('获取话题动态失败: ' . $e->getMessage());
        }
    }

    /**
     * 关注话题
     */
    public function followTopic()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 检查话题功能是否开启
            $topicEnabled = Db::name('system_config')->where('config_key', 'topic_enabled')->value('config_value') ?? '1';
            if ($topicEnabled != '1') {
                return json(['code' => 403, 'msg' => '话题功能已关闭']);
            }

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;

            if (!$currentUserId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $topicId = input('topic_id') ?: input('id');

            if (!$topicId) {
                return json(['code' => 400, 'msg' => '话题ID不能为空']);
            }

            // 检查话题是否存在
            $topic = Db::name('topics')
                ->where('id', $topicId)
                ->where('status', 1)
                ->find();

            if (!$topic) {
                return json(['code' => 404, 'msg' => '话题不存在或已被删除']);
            }

            // 检查是否已经关注
            $isFollowing = Db::name('topic_follows')
                ->where('user_id', $currentUserId)
                ->where('topic_id', $topicId)
                ->find();

            if ($isFollowing) {
                return json(['code' => 400, 'msg' => '已经关注该话题']);
            }

            // 开始事务
            Db::startTrans();

            try {
                // 添加关注记录
                Db::name('topic_follows')->insert([
                    'user_id' => $currentUserId,
                    'topic_id' => $topicId,
                    'create_time' => time()
                ]);

                // 更新话题关注数
                Db::name('topics')
                    ->where('id', $topicId)
                    ->inc('follower_count', 1)
                    ->update();

                // 提交事务
                Db::commit();

                return $this->success('关注话题成功');
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->error('关注话题失败: ' . $e->getMessage());
        }
    }

    /**
     * 取消关注话题
     */
    public function unfollowTopic()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;

            if (!$currentUserId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $topicId = input('topic_id') ?: input('id');

            if (!$topicId) {
                return json(['code' => 400, 'msg' => '话题ID不能为空']);
            }

            // 检查是否已经关注
            $isFollowing = Db::name('topic_follows')
                ->where('user_id', $currentUserId)
                ->where('topic_id', $topicId)
                ->find();

            if (!$isFollowing) {
                return json(['code' => 400, 'msg' => '未关注该话题']);
            }

            // 开始事务
            Db::startTrans();

            try {
                // 删除关注记录
                Db::name('topic_follows')
                    ->where('user_id', $currentUserId)
                    ->where('topic_id', $topicId)
                    ->delete();

                // 更新话题关注数
                Db::name('topics')
                    ->where('id', $topicId)
                    ->dec('follower_count', 1)
                    ->update();

                // 提交事务
                Db::commit();

                return json(['code' => 200, 'msg' => '取消关注话题成功']);
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                throw $e;
            }
        } catch (\Excep$thii->su)cs(
            header('Content-Type: application/json; charset=utf-8');
            echo'code' => 500,
                'msg' => '取消关注话题失败: ' . $e->getMessage()
            ]);
            exit;
        }urn$h>rrr的
    public function getUserFollowedTopics()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;
            $targetUserId = input('user_id');

            // 确定要查询的用户ID
            $userId = $targetUserId ? intval($targetUserId) : $currentUserId;

            if (!$userId) {
                return json(['code' => 400, 'msg' => '用户ID不能为空']);
            }

            if (!$currentUserId && !$targetUserId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $page = input('page', 1);
            $page = max(1, intval($page));
            $limit = input('limit', 10);
            $limit = max(1, min(50, intval($limit)));
            $offset = ($page - 1) * $limit;

            // 查询用户关注的话题
            $topicsQuery = Db::name('topic_follows')
                ->alias('tf')
                ->join('topics t', 'tf.topic_id = t.id')
                ->field('t.id, t.name, t.description, t.cover as image, t.post_count as posts_count, t.follower_count as followers_count, t.create_time, t.status')
                ->where('tf.user_id', $userId)
                ->where('t.status', 1)
                ->order('tf.create_time', 'desc');

            // 获取总数
            $total = $topicsQuery->count();

            // 获取分页数据
            $topics = $topicsQuery->limit($offset, $limit)->select();

            // 如果当前用户已登录，获取用户对话题的关注状态
            if ($currentUserId && !empty($topics)) {
                $topicIds = array_column($topics, 'id');
                $followedTopicIds = Db::name('topic_follows')
                    ->where('user_id', $currentUserId)
                    ->where('topic_id', 'in', $topicIds)
                    ->column('topic_id');

                foreach ($topics as &$topic) {
                    $topic['is_following'] = in_array($topic['id'], $followedTopicIds) ? 1 : 0;
                }
            } else {
                // 未登录用户或无话题，设置默认关注状态
                foreach ($topics as &$topic) {
                    $topic['is_following'] = 0;
                }
            }

            return $this->success([
                'list' => $topics,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'has_more' => ($offset + $limit) < $total
            ]);
        } catch (\Exception $e) {
            return $this->error('获取关注话题列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 创建话题
     */
    public function createTopic()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;
            if (!$currentUserId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $name = input('name', '');
            $description = input('description', '');
            $cover = input('cover', '');

            if (empty($name)) {
                return json(['code' => 400, 'msg' => '话题名称不能为空']);
            }

            if (strlen($name) > 50) {
                return json(['code' => 400, 'msg' => '话题名称不能超过50个字符']);
            }

            if (strlen($description) > 200) {
                return json(['code' => 400, 'msg' => '话题描述不能超过200个字符']);
            }

            // 检查话题是否已存在
            $existingTopic = Db::name('topics')
                ->where('name', $name)
                ->where('status', 1)
                ->find();

            if ($existingTopic) {
                return json(['code' => 400, 'msg' => '该话题已存在']);
            }

            // 创建话题
            $topicId = Db::name('topics')->insertGetId([
                'name' => $name,
                'description' => $description,
                'cover' => $cover,
                'post_count' => 0,
                'follower_count' => 0,
                'is_hot' => 0,
                'sort' => 0,
                'create_time' => time(),
                'status' => 1
            ]);

            // 默认关注该话题
            Db::name('topic_follows')->insert([
                'user_id' => $currentUserId,
                'topic_id' => $topicId,
                'create_time' => time()
            ]);

            // 更新话题关注数
            Db::name('topics')->where('id', $topicId)->setInc('follower_count');

            return $this->success(['topic_id' => $topicId], '创建话题成功');
        } catch (\Exception $e) {
            return $this->error('创建话题失败: ' . $e->getMessage());
        }
    }

    /**
     * 关联动态与话题
     */
    public function associateMomentWithTopic()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;
            if (!$currentUserId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $momentId = input('moment_id');
            $topicIds = input('topic_ids');

            if (empty($momentId)) {
                return json(['code' => 400, 'msg' => '动态ID不能为空']);
            }

            if (empty($topicIds)) {
                return json(['code' => 400, 'msg' => '话题ID不能为空']);
            }

            // 检查动态是否存在且属于当前用户
            $moment = Db::name('moments')
                ->where('id', $momentId)
                ->where('user_id', $currentUserId)
                ->where('status', 1)
                ->find();

            if (!$moment) {
                return json(['code' => 404, 'msg' => '动态不存在或已被删除']);
            }

            // 开始事务
            Db::startTrans();

            try {
                // 删除现有关联
                Db::name('moment_topics')
                    ->where('moment_id', $momentId)
                    ->delete();

                // 检查话题是否存在
                $topicIds = is_array($topicIds) ? $topicIds : explode(',', $topicIds);
                $validTopicIds = Db::name('topics')
                    ->where('id', 'in', $topicIds)
                    ->where('status', 1)
                    ->column('id');

                if (empty($validTopicIds)) {
                    Db::commit();
                    return json(['code' => 200, 'msg' => '无有效话题ID']);
                }

                // 添加新关联
                $now = time();
                $associateData = [];
                foreach ($validTopicIds as $topicId) {
                    $associateData[] = [
                        'moment_id' => $momentId,
                        'topic_id' => $topicId,
                        'create_time' => $now
                    ];
                }

                Db::name('moment_topics')->insertAll($associateData);

                // 更新话题动态数
                Db::name('topics')
                    ->where('id', 'in', $validTopicIds)
                    ->inc('post_count')
                    ->update();

                // 提交事务
                Db::commit();

                return $this->success('关联话题成功');
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->error('关联话题失败: ' . $e->getMessage());
        }
    }

    /**
     * 搜索话题
     */
    public function searchTopics()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $keyword = input('keyword', '');
            $page = input('page', 1);
            $page = max(1, intval($page));
            $limit = input('limit', 10);
            $limit = max(1, min(50, intval($limit)));
            $offset = ($page - 1) * $limit;

            if (empty($keyword)) {
                return json(['code' => 400, 'msg' => '搜索关键词不能为空']);
            }

            // 查询话题
            $topicsQuery = Db::name('topics')
                ->field('id, name, description, cover as image, post_count as posts_count, follower_count as followers_count, create_time, status')
                ->where('status',1)
                ->where('name', 'like', '%' . $keyword . '%')
                ->order('is_hot', 'desc')
                ->order('follower_count', 'desc')
                ->order('post_count', 'desc')
                ->order('create_time', 'desc');

            // 获取总数
            $total = $topicsQuery->count();

            // 获取分页数据
            $topics = $topicsQuery->limit($offset, $limit)->select();

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;

            // 如果用户已登录，获取用户对话题的关注状态
            if ($currentUserId && !empty($topics)) {
                $topicIds = array_column($topics, 'id');
                $followedTopicIds = Db::name('topic_follows')
                    ->where('user_id', $currentUserId)
                    ->where('topic_id', 'in', $topicIds)
                    ->column('topic_id');

                foreach ($topics as &$topic) {
                    $topic['is_following'] = in_array($topic['id'], $followedTopicIds) ? 1 : 0;
                }
            } else {
                // 未登录用户或无话题，设置默认关注状态
                foreach ($topics as &$topic) {
                    $topic['is_following'] = 0;
                }
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'list' => $topics,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'has_more' => ($offset + $limit) < $total
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error('搜索话题失败: ' . $e->getMessage());
        }
    }
}
