<?php
/**
 * Hublog 文件上传处理类
 * MIME + 文件头 + 尺寸三重校验，临时文件安全清理
 */

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;

class Upload
{
    /**
     * S2：调用方未显式传白名单时的安全兜底（文档/压缩包，绝无可渲染、可执行类型）。
     * 与 config.php 的 UPLOAD_ATTACHMENT_EXTS 保持一致；常量未定义时用这份。
     */
    private const DEFAULT_ALLOWED_EXTS = [
        'zip', 'rar', '7z', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt',
    ];

    /**
     * S2：无论调用方传了什么白名单，这些后缀一律拒绝。
     * 覆盖脚本/服务端解析后缀、浏览器可渲染的同源 XSS 载体（html/svg/xml）、
     * 以及 Apache mod_mime 多后缀可能误解析的中间后缀。
     */
    private const FORBIDDEN_EXTS = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phtm',
        'phar', 'phps', 'inc', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx',
        'jsp', 'jspx', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl',
        'js', 'mjs', 'css', 'swf', 'hta', 'htaccess', 'ini', 'conf', 'sql',
        'exe', 'dll', 'bat', 'cmd', 'com', 'scr', 'vbs',
    ];

    /** 通用附件默认白名单（供控制器显式引用，避免各处硬编码不一致） */
    public static function defaultAllowedExts(): array
    {
        $exts = defined('UPLOAD_ATTACHMENT_EXTS')
            ? (array)\UPLOAD_ATTACHMENT_EXTS
            : self::DEFAULT_ALLOWED_EXTS;
        return array_values(array_map('strtolower', $exts));
    }

    /**
     * 检测文件真实 MIME 类型
     * 优先使用 fileinfo 扩展，不可用时回退到 mime_content_type()，均不可用时返回 null
     */
    private static function detectMime(string $filePath): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            return $mime ?: null;
        }
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($filePath);
            return $mime ?: null;
        }
        return null;
    }

    /**
     * 上传图片
     * 支持基于文件哈希的秒传去重：相同内容的图片只保存一份物理文件
     *
     * P2-11 变更：返回值从 string 改为 array{url:string, file_hash:string}，
     *   调用方必须使用返回的 file_hash 写入 uploads 表，不再信任客户端 file_hash 参数。
     *   原 $fileHash 参数保留（仅作为旧签名兼容，内部不采用）。
     * @return array{0:string, 1:string} [0]=访问URL，[1]=服务端重算后的 SHA256
     */
    public static function image(array $file, string $dir, int $maxWidth = 1920, int $maxHeight = 1920, bool $compress = true, ?int $maxSize = null, ?string $fileHash = null, bool $isAvatar = false): array
    {
        self::validateFile($file, $maxSize);

        // 获取真实 MIME 类型（fileinfo 不可用时降级，后续由文件头+GD 校验兜底）
        $realMime = self::detectMime($file['tmp_name']);

        if ($realMime !== null && !in_array($realMime, \UPLOAD_IMAGE_TYPES, true)) {
            throw new HublogException('不支持的图片格式', 400);
        }

        // 验证文件头
        $handle = fopen($file['tmp_name'], 'rb');
        $header = fread($handle, 12);
        fclose($handle);

        if (!self::validateImageHeader($header, $realMime)) {
            throw new HublogException('文件头验证失败', 400);
        }
        // 通过 GD 验证图片真实尺寸与可解析性
        $imgInfo = @getimagesize($file['tmp_name']);
        if ($imgInfo === false) {
            throw new HublogException('无法解析的图片文件', 400);
        }

        // fileinfo 不可用时，从 GD 解析结果补全 MIME 类型
        if ($realMime === null) {
            $realMime = $imgInfo['mime'] ?? null;
        }

        // P2-11 修复：不信任客户端传入的 $fileHash，一律在本地 hash_file('sha256', tmp_name) 重算。
        // 原实现如果直接采用客户端预计算哈希，攻击者可提交一个他人已存在文件的 hash → 秒传直接返回该 URL，
        // 造成「越权获取他人私有文件 URL + 可能获得防盗链资源访问」。
        // 即便客户端传入了正确的 hash，重算的时间成本也可接受（1MB 图片约 1ms 级）。
        $hash = @hash_file('sha256', $file['tmp_name']) ?: '';

        // 秒传：查找已存在的相同图片且未被下架（必须验证物理文件存在）
        if ($hash) {
            $existing = Database::fetchOne(
                "SELECT url FROM uploads WHERE file_hash = ? AND type = 'image' AND status = 1 ORDER BY id ASC LIMIT 1",
                [$hash]
            );
            if ($existing && !empty($existing['url']) && self::uploadFileExists($existing['url'])) {
                if (file_exists($file['tmp_name'])) {
                    unlink($file['tmp_name']);
                }
                // 【P0-UPLOAD】秒传命中必须返回数组 [url, hash]，与函数签名 : array 一致；
                // 原实现返回 string => TypeError => HTTP 500（重复上传相同图片时必现）
                return [$existing['url'], $hash];
            }
        }

        // 生成安全文件名
        $ext = match ($realMime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg'
        };

        // WebP 转换：后台开启后，新上传的 JPEG/PNG 统一编码为 WebP（体积通常小 25%~35%）。
        // 默认关闭——存量客户端与外链都假设原格式，默认改格式属于破坏性变更。
        // GIF 不转：动画 GIF 转 WebP 需要额外处理帧，GD 不支持。
        if (
            $ext !== 'webp'
            && $ext !== 'gif'
            && self::webpEnabled()
            && function_exists('imagewebp')
        ) {
            $ext = 'webp';
        }

        $filename = Security::randomString(16) . '.' . $ext;
        [$dir, $relPath] = self::buildDateDir($dir);
        $filepath = $dir . '/' . $filename;

        try {
            // P2-9：为非头像图片生成 small/medium 缩略图变体，供列表场景替代原图直出，
            // 缓解「列表 9 图 = 9 张 1920px 原图」的带宽与解码压力。
            // 缩略图生成失败（格式不支持等）降级为 null，不影响主图上传。
            $thumbSmallUrl = null;
            $thumbMediumUrl = null;
            if (!$isAvatar) {
                try {
                    $thumbBase = pathinfo($filename, PATHINFO_FILENAME);
                    $smallPath = $dir . '/' . $thumbBase . '_s.' . $ext;
                    $mediumPath = $dir . '/' . $thumbBase . '_m.' . $ext;
                    self::compressAndSave($file['tmp_name'], $smallPath, $realMime, 240, 240);
                    self::compressAndSave($file['tmp_name'], $mediumPath, $realMime, 720, 720);
                    $thumbSmallUrl = PathResolver::uploadUrl($relPath . '/' . $thumbBase . '_s.' . $ext);
                    $thumbMediumUrl = PathResolver::uploadUrl($relPath . '/' . $thumbBase . '_m.' . $ext);
                } catch (\Throwable $e) {
                    $thumbSmallUrl = null;
                    $thumbMediumUrl = null;
                }
            }

            // S10：原实现在 compress=false 且水印挂起时直接 move_uploaded_file，
            // 跳过 GD 二次渲染 —— 图片马（合法 PNG 头 + 尾部挂脚本）可原样落盘。
            // 现在无论是否压缩一律 GD 解码→编码；不压缩时用 PHP_INT_MAX 只重编码
            // 不缩放，并保留 PNG/WEBP 透明通道（见 compressAndSave）。
            if ($compress) {
                self::compressAndSave($file['tmp_name'], $filepath, $realMime, $maxWidth, $maxHeight, $isAvatar);
            } else {
                self::compressAndSave($file['tmp_name'], $filepath, $realMime, PHP_INT_MAX, PHP_INT_MAX, $isAvatar);
            }
        } finally {
            // 无论成功与否，清理临时文件
            if (file_exists($file['tmp_name'])) {
                unlink($file['tmp_name']);
            }
        }

        return [PathResolver::uploadUrl($relPath . '/' . $filename), $hash, $thumbSmallUrl, $thumbMediumUrl];
    }

    /**
     * 按年月日生成日期子目录并返回完整目录路径
     * 文件分类存储到 uploads/{type}/YYYY/MM/DD/ 下，避免单目录文件过多
     * @param string $baseDir 基础目录（如 uploads/images/）
     * @return array{0:string,1:string} [完整目录路径, 相对路径（如 images/2026/08/03）]
     */
    private static function buildDateDir(string $baseDir): array
    {
        $now = Helper::now();
        // 兼容 Helper::now() 返回 "YYYY-MM-DD HH:MM:SS" 格式
        $datePart = substr($now, 0, 10);
        [$year, $month, $day] = explode('-', $datePart);
        $subDir = $year . '/' . $month . '/' . $day;
        // 统一处理 Windows 反斜杠和正斜杠
        $trimmedBase = rtrim(str_replace('\\', '/', $baseDir), '/');
        $fullDir = $trimmedBase . '/' . $subDir;
        if (!is_dir($fullDir) && !mkdir($fullDir, 0777, true) && !is_dir($fullDir)) {
            error_log('[Hublog Upload] 目录创建失败: ' . $fullDir . ' base=' . $baseDir); // @allow-error-log
            throw new HublogException('上传目录创建失败', 500);
        }
        return [$fullDir, basename($trimmedBase) . '/' . $subDir];
    }

    /**
     * 上传头像
     * 注意：内部调用的 image() 现返回 [url, hash]，这里取第 0 位，保持对外 API 不变。
     */
    public static function avatar(array $file, ?int $maxSize = null): string
    {
        [$url, $_] = self::image($file, \UPLOAD_AVATAR_DIR, 300, 300, true, $maxSize, null, true);
        return $url;
    }

    /**
     * 上传普通附件
     * 支持基于文件哈希的秒传去重：相同内容的文件只保存一份物理文件，
     * 但每个上传者拥有独立的 uploads 记录和自己的原始文件名。
     */
    public static function attachment(array $file, string $dir, array $allowedExts, ?int $maxSize = null, ?string $fileHash = null): array
    {
        self::validateFile($file, $maxSize);

        $originalName = $file['name'] ?? '';
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (empty($ext) || !in_array($ext, $allowedExts, true)) {
            throw new HublogException('不支持的附件格式', 400);
        }

        // P2-11 修复：服务端重算 sha256，不信任客户端 $fileHash 参数
        // （防止伪造别人文件 hash 秒传返回他人 URL 的越权访问）
        $hash = @hash_file('sha256', $file['tmp_name']) ?: '';
        if ($hash === '') {
            throw new HublogException('文件哈希计算失败', 500);
        }

        // 秒传：查找已存在的相同文件且未被下架（必须验证物理文件存在）
        $existing = Database::fetchOne(
            "SELECT url, filename FROM uploads WHERE file_hash = ? AND type = 'attachment' AND status = 1 ORDER BY id ASC LIMIT 1",
            [$hash]
        );

        if ($existing && !empty($existing['url']) && self::uploadFileExists($existing['url'])) {
            // 复用已有物理文件，返回当前用户的原始文件名便于下载展示
            return [
                'url' => $existing['url'],
                'filename' => $originalName,
                'size' => $file['size'],
                'file_hash' => $hash
            ];
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '', $originalName);
        if (empty($safeName)) {
            $safeName = 'attachment.' . $ext;
        }
        $filename = Security::randomString(16) . '_' . $safeName;
        [$dir, $relPath] = self::buildDateDir($dir);
        $filepath = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new HublogException('文件保存失败', 500);
        }

        return [
            'url' => PathResolver::uploadUrl($relPath . '/' . $filename),
            'filename' => $originalName,
            'size' => $file['size'],
            'file_hash' => $hash
        ];
    }

    /**
     * 通用文件上传（支持视频、附件等）
     * 支持基于文件哈希的秒传去重
     *
     * P2-11 变更：返回值从 string 改为 array{0:string,1:string}（[url, file_hash]），
     *   调用方使用返回的 file_hash 写库，不再信任客户端传入的 $fileHash。
     *   原 $fileHash 参数保留作为旧签名兼容，内部不采用。
     *
     * @param string|null $uploadType 上传类型 'video'|'attachment' 等，用于秒传时精确匹配类型；null 为不限制
     * @return array{0:string, 1:string}
     */
    public static function file(array $file, string $dir, ?int $maxSize = null, ?string $fileHash = null, ?array $allowedExts = null, ?string $uploadType = null): array
    {
        self::validateFile($file, $maxSize);

        $originalName = $file['name'] ?? '';
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // S2 修复：原实现在 $allowedExts === null 时整段跳过校验，而
        // ChatController 的 type=file 分支恰好漏传该参数，导致 .html/.svg 可落到
        // 同源目录形成存储型 XSS。现在缺省即使用安全默认白名单，且危险后缀一律拒绝。
        $allowedExts = array_map('strtolower', $allowedExts ?? self::defaultAllowedExts());
        if ($ext === ''
            || in_array($ext, self::FORBIDDEN_EXTS, true)
            || !in_array($ext, $allowedExts, true)) {
            throw new HublogException('不支持的文件格式', 400);
        }

        // P2-11 修复：服务端重算 sha256，不信任客户端 $fileHash 参数
        // （防止伪造他人文件 hash → 秒传返回他人文件 URL → 越权访问）
        $hash = @hash_file('sha256', $file['tmp_name']) ?: '';
        if ($hash === '') {
            throw new HublogException('文件哈希计算失败', 500);
        }

        error_log('[Hublog Upload::file] 开始: name=' . $originalName . ' size=' . ($file['size'] ?? 0) . ' hash=' . substr($hash, 0, 16) . '... type=' . ($uploadType ?? 'null') . ' dir=' . $dir); // @allow-error-log

        // 秒传：查找已存在的相同文件且未被下架
        // 注意：传了 uploadType 时必须精确匹配类型，避免视频命中附件的记录导致 type 错乱
        if ($uploadType !== null) {
            $existing = Database::fetchOne(
                "SELECT url FROM uploads WHERE file_hash = ? AND type = ? AND status = 1 ORDER BY id ASC LIMIT 1",
                [$hash, $uploadType]
            );
        } else {
            $existing = Database::fetchOne(
                "SELECT url FROM uploads WHERE file_hash = ? AND status = 1 ORDER BY id ASC LIMIT 1",
                [$hash]
            );
        }

        // 秒传前必须验证物理文件真实存在，避免 DB 残留记录指向已丢失的文件
        if ($existing && !empty($existing['url'])) {
            $physExists = self::uploadFileExists($existing['url']);
            error_log('[Hublog Upload::file] 秒传检查: db_url=' . $existing['url'] . ' phys_exists=' . ($physExists ? '1' : '0')); // @allow-error-log
            if ($physExists) {
                // 复用已有物理文件
                if (file_exists($file['tmp_name'])) {
                    unlink($file['tmp_name']);
                }
                error_log('[Hublog Upload::file] 秒传命中: url=' . $existing['url']); // @allow-error-log
                return [$existing['url'], $hash];
            }
            error_log('[Hublog Upload::file] 秒传跳过(物理文件不存在)，执行真正上传'); // @allow-error-log
        }

        // S2：原文件名可能带多个点（evil.php.txt），Apache mod_mime 的多后缀解析
        // 会把中间后缀当脚本处理。只保留白名单校验过的最后一个扩展名，
        // 文件名其余部分的点一律替换为下划线。
        $base = (string)pathinfo($originalName, PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', $base);
        if ($safeBase === '' || $safeBase === '_') {
            $safeBase = 'file';
        }
        $safeName = $safeBase . '.' . $ext;
        $filename = Security::randomString(16) . '_' . $safeName;
        [$dir, $relPath] = self::buildDateDir($dir);
        $filepath = $dir . '/' . $filename;

        error_log('[Hublog Upload::file] 保存文件: dst=' . $filepath . ' dir_exists=' . (is_dir($dir) ? '1' : '0') . ' dir_writable=' . (is_writable($dir) ? '1' : '0') . ' is_uploaded=' . (is_uploaded_file($file['tmp_name'] ?? '') ? '1' : '0')); // @allow-error-log

        if (!self::saveToDestination($file['tmp_name'] ?? '', $filepath)) {
            error_log('[Hublog Upload::file] 文件保存失败: tmp=' . ($file['tmp_name'] ?? '?') . ' dst=' . $filepath . ' is_uploaded=' . (is_uploaded_file($file['tmp_name'] ?? '') ? '1' : '0') . ' dir_writable=' . (is_writable($dir) ? '1' : '0')); // @allow-error-log
            throw new HublogException('文件保存失败', 500);
        }

        error_log('[Hublog Upload::file] 保存成功: file_exists=' . (file_exists($filepath) ? '1' : '0') . ' file_size=' . (file_exists($filepath) ? filesize($filepath) : 0)); // @allow-error-log
        return [PathResolver::uploadUrl($relPath . '/' . $filename), $hash];
    }

    /**
     * 把临时文件落到目标路径。兼容两类来源：
     *  ① PHP 上传的临时文件（is_uploaded_file 为真）→ move_uploaded_file；
     *  ② 服务端自己生成的临时文件（如 ffmpeg 压缩产物，见 VideoCompress）→ 它不是
     *     "上传文件"，move_uploaded_file 会直接失败，必须走 rename/copy。
     *     （旧代码只有 move_uploaded_file，导致服务端一启用压缩，视频上传必 500。）
     */
    private static function saveToDestination(string $tmp, string $filepath): bool
    {
        if ($tmp === '' || !is_file($tmp)) {
            return false;
        }
        if (is_uploaded_file($tmp)) {
            return @move_uploaded_file($tmp, $filepath);
        }
        // rename 在跨文件系统（/tmp → 站点目录）时可能失败，再退一步用 copy
        if (@rename($tmp, $filepath)) {
            return true;
        }
        if (@copy($tmp, $filepath)) {
            @unlink($tmp);
            return true;
        }
        return false;
    }

    /**
     * 安全删除已上传文件
     * 兼容旧版扁平路径（uploads/images/xxx.jpg）和新版日期分目录（uploads/images/2026/08/03/xxx.jpg）
     *
     * @param string $url 上传时返回的 URL，如 /uploads/images/2026/08/03/xxx.jpg
     */
    public static function deleteUpload(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = ltrim($path, '/');

        // 提取 uploads/ 之后的相对路径
        $uploadsMarker = 'uploads/';
        $pos = strpos($path, $uploadsMarker);
        $relativePath = ($pos !== false) ? substr($path, $pos + strlen($uploadsMarker)) : '';
        if (empty($relativePath)) {
            return false;
        }

        // 路径穿越防护：禁止 .. 和绝对路径
        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            return false;
        }

        // 规范化路径分隔符
        $relativePath = str_replace('\\', '/', $relativePath);
        $segments = explode('/', $relativePath);
        if (empty($segments) || count($segments) < 2) {
            return false;
        }

        // 第一段必须是允许的类型目录
        $typeDir = $segments[0];
        $allowedTypes = ['avatars', 'images', 'attachments', 'videos'];
        if (!in_array($typeDir, $allowedTypes, true)) {
            return false;
        }

        // 拼接完整物理路径
        $basePath = PathResolver::basePath() . '/uploads/';
        $fullPath = $basePath . $relativePath;

        // 二次校验：解析后的真实路径必须在 uploads/ 目录下
        $realBase = realpath($basePath);
        $realFile = realpath($fullPath);
        if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
            return false;
        }

        if (is_file($realFile)) {
            return unlink($realFile);
        }

        // 兼容旧版扁平路径：若新路径找不到文件，回退到按文件名在类型目录下递归查找
        $filename = basename($relativePath);
        $typeBasePath = $basePath . $typeDir;
        if (is_dir($typeBasePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($typeBasePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->getFilename() === $filename) {
                    return unlink($file->getPathname());
                }
            }
        }

        return false;
    }

    private static function validateFile(array $file, ?int $maxSize = null): void
    {
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            throw new HublogException('未找到上传文件', 400);
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new HublogException('文件上传失败: ' . $file['error'], 400);
        }

        $limit = $maxSize ?? \UPLOAD_MAX_SIZE;
        if ($file['size'] > $limit) {
            throw new HublogException('文件超过最大限制' . ($limit / 1024 / 1024) . 'MB', 400);
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            // 服务端自产临时文件豁免：VideoCompress 的 ffmpeg/avconv 压缩产物落在
            // sys_get_temp_dir()/hublog_vid_*.mp4，它不是 PHP 上传文件，
            // is_uploaded_file() 必然为假。PostController::uploadVideo 与
            // ChatController 私信视频在压缩后都会把 $file['tmp_name'] 换成该产物，
            // 这里若不豁免，服务端压缩一旦生效上传就 100% 报「非法上传」
            // （V114 只改了 saveToDestination，漏改本处，10-26 修复）。
            // 豁免条件双重收紧（临时目录内 + hublog_vid_ 前缀），任意路径无法借道放行；
            // 改动 VideoCompress 的产物目录/命名时必须同步 isServerTempFile()。
            if (!self::isServerTempFile($file['tmp_name'])) {
                throw new HublogException('非法上传', 400);
            }
        }
    }

    /**
     * 判断是否为服务端自己生成的临时文件。
     * 目前唯一来源：VideoCompress 的压缩产物（sys_get_temp_dir()/hublog_vid_*.mp4）。
     * 必须同时满足两个条件才放行：① 位于系统临时目录内；② basename 以 hublog_vid_ 开头。
     */
    private static function isServerTempFile(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $normalized = str_replace('\\', '/', $path);
        if (!str_starts_with(basename($normalized), 'hublog_vid_')) {
            return false;
        }
        $normalize = static function (string $p): string {
            return rtrim(str_replace('\\', '/', $p), '/');
        };
        return str_starts_with($normalize($normalized), $normalize(sys_get_temp_dir()) . '/');
    }

    /**
     * 验证 uploads 表中的 URL 对应的物理文件是否真实存在
     * 用于秒传前校验，避免 DB 残留记录指向已丢失的文件
     */
    private static function uploadFileExists(string $url): bool
    {
        if (empty($url)) {
            return false;
        }
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = ltrim($path, '/');

        // 提取 uploads/ 之后的相对路径
        $marker = 'uploads/';
        $pos = strpos($path, $marker);
        $relativePath = ($pos !== false) ? substr($path, $pos + strlen($marker)) : '';
        if (empty($relativePath)) {
            return false;
        }

        // 路径穿越防护
        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            return false;
        }

        $basePath = PathResolver::basePath() . '/uploads/';
        $fullPath = $basePath . str_replace('\\', '/', $relativePath);
        return is_file($fullPath);
    }

    /**
     * 验证图片文件头（增强版，区分 RIFF 子类型）
     */
    private static function validateImageHeader(string $header, ?string $mime): bool
    {
        $signatures = [
            'image/jpeg' => ["\xFF\xD8\xFF"],
            'image/png' => ["\x89PNG\r\n\x1a\n"],
            'image/gif' => ["GIF87a", "GIF89a"],
            'image/webp' => ["RIFF....WEBP"], // RIFF + 4 bytes size + WEBP
        ];

        // MIME 已知时：精确校验对应文件头
        if ($mime !== null) {
            if (!isset($signatures[$mime])) {
                return false;
            }
            foreach ($signatures[$mime] as $sig) {
                if (str_starts_with($header, str_replace('.', "\x00", $sig))) {
                    return true;
                }
                if ($mime === 'image/webp' && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
                    return true;
                }
                if (str_starts_with($header, $sig)) {
                    return true;
                }
            }
            return false;
        }

        // MIME 未知时（fileinfo 不可用）：遍历所有已知图片签名
        foreach ($signatures as $type => $sigs) {
            foreach ($sigs as $sig) {
                if (str_starts_with($header, str_replace('.', "\x00", $sig))) {
                    return true;
                }
                if ($type === 'image/webp' && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
                    return true;
                }
                if (str_starts_with($header, $sig)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function compressAndSave(string $src, string $dst, string $mime, int $maxW, int $maxH, bool $isAvatar = false): void
    {
        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($src),
            'image/png' => imagecreatefrompng($src),
            'image/gif' => imagecreatefromgif($src),
            'image/webp' => imagecreatefromwebp($src),
            default => null
        };

        if (!$image) {
            throw new HublogException('图片处理失败', 500);
        }

        $origW = imagesx($image);
        $origH = imagesy($image);

        if ($origW > $maxW || $origH > $maxH) {
            $ratio = min($maxW / $origW, $maxH / $origH);
            $newW = (int)($origW * $ratio);
            $newH = (int)($origH * $ratio);

            $newImage = imagecreatetruecolor($newW, $newH);

            if ($mime === 'image/png') {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
            }

            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            imagedestroy($image);
            $image = $newImage;
        }

        // S10：不缩放的重编码路径同样要保留透明通道（原实现只在缩放分支设置），
        // 否则水印 PNG/WEBP 重编码后会丢 alpha 变成实底。
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        // 图片水印：在编码落盘前叠加（Watermark 内部已处理开关、小图、GIF、异常兜底）
        if (Watermark::enabled()) {
            Watermark::apply($image, $mime, $isAvatar);
        }

        // 注意：目标格式看 $dst 的扩展名，而不是源 mime。
        // 上面生成文件名时可能已把 jpg/png 改成 .webp，这里必须跟着编码成 WebP，
        // 否则会出现「文件名 .webp 但内容是 JPEG」的不一致。
        $targetExt = strtolower(pathinfo($dst, PATHINFO_EXTENSION));

        if ($targetExt === 'webp' && function_exists('imagewebp')) {
            imagewebp($image, $dst, 85);
        } else {
            match ($mime) {
                'image/jpeg' => imagejpeg($image, $dst, 85),
                'image/png' => imagepng($image, $dst, 6),
                'image/gif' => imagegif($image, $dst),
                'image/webp' => imagewebp($image, $dst, 85),
                default => imagejpeg($image, $dst, 85)
            };
        }

        imagedestroy($image);
    }

    /**
     * 是否开启「上传转 WebP」
     *
     * 读站点设置 upload_webp（'1' 开启）。默认关闭，理由见调用处注释。
     * 读不到设置表时安全降级为关闭。
     */
    private static function webpEnabled(): bool
    {
        try {
            $row = Database::fetchOne(
                "SELECT `value` FROM settings WHERE `key` = 'upload_webp' LIMIT 1"
            );
            return $row && (string)$row['value'] === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ================================================================
     * 【直传模式】静态门面：signDirect / registerDirect / testConnection
     * 把云存储签名和登记逻辑封装在这里，跟原上传方法在同一类下，
     * 方便 PostController / AuthController / AdminController 调用。
     * ================================================================ */

    /**
     * 获取云存储直传签名（前端直传第一步 API）
     *
     * @param string $type          avatar / cover / image / video / attachment
     * @param string $filename      用户原始文件名（不保证有效）
     * @param int    $contentLength 用户声明的字节数
     * @param string $contentType   用户声明的 MIME（用于推断扩展名兜底）
     * @return array{driver:string,is_local:bool,sign:array}
     * @throws HublogException
     */
    public static function signDirect(string $type, string $filename, int $contentLength, string $contentType = ''): array
    {
        // 1. 类型白名单
        if (!in_array($type, ['avatar', 'cover', 'image', 'video', 'attachment'], true)) {
            throw new HublogException('未知的上传类型: ' . $type, 400);
        }

        // 2. 推断扩展名：pathinfo 优先，MIME 兜底
        $origExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($origExt === '' && $contentType !== '') {
            $mimeMap = [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
                'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
                'application/pdf' => 'pdf',
                'application/zip' => 'zip', 'application/x-7z-compressed' => '7z',
                'application/x-rar-compressed' => 'rar',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-powerpoint' => 'ppt',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
                'text/plain' => 'txt', 'text/csv' => 'csv',
            ];
            if (isset($mimeMap[$contentType])) {
                $origExt = $mimeMap[$contentType];
            }
        }

        // 3. 类型特定扩展名限制（跟上传时保持同一规则）
        $allowedImg = ['jpg','jpeg','png','gif','webp'];
        $allowedVid = ['mp4','webm','mov','avi','mkv'];
        switch ($type) {
            case 'avatar':
            case 'image':
                if (!in_array($origExt, $allowedImg, true) && !in_array($origExt, $allowedVid, true)) {
                    $origExt = 'jpg'; // 兜底为图片
                }
                break;
            case 'cover':
                if (!in_array($origExt, $allowedImg, true) && !in_array($origExt, $allowedVid, true)) {
                    $origExt = 'jpg';
                }
                break;
            case 'video':
                if (!in_array($origExt, $allowedVid, true)) {
                    $origExt = 'mp4';
                }
                break;
            case 'attachment':
                $whitelist = array_map('strtolower', (array)UPLOAD_ATTACHMENT_EXTS);
                $tmp = strtolower($origExt);
                if (!in_array($tmp, $whitelist, true)) {
                    throw new HublogException(
                        '不允许的附件类型：' . ($origExt ?: '(空)') . '，允许：' . implode('/', $whitelist)
                    );
                }
                break;
        }

        // 4. 调 Driver 取签名
        $provider = UploadProvider::resolve();
        $sign = $provider->signDirectUpload($type, $filename, $contentLength, $origExt);

        return [
            'driver'   => $sign->driver,
            'is_local' => $sign->driver === 'local',
            'sign'     => $sign->toArray(),
        ];
    }

    /**
     * 登记前端直传成功后的文件（云存储模式）
     *
     * 服务端此时并没有上传文件本身，它已经在云端。
     * 本方法做：
     *   1) 校验 final_url 域名在允许的 CDN/OSS 白名单（防外链注入/钓鱼头像）
     *   2) avatar/cover 直接 update users 表
     *   3) image/video/attachment 写入 attachments 表（post_id 等"发布动态"时再绑定）
     *
     * @return array 跟原 Upload::avatar/image/video/attachment 返回格式完全一致
     */
    public static function registerDirect(
        int $userId,
        string $type,
        string $objectKey,
        string $finalUrl,
        int $fileSize = 0,
        string $origFilename = '',
        string $mimeType = ''
    ): array {
        if (!in_array($type, ['avatar', 'cover', 'image', 'video', 'attachment'], true)) {
            throw new HublogException('未知上传类型: ' . $type, 400);
        }

        // 1. final_url 合法性：必须 http(s)
        if (filter_var($finalUrl, FILTER_VALIDATE_URL) === false) {
            throw new HublogException('final_url 非法：' . substr($finalUrl, 0, 60), 400);
        }
        $host = parse_url($finalUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new HublogException('final_url 缺少主机名', 400);
        }

        // 2. 白名单主机校验（本站域名 + driver 配置的 CDN/OSS 域名）
        $allowedHosts = self::_allowedFinalUrlHosts();
        // PHPStan 提级后揪出：这里原本写的是 PathResolver::url('/', true)，
        // 但 url() 只有 1 个参数 —— 第二个实参被静默丢弃，返回的是**路径**而不是绝对地址，
        // parse_url(路径, PHP_URL_HOST) 恒为 null → 本站域名从来没被加进白名单。
        // 正确取法是 origin()（返回 https://host）。
        $selfHost = parse_url(PathResolver::origin(), PHP_URL_HOST);
        if (is_string($selfHost) && $selfHost !== '') {
            $allowedHosts[] = $selfHost;
        }
        $allowedHosts = array_values(array_filter(array_unique($allowedHosts), 'is_string'));
        $okHost = false;
        foreach ($allowedHosts as $h) {
            if (strcasecmp($h, $host) === 0) { $okHost = true; break; }
            // 通配子域名（前缀带点）
            if (str_starts_with($h, '.') && str_ends_with(strtolower($host), strtolower($h))) {
                $okHost = true; break;
            }
        }
        if (!$okHost) {
            throw new HublogException(
                'final_url 不在允许的 CDN/OSS 域名列表（防外链注入）。允许列表：' . implode(', ', $allowedHosts)
            );
        }

        $now = Helper::now();
        [$year, $month, $day] = self::_objectKeyToYmd($objectKey);

        switch ($type) {
            case 'avatar':
                // PHPStan 提级后揪出：PDO::exec() 只接受 1 个参数，
                // 传第 2 个参数会抛 ArgumentCountError（致命错误 → 整站 500），
                // 头像设置这条路径原本是必挂的。改用 Database::query（prepare + 绑定参数）。
                Database::query("UPDATE users SET avatar = ?, updated_at = ? WHERE id = ?", [
                    $finalUrl, $now, $userId
                ]);
                return ['avatar' => $finalUrl];

            case 'cover':
                Database::query("UPDATE users SET cover = ?, updated_at = ? WHERE id = ?", [
                    $finalUrl, $now, $userId
                ]);
                return ['cover' => $finalUrl];

            case 'image':
                $id = Database::insert('attachments', [
                    'user_id'    => $userId,
                    'type'       => 'image',
                    'filename'   => $origFilename !== '' ? $origFilename : basename($objectKey),
                    'path'       => $objectKey,
                    'url'        => $finalUrl,
                    'mime_type'  => $mimeType ?: 'image/jpeg',
                    'size'       => max(0, $fileSize),
                    'post_id'    => null,
                    'year'       => $year,
                    'month'      => $month,
                    'day'        => $day,
                    'status'     => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return [
                    'id'     => (int)$id,
                    'url'    => $finalUrl,
                    'width'  => 0,
                    'height' => 0,
                    'mime'   => $mimeType,
                    'size'   => max(0, $fileSize),
                ];

            case 'video':
                $id = Database::insert('attachments', [
                    'user_id'    => $userId,
                    'type'       => 'video',
                    'filename'   => $origFilename !== '' ? $origFilename : basename($objectKey),
                    'path'       => $objectKey,
                    'url'        => $finalUrl,
                    'mime_type'  => $mimeType ?: 'video/mp4',
                    'size'       => max(0, $fileSize),
                    'duration'   => null,
                    'cover_url'  => null,
                    'post_id'    => null,
                    'year'       => $year,
                    'month'      => $month,
                    'day'        => $day,
                    'status'     => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return [
                    'id'       => (int)$id,
                    'url'      => $finalUrl,
                    'cover'    => null,
                    'duration' => 0,
                    'size'     => max(0, $fileSize),
                ];

            case 'attachment':
                $id = Database::insert('attachments', [
                    'user_id'    => $userId,
                    'type'       => 'attachment',
                    'filename'   => $origFilename !== '' ? $origFilename : basename($objectKey),
                    'path'       => $objectKey,
                    'url'        => $finalUrl,
                    'mime_type'  => $mimeType ?: 'application/octet-stream',
                    'size'       => max(0, $fileSize),
                    'post_id'    => null,
                    'year'       => $year,
                    'month'      => $month,
                    'day'        => $day,
                    'status'     => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return [
                    'id'       => (int)$id,
                    'url'      => $finalUrl,
                    'filename' => $origFilename !== '' ? $origFilename : basename($objectKey),
                    'size'     => max(0, $fileSize),
                ];
        }
        throw new HublogException('未知错误', 500);
    }

    /** Admin 后台连通性测试（可强制指定 driver） */
    public static function testConnection(string $forceDriver = ''): array
    {
        if ($forceDriver !== '' && in_array($forceDriver, ['local','aliyun','qcloud','qiniu'], true)) {
            $driver = $forceDriver;
        } else {
            $driver = UploadProvider::currentDriver();
        }
        $p = match ($driver) {
            'aliyun' => new \Hublog\Core\UploadProvider\AliyunOssProvider(),
            'qcloud' => new \Hublog\Core\UploadProvider\QcloudCosProvider(),
            'qiniu'  => new \Hublog\Core\UploadProvider\QiniuKodoProvider(),
            default  => new \Hublog\Core\UploadProvider\LocalUploadProvider(),
        };
        try {
            $r = $p->testConnection();
            if (!is_array($r) || !isset($r['ok'])) {
                return ['ok' => false, 'code' => 'WRONG_RETURN', 'reason' => 'Provider 返回结构异常', 'diagnosis' => null, 'driver' => $driver];
            }
            return $r + ['driver' => $driver];
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 'EXCEPTION', 'reason' => '异常：' . $e->getMessage(),
                    'diagnosis' => $e->getTraceAsString(), 'driver' => $driver];
        }
    }

    /* ---------- 内部工具方法 ---------- */

    /** 从 object_key 推断 Y m d（attachments 表字段）。找不到就用今天。 */
    private static function _objectKeyToYmd(string $key): array
    {
        if (preg_match('#/(20\d{2})(\d{2})/(\d{2})/#', $key, $m)) {
            return [(int)$m[1], (int)$m[2], (int)$m[3]];
        }
        if (preg_match('#/(20\d{2})(\d{2})/#', $key, $m)) {
            return [(int)$m[1], (int)$m[2], (int)date('d')];
        }
        return [(int)date('Y'), (int)date('m'), (int)date('d')];
    }

    /** 返回当前 driver 配置的 CDN/OSS 主机列表（不含本站域名，由调用方补） */
    private static function _allowedFinalUrlHosts(): array
    {
        $hosts = [];
        $commonCdn = (string)UploadProvider::readCfgPublic('upload_cdn_common_domain', 'UPLOAD_CDN_COMMON_DOMAIN', '');
        if ($commonCdn !== '') {
            $h = (string)parse_url($commonCdn, PHP_URL_HOST);
            if ($h !== '') $hosts[] = $h;
        }
        switch (UploadProvider::currentDriver()) {
            case 'aliyun':
                $bucket   = (string)UploadProvider::readCfgPublic('upload_oss_bucket',     'UPLOAD_OSS_BUCKET', '');
                $endpoint = (string)UploadProvider::readCfgPublic('upload_oss_endpoint',   'UPLOAD_OSS_ENDPOINT', '');
                $cdn      = (string)UploadProvider::readCfgPublic('upload_oss_cdn_domain', 'UPLOAD_OSS_CDN_DOMAIN', '');
                if ($bucket !== '' && $endpoint !== '') {
                    $endpoint = preg_replace('#^https?://#i', '', $endpoint);
                    $hosts[] = $bucket . '.' . $endpoint;
                }
                if ($cdn !== '') { $h = (string)parse_url($cdn, PHP_URL_HOST); if ($h !== '') $hosts[] = $h; }
                break;
            case 'qcloud':
                $bucket = (string)UploadProvider::readCfgPublic('upload_cos_bucket',     'UPLOAD_COS_BUCKET', '');
                $region = (string)UploadProvider::readCfgPublic('upload_cos_region',     'UPLOAD_COS_REGION', '');
                $cdn    = (string)UploadProvider::readCfgPublic('upload_cos_cdn_domain', 'UPLOAD_COS_CDN_DOMAIN', '');
                if ($bucket !== '' && $region !== '') {
                    $hosts[] = sprintf('%s.cos.%s.myqcloud.com', $bucket, $region);
                }
                if ($cdn !== '') { $h = (string)parse_url($cdn, PHP_URL_HOST); if ($h !== '') $hosts[] = $h; }
                break;
            case 'qiniu':
                $cdn = (string)UploadProvider::readCfgPublic('upload_qiniu_cdn_domain', 'UPLOAD_QINIU_CDN_DOMAIN', '');
                if ($cdn !== '') { $h = (string)parse_url($cdn, PHP_URL_HOST); if ($h !== '') $hosts[] = $h; }
                break;
        }
        return $hosts;
    }
}
