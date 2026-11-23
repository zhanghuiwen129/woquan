<?php
/**
 * Hublog 徽章系统控制器（V82）
 *
 * 用户端：GET /api/badges/my —— 我的徽章（已解锁 + 进度中的未解锁徽章）
 * 管理端：/api/admin/badges* —— 徽章配置 CRUD / 用户徽章授予冻结回收 / 操作日志 / 手动扫描
 * 定时任务：POST /api/cron/badges —— 外部 cron 每小时调用，全站扫描自动解锁/冻结
 */

namespace Hublog\Controllers;

use Hublog\Core\Auth;
use Hublog\Core\BadgeService;
use Hublog\Core\BaseController;
use Hublog\Core\CronAuth;
use Hublog\Core\BadgeRepository;
use Hublog\Core\Helper;
use Hublog\Core\Request;
use Hublog\Core\Response;

class BadgeController extends BaseController
{
    // ==================================================================
    // 一、用户端
    // ==================================================================

    /**
     * GET /api/badges/my —— 我的徽章
     *
     * 返回：
     *   unlocked：已解锁徽章（按 sort_weight 倒序，前端最多展示3枚+折叠）
     *   locked  ：未解锁的统计类徽章及当前进度（current/need/percent）
     */
    public function my(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];

        $unlocked = BadgeService::getUserBadges($userId);

        // 未解锁徽章的进度（count 类；realtime/timed/manual 不展示进度）
        $locked = [];
        try {
            $stats = BadgeService::computeStats([$userId])[$userId] ?? [];
            $unlockedKeys = array_column($unlocked, 'badge_key');
            $rows = BadgeRepository::fetchCountRuleBadges();
            foreach ($rows as $row) {
                if (in_array($row['badge_key'], $unlockedKeys, true)) {
                    continue;
                }
                $threshold = json_decode((string)$row['threshold'], true);
                if (!is_array($threshold)) {
                    continue;
                }
                // 取第一个可映射到统计字段的数值键作为主进度指标
                // （兼容 min_* 与 streak/days 两类阈值键）
                $statKey = null;
                $need = 0;
                foreach ($threshold as $k => $v) {
                    if (!is_numeric($v)) {
                        continue;
                    }
                    $candidate = self::thresholdToStatKey((string)$k);
                    if ($candidate !== null) {
                        $statKey = $candidate;
                        $need = (int)$v;
                        break;
                    }
                }
                if ($statKey === null) {
                    continue;
                }
                $current = (int)($stats[$statKey] ?? 0);
                $locked[] = [
                    'badge_key'   => $row['badge_key'],
                    'name'        => $row['name'],
                    'desc'        => $row['desc'],
                    'icon'        => $row['icon'],
                    'color'       => $row['color'],
                    'sort_weight' => (int)$row['sort_weight'],
                    'progress'    => [
                        'stat_key' => $statKey,
                        'current'  => $current,
                        'need'     => $need,
                        'percent'  => $need > 0 ? min(100, (int)floor($current * 100 / $need)) : 0,
                    ],
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Hublog BadgeController] my 进度计算失败: ' . $e->getMessage()); // @allow-error-log
        }

        return Response::success([
            'unlocked' => $unlocked,
            'locked'   => $locked,
        ]);
    }

    // ==================================================================
    // 二、管理端：徽章配置
    // ==================================================================

    /**
     * GET /api/admin/badges —— 徽章配置列表（含持有人数）
     */
    public function adminList(Request $request): Response
    {
        $this->requireAdmin($request);

        $category = $request->getString('category');
        $status = $request->getInt('status', -1);
        $keyword = $request->getString('keyword');

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 100);

        $list = BadgeRepository::fetchAdminBadgePage($category, $status, $keyword, $offset, $limit);
        $total = BadgeRepository::countAdminBadges($category, $status, $keyword);

        return Response::success(Helper::paginatedResponse($list, $page, $limit, $total));
    }

    /**
     * POST/PUT /api/admin/badge-save —— 新增/编辑徽章配置
     *
     * 已存在用户持有记录的徽章不允许删除（前端不提供删除入口），仅可禁用。
     */
    public function adminSave(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        $id = $request->getInt('id');
        $badgeKey = trim($request->getString('badge_key'));
        $name = trim($request->getString('name'));
        $desc = trim($request->getString('desc'));
        $category = $request->getString('category', 'creation');
        $icon = $request->getString('icon', 'award');
        $color = $request->getString('color', '#3b8cfa');
        $ruleType = $request->getString('rule_type', 'count');
        $sortWeight = $request->getInt('sort_weight', 0);
        $hideable = $request->getBool('hideable') ? 1 : 0;
        $status = $request->getInt('status', 1);
        // V83：展示位（1=名字旁内联展示，0=仅个人主页横排）
        $display = $request->getInt('display', 0) ? 1 : 0;

        if ($name === '') {
            return Response::error('徽章名称不能为空', 400);
        }
        if (!in_array($ruleType, ['count', 'realtime', 'timed', 'manual'], true)) {
            return Response::error('无效的规则类型', 400);
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,49}$/', $badgeKey)) {
            return Response::error('徽章标识只能包含小写字母/数字/下划线，并以字母开头', 400);
        }

        // 阈值 JSON 校验（manual 类允许为空；前端可能传字符串也可能传已解析的对象）
        $thresholdRaw = $request->get('threshold', '');
        if (is_array($thresholdRaw)) {
            $thresholdRaw = json_encode($thresholdRaw, JSON_UNESCAPED_UNICODE);
        } else {
            $thresholdRaw = (string)$thresholdRaw;
        }
        $threshold = null;
        if ($thresholdRaw !== '' && $thresholdRaw !== 'null') {
            $decoded = json_decode($thresholdRaw, true);
            if (!is_array($decoded)) {
                return Response::error('阈值不是合法的 JSON 对象', 400);
            }
            $threshold = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        $exists = null;
        if ($id > 0) {
            $exists = BadgeRepository::findBadgeIdById($id);
            if (!$exists) {
                return Response::error('徽章不存在', 404);
            }
        } else {
            $dup = BadgeRepository::findBadgeIdByKey($badgeKey);
            if ($dup) {
                return Response::error('徽章标识已被占用', 400);
            }
        }

        $data = [
            'badge_key'   => $badgeKey,
            'name'        => $name,
            'desc'        => $desc,
            'category'    => $category,
            'icon'        => $icon,
            'color'       => $color,
            'rule_type'   => $ruleType,
            'threshold'   => $threshold,
            'sort_weight' => $sortWeight,
            'hideable'    => $hideable,
            'display'     => $display,
            'status'      => $status ? 1 : 0,
            'updated_at'  => Helper::now(),
        ];

        if ($exists) {
            BadgeRepository::updateBadgeConfig($id, $data);
            $this->writeConfigLog($admin, $id, 'config_update', '编辑徽章配置：' . $name, $request);
        } else {
            $data['created_at'] = Helper::now();
            $id = BadgeRepository::insertBadgeConfig($data);
            $this->writeConfigLog($admin, $id, 'config_create', '新增徽章配置：' . $name, $request);
        }

        BadgeService::clearCache();
        return Response::success(['id' => $id], '保存成功');
    }

    /**
     * POST /api/admin/badge-toggle —— 启用/禁用徽章
     * 禁用只停止自动发放，已获取用户的徽章保留展示（PRD 第12章第5条）。
     */
    public function adminToggle(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $id = $request->getInt('id');
        $status = $request->getInt('status', 1) ? 1 : 0;

        $badge = BadgeRepository::findBadgeBriefById($id);
        if (!$badge) {
            return Response::error('徽章不存在', 404);
        }
        BadgeRepository::setBadgeStatus($id, $status, Helper::now());
        $this->writeConfigLog(
            $admin,
            $id,
            'config_toggle',
            ($status ? '启用' : '禁用') . '徽章：' . $badge['name'],
            $request
        );
        BadgeService::clearCache();
        return Response::success(null, $status ? '已启用' : '已禁用');
    }

    // ==================================================================
    // 三、管理端：用户徽章管理
    // ==================================================================

    /**
     * GET /api/admin/user-badges —— 用户持有徽章查询（筛选 + 分页）
     */
    public function adminUserBadges(Request $request): Response
    {
        $this->requireAdmin($request);

        $userId = $request->getInt('user_id');
        $keyword = $request->getString('keyword');
        $badgeId = $request->getInt('badge_id');
        $state = $request->getInt('state', -1);
        $grantType = $request->getString('grant_type');

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20);

        $list = BadgeRepository::fetchUserBadgePage(
            $userId,
            $keyword,
            $badgeId,
            $state,
            $grantType,
            $offset,
            $limit
        );
        $total = BadgeRepository::countUserBadges($userId, $keyword, $badgeId, $state, $grantType);

        // 供授予弹窗使用的徽章下拉（仅启用中的）
        $badges = BadgeRepository::fetchActiveBadgeOptions();

        return Response::success([
            'list'       => $list,
            'badges'     => $badges,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ]);
    }

    /**
     * POST /api/admin/user-badge-grant —— 人工授予徽章（备注必填）
     */
    public function adminGrant(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $userId = $request->getInt('user_id');
        $badgeKey = trim($request->getString('badge_key'));
        $remark = trim($request->getString('remark'));

        if ($userId <= 0 || $badgeKey === '') {
            return Response::error('参数不完整', 400);
        }
        if ($remark === '') {
            return Response::error('必须填写操作备注', 400);
        }

        $result = BadgeService::grant($userId, $badgeKey, (int)$admin['id'], $remark, $request->getIp());
        if (!$result['ok']) {
            return Response::error($result['message'], 400);
        }
        return Response::success(null, $result['message']);
    }

    /**
     * POST /api/admin/user-badge-freeze —— 人工冻结徽章（备注必填）
     */
    public function adminFreeze(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $userId = $request->getInt('user_id');
        $badgeKey = trim($request->getString('badge_key'));
        $remark = trim($request->getString('remark'));

        if ($userId <= 0 || $badgeKey === '') {
            return Response::error('参数不完整', 400);
        }
        if ($remark === '') {
            return Response::error('必须填写操作备注', 400);
        }

        $result = BadgeService::freeze($userId, $badgeKey, (int)$admin['id'], $remark, $request->getIp());
        if (!$result['ok']) {
            return Response::error($result['message'], 400);
        }
        return Response::success(null, $result['message']);
    }

    /**
     * POST /api/admin/user-badge-revoke —— 人工永久回收徽章（备注必填，不可自动恢复）
     */
    public function adminRevoke(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $userId = $request->getInt('user_id');
        $badgeKey = trim($request->getString('badge_key'));
        $remark = trim($request->getString('remark'));

        if ($userId <= 0 || $badgeKey === '') {
            return Response::error('参数不完整', 400);
        }
        if ($remark === '') {
            return Response::error('必须填写操作备注', 400);
        }

        $result = BadgeService::revoke($userId, $badgeKey, (int)$admin['id'], $remark, $request->getIp());
        if (!$result['ok']) {
            return Response::error($result['message'], 400);
        }
        return Response::success(null, $result['message']);
    }

    // ==================================================================
    // 四、管理端：操作日志（只读，不提供删除）
    // ==================================================================

    /**
     * GET /api/admin/badge-logs —— 徽章操作日志（筛选 + 分页）
     */
    public function adminLogs(Request $request): Response
    {
        $this->requireAdmin($request);

        $action = $request->getString('action');
        $userId = $request->getInt('user_id');
        $badgeId = $request->getInt('badge_id');
        $dateFrom = $request->getString('date_from');
        $dateTo = $request->getString('date_to');

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20);

        $list = BadgeRepository::fetchBadgeLogPage(
            $action,
            $userId,
            $badgeId,
            $dateFrom,
            $dateTo,
            $offset,
            $limit
        );
        $total = BadgeRepository::countBadgeLogs($action, $userId, $badgeId, $dateFrom, $dateTo);

        return Response::success(Helper::paginatedResponse($list, $page, $limit, $total));
    }

    // ==================================================================
    // 五、管理端：手动触发全站扫描
    // ==================================================================

    /**
     * POST /api/admin/badge-scan —— 手动立即执行一次全站徽章扫描
     * （日常由外部 cron 打 /api/cron/badges，这里是后台补救入口）
     */
    public function adminScan(Request $request): Response
    {
        $this->requireAdmin($request);
        try {
            $result = BadgeService::runScan();
            return Response::success($result, '扫描完成');
        } catch (\Throwable $e) {
            error_log('[Hublog BadgeController] adminScan 失败: ' . $e->getMessage()); // @allow-error-log
            return Response::error('扫描执行失败', 500);
        }
    }

    // ==================================================================
    // 5.5 神评标记管理（comment_marks）
    // ==================================================================

    /**
     * GET /api/admin/badges/god-marks?page&limit&keyword
     *
     * 神评标记台账：谁把哪条评论标成了神评、评论作者是谁、评论内容摘要。
     * 供后台抽查/纠删——标记是用户行为，运营侧需要能撤销误标。
     */
    public function adminGodMarks(Request $request): Response
    {
        $this->requireAdmin($request);
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 100);

        $keyword = trim($request->getString('keyword'));

        $total = BadgeRepository::countGodMarks($keyword);
        $rows = BadgeRepository::fetchGodMarks($keyword, $offset, $limit);

        $list = array_map(static function (array $r): array {
            return [
                'id'               => (int)$r['id'],
                'comment_id'       => (int)$r['comment_id'],
                'mark_type'        => $r['mark_type'],
                'operator_id'      => (int)$r['operator_id'],
                'operator_name'    => $r['operator_name'] ?? '（已注销）',
                'created_at'       => $r['created_at'],
                'time_ago'         => Helper::timeAgo($r['created_at']),
                'comment_user_id'  => (int)$r['comment_user_id'],
                'comment_author'   => $r['comment_author'] ?? '未知用户',
                'comment_avatar'   => $r['comment_avatar'],
                'comment_excerpt'  => mb_substr((string)$r['content'], 0, 60),
                'post_id'          => (int)$r['post_id'],
                'post_author'      => $r['post_author'] ?? '未知用户',
                'post_excerpt'     => mb_substr((string)$r['post_content'], 0, 40),
            ];
        }, $rows);

        return Response::success(Helper::paginatedResponse($list, $page, $limit, $total));
    }

    /**
     * DELETE /api/admin/badges/god-marks  {id} 或 {comment_id}
     *
     * 撤销一条神评标记。删完立刻对被标评论的作者重算徽章——
     * 否则用户靠 3 次神评拿到的「新晋神评选手」不会因为误标被撤而收回。
     */
    public function adminDeleteGodMark(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $id = $request->getInt('id');
        $commentId = $request->getInt('comment_id');
        if (!$id && !$commentId) {
            return Response::error('参数错误：需要 id 或 comment_id', 400);
        }

        $mark = $id
            ? BadgeRepository::findGodMarkForDeleteById($id)
            : BadgeRepository::findGodMarkForDeleteByComment($commentId);
        if (!$mark) {
            return Response::error('标记不存在或已删除', 404);
        }

        $deleted = BadgeRepository::deleteGodMark((int)$mark['id']);
        if ($deleted <= 0) {
            return Response::error('删除失败', 500);
        }

        // 撤标后重算该作者的神评类徽章（低于阈值会被冻结）
        try {
            BadgeService::evaluateUser((int)$mark['user_id']);
        } catch (\Throwable $e) {
            error_log('[Hublog BadgeController] 撤标后重算徽章失败: ' . $e->getMessage()); // @allow-error-log
        }

        // 记操作日志（与徽章配置变更同一本台账，badge_id=0 表示非徽章本体操作）
        $this->writeConfigLog(
            $admin,
            0,
            'admin_god_unmark',
            "撤销神评标记 comment_id={$mark['comment_id']}",
            $request
        );

        return Response::success(null, '已撤销该神评标记');
    }

    // ==================================================================
    // 六、定时任务入口
    // ==================================================================

    /**
     * POST /api/cron/badges {secret} 或请求头 X-Cron-Token
     *
     * 鉴权方式与 /api/cron/scheduled 一致：由 CronAuth::verify() 统一校验
     * （secret 的取值优先级与「未配置即关闭入口」的语义见 api/core/CronAuth.php）。
     * 建议外部 cron 每小时调用一次：统计类徽章解锁/冻结、限时徽章到期。
     */
    public function cron(Request $request): Response
    {
        $secret = (string)$request->getString('secret');
        if ($secret === '') {
            $secret = (string)($request->getHeader('X-Cron-Token') ?? '');
        }

        if (!CronAuth::verify($secret)) {
            return Response::error('cron token 无效或未配置', 403);
        }

        // 站点模式：徽章模块关闭时跳过扫描，外部 cron 照常收到 200，不报警也不报错。
        // 历史徽章数据不清理，随时切回即可恢复。
        if (!\Hublog\Core\FeatureFlags::enabled('badges')) {
            error_log('[Hublog cronBadges] skipped: module_disabled'); // @allow-error-log
            return Response::success(['skipped' => true, 'reason' => 'module_disabled']);
        }

        try {
            $result = BadgeService::runScan();
            error_log('[Hublog cronBadges] ' . json_encode($result, JSON_UNESCAPED_UNICODE)); // @allow-error-log
            return Response::success($result);
        } catch (\Throwable $e) {
            error_log('[Hublog cronBadges] ' . $e->getMessage()); // @allow-error-log
            return Response::error('徽章扫描执行失败', 500);
        }
    }

    // ==================================================================
    // 内部工具
    // ==================================================================

    /**
     * 徽章配置变更日志（写入 badge_logs，action = config_*）。
     */
    private function writeConfigLog(array $admin, int $badgeId, string $action, string $remark, Request $request): void
    {
        try {
            BadgeRepository::insertConfigLog([
                'operator_id'   => (int)($admin['id'] ?? 0) ?: null,
                'operator_name' => $admin['nickname'] ?? null,
                'user_id'       => 0,
                'badge_id'      => $badgeId,
                'action'        => $action,
                'remark'        => $remark,
                'ip'            => $request->getIp(),
                'created_at'    => Helper::now(),
            ]);
        } catch (\Throwable $e) {
            error_log('[Hublog BadgeController] 配置日志写入失败: ' . $e->getMessage()); // @allow-error-log
        }
    }

    /**
     * 阈值 key → 统计字段名（与 BadgeService::STAT_MAP 保持一致）。
     * 例：min_posts → posts；min_comment_likes 是参数键不映射，返回 null。
     */
    private static function thresholdToStatKey(string $key): ?string
    {
        $map = [
            'min_posts'         => 'posts',
            'min_image_posts'   => 'image_posts',
            'min_video_posts'   => 'video_posts',
            'min_featured'      => 'featured',
            'min_views'         => 'max_views',
            'min_comments'      => 'comments',
            'min_replies'       => 'replies',
            'min_accepts'       => 'accepts',
            'min_long_comments' => 'long_comments',
            'min_sofa'          => 'sofa',
            'min_hot_comments'  => 'hot_comments',
            'min_likes_given'   => 'likes_given',
            'min_favorites'     => 'favorites',
            'min_following'     => 'following',
            'min_activities'    => 'activities',
            'min_topic_posts'   => 'topic_posts',
            'streak'            => 'streak',
            'days'              => 'reg_days',
        ];
        return $map[$key] ?? null;
    }
}
