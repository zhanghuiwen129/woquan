<?php
/**
 * cron 鉴权的唯一真源（cron_secret）
 *
 * 【为什么要有这个类】
 * 治理前，cron_secret 的读取与比对在三个控制器里各抄了一遍：
 *   - api/controllers/JobController.php        （/api/cron/jobs、/api/cron/jobs/status）
 *   - api/controllers/BadgeController.php      （/api/cron/badges）
 *   - api/controllers/PostModerationTrait.php  （/api/cron/scheduled）
 * 三处都直接 SELECT settings.cron_secret 再 hash_equals 比对。
 *
 * 而「这个 secret 从哪来」在部署文档与配置模板里是空白：
 *   - .env.example 里没有 cron_secret 这一项；
 *   - config.example.php 里也没有（cron_secret 只存在于后台「系统设置」写进
 *     数据库 settings 表）。
 * 于是运维一旦忘了在后台填，三个 cron 入口全部 403，而日志里只有一句
 * 「cron token 无效或未配置」—— 分不清是「没配」还是「token 打错了」。
 *
 * 【取值优先级】
 *   ① config.php 里 define('CRON_SECRET', '...') 常量（定义了且非空时生效）
 *   ② 数据库 settings 表的 cron_secret 行
 *   ③ 都没有 → 空字符串
 *
 * ① 优先于 ② 的理由：配置文件是「部署时随代码一起走」的，数据库是
 * 「运行期可被后台改」的。让文件配置压过数据库，运维就能用「改文件 +
 * 重启」把 secret 钉死，不受后台误操作影响；反过来若允许数据库压过文件，
 * 后台一改就静默生效，排查时反而更困难。
 *
 * 【安全语义（不要改）】
 *   - secret 为空 ⇒ verify() 一律返回 false。
 *     「没配 secret」= 关闭外部调用入口，绝不是「放行空口令」。
 *     三个 cron 端点里都有能改数据的操作（执行队列任务、发布定时动态、
 *     重算徽章），空口令放行等于把写接口公开。
 *   - 比对一律用 hash_equals，避免时序侧信道。
 *
 * 【异常兜底】
 *   secret() / verify() / isConfigured() 在请求路径上调用，DB 不可用时
 *   退化成「常量或空串」，绝不抛异常打断业务请求。
 *   ensureSecret() 是「显式操作」（运维主动要一个 secret），DB 不可用时
 *   直接抛 RuntimeException —— 静默返回空串会让调用方分不清
 *   「生成成功」与「生成失败」。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class CronAuth
{
    /**
     * 单次请求内的解析结果缓存（PHP-FPM 每请求重建，长驻 worker 场景可用 reset() 主动失效）。
     * 缓存的意义：同一请求内 3 个入口可能各问一次，避免重复 SELECT。
     */
    private static ?string $resolvedSecret = null;

    /** 是否已经解析过一次（用于区分「缓存了空串」与「还没解析」） */
    private static bool $resolved = false;

    /**
     * 取当前生效的 cron secret。
     *
     * @return string 生效的 secret；未配置时返回空字符串（绝不返回 null）
     */
    public static function secret(): string
    {
        if (self::$resolved) {
            return (string)self::$resolvedSecret;
        }

        $secret = '';

        // ① 配置文件常量优先
        $constant = self::configConstantSecret();
        if ($constant !== '') {
            $secret = $constant;
        } else {
            // ② 回落数据库 settings 表
            try {
                $row  = Database::fetchOne("SELECT `value` FROM settings WHERE `key` = 'cron_secret' LIMIT 1");
                $secret = $row ? trim((string)($row['value'] ?? '')) : '';
            } catch (\Throwable $e) {
                // DB 不可用（未装站 / 连不上 / 表不存在）→ 退化成空串，绝不抛异常打断业务请求
                error_log('[Hublog CronAuth] 读取 settings.cron_secret 失败: ' . $e->getMessage()); // @allow-error-log
                $secret = '';
            }
        }

        self::$resolvedSecret = $secret;
        self::$resolved        = true;

        return $secret;
    }

    /**
     * 校验调用方带来的 token。
     *
     * @param string|null $candidate 请求体 secret 字段或请求头 X-Cron-Token
     * @return bool secret 未配置时恒为 false（关闭外部调用入口）
     */
    public static function verify(?string $candidate): bool
    {
        $expect = self::secret();

        // 未配置 secret = 关闭入口。绝不能因为「候选串也是空」就放行。
        if ($expect === '') {
            return false;
        }
        if ($candidate === null || $candidate === '') {
            return false;
        }

        return hash_equals($expect, $candidate);
    }

    /**
     * 是否已经配置了 cron secret（运维巡检用）。
     */
    public static function isConfigured(): bool
    {
        return self::secret() !== '';
    }

    /**
     * 取生效 secret 的来源，用于排查「到底在哪儿配的」。
     *
     * @return string constant | database | none
     */
    public static function source(): string
    {
        if (self::configConstantSecret() !== '') {
            return 'constant';
        }
        return self::secret() !== '' ? 'database' : 'none';
    }

    /**
     * 确保存在一个可用的 cron secret：没有就生成一个 32 位随机十六进制串写进 settings 表。
     *
     * 【只在明确调用时才会生成】—— 任何请求路径都不会自动调用本方法，
     * 否则「第一次访问站点」就会凭空造出一个 secret 并改变线上行为。
     *
     * @return string 生效的 secret
     * @throws \RuntimeException 数据库不可用或写入失败
     */
    public static function ensureSecret(): string
    {
        // 配置文件常量已经是「生效配置」，此时往库里再写一份只会被遮蔽，
        // 反而让排查的人困惑（库里明明有值却不是生效值）。直接返回常量。
        $constant = self::configConstantSecret();
        if ($constant !== '') {
            self::remember($constant);
            return $constant;
        }

        try {
            $row      = Database::fetchOne("SELECT `value` FROM settings WHERE `key` = 'cron_secret' LIMIT 1");
            $existing = $row ? trim((string)($row['value'] ?? '')) : '';
        } catch (\Throwable $e) {
            throw new \RuntimeException('无法读取 settings.cron_secret：' . $e->getMessage(), 0, $e);
        }

        if ($existing !== '') {
            self::remember($existing);
            return $existing;
        }

        try {
            // 32 位十六进制 = 16 字节随机数
            $generated = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            throw new \RuntimeException('生成随机 secret 失败：' . $e->getMessage(), 0, $e);
        }

        try {
            // 先 UPDATE（key 存在但值为空的情况），影响 0 行再 INSERT。
            // 用「先查后写」而不是 INSERT ... ON DUPLICATE KEY UPDATE：
            // 后者是 MySQL 专有语法，SQLite（行为测试用）不支持。
            $affected = Database::update('settings', ['value' => $generated], "`key` = ?", ['cron_secret']);
            if ($affected <= 0) {
                try {
                    Database::insert('settings', ['key' => 'cron_secret', 'value' => $generated]);
                } catch (\Throwable $e) {
                    // 并发下可能已被另一个进程写入 → 回读一次，拿到就用它
                    $row      = Database::fetchOne("SELECT `value` FROM settings WHERE `key` = 'cron_secret' LIMIT 1");
                    $existing = $row ? trim((string)($row['value'] ?? '')) : '';
                    if ($existing === '') {
                        throw new \RuntimeException('写入 settings.cron_secret 失败：' . $e->getMessage(), 0, $e);
                    }
                    self::remember($existing);
                    return $existing;
                }
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('写入 settings.cron_secret 失败：' . $e->getMessage(), 0, $e);
        }

        self::remember($generated);
        return $generated;
    }

    /**
     * 清空进程内的解析缓存。
     *
     * 用途：secret 变更后主动失效；测试里在切换 DB 状态前调用，避免串味。
     */
    public static function reset(): void
    {
        self::$resolvedSecret = null;
        self::$resolved        = false;
    }

    /**
     * 读取 config.php 里的 CRON_SECRET 常量（若定义且非空）。
     */
    private static function configConstantSecret(): string
    {
        if (!defined('CRON_SECRET')) {
            return '';
        }
        try {
            $value = constant('CRON_SECRET');
        } catch (\Throwable $e) {
            return '';
        }
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** 记录解析结果到进程内缓存 */
    private static function remember(string $secret): void
    {
        self::$resolvedSecret = $secret;
        self::$resolved        = true;
    }
}
