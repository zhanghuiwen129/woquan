<?php
/**
 * Hublog OAuth 第三方令牌加密工具（V102）
 *
 * 【为什么需要】oauth_accounts.access_token 注释写着「加密」，实际全是明文入库。
 * 虽然生产代码每次登录都用 code 重新换 token、不回读该字段，但库被拖走时
 * 明文令牌可直接用于访问第三方账号。这里改成对称加密（AES-256-GCM）。
 *
 * 【为什么加密而不是哈希】access_token 理论上要取出再用（刷新/调第三方 API），
 * 不能像 refresh_token 那样单向哈希。用 AES-GCM 密文可解密还原，带认证标签防篡改。
 *
 * 【密钥来源】从已强制配置的 JWT_SECRET 域分离派生，不新增部署配置项：
 *   key = hash_hmac('sha256', 'hublog.oauth_token.enc.v1', JWT_SECRET, true)
 * 生产 bootstrap 强制 JWT_SECRET 非空且非弱口令，可安全作为种子。
 *
 * 【兼容】密文带 `enc:v1:` 前缀；decrypt() 遇到非该前缀的原样返回（存量明文平滑过渡）。
 * 密钥缺失（本地未配置）时 encrypt() 原样返回明文并告警——不产生弱加密，
 * 生产环境 JWT_SECRET 必配，这条降级分支不会走到。
 *
 * 【边界】raw_data 不加密——AppRepository 依赖 JSON_EXTRACT(raw_data,'$.unionid')
 * 做账号打通，加密会破坏该 SQL。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class OAuthTokenCipher
{
    private const PREFIX = 'enc:v1:';
    private const DOMAIN = 'hublog.oauth_token.enc.v1';

    /**
     * 加密令牌。空值 / 密钥缺失时原样返回。
     */
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }
        // 已是密文：避免重复加密（幂等）
        if (str_starts_with($plain, self::PREFIX)) {
            return $plain;
        }
        $key = self::key();
        if ($key === null) {
            error_log('[Hublog OAuthTokenCipher] JWT_SECRET 未配置，令牌按明文存储（仅应出现在本地开发环境）'); // @allow-error-log
            return $plain;
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            error_log('[Hublog OAuthTokenCipher] 加密失败，令牌按明文存储'); // @allow-error-log
            return $plain;
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * 解密令牌。非本工具密文（存量明文）原样返回。
     */
    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored; // 存量明文
        }
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= 28) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    /**
     * 派生 32 字节密钥；JWT_SECRET 未配置/过短返回 null（fail-closed 到明文降级分支）。
     */
    private static function key(): ?string
    {
        if (!defined('JWT_SECRET') || !is_string(JWT_SECRET) || strlen(JWT_SECRET) < 16) {
            return null;
        }
        return hash_hmac('sha256', self::DOMAIN, JWT_SECRET, true);
    }
}
