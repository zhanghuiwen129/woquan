<?php

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\ContentPayRepository as Repo;
use Hublog\Core\ContentPayService;

/**
 * V97 内容付费墙行为测试：建表/购买事务/权益判定/试读截断。
 */
final class ContentPayBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ContentPayService::clearCache(); // 静态请求缓存跨用例残留，须重置
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `wallet_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL, `amount` REAL NOT NULL,
    `type` TEXT NOT NULL, `biz_type` TEXT, `biz_id` INTEGER, `biz_key` TEXT,
    `balance_after` REAL, `remark` TEXT, `created_at` TEXT
);
CREATE UNIQUE INDEX `uk_wallet_biz_key` ON `wallet_logs` (`biz_key`);
CREATE TABLE `points_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL, `points` INTEGER NOT NULL,
    `type` TEXT, `biz_type` TEXT, `biz_id` INTEGER, `balance_after` INTEGER, `remark` TEXT, `created_at` TEXT
);
CREATE TABLE `posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL, `content` TEXT,
    `post_type` TEXT DEFAULT 'normal', `status` INTEGER DEFAULT 1
);
CREATE TABLE `content_products` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `product_type` TEXT NOT NULL, `target_id` INTEGER NOT NULL,
    `post_type` TEXT DEFAULT 'normal', `price` REAL DEFAULT 0, `preview_ratio` INTEGER DEFAULT 30,
    `valid_days` INTEGER DEFAULT 0, `status` INTEGER DEFAULT 1, `sold_count` INTEGER DEFAULT 0,
    `created_at` TEXT, `updated_at` TEXT
);
CREATE UNIQUE INDEX `uk_product` ON `content_products` (`product_type`,`target_id`);
CREATE TABLE `content_columns` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `owner_id` INTEGER NOT NULL, `title` TEXT,
    `summary` TEXT DEFAULT '', `cover` TEXT DEFAULT '', `valid_days` INTEGER DEFAULT 365,
    `item_count` INTEGER DEFAULT 0, `status` INTEGER DEFAULT 1, `created_at` TEXT, `updated_at` TEXT
);
CREATE TABLE `content_column_items` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `column_id` INTEGER NOT NULL, `post_id` INTEGER NOT NULL,
    `post_type` TEXT DEFAULT 'normal', `sort_order` INTEGER DEFAULT 0, `created_at` TEXT
);
CREATE UNIQUE INDEX `uk_column_item` ON `content_column_items` (`column_id`,`post_id`);
CREATE TABLE `content_orders` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `order_no` TEXT NOT NULL, `user_id` INTEGER NOT NULL,
    `product_type` TEXT NOT NULL, `target_id` INTEGER NOT NULL, `post_type` TEXT DEFAULT '',
    `amount` REAL NOT NULL, `points_used` INTEGER DEFAULT 0, `discount` REAL DEFAULT 0,
    `pay_amount` REAL DEFAULT 0, `status` TEXT DEFAULT 'paid', `trade_no` TEXT,
    `paid_at` TEXT, `created_at` TEXT
);
CREATE UNIQUE INDEX `uk_order_no` ON `content_orders` (`order_no`);
CREATE UNIQUE INDEX `uk_trade_no` ON `content_orders` (`trade_no`);
CREATE TABLE `content_entitlements` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL, `product_type` TEXT NOT NULL,
    `target_id` INTEGER NOT NULL, `post_type` TEXT DEFAULT 'normal', `source` TEXT DEFAULT 'purchase',
    `order_no` TEXT DEFAULT '', `expire_at` TEXT, `created_at` TEXT, `updated_at` TEXT
);
CREATE UNIQUE INDEX `uk_entitlement` ON `content_entitlements` (`user_id`,`product_type`,`target_id`);
SQL);
    }

    public function testBuyPostDeductsBalanceWritesOrderEntitlementAndWalletLog(): void
    {
        $this->seed('users', ['id' => 10, 'balance' => 100.0, 'points' => 0]);
        $this->seed('posts', ['id' => 100, 'user_id' => 1, 'content' => str_repeat('正文', 100), 'post_type' => 'normal', 'status' => 1]);
        $this->seed('content_products', ['product_type' => 'post', 'target_id' => 100, 'post_type' => 'normal', 'price' => 20.0, 'preview_ratio' => 30, 'status' => 1]);

        $result = Repo::buy(10, 'post', 100, 'normal', 20.0, 0, 0, 20.0, 'CT-TEST-1', 0, 80.0, 0, '', '解锁单篇');

        $this->assertNull($result['expire_at'], '单篇买断=永久');
        $this->assertSame(80.0, (float)$this->pdo()->query('SELECT balance FROM users WHERE id = 10')->fetchColumn(), '余额已扣');
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM content_orders WHERE order_no = 'CT-TEST-1'")->fetchColumn(), '订单已写');
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM content_entitlements WHERE user_id = 10 AND product_type = 'post' AND target_id = 100")->fetchColumn(), '权益已写');
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM wallet_logs WHERE biz_key = 'content:CT-TEST-1'")->fetchColumn(), '钱包流水幂等键');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT sold_count FROM content_products WHERE target_id = 100')->fetchColumn(), '销量+1');
    }

    public function testBuyWithPointsUsesConditionalDeductAndLogsPoints(): void
    {
        $this->seed('users', ['id' => 11, 'balance' => 100.0, 'points' => 100]);
        $this->seed('posts', ['id' => 101, 'user_id' => 1, 'content' => 'x', 'post_type' => 'normal', 'status' => 1]);
        $this->seed('content_products', ['product_type' => 'post', 'target_id' => 101, 'price' => 20.0, 'preview_ratio' => 30, 'status' => 1]);

        // 用 50 积分（10:1 = 抵 ¥5），实付 ¥15
        Repo::buy(11, 'post', 101, 'normal', 20.0, 50, 5, 15.0, 'CT-TEST-2', 50, 85.0, 0, '抵扣', '解锁');

        $this->assertSame(50, (int)$this->pdo()->query('SELECT points FROM users WHERE id = 11')->fetchColumn(), '积分已扣');
        $this->assertSame(85.0, (float)$this->pdo()->query('SELECT balance FROM users WHERE id = 11')->fetchColumn(), '余额按折后扣');
        $this->assertSame(-50, (int)$this->pdo()->query('SELECT points FROM points_logs WHERE user_id = 11')->fetchColumn(), '积分流水已记');
    }

    public function testColumnSubscriptionSetsExpireAndRenewExtends(): void
    {
        $this->seed('users', ['id' => 12, 'balance' => 500.0, 'points' => 0]);
        $this->seed('content_columns', ['id' => 5, 'owner_id' => 1, 'title' => '专栏', 'valid_days' => 30, 'status' => 1]);
        $this->seed('content_products', ['product_type' => 'column', 'target_id' => 5, 'price' => 50.0, 'valid_days' => 30, 'status' => 1]);

        $r1 = Repo::buy(12, 'column', 5, 'normal', 50.0, 0, 0, 50.0, 'CT-COL-1', 0, 450.0, 30, '', '订阅');
        $this->assertNotNull($r1['expire_at'], '专栏订阅有到期');
        $first = $this->pdo()->query("SELECT expire_at FROM content_entitlements WHERE user_id = 12 AND product_type = 'column' AND target_id = 5")->fetchColumn();
        $this->assertSame($r1['expire_at'], $first);

        // 续费顺延：在原到期基础上 +30 天
        $r2 = Repo::buy(12, 'column', 5, 'normal', 50.0, 0, 0, 50.0, 'CT-COL-2', 0, 400.0, 30, '', '续费');
        $this->assertGreaterThan(strtotime($r1['expire_at']), strtotime($r2['expire_at']), '续费顺延');
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM content_entitlements WHERE user_id = 12 AND product_type = 'column' AND target_id = 5")->fetchColumn(), '续费不新增权益行');
    }

    public function testDuplicatePurchaseRollsBackNoExtraCharge(): void
    {
        $this->seed('users', ['id' => 13, 'balance' => 100.0, 'points' => 0]);
        $this->seed('posts', ['id' => 102, 'user_id' => 1, 'content' => 'x', 'post_type' => 'normal', 'status' => 1]);
        $this->seed('content_products', ['product_type' => 'post', 'target_id' => 102, 'price' => 30.0, 'status' => 1]);

        Repo::buy(13, 'post', 102, 'normal', 30.0, 0, 0, 30.0, 'CT-DUP-1', 0, 70.0, 0, '', '首次');
        try {
            Repo::buy(13, 'post', 102, 'normal', 30.0, 0, 0, 30.0, 'CT-DUP-2', 0, 40.0, 0, '', '重复');
            $this->fail('重复购买应被唯一键拦截');
        } catch (\Throwable $e) {
            // 唯一约束抛错 → 事务回滚
        }
        $this->assertSame(70.0, (float)$this->pdo()->query('SELECT balance FROM users WHERE id = 13')->fetchColumn(), '余额只扣一次');
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM content_orders WHERE user_id = 13")->fetchColumn(), '订单只写一条');
    }

    public function testServiceResolveLocksUnpaidAndAllowsAuthor(): void
    {
        $this->seed('posts', ['id' => 200, 'user_id' => 7, 'content' => '正文内容', 'post_type' => 'normal', 'status' => 1]);
        $this->seed('content_products', ['product_type' => 'post', 'target_id' => 200, 'price' => 10.0, 'preview_ratio' => 30, 'status' => 1]);
        $post = $this->pdo()->query('SELECT * FROM posts WHERE id = 200')->fetch(\PDO::FETCH_ASSOC);

        $r = ContentPayService::resolve($post, 0);
        $this->assertTrue($r['locked'], '游客锁定');
        $this->assertSame('post', $r['product_type']);
        $this->assertSame(10.0, $r['price']);

        $rAuthor = ContentPayService::resolve($post, 7);
        $this->assertFalse($rAuthor['locked'], '作者本人放行');

        // 解锁后放行
        $this->seed('content_entitlements', ['user_id' => 9, 'product_type' => 'post', 'target_id' => 200, 'post_type' => 'normal', 'source' => 'purchase', 'order_no' => 'X', 'expire_at' => null]);
        $rOwned = ContentPayService::resolve($post, 9);
        $this->assertFalse($rOwned['locked'], '已解锁放行');
    }

    public function testPreviewTruncatesAndHandlesBoundaries(): void
    {
        $text = implode("\n", array_map(static fn($i) => '第' . $i . '行内容', range(1, 20)));
        $cut = ContentPayService::preview($text, 30);
        $this->assertStringContainsString('剩余内容需付费解锁', $cut);
        $this->assertLessThan(mb_strlen($text), mb_strlen($cut), '试读短于全文');
        $this->assertSame('', ContentPayService::preview($text, 0), '0=不试读');
        $this->assertSame($text, ContentPayService::preview($text, 100), '100=全文');
    }

    public function testScrubFormattedClearsMediaAndAddsPaywall(): void
    {
        $this->seed('posts', ['id' => 201, 'user_id' => 8, 'content' => '高级正文内容需要付费', 'post_type' => 'normal', 'status' => 1]);
        $this->seed('content_products', ['product_type' => 'post', 'target_id' => 201, 'price' => 15.0, 'preview_ratio' => 50, 'status' => 1]);
        $post = $this->pdo()->query('SELECT * FROM posts WHERE id = 201')->fetch(\PDO::FETCH_ASSOC);

        $formatted = [
            'content' => '高级正文内容需要付费',
            'images' => ['http://x/a.png'], 'videos' => ['http://x/v.mp4'], 'attachments' => ['http://x/a.pdf'],
        ];
        ContentPayService::scrubFormatted($formatted, $post, 0);
        $this->assertTrue($formatted['paywall']['locked']);
        $this->assertStringContainsString('剩余内容需付费解锁', $formatted['content']);
        $this->assertSame([], $formatted['images']);
        $this->assertSame([], $formatted['videos']);
        $this->assertSame([], $formatted['attachments']);
    }

    public function testScrubSingleClearsTypePostExtras(): void
    {
        $this->seed('posts', ['id' => 202, 'user_id' => 8, 'content' => '正文', 'post_type' => 'question', 'status' => 1]);
        $this->seed('content_products', ['product_type' => 'post', 'target_id' => 202, 'price' => 15.0, 'preview_ratio' => 50, 'status' => 1]);
        $post = $this->pdo()->query('SELECT * FROM posts WHERE id = 202')->fetch(\PDO::FETCH_ASSOC);

        $formatted = [
            'paywall' => ['locked' => true],
            'question' => ['answer' => '机密答案'], 'question_answers' => [['content' => '答案']],
            'team' => ['members' => []], 'checkin' => ['logs' => []], 'poll' => ['options' => []],
        ];
        ContentPayService::scrubSingle($formatted, $post, 0);
        $this->assertArrayNotHasKey('question', $formatted, '类型帖正文被移除');
        $this->assertArrayNotHasKey('team', $formatted);
        $this->assertArrayNotHasKey('checkin', $formatted);
        $this->assertArrayNotHasKey('poll', $formatted);
        $this->assertSame([], $formatted['question_answers']);
    }
}
