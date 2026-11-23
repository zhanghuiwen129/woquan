<?php
/**
 * 百度智能云短信（BCE 签名）
 *
 * 密钥：settings.sms_baidu_ak / sms_baidu_sk
 * 其余：sms_baidu_endpoint（host）、sms_baidu_path（请求路径）、sms_baidu_sign_id、sms_baidu_template_id
 * 默认 host：sms.bj.baidubce.com，path 视具体短信产品而定（此处用通用发送路径占位，后台可改）。
 *
 * 请求体：{ "mobile"："138********", "signatureId"："...", "templateId"："...", "contentVar"：{ "code"："123456" } }
 * 响应：{ "code"："1000", ... } 或 { "error_code":0 } 视为成功。
 */

namespace Hublog\Core\Sms;

class BaiduSmsProvider implements ProviderInterface
{
    public static function key(): string
    {
        return 'baidu';
    }

    public static function label(): string
    {
        return '百度智能云短信';
    }

    public static function configKeys(): array
    {
        return [
            'sms_baidu_ak',
            'sms_baidu_sk',
            'sms_baidu_endpoint',
            'sms_baidu_path',
            'sms_baidu_sign_id',
            'sms_baidu_template_id',
        ];
    }

    public static function send(string $phone, string $code, string $scene, array $cfg): bool
    {
        $ak    = trim((string)($cfg['baidu_ak'] ?? ''));
        $sk    = trim((string)($cfg['baidu_sk'] ?? ''));
        $host  = trim((string)($cfg['baidu_endpoint'] ?? '')) ?: 'sms.bj.baidubce.com';
        $path  = trim((string)($cfg['baidu_path'] ?? '')) ?: '/bce/v1/sms/send';
        $sign  = trim((string)($cfg['baidu_sign_id'] ?? ''));
        $tpl   = trim((string)($cfg['baidu_template_id'] ?? ''));
        if ($ak === '' || $sk === '') {
            error_log('[Hublog Sms/baidu] 密钥未配置'); // @allow-error-log
            return false;
        }
        if (!function_exists('curl_init')) {
            error_log('[Hublog Sms/baidu] 未启用 cURL'); // @allow-error-log
            return false;
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $body    = json_encode([
            'mobile'      => $phone,
            'signatureId' => $sign,
            'templateId'  => $tpl,
            'contentVar'  => ['code' => $code],
        ], JSON_UNESCAPED_UNICODE);
        $date    = gmdate('Y-m-d\TH:i:s\Z');
        $expires = 1800;

        $auth = self::bceAuth($ak, $sk, 'POST', $host, $path, $body, $date, $expires);

        $ch = curl_init("https://{$host}{$path}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Host: ' . $host,
                'Content-Type: application/json',
                'X-Bce-Date: ' . $date,
                'X-Bce-Content-Sha256: ' . hash('sha256', $body),
                'Authorization: ' . $auth,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            error_log('[Hublog Sms/baidu] cURL 错误: ' . $err); // @allow-error-log
            return false;
        }
        $json = json_decode((string)$resp, true);
        if (!is_array($json)) {
            error_log('[Hublog Sms/baidu] 响应无法解析: ' . substr((string)$resp, 0, 300)); // @allow-error-log
            return false;
        }
        $codeField = (string)($json['code'] ?? $json['error_code'] ?? '');
        if ($codeField !== '' && $codeField !== '1000' && $codeField !== '0') {
            error_log('[Hublog Sms/baidu] 发送失败: ' . ($json['message'] ?? $json['error_msg'] ?? 'unknown')); // @allow-error-log
            return false;
        }
        return true;
    }

    private static function bceAuth(string $ak, string $sk, string $method, string $host, string $path, string $body, string $date, int $expires): string
    {
        $authPrefix  = "bce-auth-v1/{$ak}/{$date}/{$expires}";
        $signingKey  = hash_hmac('sha256', $authPrefix, $sk, true);

        $headersToSign = [
            'host'                 => $host,
            'x-bce-date'           => $date,
            'x-bce-content-sha256' => hash('sha256', $body),
            'content-type'         => 'application/json',
        ];
        ksort($headersToSign);

        $canonicalHeaders = '';
        $signedList       = [];
        foreach ($headersToSign as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim((string)$v) . "\n";
            $signedList[]      = $k;
        }
        $signedHeaders = implode(';', $signedList);

        $canonicalRequest = strtoupper($method) . "\n" . $path . "\n" . '' . "\n" . $canonicalHeaders;
        $signature   = hash_hmac('sha256', $canonicalRequest, $signingKey);
        return $authPrefix . '/' . $signedHeaders . '/' . $signature;
    }
}
