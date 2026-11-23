<?php
/**
 * Hublog 运行时体检（只读诊断 CLI）
 *
 * 【它解决什么问题】
 * 诊断报告 P1-4 的结论是「全仓零配置；每请求编译 ~340KB，稳态 QPS 上限被压低」。
 * 服务器侧的 php.ini 不在本仓库里，运维漏配 OPcache 时，代码里没有任何地方
 * 能暴露这件事 —— 只能等到「机器很慢」才去怀疑。本脚本把这份「不可见」
 * 变成一份可巡检的体检报告。
 *
 * 【它做什么 / 不做什么】
 *   - 只读：全程只读 ini 配置、扩展列表、OPcache 状态；
 *     唯一一次数据库访问是 SELECT，且仅在 config.php 存在时才会尝试；
 *   - 不改任何配置、不写任何文件、不重启任何服务；
 *   - 没有 config.php 也能跑：OPcache / 扩展 / ini 判定完全不需要连库。
 *
 * 【用法】
 *   php scripts/check-runtime.php
 *   php -d opcache.enable_cli=1 scripts/check-runtime.php   # 顺带采集缓存命中率
 *
 * 【退出码】
 *   0 = 全部关键项 OK
 *   1 = 有 WARN（可选项缺失 / 非关键项不达标）
 *   2 = 有 ERROR（pdo_mysql 缺失 / OPcache 未启用 / OPcache 关键配置不达标）
 *
 *   ⚠ ERROR 的判定范围严格限定在「运行时配置不达标」这一类：
 *     config.php 缺失、数据库连不上都只记 SKIP / WARN，不影响退出码 ——
 *     巡检脚本不该因为业务侧临时故障就报红。
 *
 * 【配套产物】
 *   OPcache 的推荐配置（每项都有「为什么是这个值 / 改了会怎样」的中文说明）见
 *   deploy/php-opcache.ini。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "本脚本只能在命令行下运行。\n");
    exit(1);
}

if (in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
    echo "用法: php scripts/check-runtime.php [--help]\n";
    echo "退出码: 0=全绿  1=有 WARN  2=有 ERROR\n";
    exit(0);
}

// ─────────────────────────────────────────────────────────────────────
// 输出辅助
// ─────────────────────────────────────────────────────────────────────

$rtErrors = 0;
$rtWarns  = 0;

/** 粗略的终端显示宽度（按 UTF-8 码点判断，CJK 记 2 列） */
function rt_width(string $s): int
{
    $w = 0;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = ord($s[$i]);
        if ($c < 0x80) {
            $w += 1;
        } elseif (($c & 0xE0) === 0xC0) {
            $w += 2;
            $i += 1;
        } elseif (($c & 0xF0) === 0xE0) {
            $w += 2;
            $i += 2;
        } elseif (($c & 0xF8) === 0xF0) {
            $w += 2;
            $i += 3;
        } else {
            $w += 1;
        }
    }
    return $w;
}

/** 按显示宽度右侧补空格 */
function rt_pad(string $s, int $width): string
{
    $w = rt_width($s);
    return $w >= $width ? $s : $s . str_repeat(' ', $width - $w);
}

/**
 * 打印一行体检结果。
 * ERROR 会让退出码变成 2，WARN 变成 1，INFO / OK / SKIP 不影响退出码。
 */
function rt_line(string $level, string $label, string $value = '', string $note = ''): void
{
    global $rtErrors, $rtWarns;
    if ($level === 'ERROR') {
        $rtErrors++;
    } elseif ($level === 'WARN') {
        $rtWarns++;
    }
    $line = '  [' . rt_pad($level, 5) . '] ' . rt_pad($label, 30);
    if ($value !== '') {
        $line .= $value;
    }
    if ($note !== '') {
        $line .= ($value !== '' ? '  —— ' : '—— ') . $note;
    }
    echo $line . PHP_EOL;
}

/** 段落标题 */
function rt_section(string $title): void
{
    echo PHP_EOL . '── ' . $title . ' ' . str_repeat('─', max(4, 62 - rt_width($title))) . PHP_EOL;
}

/** ini 值转字节数；-1 表示不限制 */
function rt_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $unit = strtolower(substr($value, -1));
    $num  = (float)$value;
    if ($unit === 'g') {
        $num *= 1024 * 1024 * 1024;
    } elseif ($unit === 'm') {
        $num *= 1024 * 1024;
    } elseif ($unit === 'k') {
        $num *= 1024;
    }
    return (int)$num;
}

/** 字节数转可读文本 */
function rt_human(int $bytes): string
{
    if ($bytes < 0) {
        return '不限制';
    }
    if ($bytes === 0) {
        return '0';
    }
    if ($bytes >= 1024 * 1024 * 1024) {
        return round($bytes / 1024 / 1024 / 1024, 2) . 'G';
    }
    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 1) . 'M';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . 'K';
    }
    return $bytes . 'B';
}

/**
 * 统计仓库里「可能被 OPcache 缓存」的 PHP 文件数。
 * 排除 .git 与运行时产物目录（缓存 / 日志 / 临时 / 上传），这些不是代码。
 */
function rt_count_php_files(string $root): int
{
    $skip = ['.git', 'cache', 'logs', 'tmp', 'uploads', '.phpunit.cache'];
    $count = 0;
    try {
        $rii = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $current) use ($skip): bool {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), $skip, true);
                    }
                    return true;
                }
            )
        );
        foreach ($rii as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $count++;
            }
        }
    } catch (\Throwable $e) {
        return -1;
    }
    return $count;
}

$root = dirname(__DIR__);

echo str_repeat('=', 66) . PHP_EOL;
echo ' Hublog 运行时体检 · 只读诊断' . PHP_EOL;
echo ' ' . date('Y-m-d H:i:s') . ' · PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')' . PHP_EOL;
echo str_repeat('=', 66) . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────
// 0. 配置加载（可选）
//    config.php 不存在时只跳过 DB 相关段落，绝不因此判定失败。
// ─────────────────────────────────────────────────────────────────────

$configFile   = $root . '/config.php';
$configLoaded = false;
$configError  = '';

if (is_file($configFile)) {
    try {
        require_once $configFile;
        $configLoaded = true;
    } catch (\Throwable $e) {
        $configError = $e->getMessage();
    }
}

// ─────────────────────────────────────────────────────────────────────
// 1. PHP 基础配置
// ─────────────────────────────────────────────────────────────────────

rt_section('1. PHP 基础');

rt_line('INFO', 'PHP 版本', PHP_VERSION);
rt_line('INFO', 'SAPI', php_sapi_name() . (PHP_SAPI === 'cli' ? '（命令行）' : '（Web SAPI）'));
rt_line('INFO', 'PHP 配置文件', (string)ini_get('loaded_config_file') ?: '（未加载 ini，使用内置默认值）');

$memoryLimit = (string)ini_get('memory_limit');
$memoryBytes = rt_bytes($memoryLimit);
if ($memoryBytes < 0) {
    rt_line('OK', 'memory_limit', $memoryLimit, '不限制内存；配合 zend.max_allowed_stack_size 使用时需自行留意');
} elseif ($memoryBytes < 64 * 1024 * 1024) {
    rt_line('WARN', 'memory_limit', $memoryLimit, '低于 64M：图片处理 / 批量导入容易触发 OOM，建议 ≥ 128M');
} else {
    rt_line('OK', 'memory_limit', $memoryLimit);
}

$maxExec = (string)ini_get('max_execution_time');
if ($maxExec === '0') {
    rt_line('INFO', 'max_execution_time', '0（不限制）', 'CLI 默认即为 0；Web SAPI 下才受此限制');
} else {
    rt_line('INFO', 'max_execution_time', $maxExec . 's', '视频压缩已异步化（V94），长请求风险已显著降低');
}

// 上传相关：以仓库自身的 UPLOAD_MAX_SIZE 为基准（config.php 缺失时用 10MB 默认值）
$uploadMaxSize = defined('UPLOAD_MAX_SIZE') ? (int)constant('UPLOAD_MAX_SIZE') : 10 * 1024 * 1024;
$uploadIni     = rt_bytes((string)ini_get('upload_max_filesize'));
$postIni       = rt_bytes((string)ini_get('post_max_size'));

if ($uploadIni >= 0 && $uploadIni < $uploadMaxSize) {
    rt_line('WARN', 'upload_max_filesize', (string)ini_get('upload_max_filesize'),
        '低于应用上限 ' . rt_human($uploadMaxSize) . '：超过该值的附件会被 PHP 直接丢弃且不报错');
} else {
    rt_line('OK', 'upload_max_filesize', (string)ini_get('upload_max_filesize'));
}

if ($postIni >= 0 && $uploadIni >= 0 && $postIni < $uploadIni) {
    rt_line('WARN', 'post_max_size', (string)ini_get('post_max_size'),
        '小于 upload_max_filesize：POST 体会被静默截断，表现为「表单提交后字段全空」');
} else {
    rt_line('OK', 'post_max_size', (string)ini_get('post_max_size'));
}

if ((string)ini_get('file_uploads') === '1') {
    rt_line('OK', 'file_uploads', 'On');
} else {
    rt_line('WARN', 'file_uploads', (string)ini_get('file_uploads'), '文件上传被关闭，后台上传功能不可用');
}

// disable_functions：被禁的进程/执行类函数会让一批工具链直接不可用
$disabledRaw = (string)ini_get('disable_functions');
$disabled    = array_values(array_filter(array_map('trim', explode(',', $disabledRaw)), static fn($f) => $f !== ''));
$criticalDisabled = array_values(array_intersect($disabled, [
    'proc_open', 'proc_get_status', 'proc_terminate', 'exec', 'shell_exec',
    'system', 'passthru', 'popen', 'pcntl_fork',
]));

if ($disabled === []) {
    rt_line('OK', 'disable_functions', '（未禁用任何函数）');
} elseif ($criticalDisabled !== []) {
    rt_line('WARN', 'disable_functions', $disabledRaw,
        '已禁用 ' . implode(', ', $criticalDisabled)
        . '：composer 依赖安装、并行/隔离子进程测试、部分 CLI 工具将不可用');
} else {
    rt_line('INFO', 'disable_functions', $disabledRaw, '未禁用进程/执行类函数');
}

// ─────────────────────────────────────────────────────────────────────
// 2. OPcache
// ─────────────────────────────────────────────────────────────────────

rt_section('2. OPcache');

$opcacheLoaded = extension_loaded('Zend OPcache');

if (!$opcacheLoaded) {
    rt_line('ERROR', '扩展', '未加载',
        '服务器需要安装 OPcache 扩展（Debian/Ubuntu: apt install php8.1-opcache），否则每请求都要重新编译全部 PHP 文件');
} else {
    rt_line('OK', '扩展', 'Zend OPcache 已加载');

    $opcacheEnable = (string)ini_get('opcache.enable');
    if ($opcacheEnable === '1') {
        rt_line('OK', 'opcache.enable', 'On');
    } else {
        rt_line('ERROR', 'opcache.enable', $opcacheEnable === '' ? '（未设置）' : 'Off',
            'OPcache 主开关未开启 = 缓存完全没生效。参考 deploy/php-opcache.ini 配置后重启 PHP-FPM');
    }

    $opcacheEnableCli = (string)ini_get('opcache.enable_cli');
    if ($opcacheEnableCli === '1') {
        rt_line('INFO', 'opcache.enable_cli', 'On', 'CLI 也会缓存文件');
    } else {
        rt_line('INFO', 'opcache.enable_cli', 'Off',
            'CLI 不走缓存（线上流量在 FPM，不受影响）；想读运行时命中率请用 php -d opcache.enable_cli=1 重跑');
    }

    // 关键配置 ①：共享内存
    $memConsumption = (int)ini_get('opcache.memory_consumption');
    if ($memConsumption < 64) {
        rt_line('ERROR', 'opcache.memory_consumption', $memConsumption . 'M',
            '低于 64M：缓存装不下会静默退化为「部分文件不缓存」，建议 128M 以上');
    } else {
        rt_line('OK', 'opcache.memory_consumption', $memConsumption . 'M');
    }

    // 关键配置 ②：可缓存文件数必须 ≥ 实际文件数
    $maxFiles  = (int)ini_get('opcache.max_accelerated_files');
    $fileCount = rt_count_php_files($root);
    if ($fileCount < 0) {
        rt_line('INFO', 'opcache.max_accelerated_files', (string)$maxFiles, '无法统计仓库文件数，跳过比对');
    } elseif ($maxFiles < $fileCount) {
        rt_line('ERROR', 'opcache.max_accelerated_files', (string)$maxFiles,
            '小于仓库实际 PHP 文件数 ' . $fileCount . '：超出部分永远进不了缓存（PHP 不会报错，只是静默重新编译）');
    } else {
        rt_line('OK', 'opcache.max_accelerated_files', $maxFiles . '（实际 ' . $fileCount . '）');
    }

    rt_line('INFO', 'opcache.validate_timestamps', (string)ini_get('opcache.validate_timestamps'),
        (string)ini_get('opcache.validate_timestamps') === '1'
            ? '开启：改代码后最多 revalidate_freq 秒生效'
            : '关闭：改代码必须重启 PHP-FPM 才生效');
    rt_line('INFO', 'opcache.revalidate_freq', (string)ini_get('opcache.revalidate_freq') . 's',
        '文件修改时间的复查间隔');
    rt_line('INFO', 'opcache.save_comments', (string)ini_get('opcache.save_comments'),
        '注释式反射（注解解析 / PHPUnit 的 @dataProvider）依赖它');
    rt_line('INFO', 'opcache.jit', (string)ini_get('opcache.jit'),
        'JIT 只对 CPU 密集型循环有效；本仓是 IO 密集型，建议保持 disable');
    rt_line('INFO', 'opcache.jit_buffer_size', rt_human((int)ini_get('opcache.jit_buffer_size')),
        '0 = JIT 未启用（缓冲为 0 时即使 jit=tracing 也不会编译 JIT 代码）');

    // 运行时状态
    $status = function_exists('opcache_get_status') ? opcache_get_status() : false;
    if (!is_array($status)) {
        rt_line('SKIP', '运行时缓存状态', '当前 SAPI 未启用 OPcache',
            '无法采集缓存脚本数与命中率；用 php -d opcache.enable_cli=1 重跑本脚本');
    } else {
        $stats     = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
        $cached    = (int)($stats['num_cached_scripts'] ?? 0);
        $hits      = (int)($stats['hits'] ?? 0);
        $misses    = (int)($stats['misses'] ?? 0);
        $hitRate   = (float)($stats['opcache_hit_rate'] ?? 0.0);
        $memUsed   = (int)($status['memory_usage'] ?? 0);

        rt_line('INFO', '已缓存脚本数', (string)$cached);
        rt_line('INFO', '缓存内存占用', rt_human($memUsed));
        rt_line('INFO', '缓存命中率', sprintf('%.2f%%（hits=%d / misses=%d）', $hitRate, $hits, $misses));

        if (!empty($status['cache_full'])) {
            rt_line('WARN', '缓存状态', '已满',
                '缓存表写满后新脚本进不去，请调大 opcache.memory_consumption 或 opcache.max_accelerated_files');
        }
        // 样本足够大才判定命中率 —— 刚重启时命中率为 0 属正常，不该误报
        if (($hits + $misses) >= 100 && $hitRate < 50.0) {
            rt_line('WARN', '缓存命中率', sprintf('%.2f%%', $hitRate),
                '偏低：缓存没真正起作用，优先检查 opcache.enable 与 max_accelerated_files');
        }
    }
}

// ─────────────────────────────────────────────────────────────────────
// 3. 关键扩展
// ─────────────────────────────────────────────────────────────────────

rt_section('3. 关键扩展');

// [扩展名 => [是否必需, 缺失时的说明]]
$extensions = [
    'pdo_mysql'  => [true,  '数据库驱动：缺失则整个站点无法工作（必须修）'],
    'mbstring'   => [false, '多字节字符串：中文处理 / 字符长度计算会退化'],
    'curl'       => [false, 'HTTP 客户端：支付回调校验、AI 接口、外部资源抓取依赖它'],
    'gd'         => [false, '图像处理（缩略图 / 水印）；缺失时若装有 imagick 可由其接管'],
    'imagick'    => [false, '图像处理（ImageMagick 绑定）；与 gd 二选一即可'],
    'json'       => [false, 'JSON 编解码：PHP 8 始终内置，几乎不可能缺失'],
    'zip'        => [false, 'ZIP 打包/解压：插件包、附件打包等场景依赖'],
    'fileinfo'   => [false, '文件类型识别：上传接口的 MIME 校验依赖它'],
    'openssl'    => [false, '加密扩展：部分随机数 / 证书 / 签名场景依赖'],
    'redis'      => [false, 'Redis 驱动：CACHE_DRIVER=redis 时必需，否则走文件缓存'],
    'pdo_sqlite' => [false, 'SQLite 驱动：仓库的行为测试套件（--testsuite behavior）依赖它'],
    'pcntl'      => [false, '进程控制：多进程并行 worker / 信号处理依赖'],
];

foreach ($extensions as $name => [$required, $why]) {
    if (extension_loaded($name)) {
        rt_line('OK', $name, '已安装');
        continue;
    }
    // gd 与 imagick 互为替代：两者都缺才算问题
    if (($name === 'gd' || $name === 'imagick') && (extension_loaded('gd') || extension_loaded('imagick'))) {
        rt_line('OK', $name, '未安装', '（已有等价扩展可用）');
        continue;
    }
    if ($required) {
        rt_line('ERROR', $name, '缺失', $why);
    } else {
        rt_line('WARN', $name, '缺失', $why);
    }
}

// ─────────────────────────────────────────────────────────────────────
// 4. 应用配置 / 数据库（可选段落）
// ─────────────────────────────────────────────────────────────────────

rt_section('4. 应用配置 / 数据库');

if (!$configLoaded) {
    rt_line('SKIP', 'config.php', $configError !== '' ? '存在但加载失败' : '未找到',
        $configError !== ''
            ? $configError . '（该错误不影响本脚本其余判定）'
            : '本仓尚未完成安装 / 部署；OPcache 与扩展判定不依赖它，故不影响退出码');
} else {
    rt_line('OK', 'config.php', '已加载');
}

// 自动加载器只用于可选的 CronAuth 检测；文件不在（极简环境）时跳过，不影响其余判定
$autoloaderFile = $root . '/api/core/AutoLoader.php';
if (is_file($autoloaderFile)) {
    require_once $autoloaderFile;
    \Hublog\Core\AutoLoader::register();
}

if ($configLoaded) {
    try {
        \Hublog\Core\Database::getInstance();
        rt_line('OK', '数据库连接', '正常');
        rt_line('INFO', '数据库', (string)(defined('DB_NAME') ? constant('DB_NAME') : '-')
            . '@' . (string)(defined('DB_HOST') ? constant('DB_HOST') : '-'));
    } catch (\Throwable $e) {
        rt_line('WARN', '数据库连接', '失败', $e->getMessage());
    }
} else {
    rt_line('SKIP', '数据库连接', '跳过', '未加载 config.php，无法连接');
}

// cron_secret 配置状态（唯一真源在 api/core/CronAuth.php）
if (!class_exists(\Hublog\Core\CronAuth::class)) {
    rt_line('SKIP', 'cron_secret', '无法判定', '找不到 api/core/CronAuth.php（极简环境）');
} elseif (!$configLoaded) {
    rt_line('SKIP', 'cron_secret', '无法判定', '未加载 config.php：既读不到数据库，也读不到 CRON_SECRET 常量');
} else {
    try {
        $cronConfigured = \Hublog\Core\CronAuth::isConfigured();
        $sourceText     = [
            'constant' => 'config.php 常量 CRON_SECRET',
            'database' => '数据库 settings.cron_secret',
            'none'     => '未配置',
        ][\Hublog\Core\CronAuth::source()];

        if ($cronConfigured) {
            rt_line('OK', 'cron_secret', '已配置', '来源：' . $sourceText);
        } else {
            rt_line('WARN', 'cron_secret', '未配置',
                '三条 cron（/api/cron/jobs、/api/cron/scheduled、/api/cron/badges）会一律 403；'
                . '请在后台「系统设置 → 定时发布调度 Token」填写，或在 config.php 中 define(\'CRON_SECRET\', ...)');
        }
    } catch (\Throwable $e) {
        rt_line('WARN', 'cron_secret', '检测失败', $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────
// 汇总
// ─────────────────────────────────────────────────────────────────────

echo PHP_EOL . str_repeat('-', 66) . PHP_EOL;
if ($rtErrors > 0) {
    echo ' 结论：' . $rtErrors . ' 项 ERROR、' . $rtWarns . ' 项 WARN —— 关键配置不达标，需要处理。' . PHP_EOL;
    echo ' 参考：deploy/php-opcache.ini（含每项取值理由），改完记得重启 PHP-FPM。' . PHP_EOL;
    exit(2);
}
if ($rtWarns > 0) {
    echo ' 结论：关键项全部 OK，另有 ' . $rtWarns . ' 项 WARN —— 可选项缺失，不影响主流程。' . PHP_EOL;
    exit(1);
}
echo ' 结论：全部关键项 OK，无 WARN。' . PHP_EOL;
exit(0);
