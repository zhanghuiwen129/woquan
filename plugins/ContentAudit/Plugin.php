<?php
/**
 * Hublog 智能内容审核插件
 * 双层审核机制：本地敏感词（DFA 算法）+ AI 语义判定
 * 命中后自动将帖子/评论置为待审核状态
 *
 * @name 智能内容审核
 * @description 双层审核：本地 DFA 敏感词匹配 + AI 语义判定，命中自动置为待审核
 * @version 1.0.0
 * @author woquan
 *
 * 触发流程：
 *   1. PostController::create() / CommentController::create() 触发钩子
 *   2. 本插件优先级 5 先于其他插件执行审核
 *   3. 第一层：DFA 算法检测本地敏感词 + 后台自定义词
 *   4. 第二层：若开启 AI 审核，调用 AiService 判定语义违规
 *   5. 命中则 status 置为 0，记录日志
 */

namespace Hublog\Plugin\ContentAudit;

use Hublog\Core\Hook;
use Hublog\Core\AiService;
use Hublog\Core\Database;
use Hublog\Core\Logger;
use Hublog\Core\Cache;
use Hublog\Core\AuditNotice;
use Hublog\Core\Moderation;
// 注意：本文件自身声明的 class 就叫 Plugin，直接 use Hublog\Core\Plugin 会
// 「类名重复」冲突，必须起别名。
use Hublog\Core\Plugin as PluginManager;

class Plugin
{
    private const BUILTIN_SENSITIVE_WORDS = [
        '色情', '赌博', '毒品', '暴力', '血腥', '诈骗', '传销',
        '反动', '分裂', '恐怖', '极端', '仇恨', '歧视', '侮辱',
        '违法', '违规', '作弊', '刷单', '黑产', '违禁', '走私',
        '卖淫', '嫖娼', '淫秽', '情色', '艳照', '裸聊',
        '骂人', '脏话', '白痴', '废物', '垃圾', '混蛋', '王八',
        '广告', '推广', '加我', '私聊', 'wx', 'VX', '出售', '代购',
    ];

    /**
     * DFA 自动机根节点
     */
    private static array $dfaRoot = [];

    /**
     * 插件入口：注册钩子监听
     */
    public function init(): void
    {
        Hook::addAction('post_created', [self::class, 'onPostCreated'], 5);
        Hook::addAction('post_commented', [self::class, 'onPostCommented'], 5);
        // V169：编辑后重审。只对「因审核转待审」的动态生效（audit_state=1），
        // 普通动态编辑不重新审核，保持原有行为不变。
        Hook::addAction('post_updated', [self::class, 'onPostUpdated'], 5);
    }

    /**
     * post_updated 钩子回调：作者编辑动态后重新送审。
     *
     * 「编辑即重新提交」——所有动态都要重审，包括之前已审核通过的。
     * 只审 audit_state=1 的话，用户改一次通过后 audit_state 变成 2，
     * 再编辑就能完全绕过审核（这是实测抓到的漏洞）。
     *
     * 例外：管理员已下架的动态（status=0 且 audit_state=0 且非定时）不参与重审，
     * 否则下架内容会被「洗」成待审态，作者又能看到并编辑，绕过管理员的决定。
     */
    public static function onPostUpdated(int $postId, int $userId): void
    {
        try {
            $row = Database::fetchOne(
                "SELECT status, audit_state, scheduled_at FROM posts WHERE id = ? LIMIT 1",
                [$postId]
            );
            if (!$row) {
                return;
            }
            if ((int)$row['status'] === 0
                && (int)($row['audit_state'] ?? 0) === 0
                && empty($row['scheduled_at'])) {
                return; // 管理员下架中
            }
            self::auditContent($postId, 'post');
        } catch (\Throwable $e) {
            Logger::error('内容重审异常', [
                'post_id' => $postId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * post_created 钩子回调
     */
    public static function onPostCreated(int $postId, int $userId): void
    {
        self::auditContent($postId, 'post');
    }

    /**
     * post_commented 钩子回调
     *
     * 【参数错位修复 P0-2】钩子 doAction('post_commented', $postId, $userId, $commentId)
     * 实际传三个参数，且第一个是 post_id。旧签名为 (int $commentId, int $userId)，
     * 把 post_id 当成 commentId 去 comments 表查内容 —— 评论审核实际一直失效，
     * 且多传的第三个参数 $commentId 被静默丢弃。
     * 现与钩子真实传参对齐（同 SmartRecommend::onPostCommented 的签名），
     * 并用真正的 $commentId 审核评论内容。
     */
    public static function onPostCommented(int $postId, int $userId, int $commentId): void
    {
        self::auditContent($commentId, 'comment');
    }

    /**
     * 执行审核流程
     *
     * 【P1-2：AI 层改异步】
     * 三层审核的耗时结构完全不同：
     *   第一层（本地 DFA 敏感词）—— 纯内存匹配，微秒级；
     *   第二层（后台词库）—— 一次 DB 查询，毫秒级；
     *   第三层（AI 语义判定）—— 一次外部大模型调用，最坏 10 秒。
     *
     * 原实现三层串行同步跑，于是「发帖接口的 P95」被第三层的 10 秒完全绑架：
     * 用户点发布后要干等 10 秒，PHP-FPM worker 也被这 10 秒占住，
     * 站点在线数一高就出现排队甚至 504。
     *
     * 现在改成：
     *   第一层 + 第二层仍同步跑（毫秒级，且是本地可判定的硬命中，
     *     必须在下发响应前就拦住，不能让违规内容先公开再回收）；
     *   第三层 AI 判定走 Plugin::defer() —— 响应发出后在同进程执行，
     *     命中则照旧把内容置为待审并发站内信。
     *
     * 【为什么第三层故意用 defer，而不是 jobs 队列】
     * 第三层是安全审核（语义违规：色情/暴力/诈骗/违法/广告引流/侮辱歧视等，
     * 词表难以穷举）。安全审核绝不能依赖「站点是否配置了 cron」这一可选条件：
     * 若把任务入 jobs 表而站点没配 /api/cron/jobs，任务会永远 pending，
     * 违规内容从「先可见后下架」变成「永久可见」，且无任何告警。
     * 因此这里坚持 Plugin::defer() —— 同进程必然执行，不依赖 cron。
     * 代价是 AI 调用仍占着发帖/评论请求的 PHP-FPM worker（最坏 10 秒）；
     * 这是安全底线，接受它。第一/二层同步拦截不变，覆盖了绝大多数明确违规场景。
     *
     * 【需要注意的时序变化】
     * 异步化后，AI 判定为违规的内容会「先可见后下架」（窗口约 10 秒），
     * 而不是原实现的「发布瞬间就判定」。本地词库与后台词库仍然同步拦截，
     * 覆盖了绝大多数明确违规场景；AI 层负责的是语义类、词表难穷举的场景。
     * 该行为变化已在报告 P1-2 中记录。
     */
    private static function auditContent(int $id, string $type): void
    {
        try {
            $content = self::getContent($id, $type);
            if ($content === '' || $content === null) {
                return;
            }

            $plainText = trim(strip_tags($content));
            if ($plainText === '') {
                return;
            }

            // 第一层：本地敏感词检测（返回全部命中词）
            $hitWords = self::detectSensitiveWords($plainText);
            if (!empty($hitWords)) {
                self::markAsPending($id, $type, 'local_sensitive_word', implode('、', $hitWords));
                return;
            }

            // 第二层：后台敏感词库（api/core/Moderation 的 replace / review / block）
            // 内置词表和后台词库是两套独立规则，用户改词时可能只躲开其中一套，
            // 重审时两套都要跑，否则换个同义词就能绕过其中一层。
            $moderationHit = self::checkModerationWords($id, $type, $plainText);
            if ($moderationHit !== null) {
                self::markAsPending($id, $type, 'moderation_review', $moderationHit);
                return;
            }

            // 第三层：AI 审核 —— 异步执行，不阻塞发帖响应。
            $cfg = self::getAuditConfig();
            if (($cfg['ai_audit_enabled'] ?? '0') !== '1') {
                // AI 审核未开启：两层本地判定都没命中，直接放行。
                self::markAsPassed($id, $type);
                return;
            }

            // 第三层 AI 判定：故意走 Plugin::defer 同进程执行，不依赖 cron。
            // 安全审核不能因为站点没配 /api/cron/jobs 就静默失效（违规内容永久可见）。
            // 只传 id/type，执行时重新读内容与配置，避免用过期快照判定。
            PluginManager::defer(static function () use ($id, $type): void {
                try {
                    self::runFromPayload(['id' => $id, 'type' => $type]);
                } catch (\Throwable $e) {
                    Logger::error('AI 审核异常', [
                        'type' => $type,
                        'id' => $id,
                        'error' => $e->getMessage()
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Logger::error('内容审核异常', [
                'type' => $type,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 第三层 AI 判定的实际执行入口（只跑 AI 语义判定）。
     * 由 auditContent() 的 Plugin::defer 回调直接调用；AsyncTask 白名单
     * 仅为兼容历史已入队的 content_audit 任务而保留。
     *
     * @param array $payload ['id'=>int, 'type'=>'post'|'comment']
     * @return bool 是否处理成功（false 会让队列任务标记 failed，便于运维发现）
     */
    public static function runFromPayload(array $payload): bool
    {
        $id = (int)($payload['id'] ?? 0);
        $type = (string)($payload['type'] ?? '');
        if ($id <= 0 || !in_array($type, ['post', 'comment'], true)) {
            Logger::error('AI 审核任务参数非法', ['type' => $type, 'id' => $id]);
            return false;
        }

        try {
            $content = self::getContent($id, $type);
            if ($content === null || $content === '') {
                return true; // 内容已删除，无需审核
            }
            $text = trim(strip_tags($content));
            if ($text === '') {
                return true;
            }

            $cfg = self::getAuditConfig();
            if (($cfg['ai_audit_enabled'] ?? '0') !== '1') {
                return true; // 配置已关闭，无需审核
            }

            $aiHit = self::aiAudit($id, $type, $text, $cfg);
            if (!$aiHit) {
                self::markAsPassed($id, $type);
            }
            return true;
        } catch (\Throwable $e) {
            Logger::error('AI 审核异常', [
                'type' => $type,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 获取待审核内容
     */
    private static function getContent(int $id, string $type): ?string
    {
        if ($type === 'post') {
            $row = Database::fetchOne(
                "SELECT content FROM posts WHERE id = ? LIMIT 1",
                [$id]
            );
            return $row['content'] ?? null;
        }
        $row = Database::fetchOne(
            "SELECT content FROM comments WHERE id = ? LIMIT 1",
            [$id]
        );
        return $row['content'] ?? null;
    }

    /**
     * 获取审核配置
     */
    private static function getAuditConfig(): array
    {
        return Cache::remember('content_audit_settings', function () {
            $rows = Database::fetchAll(
                "SELECT `key`, `value` FROM settings WHERE `key` LIKE 'ai_audit_%'"
            );
            $cfg = [];
            foreach ($rows as $row) {
                $cfg[$row['key']] = $row['value'];
            }
            return $cfg;
        }, 3600);
    }

    /**
     * 构建 DFA 树
     */
    private static function buildDfa(): void
    {
        if (!empty(self::$dfaRoot)) {
            return;
        }

        $words = self::BUILTIN_SENSITIVE_WORDS;

        // 加载自定义敏感词
        try {
            $custom = Cache::remember('content_audit_custom_words', function () {
                $row = Database::fetchColumn(
                    "SELECT `value` FROM settings WHERE `key` = 'ai_audit_custom_words' LIMIT 1"
                );
                if ($row) {
                    $arr = array_filter(array_map('trim', explode(',', $row)));
                    return $arr;
                }
                return [];
            }, 3600);
            $words = array_merge($words, $custom);
        } catch (\Throwable $e) {
            Logger::warning('加载自定义敏感词失败', ['error' => $e->getMessage()]);
        }

        // 构建 DFA 树
        self::$dfaRoot = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }
            $node = &self::$dfaRoot;
            $len = mb_strlen($word, 'UTF-8');
            for ($i = 0; $i < $len; $i++) {
                $char = mb_substr($word, $i, 1, 'UTF-8');
                if (!isset($node[$char])) {
                    $node[$char] = [];
                }
                $node = &$node[$char];
            }
            $node['\x00'] = true;
        }
    }

    /**
     * DFA 算法检测敏感词
     *
     * 返回**全部**命中词（去重），而不是第一个。
     * 用户要的是「到底哪几个词要改」，只报一个会让人改完发现又中招。
     * 同一位置取最长匹配（"垃圾话" 优先于 "垃圾"），匹配后跳过已覆盖区间。
     *
     * @return string[] 命中的词列表
     */
    private static function detectSensitiveWords(string $text): array
    {
        self::buildDfa();

        $found = [];
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $node = self::$dfaRoot;
            $matched = '';
            $best = '';
            for ($j = $i; $j < $len; $j++) {
                $char = mb_substr($text, $j, 1, 'UTF-8');
                if (!isset($node[$char])) {
                    break;
                }
                $matched .= $char;
                $node = $node[$char];
                if (isset($node['\x00'])) {
                    $best = $matched; // 继续延伸，取更长的匹配
                }
            }
            if ($best !== '') {
                $found[$best] = true;
                $i += mb_strlen($best, 'UTF-8') - 1; // 跳过已匹配区间，避免重复计数
            }
        }
        return array_keys($found);
    }

    /**
     * 第二层：后台敏感词库（api/core/Moderation）
     *
     * replace 只是打码，不算违规；review（转人工审核）与 block（直接拦截）都要拦。
     *
     * @return string|null 命中的词（顿号分隔）；未命中返回 null
     */
    private static function checkModerationWords(int $id, string $type, string $text): ?string
    {
        try {
            $scene = $type === 'post' ? 'post' : 'comment';
            $table = $type === 'post' ? 'posts' : 'comments';
            $authorId = (int)Database::fetchColumn(
                "SELECT user_id FROM {$table} WHERE id = ? LIMIT 1",
                [$id]
            );
            $r = Moderation::filter($text, $scene, $authorId, $id);
            if (in_array($r['action'], ['review', 'block'], true) && !empty($r['words'])) {
                return implode('、', $r['words']);
            }
            return null;
        } catch (\Throwable $e) {
            Logger::warning('后台词库审核异常', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * AI 审核
     *
     * @return bool 是否判定为违规（命中并已转待审）
     */
    private static function aiAudit(int $id, string $type, string $text, array $cfg): bool
    {
        $apiUrl = $cfg['ai_audit_api_url'] ?? '';
        $apiKey = $cfg['ai_audit_api_key'] ?? '';
        if ($apiUrl === '') {
            return false;
        }
        $isLocal = str_contains($apiUrl, 'localhost')
                || str_contains($apiUrl, '127.0.0.1')
                || str_contains($apiUrl, 'ollama');
        if (!$isLocal && $apiKey === '') {
            return false;
        }

        $systemPrompt = '你是一个内容审核助手。请判断以下内容是否包含违规信息（包括但不限于：色情、暴力、诈骗、违法、广告引流、侮辱歧视等）。只需回答"合规"或"违规"，不需要其他解释。';
        $userMessage = '待审核内容：' . mb_substr($text, 0, 2000);

        $reply = AiService::chatCompletion($systemPrompt, $userMessage, [
            'api_url' => $apiUrl,
            'api_key' => $apiKey,
            'model' => $cfg['ai_audit_model'] ?? 'deepseek-chat',
            'timeout' => 10,
            'max_tokens' => 10,
            'temperature' => 0.1
        ]);

        if ($reply === null) {
            return false;
        }

        $reply = trim($reply);
        // P2-3：必须是「判定词」整体匹配。原 str_contains('违规') 会把模型返回的
        // "不违规" 也判成违规，合规内容被误送审。结构化输出更稳，这里先做最小精确化。
        if (preg_match('/^\s*(违规|violation|non-compliant)\s*$/iu', $reply) === 1) {
            self::markAsPending($id, $type, 'ai_audit', $reply);
            return true;
        }
        return false;
    }

    /** 行为测试用 SQLite 与 MySQL 的标量极值函数不同， detect 一次即可 */
    private static function sqliteDriver(): bool
    {
        return Database::getInstance()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    /**
     * 标记为待审核
     */
    private static function markAsPending(int $id, string $type, string $reason, string $detail): void
    {
        $table = $type === 'post' ? 'posts' : 'comments';
        $postId = $type === 'post' ? $id : 0;
        try {
            if ($type === 'comment') {
                $row = Database::fetchOne("SELECT post_id FROM comments WHERE id = ? LIMIT 1", [$id]);
                $postId = (int)($row['post_id'] ?? 0);
            }
        } catch (\Throwable $e) {
            Logger::error('查询评论所属动态失败', ['id' => $id, 'error' => $e->getMessage()]);
        }

        try {
            // V100：comments 表已有 status/audit_state 两列，post/comment 共用同一状态语义。
            $update = ['status' => 0, 'audit_state' => 1];
            if ($type === 'post') {
                // 内容有问题就不再按原计划定时自动发布（cron 也会跳过 audit_state=1，双保险）
                $update['scheduled_at'] = null;
            }

            // 评论转待审 = 从公开计数中摘除，与状态更新放同一事务，避免计数与可见性漂移。
            Database::beginTransaction();
            try {
                Database::update($table, $update, 'id = ?', [$id]);
                if ($type === 'comment') {
                    // MySQL 用 GREATEST；SQLite（行为测试）只有标量 MAX，语义一致。
                    $decExpr = self::sqliteDriver()
                        ? 'MAX(comment_count - 1, 0)'
                        : 'GREATEST(comment_count - 1, 0)';
                    Database::query(
                        "UPDATE posts SET comment_count = {$decExpr} WHERE id = ?",
                        [$postId]
                    );
                }
                Database::commit();
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }

            // P0-11：timeline 现役键是 timeline_total_v{版本号}_*，靠命名空间升版失效。
            Cache::bumpNsVersion('timeline');
            Cache::bumpNsVersion('popular');
            Cache::deleteByPrefix('post_');
            Cache::deleteByPrefix('user_profile_');
            if ($postId > 0) {
                Cache::delete("post_comments_raw_{$postId}");
                Cache::delete("post_comments_{$postId}");
            }

            Logger::info('内容审核命中', [
                'type' => $type,
                'id' => $id,
                'reason' => $reason,
                'detail' => $detail
            ]);
        } catch (\Throwable $e) {
            Logger::error('标记待审核失败', [
                'type' => $type,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
        }

        // 通知必须在主 try 之外：DB 异常被上面的 catch 兜住后，作者仍要知道
        // 自己哪句话命中了规则，否则「内容消失且无提示」比违规本身更糟。
        self::notifyAuthor($id, $type, $reason, $detail);
    }

    /**
     * 审核通过：把因审核被拦下的动态恢复公开。
     *
     * 只有 audit_state=1（此前确实被审核拦下）的动态才需要动；
     * 普通动态本来就 status=1，不做任何写入，避免无谓的 UPDATE。
     * 走的是「先保持不可见 → 审核通过再放出」的顺序，中途没有公开窗口。
     */
    private static function markAsPassed(int $id, string $type): void
    {
        $table = $type === 'post' ? 'posts' : 'comments';
        $postId = $type === 'post' ? $id : 0;
        try {
            $row = Database::fetchOne(
                "SELECT status, audit_state" . ($type === 'comment' ? ', post_id' : '')
                . " FROM {$table} WHERE id = ? LIMIT 1",
                [$id]
            );
            if (!$row || (int)($row['audit_state'] ?? 0) !== 1) {
                return;
            }
            if ($type === 'comment') {
                $postId = (int)($row['post_id'] ?? 0);
            }

            Database::beginTransaction();
            try {
                Database::update($table, ['status' => 1, 'audit_state' => 2], 'id = ?', [$id]);
                if ($type === 'comment') {
                    // MySQL 用 LEAST；SQLite（行为测试）只有标量 MIN，语义一致。
                    $incExpr = self::sqliteDriver()
                        ? 'MIN(comment_count + 1, 4294967295)'
                        : 'LEAST(comment_count + 1, 4294967295)';
                    Database::query(
                        "UPDATE posts SET comment_count = {$incExpr} WHERE id = ?",
                        [$postId]
                    );
                }
                Database::commit();
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }

            Cache::bumpNsVersion('timeline');
            Cache::bumpNsVersion('popular');
            Cache::deleteByPrefix('post_');
            Cache::deleteByPrefix('user_profile_');
            if ($postId > 0) {
                Cache::delete("post_comments_raw_{$postId}");
                Cache::delete("post_comments_{$postId}");
            }
            Logger::info('内容重审通过，已恢复公开', ['type' => $type, 'id' => $id]);
        } catch (\Throwable $e) {
            Logger::error('重审放行失败', [
                'type' => $type,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 内容被转人工审核时，给作者发一条系统站内信，点名命中词，方便其修改后重新发布。
     * 通知失败只记日志，不影响审核标记主流程。
     */
    private static function notifyAuthor(int $id, string $type, string $reason, string $detail): void
    {
        // 只有本地词表命中才能点名具体是哪个词；AI 语义判定说不出词名，
        // 传空数组让文案走「判定为违规」的通用表述。
        $words = [];
        if ($reason === 'local_sensitive_word' && $detail !== '') {
            $words = array_values(array_filter(array_map('trim', explode('、', $detail))));
        }
        AuditNotice::notify($id, $type, $words);
    }
}