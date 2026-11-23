<?php
/**
 * 阿里云市场·身份证二要素核验（Authorization: APPCODE）
 *
 * 这是原 V51 的默认实现，现抽成 Provider 形式，行为保持不变：
 *   - 密钥：settings.realname_appcode
 *   - 接口：settings.realname_api_url（默认阿里云市场身份证核验）
 *   - 响应形态：{status:"01"} 01=一致 02/03/04/05=不一致，其余转人工
 */

namespace Hublog\Core\RealName;

use Hublog\Core\Database;
use Hublog\Core\Security;

class AliyunMarketProvider implements RealNameProviderInterface
{
    public static function key(): string
    {
        return 'aliyun_market';
    }

    public static function label(): string
    {
        return '阿里云（云市场 APPCODE）';
    }

    public static function configKeys(): array
    {
        return ['realname_appcode', 'realname_api_url'];
    }

    public static function verify(string $realName, string $idCard, array $cfg): array
    {
        $appcode = trim((string)($cfg['appcode'] ?? ''));
        $apiUrl  = trim((string)($cfg['api_url'] ?? '')) ?: 'https://idcardcheck.market.alicloudapi.com/idcardCheck';

        if ($appcode === '') {
            return ['ok' => false, 'pending' => false, 'code' => 'not_configured', 'message' => '未配置阿里云 APPCODE', 'raw' => ''];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'pending' => true, 'code' => 'no_curl', 'message' => '服务器未启用 cURL 扩展，已转人工复核', 'raw' => ''];
        }

        $ssrf = Security::validateOutboundUrl($apiUrl, ['allowHttp' => true]);
        if (!$ssrf['ok']) {
            return ['ok' => false, 'pending' => true, 'code' => 'ssrf_blocked', 'message' => '核验接口地址被安全策略拦截', 'raw' => ''];
        }

        $url = $apiUrl . (str_contains($apiUrl, '?') ? '&' : '?')
            . http_build_query(['idCard' => $idCard, 'name' => $realName], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        Security::applyCurlSsrfOptions($ch, false);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: APPCODE ' . $appcode,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            error_log(sprintf('[Hublog RealName/aliyun] cURL 错误: %s (HTTP %d)', $err, $httpCode)); // @allow-error-log
            return ['ok' => false, 'pending' => true, 'code' => 'network_error', 'message' => '核验服务暂时不可用，已转人工复核', 'raw' => ''];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'ok' => false, 'pending' => true, 'code' => 'http_' . $httpCode,
                'message' => '核验服务返回异常（HTTP ' . $httpCode . '），已转人工复核',
                'raw' => substr((string)$body, 0, 500),
            ];
        }

        return self::parse((string)$body);
    }

    /**
     * 解析响应：只认「明确通过 / 明确不一致」两种，其余 pending 交人工。
     */
    private static function parse(string $body): array
    {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'pending' => true, 'code' => 'bad_json', 'message' => '核验返回无法解析，已转人工复核', 'raw' => substr($body, 0, 500)];
        }
        $raw = substr($body, 0, 500);

        if (isset($json['status'])) {
            $status = (string)$json['status'];
            $msg    = (string)($json['msg'] ?? $json['message'] ?? '');
            if ($status === '01') {
                return ['ok' => true, 'pending' => false, 'code' => $status, 'message' => $msg ?: '实名核验通过', 'raw' => $raw];
            }
            if (in_array($status, ['02', '03', '04', '05'], true)) {
                return ['ok' => false, 'pending' => false, 'code' => $status, 'message' => $msg ?: '姓名与身份证号不一致', 'raw' => $raw];
            }
            return ['ok' => false, 'pending' => true, 'code' => $status, 'message' => $msg ?: '核验结果未知，已转人工复核', 'raw' => $raw];
        }

        $code = (string)($json['code'] ?? '');
        $msg  = (string)($json['msg'] ?? $json['message'] ?? '');
        $data = $json['data'] ?? [];
        $text = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : (string)$data;

        if ($code === '200' || $code === '0') {
            if (str_contains($text, '不一致') || str_contains($msg, '不一致')) {
                return ['ok' => false, 'pending' => false, 'code' => $code, 'message' => $msg ?: '姓名与身份证号不一致', 'raw' => $raw];
            }
            if (str_contains($text, '一致') || str_contains($msg, '成功') || str_contains($text, 'isok')) {
                return ['ok' => true, 'pending' => false, 'code' => $code, 'message' => $msg ?: '实名核验通过', 'raw' => $raw];
            }
        }

        return ['ok' => false, 'pending' => true, 'code' => $code ?: 'unknown', 'message' => $msg ?: '核验结果未知，已转人工复核', 'raw' => $raw];
    }
}
