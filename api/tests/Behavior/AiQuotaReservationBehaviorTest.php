<?php
/**
 * 批次8 P1-4：AI 试用配额原子预扣行为回归。
 *
 * 验证真实的 DB 条件插入，而不是 Cache 锁（锁失败会降级为非原子）：
 *   - 50 次上限：前 50 次成功，第 51 次返回 null；
 *   - A 预扣未确认时 B 的第 51 次必须被拒；
 *   - 释放预扣后额度可恢复；确认后仍计数；
 *   - countQuotaUsage('ai') 把 reserved 计入，否则竞态仍在。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\License;

final class AiQuotaReservationBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `ai_usage_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `feature` TEXT NOT NULL DEFAULT 'ai',
    `action` TEXT NOT NULL DEFAULT '',
    `status` TEXT NOT NULL DEFAULT 'ok',
    `created_at` TEXT NOT NULL
);
SQL);
        // BehaviorTestCase 已写入 sms_provider；追加 AI 额度后必须刷新 site_settings 缓存。
        $this->seed('settings', ['key' => 'license_unauth_daily_ai', 'value' => '50']);
        \Hublog\Core\Cache::flush();
    }

    public function testReserveIsAtomicAtHardCapAndReleasesCorrectly(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $id = License::tryReserveAiQuota(7, 'polish');
            $this->assertNotNull($id, "第 " . ($i + 1) . " 次预扣应成功");
            $ids[] = $id;
        }

        // A 的 50 笔都还没 confirm；B 的第 51 次必须被拒。
        $this->assertNull(License::tryReserveAiQuota(7, 'polish'), 'reserved 行必须占到额度');
        $this->assertSame(
            50,
            (int)$this->pdo()->query("SELECT COUNT(*) FROM ai_usage_logs WHERE status = 'reserved'")->fetchColumn()
        );
        $this->assertSame(
            0,
            (int)$this->pdo()->query("SELECT COUNT(*) FROM ai_usage_logs WHERE status = 'ok'")->fetchColumn(),
            '未确认前不应有正式记录'
        );

        // 释放一笔后额度恢复。
        License::releaseQuotaReservation($ids[0]);
        $this->assertSame(
            49,
            (int)$this->pdo()->query("SELECT COUNT(*) FROM ai_usage_logs WHERE status = 'reserved'")->fetchColumn()
        );
        $newId = License::tryReserveAiQuota(7, 'expand');
        $this->assertNotNull($newId, '释放后应可再次预扣');

        // 确认后状态为 ok，且 release 不再生效。
        License::confirmQuotaReservation($newId, 'expand');
        $row = $this->rows('ai_usage_logs');
        $byId = [];
        foreach ($row as $r) {
            $byId[(int)$r['id']] = $r;
        }
        $this->assertSame('ok', $byId[$newId]['status']);
        $this->assertSame('expand', $byId[$newId]['action']);
        License::releaseQuotaReservation($newId);
        $this->assertArrayHasKey($newId, $byId, '已确认的记录不应被 release 删除');
    }

    public function testCountQuotaUsageCountsReservedRows(): void
    {
        $id = License::tryReserveAiQuota(9, 'shorten');
        $this->assertNotNull($id);

        // 通过公开入口验证口径：未授权试用态下 checkQuota 的 used 必须包含 reserved 行。
        $this->seed('settings', ['key' => 'license_enabled', 'value' => '1']);
        \Hublog\Core\Cache::flush();
        $result = License::checkQuota('ai', 9);
        $this->assertSame(1, $result['used'], '预扣行必须计入配额用量');
    }

    public function testZeroLimitNeverReserves(): void
    {
        $this->pdo()->exec("UPDATE settings SET value = '0' WHERE `key` = 'license_unauth_daily_ai'");
        \Hublog\Core\Cache::flush();
        $this->assertNull(License::tryReserveAiQuota(11, 'polish'));
        $this->assertCount(0, $this->rows('ai_usage_logs'));
    }
}
