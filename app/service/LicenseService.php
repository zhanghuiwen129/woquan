<?php

namespace app\service;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Request;

class LicenseService
{
    private static $cacheKey = 'license_info';
    private static $cacheTime = 3600;

    private static $secretKey = env('license.secret_key', 'WOQUAN_LICENSE_SECRET_KEY_2026');

    public static function verify($licenseCode, $domain = null, $ip = null)
    {
        if (empty($licenseCode)) {
            return ['valid' => false, 'msg' => '授权码不能为空'];
        }

        $cacheKey = self::$cacheKey . '_' . md5($licenseCode);
        $cached = Cache::get($cacheKey);
        if ($cached && isset($cached['code']) && $cached['code'] === $licenseCode) {
            return $cached;
        }

        $result = self::localVerify($licenseCode, $domain, $ip);

        if (env('license.online_verify', false)) {
            $onlineResult = self::onlineVerify($licenseCode, $domain, $ip);
            if (!$onlineResult['valid']) {
                return $onlineResult;
            }
        }

        Cache::set($cacheKey, array_merge($result, ['code' => $licenseCode]), self::$cacheTime);

        return $result;
    }

    private static function localVerify($licenseCode, $domain = null, $ip = null)
    {
        $auth = Db::name('authorizations')
            ->where('license_number', 'like', Db::escape(substr($licenseCode, 0, 16)) . '%')
            ->find();

        if (!$auth) {
            return ['valid' => false, 'msg' => '授权码不存在'];
        }

        if ($auth['status'] != 1) {
            return ['valid' => false, 'msg' => '授权已被禁用'];
        }

        if ($auth['end_time'] > 0 && $auth['end_time'] < time()) {
            return ['valid' => false, 'msg' => '授权已过期'];
        }

        if (!empty($auth['signature'])) {
            $parts = explode('-', $licenseCode);
            if (count($parts) >= 2) {
                $baseCode = $parts[0];
                $signature = $parts[1];
                $expectedSignature = self::generateSignature($baseCode, $auth);
                if ($signature !== substr($expectedSignature, 0, 8)) {
                    return ['valid' => false, 'msg' => '授权签名无效'];
                }
            }
        }

        if (!empty($auth['domain']) && $domain) {
            $allowedDomains = explode(',', $auth['domain']);
            $currentDomain = self::normalizeDomain($domain);
            $domainMatched = false;
            foreach ($allowedDomains as $allowedDomain) {
                if (self::normalizeDomain($allowedDomain) === $currentDomain) {
                    $domainMatched = true;
                    break;
                }
            }
            if (!$domainMatched) {
                return ['valid' => false, 'msg' => '域名不匹配，授权域名：' . $auth['domain']];
            }
        }

        if (!empty($auth['server_ip']) && $ip) {
            $allowedIps = explode(',', $auth['server_ip']);
            if (!in_array($ip, $allowedIps)) {
                return ['valid' => false, 'msg' => 'IP地址不匹配'];
            }
        }

        Db::name('authorizations')
            ->where('id', $auth['id'])
            ->update([
                'verify_count' => Db::raw('verify_count + 1'),
                'last_verify_time' => time()
            ]);

        $auth['features'] = !empty($auth['features']) ? json_decode($auth['features'], true) : [];

        return ['valid' => true, 'data' => $auth];
    }

    private static function onlineVerify($licenseCode, $domain = null, $ip = null)
    {
        $apiUrl = env('license.verify_url', '');
        if (empty($apiUrl)) {
            return ['valid' => true, 'msg' => '跳过在线验证'];
        }

        try {
            $response = \think\facade\Http::post($apiUrl, [
                'license_code' => $licenseCode,
                'domain' => $domain,
                'ip' => $ip,
                'timestamp' => time()
            ], [
                'headers' => ['X-Secret-Key: ' . self::$secretKey]
            ]);

            $result = json_decode($response->getBody(), true);

            if (isset($result['code']) && $result['code'] === 200) {
                return ['valid' => true, 'data' => $result['data'] ?? []];
            } else {
                return ['valid' => false, 'msg' => $result['msg'] ?? '在线验证失败'];
            }
        } catch (\Exception $e) {
            return ['valid' => true, 'msg' => '在线验证失败，使用本地验证'];
        }
    }

    public static function generateLicenseNumber($data = [])
    {
        $baseCode = strtoupper(bin2hex(random_bytes(8)));

        $signature = self::generateSignature($baseCode, $data);

        $licenseCode = $baseCode . '-' . substr($signature, 0, 8);

        return $licenseCode;
    }

    private static function generateSignature($code, $data)
    {
        $signData = $code . json_encode($data) . time();
        return md5($signData . self::$secretKey);
    }

    private static function normalizeDomain($domain)
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^www\./', '', $domain);
        return $domain;
    }

    public static function checkFeature($feature)
    {
        $licenseCode = env('license.key', '');
        if (empty($licenseCode)) {
            return false;
        }

        $result = self::verify($licenseCode);
        if (!$result['valid']) {
            return false;
        }

        $auth = $result['data'];
        if (isset($auth['features']) && is_array($auth['features'])) {
            return in_array($feature, $auth['features']);
        }

        return true;
    }

    public static function getLicenseInfo()
    {
        $licenseCode = env('license.key', '');
        if (empty($licenseCode)) {
            return null;
        }

        $result = self::verify($licenseCode);
        return $result['valid'] ? $result['data'] : null;
    }
}
