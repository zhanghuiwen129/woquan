<?php
/**
 * P2-2：从 core/UploadProvider.php 拆分出来的腾讯云 COS Provider。
 * 归到 Hublog\Core\UploadProvider 命名空间；父类为 Hublog\Core\UploadProvider。
 */

namespace Hublog\Core\UploadProvider;

use Hublog\Core\Exception\HublogException;
use Hublog\Core\Security;

/* ======================================================================
 * ③ 腾讯云 COS：Post Object 签名（QCloud v5 auth）
 *   - 算法与 OSS 相同风格 HMAC-SHA1；字段名是 q- 前缀
 *   - 官方文档：https://cloud.tencent.com/document/product/436/14690
 * ====================================================================== */
class QcloudCosProvider extends \Hublog\Core\UploadProvider
{
    public function signDirectUpload(string $type, string $filename, int $contentLength, string $originalExt): UploadDirectSign
    {
        $secretId  = (string)self::readCfg('upload_cos_secret_id',  'UPLOAD_COS_SECRET_ID',  '');
        $secretKey = (string)self::readCfg('upload_cos_secret_key', 'UPLOAD_COS_SECRET_KEY', '');
        $bucket    = (string)self::readCfg('upload_cos_bucket',     'UPLOAD_COS_BUCKET',     '');
        $region    = (string)self::readCfg('upload_cos_region',     'UPLOAD_COS_REGION',     '');
        $cdn       = (string)self::readCfg('upload_cos_cdn_domain', 'UPLOAD_COS_CDN_DOMAIN', '');
        if ($secretId === '' || $secretKey === '' || $bucket === '' || $region === '') {
            throw new HublogException('腾讯云 COS 配置不完整（SecretId/SecretKey/Bucket/Region）');
        }

        // 【P3 清理】大小校验块已收敛到父类 UploadProvider::assertUploadSize()，阈值与文案保持逐字不变
        $maxSize = self::assertUploadSize($contentLength);

        $ttl   = (int)self::readCfg('upload_signature_ttl', 'UPLOAD_SIGNATURE_TTL', 600);
        $now   = time();
        $expAt = $now + $ttl;
        // q-sign-time / q-key-time 格式：开始时间戳;结束时间戳 (unix)
        $keyTime = $now . ';' . $expAt;

        $prefixDir = self::dirPrefix($type);
        $objectKey = self::buildObjectKey($prefixDir, $filename, $originalExt);

        // Policy JSON（和 OSS 类似，但 Conditions 使用数组式数组）
        $policyArr = [
            'expiration' => gmdate('Y-m-d\TH:i:s\Z', $expAt),
            'conditions' => [
                ['q-sign-algorithm' => 'sha1'],
                ['q-ak'            => $secretId],
                ['q-sign-time'     => $keyTime],
                ['q-key-time'      => $keyTime],
                ['bucket'          => $bucket],
                ['eq', '$key',     $objectKey],
                ['starts-with', '$key', $prefixDir],
                ['content-length-range', 0, $maxSize],
            ],
        ];
        $policyJson  = json_encode($policyArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $policyBase64 = base64_encode($policyJson);

        // 签名字符串：Sha1(policyBase64) 后 HMAC-SHA1(key)
        // 参考 COS 官方多签名：StringToSign = SHA1(policy)，SignKey = HMAC-SHA1(KeyTime, $SecretKey)，Signature = HMAC-SHA1(StringToSign, SignKey)
        $signKey       = hash_hmac('sha1', $keyTime, $secretKey, false);
        $stringToSign  = sha1($policyBase64, false);
        $signature     = hash_hmac('sha1', $stringToSign, $signKey, false);

        // 上传地址：https://<bucket-appid>.cos.<region>.myqcloud.com
        $host  = sprintf('%s.cos.%s.myqcloud.com', $bucket, $region);
        $scheme = (self::readCfg('upload_cos_ssl', 'UPLOAD_COS_SSL', '1') === '1') ? 'https://' : 'http://';
        $uploadUrl = $scheme . $host;

        $baseDomain = ($cdn !== '') ? $cdn : $host;

        $sign = new UploadDirectSign();
        $sign->driver          = 'qcloud';
        $sign->upload_url      = $uploadUrl;
        $sign->method          = 'POST';
        $sign->form_data       = [
            'key'               => $objectKey,
            'policy'            => $policyBase64,
            'q-sign-algorithm'  => 'sha1',
            'q-ak'              => $secretId,
            'q-key-time'        => $keyTime,
            'q-signature'       => $signature,
        ];
        $sign->object_key      = $objectKey;
        $sign->final_url       = self::buildFinalUrl('cos', $objectKey, $baseDomain);
        $sign->expires_at      = $expAt;
        $sign->provider_hint   = sprintf('腾讯云 COS：bucket=%s region=%s prefix=%s', $bucket, $region, $prefixDir);
        $sign->file_field_name = 'file';
        return $sign;
    }

    public function testConnection(): array
    {
        try {
            $bucket    = (string)self::readCfg('upload_cos_bucket',     'UPLOAD_COS_BUCKET', '');
            $region    = (string)self::readCfg('upload_cos_region',     'UPLOAD_COS_REGION', '');
            if ($bucket === '' || $region === '') {
                return ['ok' => false, 'code' => 'CONFIG_EMPTY', 'reason' => 'COS Bucket/Region 未填', 'diagnosis' => null];
            }
            $host = sprintf('%s.cos.%s.myqcloud.com', $bucket, $region);
            $url  = 'https://' . $host . '/?Hublog-Connectivity=1';
            $ch = curl_init($url);
            Security::applyCurlSsrfOptions($ch, true);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false && $err !== '') {
                return ['ok' => false, 'code' => 'CURL_ERR', 'reason' => 'HTTP 请求失败：' . $err, 'diagnosis' => '检查 Bucket-appid 格式（必须是 "名称-appid"，如 "hublog-12500000000"）；Region 是否正确（ap-guangzhou 等）'];
            }
            if ($code >= 200 && $code < 400) return ['ok' => true, 'code' => 'OK', 'reason' => 'COS Bucket 域名可访问（HTTP ' . $code . '）', 'diagnosis' => null];
            if ($code === 403) return ['ok' => false, 'code' => 'ACCESS_DENIED', 'reason' => 'COS 返回 403', 'diagnosis' => '检查 SecretId/SecretKey；子账号是否有 PutObject 权限；Bucket Policy 是否限制来源'];
            if ($code === 404) return ['ok' => false, 'code' => 'NO_SUCH_BUCKET', 'reason' => 'Bucket 不存在（404）', 'diagnosis' => 'Bucket 名称必须带 -12 位 AppID 后缀（如 hublog-1251234567），请核对 COS 控制台'];
            return ['ok' => false, 'code' => 'HTTP_' . $code, 'reason' => 'HTTP ' . $code, 'diagnosis' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 'EXCEPTION', 'reason' => $e->getMessage(), 'diagnosis' => null];
        }
    }
}
