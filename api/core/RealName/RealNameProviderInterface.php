<?php
/**
 * 实名认证服务商标识接口
 *
 * 各厂商（阿里云 / 腾讯云 / 百度云 …）实现本接口，向 RealName 门面注册。
 * 门面按 settings.realname_provider 选择实现并委托二要素核验。
 *
 * 统一约定：
 *   - 密钥一律走 settings（不落库、不进代码）
 *   - 任何「无法确定结果」的情况（网络异常 / 响应无法解析 / 未配置密钥）
 *     都返回 pending=true，由上层落「待人工复核」，绝不误判通过或驳回。
 */

namespace Hublog\Core\RealName;

interface RealNameProviderInterface
{
    /** 与 settings.realname_provider 对应的服务商标识 */
    public static function key(): string;

    /** 后台下拉展示名 */
    public static function label(): string;

    /** 该厂商所需的 settings 键（后台表单动态渲染用） */
    public static function configKeys(): array;

    /**
     * 二要素核验（姓名 + 身份证号）
     *
     * @param string $realName 已校验格式的姓名
     * @param string $idCard   已校验格式的身份证号（大写）
     * @param array  $cfg      RealName::config() 全量配置
     * @return array{ok:bool,pending:bool,code:string,message:string}
     */
    public static function verify(string $realName, string $idCard, array $cfg): array;
}
