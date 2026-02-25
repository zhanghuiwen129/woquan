<?php
session_start();

// 检查是否同意免责声明
if (!isset($_SESSION['install']['agreed']) || $_SESSION['install']['agreed'] !== true) {
    header('Location: /install?step=0');
    exit;
}

// 处理自动安装请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_fix'])) {
    $fix_result = autoFixEnvironment();
    $_SESSION['install']['fix_result'] = $fix_result;
    header('Location: /install?step=1');
    exit;
}

// 处理跳过环境检测（仅用于测试）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skip_check'])) {
    $_SESSION['install']['env_ok'] = true;
    completeStep(1);
    header('Location: /install?step=2');
    exit;
}

// 处理继续按钮
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['continue'])) {
    $_SESSION['install']['env_ok'] = true;
    completeStep(1);
    header('Location: /install?step=2');
    exit;
}

// 检查PHP版本
$php_version = PHP_VERSION;
$php_version_ok = version_compare($php_version, '7.2.0', '>=');

// 检查扩展
$extensions = [
    'pdo_mysql' => ['name' => 'PDO MySQL 扩展', 'fixable' => true],
    'gd' => ['name' => 'GD 扩展', 'fixable' => true],
    'mbstring' => ['name' => 'MBString 扩展', 'fixable' => true],
    'curl' => ['name' => 'CURL 扩展', 'fixable' => true],
    'fileinfo' => ['name' => 'Fileinfo 扩展', 'fixable' => true],
    'openssl' => ['name' => 'OpenSSL 扩展', 'fixable' => true],
    'zip' => ['name' => 'ZIP 扩展', 'fixable' => true],
    'json' => ['name' => 'JSON 扩展', 'fixable' => false]
];

$extensions_check = [];
foreach ($extensions as $ext => $info) {
    $extensions_check[$ext] = [
        'name' => $info['name'],
        'status' => extension_loaded($ext),
        'fixable' => $info['fixable']
    ];
}

// 检查目录权限
$dirs = [
    '../../runtime' => ['name' => 'runtime 目录', 'fixable' => true],
    '../../config' => ['name' => 'config 目录', 'fixable' => true],
    '../static' => ['name' => 'static 目录', 'fixable' => true],
    '../uploads' => ['name' => 'uploads 目录', 'fixable' => true],
    '../../vendor' => ['name' => 'vendor 目录', 'fixable' => true],
    '../../database' => ['name' => 'database 目录', 'fixable' => true]
];

$dirs_check = [];
foreach ($dirs as $dir => $info) {
    $dirs_check[$dir] = [
        'name' => $info['name'],
        'status' => is_writable($dir),
        'fixable' => $info['fixable']
    ];
}

// 检查其他环境要求
$other_checks = [
    'composer' => [
    'name' => 'Composer 可用性',
    'status' => is_dir('../../vendor') && file_exists('../../vendor/autoload.php'),
    'fixable' => false
],
    'max_execution_time' => [
        'name' => 'PHP执行时间 (>=30秒)',
        'status' => (ini_get('max_execution_time') >= 30 || ini_get('max_execution_time') == 0),
        'fixable' => true
    ],
    'memory_limit' => [
        'name' => 'PHP内存限制 (>=128M)',
        'status' => (convertToBytes(ini_get('memory_limit')) >= 134217728),
        'fixable' => true
    ],
    'upload_max_filesize' => [
        'name' => '上传文件大小限制 (>=10M)',
        'status' => (convertToBytes(ini_get('upload_max_filesize')) >= 10485760),
        'fixable' => true
    ]
];

// 生成检测报告
$all_checks_ok = true;
$all_checks_ok &= $php_version_ok;
foreach ($extensions_check as $check) {
    $all_checks_ok &= $check['status'];
}
foreach ($dirs_check as $check) {
    $all_checks_ok &= $check['status'];
}
foreach ($other_checks as $check) {
    $all_checks_ok &= $check['status'];
}

// 辅助函数
function checkCommandExists($command) {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = "where $command 2>nul";
    } else {
        $command = "command -v $command 2>/dev/null";
    }
    $output = [];
    $result_code = 0;
    exec($command, $output, $result_code);
    return $result_code === 0;
}

function convertToBytes($size) {
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
    $size = preg_replace('/[^0-9\.]/', '', $size);
    if ($unit) {
        $size = $size * pow(1024, stripos('bkmgtpezy', $unit[0]));
    }
    return round($size);
}

function autoFixEnvironment() {
    $result = ['success' => [], 'error' => []];
    
    // 自动修复目录权限
$fixable_dirs = [
    '../../runtime',
    '../../config',
    '../static',
    '../uploads',
    '../../vendor',
    '../../database'
];
    
    foreach ($fixable_dirs as $dir) {
        if (!is_writable($dir)) {
            if (@chmod($dir, 0755)) {
                $result['success'][] = "成功设置 {$dir} 目录权限为 0755";
            } else {
                $result['error'][] = "无法设置 {$dir} 目录权限，请手动设置";
            }
        }
    }
    
    // 尝试创建必要的目录
$required_dirs = ['../../runtime/cache', '../../runtime/log', '../../runtime/temp'];
foreach ($required_dirs as $dir) {
    if (!file_exists($dir)) {
        if (@mkdir($dir, 0755, true)) {
            $result['success'][] = "成功创建目录: {$dir}";
        } else {
            $result['error'][] = "无法创建目录: {$dir}";
        }
    }
}
    
    // 尝试设置PHP配置
    if (ini_get('max_execution_time') < 30 && ini_get('max_execution_time') != 0) {
        @ini_set('max_execution_time', '300');
        $result['success'][] = "尝试设置PHP执行时间为300秒";
    }
    
    if (convertToBytes(ini_get('memory_limit')) < 134217728) {
        @ini_set('memory_limit', '256M');
        $result['success'][] = "尝试设置PHP内存限制为256M";
    }
    
    if (convertToBytes(ini_get('upload_max_filesize')) < 10485760) {
        @ini_set('upload_max_filesize', '20M');
        @ini_set('post_max_size', '20M');
        $result['success'][] = "尝试设置上传文件大小为20M";
    }
    
    return $result;
}

// 显示修复结果
$fix_result = isset($_SESSION['install']['fix_result']) ? $_SESSION['install']['fix_result'] : null;
unset($_SESSION['install']['fix_result']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>环境检测 - 我圈社交平台安装</title>
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
        .check-item {
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 4px;
            background-color: #f9f9f9;
        }
        .check-item.success {
            border-left: 4px solid #52c41a;
        }
        .check-item.error {
            border-left: 4px solid #f5222d;
        }
        .check-item .name {
            font-weight: bold;
            color: #333;
        }
        .check-item .status {
            float: right;
        }
        .check-item .status.success {
            color: #52c41a;
        }
        .check-item .status.error {
            color: #f5222d;
        }
        .next-btn {
            display: block;
            width: 200px;
            height: 40px;
            margin: 30px auto;
            background-color: #4080FF;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            text-align: center;
            line-height: 40px;
            text-decoration: none;
        }
        .next-btn:hover {
            background-color: #2962FF;
        }
        .next-btn.disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>我圈社交平台安装 - 环境检测</h1>
        
        <?php if ($fix_result): ?>
        <div style="padding: 15px; margin-bottom: 20px; border-radius: 4px; background-color: <?php echo empty($fix_result['error']) ? '#f6ffed' : '#fff1f0'; ?>; border: 1px solid <?php echo empty($fix_result['error']) ? '#b7eb8f' : '#ffccc7'; ?>;">
            <h3 style="color: <?php echo empty($fix_result['error']) ? '#52c41a' : '#f5222d'; ?>; margin-top: 0;">
                自动修复结果
            </h3>
            <?php if (!empty($fix_result['success'])): ?>
            <div style="color: #52c41a; margin-bottom: 10px;">
                <strong>成功:</strong>
                <?php foreach ($fix_result['success'] as $success_msg): ?>
                <div>✓ <?php echo htmlspecialchars($success_msg); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($fix_result['error'])): ?>
            <div style="color: #f5222d;">
                <strong>错误:</strong>
                <?php foreach ($fix_result['error'] as $error_msg): ?>
                <div>✗ <?php echo htmlspecialchars($error_msg); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <h2>PHP版本检测</h2>
        <div class="check-item <?php echo $php_version_ok ? 'success' : 'error'; ?>">
            <span class="name">PHP版本要求 7.2.0+</span>
            <span class="status <?php echo $php_version_ok ? 'success' : 'error'; ?>">
                <?php echo $php_version_ok ? '通过' : '失败'; ?> (当前版本: <?php echo $php_version; ?>)
            </span>
        </div>
        
        <h2>扩展检测</h2>
        <?php foreach ($extensions_check as $ext => $check): ?>
            <div class="check-item <?php echo $check['status'] ? 'success' : 'error'; ?>">
                <span class="name"><?php echo $check['name']; ?></span>
                <span class="status <?php echo $check['status'] ? 'success' : 'error'; ?>">
                    <?php echo $check['status'] ? '通过' : '失败'; ?>
                    <?php if (!$check['status'] && $check['fixable']): ?>
                    <br><small style="color: #ff4d4f;">需要手动安装扩展</small>
                    <?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
        
        <h2>目录权限检测</h2>
        <?php foreach ($dirs_check as $dir => $check): ?>
            <div class="check-item <?php echo $check['status'] ? 'success' : 'error'; ?>">
                <span class="name"><?php echo $check['name']; ?></span>
                <span class="status <?php echo $check['status'] ? 'success' : 'error'; ?>">
                    <?php echo $check['status'] ? '通过' : '失败'; ?>
                    <?php if (!$check['status'] && $check['fixable']): ?>
                    <br><small style="color: #ff4d4f;">尝试自动修复</small>
                    <?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
        
        <h2>其他环境要求</h2>
        <?php foreach ($other_checks as $key => $check): ?>
            <div class="check-item <?php echo $check['status'] ? 'success' : 'error'; ?>">
                <span class="name"><?php echo $check['name']; ?></span>
                <span class="status <?php echo $check['status'] ? 'success' : 'error'; ?>">
                    <?php echo $check['status'] ? '通过' : '失败'; ?>
                    <?php if (!$check['status'] && $check['fixable']): ?>
                    <br><small style="color: #ff4d4f;">尝试自动修复</small>
                    <?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
        
        <div style="text-align: center; margin: 30px 0;">
            <?php if (!$all_checks_ok): ?>
            <form method="POST" action="/install/install.php?step=1" style="display: inline-block; margin-right: 20px;">
                <button type="submit" name="auto_fix" class="next-btn" style="background-color: #fa8c16;">
                    自动修复环境问题
                </button>
            </form>
            <?php endif; ?>
            
            <?php if ($all_checks_ok): ?>
            <form method="POST" action="/install/install.php?step=1" style="display: inline-block;">
                <button type="submit" name="continue" class="next-btn">
                    下一步
                </button>
            </form>
            <?php else: ?>
            <a href="javascript:void(0);" class="next-btn disabled" onclick="alert('请先解决环境问题或点击自动修复'); return false;">
                下一步
            </a>
            <?php endif; ?>
        </div>
        
        <?php if (!$all_checks_ok): ?>
        <div style="background-color: #fff2e8; border: 1px solid #ffbb96; border-radius: 4px; padding: 15px; margin-top: 20px;">
            <h3 style="color: #fa541c; margin-top: 0;">环境检测未通过</h3>
            <p style="color: #fa541c; margin: 0;">
                请先解决上述环境问题，或点击"自动修复环境问题"按钮尝试自动修复。
            </p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
