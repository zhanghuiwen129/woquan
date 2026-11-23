<?php
/**
 * 腾讯云短信 · SendSms（TC3-HMAC-SHA256 签名）
 *
 * 密钥：settings.sms_tencent_secret_id / sms_tencent_secret_key
 * 其余：sms_tencent_app_id（SmsSdkAppId）、sms_tencent_sign_name、sms_tencent_template_id、sms_tencent_region
 * host：sms.tencentcloudapi.com，版本 2021-01-11
 *
 * 号码需带国家码前缀（+86），TemplateParamSet 仅一个验证码元素。
 */

namespace Hublog\Core\Sms;

class TencentSmsProvider implements ProviderInterface
{
    public static function key(): string
    {
        return 'tencent';
    }

    public static function label(): string
    {
        return '腾讯云短信';
    }

    public static function configKeys(): array
    {
        return [
            'sms_tencent_secret_id',
            'sms_tencent_secret_key',
            'sms_tencent_app_id',
            'sms_tencent_sign_name',
            'sms_tencent_template_id',
            'sms_tencent_region',
        ];
    }

    public static function send(string $phone, string $code, string $scene, array $cfg): bool
    {
        $secretId  = trim((string)($cfg['tencent_secret_id'] ?? ''));
        $secretKey = trim((string)($cfg['tencent_secret_key'] ?? ''));
        $appId     = trim((string)($cfg['tencent_app_id'] ?? ''));
        $signName  = trim((string)($cfg['tencent_sign_name'] ?? ''));
        $tplId     = trim((string)($cfg['tencent_template_id'] ?? ''));
        if ($secretId === '' || $secretKey === '' || $appId === '' || $signName === '' || $tplId === '') {
            error_log('[Hublog Sms/tencent] 密钥/AppId/签名/模板未配置'); // @allow-error-log
            return false;
        }
        if (!function_exists('curl_init')) {
            error_log('[Hublog Sms/tencent] 未启用 cURL'); // @allow-error-log
            return false;
        }

        $host    = 'sms.tencentcloudapi.com';
        $service = 'sms';
        $action  = 'SendSms';
        $version = '2021-01-11';
        $region  = trim((string)($cfg['tencent_region'] ?? '')) ?: 'ap-guangzhou';

        $payload = json_encode([
            'PhoneNumberSet'   => ['+86' . $phone],
            'SmsSdkAppId'      => $appId,
            'SignName'         => $signName,
            'TemplateId'       => $tplId,
            'TemplateParamSet' => [$code],
        ], JSON_UNESCAPED_UNICODE);

        $timestamp = time();
        $date      = gmdate('Y-m-d', $timestamp);

        $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:{$host}\n";
        $signedHeaders    = 'content-type;host';
        $hashedPayload    = hash('sha256', $payload);
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$hashedPayload}";

        $credentialScope = "{$date}/{$service}/tc3_request";
        $stringToSign    = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $secretDate    = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature     = hash_hmac('sha256', $stringToSign, $secretSigning);

        $authorization = "TC3-HMAC-SHA256 Credential={$secretId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $ch = curl_init("https://{$host}/");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $authorization,
                'Content-Type: application/json; charset=utf-8',
                'Host: ' . $host,
                'X-TC-Action: ' . $action,
                'X-TC-Version: ' . $version,
                'X-TC-Region: ' . $region,
                'X-TC-Timestamp: ' . $timestamp,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            error_log('[Hublog Sms/tencent] cURL 错误: ' . $err); // @allow-error-log
            return false;
        }
        $json = json_decode((string)$resp, true);
        if (!is_array($json)) {
            error_log('[Hublog Sms/tencent] 响应无法解析: ' . substr((string)$resp, 0, 300)); // @allow-error-log
            return false;
        }
        $respBody = $json['Response'] ?? $json;
        if (isset($respBody['Error'])) {
            error_log('[Hublog Sms/tencent] 发送失败: ' . ($respBody['Error']['Message'] ?? 'unknown')); // @allow-error-log
            return false;
        }
        $set = $respBody['SendStatusSet'] ?? [];
        $first = $set[0] ?? [];
        if (($first['Code'] ?? '') !== 'Ok') {
            error_log('[Hublog Sms/tencent] 发送失败: ' . ($first['Message'] ?? 'unknown')); // @allow-error-log
            return false;
        }
        return true;
    }
}
