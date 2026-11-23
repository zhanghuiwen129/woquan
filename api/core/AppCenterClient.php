<?php
/**
 * 应用中心 · 站点端签名请求器
 *
 * 以开源站点（woquan）身份连接唯一的应用中心服务端。除 register / ping 外，
 * 所有 /api/client/* 接口都带四个签名头。签名算法与应用中心
 * AppCenter\Services\SiteService::sign() 字节级一致，任何偏差都会导致 401。
 *
 * 签名串 = METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256(canonical(params))
 * SIGNATURE = base64url( HMAC_SHA256(site_secret, 签名串) )
 *
 * 本类只负责「怎么请求」，配置读取/持久化在 AppCenterStore，装包在 AppCenterInstaller。
 */

declare(strict_types=1);

namespace Hublog\Core;

class AppCenterClient
{
    /** 签名允许的时间偏差（秒），需与中心 SIGN_TTL 一致。 */
    public const SIGN_TTL = 300;

    private string $apiBase;
    private string $siteKey;
    private string $siteSecret;
    private int $timeout;

    public function __construct(string $apiBase, string $siteKey = '', string $siteSecret = '', int $timeout = 30)
    {
        $this->apiBase = rtrim($apiBase, '/');
        $this->siteKey = $siteKey;
        $this->siteSecret = $siteSecret;
        $this->timeout = $timeout > 0 ? $timeout : 30;
    }

    /**
     * 生成 HMAC 签名（与应用中心 SiteService::sign 完全一致，客户端/探针可独立调用验证）。
     */
    public static function sign(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        array $params = []
    ): string {
        $canonical = self::canonicalParams($params);
        $stringToSign = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n"
            . hash('sha256', $canonical);
        $raw = hash_hmac('sha256', $stringToSign, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** 参数规范化：值字符串化 + ksort，稳定 JSON。 */
    public static function canonicalParams(array $params): string
    {
        $norm = [];
        foreach ($params as $key => $value) {
            $norm[(string)$key] = is_scalar($value) ? (string)$value : (string)json_encode($value);
        }
        ksort($norm, SORT_STRING);
        return json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    // ==================== 业务封装 ====================

    /** 注册新站点（公开接口，无需签名）。返回 key/secret，仅此一次。 */
    public function register(string $name, string $url = ''): array
    {
        return $this->call('POST', '/api/client/register', [
            'name' => $name,
            'url' => $url,
        ], false);
    }

    /** 连通性与身份探针。 */
    public function ping(): array
    {
        return $this->call('GET', '/api/client/ping');
    }

    /** 已上架应用列表。 */
    public function apps(): array
    {
        $data = $this->call('GET', '/api/client/apps');
        return is_array($data['apps'] ?? null) ? $data['apps'] : (is_array($data['list'] ?? null) ? $data['list'] : []);
    }

    /** 应用详情。 */
    public function appDetail(int $appId): array
    {
        return $this->call('GET', '/api/client/apps/' . $appId);
    }

    /** 激活购买码。 */
    public function activate(string $licenseKey): array
    {
        return $this->call('POST', '/api/client/license/activate', ['license_key' => $licenseKey]);
    }

    /** 本站已激活授权列表。 */
    public function licenses(): array
    {
        $data = $this->call('GET', '/api/client/licenses');
        return is_array($data['licenses'] ?? null) ? $data['licenses'] : (is_array($data['list'] ?? null) ? $data['list'] : []);
    }

    /**
     * 检查更新。$installed = [['app_id'=>int,'version'=>'x'], ...]。
     */
    public function checkUpdates(array $installed): array
    {
        $data = $this->call('POST', '/api/client/check-updates', ['installed' => $installed]);
        return is_array($data['updates'] ?? null) ? $data['updates'] : [];
    }

    /** 上报已安装版本（version_id = 0 表示最新）。 */
    public function markInstalled(int $appId, int $versionId = 0): array
    {
        return $this->call('POST', '/api/client/apps/' . $appId . '/installed', ['version_id' => $versionId]);
    }

    /**
     * 下载应用包二进制。返回 ['body' => string, 'hash' => string]。
     * 成功为 zip 二进制；失败抛 AppCenterClientException（402=未授权）。
     */
    public function download(int $appId, string $version = ''): array
    {
        $params = $version !== '' ? ['version' => $version] : [];
        return $this->callRaw('/api/client/apps/' . $appId . '/download', $params);
    }

    // ==================== 传输层 ====================

    /**
     * JSON 接口调用，返回 data 数组；失败抛异常（code 为中心返回码）。
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, array $params = [], bool $signed = true): array
    {
        $result = $this->request($method, $path, $params, $signed, false);
        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            throw new AppCenterClientException('应用中心返回了非 JSON 数据', 0);
        }
        $code = (int)($decoded['code'] ?? 0);
        if ($code !== 200) {
            throw new AppCenterClientException(
                (string)($decoded['message'] ?? '应用中心请求失败'),
                $code
            );
        }
        $data = $decoded['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    /** 二进制下载调用，返回原始响应体与 sha256 响应头。 */
    private function callRaw(string $path, array $params): array
    {
        $result = $this->request('GET', $path, $params, true, true);
        $hash = strtolower(trim((string)($result['headers']['X-Package-Hash'] ?? '')));
        return ['body' => $result['body'], 'hash' => $hash];
    }

    /**
     * 发起一次 HTTP 请求。
     *
     * GET：参数进 query（含 s=逻辑路径）；POST：参数进 JSON body（保留类型，签名一致）。
     * 签名覆盖全部业务参数（不含 s），与中心 Request::signParams() 对齐。
     *
     * @param array<string,mixed> $params
     * @return array{body:string, headers:array<string,string>}
     */
    private function request(string $method, string $path, array $params, bool $signed, bool $binary): array
    {
        if (!function_exists('curl_init')) {
            throw new AppCenterClientException('服务端缺少 curl 扩展，无法连接应用中心', 0);
        }
        if ($this->apiBase === '') {
            throw new AppCenterClientException('应用中心 API 地址未配置', 0);
        }

        $method = strtoupper($method);
        $headers = ['Accept: application/json'];
        $url = $this->apiBase . '?s=' . rawurlencode($path);
        $body = '';

        if ($method === 'GET') {
            if ($params !== []) {
                $url .= '&' . http_build_query($params);
            }
        } else {
            $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $headers[] = 'Content-Type: application/json';
        }

        if ($signed) {
            if ($this->siteKey === '' || $this->siteSecret === '') {
                throw new AppCenterClientException('站点尚未注册（缺少 site_key / site_secret）', 401);
            }
            $ts = (string)time();
            $nonce = bin2hex(random_bytes(12));
            $signature = self::sign($this->siteSecret, $method, $path, $ts, $nonce, $params);
            $headers[] = 'X-Site-Key: ' . $this->siteKey;
            $headers[] = 'X-Site-Timestamp: ' . $ts;
            $headers[] = 'X-Site-Nonce: ' . $nonce;
            $headers[] = 'X-Site-Signature: ' . $signature;
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $binary ? max(60, $this->timeout) : $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $len = strlen($header);
                $pos = strpos($header, ':');
                if ($pos !== false) {
                    $name = trim(substr($header, 0, $pos));
                    $responseHeaders[$name] = trim(substr($header, $pos + 1));
                }
                return $len;
            },
        ]);
        if ($method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new AppCenterClientException('连接应用中心失败：' . $err, $errno);
        }
        curl_close($ch);

        // 付费未授权等错误在二进制下载里也是 JSON 包络：识别后转成异常。
        if ($binary && (str_starts_with(ltrim($raw), '{'))) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['code']) && (int)$decoded['code'] !== 200) {
                throw new AppCenterClientException(
                    (string)($decoded['message'] ?? '下载失败'),
                    (int)$decoded['code']
                );
            }
        }

        return ['body' => (string)$raw, 'headers' => $responseHeaders];
    }
}

/**
 * 应用中心请求异常。getCode() 携带中心返回的业务码：
 * 401 签名失败 / 402 未授权 / 403 站点禁用 / 404 不存在。
 */
class AppCenterClientException extends \RuntimeException
{
}
