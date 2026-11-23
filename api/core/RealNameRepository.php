<?php
/**
 * Hublog 实名认证域数据仓储
 *
 * 承载 RealNameController 下沉的全部 SQL 与参数绑定。敏感字段
 * （real_name_masked / id_card_masked / id_card_hash）只写脱敏/哈希值，
 * 查询字段清单与迁移前 HEAD 原文逐字一致；status / CURDATE() 条件留在 SQL。
 *
 * 【相似但不同的查询刻意不合并】
 *  - status() 的两条 users 查询字段不同（两条 + 一条），各建各的方法；
 *  - 三条 pending/rejected 插入与 markVerified() 插入字段数不同（10 列 vs 13 列），各建各的；
 *  - approve/reject/revoke 三条 UPDATE 的字段清单不同，分别建方法，避免误复用丢字段。
 */

declare(strict_types=1);

namespace Hublog\Core;

class RealNameRepository
{
    /** 待审核 / 驳回记录插入允许的列 */
    private const RECORD_COLUMNS = [
        'user_id', 'real_name_masked', 'id_card_masked', 'id_card_hash', 'provider',
        'status', 'fail_reason', 'provider_code', 'created_at', 'updated_at',
    ];

    /** 自动核验通过记录额外允许的列 */
    private const VERIFIED_RECORD_COLUMNS = [
        'user_id', 'real_name_masked', 'id_card_masked', 'id_card_hash', 'provider',
        'status', 'fail_reason', 'provider_code', 'reviewer_id', 'reviewed_at',
        'verified_at', 'created_at', 'updated_at',
    ];

    /** 动态列名白名单 + 正则双校验，字段名永不进参数数组 */
    private static function assertColumns(array $data, array $allowed): void
    {
        foreach (array_keys($data) as $column) {
            if (!is_string($column)
                || !preg_match('/^[a-z_]+$/', $column)
                || !in_array($column, $allowed, true)) {
                throw new \InvalidArgumentException('非法实名记录字段');
            }
        }
    }

    // ==================== 用户侧 ====================

    /** status() —— 用户认证冗余状态（2 列，需实时查库） */
    public static function findUserVerification(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT realname_verified, realname_verified_at FROM users WHERE id = ? LIMIT 1",
            [$userId]
        );
    }

    /** verify() —— 是否已认证（1 列，与 status() 的 2 列查询刻意分开） */
    public static function findVerifiedFlag(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT realname_verified FROM users WHERE id = ? LIMIT 1",
            [$userId]
        );
    }

    /** verify() —— 当日核验次数（CURDATE() 口径留在 SQL） */
    public static function countTodaySubmissions(int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM realname_records
             WHERE user_id = ? AND created_at >= CURDATE()",
            [$userId]
        );
    }

    /** status() —— 最近一条认证记录（脱敏字段 + 状态） */
    public static function findLatestRecord(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id, status, real_name_masked, id_card_masked, provider, fail_reason, verified_at, created_at
             FROM realname_records WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$userId]
        );
    }

    /** verify() —— 身份证哈希查重（3 列） */
    public static function findRecordByHash(string $hash): ?array
    {
        return Database::fetchOne(
            "SELECT id, user_id, status FROM realname_records WHERE id_card_hash = ? LIMIT 1",
            [$hash]
        );
    }

    /** verify() —— 清理本人此前的未通过记录（释放哈希唯一键） */
    public static function deleteUnsuccessfulRecords(int $userId): void
    {
        Database::query(
            "DELETE FROM realname_records WHERE user_id = ? AND status IN ('pending','rejected','revoked')",
            [$userId]
        );
    }

    /** verify() —— 人工待审 / provider pending / 拒绝三种 10 列插入共用 */
    public static function insertRecord(array $data): void
    {
        self::assertColumns($data, self::RECORD_COLUMNS);
        Database::insert('realname_records', $data);
    }

    /** markVerified() —— 自动核验通过的 13 列插入（独立字段清单） */
    public static function insertVerifiedRecord(array $data): void
    {
        self::assertColumns($data, self::VERIFIED_RECORD_COLUMNS);
        Database::insert('realname_records', $data);
    }

    /** markVerified() / adminReview() —— 用户冗余认证状态回写（两个字段固定） */
    public static function setUserVerified(int $userId, int $verified, ?string $verifiedAt): void
    {
        Database::update('users', [
            'realname_verified' => $verified,
            'realname_verified_at' => $verifiedAt,
        ], 'id = ?', [$userId]);
    }

    // ==================== 事务写序列 ====================

    /**
     * 提交待审 / provider-pending / 拒绝记录：清理旧记录 + 插入新记录在单一事务内完成。
     * 调用方需自行捕获 1062 并映射为 409（外部 HTTP 核验不进事务）。
     */
    public static function submitVerification(array $record, int $userId, bool $clearOld): void
    {
        Database::beginTransaction();
        try {
            if ($clearOld) {
                self::deleteUnsuccessfulRecords($userId);
            }
            self::insertRecord($record);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** 自动核验通过：插记录 + 回写用户认证状态，原子提交 */
    public static function insertVerifiedAndSetUser(array $record, int $userId, string $now): void
    {
        Database::beginTransaction();
        try {
            self::insertVerifiedRecord($record);
            self::setUserVerified($userId, 1, $now);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** 后台通过：记录更新 + 用户状态回写，原子提交 */
    public static function approveWithUser(int $id, int $userId, array $review): void
    {
        Database::beginTransaction();
        try {
            self::approveRecord($id, $review);
            self::setUserVerified($userId, 1, (string)$review['now']);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** 后台驳回：记录更新 + 清除用户认证状态，原子提交 */
    public static function rejectWithUser(int $id, int $userId, array $review): void
    {
        Database::beginTransaction();
        try {
            self::rejectRecord($id, $review);
            self::setUserVerified($userId, 0, null);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** 后台撤销：记录更新 + 清除用户认证状态，原子提交 */
    public static function revokeWithUser(int $id, int $userId, array $review): void
    {
        Database::beginTransaction();
        try {
            self::revokeRecord($id, $review);
            self::setUserVerified($userId, 0, null);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ==================== 管理端 ====================

    /**
     * adminList() —— 列表 / 计数共用 WHERE 助手。
     *
     * status 必须在白名单枚举内才生效；LIKE 的 % 前后缀由本助手生成并绑定。
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function adminListWhere(string $status, string $keyword): array
    {
        $where = '1=1';
        $params = [];
        if ($status !== '' && in_array($status, ['pending', 'verified', 'rejected', 'revoked'], true)) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $where .= ' AND (u.username LIKE ? OR u.nickname LIKE ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
        }
        return [$where, $params];
    }

    /** adminList() —— 总数 */
    public static function countAdminRecords(string $status, string $keyword): int
    {
        [$where, $params] = self::adminListWhere($status, $keyword);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM realname_records r INNER JOIN users u ON u.id = r.user_id WHERE {$where}",
            $params
        );
    }

    /** adminList() —— 列表（16 列顺序 + ORDER BY r.id DESC + LIMIT ? OFFSET ?） */
    public static function fetchAdminRecords(string $status, string $keyword, int $limit, int $offset): array
    {
        [$where, $params] = self::adminListWhere($status, $keyword);
        return Database::fetchAll(
            "SELECT r.id, r.user_id, r.real_name_masked, r.id_card_masked, r.provider, r.status,
                       r.fail_reason, r.provider_code, r.reviewer_id, r.reviewed_at, r.verified_at, r.created_at,
                       u.username, u.nickname, u.avatar, u.realname_verified
                FROM realname_records r
                INNER JOIN users u ON u.id = r.user_id
                WHERE {$where}
                ORDER BY r.id DESC
                LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }

    /** adminReview() —— 待审核记录（SELECT *） */
    public static function findRecordById(int $id): ?array
    {
        return Database::fetchOne("SELECT * FROM realname_records WHERE id = ? LIMIT 1", [$id]);
    }

    /** adminReview() —— 同身份证已被其他通过账号占用 */
    public static function findVerifiedConflict(string $idCardHash, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id, user_id FROM realname_records
                 WHERE id_card_hash = ? AND status = 'verified' AND user_id <> ? LIMIT 1",
            [$idCardHash, $userId]
        );
    }

    /** adminReview() approve —— 6 个字段固定写入（不复用 reject/revoke） */
    public static function approveRecord(int $id, array $review): void
    {
        Database::update('realname_records', [
            'status' => \Hublog\Core\RealName::STATUS_VERIFIED,
            'fail_reason' => '',
            'reviewer_id' => $review['reviewer_id'],
            'reviewed_at' => $review['now'],
            'verified_at' => $review['now'],
            'updated_at' => $review['now'],
        ], 'id = ?', [$id]);
    }

    /** adminReview() reject —— 5 个字段固定写入 */
    public static function rejectRecord(int $id, array $review): void
    {
        Database::update('realname_records', [
            'status' => \Hublog\Core\RealName::STATUS_REJECTED,
            'fail_reason' => $review['fail_reason'],
            'reviewer_id' => $review['reviewer_id'],
            'reviewed_at' => $review['now'],
            'updated_at' => $review['now'],
        ], 'id = ?', [$id]);
    }

    /** adminReview() revoke —— 5 个字段固定写入（status 与 reject 不同） */
    public static function revokeRecord(int $id, array $review): void
    {
        Database::update('realname_records', [
            'status' => \Hublog\Core\RealName::STATUS_REVOKED,
            'fail_reason' => $review['fail_reason'],
            'reviewer_id' => $review['reviewer_id'],
            'reviewed_at' => $review['now'],
            'updated_at' => $review['now'],
        ], 'id = ?', [$id]);
    }

    /** adminReview() —— 审核后的状态回读 */
    public static function fetchRecordStatus(int $id): mixed
    {
        return Database::fetchColumn("SELECT status FROM realname_records WHERE id = ? LIMIT 1", [$id]);
    }
}
