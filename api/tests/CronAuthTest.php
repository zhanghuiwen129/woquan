<?php
/**
 * CronAuth 单元测试（unit 套件）
 *
 * 背景：cron_secret 的读取与比对原先在三个控制器里各抄了一遍，且配置模板里
 * 没有它的位置 —— 运维漏配就三条 cron 全 403，日志里只有一句
 * 「cron token 无效或未配置」，分不清是「没配」还是「token 打错了」。
 * api/core/CronAuth.php 把这块收敛成唯一真源，本测试守住它的三条语义：
 *
 *   1) 优先级：config.php 的 CRON_SECRET 常量 > 数据库 settings.cron_secret > 空串
 *   2) 【安全】secret 为空时 verify() 一律返回 false —— 「没配」= 关闭外部入口，
 *      绝不能因为「候选串也是空」就放行（这三个 cron 都能改数据）
 *   3) 常量优先级与「空 secret 拒绝」必须在干净进程里验证，
 *      所以走 @runInSeparateProcess：常量一旦在主进程里 define 就无法撤销，
 *      会污染同一进程内的其它用例。
 *
 * 本文件不连数据库（unit 套件的定位就是「不需要 MySQL 也能跑」），
 * 走数据库的那部分（settings 读写、ensureSecret 落库）放在行为级套件
 * api/tests/Behavior/CronAuthBehaviorTest.php。
 */

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\CronAuth;
use PHPUnit\Framework\TestCase;

final class CronAuthTest extends TestCase
{
    protected function setUp(): void
    {
        // 主进程里始终处于「未配置」状态：既没有常量，也没有数据库。
        CronAuth::reset();
    }

    protected function tearDown(): void
    {
        CronAuth::reset();
    }

    /**
     * 未配置 secret 时 secret() 返回空串，isConfigured() 为 false。
     */
    public function testSecretIsEmptyWhenNothingConfigured(): void
    {
        $this->assertSame('', CronAuth::secret());
        $this->assertFalse(CronAuth::isConfigured());
        $this->assertSame('none', CronAuth::source());
    }

    /**
     * 【安全守卫】未配置 secret 时，任何候选串都不能通过 —— 包括空串。
     *
     * 这条如果被改坏，等于把「执行队列任务 / 发布定时动态 / 重算徽章」
     * 三个写接口公开给任何知道 URL 的人。
     */
    public function testVerifyAlwaysFailsWhenSecretNotConfigured(): void
    {
        $this->assertFalse(CronAuth::verify(''));
        $this->assertFalse(CronAuth::verify(null));
        $this->assertFalse(CronAuth::verify('any-token'));
        $this->assertFalse(CronAuth::verify('0'));
    }

    /**
     * DB 不可用时 ensureSecret() 必须显式抛异常，不能静默返回空串 ——
     * 静默失败会让调用方分不清「secret 生成好了」和「根本没生成」。
     */
    public function testEnsureSecretThrowsWhenDatabaseUnavailable(): void
    {
        $this->expectException(\RuntimeException::class);
        CronAuth::ensureSecret();
    }

    /**
     * 常量优先级：config.php 里 define('CRON_SECRET', ...) 定义且非空时生效。
     *
     * 单独进程运行：常量在 PHP 里一旦 define 就无法撤销，在主进程里定义会
     * 污染同一进程内的其它用例（也拿不到「未配置」的状态了）。
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConfigConstantTakesPriority(): void
    {
        define('CRON_SECRET', 'constant-secret-value');

        $this->assertSame('constant-secret-value', CronAuth::secret());
        $this->assertTrue(CronAuth::isConfigured());
        $this->assertSame('constant', CronAuth::source());

        // 配了常量之后，正确的 token 能过、错的过不了
        $this->assertTrue(CronAuth::verify('constant-secret-value'));
        $this->assertFalse(CronAuth::verify('wrong-token'));
        $this->assertFalse(CronAuth::verify(''));
        $this->assertFalse(CronAuth::verify(null));
    }

    /**
     * 常量定义为空字符串时视为「没配」，必须回落到数据库 / 空串，
     * 绝不能把「空常量」当成一个可用的 secret（那等于空口令放行）。
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEmptyConfigConstantIsIgnored(): void
    {
        define('CRON_SECRET', '   ');

        $this->assertSame('', CronAuth::secret());
        $this->assertFalse(CronAuth::isConfigured());
        $this->assertFalse(CronAuth::verify(''));
        $this->assertFalse(CronAuth::verify('anything'));
    }

    /**
     * 结构性护栏：三个 cron 端点必须统一走 CronAuth::verify()，
     * 不允许再出现「自己 SELECT settings.cron_secret 再 hash_equals」的副本。
     *
     * 副本一旦回流，「唯一真源」就名存实亡：改优先级、改空值语义都要改多处。
     */
    public function testCronEndpointsAllUseCronAuthAsSingleSource(): void
    {
        $root = dirname(__DIR__, 2);

        $targets = [
            'api/controllers/JobController.php'       => ['CronAuth::verify'],
            'api/controllers/BadgeController.php'     => ['CronAuth::verify'],
            'api/controllers/PostModerationTrait.php' => ['CronAuth::verify'],
        ];

        foreach ($targets as $file => $needles) {
            $path = $root . '/' . $file;
            $this->assertFileExists($path);
            $src = (string)file_get_contents($path);

            foreach ($needles as $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $src,
                    "{$file} 必须调用 {$needle}（cron 鉴权的唯一真源是 api/core/CronAuth.php）"
                );
            }

            // 副本式读取：直接 SELECT settings.cron_secret
            $this->assertDoesNotMatchRegularExpression(
                '/SELECT\s+`?value`?\s+FROM\s+`?settings`?\s+WHERE\s+`?key`?\s*=\s*\'cron_secret\'/i',
                $src,
                "{$file} 不允许再直接查询 settings.cron_secret —— 请改用 CronAuth::verify()"
            );
        }
    }

    /**
     * 结构性护栏：cron_secret 在 api/ 目录下的真实读取只允许出现在 CronAuth 里。
     *
     * 防止将来新增控制器时又抄一份「SELECT settings.cron_secret」的副本 ——
     * 副本一旦回流，「唯一真源」就名存实亡：改优先级、改空值语义都要改多处。
     * 只拦「直接查库」这一种形态，不拦注释里提到 cron_secret（注释容易误导误伤）。
     */
    public function testCronSecretIsOnlyReadInsideCronAuth(): void
    {
        $root     = dirname(__DIR__, 2) . '/api';
        $sourceOf = str_replace('\\', '/', $root . '/core/CronAuth.php');

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $hits = [];
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if ($path === $sourceOf) {
                continue; // 唯一真源
            }
            $src = (string)file_get_contents($file->getPathname());
            if (preg_match('/SELECT\s+`?value`?\s+FROM\s+`?settings`?\s+WHERE\s+`?key`?\s*=\s*\'cron_secret\'/i', $src)) {
                $hits[] = $path;
            }
        }

        $this->assertSame([], $hits, '只有 api/core/CronAuth.php 允许直接查询 settings.cron_secret');
    }
}
