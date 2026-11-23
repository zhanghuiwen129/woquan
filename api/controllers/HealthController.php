<?php
/**
 * Hublog 健康检查端点（运维可观测性，V94）
 *
 * 提供 GET /health：返回 DB / Cache / 磁盘 三项基础健康状态。
 *
 * 【设计取舍】
 *   - 免鉴权、只读、无用户输入：监控探针（如宝塔/云监控/负载均衡的健康检查）
 *     需要频繁打这个端点，不能要求 token，也不能被限流误伤。
 *   - 只暴露「通不通 / 还剩多少」，不暴露版本、表结构、业务指标 —— 避免信息泄露。
 *   - 任何一项失败都不抛 500，而是把该项标红并返回整体 degraded / down，
 *     让监控系统能据此告警而不是拿到一堆 500。
 */

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Cache;
use Hublog\Core\SystemRepository;
use Hublog\Core\Migration;
use Hublog\Core\PathResolver;
use Hublog\Core\Response;

class HealthController extends BaseController
{
    public function check(): Response
    {
        $checks = [];

        // 1) 数据库
        $checks['db'] = $this->checkDatabase();

        // 2) 缓存（file 永远可用；redis 缺失/连接失败会被 Cache 静默降级，这里探一遍）
        $checks['cache'] = $this->checkCache();

        // 3) 磁盘（uploads 目录剩余空间）
        $checks['disk'] = $this->checkDisk();

        // 4) 迁移状态（最近一次迁移失败标记，无需连库）
        $checks['migration'] = $this->checkMigration();

        // 聚合整体状态：有一项 down → down；有一项 degraded → degraded；全 ok → ok
        $status = 'ok';
        foreach ($checks as $c) {
            if (($c['status'] ?? 'ok') === 'down') {
                $status = 'down';
                break;
            }
            if (($c['status'] ?? 'ok') === 'degraded') {
                $status = 'degraded';
            }
        }

        return Response::success([
            'status' => $status,
            'time'   => date('c'),
            'checks' => $checks,
        ]);
    }

    private function checkDatabase(): array
    {
        try {
            SystemRepository::ping();
            return ['status' => 'ok', 'detail' => 'connected'];
        } catch (\Throwable $e) {
            return ['status' => 'down', 'detail' => 'db unreachable: ' . $e->getMessage()];
        }
    }

    private function checkCache(): array
    {
        $driver = defined('CACHE_DRIVER') ? (string)CACHE_DRIVER : 'file';
        try {
            $probeKey = 'health_probe_' . mt_rand();
            $probeVal = (string)mt_rand();
            $ok = Cache::set($probeKey, $probeVal, 5);
            $got = Cache::get($probeKey);
            Cache::delete($probeKey);
            if ($ok && $got === $probeVal) {
                return ['status' => 'ok', 'detail' => $driver];
            }
            return ['status' => 'degraded', 'detail' => $driver . ' set/get mismatch'];
        } catch (\Throwable $e) {
            return ['status' => 'degraded', 'detail' => $driver . ' error: ' . $e->getMessage()];
        }
    }

    private function checkDisk(): array
    {
        $path = PathResolver::basePath() . '/uploads';
        if (!is_dir($path)) {
            $path = PathResolver::basePath();
        }
        try {
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);
            if ($free === false || $total === false) {
                return ['status' => 'degraded', 'detail' => 'cannot read disk stats'];
            }
            $pctFree = $total > 0 ? ($free / $total) * 100 : 0;
            // 剩余 < 10% 视为 degraded（预警），< 2% 视为 down
            $status = $pctFree < 2 ? 'down' : ($pctFree < 10 ? 'degraded' : 'ok');
            return [
                'status'  => $status,
                'detail'  => round($pctFree, 1) . '% free',
                'free_mb' => (int)($free / 1024 / 1024),
                'total_mb' => (int)($total / 1024 / 1024),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'degraded', 'detail' => 'disk check error: ' . $e->getMessage()];
        }
    }

    private function checkMigration(): array
    {
        try {
            $failure = Migration::getFailure();
            if ($failure === null) {
                return ['status' => 'ok', 'detail' => 'no recent failure'];
            }
            return [
                'status'    => 'down',
                'detail'    => 'migration failed',
                'target_version' => $failure['target_version'] ?? null,
                'error'     => $failure['error'] ?? '',
                'time'      => $failure['time'] ?? '',
            ];
        } catch (\Throwable $e) {
            return ['status' => 'degraded', 'detail' => 'migration check error: ' . $e->getMessage()];
        }
    }
}
