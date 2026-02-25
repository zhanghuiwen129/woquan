<?php
// Session 详细诊断脚本 - 宝塔面板环境专用
header('Content-Type: text/html; charset=utf-8');

$debugInfo = [];

// 1. 获取当前请求信息
$debugInfo['request'] = [
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'referer' => $_SERVER['HTTP_REFERER'] ?? '',
];

// 2. Session 状态
$debugInfo['session'] = [
    'id' => session_id(),
    'status' => session_status(),
    'name' => session_name(),
    'save_path' => session_save_path(),
];

// 3. 当前 Session 数据
$debugInfo['session_data'] = $_SESSION ?? [];

// 4. Cookie 数据
$debugInfo['cookie'] = $_COOKIE ?? [];

// 5. 模拟 Auth 中间件逻辑
$userIdFromSession = $_SESSION['user_id'] ?? null;
$userIdFromCookie = $_COOKIE['user_id'] ?? null;
$rememberToken = $_COOKIE['remember_token'] ?? null;

$authResult = [
    'session_user_id' => $userIdFromSession,
    'cookie_user_id' => $userIdFromCookie,
    'cookie_remember_token' => $rememberToken,
];

// 如果 Session 为空但 Cookie 有值，尝试恢复
if (!$userIdFromSession && $userIdFromCookie) {
    $_SESSION['user_id'] = $userIdFromCookie;
    $_SESSION['username'] = $_COOKIE['username'] ?? '';
    $_SESSION['nickname'] = $_COOKIE['nickname'] ?? '';
    $_SESSION['avatar'] = $_COOKIE['avatar'] ?? '';
    $authResult['recovered'] = true;
    $authResult['recovered_user_id'] = $userIdFromCookie;
}

$authResult['final_user_id'] = $_SESSION['user_id'] ?? null;
$authResult['is_logged_in'] = !empty($_SESSION['user_id']);

// 6. 路径检查
$pathinfo = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '';
$isProfileRequest = strpos($pathinfo, 'profile') !== false;
$isLoginRequest = strpos($pathinfo, 'login') !== false;
$isApiRequest = strpos($pathinfo, 'api/') === 0;

$debugInfo['path_check'] = [
    'pathinfo' => $pathinfo,
    'is_profile_request' => $isProfileRequest,
    'is_login_request' => $isLoginRequest,
    'is_api_request' => $isApiRequest,
];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Session 诊断 - 宝塔面板</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 1000px; margin: 0 auto; }
        .section { background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .success { color: #155724; background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .error { color: #721c24; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .warning { color: #856404; background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0; }
        h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        h3 { color: #555; margin-top: 20px; }
        pre { background: #eee; padding: 10px; overflow-x: auto; border-radius: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        td, th { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #007bff; color: white; }
        .highlight { background: #fff3cd; padding: 2px 5px; }
    </style>
</head>
<body>
    <h1>🔍 Session 诊断报告</h1>
    
    <div class="section">
        <h2>1. 请求信息</h2>
        <table>
            <tr><th>项</th><th>值</th></tr>
            <tr><td>请求URI</td><td><?= htmlspecialchars($debugInfo['request']['uri']) ?></td></tr>
            <tr><td>请求方法</td><td><?= htmlspecialchars($debugInfo['request']['method']) ?></td></tr>
            <tr><td>来源页面</td><td><?= htmlspecialchars($debugInfo['request']['referer'] ?: '无') ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>2. Session 状态</h2>
        <table>
            <tr><th>项</th><th>值</th></tr>
            <tr><td>Session ID</td><td><?= htmlspecialchars($debugInfo['session']['id']) ?></td></tr>
            <tr><td>Session 状态</td><td><?= $debugInfo['session']['status'] ?> 
                (0=无,1=已启动,2=活动)</td></tr>
            <tr><td>Session 名称</td><td><?= htmlspecialchars($debugInfo['session']['name']) ?></td></tr>
            <tr><td>保存路径</td><td><?= htmlspecialchars($debugInfo['session']['save_path']) ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>3. 当前 Session 数据</h2>
        <?php if (empty($_SESSION)): ?>
            <p class="warning">⚠️ Session 数据为空</p>
        <?php else: ?>
            <table>
                <tr><th>键</th><th>值</th></tr>
                <?php foreach ($_SESSION as $key => $value): ?>
                <tr><td><?= htmlspecialchars($key) ?></td>
                    <td><?= is_scalar($value) ? htmlspecialchars($value) : htmlspecialchars(json_encode($value)) ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>4. Cookie 数据</h2>
        <?php if (empty($_COOKIE)): ?>
            <p class="warning">⚠️ Cookie 数据为空</p>
        <?php else: ?>
            <table>
                <tr><th>键</th><th>值</th></tr>
                <?php foreach ($_COOKIE as $key => $value): ?>
                <tr><td><?= htmlspecialchars($key) ?></td>
                    <td><?= htmlspecialchars($value) ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>5. Auth 中间件逻辑测试</h2>
        <table>
            <tr><th>检查项</th><th>值</th></tr>
            <tr><td>Session user_id</td><td class="<?= $authResult['session_user_id'] ? 'success' : 'warning' ?>">
                <?= $authResult['session_user_id'] ?: '未设置' ?></td></tr>
            <tr><td>Cookie user_id</td><td class="<?= $authResult['cookie_user_id'] ? 'success' : 'warning' ?>">
                <?= $authResult['cookie_user_id'] ?: '未设置' ?></td></tr>
            <tr><td>Cookie remember_token</td><td>
                <?= $authResult['cookie_remember_token'] ? '已设置' : '未设置' ?></td></tr>
            <tr><td>是否已恢复会话</td><td><?= isset($authResult['recovered']) ? '✓ 是' : '✗ 否' ?></td></tr>
            <tr><td>最终 user_id</td><td class="<?= $authResult['final_user_id'] ? 'success' : 'error' ?>">
                <?= $authResult['final_user_id'] ?: '无' ?></td></tr>
            <tr><td>登录状态</td><td class="<?= $authResult['is_logged_in'] ? 'success' : 'error' ?>">
                <?= $authResult['is_logged_in'] ? '✓ 已登录' : '✗ 未登录' ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>6. 路径检查</h2>
        <table>
            <tr><th>检查项</th><th>结果</th></tr>
            <tr><td>当前路径</td><td><?= htmlspecialchars($debugInfo['path_check']['pathinfo']) ?></td></tr>
            <tr><td>是否是 profile 请求</td><td><?= $debugInfo['path_check']['is_profile_request'] ? '✓ 是' : '✗ 否' ?></td></tr>
            <tr><td>是否是 login 请求</td><td><?= $debugInfo['path_check']['is_login_request'] ? '✓ 是' : '✗ 否' ?></td></tr>
            <tr><td>是否是 API 请求</td><td><?= $debugInfo['path_check']['is_api_request'] ? '✓ 是' : '✗ 否' ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>7. 诊断结论</h2>
        <?php if ($authResult['is_logged_in']): ?>
            <div class="success">
                <strong>✓ 用户已登录</strong><br>
                用户ID: <?= htmlspecialchars($authResult['final_user_id']) ?><br>
                如果访问 /profile 仍然跳转到登录页，问题可能在：
                <ul>
                    <li>Auth 中间件配置问题</li>
                    <li>路由配置问题</li>
                    <li>重定向循环</li>
                </ul>
            </div>
        <?php else: ?>
            <div class="error">
                <strong>✗ 用户未登录</strong><br>
                这就是跳转到登录页的原因。<br>
                请检查：
                <ul>
                    <li>浏览器 Cookie 是否已禁用</li>
                    <li>登录时是否成功设置了 Cookie</li>
                    <li>Session 保存路径是否可写</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>8. 快速测试</h2>
        <p>点击以下链接测试登录状态：</p>
        <ul>
            <li><a href="/profile">→ 访问个人主页</a></li>
            <li><a href="/">→ 访问首页</a></li>
            <li><a href="/login">→ 访问登录页</a></li>
        </ul>
    </div>
</body>
</html>