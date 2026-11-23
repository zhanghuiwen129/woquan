<?php
/**
 * P2-6 SQL 复制粘贴治理起点：把「SELECT key,value FROM settings」全表加载收敛到仓储层。
 * 目前 Security.php / AdminController 共有 4 处 Cache::remember('site_settings', ...) 重复，
 * 后续统一改为 SettingsRepository::all()；本类已复用同一缓存 key 与 TTL，不会造成额外查询。
 */

declare(strict_types=1);

namespace Hublog\Core;

class SettingsRepository
{
    /** 与 Security.php / AdminController 保持一致的缓存 TTL（秒） */
    public const TTL = 300;

    /**
     * 读取全量设置（带 300s 缓存，与既有 site_settings 缓存 key 共享）
     */
    public static function all(): array
    {
        return Cache::remember('site_settings', function () {
            $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, self::TTL);
    }

    /**
     * 读取单个设置项
     */
    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    // ==================== 批次四：后台设置域下沉（只新增，不改既有方法） ====================

    /**
     * 后台设置页 / 站点配置 / 自动备份 / sitemap / robots —— 原始 key-value 行。
     *
     * 刻意不带缓存：调用方原本就在自己的 Cache::remember('site_settings', ...) 闭包内
     * 执行这条 SQL，缓存边界与 TTL 必须继续留在控制器。
     */
    public static function fetchKeyValueRows(): array
    {
        return Database::fetchAll("SELECT `key`, `value` FROM settings");
    }

    /** 后台设置写入前的主键探测（SELECT id，LIMIT 1） */
    public static function existsByKey(string $key): bool
    {
        return (bool)Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$key]);
    }

    /** 单配置值（原 SQL 里硬编码的字面量 key 统一改为 ? 绑定） */
    public static function fetchValueByKey(string $key): mixed
    {
        return Database::fetchColumn("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1", [$key]);
    }

    /**
     * 设置项 upsert：先探测再更新/插入。
     *
     * 敏感键掩码跳过、白名单过滤、站点模式三键校验等入口守卫全部留在控制器；
     * 本方法只负责「先查后写」的 SQL 序列，不引入缓存失效或额外业务判断。
     */
    public static function upsert(string $key, string $value, string $now): void
    {
        if (self::existsByKey($key)) {
            Database::update('settings', ['value' => $value, 'updated_at' => $now], '`key` = ?', [$key]);
        } else {
            Database::insert('settings', [
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** siteConfig() —— 启用中的分类（字段/排序逐字保留） */
    public static function fetchActiveCategories(): array
    {
        return Database::fetchAll(
            "SELECT id, parent_id, name, sort_order FROM categories WHERE status = 1 ORDER BY sort_order ASC, id ASC"
        );
    }

    // ===== 批次五：系统设置杂项域（只新增，不改既有方法） =====

    /**
     * AdminLicenseController::licenseStatus() —— license_ 前缀授权设置行。
     *
     * 该处原 SQL 带 `key` LIKE 'license_%' 过滤且直查无缓存，与全表 all()/fetchKeyValueRows()
     * 语义不同，故单列方法：key 用占位符绑定，WHERE 与字段逐字保留，不加缓存。
     */
    public static function fetchLicenseSettings(): array
    {
        return Database::fetchAll(
            "SELECT `key`, `value` FROM settings WHERE `key` LIKE ?",
            ['license_%']
        );
    }
}
