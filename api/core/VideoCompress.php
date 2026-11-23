<?php
/**
 * Hublog 视频压缩工具类
 *
 * 背景：手机拍的视频动辄几 MB~几十 MB，直传既慢又占空间。客户端（App 端）已有
 * uni.compressVideo 本地压缩，但 H5 端没有该能力、且部分机型压缩失败，所以服务端
 * 必须有一层兜底压缩。PHP 本身无法处理视频，唯一可行手段是 ffmpeg。
 *
 * 生产环境实测（2026-10-18 FTP 探针验证）：
 *   - ffmpeg 真实路径是 /www/server/ffmpeg/ffmpeg-6.1/ffmpeg（宝塔安装），
 *     而 PHP 进程的 PATH 只有 /usr/local/bin:/usr/bin —— `which ffmpeg` 永远找不到它。
 *     旧代码正是用 `which ffmpeg` 探测，导致服务端压缩从未真正生效。
 *   - open_basedir 限制 PHP 的文件函数只能访问站点根目录 + /tmp，
 *     所以不能用 file_exists() 判断 ffmpeg 是否存在（会被拦并刷 warning），
 *     探测只能交给 shell 执行；ffmpeg 的输出文件也必须落在 /tmp。
 *   - 旧命令 `-vf "scale=if(gte(iw,ih),...)"` 里的逗号会被 ffmpeg 当成滤镜分隔符，
 *     报 `No such filter: 'ih)'` 必然失败，故改用无逗号的 force_original_aspect_ratio 写法。
 */

namespace Hublog\Core;

class VideoCompress
{
    /**
     * 压缩后端候选（多管齐下：前一个不可用就自动换下一个）。
     * 每个后端给一组常见安装路径——Web 进程的 PATH 往往不含这些目录
     * （生产实测 PATH 只有 /usr/local/bin:/usr/bin，而 ffmpeg 在 /www/server/...），
     * 所以不能依赖 `which`，必须按绝对路径逐个探测。
     */
    private const BACKENDS = [
        'ffmpeg' => [
            '/www/server/ffmpeg/ffmpeg-6.1/ffmpeg',
            '/www/server/ffmpeg/ffmpeg',
            '/www/server/ffmpeg/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/usr/bin/ffmpeg',
            '/opt/ffmpeg/bin/ffmpeg',
        ],
        // 兼容后端：avconv 是 ffmpeg 的分支，命令行参数基本一致
        'avconv' => [
            '/usr/bin/avconv',
            '/usr/local/bin/avconv',
        ],
    ];

    /**
     * 编码器回退链：某些精简版 ffmpeg 缺 libx264，此时自动换下一个编码器。
     * 每组是一段完整的视频编码参数（mpeg4 不支持 -crf，改用 -q:v）。
     */
    private const ENCODER_TRIES = [
        '-c:v libx264 -preset veryfast -crf 28',
        '-c:v libx265 -preset veryfast -crf 30',
        '-c:v mpeg4 -q:v 5',
    ];

    /** 压缩参数：分辨率长边封顶 / 帧率 */
    private const MAX_SIDE = 1280;
    private const FPS = 25;

    /**
     * 允许被原地替换的视频扩展名。
     *
     * 异步压缩任务执行前会校验目标文件：
     * 扩展名必须在白名单内 + 路径必须位于 uploads/ 目录内，
     * 避免任务参数被篡改后覆盖站点外的任意文件。
     */
    public const ALLOWED_VIDEO_EXTS = [
        'mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v',
        '3gp', '3g2', 'mpg', 'mpeg', 'ogv', 'rm', 'rmvb', 'asf', 'ts',
        'mts', 'm2ts',
    ];

    /** @var array<string,string> 后端名 → 可执行文件路径（已探测成功的缓存） */
    private static $binCache = [];

    /**
     * 定位某个后端的可执行文件；找不到返回空字符串。
     * 探测只能靠 shell：open_basedir 下 PHP 的 file_exists() 访问不到站点根以外的路径。
     */
    public static function findBin(string $backend = 'ffmpeg'): string
    {
        if (isset(self::$binCache[$backend])) {
            return self::$binCache[$backend];
        }
        // 必须判存在性：部分主机把 shell_exec 列进 disable_functions，
        // 直接调用会抛 Error（不是返回 false），整个上传就 500 了
        if (!function_exists('shell_exec')) {
            Logger::warning('[Hublog VideoCompress] shell_exec 不可用（可能被 disable_functions 禁用），跳过视频压缩');
            return self::$binCache[$backend] = '';
        }
        $bin = '';
        foreach (self::BACKENDS[$backend] ?? [] as $candidate) {
            $out = @shell_exec(escapeshellarg($candidate) . ' -version 2>&1');
            if (is_string($out) && preg_match('/^(ffmpeg|avconv) version/i', trim($out))) {
                $bin = $candidate;
                break;
            }
        }
        if ($bin === '' && $backend === 'ffmpeg') {
            // 兜底：某些环境二进制确实就在 PATH 里（用 command -v 而非 which，更通用）
            $found = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
            if ($found !== '' && strpos($found, '/') === 0) {
                $bin = $found;
            }
        }
        if ($bin !== '') {
            Logger::info('[Hublog VideoCompress] 后端可用: ' . $backend . ' => ' . $bin);
        }
        return self::$binCache[$backend] = $bin;
    }

    /**
     * 压缩视频文件。
     *
     * @param string $sourcePath 源视频（一般是 PHP 上传临时文件）
     * @return string|null 成功返回压缩产物的绝对路径（在 /tmp 下，调用方用完要 @unlink）；
     *                     失败 / 环境不支持 / 压缩没带来明显收益时返回 null。
     */
    public static function compress(string $sourcePath): ?string
    {
        if ($sourcePath === '' || !is_file($sourcePath)) {
            return null;
        }
        if (!function_exists('exec')) {
            return null;
        }
        $origSize = (int)@filesize($sourcePath);
        if (!$origSize) {
            return null;
        }

        // 多管齐下：后端（ffmpeg → avconv）× 编码器（libx264 → libx265 → mpeg4）
        // 任一组合成功且确有收益就返回；全都不行则返回 null，调用方按「原样落盘」处理，
        // 上传不会被阻塞——这是最重要的一条底线。
        foreach (array_keys(self::BACKENDS) as $backend) {
            $bin = self::findBin($backend);
            if ($bin === '') {
                continue;
            }
            foreach (self::ENCODER_TRIES as $encArgs) {
                $destPath = sys_get_temp_dir() . '/hublog_vid_' . bin2hex(random_bytes(8)) . '.mp4';
                // 无逗号写法：等比缩放到长边不超过 MAX_SIDE（横版 1280x720、竖版 720x1280），
                // 偶数对齐；降帧到 25fps；音频统一 AAC 128k。
                // （旧命令用 scale=if(gte(iw,ih),...) —— 逗号会被 ffmpeg 当滤镜分隔符，
                //   必然报 `No such filter: 'ih)'`，所以这里绝不能出现逗号。）
                $cmd = sprintf(
                    '%s -y -i %s -vf "scale=%d:%d:force_original_aspect_ratio=decrease:force_divisible_by=2" -r %d %s -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
                    $bin,
                    escapeshellarg($sourcePath),
                    self::MAX_SIDE,
                    self::MAX_SIDE,
                    self::FPS,
                    $encArgs,
                    escapeshellarg($destPath)
                );

                set_time_limit(300);
                $output = [];
                $code = 0;
                $t0 = microtime(true);
                @exec($cmd, $output, $code);
                $sec = round(microtime(true) - $t0, 2);

                $ok = $code === 0 && is_file($destPath) && @filesize($destPath) > 0;
                $newSize = $ok ? (int)filesize($destPath) : 0;

                if ($ok && $newSize < $origSize * 0.9) {
                    Logger::info(sprintf(
                        '[Hublog VideoCompress] 压缩完成 [%s / %s]: %d -> %d bytes (%.1f%%), 耗时 %ss',
                        $backend,
                        $encArgs,
                        $origSize,
                        $newSize,
                        ($newSize / $origSize) * 100,
                        $sec
                    ));
                    return $destPath;
                }

                if (is_file($destPath)) {
                    @unlink($destPath);
                }
                Logger::info(sprintf(
                    '[Hublog VideoCompress] 尝试失败 [%s / %s] code=%d 耗时%ss %s',
                    $backend,
                    $encArgs,
                    $code,
                    $sec,
                    $ok ? '（压缩无收益，换下一种）' : '尾部输出: ' . substr(trim(implode("\n", $output)), -200)
                ));
            }
        }

        Logger::info('[Hublog VideoCompress] 所有压缩方式均不可用/无收益，原样保留: ' . $sourcePath);
        return null;
    }

    /**
     * 原地压缩：把 sourcePath 压成 targetPath，压缩产物直接替换目标文件。
     *
     * 【用途】视频压缩异步化（V94）。
     * 压缩是耗时操作（最坏几十分钟），放在请求内会：
     *   - 让用户上传视频后干等，可能直接超时；
     *   - 长期占住 PHP-FPM worker。
     * 所以请求内只把视频原样落盘并入队，压缩交给 cron worker 异步做。
     *
     * 【安全约束】
     *   1. 只允许替换「扩展名合法且位于 uploads 目录内」的目标文件，
     *      避免任务参数被篡改后覆盖任意系统文件；
     *   2. 压缩产物先写临时文件，成功后再 rename 覆盖 —— rename 是原子操作，
     *      不会出现「读到半个文件」；
     *   3. 只有确实变小了才替换（原文件已是最佳画质时保留原样）。
     *
     * @return bool true = 压缩成功且已替换；false = 未压缩（环境不支持 / 无收益 / 出错）
     */
    public static function compressInPlace(string $sourcePath, string $targetPath): bool
    {
        if ($sourcePath === '' || $targetPath === '') {
            return false;
        }
        if (!is_file($sourcePath)) {
            Logger::warning('[Hublog VideoCompress] compressInPlace: 源文件不存在 ' . $sourcePath);
            return false;
        }
        if (!is_file($targetPath)) {
            Logger::warning('[Hublog VideoCompress] compressInPlace: 目标文件不存在 ' . $targetPath);
            return false;
        }

        $compressed = self::compress($sourcePath);
        if ($compressed === null) {
            return false; // 环境不支持或压缩无收益
        }

        try {
            $newSize = (int)@filesize($compressed);
            $oldSize = (int)@filesize($targetPath);
            if ($newSize <= 0 || $newSize >= $oldSize) {
                // 没有收益就别覆盖 —— 原视频可能已经是客户端压好的高质量版本
                @unlink($compressed);
                return false;
            }

            if (!@rename($compressed, $targetPath)) {
                // rename 跨文件系统会失败，退回复制
                if (!@copy($compressed, $targetPath)) {
                    @unlink($compressed);
                    Logger::error('[Hublog VideoCompress] compressInPlace: 替换目标文件失败 ' . $targetPath);
                    return false;
                }
                @unlink($compressed);
            }

            Logger::info(sprintf(
                '[Hublog VideoCompress] 原地压缩完成: %d -> %d bytes (%.1f%%) %s',
                $oldSize,
                $newSize,
                ($newSize / max(1, $oldSize)) * 100,
                $targetPath
            ));
            return true;
        } catch (\Throwable $e) {
            @unlink($compressed);
            Logger::error('[Hublog VideoCompress] compressInPlace 异常: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 校验「可以安全被原地替换」的目标文件。
     *
     * 任务参数来自 jobs.payload，虽然写入路径是自己控制的，但 worker 执行前
     * 仍要确认目标文件在 uploads 目录内、扩展名是允许的视频格式 ——
     * 万一有别处能写 jobs 表，也不能借此覆盖站点外的文件。
     */
    public static function isSafeTarget(string $targetPath): bool
    {
        if ($targetPath === '') {
            return false;
        }
        $ext = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_VIDEO_EXTS, true)) {
            return false;
        }
        $base = PathResolver::basePath();
        $real = realpath($targetPath);
        if ($real === false) {
            return false;
        }
        $uploadsDir = realpath($base . '/uploads');
        if ($uploadsDir === false) {
            return false;
        }
        return str_starts_with($real, $uploadsDir . DIRECTORY_SEPARATOR);
    }
}
