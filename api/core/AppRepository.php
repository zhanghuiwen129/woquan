<?php

declare(strict_types=1);

/**
 * Hublog App 端数据仓储
 *
 * 承载 AppController 下沉的 SQL 与事务边界。所有值参数走 ? 绑定；
 * 控制器仅保留鉴权、参数校验、缓存边界、响应包装与跨领域编排。
 * 迁移约定见 docs/内联SQL收敛规范.md。
 */

namespace Hublog\Core;

class AppRepository
{
    // ===== 配置 / 版本 =====

    /** settings() —— 站点配置原始行（key/value 组装仍由控制器在缓存闭包内完成） */
    public static function fetchSettings(): array
    {
        return Database::fetchAll("SELECT `key`, `value` FROM settings");
    }

    /** version() —— 指定平台最新启用版本（排序与字段顺序逐字保留） */
    public static function findLatestVersion(string $platform): ?array
    {
        return Database::fetchOne(
            "SELECT platform, version_code, version_name, update_type, changelog, download_url
             FROM app_versions
             WHERE platform = ? AND status = 1
             ORDER BY version_code DESC LIMIT 1",
            [$platform]
        );
    }

    /** categories() —— 启用中的公开分类 */
    public static function fetchActiveCategories(): array
    {
        return Database::fetchAll(
            "SELECT id, parent_id, name, sort_order
             FROM categories WHERE status = 1
             ORDER BY sort_order ASC, id ASC"
        );
    }

    // ===== 推送令牌 =====

    /** registerPushToken() —— 同一用户+服务商+令牌的既有登记 */
    public static function findPushToken(int $userId, string $provider, string $token): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM push_tokens WHERE user_id = ? AND provider = ? AND token = ? LIMIT 1",
            [$userId, $provider, $token]
        );
    }

    /** registerPushToken() —— 更新已登记令牌 */
    public static function updatePushToken(int $id, string $now, string $deviceId): void
    {
        Database::update('push_tokens', [
            'updated_at' => $now,
            'device_id' => mb_substr($deviceId, 0, 64)
        ], 'id = ?', [$id]);
    }

    /** registerPushToken() —— 新登记令牌，返回新行 ID */
    public static function insertPushToken(array $data): int
    {
        return Database::insert('push_tokens', $data);
    }

    // ===== 微信登录 / 用户查询 =====

    /** wxAppLogin() —— 按 provider + oauth_id 查绑定 */
    public static function findOauthByProviderId(string $provider, string $oauthId): ?array
    {
        return Database::fetchOne(
            "SELECT id, user_id FROM oauth_accounts WHERE provider = ? AND oauth_id = ? LIMIT 1",
            [$provider, $oauthId]
        );
    }

    /** wxAppLogin() —— 按 unionid 打通小程序账号（IN 元素全部绑定） */
    public static function findOauthByUnionid(string $unionid): ?array
    {
        return Database::fetchOne(
            "SELECT id, user_id FROM oauth_accounts
             WHERE provider IN (?, ?)
               AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.unionid')) = ?
             ORDER BY id ASC LIMIT 1",
            ['wechat_app', 'wechat_mini', $unionid]
        );
    }

    /** wxAppLogin() —— 绑定账号对应的启用用户 */
    public static function findActiveUserById(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id, username, nickname, avatar, cover, role, email, bio, token_version, created_at
             FROM users WHERE id = ? AND status = 1",
            [$userId]
        );
    }

    /** wxAppLogin() —— 更新 OAuth 绑定凭据 */
    public static function updateOauthAccount(int $id, string $accessToken, string $rawData, string $now): void
    {
        Database::update('oauth_accounts', [
            'access_token' => $accessToken,
            'raw_data' => $rawData,
            'updated_at' => $now
        ], 'id = ?', [$id]);
    }

    /** wxAppLogin() —— 登录时间回写 */
    public static function touchUserLogin(int $userId, string $now): void
    {
        Database::update('users', ['last_login' => $now], 'id = ?', [$userId]);
    }

    /** wxAppLogin() —— 同 unionid 的历史用户（不限制 status，与原 SQL 一致） */
    public static function findUserByOauthId(string $oauthId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM users WHERE oauth_provider IN (?, ?) AND oauth_id = ? LIMIT 1",
            ['wechat_app', 'wechat_mini', $oauthId]
        );
    }

    /** wxAppLogin() —— 新用户与 OAuth 绑定写入（同 unionid 复用路径） */
    public static function insertOauthAccount(array $data): int
    {
        return Database::insert('oauth_accounts', $data);
    }

    /** wxAppLogin() —— 按 ID 回显用户（不限制 status） */
    public static function findUserById(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id, username, nickname, avatar, cover, role, email, bio, token_version, created_at
             FROM users WHERE id = ?",
            [$userId]
        );
    }

    /** wxAppLogin() —— 用户名占用检测（注册时消歧循环） */
    public static function usernameExists(string $username): bool
    {
        return (bool)Database::fetchColumn("SELECT id FROM users WHERE username = ? LIMIT 1", [$username]);
    }

    /** wxAppLogin() —— 自动注册，返回新用户 ID */
    public static function insertUser(array $data): int
    {
        return Database::insert('users', $data);
    }

    /** deleteAccount() —— 密码二次校验 */
    public static function findUserCredentials(int $userId): ?array
    {
        return Database::fetchOne("SELECT id, username, password FROM users WHERE id = ? LIMIT 1", [$userId]);
    }

    /** deleteAccount() —— 点赞数回退：原 SQL 的 user_id 拼接值改为绑定 */
    public static function decrementLikedPostCounts(int $userId): void
    {
        Database::query(
            "UPDATE `posts` p
             INNER JOIN (SELECT post_id FROM `likes` WHERE user_id = ?) t ON t.post_id = p.id
             SET p.`like_count` = IF(p.`like_count` > 0, p.`like_count` - 1, 0)",
            [$userId]
        );
    }

    /** deleteAccount() —— 清理无成员的会话（在外层事务内执行） */
    public static function deleteOrphanConversations(): int
    {
        $orphans = Database::fetchAll(
            "SELECT c.id FROM conversations c
             LEFT JOIN conversation_members m ON m.conversation_id = c.id
             WHERE m.id IS NULL"
        );
        foreach ($orphans as $row) {
            Database::delete('conversations', 'id = ?', [(int)$row['id']]);
        }
        return count($orphans);
    }

    /**
     * deleteAccount() —— 注销全流程事务。
     * $userUpdates 的字段名来自控制器硬编码白名单，这里再用白名单 + 正则挡一层。
     */
    public static function purgeAndAnonymizeUser(int $userId, bool $keepPosts, array $userUpdates): void
    {
        $allowed = [
            'username', 'nickname', 'email', 'email_verified', 'avatar', 'cover', 'bio', 'password',
            'oauth_id', 'realname_verified', 'realname_verified_at', 'status', 'deleted_at',
            'token_version', 'updated_at',
        ];
        foreach (array_keys($userUpdates) as $column) {
            if (!is_string($column) || !preg_match('/^[a-z_]+$/', $column) || !in_array($column, $allowed, true)) {
                throw new \InvalidArgumentException('非法更新字段');
            }
        }

        Database::beginTransaction();
        try {
            // A) 公开发表的内容：默认保留（匿名化在 users 主记录上完成）
            if (!$keepPosts) {
                Database::delete('posts', 'user_id = ?', [$userId]);
                Database::delete('comments', 'user_id = ?', [$userId]);
            }

            // B) 行为与关系数据
            self::decrementLikedPostCounts($userId);
            Database::delete('likes', 'user_id = ?', [$userId]);
            Database::delete('comment_likes', 'user_id = ?', [$userId]);
            Database::delete('favorites', 'user_id = ?', [$userId]);
            Database::delete('follows', 'follower_id = ? OR following_id = ?', [$userId, $userId]);
            Database::delete('blocked_users', 'user_id = ? OR blocked_user_id = ?', [$userId, $userId]);
            Database::delete('user_actions', 'user_id = ?', [$userId]);
            Database::delete('circle_members', 'user_id = ?', [$userId]);
            Database::delete('circle_join_requests', 'user_id = ?', [$userId]);

            // C) 只删本人收到的消息 / 通知
            // 合规擦除例外（被遗忘权），非 IM 删除路径：由 AppController::purgeAndAnonymizeUser 调用，
            // 与 ChatService 的软删除链路完全隔离，静态守卫按此注释标记显式放行。
            Database::delete('messages', 'sender_id = ?', [$userId]);
            Database::delete('conversation_members', 'user_id = ?', [$userId]);
            Database::delete('notifications', 'user_id = ?', [$userId]);
            self::deleteOrphanConversations();

            // D) 文件、令牌、第三方绑定、实名信息
            Database::delete('uploads', 'user_id = ?', [$userId]);
            Database::delete('refresh_tokens', 'user_id = ?', [$userId]);
            Database::delete('push_tokens', 'user_id = ?', [$userId]);
            Database::delete('oauth_accounts', 'user_id = ?', [$userId]);
            Database::delete('realname_records', 'user_id = ?', [$userId]);

            // E) 主记录匿名化 + 标记注销
            Database::update('users', $userUpdates, 'id = ?', [$userId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ===== 设备 =====

    /** devices() —— 有效会话列表（NOW() 为 SQL 内函数，非请求值） */
    public static function fetchActiveRefreshTokens(int $userId): array
    {
        return Database::fetchAll(
            "SELECT id, device_id, user_agent, ip, created_at, last_used_at, expires_at
             FROM refresh_tokens
             WHERE user_id = ? AND revoked = 0 AND expires_at > NOW()
             ORDER BY last_used_at DESC, id DESC
             LIMIT 20",
            [$userId]
        );
    }

    /**
     * kickDevice() —— 撤销设备 + token_version +1 完整事务。
     * $mode: all_with_current | all | id | device；$value 依 mode 为设备名或行 ID。
     */
    public static function revokeDevices(
        int $userId,
        string $mode,
        string $value,
        int $tokenVersion,
        string $now
    ): void {
        Database::beginTransaction();
        try {
            if ($mode === 'all_with_current') {
                Database::update(
                    'refresh_tokens',
                    ['revoked' => 1],
                    'user_id = ? AND revoked = 0 AND device_id <> ?',
                    [$userId, $value]
                );
            } elseif ($mode === 'id') {
                Database::update(
                    'refresh_tokens',
                    ['revoked' => 1],
                    'id = ? AND user_id = ?',
                    [(int)$value, $userId]
                );
            } elseif ($mode === 'device') {
                Database::update(
                    'refresh_tokens',
                    ['revoked' => 1],
                    'user_id = ? AND device_id = ? AND revoked = 0',
                    [$userId, $value]
                );
            } else {
                Database::update('refresh_tokens', ['revoked' => 1], 'user_id = ? AND revoked = 0', [$userId]);
            }

            Database::update(
                'users',
                ['token_version' => $tokenVersion + 1, 'updated_at' => $now],
                'id = ?',
                [$userId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** saveCrashLog() —— 客户端异常入库 */
    public static function insertCrashLog(array $data): int
    {
        return Database::insert('app_crash_logs', $data);
    }

    // ===== 批次五：后台崩溃日志 / 应用版本子域（只新增，不改既有方法） =====

    /**
     * 崩溃日志 WHERE 助手：产出 $where 与 $params，供 count/list/top 三处复用。
     *
     * 入口守卫（event/platform 枚举白名单、date 格式正则）在此下沉，
     * 仓储内任何调用路径都无法绕过；值全部占位符化。过滤口径与 HEAD 逐字一致。
     *
     * @return array{0:string,1:array}
     */
    private static function crashLogWhere(string $event, string $platform, string $date, string $keyword): array
    {
        $where = [];
        $params = [];
        if (in_array($event, ['error', 'api_error'], true)) {
            $where[] = 'event = ?';
            $params[] = $event;
        }
        if (in_array($platform, ['android', 'ios', 'h5'], true)) {
            $where[] = 'platform = ?';
            $params[] = $platform;
        }
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where[] = 'DATE(occurred_at) = ?';
            $params[] = $date;
        }
        if ($keyword !== '') {
            $where[] = '(message LIKE ? OR route LIKE ? OR api_url LIKE ? OR stack LIKE ? OR context LIKE ?)';
            $like = '%' . $keyword . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }
        return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
    }

    /** AdminCrashController::crashLogs() —— 分页计数，与列表共用同一 WHERE */
    public static function countCrashLogs(string $event, string $platform, string $date, string $keyword): int
    {
        [$where, $params] = self::crashLogWhere($event, $platform, $date, $keyword);
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM app_crash_logs {$where}", $params);
    }

    /**
     * AdminCrashController::crashLogs() —— 崩溃日志列表。
     *
     * 字段清单/顺序、ORDER BY id DESC 逐字保留；原 LIMIT/OFFSET 拼接改为
     * `LIMIT ? OFFSET ?` 绑定（参数顺序 limit, offset，与原 SQL 形态一致）。
     */
    public static function fetchCrashLogs(
        string $event,
        string $platform,
        string $date,
        string $keyword,
        int $limit,
        int $offset
    ): array {
        [$where, $params] = self::crashLogWhere($event, $platform, $date, $keyword);
        return Database::fetchAll(
            "SELECT id, user_id, event, platform, client, route, api_url, api_code, message, context, ip, occurred_at
             FROM app_crash_logs {$where} ORDER BY id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }

    /**
     * AdminCrashController::crashLogs() —— 高频故障 Top20 聚合。
     *
     * GROUP BY / MAX / ORDER BY cnt DESC / LIMIT 20 逐字保留；与列表共用同一 WHERE。
     */
    public static function fetchCrashTop(string $event, string $platform, string $date, string $keyword): array
    {
        [$where, $params] = self::crashLogWhere($event, $platform, $date, $keyword);
        return Database::fetchAll(
            "SELECT event, message, COUNT(*) AS cnt, MAX(occurred_at) AS last_at
             FROM app_crash_logs {$where} GROUP BY event, message ORDER BY cnt DESC LIMIT 20",
            $params
        );
    }

    /** AdminCrashController::crashLogs() —— 今日新增（不受筛选影响，CURDATE 为 SQL 内函数） */
    public static function countTodayCrashLogs(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM app_crash_logs WHERE DATE(occurred_at) = CURDATE()");
    }

    /** AdminCrashController::crashLogs() —— 告警阈值读取（返回原始标量，?: 0 兜底留控制器） */
    public static function fetchCrashAlertThreshold(): mixed
    {
        return Database::fetchColumn("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1", ['crash_alert_threshold']);
    }

    /**
     * AdminCrashController::crashAlertThreshold() —— 阈值「先插后更」完整序列。
     *
     * 原实现 catch 唯一键冲突后更新；为保持行为等价，沿用 try insert / catch update，
     * 不引入额外时间戳字段、不改用先探测再写的 upsert。key 为硬编码常量，值走绑定。
     */
    public static function saveCrashAlertThreshold(string $threshold): void
    {
        try {
            Database::insert('settings', [
                'key'   => 'crash_alert_threshold',
                'value' => $threshold,
            ]);
        } catch (\Throwable $e) {
            Database::update('settings', ['value' => $threshold], '`key` = ?', ['crash_alert_threshold']);
        }
    }

    /**
     * AdminAppVersionController::appVersions() —— 后台版本全量列表。
     *
     * 与公开版 findLatestVersion() 字段集不同（本方法含 status/created_at、不筛
     * status、排序为 version_code DESC, id DESC），刻意独立，禁止复用公开版方法。
     */
    public static function fetchAdminAppVersions(): array
    {
        return Database::fetchAll(
            "SELECT id, platform, version_code, version_name, update_type, changelog, download_url, status, created_at
             FROM app_versions ORDER BY version_code DESC, id DESC"
        );
    }

    /** AdminAppVersionController::saveAppVersion() —— 更新既有版本 */
    public static function updateAppVersion(int $id, array $data): void
    {
        Database::update('app_versions', $data, 'id = ?', [$id]);
    }

    /** AdminAppVersionController::saveAppVersion() —— 新增版本，返回新行 ID */
    public static function insertAppVersion(array $data): int
    {
        return Database::insert('app_versions', $data);
    }

    /** AdminAppVersionController::deleteAppVersion() —— 按主键删除 */
    public static function deleteAppVersion(int $id): void
    {
        Database::delete('app_versions', 'id = ?', [$id]);
    }
}
