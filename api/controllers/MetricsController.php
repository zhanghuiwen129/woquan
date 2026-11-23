<?php
/**
 * Hublog Prometheus 指标端点（GET /api/metrics）
 *
 * 【为什么要有这个端点】
 * 诊断报告 P2「可观测性」要求：现有 /health 只回答「通不通」，
 * 运维拿不到「慢不慢、积压多少、迁移健不健康」这些**趋势型**数据。
 * Prometheus 文本格式是监控栈的事实标准：Prometheus / Grafana / VictoriaMetrics
 * 都能直接抓，不需要额外 agent。
 *
 * 【鉴权 —— 这是本端点最要紧的一条】
 * 指标里含内部结构信息（表名、队列深度、迁移状态、慢查询分布），
 * 裸奔等于把站点的内部地图公开。所以：
 *   - 密钥来源（优先级从高到低）：
 *       ① 环境变量 HUBLOG_METRICS_SECRET（容器部署时用 secret 挂载）；
 *       ② settings 表的 `metrics_secret` 键（后台「系统设置」写入）；
 *       ③ settings 表的 `cron_secret` 键（复用运维已有的机器间调用密钥）。
 *     ③ 是兜底而非首选：cron_secret 已经能调用写接口（执行队列任务、发布定时动态），
 *     权限严格大于只读的 /metrics，所以复用它**不会**造成权限提升；
 *     但站点若想要「监控与运维用不同密钥」，配 ② 即可完全隔离。
 *   - 未配置密钥 ⇒ 返回 404（端点视作不存在，而不是「裸奔但返回空」）。
 *     用 404 而不是 403，是为了不让未配置密钥的站点被探测出「这里有个端点」。
 *   - 密钥不匹配 ⇒ 403；
 *   - 比对一律用 hash_equals（防时序侧信道）；
 *   - token 走请求头 X-Metrics-Token / Authorization: Bearer，
 *     也允许 ?token=（access 日志里该参数会被 Logger::sanitizeUri 打码）。
 *
 * 【输出格式】
 * Prometheus 文本格式：
 *   # HELP metric_name 说明
 *   # TYPE metric_name counter|gauge
 *   metric_name{label="value"} 1.23
 * Content-Type: text/plain; version=0.0.4; charset=utf-8
 *
 * 【为什么不走 Response 类】
 * Response::toJson() 固定输出 JSON，无法产出 Prometheus 文本。
 * 项目里已有同款先例（AdminToolsController::sitemap / robots 用 header()+echo+exit），
 * 这里沿用同一范式：设置 Content-Type 后直接输出并结束。
 *
 * 【敏感信息】
 * 本端点**绝不**输出：密钥、token、手机号、邮箱、SQL 参数、原始 SQL 文本、访问日志 URI。
 * 慢查询只暴露「表名 + 耗时」，表名经过标识符白名单过滤（见 Database::extractTableName）。
 */

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\SystemRepository;
use Hublog\Core\JobQueue;
use Hublog\Core\Migration;
use Hublog\Core\MigrationContract;
use Hublog\Core\ModuleCatalog;
use Hublog\Core\Request;
use ReflectionMethod;

class MetricsController extends BaseController
{
    /** settings 表里存放 metrics 专用密钥的键名 */
    private const SETTINGS_KEY = 'metrics_secret';

    /** 环境变量名（优先于 settings 表，便于容器部署时用 secret 挂载） */
    private const ENV_SECRET = 'HUBLOG_METRICS_SECRET';

    /** 兜底密钥的 settings 键名（复用运维已有的机器间调用密钥） */
    private const FALLBACK_SETTINGS_KEY = 'cron_secret';

    /**
     * GET /api/metrics
     *
     * 输出 Prometheus 文本格式指标。
     */
    public function metrics(Request $request): void
    {
        // —— 鉴权（失败一律短路，不吐任何指标内容）——
        $token = $this->extractToken($request);

        $expected = $this->resolveSecret();
        if ($expected === '') {
            // 未配置密钥 = 端点不存在。不返回 403 是为了不泄露「这里有个端点」。
            $this->text(404, "metrics endpoint is not configured\n");
            return;
        }
        if ($token === '' || !hash_equals($expected, $token)) {
            $this->text(403, "forbidden\n");
            return;
        }

        try {
            $body = $this->render();
        } catch (\Throwable $e) {
            // 出错返回简短纯文本错误，绝不抛未捕获异常（监控端点挂掉不该拖垮站点）
            error_log('[Hublog Metrics] 指标采集失败: ' . $e->getMessage()); // @allow-error-log
            $this->text(500, "metrics collection failed\n");
            return;
        }

        $this->text(200, $body);
    }

    /**
     * 从请求里取出调用方带来的 token。
     *
     * 优先请求头（不进 access 日志）；Authorization: Bearer 兼容 Prometheus 的
     * authorization 配置块；?token= 兜底（access 日志中会被打码）。
     */
    private function extractToken(Request $request): string
    {
        $header = (string)($request->getHeader('X-Metrics-Token') ?? '');
        if ($header !== '') {
            return $header;
        }

        $auth = (string)($request->getHeader('Authorization') ?? '');
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        return trim((string)$request->getString('token'));
    }

    /**
     * 解析生效的 metrics 密钥。
     *
     * 优先级：环境变量 → settings.metrics_secret → settings.cron_secret。
     * 三者都没有 ⇒ 返回空串 ⇒ 调用方按「未配置」处理（404）。
     */
    private function resolveSecret(): string
    {
        $env = getenv(self::ENV_SECRET);
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        try {
            $row = SystemRepository::findSettingValue(self::SETTINGS_KEY);
            $secret = $row ? trim((string)($row['value'] ?? '')) : '';
            if ($secret !== '') {
                return $secret;
            }

            $row = SystemRepository::findSettingValue(self::FALLBACK_SETTINGS_KEY);
            return $row ? trim((string)($row['value'] ?? '')) : '';
        } catch (\Throwable $e) {
            // DB 不可用时无法确认「是否配置过密钥」⇒ 按未配置处理（fail-closed）
            error_log('[Hublog Metrics] 读取 metrics 密钥失败: ' . $e->getMessage()); // @allow-error-log
            return '';
        }
    }

    /**
     * 输出纯文本响应并结束请求。
     */
    private function text(int $status, string $body): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        header('Cache-Control: no-store');
        echo $body;
        exit;
    }

    // ==================== 指标采集 ====================

    /**
     * 组装 Prometheus 文本格式的指标正文。
     */
    private function render(): string
    {
        $families = [];

        // 【元信息转发例外】getStats/slowQueryStats 是 Database 自身探针，非内联 SQL，
        // 经 SystemRepository 用 FQN 转发；门禁只扫 controllers，仓储内转发不计数。
        $stats = SystemRepository::stats();
        $slow  = SystemRepository::slowQueryStats();

        // —— PHP 运行时 ——
        $this->gauge($families, 'hublog_php_memory_usage_bytes', 'PHP 当前进程占用的内存（字节）', memory_get_usage(true));
        $this->gauge($families, 'hublog_php_peak_memory_bytes', 'PHP 进程生命周期内的内存峰值（字节）', memory_get_peak_usage(true));
        $this->gauge($families, 'hublog_request_duration_seconds', '当前请求已耗费的秒数', $this->currentRequestDuration());

        $opcache = $this->opcacheStats();
        $this->gauge($families, 'hublog_opcache_enabled', 'OPcache 是否启用（1=启用 0=未启用）', $opcache['enabled'] ? 1 : 0);
        if ($opcache['enabled']) {
            $this->gauge($families, 'hublog_opcache_hit_rate', 'OPcache 命中率（0~1）', $opcache['hit_rate']);
            $this->gauge($families, 'hublog_opcache_cached_scripts', 'OPcache 已缓存的脚本数', $opcache['cached_scripts']);
        }

        // —— 数据库 ——
        // 注意语义：query_count / query_time 是**本请求内**的计数（FPM 每请求重建静态属性），
        // 所以用 gauge 而不是 counter；counter 语义留给文件持久化的慢查询统计。
        $this->gauge($families, 'hublog_db_queries_executed', '当前请求内已执行的 SQL 条数（每请求重置）', (float)$stats['query_count']);
        $this->gauge($families, 'hublog_db_query_seconds', '当前请求内 SQL 累计耗时（秒，每请求重置）', (float)$stats['query_time']);

        $this->counter($families, 'hublog_db_slow_queries_total', '累计触发的慢查询次数（跨请求累加）', (float)$slow['count']);
        $this->counter($families, 'hublog_db_slow_query_seconds_total', '累计慢查询耗时（秒，跨请求累加）', round(((float)$slow['total_ms']) / 1000, 4));
        $this->gauge($families, 'hublog_db_slow_query_threshold_ms', '慢查询阈值（毫秒）', (float)$stats['slow_query_threshold_ms']);

        // 按表暴露慢查询分布：一眼看出是哪张表在拖慢全站
        foreach ($slow['by_table'] as $table => $entry) {
            $this->counter(
                $families,
                'hublog_db_slow_queries_by_table',
                '按表统计的慢查询次数（跨请求累加）',
                (float)$entry['count'],
                ['table' => (string)$table]
            );
            $this->gauge(
                $families,
                'hublog_db_slow_query_max_ms_by_table',
                '按表统计的最慢一次慢查询耗时（毫秒）',
                (float)$entry['max_ms'],
                ['table' => (string)$table]
            );
        }

        // —— 迁移契约完整性 ——
        $contract = $this->contractStatus();
        if ($contract !== null) {
            $this->gauge($families, 'hublog_migration_contract_complete', '迁移终态契约是否完整（1=完整 0=不完整）', $contract['complete'] ? 1 : 0);
            $this->gauge($families, 'hublog_migration_missing_tables', '迁移契约中缺失的表数量', (float)$contract['missing_tables']);
            $this->gauge($families, 'hublog_migration_missing_columns', '迁移契约中缺失的列数量', (float)$contract['missing_columns']);
            $this->gauge($families, 'hublog_migration_missing_unique_indexes', '迁移契约中缺失的幂等唯一键数量', (float)$contract['missing_unique_indexes']);
        }

        // —— 幂等唯一键巡检标记（零查询：读 Migration 自己写的标记文件）——
        $this->gauge($families, 'hublog_migration_idempotency_missing', '巡检标记中记录的缺失幂等唯一键数量', (float)$this->idempotencyStatus());

        // —— 队列积压 ——
        foreach ($this->queueStats() as $status => $count) {
            $this->gauge(
                $families,
                'hublog_jobs',
                '异步任务队列各状态的任务数',
                (float)$count,
                ['status' => (string)$status]
            );
        }

        // —— HTTP 状态分布（读当天 access 日志）——
        $http = $this->httpStats();
        if ($http !== null) {
            $this->gauge($families, 'hublog_http_requests_today', '当天 access 日志记录的请求数（每日 0 点归零）', (float)$http['total']);
            $this->gauge($families, 'hublog_http_responses_4xx_today', '当天 access 日志中的 4xx 响应数', (float)$http['client_errors']);
            $this->gauge($families, 'hublog_http_responses_5xx_today', '当天 access 日志中的 5xx 响应数', (float)$http['server_errors']);
            $this->gauge($families, 'hublog_http_request_duration_seconds_sum_today', '当天 access 日志中各请求 duration_ms 之和（秒）', round(((float)$http['duration_sum_ms']) / 1000, 4));
            // 扫描被截断时明确标记，避免把「只扫了尾部」误读成「当天只有这么多请求」
            $this->gauge($families, 'hublog_http_requests_scan_truncated', '当天 access 日志是否因超过扫描上限而被截断（1=是 0=否）', $http['truncated'] ? 1 : 0);

            // 【按模块归因】下面四个指标是**新增族**，上面的无标签指标一律保留。
            // 只给「来自逐请求 access 日志」的这组指标加 module 维度：每条日志都有路径，
            // 可以明确归因；而内存/OPcache/迁移/队列是进程或全局状态，强加 module
            // 会语义失真，还会把基数乘上模块数。模块取值来自 ModuleCatalog 的有限 key
            // 加一个兜底 core，基数恒定（约 20），不会爆炸。
            foreach ($http['by_module'] as $module => $stat) {
                $labels = ['module' => (string)$module];
                $this->gauge($families, 'hublog_http_requests_by_module_today', '当天按模块统计的 access 日志请求数', (float)$stat['total'], $labels);
                $this->gauge($families, 'hublog_http_responses_4xx_by_module_today', '当天按模块统计的 4xx 响应数', (float)$stat['client_errors'], $labels);
                $this->gauge($families, 'hublog_http_responses_5xx_by_module_today', '当天按模块统计的 5xx 响应数', (float)$stat['server_errors'], $labels);
                $this->gauge($families, 'hublog_http_request_duration_seconds_sum_by_module_today', '当天按模块统计的请求耗时之和（秒）', round(((float)$stat['duration_sum_ms']) / 1000, 4), $labels);
            }
        }

        return $this->renderFamilies($families);
    }

    // ==================== 指标族容器与渲染 ====================

    /**
     * 追加一个 gauge 指标。
     *
     * @param array<string,array{type:string,help:string,samples:list<string>}> $families
     * @param array<string,string> $labels
     */
    private function gauge(array &$families, string $name, string $help, float $value, array $labels = []): void
    {
        $this->add($families, $name, 'gauge', $help, $value, $labels);
    }

    /**
     * 追加一个 counter 指标。
     *
     * @param array<string,array{type:string,help:string,samples:list<string>}> $families
     * @param array<string,string> $labels
     */
    private function counter(array &$families, string $name, string $help, float $value, array $labels = []): void
    {
        $this->add($families, $name, 'counter', $help, $value, $labels);
    }

    /**
     * @param array<string,array{type:string,help:string,samples:list<string>}> $families
     * @param array<string,string> $labels
     */
    private function add(array &$families, string $name, string $type, string $help, float $value, array $labels): void
    {
        if (!isset($families[$name])) {
            $families[$name] = ['type' => $type, 'help' => $help, 'samples' => []];
        }
        $families[$name]['samples'][] = $name . $this->labelString($labels) . ' ' . $this->formatValue($value);
    }

    /**
     * @param array<string,string> $labels
     */
    private function labelString(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $parts = [];
        foreach ($labels as $k => $v) {
            $parts[] = $k . '="' . $this->escapeLabel((string)$v) . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    /**
     * 渲染指标族。
     *
     * 【为什么要先聚合成「族」再渲染】
     * Prometheus 文本格式里，一个 metric family 只能有**一行** HELP 和**一行** TYPE，
     * 后面跟该族的所有样本行。若同一个名字出现多行 HELP/TYPE，
     * promtool check metrics 会报 "second HELP line for metric name" —— 端点看着正常，
     * 监控栈却拒收。所以按名字聚合后再输出。
     *
     * @param array<string,array{type:string,help:string,samples:list<string>}> $families
     */
    private function renderFamilies(array $families): string
    {
        $out = [];
        foreach ($families as $name => $family) {
            $out[] = '# HELP ' . $name . ' ' . $family['help'];
            $out[] = '# TYPE ' . $name . ' ' . $family['type'];
            foreach ($family['samples'] as $sample) {
                $out[] = $sample;
            }
        }
        return $out === [] ? '' : implode("\n", $out) . "\n";
    }

    /**
     * 数值格式化：定点 6 位小数并去掉多余的 0 / 尾点。
     */
    private function formatValue(float $value): string
    {
        if (!is_finite($value)) {
            return '0';
        }
        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * Prometheus 标签值转义：反斜杠 / 双引号 / 换行。
     */
    private function escapeLabel(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n"],
            ['\\\\', '\\"', '\\n'],
            $value
        );
    }

    // ==================== 各指标的数据来源 ====================

    /**
     * 当前请求已耗费的秒数。
     */
    private function currentRequestDuration(): float
    {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        if (is_numeric($start)) {
            return round(microtime(true) - (float)$start, 6);
        }
        return 0.0;
    }

    /**
     * 采集 OPcache 状态。
     *
     * @return array{enabled:bool,hit_rate:float,cached_scripts:int}
     */
    private function opcacheStats(): array
    {
        $result = ['enabled' => false, 'hit_rate' => 0.0, 'cached_scripts' => 0];

        if (!function_exists('opcache_get_status')) {
            return $result;
        }

        try {
            // false = 不含每个脚本的明细（明细很贵，只要汇总）
            $status = @opcache_get_status(false);
            if (!is_array($status)) {
                return $result;
            }
            $result['enabled'] = !empty($status['opcache_enabled']);
            $result['hit_rate'] = (float)($status['opcache_statistics']['opcache_hit_rate'] ?? 0);
            $result['cached_scripts'] = (int)($status['opcache_statistics']['num_cached_scripts'] ?? 0);
        } catch (\Throwable $e) {
            // OPcache 不可读时按未启用处理，不影响其它指标
        }

        return $result;
    }

    /**
     * 迁移终态契约完整性。
     *
     * 契约的真实定义在 MigrationContract::diff()，而算 diff 需要真实库结构
     * （Migration::schemaDiff()，private，且要打 3 条 information_schema 查询）。
     * 这里用反射调用它拿到权威 diff，再交给 MigrationContract::isFullyComplete() 判定，
     * 避免在控制器里复刻一份「表 / 列 / 唯一键」的判定逻辑（那必然随契约演进而失配）。
     *
     * 查不动时返回 null —— 调用方会**跳过**这组指标（而不是报 0），
     * 因为「查不到」与「确实缺失」是两件事，谎报 0 会让告警失效。
     *
     * @return array{complete:bool,missing_tables:int,missing_columns:int,missing_unique_indexes:int}|null
     */
    private function contractStatus(): ?array
    {
        try {
            $method = new ReflectionMethod(Migration::class, 'schemaDiff');
            $method->setAccessible(true);
            $diff = $method->invoke(null);

            if (!is_array($diff)) {
                return null;
            }

            return [
                'complete'              => MigrationContract::isFullyComplete($diff),
                'missing_tables'        => count((array)($diff['tables'] ?? [])),
                'missing_columns'       => count((array)($diff['columnNames'] ?? [])) + count((array)($diff['columns'] ?? [])),
                'missing_unique_indexes' => count((array)($diff['uniqueIndexes'] ?? [])),
            ];
        } catch (\Throwable $e) {
            error_log('[Hublog Metrics] 读取迁移契约状态失败: ' . $e->getMessage()); // @allow-error-log
            return null;
        }
    }

    /**
     * 幂等唯一键缺失数（来自 Migration 的巡检标记文件，零查询）。
     */
    private function idempotencyStatus(): int
    {
        try {
            $status = Migration::getIdempotencyStatus();
            if (is_array($status)) {
                return count((array)($status['missing'] ?? []));
            }
        } catch (\Throwable $e) {
            // 读不到按 0 处理，不影响其它指标
        }
        return 0;
    }

    /**
     * 队列积压（JobQueue::stats()）。
     *
     * @return array<string,int>
     */
    private function queueStats(): array
    {
        try {
            return JobQueue::stats();
        } catch (\Throwable $e) {
            // jobs 表不存在（未装站）或 DB 不可用时返回空数组，不抛
            error_log('[Hublog Metrics] 读取队列状态失败: ' . $e->getMessage()); // @allow-error-log
            return [];
        }
    }

    /**
     * 当天 HTTP 请求数与 4xx / 5xx 分布（读 access 日志）。
     *
     * 【为什么不另建计数器】
     * access 日志（logs/access-YYYY-MM-DD.log）本来就逐请求记录了 status，
     * 另建一套计数器等于把同一份事实记两遍、还多一处会漂移的状态。
     * 日志本身按天轮转，所以这里给出的语义是「当天」而不是「累计」。
     *
     * 【扫描上限】
     * 日志文件理论上可以很大，所以只扫尾部有限字节；超出部分不计入，
     * 并用 hublog_http_requests_scan_truncated 标记这件事（0=完整，1=被截断），
     * 避免把「只扫了尾部」误读成「当天只有这么多请求」。
     *
     * 【按模块归因】总量统计沿用原来的正则，语义保持不变；另起一个逐行 JSON 解析
     * 得到 by_module（新增字段），供 render() 输出带 module 标签的**新指标族**。
     * 新日志直接读 module 字段；旧日志（当天轮转前写入的）没有该字段时按 uri 现场推算。
     *
     * @return array{total:int,client_errors:int,server_errors:int,duration_sum_ms:float,truncated:bool,by_module:array<string,array{total:int,client_errors:int,server_errors:int,duration_sum_ms:float}>}|null
     */
    private function httpStats(): ?array
    {
        try {
            $file = dirname(__DIR__, 2) . '/logs/access-' . date('Y-m-d') . '.log';
            if (!is_file($file)) {
                return null;
            }

            $maxBytes = 4 * 1024 * 1024;
            $size = (int)@filesize($file);
            $truncated = $size > $maxBytes;

            $fh = @fopen($file, 'r');
            if ($fh === false) {
                return null;
            }
            try {
                if ($truncated) {
                    fseek($fh, -$maxBytes, SEEK_END);
                }
                $content = stream_get_contents($fh);
            } finally {
                fclose($fh);
            }
            if (!is_string($content)) {
                return null;
            }

            $total = 0;
            $clientErrors = 0;
            $serverErrors = 0;
            $durationSum = 0.0;
            // access 日志是每行一条 JSON，status 字段形如 "status":200。
            // 负向后视排除转义写法 \"status\" —— 否则 uri 里若含该字样会被误计数。
            if (preg_match_all('/(?<!\\\\)"status":(\d{3})/', $content, $matches)) {
                foreach ($matches[1] as $code) {
                    $total++;
                    $code = (int)$code;
                    if ($code >= 500) {
                        $serverErrors++;
                    } elseif ($code >= 400) {
                        $clientErrors++;
                    }
                }
            }
            if (preg_match_all('/(?<!\\\\)"duration_ms":([0-9.]+)/', $content, $durations)) {
                foreach ($durations[1] as $ms) {
                    $durationSum += (float)$ms;
                }
            }

            // 归因是附加维度：即使它出错也不能拖掉上面那些既有指标。
            try {
                $byModule = self::aggregateByModule($content);
            } catch (\Throwable $e) {
                error_log('[Hublog Metrics] 按模块聚合 access 日志失败: ' . $e->getMessage()); // @allow-error-log
                $byModule = [];
            }

            return [
                'total'          => $total,
                'client_errors'  => $clientErrors,
                'server_errors'  => $serverErrors,
                'duration_sum_ms' => round($durationSum, 2),
                'truncated'      => $truncated,
                'by_module'      => $byModule,
            ];
        } catch (\Throwable $e) {
            error_log('[Hublog Metrics] 读取 access 日志失败: ' . $e->getMessage()); // @allow-error-log
            return null;
        }
    }

    /**
     * 逐行解析 access 日志并按模块聚合（纯函数，便于单测；不读文件、不查库）。
     *
     * 模块取值只接受 ModuleCatalog 的已知 key 或兜底 core，避免脏日志把
     * Prometheus 标签基数撑爆。截断产生的半行 JSON 解析失败时直接跳过。
     *
     * @return array<string,array{total:int,client_errors:int,server_errors:int,duration_sum_ms:float}>
     */
    private static function aggregateByModule(string $content): array
    {
        $byModule = [];
        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }

            $module = $record['module'] ?? null;
            $module = is_string($module) && $module !== ''
                ? $module
                : ModuleCatalog::moduleForUri((string)($record['uri'] ?? ''));
            if ($module !== 'core' && !array_key_exists($module, ModuleCatalog::MODULES)) {
                $module = 'core';
            }

            if (!isset($byModule[$module])) {
                $byModule[$module] = [
                    'total'           => 0,
                    'client_errors'   => 0,
                    'server_errors'   => 0,
                    'duration_sum_ms' => 0.0,
                ];
            }
            $byModule[$module]['total']++;

            $code = (int)($record['status'] ?? 0);
            if ($code >= 500) {
                $byModule[$module]['server_errors']++;
            } elseif ($code >= 400) {
                $byModule[$module]['client_errors']++;
            }
            $byModule[$module]['duration_sum_ms'] += (float)($record['duration_ms'] ?? 0);
        }
        ksort($byModule);
        return $byModule;
    }
}
