<?php
/**
 * P2-2：从 core/UploadProvider.php 单文件多类结构中拆分出来的配置读取 trait。
 * 原文件把 7 个类/trait 挤在一起，靠 Upload.php 的 resolve() 隐式带加载（加载顺序耦合）。
 * 现归到 Hublog\Core\UploadProvider 命名空间，由主抽象类 UploadProvider use。
 */

namespace Hublog\Core\UploadProvider;

use Hublog\Core\License;

/**
 * 云存储配置统一获取：先查 settings 表 key = upload_xxx；若没配置再回退 config.php 常量
 * （方便"生产环境后台填、开发环境直接写文件"两种部署方式平滑切换）
 */
trait UploadProviderSettingsReader
{
    /** 读取 settings 表配置，失败回退到 config.php 常量，均为空再返回 default（Upload 层通过公开别名调） */
    public static function readCfgPublic(string $key, string $fallbackConst = '', $default = '')
    {
        return self::readCfg($key, $fallbackConst, $default);
    }

    protected static function readCfg(string $key, string $fallbackConst = '', $default = '')
    {
        try {
            $fromDb = License::getSetting($key, null);
            if ($fromDb !== null && $fromDb !== '') {
                return $fromDb;
            }
        } catch (\Throwable $e) { /* DB 未安装/settings 表缺失时降级常量 */ }

        if ($fallbackConst !== '' && defined($fallbackConst)) {
            $v = constant($fallbackConst);
            if ($v !== null && $v !== '') return $v;
        }
        return $default;
    }

    /** 当前选择的 driver */
    public static function currentDriver(): string
    {
        $driver = (string)self::readCfg('upload_driver', 'UPLOAD_DRIVER', 'local');
        $driver = strtolower(trim($driver));
        if (!in_array($driver, ['local', 'aliyun', 'qcloud', 'qiniu'], true)) {
            return 'local';
        }
        return $driver;
    }

    /** 目录前缀统一读配置（默认 hublog 开头，多项目共用 bucket 避免名字冲突） */
    protected static function dirPrefix(string $uploadType): string
    {
        $prefix = (string)self::readCfg('upload_dir_prefix', 'UPLOAD_DIR_PREFIX', 'hublog');
        $prefix = trim($prefix, '/');
        // 类型二级子目录（avatars/images/videos/attachments/covers 统一规范，方便 lifecycle 管理）
        $subMap = [
            'avatar'     => 'avatars',
            'cover'      => 'covers',
            'image'      => 'images',
            'video'      => 'videos',
            'attachment' => 'attachments',
        ];
        $sub = $subMap[$uploadType] ?? 'misc';
        $ym  = date('Ym');
        return ($prefix === '' ? '' : $prefix . '/') . $sub . '/' . $ym . '/';
    }

    /** 生成上传后的最终 URL（注意与各家 driver 的 domain 规则对应） */
    protected static function buildFinalUrl(string $driver, string $objectKey, string $driverBaseDomain): string
    {
        $commonCdn = (string)self::readCfg('upload_cdn_common_domain', 'UPLOAD_CDN_COMMON_DOMAIN', '');
        if ($commonCdn !== '') {
            $base = rtrim($commonCdn, '/');
        } else {
            $base = rtrim($driverBaseDomain, '/');
        }
        if (strpos($base, 'http') !== 0) {
            $ssl = self::readCfg('upload_' . $driver . '_ssl', 'UPLOAD_' . strtoupper($driver) . '_SSL', '1') === '1';
            $base = ($ssl ? 'https://' : 'http://') . ltrim($base, '/');
        }
        $objectKey = ltrim($objectKey, '/');
        return $base . '/' . $objectKey;
    }

    /** 合法的 URL-safe 对象 key（防 ../ 穿越、非法字符、长路径） */
    protected static function buildObjectKey(string $dirPrefix, string $origFilename, string $ext): string
    {
        $rand = bin2hex(random_bytes(9)); // 18 个 hex 字符 + 原文件名前 32 字符，避免冲突
        $nameClean = preg_replace('#[^a-zA-Z0-9._-]#u', '_', pathinfo($origFilename, PATHINFO_FILENAME));
        $nameClean = mb_substr($nameClean ?? 'file', 0, 32);
        $extClean  = preg_replace('#[^a-zA-Z0-9]#', '', $ext);
        return $dirPrefix . $rand . '_' . $nameClean . '.' . strtolower($extClean ?: 'bin');
    }
}
