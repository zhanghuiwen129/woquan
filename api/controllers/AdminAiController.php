<?php
declare(strict_types=1);
/**
 * Hublog 后台「AI与Ollama」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\SettingsRepository;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Security;
use Hublog\Core\Helper;
use Hublog\Core\PathResolver;
use Hublog\Core\Upload;
use Hublog\Core\Upgrade;
use Hublog\Core\Watermark;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\BadgeService;
use Hublog\Core\MediaHelper;

class AdminAiController extends AdminController
{
    public function testAi(Request $request): Response
    {
        $this->requireAdmin($request);

        $apiUrl = trim($request->getString('api_url'));
        $apiKey = trim($request->getString('api_key'));
        $model  = trim($request->getString('model'));

        if ($apiUrl === '') {
            return Response::error('请填写 API 接口地址');
        }

        $reply = \Hublog\Core\AiService::chatCompletion(
            '你是一个测试助手，请回复"连接成功"四个字。',
            '测试连通性',
            [
                'api_url' => $apiUrl,
                'api_key' => $apiKey,
                'model'   => $model ?: 'llama3.2',
                'timeout' => 15,
                'max_tokens' => 50,
                'temperature' => 0.1
            ]
        );

        if ($reply === null) {
            return Response::error('AI 接口调用失败，请检查地址/模型/Ollama 是否运行');
        }

        return Response::success([
            'reply' => $reply,
            'model' => $model
        ], 'AI 接口连接成功');
    }

    /**
     * 检测 Ollama 本地服务状态
     */
    public function ollamaStatus(Request $request): Response
    {
        $this->requireAdmin($request);

        $port = $request->getInt('port', 11434);
        $host = strtolower(trim($request->getString('host', 'localhost')));

        // P1-8：原实现拿请求参数直接拼 URL 探测，等于给管理端开了无限制 SSRF
        // （可打云元数据 169.254.169.254、内网 MySQL/Redis、localhost 上的其它服务）。
        // 本接口用途是「检测 Ollama 是否在本机跑」，收敛为仅允许：
        //   ① 回环地址（localhost / 127.0.0.1 / ::1）
        //   ② 后台已配置的 AI 服务地址主机（ai_comment_api_url / ai_audit_api_url，
        //      Ollama 部署在局域网另一台机器上的场景）
        // 端口限 1~65535；其余一律拒绝。
        if ($port < 1 || $port > 65535) {
            return Response::error('端口不合法', 400);
        }
        $allowedHosts = ['localhost', '127.0.0.1', '::1', '[::1]'];
        foreach (['ai_comment_api_url', 'ai_audit_api_url'] as $settingKey) {
            $configured = trim((string)SettingsRepository::fetchValueByKey($settingKey));
            if ($configured !== '') {
                $parsed = parse_url($configured);
                if (!empty($parsed['host'])) {
                    $allowedHosts[] = strtolower($parsed['host']);
                }
            }
        }
        if (!in_array($host, array_unique($allowedHosts), true)) {
            return Response::error('仅支持检测本机或后台已配置的 AI 服务地址', 400);
        }

        $url = "http://{$host}:{$port}/api/tags";

        if (!function_exists('curl_init')) {
            return Response::error('服务器未启用 cURL 扩展');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            // S3/S10：本地 Ollama 走明文 http，本就不需要 TLS 校验；
            // 原来挂着的 VERIFYPEER=false 一旦将来改成 https 就会静默关闭校验，去掉。
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $os = PHP_OS_FAMILY;
            $hints = [
                'Linux' => 'curl -fsSL https://ollama.com/install.sh | sh',
                'Windows' => '下载安装包：https://ollama.com/download/OllamaSetup.exe 或 winget install Ollama.Ollama',
                'Darwin' => 'brew install ollama 或下载：https://ollama.com/download',
            ];
            return Response::error('Ollama 未运行或无法连接（' . ($error ?: "HTTP {$httpCode}") . '）', 503, [
                'running' => false,
                'host' => $host,
                'port' => $port,
                'os' => $os,
                'install_hint' => $hints[$os] ?? '请访问 https://ollama.com/download 下载安装'
            ]);
        }

        $data = json_decode($response, true);
        $models = [];
        if (is_array($data['models'] ?? null)) {
            foreach ($data['models'] as $m) {
                $models[] = $m['name'] ?? '';
            }
        }

        return Response::success([
            'running' => true,
            'host' => $host,
            'port' => $port,
            'models' => $models,
            'models_count' => count($models)
        ], 'Ollama 运行中');
    }

    /**
     * 一键安装 Ollama
     * POST /api/admin/ai/ollama-install
     * 自动检测操作系统（Linux / Windows / macOS）并执行安装，支持拉取模型
     */
    public function ollamaInstall(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $adminId = (int)($admin['id'] ?? 0);
        $os = PHP_OS_FAMILY;
        $model = trim($request->getString('model', 'llama3.2'));

        // 模型名最终会进 shell_exec（虽已 escapeshellarg），再加一层白名单，
        // 禁止空格/分号/管道等任何脚本片段进入命令。
        if ($model !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,63}$/', $model) !== 1) {
            return Response::error('模型名不合法，仅允许字母数字与 . _ : / -', 400);
        }

        // P0-3：显式开关，默认关闭。settings 里没有该键时一律拒绝；
        // 后台「AI 与 Ollama」设置页勾选后才允许执行远程安装脚本。
        if (!$this->autoInstallAllowed()) {
            $this->auditAutoInstall($adminId, 'ai.ollama_install_denied', $model, $os, 'switch=off; command=none', $request);
            return Response::error(
                '后台远程自动安装默认关闭，请先在后台设置页开启「允许 AI 远程自动安装」后重试（Linux 还需 config.php 的 ALLOW_REMOTE_INSTALLER=true）',
                403
            );
        }

        // 安装过程耗时较长，取消 PHP 执行时间限制
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '256M');
        // 关闭输出缓冲，确保响应能正确发送
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        // 安全检查：检查必要的函数
        $disabledFunctions = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
        foreach (['shell_exec', 'exec'] as $func) {
            if (in_array($func, $disabledFunctions) || !function_exists($func)) {
                return Response::error(
                    "服务器已禁用或不支持 {$func} 函数，无法执行一键安装。请手动安装 Ollama：https://ollama.com/download",
                    403
                );
            }
        }

        $logs = [];
        $now = fn() => '[' . date('Y-m-d H:i:s') . '] ';

        // P0-3：放行也留痕（谁 / 何时 / 模型 / 系统 / 命令面），统一写 admin_audit_logs。
        $this->auditAutoInstall(
            $adminId,
            'ai.ollama_install',
            $model,
            $os,
            'commands=install-script|service-start|ollama-pull',
            $request
        );

        $logs[] = $now() . '操作系统检测：' . $os;

        // 按系统分流
        if ($os === 'Linux') {
            $result = $this->installOllamaLinux($model, $logs, $now);
        } elseif ($os === 'Windows') {
            $result = $this->installOllamaWindows($model, $logs, $now);
        } elseif ($os === 'Darwin') {
            $result = $this->installOllamaMacOS($model, $logs, $now);
        } else {
            return Response::error(
                "一键安装暂不支持系统：{$os}。请访问 https://ollama.com/download 手动下载安装。",
                400
            );
        }

        if ($result instanceof Response) {
            return $result; // 安装失败时的错误响应
        }

        // 通用：拉取模型
        if (!empty($result['installed']) && $model) {
            $logs[] = $now() . '开始拉取模型：' . $model . '（可能需要几分钟）';
            $pullCmd = $os === 'Windows'
                ? 'ollama pull ' . escapeshellarg($model) . ' 2>&1'
                : 'ollama pull ' . escapeshellarg($model) . ' 2>&1';
            $pullOutput = shell_exec($pullCmd);
            if ($pullOutput) {
                $lines = explode("\n", trim($pullOutput));
                $lastLines = array_slice($lines, -5);
                foreach ($lastLines as $line) {
                    if (trim($line)) $logs[] = $now() . trim($line);
                }
            }
            $logs[] = $now() . '✅ 模型 ' . $model . ' 拉取完成';
        }

        return Response::success([
            'installed' => $result['installed'] ?? true,
            'running' => $result['running'] ?? true,
            'model' => $model,
            'os' => $os,
            'logs' => $logs
        ], 'Ollama 一键安装完成');
    }

    /**
     * 后台远程自动安装开关：settings.ai_auto_install 默认关闭。
     * 读取异常按关闭处理（fail-close），绝不因配置表故障放大 shell 权限。
     */
    private function autoInstallAllowed(): bool
    {
        try {
            $raw = SettingsRepository::get('ai_auto_install', '0');
        } catch (\Throwable $e) {
            \Hublog\Core\Logger::error('[Hublog AdminAi] 读取 ai_auto_install 失败，按关闭处理: ' . $e->getMessage());
            return false;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * P0-3 审计：复用后台统一审计表 admin_audit_logs，记录管理员与操作面。
     * 审计写入失败不阻断安装，但要留错误日志。
     */
    private function auditAutoInstall(
        int $adminId,
        string $action,
        string $model,
        string $os,
        string $detail,
        Request $request
    ): void {
        try {
            \Hublog\Core\ChatService::adminLog(
                $adminId,
                $action,
                'ollama',
                $model !== '' ? $model : 'none',
                'os=' . $os . '; ' . $detail,
                $request->getIp()
            );
        } catch (\Throwable $e) {
            \Hublog\Core\Logger::error('[Hublog AdminAi] 自动安装审计写入失败: ' . $e->getMessage());
        }
    }

    /**
     * Linux 环境安装 Ollama
     */
    private function installOllamaLinux(string $model, array &$logs, callable $now): array|Response
    {
        // 架构检测
        $arch = trim(shell_exec('uname -m 2>/dev/null') ?? '');
        $supportedArchs = ['x86_64', 'aarch64', 'arm64'];
        if (!in_array($arch, $supportedArchs)) {
            return Response::error("不支持的 CPU 架构：{$arch}。支持：x86_64、aarch64/arm64", 400);
        }

        // 检测包管理器（仅用于日志显示）
        $packageManager = '未检测到';
        if (shell_exec('command -v apt-get 2>/dev/null')) $packageManager = 'apt';
        elseif (shell_exec('command -v yum 2>/dev/null')) $packageManager = 'yum';
        elseif (shell_exec('command -v dnf 2>/dev/null')) $packageManager = 'dnf';

        $logs[] = $now() . 'CPU 架构：' . $arch;
        $logs[] = $now() . '包管理器：' . $packageManager;

        // 检测是否已安装
        $ollamaPath = trim(shell_exec('command -v ollama 2>/dev/null') ?? '');
        if ($ollamaPath) {
            $logs[] = $now() . 'Ollama 已安装于：' . $ollamaPath . '，跳过安装步骤';
            $version = trim(shell_exec('ollama --version 2>/dev/null') ?? '');
            if ($version) $logs[] = $now() . '当前版本：' . $version;
        } else {
            // S10：把远程脚本直接 `curl ... | sh` 以 Web 进程权限执行，等于把站点
            // 命运交给第三方 CDN 与 DNS（供应链 RCE）。默认 fail-closed；
            // 确需后台一键安装时，运维须在 config.php 显式开启开关。
            if (!(defined('ALLOW_REMOTE_INSTALLER') && ALLOW_REMOTE_INSTALLER === true)) {
                $logs[] = $now() . '已禁用远程脚本自动安装（供应链风险）';
                $logs[] = $now() . '请在服务器上手动执行：curl -fsSL https://ollama.com/install.sh | sh';
                $logs[] = $now() . '如确需后台一键安装，请在后台设置开启 ai_auto_install，并在 config.php 设置 ALLOW_REMOTE_INSTALLER=true';
                return Response::error(
                    '出于安全考虑已禁用远程脚本自动安装，请手动安装 Ollama 后重试；如需开启请在 config.php 设置 ALLOW_REMOTE_INSTALLER=true',
                    403,
                    ['logs' => $logs]
                );
            }
            $logs[] = $now() . '开始下载并安装 Ollama...';
            $installOutput = shell_exec('curl -fsSL https://ollama.com/install.sh | sh 2>&1');
            if ($installOutput) {
                foreach (explode("\n", trim($installOutput)) as $line) {
                    if (trim($line)) $logs[] = $now() . trim($line);
                }
            }
            $newPath = trim(shell_exec('command -v ollama 2>/dev/null') ?? '');
            if (empty($newPath)) {
                return Response::error('Ollama 安装失败，请查看日志或手动安装', 500, ['logs' => $logs]);
            }
            $logs[] = $now() . '✅ Ollama 安装成功：' . $newPath;
        }

        // 启动服务
        $logs[] = $now() . '正在启动 Ollama 服务...';
        $running = false;
        $isSystemd = !empty(shell_exec('command -v systemctl 2>/dev/null'));
        if ($isSystemd) {
            shell_exec('systemctl daemon-reload 2>&1');
            shell_exec('systemctl enable ollama 2>&1');
            $startOutput = shell_exec('systemctl restart ollama 2>&1');
            if ($startOutput) $logs[] = $now() . trim($startOutput);
            sleep(2);
            $status = trim(shell_exec('systemctl is-active ollama 2>/dev/null') ?? '');
            $running = ($status === 'active');
            $logs[] = $now() . '服务状态：' . ($running ? '✅ 运行中' : '⚠️ ' . $status);
        } else {
            shell_exec('nohup ollama serve > /dev/null 2>&1 &');
            sleep(2);
            $running = true;
            $logs[] = $now() . '已通过 nohup 后台启动';
        }

        return ['installed' => true, 'running' => $running];
    }

    /**
     * Windows 环境安装 Ollama
     */
    private function installOllamaWindows(string $model, array &$logs, callable $now): array|Response
    {
        $logs[] = $now() . 'CPU 架构：' . php_uname('m');

        // 检测是否已安装（Windows 用 where 命令）
        $ollamaPath = trim(shell_exec('where ollama 2>nul') ?? '');
        // where 可能返回多行，取第一行
        if ($ollamaPath) {
            $ollamaPath = trim(explode("\n", $ollamaPath)[0]);
        }

        if ($ollamaPath) {
            $logs[] = $now() . 'Ollama 已安装于：' . $ollamaPath . '，跳过安装步骤';
            $version = trim(shell_exec('ollama --version 2>nul') ?? '');
            if ($version) $logs[] = $now() . '当前版本：' . $version;
        } else {
            $logs[] = $now() . '开始安装 Ollama...';

            // 方式1：尝试 winget（Windows 包管理器）
            $wingetPath = trim(shell_exec('where winget 2>nul') ?? '');
            if ($wingetPath) {
                $logs[] = $now() . '检测到 winget，使用 winget 安装...';
                $installOutput = shell_exec('winget install Ollama.Ollama --accept-package-agreements --accept-source-agreements 2>&1');
                if ($installOutput) {
                    foreach (explode("\n", trim($installOutput)) as $line) {
                        if (trim($line)) $logs[] = $now() . trim($line);
                    }
                }
            } else {
                // 方式2：下载 OllamaSetup.exe 并静默安装
                $logs[] = $now() . '未检测到 winget，使用下载安装方式...';
                $tempDir = rtrim(sys_get_temp_dir(), '\\/');
                $exePath = $tempDir . '\\OllamaSetup.exe';

                $logs[] = $now() . '正在下载 OllamaSetup.exe...';
                $downloadCmd = 'powershell -NoProfile -Command "try { Invoke-WebRequest -Uri \'https://ollama.com/download/OllamaSetup.exe\' -OutFile \'' . $exePath . '\' -UseBasicParsing } catch { Write-Error $_.Exception.Message; exit 1 }" 2>&1';
                $downloadOutput = shell_exec($downloadCmd);
                if (!file_exists($exePath)) {
                    $errMsg = $downloadOutput ? trim($downloadOutput) : '下载失败';
                    return Response::error('Ollama 安装包下载失败：' . $errMsg . '。请手动下载：https://ollama.com/download/OllamaSetup.exe', 500, ['logs' => $logs]);
                }
                $logs[] = $now() . '✅ 下载完成，开始静默安装...';

                // 静默安装
                $installOutput = shell_exec('"' . $exePath . '" /SILENT 2>&1');
                if ($installOutput) {
                    foreach (explode("\n", trim($installOutput)) as $line) {
                        if (trim($line)) $logs[] = $now() . trim($line);
                    }
                }
                // 清理安装包
                @unlink($exePath);
                // 等待安装完成
                sleep(3);
            }

            // 验证安装（刷新 PATH 后重新检测）
            $newPath = trim(shell_exec('where ollama 2>nul') ?? '');
            if ($newPath) {
                $newPath = trim(explode("\n", $newPath)[0]);
            }
            // 如果 where 找不到，尝试常见安装路径
            if (empty($newPath)) {
                $commonPaths = [
                    'C:\\Users\\' . get_current_user() . '\\AppData\\Local\\Programs\\Ollama\\ollama.exe',
                    'C:\\Program Files\\Ollama\\ollama.exe',
                    'C:\\Program Files (x86)\\Ollama\\ollama.exe',
                ];
                foreach ($commonPaths as $p) {
                    if (file_exists($p)) {
                        $newPath = $p;
                        break;
                    }
                }
            }
            if (empty($newPath)) {
                return Response::error('Ollama 安装可能未完成，请手动下载安装：https://ollama.com/download/OllamaSetup.exe', 500, ['logs' => $logs]);
            }
            $logs[] = $now() . '✅ Ollama 安装成功：' . $newPath;

            // 将安装路径加入当前进程 PATH，确保后续 ollama 命令可用
            $dir = dirname($newPath);
            if (strpos(getenv('PATH') ?? '', $dir) === false) {
                putenv('PATH=' . $dir . ';' . getenv('PATH'));
            }
        }

        // 启动服务（Windows 安装后自动注册服务，这里确保它在运行）
        $logs[] = $now() . '正在启动 Ollama 服务...';
        $running = false;

        // 尝试用 sc 检查服务状态
        $serviceStatus = trim(shell_exec('sc query Ollama 2>nul') ?? '');
        if (stripos($serviceStatus, 'RUNNING') !== false) {
            $running = true;
            $logs[] = $now() . '服务状态：✅ 运行中（Windows 服务）';
        } elseif (stripos($serviceStatus, 'SERVICE_NAME') !== false) {
            // 服务存在但未运行，尝试启动
            shell_exec('sc start Ollama 2>nul');
            sleep(2);
            $recheck = trim(shell_exec('sc query Ollama 2>nul') ?? '');
            $running = (stripos($recheck, 'RUNNING') !== false);
            $logs[] = $now() . '服务状态：' . ($running ? '✅ 已启动' : '⚠️ 启动失败，尝试直接运行...');
        }

        // 如果服务方式失败，尝试直接后台启动 ollama serve
        if (!$running) {
            shell_exec('start /B ollama serve > nul 2>&1');
            sleep(3);
            // 检测端口是否可连接
            $fp = @fsockopen('localhost', 11434, $errno, $errstr, 2);
            if ($fp) {
                fclose($fp);
                $running = true;
                $logs[] = $now() . '已通过 ollama serve 后台启动';
            } else {
                $logs[] = $now() . '⚠️ Ollama 服务启动中，请稍后手动点击「检测」确认状态';
            }
        }

        return ['installed' => true, 'running' => $running];
    }

    /**
     * macOS 环境安装 Ollama
     */
    private function installOllamaMacOS(string $model, array &$logs, callable $now): array|Response
    {
        $arch = trim(shell_exec('uname -m 2>/dev/null') ?? '');
        $logs[] = $now() . 'CPU 架构：' . $arch;

        // 检测是否已安装
        $ollamaPath = trim(shell_exec('command -v ollama 2>/dev/null') ?? '');
        if ($ollamaPath) {
            $logs[] = $now() . 'Ollama 已安装于：' . $ollamaPath . '，跳过安装步骤';
            $version = trim(shell_exec('ollama --version 2>/dev/null') ?? '');
            if ($version) $logs[] = $now() . '当前版本：' . $version;
        } else {
            // 优先使用 Homebrew
            $brewPath = trim(shell_exec('command -v brew 2>/dev/null') ?? '');
            if ($brewPath) {
                $logs[] = $now() . '检测到 Homebrew，使用 brew 安装...';
                $installOutput = shell_exec('brew install ollama 2>&1');
                if ($installOutput) {
                    foreach (explode("\n", trim($installOutput)) as $line) {
                        if (trim($line)) $logs[] = $now() . trim($line);
                    }
                }
            } else {
                return Response::error('macOS 一键安装需要 Homebrew。请先安装 Homebrew（https://brew.sh）或手动下载：https://ollama.com/download', 400, ['logs' => $logs]);
            }

            $newPath = trim(shell_exec('command -v ollama 2>/dev/null') ?? '');
            if (empty($newPath)) {
                return Response::error('Ollama 安装失败，请手动下载：https://ollama.com/download', 500, ['logs' => $logs]);
            }
            $logs[] = $now() . '✅ Ollama 安装成功：' . $newPath;
        }

        // 启动服务
        $logs[] = $now() . '正在启动 Ollama 服务...';
        $running = false;
        $brewPath = trim(shell_exec('command -v brew 2>/dev/null') ?? '');
        if ($brewPath) {
            shell_exec('brew services start ollama 2>&1');
            sleep(2);
        }
        // 检测端口
        $fp = @fsockopen('localhost', 11434, $errno, $errstr, 2);
        if ($fp) {
            fclose($fp);
            $running = true;
            $logs[] = $now() . '服务状态：✅ 运行中';
        } else {
            shell_exec('nohup ollama serve > /dev/null 2>&1 &');
            sleep(2);
            $running = true;
            $logs[] = $now() . '已通过 nohup 后台启动';
        }

        return ['installed' => true, 'running' => $running];
    }

    // ==================== V36 新增功能 ====================

}
