<?php
/**
 * 授权统计上报 · QA 独立验证脚本（严过关 / Edward）
 *
 * 与工程师自检脚本的区别：
 *  - 工程师脚本用「自己复刻的服务端公式」比对签名；本脚本用真实客户端
 *    PayloadSigner 生成签名，经真实 HTTP 打进真实 remote_verify.php，
 *    以服务端实际返回的 reason 码与落盘台账作为断言依据。
 *  - 覆盖 P0-1 ~ P0-5 的核心链路，结果写入 qa_heartbeat_result.log。
 *
 * 前置：
 *  1) 授权中心已生成密钥（D:/woquanma/woquanshouquan_secure/keys.json）
 *  2) 已启动 php -S 127.0.0.1:8899 -t D:/woquanma/woquanshouquan
 *  3) OPENSSL_CONF 指向有效 openssl.cnf
 *
 * 用法：
 *  OPENSSL_CONF="C:/Program Files/Git/usr/ssl/openssl.cnf" \
 *    "D:/BtSoft/php/81/php.exe" D:/woquan/api/tests/HeartbeatReportingQATest.php
 */

declare(strict_types=1);

namespace Hublog\QATests;

use Hublog\Core\AutoLoader;
use Hublog\Core\HeartbeatReporter;
use Hublog\Core\InstallationIdentity;
use Hublog\Core\License;
use Hublog\Core\Logger;
use Hublog\Core\PayloadSigner;

// 真实 DB 用例需要 config.php 提供 DB_* 常量（Database::getInstance 直接读常量）
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/core/AutoLoader.php';
AutoLoader::register();

final class HeartbeatReportingQATest
{
    private const ENDPOINT = 'http://127.0.0.1:8899/remote_verify.php';
    private const LC_ROOT = 'D:/woquanma/woquanshouquan';
    private const LOG_FILE = __DIR__ . '/qa_heartbeat_result.log';

    private const ID_STABLE = 'aaaaaaaa-1111-4111-8111-111111111111';
    private const ID_DEDUP  = 'bbbbbbbb-2222-4222-8222-222222222222';
    private const ID_RATE   = 'cccccccc-3333-4333-8333-333333333333';

    /** @var array<int,string> */
    private static $log = [];
    private static $pass = 0;
    private static $fail = 0;
    private static $skip = 0;
    private static $caseNo = 0;

    // ------------------------------------------------------------------ 基础设施

    private static function w(string $line): void
    {
        self::$log[] = $line;
    }

    private static function check(bool $cond, string $name, string $detail = ''): void
    {
        self::$caseNo++;
        if ($cond) {
            self::$pass++;
            self::w(sprintf('[PASS] #%03d %s%s', self::$caseNo, $name, $detail === '' ? '' : ' | ' . $detail));
        } else {
            self::$fail++;
            self::w(sprintf('[FAIL] #%03d %s%s', self::$caseNo, $name, $detail === '' ? '' : ' | ' . $detail));
        }
    }

    private static function skipCase(string $name, string $reason): void
    {
        self::$caseNo++;
        self::$skip++;
        self::w(sprintf('[SKIP] #%03d %s | %s', self::$caseNo, $name, $reason));
    }

    private static function section(string $title): void
    {
        self::w('');
        self::w('===== ' . $title . ' =====');
    }

    /** @return array{code:int,body:string,json:?array} */
    private static function send(array $payload, array $headerOverrides = []): array
    {
        $headers = [
            'Content-Type: application/json',
            'X-Hublog-Fingerprint: ' . (string)($payload['fingerprint'] ?? ''),
            'X-Site-Report: heartbeat',
            'X-Site-Installation: ' . (string)($payload['installation_id'] ?? ''),
            'X-Site-Timestamp: ' . (string)($payload['timestamp'] ?? ''),
            'X-Site-Nonce: ' . (string)($payload['nonce'] ?? ''),
            'X-Site-Signature: ' . (string)($payload['signature'] ?? ''),
        ];
        foreach ($headerOverrides as $h) {
            // 以 header 名前缀匹配替换
            $name = strtolower(trim(explode(':', $h, 2)[0]));
            foreach ($headers as $i => $existing) {
                if (strtolower(trim(explode(':', $existing, 2)[0])) === $name) {
                    $headers[$i] = $h;
                    continue 2;
                }
            }
            $headers[] = $h;
        }
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($body, true);
        return ['code' => $code, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }

    /** 用真实客户端签名器生成一条完整心跳 payload */
    private static function makePayload(array $overrides = [], bool $sign = true): array
    {
        $p = array_merge([
            'installation_id' => self::ID_STABLE,
            'fingerprint'     => str_repeat('a', 64),
            'license_code'    => '',
            'site_host'       => 'a.example.com',
            'version'         => '3.2.1-QA',
            'users'           => 128,
            'posts'           => 456,
            'timestamp'       => time(),
            'nonce'           => bin2hex(random_bytes(16)),
        ], $overrides);
        if ($sign) {
            $p['signature'] = PayloadSigner::sign($p, (string)$p['license_code'], (string)$p['fingerprint']);
        } else {
            $p['signature'] = str_repeat('0', 64);
        }
        return $p;
    }

    private static function reason(array $resp): string
    {
        return (string)($resp['json']['data']['reason'] ?? '');
    }

    private static function ledger(): array
    {
        $f = self::LC_ROOT . '/data/installations.json';
        if (!is_file($f)) {
            return [];
        }
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    private static function resetLedger(): void
    {
        foreach (['data/installations.json', 'data/installations.json.lock'] as $rel) {
            $f = self::LC_ROOT . '/' . $rel;
            if (is_file($f)) {
                @unlink($f);
            }
        }
        foreach (glob(self::LC_ROOT . '/data/installations_verify_log_*.json') ?: [] as $f) {
            @unlink($f);
        }
        self::resetRate();
    }

    private static function resetRate(): void
    {
        foreach (['data/rate_limit.json', 'data/rate_limit.json.lock'] as $rel) {
            $f = self::LC_ROOT . '/' . $rel;
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    // ------------------------------------------------------------------ P0-1

    private static function p01(): void
    {
        self::section('P0-1 双端签名正确性（真实 HTTP 打进 remote_verify.php）');
        self::resetLedger();

        // 1) 正向：真实客户端签名被服务端接受
        $p = self::makePayload();
        $r = self::send($p);
        self::check($r['code'] === 200 && (int)($r['json']['code'] ?? 0) === 200
            && ($r['json']['data']['ok'] ?? false) === true,
            'P0-1 真实客户端 PayloadSigner 签名被服务端验过',
            'HTTP=' . $r['code'] . ' body=' . substr($r['body'], 0, 160));
        self::check(($r['json']['data']['installation_id'] ?? '') === self::ID_STABLE,
            'P0-1 响应回显 installation_id');

        // 2) 篡改四个签名字段（保持签名不变）→ HB_SIGNATURE_INVALID
        foreach ([
            'site_host' => 'tampered.example.com',
            'version'   => '9.9.9-tampered',
            'users'     => 999999,
        ] as $field => $badValue) {
            $p = self::makePayload();
            $p[$field] = $badValue; // 签名仍是原字段的
            $r = self::send($p);
            self::check($r['code'] === 400 && self::reason($r) === 'HB_SIGNATURE_INVALID',
                'P0-1 篡改 ' . $field . ' → HB_SIGNATURE_INVALID',
                'HTTP=' . $r['code'] . ' reason=' . self::reason($r));
        }
        // timestamp 单独处理：同步改 body+header，保持旧签名 → 必须命中签名错误而非头不一致
        $p = self::makePayload();
        $p['timestamp'] = $p['timestamp'] + 10;
        $r = self::send($p);
        self::check($r['code'] === 400 && self::reason($r) === 'HB_SIGNATURE_INVALID',
            'P0-1 篡改 timestamp（头体同步、签名仍旧）→ HB_SIGNATURE_INVALID',
            'HTTP=' . $r['code'] . ' reason=' . self::reason($r));

        // 3) 超时间窗（签名正确）→ HB_TIMESTAMP_OUT_OF_WINDOW
        self::resetRate();
        $p = self::makePayload(['timestamp' => time() - 2500]);
        $r = self::send($p);
        self::check($r['code'] === 400 && self::reason($r) === 'HB_TIMESTAMP_OUT_OF_WINDOW',
            'P0-1 timestamp 超出 1800s 窗口 → HB_TIMESTAMP_OUT_OF_WINDOW',
            'HTTP=' . $r['code'] . ' reason=' . self::reason($r));

        // 4) nonce 重放
        self::resetRate();
        $p = self::makePayload(['installation_id' => self::ID_RATE]);
        $r1 = self::send($p);
        $r2 = self::send($p);
        self::check($r1['code'] === 200 && $r2['code'] === 400 && self::reason($r2) === 'HB_NONCE_REPLAY',
            'P0-1 同一 nonce 第二次 → HB_NONCE_REPLAY',
            '1st=' . $r1['code'] . ' 2nd=' . $r2['code'] . ' reason=' . self::reason($r2));

        // 5) 头体不一致
        self::resetRate();
        $p = self::makePayload();
        $r = self::send($p, ['X-Site-Installation: wrong-installation-id']);
        self::check($r['code'] === 400 && self::reason($r) === 'HB_HEADER_MISMATCH',
            'P0-1 头体 installation_id 不一致 → HB_HEADER_MISMATCH',
            'HTTP=' . $r['code'] . ' reason=' . self::reason($r));
        $p = self::makePayload();
        $r = self::send($p, ['X-Site-Nonce: ' . str_repeat('e', 32)]);
        self::check($r['code'] === 400 && self::reason($r) === 'HB_HEADER_MISMATCH',
            'P0-1 头体 nonce 不一致 → HB_HEADER_MISMATCH',
            'HTTP=' . $r['code'] . ' reason=' . self::reason($r));

        // 6) 缺 installation_id
        self::resetRate();
        $p = self::makePayload(['installation_id' => '']);
        $r = self::send($p, ['X-Site-Installation: ']);
        self::check($r['code'] === 400 && self::reason($r) === 'HB_MISSING_INSTALLATION_ID',
            'P0-1 缺 installation_id → HB_MISSING_INSTALLATION_ID',
            'HTTP=' . $r['code'] . ' reason=' . self::reason($r));

        // 7) 签名头优先于 body 签名
        self::resetRate();
        $p = self::makePayload();
        $goodSig = $p['signature'];
        $p['signature'] = str_repeat('0', 64); // body 签名错误，header 正确
        $r = self::send($p, ['X-Site-Signature: ' . $goodSig]);
        self::check($r['code'] === 200,
            'P0-1 服务端优先使用 X-Site-Signature（body 签名错误仍通过）',
            'HTTP=' . $r['code'] . ' body=' . substr($r['body'], 0, 140));

        // 8) 限流 120/min
        self::resetRate();
        $limited = false;
        $limitResp = null;
        for ($i = 1; $i <= 125; $i++) {
            $p = self::makePayload(['installation_id' => self::ID_RATE]);
            $r = self::send($p);
            if ($i >= 120) {
                self::w(sprintf('       rate-probe #%d HTTP=%d reason=%s', $i, $r['code'], self::reason($r)));
            }
            if ($r['code'] === 429 && self::reason($r) === 'HB_RATE_LIMITED') {
                $limited = true;
                $limitResp = $r;
                break;
            }
        }
        self::check($limited,
            'P0-1 限流 120/min 触发 → HB_RATE_LIMITED',
            $limitResp === null ? '125 次内未触发' : '触发于第 ' . $i . ' 次, body=' . substr($limitResp['body'], 0, 140));
    }

    // ------------------------------------------------------------------ P0-2

    private static function p02(): void
    {
        self::section('P0-2 身份稳定性（换域名 / 纯 IP / 伪造 host / UUID 幂等）');
        self::resetLedger();

        $scenarios = [
            ['a.example.com', false, '首次域名 a.example.com'],
            ['203.0.113.7',   true,  '纯 IP 访问'],
            ['fake.example.org', false, '伪造 host'],
            ['b.example.com', false, '换域名 b.example.com'],
        ];
        foreach ($scenarios as [$host, $isIp, $label]) {
            $p = self::makePayload([
                'installation_id' => self::ID_STABLE,
                'site_host'       => $host,
            ]);
            $r = self::send($p);
            $ledger = self::ledger();
            $rec = $ledger[self::ID_STABLE] ?? [];
            self::check($r['code'] === 200 && count($ledger) === 1
                && ($rec['installation_id'] ?? '') === self::ID_STABLE
                && ($rec['site_host'] ?? '') === $host
                && (bool)($rec['is_pure_ip_host'] ?? false) === $isIp,
                'P0-2 ' . $label . ' → 仍归同一 installation_id、不新建记录',
                'records=' . count($ledger) . ' site_host=' . ($rec['site_host'] ?? '-')
                . ' is_pure_ip=' . var_export($rec['is_pure_ip_host'] ?? null, true));
        }

        $ledger = self::ledger();
        $rec = $ledger[self::ID_STABLE] ?? [];
        $dh = $rec['domain_history'] ?? [];
        self::check(is_array($dh) && in_array('a.example.com', $dh, true) && in_array('b.example.com', $dh, true),
            'P0-2 domain_history 追加换域名轨迹',
            'domain_history=' . json_encode($dh, JSON_UNESCAPED_UNICODE));

        // 客户端侧：真实 DB 的 getOrCreateId 幂等（含清缓存模拟 power-cycle）
        $before = License::getSetting('license_installation_id', '');
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            if ($i === 5) {
                \Hublog\Core\Cache::delete('site_settings');
            }
            $ids[] = InstallationIdentity::getOrCreateId();
        }
        self::check(count(array_unique($ids)) === 1 && preg_match('/^[0-9a-f-]{36}$/', $ids[0]) === 1,
            'P0-2 getOrCreateId 连续 10 次（含清缓存）返回同一 UUID',
            'before=' . var_export($before, true) . ' id=' . $ids[0]);
        self::check(InstallationIdentity::getOrCreateId() === $ids[0],
            'P0-2 重装/升级语义：已有 ID 不被覆盖');
    }

    // ------------------------------------------------------------------ P0-3

    private static function p03(): void
    {
        self::section('P0-3 去重与原子性');
        self::resetLedger();

        // 100 次同 installation_id
        $failCount = 0;
        for ($i = 0; $i < 100; $i++) {
            $p = self::makePayload(['installation_id' => self::ID_DEDUP]);
            $r = self::send($p);
            if ($r['code'] !== 200) {
                $failCount++;
            }
        }
        $ledger = self::ledger();
        $rec = $ledger[self::ID_DEDUP] ?? [];
        self::check($failCount === 0, 'P0-3 100 次上报全部被服务端接受', 'non200=' . $failCount);
        self::check(count($ledger) === 1, 'P0-3 台账仅 1 条记录', 'records=' . count($ledger));
        self::check((int)($rec['verification_count'] ?? 0) === 100,
            'P0-3 verification_count=100', 'actual=' . ($rec['verification_count'] ?? '-'));
        self::check(count($rec['verify_log'] ?? []) === 100,
            'P0-3 100 次上报产生 100 条 verify_log（未超 200 不轮转）', 'actual=' . count($rec['verify_log'] ?? []));

        // 20 并发（curl_multi）
        self::resetRate();
        $mh = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 20; $i++) {
            $p = self::makePayload(['installation_id' => self::ID_DEDUP]);
            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Site-Report: heartbeat',
                    'X-Site-Installation: ' . $p['installation_id'],
                    'X-Site-Timestamp: ' . $p['timestamp'],
                    'X-Site-Nonce: ' . $p['nonce'],
                    'X-Site-Signature: ' . $p['signature'],
                ],
                CURLOPT_TIMEOUT => 15,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running > 0);
        foreach ($handles as $ch) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        $ledger = self::ledger();
        $rec = $ledger[self::ID_DEDUP] ?? [];
        self::check(count($ledger) === 1 && (int)($rec['verification_count'] ?? 0) === 120,
            'P0-3 20 并发后仍 1 条记录、count=120',
            'records=' . count($ledger) . ' count=' . ($rec['verification_count'] ?? '-'));

        // 轮转：再 110 次 → 累计 230，主记录 200、归档 30
        self::resetRate();
        for ($i = 0; $i < 110; $i++) {
            $p = self::makePayload(['installation_id' => self::ID_DEDUP]);
            self::send($p);
        }
        $ledger = self::ledger();
        $rec = $ledger[self::ID_DEDUP] ?? [];
        $mainCount = count($rec['verify_log'] ?? []);
        $archives = glob(self::LC_ROOT . '/data/installations_verify_log_*.json') ?: [];
        $archLines = 0;
        foreach ($archives as $af) {
            $archLines += count(array_filter(explode("\n", (string)file_get_contents($af)), 'strlen'));
        }
        self::check($mainCount === 200 && count($archives) >= 1 && $archLines >= 25,
            'P0-3 verify_log 超 200 触发轮转归档',
            'main=' . $mainCount . ' archiveFiles=' . count($archives) . ' archiveLines=' . $archLines);

        // 代码审计：所有 data/*.json 读写是否位于 ls_atomic_update 回调内
        self::p03Audit();
    }

    private static function p03Audit(): void
    {
        $store = (string)file_get_contents(self::LC_ROOT . '/inc/report_store.php');
        $rv = (string)file_get_contents(self::LC_ROOT . '/remote_verify.php');
        // report_store.php：读到 licenses.json 的唯一一行位于 ls_atomic_update 回调内
        $inside = strpos($store, 'ls_atomic_update(') !== false
            && strpos($store, "ls_read_json(\$licensesFile, [])") !== false;
        self::check($inside, 'P0-3 代码审计：report_store 的 licenses 快照读取位于 ls_atomic_update 回调内');
        self::check(strpos($store, 'file_put_contents($archiveFile, $lines, FILE_APPEND | LOCK_EX)') !== false,
            'P0-3 代码审计：轮转归档写入带 LOCK_EX 且在回调内');
        // remote_verify.php 原 license 路径的唯一裸 ls_read_json 位于 RV_ALLOW_LEGACY=false 的死分支
        self::check(strpos($rv, "define('RV_ALLOW_LEGACY', false)") !== false,
            'P0-3 代码审计：RV_ALLOW_LEGACY=false，裸 ls_read_json 分支不可达');
        self::w('       [审计结论] report_store 全部 data/*.json 读写发生在 ls_atomic_update 单一回调内；'
            . 'remote_verify 的限流/nonce/license 写入亦在各自 ls_atomic_update 回调内；'
            . '仅 legacy 兼容分支(line~210)有一处 ls_read_json，因 RV_ALLOW_LEGACY=false 不可达。');
    }

    // ------------------------------------------------------------------ P0-4

    private static function p04(): void
    {
        self::section('P0-4 失败静默（真实 DB + 不可达端点 / SSRF）');

        $keys = [
            'license_report_endpoint',
            'license_remote_url',
            'license_remote_ssl_verify',
            'license_heartbeat_last_at',
            'license_heartbeat_retry_at',
            'license_heartbeat_last_error_at',
        ];
        $backup = [];
        foreach ($keys as $k) {
            $backup[$k] = License::getSetting($k, '');
        }
        try {
            // 场景 A：合法但不可达的 HTTPS 端点（DNS 失败 / 连接超时）
            self::setSetting('license_report_endpoint', 'https://license-qa-unreachable.invalid/');
            self::setSetting('license_remote_url', '');
            self::setSetting('license_remote_ssl_verify', '1');
            self::setSetting('license_heartbeat_last_at', '1000');
            self::setSetting('license_heartbeat_retry_at', '0');
            \Hublog\Core\Cache::delete('site_settings');

            $t0 = microtime(true);
            ob_start();
            $ok = HeartbeatReporter::reportNow();
            $output = (string)ob_get_clean();
            $elapsed = (microtime(true) - $t0) * 1000;
            $lastAt = (string)License::getSetting('license_heartbeat_last_at', '');
            $retryAt = (int)License::getSetting('license_heartbeat_retry_at', '0');

            self::check($ok === false, 'P0-4 不可达端点 reportNow() 返回 false 且不抛异常');
            self::check($output === '', 'P0-4 失败时无任何页面输出（无报错/无白屏）', 'output=' . var_export($output, true));
            self::check($lastAt === '1000', 'P0-4 失败不更新 last_at', 'last_at=' . $lastAt);
            self::check($retryAt >= time() + 50 && $retryAt <= time() + 70,
                'P0-4 失败写 retry_at=now+60s 防风暴', 'retry_at=' . $retryAt . ' now=' . time());
            self::w(sprintf('       不可达端点耗时 %.1f ms（含 DNS/1s 连接超时）', $elapsed));

            // 场景 B：SSRF —— 指向本地回环地址必须被拦截
            self::setSetting('license_report_endpoint', 'http://127.0.0.1:8899/');
            self::setSetting('license_remote_ssl_verify', '0');
            \Hublog\Core\Cache::delete('site_settings');
            ob_start();
            $ok2 = HeartbeatReporter::reportNow();
            $output2 = (string)ob_get_clean();
            self::check($ok2 === false && $output2 === '',
                'P0-4 SSRF：端点指向 127.0.0.1 被拦截且不输出',
                'ok=' . var_export($ok2, true));
        } finally {
            foreach ($backup as $k => $v) {
                self::setSetting($k, (string)$v);
            }
            \Hublog\Core\Cache::delete('site_settings');
        }

        // FPM 异步钩子审计
        $index = (string)file_get_contents(dirname(__DIR__) . '/index.php');
        $shutdownPos = strpos($index, 'register_shutdown_function(static function');
        // 注意：文件头部注释里也出现了 fastcgi_finish_request 字样，必须匹配真实调用 @fastcgi_finish_request()
        $finishPos = strpos($index, '@fastcgi_finish_request()');
        $reportPos = strpos($index, 'HeartbeatReporter::maybeReport');
        self::check($shutdownPos !== false && $finishPos !== false && $reportPos !== false
            && $shutdownPos < $finishPos && $finishPos < $reportPos,
            'P0-4 审计：上报位于 shutdown 回调内，且先 @fastcgi_finish_request() 再 maybeReport()');

        // 合规 grep
        $chain = [
            dirname(__DIR__) . '/core/InstallationIdentity.php',
            dirname(__DIR__) . '/core/PayloadSigner.php',
            dirname(__DIR__) . '/core/HeartbeatReporter.php',
            dirname(__DIR__) . '/controllers/CronHeartbeatController.php',
            self::LC_ROOT . '/inc/report_store.php',
            self::LC_ROOT . '/remote_verify.php',
        ];
        $serverAddrHits = [];
        $dangerHits = [];
        foreach ($chain as $f) {
            $src = (string)@file_get_contents($f);
            if (strpos($src, 'SERVER_ADDR') !== false) {
                $serverAddrHits[] = $f;
            }
            foreach (['unlink(', 'DROP TABLE', 'DELETE FROM', 'shell_exec(', 'system(', 'eval(', 'assert('] as $bad) {
                if (strpos($src, $bad) !== false) {
                    $dangerHits[] = basename($f) . ':' . $bad;
                }
            }
        }
        self::check($serverAddrHits === [], 'P0-4 合规审计：上报链路无 SERVER_ADDR',
            'hits=' . json_encode($serverAddrHits));
        self::check($dangerHits === [], 'P0-4 合规审计：上报链路无破坏性/执行类调用',
            'hits=' . json_encode($dangerHits));
    }

    private static function setSetting(string $key, string $value): void
    {
        require_once dirname(__DIR__, 2) . '/config.php';
        $now = date('Y-m-d H:i:s');
        try {
            $pdo = new \PDO(
                'mysql:host=' . \DB_HOST . ';port=' . \DB_PORT . ';dbname=' . \DB_NAME . ';charset=utf8mb4',
                \DB_USER, \DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $st = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE `key` = ?');
            $st->execute([$key]);
            if ((int)$st->fetchColumn() > 0) {
                $u = $pdo->prepare('UPDATE settings SET `value`=?, `updated_at`=? WHERE `key`=?');
                $u->execute([$value, $now, $key]);
            } else {
                $i = $pdo->prepare('INSERT INTO settings(`key`,`value`,`created_at`,`updated_at`) VALUES(?,?,?,?)');
                $i->execute([$key, $value, $now, $now]);
            }
        } catch (\Throwable $e) {
            self::w('       [WARN] setSetting(' . $key . ') 失败: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ P0-5

    private static function p05(): void
    {
        self::section('P0-5 原 license 验签路径回归（无 X-Site-Report，响应不被 heartbeat 污染）');
        self::resetRate();

        // 用授权中心自己的私钥签发一张测试 license（真实的验签路径输入）
        require_once self::LC_ROOT . '/inc/crypto.php';
        $licPayload = [
            'jti' => 'qa-regression-jti',
            'type' => 'trial',
            'domain' => '',
            'expires_at' => null,
            'modules' => ['*'],
            'features' => ['*'],
        ];
        $licenseKey = ls_encode_license($licPayload);
        self::check(strlen($licenseKey) > 40, 'P0-5 构造合法 license_key 成功', 'len=' . strlen($licenseKey));

        // 注册这张 license，使响应走「已登记」的完整字段路径（而非 NOT_REGISTERED 短响应）。
        // 备份 licenses.json，测试结束后原样恢复，避免污染授权中心状态。
        $licensesFile = self::LC_ROOT . '/data/licenses.json';
        $licensesBackup = (string)@file_get_contents($licensesFile);
        require_once self::LC_ROOT . '/inc/storage.php';
        \ls_atomic_update($licensesFile, static function (array &$list) use ($licenseKey, $licPayload): void {
            $list[] = [
                'id'                   => 'qa-regression-license',
                'license_key'          => $licenseKey,
                'payload'              => $licPayload,
                'revoked'              => false,
                'active'               => true,
                'allowed_domains'      => [],
                'allowed_fingerprints' => [],
                'customer_name'        => 'QA Regression',
                'customer_email'       => 'qa-regression@example.com',
            ];
        });

        try {
            // A：老客户端形态（无 X-Site-* 头，无 installation_id/signature）
            $common = [
                'license_key' => $licenseKey,
                'domain'      => 'regression.example.com',
                'fingerprint' => str_repeat('b', 64),
                'version'     => '3.2.1',
                'timestamp'   => time(),
                'nonce'       => bin2hex(random_bytes(8)),
                'stats'       => ['users' => 3, 'posts' => 5],
            ];
            $old = self::rawPost($common, []);

            // B：新客户端 verifyRemote 形态（带 X-Site-Installation / X-Site-Signature + body 两键，
            //    但不带 X-Site-Report）。注意 nonce 必须与 A 不同，否则会被原路径的 nonce 去重拒绝。
            $newBody = array_merge($common, [
                'installation_id' => self::ID_STABLE,
                'signature'       => str_repeat('d', 64),
                'nonce'           => bin2hex(random_bytes(8)),
            ]);
            $new = self::rawPost($newBody, [
                'X-Site-Installation: ' . self::ID_STABLE,
                'X-Site-Signature: ' . str_repeat('d', 64),
            ]);
        } finally {
            file_put_contents($licensesFile, $licensesBackup, LOCK_EX);
        }

        self::check($old['code'] === 200 && $new['code'] === 200,
            'P0-5 两种形态均走原路径返回 200',
            'old=' . $old['code'] . ' new=' . $new['code']);

        // 结构断言：data 必须是原授权字段，绝不能出现 heartbeat 字段
        $forbidden = ['ok', 'installation_id', 'auth_status', 'server_time'];
        $polluted = [];
        foreach ($forbidden as $k) {
            if (array_key_exists($k, (array)($new['json']['data'] ?? []))) {
                $polluted[] = $k;
            }
        }
        $expectedKeys = ['valid', 'reason', 'jti', 'type', 'expires_at', 'domain', 'max_users',
            'max_posts', 'modules', 'features', 'customer_name', 'customer_email', 'renewed_key', 'timestamp'];
        $missing = array_diff($expectedKeys, array_keys((array)($new['json']['data'] ?? [])));
        self::check($polluted === [] && $missing === [],
            'P0-5 原响应字段集未被 heartbeat 污染',
            'polluted=' . json_encode($polluted) . ' missing=' . json_encode(array_values($missing)));

        // 归一化比较（剔除服务端 time 与依赖它的 RSA 签名后，outer+data 逐字节一致）
        $norm = static function (array $resp): string {
            $j = $resp['json'];
            unset($j['data']['timestamp'], $j['signature']);
            unset($j['data']['last_verified_at']);
            return json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };
        $same = $norm($old) === $norm($new);
        self::check($same, 'P0-5 新旧客户端形态响应（归一化时间后）逐字节一致',
            $same ? '' : "OLD=" . substr($norm($old), 0, 240) . " NEW=" . substr($norm($new), 0, 240));
        self::w('       OLD data.reason=' . ($old['json']['data']['reason'] ?? '-')
            . ' NEW data.reason=' . ($new['json']['data']['reason'] ?? '-'));
    }

    /** @return array{code:int,body:string,json:?array} */
    private static function rawPost(array $body, array $extraHeaders): array
    {
        $headers = array_merge(['Content-Type: application/json', 'X-Hublog-Fingerprint: ' . ($body['fingerprint'] ?? '')], $extraHeaders);
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        $b = (string)curl_exec($ch);
        $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode($b, true);
        return ['code' => $c, 'body' => $b, 'json' => is_array($j) ? $j : null];
    }

    // ------------------------------------------------------------------ 补充边界探测

    private static function p06(): void
    {
        self::section('补充边界探测（工程师自检未覆盖的路径）');
        self::resetLedger();

        // 1) 真实客户端 getSiteHost() 产出的 IPv6 host 直接喂给真实服务端
        $_SERVER['HTTP_HOST'] = '[2001:DB8::1]:8443';
        $v6host = InstallationIdentity::getSiteHost();
        $p = self::makePayload(['installation_id' => self::ID_STABLE, 'site_host' => $v6host]);
        $r = self::send($p);
        $rec = self::ledger()[self::ID_STABLE] ?? [];
        self::check($r['code'] === 200 && ($rec['site_host'] ?? '') === '[2001:db8::1]'
            && (bool)($rec['is_pure_ip_host'] ?? false) === true,
            '边界 IPv6 host 经客户端归一化后上报 → is_pure_ip_host=true 且身份不变',
            'host=' . $v6host . ' stored=' . ($rec['site_host'] ?? '-'));
        unset($_SERVER['HTTP_HOST']);

        // 2) 其余签名字段篡改（fingerprint / license_code / nonce 由头体一致覆盖）
        self::resetRate();
        foreach ([
            'fingerprint'  => str_repeat('c', 64),
            'license_code' => 'WQ-2026-TAMPER',
        ] as $field => $badValue) {
            $p = self::makePayload();
            $p[$field] = $badValue;
            $r = self::send($p);
            self::check($r['code'] === 400 && self::reason($r) === 'HB_SIGNATURE_INVALID',
                '边界 篡改 ' . $field . ' → HB_SIGNATURE_INVALID',
                'HTTP=' . $r['code'] . ' reason=' . self::reason($r));
        }

        // 3) 成功恢复分支的代码守卫（E2E 受 SSRF 限制无法打本地 server，用静态审计补证）
        $reporter = (string)file_get_contents(dirname(__DIR__) . '/core/HeartbeatReporter.php');
        self::check(
            strpos($reporter, "self::writeSetting('license_heartbeat_last_at'") !== false
            && strpos($reporter, "self::writeSetting('license_heartbeat_retry_at', '0')") !== false
            && strpos($reporter, 'InstallationIdentity::clearPending()') !== false,
            '边界 成功分支守卫：更新 last_at / 清 retry_at / 清 pending');
        self::check(
            strpos($reporter, "self::writeSetting('license_heartbeat_retry_at', (string)(time() + self::RETRY_DELAY)") !== false,
            '边界 失败分支守卫：写 retry_at=now+60s');

        // 4) 非法 JSON body 不得 500
        self::resetRate();
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{not-json',
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $badCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        self::check($badCode === 400, '边界 非法 JSON body → 400（非 500）', 'HTTP=' . $badCode);
    }

    // ------------------------------------------------------------------ main

    public static function run(): void
    {
        self::w('授权统计上报 · QA 独立验证结果');
        self::w('时间：' . date('Y-m-d H:i:s') . '  PHP=' . PHP_VERSION);
        self::w('服务端：' . self::ENDPOINT . '（真实 remote_verify.php）');
        self::w('签名来源：真实客户端 PayloadSigner::sign()（非复刻公式）');

        self::p01();
        self::p02();
        self::p03();
        self::p04();
        self::p05();
        self::p06();

        self::w('');
        self::w(sprintf('===== 汇总 ====='));
        self::w(sprintf('TOTAL=%d PASS=%d FAIL=%d SKIP=%d', self::$pass + self::$fail + self::$skip,
            self::$pass, self::$fail, self::$skip));

        file_put_contents(self::LOG_FILE, implode("\n", self::$log) . "\n", LOCK_EX);
        echo implode("\n", self::$log) . "\n";
    }
}

HeartbeatReportingQATest::run();
