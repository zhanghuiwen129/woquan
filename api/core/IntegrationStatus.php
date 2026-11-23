<?php
/**
 * 外部依赖状态（后台 dashboard 只读卡片）
 *
 * 只回答「是否已配置可用」，每项返回 {key,label,ok,reason}：
 *   - 绝不返回密钥值 / 掩码串 / SDKAppID 等配置值，reason 只拼固定文案与无害计数；
 *   - 复用各域已有的判定方法，不另造读取器、不写库；
 *   - 单项判定异常时降级为 ok=false，不让一张状态卡片拖垮整个 dashboard。
 */

declare(strict_types=1);

namespace Hublog\Core;

class IntegrationStatus
{
    /** 外部依赖状态列表：[{key,label,ok,reason}, ...] */
    public static function checkAll(): array
    {
        return [
            self::trtc(),
            self::realname(),
            self::ai(),
            self::payment(),
            self::moderation(),
        ];
    }

    /**
     * 音视频（TRTC）
     *
     * 注意：trtc_sdk_app_id / trtc_secret_key 不在 AdminSettingsController 的白名单里，
     * 后台没有配置入口。未配置时只能如实告知，不能引导管理员去点一个不存在的设置项。
     */
    private static function trtc(): array
    {
        try {
            $ok = TrtcSig::configured();
        } catch (\Throwable $e) {
            return self::item('trtc', '音视频（TRTC）', false, '状态检测失败，请查看服务端日志。');
        }
        $reason = $ok
            ? 'SDKAppID 与 SecretKey 已配置，音视频通话可用。'
            : '未配置：后台暂无配置入口，需服务端配置或联系管理员。';
        return self::item('trtc', '音视频（TRTC）', $ok, $reason);
    }

    /** 实名认证：key 在后台白名单，可提示管理员去实名设置检查 */
    private static function realname(): array
    {
        try {
            $ok = RealName::available();
        } catch (\Throwable $e) {
            return self::item('realname', '实名认证', false, '状态检测失败，请查看服务端日志。');
        }
        $reason = $ok
            ? '已启用，且所选服务商凭据齐全，实名核验可用。'
            : '未启用或所选服务商凭据不全，请在后台「实名认证」设置中检查。';
        return self::item('realname', '实名认证', $ok, $reason);
    }

    /** AI 能力：无现成 configured()，内联判断；默认全关且依赖 cron */
    private static function ai(): array
    {
        try {
            $commentKey = trim((string)(License::getSetting('ai_comment_api_key', '') ?: ''));
            $auditKey   = trim((string)(License::getSetting('ai_audit_api_key', '') ?: ''));
            $commentOn  = (string)(License::getSetting('ai_comment_enabled', '0')) === '1';
            $auditOn    = (string)(License::getSetting('ai_audit_enabled', '0')) === '1';
        } catch (\Throwable $e) {
            return self::item('ai', 'AI 能力', false, '状态检测失败，请查看服务端日志。');
        }
        $commentReady = $commentOn && $commentKey !== '';
        $auditReady   = $auditOn && $auditKey !== '';
        $hasKey       = $commentKey !== '' || $auditKey !== '';
        $ok           = $commentReady || $auditReady;
        if (!$hasKey) {
            $reason = '未配置 AI 接口密钥，评论增强与内容审核均不可用。';
        } elseif (!$ok) {
            $reason = '已配置密钥但默认关闭：需在后台开启对应开关，并确保 cron 定时任务已部署运行。';
        } else {
            $reason = 'AI 能力已开启（评论增强 / 内容审核依赖 cron 定时执行）。';
        }
        return self::item('ai', 'AI 能力', $ok, $reason);
    }

    /** 在线支付：pay 总开关 + 至少一个渠道开关与凭据齐全 */
    private static function payment(): array
    {
        try {
            $payOn = (string)(License::getSetting('pay_enabled', '0')) === '1';
            $wxOn  = (string)(License::getSetting('pay_wxpay_enabled', '0')) === '1';
            $aliOn = (string)(License::getSetting('pay_alipay_enabled', '0')) === '1';
            $wxReady  = $wxOn && self::allSet([
                'wxpay_appid', 'wxpay_mchid', 'wxpay_serial_no',
                'wxpay_private_key', 'wxpay_api_v3_key',
            ]);
            $aliReady = $aliOn && self::allSet([
                'alipay_appid', 'alipay_private_key', 'alipay_public_key',
            ]);
        } catch (\Throwable $e) {
            return self::item('payment', '在线支付', false, '状态检测失败，请查看服务端日志。');
        }
        $ok = $payOn && ($wxReady || $aliReady);
        if (!$payOn) {
            $reason = '在线充值未开启，请在后台「支付设置」中开启。';
        } elseif (!$wxReady && !$aliReady) {
            $reason = '在线充值已开启，但所选支付渠道的商户信息或密钥不完整。';
        } else {
            $channels = [];
            if ($wxReady) {
                $channels[] = '微信支付';
            }
            if ($aliReady) {
                $channels[] = '支付宝';
            }
            $reason = '在线充值可用（' . implode('、', $channels) . '）。';
        }
        return self::item('payment', '在线支付', $ok, $reason);
    }

    /** 敏感词过滤：词库为空则过滤不生效 */
    private static function moderation(): array
    {
        try {
            $count = ModerationRepository::countSensitiveWords('');
        } catch (\Throwable $e) {
            return self::item('moderation', '敏感词过滤', false, '状态检测失败，请查看服务端日志。');
        }
        $ok = $count > 0;
        $reason = $ok
            ? '敏感词库已加载（' . $count . ' 条），过滤生效。'
            : '词库为空，过滤不生效；请在后台「敏感词」页面添加词条。';
        return self::item('moderation', '敏感词过滤', $ok, $reason);
    }

    /** 一组设置键是否全部非空（只读，不回传值） */
    private static function allSet(array $keys): bool
    {
        foreach ($keys as $key) {
            if (trim((string)(License::getSetting($key, '') ?: '')) === '') {
                return false;
            }
        }
        return true;
    }

    /** 统一出口：严格四字段，杜绝任何配置值外泄 */
    private static function item(string $key, string $label, bool $ok, string $reason): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'reason' => $reason];
    }
}
