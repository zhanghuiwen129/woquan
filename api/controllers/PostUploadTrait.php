<?php

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Logger;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Auth;
use Hublog\Core\Validator;
use Hublog\Core\Security;
use Hublog\Core\Upload;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\BadgeService;
use Hublog\Core\Hook;
use Hublog\Core\PostRepository;
use Hublog\Core\UploadRepository;
use Hublog\Core\PostFormatter;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\SsePush;

trait PostUploadTrait
{
    /**
     * P1-6 校验图片/视频/附件 URL 归属权
     * 在 uploads 表中查找属于当前用户的记录。
     * 默认仅匹配 post_id IS NULL（未绑定）的上传，防止越权重放；
     * 编辑态传入 $postId 时，额外允许已绑定到「当前正在编辑的动态」的上传
     * （post_id = $postId），否则编辑带图动态会因图片已被绑定而误报「不属于当前用户」。
     * 返回 null 表示校验通过，否则返回错误信息
     */

    private function assertUploadsBelongToUser(int $userId, array $images, array $videos, array $attachments, ?int $postId = null, bool $isAdmin = false): ?string
    {
        $urls = [];
        foreach ($images as $url) {
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }
        foreach ($videos as $v) {
            if (is_string($v) && $v !== '') {
                $urls[] = $v;
            } elseif (is_array($v)) {
                if (!empty($v['url'])) $urls[] = $v['url'];
                if (!empty($v['cover'])) $urls[] = $v['cover'];
            }
        }
        foreach ($attachments as $att) {
            if (is_array($att) && !empty($att['url'])) {
                $urls[] = $att['url'];
            }
        }
        $urls = array_values(array_unique($urls));
        if (empty($urls)) {
            return null;
        }
        // 遍历所有 URL，确保每个 URL 在 uploads 表中都有对应用户记录
        foreach ($urls as $url) {
            // 后台代管：管理员可编辑他人动态，原动态已绑定的媒体不属于管理员，
            // 但必须允许保留，否则编辑一次就会因归属校验被拒。
            if ($isAdmin && $postId !== null && $this->isUploadBoundToPost($url, $postId)) {
                continue;
            }
            if (!$this->isOwnUploadUrl($url, $userId, $postId)) {
                return '图片/视频/附件不属于当前用户：' . basename($url);
            }
        }
        return null;
    }

    /**
     * P1-6 判断单个 URL 是否属于当前用户的 uploads 记录
     * - 仅允许 http/https 开头，禁止 javascript:/data: 等危险协议
     * - 在 uploads 表中查找 user_id=当前用户且 post_id IS NULL 的记录
     * - 编辑态传入 $postId 时，额外允许 post_id = $postId（即本动态已有的图片）
     * - 发布时由 recordUploads 绑定 post_id 后就不能再被冒用
     */

    private function isOwnUploadUrl(string $url, int $userId, ?int $postId = null): bool
    {
        // 协议白名单与两种归属口径已随 SQL 下沉到 UploadRepository 本体，
        // 避免仓储内部调用绕过入口校验；这里只做结果布尔化。
        $row = $postId === null
            ? UploadRepository::findOwnUnboundUploadId($url, $userId)
            : UploadRepository::findOwnUploadIdForEdit($url, $userId, $postId);
        return $row !== null;
    }

    private function isUploadBoundToPost(string $url, int $postId): bool
    {
        // 协议白名单 + path 归一化 + SQL 全部下沉，仓储任何入口口径一致
        return UploadRepository::findUploadBoundToPost($url, $postId) !== null;
    }

    /**
     * P1-6+：把客户端回传的媒体 URL 归一化为 uploads 表中「实际存储的规范 URL」。
     *
     * 背景：App 端 request.js 的 normalizeAssets() 会把响应里的 /uploads/... 补成绝对地址
     *      （如 http://woquan.top/uploads/...）。老客户端与编辑态会把这种绝对地址再回传，
     *      而 uploads 表存的是相对路径，导致 assertUploadsBelongToUser() 按 url= 精确匹配
     *      失败，误报「图片/视频/附件不属于当前用户」，动态发不出去。
     *      （H5 端 API_BASE 为空串、不经 normalizeAssets，故不受影响。）
     *
     * 处理：先按原样匹配，不中再按 URL 的 path 匹配；命中后返回库中存储的规范 URL，
     *      使「校验 / 绑定(recordUploads) / 落库(images|videos|attachments)」统一为同一形式。
     *
     * 编辑态传入 $postId 时，额外允许匹配 post_id = $postId（本动态已绑定的图片），
     * 否则编辑带图动态时这些图片因已被绑定而归一化失败、进而校验误拒。
     *
     * @return string|null 命中返回规范 URL；不属于当前用户的未绑定上传返回 null
     */

    private function canonicalOwnUploadUrl(string $url, int $userId, ?int $postId = null): ?string
    {
        // 空串/协议守卫与 path 归一化已下沉到 UploadRepository 两个独立方法，
        // 避免两种 WHERE 口径被误合并。
        $row = $postId === null
            ? UploadRepository::findCanonicalUnboundUploadUrl($url, $userId)
            : UploadRepository::findCanonicalUploadUrlForEdit($url, $userId, $postId);
        return $row ? (string)$row['url'] : null;
    }

    /**
     * P1-6+：批量归一化 images/videos/attachments 里的 URL（详见 canonicalOwnUploadUrl 注释）。
     * 解析不到的 URL 原样保留，交回给 assertUploadsBelongToUser() 报出统一错误。
     * 编辑态传入 $postId，使本动态已绑定的图片也能被归一化。
     *
     * @return array{0:array,1:array,2:array} [images, videos, attachments]
     */

    private function normalizeOwnMedia(int $userId, array $images, array $videos, array $attachments, ?int $postId = null): array
    {
        $fix = function ($u) use ($userId, $postId) {
            if (!is_string($u) || $u === '') {
                return $u;
            }
            return $this->canonicalOwnUploadUrl($u, $userId, $postId) ?? $u;
        };

        $outImages = [];
        foreach ($images as $u) {
            $outImages[] = $fix($u);
        }

        $outVideos = [];
        foreach ($videos as $v) {
            if (is_array($v)) {
                if (isset($v['url'])) {
                    $v['url'] = $fix($v['url']);
                }
                if (isset($v['cover'])) {
                    $v['cover'] = $fix($v['cover']);
                }
                $outVideos[] = $v;
            } else {
                $outVideos[] = $fix($v);
            }
        }

        $outAttachments = [];
        foreach ($attachments as $att) {
            if (is_array($att) && isset($att['url'])) {
                $att['url'] = $fix($att['url']);
            }
            $outAttachments[] = $att;
        }

        return [$outImages, $outVideos, $outAttachments];
    }

    public function uploadImage(Request $request): Response
    {
        $user = Auth::check($request);
        $file = $request->getFile('image');
        if (!$file) {
            return Response::error('请上传图片', 400);
        }

        $settings = $this->getSiteSettings();
        $maxSize = (int)($settings['upload_max_size'] ?? '10') * 1024 * 1024;
        $compress = ($settings['upload_compress'] ?? '1') === '1';
        $maxWidth = (int)($settings['upload_max_width'] ?? '1920');
        // P2-11：前端传 file_hash 用于秒传，Upload 类内部会处理
        // 上传成功返回访问 URL
        $_unused_client_hash = $request->getString('file_hash');
        unset($_unused_client_hash);

        [$url, $realFileHash] = Upload::image($file, \UPLOAD_IMAGE_DIR, $maxWidth, 99999, $compress, $maxSize);
        // 写入 uploads 表，post_id 为空（待发布时绑定），file_hash 用于去重
        UploadRepository::insertImageUpload(
            (int)$user['id'],
            (string)($file['name'] ?? ''),
            $url,
            (int)($file['size'] ?? 0),
            $realFileHash,
            Helper::now()
        );
        return Response::success(['url' => $url], '上传成功');
    }

    public function uploadAttachment(Request $request): Response
    {
        $user = Auth::check($request);
        $file = $request->getFile('attachment');
        if (!$file) {
            return Response::error('请上传附件', 400);
        }

        $settings = $this->getSiteSettings();
        $maxSize = (int)($settings['upload_max_attachment_size'] ?? '50') * 1024 * 1024;
        $exts = $this->parseAttachmentExts($settings['upload_attachment_exts'] ?? '');
        // P2-11：附件上传也支持 file_hash，attachment() 内部用 sha256
        $_unused_client_hash = $request->getString('file_hash');
        unset($_unused_client_hash);

        $result = Upload::attachment($file, \UPLOAD_ATTACHMENT_DIR, $exts, $maxSize);
        UploadRepository::insertAttachmentUpload(
            (int)$user['id'],
            (string)$result['filename'],
            (string)$result['url'],
            (int)$result['size'],
            $result['file_hash'] ?? null,
            Helper::now()
        );
        return Response::success($result, '上传成功');
    }

    /**
     * 上传视频
     */

    public function uploadVideo(Request $request): Response
    {
        $user = Auth::check($request);

        $settings = $this->getSiteSettings();

        // 站点未开启视频上传
        if (($settings['allow_upload_video'] ?? '1') !== '1') {
            return Response::error('站点未开启视频上传功能', 403);
        }

        $file = $request->getFile('video');

        // $_FILES 为空时检查 CONTENT_LENGTH，如果超过 PHP 的 post_max_size 则 $_POST/$_FILES 都会为空
        if (!$file) {
            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            $postMax = self::iniSizeToBytes(ini_get('post_max_size'));
            $upMax = self::iniSizeToBytes(ini_get('upload_max_filesize'));
            if ($contentLength > 0 && ($contentLength > $postMax || $contentLength > $upMax)) {
                $limit = min($postMax, $upMax);
                return Response::error(
                    '文件大小超过 PHP 限制 ' . round($limit / 1024 / 1024, 1) . 'MB（post_max_size=' . ini_get('post_max_size') . ', upload_max_filesize=' . ini_get('upload_max_filesize') . '），请调整 php.ini',
                    400
                );
            }
            return Response::error('请上传视频', 400);
        }

        // PHP 上传错误码处理
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            $errMsg = match ($file['error']) {
                UPLOAD_ERR_INI_SIZE => '文件大小超过 upload_max_filesize 限制（' . ini_get('upload_max_filesize') . '）',
                UPLOAD_ERR_FORM_SIZE => '文件大小超过表单 MAX_FILE_SIZE 限制',
                UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                UPLOAD_ERR_NO_FILE => '没有文件被上传',
                UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                UPLOAD_ERR_EXTENSION => 'PHP 扩展停止了文件上传',
                default => '上传错误: ' . $file['error'],
            };
            Logger::error('[Hublog uploadVideo] PHP upload error: ' . $errMsg . ' | file=' . ($file['name'] ?? '?') . ' | size=' . ($file['size'] ?? 0));
            return Response::error($errMsg, 400);
        }

        $maxSize = (int)($settings['upload_max_video_size'] ?? '50') * 1024 * 1024;
        // P2-11：视频上传也支持 file_hash，Upload::file 内部会根据 hash 做秒传或去重
        $_unused_client_hash = $request->getString('file_hash');
        unset($_unused_client_hash);

        // 视频上传校验：MIME + 扩展名双重校验，防止伪装文件
        // 注意：iOS/Android 上传的视频 MIME 类型可能不一致
        // P2-12：新增 Magic Number 校验，防止 .exe 等文件改名为 .mp4 绕过检测
        $this->validateVideoMagicNumber($file['tmp_name'] ?? '');

        $allowedMimes = [
            'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo',
            'video/x-ms-wmv', 'video/webm', 'video/ogg', 'video/x-matroska',
            'video/x-flv', 'video/3gpp', 'video/3gpp2', 'video/mp2t',
            'video/x-ms-asf', 'video/x-m4v', 'video/vnd.rn-realvideo',
            'video/ogv', 'application/octet-stream',
            // 兼容空值
            'video/mp4v-es', 'video/x-m4v',
            'video/mov', 'video/hevc', 'video/h264',
            '' // 某些客户端不传 MIME 或传空 MIME，也放行（靠扩展名和 Magic Number 兜底）
        ];
        $allowedExts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v',
            '3gp', '3g2', 'mpg', 'mpeg', 'ogv', 'rm', 'rmvb', 'asf', 'ts',
            'mts', 'm2ts'];
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $fileType = $file['type'] ?? '';
        // 某些客户端的 MIME 可能带参数如 "video/mp4; charset=binary"
        $normalizedMime = strtolower(trim(explode(';', $fileType)[0] ?? ''));
        $mimeAllowed = in_array($fileType, $allowedMimes, true) || in_array($normalizedMime, $allowedMimes, true);
        $extAllowed = in_array($ext, $allowedExts, true);
        // MIME 和扩展名都不匹配才拒绝，防止误杀
        if (!$mimeAllowed && !$extAllowed) {
            Logger::warning('[Hublog uploadVideo] 类型不匹配: name=' . ($file['name'] ?? '?') . ' mime=' . $fileType . ' ext=' . $ext);
            return Response::error('仅支持 MP4/WebM/MOV/AVI/MKV/FLV/WMV/M4V/3GP/MPG/MPEG/OGV/RM/RMVB/ASF/TS 等视频格式', 400);
        }

        // 注意：客户端（App 端）已本地压缩过的视频不再压——二次编码会让画质损失叠加。
        // 客户端压缩失败 / H5 端（无压缩能力）上传的文件才会走到这里压缩。
        // 多管齐下：VideoCompress 内部按「后端（ffmpeg→avconv）× 编码器（libx264→libx265→mpeg4）」
        // 依次尝试，任一成功且确有收益才替换；全都不可用则原样落盘，不影响上传。
        // 顺序：先压后判大小——压缩后可能就不超限了。
        //
        // V94：压缩已移出请求热路径。
        // 旧实现在这里同步跑 ffmpeg（最多 6 次串行 exec × 300s = 最坏 30 分钟），
        // 用户上传视频要干等到超时，PHP-FPM worker 也被长期占住。
        // 现在改为：视频先原样落盘并返回，压缩交给 cron worker 异步做（见 JobQueue）。
        //
        // 代价：超大视频在压缩前不会因体积被拒。
        // 这是有意取舍 —— 「上传要等几十分钟」比「先收下再后台压」对用户更糟；
        // 且落盘前已有 Upload::file 的 maxSize 兜底，不会无限膨胀磁盘。
        // V94：压缩已移出请求热路径，这里不再同步跑 ffmpeg，只判定是否需要异步压缩。
        $clientCompressed = $request->getString('client_compressed') === '1';
        $needCompress = !$clientCompressed
            && ($settings['upload_compress'] ?? '1') === '1'
            && ($file['size'] ?? 0) > 500 * 1024;

        if ($file['size'] > $maxSize) {
            return Response::error('视频大小不能超过 ' . ($maxSize / 1024 / 1024) . 'MB', 400);
        }

        try {
            // 调用 Upload 类上传文件，内部会做安全校验，uploadType=video 用于日志区分
            Logger::info('[Hublog uploadVideo] 上传开始: name=' . ($file['name'] ?? '?') . ' size=' . ($file['size'] ?? 0) . ' tmp=' . ($file['tmp_name'] ?? '?') . ' ext=' . $ext . ' mime=' . $fileType);
            // 视频上传目录 uploads/videos/，不存在则创建
            $videoDir = defined('UPLOAD_VIDEO_DIR') ? \UPLOAD_VIDEO_DIR : 'uploads/videos';
            if (!is_dir($videoDir)) {
                if (!@mkdir($videoDir, 0777, true) && !is_dir($videoDir)) {
                    Logger::error('[Hublog uploadVideo] 创建目录失败: ' . $videoDir);
                    return Response::error('视频上传目录创建失败', 500);
                }
            }
            [$url, $realFileHash] = Upload::file($file, $videoDir, $maxSize, null, $allowedExts, 'video');
            Logger::info('[Hublog uploadVideo] 上传完成: url=' . $url . ' dir=' . $videoDir . ' phys_exists=' . (file_exists(str_replace('\\', '/', dirname(__DIR__, 2)) . parse_url($url, PHP_URL_PATH)) ? '1' : '0'));

            // 上传后校验文件是否存在
            $checkPath = \Hublog\Core\PathResolver::basePath() . parse_url($url, PHP_URL_PATH);
            if (!file_exists($checkPath)) {
                Logger::warning('[Hublog uploadVideo] 警告: 上传后文件物理不存在! url=' . $url . ' checkPath=' . $checkPath);
            }

            // V94：视频压缩改为异步。
            // 请求内不再跑 ffmpeg（最坏 30 分钟），而是把「压缩这个文件」入队，
            // 由 cron worker（/api/cron/jobs）异步压缩并原地替换。
            if ($needCompress && file_exists($checkPath)) {
                $this->enqueueVideoCompress($checkPath);
            }
        } catch (HublogException $e) {
            Logger::error('[Hublog uploadVideo] HublogException: ' . $e->getMessage() . ' code=' . $e->getCode());
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Logger::error('[Hublog uploadVideo] Throwable: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return Response::error('上传失败: ' . $e->getMessage(), 500);
        }

        // 视频封面图（前端截图后传入，可选）
        $cover = trim($request->getString('cover'));
        $duration = $request->getInt('duration');

        UploadRepository::insertVideoUpload(
            (int)$user['id'],
            (string)($file['name'] ?? ''),
            $url,
            (int)($file['size'] ?? 0),
            $realFileHash,
            Helper::now()
        );

        return Response::success([
            'url' => $url,
            'cover' => $cover,
            'duration' => $duration,
            '_diag' => 'v20260805.2'  // 诊断信息，版本号
        ], '上传成功');
    }

    /**
     * 把「压缩这个视频文件」登记为异步任务（V94）。
     *
     * 为什么要异步：
     *   ffmpeg 压缩最坏要几十分钟（旧实现最坏 6 次串行 exec × 300s = 30 分钟），
     *   放在请求里会让用户干等到超时，并把 PHP-FPM worker 占死。
     *   现在上传请求只负责落盘 + 入队，立刻返回；压缩由 cron worker 异步完成。
     *
     * 入队失败不影响上传：视频已经落盘可用，只是没被压缩而已，
     * 属于「降级」而不是「失败」，所以这里只记日志不抛异常。
     *
     * @param string $physicalPath 已落盘文件的绝对路径
     */
    private function enqueueVideoCompress(string $physicalPath): void
    {
        try {
            // 幂等键用文件的绝对路径：同一文件重复入队只会有一条任务
            $jobId = \Hublog\Core\JobQueue::enqueue(
                \Hublog\Core\JobQueue::TYPE_COMPRESS_VIDEO,
                [
                    'source_path' => $physicalPath,
                    'target_path' => $physicalPath,
                ],
                'compress_video:' . $physicalPath
            );
            Logger::info('[Hublog uploadVideo] 已登记压缩任务 job_id=' . $jobId . ' path=' . $physicalPath);
        } catch (\Throwable $e) {
            // 入队失败只影响压缩，不影响上传本身
            Logger::error('[Hublog uploadVideo] 压缩任务入队失败: ' . $e->getMessage());
        }
    }

    /**
     * 将 php.ini 中的大小配置转换为字节数
     */

    private static function iniSizeToBytes(string|false $val): int
    {
        if (!$val) return 0;
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1] ?? '');
        $num = (int)$val;
        switch ($last) {
            case 'g': $num *= 1024 * 1024 * 1024; break;
            case 'm': $num *= 1024 * 1024; break;
            case 'k': $num *= 1024; break;
        }
        return $num;
    }

    /**
     * 删除用户上传但未发布的文件
     *
     * 说明
     *   - 只能删除自己上传的文件
     *   - 只允许删除 post_id IS NULL 的（即尚未发布的）
     *   - 删除物理文件和数据库记录
     */

    public function deleteUpload(Request $request): Response
    {
        $user = Auth::check($request);
        $url = trim($request->getString('url'));
        if ($url === '') {
            return Response::error('请提供文件 URL', 400);
        }

        // 在 uploads 表中查找对应用户的记录
        $record = UploadRepository::findUploadRecordForDelete($url, (int)$user['id']);

        if (!$record) {
            // 找不到记录时也返回成功（幂等，防止前端重复调用报错）
            return Response::success(null, '已删除');
        }

        // 检查是否已绑定到动态
        if (!empty($record['post_id'])) {
            return Response::error('已发布的文件不能删除', 400);
        }

        // 删除物理文件
        Upload::deleteUpload($record['url']);

        // 删除数据库记录
        UploadRepository::deleteUploadById((int)$record['id']);

        return Response::success(null, '已删除');
    }

    /**
     * 直传签名：获取直传凭证 / 签名
     * POST /api/upload/sign  Body: {type, filename, content_length, content_type?}
     *
     * 本地 driver 返回 {is_local:true, sign:{upload_url:"/本地路径",...}}，前端走 multipart 本地上传
     * OSS/COS/等云存储返回 {is_local:false, sign:{upload_url,form_data,object_key,final_url,...}}，前端走 multipart POST 直传
     */

    public function uploadDirectSign(Request $request): Response
    {
        $user = Auth::check($request); // 需要登录才能上传（防止匿名用户滥用上传资源）

        $type          = trim($request->getString('type', ''));
        $filename      = $request->getString('filename', '');
        $contentLength = (int)$request->getString('content_length', '0');
        $contentType   = trim($request->getString('content_type', ''));

        if ($filename === '' || $type === '') {
            return Response::error('缺少 type 或 filename 参数', 400);
        }
        if ($contentLength <= 0) {
            return Response::error('content_length 必须大于 0', 400);
        }
        // 按类型限制大小 policy：头像 20MB / 图片 50MB / 视频 500MB / 附件 500MB
        $maxByType = [
            'avatar'     => 20 * 1024 * 1024,
            'cover'      => 50 * 1024 * 1024,
            'image'      => 50 * 1024 * 1024,
            'video'      => 500 * 1024 * 1024,
            'attachment' => 500 * 1024 * 1024,
        ];
        if (isset($maxByType[$type]) && $contentLength > $maxByType[$type]) {
            return Response::error('文件过大：' . $type . ' 不能超过 ' . round($maxByType[$type]/1024/1024, 0) . 'MB', 400);
        }

        try {
            $result = Upload::signDirect($type, $filename, $contentLength, $contentType);
            return Response::success($result, '获取成功');
        } catch (\Hublog\Core\Exception\HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Logger::error('[Hublog uploadDirectSign] EXCEPTION=' . get_class($e) . ' msg=' . $e->getMessage() . ' file=' . $e->getFile() . ':' . $e->getLine());
            Logger::error('[Hublog uploadDirectSign] TRACE=' . $e->getTraceAsString());
            return Response::error('获取签名失败', 500);
        }
    }

    /**
     * 直传注册：前端直传成功后回调后端，登记到 DB
     * POST /api/upload/register  Body: {type, object_key, final_url, file_size?, orig_filename?, mime_type?}
     *
     * 返回值
     *   avatar     => {avatar: url}
     *   cover      => {cover: url}
     *   image      => {id, url, width, height, mime, size}
     *   video      => {id, url, cover, duration, size}
     *   attachment => {id, url, filename, size}
     *
     * 说明：和 Upload::xxx 系列接口返回格式一致，供 publish.js 统一处理
     */

    public function uploadDirectRegister(Request $request): Response
    {
        $user = Auth::check($request);

        $type          = trim($request->getString('type', ''));
        $objectKey     = trim($request->getString('object_key', ''));
        $finalUrl      = trim($request->getString('final_url', ''));
        $fileSize      = (int)$request->getString('file_size', '0');
        $origFilename  = trim($request->getString('orig_filename', ''));
        $mimeType      = trim($request->getString('mime_type', ''));

        if ($type === '' || $objectKey === '' || $finalUrl === '') {
            return Response::error('缺少 type / object_key / final_url 参数', 400);
        }

        try {
            $data = Upload::registerDirect(
                (int)$user['id'],
                $type,
                $objectKey,
                $finalUrl,
                $fileSize,
                $origFilename,
                $mimeType
            );

            // 非头像/封面类型（image/video/attachment）需要写入 uploads 表
            // 头像和封面直接更新用户表，不走 uploads 表
            if (in_array($type, ['image', 'video', 'attachment'], true)) {
                $dbType = $type;
                $recUrl = $data['url'] ?? '';
                if ($recUrl !== '') {
                    $exists = UploadRepository::findExistingDirectUpload($recUrl, (int)$user['id']);
                    if (!$exists) {
                        UploadRepository::insertDirectRegisterUpload(
                            (int)$user['id'],
                            $dbType,
                            $origFilename !== '' ? $origFilename : basename($objectKey),
                            $recUrl,
                            max(0, $fileSize),
                            date('Y-m-d H:i:s', time() + 86400), // V42: 24h 后过期
                            Helper::now()
                        );
                    }
                }
            }

            return Response::success($data, '注册成功');
        } catch (\Hublog\Core\Exception\HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Logger::error('[Hublog uploadDirectRegister] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return Response::error('注册失败：' . $e->getMessage(), 500);
        }
    }

    private function parseAttachmentExts(string $exts): array
    {
        if (empty($exts)) {
            return defined('UPLOAD_ATTACHMENT_EXTS') ? \UPLOAD_ATTACHMENT_EXTS : [];
        }
        $list = array_map('trim', explode(',', strtolower($exts)));
        return array_values(array_filter($list, fn($e) => !empty($e) && preg_match('/^[a-z0-9]+$/', $e)));
    }

    /**
     * 发布/编辑动态时，将上传的文件绑定到动态 ID
     * P1-6 安全：UPDATE 时加 AND user_id = ? 条件，防止越权修改他人记录
     * 调用此函数前必须先调用 assertUploadsBelongToUser() 验证所有 URL 归属权
     * 即使 SQL 注入了也无法改他人记录，因为 URL 不在 uploads 表中时会走 INSERT 分支但 user_id 是传入的
     */

    private function recordUploads(int $userId, int $postId, array $images, array $videos, array $attachments): void
    {
        // 绑定序列（UPDATE 未命中则 INSERT，含封面图绑定）整体在仓储内完成；
        // 这里的私有方法仅为 PostCrudTrait::create() 保留的编排入口，不含 SQL。
        UploadRepository::bindUploads($userId, $postId, $images, $videos, $attachments);
    }

    /**
     * P2-12：视频文件 Magic Number 魔数校验，读取文件头前 64 字节判断真实格式
     * 防止 .exe / .php / .html 等文件改名为 .mp4 绕过上传检测
     * 检测到非视频格式直接返回 400 错误
     * 支持常见视频格式的魔数识别，覆盖绝大多数主流容器格式
     *
     * @param string $tmpPath PHP $_FILES['tmp_name'] 临时文件路径
     */

    private function validateVideoMagicNumber(string $tmpPath): void
    {
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return; // 文件不存在时直接返回，后续 validateFile 会处理
        }

        $fp = @fopen($tmpPath, 'rb');
        if (!$fp) {
            return; // 文件打不开也直接返回，move_uploaded_file 阶段会报错
        }
        try {
            // 读取前 64 字节文件头用于魔数识别
            $header = fread($fp, 64);
            if ($header === false || strlen($header) < 8) {
                // 文件太小或读取失败（<8 字节），认为是无效视频文件
                // 注意：这里必须用 HublogException（已 import）而不是 Exception\HublogException ——
                // 后者在 Hublog\Controllers 命名空间下解析成 Hublog\Controllers\Exception\HublogException，
                // 该类不存在 → 抛异常时直接 fatal（Class not found），整条上传接口 500。
                throw new HublogException('视频文件头损坏或格式无效', 400);
            }
        } finally {
            fclose($fp);
        }

        // ----------- 魔数检测 -----------
        // MP4/MOV/M4V/3GP 等 ISO Base Media File Format 格式
        //   文件头结构：[4字节 box_size][4字节 'ftyp'][4字节 major_brand]
        //   major_brand 常见值：isom/iso2/mp41/mp42 (MP4) / qt   (QuickTime MOV) / M4V  / 3gp1/3gp2/3gp4 / MSNV (SONY PSP)
        $isISOBMFF = (strlen($header) >= 12) && (substr($header, 4, 4) === 'ftyp');

        // WebM / MKV：EBML 格式头，特征字节 0x1A 0x45 0xDF 0xA3（EBML DocType = "webm"/"matroska"）
        $isEBML = (strlen($header) >= 4) && ($header[0] === "\x1A" && $header[1] === "\x45" && $header[2] === "\xDF" && $header[3] === "\xA3");

        // FLV：文件头 FLV\x01
        $isFLV = (strlen($header) >= 4) && substr($header, 0, 4) === 'FLV' . "\x01";

        // AVI / WMV (ASF) 格式
        //   AVI: 开头 RIFF (bytes 0-3) + AVI  (bytes 8-11)
        //   WMV: RIFF + AVI  或 ASF 格式头，ASF 即 WMV 的特征是 0x30 0x26 0xB2 0x75 0x8E 0x66 0xCF 0x11 0xA6 0xD9 0x00 0xAA 0x00 0x62 0xCE 0x6C
        $isRIFF = (strlen($header) >= 12) && substr($header, 0, 4) === 'RIFF' && in_array(substr($header, 8, 4), ['AVI ', 'AVIX'], true);
        $isASF = (strlen($header) >= 16) && ($header[0] === "\x30" && $header[1] === "\x26" && $header[2] === "\xB2" && $header[3] === "\x75");

        // MPEG-TS (.ts / .m2ts)：每帧以 0x47（字母 G 的 ASCII）开头，第 188 字节也是 0x47
        $isTS = ($header[0] === "\x47") && (strlen($header) >= 188 ? ($header[188] === "\x47") : true);

        // MPEG-1/2 (MPG/MPEG)：pack header 为 0x000001BA
        $isMPEG = (strlen($header) >= 4) && ($header[0] === "\x00" && $header[1] === "\x00" && $header[2] === "\x01" && $header[3] === "\xBA");

        // OGG/OGV：开头 4 字节为 OggS
        $isOGG = (strlen($header) >= 4) && substr($header, 0, 4) === 'OggS';

        // RealVideo (RM/RMVB)：开头 4 字节 .RMF（RealMedia File Header）
        $isRM = (strlen($header) >= 4) && substr($header, 0, 4) === '.RMF';

        // HLS MPEG-2 Transport Stream 分片文件也是 .ts 格式（由 isTS 检测），无需单独处理

        if ($isISOBMFF || $isEBML || $isFLV || $isRIFF || $isASF || $isTS || $isMPEG || $isOGG || $isRM) {
            return; // 校验通过，是合法视频文件
        }

        // 校验失败，记录日志
        Logger::error('[Hublog P2-12] 视频魔数不匹配: tmp=' . $tmpPath . ' bytes_0-16_hex=' . bin2hex(substr($header, 0, 16)));
        // 同上：必须用已 import 的 HublogException，Exception\HublogException 不存在会 fatal
        throw new HublogException(
            '文件格式不被识别，请上传 MP4/WebM/MKV/MOV/AVI 等常见视频格式',
            400
        );
    }

    /**
     * 方案 A+C：管理员一键清理过期孤儿文件
     * 扫描 uploads 表中 post_id IS NULL 且 expires_at < NOW() 的记录，删除物理文件 + DB
     */

    public function cleanupOrphanUploads(Request $request): Response
    {
        $user = Auth::check($request);
        if (($user['role'] ?? '') !== 'admin') {
            return Response::error('仅管理员可执行', 403);
        }

        $hours = max(1, (int)$request->getString('hours', '24'));
        $cutoff = date('Y-m-d H:i:s', time() - $hours * 3600);

        try {
            // 查待清理记录（上限 1000 条防止内存爆）
            $rows = UploadRepository::fetchOrphanUploads($cutoff, 1000);

            $filesDeleted = 0;
            $bytesFreed = 0;
            $urlsDeleted = [];

            foreach ($rows as $row) {
                // 删物理文件
                $filePath = __DIR__ . '/../../' . trim(parse_url($row['url'], PHP_URL_PATH), '/');
                if ($filePath && file_exists($filePath)) {
                    @unlink($filePath);
                    $filesDeleted++;
                    $bytesFreed += (int)$row['size'];
                    // 缩略图也删
                    $thumb = preg_replace('/\.[^.]+$/', '_thumb.$0', $filePath);
                    if (file_exists($thumb)) @unlink($thumb);
                }
                $urlsDeleted[] = $row['url'];
            }

            // 批量删 DB
            if (!empty($urlsDeleted)) {
                // PHPStan 提级后揪出：PDO::exec() 只接受 1 个参数，
                // 传第 2 个参数会抛 ArgumentCountError（致命错误 → 500）。
                // 这里由仓储用 prepare + 绑定参数执行；空集合守卫也一并下沉。
                UploadRepository::deleteUploadsByUrls($urlsDeleted);
            }

            return Response::success([
                'deleted_records' => count($urlsDeleted),
                'deleted_files' => $filesDeleted,
                'freed_mb' => round($bytesFreed / 1024 / 1024, 2),
            ], "清理完成");
        } catch (\Throwable $e) {
            Logger::error('[Hublog cleanupOrphanUploads] ' . $e->getMessage());
            return Response::error('清理失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 方案 B：登录用户主动清理自己的未发布上传文件（前端取消发布时调用）
     */

    public function cleanupOwnUploads(Request $request): Response
    {
        $user = Auth::check($request);
        // PHPStan 提级后揪出：Request 上并没有 getJson() 方法 ——
        // 调用它会抛「Call to undefined method」致命错误 → 整条清理接口 500。
        // JSON 请求体在 Request 构造时已经解析进 params，用 getArray 取即可。
        $urls = $request->getArray('urls');
        if (empty($urls)) {
            return Response::success(['deleted' => 0]);
        }

        try {
            $deleted = 0;
            foreach ($urls as $url) {
                $url = trim($url);
                if ($url === '') continue;
                // 权限校验：必须是自己的 + 未绑定 post
                $record = UploadRepository::findCleanableOwnUpload($url, (int)$user['id']);
                if (!$record) continue;

                // 删物理文件
                $filePath = __DIR__ . '/../../' . trim(parse_url($url, PHP_URL_PATH), '/');
                if ($filePath && file_exists($filePath)) {
                    @unlink($filePath);
                    $thumb = preg_replace('/\.[^.]+$/', '_thumb.$0', $filePath);
                    if (file_exists($thumb)) @unlink($thumb);
                }
                // 删 DB
                UploadRepository::deleteUploadById((int)$record['id']);
                $deleted++;
            }
            return Response::success(['deleted' => $deleted]);
        } catch (\Throwable $e) {
            Logger::error('[Hublog cleanupOwnUploads] ' . $e->getMessage());
            return Response::error('清理失败', 500);
        }
    }

    /**
     * 方案 A：惰性自动清理（每次请求 5% 概率触发，最多清理 50 个过期文件）
     * 在 api/index.php 入口调用，不阻塞主流程
     *
     * P1-3：入口已改为 Hublog\Core\Maintenance 统一调度（响应发出后再跑），
     * 本方法保留是为了兼容外部直接调用；实际清理由 cleanupExpiredUploads() 承担。
     */

    public static function lazyCleanupExpiredUploads(): void
    {
        // 5% 概率触发
        if (mt_rand(1, 100) > 5) return;

        try {
            self::cleanupExpiredUploads();
        } catch (\Throwable $e) {
            // 惰性清理失败不影响主流程
            Logger::error('[Hublog lazyCleanupExpiredUploads] ' . $e->getMessage());
        }
    }

    /**
     * 清理已过期且未绑定动态的上传文件，返回删除条数。
     *
     * P1-3：从 lazyCleanupExpiredUploads() 里抽出来的**真实执行体**，
     * 供 Hublog\Core\Maintenance 调度调用（响应发出后再跑，不占用户请求的延迟）。
     *
     * @param int $limit 单次最多清理多少条（分批，避免一次锁表过久）
     */
    public static function cleanupExpiredUploads(int $limit = 50): int
    {
        // P1-3：阈值用 PHP 侧时间（Helper::now()）而不是数据库的 NOW()。
        // 两个原因：① 与 publishScheduledPosts() 的时间口径统一（同一份 Helper::now()）；
        // ② 带占位符的���面量更利于走 expires_at 索引，且不依赖数据库时区与 PHP 时区一致。
        // LIMIT 钳制、空结果短路与参数绑定都下沉到仓储；这里只保留返回条数语义。
        $rows = UploadRepository::fetchExpiredUnboundUploads(Helper::now(), $limit);
        if (empty($rows)) return 0;

        foreach ($rows as $row) {
            $filePath = __DIR__ . '/../../' . trim(parse_url($row['url'], PHP_URL_PATH), '/');
            if ($filePath && file_exists($filePath)) {
                @unlink($filePath);
                $thumb = preg_replace('/\.[^.]+$/', '_thumb.$0', $filePath);
                if (file_exists($thumb)) @unlink($thumb);
            }
        }

        $ids = array_column($rows, 'id');
        // P2-10：原 PDO::exec() 只接受 1 个参数，第二个 $ids 数组不会绑定，
        // 含 ? 占位符又未绑定会抛异常被下方 catch 静默吞掉 → uploads 行永久残留、
        // 清理从未真正生效。仓储改用 prepare+execute 绑定参数正确删除。
        UploadRepository::deleteUploadsByIds($ids);

        return count($ids);
    }
}
