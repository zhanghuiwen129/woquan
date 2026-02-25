<?php
// session 已经在 install.php 中启动，不需要重复启动

// 检查数据库配置是否存在
if (!isset($_SESSION['install']['db_config'])) {
    header('Location: /install?step=2');
    exit;
}

$db_config = $_SESSION['install']['db_config'];

// 定义SQL文件路径
$sql_file = dirname(dirname(__DIR__)) . '/database/install.sql';

$error = '';
$success = false;
$install_log = [];
$install_progress = 0;

// 强制执行安装（每次都执行）
try {
    $install_log[] = "[1/5] 正在检测数据库连接...";
    $install_progress = 20;
    
    // 建立数据库连接
    $dsn = "mysql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_config['username'], $db_config['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    $install_log[] = "[2/5] 数据库连接成功";
    $install_progress = 40;
    
    // 检查SQL文件
    if (!file_exists($sql_file)) {
        throw new Exception("SQL文件不存在: {$sql_file}");
    }
    
    $install_log[] = "[3/5] 正在读取SQL文件...";
    $install_progress = 50;
    
    // 读取SQL文件内容
    $sql_content = file_get_contents($sql_file);

    // 处理表前缀
    $custom_prefix = trim($db_config['prefix']);
    if (!empty($custom_prefix) && substr($custom_prefix, -1) !== '_') {
        $custom_prefix .= '_';
    }

    // 为表名添加用户自定义前缀
    if (!empty($custom_prefix)) {
        $sql_content = preg_replace_callback(
            '/CREATE TABLE `([a-z_]+)`/i',
            function($matches) use ($custom_prefix) {
                return 'CREATE TABLE IF NOT EXISTS `' . $custom_prefix . $matches[1] . '`';
            },
            $sql_content
        );
        $sql_content = preg_replace_callback(
            '/DROP TABLE IF EXISTS `([a-z_]+)`/i',
            function($matches) use ($custom_prefix) {
                return 'DROP TABLE IF EXISTS `' . $custom_prefix . $matches[1] . '`';
            },
            $sql_content
        );
        $sql_content = preg_replace_callback(
            '/INSERT INTO `([a-z_]+)`/i',
            function($matches) use ($custom_prefix) {
                return 'INSERT INTO `' . $custom_prefix . $matches[1] . '`';
            },
            $sql_content
        );
        $sql_content = preg_replace_callback(
            '/ALTER TABLE `([a-z_]+)`/i',
            function($matches) use ($custom_prefix) {
                return 'ALTER TABLE `' . $custom_prefix . $matches[1] . '`';
            },
            $sql_content
        );
        $sql_content = preg_replace_callback(
            '/REFERENCES `([a-z_]+)`/i',
            function($matches) use ($custom_prefix) {
                return 'REFERENCES `' . $custom_prefix . $matches[1] . '`';
            },
            $sql_content
        );
        $sql_content = preg_replace_callback(
            '/LOCK TABLES `([a-z_]+)`/i',
            function($matches) use ($custom_prefix) {
                return 'LOCK TABLES `' . $custom_prefix . $matches[1] . '`';
            },
            $sql_content
        );
        $sql_content = preg_replace_callback(
            '/UNLOCK TABLES/i',
            function($matches) use ($custom_prefix) {
                return 'UNLOCK TABLES';
            },
            $sql_content
        );
    } else {
        $sql_content = preg_replace_callback(
            '/CREATE TABLE `([a-z_]+)`/i',
            function($matches) {
                return 'CREATE TABLE IF NOT EXISTS `' . $matches[1] . '`';
            },
            $sql_content
        );
    }
    
    // 获取当前数据库中已存在的表
    $existing_tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $existing_tables[] = $row[0];
    }
    $install_log[] = "数据库中已存在 " . count($existing_tables) . " 张表";
    
    $install_log[] = "[4/5] 正在导入数据库表结构...";
    $install_progress = 60;
    
    // 分割SQL语句
    $sql_statements = preg_split('/;\s*$/m', $sql_content, -1, PREG_SPLIT_NO_EMPTY);
    $total_count = count($sql_statements);
    
    // 执行每个SQL语句
    $executed_count = 0;
    $skipped_count = 0;
    $error_sql = '';
    foreach ($sql_statements as $index => $sql) {
        $sql = trim($sql);
        if ($sql && strpos($sql, '--') !== 0 && strpos($sql, '/*') !== 0) {
            try {
                $pdo->exec($sql);
                $executed_count++;
            } catch (PDOException $e) {
                $error_msg = $e->getMessage();
                if (strpos($error_msg, 'already exists') !== false) {
                    $skipped_count++;
                } else {
                    $error_sql = substr($sql, 0, 100) . '...';
                    throw $e;
                }
            }
            $install_progress = 60 + floor(($executed_count + $skipped_count) / $total_count * 30);
        }
    }
    
    if ($skipped_count > 0) {
        $install_log[] = "成功导入 {$executed_count} 条SQL语句，跳过 {$skipped_count} 个已存在的表";
    } else {
        $install_log[] = "成功导入 {$executed_count} 条SQL语句";
    }
    $install_progress = 90;
    
    // 更新配置文件
    $install_log[] = "[5/5] 正在更新配置文件...";
    
    // 更新.env文件
    $env_file = dirname(dirname(__DIR__)) . '/.env';
    if (file_exists($env_file)) {
        $env_content = file_get_contents($env_file);
        
        // 更新或添加数据库配置
        $db_configs = [
            'DATABASE_HOSTNAME' => $db_config['host'],
            'DATABASE_HOSTPORT' => $db_config['port'],
            'DATABASE_DATABASE' => $db_config['dbname'],
            'DATABASE_USERNAME' => $db_config['username'],
            'DATABASE_PASSWORD' => $db_config['password'],
            'DATABASE_PREFIX' => $custom_prefix
        ];
        
        foreach ($db_configs as $key => $value) {
            $pattern = '/^' . preg_quote($key, '/') . '\s*=\s*.*/m';
            $replacement = $key . '=' . $value;
            
            if (preg_match($pattern, $env_content)) {
                // 如果配置项存在，则替换
                $env_content = preg_replace($pattern, $replacement, $env_content);
            } else {
                // 如果配置项不存在，则在数据库配置区域添加
                if (preg_match('/# 数据库配置/', $env_content)) {
                    $env_content = preg_replace(
                        '/(# 数据库配置)/',
                        "$1\n" . $key . '=' . $value,
                        $env_content,
                        1
                    );
                } else {
                    // 如果没有数据库配置区域，直接添加到文件开头
                    $env_content = $key . '=' . $value . "\n" . $env_content;
                }
            }
        }
        
        file_put_contents($env_file, $env_content);
    }
    
    // 注意：config/database.php 现在使用 env() 函数从 .env 文件读取配置，不需要再更新
    
    // 创建runtime目录(如果不存在)
    $runtime_dir = dirname(dirname(__DIR__)) . '/runtime';
    if (!is_dir($runtime_dir)) {
        mkdir($runtime_dir, 0755, true);
    }
    
    $install_log[] = "配置文件更新完成";

    $install_progress = 100;
    $install_log[] = "数据库安装成功!";
    $success = true;
    
    // 标记数据库已安装
    $_SESSION['install']['db_installed'] = true;
    completeStep(3);

} catch (PDOException $e) {
    $error = '数据库错误: ' . $e->getMessage();
    if (!empty($error_sql)) {
        $error .= '<br>出错的SQL语句: ' . htmlspecialchars($error_sql);
    }
} catch (Exception $e) {
    $error = '安装错误: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据库安装 - 我圈社交平台安装</title>
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
            padding: 30px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }
        h2 {
            color: #4080FF;
            margin-top: 30px;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .error {
            color: #f5222d;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #fff1f0;
            border-radius: 4px;
            border-left: 4px solid #f5222d;
        }
        .success {
            color: #52c41a;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f6ffed;
            border-radius: 4px;
            border-left: 4px solid #52c41a;
        }
        .progress-container {
            margin: 20px 0;
        }
        .progress-bar {
            width: 100%;
            height: 30px;
            background-color: #f0f0f0;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4080FF 0%, #2962FF 100%);
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: bold;
            font-size: 14px;
        }
        .log-container {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 4px;
            padding: 15px;
            background-color: #f9f9f9;
            margin-bottom: 20px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            line-height: 1.8;
        }
        .log-item {
            margin-bottom: 5px;
            color: #333;
        }
        .log-item.success {
            color: #52c41a;
        }
        .log-item.error {
            color: #f5222d;
        }
        .database-info {
            background-color: #e6f7ff;
            border-left: 4px solid #4080FF;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .database-info p {
            margin: 8px 0;
        }
        .database-info strong {
            color: #4080FF;
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
        <h1>我圈社交平台安装 - 数据库安装</h1>
        
        <div class="database-info">
            <h2>数据库配置信息</h2>
            <p><strong>数据库主机:</strong> <?php echo htmlspecialchars($db_config['host']); ?>:<?php echo htmlspecialchars($db_config['port']); ?></p>
            <p><strong>数据库名称:</strong> <?php echo htmlspecialchars($db_config['dbname']); ?></p>
            <p><strong>数据库用户:</strong> <?php echo htmlspecialchars($db_config['username']); ?></p>
            <p><strong>表前缀:</strong> <?php echo htmlspecialchars($db_config['prefix']); ?></p>
        </div>
        
        <?php if ($error): ?>
            <div class="error">
                <strong>❌ 安装失败</strong><br>
                <?php echo $error; ?>
            </div>
        <?php elseif ($success): ?>
            <div class="success">
                <strong>✓ 数据库安装成功!</strong><br>
                所有数据表已成功创建。
            </div>
        <?php endif; ?>
        
        <div class="progress-container">
            <h2>安装进度</h2>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $install_progress; ?>%;">
                    <?php echo $install_progress; ?>%
                </div>
            </div>
        </div>
        
        <h2>安装日志</h2>
        <div class="log-container">
            <?php if (empty($install_log)): ?>
                <div class="log-item" style="color: #999;">正在准备安装...</div>
            <?php else: ?>
                <?php foreach ($install_log as $log_item): ?>
                    <?php if (strpos($log_item, '成功') !== false || strpos($log_item, '完成') !== false): ?>
                        <div class="log-item success">✓ <?php echo htmlspecialchars($log_item); ?></div>
                    <?php elseif (strpos($log_item, '错误') !== false): ?>
                        <div class="log-item error">✗ <?php echo htmlspecialchars($log_item); ?></div>
                    <?php else: ?>
                        <div class="log-item"><?php echo htmlspecialchars($log_item); ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="btn-group">
            <a href="/install/install.php?step=2" class="btn btn-secondary">← 上一步</a>
            <?php if ($success): ?>
                <a href="/install/install.php?step=4" class="btn btn-primary">下一步 →</a>
            <?php else: ?>
                <a href="/install/install.php?step=3" class="btn btn-primary">重新安装</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>