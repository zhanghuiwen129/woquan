<?php
/**
 * 腾讯云 TRTC / IM UserSig 签发（TLSSigAPIv2）
 *
 * 安全约束（务必遵守）：
 *   - 本类**只能在服务端运行**，SecretKey 绝不下发到客户端。
 *   - 客户端只拿签发好的 UserSig（默认有效期 7 天内的较短时长，建议 1~24 小时）。
 *   - UserSig 不校验身份，任何人拿到都能以该 userId 进房，所以有效期要短。
 *
 * 算法说明：TLSSigAPIv2 = base64url(zlib_compress(JSON)) + HMAC-SHA256 签名，
 * 纯 PHP 实现，不依赖任何 Composer 包（本机/生产都无 composer）。
 */

namespace Hublog\Core;

class TrtcSig
{
    /** 后台设置项 key */
    public const SETTING_SDK_APP_ID = 'trtc_sdk_app_id';
    public const SETTING_SECRET_KEY = 'trtc_secret_key';

    /**
     * 生成 UserSig
     *
     * @param int    $sdkAppId  腾讯云 SDKAppID
     * @param string $secretKey 腾讯云 SecretKey
     * @param string $userId    用户标识（本项目用 user id，字符串化）
     * @param int    $expire    有效期（秒），默认 24 小时
     * @return string
     */
    public static function genUserSig(int $sdkAppId, string $secretKey, string $userId, int $expire = 86400): string
    {
        $currTime = time();

        $sigArray = [
            'TLS.ver'        => '2.0',
            'TLS.identifier' => (string)$userId,
            'TLS.sdkappid'   => $sdkAppId,
            'TLS.expire'     => $expire,
            'TLS.time'       => $currTime,
        ];

        $sigArray['TLS.sig'] = self::hmacSha256($sdkAppId, $secretKey, $userId, $currTime, $expire, null);
        if ($sigArray['TLS.sig'] === '') {
            throw new \RuntimeException('UserSig 签名生成失败');
        }

        $json = json_encode($sigArray, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('UserSig JSON 序列化失败');
        }

        if (!function_exists('gzcompress')) {
            throw new \RuntimeException('PHP 缺少 zlib 扩展，无法生成 UserSig');
        }
        $compressed = gzcompress($json);
        if ($compressed === false) {
            throw new \RuntimeException('UserSig 压缩失败');
        }

        return self::base64UrlEncode($compressed);
    }

    /**
     * 读取后台配置并生成 UserSig
     *
     * @param int|string $userId
     * @param int        $expire
     * @return array{ok:bool, sdk_app_id?:int, user_sig?:string, message?:string}
     */
    public static function issue($userId, int $expire = 86400): array
    {
        $sdkAppId = (int)(License::getSetting(self::SETTING_SDK_APP_ID, '') ?: 0);
        $secretKey = (string)(License::getSetting(self::SETTING_SECRET_KEY, '') ?: '');

        if ($sdkAppId <= 0 || $secretKey === '') {
            return [
                'ok'      => false,
                'message' => '服务端未配置腾讯云 TRTC（需要在后台填写 SDKAppID 与 SecretKey）',
            ];
        }

        try {
            $sig = self::genUserSig($sdkAppId, $secretKey, (string)$userId, $expire);
        } catch (\Throwable $e) {
            error_log('[Hublog TrtcSig] ' . $e->getMessage()); // @allow-error-log
            return ['ok' => false, 'message' => 'UserSig 签发失败'];
        }

        return [
            'ok'         => true,
            'sdk_app_id' => $sdkAppId,
            'user_id'    => (string)$userId,
            'user_sig'   => $sig,
            'expire'     => $expire,
        ];
    }

    /** 后台是否已配置 TRTC（用于前端决定是否显示通话入口） */
    public static function configured(): bool
    {
        $sdkAppId = (int)(License::getSetting(self::SETTING_SDK_APP_ID, '') ?: 0);
        $secretKey = (string)(License::getSetting(self::SETTING_SECRET_KEY, '') ?: '');
        return $sdkAppId > 0 && $secretKey !== '';
    }

    /** 对外只下发 SDKAppID（不下发 SecretKey） */
    public static function sdkAppId(): int
    {
        return (int)(License::getSetting(self::SETTING_SDK_APP_ID, '') ?: 0);
    }

    private static function hmacSha256(
        int $sdkAppId,
        string $secretKey,
        string $userId,
        int $currTime,
        int $expire,
        ?string $base64UserBuf
    ): string {
        $content = "TLS.identifier:{$userId}\n"
            . "TLS.sdkappid:{$sdkAppId}\n"
            . "TLS.time:{$currTime}\n"
            . "TLS.expire:{$expire}\n";

        if ($base64UserBuf !== null) {
            $content .= "TLS.userbuf:{$base64UserBuf}\n";
        }

        return base64_encode(hash_hmac('sha256', $content, $secretKey, true));
    }

    /** 腾讯云要求 URL 安全的 base64：+ → *、/ → -、= → _ */
    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['*', '-', '_'], base64_encode($data));
    }
}
