<?php
/**
 * 内容治理服务（V71 / P0）
 *
 * 两件事合在一个类里，因为它们在调用点总是成对出现（写内容前既要查禁言、又要过敏感词）：
 *
 *  A. 全局敏感词过滤
 *     词库 sensitive_words，三级处理：
 *       - replace：把命中的词替换成 ***（默认，用户无感，内容照发）
 *       - block  ：直接拦截，返回错误让用户改
 *       - review ：内容照发但置为待审（status=0，不进信息流），等后台人工过
 *     命中一律写入 content_filter_logs，后台可查。
 *
 *  B. 违规处理链路
 *     - 全站禁言：users.muted_until（NULL=正常；> now = 禁言中）
 *     - 圈子限流：circles.limited=1 → 圈内禁发帖/评论，可浏览
 *     - 圈子下架：circles.removed_at != NULL → 整圈不可见
 *
 * 设计约束（踩过的坑）：
 *   1. 词库走 Cache，改词后必须调 clearWordsCache()，否则后台改完最长 10 分钟不生效；
 *   2. filter() 永远不抛异常，只返回数组，避免敏感词表缺失时把正常发帖打成 500；
 *   3. 判定优先级 block > review > replace —— 一段文本同时命中多个词时按最严的处理。
 */

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;

class Moderation
{
    /** 生效场景（与 sensitive_words.scene 对应，空=全场景） */
    public const SCENES = ['post', 'comment', 'chat', 'profile', 'circle'];

    /** 词库缓存 key */
    private const CACHE_KEY = 'sensitive_words_active';

    /** 处理级别优先级，数字越大越严 */
    private const LEVEL_RANK = ['replace' => 1, 'review' => 2, 'block' => 3];

    /**
     * 取启用中的敏感词（缓存 600 秒）
     *
     * @return array<array{word:string,level:string,replacement:string,scene:string}>
     */
    public static function words(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, function () {
                $rows = Database::fetchAll(
                    "SELECT word, level, replacement, scene
                     FROM sensitive_words
                     WHERE status = 1 AND word <> ''
                     ORDER BY CHAR_LENGTH(word) DESC"
                );
                return is_array($rows) ? $rows : [];
            }, 600);
        } catch (\Throwable $e) {
            // 表还没建出来 / 查询异常 → 当作空词库，绝不能影响正常业务写入
            Logger::error('[Moderation words] ' . $e->getMessage());
            return [];
        }
    }

    /** 后台改了词库后调用，立即失效缓存 */
    public static function clearWordsCache(): void
    {
        try {
            Cache::delete(self::CACHE_KEY);
        } catch (\Throwable $e) {
            Logger::error('[Moderation clearWordsCache] ' . $e->getMessage());
        }
    }

    /**
     * 过滤一段文本（核心入口）
     *
     * 不抛异常。返回：
     *   [
     *     'ok'    => bool,     // false = 被 block 拦截，调用方应直接返回错误
     *     'text'  => string,   // 处理后的文本（replace 已替换；block 时返回原文）
     *     'msg'   => string,   // ok=false 时给用户的提示
     *     'action'=> string,   // none / replace / review / block
     *     'words' => string[], // 命中的词
     *   ]
     *
     * @param string $text     待过滤文本
     * @param string $scene    场景，见 self::SCENES
     * @param int    $userId   命中时记录到日志的用户ID
     * @param int    $targetId 命中时记录到日志的对象ID
     */
    public static function filter(string $text, string $scene = 'post', int $userId = 0, int $targetId = 0): array
    {
        $result = [
            'ok'     => true,
            'text'   => $text,
            'msg'    => '',
            'action' => 'none',
            'words'  => [],
        ];

        if ($text === '' || !in_array($scene, self::SCENES, true)) {
            return $result;
        }

        $words = self::words();
        if (!$words) {
            return $result;
        }

        $haystack = $text;
        $lower    = mb_strtolower($haystack, 'UTF-8');
        $hits     = [];   // word => ['level'=>..,'replacement'=>..]

        foreach ($words as $w) {
            $word = (string)($w['word'] ?? '');
            if ($word === '') {
                continue;
            }
            // scene 为空 = 全场景生效；否则必须包含当前场景
            $sceneCsv = (string)($w['scene'] ?? '');
            if ($sceneCsv !== '') {
                $allowed = array_map('trim', explode(',', $sceneCsv));
                if (!in_array($scene, $allowed, true)) {
                    continue;
                }
            }
            if (mb_stripos($lower, mb_strtolower($word, 'UTF-8'), 0, 'UTF-8') !== false) {
                $hits[$word] = [
                    'level'       => (string)($w['level'] ?? 'replace'),
                    'replacement' => (string)($w['replacement'] ?? '') !== '' ? (string)$w['replacement'] : '***',
                ];
            }
        }

        if (!$hits) {
            return $result;
        }

        // 取最严级别
        $topLevel = 'replace';
        foreach ($hits as $h) {
            $lv = $h['level'];
            if ((self::LEVEL_RANK[$lv] ?? 1) > (self::LEVEL_RANK[$topLevel] ?? 1)) {
                $topLevel = $lv;
            }
        }

        $result['words']  = array_keys($hits);
        $result['action'] = $topLevel;

        if ($topLevel === 'block') {
            $result['ok']  = false;
            $result['msg'] = '内容包含违规词汇，请修改后再试';
        } elseif ($topLevel === 'replace') {
            // 长词优先替换，避免短词先把长词切碎。
            // 注意：只在原文 $text 上做 str_ireplace，不能碰 $lower —— $lower 是整段小写的匹配副本，
            // 拿它当输出会把用户正文全变成小写。
            $out = $text;
            $ordered = array_keys($hits);
            usort($ordered, static fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
            foreach ($ordered as $word) {
                $out = str_ireplace($word, $hits[$word]['replacement'], $out);
            }
            $result['text'] = $out;
        }
        // review：文本原样返回，由调用方把内容置为待审

        self::log($userId, $scene, $targetId, $topLevel, $result['words'], $text);
        self::bumpHits($result['words']);

        return $result;
    }

    /**
     * 便捷方法：过滤并把 block 转成异常抛出
     *
     * @throws HublogException
     * @return array 同 filter()
     */
    public static function guard(string $text, string $scene = 'post', int $userId = 0, int $targetId = 0): array
    {
        $r = self::filter($text, $scene, $userId, $targetId);
        if (!$r['ok']) {
            throw new HublogException($r['msg'], 403);
        }
        return $r;
    }

    /** 写命中日志（失败只记 error_log，不影响主流程） */
    public static function log(int $userId, string $scene, int $targetId, string $action, array $words, string $original): void
    {
        try {
            Database::insert('content_filter_logs', [
                'user_id'    => $userId,
                'scene'      => $scene,
                'target_id'  => $targetId,
                'action'     => $action,
                'words'      => mb_substr(implode(',', $words), 0, 500, 'UTF-8'),
                'excerpt'    => mb_substr($original, 0, 500, 'UTF-8'),
                'created_at' => Helper::now(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('[Moderation log] ' . $e->getMessage());
        }
    }

    /** 词命中次数累加（用于后台看哪些词最常触发） */
    private static function bumpHits(array $words): void
    {
        if (!$words) {
            return;
        }
        try {
            foreach ($words as $w) {
                Database::query(
                    "UPDATE sensitive_words SET hit_count = hit_count + 1 WHERE word = ? LIMIT 1",
                    [$w]
                );
            }
        } catch (\Throwable $e) {
            Logger::error('[Moderation bumpHits] ' . $e->getMessage());
        }
    }

    // ==================== 违规处理链路 ====================

    /**
     * 用户是否处于全站禁言中
     *
     * @param array|int $user 含 muted_until 的用户数组，或用户ID（会自动查库）
     */
    public static function isMuted($user): bool
    {
        $until = null;
        if (is_array($user)) {
            $until = $user['muted_until'] ?? null;
        } elseif (is_numeric($user)) {
            try {
                $until = Database::fetchColumn("SELECT muted_until FROM users WHERE id = ? LIMIT 1", [(int)$user]);
            } catch (\Throwable $e) {
                // 列还没迁出来 → 视为未禁言，不能因为新列缺失把所有人都挡住
                Logger::error('[Moderation isMuted] ' . $e->getMessage());
                return false;
            }
        }
        if (!$until) {
            return false;
        }
        return strtotime((string)$until) > time();
    }

    /**
     * 断言用户未被禁言，否则抛 403
     *
     * @throws HublogException
     */
    public static function assertNotMuted($user): void
    {
        if (self::isMuted($user)) {
            $until = is_array($user) ? (string)($user['muted_until'] ?? '') : '';
            $tip   = $until ? '（解封时间：' . date('Y-m-d H:i', strtotime($until)) . '）' : '';
            throw new HublogException('你已被禁言，暂时无法发布内容' . $tip, 403);
        }
    }

    /** 圈子是否被限流（限流=可浏览，不可发帖/评论） */
    public static function circleLimited(?array $circle): bool
    {
        return $circle !== null && (int)($circle['limited'] ?? 0) === 1;
    }

    /** 圈子是否已下架 */
    public static function circleRemoved(?array $circle): bool
    {
        return $circle !== null && !empty($circle['removed_at']);
    }

    /**
     * 断言圈子可写入（未被限流 / 未下架）
     *
     * @throws HublogException
     */
    public static function assertCircleWritable(?array $circle): void
    {
        if ($circle === null) {
            return; // 圈子不存在由调用方自己校验，这里不越权报错
        }
        if (self::circleRemoved($circle)) {
            throw new HublogException('该圈子已下架，无法发布内容', 403);
        }
        if (self::circleLimited($circle)) {
            throw new HublogException('该圈子已被限制发言，暂时无法发布内容', 403);
        }
    }
}
