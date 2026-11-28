<?php
/**
 * V165 迁移完整性守卫（真 PHP 反射）。
 *
 * 背景：以往靠 node php-parser + 若干临时 Python 脚本做静态核验（`.workbuddy/tmp/verify_*.py`），
 * 语法层面勉强够用，但「名字解析 / 版本一致性 / 编号缺口」这类语义问题查不干净，
 * 且 V159 曾发生「抽子类时漏 use 导致 Class not found」的上线险情。
 *
 * 现在本机已有真实 PHP 8.1 运行环境，改用 ReflectionClass 直接断言以下不变量：
 *   ① 类可加载 —— trait 化拆分后若出现方法重名，PHP 在类加载期就 fatal，因此「能加载」即证明无重名；
 *   ② $currentVersion 必须等于最大 migrateNN（否则升级永远跑不到最后一版）；
 *   ③ 所有 migrateNN 必须是 private static（防止被误当成可路由的 controller 方法暴露出去）；
 *   ④ 编号缺口必须是「已知且已登记」的白名单 —— 新出现的缺口意味着有人误删了迁移方法，必须显式确认。
 *
 * 说明：run() 内部用 method_exists() 跳过不存在的编号，所以缺口本身不会影响升级（版本号照常推进）。
 * 但正因为它是静默的，才更需要一个显式白名单来兜住「误删」这种情况。
 *
 * 该测试不连接数据库。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MigrationIntegrityTest extends TestCase
{
    /**
     * 已知的迁移编号缺口白名单统一放在 MigrationContract::INTENTIONAL_GAPS：
     * run() 的 applyMigration() 与这里的断言共用一份，避免「测试放行但生产抛异常」。
     */

    private function migrationClass(): ReflectionClass
    {
        $class = \Hublog\Core\Migration::class;
        // 能走到这一步就说明 trait 拆分没有致命的方法冲突
        $this->assertTrue(class_exists($class), 'Migration 类必须可加载（trait 重名会导致 fatal）');
        return new ReflectionClass($class);
    }

    /** @return array<int,int> 现有 migrateNN 的编号列表（升序） */
    private function existingNumbers(ReflectionClass $r): array
    {
        $nums = [];
        foreach ($r->getMethods() as $m) {
            if (preg_match('/^migrate(\d+)$/', $m->getName(), $mm)) {
                $nums[] = (int)$mm[1];
            }
        }
        $nums = array_values(array_unique($nums));
        sort($nums);
        return $nums;
    }

    private function currentVersion(ReflectionClass $r): int
    {
        $p = $r->getProperty('currentVersion');
        $p->setAccessible(true);
        return (int)$p->getValue();
    }

    /**
     * ① 类可加载 = 无 trait 方法冲突。
     * PHP 的规则：多个 trait 提供同名方法且未显式解决冲突时，会在类加载期抛出 fatal error，
     * 因此本断言实际是在守护「 MigrationBatchA/B/C1~C5 + MigrationHistory 之间不得有重名方法」。
     */
    public function testClassLoadsWithoutTraitConflict(): void
    {
        $r = $this->migrationClass();

        // 断言四个 trait 都被引入（防止重构时漏掉某一个批次，导致该批次迁移整体丢失）
        $traits = array_keys($r->getTraits());
        foreach (['MigrationHistory', 'MigrationBatchA', 'MigrationBatchB', 'MigrationBatchC1', 'MigrationBatchC2', 'MigrationBatchC3', 'MigrationBatchC4', 'MigrationBatchC5'] as $short) {
            $this->assertContains(
                'Hublog\\Core\\' . $short,
                $traits,
                "Migration 必须引入 trait {$short}，否则对应批次的迁移方法会整体缺失"
            );
        }
    }

    /** ② 版本号必须与最大 migrateNN 一致 */
    public function testCurrentVersionMatchesMaxMigration(): void
    {
        $r = $this->migrationClass();
        $nums = $this->existingNumbers($r);
        $this->assertNotEmpty($nums, '至少应存在一个 migrate 方法');

        $max = max($nums);
        $version = $this->currentVersion($r);

        $this->assertSame(
            $max,
            $version,
            "currentVersion({$version}) 必须等于最大 migrate 编号({$max})，"
            . '否则高于 currentVersion 的迁移永远不会被 run() 执行'
        );
    }

    /** ③ 所有 migrateNN 必须是 private static */
    public function testAllMigrationsArePrivateStatic(): void
    {
        $r = $this->migrationClass();
        $bad = [];
        foreach ($r->getMethods() as $m) {
            if (!preg_match('/^migrate(\d+)$/', $m->getName())) {
                continue;
            }
            if (!$m->isStatic() || !$m->isPrivate()) {
                $bad[] = $m->getName()
                    . ' (' . implode(',', Reflection::getModifierNames($m->getModifiers())) . ')';
            }
        }
        $this->assertEmpty(
            $bad,
            "所有 migrateNN 必须是 private static（防止被外部/路由直接调用）:\n" . implode("\n", $bad)
        );
    }

    /**
     * ④ 编号缺口必须全部落在已知白名单内。
     */
    public function testNoUnexpectedMigrationNumberGaps(): void
    {
        $r = $this->migrationClass();
        $nums = $this->existingNumbers($r);
        $max = max($nums);

        $missing = array_values(array_diff(range(1, $max), $nums));
        sort($missing);
        $expected = \Hublog\Core\MigrationContract::INTENTIONAL_GAPS;
        sort($expected);

        $this->assertSame(
            $expected,
            $missing,
            "迁移编号出现未登记的缺口。\n"
            . '现有缺口: ' . (empty($missing) ? '(无)' : implode(',', $missing)) . "\n"
            . '已登记缺口: ' . (empty($expected) ? '(无)' : implode(',', $expected)) . "\n"
            . '若确属故意跳号，请更新本测试 KNOWN_MISSING 并在提交信息里说明原因；'
            . '若是重构误删，请先恢复对应的 migrate 方法。'
        );
    }

    /** 关键公共入口必须保持现有的公开签名，避免调用方崩掉 */
    public function testPublicEntryPointsKeepSignature(): void
    {
        $r = $this->migrationClass();

        $run = $r->getMethod('run');
        $this->assertTrue($run->isPublic(), 'run() 必须保持 public');
        $this->assertTrue($run->isStatic(), 'run() 必须保持 static');
        $this->assertSame(0, $run->getNumberOfParameters(), 'run() 必须保持无参数');

        foreach (['buildFullSchema', 'now', 'log'] as $name) {
            $this->assertTrue(
                $r->hasMethod($name),
                "核心方法 {$name} 必须保留在主类中（各迁移经 self:: 调用）"
            );
        }
    }
}
