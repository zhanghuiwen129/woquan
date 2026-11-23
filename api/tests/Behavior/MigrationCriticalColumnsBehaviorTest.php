<?php
/**
 * 迁移契约精确哨兵行为回归（批次 7 / P1-1）
 *
 * REQUIRED_COLUMN_NAMES 是「全库任意表有同名列即放行」，post_type 早在 content_*
 * 四表存在，因此即使 posts.post_type 的 DDL 被 catch 吞掉，列名维度依旧满分。
 * 本测试钉死 V58/V59 动态插值列（扫描器只取 SQL 字符串字面量，抓不到）必须
 * 以精确「表.列」哨兵登记，并验证 diff() 真的会把缺失的 posts.post_type 报出来。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\MigrationContract;

final class MigrationCriticalColumnsBehaviorTest extends BehaviorTestCase
{
    /** @return list<array{0:string,1:string}> */
    private function requiredSentinels(): array
    {
        return [
            ['users', 'phone'],
            ['users', 'phone_verified_at'],
            ['users', 'gender'],
            ['users', 'birthday'],
            ['users', 'region'],
            ['users', 'user_tags'],
            ['users', 'privacy_home'],
            ['users', 'allow_search'],
            ['users', 'allow_mention'],
            ['users', 'allow_stranger_msg'],
            ['users', 'hide_online'],
            ['users', 'last_active_at'],
            ['users', 'font_scale'],
            ['users', 'msg_sound'],
            ['conversations', 'member_count'],
            ['posts', 'post_type'],
        ];
    }

    public function testAllDynamicColumnSentinelsAreRegistered(): void
    {
        $registered = [];
        foreach (MigrationContract::CRITICAL_COLUMNS as [$table, $column]) {
            $registered[] = $table . '.' . $column;
        }
        foreach ($this->requiredSentinels() as [$table, $column]) {
            $this->assertContains(
                $table . '.' . $column,
                $registered,
                "{$table}.{$column} 必须由手工哨兵登记，扫描器抓不到变量插值列名"
            );
        }
    }

    public function testMissingPostsPostTypeIsReportedEvenWithSameNameElsewhere(): void
    {
        $tables = array_fill_keys(MigrationContract::REQUIRED_TABLES, true);
        // 全库列名维度齐备，且 post_type 在 content_* 四表存在 —— 这正是旧实现的盲区。
        $columnNames = array_fill_keys(MigrationContract::REQUIRED_COLUMN_NAMES, true);
        $columnNames['post_type'] = true;

        $columnKeys = [];
        foreach (MigrationContract::CRITICAL_COLUMNS as [$table, $column]) {
            if ($table === 'posts' && $column === 'post_type') {
                continue;
            }
            $columnKeys[$table . '.' . $column] = true;
        }
        $indexes = [];
        foreach (MigrationContract::REQUIRED_UNIQUE_INDEXES as $spec) {
            $indexes[$spec['table'] . '.' . $spec['index']] = true;
        }

        $diff = MigrationContract::diff($tables, $columnNames, $columnKeys, $indexes);

        $this->assertSame([], $diff['tables']);
        $this->assertSame([], $diff['columnNames'], '同名列存在时，通用列名维度应照旧通过');
        $this->assertContains('posts.post_type', $diff['columns'], '精确哨兵必须报出 posts.post_type 缺失');
        $this->assertFalse(MigrationContract::isComplete($diff), '关键列缺失时终态自检不得判为完整');
    }
}
