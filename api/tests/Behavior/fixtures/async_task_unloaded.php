<?php
/**
 * 隔离子进程 fixture：模拟「插件被禁用 / 未加载」的队列 worker。
 *
 * 只注册框架 PSR-4 自动加载器，不 require 任何插件目录下的 Plugin.php。
 * Hublog\Plugin\ 命名空间没有注册到 AutoLoader，因此 class_exists() 为 false，
 * AsyncTask::runHandler() 应安静返回 false，而不是触发 autoload 致命错。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/api/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();

$ok = \Hublog\Core\AsyncTask::runHandler('ai_comment', ['post_id' => 1]);

echo json_encode(['ok' => $ok]);
