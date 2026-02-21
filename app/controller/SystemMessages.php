<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\Request;

class SystemMessages extends BaseFrontendController
{
    /**
     * 获取系统消息列表
     */
    public function getSystemMessages()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        
        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $page = (int)input('page', 1);
            $pageSize = (int)input('page_size', 20);
            $type = input('type', '');
            $isRead = input('is_read', '');

            $where = [
                ['user_id', '=', $userId],
                function($query) {
                    $query->where('expire_time', '=', 0)
                          ->whereOr('expire_time', '>', time());
                }
            ];

            if (!empty($type)) {
                $where[] = ['type', '=', $type];
            }

            if ($isRead !== '') {
                $where[] = ['is_read', '=', (int)$isRead];
            }

            $total = Db::name('system_messages')
                ->where($where)
                ->where(function($query) {
                    $query->where('expire_time', '=', 0)
                          ->whereOr('expire_time', '>', time());
                })
                ->count();

            $list = Db::name('system_messages')
                ->where($where)
                ->where(function($query) {
                    $query->where('expire_time', '=', 0)
                          ->whereOr('expire_time', '>', time());
                })
                ->order('priority DESC, create_time DESC')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            return $this->success([
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize
            ], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 标记系统消息为已读
     */
    public function markAsRead()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        
        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $messageId = (int)input('message_id');

            if (empty($messageId)) {
                return $this->badRequest('消息ID不能为空');
            }

            $result = Db::name('system_messages')
                ->where('id', $messageId)
                ->where('user_id', $userId)
                ->update([
                    'is_read' => 1,
                    'read_time' => time()
                ]);

            if ($result) {
                return $this->success(null, '标记成功');
            } else {
                return $this->badRequest('消息不存在或已删除');
            }
        } catch (\Exception $e) {
            return $this->error('标记失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量标记为已读
     */
    public function markAllAsRead()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        
        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $result = Db::name('system_messages')
                ->where('user_id', $userId)
                ->where('is_read', 0)
                ->update([
                    'is_read' => 1,
                    'read_time' => time()
                ]);

            return $this->success(['affected' => $result], '标记成功');
        } catch (\Exception $e) {
            return $this->error('标记失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除系统消息
     */
    public function deleteMessage()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        
        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $messageId = (int)input('message_id');

            if (empty($messageId)) {
                return $this->badRequest('消息ID不能为空');
            }

            $result = Db::name('system_messages')
                ->where('id', $messageId)
                ->where('user_id', $userId)
                ->delete();

            if ($result) {
                return $this->success(null, '删除成功');
            } else {
                return $this->badRequest('消息不存在或已删除');
            }
        } catch (\Exception $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取未读系统消息数量
     */
    public function getUnreadCount()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        
        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $count = Db::name('system_messages')
                ->where('user_id', $userId)
                ->where('is_read', 0)
                ->where(function($query) {
                    $query->where('expire_time', '=', 0)
                          ->whereOr('expire_time', '>', time());
                })
                ->count();

            return $this->success(['unread_count' => $count], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 发送系统消息（管理员功能）
     */
    public function sendSystemMessage()
    {
        $adminId = session('admin_id');
        
        if (empty($adminId)) {
            return $this->unauthorized();
        }

        try {
            $userId = (int)input('user_id', 0);
            $type = input('type', 'system');
            $title = input('title', '');
            $content = input('content', '');
            $actionUrl = input('action_url', '');
            $actionText = input('action_text', '');
            $priority = (int)input('priority', 0);
            $expireTime = (int)input('expire_time', 0);

            if (empty($title) || empty($content)) {
                return $this->badRequest('标题和内容不能为空');
            }

            $data = [
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'content' => $content,
                'action_url' => $actionUrl,
                'action_text' => $actionText,
                'priority' => $priority,
                'expire_time' => $expireTime,
                'create_time' => time()
            ];

            $messageId = Db::name('system_messages')->insertGetId($data);

            return $this->success(['message_id' => $messageId], '发送成功');
        } catch (\Exception $e) {
            return $this->error('发送失败: ' . $e->getMessage());
        }
    }
}
