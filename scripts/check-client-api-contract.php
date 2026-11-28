<?php
/**
 * 客户端 API 契约漂移门禁（P1-1，零外部依赖）
 *
 *   php scripts/check-client-api-contract.php                  # 用冻结基线对账，退出码 0/1/2
 *   php scripts/check-client-api-contract.php --json           # 机器可读报告
 *   php scripts/check-client-api-contract.php --write-baseline # 用真实差异重新固化基线
 *   php scripts/check-client-api-contract.php --manifest=... --baseline=... --app-client=... --theme=...
 *
 * 对账口径：
 *   - 契约唯一源：docs/openapi.yaml → scripts/build-client-endpoints.php 生成的端点清单；
 *   - 客户端调用：app-client/src/api/index.js 的 url:/uploadFile(...)，
 *     三主题 assets/js 下 app.request(...) / request(app, ...) / fetch(API_BASE + ...) 端点；
 *   - 路径归一化（与生成器共用 ClientEndpointManifestBuilder::normalizePath）：
 *       去 query / fragment、${id} → {param}、三元型 ${...} 可选 query 后缀整体丢弃、
 *       去尾斜杠差异、主题相对路径补 /api 前缀；
 *   - 三类结果：① 客户端调用但契约没有（漂移，受基线约束）；
 *               ② 契约有但客户端没用（信息级，不 FAIL）；
 *               ③ 归一化后匹配（正常）。
 *   - 无法可靠静态解析的动态拼接（如 '/auth/check-' + field）跳过并在报告注明，
 *     宁可漏报也不误报 FAIL。
 *
 * 冻结基线规则与 scripts/check_route_docs.php 一致：
 *   allowed_drift 之外的新增漂移 → 退出 1；漂移总数 > baseline_count → 退出 1（只减不增）。
 * 退出码：0 = 通过，1 = 门禁失败，2 = 输入文件缺失 / 解析失败（环境问题）。
 */

declare(strict_types=1);

namespace Hublog\Docs;

require_once __DIR__ . '/build-client-endpoints.php';

final class ClientApiContractChecker
{
    /** 三主题的 JS 根目录（递归扫描，跳过 dist / node_modules） */
    private const THEME_DIRS = [
        'templates/moments/assets/js',
        'templates/shaiboblog/assets/js',
        'templates/zixunfeed/assets/js',
    ];

    /** 默认路径，供 CLI 与测试共用 */
    public static function defaultThemeDirs(string $root): array
    {
        $dirs = [];
        foreach (self::THEME_DIRS as $dir) {
            $dirs[] = $root . '/' . $dir;
        }
        return $dirs;
    }

    /**
     * 扫描 JS 源码中的字符串 / 模板字面量，跳过注释，正确配对模板里的 ${...} 嵌套。
     *
     * @return list<array{value:string,start:int,end:int}>
     */
    public static function extractJsLiterals(string $source): array
    {
        $literals = [];
        $len = strlen($source);
        $i = 0;
        while ($i < $len) {
            $ch = $source[$i];
            if ($ch === '/' && $i + 1 < $len && $source[$i + 1] === '/') {
                while ($i < $len && $source[$i] !== "\n" && $source[$i] !== "\r") {
                    $i++;
                }
                continue;
            }
            if ($ch === '/' && $i + 1 < $len && $source[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $len && !($source[$i] === '*' && $source[$i + 1] === '/')) {
                    $i++;
                }
                $i = min($i + 2, $len);
                continue;
            }
            if ($ch !== "'" && $ch !== '"' && $ch !== '`') {
                $i++;
                continue;
            }

            $quote = $ch;
            $start = $i;
            $i++;
            $buf = '';
            $tplDepth = 0;
            while ($i < $len) {
                $c = $source[$i];
                if ($c === '\\' && $i + 1 < $len) {
                    $buf .= $source[$i] . $source[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($quote === '`') {
                    if ($c === '$' && $i + 1 < $len && $source[$i + 1] === '{') {
                        $tplDepth++;
                        $buf .= '${';
                        $i += 2;
                        continue;
                    }
                    if ($c === '}' && $tplDepth > 0) {
                        $tplDepth--;
                        $buf .= '}';
                        $i++;
                        continue;
                    }
                }
                if ($c === $quote && $tplDepth === 0) {
                    $i++;
                    break;
                }
                $buf .= $c;
                $i++;
            }
            $literals[] = ['value' => $buf, 'start' => $start, 'end' => $i];
        }
        return $literals;
    }

    /** 递归收集目录下的 JS 文件（跳过 dist / node_modules / .git） */
    private static function jsFiles(string $dir): array
    {
        if (is_file($dir)) {
            return substr($dir, -3) === '.js' ? [$dir] : [];
        }
        if (!is_dir($dir)) {
            return [];
        }
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $path = $fileInfo->getPathname();
            if (substr($path, -3) !== '.js') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($path, strlen($dir)));
            if (preg_match('#/(dist|node_modules|\.git)/#', '/' . ltrim($relative, '/'))) {
                continue;
            }
            $result[] = $path;
        }
        sort($result, SORT_STRING);
        return $result;
    }

    private static function relativePath(string $root, string $file): string
    {
        $prefix = $root . '/';
        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }

    private static function lineOf(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * 扫描 app-client 与三主题的真实端点调用点。
     *
     * @param list<string> $themeDirs
     * @return array{calls:list<array{path:string,file:string,line:int,kind:string}>,skipped:list<string>}
     */
    public static function scanClientSources(string $appClientFile, array $themeDirs): array
    {
        $root = dirname(__DIR__);
        $files = [];
        if (is_file($appClientFile)) {
            $files[$appClientFile] = 'app-client';
        }
        foreach ($themeDirs as $dir) {
            foreach (self::jsFiles($dir) as $file) {
                $files[$file] = 'theme';
            }
        }

        $calls = [];
        $skipped = [];
        foreach ($files as $file => $kind) {
            $source = (string)file_get_contents((string)$file);
            foreach (self::extractJsLiterals($source) as $literal) {
                $start = $literal['start'];
                $before = substr($source, max(0, $start - 90), min(90, $start));
                if ($kind === 'app-client') {
                    // app-client 端点只认 url: '...' 与 uploadFile('...') 两种写法
                    $isEndpoint = (bool)preg_match('/(?:url\s*:|uploadFile\s*\()\s*$/', $before);
                    $relative = false;
                } else {
                    // 主题端点：request 包装器的第一个 / 第二个字面量参数，或 fetch(API_BASE + '...')
                    $isEndpoint = (bool)preg_match(
                        '/(?:app\.request|api\.request|request)\s*\(\s*(?:app\s*,\s*)?$/',
                        $before
                    ) || (bool)preg_match('/fetch\s*\(\s*API_BASE\s*\+\s*$/', $before);
                    $relative = true;
                }
                if (!$isEndpoint) {
                    continue;
                }

                // 动态字符串拼接无法可靠静态还原，宁可跳过也不误报漂移
                if (preg_match('/^\s*\+/', substr($source, $literal['end'], 40))) {
                    $skipped[] = self::relativePath($root, (string)$file) . ':'
                        . self::lineOf($source, $start) . ' 动态拼接端点已跳过';
                    continue;
                }

                $normalized = ClientEndpointManifestBuilder::normalizePath($literal['value'], $relative);
                if ($normalized === null || !str_starts_with($normalized, '/api/')) {
                    continue;
                }
                $calls[] = [
                    'path' => $normalized,
                    'file' => self::relativePath($root, (string)$file),
                    'line' => self::lineOf($source, $start),
                    'kind' => $kind,
                ];
            }
        }
        return ['calls' => $calls, 'skipped' => $skipped];
    }

    /** 读取生成器产出的端点清单，返回契约 path（已归一化、去重、排序） */
    public static function loadManifest(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("端点清单读取失败: {$path}（请先跑 php scripts/build-client-endpoints.php）");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['paths'] ?? null)) {
            throw new \RuntimeException("端点清单不是合法 JSON 或缺少 paths: {$path}");
        }
        $paths = [];
        foreach ($decoded['paths'] as $item) {
            $normalized = ClientEndpointManifestBuilder::normalizePath((string)$item);
            if ($normalized !== null) {
                $paths[$normalized] = true;
            }
        }
        $result = array_keys($paths);
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * 契约路径匹配：先精确匹配；再容忍「直接粘在路径段尾部的变量」——
     * 形如 \`/follow/stats${params}\` 的 ${params} 其实是拼好的 query 串变量，
     * 静态无法判定其内容是 query 还是子路径，去掉末尾粘连的 {param} 再匹配一次。
     * 独立的路径段 {param}（如 /notifications/{param}）不会被这条规则吞掉。
     *
     * @param array<string,true> $contract path => true
     */
    private static function matchContractPath(string $path, array $contract): bool
    {
        if (isset($contract[$path])) {
            return true;
        }
        $suffix = '{param}';
        $length = strlen($suffix);
        if (strlen($path) > $length
            && substr($path, -$length) === $suffix
            && $path[strlen($path) - $length - 1] !== '/'
        ) {
            return isset($contract[substr($path, 0, -$length)]);
        }
        return false;
    }

    /**
     * 不依赖基线的差异快照（生成基线时用）。
     *
     * @param list<string> $themeDirs
     * @return array{
     *   manifest_count:int,client_call_count:int,client_unique_count:int,
     *   matched:list<string>,drift:list<string>,unused:list<string>,
     *   skipped:list<string>,calls:list<array{path:string,file:string,line:int,kind:string}>
     * }
     */
    public static function snapshot(string $manifestFile, string $appClientFile, array $themeDirs): array
    {
        $contract = array_fill_keys(self::loadManifest($manifestFile), true);
        $scanned = self::scanClientSources($appClientFile, $themeDirs);

        $matched = [];
        $drift = [];
        foreach ($scanned['calls'] as $call) {
            if (self::matchContractPath($call['path'], $contract)) {
                $matched[$call['path']] = true;
            } else {
                $drift[$call['path']] = true;
            }
        }
        $matchedPaths = array_keys($matched);
        $driftPaths = array_keys($drift);
        $unused = array_values(array_diff(array_keys($contract), $matchedPaths));
        sort($matchedPaths, SORT_STRING);
        sort($driftPaths, SORT_STRING);
        sort($unused, SORT_STRING);

        $clientPaths = [];
        foreach ($scanned['calls'] as $call) {
            $clientPaths[$call['path']] = true;
        }

        return [
            'manifest_count' => count($contract),
            'client_call_count' => count($scanned['calls']),
            'client_unique_count' => count($clientPaths),
            'matched' => $matchedPaths,
            'drift' => $driftPaths,
            'unused' => $unused,
            'skipped' => $scanned['skipped'],
            'calls' => $scanned['calls'],
        ];
    }

    /**
     * 用真实差异固化基线（一次性生成 + 人工评审）。
     *
     * @param array{drift:list<string>,manifest_count:int,client_call_count:int,client_unique_count:int} $snapshot
     */
    public static function writeBaseline(string $path, array $snapshot): void
    {
        $payload = [
            'note' => 'P1-1 存量漂移冻结基线：门禁只拦 allowed_drift 之外的新增漂移，漂移数只减不增。'
                . '存量补齐后请重新执行 php scripts/check-client-api-contract.php --write-baseline 并人工评审。',
            'baseline_count' => count($snapshot['drift']),
            'manifest_count' => $snapshot['manifest_count'],
            'client_call_count' => $snapshot['client_call_count'],
            'client_unique_count' => $snapshot['client_unique_count'],
            'allowed_drift' => $snapshot['drift'],
        ];
        $json = (string)json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("基线写入失败: {$path}");
        }
    }

    /**
     * 对账并给出退出码（CLI 与单测共用同一份判定）。
     *
     * @param list<string> $themeDirs
     */
    public static function evaluate(
        string $manifestFile,
        string $appClientFile,
        array $themeDirs,
        string $baselinePath
    ): array {
        $snapshot = self::snapshot($manifestFile, $appClientFile, $themeDirs);
        $raw = @file_get_contents($baselinePath);
        if ($raw === false) {
            throw new \RuntimeException("基线文件读取失败: {$baselinePath}（可用 --write-baseline 生成）");
        }
        $baseline = json_decode($raw, true);
        if (!is_array($baseline) || !is_array($baseline['allowed_drift'] ?? null)) {
            throw new \RuntimeException("基线文件不是合法 JSON 或缺少 allowed_drift: {$baselinePath}");
        }

        $allowed = [];
        foreach ($baseline['allowed_drift'] as $item) {
            $allowed[] = trim((string)$item);
        }
        $allowed = array_values(array_unique($allowed));
        // 基线缺 baseline_count 时按 0 处理（fail closed），防止绕过「只减不增」
        $recordedCount = max(0, (int)($baseline['baseline_count'] ?? 0));
        $unexpected = array_values(array_diff($snapshot['drift'], $allowed));
        sort($unexpected, SORT_STRING);

        $reasons = [];
        if ($unexpected !== []) {
            $reasons[] = '基线外新增漂移 ' . count($unexpected) . ' 条';
        }
        if (count($snapshot['drift']) > $recordedCount) {
            $reasons[] = '漂移数 ' . count($snapshot['drift']) . ' 超过基线记录 ' . $recordedCount . '（只减不增）';
        }

        return [
            'manifest_count' => $snapshot['manifest_count'],
            'client_call_count' => $snapshot['client_call_count'],
            'client_unique_count' => $snapshot['client_unique_count'],
            'matched_count' => count($snapshot['matched']),
            'matched' => $snapshot['matched'],
            'drift' => $snapshot['drift'],
            'unused_count' => count($snapshot['unused']),
            'unused' => $snapshot['unused'],
            'skipped' => $snapshot['skipped'],
            'baseline_count' => $recordedCount,
            'allowed_drift' => $allowed,
            'unexpected' => $unexpected,
            'reasons' => $reasons,
            'exit_code' => $reasons === [] ? 0 : 1,
        ];
    }
}

/**
 * CLI 入口（被 require 时只提供类，不产生输出）。
 *
 * @param list<string> $argv
 */
function checkClientApiContractMain(array $argv): int
{
    $root = dirname(__DIR__);
    $manifestPath = $root . '/docs/client_endpoints.json';
    $baselinePath = $root . '/docs/client_api_baseline.json';
    $appClientFile = $root . '/app-client/src/api/index.js';
    $themeDirs = ClientApiContractChecker::defaultThemeDirs($root);
    $customThemes = [];

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--manifest=')) {
            $manifestPath = substr($arg, strlen('--manifest='));
        } elseif (str_starts_with($arg, '--baseline=')) {
            $baselinePath = substr($arg, strlen('--baseline='));
        } elseif (str_starts_with($arg, '--app-client=')) {
            $appClientFile = substr($arg, strlen('--app-client='));
        } elseif (str_starts_with($arg, '--theme=')) {
            $customThemes[] = substr($arg, strlen('--theme='));
        }
    }
    if ($customThemes !== []) {
        $themeDirs = $customThemes;
    }

    if (in_array('--help', $argv, true)) {
        echo "用法: php scripts/check-client-api-contract.php [--json] [--write-baseline] "
            . "[--manifest=path] [--baseline=path] [--app-client=path] [--theme=dir]...\n";
        return 0;
    }

    try {
        if (in_array('--write-baseline', $argv, true)) {
            $snapshot = ClientApiContractChecker::snapshot($manifestPath, $appClientFile, $themeDirs);
            ClientApiContractChecker::writeBaseline($baselinePath, $snapshot);
            echo '[client-api] 基线已写入 ' . $baselinePath . '：契约 '
                . $snapshot['manifest_count'] . ' path / 客户端 ' . $snapshot['client_call_count']
                . ' 处调用（唯一 ' . $snapshot['client_unique_count'] . '）/ '
                . count($snapshot['drift']) . " 条允许漂移\n";
            return 0;
        }

        $result = ClientApiContractChecker::evaluate(
            $manifestPath,
            $appClientFile,
            $themeDirs,
            $baselinePath
        );
        if (in_array('--json', $argv, true)) {
            echo (string)json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ), PHP_EOL;
            return $result['exit_code'];
        }

        echo '[client-api] 契约 ' . $result['manifest_count'] . ' path / 客户端调用 '
            . $result['client_call_count'] . ' 处（唯一 ' . $result['client_unique_count'] . "）\n";
        echo '[client-api] 命中契约 ' . $result['matched_count'] . ' / 漂移 '
            . count($result['drift']) . '（基线允许 ' . $result['baseline_count'] . '）/ '
            . '契约未被客户端使用 ' . $result['unused_count'] . "（信息级）\n";
        if ($result['skipped'] !== []) {
            echo '[client-api] 动态拼接跳过 ' . count($result['skipped']) . " 处（详见 --json）\n";
        }
        if ($result['unexpected'] !== []) {
            echo "[client-api] [FAIL] 基线外新增漂移（客户端调用但契约没有）：\n";
            foreach ($result['unexpected'] as $item) {
                echo '    ' . $item . "\n";
            }
        }
        foreach ($result['reasons'] as $reason) {
            if (!str_starts_with($reason, '基线外新增漂移')) {
                echo '[client-api] [FAIL] ' . $reason . "\n";
            }
        }
        if ($result['exit_code'] === 0) {
            echo "[client-api] 通过：漂移已被基线冻结，未扩大\n";
        } else {
            echo '[client-api] 失败：' . implode('；', $result['reasons']) . "\n";
        }
        return $result['exit_code'];
    } catch (\Throwable $e) {
        fwrite(STDERR, '[client-api] ' . $e->getMessage() . "\n");
        return 2;
    }
}

if (PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    exit(checkClientApiContractMain($argv));
}
