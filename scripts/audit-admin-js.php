<?php
/**
 * 后台 JS 静态审计 CLI 入口
 *
 * 用法：php scripts/audit-admin-js.php
 * 退出码：高危项 > 0 时为 1（可直接挂 CI / 提交前检查）
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminJsAuditor.php';

$root = dirname(__DIR__);
$auditor = new AdminJsAuditor($root . '/admin/js');
echo $auditor->report();
exit($auditor->isClean() ? 0 : 1);
