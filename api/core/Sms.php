<?php
/**
 * 短信服务门面（多厂商）
 *
 * 使用：
 *   if (\Hublog\Core\Sms::send($phone, $code, $scene)) { ...已下发... }
 *   else { /* 未接入服务商，调用方降级为「记服务端日志」联调模式 *\/ }
 *
 * 厂商注册表：key → Provider 实现类。新增厂商只需在此登记并实现
 * Hublog\Core\Sms\ProviderInterface。
 *
 * 密钥统一走 settings（sms_* 前缀），不落库、不进代码。未配置 → send() 返回 false，
 * 由 AuthController / UserController 记录 dev 验证码，方便本地联调，绝不抛错中断业务流程。
 */

namespace Hublog\Core;

use Hublog\Core\Sms\ProviderInterface;

class Sms
{
    public const PROVIDERS = [
        'aliyun'  => \Hublog\Core\Sms\AliyunSmsProvider::class,
        'tencent' => \Hublog\Core\Sms\TencentSmsProvider::class,
        'baidu'   => \Hublog\Core\Sms\BaiduSmsProvider::class,
    ];

    /** 读取短信相关配置（带缓存） */
    public static function config(): array
    {
        $settings = Cache::remember('sms_settings', function () {
            try {
                $rows = Database::fetchAll(
                    "SELECT `key`, `value` FROM settings
                     WHERE `key` IN (
                        'sms_provider',
                        'sms_aliyun_access_key_id','sms_aliyun_access_key_secret','sms_aliyun_sign_name','sms_aliyun_template_code',
                        'sms_tencent_secret_id','sms_tencent_secret_key','sms_tencent_app_id','sms_tencent_sign_name','sms_tencent_template_id','sms_tencent_region',
                        'sms_baidu_ak','sms_baidu_sk','sms_baidu_endpoint','sms_baidu_path','sms_baidu_sign_id','sms_baidu_template_id'
                     )"
                );
            } catch (\Throwable $e) {
                return [];
            }
            $out = [];
            foreach ($rows as $r) {
                $out[$r['key']] = (string)$r['value'];
            }
            return $out;
        }, 600);

        return [
            'provider' => trim((string)($settings['sms_provider'] ?? 'aliyun')),
            // 阿里云
            'aliyun_access_key_id'     => trim((string)($settings['sms_aliyun_access_key_id'] ?? '')),
            'aliyun_access_key_secret' => trim((string)($settings['sms_aliyun_access_key_secret'] ?? '')),
            'aliyun_sign_name'         => trim((string)($settings['sms_aliyun_sign_name'] ?? '')),
            'aliyun_template_code'     => trim((string)($settings['sms_aliyun_template_code'] ?? '')),
            // 腾讯云
            'tencent_secret_id'  => trim((string)($settings['sms_tencent_secret_id'] ?? '')),
            'tencent_secret_key' => trim((string)($settings['sms_tencent_secret_key'] ?? '')),
            'tencent_app_id'     => trim((string)($settings['sms_tencent_app_id'] ?? '')),
            'tencent_sign_name'  => trim((string)($settings['sms_tencent_sign_name'] ?? '')),
            'tencent_template_id'=> trim((string)($settings['sms_tencent_template_id'] ?? '')),
            'tencent_region'     => trim((string)($settings['sms_tencent_region'] ?? '')),
            // 百度云
            'baidu_ak'         => trim((string)($settings['sms_baidu_ak'] ?? '')),
            'baidu_sk'         => trim((string)($settings['sms_baidu_sk'] ?? '')),
            'baidu_endpoint'   => trim((string)($settings['sms_baidu_endpoint'] ?? '')),
            'baidu_path'       => trim((string)($settings['sms_baidu_path'] ?? '')),
            'baidu_sign_id'    => trim((string)($settings['sms_baidu_sign_id'] ?? '')),
            'baidu_template_id'=> trim((string)($settings['sms_baidu_template_id'] ?? '')),
        ];
    }

    /** 后台下拉用：返回全部已登记厂商 [{key,label}] */
    public static function providers(): array
    {
        $list = [];
        foreach (self::PROVIDERS as $key => $class) {
            $list[] = ['key' => $key, 'label' => $class::label()];
        }
        return $list;
    }

    /**
     * 发送短信验证码
     *
     * @return bool 是否发送成功（未配置/异常一律返回 false，由调用方降级）
     */
    public static function send(string $phone, string $code, string $scene = 'login'): bool
    {
        $cfg  = self::config();
        $key  = $cfg['provider'];
        $class = self::PROVIDERS[$key] ?? null;
        if ($class === null || !is_subclass_of($class, ProviderInterface::class)) {
            error_log('[Hublog Sms] 未识别的短信服务商：' . $key); // @allow-error-log
            return false;
        }
        try {
            return (bool)$class::send($phone, $code, $scene, $cfg);
        } catch (\Throwable $e) {
            error_log('[Hublog Sms] provider=' . $key . ' 异常: ' . $e->getMessage()); // @allow-error-log
            return false;
        }
    }
}
