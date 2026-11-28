<?php
/**
 * 行为级测试：统一异步入口 AsyncTask（第二批 AI 队列化改造）
 *
 * 验证从 Plugin::defer() 迁移到持久化 JobQueue 后的关键性质：
 *   a) defer 成功入队 → jobs 表多一条 plugin_task/pending，dedupe_key 生效
 *   b) 入队失败（jobs 表不可用导致抛异常）→ 回退 Plugin::defer 且回调可执行
 *   c) JobQueue::runPending 能执行 plugin_task 并标记 done；未知 handler 被拒且不致命
 *   d) 白名单防 RCE：system / "类::方法" 等非法 handler 一律返回 false
 *   e)【安全返工】内容审核第三层故意走 Plugin::defer（不入队、不依赖 cron），
 *      回调确实执行 runFromPayload；AiComment/SmartSummary 仍走 AsyncTask 队列
 *
 * 底座沿用 BehaviorTestCase 的 SQLite 内存库。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\AsyncTask;
use Hublog\Core\JobQueue;
use Hublog\Core\Plugin;
use Hublog\Plugin\ContentAudit\Plugin as ContentAuditPlugin;
use ReflectionProperty;

final class AsyncTaskBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pdo = $this->pdo();
        $pdo->exec('DROP TABLE IF EXISTS `jobs`');
        $pdo->exec(<<<'SQL'
        CREATE TABLE `jobs` (
            `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
            `type`        TEXT    NOT NULL,
            `status`      TEXT    NOT NULL DEFAULT 'pending',
            `payload`     TEXT    DEFAULT NULL,
            `dedupe_key`  TEXT    NOT NULL DEFAULT '',
            `attempts`    INTEGER NOT NULL DEFAULT 0,
            `last_error`  TEXT    DEFAULT NULL,
            `run_after`   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            `started_at`  TEXT    DEFAULT NULL,
            `finished_at` TEXT    DEFAULT NULL,
            `priority`    INTEGER NOT NULL DEFAULT 0,
            `created_at`  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            `updated_at`  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE (`dedupe_key`)
        );
        SQL);
        // 适配入口会读 posts（空表即可走到「无需处理」分支）
        $pdo->exec(<<<'SQL'
        CREATE TABLE `posts` (
            `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
            `title`     TEXT    DEFAULT NULL,
            `content`   TEXT    DEFAULT NULL,
            `user_id`   INTEGER NOT NULL DEFAULT 0,
            `status`    INTEGER NOT NULL DEFAULT 1,
            `summary`   TEXT    DEFAULT NULL,
            `embedding` TEXT    DEFAULT NULL,
            `audit_state` INTEGER NOT NULL DEFAULT 0,
            `created_at` TEXT   DEFAULT NULL
        );
        SQL);

        $this->setDeferred([]);
        $this->loadPlugins();
    }

    protected function tearDown(): void
    {
        $this->setDeferred([]);
        parent::tearDown();
    }

    /** 生产由 api/index.php 的 Plugin::loadAll() require 插件文件；测试里显式加载 */
    private function loadPlugins(): void
    {
        foreach (['AiComment', 'SmartSummary', 'ContentAudit', 'SmartSearch'] as $name) {
            $class = 'Hublog\\Plugin\\' . $name . '\\Plugin';
            if (class_exists($class, false)) {
                continue;
            }
            $file = self::root() . '/plugins/' . $name . '/Plugin.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }

    private function setDeferred(array $tasks): void
    {
        $ref = new ReflectionProperty(Plugin::class, 'deferred');
        $ref->setAccessible(true);
        $ref->setValue(null, $tasks);
    }

    private function deferredCount(): int
    {
        $ref = new ReflectionProperty(Plugin::class, 'deferred');
        $ref->setAccessible(true);
        return count($ref->getValue());
    }

    // ── a) 入队 + 幂等 ────────────────────────────────────────────────────

    public function testDeferEnqueuesPluginTaskPendingWithDedupe(): void
    {
        $this->pdo()->exec('CREATE TABLE `comments` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER, `is_ai` INTEGER DEFAULT 0)');
        $this->seed('users', ['id' => 9, 'username' => 'ai_assistant', 'status' => 1]);
        foreach ([
            'ai_comment_enabled' => '1',
            'ai_comment_api_url' => 'https://api.example.test/v1',
            'ai_comment_api_key' => 'sk-LEAK-ME',
            'ai_comment_max_length' => '200',
        ] as $key => $value) {
            $this->seed('settings', ['key' => $key, 'value' => $value]);
        }
        \Hublog\Core\AiService::clearConfigCache();

        // 走真实插件路径：入队前由插件剔除敏感键，worker 再现场取回。
        \Hublog\Plugin\AiComment\Plugin::onPostCreated(7, 1);

        $this->assertSame(0, $this->deferredCount(), '入队成功不应再登记 defer');
        $rows = $this->rows('jobs');
        $this->assertCount(1, $rows);
        $this->assertSame('plugin_task', $rows[0]['type']);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame('ai_comment_post_7', $rows[0]['dedupe_key']);

        $stored = json_decode((string)$rows[0]['payload'], true);
        $this->assertSame('ai_comment', $stored['handler'], 'handler 必须写进 payload');
        $this->assertSame(7, $stored['post_id']);
        $this->assertSame(9, $stored['ai_user_id']);
        // P1-2：API Key 等敏感键不得明文落进 jobs.payload，worker 会现场取回。
        $this->assertArrayNotHasKey('ai_comment_api_key', $stored['cfg']);
        $this->assertSame('200', $stored['cfg']['ai_comment_max_length']);
        $this->assertSame('https://api.example.test/v1', $stored['cfg']['ai_comment_api_url']);

        // 同键再入队一次：只有一条任务
        \Hublog\Plugin\AiComment\Plugin::onPostCreated(7, 1);
        $this->assertCount(1, $this->rows('jobs'), '同 dedupe_key 只能有一条任务');
    }

    public function testSmartSummaryEnqueueStripsApiKey(): void
    {
        foreach ([
            'smart_summary_enabled' => '1',
            'smart_summary_api_url' => 'https://api.example.test/v1',
            'smart_summary_api_key' => 'sk-SUMMARY-LEAK',
        ] as $key => $value) {
            $this->seed('settings', ['key' => $key, 'value' => $value]);
        }
        \Hublog\Core\Cache::delete('smart_summary_settings');

        \Hublog\Plugin\SmartSummary\Plugin::onPostCreated(77, 1);

        $rows = $this->rows('jobs');
        $this->assertCount(1, $rows);
        $stored = json_decode((string)$rows[0]['payload'], true);
        $this->assertArrayNotHasKey('smart_summary_api_key', $stored['cfg']);
        $this->assertSame('https://api.example.test/v1', $stored['cfg']['smart_summary_api_url']);
    }

    // ── b) 入队失败回退 ───────────────────────────────────────────────────

    public function testDeferFallsBackToPluginDeferWhenEnqueueFails(): void
    {
        // jobs 表不存在 → JobQueue::enqueue 的 fetchOne 抛异常 → AsyncTask 捕获后回退
        $this->pdo()->exec('DROP TABLE IF EXISTS `jobs`');
        $before = $this->deferredCount();

        AsyncTask::defer('ai_comment', ['post_id' => 7, 'ai_user_id' => 9, 'cfg' => []], 'ai_comment_post_7');

        $this->assertSame($before + 1, $this->deferredCount(), '入队失败应回退登记 defer');

        // 回调可执行：runDeferred 会清空并执行（CLI 无 fastcgi_finish_request，不会提前 flush）
        Plugin::runDeferred();
        $this->assertSame(0, $this->deferredCount(), 'runDeferred 应清空并执行回退回调，且不抛致命错');
    }

    // ── c) worker 执行 + 未知 handler ─────────────────────────────────────

    public function testRunPendingExecutesPluginTaskAndMarksDone(): void
    {
        AsyncTask::defer('ai_comment', ['post_id' => 999, 'ai_user_id' => 9, 'cfg' => []], 'ai_comment_post_999');
        $this->assertCount(1, $this->rows('jobs'));

        $result = JobQueue::runPending();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['succeeded'], '合法 handler 应被成功执行');
        $this->assertSame(0, $result['failed']);
        $this->assertSame('done', $this->rows('jobs')[0]['status'], '任务应标记 done');
    }

    public function testUnknownHandlerIsRejectedWithoutFatal(): void
    {
        $this->assertFalse(AsyncTask::runHandler('no_such_handler', []));

        JobQueue::enqueue(JobQueue::TYPE_PLUGIN_TASK, ['handler' => 'no_such_handler'], 'k:unknown-handler');
        $result = JobQueue::runPending();

        $this->assertSame(0, $result['succeeded']);
        $this->assertSame(1, $result['failed'], '未知 handler 应标记 failed 而不是抛致命错');
        $row = $this->rows('jobs')[0];
        $this->assertSame('failed', $row['status']);
        $this->assertNotEmpty($row['last_error'], '失败任务必须保留错误摘要');
    }

    public function testDisabledOrUnloadedPluginReturnsFalse(): void
    {
        // 插件被禁用时 api/index.php 不 require 其文件 → class_exists 为 false。
        // 用隔离子进程模拟「未加载任何插件类」的生产 worker 场景。
        $result = $this->runIsolated('async_task_unloaded.php');
        $this->assertFalse($result['ok'], '插件类未加载时 runHandler 应返回 false 且不致命');
    }

    // ── d) 白名单防 RCE ───────────────────────────────────────────────────

    public function testWhitelistRejectsArbitraryClassMethod(): void
    {
        $this->assertFalse(AsyncTask::runHandler('system', ['cmd' => 'echo pwned']));
        $this->assertFalse(AsyncTask::runHandler('\\Some\\Class::method', []));
        $this->assertFalse(AsyncTask::runHandler(JobQueue::class . '::purge', []));
        $this->assertCount(0, $this->rows('jobs'), '非法 handler 不应产生任何队列副作用');
    }

    // ── e) 安全返工：内容审核第三层 defer 保底 ────────────────────────────

    public function testContentAuditThirdLayerUsesDeferNotQueue(): void
    {
        // 静态护栏：第三层必须走 Plugin::defer，绝不能退回 AsyncTask::defer。
        $src = (string)@file_get_contents(self::root() . '/plugins/ContentAudit/Plugin.php');
        $this->assertStringContainsString('PluginManager::defer(', $src, '第三层应走 Plugin::defer');
        $this->assertStringNotContainsString('AsyncTask::defer(', $src, '第三层不得入 jobs 队列');

        // 行为断言：触发 post_created → 登记 defer、不写 jobs；回调执行后真的放行。
        $this->seed('posts', [
            'id'          => 501,
            'title'       => '',
            'content'     => '今天天气不错，出去走走',
            'user_id'     => 1,
            'status'      => 1,
            'audit_state' => 1, // 此前被审核拦下，待 AI 判定放行
        ]);
        $this->pdo()->exec("INSERT INTO `settings` (`key`, `value`) VALUES ('ai_audit_enabled', '1')");

        ContentAuditPlugin::onPostCreated(501, 1);

        $this->assertCount(0, $this->rows('jobs'), '安全审核第三层不得依赖 cron 队列');
        $this->assertSame(1, $this->deferredCount(), '第三层应登记 Plugin::defer（同进程必执行）');

        Plugin::runDeferred();

        $this->assertSame(0, $this->deferredCount());
        $posts = $this->rows('posts');
        $this->assertSame(2, (int)$posts[0]['audit_state'],
            'defer 回调必须真的执行 runFromPayload → markAsPassed');
    }
}
