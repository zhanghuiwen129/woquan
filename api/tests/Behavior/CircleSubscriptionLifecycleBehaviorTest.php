<?php
/**
 * V96 付费圈子订阅周期与到期生命周期行为测试
 *
 * 覆盖：订阅三态判定、信息流 / 详情 / 圈子内容三处读时门禁、
 * 懒清理幂等与角色安全、提醒先占后发幂等、老数据 expire_at=NULL 兼容。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\CircleController;
use Hublog\Controllers\PaymentController;
use Hublog\Controllers\PostCrudTrait;
use Hublog\Core\CircleSubscriptionService;
use Hublog\Core\PostRepository;
use PDO;
use ReflectionClass;

/** 暴露 PostCrudTrait 的两个私有可见性方法供行为断言（不改动生产代码） */
final class PostVisibilityProbe
{
    use PostCrudTrait;

    /** @return array{0:string,1:array} */
    public function visibilityWhere(int $userId): array
    {
        return $this->buildVisibilityWhere($userId);
    }

    public function canView(array $post, int $userId): bool
    {
        return $this->canViewPost($post, $userId);
    }
}

final class CircleSubscriptionLifecycleBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `circles` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` TEXT DEFAULT NULL,
    `owner_id` INTEGER NOT NULL DEFAULT 0,
    `pay_type` INTEGER NOT NULL DEFAULT 0,
    `pay_amount` REAL NOT NULL DEFAULT 0,
    `pay_days` INTEGER NOT NULL DEFAULT 0,
    `pay_vip_level` INTEGER NOT NULL DEFAULT 0,
    `post_review` INTEGER NOT NULL DEFAULT 0,
    `visibility` TEXT NOT NULL DEFAULT 'public',
    `post_permission` TEXT NOT NULL DEFAULT 'all',
    `limited` INTEGER NOT NULL DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1,
    `member_count` INTEGER NOT NULL DEFAULT 0,
    `removed_at` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `circle_members` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `role` TEXT NOT NULL DEFAULT 'member',
    `status` INTEGER NOT NULL DEFAULT 1,
    `joined_at` TEXT DEFAULT NULL
);
CREATE TABLE `circle_subscribes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `order_no` TEXT DEFAULT NULL,
    `amount` REAL NOT NULL DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1,
    `expire_at` TEXT DEFAULT NULL,
    `notified_before_at` TEXT DEFAULT NULL,
    `notified_expire_at` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `circle_id` INTEGER DEFAULT NULL,
    `visibility` TEXT NOT NULL DEFAULT 'public',
    `status` INTEGER NOT NULL DEFAULT 1,
    `content` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `follows` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `follower_id` INTEGER NOT NULL,
    `following_id` INTEGER NOT NULL
);
CREATE TABLE `notifications` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `sender_id` INTEGER NOT NULL,
    `type` TEXT NOT NULL DEFAULT 'comment',
    `post_id` INTEGER DEFAULT NULL,
    `comment_id` INTEGER DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `is_read` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
SQL);
    }

    // ==================== 三态判定 ====================

    public function testSubscriptionStateCoversPayTypesRolesAndNullExpiry(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '免费圈', 'pay_type' => 0, 'owner_id' => 100]);
        $this->seed('circles', ['id' => 2, 'name' => '等级圈', 'pay_type' => 1, 'owner_id' => 100]);
        $this->seed('circles', ['id' => 3, 'name' => '付费圈', 'pay_type' => 2, 'owner_id' => 100]);

        $this->assertSame(CircleSubscriptionService::STATE_EXEMPT, CircleSubscriptionService::subscriptionState(9, 1));
        $this->assertSame(CircleSubscriptionService::STATE_EXEMPT, CircleSubscriptionService::subscriptionState(9, 2));
        $this->assertSame(CircleSubscriptionService::STATE_EXEMPT, CircleSubscriptionService::subscriptionState(100, 3), '圈主免订阅');

        $this->seed('circle_members', ['circle_id' => 3, 'user_id' => 9, 'role' => 'admin', 'status' => 1]);
        $this->assertSame(CircleSubscriptionService::STATE_EXEMPT, CircleSubscriptionService::subscriptionState(9, 3), '圈管免订阅');

        $this->assertSame(CircleSubscriptionService::STATE_NONE, CircleSubscriptionService::subscriptionState(10, 3), '无订阅');

        $this->seed('circle_subscribes', [
            'circle_id' => 3, 'user_id' => 10, 'status' => 1,
            'expire_at' => date('Y-m-d H:i:s', time() + 7 * 86400),
        ]);
        $this->assertSame(CircleSubscriptionService::STATE_ACTIVE, CircleSubscriptionService::subscriptionState(10, 3));

        $this->pdo()->exec("UPDATE circle_subscribes SET status = 0 WHERE circle_id = 3 AND user_id = 10");
        $this->assertSame(CircleSubscriptionService::STATE_NONE, CircleSubscriptionService::subscriptionState(10, 3));

        $this->pdo()->exec("UPDATE circle_subscribes SET status = 1, expire_at = '2020-01-01 00:00:00' WHERE circle_id = 3 AND user_id = 10");
        $this->assertSame(CircleSubscriptionService::STATE_EXPIRED, CircleSubscriptionService::subscriptionState(10, 3));

        // 老数据 expire_at=NULL 视为长期有效
        $this->pdo()->exec("UPDATE circle_subscribes SET expire_at = NULL WHERE circle_id = 3 AND user_id = 10");
        $this->assertSame(CircleSubscriptionService::STATE_ACTIVE, CircleSubscriptionService::subscriptionState(10, 3));
        $this->assertTrue(CircleSubscriptionService::canAccessCircleContent(10, 3));
        $this->assertTrue(CircleSubscriptionService::isSubscriptionActive(10, 3));
    }

    // ==================== 读时门禁 ====================

    public function testExpiredSubscriptionBlockedInTimelineDetailAndCircleContent(): void
    {
        $this->seed('circles', ['id' => 10, 'name' => '付费圈', 'pay_type' => 2, 'owner_id' => 100, 'visibility' => 'public']);
        $this->seed('circles', ['id' => 11, 'name' => '免费圈', 'pay_type' => 0, 'owner_id' => 100, 'visibility' => 'public']);
        $this->seed('circle_members', ['circle_id' => 10, 'user_id' => 10, 'role' => 'member', 'status' => 1]);
        $this->seed('circle_members', ['circle_id' => 11, 'user_id' => 10, 'role' => 'member', 'status' => 1]);
        $this->seed('circle_subscribes', [
            'circle_id' => 10, 'user_id' => 10, 'status' => 1, 'expire_at' => '2020-01-01 00:00:00',
        ]);
        $this->seed('posts', ['id' => 1, 'user_id' => 200, 'circle_id' => 10, 'visibility' => 'circle', 'status' => 1]);
        $this->seed('posts', ['id' => 2, 'user_id' => 200, 'circle_id' => 11, 'visibility' => 'circle', 'status' => 1]);

        $probe = new PostVisibilityProbe();

        // ① 全站信息流 SQL：只返回免费圈帖子（过期圈的帖子被 paid-aware 子查询过滤）
        [$where, $params] = $probe->visibilityWhere(10);
        $stmt = $this->pdo()->prepare("SELECT p.id FROM posts p WHERE p.status = 1 AND {$where} ORDER BY p.id");
        $stmt->execute($params);
        $this->assertSame(['2'], array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), '信息流不得泄露过期付费圈正文');

        // ② 帖子详情
        $paidPost = ['user_id' => 200, 'visibility' => 'circle', 'circle_id' => 10];
        $freePost = ['user_id' => 200, 'visibility' => 'circle', 'circle_id' => 11];
        $this->assertFalse($probe->canView($paidPost, 10), '过期用户看不到付费圈帖子详情');
        $this->assertTrue($probe->canView($freePost, 10), '免费圈帖子不受影响');

        // ③ 圈子内容门禁（public 也不能绕过）
        $this->assertSame(403, $this->gateCode(10, 10));

        // 续费后三处立刻放行（不依赖任何 cron）；门禁触发的懒清理会把 status 置 0，需一并恢复
        $future = date('Y-m-d H:i:s', time() + 30 * 86400);
        $this->pdo()->exec("UPDATE circle_subscribes SET status = 1, expire_at = '{$future}' WHERE circle_id = 10 AND user_id = 10");
        [$where, $params] = $probe->visibilityWhere(10);
        $stmt = $this->pdo()->prepare("SELECT p.id FROM posts p WHERE p.status = 1 AND {$where} ORDER BY p.id");
        $stmt->execute($params);
        $this->assertSame(['1', '2'], array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertTrue($probe->canView($paidPost, 10));
        $this->assertNull($this->gate(10, 10));
    }

    public function testPrivatePaidCircleGateHidesExistence(): void
    {
        $this->seed('circles', [
            'id' => 12, 'name' => '私密付费圈', 'pay_type' => 2, 'owner_id' => 100, 'visibility' => 'private',
        ]);
        $response = $this->gate(12, 10);
        $this->assertNotNull($response);
        $this->assertSame(404, $response->getCode());
        $data = json_decode($response->toJson(), true);
        $this->assertSame('圈子不存在', $data['message'] ?? '');
    }

    public function testPostingGateReadsPayTypeFromFindCircleForPost(): void
    {
        $this->seed('circles', [
            'id' => 40, 'name' => '发帖付费圈', 'pay_type' => 2, 'owner_id' => 100,
            'visibility' => 'public', 'post_permission' => 'all', 'limited' => 0,
        ]);
        $circle = PostRepository::findCircleForPost(40);
        $this->assertSame(2, (int)$circle['pay_type'], '发帖门禁拿到的圈子行必须带 pay_type，否则付费校验形同虚设');

        // 未订阅 / 到期用户发帖必须被拦，文案与 App 现有提示保持一致
        [$allowed, $reason] = PaymentController::circlePostAllowed(40, $circle, 10);
        $this->assertFalse($allowed);
        $this->assertSame('该圈子为付费圈子，订阅后才能发言', $reason);

        $this->seed('circle_subscribes', [
            'circle_id' => 40, 'user_id' => 10, 'status' => 1, 'expire_at' => '2020-01-01 00:00:00',
        ]);
        [$allowed, $reason] = PaymentController::circlePostAllowed(40, $circle, 10);
        $this->assertFalse($allowed);
        $this->assertSame('订阅已到期，续费后即可继续发言', $reason);

        $future = date('Y-m-d H:i:s', time() + 30 * 86400);
        $this->pdo()->exec("UPDATE circle_subscribes SET expire_at = '{$future}' WHERE circle_id = 40 AND user_id = 10");
        [$allowed, $reason] = PaymentController::circlePostAllowed(40, $circle, 10);
        $this->assertTrue($allowed, '续费后恢复发帖');
        $this->assertSame('', $reason);
    }

    // ==================== 清退 / 提醒幂等 ====================

    public function testLazyCleanupIsIdempotentAndNeverRemovesOwnersOrAdmins(): void
    {
        $this->pdo()->exec("INSERT INTO settings (`key`, `value`) VALUES ('circle_sub_auto_cleanup', '1')");
        $this->seed('circles', ['id' => 20, 'name' => '清退圈', 'pay_type' => 2, 'owner_id' => 100, 'member_count' => 3]);
        foreach ([[100, 'owner'], [101, 'admin'], [102, 'member']] as [$uid, $role]) {
            $this->seed('circle_members', ['circle_id' => 20, 'user_id' => $uid, 'role' => $role, 'status' => 1]);
        }
        $this->seed('circle_subscribes', [
            'circle_id' => 20, 'user_id' => 102, 'status' => 1, 'expire_at' => '2020-01-01 00:00:00',
            'notified_before_at' => '2020-01-01 00:00:00', 'notified_expire_at' => '2020-01-08 00:00:00',
        ]);

        $this->assertTrue(CircleSubscriptionService::lazyCleanup(20, 102));
        $this->assertSame(2, (int)$this->pdo()->query("SELECT member_count FROM circles WHERE id = 20")->fetchColumn());
        $this->assertSame(0, (int)$this->pdo()->query("SELECT status FROM circle_subscribes WHERE circle_id = 20 AND user_id = 102")->fetchColumn());

        $roles = $this->pdo()->query("SELECT user_id, role FROM circle_members WHERE circle_id = 20 ORDER BY user_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame([100 => 'owner', 101 => 'admin'], $roles, '只删普通成员，圈主与圈管保留');

        $this->assertFalse(CircleSubscriptionService::lazyCleanup(20, 102), '重复调用零副作用');
        $this->assertSame(2, (int)$this->pdo()->query("SELECT member_count FROM circles WHERE id = 20")->fetchColumn(), 'member_count 不重复自减');
    }

    public function testRemindersAreClaimedOnceAndResetOnRenew(): void
    {
        $this->pdo()->exec("INSERT INTO settings (`key`, `value`) VALUES ('circle_expire_remind_days', '7')");
        $this->seed('circles', ['id' => 30, 'name' => '提醒圈', 'pay_type' => 2, 'owner_id' => 100]);
        $expire = date('Y-m-d H:i:s', time() + 3 * 86400);
        $this->seed('circle_subscribes', [
            'circle_id' => 30, 'user_id' => 200, 'status' => 1, 'expire_at' => $expire,
        ]);

        $first = CircleSubscriptionService::sendReminders(7, 100);
        $this->assertSame(1, $first['reminded']);
        $this->assertSame(0, $first['failed']);
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM notifications")->fetchColumn());
        $notice = $this->pdo()->query("SELECT type, content, sender_id FROM notifications")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('system', $notice['type']);
        $this->assertStringContainsString('提醒圈', (string)$notice['content']);
        $this->assertGreaterThan(0, (int)$notice['sender_id']);

        $second = CircleSubscriptionService::sendReminders(7, 100);
        $this->assertSame(0, $second['reminded'], '同一周期只提醒一次');
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM notifications")->fetchColumn());

        // 关闭开关后不再提醒
        $this->pdo()->exec("UPDATE settings SET `value` = '0' WHERE `key` = 'circle_expire_remind_days'");
        $this->assertSame(0, CircleSubscriptionService::sendReminders(null, 100)['reminded']);
    }

    // ==================== 辅助 ====================

    private function gate(int $circleId, int $userId): ?\Hublog\Core\Response
    {
        $controller = (new ReflectionClass(CircleController::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(CircleController::class))->getMethod('ensureViewable');
        $method->setAccessible(true);
        return $method->invoke($controller, $circleId, $userId);
    }

    private function gateCode(int $circleId, int $userId): int
    {
        $response = $this->gate($circleId, $userId);
        return $response === null ? 200 : $response->getCode();
    }
}
