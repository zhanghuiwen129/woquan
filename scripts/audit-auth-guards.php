<?php
/**
 * 鉴权守卫审计（P2 双重守卫收敛）
 *
 * 治理目标：把「路由中间件已校验 → 控制器又校验一次」的双重守卫收敛掉，
 * 同时**防止它重新长回来**。
 *
 * 现状（P2 治理完成时，V97 前）：
 *   - requireUser() 40 处、requireAdmin() 118 处；
 *   - 另有直接写 Auth::check() 105 处、Auth::admin() 6 处；
 *   - 每个后台请求因此重复查一次 users 表（中间件查一次、控制器再查一次）。
 *
 * 收敛后的形态：
 *   - 中间件与控制器仍**都保留**（控制器守卫是纵深防御，不能删）；
 *   - 但 Auth 对同一请求内的同一 token 做记忆化，第二次起不再查库
 *     → 重复守卫的**成本**归零，安全性一分不减。
 *
 * 本脚本是 CI 门禁：
 *   1. 统计各处守卫调用点与分布，输出报告；
 *   2. 与基线比对：调用点数量不得超过基线（防止新的双重守卫长回来）；
 *   3. 校验 Auth 仍保留请求内记忆化（防止有人为了「简化」把记忆化删掉，
 *      那会让双重守卫重新变成双倍查库）。
 *
 * 退出码：0 = 通过；1 = 超基线或记忆化被移除。
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * 守卫调用点基线（治理完成时的实测值；V97 已同步新增的真实守卫）。
 * 允许减少，不允许增长 —— 新增请先说明为什么不能靠中间件解决。
 *
 * V97 同步说明：ContentPayController 新增 5 处 requireUser、
 * AdminContentPayController 新增 7 处 requireAdmin，均已逐条核实为真实守卫调用，
 * 基线据此由 40/118 调整为 45/125。
 *
 * 批次 7 同步说明：requireAdmin 实测由 125 增至 141（+16），逐处复核确认均为
 * 前几批安全加固新增的真实控制器守卫（不是误计），基线据此更新为 141。
 *
 * 扩展闭环同步说明：requireAdmin 实测由 141 增至 154（+13）—— T04 新增
 * AdminPageController 5 处（/api/admin/pages 五个写/读口），T03 新增
 * AdminPluginController 7 处 + PluginAdminSettingsController 1 处（插件生命周期、
 * 配置与设置页）。这些路由虽已在 /api/admin 组走 AdminMiddleware，仍按全站后台
 * 控制器惯例在方法入口再做 requireAdmin() 双重确认，逐处复核均为真实守卫调用。
 *
 * V102 同步说明：requireUser 实测由 45 增至 46（+1），系 V97 之后批次新增的真实
 * 控制器守卫未同步基线；逐文件核对 7 个控制器（Badge/Circle/ContentPay/Payment/
 * Point/PostFeedTrait/Shop）= 1+18+5+9+7+1+5 = 46，均为 $this->requireUser( 真实调用。
 * 旧基线 45 使本门禁恒失败（等于无门禁），据此更新为 46。
 */
const BASELINE = [
    'requireUser' => 46,
    'requireAdmin' => 154,
    'Auth::check' => 105,
    'Auth::admin' => 6,
];

$controllerDir = $root . '/api/controllers';
$files = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllerDir));
foreach ($rii as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}
sort($files);

$patterns = [
    'requireUser'   => '/\$this->requireUser\s*\(/',
    'requireAdmin'  => '/\$this->requireAdmin\s*\(/',
    'Auth::check'   => '/Auth::check\s*\(/',
    'Auth::admin'   => '/Auth::admin\s*\(/',
];

$counts = array_fill_keys(array_keys($patterns), 0);
$byFile = [];

foreach ($files as $path) {
    $src = (string)file_get_contents($path);
    $rel = str_replace($root . '/', '', $path);
    foreach ($patterns as $name => $pattern) {
        $n = preg_match_all($pattern, $src);
        if ($n > 0) {
            $counts[$name] += $n;
            $byFile[$rel][$name] = $n;
        }
    }
}

echo "[auth] " . date('c') . " 鉴权守卫审计\n";
echo "[auth] 扫描控制器文件: " . count($files) . " 个\n\n";

printf("[auth] %-16s %8s %8s %8s\n", '守卫类型', '当前', '基线', '差额');
$failed = false;
foreach ($patterns as $name => $pattern) {
    $cur = $counts[$name];
    $base = BASELINE[$name];
    $delta = $cur - $base;
    printf("[auth] %-16s %8d %8d %+8d\n", $name, $cur, $base, $delta);
    if ($cur > $base) {
        $failed = true;
    }
}

echo "\n[auth] 各文件分布（Top 20）\n";
uasort($byFile, static function (array $a, array $b): int {
    return array_sum($b) <=> array_sum($a);
});
$shown = 0;
foreach ($byFile as $rel => $row) {
    if ($shown >= 20) {
        break;
    }
    $parts = [];
    foreach ($row as $name => $n) {
        $parts[] = "{$name}×{$n}";
    }
    echo "[auth]   {$rel}: " . implode(', ', $parts) . "\n";
    $shown++;
}

// 记忆化护栏：Auth 必须保留请求内记忆化，否则双重守卫会变回双倍查库
$authSrc = (string)file_get_contents($root . '/api/core/Auth.php');
$hasMemo = str_contains($authSrc, 'self::$checkedUserToken === $token')
    && str_contains($authSrc, 'self::$currentUserToken === $token');
echo "\n[auth] 请求内记忆化: " . ($hasMemo ? '在' : '缺失') . "\n";
if (!$hasMemo) {
    echo "[auth]   ✗ Auth 缺少请求内记忆化 —— 双重守卫会重新变成每请求双倍查库\n";
    $failed = true;
}

// clearUserCache 必须让记忆化失效（封禁 / 改密码后不能继续用旧身份）
$hasInvalidate = str_contains($authSrc, 'self::forgetRequestMemo();');
echo "[auth] 封禁/改密码后失效: " . ($hasInvalidate ? '在' : '缺失') . "\n";
if (!$hasInvalidate) {
    echo "[auth]   ✗ clearUserCache() 没有让请求内记忆化失效\n";
    $failed = true;
}

if ($failed) {
    echo "\n[auth] ✗ 审计未通过\n";
    exit(1);
}

echo "\n[auth] ✓ 审计通过\n";
exit(0);
