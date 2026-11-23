<?php
/**
 * 阶段 0 安全止血 · 回归护栏（2026-11-12）
 *
 * 背景：本次修复的 S1/S2/S6/S7 都是「同一个加固只落地一处」的复发，
 * 而项目没有 CI。这里把每条修复锚定成可自动执行的断言，防止下次改回去。
 *
 * 运行：php api/tests/run_tests.php
 * 说明：能真跑的行为断言（bootstrap / Upload / Router / Request）走真实调用；
 *      依赖 DB 或完整请求生命周期的部分采用源码级护栏（与仓库既有测试同风格）。
 */

declare(strict_types=1);

namespace Hublog\Tests;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/Exception/HublogException.php';
require_once dirname(__DIR__) . '/core/Request.php';
require_once dirname(__DIR__) . '/core/Response.php';
require_once dirname(__DIR__) . '/core/Router.php';
require_once dirname(__DIR__) . '/core/Upload.php';
require_once dirname(__DIR__) . '/core/PhoneCodeService.php';

class Phase0SecurityGuardTest extends \PHPUnit\Framework\TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function src(string $rel): string
    {
        return (string)file_get_contents(self::root() . '/' . $rel);
    }

    /** 起一个独立 PHP 进程跑会 exit / 会 define 常量的代码 */
    private static function subprocess(string $code): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }
        $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1';
        $out = @shell_exec($cmd);
        return $out === null ? null : (string)$out;
    }

    // ---------------- S1：验证码签发收敛 ----------------

    public function testPhoneCodeSigningIsCentralized(): void
    {
        $auth = self::src('api/controllers/AuthController.php');
        $user = self::src('api/controllers/UserController.php');
        $svc  = self::src('api/core/PhoneCodeService.php');

        $this->assertTrue(class_exists(\Hublog\Core\PhoneCodeService::class));
        $this->assertStringContainsString('PhoneCodeService::issue(', $auth);
        $this->assertStringContainsString('PhoneCodeService::issue(', $user);
        // 两个控制器都不再自己拼 dev_code（唯一出口在 Service）
        $this->assertFalse(str_contains($auth, "'dev_code'"));
        $this->assertFalse(str_contains($user, "'dev_code'"));
        // Service 必须同时具备：DEBUG 守卫、生产 503、IP 双闸限流
        $this->assertStringContainsString('APP_DEBUG', $svc);
        $this->assertStringContainsString('503', $svc);
        $this->assertStringContainsString('sms_ip_hour', $svc);
        $this->assertStringContainsString('sms_ip_day', $svc);
        $this->assertStringContainsString('class_exists(Sms::class)', $svc);
    }

    // ---------------- S2：上传白名单 ----------------

    public function testUploadFileDefaultsToSafeWhitelist(): void
    {
        $exts = \Hublog\Core\Upload::defaultAllowedExts();
        foreach (['html', 'svg', 'php', 'phtml', 'xml', 'js'] as $bad) {
            $this->assertFalse(
                in_array($bad, $exts, true),
                "默认白名单不得包含 {$bad}"
            );
        }
        $this->assertTrue(in_array('pdf', $exts, true));

        $ref = new \ReflectionClass(\Hublog\Core\Upload::class);
        $forbidden = $ref->getReflectionConstant('FORBIDDEN_EXTS')->getValue();
        foreach (['html', 'htm', 'svg', 'svgz', 'xml', 'php', 'phtml', 'phar'] as $bad) {
            $this->assertTrue(
                in_array($bad, $forbidden, true),
                "FORBIDDEN_EXTS 必须拦住 {$bad}"
            );
        }

        $upload = self::src('api/core/Upload.php');
        $this->assertFalse(str_contains($upload, 'if ($allowedExts !== null)'));
        $this->assertStringContainsString('self::defaultAllowedExts()', $upload);
        // 文件名多点（evil.php.txt）不能再原样落盘
        $this->assertStringContainsString("pathinfo(\$originalName, PATHINFO_FILENAME)", $upload);

        $chat = self::src('api/controllers/ChatController.php');
        $this->assertStringContainsString('Upload::defaultAllowedExts()', $chat);
        $this->assertFalse(str_contains($chat, 'UPLOAD_ATTACHMENT_DIR, $maxImageSize);'));

        // Apache 侧附件强制下载 + 危险后缀拒绝
        $htaccess = self::src('uploads/.htaccess');
        $this->assertStringContainsString('Content-Disposition "attachment"', $htaccess);
        $this->assertStringContainsString('svgz?|xml', $htaccess);
    }

    // ---------------- S7：弱密钥失败关闭（真行为，子进程） ----------------

    public function testJwtWeakSecretsRejected(): void
    {
        $boot = self::root() . '/bootstrap.php';
        // Windows 的 escapeshellarg 会吞掉参数里的双引号，子进程代码一律用单引号。
        $require = 'require ' . var_export($boot, true) . ';';
        $weak = self::subprocess(
            $require
            . "define('APP_DEBUG', false);"
            . "define('JWT_SECRET', 'your-random-jwt-secret-key');"
            . "\\Hublog\\Bootstrap\\assertJwtSecret(true);"
            . "echo 'SHOULD_NOT_REACH';"
        );
        if ($weak !== null) {
            $this->assertStringContainsString('"code":500', $weak);
            $this->assertFalse(str_contains($weak, 'SHOULD_NOT_REACH'));
        }

        $strong = self::subprocess(
            $require
            . "define('APP_DEBUG', false);"
            . "define('JWT_SECRET', bin2hex(random_bytes(32)));"
            . "\\Hublog\\Bootstrap\\assertJwtSecret(true);"
            . "echo 'PASS';"
        );
        if ($strong !== null) {
            $this->assertStringContainsString('PASS', $strong);
        }

        // config.example.php 不能再提供可直接使用的公开示例密钥
        $example = self::src('config.example.php');
        $this->assertFalse(str_contains($example, "'your-random-jwt-secret-key'"));
        $this->assertFalse(str_contains($example, "'change-me-in-production'"));
    }

    public function testInstallLockLivesInRoot(): void
    {
        $this->assertSame(
            '.installed.lock',
            \Hublog\Bootstrap\INSTALL_LOCK_FILE
        );
        $tmp = sys_get_temp_dir() . '/hublog_guard_' . bin2hex(random_bytes(4));
        mkdir($tmp . '/cache', 0777, true);

        $this->assertFalse(\Hublog\Bootstrap\isInstalled($tmp));
        // 老部署的 cache/install.lock 仍应被识别为「已安装」
        file_put_contents($tmp . '/cache/install.lock', '{}');
        $this->assertTrue(\Hublog\Bootstrap\isInstalled($tmp));
        // 新锁写根目录，清缓存不受影响
        file_put_contents(\Hublog\Bootstrap\installLockPath($tmp), '{}');
        unlink($tmp . '/cache/install.lock');
        $this->assertTrue(\Hublog\Bootstrap\isInstalled($tmp));

        unlink($tmp . '/.installed.lock');
        rmdir($tmp . '/cache');
        rmdir($tmp);

        foreach (['index.php', 'api/index.php', 'install.php'] as $f) {
            $this->assertStringContainsString('Bootstrap\\isInstalled(', self::src($f));
        }
        $this->assertStringContainsString('/.installed.lock', self::src('.gitignore'));
    }

    // ---------------- S6：错误脱敏 ----------------

    public function testFatalAndExceptionOutputIsSanitized(): void
    {
        foreach (['index.php', 'api/index.php'] as $f) {
            $src = self::src($f);
            $this->assertStringContainsString('/bootstrap.php', $src);
            $this->assertStringContainsString('registerFatalHandler(', $src);
            $this->assertStringContainsString('assertJwtSecret(', $src);
            // api/index.php 另有一个「访问日志」shutdown 回调，保留是合理的；
            // 这里只断言 FATAL 兜底不再各自实现一份。
            $this->assertFalse(str_contains($src, 'error_get_last()'));
        }
        $front = self::src('index.php');
        $this->assertFalse(str_contains($front, "exit('模板文件缺失: ' . \$templatePath)"));

        $api = self::src('api/index.php');
        $this->assertFalse(str_contains($api, "'debug_hint'"));
        $this->assertFalse(str_contains($api, "'error_class' => get_class(\$e)"));

        // SQLSTATE 仍可出现在服务端日志里，但绝不能再拼进抛给客户端的异常消息。
        // 第三批 B 项把业务代码收敛到 Logger：Logger::error() 内部仍会 error_log 一份，
        // 所以裸 error_log( 与 Logger::error( 两种形态都满足「细节留在服务端」的护栏意图。
        $oldMessages = [
            'api/core/Auth.php'                    => "'认证服务异常: '",
            'api/core/ChatService.php'             => "'未读数查询失败: '",
            'api/controllers/NotificationController.php' => "'通知未读数查询失败: '",
        ];
        foreach ($oldMessages as $f => $oldMsg) {
            // V99：ChatService 已拆成 7 个 Trait，日志通道散布在 trait 里，改用逻辑源码视图
            $src = $f === 'api/core/ChatService.php' ? SourceView::chatService() : self::src($f);
            $this->assertFalse(str_contains($src, $oldMsg));
            $this->assertTrue(
                str_contains($src, 'error_log(') || str_contains($src, 'Logger::error('),
                "{$f} 缺少服务端错误日志通道"
            );
        }

        // 统一到 Logger 后，护栏的安全语义不得被削弱：
        //   1) Logger::error() 内部仍写一份 error_log（细节留服务端，不回显客户端）；
        //   2) 访问日志走 sanitizeUri，token/password/code 等敏感参数脱敏后才落盘。
        $loggerSrc = self::src('api/core/Logger.php');
        $this->assertStringContainsString('error_log(', $loggerSrc);
        $this->assertStringContainsString('function sanitizeUri(', $loggerSrc);
        $this->assertStringContainsString('self::sanitizeUri(', $loggerSrc);
        // 批次 7 / P1-2：cron secret、后台 api_key、实名 id_card、登录 phone 都不得明文落盘。
        foreach (['secret', 'api_key', 'id_card', 'phone'] as $sensitiveParam) {
            $this->assertStringContainsString(
                $sensitiveParam,
                $loggerSrc,
                "sanitizeUri() 必须覆盖 {$sensitiveParam} 查询参数"
            );
        }
        // ctx 里的 REQUEST_URI / referer 走 json_encode 落盘，写盘前必须先脱敏。
        $this->assertStringContainsString('$safeCtx', $loggerSrc);
        $this->assertStringContainsString('self::sanitizeUri($ctxValue)', $loggerSrc);
    }

    // ---------------- S5：限流不再豁免私网 ----------------

    public function testRateLimitNoLongerSkipsPrivateNetwork(): void
    {
        $src = self::src('api/middleware/RateLimitMiddleware.php');
        $this->assertFalse(str_contains($src, 'isTrustedIp'));
        $this->assertFalse(str_contains($src, "'REMOTE_ADDR'"));
        $this->assertStringContainsString('Security::rateLimit(', $src);
        // 限流调用之前不能有 return 分支
        $posReturn = strpos($src, '        return;');
        $posLimit  = strpos($src, 'Security::rateLimit(');
        $this->assertTrue(
            $posReturn === false || $posReturn > $posLimit,
            '限流调用之前不得存在豁免 return'
        );
        $this->assertStringContainsString('TRUSTED_PROXIES', self::src('config.example.php'));
    }

    // ---------------- S3：在线升级 ----------------

    public function testUpgradeTlsAndSignatureFailClosed(): void
    {
        $src = self::src('api/core/Upgrade.php');
        $this->assertFalse(str_contains($src, 'CURLOPT_SSL_VERIFYPEER => false'));
        $this->assertFalse(str_contains($src, "'verify_peer' => false"));
        $this->assertStringContainsString('CURLOPT_SSL_VERIFYPEER => true', $src);
        $this->assertStringContainsString('CURLOPT_PROTOCOLS', $src);
        $this->assertStringContainsString('Security::validateOutboundUrl(', $src);
        $this->assertStringContainsString('function allowUnsigned()', $src);
        $this->assertStringContainsString('缺少 MANIFEST', $src);
        $this->assertStringContainsString('UPGRADE_ALLOW_UNSIGNED', $src);
        $ai = self::src('api/controllers/AdminAiController.php');
        $this->assertStringContainsString('ALLOW_REMOTE_INSTALLER', $ai);
        $this->assertFalse(str_contains($ai, 'CURLOPT_SSL_VERIFYPEER => false'));
        $this->assertStringContainsString("'verify_peer' => true", self::src('api/core/BaseController.php'));
    }

    // ---------------- S4 / S9：路由与 Request（真行为） ----------------

    public function testRequestQueryReadsUrlParams(): void
    {
        $oldGet = $_GET;
        $_GET = ['ref' => 'main', 'bad' => ['x']];
        try {
            $req = new \Hublog\Core\Request();
            $this->assertSame('main', $req->query('ref'));
            $this->assertNull($req->query('missing'));
            $this->assertNull($req->query('bad')); // 数组参数不得返回
            $this->assertSame('fallback', $req->query('missing', 'fallback'));
        } finally {
            $_GET = $oldGet;
        }
    }

    public function testRouterQuotesLiteralsAndRejectsBadMiddleware(): void
    {
        $router = new \Hublog\Core\Router();
        $routes = new \ReflectionProperty($router, 'routes');
        $routes->setAccessible(true);
        $routes->setValue($router, [
            'GET' => [
                '/sitemap.xml' => ['handler' => static fn() => null, 'middleware' => []],
                '/u/{id}'      => ['handler' => static fn() => null, 'middleware' => []],
            ],
        ]);
        $find = new \ReflectionMethod($router, 'findRoute');
        $find->setAccessible(true);

        $this->assertFalse($find->invoke($router, 'GET', '/sitemapXxml') !== null);
        $this->assertTrue($find->invoke($router, 'GET', '/sitemap.xml') !== null);
        $this->assertTrue($find->invoke($router, 'GET', '/u/42') !== null);

        $src = self::src('api/core/Router.php');
        $this->assertStringContainsString('function patch(', $src);
        $this->assertStringContainsString('preg_quote($segment', $src);
        $this->assertStringContainsString('中间件配置非法', $src);
    }

    public function testRoutesChainAndTopLevelMiddleware(): void
    {
        $src = self::src('api/routes.php');
        // V67 钱包/会员组必须带 AuthMiddleware
        $needle = "'/points/claim'";
        $pos = strpos($src, $needle);
        $this->assertTrue($pos !== false);
        $tail = substr($src, $pos, 400);
        $this->assertStringContainsString("'AuthMiddleware'", $tail);
        // 顶层三条路由都挂了限流
        foreach (["'/api/csrf'", "'/sitemap.xml'", "'/robots.txt'"] as $route) {
            $p = strpos($src, $route);
            $this->assertTrue($p !== false, "缺少路由 {$route}");
            $this->assertStringContainsString("'RateLimitMiddleware'", substr($src, $p, 220));
        }
    }

    // ---------------- S10：其余确证项 ----------------

    public function testMiscHardeningGuards(): void
    {
        $upload = self::src('api/core/Upload.php');
        $this->assertFalse(str_contains($upload, 'move_uploaded_file($file[\'tmp_name\'], $filepath);'));
        $this->assertStringContainsString('imagesavealpha($image, true)', $upload);

        $chat = self::src('api/controllers/ChatController.php');
        $this->assertStringContainsString('validateMembership(', $chat);
    }

    // ---------------- 认证授权组加固 · 批次 1+2（源码级护栏） ----------------

    /** S1-a / P1-1 / P1-3 / P2-9：统一改密入口 + refresh 原子轮换 */
    public function testAuthHardeningPasswordAndRotationGuards(): void
    {
        $repo = self::src('api/core/UserRepository.php');
        $rt   = self::src('api/core/RefreshToken.php');
        $auth = self::src('api/controllers/AuthController.php');
        $user = self::src('api/controllers/UserController.php');

        // 共享入口：SQL 侧 +1、同事务撤销 refresh，旧方法保留但不再被生产调用
        $this->assertStringContainsString('function changePasswordAndInvalidateSessions(', $repo);
        $this->assertStringContainsString('token_version = token_version + 1', $repo);
        $this->assertStringContainsString('RefreshToken::revokeAllForUserInTransaction($id)', $repo);
        $this->assertStringContainsString('function applyPasswordChangeInTransaction(', $repo);
        $this->assertStringContainsString('function claimEmailVerification(', $repo);
        $this->assertStringContainsString('function resetPasswordWithToken(', $repo);
        $this->assertStringContainsString('function updatePasswordAndTokenVersion(', $repo);
        $this->assertStringContainsString('function setPasswordAndBumpToken(', $repo);
        $this->assertSame(
            3,
            substr_count($auth, 'UserRepository::changePasswordAndInvalidateSessions(')
                + substr_count($user, 'UserRepository::changePasswordAndInvalidateSessions(')
        );
        $this->assertStringContainsString('UserRepository::resetPasswordWithToken(', $auth);
        $this->assertFalse(str_contains($auth, 'UserRepository::markVerificationUsed((int)$record[\'id\'])'));

        // rotate() 内不再有连锁撤销，findValidRow 已整段删除
        $start = strpos($rt, 'public static function rotate(');
        $end   = strpos($rt, 'public static function revoke(');
        $rotate = ($start !== false && $end !== false && $end > $start)
            ? substr($rt, $start, $end - $start) : '';
        $this->assertFalse(str_contains($rt, 'function findValidRow('));
        $this->assertFalse(str_contains($rotate, 'revokeAllForUser('));
        $this->assertStringContainsString('token_hash = ? AND revoked = 0 AND expires_at > ?', $rotate);
        $this->assertStringContainsString('if ($claimed !== 1)', $rotate);
        $this->assertStringContainsString('function revokeAllForUserInTransaction(', $rt);
        // CAS 成功后的所有失败分支必须先 commit 再抛，防重放
        $this->assertTrue(substr_count($rotate, 'Database::commit();') >= 4);
    }

    /** P1-4 / P2-6 / P2-11 / P2-13 / P2-12：日志脱敏、CSRF 严格绑定、refresh 登录态、scene 写死 */
    public function testAuthHardeningGuardrailGuards(): void
    {
        $mailer = self::src('api/core/Mailer.php');
        $csrf   = self::src('api/core/Csrf.php');
        $auth   = self::src('api/controllers/AuthController.php');
        $user   = self::src('api/controllers/UserController.php');

        // SMTP 凭据绝不进日志首参
        $this->assertStringContainsString('?string $logLabel = null', $mailer);
        $this->assertStringContainsString('($logLabel ?? $command)', $mailer);
        $this->assertStringContainsString("'AUTH USER ***'", $mailer);
        $this->assertStringContainsString("'AUTH PASSWORD ***'", $mailer);
        $this->assertStringContainsString('Security::maskEmail($to)', $mailer);
        $this->assertFalse(str_contains($mailer, 'Logger::error("[Hublog Mailer] SMTP 命令失败: {$command}'));
        $this->assertFalse(str_contains($mailer, 'to={$to}, subject={$subject}'));

        // CSRF fail-open 分支删除，guest→user 兼容不得恢复
        $this->assertFalse(str_contains($csrf, "\$storedSubject === '' && ctype_digit"));
        $this->assertStringContainsString('hash_equals($storedSubject, $currentSubject)', $csrf);
        $this->assertFalse(str_contains($csrf, "'g:' === substr(\$storedSubject"));

        // refresh 只认已鉴权登录态，不再手工解析 Bearer
        $start = strpos($auth, 'public function refresh(');
        $end   = strpos($auth, 'private function wantsRefreshToken(');
        $refresh = ($start !== false && $end !== false && $end > $start)
            ? substr($auth, $start, $end - $start) : '';
        $this->assertFalse(str_contains($refresh, 'getBearerToken'));
        $this->assertFalse(str_contains($refresh, 'verifyToken'));
        $this->assertStringContainsString('$user = Auth::user($request);', $refresh);
        $this->assertStringContainsString('UserRepository::findRefreshUserById(', $refresh);

        // 限流与 scene 白名单（登录态自服务场景 bind / set_password，login/reset 禁止）
        $this->assertStringContainsString("'forgot_ip_hour'", $auth);
        $this->assertStringContainsString("'forgot_email_day'", $auth);
        $this->assertStringContainsString("'email_code_email_day'", $auth);
        $this->assertStringContainsString("'email_register'", $auth);
        $this->assertStringContainsString("in_array(\$scene, ['bind', 'set_password'], true)", $user);
        $this->assertStringContainsString("\$accountPhone = UserRepository::findPhoneById", $user);
        $this->assertStringContainsString("'nickname_check_ip'", $user);
    }

    /** QA 回归：set_password 场景放行 + 邮箱大小写归一 + 验证码归属强制登录 */
    public function testQaRegressionGuards(): void
    {
        $auth = self::src('api/controllers/AuthController.php');
        $user = self::src('api/controllers/UserController.php');
        $repo = self::src('api/core/UserRepository.php');

        // P1 回归：发码端与校验端 scene 同为 set_password，且拒绝 login/reset
        $start = strpos($user, 'public function sendPhoneCode(');
        $end   = strpos($user, 'public function verifyPhone(');
        $send = ($start !== false && $end !== false && $end > $start)
            ? substr($user, $start, $end - $start) : '';
        $this->assertStringContainsString('in_array($scene, [\'bind\', \'set_password\'], true)', $send);
        $this->assertStringContainsString("'场景参数错误'", $send);
        $this->assertStringContainsString("\$user = Auth::check(\$request);", $send);
        $this->assertFalse(str_contains($send, "\$scene = 'bind';"));

        // P2 残留 1：rateLimit identity 与验证码查询统一小写键
        $this->assertStringContainsString("Security::rateLimit(\$emailKey, 'forgot_email_day'", $auth);
        $this->assertStringContainsString('findLatestVerificationByEmail($emailKey', $auth);
        $this->assertSame(2, substr_count($auth, 'findLatestUnusedVerification($emailKey'));
        $this->assertStringContainsString('invalidateResetVerifications($emailKey', $auth);
        $this->assertStringContainsString('WHERE LOWER(email) = ?', $repo);

        // P2 残留 2：带 user_id 的验证码无论是否登录都强制同 uid
        $this->assertStringContainsString('$recordUid = (int)($record[\'user_id\'] ?? 0);', $auth);
        $this->assertStringContainsString('$recordUid > 0 && (!$viewer || (int)$viewer[\'id\'] !== $recordUid)', $auth);
    }

    /** P2-5：OAuth state 浏览器 Cookie 绑定（Web）+ App 兼容放行 */
    public function testOauthStateBrowserBindingGuards(): void
    {
        $auth = self::src('api/controllers/AuthController.php');

        $start = strpos($auth, 'public function oauthRedirect(');
        $end   = strpos($auth, 'public function oauthCallback(');
        $redirect = ($start !== false && $end !== false && $end > $start)
            ? substr($auth, $start, $end - $start) : '';
        $this->assertStringContainsString('$browserId = bin2hex(random_bytes(16));', $redirect);
        $this->assertStringContainsString("'browser_id' => \$browserId", $redirect);
        $this->assertStringContainsString("setcookie('oauth_state_browser', \$browserId", $redirect);
        $this->assertStringContainsString("'samesite' => 'Lax'", $redirect);
        $this->assertStringContainsString("'secure'   => \$secure", $redirect);

        $start = strpos($auth, 'public function oauthCallback(');
        $end   = strpos($auth, 'public function oauthExchange(');
        $callback = ($start !== false && $end !== false && $end > $start)
            ? substr($auth, $start, $end - $start) : '';
        $this->assertStringContainsString("\$_COOKIE['oauth_state_browser']", $callback);
        $this->assertStringContainsString('hash_equals((string)$stateData[\'browser_id\'], $bid)', $callback);
        $this->assertStringContainsString("Logger::warning('[Hublog OAuth] state IP 不一致", $callback);
        // IP 不一致只能告警，不得出现拒绝分支
        $this->assertFalse(str_contains($callback, "'授权失败：IP 不一致"));
        $this->assertStringContainsString("setcookie('oauth_state_browser', ''", $callback);

        $start = strpos($auth, 'public function oauthExchange(');
        $end   = strpos($auth, 'public function wxMiniLogin(');
        $exchange = ($start !== false && $end !== false && $end > $start)
            ? substr($auth, $start, $end - $start) : '';
        $this->assertStringContainsString("\$state = \$request->getString('state');", $exchange);
        $this->assertStringContainsString("Logger::warning('[Hublog OAuth] exchange state 缓存未命中", $exchange);
        $this->assertStringContainsString('Cache::delete(\'oauth_state_\' . $state);', $exchange);
    }

    /** P2-8 / P2-10 / P1-2：媒体白名单集中化、实名事务与 1062 映射 */
    public function testAuthHardeningDomainGuards(): void
    {
        $media   = self::src('api/core/MediaHelper.php');
        $auth    = self::src('api/controllers/AuthController.php');
        $app     = self::src('api/controllers/AppController.php');
        $rnRepo  = self::src('api/core/RealNameRepository.php');
        $rnCtrl  = self::src('api/controllers/RealNameController.php');

        // 白名单唯一实现 + provider CDN 常量
        $this->assertStringContainsString('function isSafeMediaUrl(', $media);
        $this->assertStringContainsString('array_merge($allowed, $extraHosts)', $media);
        $this->assertStringContainsString("'avatars.githubusercontent.com'", $media);
        $this->assertStringContainsString("'lh3.googleusercontent.com'", $media);
        $this->assertStringContainsString("'thirdwx.qlogo.cn'", $media);
        $this->assertStringContainsString('return MediaHelper::isSafeMediaUrl($url, $userId);', $auth);
        $this->assertStringContainsString('MediaHelper::OAUTH_CDN[\'wechat\']', $app);
        $this->assertStringContainsString('MediaHelper::isSafeMediaUrl($avatar, 0)', $app);

        // 邮箱验证码：原子核销 + 失败计数锁
        $this->assertStringContainsString('Security::checkCodeLock($emailKey', $auth);
        $this->assertStringContainsString('Security::recordCodeFailure($emailKey', $auth);
        $this->assertStringContainsString('Security::clearCodeFailures($emailKey', $auth);
        $this->assertStringContainsString('UserRepository::claimEmailVerification((int)$record[\'id\']', $auth);

        // 实名：短写事务 + 1062 → 409
        foreach (['submitVerification', 'insertVerifiedAndSetUser', 'approveWithUser', 'rejectWithUser', 'revokeWithUser'] as $m) {
            $this->assertStringContainsString('function ' . $m . '(', $rnRepo);
        }
        $this->assertStringContainsString('function isDuplicateKey(', $rnCtrl);
        $this->assertStringContainsString('=== 1062', $rnCtrl);
        $this->assertStringContainsString("'该身份证号已被其他账号使用'", $rnCtrl);
        // 外部核验不得进事务
        $verifyStart = strpos($rnCtrl, 'public function verify(');
        $verifyEnd   = strpos($rnCtrl, 'public function adminList(');
        $verify = ($verifyStart !== false && $verifyEnd !== false && $verifyEnd > $verifyStart)
            ? substr($rnCtrl, $verifyStart, $verifyEnd - $verifyStart) : '';
        $this->assertStringContainsString('RealName::verify($name, $idCard)', $verify);
        $this->assertFalse(str_contains($verify, 'RealNameRepository::deleteUnsuccessfulRecords('));
    }

    /**
     * 支付金融第二批护栏：回调金额校验（微信 log-only 开关 / 支付宝强校验）、
     * 折扣上限 360、最小实付 MIN_PAY、大转盘免费次数事务内重判、订阅余额 402。
     *
     * 依赖 DB / 请求生命周期的分支无法在 run_tests.php 真跑，这里用源码级断言
     * 把每条修复锚定，防止改回去；纯函数与仓储行为另有单测/行为测试覆盖。
     */
    public function testPaymentBatch2Guards(): void
    {
        $gateway = self::src('api/core/PaymentGateway.php');
        $pay     = self::src('api/controllers/PaymentController.php');
        $shop    = self::src('api/controllers/ShopController.php');
        $point   = self::src('api/core/PointRepository.php');

        // --- 修复1：回调金额一致性校验 ---
        // 纯函数 + 字段路径钉死在 data 顶层 amount.total
        $this->assertStringContainsString('function parseQueryAmount(', $gateway);
        $this->assertStringContainsString("\$data['amount']['total']", $gateway);
        $this->assertStringContainsString("'amount_total' => self::parseQueryAmount(\$res['data'])", $gateway);
        // 微信：settings 开关默认 log，仅 enforce 拦截，字段缺失 fail-safe
        $this->assertStringContainsString("\$settings['wx_amount_check'] ?? 'log'", $pay);
        $this->assertStringContainsString("['off', 'log', 'enforce']", $pay);
        $this->assertStringContainsString('[Hublog wxAmountProbe]', $pay);
        // 探针脱敏契约（QA-2 变异实证）：变异曾在探针末尾追加
        // . ' raw=' . json_encode($query) 而 76/0 全绿，说明脱敏没被锁住。
        // 这里用本文件既有的 strpos+substr 取行方式与 assertFalse(str_contains(...)) 断言，
        // 精确截取探针这一条 Logger::warning 语句（到结尾 `);` 为止），钉死"只记结构不记原文"。
        // 不引入 assertStringNotContainsString —— run_tests.php 的最小 TestCase stub 没有该方法。
        $probePos = strpos($pay, "Logger::warning('[Hublog wxAmountProbe]");
        $this->assertNotFalse($probePos, '探针日志语句必须存在');
        $probeEnd = strpos($pay, ');', $probePos);
        $probeLine = $probeEnd !== false
            ? substr($pay, $probePos, $probeEnd - $probePos + 2)
            : substr($pay, $probePos, 400);
        $this->assertFalse(str_contains($probeLine, 'raw='), '探针不得拼接原始响应原文');
        $this->assertFalse(str_contains($probeLine, 'json_encode'), '探针不得 json_encode 整个查单响应');
        $this->assertFalse(str_contains($probeLine, 'transaction_id'), '探针不得记渠道流水号');
        $this->assertFalse(str_contains($probeLine, 'openid'), '探针不得记用户标识');
        $this->assertStringContainsString("\$mode === 'enforce'", $pay);
        $this->assertStringContainsString('金额字段缺失', $pay);
        $this->assertStringContainsString('金额不一致', $pay);
        // 支付宝：验签通过后强校验 total_amount，不符或缺单输出 fail
        $this->assertStringContainsString("\$post['total_amount']", $pay);
        $this->assertStringContainsString('[Hublog notifyAlipay] 金额不符或缺单', $pay);
        $this->assertStringContainsString("echo 'fail';", $pay);

        // --- 修复2：折扣上限 360 + 最小实付 0.01 ---
        $this->assertStringContainsString("\$mode === 'amount' && \$value > 360", $shop);
        $this->assertStringContainsString('立减金额不能超过360元', $shop);
        $this->assertStringContainsString('private const MIN_PAY = 0.01;', $pay);
        $this->assertSame(2, substr_count($pay, 'max(self::MIN_PAY,'), 'buyVip 折扣卡与积分抵扣两处都要 MIN_PAY 兜底');

        // --- 修复3：大转盘免费次数事务内权威重判 ---
        $this->assertStringContainsString("SELECT COUNT(*) FROM wheel_records WHERE user_id = ? AND free = 1 AND DATE(created_at) = ?", $point);
        $this->assertStringContainsString('$isFree = false; // 免费额度已被并发占满', $point);

        // --- 修复4：circleSubscribe 余额不足前置 402 ---
        //  buyVip + circleSubscribe 各一处 402，确认新增了对称分支
        $this->assertSame(2, substr_count($pay, "return Response::error('余额不足，请联系站长充值', 402);"));
    }
}
