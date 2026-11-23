<?php
/**
 * P2-2：从 core/UploadProvider.php 拆分出来的本地存储 Provider。
 * 归到 Hublog\Core\UploadProvider 命名空间；父类为 Hublog\Core\UploadProvider。
 */

namespace Hublog\Core\UploadProvider;

use Hublog\Core\Exception\HublogException;
use Hublog\Core\PathResolver;

/* ======================================================================
 * ① 本地存储 Provider：返回"后端直传自己"的 URL，前端行为和云存储一致（POST 回来）
 *   这样在本地开发时不用切换代码，Upload.php 里 registerFile 原流程直接兼容。
 * ====================================================================== */
class LocalUploadProvider extends \Hublog\Core\UploadProvider
{
    public function signDirectUpload(string $type, string $filename, int $contentLength, string $originalExt): UploadDirectSign
    {
        // 【类型-后端接口端点映射】本地模式：仍然走到 Upload.php 的 $_FILES 流程
        // P0-UPLOAD-404：本项目 API 统一走 PATH_INFO 模式（config.js API_BASE=/api/index.php），
        //   不依赖 Web 服务器伪静态重写。端点必须带 /api/index.php 前缀，否则前端 XHR 直接
        //   POST 到 /api/auth/avatar 会命中服务器 404 原生页（nginx location /api/ 无对应重写）。
        $endpointMap = [
            'avatar'     => '/api/index.php/auth/avatar',
            'cover'      => '/api/index.php/auth/cover',
            'image'      => '/api/index.php/posts/upload',
            'video'      => '/api/index.php/posts/upload-video',
            'attachment' => '/api/index.php/posts/upload-attachment',
        ];
        if (!isset($endpointMap[$type])) {
            throw new HublogException('不支持的上传类型: ' . $type);
        }

        // 校验大小（local 模式实际还会再次校验；这里前置拦截更清楚）
        // 【P3 清理】校验块已收敛到父类 UploadProvider::assertUploadSize()，阈值与文案保持逐字不变
        $maxSize = self::assertUploadSize($contentLength);

        $ttl = (int)self::readCfg('upload_signature_ttl', 'UPLOAD_SIGNATURE_TTL', 600);
        $sign = new UploadDirectSign();
        $sign->driver         = 'local';
        // 仍走 /api/index.php?r=xxx 的 PATH_INFO 兼容路径（config.js API_BASE 模式一致）
        // PathResolver::url() 只接受一个参数；此前误传 true（被忽略）。去掉。
        // 注意：url() 可能返回 ""（空 baseUrl）+ path，即纯相对 /api/index.php/xxx，
        //       前端拿到后直接以 origin 拼接，符合 PATH_INFO 模式。
        $sign->upload_url     = PathResolver::url($endpointMap[$type]);
        $sign->method         = 'POST';
        $sign->form_data      = [
            // 本地模式不需要鉴权字段；但要声明类型方便调试
            '_upload_type' => $type,
        ];
        $sign->object_key     = 'local://' . $type . '/' . bin2hex(random_bytes(8));
        $sign->final_url      = '';  // 本地模式上传成功后会在返回 data.url 里带最终 URL
        $sign->expires_at     = time() + $ttl;
        $sign->provider_hint  = 'local-storage: 文件先上传到服务器，再由后端保存到 uploads/ 目录';
        $sign->file_field_name = $type === 'avatar' ? 'avatar' : ($type === 'cover' ? 'cover' : ($type === 'attachment' ? 'attachment' : ($type === 'video' ? 'video' : 'image')));
        return $sign;
    }

    public function testConnection(): array
    {
        // 本地存储：检查 uploads/ 目录可写
        // 注意：UPLOAD_VIDEO_DIR 在 config.example.php 里曾经缺失（只有 install.php 生成的
        // config.php 才有），直接引用会抛「Undefined constant」致命错误。
        // 这里统一用 defined() 兜底，与 ChatController / PostUploadTrait 的写法保持一致。
        $dirs = [
            UPLOAD_AVATAR_DIR,
            UPLOAD_IMAGE_DIR,
            defined('UPLOAD_VIDEO_DIR') ? UPLOAD_VIDEO_DIR : 'uploads/videos/',
            UPLOAD_ATTACHMENT_DIR,
        ];
        $lastErr = null;
        foreach ($dirs as $d) {
            $dir = rtrim($d, '/\\') . '/';
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    return ['ok' => false, 'code' => 'DIR_NOT_EXIST', 'reason' => "目录不存在且无法创建：{$dir}", 'diagnosis' => '请检查 wwwroot/uploads/ 是否有写入权限（chmod 755）'];
                }
            }
            $testFile = $dir . 'hublog_write_test_' . bin2hex(random_bytes(4)) . '.tmp';
            if (!@file_put_contents($testFile, 'ok')) {
                $lastErr = error_get_last()['message'] ?? '写入失败';
                return ['ok' => false, 'code' => 'DIR_WRITE_FAIL', 'reason' => "无法写入：{$dir}", 'diagnosis' => $lastErr];
            }
            unlink($testFile);
        }
        return ['ok' => true, 'code' => 'OK', 'reason' => '本地 uploads/ 各目录读写正常', 'diagnosis' => null];
    }
}
