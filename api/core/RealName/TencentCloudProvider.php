<?php
/**
 * 腾讯云·身份证二要素核验（TC3-HMAC-SHA256 签名）
 *
 * 走腾讯云标准 API 3.0 签名（TC3-HMAC-SHA256），适用于「实名核身 / 身份核验」类产品的
 * 二要素接口（姓名 + 身份证号）。具体产品与 Action 由后台配置（默认按「人脸核身 faceid」
 * 的身份证二要素核验接口填，可改成其它产品的 Action/Version）。
 *
 * 密钥：settings.realname_tencent_secret_id / realname_tencent_secret_key
 * 其余：realname_tencent_endpoint（host）、realname_tencent_action、realname_tencent_version、realname_tencent_region
 *
 * 响应约定（腾讯云常见形态）：
 *   { "Response": { "Result": "0", "Description": "一致", ... } }
 *   Result = "0" 表示一致；"1" 表示不一致；缺少或解析失败 → pending 转人工。
 */

namespace Hublog\Core\RealName;

use Hublog\Core\Security;

class TencentCloudProvider implements RealNameProviderInterface
{
    public static function key(): string
    {
        return 'tencent_cloud';
    }

    public static function label(): string
    {
        return '腾讯云（API 3.0 签名）';
    }

    public static function configKeys(): array
    {
        return [
            'realname_tencent_secret_id',
            'realname_tencent_secret_key',
            'realname_tencent_endpoint',
            'realname_tencent_action',
            'realname_tencent_version',
            'realname_tencent_region',
        ];
    }

    public static function verify(string $realName, string $idCard, array $cfg): array
    {
        $secretId  = trim((string)($cfg['tencent_secret_id'] ?? ''));
        $secretKey = trim((string)($cfg['tencent_secret_key'] ?? ''));
        if ($secretId === '' || $secretKey === '') {
            return ['ok' => false, 'pending' => false, 'code' => 'not_configured', 'message' => '未配置腾讯云 SecretId/SecretKey', 'raw' => ''];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'pending' => true, 'code' => 'no_curl', 'message' => '服务器未启用 cURL 扩展，已转人工复核', 'raw' => ''];
        }

        $host    = trim((string)($cfg['tencent_endpoint'] ?? '')) ?: 'faceid.tencentcloudapi.com';
        $action  = trim((string)($cfg['tencent_action'] ?? '')) ?: 'CheckIdCardInfo';
        $version = trim((string)($cfg['tencent_version'] ?? '')) ?: '2018-03-01';
        $region  = trim((string)($cfg['tencent_region'] ?? '')) ?: 'ap-guangzhou';

        $service = self::serviceFromHost($host); // 取 host 第一段作为签名 service 名
        $payload = json_encode([
            'IdCard' => $idCard,
            'Name'   => $realName,
        ], JSON_UNESCAPED_UNICODE);

        $timestamp = time();
        $date      = gmdate('Y-m-d', $timestamp);

        // 1) canonical request
        $canonicalHeaders = "content-type:application/json; charset=utf-8\n"
            . "host:{$host}\n"
            . "x-tc-action:" . strtolower($action) . "\n";
        $signedHeaders    = 'content-type;host;x-tc-action';
        $hashedPayload    = hash('sha256', $payload);
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$hashedPayload}";

        // 2) string to sign
        $credentialScope = "{$date}/{$service}/tc3_request";
        $stringToSign    = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        // 3) signature
        $secretDate    = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature     = hash_hmac('sha256', $stringToSign, $secretSigning);

        $authorization = "TC3-HMAC-SHA256 "
            . "Credential={$secretId}/{$credentialScope}, "
            . "SignedHeaders={$signedHeaders}, "
            . "Signature={$signature}";

        $url = "https://{$host}/";
        $ch  = curl_init($url);
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
        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            error_log(sprintf('[Hublog RealName/tencent] cURL 错误: %s (HTTP %d)', $err, $httpCode)); // @allow-error-log
            return ['ok' => false, 'pending' => true, 'code' => 'network_error', 'message' => '核验服务暂时不可用，已转人工复核', 'raw' => ''];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'pending' => true, 'code' => 'http_' . $httpCode, 'message' => '核验服务返回异常（HTTP ' . $httpCode . '），已转人工复核', 'raw' => substr((string)$body, 0, 500)];
        }

        return self::parse((string)$body);
    }

    private static function parse(string $body): array
    {
        $json = json_decode($body, true);
        $raw  = substr($body, 0, 500);
        if (!is_array($json)) {
            return ['ok' => false, 'pending' => true, 'code' => 'bad_json', 'message' => '核验返回无法解析，已转人工复核', 'raw' => $raw];
        }
        // 腾讯云错误响应：{ "Response": { "Error": { "Code":..., "Message":... } } }
        $resp = $json['Response'] ?? $json;
        if (isset($resp['Error'])) {
            $msg = (string)($resp['Error']['Message'] ?? '腾讯云返回错误');
            // 部分「账号未实名/参数」类错误不算「核验不通过」，转人工更安全
            return ['ok' => false, 'pending' => true, 'code' => (string)($resp['Error']['Code'] ?? 'error'), 'message' => $msg, 'raw' => $raw];
        }
        $result = (string)($resp['Result'] ?? $resp['result'] ?? '');
        $desc   = (string)($resp['Description'] ?? $resp['description'] ?? '');
        if ($result === '0') {
            return ['ok' => true, 'pending' => false, 'code' => '0', 'message' => $desc ?: '实名核验通过', 'raw' => $raw];
        }
        if ($result === '1' || str_contains($desc, '不一致')) {
            return ['ok' => false, 'pending' => false, 'code' => $result ?: '1', 'message' => $desc ?: '姓名与身份证号不一致', 'raw' => $raw];
        }
        return ['ok' => false, 'pending' => true, 'code' => $result ?: 'unknown', 'message' => $desc ?: '核验结果未知，已转人工复核', 'raw' => $raw];
    }

    /** host 第一段即签名 service，如 faceid.tencentcloudapi.com → faceid */
    private static function serviceFromHost(string $host): string
    {
        $first = strtok($host, '.');
        return $first === false ? 'faceid' : $first;
    }
}
