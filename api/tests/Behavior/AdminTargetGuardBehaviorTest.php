<?php
/**
 * 后台目标可写性守卫的行为回归测试。
 *
 * 缺陷原貌：管理员可以通过 updateStatus / delete / mute / batchUsers 封禁、删除、
 * 禁言自己或另一位管理员，甚至把 id=1 的超级管理员一起批量封禁；仓储层只挡 role，
 * 不挡 id=1，也不挡自操作。本测试从真实控制器入口逐条验证 403 且零写入。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\AdminBatchController;
use Hublog\Controllers\AdminModerationController;
use Hublog\Controllers\UserController;
use Hublog\Core\Auth;
use Hublog\Core\Request;
use Hublog\Core\Response;
use PDO;

final class AdminTargetGuardBehaviorTest extends BehaviorTestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;

        $this->pdo()->exec('ALTER TABLE `users` ADD COLUMN `updated_at` TEXT DEFAULT NULL');
        $this->pdo()->exec('ALTER TABLE `users` ADD COLUMN `deleted_at` TEXT DEFAULT NULL');
        $this->pdo()->exec(
            'CREATE TABLE `posts` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `user_id` INTEGER NOT NULL,
                `post_type` TEXT DEFAULT "normal",
                `images` TEXT DEFAULT NULL
            )'
        );

        Auth::clear();
    }

    protected function tearDown(): void
    {
        Auth::clear();
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        parent::tearDown();
    }

    private function seedUser(int $id, string $role = 'user', int $status = 1): void
    {
        $this->seed('users', [
            'id' => $id, 'username' => "u{$id}", 'nickname' => "n{$id}",
            'role' => $role, 'status' => $status, 'token_version' => 1,
            'muted_until' => '2030-01-01 00:00:00',
        ]);
    }

    /** 用真实 JWT 建立当前登录管理员身份 */
    private function loginAs(int $id): void
    {
        $user = $this->pdo()->query('SELECT * FROM users WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($user, '登录用户必须存在');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Auth::generateToken($user);
        Auth::clear();
    }

    /** @return array<string,mixed> */
    private function call(string $class, string $method, array $params): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = [];
        $_POST = $params;
        Auth::clear();

        /** @var object $controller */
        $controller = new $class();
        $response = $controller->{$method}(new Request());
        $this->assertInstanceOf(Response::class, $response);
        $decoded = json_decode($response->toJson(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function userRow(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        return $row;
    }

    public function testAdminCannotBanOrDeleteOrMuteSelf(): void
    {
        $this->seedUser(2, 'admin');
        $this->loginAs(2);
        $before = $this->userRow(2);

        $this->assertSame(403, $this->call(UserController::class, 'updateStatus', ['id' => 2, 'status' => 0])['code']);
        $this->assertSame(403, $this->call(UserController::class, 'delete', ['id' => 2])['code']);
        $this->assertSame(403, $this->call(AdminModerationController::class, 'muteUser', ['user_id' => 2, 'minutes' => 10])['code']);
        $this->assertSame(403, $this->call(AdminModerationController::class, 'unmuteUser', ['user_id' => 2])['code']);
        $this->assertSame(403, $this->call(AdminBatchController::class, 'batchUsers', ['ids' => [2], 'action' => 'ban'])['code']);

        $this->assertSame($before, $this->userRow(2), '自操作必须零写入');
    }

    public function testAdminCannotTouchIdOne(): void
    {
        $this->seedUser(1, 'admin');
        $this->seedUser(2, 'admin');
        $this->loginAs(2);
        $before = $this->userRow(1);

        $this->assertSame(403, $this->call(UserController::class, 'updateStatus', ['id' => 1, 'status' => 0])['code']);
        $this->assertSame(403, $this->call(UserController::class, 'delete', ['id' => 1])['code']);
        $this->assertSame(403, $this->call(AdminModerationController::class, 'muteUser', ['user_id' => 1, 'minutes' => 10])['code']);
        $this->assertSame(403, $this->call(AdminModerationController::class, 'unmuteUser', ['user_id' => 1])['code']);
        $this->assertSame(403, $this->call(AdminBatchController::class, 'batchUsers', ['ids' => [1], 'action' => 'delete'])['code']);

        $this->assertSame($before, $this->userRow(1), 'id=1 必须零写入');
    }

    public function testAdminCannotBanOrMuteOrDeleteOtherAdmin(): void
    {
        $this->seedUser(2, 'admin');
        $this->seedUser(3, 'admin');
        $this->loginAs(2);
        $before = $this->userRow(3);

        $this->assertSame(403, $this->call(AdminBatchController::class, 'batchUsers', ['ids' => [3], 'action' => 'ban'])['code']);
        $this->assertSame(403, $this->call(AdminModerationController::class, 'muteUser', ['user_id' => 3, 'minutes' => 10])['code']);
        $this->assertSame(403, $this->call(UserController::class, 'delete', ['id' => 3])['code']);

        $this->assertSame($before, $this->userRow(3), '其他管理员不得被破坏性操作');
    }

    public function testUnbanAndUnmuteOtherAdminAllowed(): void
    {
        $this->seedUser(2, 'admin');
        $this->seedUser(3, 'admin', 0);
        $this->loginAs(2);

        $unban = $this->call(AdminBatchController::class, 'batchUsers', ['ids' => [3], 'action' => 'unban']);
        $this->assertSame(200, $unban['code'], (string)$unban['message']);
        $this->assertSame(1, (int)$this->userRow(3)['status']);

        $unmute = $this->call(AdminModerationController::class, 'unmuteUser', ['user_id' => 3]);
        $this->assertSame(200, $unmute['code'], (string)$unmute['message']);
        $this->assertNull($this->userRow(3)['muted_until']);
    }

    public function testAdminCannotDisableAnotherAdmin(): void
    {
        $this->seedUser(2, 'admin');
        $this->seedUser(3, 'admin');
        $this->loginAs(2);
        $before = $this->userRow(3);

        $result = $this->call(UserController::class, 'updateStatus', ['id' => 3, 'status' => 0]);
        $this->assertSame(403, $result['code'], (string)$result['message']);
        $this->assertSame($before, $this->userRow(3), 'status=0 等价于封禁管理员，必须零写入');
    }

    public function testAdminCanEnableAnotherAdmin(): void
    {
        $this->seedUser(2, 'admin');
        $this->seedUser(3, 'admin', 0);
        $this->loginAs(2);

        $result = $this->call(UserController::class, 'updateStatus', ['id' => 3, 'status' => 1]);
        $this->assertSame(200, $result['code'], (string)$result['message']);
        $this->assertSame(1, (int)$this->userRow(3)['status'], '解封是恢复动作，允许对管理员执行');
    }

    public function testOrdinaryUserTargetCanBeBanned(): void
    {
        $this->seedUser(2, 'admin');
        $this->seedUser(4, 'user');
        $this->loginAs(2);

        $result = $this->call(UserController::class, 'updateStatus', ['id' => 4, 'status' => 0]);
        $this->assertSame(200, $result['code'], (string)$result['message']);
        $this->assertSame(0, (int)$this->userRow(4)['status'], '普通用户仍可被正常封禁');
    }

    public function testBatchIsAllOrNothing(): void
    {
        $this->seedUser(2, 'admin');
        $this->seedUser(4, 'user');
        $this->loginAs(2);
        $before = $this->userRow(4);

        $result = $this->call(AdminBatchController::class, 'batchUsers', [
            'ids' => [4, 1], 'action' => 'ban',
        ]);
        $this->assertSame(403, $result['code']);
        $this->assertSame($before, $this->userRow(4), '批次命中受保护 ID 时必须整体拒绝，零写入');
    }
}
