<?php
/**
 * P2-1 测试引导：定义最小常量桩 + 注册 PSR-4 自动加载器。
 *
 * 设计：引导只加载类定义（不连接数据库），路由断言测试通过静态解析 + 软反射
 * 校验 handler 合法性，可在不依赖 MySQL 的情况下运行，作为回归护栏起点。
 *
 * 运行方式（需 PHP >= 8.1 与 phpunit）：
 *   cd 项目根
 *   composer require --dev phpunit/phpunit
 *   ./vendor/bin/phpunit -c api/phpunit.xml
 */

declare(strict_types=1);

// 最小常量桩：生产由 config.php 定义；此处仅保证类文件可在无 DB 环境下被 autoload。
$constants = [
    'APP_DEBUG'            => false,
    'APP_NAME'             => 'Hublog',
    'JWT_SECRET'           => 'test-secret-for-unit-tests-only',
    'JWT_EXPIRE'           => 86400,
    'CACHE_TTL'            => 3600,
    'CACHE_DRIVER'         => 'file',
    'RATE_LIMIT_REQUESTS'  => 300,
    'RATE_LIMIT_WINDOW'    => 60,
    'CSRF_TOKEN_EXPIRE'    => 1800,
    'TRUSTED_PROXIES'      => '127.0.0.1,::1',
    'CORS_ALLOWED_ORIGINS' => '*',
    'MEDIA_BASE_URL'       => '',
];
foreach ($constants as $k => $v) {
    if (!defined($k)) {
        define($k, $v);
    }
}

require_once dirname(__DIR__) . '/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();

// 结构性测试用的「逻辑源码视图」（控制器 + 拆分出的 Trait），
// 避免 PostController 拆分后旧断言集体误报。见 SourceView.php 注释。
require_once __DIR__ . '/SourceView.php';

// 行为级测试底座（SQLite 内存库 + 隔离缓存），见 Behavior/BehaviorTestCase.php。
// 这里只加载基类定义，不建立连接、不改动任何全局状态；真正的注入发生在各用例 setUp()。
require_once __DIR__ . '/Behavior/BehaviorTestCase.php';
