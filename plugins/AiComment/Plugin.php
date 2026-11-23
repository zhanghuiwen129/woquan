<?php
/**
 * Hublog AI 自动评论插件
 * 监听 post_created 钩子，在新动态发布后异步调用 AI 生成一条评论
 *
 * @name AI 自动评论
 * @description 新动态发布后异步调用 AI 自动生成一条评论，标记为 AI 评论
 * @version 1.0.0
 * @author woquan
 *
 * 触发流程：
 *   1. PostController::create() 调用 Hook::doAction('post_created', $postId, $userId)
 *   2. 本插件回调被触发
 *   3. 若启用 AI 评论且配置完整，把生成任务入队（AsyncTask::defer → jobs 表 plugin_task）
 *   4. cron worker（POST /api/cron/jobs）在独立请求里消费任务，调用
 *      AiService::chatCompletion 生成评论，真正释放发帖请求的 PHP-FPM worker。
 *      依赖站点配置该 cron：未配置则 AI 评论不会生成（功能静默失效，不报错）
 *   5. 写入 comments 表（is_ai = 1，user_id = ai_assistant）并把 posts.comment_count +1
 *   6. 清理相关缓存（post_ / post_comments_raw_ / timeline_v4_ 等，键名与 PostController 保持一致）
 */

namespace Hublog\Plugin\AiComment;

use Hublog\Core\Hook;
use Hublog\Core\AiService;
use Hublog\Core\Cache;
use Hublog\Core\Database;
use Hublog\Core\Helper;
use Hublog\Core\AsyncTask;
use Hublog\Core\Security;
use Hublog\Controllers\AdminSettingsController;

class Plugin
{
    /**
     * 插件入口：注册钩子监听
     */
    public function init(): void
    {
        // 优先级 20：让其他插件先处理（如审核），AI 评论稍后触发
        Hook::addAction('post_created', [self::class, 'onPostCreated'], 20);
    }

    /**
     * post_created 钩子回调
     */
    public static function onPostCreated(int $postId, int $userId): void
    {
        // 1. 校验是否启用
        $cfg = AiService::getConfig();
        if (($cfg['ai_comment_enabled'] ?? '0') !== '1') {
            return;
        }

        // 2. 校验 API 配置完整性（本地 Ollama 可免 API Key）
        if (empty($cfg['ai_comment_api_url'])) {
            return;
        }
        $isLocal = str_contains($cfg['ai_comment_api_url'], 'localhost')
                || str_contains($cfg['ai_comment_api_url'], '127.0.0.1')
                || str_contains($cfg['ai_comment_api_url'], 'ollama');
        if (!$isLocal && empty($cfg['ai_comment_api_key'])) {
            return;
        }

        // 3. 获取 AI 用户 ID
        $aiUserId = AiService::getAiUserId();
        if ($aiUserId <= 0) {
            error_log('[Hublog AI] AI 用户 ai_assistant 不存在，跳过'); // @allow-error-log
            return;
        }

        // 4. 单帖只允许一条 AI 评论（防止重复触发）
        $exists = Database::fetchColumn(
            "SELECT id FROM comments WHERE post_id = ? AND is_ai = 1 LIMIT 1",
            [$postId]
        );
        if ($exists) {
            return;
        }

        // 5. 入队为持久化异步任务，由 cron worker 在独立请求里执行，真正释放 worker。
        //    注意：这里绝不能直接 fastcgi_finish_request() —— 钩子是在 PostController
        //    内部触发的，此时响应体还没输出，提前 flush 会让发帖接口返回空 body。
        //    本任务依赖 /api/cron/jobs；未配置则 AI 评论静默不生成。
        //    （仅 jobs 表不可用时 AsyncTask 才回退 defer，不覆盖「没配 cron」。）
        // P1-2：jobs.payload 是明文落库且会被后台/日志读取，API Key 绝不能入队。
        // SENSITIVE_KEYS 是公开常量（AdminSettingsController），worker 侧现场取回密钥。
        $safeCfg = array_diff_key($cfg, array_flip(AdminSettingsController::SENSITIVE_KEYS));
        AsyncTask::defer(
            'ai_comment',
            ['post_id' => $postId, 'ai_user_id' => $aiUserId, 'cfg' => $safeCfg],
            'ai_comment_post_' . $postId
        );
    }

    /**
     * 异步队列适配入口：由 AsyncTask 白名单调用。
     *
     * @param array $payload ['post_id'=>int, 'ai_user_id'=>int, 'cfg'=>array]
     * @return bool 是否处理成功（false 会让任务标记 failed，便于运维发现）
     */
    public static function runFromPayload(array $payload): bool
    {
        $postId = (int)($payload['post_id'] ?? 0);
        $aiUserId = (int)($payload['ai_user_id'] ?? 0);
        $cfg = ($payload['cfg'] ?? null);
        if ($postId <= 0 || $aiUserId <= 0 || !is_array($cfg)) {
            error_log('[Hublog AI] 评论任务参数非法: post_id=' . $postId . ' ai_user_id=' . $aiUserId); // @allow-error-log
            return false;
        }
        // 入队前已剔除敏感键；worker 执行时从后台设置现场取回最新密钥。
        $cfg = array_merge($cfg, AiService::getConfig());

        try {
            self::generateAndSave($postId, $aiUserId, $cfg);
            return true;
        } catch (\Throwable $e) {
            error_log(sprintf('[Hublog AI] 生成评论失败 post_id=%d: %s', $postId, $e->getMessage())); // @allow-error-log
            return false;
        }
    }

    /**
     * 调用 AI 生成评论并写入数据库
     */
    public static function generateAndSave(int $postId, int $aiUserId, array $cfg): void
    {
        // 1. 读取动态内容
        $post = Database::fetchOne(
            "SELECT id, title, content, user_id FROM posts WHERE id = ? AND status = 1 LIMIT 1",
            [$postId]
        );
        if (!$post) {
            return;
        }

        // 不对自己发布的 AI 用户内容评论（避免无限循环）
        if ((int)$post['user_id'] === $aiUserId) {
            return;
        }

        // 2. 构造用户消息
        $plainContent = strip_tags($post['content']);
        $plainContent = trim(preg_replace('/\s+/', ' ', $plainContent));
        if ($plainContent === '') {
            return;
        }

        // 限制输入长度，避免 token 暴涨
        $plainContent = mb_substr($plainContent, 0, 1000);
        $titleHint = !empty($post['title']) ? "【标题】{$post['title']}\n" : '';
        $userMessage = $titleHint . '【动态内容】' . $plainContent;

        // 3. 调用 AI
        $systemPrompt = $cfg['ai_comment_prompt'] ?? '请对这条朋友圈动态发表一条 30-80 字的友好评论。';
        $maxLen = (int)($cfg['ai_comment_max_length'] ?? 200);
        $timeout = (int)($cfg['ai_comment_timeout'] ?? 15);

        $reply = AiService::chatCompletion($systemPrompt, $userMessage, [
            'api_url' => $cfg['ai_comment_api_url'],
            'api_key' => $cfg['ai_comment_api_key'],
            'model' => $cfg['ai_comment_model'] ?? 'deepseek-chat',
            'timeout' => $timeout,
            'max_tokens' => max(80, (int)ceil($maxLen * 1.5)),
            'temperature' => 0.85
        ]);

        if ($reply === null || trim($reply) === '') {
            return;
        }

        // 4. 截断到配置长度并净化
        $reply = mb_substr(trim($reply), 0, $maxLen);
        $reply = Security::purify($reply);
        if ($reply === '') {
            return;
        }

        // 5. 写入 comments 表
        //    【P1-8】评论写入与 posts.comment_count 计数必须同事务：
        //    原实现是两次独立调用（INSERT 后再 UPDATE），UPDATE 失败只 error_log。
        //    结果是「评论已存在但计数少 1」，列表页与详情页长期显示不一致，
        //    且没有任何补偿机制（计数器只会被后续正常评论 +1 拉回，
        //    而这条 AI 评论对应的帖子永远补不上那 1）。
        //    现在用事务包住两步：任一步失败则整体回滚，绝不留下半截状态。
        $commentId = 0;
        Database::beginTransaction();
        try {
            $commentId = Database::insert('comments', [
                'post_id' => $postId,
                'user_id' => $aiUserId,
                'parent_id' => null,
                'content' => $reply,
                'is_ai' => 1,
                'created_at' => Helper::now()
            ]);
            if (!$commentId) {
                Database::rollBack();
                return;
            }

            // 6. posts.comment_count +1。
            //    【为什么必须做】列表页与详情页的评论数都读 posts.comment_count 这个冗余列
            //    （PostFormatter::formatPostBatch 优先读冗余列），不是 count(comments)。
            //    AI 评论是自己直接 insert 的，不走 PostController::comment()，
            //    少这一步就会出现「详情里明明有 AI 评论，评论数却少 1」。
            //    与 PostController::comment() 里的写法保持一致（LEAST 防 tinyint/unsigned 溢出）。
            Database::query(
                "UPDATE posts SET comment_count = LEAST(comment_count + 1, 4294967295) WHERE id = ?",
                [$postId]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log('[Hublog AI] 写入评论/更新计数失败，已回滚 post_id=' . $postId . '：' . $e->getMessage()); // @allow-error-log
            return;
        }

        // 5.5【P0-2】让 AI 评论同样经过内容审核。
        //     AI 评论此前直接 insert comments，绕过了 post_commented 钩子，
        //     导致违规内容免审核进入站内。这里显式触发该钩子，ContentAudit
        //     会拿真正的 $commentId 审核评论内容（参数顺序与 PostCommentTrait 一致）。
        //     用 Hook 触发而非硬编码类名直调：插件未启用时无回调，天然兼容。
        //     本逻辑在 Plugin::defer 的延迟任务内执行（响应已发出），不阻塞主请求。
        //     放在事务提交之后：审核要改 comments.status，插在事务内会与
        //     审核方的 UPDATE 争同一条行锁。
        Hook::doAction('post_commented', $postId, $aiUserId, $commentId);

        // 7. 失效相关缓存（让前端刷新立即看到 AI 评论 + 更新后的评论数）
        //    【坑】键名必须与 PostController::comment() 里的清理清单一致：
        //      - 详情评论列表实际用的键是 post_comments_raw_{postId}（PostFormatter::formatPostSingle），
        //        旧代码只清 post_comments_{postId}（历史键名）→ 评论列表最多 10 分钟不刷新；
        //      - 时间轴走的是命名空间版本化键 timeline_v4_v{nsVersion}_*，
        //        清 timeline_v2_（早已废弃的历史前缀）等于没清 → 列表最多 1 小时
        //        看不到 AI 评论和正确的评论数。现在用 bumpNsVersion 升版，
        //        命名空间内的全部键一次性失效（PostFeedTrait 生成键时带了 nsVersion）。
        Cache::delete("post_comments_{$postId}");
        Cache::delete("post_comments_raw_{$postId}");
        Cache::delete("post_{$postId}");
        Cache::bumpNsVersion('timeline');
        Cache::bumpNsVersion('popular');
        Cache::deleteByPrefix('timeline_total_');
        Cache::deleteByPrefix('user_profile_');
        Cache::deleteByPrefix('popular_');
    }
}
