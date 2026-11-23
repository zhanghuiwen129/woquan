<?php

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\License;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 授权 / 试用配额测试（V167）
 *
 * 重点不在「算得对不对」，而在**几张内部表之间的引用关系**。
 * 这些关系是静默的：一旦错位，代码照样跑、接口照样返回，只是某一道封顶悄悄失效了。
 *
 * 尤其是 A 条：TRIAL_QUOTA 引用了一个没在 QUOTA_CAP 里登记的 settingKey 时，
 * trialLimit() 的 `min($raw, self::QUOTA_CAP[$key] ?? $raw)` 会退化成「原样放行」，
 * 后台填多少就是多少——注释里明写的「防止用户自己填 99999 当无限」这道门就形同虚设。
 *
 * D/E 条锁的是 V145(2026-10-28) 的决策：AI 从「未授权硬禁」改为「未授权限量试用」。
 * 若哪天有人把 'ai' 加回 UNAUTH_DENY_FEATURES，它就同时是「硬禁」又是「限量试用」，
 * 语义自相矛盾，且配额代码永远不会被执行到。
 */
final class LicenseTrialQuotaTest extends TestCase
{
    /** @return array<string, mixed> 全部 private 常量 名=>值 */
    private function privateConstants(): array
    {
        $out = [];
        foreach ((new ReflectionClass(License::class))->getReflectionConstants() as $c) {
            $out[$c->getName()] = $c->getValue();
        }
        return $out;
    }

    // ==================== 跨版本不变式 ====================

    public function testEveryTrialSettingKeyHasAHardCap(): void
    {
        $pc   = $this->privateConstants();
        $diff = array_diff(array_column($pc['TRIAL_QUOTA'], 0), array_keys($pc['QUOTA_CAP']));

        $this->assertSame(
            [],
            $diff,
            '以下额度项没有配套的 QUOTA_CAP 封顶，后台可无限调高：' . implode(', ', $diff)
        );
    }

    public function testEditableQuotaKeysMatchCapKeysExactly(): void
    {
        $pc = $this->privateConstants();
        $cap = array_keys($pc['QUOTA_CAP']);

        $this->assertSame([], array_diff($cap, $pc['EDITABLE_QUOTA_KEYS']), '有封顶项没进可编辑名单');
        $this->assertSame([], array_diff($pc['EDITABLE_QUOTA_KEYS'], $cap), '可编辑名单里有不存在的额度项');
    }

    public function testDailyQuotaFeaturesAreAllTrialFeatures(): void
    {
        $pc   = $this->privateConstants();
        $diff = array_diff($pc['DAILY_QUOTA'], array_keys($pc['TRIAL_QUOTA']));

        $this->assertSame(
            [],
            $diff,
            '以下功能被标成「每日重置」却不在配额表里，reset 字段会给出错误提示：' . implode(', ', $diff)
        );
    }

    public function testHardDeniedAndTrialFeaturesNeverOverlap(): void
    {
        $pc  = $this->privateConstants();
        $bad = array_intersect($pc['UNAUTH_DENY_MODULES'], array_keys($pc['TRIAL_QUOTA']));

        $this->assertSame(
            [],
            $bad,
            '以下功能同时属于「未授权硬禁」和「限量试用」，两条规则互斥：' . implode(', ', $bad)
        );
    }

    public function testAiIsTrialQuotaNotHardDenied(): void
    {
        // V145 决策固化：AI 走「未授权限量试用」（每人每天 license_unauth_daily_ai 次），
        // 不再是一刀切 402。此前 V144（一刀切）/ V144-b（完全放开）两种做法都已被推翻过。
        $pc = $this->privateConstants();

        $this->assertArrayHasKey('ai', $pc['TRIAL_QUOTA']);
        $this->assertNotContains('ai', $pc['UNAUTH_DENY_MODULES']);
        $this->assertContains('ai', $pc['DAILY_QUOTA'], 'AI 配额应按自然日重置');
    }

    // ==================== 封顶 / 夹取 ====================

    public function testQuotaCapKnownValues(): void
    {
        $this->assertSame(50, License::quotaCap('license_unauth_daily_posts'));
        $this->assertSame(200, License::quotaCap('license_unauth_daily_messages'));
        $this->assertSame(50, License::quotaCap('license_unauth_max_conversations'));
        $this->assertSame(50, License::quotaCap('license_unauth_daily_ai'));
    }

    public function testQuotaCapUnknownKeyIsZero(): void
    {
        $this->assertSame(0, License::quotaCap('this_key_does_not_exist'));
    }

    public function testClampQuotaCapsOversizedValue(): void
    {
        $res = License::clampQuota('license_unauth_daily_ai', 99999);

        $this->assertSame(50, $res['value']);
        $this->assertTrue($res['clamped'], '超过硬上限必须标记 clamped，供后台提示用户');
    }

    public function testClampQuotaNeverGoesNegative(): void
    {
        $res = License::clampQuota('license_unauth_daily_ai', -5);

        $this->assertSame(0, $res['value']);
        $this->assertFalse($res['clamped']);
    }

    public function testClampQuotaAcceptsValidValue(): void
    {
        $res = License::clampQuota('license_unauth_daily_ai', 25);

        $this->assertSame(25, $res['value']);
        $this->assertFalse($res['clamped']);
    }

    public function testClampQuotaRejectsUnknownKey(): void
    {
        // 未知额度项一律判死为 0：宁可关掉功能，也不能让「未登记的额度」变成不受限
        $this->assertSame(['value' => 0, 'clamped' => false], License::clampQuota('nope', 10));
    }

    public function testClampQuotaHandlesGarbageInput(): void
    {
        $this->assertSame(0, License::clampQuota('license_unauth_daily_ai', 'abc')['value']);
    }

    // ==================== 签名规范化 ====================

    public function testCanonicalJsonIsOrderIndependent(): void
    {
        // 签发端（AdminLicenseController）与验签端（License::verifyLocal/verifyRemote）
        // 共用这一个函数，排序必须稳定，否则同一份载荷会算出两个不同的签名。
        $this->assertSame(
            License::canonicalJson(['a' => 1, 'b' => 2, 'c' => 3]),
            License::canonicalJson(['c' => 3, 'a' => 1, 'b' => 2])
        );
    }

    public function testCanonicalJsonKeepsUnicodeReadable(): void
    {
        $this->assertSame(
            '{"name":"张三"}',
            License::canonicalJson(['name' => '张三']),
            '中文不能被转义成 \\uXXXX，否则两端字节不一致必然验签失败'
        );
    }

    public function testCanonicalJsonNestingConventionIsStable(): void
    {
        // 【约定】嵌套数组会被序列化成 JSON 字符串而非展开。签发与验签走同一函数，
        // 所以是自洽的。这里锁住的是「结果必须稳定」——若改成展开实现，历史授权码会全部失效。
        $first  = License::canonicalJson(['data' => ['x' => 1, 'y' => 2]]);
        $second = License::canonicalJson(['data' => ['y' => 2, 'x' => 1]]);

        $this->assertSame($first, $second);
        $this->assertStringContainsString('\\"x\\"', $first, '嵌套层当前是被转义成字符串的');
    }
}
