<?php
session_start();

// 检查环境检测是否通过
if (!isset($_SESSION['install']['env_ok']) || $_SESSION['install']['env_ok'] !== true) {
    header('Location: /install?step=1');
    exit;
}

// 处理表单提交
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_config = [
        'host' => trim($_POST['db_host'] ?? 'localhost'),
        'port' => trim($_POST['db_port'] ?? '3306'),
        'dbname' => trim($_POST['db_name'] ?? ''),
        'username' => trim($_POST['db_username'] ?? 'root'),
        'password' => trim($_POST['db_password'] ?? ''),
        'prefix' => trim($_POST['db_prefix'] ?? '')
    ];
    
    // 验证数据库连接
    try {
        $dsn = "mysql:host={$db_config['host']};port={$db_config['port']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db_config['username'], $db_config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 检查数据库是否存在
        if (empty($db_config['dbname'])) {
            $error = '请输入数据库名称';
        } else {
            // 尝试选择数据库
            try {
                $pdo->exec("USE `{$db_config['dbname']}`");
            } catch (PDOException $e) {
                // 如果数据库不存在，尝试创建
                if (strpos($e->getMessage(), 'Unknown database') !== false) {
                    try {
                        $pdo->exec("CREATE DATABASE `{$db_config['dbname']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        $pdo->exec("USE `{$db_config['dbname']}`");
                    } catch (PDOException $e) {
                        $error = '创建数据库失败: ' . $e->getMessage();
                    }
                } else {
                    $error = '数据库连接失败: ' . $e->getMessage();
                }
            }
        }
        
        if (empty($error)) {
            // 保存数据库配置到session
            $_SESSION['install']['db_config'] = $db_config;
            
            // 标记步骤2完成
            completeStep(2);
            
            // 重定向到安装步骤
            header('Location: /install?step=3');
            exit;
        }
    } catch (PDOException $e) {
        $error = '数据库连接失败: ' . $e->getMessage();
    }
} else {
    $db_config = [
        'host' => 'localhost',
        'port' => '3306',
        'dbname' => '',
        'username' => 'root',
        'password' => '',
        'prefix' => ''
    ];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据库配置 - 我圈社交平台安装</title>
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
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: bold;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        .form-group input:focus {
            outline: none;
            border-color: #4080FF;
        }
        .error {
            color: #f5222d;
            margin-bottom: 20px;
            padding: 10px;
            background-color: #fff1f0;
            border-radius: 4px;
            border-left: 4px solid #f5222d;
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
        }
        .btn-primary {
            background-color: #4080FF;
            color: #fff;
        }
        .btn-primary:hover {
            background-color: #2962FF;
        }
        .btn-secondary {
            background-color: #f5f5f5;
            color: #333;
        }
        .btn-secondary:hover {
            background-color: #e8e8e8;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>我圈社交平台安装 - 数据库配置</h1>
        
        <div class="description">
            请输入您的数据库连接信息。系统将自动创建数据库（如果不存在）并导入数据表结构。
        </div>
        
        <?php if ($error): ?>
            <div class="error">
                <strong>❌ 错误</strong><br>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="/install/install.php?step=2" autocomplete="off">
            <div class="form-group">
                <label for="db_host">数据库主机</label>
                <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($db_config['host']); ?>" placeholder="localhost" autocomplete="off">
            </div>
            
            <div class="form-group">
                <label for="db_port">数据库端口</label>
                <input type="text" id="db_port" name="db_port" value="<?php echo htmlspecialchars($db_config['port']); ?>" placeholder="3306" autocomplete="off">
            </div>
            
            <div class="form-group">
                <label for="db_name">数据库名称</label>
                <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($db_config['dbname']); ?>" placeholder="请输入数据库名称" required autocomplete="off">
            </div>
            
            <div class="form-group">
                <label for="db_username">数据库用户名</label>
                <input type="text" id="db_username" name="db_username" value="<?php echo htmlspecialchars($db_config['username']); ?>" placeholder="root" autocomplete="off">
            </div>
            
            <div class="form-group">
                <label for="db_password">数据库密码</label>
                <input type="password" id="db_password" name="db_password" value="<?php echo htmlspecialchars($db_config['password']); ?>" placeholder="" autocomplete="new-password">
            </div>
            
            <div class="form-group">
                <label for="db_prefix">表前缀</label>
                <input type="text" id="db_prefix" name="db_prefix" value="<?php echo htmlspecialchars($db_config['prefix']); ?>" placeholder="" autocomplete="off">
            </div>
            
            <div class="btn-group">
                <a href="/install/install.php?step=1" class="btn btn-secondary">← 上一步</a>
                <button type="submit" class="btn btn-primary">下一步 →</button>
            </div>
        </form>
    </div>
</body>
</html>