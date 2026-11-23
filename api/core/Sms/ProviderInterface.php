<?php
/**
 * 短信服务商标识接口
 *
 * 各厂商（阿里云 / 腾讯云 / 百度云 …）实现本接口，向 Sms 门面注册。
 * 门面按 settings.sms_provider 选择实现并调用 send()。
 *
 * 统一约定：
 *   - 密钥一律走 settings（不落库、不进代码）
 *   - 未配置密钥 / 调用失败 → 返回 false（调用方降级为「仅记录服务端日志」的联调模式）
 *   - 短信内容固定为「验证码」场景，模板变量仅一个 {code}
 */

namespace Hublog\Core\Sms;

interface ProviderInterface
{
    /** 与 settings.sms_provider 对应的服务商标识 */
    public static function key(): string;

    /** 后台下拉展示名 */
    public static function label(): string;

    /** 该厂商所需的 settings 键（后台表单动态渲染用） */
    public static function configKeys(): array;

    /**
     * 发送短信验证码
     *
     * @param string $phone  已校验格式的手机号
     * @param string $code   6 位验证码
     * @param string $scene  login|bind|reset
     * @param array  $cfg    Sms::config() 全量配置
     * @return bool 是否发送成功
     */
    public static function send(string $phone, string $code, string $scene, array $cfg): bool;
}
