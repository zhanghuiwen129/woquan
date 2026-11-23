<?php
/**
 * 授权统计上报 · 自检脚本（无需 PHPUnit / 无需数据库）。
 *
 * 用法：php api/tests/HeartbeatReportingTest.php
 * 覆盖：双端 seed 一致、UUID v4 格式、site_host 提取 / 纯 IP 识别、
 *       规范化与签名可复现、服务端公式同源、异步钩子与静默失败的代码守卫。
 *
 * 说明：依赖 settings DB 的用例（getOrCreateId 十次幂等、锁内去重、upsert 去重）
 * 由 QA 在集成环境用真实 DB / 台账文件执行；本脚本只跑零依赖的确定性断言。
 */

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\InstallationIdentity;
use Hublog\Core\License;
use Hublog\Core\PayloadSigner;

require_once dirname(__DIR__) . '/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();

final class HeartbeatReportingTest
{
    /** @var int */
    private static $passed = 0;

    /** @var array<int, string> */
    private static $failures = [];

    /** @param mixed $expected @param mixed $actual */
    private static function assertSame($expected, $actual, string $label): void
    {
        if ($expected === $actual) {
            self::$passed++;
            return;
        }
        self::$failures[] = $label . '：expected=' . var_export($expected, true)
            . ', actual=' . var_export($actual, true);
    }

    private static function assertTrue(bool $cond, string $label): void
    {
        self::assertSame(true, $cond, $label);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<int, string> */
    public static function failures(): array
    {
        return self::$failures;
    }

    public static function failureCount(): int
    {
        return count(self::$failures);
    }

    /** 服务端公式（与 remote_verify.php 逐字节同构，用于验证双端一致性） */
    private static function serverSignature(array $fields, string $licenseCode, string $fingerprint, string $seed): string
    {
        $names = [
            'installation_id', 'fingerprint', 'license_code', 'site_host', 'version',
            'users', 'posts', 'timestamp', 'nonce',
        ];
        $picked = [];
        foreach ($names as $name) {
            if (in_array($name, ['users', 'posts', 'timestamp'], true)) {
                $picked[$name] = (int)($fields[$name] ?? 0);
            } else {
                $picked[$name] = (string)($fields[$name] ?? '');
            }
        }
        $norm = [];
        foreach ($picked as $k => $v) {
            $norm[(string)$k] = is_scalar($v) ? (string)$v : '';
        }
        ksort($norm, SORT_STRING);
        $canonical = json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signing = "POST\n/remote_verify.php\n" . (string)$picked['timestamp'] . "\n"
            . $picked['nonce'] . "\n" . hash('sha256', (string)$canonical);
        $derived = hash_hmac('sha256', $licenseCode . '|' . $fingerprint, $seed);
        return hash_hmac('sha256', $signing, $derived);
    }

    public static function run(): void
    {
        // 1) 双端 seed 逐字节一致（直接解析两份源码，避免只测常量自身）
        $clientSrc = file_get_contents(self::root() . '/api/core/License.php');
        $serverSrc = file_get_contents(dirname(self::root()) . '/woquanma/woquanshouquan/license_config.php');
        self::assertSame(true, is_string($clientSrc) && is_string($serverSrc), '能读取双端配置文件');
        if (is_string($clientSrc) && is_string($serverSrc)) {
            preg_match("/REPORT_KEY_SEED\\s*=\\s*'([0-9a-f]+)'/", $clientSrc, $cm);
            preg_match("/LS_REPORT_KEY_SEED',\\s*'([0-9a-f]+)'/", $serverSrc, $sm);
            self::assertSame(true, isset($cm[1]) && strlen($cm[1]) === 64, '客户端 seed 为 32 字节 hex');
            self::assertSame(true, isset($sm[1]) && strlen($sm[1]) === 64, '服务端 seed 为 32 字节 hex');
            if (isset($cm[1], $sm[1])) {
                self::assertSame($cm[1], $sm[1], '双端 seed 逐字节一致');
                self::assertSame($cm[1], License::REPORT_KEY_SEED, 'License 常量与源码一致');
            }
        }

        // 2) UUID v4 格式（生成 10 次都符合 RFC 4122 v4）
        $method = new \ReflectionMethod(InstallationIdentity::class, 'generateUuidV4');
        $method->setAccessible(true);
        $seen = [];
        for ($i = 0; $i < 10; $i++) {
            $uuid = (string)$method->invoke(null);
            $seen[$uuid] = true;
            self::assertSame(
                1,
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid),
                'UUID v4 格式合法：' . $uuid
            );
        }
        self::assertSame(10, count($seen), '10 次生成的 UUID 互不重复');

        // 3) site_host 提取：域名去端口 / IPv6 保留方括号 / SERVER_NAME 兜底
        $_SERVER['HTTP_HOST'] = 'WWW.Example.COM:8080';
        $_SERVER['SERVER_NAME'] = 'fallback.example.org';
        self::assertSame('www.example.com', InstallationIdentity::getSiteHost(), '域名去端口并转小写');
        self::assertSame(false, InstallationIdentity::isPureIp('www.example.com'), '域名不是纯 IP');

        $_SERVER['HTTP_HOST'] = '[2001:DB8::1]:8443';
        self::assertSame('[2001:db8::1]', InstallationIdentity::getSiteHost(), 'IPv6 保留方括号去端口');
        self::assertSame(true, InstallationIdentity::isPureIp('[2001:db8::1]'), 'IPv6 识别为纯 IP');

        $_SERVER['HTTP_HOST'] = '203.0.113.7:80';
        self::assertSame('203.0.113.7', InstallationIdentity::getSiteHost(), '纯 IPv4 去端口');
        self::assertSame(true, InstallationIdentity::isPureIp('203.0.113.7'), 'IPv4 识别为纯 IP');

        unset($_SERVER['HTTP_HOST']);
        self::assertSame('fallback.example.org', InstallationIdentity::getSiteHost(), 'SERVER_NAME 兜底');

        // 4) 规范化规则：ksort(SORT_STRING) + 不转义 Unicode / 斜杠
        $canonical = PayloadSigner::canonical(['b' => '2', 'a' => '中文/路径']);
        self::assertSame(
            '{"a":"中文/路径","b":"2"}',
            $canonical,
            'canonical 按 SORT_STRING 排序且不转义 Unicode/斜杠'
        );

        // 5) 签名可复现：双端公式逐字节一致；篡改字段即签名不同
        $fields = [
            'installation_id' => '3f2a7c1e-9b64-4d0a-8e2f-1c5d9a7b3e60',
            'fingerprint'     => str_repeat('a', 64),
            'license_code'    => 'WQ-2026-TEST-XXXX',
            'site_host'       => 'b.example.com',
            'version'         => '3.2.1',
            'users'           => 128,
            'posts'           => 456,
            'timestamp'       => 1730000000,
            'nonce'           => str_repeat('f', 32),
        ];
        $licenseCode = (string)$fields['license_code'];
        $fingerprint = (string)$fields['fingerprint'];
        $sig = PayloadSigner::sign($fields, $licenseCode, $fingerprint);
        self::assertSame(
            self::serverSignature($fields, $licenseCode, $fingerprint, License::REPORT_KEY_SEED),
            $sig,
            '服务端按同公式复现的签名与客户端逐字节一致'
        );
        self::assertSame(64, strlen($sig), '签名为 64 位 hex');
        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $sig), '签名为小写 hex');
        $tampered = $fields;
        $tampered['users'] = 129;
        self::assertSame(true, PayloadSigner::sign($tampered, $licenseCode, $fingerprint) !== $sig, '篡改字段导致签名变化');

        // 6) 静态守卫：异步钩子、静默失败、端点单一来源、回调内写台账
        $indexSrc = (string)file_get_contents(self::root() . '/api/index.php');
        $fp = strpos($indexSrc, 'fastcgi_finish_request');
        $mb = strpos($indexSrc, 'HeartbeatReporter::maybeReport');
        self::assertSame(true, $fp !== false && $mb !== false && $fp < $mb, '响应发出后才执行心跳上报');

        $reporterSrc = (string)file_get_contents(self::root() . '/api/core/HeartbeatReporter.php');
        self::assertSame(true, strpos($reporterSrc, "CURLOPT_TIMEOUT        => self::CURL_TIMEOUT") !== false, 'curl 超时为 2s');
        self::assertSame(true, strpos($reporterSrc, 'Cache::withLock(self::LOCK_KEY') !== false, '上报使用缓存锁去重');

        $storeSrc = (string)file_get_contents(dirname(self::root()) . '/woquanma/woquanshouquan/inc/report_store.php');
        self::assertSame(true, strpos($storeSrc, 'ls_atomic_update(') !== false, '台账写入在原子回调内');

        $rvSrc = (string)file_get_contents(dirname(self::root()) . '/woquanma/woquanshouquan/remote_verify.php');
        self::assertSame(true, strpos($rvSrc, "HTTP_X_SITE_REPORT") !== false, '服务端读取心跳分支头');
        self::assertSame(true, strpos($rvSrc, 'HB_SIGNATURE_INVALID') !== false, '服务端验签失败 reason 枚举存在');
    }
}

// H-1：本脚本依赖授权中心同目录（D:/woquanma/woquanshouquan）与真实 DB/HTTP，属手工 E2E，
// 不进 run_tests.php 门禁。换机器/CI 缺少该目录时明确跳过，避免误报失败。
// 需要执行时显式设置环境变量 HEARTBEAT_E2E=1。
if (getenv('HEARTBEAT_E2E') !== '1') {
    fwrite(STDERR, "[SKIP] HeartbeatReportingTest 是手工 E2E（依赖授权中心同目录 + DB）；"
        . "设置 HEARTBEAT_E2E=1 才执行。门禁请跑 run_tests.php 的 HeartbeatGateTest。\n");
    exit(0);
}
HeartbeatReportingTest::run();

if (HeartbeatReportingTest::failureCount() > 0) {
    foreach (HeartbeatReportingTest::failures() as $failure) {
        echo "FAIL " . $failure . PHP_EOL;
    }
    echo "FAILED=" . HeartbeatReportingTest::failureCount() . PHP_EOL;
    exit(1);
}
echo "PASS all heartbeat reporting checks" . PHP_EOL;
exit(0);
