<?php
/**
 * 控制器内联 SQL 收敛门禁（由 scripts/ci.php 接入，也可独立运行）
 *
 * 用法：
 *   php scripts/check-inline-sql.php             # 人类可读清单
 *   php scripts/check-inline-sql.php --json      # JSON 输出，便于其它流水线接手
 *
 * 口径：统计控制器中的内联 SQL 调用数（token 解析，排除注释与字符串）：
 *   1) 真实的 `Database::` 静态调用；
 *   2) `$this->paginate($request, <SQL字面量>, ..., <SQL字面量>, ...)` 的
 *      第 2 / 第 4 个实参（即 listSql / countSql）。
 *   事务控制调用（Database::rollBack / commit / beginTransaction / inTransaction）
 *   既无 SQL 字面量也无注入面，不计入内联 SQL（它们是事务边界，不是查询入口）。
 * 两类相加即该文件的内联 SQL 总数，严格上限均为 0。
 *
 * 阈值：
 *   - 已收敛的控制器（示范批次 CircleController + 批次一四文件 + 批次二四文件
 *     + 批次三 PointController/ShopController + 批次四九文件 + 批次五 28 文件）
 *     收紧到实际值 0（严格上限，超出即阻断）；
 *   - 其他控制器只设宽松警戒线，超限仅报告、不改变退出码。
 *
 * 退出码：0 = 严格上限未破；1 = 已收敛控制器超过收紧上限。
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$controllerDir = $root . '/api/controllers';

// 收紧上限：已完成收敛的控制器，上限 = 迁移后的实际值（均为 0）。
// 新增任何内联调用都会被拦下。批次一至批次五各文件分别于对应批次完成。
// 批次五完成后，controllers 目录内所有含内联 SQL 的文件均已纳入严格上限。
$strictCaps = [
    'CircleController.php'       => 0, // 示范批次
    'UserController.php'         => 0, // 批次一 A
    'AuthController.php'         => 0, // 批次一 A
    'PaymentController.php'      => 0, // 批次一 B
    'PostCrudTrait.php'          => 0, // 批次一 C
    'PostTypeTrait.php'          => 0, // 批次二
    'PostCommentTrait.php'       => 0, // 批次二
    'AdminCircleController.php'  => 0, // 批次二
    'AppController.php'          => 0, // 批次二
    'PointController.php'        => 0, // 批次三
    'ShopController.php'         => 0, // 批次三
    'PostFeedTrait.php'          => 0, // 批次四 A
    'PostUpdateTrait.php'        => 0, // 批次四 A
    'PostUploadTrait.php'        => 0, // 批次四 A
    'FollowController.php'       => 0, // 批次四 B
    'ChatController.php'         => 0, // 批次四 B
    'RealNameController.php'     => 0, // 批次四 B
    'AdminController.php'        => 0, // 批次四 C
    'AdminToolsController.php'   => 0, // 批次四 C
    'AdminSettingsController.php' => 0, // 批次四 C
    // 批次五 · 社交互动域（A）
    'BadgeController.php'        => 0, // 批次五 A
    'FavoriteController.php'     => 0, // 批次五 A
    'NotificationController.php' => 0, // 批次五 A
    'CallController.php'         => 0, // 批次五 A
    'ReportController.php'       => 0, // 批次五 A
    // 批次五 · 活动分类搜索域（B）
    'ActivityController.php'     => 0, // 批次五 B
    'AdminActivitiesController.php' => 0, // 批次五 B
    'CategoryController.php'     => 0, // 批次五 B
    'AdminCategoryController.php' => 0, // 批次五 B
    'SearchController.php'       => 0, // 批次五 B
    // 批次五 · 后台财务积分统计域（C）
    'AdminFinanceController.php' => 0, // 批次五 C
    'AdminPointsController.php'  => 0, // 批次五 C
    'AdminStatsController.php'   => 0, // 批次五 C
    'AdminBatchController.php'   => 0, // 批次五 C
    // 批次五 · 审核域（D）
    'AdminModerationController.php' => 0, // 批次五 D
    'PostModerationTrait.php'    => 0, // 批次五 D
    // 批次五 · 系统设置杂项域（E）
    'MetricsController.php'      => 0, // 批次五 E
    'HealthController.php'       => 0, // 批次五 E
    'JobController.php'          => 0, // 批次五 E
    'AdminUpgradeController.php' => 0, // 批次五 E
    'AdminCrashController.php'   => 0, // 批次五 E
    'AdminAppVersionController.php' => 0, // 批次五 E
    'AdminAttachmentController.php' => 0, // 批次五 E
    'AdminFriendLinkController.php' => 0, // 批次五 E
    'PostSettingsTrait.php'      => 0, // 批次五 E
    'AdminAiController.php'      => 0, // 批次五 E
    'AdminLicenseController.php' => 0, // 批次五 E
    'LocationController.php'     => 0, // 批次五 E
    'ContentPayController.php'   => 0, // P0-2：作者免购查询已收进 ContentPayRepository
];

// 宽松警戒线：仅报告，不阻断。数值取自全仓 TOP 调用数附近的量级。
$warnAt = 60;

$asJson = in_array('--json', array_slice($argv, 1), true);

/** 用 token 解析统计真实静态调用数，避免把注释/字符串里的 Database:: 算进来 */
function countDatabaseCalls(string $code): int
{
    $tokens = token_get_all($code);
    $count = 0;
    $total = count($tokens);

    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        // PHP 8 里 \Database / \Hublog\Core\Database 是 T_NAME_FULLY_QUALIFIED
        // （T_NAME_QUALIFIED）单 token，不是 T_STRING；只认 T_STRING 会漏计 FQN 调用。
        $isDatabase = ($token[0] === T_STRING && $token[1] === 'Database');
        if (!$isDatabase && defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED) {
            $isDatabase = preg_match('/(?:^|\\\\)Database$/', $token[1]) === 1;
        }
        if (!$isDatabase && defined('T_NAME_QUALIFIED') && $token[0] === T_NAME_QUALIFIED) {
            $isDatabase = preg_match('/(?:^|\\\\)Database$/', $token[1]) === 1;
        }
        if (!$isDatabase) {
            continue;
        }
        $next = $i + 1;
        while ($next < $total && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }
        if ($next < $total && is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_COLON) {
            // rollBack/commit/beginTransaction/inTransaction 只是事务控制，无 SQL 字面量、
            // 无注入面。RealNameController 的兜底 rollBack 曾因此被误计为内联 SQL。
            $method = $next + 1;
            while ($method < $total && is_array($tokens[$method]) && $tokens[$method][0] === T_WHITESPACE) {
                $method++;
            }
            if ($method < $total && is_array($tokens[$method]) && $tokens[$method][0] === T_STRING) {
                $methodName = strtolower($tokens[$method][1]);
                if (in_array($methodName, ['rollback', 'commit', 'begintransaction', 'intransaction'], true)) {
                    continue;
                }
            }
            $count++;
        }
    }
    return $count;
}

/**
 * 统计传给 paginate() 的 SQL 字面量参数（第 2 / 第 4 个实参）。
 *
 * paginate() 内部会拼 ' LIMIT ?, ?' 再走 Database::fetchAll，但控制器源码里
 * 看不到 Database:: 调用，旧口径因此漏掉这类内联 SQL。这里按 token 解析
 * 实参边界，只认字面量（字符串 / heredoc / nowdoc）；传变量名不算内联。
 */
function countPaginateSqlLiterals(string $code): int
{
    $tokens = token_get_all($code);
    $total = count($tokens);
    $count = 0;
    $objectOperators = [T_OBJECT_OPERATOR];
    if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
        $objectOperators[] = T_NULLSAFE_OBJECT_OPERATOR;
    }

    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'paginate') {
            continue;
        }

        // 只认方法调用：$this->paginate(...) / $obj?->paginate(...)
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
            $prev--;
        }
        if ($prev < 0 || !is_array($tokens[$prev]) || !in_array($tokens[$prev][0], $objectOperators, true)) {
            continue;
        }

        $open = $i + 1;
        while ($open < $total && is_array($tokens[$open]) && $tokens[$open][0] === T_WHITESPACE) {
            $open++;
        }
        if ($open >= $total || $tokens[$open] !== '(') {
            continue;
        }

        // 按顶层逗号切实参，括号/方括号/花括号内部的逗号不切
        $args = [];
        $current = [];
        $depth = 0;
        for ($j = $open + 1; $j < $total; $j++) {
            $tok = $tokens[$j];
            if ($tok === '(' || $tok === '[' || $tok === '{') {
                $depth++;
                $current[] = $tok;
                continue;
            }
            if ($tok === ')' || $tok === ']' || $tok === '}') {
                if ($tok === ')' && $depth === 0) {
                    $args[] = $current;
                    break;
                }
                $depth--;
                $current[] = $tok;
                continue;
            }
            if ($tok === ',' && $depth === 0) {
                $args[] = $current;
                $current = [];
                continue;
            }
            $current[] = $tok;
        }

        foreach ([1, 3] as $argIndex) {
            if (!isset($args[$argIndex])) {
                continue;
            }
            $first = null;
            foreach ($args[$argIndex] as $tok) {
                if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $first = $tok;
                break;
            }
            if ($first === null) {
                continue;
            }
            // 无插值的字符串是单个 T_CONSTANT_ENCAPSED_STRING；
            // 有插值的双引号串以裸 " 开头，后面才是 T_ENCAPSED_AND_WHITESPACE。
            if ($first === '"' || $first === "'") {
                $count++;
                continue;
            }
            if (is_array($first) && $first[0] === T_CONSTANT_ENCAPSED_STRING) {
                $count++;
                continue;
            }
            if (is_array($first) && defined('T_START_HEREDOC') && $first[0] === T_START_HEREDOC) {
                $count++;
            }
        }
    }
    return $count;
}

// 门禁自测：真实 Database::query 仍被计数，事务控制调用不计；供 CI / PHPUnit 直接执行。
if (in_array('--self-test', $argv, true)) {
    $querySample = '<?php Database::query("SELECT 1");';
    $txSample = '<?php Database::rollBack(); Database::commit(); Database::beginTransaction(); Database::inTransaction();';
    $queryCount = countDatabaseCalls($querySample);
    $txCount = countDatabaseCalls($txSample);
    $ok = $queryCount === 1 && $txCount === 0;
    echo 'query=' . $queryCount . ' transaction_controls=' . $txCount . ' -> ' . ($ok ? 'OK' : 'FAIL') . "\n";
    exit($ok ? 0 : 1);
}

$rows = [];
$blocked = false;

foreach (glob($controllerDir . '/*.php') ?: [] as $file) {
    $name = basename($file);
    $source = (string)file_get_contents($file);
    $databaseCalls = countDatabaseCalls($source);
    $paginateLiterals = countPaginateSqlLiterals($source);
    $count = $databaseCalls + $paginateLiterals;

    if (array_key_exists($name, $strictCaps)) {
        $cap = $strictCaps[$name];
        $status = $count > $cap ? 'FAIL' : 'PASS';
        if ($status === 'FAIL') {
            $blocked = true;
        }
        $rows[] = [
            'file' => $name,
            'count' => $count,
            'database_calls' => $databaseCalls,
            'paginate_sql_literals' => $paginateLiterals,
            'threshold' => $cap,
            'mode' => 'strict',
            'status' => $status,
        ];
        continue;
    }

    // 宽松警戒线只报告，不阻断
    $rows[] = [
        'file' => $name,
        'count' => $count,
        'database_calls' => $databaseCalls,
        'paginate_sql_literals' => $paginateLiterals,
        'threshold' => $warnAt,
        'mode' => 'warn',
        'status' => $count >= $warnAt ? 'WARN' : 'OK',
    ];
}

usort($rows, static function (array $a, array $b): int {
    $order = ['FAIL' => 0, 'WARN' => 1, 'PASS' => 2, 'OK' => 3];
    $cmp = ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
    return $cmp !== 0 ? $cmp : ($b['count'] <=> $a['count']);
});

if ($asJson) {
    echo json_encode([
        'strict_caps' => $strictCaps,
        'warn_at' => $warnAt,
        'blocked' => $blocked,
        'files' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($blocked ? 1 : 0);
}

echo "== 控制器内联 SQL 门禁 ==\n";
$strictNames = implode(', ', array_keys($strictCaps));
echo "统计口径: Database:: 静态调用 + paginate() 第 2/4 实参 SQL 字面量\n";
echo "严格上限: {$strictNames} <= 实际值 0（超出阻断）\n";
echo "宽松警戒线: 其他控制器 >= {$warnAt}（仅报告，不阻断）\n\n";
printf("%-6s %-38s %8s %10s\n", '状态', '文件', '调用数', '阈值');
printf("%s\n", str_repeat('-', 68));
foreach ($rows as $row) {
    $threshold = $row['mode'] === 'strict'
        ? "<=" . $row['threshold']
        : ">=" . $row['threshold'] . "(警戒)";
    printf("%-6s %-38s %8d %10s\n", $row['status'], $row['file'], $row['count'], $threshold);
}

$warnCount = count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'WARN'));
echo "\n";
echo $blocked
    ? "结论: 失败 —— 已收敛控制器出现新增内联 SQL（Database:: 调用或 paginate() SQL 字面量）\n"
    : "结论: 通过 —— 严格上限未破\n";
echo "警戒线命中文件数: {$warnCount}（不阻断，供分批收敛参考）\n";
exit($blocked ? 1 : 0);
