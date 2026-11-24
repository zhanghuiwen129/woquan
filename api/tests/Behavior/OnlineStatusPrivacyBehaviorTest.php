<?php
/**
 * P0-R2 回归护栏：/api/user/online 不得泄漏 last_active_at。
 *
 * 历史缺陷：UserRepository::fetchOnlineStatuses() 原样返回 last_active_at 四字段，
 * 控制器的 hide_online 只影响 online 布尔，原始时间戳无条件下发；
 * 且仓储查询不带 status 条件，封禁/注销账号的最后活跃时间同样暴露。
 *
 * 本测试用 BehaviorTestCase 的 SQLite 内存库真实调用 UserController::onlineStatus()，
 * 断言「本人 / 隐藏在线 / 非在线（含 status≠1）」一律拿不到时间戳，
 * 同时保留「在线且未隐藏的他人」这一正常社交展示能力。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\UserController;
use Hublog\Core\Auth;
use Hublog\Core\Request;

final class OnlineStatusPrivacyBehaviorTest extends BehaviorTestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];
    /** @var array<string,mixed> */
    private array $getBackup = [];
    private string $recent = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec(<<<'SQL'
ALTER TABLE `users` ADD COLUMN `hide_online` INTEGER NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `last_active_at` TEXT DEFAULT NULL;
SQL);

        $this->recent = date('Y-m-d H:i:s', time() - 30);
        $stale = date('Y-m-d H:i:s', time() - 3600);
        $rows = [
            // 查询者本人：在线但未隐藏 → online=true，时间戳按 profile 口径仍不下发
            ['id' => 1, 'username' => 'viewer', 'status' => 1, 'hide_online' => 0, 'last_active_at' => $this->recent],
            // 他人：在线且未隐藏 → 正常展示时间戳（原有社交功能不破坏）
            ['id' => 2, 'username' => 'visible', 'status' => 1, 'hide_online' => 0, 'last_active_at' => $this->recent],
            // 他人：显式隐藏在线 → online=false 且时间戳必须 null
            ['id' => 3, 'username' => 'hidden', 'status' => 1, 'hide_online' => 1, 'last_active_at' => $this->recent],
            // 他人：已离线 → 时间戳必须 null
            ['id' => 4, 'username' => 'offline', 'status' => 1, 'hide_online' => 0, 'last_active_at' => $stale],
            // 他人：封禁/注销（status=0）但时间戳很新 → 时间戳必须 null
            ['id' => 5, 'username' => 'banned', 'status' => 0, 'hide_online' => 0, 'last_active_at' => $this->recent],
            // 他人：从未活跃 → online=false 且时间戳为 null
            ['id' => 6, 'username' => 'never', 'status' => 1, 'hide_online' => 0, 'last_active_at' => null],
        ];
        foreach ($rows as $row) {
            $this->seed('users', $row + [
                'nickname' => (string)$row['username'],
                'role' => 'user',
                'token_version' => 1,
                'created_at' => date('Y-m-d H:i:s', time() - 86400),
            ]);
        }

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $viewer = $this->pdo()->query('SELECT * FROM users WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($viewer, '登录用户必须存在');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Auth::generateToken($viewer);
        Auth::clear();
    }

    protected function tearDown(): void
    {
        Auth::clear();
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        parent::tearDown();
    }

    /** @return array<int,array{online:bool,last_active_at:?string}> */
    private function callOnline(string $ids): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/user/online';
        $_GET = ['ids' => $ids];
        Auth::clear();

        $response = (new UserController())->onlineStatus(new Request());
        $this->assertSame(200, $response->getCode(), '在线状态接口应正常返回');
        $payload = json_decode($response->toJson(), true);
        $this->assertIsArray($payload, '响应必须是合法 JSON');
        return $payload['data'];
    }

    public function testHiddenOfflineBannedAndSelfNeverLeakTimestamp(): void
    {
        $data = $this->callOnline('1,2,3,4,5,6');

        $this->assertTrue($data[1]['online'], '本人在线布尔仍应可见');
        $this->assertNull($data[1]['last_active_at'], '本人时间戳按 profile() 口径不下发');

        $this->assertTrue($data[2]['online'], '在线且未隐藏的他人应标记为在线');
        $this->assertSame($this->recent, $data[2]['last_active_at'], '在线且未隐藏的他人保留原有时间戳展示');

        foreach ([3, 4, 5, 6] as $id) {
            $this->assertFalse($data[$id]['online'], "用户 {$id} 不应标记为在线");
            $this->assertNull($data[$id]['last_active_at'], "用户 {$id} 的 last_active_at 不得下发");
        }
    }

    public function testHiddenAndBannedUsersLeakNothingEvenWhenQueriedAlone(): void
    {
        foreach (['3', '5'] as $id) {
            $data = $this->callOnline($id);
            $this->assertFalse($data[(int)$id]['online'], "单独查询用户 {$id} 不应标记为在线");
            $this->assertNull($data[(int)$id]['last_active_at'], "单独查询用户 {$id} 也不得泄漏活跃时间");
        }
    }
}
