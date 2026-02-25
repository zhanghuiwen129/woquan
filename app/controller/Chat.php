<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Chat extends BaseFrontendController
{
    // 聊天页面
    public function index()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        // 获取对方用户ID（支持路由参数和GET参数）
        $otherUserId = input('id') ?: input('to_user_id') ?: input('user_id');
        if (!$otherUserId) {
            // 缺少用户ID，重定向到消息页面
            header('Location: /messages');
            exit;
        }

        // 获取对方用户信息
        $otherUser = Db::name('user')
            ->where('id', $otherUserId)
            ->field('id, nickname, avatar, level, bio')
            ->find();

        if (!$otherUser) {
            // 用户不存在，重定向到消息页面
            header('Location: /messages');
            exit;
        }

        // 检查是否安装了 Swoole WebSocket 扩展
        $enableWebSocket = extension_loaded('swoole');

        // 始终使用模块化版本
        $useModular = true;

        // 配置信息已在基类中加载
        View::assign([
            'currentUser' => $currentUser,
            'otherUser' => $otherUser,
            'isLogin' => !empty($userId),
            'current_url' => '/chat',
            'enableWebSocket' => $enableWebSocket
        ]);

        // 始终使用模块化模板
        return View::fetch('index/chat-modular');
    }
}
