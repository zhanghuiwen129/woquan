<?php
/**
 * Hublog CSRF 防护类（v2 — 用户绑定版）
 *
 * 【为什么要绑定用户 ID】
 *   旧实现只验证 Token 本身是否在 Cache 中存在。攻击者可以用自己账号先请求一个合法 CSRF Token
 *   （完全合法的步骤），再把这个 Token 嵌入到一个 CSRF 攻击页面，如果此时切换鉴权模式到 Cookie，
 *   或者未来版本引入 Cookie 会话，就能做到"攻击者自己的 Token 喂给受害者浏览器照样通过"，
 *   也就是 CSRF Token 实际上只做到了"必须有 Token"的存在性校验，没有和用户身份绑定。
 *
 *   本实现把 Token 与生成时的用户身份（user_id 或 "guest_会话指纹"）绑定，校验时必须一致。
 *   对未登录用户仍生成 guest 绑定，避免影响公开表单（登录/注册）。
 */

namespace Hublog\Core;

class Csrf
{
    /**
     * 取当前请求的"身份指纹"：登录用户用 user_id，未登录用户用 32 字节 Cookie 会话指纹
     * （该指纹只用于 CSRF 绑定，不作为鉴权依据。）
     *
     * @param Request|null $request 若为 null 则尽力取 Request 全局（generate 端一般能提供）
     */
    private static function currentSubject(?Request $request = null, ?int $overrideUserId = null): string
    {
        if ($overrideUserId !== null && $overrideUserId > 0) {
            return 'u:' . $overrideUserId;
        }

        // 优先读当前已登录 user_id（走 Bearer JWT 的情况）
        $uid = null;
        try {
            if ($request !== null) {
                $auth = Auth::user($request);
                if (is_array($auth) && !empty($auth['id'])) {
                    $uid = (int)$auth['id'];
                }
            }
        } catch (\Throwable $e) {
            $uid = null;
        }
        if ($uid !== null && $uid > 0) {
            return 'u:' . $uid;
        }

        // 未登录 → 生成一个稳定的 guest 指纹并写 Cookie
        $cookieName = 'csrf_guest_subject';
        if (!empty($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName]) && strlen($_COOKIE[$cookieName]) === 64) {
            return 'g:' . $_COOKIE[$cookieName];
        }
        $newSubj = bin2hex(random_bytes(32));
        // 静默写入 Cookie（不保证一定成功，但 CSRF 会再次生成相同 subject）
        if (!headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            setcookie(
                $cookieName,
                $newSubj,
                [
                    'expires'  => time() + (int)CSRF_TOKEN_EXPIRE,
                    'path'     => '/',
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }
        return 'g:' . $newSubj;
    }

    /**
     * 生成 CSRF Token（绑定当前用户身份）
     */
    public static function generate(?Request $request = null): string
    {
        $token   = bin2hex(random_bytes(32));
        $subject = self::currentSubject($request);
        Cache::set('csrf_' . $token, json_encode(['t' => time(), 's' => $subject], JSON_UNESCAPED_UNICODE), (int)CSRF_TOKEN_EXPIRE);
        return $token;
    }

    /**
     * 验证 CSRF Token（需与生成时的用户身份一致）
     *
     * @param string|null $token           前端提交的 Token
     * @param Request|null $request        当前请求（用于取当前用户 ID；若为 null 则不会进行 uid 绑定校验）
     */
    public static function verify(?string $token, ?Request $request = null): bool
    {
        if (empty($token)) {
            return false;
        }
        $payload = Cache::get('csrf_' . $token);
        if (!is_string($payload) || $payload === '') {
            return false;
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || empty($decoded['t']) || !isset($decoded['s'])) {
            return false;
        }
        if ((time() - (int)$decoded['t']) >= (int)CSRF_TOKEN_EXPIRE) {
            return false;
        }

        $storedSubject = (string)$decoded['s'];
        // 旧版「s='' 的遗留纯时间戳 token 直接放行」已移除：fail-open 会让任意可写缓存的
        // 攻击者绕过 CSRF 绑定校验。空 subject 一律走下面的严格比对（必然不匹配）。
        $currentSubject = self::currentSubject($request);

        // 1) 严格按 subject 绑定校验（hash_equals 防时序）
        //    P2-12 加固：移除旧版「guest→user 兼容放行」。
        //    原逻辑允许「未登录时生成的 g:xxx token 被已登录用户 u:NNN 复用」，
        //    在 JWT（非 Cookie 会话）模式下虽难以被第三方直接利用，但实质性削弱了
        //    用户绑定这一核心防护；且已登录用户本就从后端重新获取绑定自身 uid 的 token。
        //    保留严格绑定：token 的 subject 必须与当前请求身份完全一致，否则一律拒绝。
        if (hash_equals($storedSubject, $currentSubject)) {
            return true;
        }

        return false;
    }
}
