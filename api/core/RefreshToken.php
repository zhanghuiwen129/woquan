<?php
/**
 * Refresh Token（长效刷新令牌）
 *
 * 背景：Hublog 原 /api/auth/refresh 要求 access token 尚未过期才能续签，
 * 移动端一旦 JWT_EXPIRE 到期（默认 7 天）用户必然被登出。
 *
 * 设计方案（App/小程序/H5 多端适用）：
 * - 双令牌：短期 access_token（JWT_EXPIRE）+ 长效 refresh_token（默认 30 天滑动续期）
 * - 旋转轮换（Rotation）：每次用 refresh_token 换发新 access_token 时，旧 refresh_token 立即失效并下发新的
 * - 服务端存哈希：数据库只存 sha256 哈希，明文仅返回给客户端一次
 * - 设备维度：绑定 device_id（客户端生成的唯一设备串），支持单设备/全部设备注销
 * - 与 token_version 联动：用户改密/被封时自增 token_version，该用户所有 refresh_token 立即作废
 *
 * 存储表：refresh_tokens（由 Migration V44 创建）
 */

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;

class RefreshToken
{
    /** refresh_token 默认有效期（秒）：30 天 */
    private const DEFAULT_TTL = 86400 * 30;

    /**
     * 签发一枚 refresh_token
     *
     * @param int         $userId    用户 ID
     * @param string      $deviceId  设备唯一串（客户端生成，可为空表示未知设备）
     * @param string      $ip        签发来源 IP
     * @param string|null $userAgent 设备信息（有助于用户在多端管理中识别）
     * @return array{token:string, expires_at:string, expires_in:int}
     */
    public static function issue(int $userId, string $deviceId = '', string $ip = '', ?string $userAgent = null): array
    {
        $raw = bin2hex(random_bytes(32));
        $ttl = self::ttl();
        $now = Helper::now();
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        Database::insert('refresh_tokens', [
            'user_id'    => $userId,
            'token_hash' => self::hash($raw),
            'device_id'  => substr($deviceId, 0, 64),
            'user_agent' => mb_substr((string)$userAgent, 0, 255),
            'ip'         => mb_substr($ip, 0, 45),
            'tv'         => self::currentTokenVersion($userId),
            'created_at' => $now,
            'expires_at' => $expiresAt,
            'last_used_at' => $now,
            'revoked'    => 0
        ]);

        return [
            'token'      => $raw,
            'expires_at' => $expiresAt,
            'expires_in' => $ttl
        ];
    }

    /**
     * 轮换：用旧 refresh_token 换一枚新 token，返回用户信息 + 新 access_token
     *
     * @param string $rawToken 客户端提交的明文 refresh_token
     * @return array{user:array, token:string, refresh_token:string, expires_in:int}
     * @throws HublogException 令牌无效/过期/已被撤销时抛出 401
     */
    public static function rotate(string $rawToken): array
    {
        if ($rawToken === '' || strlen($rawToken) < 32) {
            throw new HublogException('refresh_token 无效', 401);
        }

        $hash  = self::hash($rawToken);
        $now   = Helper::now();

        Database::beginTransaction();
        try {
            // CAS 抢占：只有 revoked=0 且未过期的那一次 UPDATE 会命中 1 行。
            // 抢占失败者是只读观察者——绝不撤销该用户其它设备的令牌。
            $claimed = Database::update(
                'refresh_tokens',
                ['revoked' => 1, 'last_used_at' => $now],
                'token_hash = ? AND revoked = 0 AND expires_at > ?',
                [$hash, $now]
            );
            if ($claimed !== 1) {
                $exists = Database::fetchColumn(
                    'SELECT 1 FROM refresh_tokens WHERE token_hash = ? LIMIT 1',
                    [$hash]
                );
                // 没有写入任何东西，提交空事务即可（无活动事务时 commit 会抛，故必须在此 commit）
                Database::commit();
                throw new HublogException(
                    $exists ? 'refresh_token 已失效，请重新登录' : 'refresh_token 无效',
                    401
                );
            }

            $row = Database::fetchOne(
                'SELECT * FROM refresh_tokens WHERE token_hash = ? LIMIT 1',
                [$hash]
            );
            $user = Database::fetchOne(
                "SELECT id,username,nickname,avatar,cover,email,bio,role,status,token_version,created_at FROM users WHERE id=? LIMIT 1",
                [(int)$row['user_id']]
            );
            if (!$user || (int)$user['status'] !== 1) {
                // 抢占已成功：必须提交（把 revoked=1 落库），绝不回滚成可重放令牌
                Database::commit();
                throw new HublogException('账号不存在或已被禁用', 401);
            }
            $tv = (int)($user['token_version'] ?? 1);
            if ((int)($row['tv'] ?? 0) !== 0 && (int)$row['tv'] !== $tv) {
                Database::commit();
                throw new HublogException('登录状态已失效，请重新登录', 401);
            }

            $access = Auth::generateToken($user);
            MediaHelper::processUser($user);
            $issued = self::issue(
                (int)$user['id'],
                (string)$row['device_id'],
                (string)$row['ip'],
                $row['user_agent'] ?? null
            );
            Database::commit();

            return [
                'user'          => $user,
                'token'         => $access,
                'refresh_token' => $issued['token'],
                'expires_in'    => $issued['expires_in'],
            ];
        } catch (\Throwable $e) {
            // 已 commit 的分支由 Database::rollBack() 的 no-op 保护兜住，不会掩盖真实异常
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * 撤销指定 refresh_token（退出登录）
     */
    public static function revoke(string $rawToken): void
    {
        if ($rawToken === '') {
            return;
        }
        try {
            Database::update('refresh_tokens', ['revoked' => 1], 'token_hash = ?', [self::hash($rawToken)]);
        } catch (\Throwable $e) {
            error_log('[Hublog RefreshToken::revoke] ' . $e->getMessage()); // @allow-error-log
        }
    }

    /**
     * 注销用户的全部设备（改密码 / 封号 / 安全事件时调用）
     *
     * 独立事务版：SQL 只保留一份，供 UserRepository 的改密事务内复用。
     */
    public static function revokeAllForUserInTransaction(int $userId): int
    {
        return Database::update(
            'refresh_tokens',
            ['revoked' => 1],
            'user_id = ? AND revoked = 0',
            [$userId]
        );
    }

    /** 注销用户的全部设备（独立调用入口；调用方无事务时走这里） */
    public static function revokeAllForUser(int $userId): void
    {
        try {
            self::revokeAllForUserInTransaction($userId);
        } catch (\Throwable $e) {
            error_log('[Hublog RefreshToken::revokeAllForUser] ' . $e->getMessage()); // @allow-error-log
        }
    }

    /**
     * 清理已过期 / 已撤销的令牌（建议每日 cron 调用，避免表无限增长）
     */
    public static function cleanupExpired(): int
    {
        try {
            $stmt = Database::getInstance()->prepare(
                "DELETE FROM refresh_tokens WHERE expires_at < ? OR revoked = 1"
            );
            $stmt->execute([Helper::now()]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[Hublog RefreshToken::cleanupExpired] ' . $e->getMessage()); // @allow-error-log
            return 0;
        }
    }

    private static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * 读取用户当前 token_version（签发时快照，用于后续比对）
     */
    private static function currentTokenVersion(int $userId): int
    {
        try {
            $tv = Database::fetchColumn("SELECT token_version FROM users WHERE id = ? LIMIT 1", [$userId]);
            return (int)($tv ?: 1);
        } catch (\Throwable $e) {
            return 1;
        }
    }

    private static function ttl(): int
    {
        // 允许通过 config.php 的 REFRESH_TOKEN_TTL 覆盖
        return defined('REFRESH_TOKEN_TTL') ? (int)REFRESH_TOKEN_TTL : self::DEFAULT_TTL;
    }
}
