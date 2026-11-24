<?php
/**
 * Hublog JWT 认证类
 * Token 版本号校验实现黑名单/强制失效
 */

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;

class Auth
{
    /**
     * 请求内记忆化槽位（user() 的结果）。
     *
     * 原本声明了却从未被读取（只在 clear() 里被清空），现在正式用作请求内缓存。
     */
    private static ?array $currentUser = null;

    /** user() 记忆化对应的 token —— 换了 token 必须重新解析，不能复用旧结果 */
    private static ?string $currentUserToken = null;

    /**
     * 请求内记忆化槽位（check() 的结果）。
     *
     * P2 鉴权双重守卫收敛：
     *   路由中间件（AuthMiddleware / AdminMiddleware）已经校验过一次身份，
     *   控制器里的 requireUser() / requireAdmin() 又会再调一次 ——
     *   每个后台请求因此重复查一次 users 表（118 处 requireAdmin 全部命中这条路径）。
     *
     * 这里只做**请求内**记忆化：同一 token 的第二次及以后调用直接复用第一次的结果。
     * 跨请求绝不缓存 —— check() 每次都查库是有意为之：
     * token_version 变更（封禁 / 改密码）必须立刻生效，
     * 跨请求缓存会把「强制失效」削弱最多 300 秒。
     */
    private static ?array $checkedUser = null;

    /** check() 记忆化对应的 token */
    private static ?string $checkedUserToken = null;

    /** httpOnly 认证 Cookie 名（P0-3：Web 端令牌不再持久化进 localStorage） */
    public const AUTH_COOKIE = 'hublog_token';

    /**
     * 取当前请求的 JWT：优先 Authorization: Bearer 头（App 端 / 显式声明），
     * 缺失时回退 httpOnly Cookie（Web 端刷新后 JS 无 token 字符串的场景）。
     * 注意：CsrfMiddleware 对「无 Bearer 头」的写请求会启用 CSRF 校验，
     * 前端统一请求层需在 Cookie 模式下携带 X-CSRF-Token。
     */
    private static function requestToken(Request $request): ?string
    {
        $token = $request->getBearerToken();
        if ($token !== null && $token !== '') {
            return $token;
        }
        $cookie = $_COOKIE[self::AUTH_COOKIE] ?? null;
        return (is_string($cookie) && $cookie !== '') ? $cookie : null;
    }

    /**
     * 下发 httpOnly 认证 Cookie（登录 / 注册 / 续期成功时调用）。
     * - httponly：JS 不可读，XSS 无法窃取持久化令牌
     * - SameSite=Lax：跨站 POST 不自动携带，缓解 CSRF（写接口另有 CSRF 校验兜底）
     * - secure 仅在 HTTPS 请求下置位：内网 HTTP 部署不至于登录环
     */
    public static function setAuthCookie(string $jwt): void
    {
        if (headers_sent()) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::AUTH_COOKIE, $jwt, [
            'expires'  => time() + (int)JWT_EXPIRE,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** 清除 httpOnly 认证 Cookie（退出登录时调用） */
    public static function clearAuthCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::AUTH_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * 生成 JWT Token（携带 Token 版本号 + 唯一 jti 防重放）
     * - nbf: 生效时间，避免 Token 在签发前被误用
     * - jti: 32 位十六进制随机串，用于日志审计与未来撤销列表
     */
    public static function generateToken(array $user): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $time = time();
        $payload = json_encode([
            'iss' => APP_NAME,
            'iat' => $time,
            'nbf' => $time,
            'exp' => $time + JWT_EXPIRE,
            'sub' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'] ?? 'user',
            'tv' => $user['token_version'] ?? 1, // Token 版本号
            'jti' => bin2hex(random_bytes(16)) // 唯一令牌 ID
        ]);

        $base64Header = self::base64UrlEncode($header);
        $base64Payload = self::base64UrlEncode($payload);

        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, JWT_SECRET, true);
        $base64Signature = self::base64UrlEncode($signature);

        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }

    /**
     * 解析并验证 Token（含版本号校验）
     */
    public static function verifyToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new HublogException('无效的 Token 格式', 401);
        }

        [$base64Header, $base64Payload, $base64Signature] = $parts;

        $header = json_decode(self::base64UrlDecode($base64Header), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            throw new HublogException('不支持的 Token 算法', 401);
        }

        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, JWT_SECRET, true);
        $expectedSignature = self::base64UrlEncode($signature);

        if (!hash_equals($expectedSignature, $base64Signature)) {
            throw new HublogException('Token 签名无效', 401);
        }

        $payload = json_decode(self::base64UrlDecode($base64Payload), true);

        if (!$payload || !isset($payload['exp'], $payload['sub']) || $payload['exp'] < time()) {
            throw new HublogException('Token 已过期或无效', 401);
        }

        // nbf（生效时间）校验：若设置了 nbf 且当前时间早于 nbf，拒绝使用
        // 容忍 5 秒时钟漂移，避免服务器间时间差导致正常 Token 被拒
        if (isset($payload['nbf']) && time() < ((int)$payload['nbf'] - 5)) {
            throw new HublogException('Token 尚未生效', 401);
        }

        return $payload;
    }

    /**
     * 从请求中获取当前用户（校验 Token 版本号防止失效 Token 继续使用）
     */
    public static function user(Request $request): ?array
    {
        $token = self::requestToken($request);
        if (!$token) {
            return null;
        }

        // P2：请求内记忆化。同一请求里第二处 requireUser() 不必再走一次缓存/查库。
        // 仍然以 token 为键 —— 换了 token（登录 / 退出 / 换号）必须重新解析。
        if (self::$currentUserToken === $token) {
            return self::$currentUser;
        }

        try {
            $payload = self::verifyToken($token);
            $userId = (int)$payload['sub'];
            $tv = (int)($payload['tv'] ?? 1);
            $cacheKey = 'auth_user_' . $userId . '_v' . $tv;
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && (int)($cached['token_version'] ?? 0) === $tv) {
                if ((int)($cached['status'] ?? 0) !== 1) {
                    self::$currentUserToken = $token;
                    self::$currentUser = null;
                    return null;
                }
                self::$currentUserToken = $token;
                self::$currentUser = $cached;
                return $cached;
            }

            // V71：多带 muted_until（Moderation::assertNotMuted 直接用这份缓存用户判断禁言）
            $user = Database::fetchOne(
                "SELECT id, username, nickname, avatar, email, bio, role, status, token_version, created_at,
                        realname_verified, realname_verified_at, vip_level, vip_expire_at, balance, points, avatar_frame,
                        muted_until
                 FROM users WHERE id = ? AND status = 1 LIMIT 1",
                [$userId]
            );
            if (!$user) {
                self::$currentUserToken = $token;
                self::$currentUser = null;
                return null;
            }

            if ($tv !== (int)$user['token_version']) {
                self::$currentUserToken = $token;
                self::$currentUser = null;
                return null;
            }

            // P2-16：认证过的 user_id 写入全局，供 access.log 使用
            $GLOBALS['hublog_access_user_id'] = (int)$user['id'];
            Cache::set($cacheKey, $user, 300);
            self::$currentUserToken = $token;
            self::$currentUser = $user;
            return $user;
        } catch (HublogException $e) {
            self::$currentUserToken = $token;
            self::$currentUser = null;
            return null;
        }
    }

    /**
     * 清除指定用户的认证缓存（封禁/解封/改密码时调用）
     */
    public static function clearUserCache(int $userId): void
    {
        Cache::deleteByPrefix('auth_user_' . $userId . '_');
        // 请求内记忆化里可能还留着这个用户的旧身份（封禁 / 改密码后必须立刻失效）
        self::forgetRequestMemo();
    }

    public static function check(Request $request): array
    {
        // P1-7：只认 Authorization 头。原实现在头缺失时回退读 ?token= 查询参数，
        // 导致普通接口的 JWT 也会落在 URL 里（浏览器历史/Referer/access 日志明文
        // 记录，令牌有效期 14 天 → 长期可重放）。
        // 唯一保留 query token 的是 SSE 流（EventSource 无法自定义请求头），
        // 由 NotificationController::sseStream 自行解析，不走本方法。
        // P0-3：新增 httpOnly Cookie 回退（仅 Web 端刷新后 Bearer 头缺失时生效；
        // query 参数回退不恢复，?token= 仍是禁用的）。
        $token = self::requestToken($request);
        if (!$token) {
            throw new HublogException('请先登录', 401);
        }

        // P2：请求内记忆化 —— 同一 token 的重复校验不再查库。
        // 中间件已校验过一次，控制器里的 requireUser()/requireAdmin() 复用这份结果。
        // 只在**同一请求内**生效：token 变了就重新校验，跨请求一律重新查库。
        if (self::$checkedUserToken === $token && self::$checkedUser !== null) {
            return self::$checkedUser;
        }

        try {
            $payload = self::verifyToken($token);
        } catch (HublogException $e) {
            throw new HublogException('请先登录', 401);
        }

        // 【修复 500】数据库查询单独 try/catch：
        // 旧实现中若 users 表缺失列（如 token_version）、数据库连接挂掉、SQLSTATE 异常，
        // PDOException 会逃出本函数 → 在 index.php 的 Throwable 分支被吞成裸 500，
        // 前端只看到 500 + "服务器内部错误"，无法定位。本次直接抛 HublogException(500) 并附带 SQLSTATE。
        try {
            $user = Database::fetchOne(
                // V71：多带 muted_until，供 Moderation::assertNotMuted() 在写入接口前置校验全站禁言
                "SELECT id, username, nickname, avatar, cover, email, bio, role, status, token_version, created_at,
                        vip_level, vip_expire_at, balance, points, avatar_frame, muted_until
                 FROM users WHERE id = ? LIMIT 1",
                [$payload['sub']]
            );
        } catch (\PDOException $e) {
            // S6：SQLSTATE / 驱动错误码属于内部结构信息，不能拼进客户端响应
            // （HublogException 的消息会被 api/index.php 原样回显）。完整信息写日志。
            error_log('[Hublog Auth DB] ' . $e->getMessage() // @allow-error-log
                . ' [SQLSTATE=' . ($e->errorInfo[0] ?? 'unknown')
                . ' code=' . ($e->errorInfo[1] ?? 'unknown') . ']');
            $detail = (defined('APP_DEBUG') && APP_DEBUG) ? (': ' . $e->getMessage()) : '';
            throw new HublogException('认证服务异常，请稍后重试' . $detail, 500);
        } catch (\Throwable $e) {
            error_log('[Hublog Auth] ' . $e->getMessage()); // @allow-error-log
            $detail = (defined('APP_DEBUG') && APP_DEBUG) ? (': ' . $e->getMessage()) : '';
            throw new HublogException('认证服务异常，请稍后重试' . $detail, 500);
        }

        if (!$user) {
            throw new HublogException('请先登录', 401);
        }

        // 会员系统治理 P0-4a：tv 必须存在且匹配。原 isset 守卫让缺 tv 的旧签名 token
        // 绕过版本失效校验，登出/改密/封禁后仍能继续使用。
        if (!isset($payload['tv']) || (int)$payload['tv'] !== (int)$user['token_version']) {
            throw new HublogException('登录状态已失效，请重新登录', 401);
        }

        if ((int)$user['status'] !== 1) {
            throw new HublogException('账号异常，无法操作', 403);
        }

        // P2-16：强制认证的 user_id 写入全局，供 access.log 使用
        $GLOBALS['hublog_access_user_id'] = (int)$user['id'];
        // P2：记入请求内记忆化（后续 requireUser()/requireAdmin() 复用，0 次查库）
        self::$checkedUserToken = $token;
        self::$checkedUser = $user;
        return $user;
    }

    public static function admin(Request $request): array
    {
        $user = self::check($request);
        if ($user['role'] !== 'admin') {
            throw new HublogException('权限不足', 403);
        }
        return $user;
    }

    public static function hashPassword(string $password): string
    {
        // 使用 Bcrypt（兼容性最佳，避免 Argon2id 在各服务器的兼容问题）
        // password_verify 可同时验证 Bcrypt 和 Argon2id 哈希，旧用户不受影响
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * 清除请求内记忆化的身份（退出登录 / 封禁 / 改密码时调用）。
     *
     * 跨请求本来就查库，这里清的是**同一请求内**已经解析出来的身份 ——
     * 比如登录接口先 check() 再下发新令牌，若不复位，后续 check() 会拿到旧身份。
     */
    public static function clear(): void
    {
        self::forgetRequestMemo();
    }

    /**
     * 清空请求内记忆化槽位。
     */
    private static function forgetRequestMemo(): void
    {
        self::$currentUser = null;
        self::$currentUserToken = null;
        self::$checkedUser = null;
        self::$checkedUserToken = null;
    }

    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}
