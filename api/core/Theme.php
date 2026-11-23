<?php
declare(strict_types=1);
/**
 * Hublog 前台主题系统 —— 主题解析、发现与契约校验
 *
 * 设计要点（改动前必读）：
 *   1. 本类刻意「无状态」：current() 每次都走 Cache::remember('site_settings')，
 *      不引入请求级静态缓存。PHP-FPM worker 与本地 php -S 都是常驻进程，静态属性会跨请求
 *      保留；一旦缓存当前主题，后台切主题后旧 worker 会一直返回旧主题，破坏「保存即生效」。
 *      site_settings 本身是文件/Redis 缓存，读取极廉价，且后台保存时已 Cache::delete 失效。
 *   2. 校验逻辑集中在 resolve()，前台入口（已有 $siteSettings）直接调它做纯校验；
 *      API 侧 MediaHelper 没有 settings 上下文，调 current() 读缓存后再 resolve()。
 *      两条路共用同一份校验，杜绝「入口与 MediaHelper 逻辑分叉」。
 *   3. 白名单是「动态的」= templates/ 下真实存在、含 index.html、且未越界（realpath 校验）
 *      的目录。绝不硬编码主题名数组——否则新增主题又要改 PHP，违背「放目录即用」的目标。
 */
namespace Hublog\Core;

class Theme
{
    /** 主题名最大长度，防超长/65535 之类的畸形输入 */
    public const MAX_NAME_LENGTH = 64;

    /**
     * 一套完整主题必须具备的 18 个骨架页（与 index.php 路由表一一对应）。
     * 发现接口的契约校验据此判定「主题是否完整」，缺一个即标 invalid。
     */
    public const REQUIRED_PAGES = [
        'index', 'post', 'user', 'followers', 'following', 'pinned', 'favorites',
        'go', 'category', 'topic', 'circles', 'circle', 'search', 'chat', 'messages',
        'reset-password', 'wallet', 'points',
    ];

    /** 主题清单必填字段（缺一即 invalid） */
    private const REQUIRED_MANIFEST_KEYS = ['name', 'version', 'contract'];

    /** 默认主题名（config.php 未定义常量时的终极兜底） */
    public static function default(): string
    {
        return defined('DEFAULT_TEMPLATE') && DEFAULT_TEMPLATE !== ''
            ? (string)DEFAULT_TEMPLATE
            : 'moments';
    }

    /** 模板根目录的绝对路径（带尾分隔符） */
    public static function rootPath(): string
    {
        $root = defined('TEMPLATE_DIR') && TEMPLATE_DIR !== ''
            ? (string)TEMPLATE_DIR
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'templates';
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
    }

    /** 主题目录的站点根相对路径，如 templates/moments/ */
    public static function dir(string $theme): string
    {
        return 'templates/' . $theme . '/';
    }

    /** 主题目录的绝对路径（带尾分隔符），仅内部校验用 */
    private static function absDir(string $theme): string
    {
        return self::rootPath() . $theme . DIRECTORY_SEPARATOR;
    }

    /**
     * 三重校验解析主题名：格式白名单 + 目录/骨架存在 + realpath 未越界。
     * 纯函数、不读库、不缓存，任何非法值静默回退默认主题（管理员手滑不能让整站 500）。
     *
     * @param string|null $name 原始主题名（通常来自 settings.active_theme）
     */
    public static function resolve(?string $name): string
    {
        $fallback = self::default();
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || strlen($name) > self::MAX_NAME_LENGTH) {
            return $fallback;
        }
        // 只允许 [A-Za-z0-9_-]：../../etc、a/b、.. 全部挡在门外（天然防目录穿越）
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            return $fallback;
        }
        $dir = self::absDir($name);
        // 准入三重校验：目录存在 + 骨架页 + 契约清单存在（契约由 scripts/audit-theme.js 生成/审计）。
        // 要求 contract.json 是为了让「半成品主题（缺页/缺 JS 依赖 id）」无法被真正启用——
        // 没跑过契约审计的主题，绝不让它上线静默丢功能。moments 作为基准主题自带该清单。
        if (!is_dir($dir) || !is_file($dir . 'index.html') || !is_file($dir . 'contract.json')) {
            return $fallback;
        }
        // 符号链接越界防护：realpath 必须仍位于 templates 根之内
        $realDir = realpath($dir);
        $realRoot = realpath(self::rootPath());
        if ($realDir === false || ($realRoot !== false && strpos($realDir, $realRoot . DIRECTORY_SEPARATOR) !== 0)) {
            return $fallback;
        }
        return $name;
    }

    /**
     * 当前生效主题：读 site_settings 缓存里的 active_theme 后校验。
     * API 侧（MediaHelper）没有 settings 上下文时使用；前台 index.php 已有 $siteSettings，
     * 直接调 resolve() 即可，无需二次读缓存。
     */
    public static function current(): string
    {
        try {
            $settings = Cache::remember('site_settings', function () {
                $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");
                $result = [];
                foreach ($rows as $row) {
                    $result[$row['key']] = $row['value'];
                }
                return $result;
            }, 3600);
            return self::resolve(isset($settings['active_theme']) ? (string)$settings['active_theme'] : null);
        } catch (\Throwable $e) {
            // 安装中 / settings 表暂不可用时：回退默认，绝不抛给调用方
            return self::default();
        }
    }

    /**
     * 扫描 templates 下各主题的 theme.json，返回全部主题及契约校验结果。
     * 供后台「主题管理」发现接口 GET /admin/themes 使用。
     *
     * @return array<int, array{id:string,name:string,version:string,author:string,description:string,preview:?string,contract:string,inherits_js:string,active:bool,valid:bool,issues:array<int,string>}>
     */
    public static function discover(): array
    {
        $active = self::current();
        $root = self::rootPath();
        $manifests = glob($root . '*/theme.json') ?: [];
        $themes = [];
        foreach ($manifests as $manifest) {
            $id = basename(dirname($manifest));
            $raw = @file_get_contents($manifest);
            $meta = $raw === false ? null : json_decode($raw, true);
            if (!is_array($meta)) {
                $themes[] = self::invalidTheme($id, ['theme.json 不是合法 JSON']);
                continue;
            }
            [$valid, $issues] = self::validate($id, $meta);
            $preview = isset($meta['preview']) && is_string($meta['preview']) && $meta['preview'] !== ''
                ? self::dir($id) . ltrim($meta['preview'], '/')
                : null;
            if ($preview !== null && !is_file(self::rootPath() . $id . DIRECTORY_SEPARATOR . ltrim((string)$meta['preview'], '/'))) {
                $issues[] = '预览图文件不存在: ' . $meta['preview'];
                $valid = false;
            }
            $themes[] = [
                'id'          => $id,
                'name'        => isset($meta['name']) ? (string)$meta['name'] : $id,
                'version'     => isset($meta['version']) ? (string)$meta['version'] : '',
                'author'      => isset($meta['author']) ? (string)$meta['author'] : '',
                'description' => isset($meta['description']) ? (string)$meta['description'] : '',
                'preview'     => $preview,
                'contract'    => isset($meta['contract']) ? (string)$meta['contract'] : '',
                'inherits_js' => isset($meta['inherits_js']) ? (string)$meta['inherits_js'] : 'moments',
                'active'      => $id === $active,
                'valid'       => $valid,
                'issues'      => $issues,
            ];
        }
        // 默认主题排最前，其余按 id 字典序，保证后台卡片顺序稳定
        usort($themes, static function (array $a, array $b): int {
            if ($a['id'] === self::default()) return -1;
            if ($b['id'] === self::default()) return 1;
            return strcmp($a['id'], $b['id']);
        });
        return $themes;
    }

    /** 契约校验：清单必填字段 + 18 页齐全 + JS/图片/皮肤资源齐全。返回 [是否有效, 问题列表] */
    public static function validate(string $id, array $meta): array
    {
        $issues = [];
        foreach (self::REQUIRED_MANIFEST_KEYS as $key) {
            if (!array_key_exists($key, $meta) || $meta[$key] === '' || $meta[$key] === null) {
                $issues[] = 'theme.json 缺少必填字段: ' . $key;
            }
        }
        $dir = self::absDir($id);
        foreach (self::REQUIRED_PAGES as $page) {
            if (!is_file($dir . $page . '.html')) {
                $issues[] = '缺少页面: ' . $page . '.html';
            }
        }
        foreach (['assets/css/style.css', 'assets/js/app.js', 'assets/js/modules', 'assets/images/default-avatar.svg'] as $asset) {
            if (!file_exists($dir . str_replace('/', DIRECTORY_SEPARATOR, $asset))) {
                $issues[] = '缺少资源: ' . $asset;
            }
        }
        // 契约清单：没生成 = 没跑过 DOM 契约审计，禁止启用（与 resolve() 准入一致）
        if (!is_file($dir . 'contract.json')) {
            $issues[] = '缺少 DOM 契约清单 contract.json（请运行 node scripts/audit-theme.js --gen 并审计）';
        }
        return [$issues === [], $issues];
    }

    private static function invalidTheme(string $id, array $issues): array
    {
        return [
            'id' => $id, 'name' => $id, 'version' => '', 'author' => '', 'description' => '',
            'preview' => null, 'contract' => '', 'inherits_js' => 'moments',
            'active' => false, 'valid' => false, 'issues' => $issues,
        ];
    }

    /** 主题外观选项 schema 文件路径（不存在时返回空串） */
    public static function optionsFile(string $theme): string
    {
        return self::absDir($theme) . 'settings.json';
    }

    /**
     * 读取主题 settings.json 字段定义。
     * 支持类型：text / textarea / color / select / switch / number / image。
     * 文件缺失或非法时返回空数组（主题没有外观选项面板）。
     *
     * @return array<int, array{key:string,type:string,label:string,desc:string,default:mixed,options:array}>
     */
    public static function optionsSchema(string $theme): array
    {
        $file = self::optionsFile($theme);
        if (!is_file($file)) return [];
        $raw = @file_get_contents($file);
        $meta = $raw === false ? null : json_decode($raw, true);
        if (!is_array($meta) || !isset($meta['fields']) || !is_array($meta['fields'])) return [];
        $fields = [];
        foreach ($meta['fields'] as $f) {
            if (!is_array($f) || !isset($f['key']) || !is_string($f['key']) || $f['key'] === '') continue;
            $fields[] = [
                'key'     => (string)$f['key'],
                'type'    => isset($f['type']) ? (string)$f['type'] : 'text',
                'label'   => isset($f['label']) ? (string)$f['label'] : $f['key'],
                'group'   => isset($f['group']) && is_string($f['group']) ? (string)$f['group'] : '',
                'desc'    => isset($f['desc']) ? (string)$f['desc'] : '',
                'default' => array_key_exists('default', $f) ? $f['default'] : null,
                'options' => isset($f['options']) && is_array($f['options']) ? $f['options'] : [],
                // 首屏服务端内联声明（主题自描述，index.php 通用读取，无需按主题名硬编码）：
                //   cssVar  — 写入 :root 的 CSS 变量名（配色/宽度类），如 "--primary"
                //   cssUnit — 数值单位，仅数字类用，如 "px"
                //   cssRule — 整段 CSS 规则模板，用 %s 作为净化后选项值的占位符（如背景图）
                'cssVar'  => isset($f['cssVar']) && is_string($f['cssVar']) ? $f['cssVar'] : '',
                'cssUnit' => isset($f['cssUnit']) && is_string($f['cssUnit']) ? $f['cssUnit'] : '',
                'cssRule' => isset($f['cssRule']) && is_string($f['cssRule']) ? $f['cssRule'] : '',
            ];
        }
        return $fields;
    }

    /**
     * 读取某主题已保存的选项（JSON 解码为关联数组；无记录或损坏返回空数组）。
     * 各主题选项分别落库（theme_opts_{theme}），切换主题不会清掉其它主题的存档。
     */
    public static function readSavedOptions(string $theme): array
    {
        try {
            $row = Database::fetchColumn("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1", ['theme_opts_' . $theme]);
            if (!$row) return [];
            $decoded = json_decode((string)$row, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** 用 schema 默认值补齐缺失项，返回「已存值优先、缺省回退默认」的完整选项数组 */
    public static function mergeOptions(array $schema, array $saved): array
    {
        $merged = [];
        foreach ($schema as $f) {
            $key = $f['key'];
            $merged[$key] = array_key_exists($key, $saved) ? $saved[$key] : $f['default'];
        }
        return $merged;
    }
}
