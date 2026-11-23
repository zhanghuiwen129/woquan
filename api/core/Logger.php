<?php
declare(strict_types=1);

namespace Hublog\Core;

class Logger
{
    private static string $logDir = '';
    // 日志保留天数（按日期前缀清理老文件）
    // 类型化类常量（public const int X）为 PHP 8.3 语法，为兼容 8.2 及以下统一省略类型
    public const DEFAULT_RETENTION_DAYS = 14;

    private static function getLogDir(): string
    {
        if (self::$logDir === '') {
            self::$logDir = dirname(__DIR__, 2) . '/logs';
        }
        return self::$logDir;
    }

    /**
     * P2-16：按需轮转清理 logs/ 目录下的 *.log 文件。
     * - hublog-YYYY-MM-DD.log 应用日志（info/warning/error）
     * - access-YYYY-MM-DD.log 访问日志（access()）
     * - upload-YYYY-MM-DD.log 上传日志
     * 统一按文件名里的 YYYY-MM-DD 判断，早于 (today - $days) 则删除；
     * 为避免每次请求都 glob，使用 date('Y-m-d') 级别的轮转标记文件做「每天只执行一次」。
     */
    private static function rotateIfNeeded(int $days = self::DEFAULT_RETENTION_DAYS): void
    {
        $dir = self::getLogDir();
        if (!is_dir($dir)) return;

        $today = date('Y-m-d');
        $marker = $dir . '/.rotate_marker_' . $today;
        if (file_exists($marker)) return;

        // 标记文件：原子性 touch 失败（并发）则跳过
        $fh = @fopen($marker, 'xb');
        if ($fh === false) return;
        fclose($fh);

        $cutoff = time() - max(1, $days) * 86400;
        $pattern = $dir . '/*.log';
        foreach (glob($pattern) as $logFile) {
            $basename = basename($logFile);
            if (!preg_match('/(\d{4}-\d{2}-\d{2})\.log$/', $basename, $m)) {
                // 没有日期后缀但文件 mtime 超 2*retention 天就删（兜底：旧 hublog.log）
                if (is_file($logFile) && filemtime($logFile) !== false && filemtime($logFile) < (time() - 2 * max(1, $days) * 86400)) {
                    @unlink($logFile);
                }
                continue;
            }
            $ts = strtotime($m[1]);
            if ($ts === false || $ts < $cutoff) {
                @unlink($logFile);
            }
        }

        // 同步清理旧的 marker 文件（除今天之外所有历史 marker）
        foreach (glob($dir . '/.rotate_marker_*') as $oldMarker) {
            if (basename($oldMarker) !== basename($marker)) {
                @unlink($oldMarker);
            }
        }
    }

    private static function write(string $level, string $msg, array $ctx = []): void
    {
        $dir = self::getLogDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // 每天第一次写入时执行一次轮转清理
        self::rotateIfNeeded(self::DEFAULT_RETENTION_DAYS);

        $file = $dir . '/hublog-' . date('Y-m-d') . '.log';
        $time = date('Y-m-d H:i:s');
        // P1-2：ctx 里也可能带完整 URI（api/index.php 未捕获异常把 REQUEST_URI 塞进 ctx），
        // 写结构化日志前按字段语义脱敏；error_log 通道仍只写原始 msg，语义不变。
        $safeCtx = $ctx;
        foreach ($safeCtx as $ctxKey => $ctxValue) {
            if (!is_string($ctxValue)) {
                continue;
            }
            $lowerKey = strtolower((string)$ctxKey);
            if (str_contains($lowerKey, 'uri') || str_contains($lowerKey, 'url') || $lowerKey === 'referer') {
                $safeCtx[$ctxKey] = self::sanitizeUri($ctxValue);
            }
        }
        $ctxStr = !empty($safeCtx) ? ' ' . json_encode($safeCtx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = "[{$time}][{$level}] {$msg}{$ctxStr}" . PHP_EOL;

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        // 三个公开入口传入的级别是大写（ERROR/INFO/WARNING），历史实现却按小写
        // 'error' 比较，导致该分支成为死代码：错误细节只进 hublog-*.log，不再进
        // PHP error_log（运维监控通道静默丢失）。改为大小写不敏感，恢复设计意图：
        // error 级在结构化日志之外额外写一份 error_log，细节留服务端、不回显客户端。
        if (strcasecmp($level, 'error') === 0) {
            error_log("[Hublog] {$msg}");
        }
    }

    /**
     * P1-7：URI 脱敏——把查询串里的敏感参数值替换为 ***。
     * access 日志保留 14 天，SSE 的 ?token= 若不脱敏等于把 JWT 落盘两周。
     * 覆盖 token/access_token/refresh_token/code/password 以及
     * secret/api_key/apikey/id_card/idcard/phone/mobile/authorization/sign/signature；
     * 仍只匹配 [?&] 锚定的 query 参数，绝不改路径段。
     */
    public static function sanitizeUri(string $uri): string
    {
        return preg_replace(
            '/([?&](?:token|access_token|refresh_token|code|password|secret|api_key|apikey|id_card|idcard|phone|mobile|authorization|sign|signature)=)[^&]*/i',
            '$1***',
            $uri
        ) ?? $uri;
    }

    /**
     * P2-16：访问日志 access.log（按日切 access-YYYY-MM-DD.log + 14 天保留）
     * - 只记录结构化字段：IP/method/URI/status/time_ms/user_id/referer/UA/module；
     * - 不记录 Authorization 头 / Cookie / body，避免泄露凭据；
     * - P1-7：URI 先过 sanitizeUri()，查询串中的 token 等敏感值打码；
     * - module 由 ModuleCatalog::moduleForUri() 从原始 URI 现算（同一套长前缀优先规则），
     *   供 /metrics 按模块归因「哪个模块慢/出错」；未命中可关模块前缀时记为 core。
     *   只写模块 key（有限集合），不写路径参数，故不扩大日志敏感面。
     */
    public static function access(
        string $method,
        string $uri,
        int $status,
        float $durationMs,
        ?string $userId = null,
        ?string $clientIp = null,
        ?string $referer = null,
        ?string $userAgent = null
    ): void {
        $dir = self::getLogDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        self::rotateIfNeeded(self::DEFAULT_RETENTION_DAYS);

        $file = $dir . '/access-' . date('Y-m-d') . '.log';
        $time = date('Y-m-d H:i:s');

        $record = [
            'time'        => $time,
            'ip'          => $clientIp,
            'method'      => $method,
            'uri'         => self::sanitizeUri($uri),
            'status'      => $status,
            'duration_ms' => round($durationMs, 2),
            'user_id'     => $userId,
            'referer'     => $referer,
            'ua'          => $userAgent,
            // 归因字段：模块 key 从原始 URI 推算（query 已在 moduleForUri 内剥离），
            // 读取 URI 原文而非 sanitizeUri() 的结果，避免脱敏逻辑变化影响归因。
            'module'      => ModuleCatalog::moduleForUri($uri),
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::write('INFO', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write('ERROR', $msg, $ctx);
    }

    public static function warning(string $msg, array $ctx = []): void
    {
        self::write('WARNING', $msg, $ctx);
    }
}
