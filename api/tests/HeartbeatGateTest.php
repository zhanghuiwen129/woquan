<?php
/**
 * 授权统计上报 · 门禁回归护栏（自包含，进 run_tests.php）。
 *
 * 覆盖 H-1 缺口：此前两个心跳测试（HeartbeatReportingTest / HeartbeatReportingQATest）
 * 是 standalone run() 脚本，无 test* 方法且硬编码外部授权中心路径，既进不了门禁，
 * 换机器也必然失败——导致「run_tests 76/76 全绿」不覆盖本次上线功能本身。
 *
 * 本文件只断言**本地、无 DB、无网络**即可确定的不变量：
 *   - 签名公式可复现（PayloadSigner 规范化 + HMAC）
 *   - H-3 修复守卫：gate 用 path 判前缀、pending 退避顺序
 *   - H-4 修复守卫：ssl_verify=0 默认 fail-closed
 * 需要授权中心 / 真实 DB 的 E2E 场景仍由两个手工脚本负责（见其文件头 skip 说明）。
 */

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\PayloadSigner;
use Hublog\Core\License;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();

final class HeartbeatGateTest extends TestCase
{
    private function reporterSrc(): string
    {
        return (string)file_get_contents(dirname(__DIR__) . '/core/HeartbeatReporter.php');
    }

    /** 签名对固定 9 字段可复现，且与独立公式逐字节一致 */
    public function testSignatureIsReproducible(): void
    {
        $fields = [
            'installation_id' => 'inst-123',
            'fingerprint'     => 'fp-abc',
            'license_code'    => 'LC-9',
            'site_host'       => 'example.com',
            'version'         => 'v2.0',
            'users'           => 10,
            'posts'           => 42,
            'timestamp'       => 1700000000,
            'nonce'           => 'a1b2c3d4',
        ];
        $sig = PayloadSigner::sign($fields, 'LC-9', 'fp-abc');
        $this->assertSame(64, strlen($sig));
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $sig));

        // 用独立实现的公式复算，验证 deriveKey + canonical + 串接顺序未漂移
        $names = ['installation_id', 'fingerprint', 'license_code', 'site_host', 'version', 'users', 'posts', 'timestamp', 'nonce'];
        $picked = [];
        foreach ($names as $n) {
            $picked[$n] = in_array($n, ['users', 'posts', 'timestamp'], true)
                ? (int)($fields[$n] ?? 0) : (string)($fields[$n] ?? '');
        }
        // 与 PayloadSigner::canonical() 同构：先全部转字符串再 ksort，否则 json_encode 里 int 10 与 "10" 不等价
        $normalized = [];
        foreach ($picked as $k => $v) {
            $normalized[$k] = (string)$v;
        }
        ksort($normalized, SORT_STRING);
        $canonical = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stringToSign = "POST\n" . PayloadSigner::PATH . "\n"
            . (string)$picked['timestamp'] . "\n" . $picked['nonce'] . "\n"
            . hash('sha256', (string)$canonical);
        $derived = hash_hmac('sha256', 'LC-9' . '|' . 'fp-abc', License::REPORT_KEY_SEED);
        $this->assertSame(hash_hmac('sha256', $stringToSign, $derived), $sig);
    }

    /** 签名字段白名单固定：多塞字段不改变签名（防调用方污染规范化） */
    public function testSignatureIgnoresExtraFields(): void
    {
        $base = [
            'installation_id' => 'i', 'fingerprint' => 'f', 'license_code' => 'l',
            'site_host' => 'h', 'version' => 'v', 'users' => 1, 'posts' => 2,
            'timestamp' => 3, 'nonce' => 'n',
        ];
        $withExtra = $base + ['evil' => 'injected', 'server_ip' => '1.2.3.4'];
        $this->assertSame(
            PayloadSigner::sign($base, 'l', 'f'),
            PayloadSigner::sign($withExtra, 'l', 'f')
        );
    }

    /** H-3 守卫：gate 用 parse_url(PHP_URL_PATH) + 前缀，不再对整条 REQUEST_URI 做 strpos */
    public function testGateUsesPathPrefix(): void
    {
        $src = $this->reporterSrc();
        $this->assertTrue(str_contains($src, "parse_url((string)(\$_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)"));
        $this->assertTrue(str_contains($src, "str_starts_with(\$path, '/api/admin/')"));
        // 旧的整条 URI strpos 写法必须已从 gate 中移除
        $this->assertFalse(str_contains($src, "strpos(\$uri, '/api/admin/')"));
    }

    /** H-3 守卫：pending 态退避——retryAt 判断必须前置于 isPending */
    public function testPendingRespectsRetryBackoff(): void
    {
        $src = $this->reporterSrc();
        $retryPos = strpos($src, 'if ($retryAt > 0)');
        $pendingPos = strpos($src, 'InstallationIdentity::isPending()');
        $this->assertTrue($retryPos !== false && $pendingPos !== false);
        $this->assertTrue($retryPos < $pendingPos, 'retryAt 退避必须前置于 pending 直接放行');
        $this->assertTrue(str_contains($src, 'return $now >= $retryAt;'));
    }

    /** H-4 守卫：ssl_verify=0 默认 fail-closed，且有显式 opt-in 逃生舱 */
    public function testInsecureModeFailsClosed(): void
    {
        $src = $this->reporterSrc();
        $this->assertTrue(str_contains($src, "License::getSetting('license_report_allow_insecure', '0')"));
        $this->assertTrue(str_contains($src, 'if (!$allowInsecure)'));
        $this->assertTrue(str_contains($src, '拒绝认成功响应（fail-closed）'));
    }
}
