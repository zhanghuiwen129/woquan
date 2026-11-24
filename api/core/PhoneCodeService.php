<?php
/**
 * Hublog 手机验证码统一签发服务
 *
 * S1 修复（2026-11-12）：/api/auth/phone-code 与 /api/user/phone-code 原本各写一套
 * 签发逻辑，V147「生产失败关闭 + dev_code 仅 DEBUG 回带」与 P1-5「IP 级限流」
 * 只落地在前者；后者仍在生产响应里回带明文 6 位验证码，配合公开的
 * /api/auth/login-phone（按 scene='login' 取码）即可接管任意手机号账号。
 *
 * 现在所有签发路径必须且只能经过 issue()。新增场景、调整频率或改下发渠道
 * 一律改这里，禁止在控制器里复制实现 —— 否则「同一加固只改一处」会复发。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class PhoneCodeService
{
    /** 允许的业务场景（新增场景在此登记） */
    public const SCENES = ['bind', 'login', 'reset', 'set_password'];

    public const TTL = 300;
    public const COOLDOWN = 60;

    private const IP_HOUR_MAX = 20;
    private const IP_DAY_MAX = 60;

    /**
     * 签发一条验证码。
     *
     * @return array{
     *     ok: bool,
     *     status: int,
     *     message: string,
     *     data: array{sent: bool, expires_in: int, dev_code: string|null}|null
     * }
     */
    public static function issue(string $phone, string $scene, string $ip): array
    {
        $fail = static function (string $message, int $status): array {
            return ['ok' => false, 'status' => $status, 'message' => $message, 'data' => null];
        };

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return $fail('手机号格式不正确', 400);
        }
        if (!in_array($scene, self::SCENES, true)) {
            return $fail('验证码场景不正确', 400);
        }

        // P1-5：按 IP 限流，防「轮换手机号绕过 60 秒冷却」的短信轰炸。
        // 两条闸与冷却互为补充：冷却按手机号，限流按来源 IP。
        if (!Security::rateLimit($ip, 'sms_ip_hour', null, self::IP_HOUR_MAX, 3600)) {
            return $fail('该网络环境发送验证码过于频繁，请稍后再试', 429);
        }
        if (!Security::rateLimit($ip, 'sms_ip_day', null, self::IP_DAY_MAX, 86400)) {
            return $fail('今日发送验证码次数已达上限，请明天再试', 429);
        }

        $last = Database::fetchOne(
            "SELECT id, created_at FROM phone_codes WHERE phone = ? AND scene = ? ORDER BY id DESC LIMIT 1",
            [$phone, $scene]
        );
        if ($last) {
            $elapsed = time() - strtotime((string)$last['created_at']);
            if ($elapsed >= 0 && $elapsed < self::COOLDOWN) {
                return $fail("发送过于频繁，请 {$elapsed} 秒后再试", 429);
            }
        }

        $code = (string)random_int(100000, 999999);
        Database::insert('phone_codes', [
            'phone'      => $phone,
            // V102：库里只存带盐 bcrypt 哈希，明文码只经由短信/DEBUG 通道下发。
            // 列宽由 varchar(10) 扩到 varchar(255)（见 migrate102），存量明文在
            // verify() 里按前缀兼容，TTL 5 分钟后自然消失。
            'code'       => password_hash($code, PASSWORD_DEFAULT),
            'scene'      => $scene,
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL),
            'sent_ip'    => $ip,
        ]);

        $sent = false;
        if (class_exists(Sms::class)) {
            try {
                $sent = (bool)Sms::send($phone, $code, $scene);
            } catch (\Throwable $e) {
                error_log('[Hublog SMS] ' . $e->getMessage()); // @allow-error-log
            }
        }

        // V147：未成功下发时生产绝不回带明文验证码，直接失败关闭。
        // 生产日志同样不落明文码（避免日志文件成为第二条泄露通道）；
        // 只有 APP_DEBUG=true 的本地联调才写明文并返回 dev_code。
        if (!$sent) {
            if (self::isDebug()) {
                error_log("[Hublog SMS-DEV] scene={$scene} phone={$phone} code={$code}"); // @allow-error-log
            } else {
                error_log('[Hublog SMS] 下发失败（未配置或服务商不可用）scene=' . $scene // @allow-error-log
                    . ' phone=' . substr($phone, 0, 3) . '****' . substr($phone, -4));
                return $fail('短信服务暂不可用，请稍后再试或联系站长', 503);
            }
        }

        return [
            'ok'      => true,
            'status'  => 200,
            'message' => $sent
                ? '验证码已发送'
                : '验证码已生成（未配置短信服务商，已记录服务端日志）',
            'data'    => [
                'sent'       => $sent,
                'expires_in' => self::TTL,
                'dev_code'   => (!$sent && self::isDebug()) ? $code : null,
            ],
        ];
    }

    /**
     * 统一校验一条验证码 —— 三端（登录 / 设置密码 / 绑定手机）唯一入口。
     *
     * 收敛前，登录端与设置密码端各自内联「checkCodeLock + 带 scene 的 SQL + hash_equals +
     * recordCodeFailure + 核销」，而绑定端既没有 scene 过滤也没有防爆破锁定 ——
     * 结果是为 login 签发的码可以拿去 bind 使用，且 bind 可以无限次猜码。
     * 现在所有校验路径必须且只能经过这里；$scene 由调用方按语义写死，禁止透传客户端参数。
     *
     * 内部一次性完成：锁定检查 → 带 scene 的查询 → 恒定时间比对 →
     * 失败计数（满 5 次锁定 1 小时）→ 原子核销（防并发重放）→ 成功清失败计数。
     *
     * @return array{
     *     ok: bool,
     *     status: int,
     *     message: string,
     *     data: array{id:int}|null
     * }
     */
    public static function verify(string $phone, string $code, string $scene): array
    {
        $fail = static function (string $message, int $status): array {
            return ['ok' => false, 'status' => $status, 'message' => $message, 'data' => null];
        };

        if (!preg_match('/^1[3-9]\d{9}$/', $phone) || strlen($code) !== 6) {
            return $fail('参数不正确', 400);
        }
        if (!in_array($scene, self::SCENES, true)) {
            return $fail('验证码场景不正确', 400);
        }

        // ① 同一「手机号 + 场景」连续失败 5 次锁定 1 小时（期间正确码也拒绝）。
        $lock = Security::checkCodeLock($phone, $scene);
        if ($lock['locked']) {
            return $fail("验证失败次数过多，请 {$lock['retry_after']} 秒后再试", 429);
        }

        // ② scene 过滤是本次收敛的核心：用绑定场景的入口无法消费登录场景签发的码。
        //    时间用绑定参数而非 SQL NOW()，SQLite 行为测试与 MySQL 生产库口径一致。
        $now = Helper::now();
        $row = Database::fetchOne(
            "SELECT id, code FROM phone_codes
             WHERE phone = ? AND scene = ? AND used_at IS NULL AND expires_at > ?
             ORDER BY id DESC LIMIT 1",
            [$phone, $scene, $now]
        );
        if (!$row) {
            return $fail('验证码已失效，请重新获取', 400);
        }

        // ③ 恒定时间比对；失败即计数，满 5 次由 recordCodeFailure 触发锁定。
        //    V102 双模式：新数据是 bcrypt 哈希（$2y$ 前缀，走 password_verify），
        //    存量明文（6 位数字）走 hash_equals 兼容，TTL 到期后自然消失。
        $stored = (string)$row['code'];
        $matched = str_starts_with($stored, '$2y$')
            ? password_verify($code, $stored)
            : hash_equals($stored, $code);
        if (!$matched) {
            $attempts = Security::recordCodeFailure($phone, $scene);
            if ($attempts >= 5) {
                return $fail('验证码错误次数过多，已锁定 1 小时，请稍后再试', 429);
            }
            return $fail('验证码不正确', 400);
        }

        // ④ 原子核销：WHERE 带 used_at IS NULL，并发下只有第一笔能成功，杜绝重放。
        $claimed = Database::getInstance()->prepare(
            "UPDATE phone_codes SET used_at = ? WHERE id = ? AND used_at IS NULL"
        );
        $claimed->execute([$now, (int)$row['id']]);
        if ($claimed->rowCount() === 0) {
            return $fail('验证码已失效，请重新获取', 400);
        }

        // ⑤ 验证成功后清除该场景的失败计数与锁定。
        Security::clearCodeFailures($phone, $scene);

        return [
            'ok'      => true,
            'status'  => 200,
            'message' => '验证通过',
            'data'    => ['id' => (int)$row['id']],
        ];
    }

    private static function isDebug(): bool
    {
        return defined('APP_DEBUG') && APP_DEBUG === true;
    }
}
