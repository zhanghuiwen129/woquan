<?php
/**
 * P1-5 本地快照比对工具：逐路由比较归一化后的响应体（字节级）。
 *
 * 用法：
 *   php scripts/compare-front-snapshots.php [基线目录] [当前目录]
 *
 * 默认：基线 tmp/p15_golden，当前 tmp/p15_current。
 * 任一条路由的状态码或归一化字节不同即退出码 1。
 */

declare(strict_types=1);

/**
 * 读取清单。
 *
 * @return array<string, mixed>
 */
function loadManifest(string $dir): array
{
    $file = rtrim($dir, '/') . '/manifest.json';
    if (!is_file($file)) {
        throw new RuntimeException('缺少清单: ' . $file);
    }
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data) || !isset($data['routes']) || !is_array($data['routes'])) {
        throw new RuntimeException('清单格式错误: ' . $file);
    }
    return $data;
}

/**
 * 主流程。
 *
 * @param array<int, string> $argv
 */
function main(array $argv): int
{
    $root = dirname(__DIR__);
    $golden = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : $root . '/tmp/p15_golden';
    $current = isset($argv[2]) && $argv[2] !== '' ? $argv[2] : $root . '/tmp/p15_current';

    $goldenManifest = loadManifest($golden);
    $currentManifest = loadManifest($current);
    $goldenRoutes = $goldenManifest['routes'];
    $currentRoutes = $currentManifest['routes'];

    $failed = 0;
    printf("%-18s %-8s %-10s %-10s %s\n", 'ROUTE', 'STATUS', 'BYTES', 'SHA256', 'RESULT');
    foreach ($goldenRoutes as $name => $goldenInfo) {
        $goldenFile = rtrim($golden, '/') . '/normalized/' . $name . '.html';
        $currentFile = rtrim($current, '/') . '/normalized/' . $name . '.html';
        if (!isset($currentRoutes[$name])) {
            printf("%-18s %-8s %-10s %-10s %s\n", $name, '-', '-', '-', 'MISSING');
            $failed++;
            continue;
        }
        $currentInfo = $currentRoutes[$name];
        $goldenBody = is_file($goldenFile) ? (string)file_get_contents($goldenFile) : null;
        $currentBody = is_file($currentFile) ? (string)file_get_contents($currentFile) : null;

        $statusOk = $goldenInfo['status'] === $currentInfo['status'];
        $bytesOk = $goldenBody !== null && $currentBody !== null && $goldenBody === $currentBody;
        $ok = $statusOk && $bytesOk;
        if (!$ok) {
            $failed++;
        }
        printf(
            "%-18s %-8s %-10s %-10s %s\n",
            $name,
            $goldenInfo['status'] . '/' . $currentInfo['status'],
            $goldenInfo['normalized_bytes'] . '/' . $currentInfo['normalized_bytes'],
            substr((string)$goldenInfo['normalized_sha256'], 0, 8) . '/' . substr((string)$currentInfo['normalized_sha256'], 0, 8),
            $ok ? 'OK (0 diff)' : ($statusOk ? 'BYTE DIFF' : 'STATUS+BYTE DIFF')
        );
        if (!$ok && $goldenBody !== null && $currentBody !== null) {
            $diffFile = rtrim($current, '/') . '/' . $name . '.diff.txt';
            $max = max(strlen($goldenBody), strlen($currentBody));
            $offset = 0;
            while ($offset < $max && $offset < strlen($goldenBody) && $offset < strlen($currentBody)
                && $goldenBody[$offset] === $currentBody[$offset]) {
                $offset++;
            }
            file_put_contents($diffFile, sprintf(
                "first difference at byte %d\n--- golden ---\n%s\n--- current ---\n%s\n",
                $offset,
                substr($goldenBody, max(0, $offset - 120), 400),
                substr($currentBody, max(0, $offset - 120), 400)
            ));
            fwrite(STDERR, '  首差异字节 ' . $offset . '，详情: ' . $diffFile . "\n");
        }
    }
    foreach ($currentRoutes as $name => $unused) {
        if (!isset($goldenRoutes[$name])) {
            printf("%-18s %-8s %-10s %-10s %s\n", $name, '-', '-', '-', 'EXTRA');
            $failed++;
        }
    }

    $total = count($goldenRoutes);
    printf("\n结果: %d/%d 路由一致，失败 %d 条\n", $total - $failed, $total, $failed);
    return $failed === 0 ? 0 : 1;
}

try {
    exit(main($argv));
} catch (\Throwable $e) {
    fwrite(STDERR, '[compare-front-snapshots] ' . $e->getMessage() . "\n");
    exit(1);
}
