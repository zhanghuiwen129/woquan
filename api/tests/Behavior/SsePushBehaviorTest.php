<?php
/**
 * 行为级测试：SSE 文件队列的 last_event_id 续传语义与通知偏好拦截
 *
 * 全部断言来自真实调用 SsePush::push()/poll() 后观察到的事件序列，
 * 不做任何源码文本断言。缓存目录由 BehaviorTestCase 指向临时目录，
 * 每个用例开始/结束时清理 sse_user_*.json，避免跨用例污染。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Cache;
use Hublog\Core\SsePush;

final class SsePushBehaviorTest extends BehaviorTestCase
{
    private const USER_ID = 7101;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `notification_prefs` (
    `user_id`      INTEGER PRIMARY KEY,
    `all_enabled`  INTEGER NOT NULL DEFAULT 1,
    `post_like`    INTEGER NOT NULL DEFAULT 1,
    `post_comment` INTEGER NOT NULL DEFAULT 1,
    `follow`       INTEGER NOT NULL DEFAULT 1,
    `circle_post`  INTEGER NOT NULL DEFAULT 1,
    `system`       INTEGER NOT NULL DEFAULT 1
);
SQL);
        $this->purgeQueues();
    }

    protected function tearDown(): void
    {
        $this->purgeQueues();
        parent::tearDown();
    }

    /** 只清理本测试底座专用临时缓存目录里的 SSE 队列文件 */
    private function purgeQueues(): void
    {
        foreach (glob(Cache::getCacheDir() . 'sse_user_*.json') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function testPushThenPollReturnsEveryEventWithIncreasingIds(): void
    {
        SsePush::push(self::USER_ID, 'like', ['post_id' => 11]);
        SsePush::push(self::USER_ID, 'comment', ['post_id' => 11, 'comment_id' => 12]);
        SsePush::push(self::USER_ID, 'system', ['message' => 'hello']);

        $events = SsePush::poll(self::USER_ID);

        $this->assertCount(3, $events, 'push 3 条后 poll 必须返回全部 3 条');
        $this->assertSame([1, 2, 3], array_column($events, 'id'), '事件 ID 必须自增');
        $this->assertSame(['like', 'comment', 'system'], array_column($events, 'event'));
        $this->assertSame(['post_id' => 11], $events[0]['data']);
        $this->assertSame(['post_id' => 11, 'comment_id' => 12], $events[1]['data']);
        $this->assertIsInt($events[0]['timestamp']);
        $this->assertGreaterThan(0, $events[0]['timestamp']);
    }

    public function testPollWithLastEventIdResumesStrictlyAfterThatId(): void
    {
        SsePush::push(self::USER_ID, 'like', ['n' => 1]);
        SsePush::push(self::USER_ID, 'comment', ['n' => 2]);
        SsePush::push(self::USER_ID, 'system', ['n' => 3]);

        $resumed = SsePush::poll(self::USER_ID, 2);
        $this->assertCount(1, $resumed, 'last_event_id=2 只应返回 id>2 的事件');
        $this->assertSame(3, $resumed[0]['id']);
        $this->assertSame('system', $resumed[0]['event']);
        $this->assertSame([], SsePush::poll(self::USER_ID, 3), '游标已在队尾时不得返回任何事件');

        SsePush::push(self::USER_ID, 'follow', ['n' => 4]);
        $next = SsePush::poll(self::USER_ID, 3);
        $this->assertCount(1, $next, '续传后新事件必须仍能被同一游标取到');
        $this->assertSame(4, $next[0]['id']);
        $this->assertSame('follow', $next[0]['event']);

        $this->assertSame([], SsePush::poll(self::USER_ID + 1, 0), '不同用户的队列必须隔离');
    }

    public function testMissingEmptyAndCorruptQueueAllReturnEmptyArray(): void
    {
        $this->assertSame([], SsePush::poll(self::USER_ID), '不存在的事件文件应返回空数组');

        $file = Cache::getCacheDir() . 'sse_user_' . self::USER_ID . '.json';
        file_put_contents($file, '');
        $this->assertSame([], SsePush::poll(self::USER_ID), '空文件应返回空数组');

        file_put_contents($file, '{broken-json');
        $this->assertSame([], SsePush::poll(self::USER_ID), '损坏的 JSON 应返回空数组而不是致命错');

        unlink($file);
        SsePush::push(self::USER_ID, 'system', ['message' => 'first']);
        $this->assertSame([1], array_column(SsePush::poll(self::USER_ID), 'id'),
            '清空队列后新事件应从 id=1 重新开始');
    }

    public function testNotificationPrefsFilterMappedTypesBeforeEnqueue(): void
    {
        $blockedAll = 7202;
        $this->seed('notification_prefs', [
            'user_id' => $blockedAll, 'all_enabled' => 0, 'post_like' => 0,
        ]);
        SsePush::push($blockedAll, 'notification', ['type' => 'like', 'post_id' => 1]);
        $this->assertSame([], SsePush::poll($blockedAll), 'all_enabled=0 应拦截 like→post_like');
        SsePush::push($blockedAll, 'notification', ['type' => 'call_invite']);
        $this->assertCount(1, SsePush::poll($blockedAll), '未映射类型（通话邀请）必须默认放行');

        $blockedType = 7203;
        $this->seed('notification_prefs', [
            'user_id' => $blockedType, 'all_enabled' => 1, 'post_like' => 0,
        ]);
        SsePush::push($blockedType, 'notification', ['type' => 'comment', 'post_id' => 2]);
        SsePush::push($blockedType, 'notification', ['type' => 'like', 'post_id' => 2]);
        $events = SsePush::poll($blockedType);
        $this->assertCount(1, $events, 'post_like=0 只拦 like，不应误伤 post_comment');
        $this->assertSame('comment', $events[0]['data']['type']);

        $noPrefs = 7204;
        SsePush::push($noPrefs, 'notification', ['type' => 'like', 'post_id' => 3]);
        $this->assertCount(1, SsePush::poll($noPrefs), '没有偏好行时按默认开启放行');
    }

    public function testQueueTrimsToLatestHundredEventsWithoutResettingIds(): void
    {
        $userId = 7301;
        for ($i = 1; $i <= 105; $i++) {
            SsePush::push($userId, 'system', ['n' => $i]);
        }

        $events = SsePush::poll($userId);
        $this->assertCount(100, $events, '队列最多保留 100 条');
        $this->assertSame(6, $events[0]['id']);
        $this->assertSame(105, $events[count($events) - 1]['id'], '裁剪不得重置 ID 序号');
        $this->assertSame(105, $events[count($events) - 1]['data']['n'], '保留的是最新 100 条');
    }
}
