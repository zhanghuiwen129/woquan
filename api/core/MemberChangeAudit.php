<?php
/**
 * Hublog 会员变更审计服务（V103）
 *
 * 【为什么需要】现有流水表（wallet_logs / points_logs）只记「金额/积分变了多少」，
 * 不记「谁、把什么字段、从什么值改成什么值」。后台赠送 VIP、调整余额/积分、
 * 改角色、VIP 到期降级，都缺一条可追溯的操作证据链。admin_audit_logs 表虽通用，
 * 但当前唯一写入 helper 在私信域，且没有 before/after 语义，不宜混用。
 *
 * 【设计红线】
 *   - 容错写入：表缺失或写入异常一律静默（只记日志），绝不影响到账/改密主流程。
 *   - 只记关键变更：VIP 开通/降级、积分/余额后台调整、角色变更；不记签到/任务等高频流水。
 *   - before/after 以字符串存储，兼容数值 / 日期 / 枚举，避免类型强制。
 *
 * 【用法】recorder 只接受标量值，调用方负责把数组/对象序列化成字符串。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class MemberChangeAudit
{
    public const TYPE_VIP_GRANT = 'vip_grant';
    public const TYPE_VIP_EXPIRE = 'vip_expire';
    public const TYPE_VIP_DOWNGRADE = 'vip_downgrade';
    public const TYPE_POINTS_ADJUST = 'points_adjust';
    public const TYPE_BALANCE_ADJUST = 'balance_adjust';
    public const TYPE_ROLE_CHANGE = 'role_change';

    /**
     * 记一条变更审计（容错，绝不影响主流程）。
     *
     * @param int $userId 目标用户
     * @param string $changeType 见 TYPE_* 常量
     * @param scalar|null $before 变更前值
     * @param scalar|null $after 变更后值
     * @param int $operatorId 操作人 ID；0=系统/cron
     * @param string $operatorName 操作人名称快照
     * @param string $remark 备注
     * @param string $ip 来源 IP
     * @param string $bizType 业务类型 vip/wallet/points/admin
     * @param string $bizId 关联业务 ID / 订单号
     */
    public static function record(
        int $userId,
        string $changeType,
        mixed $before,
        mixed $after,
        int $operatorId = 0,
        string $operatorName = '',
        string $remark = '',
        string $ip = '',
        string $bizType = '',
        string $bizId = ''
    ): void {
        try {
            $delta = '';
            if (is_numeric($before) && is_numeric($after)) {
                $delta = (string)round((float)$after - (float)$before, 2);
            }
            Database::insert('member_change_logs', [
                'user_id'      => $userId,
                'change_type'  => mb_substr($changeType, 0, 32),
                'operator_id'  => $operatorId,
                'operator_name'=> mb_substr($operatorName, 0, 50),
                'before_value' => self::scalar($before),
                'after_value'  => self::scalar($after),
                'delta'        => $delta,
                'biz_type'     => mb_substr($bizType, 0, 32),
                'biz_id'       => mb_substr($bizId, 0, 64),
                'remark'       => mb_substr($remark, 0, 255),
                'ip'           => mb_substr($ip, 0, 45),
                'created_at'   => Helper::now(),
            ]);
        } catch (\Throwable $e) {
            // 审计表缺失/写入失败都不能阻断业务主流程
            error_log('[Hublog MemberChangeAudit] ' . $e->getMessage()); // @allow-error-log
        }
    }

    /** 标量转字符串；null 留空，数组/对象 JSON 化 */
    private static function scalar(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_scalar($v)) {
            return mb_substr((string)$v, 0, 255);
        }
        return mb_substr((string)json_encode($v, JSON_UNESCAPED_UNICODE), 0, 255);
    }
}
