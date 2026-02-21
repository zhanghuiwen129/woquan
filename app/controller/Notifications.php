<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Notifications extends BaseFrontendController
{
    // 通知页面
    public function index()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');

        if (!$userId) {
            return redirect('/login');
        }

        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        // 配置信息已在基类中加载
        View::assign([
            'currentUser' => $currentUser,
            'isLogin' => !empty($userId),
            'current_url' => '/notifications'
        ]);
        return View::fetch('index/notifications');
    }
    // 获取通知列表（支持分类筛选）
    public function getNotifications()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取筛选参数
            $type = $_GET['type'] ?? 0; // 通知类型：0-全部，1-点赞，2-评论，3-关注，4-私信，5-系统通知
            $isRead = $_GET['is_read'] ?? -1; // 是否已读：-1-全部，0-未读，1-已读
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 20;
            $offset = ($page - 1) * $limit;

            // 构建查询
            $query = Db::name('notifications')
                ->where('user_id', $userId)
                ->order('create_time', 'desc');

            // 按类型筛选
            if ($type > 0) {
                $query->where('type', $type);
            }

            // 按已读状态筛选
            if ($isRead >= 0) {
                $query->where('is_read', $isRead);
            }

            // 计算总记录数
            $total = $query->count();

            // 获取分页数据
            $notifications = $query->limit($offset, $limit)->select();

            // 获取未读通知数量
            $unreadCount = Db::name('notifications')
                ->where('user_id', $userId)
                ->where('is_read', 0)
                ->count();

            // 获取各类型通知的未读数量
            $typeUnreadCounts = [];
            for ($i = 1; $i <= 5; $i++) {
                $count = Db::name('notifications')
                    ->where('user_id', $userId)
                    ->where('type', $i)
                    ->where('is_read', 0)
                    ->count();
                $typeUnreadCounts[$i] = $count;
            }

            return $this->success(['list' => $notifications, 'total' => $total, 'page' => $page, 'limit' => $limit, 'unread_count' => $unreadCount, 'type_unread_counts' => $typeUnreadCounts], '获取通知列表成功');
        } catch (\Exception $e) {
            return $this->error('获取通知列表失败: ' . $e->getMessage());
        }
    }

    // 标记单个通知为已读
    public function markAsRead()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取POST数据
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['notification_id'])) {
                return $this->badRequest('无效的请求数据');
            }

            $notificationId = $data['notification_id'];

            // 检查通知是否存在并属于当前用户
            $notification = Db::name('notifications')
                ->where('id', $notificationId)
                ->where('user_id', $userId)
                ->find();

            if (!$notification) {
                return $this->notFound('通知不存在');
            }

            // 标记为已读
            $updateResult = Db::name('notifications')
                ->where('id', $notificationId)
                ->update([
                    'is_read' => 1,
                    'read_time' => date('Y-m-d H:i:s')
                ]);

            if (!$updateResult) {
                return $this->error('标记已读失败');
            }

            return $this->success(null, '标记已读成功');
        } catch (\Exception $e) {
            return $this->error('标记已读失败: ' . $e->getMessage());
        }
    }

    // 批量标记通知为已读
    public function batchMarkAsRead()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取POST数据
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data) {
                return $this->badRequest('无效的请求数据');
            }

            $type = $data['type'] ?? 0; // 通知类型：0-全部，1-点赞，2-评论，3-关注，4-私信，5-系统通知
            $notificationIds = $data['notification_ids'] ?? []; // 通知ID列表

            // 构建查询条件
            $query = Db::name('notifications')
                ->where('user_id', $userId)
                ->where('is_read', 0);

            // 按类型批量标记
            if ($type > 0) {
                $query->where('type', $type);
            }

            // 按ID列表批量标记
            if (!empty($notificationIds)) {
                $query->whereIn('id', $notificationIds);
            }

            // 标记为已读
            $updateResult = $query->update([
                'is_read' => 1,
                'read_time' => date('Y-m-d H:i:s')
            ]);

            if ($updateResult === false) {
                return $this->error('批量标记已读失败');
            }

            return $this->success(['count' => $updateResult], '批量标记已读成功，共标记 ' . $updateResult . ' 条通知');
        } catch (\Exception $e) {
            return $this->error('批量标记已读失败: ' . $e->getMessage());
        }
    }

    // 批量删除通知
    public function batchDelete()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取POST数据
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || empty($data['notification_ids'])) {
                return $this->badRequest('无效的请求数据');
            }

            $notificationIds = $data['notification_ids'];

            // 批量删除通知
            $deleteResult = Db::name('notifications')
                ->where('user_id', $userId)
                ->whereIn('id', $notificationIds)
                ->delete();

            if (!$deleteResult) {
                return $this->error('批量删除通知失败');
            }

            return $this->success(['count' => $deleteResult], '批量删除通知成功，共删除 ' . $deleteResult . ' 条通知');
        } catch (\Exception $e) {
            return $this->error('批量删除通知失败: ' . $e->getMessage());
        }
    }

    // 获取未读通知数量
    public function getUnreadCount()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取未读通知总数
            $unreadCount = Db::name('notifications')
                ->where('user_id', $userId)
                ->where('is_read', 0)
                ->count();

            // 获取各类型未读通知数量
            $typeUnreadCounts = [];
            for ($i = 1; $i <= 5; $i++) {
                $count = Db::name('notifications')
                    ->where('user_id', $userId)
                    ->where('type', $i)
                    ->where('is_read', 0)
                    ->count();
                $typeUnreadCounts[$i] = $count;
            }

            return $this->success(['total' => $unreadCount, 'types' => $typeUnreadCounts], '获取未读通知数量成功');
        } catch (\Exception $e) {
            return $this->error('获取未读通知数量失败: ' . $e->getMessage());
        }
    }

    // 获取通知徽章（用于顶部导航栏）
    public function getBadge()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取未读通知总数
            $unreadCount = Db::name('notifications')
                ->where('user_id', $userId)
                ->where('is_read', 0)
                ->count();

            return $this->success(['count' => $unreadCount], '获取徽章成功');
        } catch (\Exception $e) {
            return $this->error('获取徽章失败: ' . $e->getMessage());
        }
    }
}
