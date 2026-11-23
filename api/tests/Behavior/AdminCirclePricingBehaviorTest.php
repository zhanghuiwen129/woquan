<?php
/**
 * 后台定价「假开关」P0 缺陷的行为回归测试。
 *
 * 缺陷原貌：后台 circles.html 提交了 pay_type / pay_vip_level / pay_amount，
 * 但 AdminCircleController::saveCircle() 只接收 9 个字段、CircleRepository::saveAdminCircle()
 * 也不写这三列 —— 于是「保存成功」但数据库价格纹丝不动。
 *
 * 本测试走真实链路：构造 Request -> AdminCircleController::saveCircle()
 * -> CircleRepository::saveAdminCircle() -> SQLite 内存库，直接断言 circles 表落库结果。
 * 与仓库其它 Behavior 测试一致，用 BehaviorTestCase 注入的 :memory: PDO 做 mock，
 * 不连生产/测试 MySQL，也不会有跨用例状态残留。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\AdminCircleController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use PDO;

/**
 * 仅绕过 requireAdmin() 的身份校验，其余保存逻辑与线上控制器完全一致。
 * requireAdmin 在 BaseController 中是 protected，这里以同可见性覆盖。
 */
final class StubAdminCircleController extends AdminCircleController
{
    protected function requireAdmin(Request $request): array
    {
        return ['id' => 1, 'role' => 'admin', 'username' => 'ops'];
    }
}

final class AdminCirclePricingBehaviorTest extends BehaviorTestCase
{
    private array $serverBackup = [];
    private array $postBackup = [];
    private array $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 复用 CircleRepositoryBehaviorTest 的最小 circles schema（含 V67 付费三列）
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `circles` (
    `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`            TEXT    DEFAULT NULL,
    `slug`            TEXT    DEFAULT NULL,
    `description`     TEXT    DEFAULT NULL,
    `cover`           TEXT    DEFAULT NULL,
    `owner_id`        INTEGER NOT NULL DEFAULT 0,
    `member_count`    INTEGER NOT NULL DEFAULT 0,
    `post_count`      INTEGER NOT NULL DEFAULT 0,
    `join_mode`       TEXT    DEFAULT 'free',
    `post_permission` TEXT    DEFAULT 'all',
    `visibility`      TEXT    DEFAULT 'public',
    `created_at`      TEXT    DEFAULT NULL,
    `updated_at`      TEXT    DEFAULT NULL,
    `status`          INTEGER NOT NULL DEFAULT 1,
    `pay_type`        INTEGER NOT NULL DEFAULT 0,
    `pay_vip_level`   INTEGER NOT NULL DEFAULT 0,
    `pay_amount`      REAL    NOT NULL DEFAULT 0,
    `pay_days`        INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `circle_members` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id`   INTEGER NOT NULL,
    `role`      TEXT    DEFAULT 'member',
    `joined_at` TEXT    DEFAULT NULL
);
SQL);

        // saveCircle() 会校验圈主必须是有效用户
        $this->seed('users', ['id' => 1, 'username' => 'owner', 'role' => 'user']);
        $this->seed('circles', [
            'id' => 10,
            'name' => '付费圈',
            'slug' => 'pay-circle',
            'owner_id' => 1,
            'status' => 1,
            'pay_type' => 0,
            'pay_vip_level' => 0,
            'pay_amount' => 0,
        ]);

        $this->serverBackup = $_SERVER;
        $this->postBackup = $_POST;
        $this->getBackup = $_GET;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_POST = $this->postBackup;
        $_GET = $this->getBackup;
        parent::tearDown();
    }

    /** 模拟后台 POST /admin/circles；$id=0 走新建（INSERT），$id>0 走编辑（UPDATE） */
    private function saveAsAdmin(array $payFields = [], int $id = 10): Response
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/admin/circles';
        $_GET = [];
        $_POST = array_merge([
            'id' => $id,
            'name' => '付费圈',
            'description' => '描述',
            'cover' => '',
            'owner_id' => 1,
            'status' => 1,
            'join_mode' => 'free',
            'post_permission' => 'all',
            'visibility' => 'public',
        ], $payFields);

        return (new StubAdminCircleController())->saveCircle(new Request());
    }

    private function circleRow(): array
    {
        $row = $this->pdo()->query('SELECT * FROM `circles` WHERE `id` = 10')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, '测试圈子应存在');
        return $row;
    }

    public function testAdminSaveWritesPaidSubscriptionPriceToDatabase(): void
    {
        $this->assertSame(0, (int)$this->circleRow()['pay_type'], '初始应为免费圈');

        $response = $this->saveAsAdmin([
            'pay_type' => 2,
            'pay_vip_level' => 3,
            'pay_amount' => '99',
        ]);

        $this->assertSame(200, $response->getCode(), '合法定价应保存成功');
        $row = $this->circleRow();
        $this->assertSame(2, (int)$row['pay_type'], 'pay_type=2 必须真正落库');
        $this->assertSame(99.0, (float)$row['pay_amount'], 'pay_amount=99 必须真正落库');
        $this->assertSame(0, (int)$row['pay_vip_level'], '订阅模式不应残留会员等级门槛');
    }

    public function testAdminSaveOverwritesExistingPrice(): void
    {
        $this->assertSame(200, $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '99'])->getCode());

        $response = $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '128.50']);

        $this->assertSame(200, $response->getCode());
        $row = $this->circleRow();
        $this->assertSame(2, (int)$row['pay_type']);
        $this->assertSame(128.5, (float)$row['pay_amount'], '再次保存应覆盖旧价格，而不是只写一次');
    }

    public function testAdminSaveWritesAndValidatesPayDays(): void
    {
        $response = $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '99', 'pay_days' => '30']);
        $this->assertSame(200, $response->getCode());
        $this->assertSame(30, (int)$this->circleRow()['pay_days'], '单圈订阅天数必须落库');

        $this->assertSame(200, $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '99', 'pay_days' => '0'])->getCode());
        $this->assertSame(0, (int)$this->circleRow()['pay_days'], '0=跟随全局');

        $before = $this->circleRow();
        $this->assertSame(400, $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '99', 'pay_days' => '3651'])->getCode());
        $this->assertSame(400, $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '99', 'pay_days' => '-1'])->getCode());
        $this->assertSame($before, $this->circleRow(), '非法天数不得改动数据库');
    }

    public function testAdminSaveRejectsNegativePriceWithoutWritingToDatabase(): void
    {
        $this->assertSame(200, $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '99'])->getCode());
        $before = $this->circleRow();

        $response = $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '-1']);

        $this->assertSame(400, $response->getCode(), '负数价格必须被服务端拒绝');
        $this->assertSame($before, $this->circleRow(), '非法价格不得改动数据库任何字段');
    }

    public function testAdminSaveRejectsNonNumericAndOversizedPrice(): void
    {
        $this->assertSame(200, $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '66'])->getCode());
        $before = $this->circleRow();

        $this->assertSame(400, $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => 'abc'])->getCode());
        $this->assertSame(400, $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '9999999'])->getCode());
        $this->assertSame($before, $this->circleRow(), '非数字/超大值均不得落库');
    }

    public function testAdminSaveWritesVipThresholdAndClearsStalePrice(): void
    {
        $this->assertSame(200, $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '99'])->getCode());

        $response = $this->saveAsAdmin(['pay_type' => 1, 'pay_vip_level' => '3', 'pay_amount' => '99']);

        $this->assertSame(200, $response->getCode());
        $row = $this->circleRow();
        $this->assertSame(1, (int)$row['pay_type']);
        $this->assertSame(3, (int)$row['pay_vip_level'], '会员等级门槛须落库');
        $this->assertSame(0.0, (float)$row['pay_amount'], '切到会员门槛后订阅价格应清零');
    }

    public function testAdminSaveSkipsPayFieldsWhenNotSubmitted(): void
    {
        $this->assertSame(200, $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '77'])->getCode());
        $before = $this->circleRow();

        // 不提交任何 pay_* 字段：保持原定价（兼容只改名字/状态的旧调用方）
        $response = $this->saveAsAdmin();

        $this->assertSame(200, $response->getCode());
        $after = $this->circleRow();
        $this->assertSame((int)$before['pay_type'], (int)$after['pay_type']);
        $this->assertSame((float)$before['pay_amount'], (float)$after['pay_amount']);
    }

    private function circleRowByName(string $name): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM `circles` WHERE `name` = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "新建圈子 {$name} 应落库");
        return $row;
    }

    /** id=0 走 saveAdminCircle() 的 INSERT 分支：付费订阅价必须随新建一并落库 */
    public function testAdminCreateWritesPaidSubscriptionPriceOnInsertPath(): void
    {
        $response = $this->saveAsAdmin([
            'name' => '新建付费圈',
            'pay_type' => 2,
            'pay_vip_level' => 3,
            'pay_amount' => '55.5',
        ], 0);

        $this->assertSame(200, $response->getCode(), '新建付费圈应保存成功');
        $row = $this->circleRowByName('新建付费圈');
        $this->assertGreaterThan(10, (int)$row['id'], '应生成新圈子，而不是覆盖 id=10 的既有圈');
        $this->assertSame(2, (int)$row['pay_type'], 'INSERT 路径必须写入 pay_type');
        $this->assertSame(55.5, (float)$row['pay_amount'], 'INSERT 路径必须写入 pay_amount');
        $this->assertSame(0, (int)$row['pay_vip_level'], '订阅模式不应残留会员等级门槛');
    }

    /** INSERT 路径的会员等级门槛：pay_vip_level 落库且订阅价清零 */
    public function testAdminCreateWritesVipThresholdOnInsertPath(): void
    {
        $response = $this->saveAsAdmin([
            'name' => '新建会员圈',
            'pay_type' => 1,
            'pay_vip_level' => '2',
            'pay_amount' => '88',
        ], 0);

        $this->assertSame(200, $response->getCode());
        $row = $this->circleRowByName('新建会员圈');
        $this->assertSame(1, (int)$row['pay_type']);
        $this->assertSame(2, (int)$row['pay_vip_level'], 'INSERT 路径必须写入 pay_vip_level');
        $this->assertSame(0.0, (float)$row['pay_amount'], '会员门槛不应残留订阅价');
    }

    /** 付费订阅切回免费：两个门槛字段都要清零 */
    public function testAdminSwitchingBackToFreeClearsBothPayFields(): void
    {
        $this->assertSame(200, $this->saveAsAdmin([
            'pay_type' => 2, 'pay_vip_level' => 2, 'pay_amount' => '99',
        ])->getCode());

        $response = $this->saveAsAdmin([
            'pay_type' => 0, 'pay_vip_level' => '3', 'pay_amount' => '99',
        ]);

        $this->assertSame(200, $response->getCode());
        $row = $this->circleRow();
        $this->assertSame(0, (int)$row['pay_type']);
        $this->assertSame(0, (int)$row['pay_vip_level'], '切回免费后等级门槛须清零');
        $this->assertSame(0.0, (float)$row['pay_amount'], '切回免费后订阅价须清零');
    }

    /** 显式传字符串 '0' 是合法价格，应覆盖旧价；与「未提交 pay_* 保持原值」区分开 */
    public function testAdminExplicitZeroPriceOverridesOldPrice(): void
    {
        $this->assertSame(200, $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '99'])->getCode());

        $response = $this->saveAsAdmin(['pay_type' => 2, 'pay_amount' => '0']);

        $this->assertSame(200, $response->getCode());
        $row = $this->circleRow();
        $this->assertSame(2, (int)$row['pay_type']);
        $this->assertSame(0.0, (float)$row['pay_amount'], "显式传 '0' 应作为有效价格覆盖旧价");
    }
}
