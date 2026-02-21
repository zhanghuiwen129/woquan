<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\Session;
use think\facade\Cookie;
use think\facade\View;

class Moments extends BaseFrontendController
{
    /**
     * 初始化方法,确保 session 正确启动
     */
    protected function initialize()
    {
        parent::initialize();
        // 确保session已启动 - 在任何CORS头部之前访问session
        $userId = Session::get('user_id') ?: cookie('user_id');
        // 如果session中没有但cookie中有，则同步到session
        if ($userId && !Session::has('user_id')) {
            Session::set('user_id', $userId);
            $userData = Db::name('user')->where('id', $userId)->find();
            if ($userData) {
                Session::set('username', $userData['username']);
                Session::set('nickname', $userData['nickname']);
                Session::set('avatar', $userData['avatar']);
            }
        }
    }

    public function page()
    {
        $userId = Session::get('user_id') ?: Cookie::get('user_id');
        $isLogin = !empty($userId);
        $currentUser = null;
        if ($isLogin) {
            $currentUser = [
                'id' => $userId,
                'username' => Session::get('username', '') ?: Cookie::get('username', ''),
                'nickname' => Session::get('nickname', '') ?: Cookie::get('nickname', ''),
                'avatar' => Session::get('avatar', '') ?: Cookie::get('avatar', '')
            ];
        }

        View::assign([
            'isLogin' => $isLogin,
            'currentUser' => $currentUser,
            'current_url' => '/moments',
            'page_title' => '动态'
        ]);
        return View::fetch('index/moments');
    }
    public function index()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
            header('Access-Control-Allow-Credentials: true');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $currentUserId = Session::get('user_id') ?: cookie('user_id');
            
            // 获取筛选参数
            $type = $_GET['type'] ?? 0; // 动态类型：0-全部，1-文本，2-图片，3-视频
            $sort = $_GET['sort'] ?? 'time'; // 排序方式：time-按时间，hot-按热度
            $topicId = $_GET['topic'] ?? 0; // 话题ID：筛选指定话题的动态

            // 使用ThinkPHP的Db类查询动态列表
            $query = Db::name('moments')
                ->alias('m')
                ->join('user u', 'm.user_id = u.id', 'LEFT')
                ->field('m.id, m.user_id, u.nickname, u.avatar, u.username, m.content, m.images, m.videos, m.location, m.likes, m.comments, m.create_time, m.privacy, m.is_anonymous, m.type as moment_type, m.is_top')
                ->where('m.status', 1);

            // 根据话题筛选
            if ($topicId > 0) {
                $query->join('moment_topics mt', 'm.id = mt.moment_id', 'INNER')
                      ->where('mt.topic_id', $topicId);
            }
            
            // 根据类型筛选
            if ($type > 0) {
                $query->where('m.type', $type);
            }
            
            // 只显示当前用户可见的动态
            // 隐私设置: 1-公开, 2-私密, 3-仅好友, 4-部分可见
            if ($currentUserId) {
                // 获取当前用户关注的好友列表
                $followingIds = Db::name('follows')
                    ->where('follower_id', $currentUserId)
                    ->where('status', 1)
                    ->column('following_id');

                // 获取当前用户隐藏的动态ID列表
                $hiddenMomentIds = Db::name('hidden_moments')
                    ->where('user_id', $currentUserId)
                    ->column('moment_id');

                // 可见的动态包括：公开的、自己的、关注的人的(仅好友可见)
                $query->where(function($q) use ($currentUserId, $followingIds) {
                    $q->where('m.privacy', 1) // 公开
                      ->whereOr('m.user_id', $currentUserId) // 自己的(包括私密)
                      ->whereOr(function($subQ) use ($currentUserId, $followingIds) {
                          $subQ->where('m.privacy', 3) // 仅好友可见
                               ->whereIn('m.user_id', $followingIds);
                      });
                });

                // 排除用户隐藏的动态
                if (!empty($hiddenMomentIds)) {
                    $query->whereNotIn('m.id', $hiddenMomentIds);
                }
            } else {
                // 未登录用户只能查看公开动态
                $query->where('m.privacy', 1);
            }
            
            // 排序
            if ($sort === 'hot') {
                $query->order('m.likes desc, m.comments desc, m.create_time desc');
            } else {
                $query->order('m.is_top desc, m.create_time desc');
            }
            
            // 分页
            $page = (int) ($_GET['page'] ?? 1);
            $limit = (int) ($_GET['limit'] ?? 10);
            $offset = ($page - 1) * $limit;
            $query->limit($offset, $limit);

            $moments = $query->select();

            // 复制查询构建器以获取总记录数（保留所有筛选条件）
            $countQuery = clone $query;
            $totalCount = $countQuery->count();

            // 检查是否有更多数据
            $hasMore = (($page * $limit) < $totalCount);

            // 获取所有动态ID，批量查询mentions和topics
            $moments = $moments->toArray();
            $momentIds = array_column($moments, 'id');

            $mentionsData = [];
            if (!empty($momentIds)) {
                $mentions = Db::name('mentions')
                    ->alias('m')
                    ->leftJoin('user u', 'm.mentioned_user_id = u.id')
                    ->whereIn('m.moment_id', $momentIds)
                    ->field('m.moment_id, m.mentioned_user_id, m.nickname as mention_nickname, u.nickname, u.username')
                    ->select()
                    ->toArray();

                // 按moment_id分组
                foreach ($mentions as $mention) {
                    $momentId = $mention['moment_id'];
                    if (!isset($mentionsData[$momentId])) {
                        $mentionsData[$momentId] = [];
                    }
                    // 优先使用user表中的nickname，如果没有则使用mentions表中的nickname
                    $nickname = $mention['nickname'] ?: $mention['mention_nickname'];
                    $mentionsData[$momentId][] = [
                        'user_id' => $mention['mentioned_user_id'],
                        'nickname' => $nickname,
                        'username' => $mention['username']
                    ];
                }
            }

            // 批量查询话题数据
            $topicsData = [];
            if (!empty($momentIds)) {
                $topics = Db::name('moment_topics')
                    ->alias('mt')
                    ->leftJoin('topics t', 'mt.topic_id = t.id')
                    ->whereIn('mt.moment_id', $momentIds)
                    ->field('mt.moment_id, t.id as topic_id, t.name as topic_name')
                    ->select()
                    ->toArray();

                // 按moment_id分组
                foreach ($topics as $topic) {
                    $momentId = $topic['moment_id'];
                    if (!isset($topicsData[$momentId])) {
                        $topicsData[$momentId] = [];
                    }
                    $topicsData[$momentId][] = [
                        'topic_id' => $topic['topic_id'],
                        'topic_name' => $topic['topic_name']
                    ];
                }
            }

            // 检查当前用户对每条动态的点赞状态，并加载评论数据
            if ($currentUserId) {
                // 批量查询当前用户的点赞记录，提高性能
                $likedMomentIds = Db::name('likes')
                    ->where('user_id', $currentUserId)
                    ->where('target_type', 1)
                    ->column('target_id');

                foreach ($moments as &$moment) {
                    // 检查是否点赞
                    $moment['is_liked'] = in_array($moment['id'], $likedMomentIds) ? 1 : 0;

                    // 检查是否关注
                    $isFollowed = Db::name('follows')
                        ->where('follower_id', $currentUserId)
                        ->where('following_id', $moment['user_id'])
                        ->find();
                    $moment['is_followed'] = $isFollowed ? 1 : 0;

                    // 添加mentions数据
                    $moment['mentions'] = $mentionsData[$moment['id']] ?? [];

                    // 添加topics数据
                    $moment['topics'] = $topicsData[$moment['id']] ?? [];

                    // 格式化动态内容，将@用户替换为可点击链接
                    $moment['content'] = $this->formatMentionContent($moment['content'], $moment['mentions']);

                    // 格式化动态内容，将话题替换为可点击链接
                    $moment['content'] = $this->formatTopicContent($moment['content'], $moment['topics']);

                    // 处理图片和视频数据
                    if (!empty($moment['images'])) {
                        $moment['images'] = json_decode($moment['images'], true);
                    } else {
                        $moment['images'] = [];
                    }

                    if (!empty($moment['videos'])) {
                        $moment['videos'] = json_decode($moment['videos'], true);
                    } else {
                        $moment['videos'] = [];
                    }

                    // 加载前3条顶级评论数据（不包括楼中楼）
                    $comments = Db::name('comments')
                        ->alias('c')
                        ->leftJoin('user u', 'c.user_id = u.id')
                        ->where('c.moment_id', $moment['id'])
                        ->where('c.status', 1)
                        ->where(function($query) {
                            $query->where('c.parent_id', 0)->whereOr('c.parent_id', null);
                        })
                        ->field('c.id, c.content, c.create_time, c.user_id, c.parent_id, c.likes, u.nickname, u.avatar, u.username')
                        ->order('c.create_time', 'desc')
                        ->limit(3)
                        ->select()
                        ->toArray();

                    // 为每条顶级评论加载最新的2条楼中楼回复
                    foreach ($comments as &$comment) {
                        $comment['replies'] = [];

                        // 查询该顶级评论的子评论
                        $replies = Db::name('comments')
                            ->alias('c')
                            ->leftJoin('user u', 'c.user_id = u.id')
                            ->where('c.moment_id', $moment['id'])
                            ->where('c.parent_id', $comment['id'])
                            ->where('c.status', 1)
                            ->field('c.id, c.content, c.create_time, c.user_id, c.parent_id, c.likes, u.nickname, u.avatar, u.username')
                            ->order('c.create_time', 'desc')
                            ->limit(2)
                            ->select()
                            ->toArray();

                        // 获取被回复用户信息
                        if (!empty($replies)) {
                            $parentCommentIds = array_column($replies, 'parent_id');
                            if (!empty($parentCommentIds)) {
                                $parentComments = Db::name('comments')
                                    ->where('id', 'in', $parentCommentIds)
                                    ->column('user_id', 'id');

                                $parentUserIds = array_values($parentComments);
                                if (!empty($parentUserIds)) {
                                    $parentUserIds = array_unique($parentUserIds);
                                    $parentUsers = Db::name('user')
                                        ->where('id', 'in', $parentUserIds)
                                        ->column('nickname', 'id');

                                    // 添加被回复用户昵称到每条回复
                                    foreach ($replies as &$reply) {
                                        if (isset($parentComments[$reply['parent_id']])) {
                                            $replyUserId = $parentComments[$reply['parent_id']];
                                            $reply['reply_nickname'] = $parentUsers[$replyUserId] ?? '';
                                        } else {
                                            $reply['reply_nickname'] = '';
                                        }
                                    }
                                }
                            }
                        }

                        $comment['replies'] = $replies;
                        $comment['reply_count'] = count($replies);
                    }

                    $moment['comments_data'] = $comments;
                }
            } else {
                // 未登录用户，不显示点赞和关注状态
                foreach ($moments as &$moment) {
                    $moment['is_liked'] = 0;
                    $moment['is_followed'] = 0;

                    // 添加mentions数据
                    $moment['mentions'] = $mentionsData[$moment['id']] ?? [];

                    // 处理图片和视频数据
                    if (!empty($moment['images'])) {
                        $moment['images'] = json_decode($moment['images'], true);
                    } else {
                        $moment['images'] = [];
                    }

                    if (!empty($moment['videos'])) {
                        $moment['videos'] = json_decode($moment['videos'], true);
                    } else {
                        $moment['videos'] = [];
                    }

                    // 加载前3条顶级评论数据（不包括楼中楼）
                    $comments = Db::name('comments')
                        ->alias('c')
                        ->leftJoin('user u', 'c.user_id = u.id')
                        ->where('c.moment_id', $moment['id'])
                        ->where('c.status', 1)
                        ->where(function($query) {
                            $query->where('c.parent_id', 0)->whereOr('c.parent_id', null);
                        })
                        ->field('c.id, c.content, c.create_time, c.user_id, c.parent_id, c.likes, u.nickname, u.avatar, u.username')
                        ->order('c.create_time', 'desc')
                        ->limit(3)
                        ->select()
                        ->toArray();

                    // 为每条顶级评论加载最新的2条楼中楼回复
                    foreach ($comments as &$comment) {
                        $comment['replies'] = [];

                        // 查询该顶级评论的子评论
                        $replies = Db::name('comments')
                            ->alias('c')
                            ->leftJoin('user u', 'c.user_id = u.id')
                            ->where('c.moment_id', $moment['id'])
                            ->where('c.parent_id', $comment['id'])
                            ->where('c.status', 1)
                            ->field('c.id, c.content, c.create_time, c.user_id, c.parent_id, c.likes, u.nickname, u.avatar, u.username')
                            ->order('c.create_time', 'desc')
                            ->limit(2)
                            ->select()
                            ->toArray();

                        // 获取被回复用户信息
                        if (!empty($replies)) {
                            $parentCommentIds = array_column($replies, 'parent_id');
                            if (!empty($parentCommentIds)) {
                                $parentComments = Db::name('comments')
                                    ->where('id', 'in', $parentCommentIds)
                                    ->column('user_id', 'id');

                                $parentUserIds = array_values($parentComments);
                                if (!empty($parentUserIds)) {
                                    $parentUserIds = array_unique($parentUserIds);
                                    $parentUsers = Db::name('user')
                                        ->where('id', 'in', $parentUserIds)
                                        ->column('nickname', 'id');

                                    // 添加被回复用户昵称到每条回复
                                    foreach ($replies as &$reply) {
                                        if (isset($parentComments[$reply['parent_id']])) {
                                            $replyUserId = $parentComments[$reply['parent_id']];
                                            $reply['reply_nickname'] = $parentUsers[$replyUserId] ?? '';
                                        } else {
                                            $reply['reply_nickname'] = '';
                                        }
                                    }
                                }
                            }
                        }

                        $comment['replies'] = $replies;
                        $comment['reply_count'] = count($replies);
                    }

                    $moment['comments_data'] = $comments;
                }
            }
            
            $response = [
                'code' => 200,
                'msg' => '获取动态列表成功',
                'data' => [
                    'moments' => $moments,
                    'hasMore' => $hasMore,
                    'total' => $totalCount,
                    'page' => $page,
                    'limit' => $limit
                ]
            ];

            return $this->success($response['data'], $response['msg']);
        } catch (\Exception $e) {
            return $this->error('获取动态列表失败: ' . $e->getMessage());
        }
    }
    
    // 发布动态
    public function publish()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $userInfo = Db::name('user')
                ->where('id', $userId)
                ->field('nickname, avatar, can_speak, status')
                ->find();
            if (!$userInfo) {
                return $this->notFound('用户不存在');
            }

            if ($userInfo['status'] != 1) {
                return $this->error('您的账号已被禁用，无法发布动态', 403);
            }

            if ($userInfo['can_speak'] != 1) {
                return $this->error('您已被禁言，无法发布动态', 403);
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data) {
                return $this->badRequest('无效的请求数据');
            }

            if (empty($data['content']) && empty($data['images']) && empty($data['videos'])) {
                return $this->badRequest('动态内容不能为空');
            }

            // 确定动态类型
            if (!empty($data['videos'])) {
                $type = 3; // 视频
            } elseif (!empty($data['images'])) {
                $type = 2; // 图片
            } else {
                $type = 1; // 文本
            }

            // 准备动态数据
            // 隐私设置: 1-公开, 2-私密, 3-仅好友, 4-部分可见
            $privacy = isset($data['privacy']) ? (int)$data['privacy'] : 1;
            // 映射前端的隐私值到数据库值
            $privacyMap = [0 => 1, 1 => 3, 2 => 2]; // 0公开->1, 1仅好友->3, 2仅自己->2
            $finalPrivacy = isset($privacyMap[$privacy]) ? $privacyMap[$privacy] : 1;

            // 清理内容中的&nbsp;，替换为普通空格（前端插入话题时使用了&nbsp;）
            $cleanContent = isset($data['content']) ? str_replace('&nbsp;', ' ', $data['content']) : '';

            $momentData = [
                'user_id' => $userId,
                'nickname' => $userInfo['nickname'],
                'avatar' => $userInfo['avatar'],
                'content' => $cleanContent,
                'images' => !empty($data['images']) ? json_encode($data['images']) : '',
                'videos' => !empty($data['videos']) ? json_encode($data['videos']) : '',
                'location' => $data['location'] ?? '',
                'privacy' => $finalPrivacy,
                'is_anonymous' => $data['is_anonymous'] ?? 0,
                'type' => $type,
                'create_time' => time(),
                'status' => 1 // 1-正常，0-禁用
            ];

            // 插入动态数据
            $momentId = Db::name('moments')->insertGetId($momentData);

            if (!$momentId) {
                return $this->error('发布动态失败');
            }

            // 注意：图片和视频信息已经在 uploadImage 时记录到 storage_files 表
            // 这里不需要重复插入，避免产生重复记录

            // 处理@用户，发送站内消息通知
            if (!empty($data['mentioned_users']) && is_array($data['mentioned_users'])) {
                foreach ($data['mentioned_users'] as $mentionedUserId) {
                    // 不给自己发通知
                    if ($mentionedUserId != $userId) {
                        // 获取被@用户信息
                        $mentionedUser = Db::name('user')
                            ->where('id', $mentionedUserId)
                            ->field('id, nickname, avatar')
                            ->find();

                        if ($mentionedUser) {
                            // 保存到mentions表
                            Db::name('mentions')->insert([
                                'moment_id' => $momentId,
                                'user_id' => $userId, // 发布动态的用户ID
                                'mentioned_user_id' => $mentionedUser['id'],
                                'nickname' => $mentionedUser['nickname'],
                                'avatar' => $mentionedUser['avatar'] ?? '',
                                'content' => '', // 相关内容
                                'create_time' => time(), // 使用时间戳
                                'read_status' => 0 // 未读
                            ]);

                            // 发送站内消息通知
                            $senderInfo = Db::name('user')->where('id', $userId)->field('nickname')->find();
                            $nickname = $senderInfo['nickname'] ?? '用户';

                            Db::name('notifications')->insert([
                                'user_id' => $mentionedUserId,
                                'sender_id' => $userId,
                                'type' => 2, // 2-评论通知（@消息也是评论）
                                'title' => '新消息',
                                'content' => $nickname . '在动态中@了你',
                                'target_id' => $momentId,
                                'target_type' => 'moment',
                                'create_time' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
            }

            // 处理话题
            $content = $data['content'] ?? '';
            $topics = $this->parseTopics($content);

            if (!empty($topics)) {
                foreach ($topics as $topicName) {
                    // 查找或创建话题
                    $topic = Db::name('topics')
                        ->where('name', $topicName)
                        ->find();

                    if (!$topic) {
                        // 创建新话题
                        $topicId = Db::name('topics')->insertGetId([
                            'name' => $topicName,
                            'description' => '',
                            'cover' => '',
                            'post_count' => 0,
                            'follower_count' => 0,
                            'create_time' => time(),
                            'status' => 1
                        ]);
                    } else {
                        $topicId = $topic['id'];
                    }

                    // 保存动态与话题的关联
                    Db::name('moment_topics')->insert([
                        'moment_id' => $momentId,
                        'topic_id' => $topicId,
                        'create_time' => time()
                    ]);

                    // 更新话题的动态数量
                    Db::name('topics')
                        ->where('id', $topicId)
                        ->inc('post_count', 1)
                        ->update();
                }
            }

            return $this->success(['moment_id' => $momentId], '发布动态成功');
        } catch (\Exception $e) {
            return $this->error('发布动态失败: ' . $e->getMessage());
        }
    }
    
    // 获取动态详情
    public function detail()
    {
        try {
            $currentUserId = Session::get('user_id') ?: cookie('user_id');

            $momentId = $_GET['id'] ?? null;
            if (!$momentId) {
                return $this->badRequest('动态ID不能为空');
            }

            $moment = Db::name('moments')
                ->field('id, user_id, nickname, avatar, content, images, videos, location, likes, comments, create_time, privacy, is_anonymous, type as moment_type, is_top')
                ->where('id', $momentId)
                ->where('status', 1)
                ->find();

            if (!$moment) {
                return $this->notFound('动态不存在');
            }

            if ($moment['privacy'] == 2 && $currentUserId != $moment['user_id']) {
                return $this->error('无权查看该动态', 403);
            }

            if ($moment['privacy'] == 3 && $currentUserId != $moment['user_id']) {
                $followingIds = Db::name('follows')
                    ->where('follower_id', $currentUserId)
                    ->where('status', 1)
                    ->column('following_id');

                if (!in_array($moment['user_id'], $followingIds)) {
                    return $this->error('无权查看该动态', 403);
                }
            }

            if (!empty($moment['images'])) {
                $moment['images'] = json_decode($moment['images'], true);
            } else {
                $moment['images'] = [];
            }

            if (!empty($moment['videos'])) {
                $moment['videos'] = json_decode($moment['videos'], true);
            } else {
                $moment['videos'] = [];
            }

            if ($currentUserId) {
                $isLiked = Db::name('likes')
                    ->where('user_id', $currentUserId)
                    ->where('target_id', $momentId)
                    ->where('target_type', 1)
                    ->find();
                $moment['is_liked'] = $isLiked ? 1 : 0;

                $isFollowed = Db::name('follows')
                    ->where('follower_id', $currentUserId)
                    ->where('following_id', $moment['user_id'])
                    ->find();
                $moment['is_followed'] = $isFollowed ? 1 : 0;
            } else {
                $moment['is_liked'] = 0;
                $moment['is_followed'] = 0;
            }

            return $this->success($moment, '获取动态详情成功');
        } catch (\Exception $e) {
            return $this->error('获取动态详情失败: ' . $e->getMessage());
        }
    }

    /**
     * 动态详情页面
     */
    public function detailPage()
    {
        // 获取动态ID
        $id = request()->param('id');

        // 渲染动态详情页面
        return view('index/moment-detail', [
            'moment_id' => $id
        ]);
    }

    
    // 点赞动态
    public function like()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 检查点赞功能是否开启
            $likeEnabled = Db::name('system_config')->where('config_key', 'like_enabled')->value('config_value') ?? '1';
            if ($likeEnabled != '1') {
                return $this->error('点赞功能已关闭', 403);
            }

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['moment_id'])) {
                return $this->badRequest('动态ID不能为空');
            }

            $momentId = $data['moment_id'];
            $action = $data['action'] ?? 'like'; // like 或 unlike

            // 如果是取消点赞，调用 unlike 方法逻辑
            if ($action === 'unlike') {
                return $this->handleUnlike($userId, $momentId);
            }

            // 以下是点赞逻辑

            // 检查动态是否存在
            $moment = Db::name('moments')
                ->where('id', $momentId)
                ->where('status', 1)
                ->find();

            if (!$moment) {
                return $this->notFound('动态不存在');
            }

            Db::startTrans();

            try {
                $likeRecord = Db::name('likes')
                    ->where('user_id', $userId)
                    ->where('target_id', $momentId)
                    ->where('target_type', 1)
                    ->find();

                if ($likeRecord) {
                    Db::name('likes')
                        ->where('user_id', $userId)
                        ->where('target_id', $momentId)
                        ->where('target_type', 1)
                        ->delete();

                    Db::name('moments')
                        ->where('id', $momentId)
                        ->dec('likes', 1)
                        ->update();

                    if ($userId != $moment['user_id']) {
                        Db::name('notifications')
                            ->where('user_id', $moment['user_id'])
                            ->where('sender_id', $userId)
                            ->where('type', 1)
                            ->where('target_id', $momentId)
                            ->where('target_type', 'moment')
                            ->where('is_read', 0)
                            ->delete();
                    }

                    Db::commit();

                    return $this->success(['liked' => false, 'count' => $moment['likes'] - 1], '取消点赞成功');
                }

                $likeData = [
                    'user_id' => $userId,
                    'target_id' => $momentId,
                    'target_type' => 1,
                    'create_time' => date('Y-m-d H:i:s')
                ];
                Db::name('likes')->insert($likeData);

                Db::name('moments')
                    ->where('id', $momentId)
                    ->inc('likes', 1)
                    ->update();

                if ($userId != $moment['user_id']) {
                    $this->createNotification(
                        $moment['user_id'],
                        $userId,
                        1,
                        '有人点赞了你的动态',
                        '有人点赞了你的动态',
                        $momentId,
                        'moment'
                    );
                }

                Db::commit();

                return $this->success(['liked' => true, 'count' => $moment['likes'] + 1], '点赞成功');
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->error('点赞失败: ' . $e->getMessage());
        }
    }

    // 处理取消点赞的私有方法
    private function handleUnlike($userId, $momentId)
    {
        try {
            $moment = Db::name('moments')
                ->where('id', $momentId)
                ->find();

            if (!$moment) {
                return $this->notFound('动态不存在');
            }

            Db::startTrans();

            try {
                $result = Db::name('likes')
                    ->where('user_id', $userId)
                    ->where('target_id', $momentId)
                    ->where('target_type', 1)
                    ->delete();

                if (!$result) {
                    return $this->badRequest('未点赞过该动态');
                }

                Db::name('moments')
                    ->where('id', $momentId)
                    ->dec('likes', 1)
                    ->update();

                if ($userId != $moment['user_id']) {
                    Db::name('notifications')
                        ->where('user_id', $moment['user_id'])
                        ->where('sender_id', $userId)
                        ->where('type', 1)
                        ->where('target_id', $momentId)
                        ->where('target_type', 'moment')
                        ->where('is_read', 0)
                        ->delete();
                }

                Db::commit();

                return $this->success(['liked' => false, 'count' => $moment['likes'] - 1], '取消点赞成功');
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->error('取消点赞失败: ' . $e->getMessage());
        }
    }

    // 取消点赞
    public function unlike()
    {
        // 设置CORS头部
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');

        $userId = Session::get('user_id') ?: cookie('user_id');
        if (!$userId) {
            return $this->unauthorized();
        }

        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data || !isset($data['moment_id'])) {
            return $this->badRequest('动态ID不能为空');
        }

        $momentId = $data['moment_id'];

        $this->handleUnlike($userId, $momentId);
    }
    
    // 发表评论
    public function comment()
    {
        try {
            $commentEnabled = Db::name('system_config')->where('config_key', 'comment_enabled')->value('config_value') ?? '1';
            if ($commentEnabled != '1') {
                return $this->error('评论功能已关闭', 403);
            }

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $userInfo = Db::name('user')
                ->where('id', $userId)
                ->field('nickname, avatar')
                ->find();
            if (!$userInfo) {
                return $this->notFound('用户不存在');
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['moment_id']) || !isset($data['content'])) {
                return $this->badRequest('动态ID和评论内容不能为空');
            }

            $momentId = $data['moment_id'];
            $content = $data['content'];
            $parentId = $data['parent_id'] ?? 0;

            $moment = Db::name('moments')
                ->where('id', $momentId)
                ->where('status', 1)
                ->find();

            if (!$moment) {
                return $this->notFound('动态不存在');
            }

            Db::startTrans();

            try {
                $commentData = [
                    'moment_id' => $momentId,
                    'user_id' => $userId,
                    'nickname' => $userInfo['nickname'],
                    'avatar' => $userInfo['avatar'],
                    'content' => $content,
                    'parent_id' => $parentId,
                    'create_time' => time(),
                    'status' => 1
                ];
                $commentId = Db::name('comments')->insertGetId($commentData);

                Db::name('moments')
                    ->where('id', $momentId)
                    ->inc('comments', 1)
                    ->update();

                if ($userId != $moment['user_id']) {
                    $this->createNotification(
                        $moment['user_id'],
                        $userId,
                        2,
                        '有人评论了你的动态',
                        $content,
                        $momentId,
                        'moment'
                    );
                }

                if ($parentId > 0) {
                    $parentComment = Db::name('comments')
                        ->where('id', $parentId)
                        ->find();

                    if ($parentComment && $parentComment['user_id'] != $userId && $parentComment['user_id'] != $moment['user_id']) {
                        $this->createNotification(
                            $parentComment['user_id'],
                            $userId,
                            2,
                            '有人回复了你的评论',
                            $content,
                            $commentId,
                            'comment'
                        );
                    }
                }

                Db::commit();

                return $this->success(['comment_id' => $commentId], '评论成功');
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->error('评论失败: ' . $e->getMessage());
        }
    }
    
    // 获取评论列表
    public function getComments()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $currentUserId = Session::get('user_id') ?: cookie('user_id');
            
            // 获取动态ID
            $momentId = $_GET['moment_id'] ?? null;
            if (!$momentId) {
                return $this->badRequest('动态ID不能为空');
            }

            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $offset = ($page - 1) * $limit;

            $comments = Db::name('comments')
                ->where('moment_id', $momentId)
                ->where('parent_id', 0)
                ->where('status', 1)
                ->order('create_time desc')
                ->limit($offset, $limit)
                ->select();

            $totalCount = Db::name('comments')
                ->where('moment_id', $momentId)
                ->where('parent_id', 0)
                ->where('status', 1)
                ->count();

            foreach ($comments as &$comment) {
                $replies = Db::name('comments')
                    ->where('parent_id', $comment['id'])
                    ->where('status', 1)
                    ->order('create_time asc')
                    ->select();

                $comment['replies'] = $replies;
                $comment['reply_count'] = count($replies);
            }

            return $this->success([
                'list' => $comments,
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit
            ], '获取评论列表成功');
        } catch (\Exception $e) {
            return $this->error('获取评论列表失败: ' . $e->getMessage());
        }
    }
    
    // 获取当前用户的动态列表
    public function getUserMoments()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $currentUserId = Session::get('user_id') ?: cookie('user_id');
            if (!$currentUserId) {
                return $this->unauthorized();
            }

            // 获取查询参数
            $userId = $_GET['user_id'] ?? null;
            if (!$userId) {
                $userId = $currentUserId; // 默认查看自己的动态
            }
            
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $offset = ($page - 1) * $limit;
            
            // 查询动态列表
            $query = Db::name('moments')
                ->field('id, user_id, nickname, avatar, content, images, videos, location, likes, comments, create_time, privacy, is_anonymous, type as moment_type')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->order('create_time desc');
            
            // 只有当前用户或管理员才能查看所有动态，包括隐私设置为仅好友可见的
            if ($userId != $currentUserId) {
                // 检查是否关注了该用户
                $isFollowing = Db::name('follows')
                    ->where('follower_id', $currentUserId)
                    ->where('following_id', $userId)
                    ->where('status', 1)
                    ->find();
                
                if ($isFollowing) {
                    // 关注的好友可以查看公开和仅好友可见的动态
                    $query->where(function($q) {
                        $q->where('privacy', 1) // 公开
                          ->whereOr('privacy', 3); // 仅好友可见
                    });
                } else {
                    // 非关注用户只能查看公开动态
                    $query->where('privacy', 1);
                }
            }
            
            // 分页
            $moments = $query->limit($offset, $limit)->select();
            
            // 获取总记录数
            $totalCount = Db::name('moments')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->count();
            
            // 检查当前用户对每条动态的点赞状态
            foreach ($moments as &$moment) {
                // 检查是否点赞
                $isLiked = Db::name('likes')
                    ->where('user_id', $currentUserId)
                    ->where('target_id', $moment['id'])
                    ->where('target_type', 1)
                    ->find();
                $moment['is_liked'] = $isLiked ? 1 : 0;
                
                // 处理图片和视频数据
                if (!empty($moment['images'])) {
                    $moment['images'] = json_decode($moment['images'], true);
                } else {
                    $moment['images'] = [];
                }
                
                if (!empty($moment['videos'])) {
                    $moment['videos'] = json_decode($moment['videos'], true);
                } else {
                    $moment['videos'] = [];
                }
            }
            
            // 返回成功响应
            return $this->success([
                'list' => $moments,
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit
            ], '获取用户动态成功');
        } catch (\Exception $e) {
            return $this->error('获取用户动态失败: ' . $e->getMessage());
        }
    }
    
    // 获取推荐用户列表
    public function getRecommendUsers()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $currentUserId = Session::get('user_id') ?: cookie('user_id');
            if (!$currentUserId) {
                return $this->unauthorized();
            }

            $followedUserIds = Db::name('follows')
                ->where('follower_id', $currentUserId)
                ->where('status', 1)
                ->column('following_id');

            array_push($followedUserIds, $currentUserId);

            $users = Db::name('user')
                ->whereNotIn('id', $followedUserIds)
                ->field('id, nickname, avatar, bio, moments_count, followers_count, follows_count')
                ->order('moments_count desc, followers_count desc')
                ->limit(10)
                ->select();

            return $this->success($users, '获取推荐用户成功');
        } catch (\Exception $e) {
            return $this->error('获取推荐用户失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取热门话题列表
     */
    public function getHotTopics()
    {
        try {
            // 查询热门话题（这里简化处理，实际项目中可能需要更复杂的算法）
            $topics = Db::name('topics')
                ->field('id, name, description, count')
                ->order('count desc')
                ->limit(10)
                ->select();

            return $this->success($topics);

        } catch (\Exception $e) {
            return $this->error('获取热门话题失败: ' . $e->getMessage());
        }
    }
    
    // 关注用户
    public function follow()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 检查关注功能是否开启
            $followEnabled = Db::name('system_config')->where('config_key', 'follow_enabled')->value('config_value') ?? '1';
            if ($followEnabled != '1') {
                return $this->error('关注功能已关闭', 403);
            }

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取POST数据
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data || !isset($data['following_id'])) {
                return $this->badRequest('关注用户ID不能为空');
            }

            $followUserId = $data['following_id'];
            
            if ($userId == $followUserId) {
                return $this->badRequest('不能关注自己');
            }

            $followUser = Db::name('user')
                ->where('id', $followUserId)
                ->find();

            if (!$followUser) {
                return $this->notFound('关注的用户不存在');
            }
            
            // 检查是否已经关注
            $followRecord = Db::name('follows')
                ->where('follower_id', $userId)
                ->where('following_id', $followUserId)
                ->find();
            
            if ($followRecord) {
                return $this->badRequest('已经关注过该用户');
            }
            
            // 开始事务
            Db::startTrans();
            
            try {
                // 添加关注记录
                $followData = [
                    'follower_id' => $userId,
                    'following_id' => $followUserId,
                    'create_time' => time()
                ];
                Db::name('follows')->insert($followData);
                
                // 更新关注者和被关注者的计数
                Db::name('user')
                    ->where('id', $userId)
                    ->inc('follows_count', 1)
                    ->update();

                Db::name('user')
                    ->where('id', $followUserId)
                    ->inc('followers_count', 1)
                    ->update();
                
                // 创建关注通知
                $this->createNotification(
                    $followUserId,
                    $userId,
                    3, // 3-关注
                    '有人关注了你',
                    '有人关注了你',
                    $userId,
                    'user'
                );
                
                Db::commit();

                return $this->success(null, '关注成功');
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->error('关注失败: ' . $e->getMessage());
        }
    }
    
    // 取消关注
    public function unfollow()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['following_id'])) {
                return $this->badRequest('关注用户ID不能为空');
            }

            $followUserId = $data['following_id'];
            
            // 开始事务
            Db::startTrans();
            
            try {
                // 删除关注记录
                $result = Db::name('follows')
                    ->where('follower_id', $userId)
                    ->where('following_id', $followUserId)
                    ->delete();
                
                if (!$result) {
                    return $this->badRequest('未关注该用户');
                }

                Db::name('user')
                    ->where('id', $userId)
                    ->dec('follows_count', 1)
                    ->update();

                Db::name('user')
                    ->where('id', $followUserId)
                    ->dec('followers_count', 1)
                    ->update();

                Db::commit();

                return $this->success(null, '取消关注成功');
            } catch (\Exception $e) {
                Db::rollback();
                return $this->error('取消关注失败: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            return $this->error('取消关注失败: ' . $e->getMessage());
        }
    }

    // 关注用户（兼容前端调用）
    public function followUser()
    {
        return $this->follow();
    }

    // 取消关注用户（兼容前端调用）
    public function unfollowUser()
    {
        return $this->unfollow();
    }

    // 保存草稿
    public function saveDraft()
    {
        try {
            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data) {
                return $this->badRequest('无效的请求数据');
            }

            $draftData = [
                'user_id' => $userId,
                'content' => $data['content'] ?? '',
                'images' => !empty($data['images']) ? json_encode($data['images']) : '',
                'videos' => !empty($data['videos']) ? json_encode($data['videos']) : '',
                'location' => $data['location'] ?? '',
                'privacy' => $data['privacy'] ?? 0,
                'is_anonymous' => $data['is_anonymous'] ?? 0,
                'updated_time' => time()
            ];

            $existingDraft = Db::name('moment_drafts')
                ->where('user_id', $userId)
                ->find();

            if ($existingDraft) {
                $result = Db::name('moment_drafts')
                    ->where('id', $existingDraft['id'])
                    ->update($draftData);

                if (!$result) {
                    return $this->error('保存草稿失败');
                }

                return $this->success(['draft_id' => $existingDraft['id']], '更新草稿成功');
            }

            $draftId = Db::name('moment_drafts')->insertGetId($draftData);

            if (!$draftId) {
                return $this->error('保存草稿失败');
            }

            return $this->success(['draft_id' => $draftId], '保存草稿成功');
        } catch (\Exception $e) {
            return $this->error('保存草稿失败: ' . $e->getMessage());
        }
    }
    
    // 获取草稿列表
    public function getDrafts()
    {
        try {
            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $offset = ($page - 1) * $limit;

            $drafts = Db::name('moment_drafts')
                ->where('user_id', $userId)
                ->field('id, content, images, videos, location, privacy, is_anonymous, create_time, updated_time')
                ->order('updated_time desc')
                ->limit($offset, $limit)
                ->select();

            $total = Db::name('moment_drafts')->where('user_id', $userId)->count();

            foreach ($drafts as &$draft) {
                if (!empty($draft['images'])) {
                    $draft['images'] = json_decode($draft['images'], true);
                } else {
                    $draft['images'] = [];
                }

                if (!empty($draft['videos'])) {
                    $draft['videos'] = json_decode($draft['videos'], true);
                } else {
                    $draft['videos'] = [];
                }
            }

            return $this->success([
                'list' => $drafts,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ], '获取草稿列表成功');
        } catch (\Exception $e) {
            return $this->error('获取草稿列表失败: ' . $e->getMessage());
        }
    }
    
    // 删除草稿
    public function deleteDraft()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['draft_id'])) {
                return $this->badRequest('草稿ID不能为空');
            }

            $draftId = $data['draft_id'];

            // 检查草稿是否存在并且属于当前用户
            $draft = Db::name('moment_drafts')
                ->where('id', $draftId)
                ->where('user_id', $userId)
                ->find();
            
            if (!$draft) {
                return $this->notFound('草稿不存在');
            }

            $result = Db::name('moment_drafts')
                ->where('id', $draftId)
                ->delete();

            if (!$result) {
                return $this->error('删除草稿失败');
            }

            return $this->success(null, '删除草稿成功');
        } catch (\Exception $e) {
            return $this->error('删除草稿失败: ' . $e->getMessage());
        }
    }

    /**
     * 创建通知
     * @param int $toUserId 接收通知的用户ID
     * @param int $fromUserId 发送通知的用户ID
     * @param int $type 通知类型：1-点赞，2-评论，3-关注，4-私信，5-系统通知
     * @param string $title 通知标题
     * @param string $content 通知内容
     * @param int $targetId 目标ID（如动态ID、评论ID等）
     * @param string $targetType 目标类型（如moment、comment等）
     */
    private function createNotification($toUserId, $fromUserId, $type, $title, $content, $targetId, $targetType)
    {
        try {
            // 如果是自己操作，不需要发送通知
            if ($toUserId == $fromUserId) {
                return true;
            }

            // 获取发送通知用户的信息
            $fromUser = Db::name('user')->where('id', $fromUserId)->field('nickname, avatar')->find();
            $fromNickname = $fromUser['nickname'] ?? '用户';
            $fromAvatar = $fromUser['avatar'] ?? '/static/images/default-avatar.png';

            // 构建通知数据
            $notificationData = [
                'user_id' => $toUserId,
                'sender_id' => $fromUserId,
                'from_nickname' => $fromNickname,
                'from_avatar' => $fromAvatar,
                'type' => $type,
                'title' => $title,
                'content' => $content,
                'target_id' => $targetId,
                'target_type' => $targetType,
                'is_read' => 0
            ];

            // 插入通知数据
            $notificationId = Db::name('notifications')->insertGetId($notificationData);
            return $notificationId > 0;
        } catch (\Exception $e) {
            // 通知创建失败不影响主要功能，记录日志即可
            error_log('创建通知失败: ' . $e->getMessage());
            return false;
        }
    }
    
    // 获取通知列表
    public function getNotifications()
    {
        try {
            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $page = $data['page'] ?? 1;
            $limit = $data['limit'] ?? 20;
            $offset = ($page - 1) * $limit;

            $notifications = Db::name('notifications')
                ->where('user_id', $userId)
                ->order('create_time desc')
                ->limit($offset, $limit)
                ->select();

            $total = Db::name('notifications')->where('user_id', $userId)->count();

            return $this->success([
                'list' => $notifications,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ], '获取通知列表成功');
        } catch (\Exception $e) {
            return $this->error('获取通知列表失败: ' . $e->getMessage());
        }
    }

    // 标记通知为已读
    public function readNotification()
    {
        try {
            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['id'])) {
                return $this->badRequest('无效的请求数据');
            }

            $notificationId = $data['id'];

            $result = Db::name('notifications')
                ->where('id', $notificationId)
                ->where('user_id', $userId)
                ->update(['is_read' => 1]);

            if ($result) {
                return $this->success(null, '标记已读成功');
            } else {
                return $this->error('标记已读失败');
            }
        } catch (\Exception $e) {
            return $this->error('标记已读失败: ' . $e->getMessage());
        }
    }

    // 获取未读通知数量
    public function getUnreadCount()
    {
        try {
            // 先获取当前登录用户ID（确保session已启动）
            $userId = Session::get('user_id') ?: cookie('user_id');

            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');
            if (!$userId) {
                return $this->unauthorized();
            }

            $count = Db::name('notifications')
                ->where('user_id', $userId)
                ->where('is_read', 0)
                ->count();

            return $this->success(['unread_count' => $count], '获取未读数量成功');
        } catch (\Exception $e) {
            return $this->error('获取未读数量失败: ' . $e->getMessage());
        }
    }

    // 获取话题列表
    public function getTopics()
    {
        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            $query = Db::name('topics')
                ->where('status', 1);

            if (!empty($data['is_hot'])) {
                $query->where('is_hot', 1);
            }

            $topics = $query->order('post_count desc, create_time desc')
                ->limit(20)
                ->select();

            $topicList = [];
            foreach ($topics as $topic) {
                $topicList[] = [
                    'id' => $topic['id'],
                    'name' => $topic['name'],
                    'description' => $topic['description'] ?? '',
                    'cover_image' => $topic['cover'] ?? '',
                    'post_count' => $topic['post_count'] ?? 0,
                    'follower_count' => $topic['follower_count'] ?? 0,
                    'is_hot' => $topic['is_hot'] ?? 0,
                    'create_time' => $topic['create_time']
                ];
            }

            return $this->success($topicList, '获取话题列表成功');
        } catch (\Exception $e) {
            return $this->error('获取话题列表失败: ' . $e->getMessage());
        }
    }

    // 发送私信
    public function sendPrivateMessage()
    {
        try {
            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['receiver_id']) || !isset($data['content'])) {
                return $this->badRequest('无效的请求数据');
            }

            $toUserId = $data['receiver_id'];
            $content = $data['content'];

            $sender = Db::name('user')
                ->where('id', $userId)
                ->field('can_speak, status')
                ->find();

            if (!$sender) {
                return $this->notFound('用户不存在');
            }

            if ($sender['status'] != 1) {
                return $this->error('您的账号已被禁用，无法发送私信', 403);
            }

            if ($sender['can_speak'] != 1) {
                return $this->error('您已被禁言，无法发送私信', 403);
            }

            $toUser = Db::name('user')->where('id', $toUserId)->find();
            if (!$toUser) {
                return $this->notFound('接收用户不存在');
            }

            Db::startTrans();

            $messageType = $data['message_type'] ?? 1;
            $fileUrl = $data['file_url'] ?? '';

            if (($messageType == 2 || $messageType == 3) && !empty($fileUrl)) {
                $content = $fileUrl;
            }

            $messageData = [
                'sender_id' => $userId,
                'receiver_id' => $toUserId,
                'content' => $content,
                'message_type' => $messageType,
                'file_url' => $fileUrl,
                'is_read' => 0,
                'create_time' => time()
            ];
            $messageId = Db::name('messages')->insertGetId($messageData);

            if (!$messageId) {
                Db::rollback();
                return $this->error('发送私信失败');
            }

            Db::commit();

            return $this->success(null, '发送私信成功');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('发送私信失败: ' . $e->getMessage());
        }
    }

    // 获取私信列表
    public function getPrivateMessages()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取POST数据
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['receiver_id'])) {
                return $this->badRequest('无效的请求数据');
            }

            $toUserId = $data['receiver_id'];
            $page = $data['page'] ?? 1;
            $limit = $data['limit'] ?? 20;
            $offset = ($page - 1) * $limit;

            // 查询私信列表
            $messages = Db::name('messages')
                ->where(function($query) use ($userId, $toUserId) {
                    $query->where(['sender_id' => $userId, 'receiver_id' => $toUserId])
                          ->whereOr(['sender_id' => $toUserId, 'receiver_id' => $userId]);
                })
                ->order('create_time asc')
                ->limit($offset, $limit)
                ->select();

            // 计算总记录数
            $total = Db::name('messages')
                ->where(function($query) use ($userId, $toUserId) {
                    $query->where(['sender_id' => $userId, 'receiver_id' => $toUserId])
                          ->whereOr(['sender_id' => $toUserId, 'receiver_id' => $userId]);
                })
                ->count();

            // 标记对方发送的消息为已读
            Db::name('messages')
                ->where(['sender_id' => $toUserId, 'receiver_id' => $userId, 'is_read' => 0])
                ->update(['is_read' => 1]);

            return $this->success([
                'list' => $messages,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ], '获取私信列表成功');
        } catch (\Exception $e) {
            return $this->error('获取私信列表失败: ' . $e->getMessage());
        }
    }

    // 标记私信为已读
    public function readPrivateMessage()
    {
        try {
            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['id'])) {
                return $this->badRequest('无效的请求数据');
            }

            $messageId = $data['id'];

            $result = Db::name('messages')
                ->where('id', $messageId)
                ->where('receiver_id', $userId)
                ->update(['is_read' => 1]);

            if ($result) {
                return $this->success(null, '标记已读成功');
            } else {
                return $this->error('标记已读失败');
            }
        } catch (\Exception $e) {
            return $this->error('标记已读失败: ' . $e->getMessage());
        }
    }

    // 获取会话列表
    public function getConversationList()
    {
        try {
            // 先获取当前登录用户ID（确保session已启动）
            $userId = Session::get('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            $page = $data['page'] ?? 1;
            $limit = $data['limit'] ?? 20;
            $offset = ($page - 1) * $limit;

            // 查询会话列表
            $conversations = Db::name('messages')
                ->field('sender_id, receiver_id, MAX(create_time) as last_message_time')
                ->where(function($query) use ($userId) {
                    $query->where('sender_id', $userId)
                          ->whereOr('receiver_id', $userId);
                })
                ->group('sender_id, receiver_id')
                ->order('last_message_time desc')
                ->limit($offset, $limit)
                ->select();

            // 计算总记录数
            $total = Db::name('messages')
                ->field('sender_id, receiver_id')
                ->where(function($query) use ($userId) {
                    $query->where('sender_id', $userId)
                          ->whereOr('receiver_id', $userId);
                })
                ->group('sender_id, receiver_id')
                ->count();

            // 处理会话列表，获取对方用户信息和最新消息
            $conversationList = [];
            foreach ($conversations as $conversation) {
                // 确定对方用户ID
                $otherUserId = $conversation['sender_id'] == $userId ? $conversation['receiver_id'] : $conversation['sender_id'];

                // 获取对方用户信息
                $otherUser = Db::name('user')
                    ->where('id', $otherUserId)
                    ->field('id, nickname, avatar')
                    ->find();

                if (!$otherUser) {
                    continue;
                }

                // 获取最新消息
                $lastMessage = Db::name('messages')
                    ->where(function($query) use ($userId, $otherUserId) {
                        $query->where(['sender_id' => $userId, 'receiver_id' => $otherUserId])
                              ->whereOr(['sender_id' => $otherUserId, 'receiver_id' => $userId]);
                    })
                    ->order('create_time desc')
                    ->find();

                // 获取未读消息数
                $unreadCount = Db::name('messages')
                    ->where(['sender_id' => $otherUserId, 'receiver_id' => $userId, 'is_read' => 0])
                    ->count();
                
                $conversationList[] = [
                    'user' => $otherUser,
                    'last_message' => $lastMessage,
                    'unread_count' => $unreadCount
                ];
            }

            return $this->success([
                'list' => $conversationList,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ], '获取会话列表成功');
        } catch (Exception $e) {
            return $this->error('获取会话列表失败: ' . $e->getMessage());
        }
    }

    // 上传文件（支持图片、视频）
    public function uploadFile()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $file = request()->file('file');
            if (!$file) {
                return $this->badRequest('请选择要上传的文件');
            }

            $fileType = $file->getMime();
            $allowedTypes = [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/jpg',
                'video/mp4',
                'video/webm',
                'video/ogg'
            ];

            if (!in_array($fileType, $allowedTypes)) {
                return $this->badRequest('不支持的文件类型');
            }

            $maxSize = 10 * 1024 * 1024;
            if ($file->getSize() > $maxSize) {
                return $this->badRequest('文件大小不能超过10MB');
            }

            // 根据文件类型选择保存目录
            if (strpos($fileType, 'image') !== false) {
                $uploadDir = 'uploads/images';
            } else {
                $uploadDir = 'uploads/videos';
            }

            // 创建目录
            $savePath = app()->getRootPath() . 'public/' . $uploadDir . '/' . date('Ym');
            if (!is_dir($savePath)) {
                mkdir($savePath, 0755, true);
            }

            // 生成文件名
            $ext = $file->extension();
            $filename = $userId . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;

            // 生成访问URL
            $fileUrl = '/' . $uploadDir . '/' . date('Ym') . '/' . $filename;
            $fullFilePath = $savePath . '/' . $filename;

            // 如果是图片，进行压缩处理
            $finalFileSize = $file->getSize();
            if (strpos($fileType, 'image') !== false && function_exists('imagecreatefromjpeg')) {
                try {
                    // 从配置中读取压缩参数
                    $compressEnabled = Db::name('system_config')->where('config_key', 'image_compress_enabled')->value('config_value');
                    if ($compressEnabled === '0') {
                        // 压缩功能已关闭，直接保存文件
                        $file->move($savePath, $filename);
                    } else {
                        // 压缩功能已开启
                        $maxWidth = (int)Db::name('system_config')->where('config_key', 'image_max_width')->value('config_value') ?: 640;
                        $maxHeight = (int)Db::name('system_config')->where('config_key', 'image_max_height')->value('config_value') ?: 640;
                        $quality = (int)Db::name('system_config')->where('config_key', 'image_compress_quality')->value('config_value') ?: 70;

                        $imageInfo = getimagesize($file->getPathname());
                        if ($imageInfo) {
                            $srcWidth = $imageInfo[0];
                            $srcHeight = $imageInfo[1];

                            // 如果图片尺寸超过限制，进行压缩
                            if ($srcWidth > $maxWidth || $srcHeight > $maxHeight) {
                                $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
                                $dstWidth = (int)($srcWidth * $ratio);
                                $dstHeight = (int)($srcHeight * $ratio);

                                // 根据图片类型创建图像资源
                                switch ($imageInfo[2]) {
                                    case IMAGETYPE_JPEG:
                                        $srcImage = imagecreatefromjpeg($file->getPathname());
                                        $dstImage = imagecreatetruecolor($dstWidth, $dstHeight);
                                        break;
                                    case IMAGETYPE_PNG:
                                        $srcImage = imagecreatefrompng($file->getPathname());
                                        $dstImage = imagecreatetruecolor($dstWidth, $dstHeight);
                                        // 保持PNG透明度
                                        imagealphablending($dstImage, false);
                                        imagesavealpha($dstImage, true);
                                        $transparent = imagecolorallocatealpha($dstImage, 255, 255, 255, 127);
                                        imagefilledrectangle($dstImage, 0, 0, $dstWidth, $dstHeight, $transparent);
                                        break;
                                    case IMAGETYPE_GIF:
                                        $srcImage = imagecreatefromgif($file->getPathname());
                                        $dstImage = imagecreatetruecolor($dstWidth, $dstHeight);
                                        // 保持GIF透明度
                                        imagealphablending($dstImage, false);
                                        imagesavealpha($dstImage, true);
                                        $transparent = imagecolorallocatealpha($dstImage, 255, 255, 255, 127);
                                        imagefilledrectangle($dstImage, 0, 0, $dstWidth, $dstHeight, $transparent);
                                        break;
                                    default:
                                        $srcImage = null;
                                        $dstImage = null;
                                }

                                if ($srcImage && $dstImage) {
                                    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $dstWidth, $dstHeight, $srcWidth, $srcHeight);

                                    // 保存压缩后的图片
                                    switch ($imageInfo[2]) {
                                        case IMAGETYPE_JPEG:
                                            imagejpeg($dstImage, $fullFilePath, $quality);
                                            break;
                                        case IMAGETYPE_PNG:
                                            imagepng($dstImage, $fullFilePath, round(9 * ($quality / 100)));
                                            break;
                                        case IMAGETYPE_GIF:
                                            imagegif($dstImage, $fullFilePath);
                                            break;
                                    }

                                    imagedestroy($srcImage);
                                    imagedestroy($dstImage);

                                    // 获取压缩后的大小
                                    clearstatcache();
                                    if (file_exists($fullFilePath)) {
                                        $finalFileSize = filesize($fullFilePath);
                                    }
                                }
                            } else {
                                // 图片尺寸符合要求，直接保存
                                $file->move($savePath, $filename);
                            }
                        } else {
                            // 无法获取图片信息，直接保存
                            $file->move($savePath, $filename);
                        }
                    }
                } catch (\Exception $e) {
                    // 压缩失败，直接保存原文件
                    error_log('图片压缩失败，保存原文件: ' . $e->getMessage());
                    $file->move($savePath, $filename);
                }
            } else {
                // 非图片文件或GD库未安装，直接保存
                $file->move($savePath, $filename);
            }

            // 将文件信息记录到storage_files表
            try {
                $fileInfo = [
                    'user_id' => $userId,
                    'filename' => $filename,
                    'filepath' => $fileUrl,
                    'filesize' => $finalFileSize,
                    'mimetype' => $fileType,
                    'storage_type' => 'local',
                    'status' => 1,
                    'create_time' => time()
                ];
                Db::name('storage_files')->insert($fileInfo);
            } catch (\Exception $e) {
                // 记录失败不影响上传流程，但需要输出错误信息用于调试
                error_log('记录文件信息到storage_files表失败: ' . $e->getMessage());
                error_log('文件信息: ' . json_encode($fileInfo));
            }

            return $this->success([
                'url' => $fileUrl,
                'type' => $fileType
            ], '上传成功');
        } catch (\Exception $e) {
            return $this->error('文件上传失败: ' . $e->getMessage());
        }
    }

    // 收藏/取消收藏动态
    public function collect()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['moment_id'])) {
                return $this->badRequest('动态ID不能为空');
            }

            $momentId = $data['moment_id'];
            $action = $data['action'] ?? 'collect'; // collect 或 uncollect

            // 检查动态是否存在
            $moment = Db::name('moments')
                ->where('id', $momentId)
                ->where('status', 1)
                ->find();

            if (!$moment) {
                return $this->notFound('动态不存在');
            }

            $existing = Db::name('favorites')
                ->where('user_id', $userId)
                ->where('target_id', $momentId)
                ->where('target_type', 1)
                ->find();

            if ($action == 'collect') {
                if ($existing) {
                    return $this->success(['collected' => true], '已收藏');
                }

                Db::name('favorites')->insert([
                    'user_id' => $userId,
                    'target_id' => $momentId,
                    'target_type' => 1,
                    'create_time' => time()
                ]);

                Db::name('moments')->where('id', $momentId)->inc('favorites')->update();

                return $this->success(['collected' => true], '收藏成功');
            } else {
                if (!$existing) {
                    return $this->success(['collected' => false], '未收藏');
                }

                Db::name('favorites')
                    ->where('user_id', $userId)
                    ->where('target_id', $momentId)
                    ->where('target_type', 1)
                    ->delete();

                Db::name('moments')->where('id', $momentId)->dec('favorites')->update();

                return $this->success(['collected' => false], '取消收藏成功');
            }
        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }

    // 获取未读消息数
    public function unread()
    {
        try {
            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->success(['count' => 0], '未登录');
            }

            try {
                $test = Db::name('user')->count();
            } catch (\Exception $e) {
                return $this->error('数据库连接失败: ' . $e->getMessage());
            }

            try {
                $unreadCount = Db::name('messages')
                    ->where('receiver_id', $userId)
                    ->where('is_read', 0)
                    ->count();
            } catch (\Exception $e) {
                $unreadCount = 0;
            }

            return $this->success(['count' => $unreadCount], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取未读消息失败: ' . $e->getMessage());
        }
    }

    /**
     * API: 获取动态列表(为前端提供)
     */
    public function apiList()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json');

            $page = (int) ($_GET['page'] ?? 1);
            $limit = (int) ($_GET['limit'] ?? 10);
            $offset = ($page - 1) * $limit;
            $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;

            $currentUserId = Session::get('user_id') ?: cookie('user_id');

            // 查询动态
            $query = Db::name('moments')
                ->alias('m')
                ->join('user u', 'm.user_id = u.id', 'LEFT')
                ->field('m.id as id, m.user_id, u.nickname, u.username, u.avatar, m.content, m.images, m.likes, m.comments as comments, m.create_time')
                ->where('m.status', 1);

            // 如果指定了用户ID，只查询该用户的动态
            if ($userId) {
                $query->where('m.user_id', $userId);
            } else {
                // 否则根据隐私设置和关注关系查询
                if ($currentUserId) {
                    $followingIds = Db::name('follows')
                        ->where('follower_id', $currentUserId)
                        ->where('status', 1)
                        ->column('following_id');

                    $query->where(function($q) use ($currentUserId, $followingIds) {
                        $q->where('m.privacy', 1)
                          ->whereOr('m.user_id', $currentUserId)
                          ->whereOr(function($subQ) use ($followingIds) {
                              $subQ->where('m.privacy', 3)->whereIn('m.user_id', $followingIds);
                          });
                    });
                } else {
                    $query->where('m.privacy', 1);
                }
            }

            $query->order('m.create_time desc')->limit($offset, $limit);
            $moments = $query->select();

            // 格式化时间和图片
            $resultMoments = [];
            foreach ($moments as $moment) {
                $formattedMoment = [];
                foreach ($moment as $key => $value) {
                    $formattedMoment[$key] = $value;
                }

                $formattedMoment['create_time'] = $this->formatTime($formattedMoment['create_time']);
                $formattedMoment['is_author'] = ($currentUserId == $formattedMoment['user_id']);

                // 解析图片字段
                if (!empty($formattedMoment['images'])) {
                    $formattedMoment['images'] = json_decode($formattedMoment['images'], true);
                } else {
                    $formattedMoment['images'] = [];
                }

                $resultMoments[] = $formattedMoment;
            }

            $moments = $resultMoments;

            return $this->success($moments, '获取成功');
        } catch (\Exception $e) {
            error_log("apiList - Error: " . $e->getMessage());
            return $this->error('获取动态失败: ' . $e->getMessage());
        }
    }

    /**
     * API: 获取推荐用户
     */
    public function recommendedUsers()
    {
        try {
            \think\facade\App::debug(false);
            
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json');

            $currentUserId = Session::get('user_id') ?: Cookie::get('user_id');

            $query = Db::name('user')
                ->where('status', 1);

            if ($currentUserId) {
                $query->where('id', '<>', $currentUserId);
            }

            $users = $query->select()->toArray();
            $allUserIds = array_column($users, 'id');

            $followingIds = [];
            if ($currentUserId && !empty($allUserIds)) {
                $followingIds = Db::name('follows')
                    ->where('follower_id', $currentUserId)
                    ->where('status', 1)
                    ->where('following_id', 'in', $allUserIds)
                    ->column('following_id');
            }

            if (!empty($followingIds)) {
                $allUserIds = array_diff($allUserIds, $followingIds);
            }

            $recommendedUsers = [];
            if (!empty($allUserIds)) {
                shuffle($allUserIds);
                $randomIds = array_slice($allUserIds, 0, 5);

                $idToUser = array_column($users, null, 'id');
                foreach ($randomIds as $id) {
                    if (isset($idToUser[$id])) {
                        $user = $idToUser[$id];
                        $user['avatar'] = $user['avatar'] ?? '/static/images/default-avatar.png';
                        $user['nickname'] = $user['nickname'] ?? $user['username'];
                        $user['bio'] = $user['bio'] ?? '暂无简介';
                        $user['is_following'] = false;
                        $recommendedUsers[] = $user;
                    }
                }
            }

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            return $this->success($recommendedUsers, '获取成功');
        } catch (\Exception $e) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            return $this->error('获取推荐用户失败: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * API: 获取热门话题
     */
    public function hotTopics()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json');

            // 从topics表查询真实的热门话题数据
            $topics = Db::name('topics')
                ->field('id, name, post_count as count, description')
                ->where('status', 1)
                ->order('post_count', 'desc')
                ->limit(10)
                ->select();

            // 如果没有数据，返回空数组
            if (empty($topics)) {
                $topics = [];
            }

            return $this->success($topics, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取热门话题失败: ' . $e->getMessage());
        }
    }

    /**
     * API: 获取活动列表
     */
    public function activities()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $now = time();

            // 从activities表查询进行中的活动
            // 状态说明：0-未发布，1-进行中，2-已结束，3-已取消
            $activities = Db::name('activities')
                ->field('id, title, cover_image as image, participant_count as participants, start_time, end_time, status, type, location')
                ->where('status', 1)  // 只查询进行中的活动
                ->where(function($query) use ($now) {
                    // 检查时间范围
                    $query->where('start_time', '<=', $now)
                          ->where('end_time', '>=', $now);
                })
                ->order('is_hot', 'desc')  // 热门活动优先
                ->order('participant_count', 'desc')  // 参与人数多的优先
                ->order('sort', 'desc')  // 排序权重
                ->order('id', 'desc')
                ->limit(10)
                ->select();

            // 如果没有数据，返回空数组
            if (empty($activities)) {
                $activities = [];
            }

            return $this->success($activities, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取活动失败: ' . $e->getMessage());
        }
    }

    /**
     * API: 获取在线用户
     */
    public function onlineUsers()
    {
        try {
            $onlineThreshold = time() - 300;

            $onlineUsers = Db::name('user')
                ->where('is_online', 1)
                ->where('last_heartbeat_time', '>=', $onlineThreshold)
                ->field('id, username, nickname, avatar')
                ->select()
                ->toArray();

            foreach ($onlineUsers as &$user) {
                $user['avatar'] = $user['avatar'] ?? '/static/images/default-avatar.png';
                $user['nickname'] = $user['nickname'] ?? $user['username'];
            }

            return $this->success([
                'users' => $onlineUsers,
                'count' => count($onlineUsers)
            ]);

        } catch (\Exception $e) {
            return $this->error('获取在线用户失败: ' . $e->getMessage());
        }
    }

    /**
     * 格式化时间为相对时间
     */
    private function formatTime($time)
    {
        $timestamp = is_numeric($time) ? $time : strtotime($time);
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return '刚刚';
        } elseif ($diff < 3600) {
            return floor($diff / 60) . '分钟前';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . '小时前';
        } elseif ($diff < 2592000) {
            return floor($diff / 86400) . '天前';
        } else {
            return date('Y-m-d', $timestamp);
        }
    }

    /**
     * 清理过期草稿
     */
    public function clearExpiredDrafts()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            // 清理30天前的草稿
            $expiredTime = date('Y-m-d H:i:s', strtotime('-30 days'));
            $result = Db::name('moment_drafts')
                ->where('user_id', $userId)
                ->where('updated_time', '<', $expiredTime)
                ->delete();

            return $this->success(['deleted' => $result], '清理过期草稿成功');
        } catch (\Exception $e) {
            return $this->error('清理过期草稿失败: ' . $e->getMessage());
        }
    }

    /**
     * 修复所有动态的点赞数
     */
    public function fixLikes()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            // 检查用户是否有管理员权限（这里简化处理，假设所有登录用户都可以修复）
            // 在实际应用中，应该检查用户权限

            // 查找数据不一致的动态
            $inconsistentMoments = Db::name('moments')
                ->alias('m')
                ->field('m.id, m.likes as moment_likes, (SELECT COUNT(*) FROM qz_likes WHERE target_id = m.id AND target_type = 1) as actual_likes')
                ->where('m.status', 1)
                ->where('m.likes <> (SELECT COUNT(*) FROM qz_likes WHERE target_id = m.id AND target_type = 1)')
                ->select()
                ->toArray();

            $fixedCount = 0;

            if (!empty($inconsistentMoments)) {
                // 批量修复点赞数
                foreach ($inconsistentMoments as $moment) {
                    Db::name('moments')
                        ->where('id', $moment['id'])
                        ->update(['likes' => $moment['actual_likes']]);
                    $fixedCount++;
                }
            }

            return $this->success(['fixed' => $fixedCount], "修复完成，共修复 {$fixedCount} 条动态的点赞数");
        } catch (\Exception $e) {
            return $this->error('修复点赞数失败: ' . $e->getMessage());
        }
    }

    /**
     * 创建 wx_mentions 表（用于@提及功能）
     */
    public function createMentionsTable()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 检查表是否已存在
            $tableName = config('database.connections.mysql.prefix') . 'mentions';
            $checkSql = "SHOW TABLES LIKE '{$tableName}'";
            $result = Db::query($checkSql);

            if (!empty($result)) {
                return $this->success(null, "表 {$tableName} 已存在");
            }

            $createSql = "
            CREATE TABLE `{$tableName}` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `moment_id` int(11) NOT NULL COMMENT '动态ID',
              `user_id` int(11) NOT NULL COMMENT '发布动态的用户ID',
              `mentioned_user_id` int(11) NOT NULL COMMENT '被@的用户ID',
              `nickname` varchar(50) NOT NULL COMMENT '被@用户的昵称',
              `avatar` varchar(255) DEFAULT '' COMMENT '被@用户的头像',
              `content` text COMMENT '相关内容',
              `create_time` int(11) NOT NULL COMMENT '创建时间',
              `read_status` tinyint(1) DEFAULT '0' COMMENT '阅读状态:0未读,1已读',
              `read_time` int(11) DEFAULT '0' COMMENT '阅读时间',
              PRIMARY KEY (`id`),
              KEY `moment_id` (`moment_id`),
              KEY `user_id` (`user_id`),
              KEY `mentioned_user_id` (`mentioned_user_id`),
              KEY `create_time` (`create_time`),
              KEY `read_status` (`read_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动态@提及表';
            ";

            Db::execute($createSql);

            return $this->success(['table' => $tableName], "表 {$tableName} 创建成功！");
        } catch (\Exception $e) {
            return $this->error('创建表失败: ' . $e->getMessage());
        }
    }

    /**
     * 格式化动态内容，将@用户昵称替换为可点击链接
     * @param string $content 动态内容
     * @param array $mentions 提及的用户列表
     * @return string 格式化后的内容
     */
    private function formatMentionContent($content, $mentions = [])
    {
        if (empty($content)) {
            return '';
        }

        // 如果没有mentions数据，不做处理
        if (empty($mentions)) {
            return $content;
        }

        // 为每个mention创建替换数组
        foreach ($mentions as $mention) {
            $nickname = $mention['nickname'] ?? '';
            $userId = $mention['user_id'] ?? 0;

            if (empty($nickname) || empty($userId)) {
                continue;
            }

            // 转义特殊字符
            $escapedNickname = preg_quote($nickname, '/');

            // 替换@nickname为可点击链接
            $pattern = '/@' . $escapedNickname . '/u';
            $replacement = '<a href="javascript:void(0)" class="mention-link text-blue-500 font-medium hover:underline cursor-pointer" data-mention-user-id="' . $userId . '">@' . htmlspecialchars($nickname) . '</a>';

            $content = preg_replace($pattern, $replacement, $content, 1); // 只替换第一次出现
        }

        return $content;
    }

    /**
     * 解析动态内容中的话题
     * @param string $content 动态内容
     * @return array 话题名称数组
     */
    private function parseTopics($content)
    {
        if (empty($content)) {
            return [];
        }

        // 先清理内容中的 HTML 实体和标签，避免它们被包含在话题名称中
        $cleanContent = strip_tags($content);
        $cleanContent = html_entity_decode($cleanContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 匹配 #话题名 格式的话题（单个#号）
        $pattern = '/#([^\s#]+)/u';
        preg_match_all($pattern, $cleanContent, $matches);

        // 返回匹配到的话题名称（去重）
        if (!empty($matches[1])) {
            return array_unique($matches[1]);
        }

        return [];
    }

    /**
     * 格式化动态内容中的话题为可点击链接
     * @param string $content 动态内容
     * @param array $topics 话题数组
     * @return string 格式化后的内容
     */
    private function formatTopicContent($content, $topics = [])
    {
        if (empty($content)) {
            return '';
        }

        // 如果没有topics数据，不做处理
        if (empty($topics)) {
            return $content;
        }

        // 为每个topic创建替换数组
        foreach ($topics as $topic) {
            $topicName = $topic['topic_name'] ?? '';
            $topicId = $topic['topic_id'] ?? 0;

            if (empty($topicName) || empty($topicId)) {
                continue;
            }

            // 转义特殊字符
            $escapedTopicName = preg_quote($topicName, '/');

            // 替换#话题名为可点击链接（单个#号）
            $pattern = '/#' . $escapedTopicName . '(?=[\s#]|$)/u';
            $replacement = '<a href="javascript:void(0)" class="topic-link text-blue-500 font-medium hover:underline cursor-pointer" data-topic-id="' . $topicId . '" data-topic-name="' . htmlspecialchars($topicName) . '">#' . htmlspecialchars($topicName) . '</a>';

            $content = preg_replace($pattern, $replacement, $content, 1); // 只替换第一次出现
        }

        // 去除话题链接后面紧跟的&nbsp;（如果不换行空格在话题链接后面且后面没有其他内容）
        // 匹配 </a>&nbsp; 后面跟着空格、换行、标签结尾或字符串结尾
        $content = preg_replace('/(<\/a>)&nbsp;(\s*$|<\/?[^>]+>)/', '$1$2', $content);

        // 如果话题链接后紧跟&nbsp;并且是内容末尾，去掉这个&nbsp;
        $content = preg_replace('/(<\/a>)&nbsp;\s*$/', '$1', $content);

        return $content;
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

            // 获取搜索关键词
            $keyword = input('keyword', '');

            if (empty($keyword)) {
                return $this->badRequest('搜索关键词不能为空');
            }

            // 查询话题
            $topics = Db::name('topics')
                ->field('id, name, description, cover as image, post_count as posts_count, follower_count as followers_count, create_time, status')
                ->where('name', 'like', '%' . $keyword . '%')
                ->where('status', 1)
                ->order('post_count', 'desc')
                ->order('follower_count', 'desc')
                ->limit(20)
                ->select();

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
                'total' => count($topics)
            ], 'success');
        } catch (\Exception $e) {
            return $this->error('搜索话题失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除动态
     */
    public function delete()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $momentId = input('id', 0);
            if (!$momentId) {
                return $this->badRequest('参数错误');
            }

            $moment = Db::name('moments')->where('id', $momentId)->find();
            if (!$moment) {
                return $this->notFound('动态不存在');
            }

            if ($moment['user_id'] != $userId) {
                return $this->error('无权删除此动态', 403);
            }

            Db::name('moments')->where('id', $momentId)->delete();
            Db::name('comments')->where('moment_id', $momentId)->delete();
            Db::name('likes')->where('moment_id', $momentId)->delete();
            Db::name('collects')->where('moment_id', $momentId)->delete();
            Db::name('moment_topics')->where('moment_id', $momentId)->delete();

            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 隐藏动态（不感兴趣）
     */
    public function hide()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $momentId = input('id', 0);
            if (!$momentId) {
                return $this->badRequest('参数错误');
            }

            $exists = Db::name('hidden_moments')
                ->where('user_id', $userId)
                ->where('moment_id', $momentId)
                ->find();

            if (!$exists) {
                Db::name('hidden_moments')->insert([
                    'user_id' => $userId,
                    'moment_id' => $momentId,
                    'create_time' => time()
                ]);
            }

            return $this->success(null, '已隐藏该动态');
        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }

    public function top()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $momentId = input('id', 0);
            if (!$momentId) {
                return $this->badRequest('参数错误');
            }

            $moment = Db::name('moments')->where('id', $momentId)->find();
            if (!$moment) {
                return $this->notFound('动态不存在');
            }

            if ($moment['user_id'] != $userId) {
                return $this->error('无权置顶此动态', 403);
            }

            $newTopStatus = $moment['is_top'] == 1 ? 0 : 1;
            Db::name('moments')->where('id', $momentId)->update(['is_top' => $newTopStatus]);

            return $this->success(null, $newTopStatus == 1 ? '置顶成功' : '取消置顶成功');
        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }

    public function report()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $momentId = input('id', 0);
            if (!$momentId) {
                return $this->badRequest('参数错误');
            }

            $moment = Db::name('moments')->where('id', $momentId)->find();
            if (!$moment) {
                return $this->notFound('动态不存在');
            }

            $exists = Db::name('reports')
                ->where('user_id', $userId)
                ->where('target_id', $momentId)
                ->where('type', 'moment')
                ->find();

            if ($exists) {
                return $this->badRequest('您已经举报过该动态');
            }

            Db::name('reports')->insert([
                'user_id' => $userId,
                'target_id' => $momentId,
                'type' => 'moment',
                'reason' => input('reason', '违规内容'),
                'status' => 0,
                'create_time' => time()
            ]);

            return $this->success(null, '举报成功，我们会尽快处理');
        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }

    public function visibility()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $momentId = input('id', 0);
            $visibility = input('visibility', 'public');

            if (!$momentId) {
                return $this->badRequest('参数错误');
            }

            $validVisibilities = ['public', 'friends', 'private'];
            if (!in_array($visibility, $validVisibilities)) {
                return $this->badRequest('无效的可见性设置');
            }

            $moment = Db::name('moments')->where('id', $momentId)->find();
            if (!$moment) {
                return $this->notFound('动态不存在');
            }

            if ($moment['user_id'] != $userId) {
                return $this->error('无权修改此动态', 403);
            }

            $privacyMap = [
                'public' => 1,
                'friends' => 3,
                'private' => 2
            ];

            Db::name('moments')->where('id', $momentId)->update(['privacy' => $privacyMap[$visibility]]);

            return $this->success(null, '设置成功');
        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }
}
