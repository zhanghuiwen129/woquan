<?php
/**
 * 微信 unionid 跨端一致性诊断 / 现状固化（已知数据一致性待办，非安全漏洞）
 *
 * 架构师静态追踪结论：mini 侧按 openid 查绑定、app 侧按 unionid 反查，两条路径不对称，
 * 导致「先小程序后 App」的同人账号可能重复。但打通需要产品拍板跨端账号语义 +
 * 一次性历史数据修复，单改一行写入值无效，因此本批不改任何业务代码，
 * 只用本测试固化两个方向与源码现状，防止后续误当普通 bug 随手改坏。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\AppRepository;
use Hublog\Core\UserRepository;

final class WechatUnionidBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->pdo();

        // 与 Batch2RepositoryBehaviorTest 相同的 SQLite 函数 stub（照抄现成先例）
        $pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn($v) => $v, 1);
        foreach ([
            'oauth_provider TEXT DEFAULT NULL',
            'oauth_id TEXT DEFAULT NULL',
        ] as $column) {
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $column);
        }
        $pdo->exec(<<<'SQL'
CREATE TABLE `oauth_accounts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL, `provider` TEXT DEFAULT NULL,
    `oauth_id` TEXT DEFAULT NULL, `access_token` TEXT DEFAULT NULL, `raw_data` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL, `updated_at` TEXT DEFAULT NULL
);
SQL);
    }

    /** 方向 A：mini 记录写入 unionid 后，App 侧按 unionid 能反查到（路径未断） */
    public function testMiniRecordWithUnionidIsVisibleToAppLookup(): void
    {
        $this->seed('oauth_accounts', [
            'id'        => 1,
            'user_id'   => 1,
            'provider'  => 'wechat_mini',
            'oauth_id'  => 'O_mini',
            'raw_data'  => json_encode(['unionid' => 'U'], JSON_UNESCAPED_UNICODE),
        ]);

        $hit = AppRepository::findOauthByUnionid('U');
        $this->assertNotNull($hit, '方向A：App 侧 findOauthByUnionid 应命中 mini 记录');
        $this->assertSame(1, (int)$hit['id']);
    }

    /** 方向 B：mini 侧缺 unionid 读路径 —— 固化现状，不视为需要本批修复的 bug */
    public function testMiniSideCannotResolveByUnionidToday(): void
    {
        $this->seed('users', [
            'id'             => 2,
            'username'       => 'wx_app',
            'status'         => 1,
            'token_version'  => 1,
            'oauth_provider' => 'wechat_app',
            'oauth_id'       => 'U',
        ]);
        $this->seed('oauth_accounts', [
            'id'       => 2,
            'user_id'  => 2,
            'provider' => 'wechat_app',
            'oauth_id' => 'O_app',
            'raw_data' => json_encode(['unionid' => 'U'], JSON_UNESCAPED_UNICODE),
        ]);

        // mini 入口只按 openid 查绑定，没有 unionid 读路径：新 openid 必然查不到
        $this->assertNull(UserRepository::findWechatMiniAccount('O_mini_new'));
        // App 侧按 unionid 能命中 app 行，证明断裂是单向的
        $hit = AppRepository::findUserByOauthId('U');
        $this->assertNotNull($hit);
        $this->assertSame(2, (int)$hit['id']);
    }

    /** 源码固化：unionid 在 wxMiniLogin 中只出现在提取处，改写入值并不够 */
    public function testUnionidOnlyAppearsAtExtractionInWxMiniLogin(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/controllers/AuthController.php');
        $start = strpos($src, 'public function wxMiniLogin(');
        $this->assertNotFalse($start);
        $lines = explode("\n", substr($src, (int)$start));
        $hits = array_values(array_filter(
            $lines,
            static fn(string $line): bool => stripos($line, 'unionid') !== false
        ));

        $this->assertCount(1, $hits, 'wxMiniLogin 中 unionid 只应在提取处出现一次');
        $this->assertStringContainsString("\$unionid = \$sessionData['unionid'] ?? '';", $hits[0]);
    }
}
