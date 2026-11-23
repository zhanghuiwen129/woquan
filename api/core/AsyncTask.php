<?php
/**
 * Hublog 统一异步任务入口
 *
 * 【为什么需要】
 * 三个 AI 插件 + 一个搜索插件原来用 Plugin::defer() 做「异步」：
 * runDeferred() 在响应 echo 后调用 fastcgi_finish_request()，但回调仍在
 * 同一个 PHP-FPM 进程内串行跑完（AI 评论 30s + 内容审核 10s + 摘要 15s
 * = 最坏 55s/帖占着 worker）。defer 只做到「让用户不等」，并没有释放 worker。
 *
 * 本类把重活改走持久化队列 JobQueue：
 *   - 请求侧只入队（一条 INSERT），立刻返回；
 *   - cron worker（POST /api/cron/jobs）在独立请求里消费，真正释放请求进程。
 *
 * 【队列消费依赖 cron —— 站点必须配置】
 * 入队成功（id > 0）即由 cron worker（POST /api/cron/jobs）消费。站点必须配置
 * 该定时任务，否则任务会一直 pending，相关 AI 功能静默不生效（不报错）。
 * 本类不探测 cron 是否配置 —— enqueue 的返回值只反映 jobs 表能否写入，
 * 与是否配置 cron 无关。只有「入队失败」（jobs 表不可用，返回 0 / 抛异常）
 * 时才回退到 Plugin::defer() 同进程执行，并写 error_log 提示运维。
 * 注意：内容审核第三层是安全审核，不走本类，固定走 Plugin::defer 保底。
 *
 * 【安全】
 * runHandler() 只接受硬编码白名单里的 handler 名，绝不用 payload 里携带的
 * 类名/方法名做调用，避免有人写 jobs 表 payload 就触发任意类方法（RCE）。
 */

declare(strict_types=1);

namespace Hublog\Core;

class AsyncTask
{
    /** 任务类型：通用插件任务（JobQueue::execute() 据此分发到本类） */
    public const TYPE_PLUGIN_TASK = 'plugin_task';

    /**
     * 入队一个插件异步任务；仅入队失败（jobs 表不可用）时回退到 Plugin::defer()。
     * 入队成功必须由站点配置的 /api/cron/jobs 消费，本类不兜底「没配 cron」场景。
     *
     * @param string $handler   白名单里的 handler 名（见 handlerMap）
     * @param array  $payload   任务参数（不含 handler，由本方法强制注入）
     * @param string $dedupeKey 幂等键，同键只排队一次
     */
    public static function defer(string $handler, array $payload, string $dedupeKey): void
    {
        $jobPayload = $payload;
        $jobPayload['handler'] = $handler; // 强制覆盖：payload 不得伪造 handler

        try {
            $id = JobQueue::enqueue(self::TYPE_PLUGIN_TASK, $jobPayload, $dedupeKey);
            if ($id > 0) {
                // 入队成功：必须由站点配置的 /api/cron/jobs 消费，不再登记 defer。
                // 站点未配 cron 时任务会积压 pending、功能静默失效（见类头注释）。
                return;
            }
            Logger::warning('[Hublog AsyncTask] 入队返回 0，回退 defer: handler=' . $handler . ' key=' . $dedupeKey);
        } catch (\Throwable $e) {
            Logger::warning('[Hublog AsyncTask] 入队异常，回退 defer: handler=' . $handler
                . ' key=' . $dedupeKey . ' err=' . $e->getMessage());
        }

        // 仅「入队失败」（jobs 表不可用）才回退同进程执行；不覆盖「没配 cron」场景。
        Plugin::defer(static fn(): bool => self::runHandler($handler, $payload));
    }

    /**
     * handler 白名单：handler 名 → [插件类, 适配方法]。
     *
     * 键与值都是硬编码，不来自 payload —— 这是防 RCE 的核心。
     * 适配方法统一签名 runFromPayload(array $payload): bool。
     *
     * 插件类由 api/index.php 的 Plugin::loadAll() require 进来；
     * 被禁用的插件不会加载，class_exists() 为 false 时按「处理器不可用」处理。
     * content_audit 仅为兼容历史已入队任务保留；新的内容审核第三层走
     * ContentAudit\Plugin::auditContent() 里的 Plugin::defer，不经队列。
     *
     * @return array<string, array{0:class-string,1:string}>
     */
    private static function handlerMap(): array
    {
        return [
            'ai_comment'    => [\Hublog\Plugin\AiComment\Plugin::class, 'runFromPayload'],
            'content_audit' => [\Hublog\Plugin\ContentAudit\Plugin::class, 'runFromPayload'],
            'smart_summary' => [\Hublog\Plugin\SmartSummary\Plugin::class, 'runFromPayload'],
            'smart_search'  => [\Hublog\Plugin\SmartSearch\Plugin::class, 'runFromPayload'],
            // V96：付费圈子订阅生命周期（核心服务，非插件；由 AutoLoader 自动加载）
            'circle_subscription_lifecycle' => [\Hublog\Core\CircleSubscriptionService::class, 'runFromPayload'],
        ];
    }

    /**
     * 白名单分发执行一个插件任务。
     *
     * @return bool 是否执行成功（供 JobQueue 决定标记 done / failed）
     */
    public static function runHandler(string $handler, array $payload): bool
    {
        $map = self::handlerMap();
        if (!isset($map[$handler])) {
            Logger::error('[Hublog AsyncTask] 拒绝非法/未知 handler: ' . $handler);
            return false;
        }

        [$class, $method] = $map[$handler];
        if (!class_exists($class) || !method_exists($class, $method)) {
            Logger::warning('[Hublog AsyncTask] handler 处理器不可用（插件被禁用或未加载）: ' . $handler);
            return false;
        }

        /** @var callable(array):bool $callable */
        $callable = [$class, $method];
        if (!is_callable($callable)) {
            Logger::error('[Hublog AsyncTask] handler 不可调用: ' . $handler);
            return false;
        }

        try {
            return (bool)call_user_func($callable, $payload);
        } catch (\Throwable $e) {
            Logger::error('[Hublog AsyncTask] handler 执行失败: ' . $handler . ' err=' . $e->getMessage());
            return false;
        }
    }
}
