<?php
/**
 * 实名认证服务（V51）
 *
 * 设计要点：
 *  1. 合规优先：身份证号属敏感个人信息，库里只落「脱敏值 + 加盐哈希」，不存明文；
 *     哈希仅用于「一个身份证只能认证一个账号」的查重，无法反推原文。
 *  2. 服务商可插拔：当前实现阿里云云市场「身份证二要素核验」（Authorization: APPCODE），
 *     密钥走后台 settings（realname_appcode），未配置时不阻塞注册/使用，仅关闭认证入口。
 *  3. 结果不确定时不误判：服务商返回无法解析或网络异常 → 落 pending，由后台人工复核。
 */

namespace Hublog\Core;

class RealName
{
    /** 认证记录状态 */
    public const STATUS_PENDING  = 'pending';   // 待核验 / 人工复核
    public const STATUS_VERIFIED = 'verified';  // 已通过
    public const STATUS_REJECTED = 'rejected';  // 未通过
    public const STATUS_REVOKED  = 'revoked';   // 已撤销

    /** 默认服务商标识 */
    public const PROVIDER_ALIYUN_MARKET = 'aliyun_market';
    public const PROVIDER_TENCENT_CLOUD = 'tencent_cloud';
    public const PROVIDER_BAIDU_CLOUD   = 'baidu_cloud';

    /** 默认核验接口（阿里云市场·身份证二要素核验，可在后台设置里覆盖） */
    public const DEFAULT_API_URL = 'https://idcardcheck.market.alicloudapi.com/idcardCheck';

    /**
     * 服务商标册表：key → Provider 实现类
     * 新增厂商只需在此登记并实现 RealNameProviderInterface。
     */
    public const PROVIDERS = [
        self::PROVIDER_ALIYUN_MARKET => \Hublog\Core\RealName\AliyunMarketProvider::class,
        self::PROVIDER_TENCENT_CLOUD => \Hublog\Core\RealName\TencentCloudProvider::class,
        self::PROVIDER_BAIDU_CLOUD   => \Hublog\Core\RealName\BaiduCloudProvider::class,
    ];

    /**
     * 各厂商「可用」所需的必填配置键（available() 据此判断）。
     */
    private const PROVIDER_REQUIRED = [
        self::PROVIDER_ALIYUN_MARKET => ['appcode'],
        self::PROVIDER_TENCENT_CLOUD => ['tencent_secret_id', 'tencent_secret_key'],
        self::PROVIDER_BAIDU_CLOUD   => ['baidu_ak', 'baidu_sk'],
    ];

    /**
     * 读取实名相关配置（settings 表，带缓存）
     *
     * @return array 含 enabled/provider + 各厂商凭证键（appcode / api_url / tencent_* 系列 / baidu_* 系列）
     *               注意：注释里别写「星号紧跟斜杠」的组合，会提前闭合块注释（踩过一次）。
     */
    public static function config(): array
    {
        $settings = Cache::remember('realname_settings', function () {
            try {
                $rows = Database::fetchAll(
                    "SELECT `key`, `value` FROM settings
                     WHERE `key` IN (
                        'realname_enabled','realname_provider',
                        'realname_appcode','realname_api_url',
                        'realname_tencent_secret_id','realname_tencent_secret_key',
                        'realname_tencent_endpoint','realname_tencent_action',
                        'realname_tencent_version','realname_tencent_region',
                        'realname_baidu_ak','realname_baidu_sk',
                        'realname_baidu_endpoint','realname_baidu_path'
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
            'enabled'  => ($settings['realname_enabled'] ?? '1') === '1',
            'provider' => trim((string)($settings['realname_provider'] ?? self::PROVIDER_ALIYUN_MARKET)),
            // 阿里云
            'appcode'  => trim((string)($settings['realname_appcode'] ?? '')),
            'api_url'  => trim((string)($settings['realname_api_url'] ?? '')) ?: self::DEFAULT_API_URL,
            // 腾讯云
            'tencent_secret_id'  => trim((string)($settings['realname_tencent_secret_id'] ?? '')),
            'tencent_secret_key' => trim((string)($settings['realname_tencent_secret_key'] ?? '')),
            'tencent_endpoint'   => trim((string)($settings['realname_tencent_endpoint'] ?? '')),
            'tencent_action'     => trim((string)($settings['realname_tencent_action'] ?? '')),
            'tencent_version'    => trim((string)($settings['realname_tencent_version'] ?? '')),
            'tencent_region'     => trim((string)($settings['realname_tencent_region'] ?? '')),
            // 百度云
            'baidu_ak'       => trim((string)($settings['realname_baidu_ak'] ?? '')),
            'baidu_sk'       => trim((string)($settings['realname_baidu_sk'] ?? '')),
            'baidu_endpoint' => trim((string)($settings['realname_baidu_endpoint'] ?? '')),
            'baidu_path'     => trim((string)($settings['realname_baidu_path'] ?? '')),
        ];
    }

    /**
     * 后台下拉用：返回全部已登记厂商 [{key,label}]
     */
    public static function providers(): array
    {
        $list = [];
        foreach (self::PROVIDERS as $key => $class) {
            $list[] = ['key' => $key, 'label' => $class::label()];
        }
        return $list;
    }

    /** 服务商标识 → 展示名（记录里存的是 key，列表/详情展示时转换） */
    public static function providerLabel(string $key): string
    {
        if (isset(self::PROVIDERS[$key])) {
            return self::PROVIDERS[$key]::label();
        }
        return $key ?: '未配置';
    }

    /** 认证功能是否可用（开启 + 所选厂商密钥齐全） */
    public static function available(): bool
    {
        $cfg = self::config();
        if (!$cfg['enabled']) {
            return false;
        }
        $required = self::PROVIDER_REQUIRED[$cfg['provider']] ?? null;
        if ($required === null) {
            return false;
        }
        foreach ($required as $k) {
            if (($cfg[$k] ?? '') === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * 身份证号格式校验：18 位 + 校验位 + 出生日期合法
     */
    public static function checkIdCard(string $idCard): bool
    {
        $id = strtoupper(trim($idCard));
        if (!preg_match('/^\d{17}[\dX]$/', $id)) {
            return false;
        }
        // 出生日期合法性
        $year  = (int)substr($id, 6, 4);
        $month = (int)substr($id, 10, 2);
        $day   = (int)substr($id, 12, 2);
        $now   = (int)date('Y');
        if ($year < 1900 || $year > $now || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return false;
        }
        if (!checkdate($month, $day, $year)) {
            return false;
        }
        // 校验位（ISO 7064:1983 MOD 11-2）
        $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $codes   = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += ((int)$id[$i]) * $weights[$i];
        }
        return $codes[$sum % 11] === $id[17];
    }

    /**
     * 姓名基本要求：2-30 位，仅中英文与间隔号
     */
    public static function checkName(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        $len = mb_strlen($name, 'UTF-8');
        if ($len < 2 || $len > 30) {
            return false;
        }
        return (bool)preg_match('/^[\x{4e00}-\x{9fa5}]{2,30}$/u', $name)
            || (bool)preg_match('/^[a-zA-Z][a-zA-Z\s·.\'-]{1,29}$/u', $name);
    }

    /** 姓名脱敏：张三 → 张*；欧阳娜娜 → 欧**娜 */
    public static function maskName(string $name): string
    {
        $name = trim($name);
        $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $len = count($chars);
        if ($len <= 1) {
            return $name;
        }
        if ($len === 2) {
            return $chars[0] . '*';
        }
        return $chars[0] . str_repeat('*', $len - 2) . $chars[$len - 1];
    }

    /** 身份证脱敏：保留前 1 后 1 */
    public static function maskIdCard(string $idCard): string
    {
        $id = strtoupper(trim($idCard));
        $len = strlen($id);
        if ($len <= 2) {
            return str_repeat('*', max(0, $len));
        }
        return substr($id, 0, 1) . str_repeat('*', $len - 2) . substr($id, -1);
    }

    /**
     * 身份证哈希（加盐，用于查重）
     * 盐取自 JWT_SECRET，部署后不会变；不同站点自然不同
     */
    public static function hashIdCard(string $idCard): string
    {
        $pepper = defined('JWT_SECRET') ? JWT_SECRET : 'hublog-realname';
        return hash('sha256', $pepper . '|realname|' . strtoupper(trim($idCard)));
    }

    /**
     * 调服务商做二要素核验（姓名 + 身份证号）
     *
     * PHPStan 提级后揪出：旧 docblock 承诺一定有 raw 键，但服务商的返回值里没有它
     * （只有本方法自己的早退分支带 raw）。调用方也从未读过 raw。
     * 这里把契约改成「raw 可选」，并让所有早退分支都带上 raw，
     * 保证调用方拿到的结构稳定、不会因缺键报 undefined index。
     *
     * @return array{ok:bool,pending:bool,code:string,message:string,raw?:string}
     *   ok=true  核验通过
     *   pending=true 结果无法确定（网络/解析失败），留待人工复核
     */
    public static function verify(string $realName, string $idCard): array
    {
        $cfg = self::config();
        if (!$cfg['enabled']) {
            return ['ok' => false, 'pending' => false, 'code' => 'disabled', 'message' => '实名认证功能未开启', 'raw' => ''];
        }

        $providerKey = $cfg['provider'];
        $class = self::PROVIDERS[$providerKey] ?? null;
        if ($class === null) {
            // 未知的 provider（比如配置被改坏）→ 落人工复核，不误判
            return ['ok' => false, 'pending' => true, 'code' => 'unknown_provider', 'message' => '未识别的核验服务商，已转人工复核', 'raw' => ''];
        }

        // 密钥未配齐 → 不阻塞用户提交，转人工复核
        if (!self::available()) {
            return ['ok' => false, 'pending' => true, 'code' => 'not_configured', 'message' => '未配置该服务商的核验密钥，已转人工复核', 'raw' => ''];
        }

        try {
            return $class::verify($realName, strtoupper($idCard), $cfg);
        } catch (\Throwable $e) {
            error_log('[Hublog RealName] provider=' . $providerKey . ' 异常: ' . $e->getMessage()); // @allow-error-log
            return ['ok' => false, 'pending' => true, 'code' => 'exception', 'message' => '核验过程异常，已转人工复核', 'raw' => ''];
        }
    }
}
