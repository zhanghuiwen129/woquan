<?php
/**
 * T01 入群鉴权绕过 · 行为回归测试。
 *
 * 缺陷原貌：joinGroup() 只查圈子存在与黑名单，任何登录用户持 circle_id
 * 即可建群并入群；addGroupMember() 对已存在成员还会把群主/管理员降级为 member。
 * 本测试走真实 CircleController::joinGroup() -> CircleRepository -> SQLite 内存库，
 * 覆盖付费门槛、成员状态、join_mode、黑名单、幂等与角色保持。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\CircleController;
use Hublog\Core\Auth;
use Hublog\Core\Database;
use Hublog\Core\Request;
use Hublog\Core\Response;
use PDO;
use ReflectionProperty;

final class CircleGroupEntryGuardBehaviorTest extends BehaviorTestCase
{
    private ?CircleGroupCompatPdo $db = null;
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new CircleGroupCompatPdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec(self::SCHEMA);
        $this->db = $pdo;

        // 产品代码走 Database::getInstance()，必须换成同一条连接
        $ref = new ReflectionProperty(Database::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $pdo);

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
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

    private const SCHEMA = <<<'SQL'
CREATE TABLE `users` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `username` TEXT DEFAULT NULL,
    `nickname` TEXT DEFAULT NULL,
    `avatar` TEXT DEFAULT NULL,
    `cover` TEXT DEFAULT NULL,
    `email` TEXT DEFAULT NULL,
    `bio` TEXT DEFAULT NULL,
    `role` TEXT DEFAULT 'user',
    `status` INTEGER NOT NULL DEFAULT 1,
    `token_version` INTEGER NOT NULL DEFAULT 1,
    `created_at` TEXT DEFAULT NULL,
    `vip_level` INTEGER NOT NULL DEFAULT 0,
    `vip_expire_at` TEXT DEFAULT NULL,
    `balance` REAL NOT NULL DEFAULT 0,
    `points` INTEGER NOT NULL DEFAULT 0,
    `avatar_frame` TEXT DEFAULT NULL,
    `muted_until` TEXT DEFAULT NULL,
    `realname_verified` INTEGER NOT NULL DEFAULT 0,
    `realname_verified_at` TEXT DEFAULT NULL
);
CREATE TABLE `circles` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` TEXT DEFAULT NULL,
    `cover` TEXT DEFAULT NULL,
    `owner_id` INTEGER NOT NULL DEFAULT 0,
    `member_count` INTEGER NOT NULL DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1,
    `join_mode` TEXT DEFAULT 'free',
    `pay_type` INTEGER NOT NULL DEFAULT 0,
    `pay_vip_level` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `circle_members` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `role` TEXT DEFAULT 'member',
    `status` INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `circle_subscribes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `status` INTEGER NOT NULL DEFAULT 0,
    `expire_at` TEXT DEFAULT NULL
);
CREATE TABLE `conversations` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `type` TEXT DEFAULT 'group',
    `title` TEXT DEFAULT NULL,
    `avatar` TEXT DEFAULT NULL,
    `creator_id` INTEGER NOT NULL DEFAULT 0,
    `member_count` INTEGER NOT NULL DEFAULT 0,
    `circle_id` INTEGER DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL,
    `last_message_at` TEXT DEFAULT NULL
);
CREATE TABLE `conversation_members` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `conversation_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `role` TEXT DEFAULT 'member',
    `joined_at` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL
);
SQL;

    private function db(): CircleGroupCompatPdo
    {
        $this->assertNotNull($this->db);
        return $this->db;
    }

    private function seedRow(string $table, array $row): void
    {
        $cols = array_keys($row);
        foreach ($cols as $col) {
            $this->assertSame(1, preg_match('/^[a-zA-Z0-9_]+$/', (string)$col));
        }
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $this->db()->prepare($sql)->execute(array_values($row));
    }

    private function countRows(string $table): int
    {
        return (int)$this->db()->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    private function seedUser(int $id, int $vipLevel = 0): void
    {
        $this->seedRow('users', [
            'id' => $id, 'username' => "u{$id}", 'nickname' => "n{$id}",
            'status' => 1, 'vip_level' => $vipLevel, 'token_version' => 1,
        ]);
    }

    private function seedCircle(array $row): void
    {
        $this->seedRow('circles', array_merge([
            'owner_id' => 1, 'status' => 1, 'join_mode' => 'free', 'pay_type' => 0,
        ], $row));
    }

    private function seedMember(int $circleId, int $userId, int $status = 1, string $role = 'member'): void
    {
        $this->seedRow('circle_members', [
            'circle_id' => $circleId, 'user_id' => $userId, 'status' => $status, 'role' => $role,
        ]);
    }

    /** 用真实 JWT 建立当前登录身份，并模拟 POST /api/circles/group */
    private function joinGroupAs(int $userId, int $circleId): Response
    {
        $user = $this->db()->query('SELECT * FROM users WHERE id = ' . $userId)->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($user, '登录用户必须存在');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Auth::generateToken($user);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/circles/group';
        $_GET = ['id' => $circleId];
        $_POST = ['id' => $circleId];
        Auth::clear();

        return (new CircleController())->joinGroup(new Request());
    }

    public function testNonMemberCannotEnterFreeCircleGroup(): void
    {
        $this->seedUser(1);
        $this->seedUser(2);
        $this->seedCircle(['id' => 1, 'name' => '普通圈']);

        $response = $this->joinGroupAs(2, 1);
        $this->assertSame(403, $response->getCode(), '非成员不得绕过入群鉴权');
        $this->assertSame(0, $this->countRows('conversation_members'), '不得写入群成员行');
        $this->assertSame(0, $this->countRows('conversations'), '鉴权失败前不得建群');
    }

    public function testPaidCircleRequiresValidSubscriptionOrVip(): void
    {
        $this->seedUser(1);
        $this->seedUser(2);
        $this->seedCircle(['id' => 2, 'name' => '订阅圈', 'pay_type' => 2]);
        $this->seedMember(2, 2);

        $response = $this->joinGroupAs(2, 2);
        $this->assertSame(402, $response->getCode(), '无有效订阅应返回 402');
        $this->assertStringContainsString('订阅', json_decode($response->toJson(), true)['message']);
        $this->assertSame(0, $this->countRows('conversations'), '付费门槛未过不得建群');

        $this->seedCircle(['id' => 3, 'name' => '会员圈', 'pay_type' => 1, 'pay_vip_level' => 3]);
        $this->seedMember(3, 2);
        $response = $this->joinGroupAs(2, 3);
        $this->assertSame(402, $response->getCode(), '会员等级不足应返回 402');
        $this->assertSame(0, $this->countRows('conversation_members'), '付费门槛未过不得入群');
    }

    public function testInviteAndApprovalModesRejectNonMembers(): void
    {
        $this->seedUser(1);
        $this->seedUser(2);
        $this->seedCircle(['id' => 4, 'name' => '邀请圈', 'join_mode' => 'invite']);
        $this->seedCircle(['id' => 5, 'name' => '审核圈', 'join_mode' => 'approval']);

        $this->assertSame(403, $this->joinGroupAs(2, 4)->getCode(), '邀请制圈子不得直接进入');
        $this->assertSame(403, $this->joinGroupAs(2, 5)->getCode(), '审核制圈子不得直接进入');
        $this->assertSame(0, $this->countRows('conversations'));
    }

    public function testBlacklistedMemberCannotEnterGroup(): void
    {
        $this->seedUser(1);
        $this->seedUser(2);
        $this->seedCircle(['id' => 6, 'name' => '黑名单圈']);
        $this->seedMember(6, 2, 2);

        $this->assertSame(403, $this->joinGroupAs(2, 6)->getCode());
        $this->assertSame(0, $this->countRows('conversation_members'));
        $this->assertSame(0, $this->countRows('conversations'));
    }

    public function testEffectiveMembersJoinIdempotentlyWithoutRoleDowngrade(): void
    {
        $this->seedUser(1);
        $this->seedUser(2);
        $this->seedCircle(['id' => 7, 'name' => '正常圈']);
        $this->seedMember(7, 1, 1, 'owner');
        $this->seedMember(7, 2, 1, 'admin');

        // 预先存在的群：joinGroup 必须复用，不得再建第二个群
        $this->seedRow('conversations', [
            'id' => 77, 'type' => 'group', 'title' => '正常圈',
            'creator_id' => 1, 'member_count' => 2, 'circle_id' => 7,
        ]);
        $this->seedRow('conversation_members', [
            'conversation_id' => 77, 'user_id' => 1, 'role' => 'owner',
        ]);
        $this->seedRow('conversation_members', [
            'conversation_id' => 77, 'user_id' => 2, 'role' => 'admin',
        ]);

        foreach ([1, 2, 1] as $userId) {
            $response = $this->joinGroupAs($userId, 7);
            $this->assertSame(200, $response->getCode(), '有效成员应可幂等进群');
            $data = json_decode($response->toJson(), true)['data'];
            $this->assertSame(77, (int)$data['conversation_id']);
            $this->assertSame(2, (int)$data['member_count']);
        }

        $this->assertSame(1, $this->countRows('conversations'), '不得重复建群');
        $this->assertSame(2, $this->countRows('conversation_members'), '重复入群不得插新行');
        $roles = $this->db()->query('SELECT user_id, role FROM conversation_members ORDER BY user_id')
            ->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([['user_id' => 1, 'role' => 'owner'], ['user_id' => 2, 'role' => 'admin']], $roles,
            '已存在的群主/管理员角色不得被 member 覆盖');
    }
}

/**
 * 测试专用 PDO：产品仓储使用 MySQL 的 INSERT IGNORE / NOW()，SQLite 需等价改写。
 * 只改 SQL 方言，不改变绑定参数数量与业务语义。
 */
final class CircleGroupCompatPdo extends PDO
{
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $query = str_ireplace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $query);
        $query = str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $query);
        return parent::prepare($query, $options);
    }
}
