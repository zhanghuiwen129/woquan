<?php
/**
 * 应用中心 · 站点端本地存储
 *
 * 职责：
 *  1. 配置读写（中心 API 地址 / site_key / site_secret）：存 settings 表，key 前缀 app_center_；
 *     site_secret 属敏感项，对外输出时掩码。
 *  2. 已安装扩展记录：cache/app-center-installs.json（版本 / 来源 / app_id / 安装时间）。
 *  3. 完整性基线：cache/app-center-baselines/{slug}.json（文件清单 + sha256），用于篡改检测。
 *
 * 不改 woquan 任何数据库结构，全部复用 settings 表 + 可写 cache 目录，符合「增量合并」原则。
 */

declare(strict_types=1);

namespace Hublog\Core;

class AppCenterStore
{
    public const K_API = 'app_center_api';
    public const K_KEY = 'app_center_site_key';
    public const K_SECRET = 'app_center_site_secret';
    public const K_NAME = 'app_center_site_name';
    public const K_AUTO_CHECK = 'app_center_auto_check';

    /** @var string|null 测试用缓存目录覆盖（生产恒为 null）。 */
    private static ?string $testCacheDir = null;

    /** 测试钩子：把安装记录/基线存储指向临时目录。 */
    public static function setTestCacheDir(?string $dir): void
    {
        self::$testCacheDir = $dir;
    }

    /** 缓存目录（与 Cache 一致）。 */
    private static function cacheDir(): string
    {
        return self::$testCacheDir ?? (dirname(__DIR__, 2) . '/cache/');
    }

    private static function installsFile(): string
    {
        return self::cacheDir() . 'app-center-installs.json';
    }

    private static function baselineDir(): string
    {
        return self::cacheDir() . 'app-center-baselines/';
    }

    // ==================== 配置 ====================

    /** 对外输出的配置（site_secret 掩码，不泄露）。 */
    public static function config(): array
    {
        $secret = self::get(self::K_SECRET);
        return [
            'api' => self::get(self::K_API, defined('APP_CENTER_URL') ? APP_CENTER_URL : ''),
            'site_key' => self::get(self::K_KEY),
            'site_name' => self::get(self::K_NAME),
            'site_secret_masked' => $secret !== '' ? substr($secret, 0, 6) . '****' . substr($secret, -4) : '',
            'registered' => $secret !== '' && self::get(self::K_KEY) !== '',
            'auto_check' => self::get(self::K_AUTO_CHECK, '1') === '1',
        ];
    }

    /** 保存中心地址（可空）。 */
    public static function setApi(string $url): void
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('应用中心地址必须以 http:// 或 https:// 开头');
        }
        self::set(self::K_API, rtrim($url, '/'));
    }

    /** 注册成功后落库 key/secret/name。 */
    public static function saveRegistration(string $siteKey, string $siteSecret, string $name): void
    {
        if ($siteKey === '' || $siteSecret === '') {
            throw new \InvalidArgumentException('站点凭据不能为空');
        }
        self::set(self::K_KEY, $siteKey);
        self::set(self::K_SECRET, $siteSecret);
        self::set(self::K_NAME, $name);
    }

    public static function clearRegistration(): void
    {
        foreach ([self::K_KEY, self::K_SECRET, self::K_NAME] as $k) {
            self::set($k, '');
        }
    }

    /** 构造一个已配置好的请求器；未注册时抛异常。 */
    public static function client(): AppCenterClient
    {
        $api = self::get(self::K_API, '');
        if ($api === '' && defined('APP_CENTER_URL')) {
            // APP_CENTER_URL 默认指向 admin.php；推导 api.php 作为兜底
            $api = preg_replace('#/admin\.php$#', '/api.php', APP_CENTER_URL) ?? APP_CENTER_URL;
        }
        return new AppCenterClient(
            $api,
            self::get(self::K_KEY),
            self::get(self::K_SECRET)
        );
    }

    /** 读单个配置（优先 settings 表）。 */
    private static function get(string $key, string $default = ''): string
    {
        $val = SettingsRepository::get($key, null);
        return $val === null || $val === '' ? $default : (string)$val;
    }

    /** 写单个配置（先探测再 upsert，复用仓储层，清 settings 缓存）。 */
    private static function set(string $key, string $value): void
    {
        SettingsRepository::upsert($key, $value, date('Y-m-d H:i:s'));
        Cache::delete('site_settings');
    }

    // ==================== 安装记录 ====================

    /** @return array<string,array<string,mixed>> key = type:slug */
    public static function installs(): array
    {
        $file = self::installsFile();
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string)@file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,array<string,mixed>> $installs */
    public static function saveInstalls(array $installs): void
    {
        self::ensureCacheDir();
        $json = json_encode($installs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (@file_put_contents(self::installsFile(), $json, LOCK_EX) === false) {
            throw new \RuntimeException('无法写入应用中心安装记录');
        }
    }

    /** 记录一次安装/更新。 */
    public static function recordInstall(string $type, string $slug, array $info): void
    {
        $installs = self::installs();
        $installs[$type . ':' . $slug] = array_merge($info, [
            'type' => $type,
            'slug' => $slug,
            'installed_at' => date('Y-m-d H:i:s'),
        ]);
        self::saveInstalls($installs);
    }

    /** 删除一条安装记录。 */
    public static function removeInstall(string $type, string $slug): void
    {
        $installs = self::installs();
        unset($installs[$type . ':' . $slug]);
        self::saveInstalls($installs);
    }

    /** 取某条安装记录。 */
    public static function install(string $type, string $slug): ?array
    {
        return self::installs()[$type . ':' . $slug] ?? null;
    }

    // ==================== 完整性基线 ====================

    /**
     * 为某个已安装扩展建立基线：扫描目录内所有文件（排除可变目录），记录 sha256。
     *
     * @return array{slug:string, files:int, hash:string, created_at:string}
     */
    public static function buildBaseline(string $type, string $slug, string $dir): array
    {
        if (!is_dir($dir)) {
            throw new \RuntimeException('扩展目录不存在，无法建立基线');
        }
        $files = self::scanFiles($dir, $dir);
        $manifest = [];
        foreach ($files as $rel) {
            $manifest[$rel] = @hash_file('sha256', $dir . '/' . $rel) ?: '';
        }
        ksort($manifest);
        $overall = hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES) ?: '');
        $data = [
            'type' => $type,
            'slug' => $slug,
            'root' => $dir,
            'files' => $manifest,
            'hash' => $overall,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        self::ensureBaselineDir();
        $file = self::baselineFile($type, $slug);
        if (@file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new \RuntimeException('无法写入完整性基线');
        }
        return ['slug' => $slug, 'files' => count($manifest), 'hash' => $overall, 'created_at' => $data['created_at']];
    }

    /**
     * 校验扩展是否被篡改：modified / added / deleted。
     *
     * @return array{status:string, modified:array, added:array, deleted:array, baseline_at:?string}
     */
    public static function verifyBaseline(string $type, string $slug): array
    {
        $file = self::baselineFile($type, $slug);
        if (!is_file($file)) {
            return ['status' => 'no_baseline', 'modified' => [], 'added' => [], 'deleted' => [], 'baseline_at' => null];
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            return ['status' => 'no_baseline', 'modified' => [], 'added' => [], 'deleted' => [], 'baseline_at' => null];
        }
        $dir = (string)($data['root'] ?? '');
        if ($dir === '' || !is_dir($dir)) {
            return ['status' => 'missing_dir', 'modified' => [], 'added' => [], 'deleted' => [], 'baseline_at' => $data['created_at'] ?? null];
        }
        $current = [];
        foreach (self::scanFiles($dir, $dir) as $rel) {
            $current[$rel] = @hash_file('sha256', $dir . '/' . $rel) ?: '';
        }
        $modified = [];
        foreach ($data['files'] as $rel => $hash) {
            if (!array_key_exists($rel, $current)) {
                $modified[] = $rel; // 基线里的文件消失也归为改动（用 deleted 更准，兼容保留）
            } elseif (!hash_equals((string)$hash, (string)$current[$rel])) {
                $modified[] = $rel;
            }
        }
        $deleted = array_values(array_diff(array_keys($data['files']), array_keys($current)));
        $modified = array_values(array_diff($modified, $deleted));
        $added = array_values(array_diff(array_keys($current), array_keys($data['files'])));
        sort($modified);
        sort($added);
        sort($deleted);
        $status = ($modified === [] && $added === [] && $deleted === []) ? 'ok' : 'tampered';
        return [
            'status' => $status,
            'modified' => $modified,
            'added' => $added,
            'deleted' => $deleted,
            'baseline_at' => $data['created_at'] ?? null,
        ];
    }

    /** 删除基线。 */
    public static function deleteBaseline(string $type, string $slug): bool
    {
        $file = self::baselineFile($type, $slug);
        return is_file($file) ? @unlink($file) : true;
    }

    /** 列出所有已建基线的 slug。 */
    public static function baselineList(): array
    {
        $dir = self::baselineDir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '*.json') ?: [] as $file) {
            $decoded = json_decode((string)@file_get_contents($file), true);
            if (is_array($decoded)) {
                $out[] = [
                    'type' => (string)($decoded['type'] ?? ''),
                    'slug' => (string)($decoded['slug'] ?? ''),
                    'files' => is_array($decoded['files'] ?? null) ? count($decoded['files']) : 0,
                    'created_at' => (string)($decoded['created_at'] ?? ''),
                ];
            }
        }
        return $out;
    }

    // ==================== 私有辅助 ====================

    /** 递归扫描文件，返回相对路径（排除可变目录与隐藏文件）。 */
    private static function scanFiles(string $root, string $dir): array
    {
        $out = [];
        $excludeDirs = ['node_modules', 'vendor', '.git', 'cache', 'logs', 'uploads'];
        foreach (scandir($dir, SCANDIR_SORT_ASCENDING) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            $rel = ltrim(str_replace($root, '', $full), '/');
            if (is_dir($full)) {
                if (in_array(strtolower($entry), $excludeDirs, true)) {
                    continue;
                }
                $out = array_merge($out, self::scanFiles($root, $full));
            } elseif (is_file($full)) {
                $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }

    private static function baselineFile(string $type, string $slug): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $type . '_' . $slug);
        return self::baselineDir() . $safe . '.json';
    }

    private static function ensureCacheDir(): void
    {
        $dir = self::cacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建缓存目录');
        }
    }

    private static function ensureBaselineDir(): void
    {
        $dir = self::baselineDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建基线目录');
        }
    }
}
