<?php
/**
 * 会员系统治理护栏（2026-11-24）
 *
 * 背景：会员系统分析报告列出 4 个 P0 风险 + 三类根因（同一事实多处定义、
 * 鉴权靠调用方自觉、冗余状态无对账）。本测试把每条治理目标锚成自动断言，
 * 修复一处、转绿一组，避免"改完又改回去"。
 *
 * 分组：
 *   A. 不变契约（当前已正确，防回归）：VIP 三档、管理员判定、token_version 语义
 *   B. P0 修复目标（修复前失败、修复后转绿）：注册限流、OAuth state、路由分组、tv 严格校验
 *   C. 收敛目标（阶段 2）：权益单一源、VIP 展示接口驱动
 *
 * 运行：
 *   D:/BtSoft/php/81/php.exe vendor/bin/phpunit -c phpunit.xml.dist --filter MemberSystemGuardTest
 */

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Controllers\PaymentController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MemberSystemGuardTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function src(string $rel): string
    {
        $path = self::root() . '/' . $rel;
        return is_file($path) ? (string)file_get_contents($path) : '';
    }

    // ==================== A. 不变契约 ====================

    public function testAdminRequiresRoleNotUid(): void
    {
        $auth = self::src('api/core/Auth.php');
        self::assertStringContainsString("\$user['role'] !== 'admin'", $auth,
            '管理员判定必须走 role 枚举，不能退化为 uid==1');
        // uid==1 只允许出现在超管保护逻辑里，不能成为鉴权条件
        self::assertStringNotContainsString("\$user['id'] === 1", $auth);
    }

    public function testVipTierConstantsRemainSingleSource(): void
    {
        $ref = new ReflectionClass(PaymentController::class);
        $expected = [
            'VIP_DAYS' => [1 => 30, 2 => 90, 3 => 365],
            'VIP_NAMES' => [1 => '青铜', 2 => '白银', 3 => '黄金'],
            'POST_DAILY_LIMIT' => [0 => 10, 1 => 100, 2 => 100, 3 => 100],
            'CIRCLE_LIMIT' => [0 => 1, 1 => 5, 2 => 5, 3 => 5],
        ];
        foreach ($expected as $name => $values) {
            $c = $ref->getReflectionConstant($name);
            self::assertNotFalse($c, "常量 {$name} 缺失");
            self::assertSame($values, $c->getValue(), "常量 {$name} 被改动");
        }
    }

    public function testPointsAndBalanceUseAtomicConditionalUpdate(): void
    {
        $point = self::src('api/core/PointRepository.php');
        $pay = self::src('api/core/PaymentRepository.php');
        self::assertStringContainsString('points = points + ?', $point,
            '积分必须走原子自增，禁止读改写');
        self::assertStringContainsString('points >= ?', $pay,
            '积分抵扣必须带条件防止扣穿');
    }

    public function testPasswordUsesBcryptCost12(): void
    {
        $auth = self::src('api/core/Auth.php');
        self::assertStringContainsString('PASSWORD_BCRYPT', $auth);
        self::assertStringContainsString("'cost' => 12", $auth);
    }

    // ==================== B. P0 修复目标 ====================

    /**
     * P0-1：注册必须有人机校验。当前 register() 无任何验证码/限流调用。
     * 修复方向：引入独立注册限流（如 Security::checkRegisterLock()）或验证码校验。
     */
    public function testRegisterHasAbuseControl(): void
    {
        $auth = self::src('api/controllers/AuthController.php');
        $register = self::methodBody($auth, 'register');
        $hasGuard = str_contains($register, 'checkRegisterLock')
            || str_contains($register, 'Captcha')
            || str_contains($register, 'captcha')
            || str_contains($register, 'verifyCode');
        self::assertTrue($hasGuard,
            'P0-1：register() 缺少人机校验/独立限流，可批量注册垃圾账号');
    }

    /**
     * P0-2：OAuth App 兑换必须强制 state。当前 state 为空直接跳过、未命中仅告警放行。
     * 修复方向：state 缺失或未命中一律返回错误。
     */
    public function testOAuthExchangeRequiresState(): void
    {
        $auth = self::src('api/controllers/AuthController.php');
        $exchange = self::methodBody($auth, 'oauthExchange');
        self::assertStringNotContainsString('App 兼容放行', $exchange,
            'P0-2：OAuth App 兑换不得对未命中 state 放行');
        $hasStrict = str_contains($exchange, "empty(\$state)")
            || str_contains($exchange, "state === ''");
        self::assertTrue($hasStrict,
            'P0-2：oauthExchange() 必须显式校验 state 非空');
    }

    /**
     * P0-3：/api/user/* 写接口必须在需登录组。当前整段挂在公开组（routes.php:101-125）。
     * 修复方向：把这些写接口移到带 AuthMiddleware 的分组。
     */
    public function testUserWriteRoutesRequireAuthMiddleware(): void
    {
        $routes = self::src('api/routes.php');
        // 公开组 = 从「公开接口（限流）」到其后第一个 group 结束的中间件声明
        $start = strpos($routes, '// 公开接口（限流）');
        self::assertNotFalse($start, '未找到公开组起点');
        $tail = substr($routes, $start);
        $end = strpos($tail, "}, ['RateLimitMiddleware', 'CsrfMiddleware'");
        self::assertNotFalse($end, '未找到公开组终点');
        $openGroup = substr($tail, 0, $end);
        // 用「路径 + 方法」组合精确匹配，避免方法名撞车（如 CronHeartbeatController::heartbeat）
        $writeRoutes = [
            "'/user/privacy'", "'/user/draft'", "'/user/appeal'",
            "'/user/notify-prefs'", "'/user/phone/verify'", "'/user/frame'",
            "'/user/alive'", "'/user/export-data'",
        ];
        foreach ($writeRoutes as $needle) {
            self::assertStringNotContainsString($needle, $openGroup,
                'P0-3：用户写接口 ' . $needle . ' 不得注册在无 AuthMiddleware 的公开组');
        }
    }

    /**
     * P0-4a：tv 校验不允许 isset 守卫。当前缺 tv 的旧 token 可绕过版本失效。
     */
    public function testTokenVersionCheckIsStrict(): void
    {
        $auth = self::src('api/core/Auth.php');
        self::assertStringNotContainsString("isset(\$payload['tv']) &&", $auth,
            'P0-4a：tv 校验必须严格，缺 tv 应直接拒绝而非放行');
    }

    /**
     * P0-4b：登出必须走 SQL 自增 token_version，不能读改写。
     */
    public function testLogoutBumpsTokenVersionAtomically(): void
    {
        $auth = self::src('api/controllers/AuthController.php');
        self::assertStringNotContainsString("\$user['token_version'] + 1", $auth,
            'P0-4b：登出不得用 token_version+1 读改写，须 SQL 原子自增');
    }

    // ==================== C. 阶段 2 收敛目标 ====================

    /**
     * 权益单一源：前端三套主题 + App 不应各自硬编码 VIP 名称映射。
     * 修复方向：由 /api/pay/vip-info 的 plans/privileges 驱动。
     */
    public function testVipNamesHaveSingleFrontendSource(): void
    {
        $app = self::root() . '/app-client/src/utils/vip.js';
        self::assertTrue(is_file($app), 'App VIP 工具文件应存在');
        $appSrc = (string)file_get_contents($app);

        // App 侧保留展示映射可以接受，但必须存在从接口 privileges 读取的路径
        $api = self::src('app-client/src/api/index.js');
        self::assertStringContainsString('vip-info', $api,
            '前端必须消费 /pay/vip-info 的权益数据');

        $vipVue = self::src('app-client/src/pages/vip/vip.vue');
        self::assertStringContainsString('privileges', $vipVue,
            '会员中心必须用接口返回的 privileges 驱动权益展示，不得全硬编码');
    }

    // ==================== D. 阶段 3 数据一致性目标 ====================

    /** 只读对账：余额/积分对账脚本存在，且明确不自动改库（资金表自动覆盖会洗掉真实错误） */
    public function testWalletReconcilerIsReadOnly(): void
    {
        $reconciler = self::src('api/core/WalletReconciler.php');
        $cli = self::src('bin/reconcile-wallet.php');
        self::assertNotSame('', $reconciler, 'WalletReconciler 应存在');
        self::assertStringContainsString('SUM(w.amount)', $reconciler,
            '对账须基于 wallet_logs 流水累计');
        self::assertStringContainsString('SUM(p.points)', $reconciler,
            '对账须基于 points_logs 流水累计');
        // 只读红线：对账器内不得有针对 users 的 UPDATE / DELETE
        self::assertStringNotContainsString('UPDATE users', $reconciler,
            '对账器只读，禁止 UPDATE users（资金数据自动覆盖会掩盖 bug）');
        self::assertStringNotContainsString('DELETE FROM', $reconciler);
        self::assertStringContainsString('reconcile-wallet.php', self::src('docs/数据库结构说明.md')
            . self::src('docs/运维排障手册.md') . self::src('CONTRIBUTING.md'),
            '对账脚本须写入运维文档');
    }

    /** VIP 定时降级：cron 路由 + 读时兜底保留 + 缓存清理，三者缺一不可 */
    public function testVipExpiryCronWiredWithoutRemovingReadTimeFallback(): void
    {
        $routes = self::src('api/routes.php');
        self::assertStringContainsString("'/cron/vip-expire'", $routes,
            'VIP 降级 cron 路由须注册');
        $csrf = self::src('api/middleware/CsrfMiddleware.php');
        self::assertStringContainsString('/api/cron/vip-expire', $csrf,
            'VIP 降级 cron 须加入 CSRF 白名单');
        // 读时兜底必须保留：未配 cron 的站点靠它保证过期用户拿不到权益
        $pay = self::src('api/controllers/PaymentController.php');
        self::assertStringContainsString('function syncVip', $pay,
            '读时兜底 syncVip() 必须保留，不能被定时降级取代');
        $svc = self::src('api/core/VipExpiryService.php');
        self::assertStringContainsString('Auth::clearUserCache', $svc,
            '降级后必须清认证缓存，否则头像框可陈旧 300 秒');
        self::assertStringContainsString('<= NOW()', $svc,
            'cron 降级边界须与 syncVip 的 <= time() 口径一致');
    }

    /** 验证码哈希：库里只存 bcrypt，校验双模式兼容存量明文 */
    public function testPhoneCodeStoredHashed(): void
    {
        $svc = self::src('api/core/PhoneCodeService.php');
        self::assertStringContainsString("password_hash(\$code, PASSWORD_DEFAULT)", $svc,
            '验证码入库必须是 bcrypt 哈希，不能存 6 位明文');
        self::assertStringContainsString('password_verify($code, $stored)', $svc,
            '校验须支持 bcrypt 哈希');
        self::assertStringContainsString('hash_equals($stored, $code)', $svc,
            '校验须兼容存量明文（TTL 到期前）');
    }

    /** OAuth token：仓储写入统一走加密工具，raw_data 保持明文（账号打通依赖 JSON_EXTRACT） */
    public function testOAuthTokenEncryptedAtRest(): void
    {
        $cipher = self::src('api/core/OAuthTokenCipher.php');
        self::assertStringContainsString('aes-256-gcm', $cipher,
            '第三方令牌须 AES-GCM 对称加密（可解密再用）');
        self::assertStringContainsString("hash_hmac('sha256', self::DOMAIN, JWT_SECRET", $cipher,
            '密钥须从 JWT_SECRET 域分离派生，不新增部署配置');
        foreach (['api/core/UserRepository.php', 'api/core/AppRepository.php'] as $f) {
            $src = self::src($f);
            self::assertStringContainsString('OAuthTokenCipher::encrypt', $src,
                $f . ' 的 OAuth 写入须加密令牌');
        }
        // raw_data 不加密：账号打通依赖 JSON_EXTRACT
        $app = self::src('api/core/AppRepository.php');
        self::assertStringContainsString("'raw_data' => \$rawData", $app,
            'raw_data 必须保持明文，JSON_EXTRACT 账号打通依赖它');
    }

    /** status 二义性拆分：新增 account_state 列，写入点全部收口，不改 status 查询语义 */
    public function testStatusAmbiguitySplitWithAccountState(): void
    {
        // 迁移 + 终态 DDL 都有 account_state
        self::assertStringContainsString(
            'account_state',
            self::src('api/core/MigrationBatchC1.php')
                . self::src('api/core/MigrationBatchC2.php')
                . self::src('api/core/MigrationBatchC3.php')
                . self::src('api/core/MigrationBatchC4.php')
                . self::src('api/core/MigrationBatchC5.php')
        );
        self::assertStringContainsString('account_state', self::src('api/core/FreshInstallSchema.php'));
        // 封禁/解封/软删/注销四处写入都维护 account_state
        self::assertStringContainsString('account_state', self::src('api/core/UserRepository.php'));
        $admin = self::src('api/core/AdminRepository.php');
        self::assertStringContainsString('account_state', $admin,
            '批量封禁/解封/软删须维护 account_state');
        self::assertStringContainsString('account_state', self::src('api/controllers/AppController.php'),
            'App 注销须标记 account_state=2');
        // 版本号一致
        $mig = self::src('api/core/Migration.php');
        $schema = self::src('api/core/FreshInstallSchema.php');
        preg_match('/currentVersion = (\d+)/', $mig, $m);
        preg_match('/SCHEMA_VERSION = (\d+)/', $schema, $s);
        self::assertSame($m[1] ?? '', $s[1] ?? 'x', '迁移版本与终态 schema 版本必须一致');
    }

    /** 后台积分调整须在事务内调 addPoints，否则流水失败时积分已落库 */
    public function testAdminPointsAdjustUsesTransaction(): void
    {
        $ctl = self::src('api/controllers/AdminPointsController.php');
        $body = self::methodBody($ctl, 'pointsAdjust');
        self::assertStringContainsString('Database::beginTransaction', $body,
            '后台积分调整必须在事务内，防止 users.points 与 points_logs 漂移');
        self::assertStringContainsString('Database::rollBack', $body);
    }

    // ==================== E. 阶段 4 审计能力目标 ====================

    /** 会员变更审计：表存在、写入收口、容错不影响主流程 */
    public function testMemberChangeAuditWired(): void
    {
        $service = self::src('api/core/MemberChangeAudit.php');
        self::assertNotSame('', $service, 'MemberChangeAudit 服务应存在');
        self::assertStringContainsString('member_change_logs', $service);
        self::assertStringContainsString('try {', $service, '审计写入必须容错');
        // before/after 证据链
        self::assertStringContainsString('before_value', $service);
        self::assertStringContainsString('after_value', $service);
        // 写入点收口到关键 Repository（VIP/余额/积分）+ 到期降级服务
        foreach ([
            'api/core/FinanceRepository.php',
            'api/core/PointRepository.php',
            'api/core/VipExpiryService.php',
        ] as $f) {
            self::assertStringContainsString('MemberChangeAudit::record', self::src($f),
                $f . ' 的关键变更须写审计');
        }
        // 积分审计只对后台调整，避免高频流水膨胀
        $point = self::src('api/core/PointRepository.php');
        self::assertStringContainsString("\$type === 'adjust' && \$bizType === 'admin'", $point,
            '积分审计必须限定后台调整场景');
        // 新表进迁移 + 归档白名单
        self::assertStringContainsString(
            'member_change_logs',
            self::src('api/core/MigrationBatchC1.php')
                . self::src('api/core/MigrationBatchC2.php')
                . self::src('api/core/MigrationBatchC3.php')
                . self::src('api/core/MigrationBatchC4.php')
                . self::src('api/core/MigrationBatchC5.php')
        );
        self::assertStringContainsString('member_change_logs', self::src('api/core/TableArchive.php'));
    }

    /** 宽表拆分/RBAC 明确不在本轮做：防止护栏诱导高风险重构 */
    public function testNoExplosiveRefactorOfAuthHotPath(): void
    {
        $auth = self::src('api/core/Auth.php');
        // Auth 热路径仍从 users 单表读（未拆成 4 表 JOIN）
        self::assertStringContainsString('FROM users WHERE id = ?', $auth,
            'Auth 热路径保持单表查询，禁止本轮拆表引入 4 表 JOIN');
        // role 仍是三档枚举，未引入 RBAC 表
        $schema = self::src('api/core/FreshInstallSchema.php');
        self::assertStringNotContainsString("CREATE TABLE IF NOT EXISTS `user_roles`", $schema,
            '本轮不引入 RBAC 表');
    }

    // ==================== helpers ====================

    /** 粗粒度取方法体（从 function xxx 到下一个 function 声明） */
    private static function methodBody(string $src, string $name): string
    {
        $pos = strpos($src, 'function ' . $name . '(');
        if ($pos === false) {
            return '';
        }
        $next = strpos($src, "\n    public function ", $pos + 10);
        $nextPrivate = strpos($src, "\n    private function ", $pos + 10);
        $candidates = array_filter([$next, $nextPrivate]);
        $end = $candidates ? min($candidates) : strlen($src);
        return substr($src, $pos, $end - $pos);
    }

    /** 按行号取路由分组体（ Approximate，源码级护栏够用） */
    private static function groupBody(string $src, int $startLine, int $endLine): string
    {
        $lines = explode("\n", $src);
        return implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }
}
