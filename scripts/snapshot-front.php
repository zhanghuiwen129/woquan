<?php
/**
 * P1-5 本地快照工具：抓取前台真实渲染结果，供拆分前后做逐字节等价验证。
 *
 * 用法：
 *   php scripts/snapshot-front.php [输出目录]
 *
 * 默认输出目录：<项目根>/tmp/p15_golden
 * 需要预先启动内置服务器：
 *   D:/BtSoft/php/81/php.exe -S 127.0.0.1:8899 -t D:/woquan
 *
 * 说明：
 *   - 原始响应体写入 <输出目录>/<路由名>.html；
 *   - 归一化响应体写入 <输出目录>/normalized/<路由名>.html；
 *   - 清单 <输出目录>/manifest.json 记录每条路由的状态码、原始/归一化 sha256。
 * 归一化只处理两类每次请求都会变的字节：执行耗时与静态资源 ?v= 指纹。
 */

declare(strict_types=1);

const SNAPSHOT_HOST = 'http://127.0.0.1:8899';

/**
 * 需要抓取的路由表。
 *
 * @return array<string, array{uri: string, headers: array<int, string>}>
 */
function snapshotRoutes(): array
{
    $plain = [];

    return [
        'home' => ['uri' => '/index.php', 'headers' => $plain],
        'home_client_header' => [
            'uri' => '/index.php',
            'headers' => ['X-Client-Type: app'],
        ],
        'post' => ['uri' => '/index.php/p/1', 'headers' => $plain],
        'post_missing' => ['uri' => '/index.php/p/9999999', 'headers' => $plain],
        'category' => ['uri' => '/index.php/c/1', 'headers' => $plain],
        'search' => ['uri' => '/index.php/search?q=test', 'headers' => $plain],
        'user' => ['uri' => '/index.php/u/admin', 'headers' => $plain],
        'user_followers' => ['uri' => '/index.php/u/admin/followers', 'headers' => $plain],
        'chat' => ['uri' => '/index.php/chat', 'headers' => $plain],
        'topic' => ['uri' => '/index.php/topic/test', 'headers' => $plain],
        'robots' => ['uri' => '/index.php/robots.txt', 'headers' => $plain],
        'sitemap' => ['uri' => '/index.php/sitemap.xml', 'headers' => $plain],
        'unknown_path' => ['uri' => '/index.php/no-such-page', 'headers' => $plain],
    ];
}

/**
 * 抹掉每次请求必然变化的字节，其余字节保持原样。
 */
function normalizeSnapshot(string $html): string
{
    $html = preg_replace('/("execution_time":)[-+0-9.eE]+/', '$1"<T>"', $html) ?? $html;
    return preg_replace('/\?v=[0-9]+/', '?v=<V>', $html) ?? $html;
}

/**
 * 用 curl 扩展抓一条路由（显式关闭代理，等价于 curl --noproxy '*'）。
 *
 * @param array<int, string> $headers
 * @return array{status: int, body: string}
 */
function fetchRoute(string $uri, array $headers): array
{
    $ch = curl_init(SNAPSHOT_HOST . $uri);
    if ($ch === false) {
        throw new RuntimeException('curl_init 失败: ' . $uri);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body) || $error !== '') {
        throw new RuntimeException('抓取失败 ' . $uri . ': ' . $error);
    }

    return ['status' => $status, 'body' => $body];
}

/**
 * 主流程。
 *
 * @param array<int, string> $argv
 */
function main(array $argv): int
{
    $root = dirname(__DIR__);
    $outDir = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : $root . '/tmp/p15_golden';
    $normDir = rtrim($outDir, '/') . '/normalized';
    if (!is_dir($normDir) && !mkdir($normDir, 0777, true) && !is_dir($normDir)) {
        throw new RuntimeException('无法创建目录: ' . $normDir);
    }

    $manifest = [
        'captured_at' => date('c'),
        'host' => SNAPSHOT_HOST,
        'routes' => [],
    ];
    foreach (snapshotRoutes() as $name => $spec) {
        $result = fetchRoute($spec['uri'], $spec['headers']);
        $normalized = normalizeSnapshot($result['body']);
        file_put_contents(rtrim($outDir, '/') . '/' . $name . '.html', $result['body']);
        file_put_contents($normDir . '/' . $name . '.html', $normalized);
        $manifest['routes'][$name] = [
            'uri' => $spec['uri'],
            'headers' => $spec['headers'],
            'status' => $result['status'],
            'bytes' => strlen($result['body']),
            'raw_sha256' => hash('sha256', $result['body']),
            'normalized_sha256' => hash('sha256', $normalized),
            'normalized_bytes' => strlen($normalized),
        ];
        printf(
            "%-18s status=%d bytes=%d normalized_sha256=%s\n",
            $name,
            $result['status'],
            strlen($result['body']),
            $manifest['routes'][$name]['normalized_sha256']
        );
    }
    file_put_contents(rtrim($outDir, '/') . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return 0;
}

try {
    exit(main($argv));
} catch (\Throwable $e) {
    fwrite(STDERR, '[snapshot-front] ' . $e->getMessage() . "\n");
    exit(1);
}
