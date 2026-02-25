<?php
namespace app\service;

use think\facade\Db;
use think\facade\Log;

class CommentService
{
    const STATUS_DELETED = 0;
    const STATUS_NORMAL = 1;
    const STATUS_FOLDED = 2;
    const STATUS_PENDING = 3;

    const TARGET_TYPE_MOMENT = 1;
    const TARGET_TYPE_COMMENT = 2;

    const NOTIFICATION_TYPE_COMMENT = 2;

    protected static $commentFields = 'c.id, c.moment_id, c.user_id, c.content, c.likes, c.replies, c.status, c.parent_id, c.is_top, c.is_hot, c.is_author, c.reply_to_user_id, c.reply_to_nickname, c.media, c.create_time, c.update_time, u.username, u.nickname, u.avatar';

    protected static $replyFields = 'c.id, c.moment_id, c.user_id, c.content, c.likes, c.status, c.parent_id, c.is_author, c.reply_to_user_id, c.reply_to_nickname, c.create_time, u.username, u.nickname, u.avatar';

    public static function getCommentList($params, $currentUserId = null)
    {
        $momentId = $params['moment_id'] ?? 0;
        $page = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 10;

        if (!$momentId) {
            return [
                'code' => 400,
                'msg' => '动态ID不能为空',
                'data' => []
            ];
        }

        try {
            $momentAuthorId = Db::name('moments')
                ->where('id', $momentId)
                ->value('user_id');

            $topLevelComments = Db::name('comments')
                ->alias('c')
                ->join('user u', 'c.user_id = u.id')
                ->where('c.moment_id', $momentId)
                ->where(function($query) {
                    $query->where('c.parent_id', 0)->whereOr('c.parent_id', null);
                })
                ->where('c.status', self::STATUS_NORMAL)
                ->field(self::$commentFields)
                ->order('c.is_top DESC, c.create_time DESC')
                ->page($page, $limit)
                ->select()
                ->toArray();

            $topLevelIds = array_column($topLevelComments, 'id');

            $replies = [];
            if (!empty($topLevelIds)) {
                $replies = Db::name('comments')
                    ->alias('c')
                    ->join('user u', 'c.user_id = u.id')
                    ->where('c.moment_id', $momentId)
                    ->where('c.parent_id', 'in', $topLevelIds)
                    ->where('c.status', self::STATUS_NORMAL)
                    ->field(self::$replyFields)
                    ->order('c.create_time ASC')
                    ->select()
                    ->toArray();
            }

            $parentComments = [];
            $parentCommentIds = [];
            foreach ($replies as $reply) {
                $parentId = $reply['parent_id'] ?? null;
                if ($parentId && $parentId > 0) {
                    $parentCommentIds[] = $parentId;
                }
            }
            $parentCommentIds = array_unique($parentCommentIds);

            if (!empty($parentCommentIds)) {
                $parentComments = Db::name('comments')
                    ->where('id', 'in', $parentCommentIds)
                    ->column('nickname', 'id');
            }

            foreach ($topLevelComments as $key => $comment) {
                $topLevelComments[$key]['is_author'] = ($comment['user_id'] == $momentAuthorId) ? 1 : 0;
                $topLevelComments[$key]['reply_nickname'] = null;
                $topLevelComments[$key]['replies'] = [];
                $topLevelComments[$key]['like_count'] = $comment['likes'] ?? 0;
                $topLevelComments[$key]['reply_count'] = $comment['replies'] ?? 0;
            }

            $topLevelMap = [];
            foreach ($topLevelComments as $key => $comment) {
                $topLevelMap[$comment['id']] = &$topLevelComments[$key];
            }
            unset($comment);

            foreach ($replies as &$reply) {
                $reply['is_author'] = ($reply['user_id'] == $momentAuthorId) ? 1 : 0;
                $reply['like_count'] = $reply['likes'] ?? 0;

                if ($reply['reply_to_user_id'] && $reply['reply_to_user_id'] > 0) {
                    $reply['reply_nickname'] = $reply['reply_to_nickname'] ?? '';
                } else {
                    $reply['reply_nickname'] = null;
                }

                $reply['replies'] = [];

                $parentId = $reply['parent_id'] ?? null;
                if ($parentId && isset($topLevelMap[$parentId])) {
                    $topLevelMap[$parentId]['replies'][] = $reply;
                }
            }

            $commentTree = $topLevelComments;

            if ($currentUserId && !empty($commentTree)) {
                $allCommentIds = [];
                $allUserIds = [];
                foreach ($commentTree as $comment) {
                    $allCommentIds[] = $comment['id'];
                    $allUserIds[] = $comment['user_id'];
                    if (!empty($comment['replies'])) {
                        foreach ($comment['replies'] as $reply) {
                            $allCommentIds[] = $reply['id'];
                            $allUserIds[] = $reply['user_id'];
                        }
                    }
                }
                $allUserIds = array_unique($allUserIds);

                // 查询点赞状态
                $likedCommentIds = Db::name('likes')
                    ->where('user_id', $currentUserId)
                    ->where('target_id', 'in', $allCommentIds)
                    ->where('target_type', self::TARGET_TYPE_COMMENT)
                    ->column('target_id');

                // 查询关注关系
                if (!empty($allUserIds)) {
                    $followingUserIds = Db::name('follows')
                        ->where('follower_id', $currentUserId)
                        ->where('following_id', 'in', $allUserIds)
                        ->where('status', 1)
                        ->column('following_id');

                    $followerUserIds = Db::name('follows')
                        ->where('following_id', 'in', $allUserIds)
                        ->where('follower_id', $currentUserId)
                        ->where('status', 1)
                        ->column('follower_id');
                } else {
                    $followingUserIds = [];
                    $followerUserIds = [];
                }

                $setLikeStatus = function(&$comments) use (&$setLikeStatus, $likedCommentIds, $followingUserIds, $followerUserIds) {
                    foreach ($comments as &$comment) {
                        $comment['is_liked'] = in_array($comment['id'], $likedCommentIds) ? 1 : 0;
                        $comment['is_following'] = in_array($comment['user_id'], $followingUserIds) ? 1 : 0;
                        $comment['is_follower'] = in_array($comment['user_id'], $followerUserIds) ? 1 : 0;
                        if (!empty($comment['replies'])) {
                            $setLikeStatus($comment['replies']);
                        }
                    }
                };
                $setLikeStatus($commentTree);
            } else {
                $setLikeStatus = function(&$comments) use (&$setLikeStatus) {
                    foreach ($comments as &$comment) {
                        $comment['is_liked'] = 0;
                        $comment['is_following'] = 0;
                        $comment['is_follower'] = 0;
                        if (!empty($comment['replies'])) {
                            $setLikeStatus($comment['replies']);
                        }
                    }
                };
                $setLikeStatus($commentTree);
            }

            $total = Db::name('comments')
                ->where('moment_id', $momentId)
                ->where(function($query) {
                    $query->where('parent_id', 0)->whereOr('parent_id', null);
                })
                ->where('status', self::STATUS_NORMAL)
                ->count();

            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $commentTree,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ];

        } catch (\Exception $e) {
            Log::error('获取评论列表失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => '获取评论列表失败',
                'data' => []
            ];
        }
    }

    public static function addComment($data, $currentUserId)
    {
        if (!$currentUserId) {
            return [
                'code' => 401,
                'msg' => '未登录'
            ];
        }

        $momentId = $data['moment_id'] ?? 0;
        $content = trim($data['content'] ?? '');
        $parentId = $data['parent_id'] ?? 0;
        $mentionedUsers = $data['mentioned_users'] ?? [];

        if (!$momentId) {
            return [
                'code' => 400,
                'msg' => '动态ID不能为空'
            ];
        }

        if (empty($content)) {
            return [
                'code' => 400,
                'msg' => '评论内容不能为空'
            ];
        }

        try {
            $moment = Db::name('moments')
                ->where('id', $momentId)
                ->where('status', 1)
                ->find();

            if (!$moment) {
                return [
                    'code' => 404,
                    'msg' => '动态不存在'
                ];
            }

            $user = Db::name('user')
                ->where('id', $currentUserId)
                ->field('username, nickname, avatar, can_speak, status')
                ->find();

            if (!$user) {
                return [
                    'code' => 404,
                    'msg' => '用户不存在'
                ];
            }

            // 检查用户状态
            if ($user['status'] != 1) {
                return [
                    'code' => 403,
                    'msg' => '您的账号已被禁用，无法评论'
                ];
            }

            // 检查发言状态
            if ($user['can_speak'] != 1) {
                return [
                    'code' => 403,
                    'msg' => '您已被禁言，无法评论'
                ];
            }

            $replyToUserId = 0;
            $replyToNickname = '';
            if ($parentId > 0) {
                $parentComment = Db::name('comments')
                    ->where('id', $parentId)
                    ->find();
                if ($parentComment) {
                    $replyToUserId = $parentComment['user_id'];
                    $replyToNickname = $parentComment['nickname'] ?? '';
                }
            }

            $isAuthor = ($currentUserId == $moment['user_id']) ? 1 : 0;

            $commentData = [
                'moment_id' => $momentId,
                'user_id' => $currentUserId,
                'nickname' => $user['nickname'] ?? '',
                'avatar' => $user['avatar'] ?? '',
                'content' => $content,
                'parent_id' => $parentId,
                'reply_to_user_id' => $replyToUserId,
                'reply_to_nickname' => $replyToNickname,
                'likes' => 0,
                'replies' => 0,
                'is_top' => 0,
                'is_hot' => 0,
                'is_author' => $isAuthor,
                'status' => self::STATUS_NORMAL,
                'create_time' => time(),
                'update_time' => time()
            ];

            if (isset($data['media']) && !empty($data['media'])) {
                $commentData['media'] = is_array($data['media']) ? implode(',', $data['media']) : $data['media'];
            }

            $commentId = Db::name('comments')->insertGetId($commentData);

            Db::name('moments')->where('id', $momentId)->inc('comments')->update();

            if ($parentId > 0) {
                Db::name('comments')->where('id', $parentId)->inc('replies')->update();
            }

            if ($moment['user_id'] != $currentUserId) {
                self::sendNotification($moment['user_id'], $currentUserId, self::NOTIFICATION_TYPE_COMMENT, '新评论', $user['nickname'] . '评论了你的动态', $momentId, 'moment');
            }

            if ($parentId > 0 && $replyToUserId != $currentUserId) {
                self::sendNotification($replyToUserId, $currentUserId, self::NOTIFICATION_TYPE_COMMENT, '新回复', $user['nickname'] . '回复了你的评论', $momentId, 'moment');
            }

            if (!empty($mentionedUsers) && is_array($mentionedUsers)) {
                foreach ($mentionedUsers as $mentionedUserId) {
                    if ($mentionedUserId != $currentUserId &&
                        $mentionedUserId != $moment['user_id'] &&
                        ($parentId == 0 || $mentionedUserId != $replyToUserId)) {

                        $mentionedUser = Db::name('user')
                            ->where('id', $mentionedUserId)
                            ->field('nickname')
                            ->find();

                        if ($mentionedUser) {
                            self::sendNotification($mentionedUserId, $currentUserId, self::NOTIFICATION_TYPE_COMMENT, '新消息', $user['nickname'] . '在评论中@了你', $momentId, 'moment');
                        }
                    }
                }
            }

            $newComment = Db::name('comments')
                ->alias('c')
                ->join('user u', 'c.user_id = u.id')
                ->where('c.id', $commentId)
                ->field(self::$commentFields)
                ->find();

            $newComment['is_liked'] = 0;
            $newComment['like_count'] = $newComment['likes'] ?? 0;
            $newComment['reply_count'] = $newComment['replies'] ?? 0;
            $newComment['replies'] = [];

            return [
                'code' => 200,
                'msg' => '评论成功',
                'data' => [
                    'id' => $commentId,
                    'comment' => $newComment
                ]
            ];

        } catch (\Exception $e) {
            Log::error('添加评论失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => '评论失败'
            ];
        }
    }

    public static function toggleLike($commentId, $action, $currentUserId)
    {
        if (!$currentUserId) {
            return [
                'code' => 401,
                'msg' => '未登录'
            ];
        }

        if (!$commentId) {
            return [
                'code' => 400,
                'msg' => '评论ID不能为空'
            ];
        }

        if (!in_array($action, ['like', 'unlike'])) {
            return [
                'code' => 400,
                'msg' => '操作类型错误'
            ];
        }

        try {
            $comment = Db::name('comments')
                ->where('id', $commentId)
                ->where('status', self::STATUS_NORMAL)
                ->find();

            if (!$comment) {
                return [
                    'code' => 404,
                    'msg' => '评论不存在'
                ];
            }

            $like = Db::name('likes')
                ->where('user_id', $currentUserId)
                ->where('target_id', $commentId)
                ->where('target_type', self::TARGET_TYPE_COMMENT)
                ->find();

            if ($action === 'like') {
                if ($like) {
                    return [
                        'code' => 400,
                        'msg' => '已经点赞过了'
                    ];
                }

                Db::name('likes')->insert([
                    'user_id' => $currentUserId,
                    'target_id' => $commentId,
                    'target_type' => self::TARGET_TYPE_COMMENT,
                    'create_time' => date('Y-m-d H:i:s')
                ]);

                Db::name('comments')->where('id', $commentId)->inc('likes')->update();

                if ($comment['user_id'] != $currentUserId) {
                    $liker = Db::name('user')
                        ->where('id', $currentUserId)
                        ->field('nickname, avatar')
                        ->find();

                    self::sendNotification($comment['user_id'], $currentUserId, self::NOTIFICATION_TYPE_COMMENT, '新点赞', $liker['nickname'] . '点赞了你的评论', $comment['moment_id'], 'moment');
                }

            } else {
                if (!$like) {
                    return [
                        'code' => 400,
                        'msg' => '还未点赞'
                    ];
                }

                Db::name('likes')
                    ->where('user_id', $currentUserId)
                    ->where('target_id', $commentId)
                    ->where('target_type', self::TARGET_TYPE_COMMENT)
                    ->delete();

                Db::name('comments')->where('id', $commentId)->dec('likes')->update();
            }

            $updatedComment = Db::name('comments')
                ->where('id', $commentId)
                ->value('likes');

            return [
                'code' => 200,
                'msg' => '操作成功',
                'data' => [
                    'likes' => $updatedComment,
                    'liked' => ($action === 'like')
                ]
            ];

        } catch (\Exception $e) {
            Log::error('点赞操作失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => '操作失败'
            ];
        }
    }

    public static function deleteComment($commentId, $currentUserId)
    {
        if (!$currentUserId) {
            return [
                'code' => 401,
                'msg' => '未登录'
            ];
        }

        if (!$commentId) {
            return [
                'code' => 400,
                'msg' => '评论ID不能为空'
            ];
        }

        try {
            $comment = Db::name('comments')
                ->where('id', $commentId)
                ->find();

            if (!$comment) {
                return [
                    'code' => 404,
                    'msg' => '评论不存在'
                ];
            }

            if ($comment['user_id'] != $currentUserId) {
                return [
                    'code' => 403,
                    'msg' => '无权删除此评论'
                ];
            }

            if ($comment['parent_id'] > 0) {
                Db::name('comments')
                    ->where('id', $comment['parent_id'])
                    ->dec('replies')->update();
            }

            Db::name('likes')
                ->where('target_id', $commentId)
                ->where('target_type', self::TARGET_TYPE_COMMENT)
                ->delete();

            Db::name('comments')
                ->where('id', $commentId)
                ->update(['status' => self::STATUS_DELETED]);

            Db::name('moments')->where('id', $comment['moment_id'])->dec('comments')->update();

            return [
                'code' => 200,
                'msg' => '删除成功'
            ];

        } catch (\Exception $e) {
            Log::error('删除评论失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => '删除失败'
            ];
        }
    }

    public static function getReplies($commentId, $params, $currentUserId = null)
    {
        $offset = $params['offset'] ?? 0;
        $limit = $params['limit'] ?? 10;

        if (!$commentId) {
            return [
                'code' => 400,
                'msg' => '评论ID不能为空'
            ];
        }

        try {
            $parentComment = Db::name('comments')
                ->where('id', $commentId)
                ->find();

            if (!$parentComment) {
                return [
                    'code' => 404,
                    'msg' => '评论不存在'
                ];
            }

            $momentAuthorId = Db::name('moments')
                ->where('id', $parentComment['moment_id'])
                ->value('user_id');

            $replies = Db::name('comments')
                ->alias('c')
                ->join('user u', 'c.user_id = u.id')
                ->where('c.moment_id', $parentComment['moment_id'])
                ->where('c.parent_id', $commentId)
                ->where('c.status', self::STATUS_NORMAL)
                ->field(self::$replyFields)
                ->order('c.create_time ASC')
                ->limit($offset, $limit)
                ->select()
                ->toArray();

            foreach ($replies as &$reply) {
                $reply['is_author'] = ($reply['user_id'] == $momentAuthorId) ? 1 : 0;
                $reply['like_count'] = $reply['likes'] ?? 0;

                if ($reply['reply_to_user_id'] && $reply['reply_to_user_id'] > 0) {
                    $reply['reply_nickname'] = $reply['reply_to_nickname'] ?? '';
                } else {
                    $reply['reply_nickname'] = null;
                }
            }

            if ($currentUserId && !empty($replies)) {
                $replyIds = array_column($replies, 'id');
                $replyUserIds = array_column($replies, 'user_id');

                // 查询点赞状态
                $likedReplyIds = Db::name('likes')
                    ->where('user_id', $currentUserId)
                    ->where('target_id', 'in', $replyIds)
                    ->where('target_type', self::TARGET_TYPE_COMMENT)
                    ->column('target_id');

                // 查询关注关系
                $followingUserIds = [];
                $followerUserIds = [];
                if (!empty($replyUserIds)) {
                    $followingUserIds = Db::name('follows')
                        ->where('follower_id', $currentUserId)
                        ->where('following_id', 'in', $replyUserIds)
                        ->where('status', 1)
                        ->column('following_id');

                    $followerUserIds = Db::name('follows')
                        ->where('follower_id', 'in', $replyUserIds)
                        ->where('following_id', $currentUserId)
                        ->where('status', 1)
                        ->column('follower_id');
                }

                foreach ($replies as &$reply) {
                    $reply['is_liked'] = in_array($reply['id'], $likedReplyIds) ? 1 : 0;
                    $reply['is_following'] = in_array($reply['user_id'], $followingUserIds) ? 1 : 0;
                    $reply['is_follower'] = in_array($reply['user_id'], $followerUserIds) ? 1 : 0;
                }
            } else {
                foreach ($replies as &$reply) {
                    $reply['is_liked'] = 0;
                    $reply['is_following'] = 0;
                    $reply['is_follower'] = 0;
                }
            }

            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $replies,
                    'total' => $parentComment['replies']
                ]
            ];

        } catch (\Exception $e) {
            Log::error('获取回复列表失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => '获取回复列表失败'
            ];
        }
    }

    public static function setTopComment($commentId, $isTop, $currentUserId)
    {
        if (!$currentUserId) {
            return [
                'code' => 401,
                'msg' => '未登录'
            ];
        }

        if (!$commentId) {
            return [
                'code' => 400,
                'msg' => '评论ID不能为空'
            ];
        }

        try {
            $comment = Db::name('comments')
                ->where('id', $commentId)
                ->find();

            if (!$comment) {
                return [
                    'code' => 404,
                    'msg' => '评论不存在'
                ];
            }

            if ($comment['parent_id'] > 0) {
                return [
                    'code' => 400,
                    'msg' => '只能置顶一级评论'
                ];
            }

            $moment = Db::name('moments')
                ->where('id', $comment['moment_id'])
                ->find();

            if ($moment['user_id'] != $currentUserId) {
                return [
                    'code' => 403,
                    'msg' => '只有动态作者可以置顶评论'
                ];
            }

            Db::name('comments')
                ->where('id', $commentId)
                ->update(['is_top' => $isTop ? 1 : 0]);

            return [
                'code' => 200,
                'msg' => '操作成功'
            ];

        } catch (\Exception $e) {
            Log::error('置顶评论失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => '操作失败'
            ];
        }
    }

    public static function setHotComment($commentId, $isHot, $currentUserId)
    {
        if (!$currentUserId) {
            return [
                'code' => 401,
                'msg' => '未登录'
            ];
        }

        if (!$commentId) {
            return [
                'code' => 400,
                'msg' => '评论ID不能为空'
            ];
        }

        try {
            $comment = Db::name('comments')
                ->where('id', $commentId)
                ->find();

            if (!$comment) {
                return [
                    'code' => 404,
                    'msg' => '评论不存在'
                ];
            }

            Db::name('comments')
                ->where('id', $commentId)
                ->update(['is_hot' => $isHot ? 1 : 0]);

            return [
                'code' => 200,
                'msg' => '操作成功'
            ];

        } catch (\Exception $e) {
            Log::error('设置热评失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => '操作失败'
            ];
        }
    }

    protected static function sendNotification($userId, $senderId, $type, $title, $content, $targetId, $targetType)
    {
        try {
            Db::name('notifications')->insert([
                'user_id' => $userId,
                'sender_id' => $senderId,
                'type' => $type,
                'title' => $title,
                'content' => $content,
                'target_id' => $targetId,
                'target_type' => $targetType,
                'create_time' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            Log::error('发送通知失败: ' . $e->getMessage());
        }
    }

    public static function getCurrentUserId()
    {
        return session('user_id') ?: cookie('user_id');
    }
}
