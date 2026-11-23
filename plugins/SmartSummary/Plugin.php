<?php
/**
 * Hublog 智能摘要与标签生成插件
 * 用户发帖后，调用 AI 生成 50 字摘要 + 3 个标签
 *
 * @name 智能摘要与标签
 * @description 发帖后调用 AI 自动生成 50 字摘要 + 3 个标签，提升内容可发现性
 * @version 1.0.0
 * @author woquan
 *
 * 触发流程：
 *   1. PostController::create() 触发 post_created 钩子
 *   2. 读取帖子内容，调用 AI 生成摘要和标签
 *   3. 摘要存入 posts.summary 字段
 *   4. 标签存入 post_tags 表
 */

namespace Hublog\Plugin\SmartSummary;

use Hublog\Core\Hook;
use Hublog\Core\AiService;
use Hublog\Core\Database;
use Hublog\Core\Logger;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\AsyncTask;
use Hublog\Controllers\AdminSettingsController;

class Plugin
{
    /**
     * 插件入口：注册钩子监听
     */
    public function init(): void
    {
        Hook::addAction('post_created', [self::class, 'onPostCreated'], 15);
    }

    /**
     * post_created 钩子回调
     *
     * 【P1-2：改异步】
     * 摘要与标签是纯附加信息（不影响内容可见性、不影响任何权限判定），
     * 而调用 AI 最坏要 15 秒。原实现同步跑在 post_created 钩子里，
     * 直接把「发帖接口的响应时间」推高到 15 秒（叠加 AI 审核的 10 秒 = 25 秒），
     * 期间 PHP-FPM worker 也被占住。
     *
     * 现在改为 AsyncTask::defer() 入队：请求侧只写一条 jobs 任务立刻返回，
     * 摘要由 cron worker 在独立进程生成。依赖站点配置 /api/cron/jobs；
     * 未配置则摘要/标签不会生成（功能静默失效，不报错）。
     * 摘要写入后同样要失效 posts 缓存，前端刷新即可看到。
     */
    public static function onPostCreated(int $postId, int $userId): void
    {
        try {
            $cfg = self::getSummaryConfig();
            if (($cfg['smart_summary_enabled'] ?? '0') !== '1') {
                return;
            }

            $apiUrl = $cfg['smart_summary_api_url'] ?? '';
            $apiKey = $cfg['smart_summary_api_key'] ?? '';
            if ($apiUrl === '') {
                return;
            }
            $isLocal = str_contains($apiUrl, 'localhost')
                    || str_contains($apiUrl, '127.0.0.1')
                    || str_contains($apiUrl, 'ollama');
            if (!$isLocal && $apiKey === '') {
                return;
            }

            // 内容读取也放进异步任务：绝大多数请求根本不会走到 AI 调用，
            // 不必为此多查一次库。
            // P1-2：API Key 不进 jobs.payload（明文落库），worker 侧现场取回。
            $safeCfg = array_diff_key($cfg, array_flip(AdminSettingsController::SENSITIVE_KEYS));
            AsyncTask::defer(
                'smart_summary',
                ['post_id' => $postId, 'cfg' => $safeCfg],
                'smart_summary_post_' . $postId
            );
        } catch (\Throwable $e) {
            Logger::error('智能摘要入队异常', [
                'post_id' => $postId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 异步队列适配入口：由 AsyncTask 白名单调用。
     *
     * @param array $payload ['post_id'=>int, 'cfg'=>array]
     * @return bool 是否处理成功（false 会让任务标记 failed，便于运维发现）
     */
    public static function runFromPayload(array $payload): bool
    {
        $postId = (int)($payload['post_id'] ?? 0);
        $cfg = ($payload['cfg'] ?? null);
        if ($postId <= 0 || !is_array($cfg)) {
            Logger::error('智能摘要任务参数非法', ['post_id' => $postId]);
            return false;
        }
        // 入队前已剔除敏感键；worker 执行时从后台设置现场取回最新密钥。
        $cfg = array_merge($cfg, AiService::getConfig());

        try {
            $post = Database::fetchOne(
                "SELECT id, title, content FROM posts WHERE id = ? AND status = 1 LIMIT 1",
                [$postId]
            );
            if (!$post) {
                return true; // 帖子已删除/下架，无需生成
            }

            $plainContent = strip_tags((string)$post['content']);
            $plainContent = trim((string)preg_replace('/\s+/', ' ', $plainContent));
            if ($plainContent === '') {
                return true;
            }

            self::generateSummaryAndTags($postId, $post, $plainContent, $cfg);
            return true;
        } catch (\Throwable $e) {
            Logger::error('智能摘要生成异常', [
                'post_id' => $postId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 获取摘要配置
     */
    private static function getSummaryConfig(): array
    {
        return Cache::remember('smart_summary_settings', function () {
            $rows = Database::fetchAll(
                "SELECT `key`, `value` FROM settings WHERE `key` LIKE 'smart_summary_%'"
            );
            $cfg = [];
            foreach ($rows as $row) {
                $cfg[$row['key']] = $row['value'];
            }
            return $cfg;
        }, 3600);
    }

    /**
     * 调用 AI 生成摘要和标签
     */
    private static function generateSummaryAndTags(int $postId, array $post, string $content, array $cfg): void
    {
        $titleHint = !empty($post['title']) ? "标题：{$post['title']}\n" : '';
        $truncated = mb_substr($content, 0, 1500);

        $systemPrompt = '你是一个内容摘要和标签生成助手。请为给定内容生成：
1. 一段不超过50字的摘要，准确概括核心内容
2. 3个简短的标签（每个不超过6个字），用于描述内容主题

严格按照以下JSON格式输出，不要添加其他文字：
{"summary": "摘要内容", "tags": ["标签1", "标签2", "标签3"]}';

        $userMessage = $titleHint . "内容：" . $truncated;

        $reply = AiService::chatCompletion($systemPrompt, $userMessage, [
            'api_url' => $cfg['smart_summary_api_url'],
            'api_key' => $cfg['smart_summary_api_key'],
            'model' => $cfg['smart_summary_model'] ?? 'deepseek-chat',
            'timeout' => 15,
            'max_tokens' => 200,
            'temperature' => 0.7
        ]);

        if ($reply === null || trim($reply) === '') {
            return;
        }

        $result = self::parseAiResponse($reply);
        if ($result === null) {
            Logger::warning('AI 摘要响应解析失败', ['post_id' => $postId, 'raw' => $reply]);
            return;
        }

        // 更新摘要到 posts 表
        $summary = mb_substr($result['summary'], 0, 300);
        Database::update('posts', ['summary' => $summary], 'id = ?', [$postId]);

        // 写入标签
        if (!empty($result['tags'])) {
            foreach ($result['tags'] as $tag) {
                $tag = trim($tag);
                if ($tag === '') {
                    continue;
                }
                Database::insert('post_tags', [
                    'post_id' => $postId,
                    'tag' => mb_substr($tag, 0, 30),
                    'created_at' => Helper::now()
                ]);
            }
        }

        // 清理相关缓存
        // 【P0-3 同类修复】原先清的是 timeline_v2_ —— 那是早已废弃的历史前缀，
        // 现役时间轴键是 timeline_v4_v{nsVersion}_*（PostFeedTrait:74）。
        // 清废弃前缀等于没清 → 摘要写完后信息流最长 1 小时仍显示旧摘要。
        // 现在用命名空间升版一次性失效。
        Cache::delete("post_{$postId}");
        Cache::bumpNsVersion('timeline');
        Cache::bumpNsVersion('popular');
        Cache::deleteByPrefix('user_profile_');

        Logger::info('智能摘要生成成功', [
            'post_id' => $postId,
            'summary' => $summary,
            'tags' => $result['tags']
        ]);
    }

    /**
     * 解析 AI 返回的 JSON 响应
     */
    private static function parseAiResponse(string $reply): ?array
    {
        $reply = trim($reply);

        // 移除可能的 markdown 代码块包裹
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $reply, $matches)) {
            $reply = trim($matches[1]);
        }

        $data = json_decode($reply, true);
        if (!is_array($data)) {
            // 尝试直接提取 summary 和 tags
            if (preg_match('/"summary"\s*:\s*"([^"]*)"/', $reply, $sMatch)
                && preg_match('/"tags"\s*:\s*\[(.*?)\]/', $reply, $tMatch)) {
                $summary = $sMatch[1];
                preg_match_all('/"([^"]*)"/', $tMatch[1], $tagMatches);
                $tags = $tagMatches[1] ?? [];
                if ($summary !== '' && count($tags) > 0) {
                    return ['summary' => $summary, 'tags' => array_slice($tags, 0, 3)];
                }
            }
            return null;
        }

        if (!isset($data['summary']) || !isset($data['tags'])) {
            return null;
        }

        $tags = is_array($data['tags']) ? $data['tags'] : [];
        $tags = array_slice(array_filter(array_map('trim', $tags)), 0, 3);

        return [
            'summary' => trim((string)$data['summary']),
            'tags' => $tags
        ];
    }
}