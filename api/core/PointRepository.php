<?php
/**
 * Hublog 积分域数据仓储
 *
 * 承载 PointController 下沉的全部 SQL 与事务边界（home / signin / logs / claim /
 * wheel / wheel-records）。所有值一律走 ? 绑定；字段清单、顺序、别名、JOIN、
 * ORDER BY、LIMIT/OFFSET 与迁移前 HEAD 原文逐字一致。
 *
 * 【相似但不同的查询刻意不合并】
 *  - users.points：fetchPointsRowById（fetchOne，无 LIMIT） ≠
 *    fetchPointsColumnById（fetchColumn，无 LIMIT） ≠
 *    fetchPointsAfterById（fetchColumn，带 LIMIT 1）；
 *  - user_signins：findTodaySignin（id, points） ≠ findSignDateByDate（sign_date） ≠
 *    findSigninIdByDate（id）；
 *  - wheel_prizes：fetchAvailableWheelPrizes（4 列 + ORDER BY） ≠
 *    fetchDrawableWheelPrizes（SELECT *，无 ORDER BY）；商城域的
 *    ShopRepository::fetchAllWheelPrizes（SELECT * + ORDER BY，含停用）另属一域，
 *    三者语义/字段/排序均不同，互不复用。
 *
 * 控制器保留权限、参数校验、缓存与响应包装；本类不组装响应、不做权限判断。
 */

declare(strict_types=1);

namespace Hublog\Core;

class PointRepository
{
    /** points_logs 流水分类白名单（与控制器 POINT_LOG_TYPES 一致） */
    private const POINT_LOG_TYPES = [
        'signin', 'task', 'spend', 'reward', 'adjust', 'refund', 'post', 'comment'
    ];

    /**
     * 内置任务类型 → 计数器 SQL。
     *
     * 原控制器用 str_replace 把 UID / 月份拼进 SQL；这里全部改为 ? 绑定，
     * 类型名由本类白名单决定，任何请求值都不进 SQL 文本。
     */
    private const TASK_COUNTERS = [
        'profile'     => "SELECT 1 FROM users u WHERE u.id = ? AND u.nickname <> '' AND u.avatar IS NOT NULL AND u.avatar <> ''",
        'post'        => "SELECT COUNT(*) FROM posts p WHERE p.user_id = ? AND p.status = 1",
        'comment'     => "SELECT COUNT(*) FROM comments c WHERE c.user_id = ? AND c.post_id > 0",
        'like'        => "SELECT COUNT(*) FROM likes l WHERE l.user_id = ?",
        'circle_join' => "SELECT COUNT(*) FROM circle_members cm WHERE cm.user_id = ?",
        'follow'      => "SELECT COUNT(*) FROM follows f WHERE f.follower_id = ?",
        'signin_days' => "SELECT COUNT(*) FROM user_signins s WHERE s.user_id = ? AND s.sign_month = ?",
    ];

    // ==================== users / settings 基础读取 ====================

    /** home() / currentPoints() —— 积分余额行（无 LIMIT，与原文一致） */
    public static function fetchPointsRowById(int $userId): ?array
    {
        return Database::fetchOne("SELECT points FROM users WHERE id = ?", [$userId]);
    }

    /** wheel() —— 余额校验标量（fetchColumn，无 LIMIT，与原文一致） */
    public static function fetchPointsColumnById(int $userId): mixed
    {
        return Database::fetchColumn("SELECT points FROM users WHERE id = ?", [$userId]);
    }

    /** addPoints() / wheel() —— 变动后余额标量（fetchColumn，带 LIMIT 1） */
    public static function fetchPointsAfterById(int $userId): mixed
    {
        return Database::fetchColumn("SELECT points FROM users WHERE id = ? LIMIT 1", [$userId]);
    }

    /** setting() —— 单配置值；SQL 与迁移前逐字一致 */
    public static function fetchSetting(string $key): mixed
    {
        return Database::fetchColumn("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1", [$key]);
    }

    // ==================== 积分流水 ====================

    /**
     * logs() —— 流水 WHERE 助手：列表与计数共用，避免翻页错位。
     * direction / type 的合法性由控制器校验；这里只负责组装参数化条件。
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function logsWhere(int $userId, string $direction, string $type): array
    {
        $where = 'user_id = ?';
        $params = [$userId];
        if ($direction === 'income') {
            $where .= ' AND points > 0';
        } elseif ($direction === 'expense') {
            $where .= ' AND points < 0';
        }
        if ($type !== '' && in_array($type, self::POINT_LOG_TYPES, true)) {
            $where .= ' AND type = ?';
            $params[] = $type;
        }
        return [$where, $params];
    }

    /** logs() —— 流水列表（字段清单/顺序、ORDER BY、LIMIT ? OFFSET ? 与原文一致） */
    public static function fetchLogs(int $userId, string $direction, string $type, int $limit, int $offset): array
    {
        [$where, $params] = self::logsWhere($userId, $direction, $type);
        return Database::fetchAll(
            "SELECT id, points, type, biz_type, biz_id, balance_after, remark, created_at
             FROM points_logs WHERE {$where}
             ORDER BY id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }

    /** logs() —— 与列表同 WHERE 的计数 */
    public static function countLogs(int $userId, string $direction, string $type): int
    {
        [$where, $params] = self::logsWhere($userId, $direction, $type);
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM points_logs WHERE {$where}", $params);
    }

    /** logs() —— 不受筛选影响的全量收支统计 */
    public static function summarizeLogs(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) AS earned,
                COALESCE(SUM(CASE WHEN points < 0 THEN -points ELSE 0 END), 0) AS spent,
                COUNT(*) AS cnt
             FROM points_logs WHERE user_id = ?",
            [$userId]
        );
    }

    // ==================== 签到 ====================

    /** signin() / todaySignin() —— 当天签到行（id, points，LIMIT 1） */
    public static function findTodaySignin(int $userId, string $today): ?array
    {
        return Database::fetchOne(
            "SELECT id, points FROM user_signins WHERE user_id = ? AND sign_date = ? LIMIT 1",
            [$userId, $today]
        );
    }

    /** signin() —— 昨日连击行（streak，LIMIT 1） */
    public static function findYesterdayStreak(int $userId, string $yesterday): ?array
    {
        return Database::fetchOne(
            "SELECT streak FROM user_signins WHERE user_id = ? AND sign_date = ? LIMIT 1",
            [$userId, $yesterday]
        );
    }

    /** currentStreak() —— 首日存在性探针（sign_date 列，LIMIT 1） */
    public static function findSignDateByDate(int $userId, string $date): ?array
    {
        return Database::fetchOne(
            "SELECT sign_date FROM user_signins WHERE user_id = ? AND sign_date = ? LIMIT 1",
            [$userId, $date]
        );
    }

    /** currentStreak() —— 逐日回溯探针（id 列，LIMIT 1） */
    public static function findSigninIdByDate(int $userId, string $date): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM user_signins WHERE user_id = ? AND sign_date = ? LIMIT 1",
            [$userId, $date]
        );
    }

    /** buildCalendar() —— 指定月份签到记录（无 ORDER BY，与原文一致） */
    public static function fetchMonthSignins(int $userId, string $month): array
    {
        return Database::fetchAll(
            "SELECT sign_date, points, streak FROM user_signins
             WHERE user_id = ? AND sign_month = ?",
            [$userId, $month]
        );
    }

    /** monthSignedCount() —— 当月签到天数 */
    public static function countMonthSignins(int $userId, string $month): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM user_signins WHERE user_id = ? AND sign_month = ?",
            [$userId, $month]
        );
    }

    /**
     * signin() —— 签到入库 + 加积分完整事务。
     *
     * 幂等「当天已签」由调用方先查 findTodaySignin 保证；这里不擅自改业务规则。
     * 事务内任一步失败则 rollBack 后 throw，由控制器保持原有 500 文案。
     *
     * @return int 变动后的积分余额（原 $after）
     */
    public static function insertSigninAndAddPoints(
        int $userId,
        int $reward,
        int $streak,
        int $day,
        string $today,
        string $month
    ): int {
        Database::beginTransaction();
        try {
            $signId = (int)Database::insert('user_signins', [
                'user_id' => $userId,
                'sign_month' => $month,
                'sign_date' => $today,
                'points' => $reward,
                'streak' => $streak,
            ]);

            $after = self::addPoints(
                $userId,
                $reward,
                'signin',
                'signin',
                $signId,
                "每日签到（第 {$day} 天，连续 {$streak} 天）"
            );

            Database::commit();
            return $after;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ==================== 任务 ====================

    /** taskList() —— 启用中的任务列表（字段/排序逐字保留） */
    public static function fetchActiveTasks(): array
    {
        return Database::fetchAll(
            "SELECT id, title, `desc`, points, task_type, target, period, status, sort
             FROM point_tasks WHERE status = 1 ORDER BY sort ASC, id ASC"
        );
    }

    /** claim() —— 任务定义（SELECT *，LIMIT 1） */
    public static function findTaskById(int $taskId): ?array
    {
        return Database::fetchOne("SELECT * FROM point_tasks WHERE id = ? LIMIT 1", [$taskId]);
    }

    /** taskList() —— 用户任务进度日志 */
    public static function fetchUserTaskLogs(int $userId): array
    {
        return Database::fetchAll(
            "SELECT task_id, times, claimed, last_date FROM user_task_logs WHERE user_id = ?",
            [$userId]
        );
    }

    /** claim() —— 单任务领取记录（SELECT *，LIMIT 1） */
    public static function findUserTaskLog(int $userId, int $taskId): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM user_task_logs WHERE user_id = ? AND task_id = ? LIMIT 1",
            [$userId, $taskId]
        );
    }

    /**
     * taskProgress() —— 任务完成次数计数器（全部值绑定）。
     *
     * $date 非 null 时追加与原控制器完全相同的 `AND DATE(created_at) = ?`
     * （daily 周期按自然日重算）。
     */
    public static function countTaskProgress(int $userId, string $type, string $month, ?string $date): int
    {
        if (!isset(self::TASK_COUNTERS[$type])) {
            return 0;
        }
        $sql = self::TASK_COUNTERS[$type];
        $params = [$userId];
        if ($type === 'signin_days') {
            $params[] = $month;
        }
        if ($date !== null) {
            $sql .= " AND DATE(created_at) = ?";
            $params[] = $date;
        }
        try {
            return (int)Database::fetchColumn($sql, $params);
        } catch (\Throwable $e) {
            // 与原控制器一致：计数器异常不阻断任务列表，记日志后按 0 处理
            error_log('[Hublog taskProgress ' . $type . '] ' . $e->getMessage()); // @allow-error-log
            return 0;
        }
    }

    /**
     * claim() —— 领取任务：确保进度行存在 + 事务内条件抢占 + 发积分。
     *
     * 权威判定是事务内的条件 UPDATE（rowCount===1 才发分），控制器侧的进度检查仅作快速失败。
     * 同一 (user_id, task_id, period) 重复提交时，第二次必然抢不到，绝不重复发分。
     */
    public static function claimTaskAndAddPoints(
        int $userId,
        int $taskId,
        int $progress,
        int $reward,
        string $today,
        string $title,
        string $period = 'daily'
    ): int {
        Database::beginTransaction();
        try {
            // ① no-op upsert：确保进度行存在，已存在则什么都不做。
            // 不用 MySQL 专有的 ON DUPLICATE KEY UPDATE（SQLite 行为测试不支持），
            // 改「先查后写 + 唯一键冲突回读」，与 CronAuth 的跨库写法一致。
            $exists = Database::fetchColumn(
                'SELECT id FROM user_task_logs WHERE user_id = ? AND task_id = ? LIMIT 1',
                [$userId, $taskId]
            );
            if (!$exists) {
                try {
                    Database::insert('user_task_logs', [
                        'user_id' => $userId,
                        'task_id' => $taskId,
                        'times' => $progress,
                        'claimed' => 0,
                        'last_date' => null,
                    ]);
                } catch (\Throwable $e) {
                    // 并发下另一请求已插入（uk_user_task 冲突）→ 回读确认后继续抢
                    $exists = Database::fetchColumn(
                        'SELECT id FROM user_task_logs WHERE user_id = ? AND task_id = ? LIMIT 1',
                        [$userId, $taskId]
                    );
                    if (!$exists) {
                        throw $e;
                    }
                }
            }

            // ② 条件抢占：once 只允许领一次；daily 允许跨日期重领但同一天只能领一次。
            if ($period === 'once') {
                $cond = 'claimed = 0';
                $condParams = [];
            } else {
                $cond = '(claimed = 0 OR last_date <> ?)';
                $condParams = [$today];
            }
            $claim = Database::query(
                "UPDATE user_task_logs SET times = ?, claimed = 1, last_date = ?
                 WHERE user_id = ? AND task_id = ? AND {$cond}",
                array_merge([$progress, $today, $userId, $taskId], $condParams)
            );
            if ($claim->rowCount() !== 1) {
                // 未抢到：提交空事务后返回当前余额，绝不发分
                Database::commit();
                return self::currentBalance($userId);
            }

            // ③ 抢到才发分
            $after = self::addPoints($userId, $reward, 'task', 'task', $taskId, "完成任务：{$title}");
            Database::commit();
            return $after;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** 当前积分余额（重复领奖时返回，不改变任何数据） */
    private static function currentBalance(int $userId): int
    {
        $row = self::fetchPointsRowById($userId);
        return (int)($row['points'] ?? 0);
    }

    // ==================== 积分变动（双写，供本控制器与 PaymentController 复用） ====================

    /**
     * 给某用户加/扣积分，同时写流水；不带事务，便于在调用方的事务内复用。
     *
     * 条件原子更新 `points + ? >= 0` 原样保留（并发下防止扣成负数）；
     * 错误文案「用户不存在」「积分不足」原样抛出，由调用方转响应。
     */
    public static function addPoints(
        int $userId,
        int $delta,
        string $type,
        string $bizType,
        int $bizId,
        string $remark
    ): int {
        // V147 短路：delta=0 时 HEAD 直接返回当前余额，不 UPDATE、不写流水。
        // 缺少此分支时 MySQL 的 `points + 0` 更新 rowCount=0 会被误判为「积分不足」，
        // 使 wheel_cost=0 / 0 分奖品等合法配置在事务内整单回滚。
        if ($delta === 0) {
            $row = self::fetchPointsRowById($userId);
            return (int)($row['points'] ?? 0);
        }

        $stmt = Database::query(
            "UPDATE users SET points = points + ? WHERE id = ? AND points + ? >= 0",
            [$delta, $userId, $delta]
        );
        if ($stmt->rowCount() === 0) {
            $exists = Database::fetchColumn("SELECT id FROM users WHERE id = ? LIMIT 1", [$userId]);
            if (!$exists) {
                throw new \RuntimeException('用户不存在');
            }
            throw new \RuntimeException('积分不足');
        }

        $after = (int)self::fetchPointsAfterById($userId);
        Database::insert('points_logs', [
            'user_id' => $userId,
            'points' => $delta,
            'type' => $type,
            'biz_type' => $bizType,
            'biz_id' => $bizId,
            'balance_after' => $after,
            'remark' => $remark,
        ]);

        return $after;
    }

    // ==================== 大转盘 ====================

    /** wheelPrizes() —— 可展示奖品（4 列 + ORDER BY，含库存过滤） */
    public static function fetchAvailableWheelPrizes(): array
    {
        return Database::fetchAll(
            "SELECT id, name, prize_type, value FROM wheel_prizes
             WHERE status = 1 AND (total = 0 OR used < total) ORDER BY sort ASC, id ASC"
        );
    }

    /** wheelPrizes() / wheel() —— 今日已用免费次数（COUNT(*) + CURDATE()） */
    public static function countTodayFreeDraws(int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM wheel_records
             WHERE user_id = ? AND free = 1 AND DATE(created_at) = CURDATE()",
            [$userId]
        );
    }

    /** wheel() —— 可抽取奖品（SELECT *，无 ORDER BY，概率抽取依赖全字段） */
    public static function fetchDrawableWheelPrizes(): array
    {
        return Database::fetchAll(
            "SELECT * FROM wheel_prizes WHERE status = 1 AND (total = 0 OR used < total)"
        );
    }

    /** wheel() —— 余额奖励：加余额 + 写钱包流水（在外层事务内执行） */
    private static function creditBalanceAndLog(int $userId, float $yuan, int $prizeId, string $remark): void
    {
        Database::query("UPDATE users SET balance = balance + ? WHERE id = ?", [$yuan, $userId]);
        $after = (float)Database::fetchColumn("SELECT balance FROM users WHERE id = ?", [$userId]);
        Database::insert('wallet_logs', [
            'user_id' => $userId,
            'amount' => $yuan,
            'type' => 'reward',
            'biz_type' => 'wheel',
            'biz_id' => $prizeId,
            'balance_after' => number_format($after, 2, '.', ''),
            'remark' => $remark,
        ]);
    }

    /** wheel() —— VIP 奖励：不降档延长到期时间，返回写库后的到期时间字符串 */
    private static function grantWheelVip(int $userId, int $days, int $level): string
    {
        $cur = Database::fetchOne("SELECT vip_level, vip_expire_at FROM users WHERE id = ?", [$userId]);
        $base = !empty($cur['vip_expire_at']) ? strtotime($cur['vip_expire_at']) : 0;
        $expire = max(time(), $base) + $days * 86400;
        $expireAt = date('Y-m-d H:i:s', $expire);
        Database::update('users', [
            'vip_expire_at' => $expireAt,
            'vip_level' => max((int)($cur['vip_level'] ?? 0), $level),
        ], 'id = ?', [$userId]);
        return $expireAt;
    }

    /**
     * wheel() —— 抽奖发奖 + 库存扣减 + 记录落库完整事务。
     *
     * 概率抽取属于业务编排留在控制器；$hit 是已抽中的奖品行（原样传来），
     * $award 是控制器的初始奖励描述，vip 分支会在此补 expire_at 后整体返回。
     *
     * @param array<string,mixed> $award
     * @return array{award:array<string,mixed>,points:int,free_left:int}
     */
    public static function finishWheelDraw(
        int $userId,
        array $hit,
        bool $isFree,
        int $cost,
        int $freeDaily,
        int $usedFree,
        array $award
    ): array {
        Database::beginTransaction();
        try {
            // 修复3（P2 竞态）：免费次数的权威判定下沉事务内，不信控制器在事务外算的 $isFree 快照。
            // 控制器 countTodayFreeDraws 发生在事务外，并发下两个请求会同时拿到"还有免费次数"
            // 的旧值；这里在发奖前按当天 free=1 记录数重判，额度已满则强制本次转付费。
            // wheel_records.free 列已确认存在（见 schema），DATE(created_at)=? 用 PHP 当天日期入参，
            // SQLite 内置 DATE() 与 MySQL DATE() 语义一致，测试与生产同口径。
            if ($isFree && $freeDaily > 0) {
                $freeUsedNow = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM wheel_records WHERE user_id = ? AND free = 1 AND DATE(created_at) = ?",
                    [$userId, date('Y-m-d')]
                );
                $usedFree = $freeUsedNow; // 用事务内权威值刷新，free_left 口径同步
                if ($freeUsedNow >= $freeDaily) {
                    $isFree = false; // 免费额度已被并发占满，照常扣积分
                }
            }

            if (!$isFree) {
                self::addPoints($userId, -$cost, 'spend', 'wheel', 0, "大转盘抽奖（消耗 {$cost} 积分）");
            }

            // 原子抢库存：抢在发奖之前，条件带 status 与剩余额度。
            // rowCount!==1 直接抛异常回滚整单（扣费也一并回滚），绝不超发。
            $claim = Database::query(
                "UPDATE wheel_prizes SET used = used + 1
                 WHERE id = ? AND status = 1 AND (total = 0 OR used < total)",
                [(int)$hit['id']]
            );
            if ($claim->rowCount() !== 1) {
                throw new \RuntimeException('奖品库存不足');
            }

            $prizeType = (string)$hit['prize_type'];
            if ($prizeType === 'points') {
                self::addPoints($userId, (int)$hit['value'], 'reward', 'wheel', (int)$hit['id'], '大转盘中奖');
            } elseif ($prizeType === 'balance') {
                self::creditBalanceAndLog(
                    $userId,
                    round((int)$hit['value'] / 100, 2),
                    (int)$hit['id'],
                    '大转盘中奖：' . $hit['name']
                );
            } elseif ($prizeType === 'vip') {
                $days = (int)$hit['value'];
                $level = $days >= 365 ? 3 : ($days >= 90 ? 2 : 1);
                $award['expire_at'] = self::grantWheelVip($userId, $days, $level);
            }

            // 库存已在发奖前由条件 UPDATE 原子 +1；$hit['used'] 是抽取时的陈旧快照，不得再写回
            Database::insert('wheel_records', [
                'user_id' => $userId,
                'prize_id' => (int)$hit['id'],
                'prize_type' => (string)$hit['prize_type'],
                'value' => (int)$hit['value'],
                'free' => $isFree ? 1 : 0,
                'created_at' => Helper::now(),
            ]);

            Database::commit();

            $points = (int)self::fetchPointsAfterById($userId);
            $freeLeft = $isFree ? max(0, $freeDaily - $usedFree - 1) : $freeDaily - $usedFree;
            return ['award' => $award, 'points' => $points, 'free_left' => $freeLeft];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** wheelRecords() —— 我的抽奖记录（JOIN + 字段清单 + LIMIT ? 逐字保留） */
    public static function fetchWheelRecords(int $userId, int $limit): array
    {
        return Database::fetchAll(
            "SELECT r.id, r.prize_id, r.prize_type, r.value, r.free, r.created_at, p.name
             FROM wheel_records r LEFT JOIN wheel_prizes p ON p.id = r.prize_id
             WHERE r.user_id = ? ORDER BY r.id DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    // ==================== 后台积分管理（AdminPointsController，新增段落，既有方法零改动） ====================
    //
    // 【相似 SQL 刻意不合并】
    //  - fetchAllAdminTasks() 无 WHERE；既有 fetchActiveTasks() 带 `WHERE status = 1`，口径不同；
    //  - findTaskIdRow() 只取 id 且无 LIMIT；既有 findTaskById() 是 SELECT * + LIMIT 1；
    //  - updateAdminTask() / insertAdminTask() 字段顺序不同（前者 sort 在 status 前，
    //    后者 status 在 sort 前），因此各建独立方法，绝不互相复用；
    //  - fetchFeaturedPosts() 是前台精选位（slot + NOW() 时间窗 + LIMIT ?），
    //    与 AdminRepository::fetchFeaturedList() 的后台列表口径不同。

    /**
     * pointsLogs() —— 后台流水筛选 WHERE 助手，列表与计数共用。
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function adminLogsWhere(string $type, string $keyword): array
    {
        $where = 'WHERE 1 = 1';
        $params = [];
        if ($type !== '') {
            $where .= ' AND pl.type = ?';
            $params[] = $type;
        }
        if ($keyword !== '') {
            $where .= ' AND (u.username LIKE ? OR u.nickname LIKE ? OR pl.remark LIKE ?)';
            $like = "%{$keyword}%";
            $params = array_merge($params, [$like, $like, $like]);
        }
        return [$where, $params];
    }

    /** pointsLogs() —— 后台积分流水列表（字段/别名/JOIN/排序/分页逐字保留） */
    public static function fetchAdminLogs(string $type, string $keyword, int $offset, int $limit): array
    {
        [$where, $params] = self::adminLogsWhere($type, $keyword);
        return Database::fetchAll(
            "SELECT pl.id, pl.user_id, pl.points, pl.type, pl.biz_type, pl.biz_id,
                    pl.balance_after, pl.remark, pl.created_at,
                    u.username, u.nickname
             FROM points_logs pl
             LEFT JOIN users u ON pl.user_id = u.id
             {$where}
             ORDER BY pl.id DESC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** pointsLogs() —— 与列表共用 WHERE 的分页计数 */
    public static function countAdminLogs(string $type, string $keyword): int
    {
        [$where, $params] = self::adminLogsWhere($type, $keyword);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM points_logs pl LEFT JOIN users u ON pl.user_id = u.id {$where}",
            $params
        );
    }

    /** pointsAdjust() —— 调整前用户行（id, points，无 LIMIT） */
    public static function findAdminAdjustUser(int $userId): ?array
    {
        return Database::fetchOne("SELECT id, points FROM users WHERE id = ?", [$userId]);
    }

    /** pointTasks() —— 后台任务全量列表（含停用任务，无 WHERE） */
    public static function fetchAllAdminTasks(): array
    {
        return Database::fetchAll(
            "SELECT id, title, `desc`, points, task_type, target, period, status, sort
             FROM point_tasks ORDER BY sort ASC, id ASC"
        );
    }

    /** pointTaskSave() —— 修改前任务存在性探针（只取 id，无 LIMIT） */
    public static function findTaskIdRow(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::fetchOne("SELECT id FROM point_tasks WHERE id = ?", [$id]);
    }

    /**
     * pointTaskSave() —— 修改任务（8 列，字段顺序 sort → status 与 HEAD 一致）。
     */
    public static function updateAdminTask(
        int $id,
        string $title,
        string $desc,
        int $points,
        string $type,
        int $target,
        string $period,
        int $sort,
        int $status
    ): void {
        if ($id <= 0) {
            throw new \InvalidArgumentException('任务不存在');
        }
        Database::update('point_tasks', [
            'title' => $title,
            'desc' => $desc,
            'points' => $points,
            'task_type' => $type,
            'target' => $target,
            'period' => $period,
            'sort' => $sort,
            'status' => $status,
        ], 'id = ?', [$id]);
    }

    /** pointTaskSave() —— 新建任务（8 列，字段顺序 status → sort 与 HEAD 一致） */
    public static function insertAdminTask(
        string $title,
        string $desc,
        int $points,
        string $type,
        int $target,
        string $period,
        int $status,
        int $sort
    ): int {
        return Database::insert('point_tasks', [
            'title' => $title,
            'desc' => $desc,
            'points' => $points,
            'task_type' => $type,
            'target' => $target,
            'period' => $period,
            'status' => $status,
            'sort' => $sort,
        ]);
    }

    /**
     * pointTaskDelete() —— 先清任务日志再删任务（两条独立写，HEAD 无事务，保持不加事务）。
     */
    public static function deleteAdminTask(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        Database::delete('user_task_logs', 'task_id = ?', [$id]);
        Database::delete('point_tasks', 'id = ?', [$id]);
    }

    /**
     * featuredPosts() —— 公开精选位取数。
     *
     * NOW() 为 SQL 内建时间函数（非请求值）；slot 与 limit 走 ? 绑定。
     * p.post_type 在 HEAD 原文中已存在，逐字保留。
     * 公开接口只出 public 帖：复用权威可见性策略 VisibilityPolicy::where(0)。
     */
    public static function fetchFeaturedPosts(string $slot, int $limit): array
    {
        [$visWhere, $visParams] = VisibilityPolicy::where(0);
        return Database::fetchAll(
            "SELECT f.post_id, f.sort_order, f.remark,
    p.id, p.content, p.images, p.post_type, p.like_count, p.comment_count, p.created_at,
    u.username, u.nickname, u.avatar
 FROM featured_posts f
 INNER JOIN posts p ON p.id = f.post_id AND p.status = 1
 INNER JOIN users u ON u.id = p.user_id
 WHERE f.slot = ? AND (f.start_at IS NULL OR f.start_at <= NOW())
   AND (f.end_at IS NULL OR f.end_at >= NOW())
   AND {$visWhere}
 ORDER BY f.sort_order ASC, f.id ASC LIMIT ?",
            array_merge([$slot], $visParams, [$limit])
        );
    }
}
