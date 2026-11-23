<?php
/**
 * Copyright 2026 woquan. All rights reserved.
 * 本文件为本项目新增代码，商业使用须取得书面授权。
 *
 * 站点唯一身份：installation_id 在安装时生成一次并持久化到 settings KV，
 * 与域名 / HTTP_HOST / 访问 IP 完全解耦（PRD P0-01 / P0-11、铁律 #4）。
 */

declare(strict_types=1);

namespace Hublog\Core;

/**
 * 安装实例身份内核。
 *
 * 设计要点：
 *  - 身份主键只写一次；重装 / 换域名 / 纯 IP 访问 / 伪造 host 均复用同一个 UUID v4；
 *  - 全部配置走 settings KV（不建表、不动 MigrationContract.php，铁律 #4）；
 *  - 每次写 settings 后必须失效 site_settings 缓存（铁律 #5）；
 *  - site_host 只作展示字段，绝不参与身份判定。
 */
final class InstallationIdentity
{
    /** settings 键：安装实例主键（UUID v4） */
    public const SETTINGS_KEY_ID = 'license_installation_id';

    /** settings 键：首次握手是否未完成（'1' 表示待上报） */
    public const SETTINGS_KEY_PENDING = 'license_installation_pending';

    /** 生成 ID 时使用的锁键，避免并发安装生成两个不同 UUID */
    private const LOCK_KEY = 'license_installation_id_create';

    /**
     * 读取或创建 installation_id。
     *
     * 已有非空值直接复用，任何情况下不覆盖；仅首次生成时写 pending='1'。
     * 生成过程置于 Cache::withLock 内并二次读取，防止并发请求各自生成不同 ID。
     *
     * @return string UUID v4（小写）
     */
    public static function getOrCreateId(): string
    {
        $existing = trim((string)License::getSetting(self::SETTINGS_KEY_ID, ''));
        if ($existing !== '') {
            return $existing;
        }

        /** @var string $id */
        $id = Cache::withLock(self::LOCK_KEY, static function (): string {
            // 锁内二次读取：前一个进程可能刚刚写入
            $latest = trim((string)License::getSetting(self::SETTINGS_KEY_ID, ''));
            if ($latest !== '') {
                return $latest;
            }

            $created = self::generateUuidV4();
            self::writeSetting(self::SETTINGS_KEY_ID, $created);
            self::writeSetting(self::SETTINGS_KEY_PENDING, '1');
            return $created;
        });

        return trim((string)$id);
    }

    /**
     * 硬件指纹：直接复用 License 的既有实现，不复制、不改造（P0 不改指纹算法）。
     *
     * @return string 指纹字符串；异常时返回空串，由上报层静默处理
     */
    public static function getFingerprint(): string
    {
        try {
            return (string)License::getHardwareFingerprint();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 提取站点 host（展示字段，可伪造、会变化，不参与身份判定）。
     *
     * 规则：HTTP_HOST 优先、SERVER_NAME 兜底；IPv6 保留方括号并去掉端口；
     * 其余形式按第一个冒号截断端口；最后 trim + 转小写。
     *
     * @return string 站点 host；无可用值时返回空串
     */
    public static function getSiteHost(): string
    {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
        }
        if ($host === '') {
            return '';
        }

        if ($host[0] === '[') {
            // IPv6：保留方括号，截到 ']' 为止（形如 [::1]:8080）
            $end = strpos($host, ']');
            if ($end !== false) {
                $host = substr($host, 0, $end + 1);
            }
        } else {
            // IPv4 / 域名：去掉 ':端口'
            $pos = strpos($host, ':');
            if ($pos !== false) {
                $host = substr($host, 0, $pos);
            }
        }

        return strtolower(trim($host));
    }

    /**
     * 判断 host 是否为纯 IP（含 IPv6 方括号形式）。
     */
    public static function isPureIp(string $host): bool
    {
        $candidate = trim(trim($host), '[]');
        if ($candidate === '') {
            return false;
        }
        return filter_var($candidate, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * 是否存在待完成的首次握手。
     *
     * ID 尚未生成也视为 pending，保证安装后首次请求会立即补发。
     */
    public static function isPending(): bool
    {
        try {
            $id = trim((string)License::getSetting(self::SETTINGS_KEY_ID, ''));
            if ($id === '') {
                return true;
            }
            return (string)License::getSetting(self::SETTINGS_KEY_PENDING, '0') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 清除待上报标记（仅在上报成功后调用）。
     */
    public static function clearPending(): void
    {
        self::writeSetting(self::SETTINGS_KEY_PENDING, '0');
    }

    /**
     * 写 settings KV：不存在则插入，存在则更新，随后失效 site_settings 缓存。
     *
     * 使用参数化 SQL，键名来自本类常量或 HeartbeatReporter 常量，不接受外部输入。
     */
    private static function writeSetting(string $key, string $value): void
    {
        try {
            $now = Helper::now();
            $exists = Database::fetchColumn(
                'SELECT COUNT(*) FROM settings WHERE `key` = ?',
                [$key]
            );
            if ($exists !== false && (int)$exists > 0) {
                Database::update(
                    'settings',
                    ['value' => $value, 'updated_at' => $now],
                    '`key` = ?',
                    [$key]
                );
            } else {
                Database::insert('settings', [
                    'key'        => $key,
                    'value'      => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } finally {
            // 铁律 #5：任何写入路径都要失效 site_settings，否则 getSetting 读到 1 小时旧值
            Cache::delete('site_settings');
        }
    }

    /**
     * 生成 RFC 4122 v4 UUID（16 字节随机数 + 版本/变体位修正）。
     */
    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
