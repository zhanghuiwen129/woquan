<?php
/**
 * PHPStan level 3 提级后修掉的真实缺陷 —— 回归护栏
 *
 * 背景：把 PHPStan 从 level 1 提到 level 3 之后，一次就揪出了 40 处问题，
 * 其中多���是**线上必然 500**或**功能形同虚设**的真缺陷：
 *
 *   ① PDO::exec() 多传第 2 个参数 → ArgumentCountError（致命错误）
 *      —— 头像设置、封面设置、忘记密码、投票移除选项、批量删上传记录，五条路径原本必挂。
 *   ② `new Exception\HublogException` 在 Hublog\Controllers 命名空间下解析到
 *      不存在的类 → 抛异常时 fatal（视频魔数校验失败即整条上传接口 500）。
 *   ③ 调用不存在的方法：Request::getJson() / Upgrade::createBackup() → fatal。
 *   ④ 静态方法里 self:: 调用实例方法：PointController::currentPoints() → fatal。
 *   ⑤ 未定义变量 $settings → 昵称禁用词检查完全失效（内容合规漏洞）。
 *   ⑥ 多余实参被 PHP 静默丢弃：Response::error() 的第 3 参、Helper::paginatedResponse()
 *      的第 5 参、Request::getFloat() 的第 2 参 —— 错误详情 / 分类名 / 默认值
 *      从来没有真正生效。
 *
 * 本测试把「按参数个数调用」这一类缺陷变成**可执行的门禁**：
 * 全仓扫描，禁止再出现「给只接受 1 个参数的内部方法传 2 个参数」这种写法。
 */

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\Helper;
use Hublog\Core\Request;
use Hublog\Core\Response;
use PHPUnit\Framework\TestCase;

final class PHPStanFixRegressionTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * 去掉注释后的源码：行号用等量换行补齐。
     *
     * 源码里的说明文字常引用"有问题的写法"作为反面示例（例如 scripts/ci.php
     * 与 Upload.php 的注释），直接正则扫原文会误报，必须只扫真正的代码。
     */
    private static function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /** @return string 文件声明的命名空间（去首尾反斜杠），没有则返回空串 */
    private static function namespaceOf(string $src): string
    {
        return preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $src, $m)
            ? trim($m[1], '\\')
            : '';
    }

    /**
     * 扫描全仓 PHP 源码，找「PDO::exec / query / prepare 传了 2 个以上参数」的写法。
     *
     * 这类调用在 PHP 里对**用户态**方法是静默丢弃多余参数（行为悄悄不对），
     * 对**内部方法**（PDO::exec）是 ArgumentCountError（致命错误）——
     * 两种都不该出现在代码里。
     */
    public function testNoInternalPdoMethodIsCalledWithTooManyArguments(): void
    {
        $offenders = [];
        foreach ($this->phpFiles() as $file) {
            $src = self::codeOnly((string)file_get_contents($file));
            // 匹配 ->exec( / ->query( / ->prepare( 后面带逗号的写法
            if (preg_match_all('/->(exec|query|prepare)\s*\(\s*(?:"[^"]*"|\'[^\']*\')\s*,/m', $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $offenders[] = str_replace(self::root() . '/', '', $file)
                        . ' → ->' . $hit[1] . '(... , ...)'
                        . '（行 ' . (substr_count(substr($src, 0, (int)strpos($src, $hit[0])), "\n") + 1) . '）';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "这些调用给「只接受 1 个参数」的 PDO 方法传了第 2 个参数：\n"
            . implode("\n", $offenders)
            . "\nPDO::exec()/query() 多传参数会抛 ArgumentCountError（致命错误 → 整条路径 500）；"
            . "确需绑定参数请改用 Database::query()（内部是 prepare + execute）。"
        );
    }

    /**
     * 相对命名空间的异常类引用：`new Exception\HublogException` 在命名空间
     * `Hublog\Controllers` 下会解析成 `Hublog\Controllers\Exception\HublogException`
     * —— 该类不存在，抛异常时直接 fatal。
     *
     * 例外：`Hublog\Core` 下确实存在 `Hublog\Core\Exception\HublogException`，
     * 所以在那一层写相对名是合法的，不能一刀切报错。
     */
    public function testNoRelativeExceptionClassReference(): void
    {
        $offenders = [];
        foreach ($this->phpFiles() as $file) {
            $raw = (string)file_get_contents($file);
            if (self::namespaceOf($raw) === 'Hublog\Core') {
                continue;
            }
            $src = self::codeOnly($raw);
            if (preg_match('/new\s+Exception\\\\[A-Za-z_]/', $src, $m, PREG_OFFSET_CAPTURE)) {
                $offenders[] = str_replace(self::root() . '/', '', $file)
                    . ' → ' . $m[0][0]
                    . '（行 ' . (substr_count(substr($src, 0, $m[0][1]), "\n") + 1) . '）';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "这些位置用了相对命名空间的异常类，运行时解析到不存在的类 → fatal：\n"
            . implode("\n", $offenders)
            . "\n请改用已 import 的简名（use Hublog\\Core\\Exception\\HublogException;）。"
        );
    }

    /** Response::error() 的第 3 参（错误详情）必须真的下发到响应体里。 */
    public function testResponseErrorKeepsDetailPayload(): void
    {
        $res = Response::error('用户名已被注册', 409, ['field' => 'username']);
        $decoded = json_decode($res->toJson(), true);

        $this->assertSame(409, $decoded['code']);
        $this->assertSame('用户名已被注册', $decoded['message']);
        $this->assertSame(
            ['field' => 'username'],
            $decoded['data'],
            '错误详情必须下发到 data，否则前端无法把提示定位到具体输入框'
        );
    }

    /** 既有的 2 参调用行为不变（data 为 null）。 */
    public function testResponseErrorWithoutDetailStillWorks(): void
    {
        $decoded = json_decode(Response::error('出错了', 400)->toJson(), true);
        $this->assertSame(400, $decoded['code']);
        $this->assertNull($decoded['data']);
    }

    /** Request::getFloat() 的默认值必须生效（ShopController 有 4 处依赖它）。 */
    public function testRequestGetFloatHonoursDefault(): void
    {
        $request = new Request();
        $this->assertSame(1.5, $request->getFloat('missing', 1.5));
        $this->assertNull($request->getFloat('missing'));
    }

    /** Helper::paginatedResponse() 的附加参数必须合并进响应体。 */
    public function testPaginatedResponseMergesExtraPayload(): void
    {
        $res = Helper::paginatedResponse([['id' => 1]], 1, 20, 1, ['category' => 'tech']);
        $this->assertSame('tech', $res['category']);
        $this->assertSame(1, $res['pagination']['total']);

        // 4 参调用行为不变
        $plain = Helper::paginatedResponse([], 1, 20, 0);
        $this->assertArrayNotHasKey('category', $plain);
    }

    /**
     * 上传回调里「本站域名加入白名单」必须用 origin()（绝对地址），
     * 不能用 url()（只返回路径，parse_url 后拿不到 host）。
     */
    public function testUploadWhitelistUsesOriginNotUrl(): void
    {
        $src = self::codeOnly((string)file_get_contents(self::root() . '/api/core/Upload.php'));
        $this->assertStringNotContainsString(
            "PathResolver::url('/', true)",
            $src,
            "PathResolver::url() 只有 1 个参数，第 2 个实参被静默丢弃 → 本站域名加不进白名单"
        );
        $this->assertStringContainsString('PathResolver::origin()', $src);
    }

    /**
     * 后台「自动备份」调用 Upgrade::createBackup()，方法缺失会让接口必 500。
     * 这里校验方法契约存在，且确实复用类内已有的备份私有方法（不重写备份逻辑）。
     */
    public function testUpgradeCreateBackupContract(): void
    {
        $rc = new \ReflectionClass(\Hublog\Core\Upgrade::class);
        $this->assertTrue($rc->hasMethod('createBackup'), '后台自动备份接口必 500：createBackup() 不存在');

        $method = $rc->getMethod('createBackup');
        $this->assertTrue($method->isPublic());
        $this->assertSame(0, $method->getNumberOfRequiredParameters());
        $this->assertSame('array', (string)$method->getReturnType());

        $lines = (array)file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        foreach (['backupDatabase(', 'backupFiles(', 'recordBackup('] as $call) {
            $this->assertStringContainsString($call, $body, "createBackup() 必须复用既有私有方法 {$call}");
        }
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $out = [];
        foreach (['api/core', 'api/controllers', 'api/middleware', 'plugins', 'bin', 'scripts'] as $dir) {
            $path = self::root() . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($rii as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $out[] = $file->getPathname();
                }
            }
        }
        return $out;
    }
}
