<?php
/**
 * Hublog 异步任务 worker 控制器
 *
 * 提供 POST /api/cron/jobs：拉取并执行 jobs 队列里的待办任务。
 *
 * 【为什么需要独立的 worker】
 * 视频压缩走 ffmpeg，最坏一次几十分钟。放在请求内会让用户干等到超时，
 * 并长期占住 PHP-FPM worker（V94 前是「6 次串行 exec × 300s = 30 分钟」）。
 * 所以请求侧只入队，由这个 worker 异步消费。
 *
 * 【鉴权】
 * 与 /api/cron/scheduled、/api/cron/badges 同一套机制：
 * body 传 secret 或请求头 X-Cron-Token，由 CronAuth::verify() 统一校验
 * （secret 的取值优先级与「未配置即关闭入口」的语义见 api/core/CronAuth.php）。
 * cron_secret 留空 = 关闭外部调用入口（此时任务只能由站内其它入口触发）。
 */

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\CronAuth;
use Hublog\Core\SystemRepository;
use Hublog\Core\JobQueue;
use Hublog\Core\TableArchive;
use Hublog\Core\Request;
use Hublog\Core\Response;

class JobController extends BaseController
{
    /**
     * POST /api/cron/jobs
     *
     * 参数：
     *   secret   —— 鉴权用（也可用请求头 X-Cron-Token）
     *   limit    —— 本次最多处理几个任务，默认 5，上限 20
     *   purge    —— 传 1 时顺带清理 7 天前已完成的任务
     *
     * 建议外部 cron 每分钟打一次：
     *   * * * * * curl -fsS -X POST -H "X-Cron-Token: <SECRET>" https://你的域名/api/cron/jobs
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

        $limit = $request->getInt('limit', 5);
        $limit = max(1, min(20, $limit));

        try {
            $result = JobQueue::runPending($limit);

            if ($request->getInt('purge', 0) === 1) {
                $result['purged'] = JobQueue::purge(7);
            }

            $result['stats'] = JobQueue::stats();
            error_log('[Hublog cronJobs] ' . json_encode($result, JSON_UNESCAPED_UNICODE)); // @allow-error-log
            return Response::success($result);
        } catch (\Throwable $e) {
            error_log('[Hublog cronJobs] 执行失败: ' . $e->getMessage()); // @allow-error-log
            return Response::error('任务执行失败', 500);
        }
    }

    /**
     * POST /api/cron/archive —— 只增日志表滚动归档（需要 cron_secret）
     *
     * 参数：
     *   secret    —— 鉴权用（也可用请求头 X-Cron-Token）
     *   keep_days —— 保留最近 N 天，默认 30，最小 1
     *   limit     —— 每张表本轮最多删多少行，默认 500，上限 1000
     *
     * 建议每天凌晨打一次；一次只删有限行，积压时多次调用逐步收敛。
     */
    public function archive(Request $request): Response
    {
        $secret = (string)$request->getString('secret');
        if ($secret === '') {
            $secret = (string)($request->getHeader('X-Cron-Token') ?? '');
        }
        if (!CronAuth::verify($secret)) {
            return Response::error('cron token 无效或未配置', 403);
        }

        try {
            $result = TableArchive::run(
                $request->getInt('keep_days', 30),
                $request->getInt('limit', 500)
            );
            error_log('[Hublog cronArchive] ' . json_encode($result, JSON_UNESCAPED_UNICODE)); // @allow-error-log
            return Response::success($result);
        } catch (\Throwable $e) {
            error_log('[Hublog cronArchive] 执行失败: ' . $e->getMessage()); // @allow-error-log
            return Response::error('归档执行失败', 500);
        }
    }

    /**
     * GET /api/cron/jobs/status —— 查看队列积压情况（同样需要 cron_secret）
     *
     * 给运维用：队列里堆积了多少任务、失败了多少。
     */
    public function status(Request $request): Response
    {
        $secret = (string)$request->getString('secret');
        if ($secret === '') {
            $secret = (string)($request->getHeader('X-Cron-Token') ?? '');
        }

        if (!CronAuth::verify($secret)) {
            // 巡检友好：「没配 secret」和「token 打错了」必须能一眼分开。
            // 这里只在未配置时额外带一个 configured:false —— 状态码与文案保持原样
            // （403 / cron token 无效或未配置），既有语义不变。
            if (!CronAuth::isConfigured()) {
                return new Response(403, 'cron token 无效或未配置', ['configured' => false]);
            }
            return Response::error('cron token 无效或未配置', 403);
        }

        try {
            $recent = SystemRepository::recentJobs();
            return Response::success([
                'stats'      => JobQueue::stats(),
                'recent'     => $recent,
                'configured' => true,
            ]);
        } catch (\Throwable $e) {
            error_log('[Hublog cronJobs status] ' . $e->getMessage()); // @allow-error-log
            return Response::error('读取任务状态失败', 500);
        }
    }
}
