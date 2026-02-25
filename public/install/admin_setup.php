<?php
// session 已经在 install.php 中启动，不需要重复启动

// 检查数据库是否已安装
if (!isset($_SESSION['install']['db_installed']) || $_SESSION['install']['db_installed'] !== true) {
    header('Location: /install?step=3');
    exit;
}

// 从 session 读取数据库配置
if (!isset($_SESSION['install']['db_config'])) {
    header('Location: /install?step=2');
    exit;
}

$db_config = $_SESSION['install']['db_config'];

// 建立数据库连接
try {
    $dsn = "mysql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_config['username'], $db_config['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 检查admin表是否存在
    $table_prefix = trim($db_config['prefix']);
    if (substr($table_prefix, -1) !== '_') {
        $table_prefix .= '_';
    }

    $table_name = $table_prefix . 'admin';
    $stmt = $pdo->query("SHOW TABLES LIKE '{$table_name}'");
    if ($stmt->rowCount() === 0) {
        // 表不存在，跳转到数据库安装页
        header('Location: /install?step=3');
        exit;
    }
} catch (PDOException $e) {
    header('Location: /install?step=2');
    exit;
}

$error = '';
$success = false;
$admin_info = [];

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_admin'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $nickname = trim($_POST['nickname'] ?? '');
    
    // 验证输入
    if (empty($username)) {
        $error = '用户名不能为空';
    } elseif (strlen($username) < 3 || strlen($username) > 20) {
        $error = '用户名长度必须在3-20个字符之间';
    } elseif (empty($password)) {
        $error = '密码不能为空';
    } elseif (strlen($password) < 6) {
        $error = '密码长度不能少于6个字符';
    } elseif ($password !== $confirm_password) {
        $error = '两次输入的密码不一致';
    } elseif (empty($email)) {
        $error = '邮箱不能为空';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } elseif (empty($nickname)) {
        $error = '昵称不能为空';
    } else {
        try {
            // 使用已经建立的PDO连接
            $table_prefix = trim($db_config['prefix']);
            if (substr($table_prefix, -1) !== '_') {
                $table_prefix .= '_';
            }

            // 检查用户名是否已存在
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table_prefix}admin WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $error = '用户名已存在，请选择其他用户名';
            } else {
                // 加密密码
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // 插入管理员账号
                $sql = "INSERT INTO {$table_prefix}admin (username, password, email, nickname, status, create_time)
                        VALUES (?, ?, ?, ?, 1, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$username, $password_hash, $email, $nickname, time()]);

                $success = true;
                $admin_info = [
                    'username' => $username,
                    'email' => $email,
                    'nickname' => $nickname
                ];

                // 标记管理员已创建
                $_SESSION['install']['admin_created'] = true;
                $_SESSION['install']['admin_info'] = $admin_info;
                completeStep(4);
            }
        } catch (PDOException $e) {
            $error = '数据库错误: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员设置 - 我圈社交平台安装</title>
    <style>
        body {
            font-family: 'Microsoft YaHei', sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 800px;
            margin: 50px auto;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 40px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }
        .description {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.8;
        }
        .form-group {
            margin-bottom: 25px;
        }
        .form-group label {
            display: block;
            font-weight: bold;
            color: #333;
            margin-bottom: 8px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }
        .form-group input:focus {
            outline: none;
            border-color: #4080FF;
            box-shadow: 0 0 0 3px rgba(64, 128, 255, 0.1);
        }
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #999;
            font-size: 12px;
        }
        .error {
            color: #f5222d;
            background-color: #fff1f0;
            border: 1px solid #f5c6cb;
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success {
            color: #52c41a;
            background-color: #f6ffed;
            border: 1px solid #b7eb8f;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success h3 {
            margin: 0 0 10px 0;
            color: #52c41a;
        }
        .success p {
            margin: 5px 0;
            color: #333;
        }
        .btn-group {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s;
        }
        .btn-primary {
            background-color: #4080FF;
            color: #fff;
        }
        .btn-primary:hover {
            background-color: #2962FF;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(64, 128, 255, 0.3);
        }
        .btn-secondary {
            background-color: #f5f5f5;
            color: #333;
        }
        .btn-secondary:hover {
            background-color: #e8e8e8;
        }
        .info-box {
            background-color: #e6f7ff;
            border-left: 4px solid #4080FF;
            padding: 15px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        .info-box h3 {
            margin: 0 0 10px 0;
            color: #4080FF;
            font-size: 16px;
        }
        .info-box ul {
            margin: 0;
            padding-left: 20px;
            color: #333;
        }
        .info-box li {
            margin: 5px 0;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>我圈社交平台安装 - 管理员设置</h1>
        
        <?php if ($success): ?>
            <div class="success">
                <h3>✓ 管理员账号创建成功!</h3>
                <p><strong>用户名:</strong> <?php echo htmlspecialchars($admin_info['username']); ?></p>
                <p><strong>昵称:</strong> <?php echo htmlspecialchars($admin_info['nickname']); ?></p>
                <p><strong>邮箱:</strong> <?php echo htmlspecialchars($admin_info['email']); ?></p>
                <p style="color: #999; font-size: 13px; margin-top: 15px;">请妥善保管您的管理员账号信息</p>
            </div>
        <?php else: ?>
            <p class="description">请设置系统管理员账号，用于登录后台管理系统</p>
            
            <div class="info-box">
                <h3>账号安全提示</h3>
                <ul>
                    <li>用户名长度为3-20个字符</li>
                    <li>密码长度至少6个字符</li>
                    <li>建议使用包含字母、数字和特殊字符的强密码</li>
                    <li>邮箱将用于接收重要通知</li>
                </ul>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error">
                <strong>❌ <?php echo htmlspecialchars($error); ?></strong>
            </div>
        <?php endif; ?>
        
        <?php if (!$success): ?>
            <form method="POST" action="/install/install.php?step=4">
                <div class="form-group">
                    <label for="username">用户名 *</label>
                    <input type="text" id="username" name="username" 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                           placeholder="请输入用户名(3-20个字符)" required>
                    <small>用于登录后台管理系统</small>
                </div>
                
                <div class="form-group">
                    <label for="nickname">昵称 *</label>
                    <input type="text" id="nickname" name="nickname" 
                           value="<?php echo htmlspecialchars($_POST['nickname'] ?? ''); ?>" 
                           placeholder="请输入管理员昵称" required>
                    <small>显示在后台管理界面</small>
                </div>
                
                <div class="form-group">
                    <label for="email">邮箱 *</label>
                    <input type="email" id="email" name="email" 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                           placeholder="请输入邮箱地址" required>
                    <small>用于接收系统通知</small>
                </div>
                
                <div class="form-group">
                    <label for="password">密码 *</label>
                    <input type="password" id="password" name="password" 
                           placeholder="请输入密码(至少6个字符)" required>
                    <small>建议使用强密码以保护账号安全</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">确认密码 *</label>
                    <input type="password" id="confirm_password" name="confirm_password" 
                           placeholder="请再次输入密码" required>
                </div>
                
                <div class="btn-group">
                    <a href="/install/install.php?step=3" class="btn btn-secondary">← 上一步</a>
                    <button type="submit" name="save_admin" class="btn btn-primary">创建管理员账号</button>
                </div>
            </form>
        <?php else: ?>
            <div class="btn-group">
                <a href="/install/install.php?step=3" class="btn btn-secondary">← 上一步</a>
                <a href="/install/install.php?step=5" class="btn btn-primary">完成安装 →</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
