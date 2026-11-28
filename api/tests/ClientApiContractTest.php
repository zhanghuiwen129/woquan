<?php
/**
 * P1-1 客户端 API 契约门禁行为测试
 *
 * 全部用真实临时文件 fixture 驱动解析 / 生成 / 对账逻辑，不以源码文本断言充当逻辑测试：
 *   1. 路径归一化（query 剥离、模板参数、可选 query 后缀、主题相对路径补前缀、尾斜杠）；
 *   2. JS 字面量扫描跳过注释、正确配对模板里的 ${...} 嵌套；
 *   3. 生成器对同一契约的输出确定性，契约变化即清单变化；
 *   4. 注入假漂移端点 → 门禁红灯；写基线 → 绿灯；基线只减不增 → 再红灯；
 *   5. 团队提供的两个「模板被截断」假阳性不误报；
 *   6. 当前仓库真实清单 + 真实基线能通过。
 */

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Docs\ClientApiContractChecker;
use Hublog\Docs\ClientEndpointManifestBuilder;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/build-client-endpoints.php';
require_once dirname(__DIR__, 2) . '/scripts/check-client-api-contract.php';

final class ClientApiContractTest extends TestCase
{
    /** @var list<string> 需要清理的临时路径 */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            $this->removePath($path);
        }
        $this->tempPaths = [];
        parent::tearDown();
    }

    /** 路径归一化：query / 模板参数 / 占位符名 / 可选 query 后缀 / 尾斜杠 */
    public function testNormalizesPaths(): void
    {
        $normalize = static fn(string $raw, bool $relative = false): ?string =>
            ClientEndpointManifestBuilder::normalizePath($raw, $relative);

        $this->assertSame('/api/posts/detail', $normalize('/api/posts/detail?id=123'));
        $this->assertSame('/api/posts/timeline', $normalize('/api/posts/timeline?page=1&limit=10'));
        $this->assertSame('/api/posts', $normalize('/api/posts/'));
        $this->assertSame('/api/content-pay/buy', $normalize('/content-pay/buy', true));

        // ${id} 与契约 {id} 统一归一为 {param}
        $this->assertSame('/api/notifications/{param}/read', $normalize('/api/notifications/${id}/read'));
        $this->assertSame('/api/notifications/{param}/read', $normalize('/api/notifications/{id}/read'));
        $this->assertSame('/api/notifications/{param}', $normalize('/api/notifications/${id}'));

        // ${deviceId ? '?x=1' : ''} 是可选 query 后缀，整体丢弃而不是变成路径参数
        $this->assertSame(
            '/api/app/devices',
            $normalize("/api/app/devices\${deviceId ? '?device_id=' + deviceId : ''}")
        );
        // 尾随 query 串变量 ${params}（静态无法判内容）保留为粘连 {param}，匹配阶段做宽松对齐
        $this->assertSame('/api/follow/stats{param}', $normalize('/api/follow/stats${params}'));
    }

    /** JS 字面量扫描：注释里的伪端点不采，模板里的嵌套反引号 / ${...} 完整保留 */
    public function testExtractJsLiteralsSkipsCommentsAndKeepsNestedTemplates(): void
    {
        $source = implode("\n", [
            "// request(app, '/api/fake/comment')",
            "/* uploadFile('/api/fake/block') */",
            "const a = request({ url: '/api/auth/login' })",
            "const b = request({ url: `/api/circles?page=\${page}\${keyword ? `&keyword=\${kw}` : ''}` })",
        ]);
        $literals = ClientApiContractChecker::extractJsLiterals($source);

        $this->assertCount(2, $literals);
        $this->assertSame('/api/auth/login', $literals[0]['value']);
        $this->assertStringContainsString('&keyword=${kw}', $literals[1]['value']);
        $this->assertStringContainsString('${page}', $literals[1]['value']);
    }

    /** 生成器确定性：同一 openapi 输出逐字节一致；新增 path 后清单随之变化 */
    public function testGeneratorIsDeterministicAndTracksContractChanges(): void
    {
        $dir = $this->makeTempDir();
        $yaml = implode("\n", [
            'openapi: 3.0.3',
            'paths:',
            '  /api/auth/login:',
            '    post:',
            '      tags: [认证]',
            '      summary: 登录',
            '  /api/notifications/{id}/read:',
            '    put:',
            '      tags: [通知]',
            '',
        ]);
        $parsed = ClientEndpointManifestBuilder::parseOpenApi($yaml);
        $this->assertArrayHasKey('/api/auth/login', $parsed);
        $this->assertArrayHasKey('/api/notifications/{id}/read', $parsed);
        $this->assertSame(['post'], array_keys($parsed['/api/auth/login']['methods']));
        $this->assertSame(['认证'], array_keys($parsed['/api/auth/login']['tags']));

        $first = ClientEndpointManifestBuilder::render($parsed, 'sha-a');
        $second = ClientEndpointManifestBuilder::render(
            ClientEndpointManifestBuilder::parseOpenApi($yaml),
            'sha-a'
        );
        $this->assertSame($first, $second, '同契约重复运行必须幂等（--check 逐字节比对）');
        $this->assertStringContainsString('/api/notifications/{param}/read', $first);

        $expanded = ClientEndpointManifestBuilder::render(
            ClientEndpointManifestBuilder::parseOpenApi($yaml . "  /api/ghost/new:\n    get:\n"),
            'sha-b'
        );
        $this->assertNotSame($first, $expanded, '契约新增 path 后清单必须变化，否则 --check 形同虚设');
    }

    /** 注入假漂移 → 红灯；固化基线 → 绿灯；扩大基线数但漂移变多 → 仍红灯（只减不增） */
    public function testDetectsInjectedDriftAndBaselineOnlyShrinks(): void
    {
        $dir = $this->makeTempDir();
        $manifest = $dir . '/manifest.json';
        $appClient = $dir . '/api.js';
        $themeDir = $dir . '/theme';
        $baseline = $dir . '/baseline.json';
        mkdir($themeDir);
        $this->write($manifest, (string)json_encode(['paths' => ['/api/auth/login']]));
        $this->write($appClient, implode("\n", [
            "export const ok = () => request({ url: '/api/auth/login' })",
            "export const ghost = () => request({ url: '/api/ghost/endpoint' })",
        ]));
        $this->writeBaseline($baseline, 0, []);

        $result = ClientApiContractChecker::evaluate($manifest, $appClient, [$themeDir], $baseline);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame(['/api/ghost/endpoint'], $result['unexpected']);
        $this->assertSame(1, $result['matched_count']);

        // 用真实差异固化基线后放行存量
        $snapshot = ClientApiContractChecker::snapshot($manifest, $appClient, [$themeDir]);
        ClientApiContractChecker::writeBaseline($baseline, $snapshot);
        $result = ClientApiContractChecker::evaluate($manifest, $appClient, [$themeDir], $baseline);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame([], $result['unexpected']);

        // 再注入一个漂移，即便人为把它写进 allowed_drift，总数超过 baseline_count 仍必须拦截
        $this->write($appClient, (string)file_get_contents($appClient) . "\n"
            . "export const ghost2 = () => request({ url: '/api/ghost/second' })\n");
        $this->writeBaseline($baseline, 1, ['/api/ghost/endpoint', '/api/ghost/second']);
        $result = ClientApiContractChecker::evaluate($manifest, $appClient, [$themeDir], $baseline);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame([], $result['unexpected'], '两者都在 allowed_drift 里，靠「只减不增」兜底');
        $this->assertStringContainsString('只减不增', implode('；', $result['reasons']));

        // 修掉一个存量漂移：差异变小应继续放行（基线只减不增，不要求相等）
        $this->write($appClient, "export const ok = () => request({ url: '/api/auth/login' })\n"
            . "export const ghost = () => request({ url: '/api/ghost/endpoint' })\n");
        $this->writeBaseline($baseline, 2, ['/api/ghost/endpoint', '/api/ghost/second']);
        $result = ClientApiContractChecker::evaluate($manifest, $appClient, [$themeDir], $baseline);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(1, count($result['drift']));
    }

    /** 团队给出的两个假阳性（模板尾部截断 / 字符串拼接）不得误报为漂移 */
    public function testTemplateFalsePositivesDoNotBecomeDrift(): void
    {
        $dir = $this->makeTempDir();
        $manifest = $dir . '/manifest.json';
        $appClient = $dir . '/api.js';
        $themeDir = $dir . '/theme';
        $baseline = $dir . '/baseline.json';
        mkdir($themeDir);
        $this->write($manifest, (string)json_encode([
            'paths' => ['/api/notifications/{param}/read', '/api/content-pay/buy', '/api/posts'],
        ]));
        $this->write($appClient, implode("\n", [
            'export const a = () => request({ url: `/api/notifications/${id}/read` })',
            "export const b = () => request({ url: '/api/posts/' + params.action })",
        ]));
        $this->write($themeDir . '/api.js', implode("\n", [
            "export const x = (app) => request(app, '/content-pay/buy', {})",
            'export const y = (app, id) => app.request(`/notifications/${id}/read`)',
            "// request(app, '/api/fake/comment')",
        ]));
        $this->writeBaseline($baseline, 0, []);

        $snapshot = ClientApiContractChecker::snapshot($manifest, $appClient, [$themeDir]);
        $this->assertSame([], $snapshot['drift'], '模板 ${id} 与注释均不得误报');
        $this->assertSame(2, $snapshot['client_unique_count'], '拼接端点被跳过，不应计入');
        $this->assertCount(1, $snapshot['skipped'], "字符串拼接 '/api/posts/' + action 应跳过并注明");

        $result = ClientApiContractChecker::evaluate($manifest, $appClient, [$themeDir], $baseline);
        $this->assertSame(0, $result['exit_code']);
    }

    /** 真实仓库：生成器产物 + 冻结基线必须放行当前存量 */
    public function testRealRepositoryGatePasses(): void
    {
        $root = dirname(__DIR__, 2);
        $result = ClientApiContractChecker::evaluate(
            $root . '/docs/client_endpoints.json',
            $root . '/app-client/src/api/index.js',
            ClientApiContractChecker::defaultThemeDirs($root),
            $root . '/docs/client_api_baseline.json'
        );
        $this->assertSame(0, $result['exit_code'], implode('；', $result['reasons']));
        $this->assertGreaterThanOrEqual(340, $result['manifest_count']);
        $this->assertGreaterThanOrEqual(170, $result['matched_count']);
        $this->assertLessThanOrEqual(1, count($result['drift']), '当前只允许 oauth/providers 一条存量漂移');
    }

    /** @return string 新建的临时目录 */
    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/client_api_contract_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempPaths[] = $dir;
        return $dir;
    }

    private function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            $this->fail("fixture 写入失败: {$path}");
        }
    }

    /** @param list<string> $allowed */
    private function writeBaseline(string $path, int $count, array $allowed): void
    {
        $this->write($path, (string)json_encode([
            'baseline_count' => $count,
            'allowed_drift' => $allowed,
        ]));
    }

    private function removePath(string $path): void
    {
        if (is_dir($path)) {
            $items = scandir($path) ?: [];
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') {
                    $this->removePath($path . '/' . $item);
                }
            }
            rmdir($path);
            return;
        }
        if (is_file($path)) {
            unlink($path);
        }
    }
}
