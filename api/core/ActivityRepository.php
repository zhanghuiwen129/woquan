<?php
declare(strict_types=1);

namespace Hublog\Core;

/**
 * 活动域数据仓储。
 *
 * 承载 ActivityController（前台）与 AdminActivitiesController（后台）下沉的全部 SQL。
 * 所有值一律走 ? 绑定；字段清单、顺序、别名、JOIN、ORDER BY、LIMIT/OFFSET
 * 与迁移前 HEAD 原文逐字一致。
 *
 * 【相似但不同的查询刻意不合并】
 *  - fetchActivities（前台）：12 列 + c.circle_name + joined_count，ORDER BY a.created_at DESC，
 *    支持 circle_id 过滤，status 允许 ''；由 BaseController::paginate 追加 LIMIT ?, ?；
 *  - fetchAdminActivities（后台）：15 列（多 creator_id / checkin_goal），ORDER BY a.id DESC，
 *    支持标题关键词与圈主 owned 圈子 IN 过滤，显式 LIMIT ?, ?。
 *  两者字段集与排序均不同，绝不复用同一方法。
 *  - findActivityDetailById（a.* + 圈子名）≠ findActivityById（裸 SELECT *）；
 *  - countOwnedCircles（无 LIMIT）≠ countCircleById（带 LIMIT 1）。
 *
 * 控制器保留权限、参数校验、时间与状态文案；本类不组装响应、不做权限判断。
 */
class ActivityRepository
{
    /** 后台允许的活动状态（与 AdminActivitiesController 入参校验口径一致） */
    private const ADMIN_STATUSES = ['open', 'closed', 'ended'];

    /** 前台活动列表 / 后台活动列表均可写的字段 */
    private const ACTIVITY_FIELDS = [
        'circle_id', 'title', 'description', 'cover', 'type', 'mode', 'capacity',
        'checkin_goal', 'start_at', 'end_at', 'status', 'updated_at',
    ];

    // ==================== 前台列表（ActivityController::list） ====================

    /**
     * 前台活动列表 WHERE：列表与计数共用，避免翻页错位。
     * 入参已由控制器归一化（type 仅 '' / 'signup'，status 仅 open/closed/ended/''）；
     * 这里再做一次白名单守卫，防止仓储被内部调用时绕过入口校验。
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function activityWhere(
        int $circleId,
        string $type,
        string $status,
        string $keyword
    ): array {
        $where = 'WHERE 1=1';
        $params = [];
        if ($circleId > 0) {
            $where .= ' AND a.circle_id = ?';
            $params[] = $circleId;
        }
        if ($type === 'signup') {
            $where .= ' AND a.type = ?';
            $params[] = $type;
        }
        if ($status !== '' && in_array($status, self::ADMIN_STATUSES, true)) {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $where .= ' AND a.title LIKE ?';
            $params[] = "%{$keyword}%";
        }
        return [$where, $params];
    }

    /** list() —— 前台活动列表；LIMIT ?, ? 由原 BaseController::paginate 追加，参数顺序 offset, limit */
    public static function fetchActivities(
        int $circleId,
        string $type,
        string $status,
        string $keyword,
        int $offset,
        int $limit
    ): array {
        [$where, $params] = self::activityWhere($circleId, $type, $status, $keyword);
        return Database::fetchAll(
            "SELECT a.id, a.circle_id, a.title, a.description, a.cover, a.type, a.mode, a.capacity,
                    a.start_at, a.end_at, a.status, a.created_at,
                    c.name AS circle_name,
                    (SELECT COUNT(*) FROM activity_registrations r
                      WHERE r.activity_id = a.id AND r.status = 'joined') AS joined_count
             FROM activities a
             LEFT JOIN circles c ON c.id = a.circle_id
             {$where}
             ORDER BY a.created_at DESC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** list() —— 与前台列表同一 WHERE 的计数（COUNT(*) 保持不变） */
    public static function countActivities(
        int $circleId,
        string $type,
        string $status,
        string $keyword
    ): int {
        [$where, $params] = self::activityWhere($circleId, $type, $status, $keyword);
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM activities a {$where}", $params);
    }

    // ==================== 前台详情 / 报名 / 我的活动 ====================

    /** detail() —— 活动详情（a.* + 圈子名，LIMIT 1） */
    public static function findActivityDetailById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT a.*, c.name AS circle_name
             FROM activities a LEFT JOIN circles c ON c.id = a.circle_id
             WHERE a.id = ? LIMIT 1",
            [$id]
        );
    }

    /** detail() / join() —— 已报名人数；原文 fetchColumn + 调用方 (int) 强转 */
    public static function countJoinedRegistrations(int $activityId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM activity_registrations WHERE activity_id = ? AND status = 'joined'",
            [$activityId]
        );
    }

    /** detail() —— 当前用户是否已报名；原文取 id 标量后 (bool) 强转 */
    public static function hasJoinedRegistration(int $activityId, int $userId): bool
    {
        return (bool)Database::fetchColumn(
            "SELECT id FROM activity_registrations WHERE activity_id = ? AND user_id = ? AND status = 'joined' LIMIT 1",
            [$activityId, $userId]
        );
    }

    /** join() —— 裸活动行（SELECT *，LIMIT 1） */
    public static function findActivityById(int $activityId): ?array
    {
        return Database::fetchOne("SELECT * FROM activities WHERE id = ? LIMIT 1", [$activityId]);
    }

    /** join() —— 当前用户报名行（id, status，无 LIMIT） */
    public static function findRegistration(int $activityId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id, status FROM activity_registrations WHERE activity_id = ? AND user_id = ? LIMIT 1",
            [$activityId, $userId]
        );
    }

    /** join() —— 已取消后重新报名：更新既有行，不新增 */
    public static function updateRegistrationToJoined(int $registrationId, ?string $note, string $now): void
    {
        if ($registrationId <= 0) {
            throw new \InvalidArgumentException('参数错误');
        }
        Database::update(
            'activity_registrations',
            ['status' => 'joined', 'note' => $note, 'updated_at' => $now],
            'id = ?',
            [$registrationId]
        );
    }

    /** join() —— 首次报名写入 */
    public static function insertRegistration(int $activityId, int $userId, ?string $note): int
    {
        if ($activityId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('参数错误');
        }
        return Database::insert('activity_registrations', [
            'activity_id' => $activityId,
            'user_id'     => $userId,
            'status'      => 'joined',
            'note'        => $note,
        ]);
    }

    /** leave() —— 仅把本人 joined 行改为 canceled，返回影响行数 */
    public static function cancelRegistration(int $activityId, int $userId, string $now): int
    {
        if ($activityId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('参数错误');
        }
        return Database::update(
            'activity_registrations',
            ['status' => 'canceled', 'updated_at' => $now],
            'activity_id = ? AND user_id = ? AND status = ?',
            [$activityId, $userId, 'joined']
        );
    }

    /** mine() —— 我报名的活动（INNER JOIN + LIMIT 50，字段顺序逐字不变） */
    public static function fetchMyActivities(int $userId): array
    {
        return Database::fetchAll(
            "SELECT a.id, a.title, a.cover, a.type, a.status, a.end_at,
                    r.status AS reg_status, r.note, r.updated_at
             FROM activity_registrations r
             INNER JOIN activities a ON a.id = r.activity_id
             WHERE r.user_id = ? AND r.status = 'joined'
             ORDER BY a.created_at DESC LIMIT 50",
            [$userId]
        );
    }

    // ==================== 后台活动管理（AdminActivitiesController） ====================

    /**
     * 后台活动列表 WHERE：列表与计数共用。
     * 圈主权限带来的 owned 圈子 IN 过滤由控制器先判定，空数组时控制器直接返回空页；
     * 这里仍保留空数组守卫，避免仓储被绕过时生成非法 IN ()。
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function adminActivityWhere(
        string $keyword,
        string $type,
        string $status,
        array $ownedCircleIds
    ): array {
        $where = 'WHERE 1=1';
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND a.title LIKE ?';
            $params[] = '%' . $keyword . '%';
        }
        if ($type === 'signup') {
            $where .= ' AND a.type = ?';
            $params[] = $type;
        }
        if (in_array($status, self::ADMIN_STATUSES, true)) {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
        if ($ownedCircleIds !== []) {
            $ids = array_map('intval', array_values($ownedCircleIds));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND a.circle_id IN ({$placeholders})";
            $params = array_merge($params, $ids);
        }
        return [$where, $params];
    }

    /** activities() —— 后台活动列表（15 列 + ORDER BY a.id DESC + LIMIT ?, ?） */
    public static function fetchAdminActivities(
        string $keyword,
        string $type,
        string $status,
        array $ownedCircleIds,
        int $offset,
        int $limit
    ): array {
        [$where, $params] = self::adminActivityWhere($keyword, $type, $status, $ownedCircleIds);
        return Database::fetchAll(
            "SELECT a.id, a.circle_id, a.creator_id, a.title, a.description, a.cover,
                    a.type, a.mode, a.capacity, a.checkin_goal, a.start_at, a.end_at,
                    a.status, a.created_at,
                    c.name AS circle_name,
                    (SELECT COUNT(*) FROM activity_registrations r
                      WHERE r.activity_id = a.id AND r.status = 'joined') AS joined_count
             FROM activities a
             LEFT JOIN circles c ON c.id = a.circle_id
             {$where}
             ORDER BY a.id DESC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** activities() —— 后台活动计数，与后台列表同一 WHERE */
    public static function countAdminActivities(
        string $keyword,
        string $type,
        string $status,
        array $ownedCircleIds
    ): int {
        [$where, $params] = self::adminActivityWhere($keyword, $type, $status, $ownedCircleIds);
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM activities a {$where}", $params);
    }

    /** saveActivity() —— 关联圈子存在性（COUNT(*) + LIMIT 1） */
    public static function countCircleById(int $circleId): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM circles WHERE id = ? LIMIT 1", [$circleId]);
    }

    /** deleteActivity() —— 删除前的归属校验行（id, circle_id，LIMIT 1） */
    public static function findActivityCircleRow(int $activityId): ?array
    {
        return Database::fetchOne("SELECT id, circle_id FROM activities WHERE id = ? LIMIT 1", [$activityId]);
    }

    /** saveActivity() —— 更新前读取活动当前所属圈子（fetchColumn，LIMIT 1） */
    public static function fetchActivityCircleId(int $activityId): mixed
    {
        return Database::fetchColumn("SELECT circle_id FROM activities WHERE id = ? LIMIT 1", [$activityId]);
    }

    /** saveActivity() —— 活动存在性（COUNT(*) + LIMIT 1） */
    public static function existsActivityById(int $activityId): bool
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM activities WHERE id = ? LIMIT 1",
            [$activityId]
        ) > 0;
    }

    /** saveActivity() —— 更新活动；列名白名单 + 正则双重校验，字段名不进参数数组 */
    public static function updateActivityFields(int $id, array $data): void
    {
        if ($id <= 0 || $data === []) {
            throw new \InvalidArgumentException('参数错误');
        }
        self::assertActivityFields($data, false);
        Database::update('activities', $data, 'id = ?', [$id]);
    }

    /** saveActivity() —— 新建活动；比更新额外允许 creator_id / created_at */
    public static function insertActivity(array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('参数错误');
        }
        self::assertActivityFields($data, true);
        return Database::insert('activities', $data);
    }

    /** deleteActivity() —— 按主键删除（外键级联由数据库负责） */
    public static function deleteActivityById(int $id): int
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('参数错误');
        }
        return Database::delete('activities', 'id = ?', [$id]);
    }

    /** myCircles() —— 站点管理员的全部圈子（固定 LIMIT 200） */
    public static function fetchAdminMyCircles(): array
    {
        return Database::fetchAll(
            "SELECT id, name, owner_id, member_count, status FROM circles ORDER BY id DESC LIMIT 200"
        );
    }

    /** myCircles() —— 圈主名下圈子（IN 占位符由白名单数组长度生成） */
    public static function fetchOwnedCircles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_map('intval', array_values($ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return Database::fetchAll(
            "SELECT id, name, owner_id, member_count, status FROM circles WHERE id IN ({$placeholders}) ORDER BY id DESC",
            $ids
        );
    }

    /** activityManager() —— 是否拥有至少一个圈子（COUNT(*) 无 LIMIT） */
    public static function countOwnedCircles(int $userId): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM circles WHERE owner_id = ?", [$userId]);
    }

    /** ownedCircleIds() —— 圈主名下的圈子 ID（无 LIMIT / 无 ORDER BY） */
    public static function fetchOwnedCircleIds(int $userId): array
    {
        return Database::fetchAll("SELECT id FROM circles WHERE owner_id = ?", [$userId]);
    }

    /**
     * 活动写入字段白名单：动态列名只能来自此处，且必须匹配 /^[a-z_]+$/。
     *
     * @param array<string,mixed> $data
     */
    private static function assertActivityFields(array $data, bool $allowMeta): void
    {
        $allowed = self::ACTIVITY_FIELDS;
        if ($allowMeta) {
            $allowed[] = 'creator_id';
            $allowed[] = 'created_at';
        }
        foreach (array_keys($data) as $column) {
            $fragment = (string)$column . ' = ?';
            if (!is_string($column)
                || !in_array($column, $allowed, true)
                || preg_match('/^[a-z_]+ = \?$/', $fragment) !== 1
            ) {
                throw new \InvalidArgumentException('非法字段名');
            }
        }
    }
}
