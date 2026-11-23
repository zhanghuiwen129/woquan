<?php
/**
 * Hublog 云存储直传抽象层（纯 PHP，零外部依赖，不装 Composer 也能用）
 *
 * 【设计原则】
 *   - 所有签名/计算都在服务端完成，前端绝对拿不到 AK/SK（经验 853150 反模式规避）
 *   - 前端直传链路固定返回契约：
 *        {
 *          driver: 'aliyun'|'qcloud'|'qiniu'|'local',
 *          upload_url: string,          // POST/PUT 的目标地址（阿里云 https://bucket.endpoint / 七牛 http(s)://up-z1.qiniup.com 等）
 *          method: 'POST'|'PUT',        // OSS/COS/Qiniu=POST Form 上传
 *          form_data: { key:value },    // 表单字段（key/policy/signature/OSSAccessKeyId... 或七牛 token）
 *          object_key: string,          // 目标路径 + 文件名（不含域名的 key，用于写入 attachments 表判断是否为本站点文件）
 *          final_url: string,           // 上传成功后用户可访问的最终 URL（CDN/公网），前端直接使用
 *          expires_at: 1700000000,      // 签名过期 unix 时间戳，前端超过该时间后需重新 /upload/sign
 *          provider_hint: string,       // 调试用提示
 *          file_field_name: 'file'      // 上传表单的 file 字段名，OSS/COS/Qiniu 一律叫 file（与官方文档保持一致）
 *        }
 *   - OSS/COS expiration 严格使用 ISO 8601 UTC 格式（经验 703935 根因修复）
 *   - 三家对象存储都给了签名接口的测试桩：public function testConnection(): {ok: bool, reason?}
 *
 * 【Upload.php 调用示例】
 *   $provider = UploadProvider::resolve('image', 'jpg', 3_000_000, 'photo_123.jpg');
 *   if ($provider instanceof LocalUploadProvider) {
 *       // 原走 PHP 后端保存流程（兼容本地开发）
 *   } else {
 *       // 直传模式：返回 $provider->sign()->toArray() 给前端
 *   }
 *
 *
 * ============================================================
 * 【⑧ 交付附录】三家控制台 CORS 配置 + 小程序/APP 直传样板
 * ------------------------------------------------------------
 * ▶️ 一、阿里云 OSS 控制台 CORS 设置（否则浏览器 H5 直传 403/CORS 拦截）
 *   1. 登录 OSS 控制台 → Bucket 列表 → 你的 Bucket → 权限管理 → 跨域设置
 *   2. 新增一条规则：
 *      - 来源(Origin)            : https://your-domain.com,http://localhost:3000
 *        （建议生产只填正式域名，测试加临时 localhost，不写 *）
 *      - 允许 Methods(Methods)   : POST, OPTIONS
 *      - 允许 Headers(AllowedHeaders): *
 *      - 暴露 Headers(ExposeHeaders): ETag,x-oss-request-id
 *      - 缓存时间(MaxAgeSeconds)  : 600
 *   3. RAM 子账号最小权限（不要用主账号 AK！）：
 *        { "Version":"1","Statement":[{
 *            "Effect":"Allow",
 *            "Action":["oss:PutObject","oss:GetBucketLocation","oss:HeadObject"],
 *            "Resource":["acs:oss:*:*:your-bucket-name/*","acs:oss:*:*:your-bucket-name"]
 *        }] }
 *
 * ------------------------------------------------------------
 * ▶️ 二、腾讯云 COS 控制台 CORS 设置
 *   1. 对象存储 → 存储桶列表 → 你的 Bucket → 安全管理 → 跨域访问 CORS 设置
 *   2. 新增：
 *      - 来源 Origin           : https://your-domain.com
 *      - 允许 Methods           : POST, OPTIONS
 *      - Allow-Headers          : *
 *      - Expose-Headers         : ETag,x-cos-request-id,Content-Length
 *      - Max-Age                : 600
 *   3. CAM 子账号最小权限（QcloudCOSProvider::testConnection 会调 HeadBucket）：
 *        QcloudCOSFullAccess 太宽了，自定义策略：
 *        { "version":"2.0","statement":[{
 *            "effect":"allow",
 *            "action":["name/cos:PutObject","name/cos:HeadBucket","name/cos:HeadObject"],
 *            "resource":["qcs::cos:ap-shanghai:uid/125xxxxxx:prefix//your-bucket-125xxxxxx/*"]
 *        }] }
 *
 * ------------------------------------------------------------
 * ▶️ 三、七牛云 Kodo 控制台 CORS 设置
 *   1. 对象存储 Kodo → 空间设置 → 跨域设置 → 添加规则
 *   2. 新增：
 *      - 来源域名      : https://your-domain.com
 *      - 允许方法      : POST, OPTIONS
 *      - 允许头        : *
 *      - 暴露头        : ETag,X-Log,X-Reqid
 *      - 缓存最大时间  : 600
 *   3. 七牛没有"子账号只允许 PutObject"的官方细粒度策略模板，建议单独开一个 AK/SK
 *      只绑定目标 Bucket 的"上传者"角色。
 *
 * ------------------------------------------------------------
 * ▶️ 四、小程序 / uni-app 直传样板代码（无需 SDK，直接 HTTP Form POST）
 *    // 以 uni-app 上传图片（image 类型）为例：
 *    async function uploadImageUni(filePath, size, contentType) {
 *        // ① 拿服务端签名
 *        const signResp = await uni.request({
 *            url: 'https://your-domain.com/api/upload/sign',
 *            method: 'POST',
 *            header: { Authorization: 'Bearer ' + token },
 *            data: {
 *                type: 'image',
 *                filename: filePath.split('/').pop(),
 *                content_length: size,
 *                content_type: contentType || 'image/jpeg',
 *            }
 *        });
 *        const { driver, is_local, sign } = signResp.data.data;
 *
 *        // ② multipart/form-data POST 到云存储
 *        const formData = Object.assign({}, sign.form_data || {});
 *        const upTask = uni.uploadFile({
 *            url: sign.upload_url,
 *            filePath: filePath,
 *            name: sign.file_field_name || 'file',      // 注意：七牛也是 file
 *            formData: formData,
 *            success: (res) => {
 *                // 204 OSS/COS 空体；七牛 200 JSON；200-299 都算成功
 *                if (res.statusCode >= 200 && res.statusCode < 300) {
 *                    // ③ 回后端登记 URL 入库（本地 driver 可跳过 register 但 /upload/register
 *                    //    是幂等的，统一调一下不会有副作用）
 *                    uni.request({
 *                        url: 'https://your-domain.com/api/upload/register',
 *                        method: 'POST',
 *                        header: { Authorization: 'Bearer ' + token },
 *                        data: {
 *                            type: 'image',
 *                            object_key: sign.object_key,
 *                            final_url: sign.final_url,
 *                            file_size: size,
 *                            mime_type: contentType,
 *                            orig_filename: filePath.split('/').pop(),
 *                        },
 *                        success: (r2) => { console.log('OK', r2.data); }
 *                    });
 *                } else {
 *                    console.error('直传失败', res.statusCode, res.data);
 *                }
 *            }
 *        });
 *        return upTask;  // 可以 onProgressUpdate 监听进度
 *    }
 *
 * ------------------------------------------------------------
 * ▶️ 五、常见错误速查表
 *    403 AccessDenied           → Policy/Signature 错；AK 没 PutObject 权限；Bucket/Endpoint 对不上
 *    CORS 预检 403/无响应       → 控制台 CORS 没配置 / Origin 没加白名单 / Methods 缺 OPTIONS
 *    400 InvalidObjectName      → 文件名里有中文/特殊字符，UploadProvider 已经净化了，仍报错多半是自定义 dir_prefix 缺 /
 *    七牛 614 / 631             → 上传凭证 scope 填错（bucket != 注册的那个）或 token 过期
 *    COS InvalidAccessKeyId     → SecretId/SecretKey 长度不一致
 *    直传成功但显示 404 图片     → 对象私有读；开启"公有读"或用签名 URL（可在 CDN 层配 referer 防盗链）
 *
 * ============================================================
 *
 * 【P2-2 PSR-4 拆分说明】
 *   原文件把 7 个类/trait（抽象主类 + 6 个具体实现/返回结构）挤在一个文件里，
 *   实际能跑纯属 Upload.php 的 resolve() 先 require 了整个文件、把附属类一并加载（加载顺序隐式耦合），
 *   且 UploadDirectSign 等原本就是「只有主文件能自动加载、其余靠副作用带加载」的脆弱结构。
 *   现按 PSR-4 把实现类与返回结构拆到 core/UploadProvider/ 子目录（命名空间 Hublog\Core\UploadProvider），
 *   主抽象类 Hublog\Core\UploadProvider 仍留在本文件、对外公开静态 API（resolve/currentDriver/readCfgPublic 等）不变。
 */

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;
use Hublog\Core\UploadProvider\UploadProviderSettingsReader;
use Hublog\Core\UploadProvider\UploadDirectSign;
use Hublog\Core\UploadProvider\LocalUploadProvider;
use Hublog\Core\UploadProvider\AliyunOssProvider;
use Hublog\Core\UploadProvider\QcloudCosProvider;
use Hublog\Core\UploadProvider\QiniuKodoProvider;

/**
 * 统一入口解析类（抽象基类，对外公开静态 API）
 */
abstract class UploadProvider
{
    use UploadProviderSettingsReader;

    /**
     * 对外：获取某个上传任务的直传签名/元信息
     *
     * @param string $type           avatar / cover / image / video / attachment
     * @param string $filename       用户原始文件名（带扩展名，不保证有效）
     * @param int    $contentLength  声明的文件大小（字节），服务端签名时写入 policy，过大直接报错
     * @param string $originalExt    声明的扩展名（jpg/mp4/zip 等），会做白名单校验
     * @return UploadDirectSign      直接透传给前端做直传
     * @throws HublogException       driver 未配置、MIME/大小不合法、签名算法失败等
     */
    abstract public function signDirectUpload(string $type, string $filename, int $contentLength, string $originalExt): UploadDirectSign;

    /**
     * 连通性测试：后台点"测试"时调用（经验 1277439 错误分类：AccessDenied/BucketNotExist/RegionError 分流）
     *
     * @return array{ok:bool,code:string,reason:string,diagnosis:?string}
     */
    abstract public function testConnection(): array;

    /**
     * 上传大小上限校验（4 个 Provider 共用）
     *
     * 【P3 清理】原先 AliyunOss / QcloudCos / QiniuKodo / Local 四个子类各自复制了一份
     * 逐字相同的大小校验块（阈值 UPLOAD_MAX_SIZE、异常类型 HublogException、文案完全一致），
     * 后续改阈值/改文案极易只改到一半导致各家行为不一致，故收敛到父类唯一实现。
     * 阈值、异常类型、错误文案与迁移前逐字一致，未做任何调整。
     *
     * @param int $contentLength 声明的文件大小（字节）
     * @return int 实际生效的上限（字节），供调用方继续用于 content-length-range / fsizeLimit
     * @throws HublogException 超过上限时抛出（异常码仍为默认 400）
     */
    protected static function assertUploadSize(int $contentLength): int
    {
        $maxSize = (int)UPLOAD_MAX_SIZE;
        if ($contentLength > $maxSize) {
            throw new HublogException('文件大小超过上限：' . number_format($maxSize / 1024 / 1024, 1) . 'MB');
        }
        return $maxSize;
    }

    /**
     * 工厂：按当前配置的 driver new 对应实例
     */
    public static function resolve(): self
    {
        $driver = self::currentDriver();
        switch ($driver) {
            case 'aliyun': return new AliyunOssProvider();
            case 'qcloud': return new QcloudCosProvider();
            case 'qiniu':  return new QiniuKodoProvider();
            case 'local':
            default:       return new LocalUploadProvider();
        }
    }
}
