<?php
/**
 * 阿里云短信 · SendSms（RPC 签名 HMAC-SHA1）
 *
 * 密钥：settings.sms_aliyun_access_key_id / sms_aliyun_access_key_secret
 * 其余：sms_aliyun_sign_name（签名）、sms_aliyun_template_code（模板 CODE）
 * 默认 host：dysmsapi.aliyuncs.com，版本 2017-05-25
 */

namespace Hublog\Core\Sms;

class AliyunSmsProvider implements ProviderInterface
{
    public static function key(): string
    {
        return 'aliyun';
    }

    public static function label(): string
    {
        return '阿里云短信';
    }

    public static function configKeys(): array
    {
        return [
            'sms_aliyun_access_key_id',
            'sms_aliyun_access_key_secret',
            'sms_aliyun_sign_name',
            'sms_aliyun_template_code',
        ];
    }

    public static function send(string $phone, string $code, string $scene, array $cfg): bool
    {
        $ak     = trim((string)($cfg['aliyun_access_key_id'] ?? ''));
        $sk     = trim((string)($cfg['aliyun_access_key_secret'] ?? ''));
        $sign   = trim((string)($cfg['aliyun_sign_name'] ?? ''));
        $tpl    = trim((string)($cfg['aliyun_template_code'] ?? ''));
        if ($ak === '' || $sk === '' || $sign === '' || $tpl === '') {
            error_log('[Hublog Sms/aliyun] 密钥/签名/模板未配置'); // @allow-error-log
            return false;
        }
        if (!function_exists('curl_init')) {
            error_log('[Hublog Sms/aliyun] 未启用 cURL'); // @allow-error-log
            return false;
        }

        $params = [
            'AccessKeyId'      => $ak,
            'Action'           => 'SendSms',
            'Format'           => 'JSON',
            'PhoneNumbers'     => $phone,
            'RegionId'         => 'cn-hangzhou',
            'SignName'         => $sign,
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => uniqid('', true),
            'SignatureVersion' => '1.0',
            'TemplateCode'     => $tpl,
            'TemplateParam'    => json_encode(['code' => $code], JSON_UNESCAPED_SLASHES),
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'Version'          => '2017-05-25',
        ];

        ksort($params);
        $canonical = '';
        foreach ($params as $k => $v) {
            $canonical .= '&' . self::encode($k) . '=' . self::encode($v);
        }
        $canonical = substr($canonical, 1);

        $stringToSign = "GET&" . self::encode('/') . '&' . self::encode($canonical);
        $signature    = base64_encode(hash_hmac('sha1', $stringToSign, $sk . '&', true));
        $params['Signature'] = $signature;

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $url   = 'https://dysmsapi.aliyuncs.com/?' . $query;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            error_log('[Hublog Sms/aliyun] cURL 错误: ' . $err); // @allow-error-log
            return false;
        }
        $json = json_decode((string)$resp, true);
        if (!is_array($json)) {
            error_log('[Hublog Sms/aliyun] 响应无法解析: ' . substr((string)$resp, 0, 300)); // @allow-error-log
            return false;
        }
        if (($json['Code'] ?? '') !== 'OK') {
            error_log('[Hublog Sms/aliyun] 发送失败: ' . ($json['Message'] ?? 'unknown')); // @allow-error-log
            return false;
        }
        return true;
    }

    /** 阿里云专属 percent-encode（RFC3986 + 额外处理 + * ~） */
    private static function encode(string $s): string
    {
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], rawurlencode($s));
    }
}
