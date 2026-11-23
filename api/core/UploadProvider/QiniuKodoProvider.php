<?php
/**
 * P2-2：从 core/UploadProvider.php 拆分出来的七牛云 Kodo Provider。
 * 归到 Hublog\Core\UploadProvider 命名空间；父类为 Hublog\Core\UploadProvider。
 */

namespace Hublog\Core\UploadProvider;

use Hublog\Core\Exception\HublogException;
use Hublog\Core\Security;

/* ======================================================================
 * ④ 七牛云 Kodo：上传凭证（UploadToken = AK:Sign:EncodedPutPolicy）
 *   官方算法：https://developer.qiniu.com/kodo/1208/upload-token
 *   Sign = URLSafe Base64(HMAC-SHA1(EncodedPutPolicy, SK))
 * ====================================================================== */
class QiniuKodoProvider extends \Hublog\Core\UploadProvider
{
    public function signDirectUpload(string $type, string $filename, int $contentLength, string $originalExt): UploadDirectSign
    {
        $ak       = (string)self::readCfg('upload_qiniu_access_key',   'UPLOAD_QINIU_ACCESS_KEY',   '');
        $sk       = (string)self::readCfg('upload_qiniu_secret_key',   'UPLOAD_QINIU_SECRET_KEY',   '');
        $bucket   = (string)self::readCfg('upload_qiniu_bucket',       'UPLOAD_QINIU_BUCKET',       '');
        $cdn      = (string)self::readCfg('upload_qiniu_cdn_domain',   'UPLOAD_QINIU_CDN_DOMAIN',   '');
        if ($ak === '' || $sk === '' || $bucket === '') {
            throw new HublogException('七牛云配置不完整（AccessKey / SecretKey / Bucket）');
        }
        if ($cdn === '') {
            throw new HublogException('七牛云必须配置 UPLOAD_QINIU_CDN_DOMAIN（没有免费默认域名）');
        }

        // 【P3 清理】大小校验块已收敛到父类 UploadProvider::assertUploadSize()，阈值与文案保持逐字不变
        $maxSize = self::assertUploadSize($contentLength);

        $ttl   = (int)self::readCfg('upload_signature_ttl', 'UPLOAD_SIGNATURE_TTL', 600);
        $expAt = time() + $ttl;

        $prefixDir = self::dirPrefix($type);
        $objectKey = self::buildObjectKey($prefixDir, $filename, $originalExt);

        $putPolicy = [
            'scope'      => $bucket . ':' . $objectKey,   // 指定 key（防止上传者覆盖别人的文件）
            'deadline'   => $expAt,
            'fsizeLimit' => $maxSize,
            // 'insertOnly' => 1, // 开启后无法覆盖同名文件（因 scope 带唯一 key，可安全开启 insertOnly=1）
            'insertOnly' => 1,
            // 'mimeLimit' => 根据 type 限制（image/* 等）
            'mimeLimit'  => $this->inferMimeLimit($type),
            // 七牛的 returnBody 规范：上传成功时服务端返回这个 JSON，让前端能直接拿到最终 key/hash/size
            'returnBody' => '{"key":"$(key)","hash":"$(etag)","size":$(fsize),"mime":"$(mimeType)","w":$(imageInfo.width),"h":$(imageInfo.height)}',
        ];

        $policyJson = json_encode($putPolicy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encodedPolicy = self::qiniuBase64($policyJson);
        $sign          = self::qiniuBase64(hash_hmac('sha1', $encodedPolicy, $sk, true));
        $uploadToken   = $ak . ':' . $sign . ':' . $encodedPolicy;

        // 七牛上传域名：https://developer.qiniu.com/kodo/1671/region-endpoint
        // 我们无法判断用户是哪个机房 -> 这里用 auto detect 域名 up.qiniup.com / v0 / up-z1 / up-na0 通用 https://upload.qiniup.com
        // 同时在 form_data 里带 token，只要服务端域名正确就能处理
        $uploadHost = 'https://upload.qiniup.com'; // 中国大陆通用（HTTPS 上传入口）

        $sign = new UploadDirectSign();
        $sign->driver          = 'qiniu';
        $sign->upload_url      = $uploadHost;
        $sign->method          = 'POST';
        $sign->form_data       = [
            'token' => $uploadToken,
            'key'   => $objectKey,
        ];
        $sign->object_key      = $objectKey;
        $sign->final_url       = self::buildFinalUrl('qiniu', $objectKey, $cdn);
        $sign->expires_at      = $expAt;
        $sign->provider_hint   = sprintf('七牛云：bucket=%s cdn=%s prefix=%s', $bucket, $cdn, $prefixDir);
        $sign->file_field_name = 'file';
        return $sign;
    }

    public function testConnection(): array
    {
        try {
            // 发一个 0 字节的假试探：用 sign() 生成 token 再上传一个 0 字节空文件
            $fakeSign = $this->signDirectUpload('image', 'connectivity-test.bin', 1, 'bin');
            // 尝试发起一次空 body 的 multipart 到 upload.qiniup.com，看是否有有效响应
            $boundary = '----HublogTest' . bin2hex(random_bytes(8));
            $body = '';
            foreach ($fakeSign->form_data as $k => $v) {
                $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
            }
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"test.txt\"\r\nContent-Type: text/plain\r\n\r\nok\r\n--{$boundary}--\r\n";

            $ch = curl_init($fakeSign->upload_url);
            Security::applyCurlSsrfOptions(curl_copy_handle($ch), true);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data; boundary=' . $boundary],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false && $err !== '') {
                return ['ok' => false, 'code' => 'CURL_ERR', 'reason' => '七牛上传地址请求失败：' . $err, 'diagnosis' => '七牛有多个上传入口，如 upload.qiniup.com（华东通用），若为海外 bucket 请换成对应区域域名（developer.qiniu.com/kodo/1671/region-endpoint）'];
            }
            if ($code === 200) {
                return ['ok' => true, 'code' => 'OK', 'reason' => '七牛上传凭证有效 + 上传链路正常（已实际写入测试文件，建议 7 天后按 lifecycle 清理 prefix ' . self::dirPrefix('image') . '）', 'diagnosis' => null];
            }
            $data = @json_decode((string)$resp, true);
            if (is_array($data) && !empty($data['error'])) {
                $msg = (string)$data['error'];
                if (str_contains($msg, 'bad token'))    return ['ok' => false, 'code' => 'BAD_TOKEN',    'reason' => '上传凭证无效 (bad token)', 'diagnosis' => '检查 AK/SK 是否填错；Bucket 名称必须与 AK 同主账号'];
                if (str_contains($msg, 'file exists'))  return ['ok' => true,  'code' => 'OK',           'reason' => 'Bucket 可写（探测命中 insertOnly 校验 = 已写入成功）', 'diagnosis' => null];
                if (str_contains($msg, 'bucket not exist')) return ['ok' => false, 'code' => 'NO_BUCKET', 'reason' => 'Bucket 不存在：' . $msg, 'diagnosis' => '检查 Bucket 名称、所在区域'];
                if (str_contains($msg, 'no such file or directory')) return ['ok' => false, 'code' => 'BUCKET_MISMATCH', 'reason' => 'Bucket 不存在或跨区域：' . $msg, 'diagnosis' => null];
                return ['ok' => false, 'code' => 'UPLOAD_ERR_' . $code, 'reason' => '上传返回错误：' . $msg, 'diagnosis' => null];
            }
            return ['ok' => false, 'code' => 'HTTP_' . $code, 'reason' => 'HTTP ' . $code . ' ' . substr((string)$resp, 0, 200), 'diagnosis' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 'EXCEPTION', 'reason' => $e->getMessage(), 'diagnosis' => null];
        }
    }

    private function inferMimeLimit(string $type): string
    {
        switch ($type) {
            case 'image':      return 'image/jpeg;image/png;image/gif;image/webp';
            case 'avatar':     return 'image/jpeg;image/png;image/webp;image/gif';
            case 'video':      return 'video/mp4;video/webm;video/quicktime;video/x-msvideo';
            case 'cover':      return 'image/jpeg;image/png;image/webp;image/gif;video/mp4;video/webm';
            case 'attachment':
                // 七牛 mimeLimit 不支持 "*" 精确任意 mime → 空字符串不限制；我们已在大小层做了限制
                return '';
            default:
                return '';
        }
    }

    protected static function qiniuBase64(string $bytes): string
    {
        $s = base64_encode($bytes);
        $s = rtrim($s, '=');
        return strtr($s, '+/', '-_');
    }
}
