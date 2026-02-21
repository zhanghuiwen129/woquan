<?php
/**
 * WebSocket 聊天服务器 (使用 PHP Swoole)
 * 启动命令: php public/websocket/chat-server.php
 *
 * 注意: 需要先安装 Swoole 扩展
 * pecl install swoole
 * 或: composer require swoole/ide-helper
 */

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';

// WebSocket 服务器配置
$host = '0.0.0.0';
$port = 8080;

// 创建 WebSocket 服务器
$server = new Server($host, $port);

// 用户连接映射 (fd => user_id)
$userConnections = [];
// 用户ID到连接映射 (user_id => fd)
$userToFd = [];

/**
 * 连接打开事件
 */
$server->on('open', function(Server $server, $request) use (&$userConnections) {
    echo "Connection open: {$request->fd}\n";
    
    // 获取用户ID和token (从 URL 参数或 header)
    $userId = $request->get['user_id'] ?? null;
    $token = $request->get['token'] ?? $request->header['token'] ?? null;
    
    // 验证token
    if ($userId && $token) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id FROM qz_users WHERE id = ? AND (remember_token = ? OR session_token = ?)");
        $stmt->execute([$userId, $token, $token]);
        $user = $stmt->fetch();
        
        if (!$user) {
            echo "Authentication failed for user {$userId}\n";
            $server->close($request->fd);
            return;
        }
    }
    
    if ($userId) {
        $userConnections[$request->fd] = $userId;
        $userToFd[$userId] = $request->fd;
        echo "User {$userId} connected with fd {$request->fd}\n";
    } else {
        echo "Connection rejected: missing user_id\n";
        $server->close($request->fd);
    }
});

/**
 * 消息接收事件
 */
$server->on('message', function(Server $server, Frame $frame) use (&$userConnections, &$userToFd) {
    echo "Received message from {$frame->fd}: {$frame->data}\n";

    try {
        $data = json_decode($frame->data, true);

        if (!$data || !isset($data['type'])) {
            $server->push($frame->fd, json_encode([
                'type' => 'error',
                'message' => 'Invalid message format'
            ]));
            return;
        }

        switch ($data['type']) {
            case 'ping':
                // 心跳响应
                $server->push($frame->fd, json_encode(['type' => 'pong']));
                break;

            case 'message':
                // 处理新消息
                handleNewMessage($server, $frame->fd, $data, $userToFd);
                break;

            case 'typing':
                // 处理正在输入状态
                handleTyping($server, $frame->fd, $data, $userToFd);
                break;

            case 'mark_read':
                // 处理标记已读
                handleMarkRead($server, $frame->fd, $data, $userToFd);
                break;

            case 'recall':
                // 处理消息撤回
                handleRecall($server, $frame->fd, $data, $userToFd);
                break;

            default:
                echo "Unknown message type: {$data['type']}\n";
        }
    } catch (\Exception $e) {
        echo "Error processing message: " . $e->getMessage() . "\n";
        $server->push($frame->fd, json_encode([
            'type' => 'error',
            'message' => $e->getMessage()
        ]));
    }
});

/**
 * 连接关闭事件
 */
$server->on('close', function(Server $server, $fd) use (&$userConnections, &$userToFd) {
    echo "Connection close: {$fd}\n";

    if (isset($userConnections[$fd])) {
        $userId = $userConnections[$fd];
        unset($userConnections[$fd]);
        unset($userToFd[$userId]);
        echo "User {$userId} disconnected\n";
    }
});

/**
 * 处理新消息
 */
function handleNewMessage($server, $fd, $data, &$userToFd)
{
    $senderId = $data['sender_id'] ?? null;
    $receiverId = $data['receiver_id'] ?? null;

    if (!$senderId || !$receiverId) {
        $server->push($fd, json_encode([
            'type' => 'error',
            'message' => 'Missing sender_id or receiver_id'
        ]));
        return;
    }

    // 保存消息到数据库
    try {
        $pdo = getDbConnection();

        $stmt = $pdo->prepare("INSERT INTO qz_messages (sender_id, receiver_id, content, message_type, file_url, reply_to_id, is_read, create_time) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
        $stmt->execute([
            $senderId,
            $receiverId,
            $data['content'] ?? '',
            $data['message_type'] ?? 1,
            $data['file_url'] ?? '',
            $data['reply_to_id'] ?? 0,
            time()
        ]);

        $messageId = $pdo->lastInsertId();

        // 构建完整消息数据
        $message = [
            'id' => $messageId,
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'content' => $data['content'] ?? '',
            'message_type' => $data['message_type'] ?? 1,
            'file_url' => $data['file_url'] ?? '',
            'reply_to_id' => $data['reply_to_id'] ?? 0,
            'is_read' => 0,
            'create_time' => time()
        ];

        // 通知发送者消息已发送
        $server->push($fd, json_encode([
            'type' => 'message_sent',
            'message' => $message
        ]));

        // 如果接收者在线,推送新消息
        if (isset($userToFd[$receiverId])) {
            $receiverFd = $userToFd[$receiverId];
            $server->push($receiverFd, json_encode([
                'type' => 'new_message',
                'message' => $message
            ]));
            echo "Message sent to user {$receiverId} (fd: {$receiverFd})\n";
        } else {
            echo "User {$receiverId} is offline\n";
        }

    } catch (\PDOException $e) {
        echo "Database error: " . $e->getMessage() . "\n";
        $server->push($fd, json_encode([
            'type' => 'error',
            'message' => 'Failed to save message'
        ]));
    }
}

/**
 * 处理正在输入状态
 */
function handleTyping($server, $fd, $data, &$userToFd)
{
    $senderId = $data['sender_id'] ?? null;
    $receiverId = $data['receiver_id'] ?? null;
    $isTyping = $data['is_typing'] ?? false;

    if (!$senderId || !$receiverId) {
        return;
    }

    // 如果接收者在线,推送正在输入状态
    if (isset($userToFd[$receiverId])) {
        $receiverFd = $userToFd[$receiverId];
        $server->push($receiverFd, json_encode([
            'type' => 'typing',
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'is_typing' => $isTyping
        ]));
    }
}

/**
 * 处理标记已读
 */
function handleMarkRead($server, $fd, $data, &$userToFd)
{
    $senderId = $data['sender_id'] ?? null;
    $messageIds = $data['message_ids'] ?? null;

    if (!$senderId) {
        return;
    }

    try {
        $pdo = getDbConnection();

        // 更新数据库
        if ($messageIds && is_array($messageIds)) {
            $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE qz_messages SET is_read = 1, read_time = ? WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([time()], $messageIds));
        }

        // 通知发送者消息已读
        if (isset($userToFd[$senderId])) {
            $senderFd = $userToFd[$senderId];
            $server->push($senderFd, json_encode([
                'type' => 'messages_read',
                'message_ids' => $messageIds,
                'read_time' => time()
            ]));
        }

    } catch (\PDOException $e) {
        echo "Database error: " . $e->getMessage() . "\n";
    }
}

/**
 * 处理消息撤回
 */
function handleRecall($server, $fd, $data, &$userToFd)
{
    $messageId = $data['message_id'] ?? null;

    if (!$messageId) {
        return;
    }

    try {
        $pdo = getDbConnection();

        // 获取消息信息
        $stmt = $pdo->prepare("SELECT * FROM qz_messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$message) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Message not found'
            ]));
            return;
        }

        // 更新数据库
        $stmt = $pdo->prepare("UPDATE qz_messages SET is_recalled = 1, recall_time = ? WHERE id = ?");
        $stmt->execute([time(), $messageId]);

        // 通知双方消息已撤回
        $senderFd = $userToFd[$message['sender_id']] ?? null;
        $receiverFd = $userToFd[$message['receiver_id']] ?? null;

        $recallData = [
            'type' => 'message_recalled',
            'message_id' => $messageId,
            'recall_time' => time()
        ];

        if ($senderFd) {
            $server->push($senderFd, json_encode($recallData));
        }
        if ($receiverFd) {
            $server->push($receiverFd, json_encode($recallData));
        }

    } catch (\PDOException $e) {
        echo "Database error: " . $e->getMessage() . "\n";
        $server->push($fd, json_encode([
            'type' => 'error',
            'message' => 'Failed to recall message'
        ]));
    }
}

/**
 * 获取数据库连接
 */
function getDbConnection()
{
    static $pdo = null;

    if ($pdo === null) {
        $config = require __DIR__ . '/../../config/database.php';
        $db = $config['connections']['mysql'];

        $dsn = "mysql:host={$db['hostname']};dbname={$db['database']};charset={$db['charset']}";
        $pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    return $pdo;
}

/**
 * 启动服务器
 */
echo "WebSocket Server started on {$host}:{$port}\n";
echo "Waiting for connections...\n";

$server->start();
