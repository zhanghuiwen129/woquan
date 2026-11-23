<?php
/**
 * Hublog 徽章系统核心服务（V82）
 *
 * 四类徽章规则（badges.rule_type）：
 *   realtime —— 账号属性类，事件实时校验（手机/邮箱/实名/资料/VIP/圈子角色/平台管理员）；
 *   count    —— 统计成就类，由定时任务（POST /api/cron/badges）每小时扫描解锁/冻结；
 *   timed    —— 限时类（新人启航），到期自动消失；
 *   manual   —— 人工授予类（活动优胜者/年度优秀用户），仅后台可发。
 *
 * 状态机（user_badges.state）：1=unlock 2=freeze 3=revoke。
 *   - not_reach 不落库，查询时按阈值现算；
 *   - freeze 可由自动任务在条件恢复后还原为 unlock；
 *   - revoke 是永久回收，自动任务永远不会还原（只有人工重新授予）。
 *
 * 防刷口径（PRD 第8章的轻量实现，全部复用现有字段零成本）：
 *   - AI 自动评论不计入（comments.is_ai = 0）；
 *   - 封禁/注销账号不扫描（runScan 只扫 status=1 且 deleted_at IS NULL）；
 *   - 删除的内容天然不计入（统计全部基于现存行）。
 */

namespace Hublog\Core;

class BadgeService
{
    public const STATE_UNLOCK = 1;
    public const STATE_FREEZE = 2;
    public const STATE_REVOKE = 3;

    /** @var array|null 徽章配置缓存（badge_key => row），仅 status=1 */
    private static ?array $badgeCache = null;

    /**
     * 阈值 key → 统计字段 映射（count 类徽章用）
     * 阈值里以 min_ 开头的数值键参与「统计值 >= 阈值」比较；
     * 其余键（min_length / min_comment_likes 等）是统计查询的参数，不参与比较。
     */
    private const STAT_MAP = [
        'streak'            => 'streak',
        'days'              => 'reg_days',
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
        'min_original'      => 'original',
        'min_god_comments'  => 'god_comments',
    ];

    // ==================================================================
    // 一、事件入口（控制器在用户数据变更后调用）
    // ==================================================================

    /**
     * 用户数据变更事件 → 触发对应徽章实时校验。
     *
     * 已支持事件：
     *   profile_update      编辑资料（头像/昵称/简介）
     *   phone_bound         手机号绑定/登录注册
     *   email_verified      邮箱验证通过
     *   realname_verified   实名认证通过
     *   vip_changed         会员开通/到期/后台发放
     *   circle_role_changed 圈子角色变化（任命/撤销/转让/解散）
     *   role_changed        平台角色变化
     *   user_status_changed 封禁/解封
     *
     * 统计类徽章不在此处实时跑（重），统一交给定时任务。
     */
    public static function onEvent(int $userId, string $event): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            self::evaluateRealtime($userId);
        } catch (\Throwable $e) {
            Logger::error("[Hublog BadgeService] onEvent({$event}, user={$userId}) 失败: " . $e->getMessage());
        }
    }

    // ==================================================================
    // 二、实时校验（realtime 类徽章）
    // ==================================================================

    /**
     * 校验单个用户的全部 realtime 徽章（幂等，可反复调用）。
     *
     * @return array{unlocked:string[],frozen:string[]} 本次发生状态变化的徽章 key
     */
    public static function evaluateRealtime(int $userId, bool $notify = true): array
    {
        $changed = ['unlocked' => [], 'frozen' => []];

        $user = Database::fetchOne(
            "SELECT id, phone, phone_verified_at, email, email_verified, realname_verified, avatar, nickname, bio,
                    vip_level, vip_expire_at, role, status, deleted_at, created_at
             FROM users WHERE id = ? LIMIT 1",
            [$userId]
        );
        if (!$user || (int)$user['status'] !== 1 || $user['deleted_at'] !== null) {
            // 封禁/注销账号：名下 unlock 的 realtime 徽章一律冻结（回收语义）
            // V83：只冻结已解锁的，避免给从未解锁过的徽章凭空插入 freeze 记录
            foreach (self::badgesByType('realtime') as $badge) {
                if (self::freezeIfUnlocked($userId, (int)$badge['id'], 'freeze', '账号异常（封禁/注销），徽章冻结')) {
                    $changed['frozen'][] = $badge['badge_key'];
                }
            }
            return $changed;
        }

        // 圈子角色：2=圈主 1=圈子管理员 0=无
        $circleRoleLevel = (int)Database::fetchColumn(
            "SELECT MAX(CASE role WHEN 'owner' THEN 2 WHEN 'admin' THEN 1 ELSE 0 END)
             FROM circle_members WHERE user_id = ?",
            [$userId]
        );

        $defaultAvatar = '/templates/moments/assets/images/default-avatar.svg';
        $bio = (string)($user['bio'] ?? '');
        $nickname = (string)($user['nickname'] ?? '');
        $vipLevel = (int)$user['vip_level'];
        $vipActive = $vipLevel > 0
            && ($user['vip_expire_at'] === null || strtotime((string)$user['vip_expire_at']) > time());

        // 每个 realtime 徽章的「是否达标」判定
        $conditions = [
            'phone_verified'    => !empty($user['phone_verified_at']),
            'email_verified'    => (int)$user['email_verified'] === 1,
            'realname_verified' => (int)$user['realname_verified'] === 1,
            'profile_complete'  => ($user['avatar'] ?? '') !== $defaultAvatar
                && $nickname !== ''
                && !preg_match('/^用户\d+$/', $nickname)
                && mb_strlen($bio) >= 8,
            'vip_monthly'       => $vipActive && in_array($vipLevel, [1, 2], true),
            'vip_yearly'        => $vipActive && $vipLevel === 3,
            // V86：会员只有 1/2/3 三档（购买获得），4/5 未开放，这两枚徽章已改为 manual
            // 人工授予。判定保留在此，将来若真开放 4/5 档位，把 rule_type 改回 realtime 即可生效。
            'vip_trial'         => $vipActive && $vipLevel === 4,
            'vip_crown'         => $vipActive && $vipLevel === 5,
            'circle_owner'      => $circleRoleLevel >= 2,
            'circle_admin'      => $circleRoleLevel >= 1,
            'platform_admin'    => ($user['role'] ?? '') === 'admin',
            // V85：审核员角色（users.role = reviewer，在职有效）
            'platform_reviewer' => ($user['role'] ?? '') === 'reviewer',
        ];

        foreach (self::badgesByType('realtime') as $badge) {
            $key = $badge['badge_key'];
            if (!array_key_exists($key, $conditions)) {
                continue; // 未登记判定规则的徽章跳过（防御）
            }
            if ($conditions[$key]) {
                if (self::setState($userId, (int)$badge['id'], self::STATE_UNLOCK, 'grant', [
                    'remark' => '条件达标，自动解锁',
                ])) {
                    $changed['unlocked'][] = $key;
                    if ($notify) {
                        self::notifyUnlock($userId, $badge);
                    }
                }
            } else {
                // V83：同上，只冻结已解锁的
                if (self::freezeIfUnlocked($userId, (int)$badge['id'], 'freeze', '条件不再满足，徽章冻结')) {
                    $changed['frozen'][] = $key;
                }
            }
        }
        return $changed;
    }

    // ==================================================================
    // 三、统计校验（count 类徽章）
    // ==================================================================

    /**
     * 校验单个用户的全部 count 徽章（现算统计，较重，供单用户刷新用）。
     * 批量场景请用 runScan()（聚合查询一次算完）。
     */
    public static function evaluateCount(int $userId, bool $notify = true): array
    {
        $stats = self::computeStats([$userId]);
        return self::evaluateCountWithStats($userId, $stats[$userId] ?? self::emptyStats(), $notify);
    }

    /**
     * 用已算好的统计数据校验 count 徽章。
     */
    private static function evaluateCountWithStats(int $userId, array $stats, bool $notify = true): array
    {
        $changed = ['unlocked' => [], 'frozen' => []];

        // V83：预先取一次该用户已持有的徽章状态。
        // 目的：count 徽章数量多（约38枚），runScan 又是对全站用户跑，
        // 若逐枚查「是否已解锁」会产生 N+1；批量取一次即可。
        $owned = self::ownedStates($userId);

        foreach (self::badgesByType('count') as $badge) {
            $threshold = json_decode((string)($badge['threshold'] ?? '[]'), true);
            if (!is_array($threshold) || !$threshold) {
                continue;
            }
            if (self::meetsThreshold($stats, $threshold)) {
                if (self::setState($userId, (int)$badge['id'], self::STATE_UNLOCK, 'grant', [
                    'remark' => '统计达标，自动解锁',
                ])) {
                    $changed['unlocked'][] = $badge['badge_key'];
                    if ($notify) {
                        self::notifyUnlock($userId, $badge);
                    }
                }
            } else {
                // V83：只冻结「已解锁」的徽章。从未解锁过的直接跳过——
                // 否则每个用户都会为每个未达标的 count 徽章插入一条 freeze 记录
                // （实测 7 用户就产生 263 条），既撑大 user_badges 表，
                // 也让后台「用户徽章」列表被大量冻结项刷屏。
                if (($owned[(int)$badge['id']] ?? 0) !== self::STATE_UNLOCK) {
                    continue;
                }
                if (self::setState($userId, (int)$badge['id'], self::STATE_FREEZE, 'freeze', [
                    'remark' => '统计回落到阈值以下，徽章冻结',
                ])) {
                    $changed['frozen'][] = $badge['badge_key'];
                }
            }
        }
        return $changed;
    }

    /**
     * V83：取某用户已持有徽章的状态表 [badge_id => state]。
     * 用于避免「是否需要冻结」的 N+1 查询。
     */
    private static function ownedStates(int $userId): array
    {
        $rows = Database::fetchAll(
            "SELECT badge_id, state FROM user_badges WHERE user_id = ?",
            [$userId]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['badge_id']] = (int)$r['state'];
        }
        return $map;
    }

    /**
     * V83：仅在徽章当前处于「已解锁」时才冻结它。
     *
     * 直接调 setState(FREEZE) 会对「从未解锁过」的徽章也插入一条记录并写日志，
     * 造成 user_badges 表被无意义的冻结项填满（后台列表刷屏、数据膨胀）。
     * 冻结的语义是「收回已发出的徽章」，没发出过的自然无需冻结。
     *
     * @return bool 是否真的发生了状态变化
     */
    private static function freezeIfUnlocked(int $userId, int $badgeId, string $action, string $remark): bool
    {
        $row = Database::fetchOne(
            "SELECT id, state FROM user_badges WHERE user_id = ? AND badge_id = ?",
            [$userId, $badgeId]
        );
        if (!$row || (int)$row['state'] !== self::STATE_UNLOCK) {
            return false;
        }
        return self::setState($userId, $badgeId, self::STATE_FREEZE, $action, ['remark' => $remark]);
    }

    /**
     * 限时徽章校验（新人启航：注册 ≤ N 天展示，超过自动消失）。
     */
    public static function evaluateTimed(int $userId, bool $notify = true): array
    {
        $changed = ['unlocked' => [], 'frozen' => []];
        $user = Database::fetchOne(
            "SELECT created_at, status, deleted_at FROM users WHERE id = ? LIMIT 1",
            [$userId]
        );
        if (!$user || (int)$user['status'] !== 1 || $user['deleted_at'] !== null) {
            return $changed;
        }
        $regDays = (int)floor((time() - strtotime((string)$user['created_at'])) / 86400);

        foreach (self::badgesByType('timed') as $badge) {
            $threshold = json_decode((string)($badge['threshold'] ?? '[]'), true);
            $days = (int)($threshold['days'] ?? 0);
            if ($days <= 0) {
                continue;
            }
            if ($regDays <= $days) {
                $expireAt = date('Y-m-d H:i:s', strtotime((string)$user['created_at']) + $days * 86400);
                if (self::setState($userId, (int)$badge['id'], self::STATE_UNLOCK, 'grant', [
                    'expire_time' => $expireAt,
                    'remark'      => '新用户限时徽章',
                ])) {
                    $changed['unlocked'][] = $badge['badge_key'];
                    if ($notify) {
                        self::notifyUnlock($userId, $badge);
                    }
                }
            } else {
                // V83：同上，只冻结已解锁的
                if (self::freezeIfUnlocked($userId, (int)$badge['id'], 'expire', '限时徽章已到期')) {
                    $changed['frozen'][] = $badge['badge_key'];
                }
            }
        }
        return $changed;
    }

    /**
     * 单用户全量校验（realtime + count + timed）。
     */
    public static function evaluateUser(int $userId, bool $notify = true): array
    {
        $result = ['unlocked' => [], 'frozen' => []];
        foreach (['evaluateRealtime', 'evaluateCount', 'evaluateTimed'] as $method) {
            $r = self::$method($userId, $notify);
            $result['unlocked'] = array_merge($result['unlocked'], $r['unlocked']);
            $result['frozen'] = array_merge($result['frozen'], $r['frozen']);
        }
        return $result;
    }

    /**
     * V83：用户产生内容行为后触发徽章重算（发帖/评论/点赞/收藏/关注/签到等）。
     *
     * 背景：onEvent() 只覆盖身份类事件（实名/手机/VIP/角色），而 posts、comments、
     * likes_given、original、god_comments 这类统计徽章原本只能等外部 cron 扫描。
     * 一旦服务器没配 cron，这些徽章永远不会解锁——所以在行为发生后补一次重算。
     *
     * 节流：点赞/浏览属高频操作，每次都跑 computeStats（十余条聚合查询）会拖垮性能，
     * 故用缓存做「同一用户 $ttl 秒内最多重算一次」的限流。低频行为可把 $ttl 传 0 跳过限流。
     *
     * 绝不抛异常：徽章属增值功能，任何失败都只记日志，不能阻断发帖/评论主流程。
     *
     * @param int $userId 行为发起者
     * @param int $ttl    节流窗口（秒），0 表示不节流
     */
    public static function evaluateOnAction(int $userId, int $ttl = 120): array
    {
        $empty = ['unlocked' => [], 'frozen' => []];
        if ($userId <= 0) {
            return $empty;
        }

        try {
            if ($ttl > 0) {
                $lockKey = 'badge_eval_lock_' . $userId;
                if (Cache::has($lockKey)) {
                    return $empty;
                }
                Cache::set($lockKey, 1, $ttl);
            }
            $result = self::evaluateUser($userId, true);

            // 徽章状态变了才清展示缓存：列表/详情都缓存了 user.badges，
            // 不清的话刚解锁的徽章要等缓存自然过期（最长 1 小时）才显示出来。
            // 解锁是低频事件，这里清缓存的代价可以接受。
            if ($result['unlocked'] || $result['frozen']) {
                self::clearDisplayCache();
            }
            return $result;
        } catch (\Throwable $e) {
            Logger::error('[Hublog BadgeService] evaluateOnAction 失败(uid=' . $userId . '): ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * V83：清掉缓存了徽章数据的展示层缓存。
     * 列表走 timeline 命名空间（原 timeline_v4_*，P0-11 起改为版本号升版），
     * 详情用 post_*（含 post_comments_/post_likes_ 等同前缀）。
     * 后台「立即扫描」和 cron 跑完后必须调一次，否则页面仍显示扫描前的旧徽章。
     */
    public static function clearDisplayCache(): void
    {
        try {
            // P0-11：timeline 命名空间升版替代前缀删除
            Cache::bumpNsVersion('timeline');
            Cache::deleteByPrefix('post_');
        } catch (\Throwable $e) {
            Logger::error('[Hublog BadgeService] clearDisplayCache 失败: ' . $e->getMessage());
        }
    }

    /**
     * 定时任务入口：全站扫描（只扫正常账号），自动解锁/冻结/到期。
     *
     * @param int $batchSize 每批用户数（聚合查询按批跑，控制单次 SQL 的 IN 列表长度）
     * @return array{users:int,unlocked:int,frozen:int}
     */
    public static function runScan(int $batchSize = 500): array
    {
        $result = ['users' => 0, 'unlocked' => 0, 'frozen' => 0];
        $lastId = 0;

        while (true) {
            $rows = Database::fetchAll(
                "SELECT id FROM users
                 WHERE status = 1 AND deleted_at IS NULL AND id > ?
                 ORDER BY id ASC LIMIT ?",
                [$lastId, $batchSize]
            );
            if (!$rows) {
                break;
            }
            $ids = array_map('intval', array_column($rows, 'id'));
            $lastId = end($ids);

            $statsMap = self::computeStats($ids);
            foreach ($ids as $uid) {
                $r = self::evaluateCountWithStats($uid, $statsMap[$uid] ?? self::emptyStats(), true);
                $result['unlocked'] += count($r['unlocked']);
                $result['frozen'] += count($r['frozen']);

                $r2 = self::evaluateRealtime($uid, true);
                $result['unlocked'] += count($r2['unlocked']);
                $result['frozen'] += count($r2['frozen']);

                $r3 = self::evaluateTimed($uid, true);
                $result['unlocked'] += count($r3['unlocked']);
                $result['frozen'] += count($r3['frozen']);

                $result['users']++;
            }
            if (count($ids) < $batchSize) {
                break;
            }
        }

        // V83：扫描改变了徽章状态就必须清展示缓存，否则页面还是旧数据
        if ($result['unlocked'] > 0 || $result['frozen'] > 0) {
            self::clearDisplayCache();
        }
        return $result;
    }

    // ==================================================================
    // 四、人工操作（后台：授予 / 冻结 / 回收）
    // ==================================================================

    /**
     * 人工授予徽章（补发活动徽章、补偿用户、特殊荣誉）。
     * revoke 状态的徽章只能通过此途径恢复（PRD：永久回收不可自动恢复）。
     *
     * @return array{ok:bool,message:string}
     */
    public static function grant(int $userId, string $badgeKey, int $operatorId, string $remark, string $ip = ''): array
    {
        $badge = self::findBadge($badgeKey);
        if (!$badge) {
            return ['ok' => false, 'message' => '徽章不存在'];
        }
        $user = Database::fetchOne("SELECT id, status FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user) {
            return ['ok' => false, 'message' => '用户不存在'];
        }

        $existing = Database::fetchOne(
            "SELECT id, state FROM user_badges WHERE user_id = ? AND badge_id = ?",
            [$userId, (int)$badge['id']]
        );
        if ($existing && (int)$existing['state'] === self::STATE_UNLOCK) {
            return ['ok' => true, 'message' => '该用户已持有此徽章'];
        }

        $opts = [
            'grant_type'  => 'manual',
            'operator_id' => $operatorId,
            'remark'      => $remark !== '' ? $remark : '后台人工授予',
            'ip'          => $ip,
        ];
        self::setState($userId, (int)$badge['id'], self::STATE_UNLOCK, 'grant', $opts);
        self::notifyUnlock($userId, $badge);
        return ['ok' => true, 'message' => '授予成功'];
    }

    /**
     * 人工冻结徽章（临时隐藏；条件恢复后自动任务可还原）。
     */
    public static function freeze(int $userId, string $badgeKey, int $operatorId, string $remark, string $ip = ''): array
    {
        $badge = self::findBadge($badgeKey);
        if (!$badge) {
            return ['ok' => false, 'message' => '徽章不存在'];
        }
        $existing = Database::fetchOne(
            "SELECT id, state FROM user_badges WHERE user_id = ? AND badge_id = ?",
            [$userId, (int)$badge['id']]
        );
        if (!$existing) {
            return ['ok' => false, 'message' => '该用户未持有此徽章'];
        }
        if ((int)$existing['state'] === self::STATE_REVOKE) {
            return ['ok' => false, 'message' => '徽章已永久回收，不能冻结'];
        }
        if ((int)$existing['state'] === self::STATE_FREEZE) {
            return ['ok' => true, 'message' => '徽章已处于冻结状态'];
        }

        self::setState($userId, (int)$badge['id'], self::STATE_FREEZE, 'freeze', [
            'operator_id' => $operatorId,
            'remark'      => $remark !== '' ? $remark : '后台人工冻结',
            'ip'          => $ip,
        ]);
        return ['ok' => true, 'message' => '冻结成功'];
    }

    /**
     * 人工永久回收徽章（不可自动恢复，只能人工重新授予）。
     */
    public static function revoke(int $userId, string $badgeKey, int $operatorId, string $remark, string $ip = ''): array
    {
        $badge = self::findBadge($badgeKey);
        if (!$badge) {
            return ['ok' => false, 'message' => '徽章不存在'];
        }
        $existing = Database::fetchOne(
            "SELECT id, state FROM user_badges WHERE user_id = ? AND badge_id = ?",
            [$userId, (int)$badge['id']]
        );
        if (!$existing) {
            return ['ok' => false, 'message' => '该用户未持有此徽章'];
        }
        if ((int)$existing['state'] === self::STATE_REVOKE) {
            return ['ok' => true, 'message' => '徽章已处于回收状态'];
        }

        self::setState($userId, (int)$badge['id'], self::STATE_REVOKE, 'revoke', [
            'operator_id' => $operatorId,
            'remark'      => $remark !== '' ? $remark : '后台人工回收',
            'ip'          => $ip,
        ]);
        return ['ok' => true, 'message' => '回收成功'];
    }

    // ==================================================================
    // 五、展示查询
    // ==================================================================

    /**
     * 用户已解锁的徽章列表（含配置，按 sort_weight 倒序）。
     * 徽章被后台禁用不影响已获取用户的展示（PRD 第12章第5条）。
     *
     * @return array<int,array> 徽章数组（badge_key/name/desc/icon/color/sort_weight/hideable/unlock_time）
     */
    public static function getUserBadges(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = Database::fetchAll(
            "SELECT b.badge_key, b.name, b.`desc`, b.icon, b.color, b.sort_weight, b.hideable,
                    ub.unlock_time, ub.grant_type
             FROM user_badges ub
             JOIN badges b ON b.id = ub.badge_id
             WHERE ub.user_id = ? AND ub.state = ?
             ORDER BY b.sort_weight DESC, ub.unlock_time ASC",
            [$userId, self::STATE_UNLOCK]
        );
        return array_map(static function ($row) {
            return [
                'badge_key'   => $row['badge_key'],
                'name'        => $row['name'],
                'desc'        => $row['desc'],
                'icon'        => $row['icon'],
                'color'       => $row['color'],
                'sort_weight' => (int)$row['sort_weight'],
                'hideable'    => (int)$row['hideable'],
                'grant_type'  => $row['grant_type'],
                'unlock_time' => $row['unlock_time'],
            ];
        }, $rows);
    }

    /**
     * 给用户信息数组补充 badges 字段（个人主页 / 用户名片用）。
     * 用法：BadgeService::decorateUser($user);
     */
    public static function decorateUser(array &$user): void
    {
        $userId = (int)($user['id'] ?? 0);
        $user['badges'] = $userId > 0 ? self::getUserBadges($userId) : [];
    }

    /**
     * 单个用户的内联徽章（display=1，即「名字旁」展示的身份/认证/角色类徽章）。
     * 用于动态作者区、评论作者区——与实名徽章同一位置。
     */
    public static function getInlineBadges(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        return self::getInlineBadgesBatch([$userId])[$userId] ?? [];
    }

    /**
     * 批量取用户内联徽章（一次查询，避免列表页 N+1）。
     *
     * @param int[] $userIds
     * @return array<int,array> user_id => 徽章数组（按 sort_weight 倒序）
     */
    public static function getInlineBadgesBatch(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $map = [];
        foreach ($userIds as $uid) {
            $map[$uid] = [];
        }
        if (!$userIds) {
            return $map;
        }
        try {
            $in = implode(',', array_fill(0, count($userIds), '?'));
            $rows = Database::fetchAll(
                "SELECT ub.user_id, b.badge_key, b.name, b.`desc`, b.icon, b.color, b.sort_weight, b.hideable,
                        ub.unlock_time, ub.grant_type
                 FROM user_badges ub
                 JOIN badges b ON b.id = ub.badge_id
                 WHERE ub.user_id IN ({$in}) AND ub.state = ? AND b.display = 1
                 ORDER BY b.sort_weight DESC, ub.unlock_time ASC",
                array_merge($userIds, [self::STATE_UNLOCK])
            );
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if (!isset($map[$uid])) {
                    continue;
                }
                $map[$uid][] = [
                    'badge_key'   => $row['badge_key'],
                    'name'        => $row['name'],
                    'desc'        => $row['desc'],
                    'icon'        => $row['icon'],
                    'color'       => $row['color'],
                    'sort_weight' => (int)$row['sort_weight'],
                    'hideable'    => (int)$row['hideable'],
                    'grant_type'  => $row['grant_type'],
                    'unlock_time' => $row['unlock_time'],
                ];
            }
        } catch (\Throwable $e) {
            Logger::error('[Hublog BadgeService] getInlineBadgesBatch 失败: ' . $e->getMessage());
        }
        return $map;
    }

    // ==================================================================
    // 六、统计计算（count 类徽章的数据底座）
    // ==================================================================

    /**
     * 空统计模板（所有指标默认 0）。
     */
    private static function emptyStats(): array
    {
        return [
            'posts' => 0, 'image_posts' => 0, 'video_posts' => 0, 'max_views' => 0,
            'featured' => 0, 'comments' => 0, 'replies' => 0, 'long_comments' => 0,
            'accepts' => 0, 'sofa' => 0, 'hot_comments' => 0, 'likes_given' => 0,
            'favorites' => 0, 'following' => 0, 'activities' => 0, 'topic_posts' => 0,
            'original' => 0, 'god_comments' => 0,
            'streak' => 0, 'reg_days' => 0,
        ];
    }

    /**
     * 批量计算一批用户的徽章统计数据（每条统计一句 GROUP BY 聚合，全批一次算完）。
     *
     * 防刷口径：comments 排除 AI（is_ai=0）；posts 只要 status=1；
     * 删除的内容天然不在统计内（计数回滚由 PRD 规则天然满足）。
     *
     * @param int[] $userIds
     * @return array<int,array> user_id => stats
     */
    public static function computeStats(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $stats = [];
        foreach ($userIds as $uid) {
            $stats[$uid] = self::emptyStats();
        }
        if (!$userIds) {
            return $stats;
        }

        $merge = static function (array $rows, string $col) use (&$stats): void {
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if (isset($stats[$uid])) {
                    $stats[$uid][$col] = (int)$row['cnt'];
                }
            }
        };

        try {
            $in = implode(',', array_fill(0, count($userIds), '?'));

            // 有效帖 / 图文帖 / 视频帖 / 最高浏览量
            $rows = Database::fetchAll(
                "SELECT user_id,
                        COUNT(*) AS cnt_total,
                        SUM(CASE WHEN `images` IS NOT NULL AND JSON_LENGTH(`images`) > 0 THEN 1 ELSE 0 END) AS cnt_image,
                        SUM(CASE WHEN `videos` IS NOT NULL AND JSON_LENGTH(`videos`) > 0 THEN 1 ELSE 0 END) AS cnt_video,
                        MAX(view_count) AS cnt_views
                 FROM posts WHERE status = 1 AND user_id IN ({$in}) GROUP BY user_id",
                $userIds
            );
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if (!isset($stats[$uid])) {
                    continue;
                }
                $stats[$uid]['posts'] = (int)$row['cnt_total'];
                $stats[$uid]['image_posts'] = (int)$row['cnt_image'];
                $stats[$uid]['video_posts'] = (int)$row['cnt_video'];
                $stats[$uid]['max_views'] = (int)$row['cnt_views'];
            }

            // 精华帖（featured_posts → 帖子作者）
            $merge(Database::fetchAll(
                "SELECT p.user_id, COUNT(*) AS cnt
                 FROM featured_posts fp JOIN posts p ON p.id = fp.post_id
                 WHERE p.user_id IN ({$in}) GROUP BY p.user_id",
                $userIds
            ), 'featured');

            // 有效评论 / 楼中楼回复 / 长评论(≥80字)
            $rows = Database::fetchAll(
                "SELECT user_id,
                        COUNT(*) AS cnt_total,
                        SUM(CASE WHEN parent_id IS NOT NULL THEN 1 ELSE 0 END) AS cnt_reply,
                        SUM(CASE WHEN CHAR_LENGTH(content) >= 80 THEN 1 ELSE 0 END) AS cnt_long
                 FROM comments WHERE is_ai = 0 AND user_id IN ({$in}) GROUP BY user_id",
                $userIds
            );
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if (!isset($stats[$uid])) {
                    continue;
                }
                $stats[$uid]['comments'] = (int)$row['cnt_total'];
                $stats[$uid]['replies'] = (int)$row['cnt_reply'];
                $stats[$uid]['long_comments'] = (int)$row['cnt_long'];
            }

            // 问答被采纳
            $merge(Database::fetchAll(
                "SELECT answered_by AS user_id, COUNT(*) AS cnt
                 FROM questions WHERE answered_by IN ({$in}) GROUP BY answered_by",
                $userIds
            ), 'accepts');

            // 沙发：每帖首条非 AI 评论的作者
            $merge(Database::fetchAll(
                "SELECT c.user_id, COUNT(*) AS cnt
                 FROM comments c
                 JOIN (SELECT post_id, MIN(id) AS first_id FROM comments WHERE is_ai = 0 GROUP BY post_id) f
                   ON f.post_id = c.post_id AND f.first_id = c.id
                 WHERE c.is_ai = 0 AND c.user_id IN ({$in}) GROUP BY c.user_id",
                $userIds
            ), 'sofa');

            // 热评：单条评论点赞数 ≥ 10（阈值与 hot_comment 徽章配置保持一致）
            $merge(Database::fetchAll(
                "SELECT c.user_id, COUNT(*) AS cnt
                 FROM comments c
                 JOIN (SELECT comment_id, COUNT(*) AS cnt FROM comment_likes GROUP BY comment_id) cl
                   ON cl.comment_id = c.id AND cl.cnt >= 10
                 WHERE c.is_ai = 0 AND c.user_id IN ({$in}) GROUP BY c.user_id",
                $userIds
            ), 'hot_comments');

            // 送出点赞（帖子 + 评论）
            $rows = Database::fetchAll(
                "SELECT user_id, COUNT(*) AS cnt FROM likes WHERE user_id IN ({$in}) GROUP BY user_id",
                $userIds
            );
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if (isset($stats[$uid])) {
                    $stats[$uid]['likes_given'] += (int)$row['cnt'];
                }
            }
            $rows = Database::fetchAll(
                "SELECT user_id, COUNT(*) AS cnt FROM comment_likes WHERE user_id IN ({$in}) GROUP BY user_id",
                $userIds
            );
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if (isset($stats[$uid])) {
                    $stats[$uid]['likes_given'] += (int)$row['cnt'];
                }
            }

            // 收藏 / 关注 / 活动报名
            $merge(Database::fetchAll(
                "SELECT user_id, COUNT(*) AS cnt FROM favorites WHERE user_id IN ({$in}) GROUP BY user_id",
                $userIds
            ), 'favorites');
            $merge(Database::fetchAll(
                "SELECT follower_id AS user_id, COUNT(*) AS cnt
                 FROM follows WHERE follower_id IN ({$in}) GROUP BY follower_id",
                $userIds
            ), 'following');
            $merge(Database::fetchAll(
                "SELECT user_id, COUNT(*) AS cnt
                 FROM activity_registrations WHERE status = 'joined' AND user_id IN ({$in}) GROUP BY user_id",
                $userIds
            ), 'activities');

            // 官方话题下发帖（post_tags.tag 命中 topics.name）
            $merge(Database::fetchAll(
                "SELECT p.user_id, COUNT(*) AS cnt
                 FROM posts p
                 JOIN post_tags pt ON pt.post_id = p.id
                 WHERE p.status = 1 AND p.user_id IN ({$in})
                   AND pt.tag IN (SELECT name FROM topics WHERE status = 1)
                 GROUP BY p.user_id",
                $userIds
            ), 'topic_posts');

            // 原创帖（is_original=1，仅统计有效帖）
            $merge(Database::fetchAll(
                "SELECT user_id, COUNT(*) AS cnt FROM posts
                 WHERE status = 1 AND is_original = 1 AND user_id IN ({$in}) GROUP BY user_id",
                $userIds
            ), 'original');

            // 被标神评的评论数（comment_marks 反查评论作者）
            $merge(Database::fetchAll(
                "SELECT c.user_id, COUNT(*) AS cnt
                 FROM comment_marks cm
                 JOIN comments c ON c.id = cm.comment_id AND c.is_ai = 0
                 WHERE cm.mark_type = 'god' AND c.user_id IN ({$in}) GROUP BY c.user_id",
                $userIds
            ), 'god_comments');

            // 签到最高连击 + 注册天数
            $merge(Database::fetchAll(
                "SELECT user_id, MAX(streak) AS cnt FROM user_signins WHERE user_id IN ({$in}) GROUP BY user_id",
                $userIds
            ), 'streak');
            $rows = Database::fetchAll(
                "SELECT id AS user_id, DATEDIFF(NOW(), created_at) AS cnt FROM users WHERE id IN ({$in})",
                $userIds
            );
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if (isset($stats[$uid])) {
                    $stats[$uid]['reg_days'] = (int)$row['cnt'];
                }
            }
        } catch (\Throwable $e) {
            Logger::error('[Hublog BadgeService] computeStats 失败: ' . $e->getMessage());
        }
        return $stats;
    }

    /**
     * 统计值是否满足徽章阈值（阈值内所有 min_* 数值键全部满足才算达标，AND 语义）。
     */
    private static function meetsThreshold(array $stats, array $threshold): bool
    {
        foreach ($threshold as $key => $need) {
            if (!is_numeric($need) || !isset(self::STAT_MAP[$key])) {
                continue; // min_length 等参数键不参与比较
            }
            $statKey = self::STAT_MAP[$key];
            if ((int)($stats[$statKey] ?? 0) < (int)$need) {
                return false;
            }
        }
        return true;
    }

    // ==================================================================
    // 七、内部工具
    // ==================================================================

    /**
     * 加载徽章配置（进程内缓存；后台改配置后由控制器清缓存）。
     *
     * @return array<string,array> badge_key => 配置行
     */
    private static function loadBadges(): array
    {
        if (self::$badgeCache !== null) {
            return self::$badgeCache;
        }
        $rows = Database::fetchAll("SELECT * FROM badges WHERE status = 1");
        $cache = [];
        foreach ($rows as $row) {
            $cache[$row['badge_key']] = $row;
        }
        self::$badgeCache = $cache;
        return $cache;
    }

    /** 清空徽章配置缓存（后台增删改后调用） */
    public static function clearCache(): void
    {
        self::$badgeCache = null;
    }

    /**
     * V83：给一批用户数据批量补上内联徽章（原地写回 $user['badges']）。
     *
     * 用于用户列表类接口（关注/粉丝/搜索/推荐/排行榜），
     * 让「实名徽章在哪、新增徽章就在哪」在所有露出昵称的地方一致成立。
     * 传引用数组，调用方直接接着 json 输出即可。
     *
     * @param array $users  用户数组列表，每项须含 id 字段
     * @return array 原样返回（便于链式书写）
     */
    public static function decorateUsersWithInlineBadges(array &$users): array
    {
        if (!$users) {
            return $users;
        }
        $ids = [];
        foreach ($users as $u) {
            if (is_array($u) && !empty($u['id'])) {
                $ids[] = (int)$u['id'];
            }
        }
        if (!$ids) {
            return $users;
        }
        $map = self::getInlineBadgesBatch($ids);
        foreach ($users as $i => $u) {
            if (!is_array($u) || empty($u['id'])) {
                continue;
            }
            // 已自带 badges（如帖子作者）时不覆盖，避免重复查询互相打架
            if (isset($users[$i]['badges'])) {
                continue;
            }
            $users[$i]['badges'] = $map[(int)$u['id']] ?? [];
        }
        return $users;
    }

    /** 按 rule_type 取徽章配置列表 */
    private static function badgesByType(string $ruleType): array
    {
        return array_filter(self::loadBadges(), static function ($badge) use ($ruleType) {
            return $badge['rule_type'] === $ruleType;
        });
    }

    /** 按 badge_key 取单个徽章配置（不要求启用，人工授予禁用徽章也允许） */
    public static function findBadge(string $badgeKey): ?array
    {
        $row = Database::fetchOne("SELECT * FROM badges WHERE badge_key = ? LIMIT 1", [$badgeKey]);
        return $row ?: null;
    }

    /**
     * 写入用户徽章状态（幂等：状态无变化时返回 false，不写日志）。
     *
     * @param array $opts expire_time/grant_type/operator_id/remark/ip
     * @return bool 是否发生了状态变化
     */
    private static function setState(int $userId, int $badgeId, int $newState, string $action, array $opts = []): bool
    {
        $existing = Database::fetchOne(
            "SELECT * FROM user_badges WHERE user_id = ? AND badge_id = ?",
            [$userId, $badgeId]
        );
        $fromState = $existing ? (int)$existing['state'] : 0;

        // 永久回收的徽章绝不被自动任务改写（revoke 只能人工重新授予）。
        // 注意：自动解锁传的也是 action='grant'，必须靠 grant_type=manual 区分人工授予。
        $isManualGrant = ($opts['grant_type'] ?? '') === 'manual';
        if ($fromState === self::STATE_REVOKE && !$isManualGrant) {
            return false;
        }
        if ($existing && $fromState === $newState) {
            return false;
        }

        $data = ['state' => $newState];
        if (!$existing || empty($existing['unlock_time'])) {
            $data['unlock_time'] = Helper::now();
        }
        if (!empty($opts['expire_time'])) {
            $data['expire_time'] = $opts['expire_time'];
        }
        if (!empty($opts['grant_type'])) {
            $data['grant_type'] = $opts['grant_type'];
        }
        if (array_key_exists('operator_id', $opts) && $opts['operator_id'] !== null) {
            $data['operator_id'] = (int)$opts['operator_id'];
        }
        if (array_key_exists('remark', $opts)) {
            $data['remark'] = $opts['remark'];
        }

        if ($existing) {
            Database::update('user_badges', $data, 'id = ?', [(int)$existing['id']]);
        } else {
            $data['user_id'] = $userId;
            $data['badge_id'] = $badgeId;
            Database::insert('user_badges', $data);
        }

        self::writeLog($userId, $badgeId, $action, $fromState, $newState, $opts);
        return true;
    }

    /**
     * 写徽章操作日志（所有状态变更必须留痕，日志不提供删除）。
     */
    private static function writeLog(
        int $userId,
        int $badgeId,
        string $action,
        ?int $fromState,
        int $toState,
        array $opts = []
    ): void {
        try {
            $operatorId = $opts['operator_id'] ?? null;
            $operatorName = null;
            if ($operatorId) {
                $operatorName = Database::fetchColumn(
                    "SELECT nickname FROM users WHERE id = ? LIMIT 1",
                    [(int)$operatorId]
                );
            }
            Database::insert('badge_logs', [
                'operator_id'   => $operatorId ? (int)$operatorId : null,
                'operator_name' => $operatorName,
                'user_id'       => $userId,
                'badge_id'      => $badgeId,
                'action'        => $action,
                'from_state'    => $fromState,
                'to_state'      => $toState,
                'remark'        => $opts['remark'] ?? null,
                'ip'            => $opts['ip'] ?? null,
                'created_at'    => Helper::now(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('[Hublog BadgeService] 写徽章日志失败: ' . $e->getMessage());
        }
    }

    /**
     * 徽章解锁通知（站内通知 + 离线推送）。
     *
     * V168：发送人必须是「系统」（system_notice 虚拟账号），不能借用 ai_assistant——
     * 否则用户收到的徽章通知显示发送人是「ai评论家」。
     * notifications.sender_id 有外键约束不能为 0，故必须有真实账号；
     * system_notice 未创建（迁移没跑）时兜底到管理员 id=1，展示层仍会覆盖成「系统通知」。
     */
    private static function notifyUnlock(int $userId, array $badge): void
    {
        try {
            $senderId = Helper::getSystemUserId();
            if ($senderId <= 0) {
                $senderId = 1; // 兜底：install.php 固定插入的管理员 id=1
            }
            Database::insert('notifications', [
                'user_id'    => $userId,
                'sender_id'  => $senderId,
                'type'       => 'badge',
                'content'    => '恭喜获得徽章「' . $badge['name'] . '」：' . ($badge['desc'] ?? ''),
                'is_read'    => 0,
                'created_at' => Helper::now(),
            ]);
            PushService::pushToUser($userId, '徽章解锁', '恭喜获得徽章「' . $badge['name'] . '」', [
                'type'      => 'badge',
                'badge_key' => $badge['badge_key'] ?? '',
            ]);
        } catch (\Throwable $e) {
            Logger::error('[Hublog BadgeService] 徽章通知失败: ' . $e->getMessage());
        }
    }
}
