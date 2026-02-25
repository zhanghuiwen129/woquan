<?php
// 直接API入口 - 不经过路由
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cookie');

session_start();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 调试信息
$debug = [
    'session_id' => session_id(),
    'session_user_id' => $_SESSION['user_id'] ?? null,
    'cookies' => $_COOKIE,
    'action' => $action
];

if ($action === 'current') {
    if (isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
        
        $host = 'localhost';
        $dbname = 'dzw';
        $username = 'dzw';
        $password = 'root123';
        $port = 3306;
        
        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname";
            $pdo = new PDO($dsn, $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("SET NAMES 'utf8mb4'");
            
            $stmt = $pdo->prepare("
                SELECT id, username, email, name, nickname, avatar,
                       phone, gender, birthday, bio, status
                FROM user WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                echo json_encode([
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $user,
                    'debug' => $debug
                ], JSON_UNESCAPED_UNICODE);
            } else {
                session(null);
                echo json_encode([
                    'code' => 401,
                    'msg' => '用户不存在',
                    'debug' => $debug
                ], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            echo json_encode([
                'code' => 500,
                'msg' => '数据库错误: ' . $e->getMessage(),
                'debug' => $debug
            ], JSON_UNESCAPED_UNICODE);
        }
    } else {
        echo json_encode([
            'code' => 401,
            'msg' => '未登录',
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 测试登录功能
if ($action === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        echo json_encode([
            'code' => 400,
            'msg' => '用户名和密码不能为空'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $host = 'localhost';
    $dbname = 'dzw';
    $username_db = 'dzw';
    $password_db = 'root123';
    $port = 3306;
    
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname";
        $pdo = new PDO($dsn, $username_db, $password_db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("
            SELECT id, username, password, email, name, nickname, avatar, 
                   phone, gender, birthday, bio, status, last_login_time
            FROM user WHERE username = ? OR email = ?
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode([
                'code' => 400,
                'msg' => '用户不存在'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (!password_verify($password, $user['password'])) {
            echo json_encode([
                'code' => 400,
                'msg' => '密码错误'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if ($user['status'] != 1) {
            echo json_encode([
                'code' => 400,
                'msg' => '账户已被禁用'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 设置session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nickname'] = $user['nickname'] ?? $user['username'];
        
        // 设置cookie（7天）
        setcookie('user_id', $user['id'], time() + 86400 * 7, '/');
        setcookie('username', $user['username'], time() + 86400 * 7, '/');
        
        unset($user['password']);
        
        echo json_encode([
            'code' => 200,
            'msg' => '登录成功',
            'data' => $user,
            'session_id' => session_id()
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode([
            'code' => 500,
            'msg' => '登录失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 默认返回测试信息
echo json_encode([
    'test' => 'direct_api',
    'time' => date('Y-m-d H:i:s'),
    'session_id' => session_id(),
    'session_user_id' => $_SESSION['user_id'] ?? null,
    'debug' => $debug
], JSON_UNESCAPED_UNICODE);
