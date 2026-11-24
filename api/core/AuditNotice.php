<?php
/**
 * 审核结果站内信
 *
 * 命中审核规则时，必须告诉作者「命中了哪个词」，否则用户只看到动态不见了，
 * 根本不知道要改什么 —— 这是审核链路最常见的断点。
 *
 * 两条审核路径共用这里的实现：
 *   1) plugins/ContentAudit —— 内置词表 DFA + AI 语义审核（post_created / post_updated 钩子）
 *   2) api/core/Moderation  —— 后台敏感词库（replace / block / review 分级）
 *
 * 同一对象只保留一条审核通知：反复改稿重审时不让站内信刷屏；
 * 命中词变了就并进原通知（而不是覆盖），避免两条路径命中不同词时互相把对方的信息冲掉。
 */

namespace Hublog\Core;

class AuditNotice
{
    /**
     * 构造审核提示文案
     *
     * @param string $label '动态' / '评论'
     * @param array  $words 命中的词（可为空：AI 语义判定说不出具体是哪个字）
     */
    public static function buildMessage(string $label, array $words): string
    {
        $words = self::cleanWords($words);
        if (!empty($words)) {
            return '你的' . $label . '因命中敏感词「' . implode('、', $words)
                . '」已转入人工审核，暂不在信息流公开展示。请修改相关表述后重新发布。';
        }
        // 语义判定判的是整体语义，说不出具体是哪个字，
        // 这里不编造词名，只说明结果，避免误导用户去找一个不存在的词。
        return '你的' . $label . '经内容审核判定为违规，已转入人工审核，'
            . '暂不在信息流公开展示。请检查内容表述后重新发布。';
    }

    /**
     * 给作者发一条审核提示站内信（已存在则合并命中词后更新，不重复插入）
     *
     * @param int    $targetId 动态 ID / 评论 ID
     * @param string $type     'post' / 'comment'
     * @param array  $words    本次命中的词
     */
    public static function notify(int $targetId, string $type, array $words): void
    {
        try {
            $table = $type === 'post' ? 'posts' : 'comments';
            $row = Database::fetchOne(
                "SELECT user_id FROM {$table} WHERE id = ? LIMIT 1",
                [$targetId]
            );
            $authorId = (int)($row['user_id'] ?? 0);
            if ($authorId <= 0) {
                return;
            }

            // 发件人用系统虚拟账号（system_notice），与徽章通知保持一致。
            // 不能填作者本人：notifications.sender_id 有外键约束，但语义上
            // 审核通知是「平台发的」，作者本人发给自己会让数据层含义错乱。
            $senderId = Helper::getSystemUserId();
            if ($senderId <= 0) {
                $senderId = 1; // 兜底：install.php 固定插入的管理员 id=1
            }

            $label = $type === 'post' ? '动态' : '评论';
            $existingId = 0;
            $existingContent = '';
            if ($type === 'post') {
                $existing = Database::fetchOne(
                    "SELECT id, content FROM notifications
                     WHERE user_id = ? AND type = 'system' AND post_id = ? AND comment_id IS NULL
                     ORDER BY id DESC LIMIT 1",
                    [$authorId, $targetId]
                );
            } else {
                $existing = Database::fetchOne(
                    "SELECT id, content FROM notifications
                     WHERE user_id = ? AND type = 'system' AND comment_id = ?
                     ORDER BY id DESC LIMIT 1",
                    [$authorId, $targetId]
                );
            }
            if ($existing) {
                $existingId = (int)$existing['id'];
                $existingContent = (string)($existing['content'] ?? '');
            }

            // 合并历史命中词：两条审核路径命中不同词时，通知里要都列出来
            $mergedWords = array_merge(
                self::extractWords($existingContent),
                self::cleanWords($words)
            );
            $content = self::buildMessage($label, $mergedWords);

            if ($existingId > 0) {
                Database::update('notifications', [
                    'content'    => $content,
                    'is_read'    => 0,
                    'created_at' => Helper::now(),
                ], 'id = ?', [$existingId]);
            } else {
                Database::insert('notifications', [
                    'user_id'    => $authorId,
                    'sender_id'  => $senderId,
                    'type'       => 'system',
                    'post_id'    => $type === 'post' ? $targetId : null,
                    'comment_id' => $type === 'comment' ? $targetId : null,
                    'content'    => $content,
                    'is_read'    => 0,
                    'created_at' => Helper::now(),
                ]);
            }

            try {
                SsePush::push($authorId, 'notification', [
                    'type'       => 'system',
                    'post_id'    => $type === 'post' ? $targetId : null,
                    'comment_id' => $type === 'comment' ? $targetId : null,
                    'content'    => $content,
                ]);
            } catch (\Throwable $_) {
                // 实时推送失败不影响落库
            }
        } catch (\Throwable $e) {
            Logger::warning('审核通知发送失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 审核结果通知（圈内发帖审核的通过/拒绝）。
     *
     * 与 notify() 分开：notify() 会把命中词合并进同一条「敏感词待审」文案，
     * 圈内审核的结论是另一件事，复用同一条通知会把两种语义揉在一起。
     */
    public static function notifyReviewResult(int $targetId, string $type, string $content): void
    {
        try {
            $table = $type === 'post' ? 'posts' : 'comments';
            $row = Database::fetchOne(
                "SELECT user_id FROM {$table} WHERE id = ? LIMIT 1",
                [$targetId]
            );
            $authorId = (int)($row['user_id'] ?? 0);
            if ($authorId <= 0) {
                return;
            }

            $senderId = Helper::getSystemUserId();
            if ($senderId <= 0) {
                $senderId = 1;
            }

            Database::insert('notifications', [
                'user_id'    => $authorId,
                'sender_id'  => $senderId,
                'type'       => 'system',
                'post_id'    => $type === 'post' ? $targetId : null,
                'comment_id' => $type === 'comment' ? $targetId : null,
                'content'    => $content,
                'is_read'    => 0,
                'created_at' => Helper::now(),
            ]);

            try {
                SsePush::push($authorId, 'notification', [
                    'type'       => 'system',
                    'post_id'    => $type === 'post' ? $targetId : null,
                    'comment_id' => $type === 'comment' ? $targetId : null,
                    'content'    => $content,
                ]);
            } catch (\Throwable $_) {
                // 实时推送失败不影响落库
            }
        } catch (\Throwable $e) {
            Logger::warning('审核结果通知发送失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 清洗词数组：去空白、去重、去空串
     */
    private static function cleanWords(array $words): array
    {
        $out = [];
        foreach ($words as $w) {
            $w = trim((string)$w);
            if ($w !== '') {
                $out[] = $w;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * 从已有通知文案里回捞此前通知过的命中词（「词1、词2」）
     */
    private static function extractWords(string $content): array
    {
        if ($content === '' || !preg_match('/「(.+?)」/u', $content, $m)) {
            return [];
        }
        return self::cleanWords(explode('、', $m[1]));
    }
}
