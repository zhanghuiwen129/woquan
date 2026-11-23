<?php
/**
 * Copyright 2026 woquan. All rights reserved.
 * 本文件为本项目新增代码，商业使用须取得书面授权。
 *
 * 授权统计心跳编排：门控 → 间隔 / pending 判定 → 缓存锁双重检查 → 组包签名 → 2s curl → 静默失败。
 */

declare(strict_types=1);

namespace Hublog\Core;

/**
 * 心跳上报器。
 *
 * 性能约定：前台请求的热路径只做一次 Cache::get（低频门控），
 * 真正的 HTTP 上报由 api/index.php 在响应发出后的 shutdown 回调中执行，
 * 因此故障注入下页面 P95 延迟增量远小于 100ms（PRD G3）。
 */
final class HeartbeatReporter
{
    /** 前台「是否到期」检查的低频门控键 */
    private const GATE_KEY = 'license_heartbeat_check_gate';

    /** 上报去重锁键（Cache::withLock 内部会加 md5 前缀） */
    private const LOCK_KEY = 'license_heartbeat_report';

    /** 前台门控 TTL（秒） */
    private const GATE_TTL = 300;

    /** 失败后的重试门控（秒） */
    private const RETRY_DELAY = 60;

    /** 默认上报间隔（秒）：6 小时 */
    private const DEFAULT_INTERVAL = 21600;

    /** 网络超时（秒） */
    private const CURL_TIMEOUT = 2;

    /** 连接超时（秒） */
    private const CURL_CONNECT_TIMEOUT = 1;

    /**
     * 请求钩子入口：判断是否到期，到期则在锁内补发一次。
     *
     * 最外层 try/catch 全吞：任何异常都不得影响页面渲染与授权功能。
     */
    public static function maybeReport(): void
    {
        try {
            // 先做最便宜的门控，再读 settings，避免热路径反复查库
            if (!self::shouldCheckByGate()) {
                return;
            }
            if (!self::enabled()) {
                return;
            }

            $now = time();
            $interval = self::intervalSeconds();
            if (!self::isDue($now, $interval)) {
                return;
            }

            Cache::withLock(self::LOCK_KEY, static function () use ($now, $interval): bool {
                // 锁内二次检查：并发请求中只有第一个真正发 HTTP
                if (!self::isDue(time(), $interval)) {
                    return false;
                }
                return self::reportNow();
            });
        } catch (\Throwable $e) {
            self::logFailure('maybeReport: ' . $e->getMessage());
        }
    }

    /**
     * 立即上报一次（安装完成 / cron 兜底调用）。
     *
     * 成功：写 last_at、清 retry_at、清 pending；
     * 失败：只写 retry_at(+60s) 与限频 debug 日志，不抛异常、不渲染任何提示。
     */
    public static function reportNow(): bool
    {
        try {
            if (!self::enabled()) {
                return false;
            }

            $payload = self::buildPayload();
            $ok = self::send($payload);
            if ($ok) {
                $now = time();
                self::writeSetting('license_heartbeat_last_at', (string)$now);
                self::writeSetting('license_heartbeat_retry_at', '0');
                InstallationIdentity::clearPending();
            } else {
                self::writeSetting('license_heartbeat_retry_at', (string)(time() + self::RETRY_DELAY));
                self::logFailure('上报请求被拒绝或网络失败');
            }
            return $ok;
        } catch (\Throwable $e) {
            try {
                self::writeSetting('license_heartbeat_retry_at', (string)(time() + self::RETRY_DELAY));
            } catch (\Throwable $ignored) {
                // DB 不可用时连 retry_at 也写不进去，静默放弃即可
            }
            self::logFailure('reportNow: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 组包：九个签名字段 + signature，signature 同时放 body 与 X-Site-* 头。
     *
     * @return array<string, mixed>
     */
    public static function buildPayload(): array
    {
        $stats = License::getQuickStats();
        $installationId = InstallationIdentity::getOrCreateId();
        $fingerprint = InstallationIdentity::getFingerprint();
        $licenseCode = (string)License::getSetting('license_key', '');

        $payload = [
            'installation_id' => $installationId,
            'fingerprint'     => $fingerprint,
            'license_code'    => $licenseCode,
            'site_host'       => InstallationIdentity::getSiteHost(),
            'version'         => defined('APP_VERSION') ? (string)APP_VERSION : 'v2.0',
            'users'           => (int)($stats['users'] ?? 0),
            'posts'           => (int)($stats['posts'] ?? 0),
            'timestamp'       => time(),
            // 16 字节随机串 → 32 位 hex（与服务端 nonce 去重窗口配合）
            'nonce'           => bin2hex(random_bytes(16)),
        ];
        $payload['signature'] = PayloadSigner::sign($payload, $licenseCode, $fingerprint);

        return $payload;
    }

    /**
     * 发送一次 POST，返回服务端是否接受（code=200）。
     *
     * @param array<string, mixed> $payload buildPayload() 的结果
     */
    private static function send(array $payload): bool
    {
        $url = self::endpoint();
        if ($url === '' || !function_exists('curl_init')) {
            self::logFailure('curl 不可用或端点为空');
            return false;
        }

        $sslVerify = (string)License::getSetting('license_remote_ssl_verify', '1') === '1';
        // 与 License::verifyRemote() 同一套 SSRF 防护，绝不放开内网 / 元数据地址
        $ssrf = Security::validateOutboundUrl($url, ['allowHttp' => !$sslVerify]);
        if (!is_array($ssrf) || empty($ssrf['ok'])) {
            self::logFailure('SSRF 校验失败: ' . (string)($ssrf['reason'] ?? 'unknown'));
            return false;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            self::logFailure('payload JSON 编码失败');
            return false;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            self::logFailure('curl_init 失败');
            return false;
        }
        Security::applyCurlSsrfOptions($ch, !$sslVerify);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                // 原授权链路保留头，兼容授权中心旧路径解析
                'X-Hublog-Fingerprint: ' . (string)($payload['fingerprint'] ?? ''),
                'X-Site-Report: heartbeat',
                'X-Site-Installation: ' . (string)($payload['installation_id'] ?? ''),
                'X-Site-Timestamp: ' . (string)($payload['timestamp'] ?? ''),
                'X-Site-Nonce: ' . (string)($payload['nonce'] ?? ''),
                'X-Site-Signature: ' . (string)($payload['signature'] ?? ''),
            ],
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_USERAGENT      => 'Hublog-Heartbeat/1.0',
            CURLOPT_FAILONERROR    => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = (string)curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            self::logFailure('HTTP 失败: code=' . $httpCode . ', err=' . $curlErr);
            return false;
        }
        $data = json_decode((string)$response, true);
        if (!is_array($data) || (int)($data['code'] ?? 0) !== 200) {
            // fire-and-forget：响应 RSA 签名不验，只读 code 记 debug 日志
            self::logFailure('服务端拒绝: code=' . (string)($data['code'] ?? 'invalid'));
            return false;
        }
        return true;
    }

    /**
     * 端点解析：settings.license_report_endpoint > license_remote_url > 内置默认端点。
     * 域名只在 License::DEFAULT_REPORT_ENDPOINT 一处定义（发布前替换）。
     */
    private static function endpoint(): string
    {
        $configured = trim((string)License::getSetting('license_report_endpoint', ''));
        if ($configured === '') {
            $configured = trim((string)License::getSetting('license_remote_url', ''));
        }
        if ($configured === '') {
            $configured = License::DEFAULT_REPORT_ENDPOINT;
        }

        $base = rtrim($configured, '/');
        if (substr($base, -strlen(PayloadSigner::PATH)) === PayloadSigner::PATH) {
            return $base;
        }
        return $base . PayloadSigner::PATH;
    }

    /** 上报开关：settings.license_report_enabled 为 '0' 时关闭 */
    private static function enabled(): bool
    {
        $flag = License::getSetting('license_report_enabled', '1');
        return $flag !== '0' && $flag !== 'off' && $flag !== false;
    }

    /**
     * 低频门控：后台 /api/admin/* 每次都检查；前台每 5 分钟最多检查一次。
     */
    private static function shouldCheckByGate(): bool
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($uri, '/api/admin/') !== false) {
            return true;
        }
        if (Cache::get(self::GATE_KEY) !== null) {
            return false;
        }
        Cache::set(self::GATE_KEY, 1, self::GATE_TTL);
        return true;
    }

    /** 间隔 / pending / 失败重试门控判定 */
    private static function isDue(int $now, int $interval): bool
    {
        $lastAt = (int)License::getSetting('license_heartbeat_last_at', '0');
        $retryAt = (int)License::getSetting('license_heartbeat_retry_at', '0');

        if (InstallationIdentity::isPending()) {
            return true;
        }
        if ($retryAt > 0 && $now >= $retryAt) {
            return true;
        }
        return $now - $lastAt >= $interval;
    }

    /** 配置间隔（秒），非法值回落到 6 小时 */
    private static function intervalSeconds(): int
    {
        $value = (int)License::getSetting('license_report_interval', (string)self::DEFAULT_INTERVAL);
        return $value > 0 ? $value : self::DEFAULT_INTERVAL;
    }

    /**
     * 静默日志：Logger::error + 限频（≥300s）写 settings.license_heartbeat_last_error。
     */
    private static function logFailure(string $message): void
    {
        try {
            Logger::error('[Heartbeat] ' . $message);
        } catch (\Throwable $ignored) {
            // 日志组件自身失败也不能打断上报流程
        }

        try {
            $lastErrorAt = (int)License::getSetting('license_heartbeat_last_error_at', '0');
            if (time() - $lastErrorAt >= 300) {
                self::writeSetting('license_heartbeat_last_error', $message);
                self::writeSetting('license_heartbeat_last_error_at', (string)time());
            }
        } catch (\Throwable $ignored) {
            // 写 settings 失败时仅保留文件日志
        }
    }

    /**
     * 写 settings KV 并失效 site_settings 缓存（铁律 #5）。
     */
    private static function writeSetting(string $key, string $value): void
    {
        try {
            $now = Helper::now();
            $exists = Database::fetchColumn(
                'SELECT COUNT(*) FROM settings WHERE `key` = ?',
                [$key]
            );
            if ($exists !== false && (int)$exists > 0) {
                Database::update(
                    'settings',
                    ['value' => $value, 'updated_at' => $now],
                    '`key` = ?',
                    [$key]
                );
            } else {
                Database::insert('settings', [
                    'key'        => $key,
                    'value'      => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } finally {
            Cache::delete('site_settings');
        }
    }
}
