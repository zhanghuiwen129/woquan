<?php
declare(strict_types=1);

/**
 * Hublog 积分中心
 *
 * 定位（V68）：积分是站内**主要流通货币** —— 签到 / 任务白给，用来抵扣会员与付费圈子。
 *   签到   每月第 n 天奖励 2n 分（1号 2 分 → 31号 62 分）。
 *          当天重复签直接忽略；**漏签那天奖励作废**，连击 streak 从 1 重新开始。
 *   任务   point_tasks 后台可配，达标后在 App 手动点「领取」—— 不埋点自动发，
 *          避免用户发了动态却因为异常路径漏加分，也方便后台随时调奖励。
 *   消费   buyVip / circleSubscribe 支持 use_points 抵扣，汇率 settings.point_rate
 *          默认 10 积分 = ¥1，抵扣上限 50%（防纯积分白嫖）。
 *   余额   users.points 冗余，points_logs 流水双写（与 V67 钱包同一套写法）。
 *
 * 路由分布（全部需登录）：
 *   GET  /points/home     积分中心总览（余额 / 月历 / 任务进度）
 *   POST /points/signin   每日签到
 *   GET  /points/logs     积分流水
 *   POST /points/claim    领取任务奖励
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\PointRepository;
use Hublog\Core\Auth;
use Hublog\Core\Helper;
use Hublog\Core\BadgeService;

class PointController extends BaseController
{
    /** 签到基数：第 n 天 = 基数 × n（2/4/6…62） */
    private const SIGNIN_BASE = 2;

    /** 每元需要多少积分（默认值，后台 point_rate 可改） */
    private const DEFAULT_POINT_RATE = 10;

    /** 单笔订单最多能用积分抵扣的比例 */
    private const POINT_MAX_RATIO = 0.5;

    /** V97：points_logs.type 的白名单（流水分类筛选只认这几个，避免拼出奇怪条件） */
    private const POINT_LOG_TYPES = [
        'signin', 'task', 'spend', 'reward', 'adjust', 'refund', 'post', 'comment'
    ];

    /** 内置任务类型白名单：计数器 SQL 已下沉到 PointRepository::countTaskProgress() */
    private const TASK_TYPES = [
        'profile', 'post', 'comment', 'like', 'circle_join', 'follow', 'signin_days'
    ];

    // ==================== 积分中心总览 ====================

    /**
     * GET /api/points/home —— 余额 + 本月月历 + 任务进度
     *
     * query: { month: 2026-10 }（不传取当月）
     */
    public function home(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        $month = trim((string)$request->getString('month', ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }

        $row = PointRepository::fetchPointsRowById($userId);
        $points = (int)($row['points'] ?? 0);

        [$calendar, $monthSigned, $monthPoints] = $this->buildCalendar($userId, $month);
        $tasks = $this->taskList($userId);
        $signin = $this->todaySignin($userId, $month);

        return Response::success([
            'points' => $points,
            'rate' => (int)$this->setting('point_rate', self::DEFAULT_POINT_RATE),
            'today' => date('Y-m-d'),
            'today_signed' => $signin['today_signed'],
            'today_points' => $signin['today_points'],
            'streak' => $signin['streak'],
            'month' => $month,
            'month_signed' => $monthSigned,
            'month_points' => $monthPoints,
            'calendar' => $calendar,
            'tasks' => $tasks,
            'can_signin' => !$signin['today_signed'],
        ], '获取成功');
    }

    // ==================== 签到 ====================

    /**
     * POST /api/points/signin —— 每日签到
     *
     * 规则：第 n 天奖励 2n 分；当天已签直接返回（不重复加分）；
     *       昨天没签 = 漏签，连击重置为 1，当天仍按日号给基础分。
     */
    public function signin(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];

        $today = date('Y-m-d');
        $day = (int)date('j');

        $already = PointRepository::findTodaySignin($userId, $today);
        if ($already) {
            return Response::success([
                'already' => true,
                'points' => (int)$already['points'],
                'total' => (int)(self::currentPoints($userId)),
                'streak' => $this->currentStreak($userId),
                'month_signed' => $this->monthSignedCount($userId),
            ], '今天已经签到过了，明天再来');
        }

        $reward = $day * self::SIGNIN_BASE;

        // 连击：昨天签过就接着数，漏签（昨天没签）就从头开始
        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
        $prev = PointRepository::findYesterdayStreak($userId, $yesterday);
        $streak = $prev ? min((int)$prev['streak'] + 1, $day) : 1;

        $total = $points = 0;
        try {
            // 事务（签到入库 + 积分双写）已完整下沉；仓储异常时已自行 rollBack
            $total = PointRepository::insertSigninAndAddPoints(
                $userId,
                $reward,
                $streak,
                $day,
                $today,
                date('Y-m')
            );
            $points = $reward;
        } catch (\Throwable $e) {
            error_log('[Hublog signin] ' . $e->getMessage()); // @allow-error-log
            return Response::error('签到失败，请重试', 500);
        }

        // V83：连续签到天数变化 → 重算「连续打卡7/30/100天」等徽章（每日一次，不节流）
        BadgeService::evaluateOnAction($userId, 0);

        return Response::success([
            'already' => false,
            'points' => $points,
            'total' => $total,
            'streak' => $streak,
            'month_signed' => $this->monthSignedCount($userId),
        ], "签到成功，+{$points} 积分（连续 {$streak} 天）");
    }

    // ==================== 流水 ====================

    /**
     * GET /api/points/logs —— 积分流水
     */
    public function logs(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];

        $page = max(1, $request->getInt('page', 1));
        $limit = min(50, max(1, $request->getInt('limit', 20)));
        $offset = ($page - 1) * $limit;

        // V97：钱包页的积分流水要做「收入/支出 + 分类」筛选，跟钱包流水保持同一套参数
        $direction = strtolower(trim($request->getString('direction', 'all')));
        if (!in_array($direction, ['all', 'income', 'expense'], true)) {
            $direction = 'all';
        }
        $type = trim($request->getString('type', ''));

        // WHERE 组装在仓储内共用同一助手，列表与计数不会错位
        $logs = PointRepository::fetchLogs($userId, $direction, $type, $limit, $offset);
        $total = PointRepository::countLogs($userId, $direction, $type);

        // 全量积分收支统计（不受上面筛选影响），给钱包页顶上那张统计条用
        $sum = PointRepository::summarizeLogs($userId);

        $payload = Helper::paginatedResponse($logs, $page, $limit, $total);
        $payload['summary'] = [
            'earned' => (int)($sum['earned'] ?? 0),
            'spent' => (int)($sum['spent'] ?? 0),
            'count' => (int)($sum['cnt'] ?? 0),
        ];
        $payload['filter'] = ['direction' => $direction, 'type' => $type];

        return Response::success($payload, '获取成功');
    }

    // ==================== 任务 ====================

    /**
     * GET /api/points/tasks —— 只要任务列表（签到页省一次 home 请求时用）
     */
    public function tasks(Request $request): Response
    {
        $user = $this->requireUser($request);
        return Response::success($this->taskList((int)$user['id']), '获取成功');
    }

    /**
     * POST /api/points/claim —— 领取任务奖励
     *
     * body: { task_id }
     */
    public function claim(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];

        $taskId = $request->getInt('task_id');
        if ($taskId <= 0) {
            return Response::error('参数错误', 400);
        }

        $task = PointRepository::findTaskById($taskId);
        if (!$task) {
            return Response::error('任务不存在', 404);
        }
        if ((int)$task['status'] !== 1) {
            return Response::error('该任务已下线', 400);
        }

        $today = date('Y-m-d');

        // daily 周期：同一天重复领直接拒绝（进度表按天重置）
        $log = PointRepository::findUserTaskLog($userId, $taskId);
        if ($log && (int)$log['claimed'] === 1) {
            $changed = ($log['last_date'] === $today) || ((string)$task['period'] === 'once');
            if ($changed) {
                return Response::error('今天已经领取过了，明天再来', 400);
            }
        }

        $progress = $this->taskProgress($userId, $task);
        if ($progress < (int)$task['target']) {
            return Response::error(
                "还差 " . ((int)$task['target'] - $progress) . " 次才能领取",
                400
            );
        }

        $reward = (int)$task['points'];
        if ($reward <= 0) {
            return Response::error('该任务没有积分奖励', 400);
        }

        try {
            // upsert 幂等（防重复刷分）+ 发积分的事务已完整下沉；异常时仓储自行 rollBack
            $after = PointRepository::claimTaskAndAddPoints(
                $userId,
                $taskId,
                $progress,
                $reward,
                $today,
                (string)$task['title'],
                (string)$task['period']
            );
        } catch (\Throwable $e) {
            error_log('[Hublog claim] ' . $e->getMessage()); // @allow-error-log
            return Response::error('领取失败，请重试', 500);
        }

        return Response::success([
            'points' => $reward,
            'total' => $after,
        ], "+" . $reward . " 积分已到账");
    }

    // ==================== 内部：月历 ====================

    /**
     * 生成本月月历：{ year, month, days: [{day, points, status}], total }
     * status：signed 已签 / today 今天（可签）/ miss 漏签（未签且已过）/ future 未来
     */
    private function buildCalendar(int $userId, string $month): array
    {
        [$year, $m] = array_map('intval', explode('-', $month));
        // 注意：别用 cal_days_in_month() —— 线上 PHP 没装 calendar 扩展，直接 undefined function（踩过）。
        $daysInMonth = (int)date('t', mktime(0, 0, 0, $m, 1, $year));

        $rows = PointRepository::fetchMonthSignins($userId, $month);
        $signedMap = [];
        foreach ($rows as $r) {
            $signedMap[(int)date('j', strtotime($r['sign_date']))] = $r;
        }

        $todayDay = (int)date('j');
        $todayStr = date('Y-m-d');
        $days = [];
        $monthSigned = 0;
        $monthPoints = 0;

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateStr = sprintf('%04d-%02d-%02d', $year, $m, $d);
            $points = $d * self::SIGNIN_BASE;
            if (isset($signedMap[$d])) {
                $status = 'signed';
                $got = (int)$signedMap[$d]['points'];
                $monthSigned++;
                $monthPoints += $got;
            } elseif ($dateStr === $todayStr) {
                $status = 'today';
                $got = 0;
            } elseif ($d > $todayDay) {
                $status = 'future';
                $got = 0;
            } else {
                // 已经过去且没签 = 漏签，当天奖励作废
                $status = 'miss';
                $got = 0;
            }
            $days[] = [
                'day' => $d,
                'date' => $dateStr,
                'points' => $points,
                'got' => $got,
                'status' => $status,
            ];
        }

        return [
            ['year' => $year, 'month' => $m, 'days_in_month' => $daysInMonth, 'days' => $days],
            $monthSigned,
            $monthPoints,
        ];
    }

    // ==================== 内部：任务列表 ====================

    /**
     * 任务列表（带进度 / 是否已领 / 今天能不能领）
     */
    private function taskList(int $userId): array
    {
        $tasks = PointRepository::fetchActiveTasks();

        $today = date('Y-m-d');
        $logs = PointRepository::fetchUserTaskLogs($userId);
        $logMap = [];
        foreach ($logs as $l) {
            $logMap[(int)$l['task_id']] = $l;
        }

        $list = [];
        foreach ($tasks as $t) {
            $id = (int)$t['id'];
            $period = (string)$t['period'];
            $log = $logMap[$id] ?? null;
            $claimed = (int)($log['claimed'] ?? 0) === 1;
            // once 任务过期后还是“已领”；daily 只看今天
            $claimedToday = $claimed && (
                $period === 'once'
                || ($log['last_date'] ?? '') === $today
            );

            $current = $this->taskProgress($userId, $t);
            $target = max(1, (int)$t['target']);

            $list[] = [
                'id' => $id,
                'title' => $t['title'],
                'desc' => $t['desc'],
                'points' => (int)$t['points'],
                'task_type' => $t['task_type'],
                'target' => $target,
                'current' => min($current, $target),
                'done' => $current >= $target,
                'claimed' => $claimedToday,
                'period' => $period,
            ];
        }

        return $list;
    }

    /**
     * 任务完成次数（按 task_type 取数）
     */
    private function taskProgress(int $userId, array $task): int
    {
        $type = (string)$task['task_type'];
        if (!in_array($type, self::TASK_TYPES, true)) {
            return 0;
        }

        // 一次性任务看「历史累计」；每日任务按自然日重算，跨天自动归零
        $period = (string)$task['period'];
        $date = $period === 'daily' ? date('Y-m-d') : null;

        // 计数器 SQL 的值全部走 ? 绑定；仓储对未知类型/异常按 0 处理
        return PointRepository::countTaskProgress($userId, $type, date('Y-m'), $date);
    }

    // ==================== 内部：签到辅助 ====================

    private function todaySignin(int $userId, string $month): array
    {
        $today = date('Y-m-d');
        $row = PointRepository::findTodaySignin($userId, $today);
        return [
            'today_signed' => $row ? true : false,
            'today_points' => $row ? (int)$row['points'] : 0,
            'streak' => $this->currentStreak($userId),
        ];
    }

    /**
     * 当前连击：从今天（或昨天）往回数，中间断一天就停
     */
    private function currentStreak(int $userId): int
    {
        $today = date('Y-m-d');
        $cursor = $today;
        // 今天还没签就从昨天开始数（今天签了 streak 会自己 +1 落到库里）
        $first = PointRepository::findSignDateByDate($userId, $today);
        if (!$first) {
            $cursor = date('Y-m-d', strtotime($today . ' -1 day'));
        }

        $streak = 0;
        for ($i = 0; $i < 31; $i++) {
            $row = PointRepository::findSigninIdByDate($userId, $cursor);
            if (!$row) {
                break;
            }
            $streak++;
            $cursor = date('Y-m-d', strtotime($cursor . ' -1 day'));
        }
        return $streak;
    }

    private function monthSignedCount(int $userId): int
    {
        return PointRepository::countMonthSignins($userId, date('Y-m'));
    }

    /**
     * 查当前积分。
     *
     * PHPStan 提级后揪出：这里原本是实例方法，但静态方法 addPoints() 里用
     * self::currentPoints() 调用它 —— 运行时抛「Non-static method cannot be called statically」
     * 致命错误。加积分时若 delta 恰好为 0（例如任务奖励为 0），整条路径直接 500。
     * 改成静态方法即可同时满足两处调用点。
     */
    private static function currentPoints(int $userId): int
    {
        $row = PointRepository::fetchPointsRowById($userId);
        return (int)($row['points'] ?? 0);
    }

    // ==================== 工具：加积分（双写，供本控制器与 PaymentController 复用） ====================

    /**
     * 给某用户加/扣积分，同时写流水。负数时余额不足会抛异常。
     *
     * @return int 变动后的积分余额
     */
    public static function addPoints(
        int $userId,
        int $delta,
        string $type,
        string $bizType,
        int $bizId,
        string $remark
    ): int {
        // V147：条件原子更新。旧实现是「SELECT points → PHP 相加 → 全量回写」，
        // 并发下（签到/任务/转盘奖励同时到）会丢失更新，甚至把积分扣成负数
        // （$after<0 检查基于陈旧值，形同虚设）。
        // WHERE 带 points + ? >= 0 后，扣成负数的请求直接失败，由调用方回滚。
        if ($delta === 0) {
            return self::currentPoints($userId);
        }
        // 仓储方法不带事务，可在签到/任务/转盘的调用方事务内复用；
        // 「用户不存在」「积分不足」文案与异常类型由仓储原样抛出。
        return PointRepository::addPoints($userId, $delta, $type, $bizType, $bizId, $remark);
    }

    private function setting(string $key, $default)
    {
        $value = PointRepository::fetchSetting($key);
        // fetchColumn 查无行返回 false 不是 null —— 只判 null/'' 会把默认值吞成 0（V67 踩过）
        if ($value === null || $value === '' || $value === false) {
            return $default;
        }
        return $value;
    }

    // ==================== 积分大转盘（V96） ====================

    /**
     * GET /api/points/wheel-prizes —— 转盘奖品配置 + 我的剩余免费次数
     * 公开，但 free_left 仅在登录时返回
     */
    public function wheelPrizes(Request $request): Response
    {
        $prizes = PointRepository::fetchAvailableWheelPrizes();
        $cost = (int)$this->setting('wheel_cost', 20);
        $freeDaily = (int)$this->setting('wheel_free_daily', 1);

        $userId = 0;
        $user = Auth::user($request);
        $freeLeft = $freeDaily;
        if ($user) {
            $userId = (int)$user['id'];
            $used = PointRepository::countTodayFreeDraws($userId);
            $freeLeft = max(0, $freeDaily - $used);
        }
        return Response::success([
            'prizes' => $prizes ?: [],
            'cost' => $cost,
            'free_left' => $freeLeft,
            'free_daily' => $freeDaily,
            'user_id' => $userId,
        ]);
    }

    /**
     * POST /api/points/wheel —— 抽一次（扣积分或扣免费次数）
     */
    public function wheel(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];

        $prizes = PointRepository::fetchDrawableWheelPrizes();
        if (empty($prizes)) {
            return Response::error('奖品暂未配置', 400);
        }

        $cost = (int)$this->setting('wheel_cost', 20);
        $freeDaily = (int)$this->setting('wheel_free_daily', 1);

        // 是否使用免费次数
        $usedFree = PointRepository::countTodayFreeDraws($userId);
        $isFree = $freeDaily > 0 && $usedFree < $freeDaily;
        if (!$isFree) {
            // 扣积分
            $balance = (int)PointRepository::fetchPointsColumnById($userId);
            if ($balance < $cost) {
                return Response::error('积分不足，无法抽奖', 400);
            }
        }

        // 按概率抽取（归一化）
        $totalP = 0.0;
        foreach ($prizes as $p) {
            $totalP += (float)$p['probability'];
        }
        if ($totalP <= 0) {
            return Response::error('奖品概率配置有误', 500);
        }
        // P2-12 加固：mt_rand 可被预测且分布不均，抽奖命中改用密码学安全的 random_int
        $r = random_int(0, 100000) / 100000 * $totalP;
        $acc = 0.0;
        $hit = $prizes[0];
        foreach ($prizes as $p) {
            $acc += (float)$p['probability'];
            if ($r <= $acc) {
                $hit = $p;
                break;
            }
        }

        $award = ['type' => (string)$hit['prize_type'], 'value' => (int)$hit['value']];
        try {
            // 发奖 / 库存 / 记录 / 扣费的事务已完整下沉；异常时仓储自行 rollBack
            $result = PointRepository::finishWheelDraw(
                $userId,
                $hit,
                $isFree,
                $cost,
                $freeDaily,
                $usedFree,
                $award
            );
            $award = $result['award'];
            $points = $result['points'];
            $freeLeft = $result['free_left'];
            return Response::success([
                'prize' => ['id' => (int)$hit['id'], 'name' => $hit['name'], 'prize_type' => (string)$hit['prize_type'], 'value' => (int)$hit['value']],
                'award' => $award,
                'is_free' => $isFree,
                'points' => $points,
                'free_left' => $freeLeft,
            ], '抽奖完成');
        } catch (\Throwable $e) {
            error_log('[Hublog PointController::wheel] ' . $e->getMessage()); // @allow-error-log
            return Response::error('抽奖失败，请重试', 500);
        }
    }

    /**
     * GET /api/points/wheel-records —— 我的抽奖记录
     */
    public function wheelRecords(Request $request): Response
    {
        $user = $this->requireUser($request);
        $limit = $request->getInt('limit', 20);
        if ($limit <= 0 || $limit > 50) {
            $limit = 20;
        }
        $rows = PointRepository::fetchWheelRecords((int)$user['id'], $limit);
        return Response::success($rows ?: [], '获取成功');
    }
}
