<?php
/**
 * 百度智能云·身份证二要素核验（BCE 签名）
 *
 * 走百度云标准 BCE_AUTH（bce-auth-v1）签名。默认指向「人脸识别 / 身份证二要素核验」类接口，
 * host、path 均可在后台覆盖以适配不同产品。
 *
 * 密钥：settings.realname_baidu_ak / realname_baidu_sk
 * 其余：realname_baidu_endpoint（host）、realname_baidu_path（请求路径）
 *
 * 响应约定（百度云常见形态）：
 *   { "error_code": 0, "error_msg": "SUCCESS", "result": { ... 含一致/不一致标识 ... } }
 *   error_code = 0 且结果含「一致」视为通过；明确「不一致」视为未通过；其余 pending 转人工。
 */

namespace Hublog\Core\RealName;

class BaiduCloudProvider implements RealNameProviderInterface
{
    public static function key(): string
    {
        return 'baidu_cloud';
    }

    public static function label(): string
    {
        return '百度智能云（BCE 签名）';
    }

    public static function configKeys(): array
    {
        return [
            'realname_baidu_ak',
            'realname_baidu_sk',
            'realname_baidu_endpoint',
            'realname_baidu_path',
        ];
    }

    public static function verify(string $realName, string $idCard, array $cfg): array
    {
        $ak = trim((string)($cfg['baidu_ak'] ?? ''));
        $sk = trim((string)($cfg['baidu_sk'] ?? ''));
        if ($ak === '' || $sk === '') {
            return ['ok' => false, 'pending' => false, 'code' => 'not_configured', 'message' => '未配置百度云 AK/SK', 'raw' => ''];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'pending' => true, 'code' => 'no_curl', 'message' => '服务器未启用 cURL 扩展，已转人工复核', 'raw' => ''];
        }

        $host = trim((string)($cfg['baidu_endpoint'] ?? '')) ?: 'face.baidubce.com';
        $path = trim((string)($cfg['baidu_path'] ?? '')) ?: '/rest/2.0/face/v4/idcard';
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $body    = json_encode(['id_card_number' => $idCard, 'name' => $realName], JSON_UNESCAPED_UNICODE);
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
        $resp     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            error_log(sprintf('[Hublog RealName/baidu] cURL 错误: %s (HTTP %d)', $err, $httpCode)); // @allow-error-log
            return ['ok' => false, 'pending' => true, 'code' => 'network_error', 'message' => '核验服务暂时不可用，已转人工复核', 'raw' => ''];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'pending' => true, 'code' => 'http_' . $httpCode, 'message' => '核验服务返回异常（HTTP ' . $httpCode . '），已转人工复核', 'raw' => substr((string)$resp, 0, 500)];
        }

        return self::parse((string)$resp);
    }

    /**
     * 百度云 BCE_AUTH v1 签名
     */
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

        $canonicalRequest = strtoupper($method) . "\n"
            . $path . "\n"
            . '' . "\n"
            . $canonicalHeaders;

        $signature   = hash_hmac('sha256', $canonicalRequest, $signingKey);
        return $authPrefix . '/' . $signedHeaders . '/' . $signature;
    }

    private static function parse(string $body): array
    {
        $json = json_decode($body, true);
        $raw  = substr($body, 0, 500);
        if (!is_array($json)) {
            return ['ok' => false, 'pending' => true, 'code' => 'bad_json', 'message' => '核验返回无法解析，已转人工复核', 'raw' => $raw];
        }
        if (isset($json['error_code']) && (int)$json['error_code'] !== 0) {
            // 百度云业务错误（参数/配额/未开通等）不算「核验不通过」，转人工更安全
            return ['ok' => false, 'pending' => true, 'code' => (string)$json['error_code'], 'message' => (string)($json['error_msg'] ?? '百度云返回错误'), 'raw' => $raw];
        }
        $text = json_encode($json, JSON_UNESCAPED_UNICODE);
        $msg  = '';
        if (isset($json['result']) && is_array($json['result'])) {
            $msg = json_encode($json['result'], JSON_UNESCAPED_UNICODE);
        }
        if (str_contains($text, '不一致') || str_contains($msg, '不一致')) {
            return ['ok' => false, 'pending' => false, 'code' => 'mismatch', 'message' => '姓名与身份证号不一致', 'raw' => $raw];
        }
        if (str_contains($text, '一致') || (int)($json['error_code'] ?? -1) === 0) {
            return ['ok' => true, 'pending' => false, 'code' => '0', 'message' => '实名核验通过', 'raw' => $raw];
        }
        return ['ok' => false, 'pending' => true, 'code' => 'unknown', 'message' => '核验结果未知，已转人工复核', 'raw' => $raw];
    }
}
