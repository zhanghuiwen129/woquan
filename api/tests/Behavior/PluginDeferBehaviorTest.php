<?php
/**
 * 行为级测试：Plugin::defer()/runDeferred() 语义与 ContentAudit 延迟审核链
 *
 * defer 队列是 private static，若不在 setUp/tearDown 用反射清空，会污染同进程其它套件。
 * 所有断言来自真实回调执行与数据库状态变化，不做源码文本断言。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Cache;
use Hublog\Core\Plugin;
use Hublog\Plugin\ContentAudit\Plugin as ContentAuditPlugin;
use ReflectionProperty;

final class PluginDeferBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDeferred();
        $this->resetDfa();
    }

    protected function tearDown(): void
    {
        $this->resetDeferred();
        $this->resetDfa();
        parent::tearDown();
    }

    private function resetDeferred(): void
    {
        $ref = new ReflectionProperty(Plugin::class, 'deferred');
        $ref->setAccessible(true);
        $ref->setValue(null, []);
    }

    private function deferredCount(): int
    {
        $ref = new ReflectionProperty(Plugin::class, 'deferred');
        $ref->setAccessible(true);
        return count($ref->getValue());
    }

    /** ContentAudit 的 DFA 树也是静态状态，隔离其它用例注入的自定义词 */
    private function resetDfa(): void
    {
        if (!class_exists(ContentAuditPlugin::class, false)) {
            return;
        }
        $ref = new ReflectionProperty(ContentAuditPlugin::class, 'dfaRoot');
        $ref->setAccessible(true);
        $ref->setValue(null, []);
    }

    private function loadContentAudit(): void
    {
        if (class_exists(ContentAuditPlugin::class, false)) {
            return;
        }
        require_once self::root() . '/plugins/ContentAudit/Plugin.php';
    }

    private function createAuditSchema(): void
    {
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `posts` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `title`        TEXT    DEFAULT NULL,
    `content`      TEXT    DEFAULT NULL,
    `user_id`      INTEGER NOT NULL DEFAULT 0,
    `status`       INTEGER NOT NULL DEFAULT 1,
    `audit_state`  INTEGER NOT NULL DEFAULT 0,
    `scheduled_at` TEXT    DEFAULT NULL,
    `created_at`   TEXT    DEFAULT NULL
);
CREATE TABLE `notification_prefs` (
    `user_id`     INTEGER PRIMARY KEY,
    `all_enabled` INTEGER NOT NULL DEFAULT 0,
    `system`      INTEGER NOT NULL DEFAULT 1
);
SQL);
    }

    private function postRow(int $id): array
    {
        $row = $this->pdo()->query('SELECT * FROM posts WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row, '测试动态必须存在');
        return $row;
    }

    public function testDeferredCallbacksExecuteExactlyOnce(): void
    {
        $state = ['first' => 0, 'second' => 0];
        Plugin::defer(static function () use (&$state): void {
            $state['first']++;
        });
        Plugin::defer(static function () use (&$state): void {
            $state['second'] += 2;
        });

        $this->assertSame(2, $this->deferredCount());
        Plugin::runDeferred();

        $this->assertSame(['first' => 1, 'second' => 2], $state);
        $this->assertSame(0, $this->deferredCount(), 'runDeferred 必须清空队列');

        Plugin::runDeferred();
        $this->assertSame(['first' => 1, 'second' => 2], $state, '第二次 runDeferred 不得重复执行');
    }

    public function testThrowingCallbackDoesNotBlockFollowingCallbacks(): void
    {
        $state = ['after' => 0];
        Plugin::defer(static function (): void {
            throw new \RuntimeException('deferred boom');
        });
        Plugin::defer(static function () use (&$state): void {
            $state['after']++;
        });

        Plugin::runDeferred();

        $this->assertSame(1, $state['after'], '前一个回调抛异常不能吞掉后续回调');
        $this->assertSame(0, $this->deferredCount(), '异常回调也必须从队列移除');
    }

    public function testCallbackCanDeferAgainWithoutRecursiveExecution(): void
    {
        $state = ['first' => 0, 'second' => 0];
        Plugin::defer(static function () use (&$state): void {
            $state['first']++;
            Plugin::defer(static function () use (&$state): void {
                $state['second']++;
            });
        });

        Plugin::runDeferred();
        $this->assertSame(1, $state['first']);
        $this->assertSame(0, $state['second'], '回调内新登记的任务不得在本轮递归执行');
        $this->assertSame(1, $this->deferredCount(), '新任务应留给下一轮');

        Plugin::runDeferred();
        $this->assertSame(1, $state['second']);
        $this->assertSame(0, $this->deferredCount());
    }

    public function testContentAuditAiLayerDefersPassUntilAfterResponse(): void
    {
        $this->createAuditSchema();
        $this->loadContentAudit();
        $this->seed('posts', [
            'id' => 901, 'title' => '正常动态', 'content' => '今天天气不错，出去走走',
            'user_id' => 1, 'status' => 1, 'audit_state' => 1,
            'scheduled_at' => '2030-01-01 00:00:00',
        ]);
        $this->seed('settings', ['key' => 'ai_audit_enabled', 'value' => '1']);
        Cache::delete('content_audit_settings');

        ContentAuditPlugin::onPostCreated(901, 1);

        $this->assertSame(1, $this->deferredCount(), 'AI 层必须登记 defer 而不是阻塞发帖响应');
        $this->assertSame(1, (int)$this->postRow(901)['audit_state'], '响应发出前内容状态不应被改写');

        Plugin::runDeferred();

        $row = $this->postRow(901);
        $this->assertSame(2, (int)$row['audit_state'], 'AI 判定合规后应从未审(1)→通过(2)');
        $this->assertSame(1, (int)$row['status'], '通过态必须恢复公开');
        $this->assertSame(0, $this->deferredCount());
    }

    public function testSensitiveWordHitMarksPendingSynchronouslyWithoutDefer(): void
    {
        $this->createAuditSchema();
        $this->loadContentAudit();
        // 关闭系统通知的 SSE 投递，避免测试污染共享临时缓存目录里的队列文件。
        $this->seed('notification_prefs', ['user_id' => 2, 'all_enabled' => 0]);
        $this->seed('posts', [
            'id' => 902, 'title' => '违规动态', 'content' => '这条内容涉及赌博推广',
            'user_id' => 2, 'status' => 1, 'audit_state' => 0,
            'scheduled_at' => '2030-01-01 00:00:00',
        ]);
        $this->seed('settings', ['key' => 'ai_audit_enabled', 'value' => '1']);
        Cache::delete('content_audit_settings');

        ContentAuditPlugin::onPostCreated(902, 2);

        $this->assertSame(0, $this->deferredCount(), '本地词表硬命中必须同步拦截，不能延后');
        $row = $this->postRow(902);
        $this->assertSame(0, (int)$row['status'], '待审内容不得公开');
        $this->assertSame(1, (int)$row['audit_state'], '命中后进入待审态(1)');
        $this->assertNull($row['scheduled_at'], '待审内容不得再定时自动发布');
    }
}
