<?php
/**
 * Hublog PDO 数据库封装类
 * 防SQL注入、字段名校验、事务支持、性能统计
 */

namespace Hublog\Core;

use PDO;
use PDOException;
use PDOStatement;
use Hublog\Core\Exception\HublogException;

class Database
{
    /**
     * 慢查询阈值（毫秒）。
     *
     * 【为什么是 1000ms 这个量级】
     * 1. 业务语义：Hublog 的请求路径全是「读一条 → 拼一份 JSON」，正常单条 SQL 在
     *    InnoDB 上是毫秒级。超过 1 秒已经不是「慢一点」，而是「这条 SQL 缺索引 /
     *    被锁住了 / 在扫全表」—— 属于需要立刻排查的故障，不是可以容忍的抖动。
     * 2. 资源语义：PHP-FPM worker 的默认超时普遍在 30~60s；一条 SQL 吃掉 1s 意味着
     *    单个请求能吃掉 worker 的 2%~3%。再低的阈值（比如 100ms）会把「正常但稍慢」
     *    的查询也拖进告警，噪声淹没真问题；再高（比如 5s）则等到快超时了才报警。
     * 3. 运维语义：慢查询要落一条「哪张表 + 多少毫秒」的摘要。一秒级的量级让
     *    「一次批量删除跑了几秒」这种噪声被自然过滤掉，留下的都是真问题。
     *
     * 覆盖方式（优先级从高到低）：
     *   ① 环境变量 HUBLOG_SLOW_QUERY_MS（运维可不改代码直接调）；
     *   ② 常量 SLOW_QUERY_MS（config.php 里 define，改完需重启 PHP-FPM）。
     */
    public const DEFAULT_SLOW_QUERY_MS = 1000;

    /** 慢查询摘要保留条数（只留最慢的几条，避免无界增长） */
    private const SLOW_QUERY_TOP_N = 5;

    private static ?PDO $instance = null;
    private static int $queryCount = 0;
    private static float $queryTime = 0;

    /** 本次请求内的慢查询次数 */
    private static int $slowQueryCount = 0;
    /** 本次请求内的慢查询累计耗时（毫秒） */
    private static float $slowQueryTime = 0;
    /** 本次请求内最慢的 N 条慢查询摘要：[['table' => 表名, 'ms' => 毫秒], ...] */
    private static array $slowestQueries = [];
    /** 阈值缓存：只在首次使用时解析一次（避免每条 SQL 都 getenv） */
    private static ?float $slowQueryMs = null;
    private static string $fieldPattern = '/^[a-zA-Z0-9_]+$/';

    /**
     * 迁移期元数据快照（opt-in，默认 null=关闭）。
     *
     * 【用途】全新安装补跑 66 个增量迁移时，每个 migrateNN 都要先探测 information_schema
     * （表/列/索引是否存在），实测 133 次探测在大数据字典服务器上占安装耗时 90%。
     * 全新安装先由 FreshInstallSchema 一次性建出终态库，再开启本快照：
     * 迁移里所有「已存在就跳过 DDL」的判断都能在内存里命中，真实查询降到个位数。
     *
     * 【安全性】只缓存"肯定"结论：快照里存在 → 返回同形结果；快照里不存在 →
     * 返回 null 走真实查询兜底（迁移中途新建的对象绝不会被误判为不存在）。
     * 快照在终态 DDL 之后拍摄，故"存在"就是事实；另有 DROP 对象排除名单兜底。
     *
     * 【常态开销】关闭时 fetchOne/fetchColumn 只多一次 null 判断。
     * 只在全新安装的补种子循环前后由 Migration::runFreshInstall() 开启/关闭。
     */
    private static ?array $schemaSnap = null;

    /** 快照命中计数（诊断用：证明优化代码是否真的生效） */
    private static int $snapHits = 0;

    /** 迁移期探测总计数（白名单内被快照接管的探测次数） */
    private static int $snapProbes = 0;

    /** 快照层代码版本标记：完成页显示，用于判断是否加载到新代码（OPcache 排查） */
    public const SNAP_VERSION = 'v2';

    /**
     * 返回快照命中统计（诊断用）。格式：['version'=>..., 'hits'=>n, 'probes'=>n]
     */
    public static function snapshotStats(): array
    {
        return ['version' => self::SNAP_VERSION, 'hits' => self::$snapHits, 'probes' => self::$snapProbes];
    }

    /**
     * 会被迁移 DROP 掉的对象：排除出快照白名单，一律走真实查询。
     * 原因：这些对象"先存在后被删"，快照缓存"存在"会陈旧，可能导致后续探测误判。
     * （MODIFY COLUMN 不改名字、列始终存在，不在此列。）
     */
    private const SNAP_EXCLUDE_TABLES = ['checkins' => true];
    private const SNAP_EXCLUDE_INDEXES = ['post_tags' . "\0" . 'idx_tag' => true];

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            try {
                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]);
            } catch (PDOException $e) {
                throw new HublogException('数据库连接失败', 500);
            }
        }
        return self::$instance;
    }

    /**
     * 校验字段/表名合法性，防止 SQL 注入
     */
    public static function validateName(string $name): bool
    {
        return preg_match(self::$fieldPattern, $name) === 1;
    }

    /**
     * 开启迁移期元数据快照：一次性批量拉取全库表/列/索引元数据到内存。
     * 仅供全新安装的补种子循环调用；调用方必须用 try/finally 保证 disableSchemaSnapshot()。
     */
    public static function enableSchemaSnapshot(): void
    {
        self::$snapHits = 0;
        self::$snapProbes = 0;
        $pdo = self::getInstance();
        $tables = [];
        $columns = [];
        $indexes = [];

        // 2 条批量查询代替 133 次逐条探测（列 / 索引元数据一次拉全；表从列元数据推得）
        foreach ($pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $t = (string)$row['TABLE_NAME'];
            $tables[$t] = true;
            $columns[$t . "\0" . strtolower((string)$row['COLUMN_NAME'])] = [
                'name' => (string)$row['COLUMN_NAME'],
                'type' => (string)$row['COLUMN_TYPE'],
                'nullable' => strtoupper((string)$row['IS_NULLABLE']) === 'YES',
            ];
        }
        // STATISTICS 一个索引有 N 列就返回 N 行，按键收敛；NON_UNIQUE=0 才是唯一键
        foreach ($pdo->query(
            "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)$row['TABLE_NAME'] . "\0" . strtolower((string)$row['INDEX_NAME']);
            if (!isset($indexes[$key]) || (int)$row['NON_UNIQUE'] === 0) {
                $indexes[$key] = ['name' => (string)$row['INDEX_NAME'], 'non_unique' => (int)$row['NON_UNIQUE']];
            }
        }

        self::$schemaSnap = ['tables' => $tables, 'columns' => $columns, 'indexes' => $indexes];
    }

    /** 关闭迁移期元数据快照，恢复正常真实查询路径。 */
    public static function disableSchemaSnapshot(): void
    {
        self::$schemaSnap = null;
    }

    /**
     * 用元数据快照回答迁移里的 information_schema 存在性探测。
     *
     * 支持 COLUMNS / TABLES / STATISTICS 三种视图，字面量与 ? 占位符两种参数形态，
     * 覆盖迁移源码里 133 次探测中的 126 次（剩余 PLUGINS / fetchAll 批量等放行）。
     *
     * 【只返回肯定结论】快照中存在 → 返回与真实查询同形的伪造结果（调用方据此跳过 DDL）；
     * 快照中不存在或 SQL 无法安全解析 → 返回 null，回落到真实查询。
     * 这样即使快照拍早了，也只会多查几次，绝不会漏建表 / 列 / 索引。
     *
     * @return array{0: bool, 1: mixed}|null [是否命中, 伪造的返回值]；null=未命中
     */
    private static function probeFromSnapshot(string $sql, array $params = []): ?array
    {
        if (self::$schemaSnap === null) {
            return null;
        }
        $s = preg_replace('/\s+/', ' ', trim($sql));

        if (!preg_match(
            '/^SELECT\s+(.+?)\s+FROM\s+information_schema\.(COLUMNS|TABLES|STATISTICS)\s+WHERE\s+(.*?)(?:\s+LIMIT\s+\d+)?\s*$/i',
            $s,
            $head
        )) {
            return null;
        }
        $select = strtoupper(trim($head[1]));
        $view = strtoupper($head[2]);
        $where = $head[3];

        // 逐条件解析；只接受白名单字段 + DATABASE() / 数字 / 字符串字面量 / ? 占位符
        $conds = [];
        $paramIndex = 0;
        foreach (preg_split('/\s+AND\s+/i', $where) as $cond) {
            $cond = trim($cond);
            if (preg_match('/^TABLE_SCHEMA\s*=\s*DATABASE\(\)$/i', $cond)) {
                continue;
            }
            if (!preg_match('/^(\w+)\s*=\s*(.+)$/i', $cond, $cm)) {
                return null;
            }
            $field = strtoupper($cm[1]);
            $rhs = trim($cm[2]);
            if ($rhs === '?') {
                if (!array_key_exists($paramIndex, $params)) {
                    return null;
                }
                $value = (string)$params[$paramIndex];
                $paramIndex++;
            } elseif (preg_match("/^'((?:[^']|'')*)'$/", $rhs, $qm)) {
                $value = str_replace("''", "'", $qm[1]);
            } elseif (preg_match('/^-?\d+$/', $rhs)) {
                $value = $rhs;
            } else {
                return null; // 变量 / 函数 / 表达式：交给真实查询
            }
            $conds[$field] = $value;
        }

        // 占位符必须全部消费，否则说明解析与参数对不上，安全起见放行
        if ($paramIndex !== count($params)) {
            return null;
        }

        $table = $conds['TABLE_NAME'] ?? null;
        if ($table === null || $table === '') {
            return null;
        }
        if (isset(self::SNAP_EXCLUDE_TABLES[$table])) {
            return null;
        }

        if ($view === 'TABLES') {
            if (!in_array($select, ['COUNT(*)', '1', 'TABLE_NAME'], true)) {
                return null;
            }
            // 不支持附加约束（如 CREATE_TIME），避免误答
            if (array_diff(array_keys($conds), ['TABLE_NAME']) !== []) {
                return null;
            }
            if (!isset(self::$schemaSnap['tables'][$table])) {
                return null;
            }
            self::$snapProbes++;
            self::$snapHits++;
            return [true, $select === 'TABLE_NAME' ? ['TABLE_NAME' => $table] : 1];
        }

        if ($view === 'COLUMNS') {
            $column = $conds['COLUMN_NAME'] ?? null;
            if ($column === null || $column === '') {
                return null;
            }
            if (!in_array($select, ['COUNT(*)', '1', 'COLUMN_NAME', 'COLUMN_TYPE'], true)) {
                return null;
            }
            if (array_diff(array_keys($conds), ['TABLE_NAME', 'COLUMN_NAME', 'IS_NULLABLE']) !== []) {
                return null;
            }
            $key = $table . "\0" . strtolower($column);
            if (!isset(self::$schemaSnap['columns'][$key])) {
                return null;
            }
            $info = self::$schemaSnap['columns'][$key];
            // IS_NULLABLE='YES' 是附加筛选：列存在但不可空时答案是 0（否定结论）→ 放行真实查询
            if (isset($conds['IS_NULLABLE']) && strtoupper($conds['IS_NULLABLE']) === 'YES' && !$info['nullable']) {
                return null;
            }
            self::$snapProbes++;
            self::$snapHits++;
            if ($select === 'COLUMN_NAME') {
                return [true, ['COLUMN_NAME' => $info['name']]];
            }
            if ($select === 'COLUMN_TYPE') {
                return [true, ['COLUMN_TYPE' => $info['type']]];
            }
            return [true, 1];
        }

        if ($view === 'STATISTICS') {
            $index = $conds['INDEX_NAME'] ?? null;
            if ($index === null || $index === '') {
                return null;
            }
            if (!in_array($select, ['COUNT(*)', '1', 'INDEX_NAME'], true)) {
                return null;
            }
            if (array_diff(array_keys($conds), ['TABLE_NAME', 'INDEX_NAME', 'NON_UNIQUE']) !== []) {
                return null;
            }
            $key = $table . "\0" . strtolower($index);
            if (isset(self::SNAP_EXCLUDE_INDEXES[$key])) {
                return null;
            }
            if (!isset(self::$schemaSnap['indexes'][$key])) {
                return null;
            }
            $info = self::$schemaSnap['indexes'][$key];
            // NON_UNIQUE=0 筛选：普通索引命中时答案为 0 → 放行真实查询
            if (isset($conds['NON_UNIQUE']) && (int)$conds['NON_UNIQUE'] === 0 && $info['non_unique'] !== 0) {
                return null;
            }
            self::$snapProbes++;
            self::$snapHits++;
            return [true, $select === 'INDEX_NAME' ? ['INDEX_NAME' => $info['name']] : 1];
        }

        return null;
    }

    /**
     * 执行预处理查询
     *
     * 【慢查询埋点接入点】
     * 这里是唯一的 SQL 执行汇聚点（fetchOne/fetchAll/fetchColumn/insert/update/delete
     * 全部走这里），所以耗时统计挂在这里就是全站唯一口径，不会有漏网的写路径。
     *
     * 常态路径开销：一次浮点比较 + 一次已缓存的阈值读取，没有字符串拼接、没有
     * 正则、没有文件 IO —— 与改造前相比只多几十纳秒。
     * 只有真的超过阈值时，才会去解析表名并组装摘要（正则匹配 + 写文件），
     * 而「超过 1 秒的 SQL」本身就是罕见事件，不构成热路径负担。
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $start = microtime(true);
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        $elapsed = microtime(true) - $start;
        self::$queryCount++;
        self::$queryTime += $elapsed;

        if ($elapsed * 1000 >= self::slowQueryThresholdMs()) {
            self::recordSlowQuery($sql, $elapsed * 1000);
        }

        return $stmt;
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        if (($hit = self::probeFromSnapshot($sql, $params)) !== null) {
            return $hit[1]; // 快照命中"存在"：返回同形数组，跳过真实查询
        }
        $stmt = self::query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll();
    }

    public static function fetchColumn(string $sql, array $params = []): mixed
    {
        if (($hit = self::probeFromSnapshot($sql, $params)) !== null) {
            return $hit[1]; // 快照命中"存在"：返回 1，跳过真实查询
        }
        $stmt = self::query($sql, $params);
        return $stmt->fetchColumn();
    }

    /**
     * 插入数据（自动校验字段名）
     */
    public static function insert(string $table, array $data): int
    {
        if (!self::validateName($table)) {
            throw new HublogException('非法表名', 500);
        }
        $columns = [];
        foreach (array_keys($data) as $col) {
            if (!self::validateName($col)) {
                throw new HublogException("非法字段名: {$col}", 500);
            }
            $columns[] = "`{$col}`";
        }
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
        self::query($sql, array_values($data));
        return (int)self::getInstance()->lastInsertId();
    }

    /**
     * 更新数据（自动校验字段名）
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (!self::validateName($table)) {
            throw new HublogException('非法表名', 500);
        }
        $sets = [];
        foreach (array_keys($data) as $column) {
            if (!self::validateName($column)) {
                throw new HublogException("非法字段名: {$column}", 500);
            }
            $sets[] = "`{$column}` = ?";
        }
        $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE {$where}";
        $stmt = self::query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        if (!self::validateName($table)) {
            throw new HublogException('非法表名', 500);
        }
        $where = trim($where);
        if ($where === '' || preg_match('/^\d+\s*=\s*\d+$/', $where)) {
            throw new HublogException('禁止无条件删除', 500);
        }
        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    public static function beginTransaction(): void
    {
        self::getInstance()->beginTransaction();
    }

    public static function commit(): void
    {
        self::getInstance()->commit();
    }

    /**
     * 回滚当前事务（仅在确有活动事务时执行）。
     *
     * 极端场景（连接异常导致事务已被服务端自动回滚、或上游已 commit）下，
     * PDO::rollBack() 会抛 "There is no active transaction"，从而掩盖 catch 块
     * 原本要重抛的真实异常。这里做防御性短路：无活动事务时静默返回，正常路径
     * 语义完全不变。
     */
    public static function rollBack(): void
    {
        $pdo = self::getInstance();
        if (!$pdo->inTransaction()) {
            return;
        }
        $pdo->rollBack();
    }

    /**
     * 慢查询阈值（毫秒）。
     *
     * 解析优先级：环境变量 HUBLOG_SLOW_QUERY_MS → 常量 SLOW_QUERY_MS → 默认 1000。
     * 结果缓存在静态属性里（同一请求只解析一次），避免每条 SQL 都 getenv。
     */
    public static function slowQueryThresholdMs(): float
    {
        if (self::$slowQueryMs !== null) {
            return self::$slowQueryMs;
        }

        $ms = null;
        $env = getenv('HUBLOG_SLOW_QUERY_MS');
        if ($env !== false && $env !== '' && is_numeric($env) && (float)$env > 0) {
            $ms = (float)$env;
        } elseif (defined('SLOW_QUERY_MS') && is_numeric(constant('SLOW_QUERY_MS')) && (float)constant('SLOW_QUERY_MS') > 0) {
            $ms = (float)constant('SLOW_QUERY_MS');
        }

        self::$slowQueryMs = $ms ?? (float)self::DEFAULT_SLOW_QUERY_MS;
        return self::$slowQueryMs;
    }

    /**
     * 记录一条慢查询。
     *
     * 【脱敏口径 —— 为什么只留「表名 + 耗时」】
     * SQL 文本里可能夹带用户输入（手机号、邮箱、token 被拼进 WHERE 值），
     * 参数数组更是原样敏感值。所以这里**只**提取标识符级别的表名，
     * 绝不记录 SQL 原文、绑定参数、WHERE 条件或任何字面量。
     *
     * 表名从 SQL 里按「首个 FROM/INTO/UPDATE 后的反引号或裸标识符」提取，
     * 并用与 validateName() 相同的白名单过滤；提取不到就记 'unknown'。
     */
    private static function recordSlowQuery(string $sql, float $ms): void
    {
        self::$slowQueryCount++;
        self::$slowQueryTime += $ms;

        $table = self::extractTableName($sql);
        $entry = ['table' => $table, 'ms' => round($ms, 2)];

        // 维护「最慢 N 条」：未满 N 条时直接入队并重排；已满时只有比当前最慢的
        // 那条更慢才替换它。因此常态下的比较次数极少（数组通常是满的）。
        if (count(self::$slowestQueries) < self::SLOW_QUERY_TOP_N) {
            self::$slowestQueries[] = $entry;
            usort(self::$slowestQueries, static fn(array $a, array $b): int => $b['ms'] <=> $a['ms']);
        } elseif ($ms > self::$slowestQueries[self::SLOW_QUERY_TOP_N - 1]['ms']) {
            self::$slowestQueries[self::SLOW_QUERY_TOP_N - 1] = $entry;
            usort(self::$slowestQueries, static fn(array $a, array $b): int => $b['ms'] <=> $a['ms']);
        }

        self::persistSlowQuery($table, $ms);
    }

    /**
     * 从 SQL 中提取主表名（标识符级，绝不返回参数值）。
     */
    private static function extractTableName(string $sql): string
    {
        if (preg_match('/\b(?:FROM|INTO|UPDATE)\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            $name = $m[1];
            if (self::validateName($name)) {
                return strtolower($name);
            }
        }
        return 'unknown';
    }

    /**
     * 把慢查询摘要写入「跨请求持久化」的状态文件。
     *
     * 【为什么必须落文件】
     * PHP-FPM 每个请求都重建一次进程内静态属性：只放静态属性的话，
     * /metrics 端点只能看到「当前这一个请求」的慢查询数 —— 永远是 0，
     * 埋点就白做了。落文件后，/metrics 能读到「历史累计慢查询数 / 累计耗时」
     * 与「最近发生过哪些慢查询」。
     *
     * 【写的是什么】
     * 只有表名 + 耗时 + 时间戳。**不写 SQL 原文、不写绑定参数**——
     * WHERE 值里可能夹带手机号、邮箱、token，写进去就是二次泄露。
     *
     * 读-改-写全程持 flock 排他锁：并发请求不会互相覆盖计数。
     * 「最近摘要」固定保留最近 20 条，文件大小有界，不会撑爆磁盘。
     */
    private static function persistSlowQuery(string $table, float $ms): void
    {
        try {
            $dir = dirname(__DIR__, 2) . '/cache';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $file = $dir . '/slow_queries.json';

            $fh = @fopen($file, 'c+');
            if ($fh === false) {
                return;
            }
            try {
                if (!flock($fh, LOCK_EX)) {
                    return;
                }
                $raw = stream_get_contents($fh);
                $state = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($state)) {
                    $state = [];
                }

                $state['count']    = (int)($state['count'] ?? 0) + 1;
                $state['total_ms'] = round((float)($state['total_ms'] ?? 0) + $ms, 2);

                // 按表累计：/metrics 要按表暴露慢查询次数与该表最慢一次耗时
                $byTable = is_array($state['by_table'] ?? null) ? $state['by_table'] : [];
                $entry = is_array($byTable[$table] ?? null) ? $byTable[$table] : ['count' => 0, 'max_ms' => 0.0];
                $entry['count']  = (int)($entry['count'] ?? 0) + 1;
                $entry['max_ms'] = max((float)($entry['max_ms'] ?? 0), round($ms, 2));
                $byTable[$table] = $entry;
                $state['by_table'] = $byTable;

                $recent = is_array($state['recent'] ?? null) ? $state['recent'] : [];
                $recent[] = [
                    'at'    => date('Y-m-d H:i:s'),
                    'table' => $table,
                    'ms'    => round($ms, 2),
                ];
                if (count($recent) > 20) {
                    $recent = array_slice($recent, -20);
                }
                $state['recent']     = $recent;
                $state['updated_at'] = date('Y-m-d H:i:s');

                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, (string)json_encode($state, JSON_UNESCAPED_UNICODE));
                fflush($fh);
            } finally {
                @flock($fh, LOCK_UN);
                @fclose($fh);
            }
        } catch (\Throwable $e) {
            // 埋点绝不能影响主流程
            error_log('[Hublog Database] 慢查询统计写入失败: ' . $e->getMessage()); // @allow-error-log
        }
    }

    /**
     * 读取跨请求持久化的慢查询统计。
     *
     * @return array{count:int,total_ms:float,by_table:array<string,array{count:int,max_ms:float}>,recent:list<array{at:string,table:string,ms:float}>}
     */
    public static function slowQueryStats(): array
    {
        $empty = ['count' => 0, 'total_ms' => 0.0, 'by_table' => [], 'recent' => []];
        try {
            $file = dirname(__DIR__, 2) . '/cache/slow_queries.json';
            if (!is_file($file)) {
                return $empty;
            }
            $raw = @file_get_contents($file);
            if (!is_string($raw) || $raw === '') {
                return $empty;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return $empty;
            }
            $recent = [];
            foreach ((array)($decoded['recent'] ?? []) as $item) {
                if (is_array($item) && isset($item['table'], $item['ms'])) {
                    $recent[] = [
                        'at'    => (string)($item['at'] ?? ''),
                        'table' => (string)$item['table'],
                        'ms'    => (float)$item['ms'],
                    ];
                }
            }
            $byTable = [];
            foreach ((array)($decoded['by_table'] ?? []) as $table => $entry) {
                if (is_array($entry)) {
                    $byTable[(string)$table] = [
                        'count'  => (int)($entry['count'] ?? 0),
                        'max_ms' => (float)($entry['max_ms'] ?? 0),
                    ];
                }
            }
            return [
                'count'    => (int)($decoded['count'] ?? 0),
                'total_ms' => round((float)($decoded['total_ms'] ?? 0), 2),
                'by_table' => $byTable,
                'recent'   => $recent,
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * 读取最近的慢查询摘要（脱敏后：只有表名 + 耗时 + 时间）。
     *
     * @param int $limit 最多返回几条
     * @return list<array{at:string,table:string,ms:float}>
     */
    public static function recentSlowQueries(int $limit = 10): array
    {
        $recent = self::slowQueryStats()['recent'];
        if ($limit <= 0) {
            return [];
        }
        return array_slice($recent, -$limit);
    }

    public static function getStats(): array
    {
        $persisted = self::slowQueryStats();

        return [
            'query_count' => self::$queryCount,
            'query_time' => round(self::$queryTime, 4),
            // —— 慢查询（新增字段；原有 query_count / query_time 语义不变）——
            // 本请求内
            'slow_query_count'        => self::$slowQueryCount,
            'slow_query_time'         => round(self::$slowQueryTime, 2),
            'slow_query_threshold_ms' => self::slowQueryThresholdMs(),
            'slowest_queries'         => self::$slowestQueries,
            // 跨请求累计（供 /metrics 暴露成 Prometheus counter）
            'slow_query_total_count'  => $persisted['count'],
            'slow_query_total_time'   => $persisted['total_ms'],
        ];
    }
}
