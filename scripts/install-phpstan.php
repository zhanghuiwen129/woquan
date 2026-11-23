<?php
/**
 * 下载 PHPStan 独立 phar 到 tools/（不入库）
 *
 * 为什么不用 composer：
 *   本项目没有 composer autoload，且部分部署环境禁用了 proc_open —— composer 跑不起来。
 *   PHPStan 官方提供独立 phar，放到 tools/ 下即可直接用：
 *     php tools/phpstan.phar analyse
 *
 * 下载源按可用性依次尝试：
 *   ① 阿里云 composer 镜像（国内可达）
 *   ② 腾讯云 composer 镜像
 *   ③ packagist 官方
 *   ④ GitHub Releases 直链
 *
 * 用法：
 *   php scripts/install-phpstan.php            # 下载/更新到 tools/phpstan.phar
 *   php scripts/install-phpstan.php --check    # 只检查是否已安装且能运行
 *
 * 退出码：0 = 已就绪；1 = 下载或校验失败。
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$target = $root . '/tools/phpstan.phar';
$php = PHP_BINARY;

if (in_array('--check', $argv, true)) {
    if (!is_file($target)) {
        fwrite(STDERR, "[phpstan] 未安装：{$target} 不存在（运行 php scripts/install-phpstan.php）\n");
        exit(1);
    }
    $version = trim((string)@shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($target) . ' --version 2>&1'));
    echo "[phpstan] 已安装：{$version}\n";
    exit(0);
}

if (!is_dir(dirname($target))) {
    @mkdir(dirname($target), 0755, true);
}

/** 用 PHP 流下载（不依赖 curl / unzip / composer） */
function fetch(string $url, string $to): bool
{
    $ctx = stream_context_create([
        'http' => [
            'timeout'        => 120,
            'user_agent'     => 'woquan-install-phpstan',
            'follow_location'=> 1,
            'max_redirects'  => 5,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) < 1_000_000) {
        return false;
    }
    return file_put_contents($to, $data) !== false;
}

$urls = [
    'https://mirrors.aliyun.com/composer/dists/phpstan/phpstan/2.1.12/96dde49e967c0c22812bcfa7bda4ff82c09f3b0c.zip',
    'https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar',
];

foreach ($urls as $url) {
    echo "[phpstan] 尝试下载: {$url}\n";
    $tmp = $target . '.tmp';
    if (!fetch($url, $tmp)) {
        @unlink($tmp);
        continue;
    }

    // 可能是 zip（composer dist）也可能是 phar
    $magic = (string)file_get_contents($tmp, false, null, 0, 4);
    if (str_starts_with($magic, "PK\x03\x04")) {
        $phar = extractPharFromZip($tmp);
        @unlink($tmp);
        if ($phar === null) {
            echo "[phpstan] zip 里没找到 phpstan.phar，换下一个源\n";
            continue;
        }
        file_put_contents($target, $phar);
    } else {
        rename($tmp, $target);
    }

    @chmod($target, 0755);
    $version = trim((string)@shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($target) . ' --version 2>&1'));
    if (str_contains($version, 'PHPStan')) {
        echo "[phpstan] 安装完成：{$version}\n";
        echo "[phpstan] 路径：{$target}（tools/ 不入库，需在 CI 上单独下载）\n";
        exit(0);
    }
    echo "[phpstan] 下载到的文件无法运行（{$version}），换下一个源\n";
    @unlink($target);
}

fwrite(STDERR, "[phpstan] 所有下载源都失败。可手动下载 phpstan.phar 并放到 tools/phpstan.phar\n");
exit(1);

/**
 * 从 zip 里取出 phpstan.phar。
 */
function extractPharFromZip(string $zipPath): ?string
{
    if (!class_exists(\ZipArchive::class)) {
        return null;
    }
    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return null;
    }
    $found = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (str_ends_with($name, 'phpstan.phar')) {
            $found = (string)$zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();
    return $found;
}
