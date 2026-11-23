<?php
/**
 * P2-2：从 core/UploadProvider.php 拆分出来的阿里云 OSS Provider。
 * 归到 Hublog\Core\UploadProvider 命名空间；父类为 Hublog\Core\UploadProvider。
 */

namespace Hublog\Core\UploadProvider;

use Hublog\Core\Exception\HublogException;
use Hublog\Core\Security;

/* ======================================================================
 * ② 阿里云 OSS：POST Object Form 签名
 *   官方算法文档：https://help.aliyun.com/document_detail/31988.html
 *   Expiration 必须是 ISO 8601 UTC 字符串（修复经验 703935）
 * ====================================================================== */
class AliyunOssProvider extends \Hublog\Core\UploadProvider
{
    public function signDirectUpload(string $type, string $filename, int $contentLength, string $originalExt): UploadDirectSign
    {
        $akId     = (string)self::readCfg('upload_oss_access_key_id',     'UPLOAD_OSS_ACCESS_KEY_ID', '');
        $akSecret = (string)self::readCfg('upload_oss_access_key_secret', 'UPLOAD_OSS_ACCESS_KEY_SECRET', '');
        $bucket   = (string)self::readCfg('upload_oss_bucket',            'UPLOAD_OSS_BUCKET', '');
        $endpoint = (string)self::readCfg('upload_oss_endpoint',          'UPLOAD_OSS_ENDPOINT', '');
        $cdn      = (string)self::readCfg('upload_oss_cdn_domain',        'UPLOAD_OSS_CDN_DOMAIN', '');
        if ($akId === '' || $akSecret === '' || $bucket === '' || $endpoint === '') {
            throw new HublogException('阿里云 OSS 配置不完整（AccessKey/Bucket/Endpoint），无法直传');
        }

        // 大小校验（包含在 Policy conditions 里，OSS 也会校验超限的文件；这里前置更友好）
        // 【P3 清理】校验块已收敛到父类 UploadProvider::assertUploadSize()，阈值与文案保持逐字不变
        $maxSize = self::assertUploadSize($contentLength);

        $ttl   = (int)self::readCfg('upload_signature_ttl', 'UPLOAD_SIGNATURE_TTL', 600);
        $expAt = time() + $ttl;
        // OSS 要求 UTC ISO 8601，例如 "2026-05-08T14:45:51Z"（修正经验 703935）
        $expiration = gmdate('Y-m-d\TH:i:s\Z', $expAt);

        $prefixDir = self::dirPrefix($type);
        $objectKey = self::buildObjectKey($prefixDir, $filename, $originalExt);

        // Policy Document（UTF-8 JSON）
        $policyArr = [
            'expiration' => $expiration,
            'conditions' => [
                ['bucket' => $bucket],
                ['eq', '$key', $objectKey],
                ['content-length-range', 0, $maxSize],
                // 前缀约束：starts-with key 防止用户绕过上传目录（经验 853150 加固）
                ['starts-with', '$key', $prefixDir],
            ],
        ];
        $policyJson  = json_encode($policyArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $policyBase64 = self::ossBase64Encode($policyJson);

        // HMAC-SHA1 + Base64 签名
        $signatureBin = hash_hmac('sha1', $policyBase64, $akSecret, true);
        $signature    = self::ossBase64Encode($signatureBin);

        // Host：<bucket>.<endpoint> （外网访问）
        $host = self::makeUploadHost($bucket, $endpoint);

        // 直传时上传地址就是 Host
        $uploadUrl = (self::readCfg('upload_oss_ssl', 'UPLOAD_OSS_SSL', '1') === '1' ? 'https://' : 'http://') . $host;

        // Final URL：若配置 CDN 走 CDN；否则走 bucket.endpoint 公网域名
        if ($cdn !== '') {
            $baseDomain = $cdn;
        } else {
            $baseDomain = $host;
        }

        $sign = new UploadDirectSign();
        $sign->driver          = 'aliyun';
        $sign->upload_url      = $uploadUrl;
        $sign->method          = 'POST';
        $sign->form_data       = [
            'key'                 => $objectKey,
            'policy'              => $policyBase64,
            'OSSAccessKeyId'      => $akId,
            'signature'           => $signature,
            'success_action_status' => '200',
            // 可选：若需要服务器端回调 (OSS callback)，可在此追加 callback / callback-var
            // 这里默认不启用回调，上传成功后前端 POST /api/upload/register 让服务器登记
        ];
        $sign->object_key      = $objectKey;
        $sign->final_url       = self::buildFinalUrl('aliyun', $objectKey, $baseDomain);
        $sign->expires_at      = $expAt;
        $sign->provider_hint   = sprintf('阿里云 OSS：bucket=%s endpoint=%s prefix=%s', $bucket, $endpoint, $prefixDir);
        $sign->file_field_name = 'file';
        return $sign;
    }

    public function testConnection(): array
    {
        // 以一个极小的签名 → 立刻回传一份 policy 签名用于检查 AK/Bucket 可连通
        try {
            // 用 1 字节的假文件试探：生成签名后，发 curl HEAD bucket.endpoint / 403 则 AK 可能无权限 / 404 bucket 不存在 / 200 通
            $akId     = (string)self::readCfg('upload_oss_access_key_id',     'UPLOAD_OSS_ACCESS_KEY_ID', '');
            $akSecret = (string)self::readCfg('upload_oss_access_key_secret', 'UPLOAD_OSS_ACCESS_KEY_SECRET', '');
            $bucket   = (string)self::readCfg('upload_oss_bucket',            'UPLOAD_OSS_BUCKET', '');
            $endpoint = (string)self::readCfg('upload_oss_endpoint',          'UPLOAD_OSS_ENDPOINT', '');
            if ($akId === '' || $akSecret === '' || $bucket === '' || $endpoint === '') {
                return ['ok' => false, 'code' => 'CONFIG_EMPTY', 'reason' => 'OSS 配置不完整', 'diagnosis' => '请至少填 AccessKeyId / AccessKeySecret / Bucket / Endpoint 四项'];
            }
            $host = self::makeUploadHost($bucket, $endpoint);
            $url  = 'https://' . $host . '/?attachment=hublog'; // 任意 query 避免命中 static

            $ch = curl_init($url);
            // SSRF 保护：对象存储域名是公开的，但仍限协议 https、不跟随跳转（经验 P1-2 同款保护）
            Security::applyCurlSsrfOptions(curl_copy_handle($ch), true);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_USERAGENT      => 'hublog-connectivity-test/1.0',
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false && $err !== '') {
                return ['ok' => false, 'code' => 'CURL_ERR', 'reason' => 'HTTP 请求失败：' . $err, 'diagnosis' => '检查网络/SSL；EndPoint 是否正确（不要加 bucket 前缀）'];
            }
            if ($code >= 200 && $code < 400) {
                return ['ok' => true,  'code' => 'OK',       'reason' => '连通性正常（HTTP ' . $code . '），Bucket 可访问', 'diagnosis' => null];
            }
            if ($code === 403) {
                return ['ok' => false, 'code' => 'ACCESS_DENIED', 'reason' => 'OSS 返回 403 拒绝访问', 'diagnosis' => '常见原因：1) AK/SK 填错；2) RAM 策略未授权 PutObject/GetObject；3) Bucket 不是这个 AK 所属主账号'];
            }
            if ($code === 404) {
                return ['ok' => false, 'code' => 'NO_SUCH_BUCKET', 'reason' => 'Bucket 不存在（HTTP 404）', 'diagnosis' => '检查 Bucket 名称是否正确、所属 Region 是否和 Endpoint 匹配'];
            }
            return ['ok' => false, 'code' => 'HTTP_' . $code, 'reason' => '服务器返回 HTTP ' . $code, 'diagnosis' => '参考 OSS 错误中心对照状态码排查'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 'EXCEPTION', 'reason' => '异常：' . $e->getMessage(), 'diagnosis' => null];
        }
    }

    private function makeUploadHost(string $bucket, string $endpoint): string
    {
        $endpoint = trim($endpoint);
        $endpoint = preg_replace('#^https?://#', '', $endpoint);
        $bucket   = trim($bucket, '/.');
        // 默认是 "virtual-hosted style"：bucket.endpoint（阿里云对外访问的推荐风格）
        return $bucket . '.' . ltrim($endpoint, '/');
    }

    /**
     * 阿里云 OSS 要求"URL-safe 风格"但实际算法是：先 base64 再把 +→-、/→_、去掉尾部 =
     * 这是 PostObject policy 文档里的标准算法
     */
    protected static function ossBase64Encode(string $data): string
    {
        $b = base64_encode($data);
        $b = rtrim($b, '=');
        return strtr($b, '+/', '-_');
    }
}
