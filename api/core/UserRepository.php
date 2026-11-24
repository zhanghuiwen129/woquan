<?php
/**
 * Hublog 用户数据仓储
 *
 * 只承载 UserController / AuthController 下沉的 SQL 与事务：返回原始行结构，不做
 * 权限判定、响应包装、缓存与跨领域编排——这些仍留在控制器。迁移约定见
 * docs/内联SQL收敛规范.md。
 *
 * 约定（与原控制器逐条对齐，行为不变）：
 *  - 所有值一律通过 ? 占位符绑定（含 IN / LIKE / LIMIT / 已转型的 int），禁止字符串拼接值；
 *  - 字段清单、字段顺序、表别名、JOIN 条件、排序与原 SQL 逐字一致；
 *  - 涉及 posts 的行查询带 p.post_type；COUNT(*) 聚合保持 COUNT(*)；
 *  - 软删/状态/可见性条件（status / removed_at / visibility）留在 SQL 的 WHERE 中；
 *  - 一个完整写序列作为一个方法，事务的 begin/commit/rollBack 全部留在仓储层，
 *    catch 里 rollBack 后 throw，由控制器转成原有错误文案。
 */

declare(strict_types=1);

namespace Hublog\Core;

class UserRepository
{
    /** 基础公开字段（AuthController 等处重复 7 次的 11 列 SELECT） */
    private const BASIC_COLUMNS = 'id, username, nickname, avatar, cover, role, email, token_version, created_at';

    // ==================== 既有基础查询（保持不变） ====================

    /** 取基础公开字段（不含 password / phone 等敏感列） */
    public static function findBasicById($id): ?array
    {
        $row = Database::fetchOne(
            "SELECT " . self::BASIC_COLUMNS . " FROM users WHERE id = ?",
            [$id]
        );
        return is_array($row) ? $row : null;
    }

    /** 取完整用户行（含敏感列，仅限已鉴权内部使用） */
    public static function findById($id): ?array
    {
        $row = Database::fetchOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$id]);
        return is_array($row) ? $row : null;
    }

    /** 按手机号查用户（登录等场景） */
    public static function findByPhone(string $phone): ?array
    {
        $row = Database::fetchOne("SELECT * FROM users WHERE phone = ? LIMIT 1", [$phone]);
        return is_array($row) ? $row : null;
    }

    // ==================== 站点配置（对应 register / getRegisterSettings / Cache::remember） ====================

    /** 读取单个配置值（原 allow_register / forbidden_nicknames 查询，key 走绑定） */
    public static function fetchSetting(string $key): mixed
    {
        return Database::fetchColumn("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1", [$key]);
    }

    /** 按 key 白名单批量取配置行（IN 元素全部绑定，返回原始 key/value 行） */
    public static function fetchSettingsByKeys(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        return Database::fetchAll(
            "SELECT `key`, `value` FROM settings WHERE `key` IN ({$placeholders})",
            array_values($keys)
        );
    }

    /** 取全部配置行（site_settings 缓存回填用） */
    public static function fetchAllSettings(): array
    {
        return Database::fetchAll("SELECT `key`, `value` FROM settings");
    }

    // ==================== 用户单行 / 存在性查询 ====================

    /** 用户名 -> id（注册查重 / 唯一名生成，原多处 SELECT id ... LIMIT 1） */
    public static function userIdByUsername(string $username): mixed
    {
        return Database::fetchColumn("SELECT id FROM users WHERE username = ? LIMIT 1", [$username]);
    }

    public static function userIdByEmail(string $email): mixed
    {
        return Database::fetchColumn("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
    }

    public static function userIdByNickname(string $nickname): mixed
    {
        return Database::fetchColumn("SELECT id FROM users WHERE nickname = ? LIMIT 1", [$nickname]);
    }

    /** 查重排除自身（checkNickname / updateProfile / adminUpdate） */
    public static function nicknameExistsElsewhere(string $nickname, int $excludeId): mixed
    {
        return Database::fetchColumn(
            "SELECT id FROM users WHERE nickname = ? AND id != ? LIMIT 1",
            [$nickname, $excludeId]
        );
    }

    public static function emailExistsElsewhere(string $email, int $excludeId): mixed
    {
        return Database::fetchColumn(
            "SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1",
            [$email, $excludeId]
        );
    }

    /** 绑定手机号时查号是否被他人占用 */
    public static function phoneTakenByOther(string $phone, int $excludeId): mixed
    {
        return Database::fetchColumn(
            "SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1",
            [$phone, $excludeId]
        );
    }

    /** 登录：用户名或邮箱命中，含 password（不在仓储层校验密码/状态） */
    public static function findByUsernameOrEmailWithPassword(string $value): ?array
    {
        return Database::fetchOne(
            "SELECT id, username, nickname, avatar, cover, email, bio, password, role, status, token_version, created_at
             FROM users WHERE username = ? OR email = ? LIMIT 1",
            [$value, $value]
        ) ?: null;
    }

    /** 手机号登录：13 列含 password + phone_verified_at */
    public static function findByPhoneWithPassword(string $phone): ?array
    {
        return Database::fetchOne(
            "SELECT id, username, nickname, avatar, cover, email, bio, password, role, status, token_version, phone_verified_at, created_at
             FROM users WHERE phone = ? LIMIT 1",
            [$phone]
        ) ?: null;
    }

    /** 取密码哈希（changePassword） */
    public static function findPasswordById(int $id): ?array
    {
        return Database::fetchOne("SELECT password FROM users WHERE id = ? LIMIT 1", [$id]) ?: null;
    }

    /** 取 token_version（resetPassword，fetchColumn 不做转型） */
    public static function findTokenVersionById(int $id): mixed
    {
        return Database::fetchColumn("SELECT token_version FROM users WHERE id = ?", [$id]);
    }

    public static function findPhoneById(int $id): mixed
    {
        return Database::fetchColumn("SELECT phone FROM users WHERE id = ? LIMIT 1", [$id]);
    }

    public static function findUsernameById(int $id): mixed
    {
        return Database::fetchColumn("SELECT username FROM users WHERE id = ?", [$id]);
    }

    /** 本人可编辑资料（GET /auth/profile） */
    public static function findProfileById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT id, username, nickname, avatar, cover, email, bio,
                    gender, birthday, region, user_tags,
                    privacy_home, allow_search, allow_mention, allow_stranger_msg,
                    hide_online, font_scale, msg_sound, created_at
             FROM users WHERE id = ? LIMIT 1",
            [$id]
        ) ?: null;
    }

    /** Token 续期用户（GET /auth/refresh） */
    public static function findRefreshUserById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT id, username, nickname, avatar, cover, email, bio, role, status, token_version, created_at, vip_level, vip_expire_at, avatar_frame FROM users WHERE id = ? LIMIT 1",
            [$id]
        ) ?: null;
    }

    /** updateProfile 提交后回显（列清单与原 SQL 一致） */
    public static function findUpdatedProfileById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT id, username, nickname, avatar, cover, email, role, bio, gender, birthday, region, user_tags,
                    privacy_home, allow_search, allow_mention, allow_stranger_msg, hide_online, font_scale, msg_sound,
                    created_at
             FROM users WHERE id = ?",
            [$id]
        ) ?: null;
    }

    /** OAuth 已关联分支：基础 9 列 + status=1（与 findBasicById 的过滤口径不同，刻意分开） */
    public static function findActiveBasicById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT " . self::BASIC_COLUMNS . " FROM users WHERE id = ? AND status = 1",
            [$id]
        ) ?: null;
    }

    /** 按用户名查启用用户 id（评论/点赞列表入口） */
    public static function findActiveUserIdByUsername(string $username): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM users WHERE username = ? AND status = 1 LIMIT 1",
            [$username]
        ) ?: null;
    }

    /**
     * GDPR 导出账号信息（R9：补齐个人字段）。
     *
     * 仅补充用户本人的隐私/资料字段；绝不返回 password / password_hash / 任何 token 字段。
     */
    public static function findExportProfileById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT id, username, nickname, email, avatar, bio, role, status, created_at,
                    phone, birthday, gender, region, user_tags, privacy_home, allow_search,
                    hide_online, allow_stranger_msg, allow_mention
             FROM users WHERE id = ?",
            [$id]
        ) ?: null;
    }

    /** 后台目标守卫批量取角色：不存在的 ID 自然不出现在结果中 */
    public static function fetchRolesByIds(array $ids): array
    {
        $ids = array_values(array_map('intval', $ids));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return Database::fetchAll(
            "SELECT id, role FROM users WHERE id IN ({$placeholders})",
            $ids
        );
    }

    /** 后台编辑用户回显 / AdminTargetGuard 单目标判定 */
    public static function findIdRoleStatusById(int $id): ?array
    {
        return Database::fetchOne("SELECT id, role, status FROM users WHERE id = ? LIMIT 1", [$id]) ?: null;
    }

    /** 删除用户前回显图片清理所需列 */
    public static function findIdUsernameAvatarById(int $id): ?array
    {
        return Database::fetchOne("SELECT id, username, avatar FROM users WHERE id = ? LIMIT 1", [$id]) ?: null;
    }

    /** 主页可见性判定（privacy_home / hide_online / last_active_at） */
    public static function findPrivacyState(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT privacy_home, hide_online, last_active_at FROM users WHERE id = ? LIMIT 1",
            [$id]
        ) ?: null;
    }

    /**
     * R4/R5：主页关系链与行为列表的可见性门禁（唯一判定入口）。
     *
     * 复用 findPrivacyState()，口径与 UserController::profile() 原逻辑一致：
     *  - 目标不存在 / id 非法 → 默认拒绝；
     *  - 本人、平台管理员 → 始终放行；
     *  - privacy_home=public（含历史缺失值）→ 放行，保持「默认公开」产品形态；
     *  - privacy_home=private → 仅本人 / admin；
     *  - privacy_home=followers → 本人 / admin 或 viewer 关注了 target；
     *  - 其它非法值 → 默认拒绝。
     *
     * @param int  $targetId 被访问主页的用户 id
     * @param int  $viewerId 访问者 id，<=0 视为游客
     * @param bool $isAdmin  访问者是否为平台管理员
     */
    public static function isProfileVisibleTo(int $targetId, int $viewerId, bool $isAdmin = false): bool
    {
        if ($targetId <= 0) {
            return false;
        }
        if ($isAdmin || ($viewerId > 0 && $viewerId === $targetId)) {
            return true;
        }
        $state = self::findPrivacyState($targetId);
        if (!$state) {
            return false;
        }
        $home = $state['privacy_home'] ?? null;
        // 缺失/空值按历史行为视为 public：不改变默认公开的产品形态，也不破坏 profile() 复用后的行为
        if ($home === null || $home === '' || $home === 'public') {
            return true;
        }
        if ($home === 'private') {
            return false;
        }
        if ($home === 'followers') {
            return $viewerId > 0 && self::isFollowing($viewerId, $targetId);
        }
        return false;
    }

    /** 设置页隐私 + 资料扩展 + 手机号 */
    public static function findPrivacySettings(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT privacy_home, allow_search, allow_mention, allow_stranger_msg, hide_online,
                    font_scale, msg_sound, gender, birthday, region, user_tags, phone, phone_verified_at
             FROM users WHERE id = ?",
            [$id]
        ) ?: null;
    }

    /** @提及搜索：enable 过滤 + 排除自己 + LIKE 绑定 + LIMIT 绑定 */
    public static function searchUsers(int $excludeId, string $keyword, int $limit): array
    {
        return Database::fetchAll(
            "SELECT id, username, nickname, avatar, vip_level, avatar_frame, realname_verified FROM users
             WHERE status = 1 AND id != ? AND allow_search = 1
             AND (username LIKE ? OR nickname LIKE ?)
             ORDER BY id DESC LIMIT ?",
            [$excludeId, "%{$keyword}%", "%{$keyword}%", $limit]
        );
    }

    /** 公开主页用户行（status=1 或 已注销） */
    public static function findProfileByUsername(string $username): ?array
    {
        return Database::fetchOne(
            "SELECT id, username, nickname, avatar, cover, bio, user_tags, created_at,
                    realname_verified, realname_verified_at, deleted_at,
                    vip_level, vip_expire_at, avatar_frame
             FROM users
             WHERE username = ? AND (status = 1 OR deleted_at IS NOT NULL) LIMIT 1",
            [$username]
        ) ?: null;
    }

    /** 邮箱验证状态（含 email_verified） */
    public static function findEmailVerificationState(string $email): ?array
    {
        return Database::fetchOne(
            "SELECT id, email_verified FROM users WHERE email = ? LIMIT 1",
            [$email]
        ) ?: null;
    }

    public static function findIdUsernameByEmail(string $email): ?array
    {
        return Database::fetchOne("SELECT id, username FROM users WHERE email = ? LIMIT 1", [$email]) ?: null;
    }

    public static function findAppealUserTarget(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT id, nickname, username, status FROM users WHERE id = ?",
            [$id]
        ) ?: null;
    }

    /** 在线状态批量查询：IN 占位符由白名单 id 数量生成，值全绑定 */
    public static function fetchOnlineStatuses(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return Database::fetchAll(
            "SELECT id, last_active_at, hide_online, status FROM users WHERE id IN ({$placeholders})",
            array_values($ids)
        );
    }

    /** 是否拥有圈子（me 的 is_circle_owner，任意状态计数） */
    public static function countOwnedCircles(int $ownerId): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM circles WHERE owner_id = ?", [$ownerId]);
    }

    // ==================== 用户写操作 ====================

    public static function insertUser(array $data): int
    {
        return Database::insert('users', $data);
    }

    /** 动态列更新：字段名来自控制器白名单，这里再挡一层防注入 */
    private static function assertUpdateFields(array $data): void
    {
        foreach (array_keys($data) as $column) {
            if (!is_string($column) || !preg_match('/^[a-z_]+$/', $column)) {
                throw new \InvalidArgumentException('非法更新字段');
            }
        }
    }

    /** 通用用户字段更新（updateProfile / adminUpdate / updatePrivacy 等） */
    public static function updateUserFields(int $id, array $data): int
    {
        self::assertUpdateFields($data);
        return Database::update('users', $data, 'id = ?', [$id]);
    }

    public static function updateLastLogin(int $id, string $now): void
    {
        Database::update('users', ['last_login' => $now], 'id = ?', [$id]);
    }

    public static function updateLastLoginAndActive(int $id, string $now): void
    {
        Database::update('users', ['last_login' => $now, 'last_active_at' => $now], 'id = ?', [$id]);
    }

    public static function updatePhoneVerifiedAt(int $id, string $now): void
    {
        Database::update('users', ['phone_verified_at' => $now], 'id = ?', [$id]);
    }

    /** 退出登录：写入递增后的 token_version（新值由控制器按登录态计算） */
    public static function updateTokenVersion(int $id, int $version, string $now): void
    {
        Database::update('users', ['token_version' => $version, 'updated_at' => $now], 'id = ?', [$id]);
    }

    /**
     * 会员系统治理 P0-4b：原子递增 token_version。
     * 登出等场景不再用「读当前值 +1 再写回」，避免并发两次登出只增 1 的竞态。
     * 与改密路径 applyPasswordChangeInTransaction 的 token_version = token_version + 1 同口径。
     */
    public static function bumpTokenVersion(int $id, string $now): void
    {
        // 用原始 SQL 而非 Database::update：后者会把键当列名做正则白名单校验，
        // 不接受 `token_version = token_version + 1` 这类表达式。
        Database::query(
            "UPDATE users SET token_version = token_version + 1, updated_at = ? WHERE id = ?",
            [$now, $id]
        );
    }

    public static function updateAvatarFrame(int $id, string $frame): void
    {
        Database::update('users', ['avatar_frame' => $frame], 'id = ?', [$id]);
    }

    /**
     * V102：封禁/解封统一入口，同时维护 status 与 account_state（二义性拆分）。
     * $accountState：0正常 1封禁 2注销 3后台软删；null=仅改 status（兼容旧调用）。
     */
    public static function updateUserStatus(int $id, int $status, string $now, ?int $accountState = null): void
    {
        $data = ['status' => $status, 'updated_at' => $now];
        if ($accountState !== null) {
            $data['account_state'] = $accountState;
        }
        Database::update('users', $data, 'id = ?', [$id]);
    }

    /** 修改密码 + 递增 token_version（changePassword / resetPassword，无事务）
     *  @deprecated 改密请统一走 changePasswordAndInvalidateSessions()，它会 SQL 侧 +1 并同事务撤销全部 refresh_token；
     *              本方法仅为兼容既有行为测试保留，禁止在生产写路径新增调用。
     */
    public static function updatePasswordAndTokenVersion(int $id, string $hash, int $version, string $now): void
    {
        Database::update('users', [
            'password' => $hash,
            'token_version' => $version,
            'updated_at' => $now,
        ], 'id = ?', [$id]);
    }

    /** 设置密码：与原 setPassword 一致，单条更新包在事务里
     *  @deprecated 改密请统一走 changePasswordAndInvalidateSessions()，它会 SQL 侧 +1 并同事务撤销全部 refresh_token；
     *              本方法仅为兼容既有行为测试保留，禁止在生产写路径新增调用。
     */
    public static function setPasswordAndBumpToken(int $id, string $hash, int $version, string $now): void
    {
        Database::beginTransaction();
        try {
            Database::update('users', [
                'password' => $hash,
                'token_version' => $version,
                'updated_at' => $now,
            ], 'id = ?', [$id]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * 改密 / 重置 / 管理员改密的唯一入口。
     *
     * token_version 由 SQL 侧 token_version+1 自增，绝不信任调用方算出的版本（消除读-改-写竞态）；
     * 同一事务内撤销该用户全部 refresh_token，避免「改完密码旧长效令牌还能续期」。
     *
     * @param array $extraFields 同事务附带更新的用户字段（id/password/token_version/updated_at 会被强制剔除）
     * @return int 新 token_version
     */
    public static function changePasswordAndInvalidateSessions(
        int $id,
        string $hash,
        string $now,
        array $extraFields = []
    ): int {
        // 参数校验先于 beginTransaction，失败不留活动事务
        $safe = array_diff_key($extraFields, array_flip(['id', 'password', 'token_version', 'updated_at']));
        self::assertUpdateFields($safe);

        Database::beginTransaction();
        try {
            $newVersion = self::applyPasswordChangeInTransaction($id, $hash, $now, $safe);
            Database::commit();
            return $newVersion;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * 改密事务体（由 changePasswordAndInvalidateSessions / resetPasswordWithToken 复用）。
     * 调用方负责 beginTransaction / commit / rollBack。
     */
    private static function applyPasswordChangeInTransaction(
        int $id,
        string $hash,
        string $now,
        array $safe = []
    ): int {
        self::assertUpdateFields($safe);
        $sets = '';
        $params = [];
        foreach ($safe as $column => $value) {
            $sets .= "`{$column}` = ?, ";
            $params[] = $value;
        }
        $sql = "UPDATE users SET {$sets}password = ?, token_version = token_version + 1, updated_at = ? WHERE id = ?";
        $params[] = $hash;
        $params[] = $now;
        $params[] = $id;
        if (Database::query($sql, $params)->rowCount() !== 1) {
            throw new \Hublog\Core\Exception\HublogException('用户不存在', 404);
        }
        // 同事务撤销全部长效令牌；失败则整段回滚，绝不留下「密码已改、令牌仍有效」的中间态
        RefreshToken::revokeAllForUserInTransaction($id);
        return (int)Database::fetchColumn("SELECT token_version FROM users WHERE id = ?", [$id]);
    }

    /** 邮箱验证通过：置 email_verified=1 */
    public static function markEmailVerified(string $email, string $now): void
    {
        Database::update('users', ['email_verified' => 1, 'updated_at' => $now], 'email = ?', [$email]);
    }

    /** 绑定手机号事务：解绑占用者 + 写入本机号，整段原子 */
    public static function bindPhone(int $userId, string $phone, string $now): void
    {
        Database::beginTransaction();
        try {
            $occupied = Database::fetchColumn(
                "SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1",
                [$phone, $userId]
            );
            if ($occupied) {
                Database::update('users', ['phone' => null, 'updated_at' => $now], 'id = ?', [$occupied]);
            }
            Database::update('users', [
                'phone' => $phone,
                'phone_verified_at' => $now,
                'updated_at' => $now,
            ], 'id = ?', [$userId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public static function updateLastActiveAt(int $id, string $now): void
    {
        Database::update('users', ['last_active_at' => $now], 'id = ?', [$id]);
    }

    /** 删除用户：保持原「单条删除包事务」语义 */
    public static function deleteUser(int $id): void
    {
        Database::beginTransaction();
        try {
            Database::delete('users', 'id = ?', [$id]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ==================== 个人主页统计（COUNT 与列表口径按原样保留） ====================

    public static function countUserPosts(int $userId): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM posts WHERE user_id = ? AND status = 1", [$userId]);
    }

    /** 我给别人点了多少赞（列表与计数共用同一 scope，聚合保持 COUNT(*)） */
    public static function countLikesByUser(int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) " . self::likedPostsScope($userId),
            [$userId]
        );
    }

    public static function countCommentsByUser(int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE c.user_id = ? AND p.status = 1",
            [$userId]
        );
    }

    public static function countLikesReceived(int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.user_id = ?",
            [$userId]
        );
    }

    public static function countCommentsReceived(int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM (
                    SELECT c.id FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.user_id = ?
                    UNION
                    SELECT c.id FROM comments c INNER JOIN comments pc ON c.parent_id = pc.id WHERE pc.user_id = ?
                ) t",
            [$userId, $userId]
        );
    }

    public static function countFollowing(int $userId): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM follows WHERE follower_id = ?", [$userId]);
    }

    public static function countFollowers(int $userId): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM follows WHERE following_id = ?", [$userId]);
    }

    /** 评论列表总数：原 SQL 无 posts 过滤，与列表口径分开，刻意不合并 */
    public static function countUserCommentsAll(int $userId): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM comments WHERE user_id = ?", [$userId]);
    }

    public static function isFollowing(int $followerId, int $followingId): bool
    {
        return (bool)Database::fetchColumn(
            "SELECT id FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1",
            [$followerId, $followingId]
        );
    }

    /** 用户评论列表：posts 仅作 status 过滤；行查询带 p.post_type（无害新增列） */
    public static function fetchUserComments(int $userId, int $limit, int $offset): array
    {
        return Database::fetchAll(
            "SELECT c.id, c.post_id, c.content, c.created_at, p.post_type
             FROM comments c
             INNER JOIN posts p ON c.post_id = p.id
             WHERE c.user_id = ? AND p.status = 1
             ORDER BY c.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    /** 点赞列表/计数共用的 FROM + JOIN + WHERE，避免翻页错位 */
    private static function likedPostsScope(int $userId): string
    {
        return "FROM likes l
             INNER JOIN posts p ON l.post_id = p.id
             WHERE l.user_id = ? AND p.status = 1";
    }

    public static function fetchLikedPostIds(int $userId, int $limit, int $offset): array
    {
        return Database::fetchAll(
            "SELECT l.post_id, p.post_type " . self::likedPostsScope($userId) . "
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    // ==================== GDPR 数据导出（行查询带 p.post_type） ====================

    public static function fetchUserPostsForExport(int $userId): array
    {
        return Database::fetchAll(
            "SELECT p.id, p.category_id, p.title, p.content, p.images, p.attachments, p.video_url,
                    p.view_count, p.like_count, p.comment_count, p.status, p.is_pinned, p.created_at, p.post_type
             FROM posts p WHERE p.user_id = ? ORDER BY p.id",
            [$userId]
        );
    }

    public static function fetchUserCommentsForExport(int $userId): array
    {
        return Database::fetchAll(
            "SELECT c.id, c.post_id, c.parent_id, c.content, c.image, c.is_ai, c.created_at,
                    p.title AS post_title, p.post_type
             FROM comments c LEFT JOIN posts p ON c.post_id = p.id
             WHERE c.user_id = ? ORDER BY c.id",
            [$userId]
        );
    }

    public static function fetchLikesForExport(int $userId): array
    {
        return Database::fetchAll(
            "SELECT l.post_id, l.created_at, p.title AS post_title, p.post_type
             FROM likes l LEFT JOIN posts p ON l.post_id = p.id
             WHERE l.user_id = ? ORDER BY l.id",
            [$userId]
        );
    }

    public static function fetchFavoritesForExport(int $userId): array
    {
        return Database::fetchAll(
            "SELECT f.post_id, f.created_at, p.title AS post_title, p.post_type
             FROM favorites f LEFT JOIN posts p ON f.post_id = p.id
             WHERE f.user_id = ? ORDER BY f.id",
            [$userId]
        );
    }

    public static function fetchFollowingForExport(int $userId): array
    {
        return Database::fetchAll(
            "SELECT f.following_id AS user_id, f.created_at, u.username, u.nickname
             FROM follows f INNER JOIN users u ON f.following_id = u.id
             WHERE f.follower_id = ? ORDER BY f.id",
            [$userId]
        );
    }

    public static function fetchFollowersForExport(int $userId): array
    {
        return Database::fetchAll(
            "SELECT f.follower_id AS user_id, f.created_at, u.username, u.nickname
             FROM follows f INNER JOIN users u ON f.follower_id = u.id
             WHERE f.following_id = ? ORDER BY f.id",
            [$userId]
        );
    }

    /** 删号前预收集动态图片（行查询带 p.post_type） */
    public static function fetchUserPostImages(int $userId): array
    {
        return Database::fetchAll(
            "SELECT p.id, p.images, p.post_type FROM posts p WHERE p.user_id = ?",
            [$userId]
        );
    }

    // ==================== 上传记录 ====================

    /** 头像/封面 URL 是否为本人在本站登记过的上传 */
    public static function uploadRecordExists(string $url, int $userId): bool
    {
        return (bool)Database::fetchColumn(
            "SELECT id FROM uploads WHERE url = ? AND user_id = ? LIMIT 1",
            [$url, $userId]
        );
    }

    public static function insertUpload(array $data): int
    {
        return Database::insert('uploads', $data);
    }

    // ==================== 邮箱验证 / 密码找回 ====================

    public static function insertEmailVerification(array $data): int
    {
        return Database::insert('email_verifications', $data);
    }

    /**
     * 取最近的验证码（含已使用/已作废；限流与找回密码的冷却窗口判定用）。
     * 调用方传小写邮箱键；SQL 对列做 LOWER 比较，兼容历史大小写混合记录。
     */
    public static function findLatestVerificationByEmail(string $email, string $type): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM email_verifications WHERE LOWER(email) = ? AND type = ? ORDER BY id DESC LIMIT 1",
            [$email, $type]
        ) ?: null;
    }

    /** 取最近的未使用验证码（register / reset 两种 type；邮箱口径同上） */
    public static function findLatestUnusedVerification(string $email, string $type): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM email_verifications WHERE LOWER(email) = ? AND used = 0 AND type = ? ORDER BY id DESC LIMIT 1",
            [$email, $type]
        ) ?: null;
    }

    /**
     * 原子核销验证码：只有 used=0 的那一次 UPDATE 会命中 1 行。
     * 并发下第二个请求拿到 0 行 → 返回 false，杜绝 TOCTOU 复用。
     */
    public static function claimEmailVerification(int $id, string $now): bool
    {
        return Database::update(
            'email_verifications',
            ['used' => 1, 'updated_at' => $now],
            'id = ? AND used = 0',
            [$id]
        ) === 1;
    }

    public static function markVerificationUsed(int $id): void
    {
        Database::update('email_verifications', ['used' => 1], 'id = ?', [$id]);
    }

    /**
     * 重置密码：抢验证码 + 改密码 + 增版本 + 撤销全部 refresh_token 全在一个事务里。
     * 不嵌套 beginTransaction：claim 复用调用方事务，由本方法统一收口。
     */
    public static function resetPasswordWithToken(int $recordId, int $userId, string $hash, string $now): bool
    {
        Database::beginTransaction();
        try {
            if (!self::claimEmailVerification($recordId, $now)) {
                Database::rollBack();
                return false;
            }
            self::applyPasswordChangeInTransaction($userId, $hash, $now);
            Database::commit();
            return true;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** 发起新重置请求时作废旧 reset 记录（used=2） */
    public static function invalidateResetVerifications(string $email, string $now): void
    {
        Database::query(
            "UPDATE email_verifications SET used = 2, updated_at = ?
             WHERE LOWER(email) = ? AND type = 'reset' AND used = 0",
            [$now, $email]
        );
    }

    // ==================== OAuth / 小程序账号 ====================

    public static function findOauthAccount(string $provider, string $oauthId): ?array
    {
        return Database::fetchOne(
            "SELECT user_id FROM oauth_accounts WHERE provider = ? AND oauth_id = ? LIMIT 1",
            [$provider, $oauthId]
        ) ?: null;
    }

    public static function findWechatMiniAccount(string $openid): ?array
    {
        return Database::fetchOne(
            "SELECT id, user_id FROM oauth_accounts WHERE provider = 'wechat_mini' AND oauth_id = ? LIMIT 1",
            [$openid]
        ) ?: null;
    }

    public static function insertOauthAccount(array $data): int
    {
        // V102：第三方令牌加密入库（raw_data 不加密，账号打通依赖 JSON_EXTRACT）。
        foreach (['access_token', 'refresh_token'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = OAuthTokenCipher::encrypt((string)$data[$field]);
            }
        }
        return Database::insert('oauth_accounts', $data);
    }

    public static function updateOauthAccountTokens(int $id, string $token, string $raw, string $now): void
    {
        Database::update('oauth_accounts', [
            'access_token' => OAuthTokenCipher::encrypt($token),
            'raw_data' => $raw,
            'updated_at' => $now,
        ], 'id = ?', [$id]);
    }

    // ==================== 通知偏好 ====================

    public static function findNotificationPrefs(int $userId): ?array
    {
        return Database::fetchOne("SELECT * FROM notification_prefs WHERE user_id = ?", [$userId]) ?: null;
    }

    public static function notificationPrefsExist(int $userId): bool
    {
        return (bool)Database::fetchOne("SELECT id FROM notification_prefs WHERE user_id = ?", [$userId]);
    }

    public static function insertNotificationPrefs(array $data): int
    {
        return Database::insert('notification_prefs', $data);
    }

    public static function updateNotificationPrefs(int $userId, array $data): int
    {
        self::assertUpdateFields($data);
        return Database::update('notification_prefs', $data, 'user_id = ?', [$userId]);
    }

    // ==================== 草稿箱 ====================

    /** drafts.extra 列探测（information_schema 查询下沉，进程内缓存留在仓储层） */
    public static function draftsTableHasExtraColumn(): bool
    {
        static $cached = null;
        if ($cached === null) {
            try {
                $row = Database::fetchOne(
                    "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drafts' AND COLUMN_NAME = 'extra'"
                );
                $cached = (int)($row['c'] ?? 0) > 0;
            } catch (\Throwable $e) {
                $cached = false;
            }
        }
        return $cached;
    }

    /**
     * 保存草稿事务。草稿不存在时回滚并返回 null，由控制器转 404；
     * 其它异常 rollBack 后抛出，保持原错误文案。
     */
    public static function saveDraft(int $userId, int $draftId, string $type, int $postId, array $common): ?int
    {
        Database::beginTransaction();
        try {
            if ($draftId > 0) {
                $exists = Database::fetchOne(
                    "SELECT id FROM drafts WHERE id = ? AND user_id = ? LIMIT 1",
                    [$draftId, $userId]
                );
                if (!$exists) {
                    Database::rollBack();
                    return null;
                }
                self::assertUpdateFields($common);
                Database::update('drafts', $common, 'id = ? AND user_id = ?', [$draftId, $userId]);
                $resultId = $draftId;
            } else {
                // postId 显式按 int 绑定：MySQL 会隐式转换字符串，SQLite 不会，
                // 绑定类型显式化可保证两种库上「同类型同目标只留一条」行为一致。
                $del = Database::getInstance()->prepare(
                    "DELETE FROM drafts WHERE user_id = ? AND type = ? AND COALESCE(post_id, 0) = ?"
                );
                $del->bindValue(1, $userId, \PDO::PARAM_INT);
                $del->bindValue(2, $type, \PDO::PARAM_STR);
                $del->bindValue(3, $postId, \PDO::PARAM_INT);
                $del->execute();
                $resultId = Database::insert('drafts', array_merge($common, ['user_id' => $userId]));
            }
            Database::commit();
            return $resultId;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public static function findDraftById(int $id): ?array
    {
        return Database::fetchOne("SELECT * FROM drafts WHERE id = ?", [$id]) ?: null;
    }

    public static function clearDraftContent(int $id, int $userId): int
    {
        return Database::update('drafts', ['content' => null, 'images' => null], 'id = ? AND user_id = ?', [$id, $userId]);
    }

    public static function deleteDraft(int $id, int $userId): int
    {
        return Database::delete('drafts', 'id = ? AND user_id = ?', [$id, $userId]);
    }

    // ==================== 申诉 / 通知 ====================

    /** 今日申诉数（CURDATE() 为原 SQL 一部分，行为等价不改） */
    public static function countTodayAppeals(int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM appeals WHERE user_id = ? AND created_at >= CURDATE()",
            [$userId]
        );
    }

    public static function insertAppeal(array $data): int
    {
        return Database::insert('appeals', $data);
    }

    public static function findAppealById(int $id): ?array
    {
        return Database::fetchOne("SELECT id, user_id, status FROM appeals WHERE id = ?", [$id]) ?: null;
    }

    public static function updateAppeal(int $id, array $data): int
    {
        self::assertUpdateFields($data);
        return Database::update('appeals', $data, 'id = ?', [$id]);
    }

    /** 申诉对象摘要：帖子（行查询带 p.post_type） */
    public static function findAppealPostTarget(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT p.id, p.title, p.content, p.status, p.post_type FROM posts p WHERE p.id = ?",
            [$id]
        ) ?: null;
    }

    public static function findAppealCommentTarget(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT c.id, c.content, c.status, c.post_id FROM comments c WHERE c.id = ?",
            [$id]
        ) ?: null;
    }

    public static function findAppealCircleTarget(int $id): ?array
    {
        return Database::fetchOne("SELECT id, name, status FROM circles WHERE id = ?", [$id]) ?: null;
    }

    public static function insertNotification(array $data): int
    {
        return Database::insert('notifications', $data);
    }

    // ==================== paginate SQL 字面量收敛：用户/草稿/申诉分页 ====================

    /** UserController::list() 的筛选条件（原控制器逐字搬迁） */
    private static function adminUserWhere(string $keyword): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($keyword) {
            $where .= ' AND (username LIKE ? OR nickname LIKE ? OR email LIKE ?)';
            $params = array_fill(0, 3, "%{$keyword}%");
        }
        return [$where, $params];
    }

    /** UserController::list() —— 原 paginate() 的 listSql + LIMIT ?, ? */
    public static function fetchAdminUserPage(string $keyword, int $offset, int $limit): array
    {
        [$where, $params] = self::adminUserWhere($keyword);
        $sql = "SELECT id, username, nickname, avatar, email, role, status, created_at, last_login, muted_until
             FROM users {$where} ORDER BY id DESC";
        return Database::fetchAll($sql . ' LIMIT ?, ?', array_merge($params, [$offset, $limit]));
    }

    /** UserController::list() —— 原 paginate() 的 countSql */
    public static function countAdminUsers(string $keyword): int
    {
        [$where, $params] = self::adminUserWhere($keyword);
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM users {$where}", $params);
    }

    /** UserController::drafts() —— extra 列探测留在仓储，SQL 与 HEAD 逐字一致 */
    public static function fetchDraftPage(int $userId, string $type, int $offset, int $limit): array
    {
        $extraCol = self::draftsTableHasExtraColumn() ? ', extra' : '';
        $sql = "SELECT id, type, circle_id, post_id, title, content, images, visibility, scheduled_at, created_at, updated_at{$extraCol}
             FROM drafts WHERE user_id = ? AND type = ? ORDER BY updated_at DESC";
        return Database::fetchAll($sql . ' LIMIT ?, ?', [$userId, $type, $offset, $limit]);
    }

    public static function countDrafts(int $userId, string $type): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM drafts WHERE user_id = ? AND type = ?",
            [$userId, $type]
        );
    }

    /** UserController::myAppeals() —— 原 paginate() 的 listSql + LIMIT ?, ? */
    public static function fetchMyAppeals(int $userId, int $offset, int $limit): array
    {
        $sql = "SELECT id, target_type, target_id, category, content, status, admin_note, created_at, handled_at
             FROM appeals WHERE user_id = ? ORDER BY created_at DESC";
        return Database::fetchAll($sql . ' LIMIT ?, ?', [$userId, $offset, $limit]);
    }

    public static function countMyAppeals(int $userId): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM appeals WHERE user_id = ?", [$userId]);
    }

    /** UserController::adminAppeals() 的筛选条件（枚举白名单由控制器先归一化） */
    private static function adminAppealWhere(string $status, string $category, string $keyword): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($status !== '') {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
        if ($category !== '') {
            $where .= ' AND a.category = ?';
            $params[] = $category;
        }
        if ($keyword !== '') {
            $where .= ' AND (a.content LIKE ? OR u.username LIKE ? OR u.nickname LIKE ?)';
            $kw = "%{$keyword}%";
            array_push($params, $kw, $kw, $kw);
        }
        return [$where, $params];
    }

    /** UserController::adminAppeals() —— 原 paginate() 的 listSql + LIMIT ?, ? */
    public static function fetchAdminAppeals(string $status, string $category, string $keyword, int $offset, int $limit): array
    {
        [$where, $params] = self::adminAppealWhere($status, $category, $keyword);
        $sql = "SELECT a.id, a.user_id, a.target_type, a.target_id, a.category, a.content, a.images,
                    a.status, a.admin_id, a.admin_note, a.handled_at, a.created_at,
                    u.username, u.nickname, u.avatar
             FROM appeals a LEFT JOIN users u ON u.id = a.user_id
             {$where} ORDER BY a.created_at DESC";
        return Database::fetchAll($sql . ' LIMIT ?, ?', array_merge($params, [$offset, $limit]));
    }

    /** UserController::adminAppeals() —— 原 paginate() 的 countSql */
    public static function countAdminAppeals(string $status, string $category, string $keyword): int
    {
        [$where, $params] = self::adminAppealWhere($status, $category, $keyword);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM appeals a LEFT JOIN users u ON u.id = a.user_id {$where}",
            $params
        );
    }
}
