<?php
/**
 * 应用中心 · 站点端安装器
 *
 * 把从应用中心下载的 zip 包部署到本 woquan 站点：
 *  1. 下载流 sha256 与中心响应头 X-Package-Hash 校验（失败拒装）。
 *  2. zip 安全解压：拒绝 Zip Slip / 绝对路径 / 符号链接 / 超限。
 *  3. 插件：把应用中心清单包适配成 woquan 可发现的格式
 *     （目录 PascalCase、命名空间 Hublog\Plugin\{X}、init() 入口、docblock 元信息）。
 *  4. 主题：部署 theme.json + contract.json + 骨架页到 templates/{slug}/。
 *  5. 覆盖前备份到 cache/app-center-backups/，并写安装记录 + 建完整性基线。
 *
 * 适配逻辑移植自应用中心 app/Services/TargetSiteService.php::deployPluginForHublog，
 * 保持两端包格式兼容。
 */

declare(strict_types=1);

namespace Hublog\Core;

class AppCenterInstaller
{
    private const MAX_PACKAGE = 20971520;   // 20MB
    private const MAX_UNPACK = 104857600;   // 100MB

    /** @var string|null 测试用根目录覆盖（生产恒为 null）。 */
    private static ?string $testRoot = null;

    /** 测试钩子：把部署根目录指向临时目录，避免污染生产 plugins/ 与 templates/。 */
    public static function setTestRoot(?string $root): void
    {
        self::$testRoot = $root;
    }

    /** 根目录（woquan 项目根）。 */
    private static function root(): string
    {
        return self::$testRoot ?? dirname(__DIR__, 2);
    }

    private static function pluginDir(): string
    {
        return self::root() . '/plugins';
    }

    private static function themeDir(): string
    {
        return self::root() . '/templates/';
    }

    /**
     * 从应用中心在线安装/更新一个应用。
     *
     * @param array<string,mixed> $app 中心应用信息（含 id/slug/type/title 等）
     */
    public static function install(array $app, string $zipBinary, string $declaredHash): array
    {
        $appId = (int)($app['id'] ?? 0);
        $slug = (string)($app['slug'] ?? '');
        $type = (string)($app['type'] ?? 'plugin');
        $version = (string)($app['latest_version'] ?? $app['version'] ?? '1.0.0');
        if ($appId <= 0 || $slug === '' || !in_array($type, ['plugin', 'theme'], true)) {
            throw new AppCenterClientException('应用信息不完整或类型不合法', 0);
        }
        if ($zipBinary === '') {
            throw new AppCenterClientException('下载内容为空', 0);
        }

        // 1) sha256 校验（中心提供时必校）
        $actualHash = hash('sha256', $zipBinary);
        if ($declaredHash !== '' && !hash_equals(strtolower($declaredHash), $actualHash)) {
            throw new AppCenterClientException('应用包校验失败（sha256 不匹配），已拒绝安装', 0);
        }

        // 2) 落临时文件 + 安全解压
        $tmpBase = self::root() . '/cache/app-center-tmp';
        self::ensureDir($tmpBase);
        $workDir = $tmpBase . '/install_' . bin2hex(random_bytes(6));
        self::ensureDir($workDir);
        $zipFile = $workDir . '/package.zip';
        $extractDir = $workDir . '/extracted';
        $target = null;
        $backup = null;

        try {
            if (@file_put_contents($zipFile, $zipBinary) === false) {
                throw new \RuntimeException('无法写入临时应用包');
            }
            self::extractZip($zipFile, $extractDir);
            $source = self::singleRoot($extractDir);
            $manifest = self::readManifest($source, $type, $slug);

            // 3) 计算目标目录
            $dirName = $type === 'plugin'
                ? self::pascalSlug((string)($manifest['name'] ?? $slug))
                : (self::safeDirName((string)($manifest['id'] ?? $slug)) ? (string)($manifest['id'] ?? $slug) : $slug);
            if ($dirName === '') {
                $dirName = $type === 'plugin' ? self::pascalSlug($slug) : $slug;
            }
            $base = $type === 'plugin' ? self::pluginDir() : rtrim(self::themeDir(), '/');
            self::ensureDir($base);
            $target = $base . '/' . $dirName;
            if (!self::pathSafe($base, $target)) {
                throw new \RuntimeException('部署路径越界');
            }

            // 4) 覆盖前备份
            if (is_dir($target)) {
                $backupDir = self::root() . '/cache/app-center-backups';
                self::ensureDir($backupDir);
                $backup = $backupDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $dirName) . '-' . date('YmdHis');
                self::copyTree($target, $backup);
            }

            // 5) 部署
            self::removeTree($target);
            self::ensureDir($target);
            $warnings = [];
            if ($type === 'plugin') {
                $warnings = self::deployPlugin($source, $target, $manifest, $dirName);
            } else {
                self::copyTree($source, $target);
                $warnings = self::checkThemeContract($target);
            }

            // 6) 记录 + 基线
            AppCenterStore::recordInstall($type, $dirName, [
                'app_id' => $appId,
                'slug' => $dirName,
                'version' => $version,
                'hash' => $actualHash,
                'backup' => $backup,
                'source' => 'appcenter',
            ]);
            $baseline = AppCenterStore::buildBaseline($type, $dirName, $target);

            return [
                'app_id' => $appId,
                'type' => $type,
                'slug' => $dirName,
                'version' => $version,
                'deploy_path' => $target,
                'backup' => $backup,
                'warnings' => $warnings,
                'baseline' => $baseline,
            ];
        } finally {
            self::removeTree($workDir);
        }
    }

    /** 卸载：删目录 + 删记录 + 删基线（目录先备份）。 */
    public static function uninstall(string $type, string $slug): array
    {
        if (!in_array($type, ['plugin', 'theme'], true)) {
            throw new AppCenterClientException('应用类型不合法', 0);
        }
        if (!self::safeDirName($slug)) {
            throw new AppCenterClientException('目录名不合法', 0);
        }
        $base = $type === 'plugin' ? self::pluginDir() : rtrim(self::themeDir(), '/');
        $target = $base . '/' . $slug;
        if (!self::pathSafe($base, $target)) {
            throw new \RuntimeException('目标路径越界');
        }
        if (!is_dir($target)) {
            throw new AppCenterClientException('本机未找到该扩展', 404);
        }
        $backupDir = self::root() . '/cache/app-center-backups';
        self::ensureDir($backupDir);
        $backup = $backupDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $slug) . '-uninstall-' . date('YmdHis');
        self::copyTree($target, $backup);
        self::removeTree($target);
        AppCenterStore::removeInstall($type, $slug);
        AppCenterStore::deleteBaseline($type, $slug);
        return ['type' => $type, 'slug' => $slug, 'removed' => true, 'backup' => $backup];
    }

    // ==================== 适配核心（移植自应用中心） ====================

    /** 把清单包适配成 woquan 可发现的 Hublog 插件格式。 */
    private static function deployPlugin(string $source, string $target, array $manifest, string $dirName): array
    {
        self::copyTree($source, $target);
        $entry = self::safeFileName((string)($manifest['entry'] ?? 'Plugin.php')) ? (string)($manifest['entry'] ?? 'Plugin.php') : 'Plugin.php';
        $entryPath = $target . '/' . $entry;
        $warnings = [];
        if (!is_file($entryPath)) {
            return ['入口文件不存在：' . $entry];
        }

        $content = (string)@file_get_contents($entryPath);
        $pascal = self::pascalSlug((string)($manifest['name'] ?? $dirName));
        if ($pascal === '') {
            $pascal = $dirName;
        }

        // 1) 注入 woquan parseMeta 需要的 docblock（清单优先）
        $title = self::oneLine((string)($manifest['title'] ?? $manifest['name'] ?? $dirName));
        $description = self::oneLine((string)($manifest['description'] ?? ''));
        $version = self::oneLine((string)($manifest['version'] ?? '1.0.0'));
        $author = self::oneLine((string)($manifest['author'] ?? 'appcenter'));
        $doc = "/**\n * " . $title . "\n"
            . " * " . $description . "\n"
            . " * @name " . $title . "\n"
            . " * @description " . $description . "\n"
            . " * @version " . $version . "\n"
            . " * @author " . $author . "\n */\n";
        $content = preg_replace('/<\?php\s*\/\*\*.*?\*\//s', '<?php', $content, 1) ?? $content;
        $content = preg_replace('/<\?php\s*/', "<?php\n" . $doc . "\n", $content, 1) ?? $content;

        // 2) 命名空间：任意 AppCenter\...\Plugin\{X} → Hublog\Plugin\{Pascal}
        if (preg_match('/^namespace\s+[^;]+;/m', $content, $m) === 1) {
            $content = str_replace($m[0], 'namespace Hublog\\Plugin\\' . $pascal . ';', $content);
        } else {
            $content = preg_replace('/(<\?php\s*(?:\/\*\*.*?\*\/\s*)?)/s', '${1}namespace Hublog\\Plugin\\' . $pascal . ";\n", $content, 1) ?? $content;
            $warnings[] = '源文件缺少命名空间，已注入 Hublog\\Plugin\\' . $pascal;
        }

        // 3) HookEngine → woquan Hook；boot(HookEngine) → init()
        $hadHookEngine = strpos($content, 'HookEngine') !== false;
        if ($hadHookEngine) {
            $content = str_replace('use AppCenter\\Services\\HookEngine;', 'use Hublog\\Core\\Hook;', $content);
            $content = str_replace('$hooks->', 'Hook::', $content);
        }
        if (preg_match('/function\s+init\s*\(/', $content) !== 1) {
            if (preg_match('/function\s+boot\s*\([^)]*\)/', $content) === 1) {
                $content = preg_replace('/function\s+boot\s*\([^)]*\)/', 'function init()', $content, 1) ?? $content;
                $warnings[] = '已将 boot(HookEngine) 适配为 woquan 的 init()';
            } else {
                $pos = strrpos($content, '}');
                if ($pos !== false) {
                    $content = substr($content, 0, $pos)
                        . "\n    public function init(): void { /* 由应用中心在线安装生成，按需补全业务逻辑 */ }\n"
                        . substr($content, $pos);
                    $warnings[] = '源文件无 init()，已注入空实现';
                }
            }
        }

        if (@file_put_contents($entryPath, $content) === false) {
            throw new \RuntimeException('无法写入适配后的插件入口');
        }
        if ($hadHookEngine) {
            $warnings[] = 'HookEngine 调用已转换为 Hublog\\Core\\Hook 静态方法';
        }

        // 4) 适配后语法自检（语法坏了就不让装，避免拖垮全站插件加载）
        $lint = self::phpLint($entryPath);
        if ($lint !== '') {
            throw new \RuntimeException('适配后插件入口语法错误：' . $lint);
        }
        return $warnings;
    }

    /** 主题契约自检：theme.json + contract.json + 三页骨架。 */
    private static function checkThemeContract(string $dir): array
    {
        $warnings = [];
        foreach (['theme.json', 'index.html', 'apps.html', 'app.html'] as $file) {
            if (!is_file($dir . '/' . $file)) {
                $warnings[] = '主题缺少 ' . $file;
            }
        }
        if (!is_file($dir . '/contract.json')) {
            $warnings[] = '主题缺少 contract.json（契约校验可能不完整）';
        }
        return $warnings;
    }

    /** 读清单（plugin.json / theme.json），失败从 docblock 兜底。 */
    private static function readManifest(string $dir, string $type, string $slug): array
    {
        $file = $dir . '/' . ($type === 'theme' ? 'theme.json' : 'plugin.json');
        if (is_file($file)) {
            $decoded = json_decode((string)@file_get_contents($file), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $entry = $dir . '/Plugin.php';
        $out = ['name' => $slug, 'version' => '1.0.0', 'title' => $slug, 'description' => '', 'author' => ''];
        if (is_file($entry)) {
            $head = (string)@file_get_contents($entry);
            foreach (['title', 'name', 'version', 'description', 'author'] as $field) {
                if (preg_match('/@' . $field . '\s+(.+)$/m', $head, $m) === 1) {
                    $out[$field === 'name' ? 'title' : $field] = trim($m[1]);
                }
            }
        }
        return $out;
    }

    // ==================== 通用文件工具 ====================

    /** 安全解压 zip（拒绝 Zip Slip / 绝对路径 / 符号链接 / 超限）。 */
    private static function extractZip(string $zipFile, string $dest): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('服务端缺少 zip 扩展');
        }
        if ((int)@filesize($zipFile) > self::MAX_PACKAGE) {
            throw new \RuntimeException('应用包超过大小上限');
        }
        $zip = new \ZipArchive();
        $rc = $zip->open($zipFile);
        if ($rc !== true) {
            throw new \RuntimeException('无法打开 zip 应用包（code=' . $rc . '）');
        }
        self::ensureDir($dest);
        $base = realpath($dest);
        if ($base === false) {
            $zip->close();
            throw new \RuntimeException('解压目录不可访问');
        }
        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    continue;
                }
                $name = (string)($stat['name'] ?? '');
                $total += (int)($stat['size'] ?? 0);
                if (!self::safeZipEntry($name, $stat)) {
                    throw new \RuntimeException('应用包包含不安全路径：' . $name);
                }
            }
            if ($total > self::MAX_UNPACK) {
                throw new \RuntimeException('解压后总大小超过上限');
            }
            if (!$zip->extractTo($base)) {
                throw new \RuntimeException('解压失败');
            }
            // 解压后再查符号链接并清理越界
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $info) {
                /** @var \SplFileInfo $info */
                if (is_link($info->getPathname())) {
                    @unlink($info->getPathname());
                    throw new \RuntimeException('应用包包含符号链接，已拒绝');
                }
                if (!self::pathSafe($base, $info->getPathname())) {
                    throw new \RuntimeException('解压路径越界');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private static function safeZipEntry(string $name, array $stat): bool
    {
        if ($name === '' || strpos($name, "\0") !== false) {
            return false;
        }
        if (str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }
        if (preg_match('#://#', $name) === 1) {
            return false;
        }
        foreach (explode('/', str_replace('\\', '/', $name)) as $seg) {
            if ($seg === '..') {
                return false;
            }
        }
        $external = (int)($stat['external_attributes'] ?? 0);
        $mode = ($external >> 16) & 0177777;
        return !((($mode & 0170000) === 0120000)); // 拒绝符号链接
    }

    /** zip 单一顶层目录。 */
    private static function singleRoot(string $dir): string
    {
        $children = [];
        foreach (scandir($dir, SCANDIR_SORT_ASCENDING) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $children[] = $item;
            }
        }
        return count($children) === 1 && is_dir($dir . '/' . $children[0]) ? $dir . '/' . $children[0] : $dir;
    }

    /** 递归复制目录。 */
    private static function copyTree(string $src, string $dst): void
    {
        if (!is_dir($src)) {
            return;
        }
        self::ensureDir($dst);
        foreach (scandir($src, SCANDIR_SORT_ASCENDING) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $s = $src . '/' . $entry;
            $d = $dst . '/' . $entry;
            if (is_dir($s)) {
                self::copyTree($s, $d);
            } elseif (is_file($s)) {
                @copy($s, $d);
            }
        }
    }

    /** 递归删除目录/文件。 */
    public static function removeTree(string $target): void
    {
        if (is_link($target) || (is_file($target))) {
            @unlink($target);
            return;
        }
        if (!is_dir($target)) {
            return;
        }
        foreach (scandir($target, SCANDIR_SORT_ASCENDING) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($target . '/' . $entry);
        }
        @rmdir($target);
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建目录：' . $dir);
        }
    }

    private static function pathSafe(string $base, string $target): bool
    {
        $baseReal = realpath($base);
        if ($baseReal === false) {
            return false;
        }
        $targetReal = realpath($target);
        if ($targetReal === false) {
            $parent = realpath(dirname($target));
            if ($parent === false) {
                return false;
            }
            $targetReal = $parent . '/' . basename($target);
        }
        $baseN = rtrim(str_replace('\\', '/', $baseReal), '/');
        $targetN = rtrim(str_replace('\\', '/', $targetReal), '/');
        return $targetN === $baseN || strpos($targetN, $baseN . '/') === 0;
    }

    private static function pascalSlug(string $slug): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $slug, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        foreach ($parts as $part) {
            $out .= ucfirst($part);
        }
        return $out;
    }

    private static function safeDirName(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name) === 1;
    }

    private static function safeFileName(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/', $name) === 1 && strpos($name, '..') === false;
    }

    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', $text) ?? $text);
    }

    /** 用 PHP 二进制做语法检查，返回错误串或空。 */
    private static function phpLint(string $file): string
    {
        $bin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($bin) . ' -l ' . escapeshellarg($file) . ' 2>&1';
        $out = [];
        @exec($cmd, $out, $code);
        return $code === 0 ? '' : implode(' ', $out);
    }
}
