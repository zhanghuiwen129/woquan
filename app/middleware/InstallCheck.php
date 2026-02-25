<?php
namespace app\middleware;

use think\facade\Db;
use think\facade\Config;
use think\facade\Route;

class InstallCheck
{
    public function handle($request, \Closure $next)
    {
        // 定义允许在未安装时访问的路径
        $allowedPaths = [
            'install' => true,
            'public/install' => true,
        ];

        $url = $request->url();
        $pathInfo = $request->pathinfo();

        // 检查是否是安装相关路径
        $isInstallPath = false;
        foreach ($allowedPaths as $path => $v) {
            if (strpos($pathInfo, $path) === 0) {
                $isInstallPath = true;
                break;
            }
        }

        // 如果是安装相关路径，直接放行
        if ($isInstallPath) {
            return $next($request);
        }

        // 检查安装锁文件
        $lockFile = app()->getRootPath() . 'public/install/install.lock';
        $hasLockFile = file_exists($lockFile);

        // 检查数据库中是否有表
        $hasTables = false;
        try {
            // 尝试连接数据库
            $database = Config::get('database.connections.mysql');
            $dsn = "mysql:host={$database['hostname']};port={$database['hostport']};dbname={$database['database']};charset=utf8mb4";
            $pdo = new \PDO($dsn, $database['username'], $database['password']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // 检查是否有数据表
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            $hasTables = !empty($tables);
        } catch (\Exception $e) {
            $hasTables = !empty($tables);
        }

        // 情况1：有安装锁，但没有数据表 -> 跳转到安装页面
        if ($hasLockFile && !$hasTables) {
            return redirect('/install');
        }

        // 情况2：没有安装锁，但有数据表 -> 提示恢复安装锁
        if (!$hasLockFile && $hasTables) {
            // 首次请求时创建临时标记，避免重复提示
            $showWarning = session('install_lock_warning_shown', false);
            if (!$showWarning && !$request->isAjax()) {
                session('install_lock_warning_shown', true);

                // 返回带有警告信息的页面
                return $this->showInstallLockWarning($request);
            }
        }

        // 情况3：既没有安装锁，也没有数据表 -> 跳转到安装页面
        if (!$hasLockFile && !$hasTables) {
            return redirect('/install');
        }

        // 已安装，正常访问
        return $next($request);
    }

    /**
     * 显示安装锁缺失警告
     */
    private function showInstallLockWarning($request)
    {
        $url = $request->url();
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装锁缺失 - 我圈社交平台</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Microsoft YaHei', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .warning-container {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        .icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: shake 0.5s ease-in-out;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        h1 {
            color: #f59e0b;
            font-size: 28px;
            margin-bottom: 16px;
        }
        p {
            color: #666;
            line-height: 1.8;
            margin-bottom: 12px;
            font-size: 16px;
        }
        .highlight {
            color: #667eea;
            font-weight: bold;
        }
        .btn-group {
            margin-top: 30px;
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        .btn-secondary {
            background: #f3f4f6;
            color: #666;
        }
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        .note {
            margin-top: 25px;
            padding: 15px;
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
            text-align: left;
            font-size: 14px;
        }
        .note strong {
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="warning-container">
        <div class="icon">⚠️</div>
        <h1>安装锁文件缺失</h1>

        <p>系统检测到您的数据库中已存在数据表，但是<span class="highlight">安装锁文件</span>丢失了。</p>

        <p>这通常发生在以下情况：</p>
        <ul style="text-align: left; margin: 15px 0 15px 30px; color: #666;">
            <li>手动删除了 <code>public/install/install.lock</code> 文件</li>
            <li>服务器迁移时未复制安装锁文件</li>
            <li>文件权限问题导致锁文件被自动清理</li>
        </ul>

        <p>为保护您的数据安全，系统需要恢复安装锁文件。</p>

        <div class="note">
            <strong>注意：</strong>恢复安装锁是安全操作，不会影响您的任何数据。这只是为了防止意外重新安装导致数据丢失。
        </div>

        <div class="btn-group">
            <a href="javascript:void(0)" onclick="recoverLock()" class="btn btn-primary">
                <span>🔒 恢复安装锁</span>
            </a>
            <a href="$url" onclick="skipWarning()" class="btn btn-secondary">
                <span>➡️ 继续访问网站</span>
            </a>
        </div>
    </div>

    <script>
        async function recoverLock() {
            try {
                const response = await fetch('/scripts/recover_install_lock.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                });

                const result = await response.json();

                if (result.code === 200) {
                    alert('✅ 安装锁已恢复！即将跳转到首页...');
                    setTimeout(() => {
                        window.location.href = '/';
                    }, 1000);
                } else {
                    alert('❌ ' + result.msg);
                }
            } catch (error) {
                alert('恢复失败：' + error.message);
            }
        }

        function skipWarning() {
            // 设置Cookie，24小时内不再显示警告
            document.cookie = 'skip_install_warning=1; path=/; max-age=86400';
            window.location.href = '$url';
        }
    </script>
</body>
</html>
HTML;

        return response($html)->contentType('text/html; charset=utf-8');
    }
}
