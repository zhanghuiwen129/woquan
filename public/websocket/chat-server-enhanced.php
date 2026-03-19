<?php
/**
 * WebSocket 聊天服务器 (使用 PHP Swoole)
 * 启动命令: php public/websocket/chat-server-enhanced.php
 *
 * 注意: 需要先安装 Swoole 扩展
 * pecl install swoole
 * 或: composer require swoole/ide-helper
 */

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Process;

require_once __DIR__ . '/../../vendor/autoload.php';

// WebSocket 服务器配置
$host = env('websocket.host', '0.0.0.0');
$port = env('websocket.port', 9501);

// 创建 WebSocket 服务器
$server = new Server($host, $port);

// 设置服务器配置
$server->set([
    'worker_num' => 4,
    'task_worker_num' => 2,
    'max_request' => 10000,
    'dispatch_mode' => 2,
    'debug_mode' => false,
    'log_file' => runtime_path() . 'log/websocket.log',
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 300,
    'enable_coroutine' => true,
]);

// 用户连接映射 (fd => user_id)
$userConnections = [];
// 用户ID到连接映射 (user_id => fd)
$userToFd = [];
// 用户最后活跃时间 (user_id => timestamp)
$userLastActive = [];

/**
 * 连接打开事件
 */
$server->on('open', function(Server $server, $request) use (&$userConnections, &$userToFd, &$userLastActive) {
    echo "Connection open: {$request->fd}\n";
    
    // 获取用户ID和token (从 URL 参数或 header)
    $userId = $request->get['user_id'] ?? null;
    $token = $request->get['token'] ?? $request->header['token'] ?? null;
    
    // 验证token
    if ($userId && $token) {
        if (!validateToken($userId, $token)) {
            echo "Authentication failed for user {$userId}\n";
            $server->push($request->fd, json_encode([
                'type' => 'error',
                'code' => 401,
                'message' => 'Authentication failed'
            ]));
            $server->close($request->fd);
            return;
        }
    }
    
    if ($userId) {
        $userConnections[$request->fd] = $userId;
        $userToFd[$userId] = $request->fd;
        $userLastActive[$userId] = time();
        
        // 更新用户在线状态
        updateUserOnlineStatus($userId, true);
        
        echo "User {$userId} connected with fd {$request->fd}\n";
        
        // 发送连接成功消息
        $server->push($request->fd, json_encode([
            'type' => 'connected',
            'user_id' => $userId,
            'timestamp' => time()
        ]));
    } else {
        echo "Connection rejected: missing user_id\n";
        $server->push($request->fd, json_encode([
            'type' => 'error',
            'code' => 400,
            'message' => 'Missing user_id'
        ]));
        $server->close($request->fd);
    }
});

/**
 * 消息接收事件
 */
$server->on('message', function(Server $server, Frame $frame) use (&$userConnections, &$userToFd, &$userLastActive) {
    echo "Received message from {$frame->fd}: {$frame->data}\n";

    try {
        $data = json_decode($frame->data, true);

        if (!$data || !isset($data['type'])) {
            $server->push($frame->fd, json_encode([
                'type' => 'error',
                'code' => 400,
                'message' => 'Invalid message format'
            ]));
            return;
        }

        // 更新用户活跃时间
        $userId = $userConnections[$frame->fd] ?? null;
        if ($userId) {
            $userLastActive[$userId] = time();
        }

        switch ($data['type']) {
            case 'ping':
                handlePing($server, $frame->fd);
                break;

            case 'message':
                handleNewMessage($server, $frame->fd, $data, $userToFd);
                break;

            case 'typing':
                handleTyping($server, $frame->fd, $data, $userToFd);
                break;

            case 'mark_read':
                handleMarkRead($server, $frame->fd, $data, $userToFd);
                break;

            case 'recall':
                handleRecall($server, $frame->fd, $data, $userToFd);
                break;

            case 'get_offline_messages':
                handleGetOfflineMessages($server, $frame->fd, $data);
                break;

            case 'get_conversation_list':
                handleGetConversationList($server, $frame->fd, $data);
                break;

            default:
                echo "Unknown message type: {$data['type']}\n";
                $server->push($frame->fd, json_encode([
                    'type' => 'error',
                    'code' => 400,
                    'message' => 'Unknown message type'
                ]));
        }
    } catch (\Exception $e) {
        echo "Error processing message: " . $e->getMessage() . "\n";
        $server->push($frame->fd, json_encode([
            'type' => 'error',
            'code' => 500,
            'message' => $e->getMessage()
        ]));
    }
});

/**
 * 连接关闭事件
 */
$server->on('close', function(Server $server, $fd) use (&$userConnections, &$userToFd, &$userLastActive) {
    echo "Connection close: {$fd}\n";

    if (isset($userConnections[$fd])) {
        $userId = $userConnections[$fd];
        unset($userConnections[$fd]);
        unset($userToFd[$userId]);
        unset($userLastActive[$userId]);
        
        // 更新用户离线状态
        updateUserOnlineStatus($userId, false);
        
        echo "User {$userId} disconnected\n";
    }
});

/**
 * Worker启动事件
 */
$server->on('workerStart', function(Server $server, $workerId) {
    echo "Worker {$workerId} started\n";
});

/**
 * Task任务处理
 */
$server->on('task', function(Server $server, $taskId, $reactorId, $data) {
    echo "Task {$taskId} processing: " . json_encode($data) . "\n";
    
    switch ($data['action']) {
        case 'send_notification':
            return sendNotification($data);
            
        case 'broadcast_message':
            return broadcastMessage($server, $data);
            
        case 'cleanup_inactive_users':
            return cleanupInactiveUsers($server, $data);
            
        default:
            return ['success' => false, 'message' => 'Unknown task action'];
    }
});

/**
 * Task完成事件
 */
$server->on('finish', function(Server $server, $taskId, $data) {
    echo "Task {$taskId} finished: " . json_encode($data) . "\n";
});

/**
 * 验证Token
 */
function validateToken($userId, $token)
{
    try {
        $pdo = getDbConnection();
        
        // 检查token是否有效
        $stmt = $pdo->prepare("SELECT id FROM qz_users WHERE id = ? AND (remember_token = ? OR session_token = ?) AND status = 1");
        $stmt->execute([$userId, $token, $token]);
        $user = $stmt->fetch();
        
        return $user !== false;
    } catch (\Exception $e) {
        echo "Token validation error: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * 更新用户在线状态
 */
function updateUserOnlineStatus($userId, $isOnline)
{
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("UPDATE qz_users SET is_online = ?, last_heartbeat_time = ? WHERE id = ?");
        $stmt->execute([$isOnline ? 1 : 0, time(), $userId]);
        
        // 更新Redis缓存
        if ($isOnline) {
            \app\service\RedisCache::setOnlineUser($userId);
        }
    } catch (\Exception $e) {
        echo "Update online status error: " . $e->getMessage() . "\n";
    }
}

/**
 * 处理心跳
 */
function handlePing($server, $fd)
{
    $server->push($fd, json_encode([
        'type' => 'pong',
        'timestamp' => time()
    ]));
}

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
            'code' => 400,
            'message' => 'Missing sender_id or receiver_id'
        ]));
        return;
    }

    // 验证发送者ID
    if ($senderId !== $data['sender_id']) {
        $server->push($fd, json_encode([
            'type' => 'error',
            'code' => 403,
            'message' => 'Forbidden'
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

        // 获取发送者信息
        $stmt = $pdo->prepare("SELECT id, username, nickname, avatar FROM qz_users WHERE id = ?");
        $stmt->execute([$senderId]);
        $sender = $stmt->fetch();

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
            'create_time' => time(),
            'sender' => $sender
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
            
            // 创建离线通知
            createOfflineNotification($receiverId, $senderId, $message);
        }

    } catch (\PDOException $e) {
        echo "Database error: " . $e->getMessage() . "\n";
        $server->push($fd, json_encode([
            'type' => 'error',
            'code' => 500,
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
                'code' => 404,
                'message' => 'Message not found'
            ]));
            return;
        }

        // 检查消息是否可以撤回（2分钟内）
        if (time() - $message['create_time'] > 120) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'code' => 400,
                'message' => 'Message cannot be recalled after 2 minutes'
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
            'code' => 500,
            'message' => 'Failed to recall message'
        ]));
    }
}

/**
 * 处理获取离线消息
 */
function handleGetOfflineMessages($server, $fd, $data)
{
    $userId = $data['user_id'] ?? null;
    $limit = $data['limit'] ?? 50;

    if (!$userId) {
        return;
    }

    try {
        $pdo = getDbConnection();

        $stmt = $pdo->prepare("
            SELECT m.*, 
                   u1.username as sender_username, u1.nickname as sender_nickname, u1.avatar as sender_avatar,
                   u2.username as receiver_username, u2.nickname as receiver_nickname, u2.avatar as receiver_avatar
            FROM qz_messages m
            LEFT JOIN qz_users u1 ON m.sender_id = u1.id
            LEFT JOIN qz_users u2 ON m.receiver_id = u2.id
            WHERE (m.sender_id = ? OR m.receiver_id = ?)
            AND m.is_recalled = 0
            ORDER BY m.create_time DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $userId, $limit]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $server->push($fd, json_encode([
            'type' => 'offline_messages',
            'messages' => $messages
        ]));

    } catch (\PDOException $e) {
        echo "Database error: " . $e->getMessage() . "\n";
        $server->push($fd, json_encode([
            'type' => 'error',
            'code' => 500,
            'message' => 'Failed to get offline messages'
        ]));
    }
}

/**
 * 处理获取会话列表
 */
function handleGetConversationList($server, $fd, $data)
{
    $userId = $data['user_id'] ?? null;
    $limit = $data['limit'] ?? 20;

    if (!$userId) {
        return;
    }

    try {
        $pdo = getDbConnection();

        $stmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN m.sender_id = ? THEN m.receiver_id 
                    ELSE m.sender_id 
                END as other_user_id,
                u.username, u.nickname, u.avatar,
                m.content, m.message_type, m.file_url, m.create_time,
                (SELECT COUNT(*) FROM qz_messages WHERE receiver_id = ? AND sender_id = other_user_id AND is_read = 0) as unread_count
            FROM qz_messages m
            LEFT JOIN qz_users u ON (
                CASE 
                    WHEN m.sender_id = ? THEN m.receiver_id 
                    ELSE m.sender_id 
                END = u.id
            )
            WHERE (m.sender_id = ? OR m.receiver_id = ?)
            AND m.is_recalled = 0
            GROUP BY other_user_id
            ORDER BY m.create_time DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $limit]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $server->push($fd, json_encode([
            'type' => 'conversation_list',
            'conversations' => $conversations
        ]));

    } catch (\PDOException $e) {
        echo "Database error: " . $e->getMessage() . "\n";
        $server->push($fd, json_encode([
            'type' => 'error',
            'code' => 500,
            'message' => 'Failed to get conversation list'
        ]));
    }
}

/**
 * 创建离线通知
 */
function createOfflineNotification($userId, $senderId, $message)
{
    try {
        $pdo = getDbConnection();

        $stmt = $pdo->prepare("INSERT INTO qz_notifications (user_id, sender_id, type, title, content, create_time) VALUES (?, ?, 4, ?, ?, ?)");
        $stmt->execute([
            $userId,
            $senderId,
            '新消息',
            mb_substr($message['content'], 0, 50),
            time()
        ]);

    } catch (\PDOException $e) {
        echo "Create offline notification error: " . $e->getMessage() . "\n";
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
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => true
        ]);
    }

    return $pdo;
}

/**
 * 发送通知
 */
function sendNotification($data)
{
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("INSERT INTO qz_notifications (user_id, sender_id, type, title, content, create_time) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['user_id'],
            $data['sender_id'] ?? 0,
            $data['type'],
            $data['title'],
            $data['content'],
            time()
        ]);
        
        return ['success' => true];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 广播消息
 */
function broadcastMessage($server, $data)
{
    $message = json_encode([
        'type' => 'broadcast',
        'data' => $data['data']
    ]);
    
    foreach ($server->connections as $fd) {
        if ($server->isEstablished($fd)) {
            $server->push($fd, $message);
        }
    }
    
    return ['success' => true];
}

/**
 * 清理不活跃用户
 */
function cleanupInactiveUsers($server, $data)
{
    $timeout = $data['timeout'] ?? 300; // 5分钟
    
    foreach ($server->connections as $fd) {
        if ($server->isEstablished($fd)) {
            $lastPing = $server->getClientInfo($fd)['last_time'] ?? 0;
            if (time() - $lastPing > $timeout) {
                $server->close($fd);
            }
        }
    }
    
    return ['success' => true];
}

/**
 * 启动服务器
 */
echo "WebSocket Server started on {$host}:{$port}\n";
echo "Waiting for connections...\n";

$server->start();
