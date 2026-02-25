<?php
session_start();

// 定义安装状态常量
const INSTALL_LOCK_FILE = __DIR__ . '/install.lock';

// 定义 env() 辅助函数（用于安装期间）
if (!function_exists('env')) {
    function env($key, $default = null) {
        return $default;
    }
}

// 定义安装步骤常量
const INSTALL_STEPS = [
    0 => 'disclaimer',
    1 => 'env_check',
    2 => 'db_config',
    3 => 'db_install',
    4 => 'admin_setup',
    5 => 'finish'
];

// 初始化安装状态
function initInstallState() {
    if (!isset($_SESSION['install'])) {
        $_SESSION['install'] = [
            'current_step' => 0,
            'completed_steps' => [],
            'agreed' => false,
            'env_ok' => false,
            'db_config' => null,
            'db_installed' => false,
            'admin_created' => false,
            'install_time' => null
        ];
    }
}

// 检查步骤是否可以访问
function canAccessStep($step) {
    // 如果已经完成安装，不允许访问任何步骤（除了删除锁文件后）
    if (file_exists(INSTALL_LOCK_FILE)) {
        return false;
    }
    
    // 确保安装状态已初始化
    if (!isset($_SESSION['install'])) {
        initInstallState();
    }
    
    // 允许访问当前步骤
    if ($step === $_SESSION['install']['current_step']) {
        return true;
    }
    
    // 允许访问下一步（防止用户直接跳到后面步骤）
    if ($step === $_SESSION['install']['current_step'] + 1) {
        return true;
    }
    
    // 允许返回已完成的步骤
    if (in_array($step, $_SESSION['install']['completed_steps'])) {
        return true;
    }
    
    return false;
}

// 标记步骤为已完成
function completeStep($step) {
    if (!isset($_SESSION['install'])) {
        initInstallState();
    }
    if (!in_array($step, $_SESSION['install']['completed_steps'])) {
        $_SESSION['install']['completed_steps'][] = $step;
    }
    $_SESSION['install']['current_step'] = $step + 1;
}

// 重置安装状态
function resetInstallState() {
    unset($_SESSION['install']);
    initInstallState();
}

// 检查是否已经安装
function checkInstallationStatus() {
    if (!file_exists(INSTALL_LOCK_FILE)) {
        return false;
    }

    // 确保安装状态已初始化
    if (!isset($_SESSION['install'])) {
        initInstallState();
    }
    
    // 如果session中还有安装步骤，说明还在安装过程中，允许继续
    if ($_SESSION['install']['current_step'] < 5) {
        return false;
    }

    // 检查数据库是否有数据表
    try {
        $config = require '../../config/database.php';
        $dsn = "mysql:host={$config['connections']['mysql']['hostname']};port={$config['connections']['mysql']['hostport']};dbname={$config['connections']['mysql']['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['connections']['mysql']['username'], $config['connections']['mysql']['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($tables) > 0) {
            // 有安装锁且有数据表 - 已安装
            echo '<div style="text-align:center;margin:20px 0;"><h1>系统已经安装完成</h1></div>';
            echo '<div style="max-width:600px;margin:50px auto;padding:20px;background:#f6ffed;border:1px solid #b7eb8f;border-radius:5px;text-align:center;">';
            echo '<p style="color:#52c41a;">如果需要重新安装，请先删除 public/install 目录下的 install.lock 文件</p>';
            echo '<div style="margin:20px 0;">';
            echo '<a href="/" style="background:#4080FF;color:white;padding:12px 30px;text-decoration:none;border-radius:4px;margin-right:15px;display:inline-block;">访问首页</a>';
            echo '<a href="/admin/login" style="background:#52c41a;color:white;padding:12px 30px;text-decoration:none;border-radius:4px;display:inline-block;">访问后台</a>';
            echo '</div>';
            echo '</div>';
            exit;
        } else {
            // 有安装锁但无数据表 - 提示恢复安装锁
            echo '<h1>检测到安装锁但数据库为空</h1>';
            echo '<div style="max-width:600px;margin:50px auto;padding:20px;background:#fff3cd;border:1px solid #ffeaa7;border-radius:5px;">';
            echo '<p style="color:#856404;">检测到安装锁文件存在，但数据库中没有数据表。</p>';
            echo '<p style="color:#856404;">这可能是因为数据库被清空或重置。</p>';
            echo '<p style="color:#856404;">请选择操作：</p>';
            echo '<div style="margin:20px 0;">';
            echo '<a href="?action=remove_lock" style="background:#dc3545;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;margin-right:10px;">删除安装锁并重新安装</a>';
            echo '<a href="../" style="background:#6c757d;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;">返回首页</a>';
            echo '</div>';
            echo '<p style="font-size:12px;color:#666;">注意：删除安装锁将允许重新安装，但请确保数据库已备份！</p>';
            echo '</div>';
            exit;
        }
    } catch (Exception $e) {
        // 数据库连接失败，提示检查配置
        echo '<h1>数据库连接失败</h1>';
        echo '<div style="max-width:600px;margin:50px auto;padding:20px;background:#f8d7da;border:1px solid #f5c6cb;border-radius:5px;">';
        echo '<p style="color:#721c24;">无法连接到数据库，请检查配置文件：</p>';
        echo '<p style="color:#721c24;">config/database.php</p>';
        echo '<p style="color:#721c24;">错误信息：' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<div style="margin:20px 0;">';
        echo '<a href="?action=remove_lock" style="background:#dc3545;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;margin-right:10px;">删除安装锁并重新安装</a>';
        echo '<a href="../" style="background:#6c757d;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;">返回首页</a>';
        echo '</div>';
        echo '</div>';
        exit;
    }
}

// 处理删除安装锁操作
if (isset($_GET['action']) && $_GET['action'] === 'remove_lock' && file_exists(INSTALL_LOCK_FILE)) {
    unlink(INSTALL_LOCK_FILE);
    resetInstallState();
    header('Location: /install');
    exit;
}

// 处理重新开始安装操作
if (isset($_GET['action']) && $_GET['action'] === 'restart') {
    resetInstallState();
    header('Location: /install');
    exit;
}

// 初始化安装状态
initInstallState();

// 检查安装状态
checkInstallationStatus();

// 获取当前步骤
$step = isset($_GET['step']) ? intval($_GET['step']) : 0;

// 验证步骤是否可以访问
if (!canAccessStep($step)) {
    // 如果步骤无效，重定向到当前步骤
    header('Location: /install?step=' . $_SESSION['install']['current_step']);
    exit;
}

// 安装步骤处理
switch ($step) {
    case 0:
        // 免责声明
        include __DIR__ . '/disclaimer.php';
        break;
    case 1:
        // 环境检测
        include __DIR__ . '/env_check.php';
        break;
    case 2:
        // 数据库配置
        include __DIR__ . '/db_config.php';
        break;
    case 3:
        // 数据库安装
        include __DIR__ . '/db_install.php';
        break;
    case 4:
        // 管理员设置
        include __DIR__ . '/admin_setup.php';
        break;
    case 5:
        // 安装完成
        include __DIR__ . '/finish.php';
        break;
    default:
        // 默认跳转到免责声明
        header('Location: /install?step=0');
        exit;
}
