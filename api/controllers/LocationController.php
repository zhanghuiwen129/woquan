<?php
/**
 * Hublog 定位服务控制器
 * 基于 IP 地址进行自动定位，支持逆地理编码
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Cache;
use Hublog\Core\SettingsRepository;

class LocationController extends BaseController
{
    /**
     * 根据 IP 自动定位
     * GET /api/location/ip
     * 使用免费 ip-api.com 服务（无需密钥，限每分钟 45 次）
     */
    public function ipLocate(Request $request): Response
    {
        $clientIp = $this->getClientIp();

        // 本地/内网地址无法定位，返回空
        if ($this->isPrivateIp($clientIp)) {
            return Response::success([
                'ip' => $clientIp,
                'location' => null,
                'lat' => null,
                'lng' => null,
                'source' => 'local'
            ], '本地网络，无法定位');
        }

        // 缓存 10 分钟，避免重复请求外部接口
        $cacheKey = 'ip_location_' . md5($clientIp);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return Response::success($cached);
        }

        // 调用 ip-api.com（HTTP，免费版无 HTTPS；如需 HTTPS 需付费）
        // 中国大陆境内可正常访问
        $url = 'http://ip-api.com/json/' . urlencode($clientIp) . '?lang=zh-CN&fields=status,country,regionName,city,lat,lon,query';
        $response = $this->httpGet($url);

        if ($response === false) {
            return Response::error('定位服务暂时不可用', 503);
        }

        $data = json_decode($response, true);
        if (!$data || ($data['status'] ?? '') !== 'success') {
            return Response::error('定位失败：' . ($data['message'] ?? '未知错误'), 500);
        }

        $region = trim($data['regionName'] ?? '');
        $city = trim($data['city'] ?? '');
        $country = trim($data['country'] ?? '');

        // 拼接位置名称：中国 · 北京市 · 北京市
        $parts = array_filter([$country, $region, $city]);
        $locationName = implode(' · ', $parts);

        $result = [
            'ip' => $data['query'] ?? $clientIp,
            'location' => $locationName ?: null,
            'lat' => isset($data['lat']) ? (float)$data['lat'] : null,
            'lng' => isset($data['lon']) ? (float)$data['lon'] : null,
            'source' => 'ip'
        ];

        // 缓存 10 分钟
        Cache::set($cacheKey, $result, 600);

        return Response::success($result);
    }

    /**
     * 逆地理编码
     * GET /api/location/reverse?lat=&lng=
     * 将经纬度转换为可读地址
     *
     * 说明：坐标 → 地名必须依赖逆地理服务。原先调用的 ip-api.com/reverse 端点
     * 根本不存在（实测返回 {"status":"fail","query":"reverse"}），导致这里永远
     * 走降级分支、只能返回坐标串。现在改为：
     *   1) 配了高德 Web 服务 Key → 用高德逆地理（国内可用，含 POI 级名称）
     *   2) 没配 Key 或服务不可用 → 明确返回 source=coordinate，前端据此提示用户手动改名称
     */
    public function reverseGeocode(Request $request): Response
    {
        $lat = $request->getFloat('lat');
        $lng = $request->getFloat('lng');

        if (!$lat || !$lng) {
            return Response::error('经纬度参数错误', 400);
        }

        // 坐标精度保留 5 位（约 1 米）即可，避免同一地点因浮点差异反复请求
        $cacheKey = 'reverse_geocode_' . md5(sprintf('%.5f,%.5f', $lat, $lng));
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return Response::success($cached);
        }

        $key = $this->amapKey();
        if ($key !== '') {
            $result = $this->amapReverse($lat, $lng, $key);
            if ($result !== null) {
                Cache::set($cacheKey, $result, 86400);
                return Response::success($result);
            }
        }

        // 降级：只给坐标，source=coordinate 让前端知道「名称不可信，需用户确认」
        return Response::success($this->coordinateFallback($lat, $lng));
    }

    /** 高德 Web 服务 Key：优先后台设置 amap_web_key，其次 config.php 常量 AMAP_WEB_KEY */
    private function amapKey(): string
    {
        $key = '';
        try {
            $s = $this->settings();
            $key = trim((string)($s['amap_web_key'] ?? ''));
        } catch (\Throwable $e) {
            $key = '';
        }
        if ($key === '' && defined('AMAP_WEB_KEY')) {
            $key = trim((string)constant('AMAP_WEB_KEY'));
        }
        return $key;
    }

    /** 高德逆地理编码：https://restapi.amap.com/v3/geocode/regeo */
    private function amapReverse(float $lat, float $lng, string $key): ?array
    {
        // 注意：高德要求 location 参数是「经度,纬度」顺序
        $url = 'https://restapi.amap.com/v3/geocode/regeo?output=JSON&extensions=base'
            . '&key=' . urlencode($key)
            . '&location=' . rawurlencode(sprintf('%.6f,%.6f', $lng, $lat));

        $raw = $this->httpGet($url, 5);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['status'] ?? '') !== '1') {
            return null;
        }

        $rg = $data['regeocode'] ?? [];
        $detail = trim((string)($rg['formatted_address'] ?? ''));
        $comp = $rg['addressComponent'] ?? [];

        // 直辖市时 city 字段会是空数组，必须过滤非字符串
        $short = implode(' · ', array_filter([
            $this->compText($comp['province'] ?? ''),
            $this->compText($comp['city'] ?? ''),
            $this->compText($comp['district'] ?? ''),
        ]));

        if ($detail === '' && $short === '') {
            return null;
        }

        return [
            'location'        => $short !== '' ? $short : $detail,
            'location_detail' => $detail,
            'lat'             => $lat,
            'lng'             => $lng,
            'source'          => 'amap'
        ];
    }

    /** addressComponent 字段可能是数组（直辖市 city=[]），统一转字符串 */
    private function compText($value): string
    {
        if (is_array($value)) {
            return '';
        }
        return trim((string)$value);
    }

    /** 无逆地理服务时的兜底：至少把精确坐标存下来 */
    private function coordinateFallback(float $lat, float $lng): array
    {
        return [
            'location' => sprintf('%.5f, %.5f', $lat, $lng),
            'lat'      => $lat,
            'lng'      => $lng,
            'source'   => 'coordinate'
        ];
    }

    /** 站点设置（settings 表 key/value），缓存 1 小时 */
    private function settings(): array
    {
        return Cache::remember('site_settings', function () {
            $rows = SettingsRepository::fetchKeyValueRows();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);
    }

    /**
     * 获取客户端真实 IP
     *
     * P2-12 加固：原实现按优先级信任 HTTP_X_FORWARDED_FOR / HTTP_CLIENT_IP 等可伪造代理头，
     * 攻击者可伪造这些头谎报位置（若本方法被用于安全/风控决策则被绕过）。
     * 改为与 core/Request::getIp() 一致的「受信任代理」策略：
     *   - 默认仅取 TCP 连接层 REMOTE_ADDR；
     *   - 仅当 REMOTE_ADDR 命中 TRUSTED_PROXIES 可信代理列表时，才解析 X-Forwarded-For 的第一个值；
     *   - 解析结果做 FILTER_VALIDATE_IP 校验，不可信直接 fallback 到 REMOTE_ADDR。
     */
    private function getClientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $trusted = defined('TRUSTED_PROXIES')
            ? array_map('trim', explode(',', (string)TRUSTED_PROXIES))
            : [];

        if (in_array($remote, $trusted, true)) {
            $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($xff) && $xff !== '') {
                $candidate = trim(explode(',', $xff)[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        return $remote;
    }

    /**
     * 判断是否为内网/本地 IP
     */
    private function isPrivateIp(string $ip): bool
    {
        if ($ip === '0.0.0.0') return true;
        // IPv4 本地/内网
        $filter = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($filter === false) {
            return true;
        }
        return false;
    }

    /**
     * 简单 HTTP GET 请求
     */
    private function httpGet(string $url, int $timeout = 5): string|false
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_USERAGENT => 'Hublog/1.0 LocationService',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 300) {
                return $response;
            }
            return false;
        }

        // 降级到 file_get_contents
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'user_agent' => 'Hublog/1.0 LocationService',
                'ignore_errors' => false,
            ]
        ]);
        $response = @file_get_contents($url, false, $ctx);
        return $response !== false ? $response : false;
    }
}
