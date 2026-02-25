<?php
// session 已经在 install.php 中启动，不需要重复启动

// 检查管理员是否已创建
if (!isset($_SESSION['install']['admin_created']) || $_SESSION['install']['admin_created'] !== true) {
    header('Location: /install?step=4');
    exit;
}

// 创建安装锁文件
$lock_file = __DIR__ . '/install.lock';
if (!file_exists($lock_file)) {
    $lock_content = json_encode([
        'version' => '1.0',
        'install_time' => date('Y-m-d H:i:s'),
        'db_config' => [
            'host' => $_SESSION['install']['db_config']['host'],
            'port' => $_SESSION['install']['db_config']['port'],
            'database' => $_SESSION['install']['db_config']['dbname'],
            'prefix' => $_SESSION['install']['db_config']['prefix']
        ],
        'admin_info' => $_SESSION['install']['admin_info']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($lock_file, $lock_content);
}

// 从配置文件读取数据库配置
$db_config_file = dirname(dirname(__DIR__)) . '/config/database.php';
if (!file_exists($db_config_file)) {
    header('Location: /install?step=2');
    exit;
}

$db_config_data = require $db_config_file;
$mysql_config = $db_config_data['connections']['mysql'];

// 获取管理员信息（从session读取）
$admin_info = $_SESSION['install']['admin_info'] ?? null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装完成 - 我圈社交平台安装</title>
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
        .success-icon {
            text-align: center;
            font-size: 64px;
            color: #52c41a;
            margin-bottom: 20px;
        }
        .success-message {
            text-align: center;
            font-size: 24px;
            color: #333;
            margin-bottom: 30px;
        }
        .info-box {
            background-color: #f9f9f9;
            border-left: 4px solid #4080FF;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .info-box p {
            margin: 5px 0;
        }
        .account-box {
            background-color: #f6ffed;
            border-left: 4px solid #52c41a;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .account-box h3 {
            margin-top: 0;
            color: #52c41a;
        }
        .account-box table {
            width: 100%;
            border-collapse: collapse;
        }
        .account-box table td {
            padding: 8px;
            border-bottom: 1px solid #e8f5e9;
        }
        .account-box table td:first-child {
            font-weight: bold;
            width: 150px;
        }
        .btn-group {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 40px;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 18px;
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
        }
        .btn-success {
            background-color: #52c41a;
            color: #fff;
        }
        .btn-success:hover {
            background-color: #389e0d;
        }
        .btn-danger {
            background-color: #f5222d;
            color: #fff;
        }
        .btn-danger:hover {
            background-color: #cf1322;
        }
        .tips {
            background-color: #fffbe6;
            border-left: 4px solid #faad14;
            padding: 15px;
            margin-top: 40px;
            border-radius: 4px;
        }
        .tips ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .tips li {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">
            <i class="fa fa-check-circle"></i>
        </div>
        <div class="success-message">
            恭喜！我圈社交平台安装成功！
        </div>
        
        <div class="info-box">
            <h2>系统信息</h2>
            <p><strong>系统版本:</strong> 我圈社交平台 v1.0</p>
            <p><strong>安装时间:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
            <p><strong>数据库:</strong> <?php echo htmlspecialchars($mysql_config['database']); ?></p>
        </div>

        <?php if ($admin_info): ?>
        <div class="account-box">
            <h2>管理员账号信息</h2>
            <table>
                <tr>
                    <td>用户名:</td>
                    <td><?php echo htmlspecialchars($admin_info['username']); ?></td>
                </tr>
                <tr>
                    <td>昵称:</td>
                    <td><?php echo htmlspecialchars($admin_info['nickname']); ?></td>
                </tr>
                <tr>
                    <td>邮箱:</td>
                    <td><?php echo htmlspecialchars($admin_info['email']); ?></td>
                </tr>
                <tr>
                    <td>后台地址:</td>
                    <td><a href="/admin/login" target="_blank">/admin/login</a></td>
                </tr>
                <tr>
                    <td>首页地址:</td>
                    <td><a href="/" target="_blank">/</a></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
        
        <div class="btn-group">
            <a href="/" class="btn btn-primary">
                <i class="fa fa-home"></i> 访问首页
            </a>
            <a href="/admin/login" class="btn btn-success">
                <i class="fa fa-cog"></i> 管理后台
            </a>
        </div>

        <div class="tips">
            <h2>安装完成后注意事项</h2>
            <ul>
                <li>请妥善保管您设置的管理员账号和密码</li>
                <li>建议定期备份数据库，防止数据丢失</li>
                <li>如遇到问题，请查看系统日志或联系技术支持</li>
                <li>建议开启服务器的安全防护措施</li>
                <li><strong>如果需要重新安装，请先删除 <code>public/install/install.lock</code> 文件</strong></li>
            </ul>
        </div>
    </div>
    
    <!-- 引入FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</body>
</html>
