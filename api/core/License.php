<?php
/**
 * Hublog 授权系统核心类
 * 功能：授权验证、域名绑定、文件完整性校验、功能权限检查
 *
 * 修改记录（Apache-2.0 要求标注变更）：
 *   本文件由 woquan（我圈）在 Apache-2.0 许可下修改。
 *   变更：将 badges / points / moderation 从付费门禁移除，改为核心免费模块
 *         （同步调整 KNOWN_MODULES / TIER_MODULES / UNAUTH_DENY_MODULES / canUseModule）。
 *   Modified by woquan on 2026-11-13 under Apache License 2.0.
 */

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;

class License
{
    /**
     * 授权统计上报密钥种子（与授权中心 license_config.php::LS_REPORT_KEY_SEED 逐字节一致）。
     *
     * 常量放在授权核心文件内：删除/改名会让 verifyRemote() 的签名调用抛可捕获 Error，
     * 授权校验随之失效——这是「剔除上报的成本高于保留」的刻意设计（PRD Q4）。
     *
     * 【勿挪到 .env / config（安全 B-1）】该 seed 必须与授权中心 LS_REPORT_KEY_SEED 逐字节一致，
     * 且置于核心文件是反剔除耦合的一部分；挪到私有配置会破坏该耦合、可能让 license 校验整体崩塌。
     * 【用途边界 / 路径A】由此派生的上报签名仅作完整性痕迹与统计告警，服务端绝不据此做授权 / 配额判定；
     * seed 公开 + license_code/fingerprint 明文外发意味着签名可被离线重算，不具备身份真实性保证。
     */
    public const REPORT_KEY_SEED = 'd570994d805a04e3f54a746f8e754155e0ec1d8a9e33025d5ff93a67eec3daba';

    /**
     * 默认上报端点：授权中心独立域名。
     * 优先级：settings.license_report_endpoint > license_remote_url > 本常量。
     */
    public const DEFAULT_REPORT_ENDPOINT = 'https://woquan.com.cn/remote_verify.php';

    /** 授权缓存键 */
    private const CACHE_KEY = 'license_status_v1';
    private const CACHE_TTL = 3600; // 1小时

    /**
     * 最近一次本地验签失败的具体原因（仅 verifyLocal 内部设置）
     *
     * 用途：activateLicense 拿到 null 时，能从这里取出具体失败原因抛给前端，
     * 而不是笼统的「授权码无效或格式错误」。每次 verifyLocal 调用前会重置。
     */
    private static string $lastVerifyError = '';

    /**
     * 读取最近一次本地验签失败的具体原因（空字符串表示无记录或上次成功）
     */
    public static function getLastVerifyError(): string
    {
        return self::$lastVerifyError;
    }

    /**
     * 官方吊销清单缓存键
     * 离线验签模式下远程验签根本走不到，客户端靠这份清单知道「某授权码已被官方撤销」
     */
    private const REVOKED_CACHE_KEY = 'license_revoked_v1';
    private const REVOKED_CACHE_TTL = 86400; // 缓存一天，由后台手动/保存设置时触发刷新

    /** 需要进行完整性校验的核心文件（相对路径） */
    private const INTEGRITY_FILES = [
        'api/index.php',
        'api/core/Auth.php',
        'api/core/Router.php',
        'api/core/Database.php',
        'api/core/License.php',
        'config.php',
        'index.php',
    ];

    /**
     * 获取所有系统设置（带缓存）
     */
    private static function getSettings(): array
    {
        return Cache::remember('site_settings', function () {
            $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);
    }

    /**
     * 获取单个设置项
     *
     * 注意：必须是 public。ChatController / AdminController / UploadProvider
     * 都在类外部调用本方法读取后台配置项；此前声明为 private 会导致
     * "Call to undefined method" 致命错误，被入口的 catch(Throwable) 兜住后
     * 对外表现为 HTTP 400，且错误信息被吞掉，极难排查（/chat/send 全量 400 即由此引起）。
     */
    public static function getSetting(string $key, $default = null)
    {
        $settings = self::getSettings();
        return $settings[$key] ?? $default;
    }

    /**
     * 授权系统是否启用
     */
    public static function isEnabled(): bool
    {
        return self::getSetting('license_enabled', '1') === '1';
    }

    /**
     * 获取当前授权状态（完整信息）
     *
     * 返回格式：
     * [
     *   'enabled' => true/false,          // 授权系统是否启用
     *   'authorized' => true/false,       // 是否已授权
     *   'type' => 'unauthorized/trial/personal/business/enterprise',
     *   'license_key' => '当前授权码',
     *   'expires_at' => '过期时间或NULL',
     *   'expired' => true/false,          // 是否已过期
     *   'domain' => '绑定域名或NULL',
     *   'domain_ok' => true/false,        // 域名是否匹配
     *   'max_users' => int/null,          // 最大用户数
     *   'max_posts' => int/null,          // 最大动态数
     *   'features' => [...],              // 可用功能列表
     *   'integrity_ok' => true/false,     // 文件完整性是否通过
     *   'integrity_issues' => [...],      // 完整性问题文件列表
     *   'customer_name' => '客户名称',
     *   'customer_email' => '客户邮箱',
     * ]
     */
    public static function getStatus(bool $force = false): array
    {
        if (!self::isEnabled()) {
            return [
                'enabled' => false,
                'authorized' => true,
                'type' => 'disabled',
                'license_key' => '',
                'expires_at' => null,
                'expired' => false,
                'domain' => null,
                'domain_ok' => true,
                'max_users' => null,
                'max_posts' => null,
                'features' => ['*'],
                'integrity_ok' => true,
                'integrity_issues' => [],
                'customer_name' => null,
                'customer_email' => null,
            ];
        }

        if (!$force) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $status = self::computeStatus();
        Cache::set(self::CACHE_KEY, $status, self::CACHE_TTL);

        return $status;
    }

    /**
     * 清除授权状态缓存
     */
    public static function clearCache(): void
    {
        Cache::delete(self::CACHE_KEY);
    }

    /**
     * 计算授权状态（不使用缓存）
     */
    private static function computeStatus(): array
    {
        $settings = self::getSettings();
        $licenseKey = trim((string)($settings['license_key'] ?? ''));
        $verifyMode = $settings['license_verify_mode'] ?? 'local';

        $authorized = false;
        $type = 'unauthorized';
        $expiresAt = null;
        $domain = null;
        $maxUsers = null;
        $maxPosts = null;
        $modules = [];
        $customerName = null;
        $customerEmail = null;
        $remoteRequested = false;     // 是否走了远程模式（有URL+key）
        $remoteSucceeded = false;     // 远程服务器是否成功返回了合法响应（即使 valid=false 也算成功连接）
        $revokedReason = '';          // 签名合法但被官方吊销时的原因

        if ($licenseKey !== '') {
            if ($verifyMode === 'remote' && !empty($settings['license_remote_url'])) {
                // 远程验证模式
                $remoteRequested = true;
                $remoteResult = self::verifyRemote($licenseKey, $settings['license_remote_url']);
                // 续期不换码：服务端告知该码已被续期新码替换（reason=SUPERSEDED + renewed_key）时，
                // 先用新码验一次，通过了才落库替换——客户零操作，旧码死亡与新码生效在同一请求内完成。
                if (is_array($remoteResult) && empty($remoteResult['valid'])
                    && ($remoteResult['reason'] ?? '') === 'SUPERSEDED'
                    && !empty($remoteResult['renewed_key'])) {
                    $newKey  = (string)$remoteResult['renewed_key'];
                    $retry   = self::verifyRemote($newKey, $settings['license_remote_url']);
                    if (is_array($retry) && !empty($retry['valid']) && self::saveLicenseKeySetting($newKey)) {
                        $licenseKey   = $newKey;   // 状态里展示的也应是新码
                        $remoteResult = $retry;
                        Logger::info('[Hublog License] 授权码已续期，客户端自动换用新授权码（renewed_key）');
                    }
                }
                if ($remoteResult && ($remoteResult['valid'] ?? false)) {
                    $remoteSucceeded = true;
                    $authorized = true;
                    $type = $remoteResult['type'] ?? 'personal';
                    $expiresAt = $remoteResult['expires_at'] ?? null;
                    $domain = $remoteResult['domain'] ?? null;
                    $maxUsers = $remoteResult['max_users'] ?? null;
                    $maxPosts = $remoteResult['max_posts'] ?? null;
                    $modules = $remoteResult['modules'] ?? $remoteResult['features'] ?? ['*'];
                    $customerName = $remoteResult['customer_name'] ?? null;
                    $customerEmail = $remoteResult['customer_email'] ?? null;
                } elseif (is_array($remoteResult)) {
                    // 服务器返回了 JSON 但 valid=false
                    $remoteSucceeded = true;
                    $invalidReason = (string)($remoteResult['reason'] ?? '');
                    // 归一化到现有 type 枚举（供状态卡片显示）
                    switch ($invalidReason) {
                        case 'REVOKED':  $type = 'revoked'; break;
                        case 'INACTIVE': $type = 'inactive'; break;
                        case 'EXPIRED':  $type = 'expired'; break;
                        case 'DOMAIN_MISMATCH': $type = 'domain_mismatch'; break;
                        case 'FINGERPRINT_MISMATCH': $type = 'integrity_failed'; break;
                        // 续期替换后旧码失效：若上方自动换码没成功（新码也失效/网络失败），
                        // 状态显示为 revoked，横幅提示联系管理员获取新授权码
                        case 'SUPERSEDED': $type = 'revoked'; break;
                    }
                }
            } else {
                // 本地离线验证模式
                $localResult = self::verifyLocal($licenseKey);
                if ($localResult) {
                    if (is_array($localResult['revoked'] ?? null)) {
                        // 签名没问题，但官方已把这张码列入吊销清单 → 判无效
                        $authorized = false;
                        $type = 'revoked';
                        $revokedReason = (string)($localResult['revoked']['reason'] ?? '');
                    } else {
                        $authorized = true;
                        $type = $localResult['type'];
                        $expiresAt = $localResult['expires_at'];
                        $domain = $localResult['domain'];
                        $maxUsers = $localResult['max_users'];
                        $maxPosts = $localResult['max_posts'];
                        $modules = $localResult['modules'] ?? $localResult['features'] ?? ['*'];
                        $customerName = $localResult['customer_name'] ?? null;
                        $customerEmail = $localResult['customer_email'] ?? null;
                    }
                }
            }
        }

        // modules 单一来源强制 + 档位兜底裁剪（本地/远程两条验签路径汇合后统一处理）：
        //   1. 旧功能名经 LEGACY_FEATURE_TO_MODULE 映射、白名单外名字丢弃（KNOWN_MODULES）；
        //   2. 按档位裁剪到 TIER_MODULES[type]（trial/personal 限功能，business/enterprise 全开），
        //      签发端配超也按档位执行。
        //
        // 【为什么裁剪放在汇合后】本地验签与远程验签拿到的 modules 形态不同
        // （本地走 payloadModules() 回退，远程走 remote_verify 的返回体），
        // 但档位裁剪只应有一处生效点，否则两条路径的裁剪规则可能漂移。
        // 放在这里，存量 personal 码（payload 只有 features:['*']）无需重签即可被收窄到 7 项。
        if ($authorized) {
            $modules = self::applyTierModules((string)$type, self::normalizeModules($modules));
        }

        // 域名匹配检查
        $bindDomain = $settings['license_bind_domain'] ?? '';
        if ($authorized && !empty($bindDomain)) {
            $domain = $bindDomain;
        }
        $domainOk = self::checkDomain($domain);

        // 如果域名不匹配，也视为未授权
        if ($authorized && !empty($domain) && !$domainOk) {
            $authorized = false;
            $type = 'domain_mismatch';
        }

        // 过期检查
        $expired = false;
        if ($authorized && $expiresAt !== null) {
            $expireTime = strtotime($expiresAt);
            if ($expireTime !== false && $expireTime < time()) {
                $expired = true;
                $authorized = false;
                $type = 'expired';
            }
        }

        // 文件完整性检查
        $integrityCheck = ($settings['license_integrity_check'] ?? '1') === '1';
        $integrityOk = true;
        $integrityIssues = [];
        if ($integrityCheck) {
            $integrityResult = self::checkIntegrity();
            $integrityOk = $integrityResult['ok'];
            $integrityIssues = $integrityResult['issues'];
            // 非宽容模式且完整性失败时，降级为未授权
            $lenient = ($settings['license_integrity_lenient'] ?? '0') === '1';
            if (!$lenient && !$integrityOk) {
                $authorized = false;
                $type = 'integrity_failed';
            }
        }

        return [
            'enabled' => true,
            'authorized' => $authorized,
            'type' => $type,
            'license_key' => $licenseKey,
            'expires_at' => $expiresAt,
            'expired' => $expired,
            'domain' => $domain,
            'domain_ok' => $domainOk,
            'max_users' => $maxUsers,
            'max_posts' => $maxPosts,
            'modules' => $modules,
            'features' => $modules, // 向后兼容别名（后台 license.js 读取 status.features 渲染已授权功能）
            'integrity_ok' => $integrityOk,
            'integrity_issues' => $integrityIssues,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            // 远程验证附加诊断（不影响逻辑，仅用于后台状态展示）
            'verify_mode' => $verifyMode,
            'remote_requested' => $remoteRequested,
            'remote_succeeded' => $remoteSucceeded,
            // 吊销清单（离线模式下判断「码被官方撤销」的唯一依据）
            'revoked_reason' => $revokedReason,
            'revoked_count' => count(self::getRevokedList()),
            'revoked_synced_at' => (int)(self::getRevokedMeta()['synced_at'] ?? 0),
        ];
    }

    /**
     * 从授权码 payload 里取模块清单（modules 单一来源）
     *
     * 【为什么必须有这个函数】签发端从 2026-11-08 起签出的 payload 只有 modules 字段；
     * 改造前签出的旧码只有 features 字段。两个字段名都要认，否则会出现两种静默故障：
     *   1. 只读 features → 新码取不到模块 → 回退 ['*'] → 签发端勾什么模块都全放行（付费功能白送）；
     *   2. 只读 modules  → 旧码取不到模块 → 同样回退 ['*'] → 旧码变成全功能码。
     * 这里统一「先 modules、再 features、都没有才 ['*']」，并把旧功能名原样交出去，
     * 由 normalizeModules() 负责 legacy 映射（export/stats/plugins → dataops 等）。
     *
     * @param array $payload 已解出的授权码 payload
     * @return array 模块名数组（可能是旧功能名，由调用方归一化）
     */
    private static function payloadModules(array $payload): array
    {
        if (array_key_exists('modules', $payload) && is_array($payload['modules'])) {
            return $payload['modules'];
        }
        if (array_key_exists('features', $payload) && is_array($payload['features'])) {
            return $payload['features'];
        }
        return ['*'];
    }

    /**
     * 本地离线验证授权码
     * 授权码格式：base64(payload_json.signature_hex)
     * payload: {type, expires_at, domain, max_users, max_posts, modules|features, iat, customer_name?, customer_email?}
     */
    public static function verifyLocal(string $licenseKey): ?array
    {
        // 每次进入先重置上次失败原因，避免读到陈旧错误
        self::$lastVerifyError = '';
        try {
            // 容错：用户从 textarea / IM / 邮件复制授权码时可能被插入换行、空格、Tab、零宽字符，
            // base64_decode($s, true) 的严格模式会因这些字符直接返回 false，让本来合法的授权码
            // 被误判成「格式错误」。这里先把所有空白字符剔掉再交给 base64_decode。
            // 注意：合法的授权码 = base64_encode(payloadB64 . '.' . sigHex)，
            // 里面只会出现 [A-Za-z0-9+/=] 和一个 '.'，没有任何理由保留空白。
            $licenseKey = preg_replace('/\s+/', '', $licenseKey);

            $decoded = base64_decode($licenseKey, true);
            if ($decoded === false) {
                return null;
            }
            $parts = explode('.', $decoded, 2);
            if (count($parts) !== 2) {
                return null;
            }
            [$payloadB64, $signature] = $parts;
            $payloadJson = base64_decode($payloadB64, true);
            if ($payloadJson === false) {
                return null;
            }
            $payload = json_decode($payloadJson, true);
            if (!is_array($payload)) {
                return null;
            }

            // 获取公钥
            $publicKeyPem = self::getSetting('license_public_key', '');
            if (empty($publicKeyPem)) {
                return null;
            }
            // 容错：PEM 里换行被压成空格、或被截断成「-----BEGIN PUBLIC KEY-----xxxxxx...xxxxxx-----END PUBLIC KEY-----」
            // 之前后台回填截断值再保存会把公钥永久损坏。这里检测到 '...' 截断标记直接判失败，
            // 让上层给出明确错误信息，引导用户重新粘贴完整公钥。
            if (str_contains($publicKeyPem, '...')) {
                self::$lastVerifyError = '后台 RSA 公钥疑似被截断（包含 "..." 占位符），请到「授权管理 → 验签配置」重新粘贴完整 PEM 公钥后保存';
                return null;
            }
            $publicKey = openssl_pkey_get_public($publicKeyPem);
            if ($publicKey === false) {
                self::$lastVerifyError = '后台 RSA 公钥无法加载（PEM 格式错误或已损坏），请到「授权管理 → 验签配置」重新粘贴完整 PEM 公钥后保存';
                return null;
            }

            // RSA-SHA256 验签
            $signatureBin = hex2bin($signature);
            if ($signatureBin === false) {
                return null;
            }
            $verifyResult = openssl_verify($payloadB64, $signatureBin, $publicKey, OPENSSL_ALGO_SHA256);
            openssl_free_key($publicKey);
            if ($verifyResult !== 1) {
                self::$lastVerifyError = '授权码签名校验失败：可能授权码与后台公钥不匹配，或授权码已被篡改';
                return null;
            }

            // 离线吊销兜底：签名合法但官方已撤销该授权码时，直接作废（离线模式本来查不到远程吊销）
            $revokedInfo = self::isRevoked($payload['jti'] ?? null);

            // 有效期检查
            if (!empty($payload['expires_at'])) {
                $expireTime = strtotime($payload['expires_at']);
                if ($expireTime !== false && $expireTime < time()) {
                    // 已过期，标记为expired类型（由外层处理）
                    return [
                        'type' => $payload['type'] ?? 'personal',
                        'expires_at' => $payload['expires_at'],
                        'domain' => $payload['domain'] ?? null,
                        'max_users' => $payload['max_users'] ?? null,
                        'max_posts' => $payload['max_posts'] ?? null,
                        'modules' => self::payloadModules($payload),
                        'features' => self::payloadModules($payload),
                        'customer_name' => $payload['customer_name'] ?? null,
                        'customer_email' => $payload['customer_email'] ?? null,
                    ];
                }
            }

            return [
                'type' => $payload['type'] ?? 'personal',
                'expires_at' => $payload['expires_at'] ?? null,
                'domain' => $payload['domain'] ?? null,
                'max_users' => $payload['max_users'] ?? null,
                'max_posts' => $payload['max_posts'] ?? null,
                'modules' => self::payloadModules($payload),
                'features' => self::payloadModules($payload),
                'customer_name' => $payload['customer_name'] ?? null,
                'customer_email' => $payload['customer_email'] ?? null,
                'jti' => $payload['jti'] ?? null,
                'revoked' => $revokedInfo,
            ];
        } catch (\Throwable $e) {
            Logger::error('[Hublog License verifyLocal] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 同步官方吊销清单（离线验签模式下的吊销兜底）
     *
     * 离线模式不会真的去问授权服务器「你这码还在不在」，所以服务器把已吊销/停用的
     * jti 签好名放进公开清单，客户端拉回来本地比对即可。
     *
     * 失败时**不覆盖**旧缓存：拉不到就按清单缺失处理（fail-open），
     * 不因为一次网络抖动就把客户的有效授权判死。
     *
     * @return array{ok:bool,count:int,synced_at:int,reason:string}
     */
    public static function syncRevokedList(): array
    {
        $result = ['ok' => false, 'count' => 0, 'synced_at' => 0, 'reason' => ''];

        $remoteUrl = trim((string)self::getSetting('license_remote_url', ''));
        if ($remoteUrl === '') {
            $result['reason'] = '尚未配置远程授权服务器地址';
            return $result;
        }
        if (!function_exists('curl_init')) {
            $result['reason'] = 'PHP 未启用 curl 扩展';
            return $result;
        }

        $timeoutSec   = (int)self::getSetting('license_remote_timeout', '8');
        $sslVerify    = self::getSetting('license_remote_ssl_verify', '1') === '1';
        $remotePubKey = trim((string)self::getSetting('license_remote_pubkey', ''));

        try {
            $url = rtrim($remoteUrl, '/') . '/revoked.php';

            $ssrfChk = Security::validateOutboundUrl($url, ['allowHttp' => !$sslVerify]);
            if (!$ssrfChk['ok']) {
                $result['reason'] = '目标地址不合法：' . (string)($ssrfChk['reason'] ?? 'unknown');
                return $result;
            }

            $ch = curl_init($url);
            Security::applyCurlSsrfOptions($ch, !$sslVerify);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => max(3, $timeoutSec),
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
                CURLOPT_USERAGENT      => 'Hublog-License-Verifier/1.0',
                CURLOPT_FAILONERROR    => false,
            ]);
            $resp     = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false || $httpCode !== 200) {
                $result['reason'] = '请求失败，http 状态码=' . $httpCode;
                return $result;
            }

            $body = json_decode((string)$resp, true);
            if (!is_array($body) || !is_array($body['data'] ?? null)) {
                $result['reason'] = '响应格式错误';
                return $result;
            }
            if ((int)($body['code'] ?? 0) !== 200) {
                $result['reason'] = '接口返回码异常：' . (string)($body['message'] ?? '');
                return $result;
            }

            // 配了远程公钥就必须验签，避免清单被中间人篡改后误杀客户授权
            $signature = (string)($body['signature'] ?? '');
            if ($remotePubKey !== '' && $signature !== '') {
                $sigBin = @hex2bin($signature);
                $pubKey = $signature !== '' ? openssl_pkey_get_public($remotePubKey) : false;
                if ($pubKey !== false && $sigBin !== false) {
                    $ok = openssl_verify(self::canonicalJson($body['data']), $sigBin, $pubKey, OPENSSL_ALGO_SHA256);
                    openssl_free_key($pubKey);
                    if ($ok !== 1) {
                        $result['reason'] = '清单签名校验失败，已丢弃本次结果';
                        return $result;
                    }
                }
            }

            $map = [];
            foreach (['revoked', 'inactive'] as $section) {
                foreach ((array)($body['data'][$section] ?? []) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $jti = (string)($item['jti'] ?? '');
                    if ($jti === '') {
                        continue; // 老授权码没有 jti，离线吊销不了，只能走远程验签
                    }
                    $map[$jti] = [
                        'reason' => (string)($item['reason'] ?? ($section === 'revoked' ? '已吊销' : '已停用')),
                        'at'     => (string)($item['at'] ?? ''),
                    ];
                }
            }

            Cache::set(self::REVOKED_CACHE_KEY, ['synced_at' => time(), 'items' => $map], self::REVOKED_CACHE_TTL);
            $result = ['ok' => true, 'count' => count($map), 'synced_at' => time(), 'reason' => ''];
            return $result;
        } catch (\Throwable $e) {
            Logger::error('[Hublog License syncRevokedList] ' . $e->getMessage());
            $result['reason'] = $e->getMessage();
            return $result;
        }
    }

    /**
     * 已缓存的官方吊销清单（jti => ['reason'=>..,'at'=>..]）
     */
    public static function getRevokedList(): array
    {
        $cached = Cache::get(self::REVOKED_CACHE_KEY);
        if (!is_array($cached)) {
            return [];
        }
        return is_array($cached['items'] ?? null) ? $cached['items'] : [];
    }

    /**
     * 吊销清单的同步元信息：synced_at（同步时间）、count（条目数）
     */
    public static function getRevokedMeta(): array
    {
        $cached = Cache::get(self::REVOKED_CACHE_KEY);
        return is_array($cached) ? $cached : [];
    }

    /**
     * 判断某个授权码（jti）是否被官方吊销
     *
     * @param mixed $jti
     * @return null|array{reason:string,at:string} 命中返回吊销信息，未命中返回 null
     */
    public static function isRevoked($jti): ?array
    {
        if ($jti === null || $jti === '') {
            return null;
        }
        $list = self::getRevokedList();
        return isset($list[(string)$jti]) && is_array($list[(string)$jti]) ? $list[(string)$jti] : null;
    }

    /**
     * 远程授权验证（简单实现）
     */
    /**
     * 远程授权验证失败原因码
     */
    public const REMOTE_ERR_NETWORK = 1;       // 网络/HTTP 错误
    public const REMOTE_ERR_HTTP = 2;          // HTTP 状态码非 200
    public const REMOTE_ERR_DECODE = 3;        // 响应非 JSON 或结构错误
    public const REMOTE_ERR_API_CODE = 4;      // 接口返回 code !== 200
    public const REMOTE_ERR_SIG_MISSING = 5;   // 响应缺少签名
    public const REMOTE_ERR_SIG_INVALID = 6;   // 响应签名无效
    public const REMOTE_ERR_PUBKEY_MISSING = 7;// 未配置远程公钥
    public const REMOTE_ERR_TIMEOUT = 8;       // 请求超时

    private static function verifyRemote(string $licenseKey, string $remoteUrl): ?array
    {
        if (!function_exists('curl_init')) {
            self::logRemoteError(self::REMOTE_ERR_NETWORK, 'curl extension not loaded');
            return null;
        }

        $timeoutSec = (int)self::getSetting('license_remote_timeout', '8');
        $sslVerify  = self::getSetting('license_remote_ssl_verify', '1') === '1';
        $remotePubKey = trim((string)self::getSetting('license_remote_pubkey', ''));

        try {
            // ========== SSRF 防护 ==========
            // 授权服务器一般是公网地址；不允许指向内网、回环、元数据、危险端口
            // 管理员若有本地测试需求，可把 127.0.0.1 加到 allowedHosts 里通过 settings 单独放开（这里不开放）
            $ssrfChk = Security::validateOutboundUrl($remoteUrl, [
                'allowHttp' => !$sslVerify,  // 关掉 SSL 校验时视为开发/内网环境，允许 HTTP
            ]);
            if (!$ssrfChk['ok']) {
                self::logRemoteError(self::REMOTE_ERR_NETWORK, 'ssrf blocked: ' . ($ssrfChk['reason'] ?? 'unknown'));
                return null;
            }

            // ========== 1. 构造请求参数（含硬件指纹 + 时间戳防重放） ==========
            $currentDomain = self::getCurrentDomain();
            $fingerprint   = self::getHardwareFingerprint();
            $timestamp     = time();
            $nonce         = bin2hex(random_bytes(8));

            $postPayload = [
                'license_key' => $licenseKey,
                'domain'      => $currentDomain,
                'fingerprint' => $fingerprint,
                'version'     => defined('APP_VERSION') ? APP_VERSION : 'v2.0',
                'timestamp'   => $timestamp,
                'nonce'       => $nonce,
                // 附加资源使用情况（服务端可做超额预警）
                'stats'       => self::getQuickStats(),
            ];

            // ===== 授权统计上报交织点（删除签名器会让本方法抛错，授权校验随之失效）=====
            // 仅追加两个键与两个头，不发送 X-Site-Report，故服务端仍走原 license 验签路径。
            $signFields = [
                'installation_id' => InstallationIdentity::getOrCreateId(),
                'fingerprint'     => $fingerprint,
                'license_code'    => $licenseKey,
                'site_host'       => InstallationIdentity::getSiteHost(),
                'version'         => (string)$postPayload['version'],
                'users'           => (int)($postPayload['stats']['users'] ?? 0),
                'posts'           => (int)($postPayload['stats']['posts'] ?? 0),
                'timestamp'       => $timestamp,
                'nonce'           => $nonce,
            ];
            $postPayload['installation_id'] = $signFields['installation_id'];
            $postPayload['signature']       = PayloadSigner::sign($signFields, $licenseKey, $fingerprint);

            $postData = json_encode($postPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ch = curl_init($remoteUrl);
            Security::applyCurlSsrfOptions($ch, !$sslVerify);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => max(3, $timeoutSec),
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Hublog-Fingerprint: ' . $fingerprint,
                    // 交织信号：服务端据此识别上报能力；无 X-Site-Report 时仍走原 license 验签路径
                    'X-Site-Installation: ' . $signFields['installation_id'],
                    'X-Site-Signature: ' . $postPayload['signature'],
                ],
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
                CURLOPT_USERAGENT      => 'Hublog-License-Verifier/1.0',
                CURLOPT_FAILONERROR    => false,
            ]);

            $resp     = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            // ========== 2. 网络/HTTP 异常 ==========
            if ($resp === false) {
                self::logRemoteError(self::REMOTE_ERR_NETWORK, 'curl error: ' . $curlErr);
                return null;
            }
            if ($httpCode === 0) {
                self::logRemoteError(self::REMOTE_ERR_TIMEOUT, 'timeout or dns failure, http_code=0');
                return null;
            }
            if ($httpCode !== 200) {
                self::logRemoteError(self::REMOTE_ERR_HTTP, 'http_code=' . $httpCode . ', body=' . substr((string)$resp, 0, 500));
                return null;
            }

            $data = json_decode((string)$resp, true);
            if (!is_array($data)) {
                self::logRemoteError(self::REMOTE_ERR_DECODE, 'invalid json, body=' . substr((string)$resp, 0, 500));
                return null;
            }

            // ========== 3. 接口业务 code 检查 ==========
            $apiCode = (int)($data['code'] ?? 0);
            if ($apiCode !== 200) {
                $msg = (string)($data['message'] ?? 'unknown error');
                // 授权明确无效（不是网络问题）时，写日志，返回 null 让外层视为未授权
                self::logRemoteError(self::REMOTE_ERR_API_CODE, 'api_code=' . $apiCode . ', message=' . $msg);
                // 对「授权码不存在/被吊销/域名不符」这类明确失败，不返回降级数据，直接失效
                return null;
            }

            // ========== 4. 响应签名验证（若配置了远程公钥，则强制验签通过才接受） ==========
            $payloadData = $data['data'] ?? null;
            $signature   = (string)($data['signature'] ?? '');
            $sigVerified = false;   // 响应是否经过 RSA 验签（决定 renewed_key 能否被信任）

            if ($remotePubKey !== '') {
                if ($signature === '') {
                    self::logRemoteError(self::REMOTE_ERR_SIG_MISSING, 'remote pubkey configured but response signature missing');
                    return null;
                }
                if (!is_array($payloadData)) {
                    self::logRemoteError(self::REMOTE_ERR_DECODE, 'signed payload missing');
                    return null;
                }
                // 签名格式：对 data 字段的 JSON 字符串（按 ksort 后重编码）做 RSA-SHA256 签名 hex
                $signingStr = self::canonicalJson($payloadData);
                $pubKey     = openssl_pkey_get_public($remotePubKey);
                if ($pubKey === false) {
                    self::logRemoteError(self::REMOTE_ERR_PUBKEY_MISSING, 'configured remote pubkey invalid');
                    return null;
                }
                $sigBin = @hex2bin($signature);
                if ($sigBin === false) {
                    self::logRemoteError(self::REMOTE_ERR_SIG_INVALID, 'signature not hex');
                    openssl_free_key($pubKey);
                    return null;
                }
                $ok = openssl_verify($signingStr, $sigBin, $pubKey, OPENSSL_ALGO_SHA256);
                openssl_free_key($pubKey);
                if ($ok !== 1) {
                    self::logRemoteError(self::REMOTE_ERR_SIG_INVALID, 'signature verify fail, signing_str_len=' . strlen($signingStr));
                    return null;
                }
                $sigVerified = true;
                // 时间戳窗口：± 5 分钟，防止重放
                $serverTs = (int)($payloadData['timestamp'] ?? 0);
                if ($serverTs > 0 && abs($serverTs - time()) > 300) {
                    self::logRemoteError(self::REMOTE_ERR_SIG_INVALID, 'timestamp skew too large: remote=' . $serverTs . ', local=' . time());
                    return null;
                }
            } else {
                // 未配置远程公钥——警告但兼容老的纯 HTTP 模式（安全 H-4：生产必须配置，否则响应可被伪造）。
                // 不在代码层强制 fail-closed：会让所有未配公钥的存量部署授权校验失败，属部署要求而非代码行为。
                Logger::warning('[Hublog License verifyRemote] SECURITY: license_remote_pubkey not configured — response signature NOT verified (spoofing possible). PRODUCTION MUST configure the public key.');
            }

            if (!is_array($payloadData)) {
                return null;
            }

            // ========== 5. 把结果返回给 computeStatus ==========
            $valid = !empty($payloadData['valid']);
            if (!$valid) {
                // 服务端明确判定无效：不再简单返回 null，把原因码带回去。
                // computeStatus 据此做两件事：
                //   1. 归一化 type（revoked/expired/...）供状态卡片显示；
                //   2. reason=SUPERSEDED（续期替换）时用 renewed_key 自动换码（需响应已验签）。
                self::logRemoteError(self::REMOTE_ERR_API_CODE, 'server returned valid=false, reason=' . ($payloadData['reason'] ?? 'unknown'));
                return [
                    'valid'       => false,
                    'reason'      => (string)($payloadData['reason'] ?? ''),
                    // 未配置远程公钥（响应无验签）时不给 renewed_key，防止中间人诱导换码
                    'renewed_key' => $sigVerified ? (string)($payloadData['renewed_key'] ?? '') : '',
                    'timestamp'   => $payloadData['timestamp'] ?? null,
                ];
            }

            return [
                'valid'          => true,
                'type'           => $payloadData['type']           ?? 'personal',
                'expires_at'     => $payloadData['expires_at']     ?? null,
                'domain'         => $payloadData['domain']         ?? null,
                'max_users'      => $payloadData['max_users']      ?? null,
                'max_posts'      => $payloadData['max_posts']      ?? null,
                'features'       => $payloadData['features']       ?? ['*'],
                'customer_name'  => $payloadData['customer_name']  ?? null,
                'customer_email' => $payloadData['customer_email'] ?? null,
            ];
        } catch (\Throwable $e) {
            Logger::error('[Hublog License verifyRemote] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 硬件指纹（用于远程验证，非严格但足以区分不同服务器）
     * 混合：document_root + DB 前缀 + hostname + 当前 PHP 安装路径 hash
     */
    public static function getHardwareFingerprint(): string
    {
        $parts = [];
        $parts['root'] = __DIR__; // api/core 目录的绝对路径——不同部署肯定不一样
        $parts['host'] = $_SERVER['SERVER_NAME'] ?? (gethostname() ?: 'unknown');
        $parts['db']   = self::getSetting('db_name_marker', '');
        if ($parts['db'] === '') {
            try {
                // 用数据库名 + 首个用户的创建时间作为一个锚点
                $dbName = Database::fetchColumn("SELECT DATABASE()") ?: '';
                $firstTs = Database::fetchColumn("SELECT created_at FROM users ORDER BY id ASC LIMIT 1") ?: '';
                $parts['db'] = $dbName . '|' . $firstTs;
            } catch (\Throwable $e) {
                $parts['db'] = 'n/a';
            }
        }
        $raw = json_encode($parts, JSON_UNESCAPED_UNICODE);
        return hash('sha256', $raw);
    }

    /**
     * 快速获取当前用户/动态数量（附带给远程验证服务器）。
     * F-09：为供 HeartbeatReporter::buildPayload() 复用而由 private 改为 public，
     * 勿在请求热路径高频调用（每次两次 COUNT 查询）。
     */
    public static function getQuickStats(): array
    {
        try {
            return [
                'users' => (int)Database::fetchColumn("SELECT COUNT(*) FROM users WHERE status = 1"),
                'posts' => (int)Database::fetchColumn("SELECT COUNT(*) FROM posts WHERE status = 1"),
            ];
        } catch (\Throwable $e) {
            return ['users' => 0, 'posts' => 0];
        }
    }

    /**
     * 规范化 JSON：对关联数组 ksort 后重新编码，保证签名前后端一致
     */
    public static function canonicalJson(array $arr): string
    {
        ksort($arr);
        foreach ($arr as $k => &$v) {
            if (is_array($v)) {
                $v = self::canonicalJson($v);
                // 子数组被序列化成字符串，需 decode 回来以保持整体是一个 JSON 对象——这里简化：
                // 实际上我们只对顶层（一维）签名即可，所以对嵌套值先转成 JSON 字符串再 hash，避免递归复杂性
            }
        }
        unset($v);
        return json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 远程验证错误日志（写入 settings 表 license_remote_last_error，方便后台直接查看）
     */
    private static function logRemoteError(int $code, string $detail): void
    {
        $msg = date('[Y-m-d H:i:s]') . ' [code=' . $code . '] ' . $detail;
        Logger::error('[Hublog License RemoteErr] ' . $msg);
        try {
            $now = Helper::now();
            $exists = Database::fetchColumn("SELECT COUNT(*) FROM settings WHERE `key` = 'license_remote_last_error'");
            if ($exists) {
                Database::update('settings', ['value' => $msg, 'updated_at' => $now], "`key` = 'license_remote_last_error'");
            } else {
                Database::insert('settings', ['key' => 'license_remote_last_error', 'value' => $msg, 'created_at' => $now, 'updated_at' => $now]);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * 获取当前访问域名（用于域名绑定校验）
     */
    public static function getCurrentDomain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            $host = $_SERVER['SERVER_NAME'] ?? '';
        }
        // 去掉端口
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }
        return strtolower(trim($host));
    }

    /**
     * 检查当前域名是否与授权域名匹配
     * 支持：精确匹配、通配符（*.example.com）、多域名逗号分隔
     */
    public static function checkDomain(?string $bindDomain): bool
    {
        if (empty($bindDomain)) {
            return true; // 未绑定域名，允许任意域名
        }
        $current = self::getCurrentDomain();
        if ($current === '') {
            return true; // 命令行或无法确定域名时放行
        }
        // 本地开发域名直接放行
        $localDomains = ['localhost', '127.0.0.1', '0.0.0.0', ''];
        if (in_array($current, $localDomains, true)) {
            return true;
        }
        $domains = array_map('trim', explode(',', $bindDomain));
        foreach ($domains as $domain) {
            $domain = strtolower($domain);
            if ($domain === '') continue;
            // 通配符匹配 *.example.com
            if (str_starts_with($domain, '*.')) {
                $suffix = substr($domain, 1); // .example.com
                if (str_ends_with($current, $suffix)) {
                    return true;
                }
                // 也匹配根域名 example.com
                if ($current === substr($suffix, 1)) {
                    return true;
                }
            } elseif ($current === $domain) {
                return true;
            }
        }
        return false;
    }

    /**
     * 文件完整性校验（防止核心文件被篡改）
     */
    public static function checkIntegrity(): array
    {
        $issues = [];
        $ok = true;
        $rootDir = __DIR__ . '/../../';

        try {
            $stored = Database::fetchAll("SELECT file_path, file_hash FROM file_hashes");
            $hashMap = [];
            foreach ($stored as $row) {
                $hashMap[$row['file_path']] = $row['file_hash'];
            }

            foreach (self::INTEGRITY_FILES as $file) {
                $absPath = $rootDir . $file;
                if (!file_exists($absPath)) {
                    $ok = false;
                    $issues[] = ['file' => $file, 'issue' => '文件缺失'];
                    continue;
                }
                if (isset($hashMap[$file])) {
                    $actualHash = hash_file('sha256', $absPath);
                    if ($actualHash !== $hashMap[$file]) {
                        $ok = false;
                        $issues[] = ['file' => $file, 'issue' => '文件哈希不匹配（可能被篡改）'];
                    }
                }
                // 数据库中没有记录的文件（新版本或非核心），不报错
            }
        } catch (\Throwable $e) {
            $ok = false;
            $issues[] = ['file' => '*', 'issue' => '完整性检查异常: ' . $e->getMessage()];
        }

        return ['ok' => $ok, 'issues' => $issues];
    }

    /**
     * 刷新核心文件哈希（升级或修改核心文件后，由管理员调用）
     */
    public static function refreshIntegrityHashes(): array
    {
        $rootDir = __DIR__ . '/../../';
        $version = defined('APP_VERSION') ? APP_VERSION : 'v2.0';
        $now = Helper::now();
        $updated = 0;
        $added = 0;

        foreach (self::INTEGRITY_FILES as $file) {
            $absPath = $rootDir . $file;
            if (!file_exists($absPath)) continue;
            $hash = hash_file('sha256', $absPath);
            $exists = Database::fetchColumn(
                "SELECT COUNT(*) FROM file_hashes WHERE file_path = ?",
                [$file]
            );
            if ($exists) {
                Database::update(
                    'file_hashes',
                    ['file_hash' => $hash, 'version' => $version, 'updated_at' => $now],
                    'file_path = ?',
                    [$file]
                );
                $updated++;
            } else {
                Database::insert('file_hashes', [
                    'file_path' => $file,
                    'file_hash' => $hash,
                    'version' => $version,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $added++;
            }
        }
        return ['added' => $added, 'updated' => $updated];
    }

    // ==================== 功能权限检查 ====================

    /**
     * 模块制授权：已登记的可授权模块清单（module 名 => 展示名）。
     *
     * 这是「授权系统能限制什么」的单一事实来源，取代了原先散装、且含幽灵功能
     * （theme 纯前端 CSS，后端无法拦截）的 7 个 feature。模块对应后端真实可拦截的能力，
     * 新增模块必须同步两处（漏一处 = 一边收费一边白送）：
     *   1. shouquan/license_config.php        → LS_ALL_MODULES（签发端表单 + 档位模板）
     *   2. api/middleware/LicenseMiddleware   → MODULE_ROUTES（路由拦截）
     * 本文件的 KNOWN_MODULES / TIER_MODULES 与之保持一致。
     *
     * 注：发布 / 私信 / 会话属基础社交能力，不作模块（永远在试用配额内可用），
     * 因此不在本清单；它们由 checkQuota() 限量，不受模块门禁约束。
     */
    private const KNOWN_MODULES = [
        'ai'         => 'AI 智能辅助',
        'scheduled'  => '定时发布',
        'dataops'    => '数据运维（导出·统计·插件·升级）',
        'circles'    => '圈子',
        'shop'       => '商城',
        'finance'    => '财务会员（钱包·VIP）',
        'activities' => '活动',
        'realname'   => '实名认证',
    ];

    /**
     * 各档位授权码【允许】的模块集合（兜底裁剪，防签约端配错）。
     *
     * 与 shouquan/license_config.php 的 LS_LICENSE_TIERS 保持一致：
     *   trial       → 全部模块。试用期只有 30 天 + 容量上限（20 用户 / 200 动态），
     *                  本就是「限时全功能体验」；若试用版还阉割模块，客户根本体验不到
     *                  圈子/商城/积分等付费能力，30 天后拿不出续费理由。
     *   personal    → 4 项可授权核心能力（ai / 定时发布 / 圈子 / 实名认证）。
     *                  徽章 / 积分 / 内容治理自 2026-11 起改为免费核心能力（见 CORE_FREE_MODULES），
     *                  不再占用 personal 档位额度、也不再出现在 KNOWN_MODULES 中。
     *                  商城 / 财务会员 / 活动 / 数据运维仍为付费模块。
     *   business    → 全部模块（按年授权）。
     *   enterprise  → 全部模块（永久 + 不限量）。
     *
     * 【trial 的 '*' 与 business/enterprise 语义不同】
     *   trial 的 '*' = 限时不限模块，business/enterprise 的 '*' = 永久不限模块。
     *   三者的容量/有效期边界都由授权码 payload 的 max_users/max_posts/expires_at 控制，
     *   与模块维度是否开放是两件独立的事。
     *
     * 未在本表列出的档位（如未来新增的自定义档）原样放行，不做裁剪。
     */
    private const TIER_MODULES = [
        'trial'       => ['*'],
        'personal'    => ['ai', 'scheduled', 'circles', 'realname'],
        'business'    => ['*'],
        'enterprise'  => ['*'],
    ];

    /**
     * 未授权时【系统硬性关闭】的模块，后台不可配置、不可自行勾选开启。
     *
     * 这是授权策略的一部分：谁都能在后台开个「未授权也允许」，授权系统就白做了。
     * 所有付费模块默认都在本表（即未授权一律不可用），唯一例外是 'ai'——
     * AI 自 2026-10-28 起改为「未授权限量试用」（每人每天 license_unauth_daily_ai 次），
     * 见 TRIAL_QUOTA / canUseModule()。
     */
    /**
     * 免费核心能力（永远可用，不受授权码 / 未授权状态约束）。
     *
     * 自 2026-11 起，徽章 / 积分 / 内容治理（敏感词·违规·精选）从付费模块改为免费核心能力：
     * 它们对所有站点（含未授权 / 本地开发实例）默认可用，后台与用户侧入口不再被模块门禁拦截。
     * 因此：
     *   1. 不在 LicenseMiddleware::MODULE_ROUTES 登记路由门禁；
     *   2. 不在 KNOWN_MODULES / TIER_MODULES / UNAUTH_DENY_MODULES 之列（不再可授权 / 不再限档位）；
     *   3. canUseModule() 对它们直接返回 true（见下方分支）。
     */
    private const CORE_FREE_MODULES = ['badges', 'points', 'moderation'];

    private const UNAUTH_DENY_MODULES = ['scheduled', 'dataops', 'circles', 'shop', 'finance', 'activities', 'realname'];

    /**
     * 旧授权码兼容：历史 license payload 用 `features` 字段（原子功能名）。
     * 新授权码用 `modules` 字段（模块名）。本表把旧功能名映射到模块名，
     * 找不到对应模块的（chat 是基础社交、theme 是幽灵功能已废弃）映射为 null 直接丢弃。
     * 这样已签发的旧授权码在新客户端上仍能正确还原出模块集合。
     */
    private const LEGACY_FEATURE_TO_MODULE = [
        'export'    => 'dataops',
        'stats'     => 'dataops',
        'plugins'   => 'dataops',
        'ai'        => 'ai',
        'scheduled' => 'scheduled',
        'chat'      => null,
        'theme'     => null,
    ];

    /**
     * 后台可自行调整的额度项及其【硬上限】。
     *
     * 未授权 = 限量试用，额度需要能调（否则试用体验太死），
     * 但不能让用户自己填个 99999 当成无限——所以这里设一道封顶，
     * trialLimit() / checkResourceLimit() 读值时一律 clamp。
     */
    private const QUOTA_CAP = [
        'license_unauth_daily_posts'        => 50,    // 每人每天发布动态
        'license_unauth_daily_messages'     => 200,   // 每人每天发送私信
        'license_unauth_max_conversations'  => 50,    // 每人可拥有的会话总数
        'license_unauth_max_users'          => 200,   // 全站累计注册用户
        'license_unauth_max_posts'          => 2000,  // 全站累计动态
        'license_unauth_daily_ai'           => 50,    // 每人每天 AI 辅助次数（V145）
    ];

    /**
     * 后台「可编辑的额度项」名单。
     *
     * 【2026-10-03 收敛】默认这些额度后台**不可写**，理由：未授权试用额度属于授权策略，
     * 让安装者自己填数字（哪怕填 50 这种封顶值）也是在自行决定处罚力度，
     * 和「能自开功能」一样属于把授权策略下放给用户。现在统一走系统内置策略。
     *
     * 例外：自用/内测站点确有需要微调时，在 config.php 里
     *     define('LICENSE_TRIAL_CUSTOM', true);
     * 后台才会放开额度输入（仍然 clamp）。这是给官方自己的逃生口，不对外宣传。
     */
    public const EDITABLE_QUOTA_KEYS = [
        'license_unauth_daily_posts',
        'license_unauth_daily_messages',
        'license_unauth_max_conversations',
        'license_unauth_max_users',
        'license_unauth_max_posts',
        'license_unauth_daily_ai',
    ];

    /** 取某项额度的封顶值 */
    public static function quotaCap(string $settingKey): int
    {
        return self::QUOTA_CAP[$settingKey] ?? 0;
    }

    /**
     * 把一个后台传来的额度值夹到合法区间（0..cap）
     *
     * @return array{value:int, clamped:bool}
     */
    public static function clampQuota(string $settingKey, $raw): array
    {
        $cap = self::QUOTA_CAP[$settingKey] ?? 0;
        if ($cap <= 0) {
            return ['value' => 0, 'clamped' => false];
        }
        $val = (int)$raw;
        if ($val < 0) $val = 0;
        if ($val > $cap) {
            return ['value' => $cap, 'clamped' => true];
        }
        return ['value' => $val, 'clamped' => false];
    }

    /** 已登记模块名列表（后台展示/自检用） */
    public static function registeredModules(): array
    {
        return array_keys(self::KNOWN_MODULES);
    }

    /**
     * 未授权「限量试用」配额表：feature => [设置项名, 默认值]
     *
     * 设计原则：未授权 ≠ 不能用，而是限量试用——用户能真正把功能跑通，
     * 用完了再引导授权。直接一刀切禁掉，试用者根本体验不到东西，反而没人愿意授权。
     * 配额填 0 才表示这项功能在未授权时完全关闭。
     * 每日配额按自然日（服务器时区）0 点重置；总量配额不重置。
     */
    private const TRIAL_QUOTA = [
        'post'         => ['license_unauth_daily_posts',        5],   // 每人每天发布动态数
        'chat'         => ['license_unauth_daily_messages',    30],   // 每人每天发送私信数
        'conversation' => ['license_unauth_max_conversations', 10],   // 每人可拥有的会话总数
        // V145：AI 辅助（发布页润色/扩写/续写/精简/换风格）。按「次」计，一次成功生成算一次；
        // 生成失败、参数不合法、被 8 秒频率限制挡下的都不计数（见 AiController::assist）。
        'ai'           => ['license_unauth_daily_ai',           5],   // 每人每天 AI 辅助次数
    ];

    /** 每日重置的配额项（其余为总量配额） */
    private const DAILY_QUOTA = ['post', 'chat', 'ai'];

    /** 取某项试用配额上限（0 = 未授权下不开放） */
    public static function trialLimit(string $feature): int
    {
        $cfg = self::TRIAL_QUOTA[$feature] ?? null;
        if ($cfg === null) {
            return 0;
        }
        $raw = (int)self::getSetting($cfg[0], (string)$cfg[1]);
        // 封顶保护：后台把额度调得再大，也不会超过系统上限
        return min($raw, self::QUOTA_CAP[$cfg[0]] ?? $raw);
    }

    /** 未授权下该功能是否开放试用 */
    public static function isTrialOpen(string $feature): bool
    {
        $status = self::getStatus();
        if (!$status['enabled'] || $status['authorized']) {
            return true;
        }
        return self::trialLimit($feature) > 0;
    }

    /**
     * 规范化授权码里的 modules 列表（单一来源强制）
     *
     * 1. 含 '*' → 全部模块，原样返回；
     * 2. 否则先经 LEGACY_FEATURE_TO_MODULE 把旧功能名映射成模块名，再按 KNOWN_MODULES
     *    白名单过滤，没登记/映射不出的名字一律丢弃
     *    （过滤后可能为空数组 = 一个付费模块都不给，这是 fail-closed 的预期行为）。
     */
    public static function normalizeModules($modules): array
    {
        if (!is_array($modules)) {
            return ['*'];
        }
        if (in_array('*', $modules, true)) {
            return ['*'];
        }
        $out = [];
        foreach ($modules as $raw) {
            $raw = (string)$raw;
            if (isset(self::KNOWN_MODULES[$raw])) {
                $out[] = $raw;
                continue;
            }
            // 兼容历史授权码里的旧功能名
            if (array_key_exists($raw, self::LEGACY_FEATURE_TO_MODULE)) {
                $mapped = self::LEGACY_FEATURE_TO_MODULE[$raw];
                if ($mapped !== null && !in_array($mapped, $out, true)) {
                    $out[] = $mapped;
                }
                continue;
            }
            // 未知名字：丢弃（fail-closed），不静默信任
        }
        return array_values(array_unique($out));
    }

    /**
     * 按授权档位兜底裁剪 modules（防签约端把某档配超）
     *
     * 例：personal 档授权码的 modules 被签成 ['*'] 或含 'shop'，
     * 这里收窄到 TIER_MODULES['personal'] = 7 项核心能力。
     * 未列档位原样返回。
     *
     * 【'*' 语义（2026-11-08 修正）】
     *   档位上限为 '*' → 该档不限模块（trial/business/enterprise），原样返回。
     *   授权码为 '*'   → 收窄到档位上限（personal 码签成 '*' 时只给 7 项）。
     *
     * 【为什么必须先判 allowed 是否含 '*'】
     * 若把 allowed=['*'] 直接丢给 array_intersect，array_intersect(['ai'], ['*']) = []
     *   —— '*' 是字符串不是通配符，交集为空 → trial 码被裁成「零模块」→ 全站功能锁死。
     * 所以两处 '*' 必须分别判断，不能混用 array_intersect。
     */
    public static function applyTierModules(string $type, array $modules): array
    {
        $allowed = self::TIER_MODULES[$type] ?? null;
        if ($allowed === null) {
            return $modules;                       // 未列档位：不裁剪
        }
        if (in_array('*', $allowed, true)) {
            return $modules;                       // 该档不限模块：原样放行
        }
        if (in_array('*', $modules, true)) {
            return $allowed;                       // 码签成全量：收窄到档位上限
        }
        return array_values(array_intersect($modules, $allowed));
    }

    /**
     * 检查试用配额（仅未授权时生效；已授权或关闭授权系统时直接通过）
     *
     * @return array{ok:bool,used:int,limit:int,reset:string,message:string}
     */
    public static function checkQuota(string $feature, int $userId): array
    {
        $status = self::getStatus();
        if (!$status['enabled'] || $status['authorized']) {
            return ['ok' => true, 'used' => 0, 'limit' => 0, 'reset' => '', 'message' => ''];
        }

        $limit = self::trialLimit($feature);
        if ($limit <= 0) {
            return [
                'ok' => false, 'used' => 0, 'limit' => 0, 'reset' => 'none',
                'message' => '试用版未开放该功能，请输入授权码后使用',
            ];
        }

        $used  = self::countQuotaUsage($feature, $userId);
        $daily = in_array($feature, self::DAILY_QUOTA, true);
        $reset = $daily ? 'next_day' : 'never';

        if ($used >= $limit) {
            $name = ['post' => '发布动态', 'chat' => '发送私信', 'conversation' => '发起会话', 'ai' => '使用 AI 辅助'][$feature] ?? $feature;
            return [
                'ok' => false, 'used' => $used, 'limit' => $limit, 'reset' => $reset,
                'message' => $daily
                    ? "试用版每天最多{$name} {$limit} 次，今天已用完（{$used}/{$limit}），明天 0 点自动重置；输入授权码可解锁不限量。"
                    : "试用版最多{$limit} 个会话，当前已达上限（{$used}/{$limit}）；输入授权码可解锁不限量。",
            ];
        }

        return ['ok' => true, 'used' => $used, 'limit' => $limit, 'reset' => $reset, 'message' => ''];
    }

    /** 统计当前用量（每日项只算今天） */
    private static function countQuotaUsage(string $feature, int $userId): int
    {
        try {
            switch ($feature) {
                case 'post':
                    return (int)Database::fetchColumn(
                        "SELECT COUNT(*) FROM posts WHERE user_id = ? AND created_at >= ? AND created_at < ?",
                        [$userId, self::todayStart(), self::tomorrowStart()]
                    );
                case 'chat':
                    return (int)Database::fetchColumn(
                        "SELECT COUNT(*) FROM messages WHERE sender_id = ? AND created_at >= ? AND created_at < ?",
                        [$userId, self::todayStart(), self::tomorrowStart()]
                    );
                case 'conversation':
                    return (int)Database::fetchColumn(
                        "SELECT COUNT(*) FROM conversation_members WHERE user_id = ?",
                        [$userId]
                    );
                case 'ai':
                    // V145：AI 辅助没有天然的落库载体（发帖有 posts、私信有 messages），
                    // 所以单独记一张 ai_usage_logs。
                    // V100：预扣行（reserved）也必须计数，否则 check-then-act 的竞态仍在。
                    return (int)Database::fetchColumn(
                        "SELECT COUNT(*) FROM ai_usage_logs
                         WHERE user_id = ? AND feature = 'ai' AND status IN ('ok','reserved')
                           AND created_at >= ? AND created_at < ?",
                        [$userId, self::todayStart(), self::tomorrowStart()]
                    );
            }
        } catch (\Throwable $e) {
            Logger::error('[Hublog License countQuotaUsage] ' . $e->getMessage());
        }
        return 0;
    }

    /**
     * 记录一次配额消耗（目前只有 AI 需要显式记一笔）
     *
     * 发布/私信不用调它——它们的使用本身就落库（posts / messages），countQuotaUsage 数现成的行。
     * AI 调用不产生任何业务数据，必须在成功后自己写一条，否则配额形同虚设。
     *
     * 只在该功能确实处于「未授权试用」状态时才落库：已授权或授权系统关闭时无需计数。
     */
    public static function recordQuotaUsage(string $feature, int $userId, array $extra = []): void
    {
        if ($userId <= 0 || $feature !== 'ai') {
            return;
        }
        try {
            $status = self::getStatus();
            if (!$status['enabled'] || $status['authorized']) {
                return;
            }
            Database::insert('ai_usage_logs', array_merge([
                'user_id'    => $userId,
                'feature'    => 'ai',
                'created_at' => date('Y-m-d H:i:s'),
            ], $extra));
        } catch (\Throwable $e) {
            // 兼容兜底保留，但这里不再是主扣减路径（主路径已改为 DB 原子预扣）。
            // 吞错只影响「少算一次」，绝不把本次 AI 结果变成失败。
            Logger::error('[Hublog License recordQuotaUsage] ' . $feature . ' : ' . $e->getMessage());
        }
    }

    /**
     * V100：原子预扣一次 AI 配额（check-then-act → DB 条件插入）。
     *
     * 一条 INSERT ... SELECT 在数据库内完成「计数 + 占位」：
     *   - 同用户同一天并发 51 个请求时，只有 50 个能插入成功（rowCount=1）；
     *   - 不能用 Cache::withLock 做预扣依据：锁失败会降级为非原子执行。
     *
     * @return int|null 预扣记录 ID；额度已满或写入失败返回 null
     */
    public static function tryReserveAiQuota(int $userId, string $action): ?int
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $limit = self::trialLimit('ai');
            if ($limit <= 0) {
                return null;
            }
            // MySQL 用 NOW()；SQLite（行为测试）没有该函数，改用绑定当前时间。
            $isSqlite = Database::getInstance()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $nowSql = $isSqlite ? '?' : 'NOW()';
            $params = [$userId, 'ai', $action];
            if ($isSqlite) {
                $params[] = date('Y-m-d H:i:s');
            }
            array_push($params, $userId, self::todayStart(), self::tomorrowStart(), $limit);

            $stmt = Database::query(
                "INSERT INTO ai_usage_logs (`user_id`, `feature`, `action`, `status`, `created_at`)
                 SELECT ?, ?, ?, 'reserved', {$nowSql}
                 WHERE (SELECT COUNT(*) FROM ai_usage_logs
                        WHERE user_id = ? AND feature = 'ai' AND status IN ('ok','reserved')
                          AND created_at >= ? AND created_at < ?) < CAST(? AS SIGNED)",
                $params
            );
            if ($stmt->rowCount() !== 1) {
                return null;
            }
            $id = (int)Database::getInstance()->lastInsertId();
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            // 预扣失败一律 fail-closed：宁可少放行一次，也不能放开无上限调用。
            Logger::error('[Hublog License tryReserveAiQuota] ' . $e->getMessage());
            return null;
        }
    }

    /** 生成成功后把预扣行确认为正式配额记录 */
    public static function confirmQuotaReservation(int $id, string $action): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            Database::query(
                "UPDATE ai_usage_logs SET status = 'ok', action = ? WHERE id = ? AND status = 'reserved'",
                [$action, $id]
            );
        } catch (\Throwable $e) {
            Logger::error('[Hublog License confirmQuotaReservation] ' . $e->getMessage());
        }
    }

    /** 生成失败 / 参数错误时释放预扣，避免把失败调用也算进额度 */
    public static function releaseQuotaReservation(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            Database::query(
                "DELETE FROM ai_usage_logs WHERE id = ? AND status = 'reserved'",
                [$id]
            );
        } catch (\Throwable $e) {
            Logger::error('[Hublog License releaseQuotaReservation] ' . $e->getMessage());
        }
    }

    /**
     * 已授权 / 未授权（试用）模块对照表
     *
     * 面向使用方（站点主及其用户）的说明，口径一律以代码实际执行为准：
     *   - 基础社交（发布/私信/会话）不作模块，永远在试用配额内可用；
     *   - AI 自 V145 起从「付费功能」转为未授权限量试用（每人每天 license_unauth_daily_ai 次）；
     *   - 其余模块（dataops/circles/shop/...）属付费模块，需授权码含对应模块，未授权默认不开放。
     * 后台仅只读展示，不可自行调整（授权策略由授权码决定）。
     */
    public static function moduleMatrix(): array
    {
        $maxUsers   = (int)self::getSetting('license_unauth_max_users', '20');
        $maxPosts   = (int)self::getSetting('license_unauth_max_posts', '100');
        $dailyPosts = self::trialLimit('post');
        $dailyMsgs  = self::trialLimit('chat');
        $maxConv    = self::trialLimit('conversation');
        $dailyAi    = self::trialLimit('ai');

        $rows = [];
        // 基础社交：不作模块，永远可用（试用配额内）
        $rows[] = ['key' => 'social', 'name' => '基础社交（发布·私信·会话）', 'authorized' => '不限量',
                   'trial' => "发布每天 {$dailyPosts} 条 · 私信每天 {$dailyMsgs} 条 · 会话每人 {$maxConv} 个 · 注册累计 {$maxUsers} 用户 / {$maxPosts} 动态", 'daily' => true];
        // 各付费模块
        foreach (self::KNOWN_MODULES as $key => $name) {
            if ($key === 'ai') {
                $rows[] = ['key' => $key, 'name' => $name, 'authorized' => '授权码含 ai（不限量）',
                           'trial' => $dailyAi > 0 ? "每天 {$dailyAi} 次（次日 0 点重置）" : '不开放', 'daily' => true];
            } else {
                $rows[] = ['key' => $key, 'name' => $name, 'authorized' => '授权码含本模块',
                           'trial' => '不开放（付费模块）', 'daily' => false];
            }
        }
        return $rows;
    }

    /**
     * 【兼容别名】旧「功能制」API → 模块制矩阵
     *
     * @deprecated 内部请改用 moduleMatrix()
     */
    public static function featureMatrix(): array
    {
        return self::moduleMatrix();
    }

    /**
     * 系统内置试用额度默认值（后台「未授权策略」只读展示、保存设置时复位用）
     *
     * key => [设置项名, 默认值]。功能开关 license_unauth_allow_* 一并按 0 复位，
     * 因为「未授权能用什么功能」只能由授权策略决定，不能由安装者留痕。
     */
    public static function defaultTrialSettings(): array
    {
        $out = [];
        foreach (self::TRIAL_QUOTA as [$key, $default]) {
            $out[$key] = (string)$default;
        }
        $out['license_unauth_max_users']      = '20';
        $out['license_unauth_max_posts']      = '100';
        // 注：旧的 license_unauth_allow_* 自管开关已移除（授权策略由授权码决定，站点不可自开），
        // 这里不再为它们写复位值；若库里残留这些键，保存设置时会由 resetTrialSettingsToDefault 一并清掉。
        return $out;
    }

    /**
     * 把试用额度 / 功能开关复位为系统内置默认。
     *
     * 后台保存设置时调用：这样即使历史上站点自己填过 99999 之类的脏值，
     * 也会被拉回官方默认，不会出现「后台显示只读、实际额度却是自己填的无限」。
     *
     * @return array{reset:array, changed:int}
     */
    public static function resetTrialSettingsToDefault(): array
    {
        $defaults = self::defaultTrialSettings();
        $now      = date('Y-m-d H:i:s');
        $changed  = 0;
        $reset    = [];

        foreach ($defaults as $key => $default) {
            try {
                if ((string)self::getSetting($key, '') === (string)$default) {
                    continue;
                }
                $exists = Database::fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
                if ($exists) {
                    Database::update('settings', ['value' => $default, 'updated_at' => $now], '`key`=?', [$key]);
                } else {
                    Database::insert('settings', ['key' => $key, 'value' => $default, 'created_at' => $now, 'updated_at' => $now]);
                }
                $reset[] = $key;
                $changed++;
            } catch (\Throwable $e) {
                Logger::error('[Hublog License resetTrialSettings] ' . $key . ' : ' . $e->getMessage());
            }
        }

        if ($changed > 0) {
            self::clearCache();
        }
        return ['reset' => $reset, 'changed' => $changed];
    }

    /**
     * 当前系统用量（后台展示：配额用了多少 / 还剩多少）
     */
    public static function usageSummary(): array
    {
        $out = ['users' => 0, 'posts' => 0, 'today_posts' => 0, 'today_messages' => 0, 'conversations' => 0, 'today_ai' => 0];
        try {
            $out['users']           = (int)Database::fetchColumn("SELECT COUNT(*) FROM users WHERE status = 1");
            $out['posts']           = (int)Database::fetchColumn("SELECT COUNT(*) FROM posts WHERE status = 1");
            $out['today_posts']     = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM posts WHERE created_at >= ? AND created_at < ?",
                [self::todayStart(), self::tomorrowStart()]
            );
            $out['today_messages']  = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM messages WHERE created_at >= ? AND created_at < ?",
                [self::todayStart(), self::tomorrowStart()]
            );
            $out['conversations']   = (int)Database::fetchColumn("SELECT COUNT(*) FROM conversations");
        } catch (\Throwable $e) {
            Logger::error('[Hublog License usageSummary] ' . $e->getMessage());
        }
        // V145：AI 用量单独一段 try —— ai_usage_logs 是 migrate88 才建的，
        // 迁移没跑完的站点不能因为它把整份用量统计带成 0。
        try {
            $out['today_ai'] = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM ai_usage_logs WHERE created_at >= ? AND created_at < ?",
                [self::todayStart(), self::tomorrowStart()]
            );
        } catch (\Throwable $e) {
            Logger::error('[Hublog License usageSummary ai] ' . $e->getMessage());
        }
        return $out;
    }

    private static function todayStart(): string
    {
        return date('Y-m-d 00:00:00');
    }

    private static function tomorrowStart(): string
    {
        return date('Y-m-d 00:00:00', strtotime('+1 day'));
    }

    /**
     * 是否允许使用某模块
     * 免费核心能力（CORE_FREE_MODULES：徽章 / 积分 / 内容治理）：一律放行
     * 已授权状态下：modules 含 '*' 或指定模块名则允许
     * 未授权状态下：仅 'ai' 走限量试用配额，其余付费模块一律由 UNAUTH_DENY_MODULES 判死
     */
    public static function canUseModule(string $module): bool
    {
        $status = self::getStatus();
        if (!$status['enabled']) {
            return true;
        }
        // 免费核心能力：永远可用，不受授权码 / 未授权状态约束
        if (in_array($module, self::CORE_FREE_MODULES, true)) {
            return true;
        }
        if ($status['authorized']) {
            if (in_array('*', $status['modules'], true)) {
                return true;
            }
            return in_array($module, $status['modules'], true);
        }

        // 未授权：AI 走限量试用配额（每人每天 N 次，0 点重置）
        if ($module === 'ai') {
            return self::trialLimit('ai') > 0;
        }
        // 其余付费模块：未授权一律不可用，后台没有开关可以打开
        if (in_array($module, self::UNAUTH_DENY_MODULES, true)) {
            return false;
        }
        // 兜底默认拒绝（未知模块名也不放行）
        return false;
    }

    /**
     * 【兼容别名】旧「功能制」API —— 保留给未同步升级的控制器与客户站点
     *
     * 模块制改造后 canUseFeature() 已由 canUseModule() 取代，但线上/客户站点
     * 仍可能跑着旧版 PostController.php（内部调用 canUseFeature('scheduled')）。
     * 直接删掉会让这些调用点 fatal error（Call to undefined method），
     * 所以保留别名做一次 legacy 名字映射，保证「只升级核心不炸」。
     *
     * @deprecated 内部请改用 canUseModule()
     */
    public static function canUseFeature(string $feature): bool
    {
        if ($feature === '*') {
            return true;
        }
        // 旧功能名 → 模块名；值为 null 表示「已废弃或属基础社交」，不作门禁
        if (array_key_exists($feature, self::LEGACY_FEATURE_TO_MODULE)) {
            $module = self::LEGACY_FEATURE_TO_MODULE[$feature];
            if ($module === null) {
                return true;
            }
            return self::canUseModule($module);
        }
        // 已经是模块名（新代码误用旧 API）
        if (array_key_exists($feature, self::KNOWN_MODULES)) {
            return self::canUseModule($feature);
        }
        // 未知名字：fail-closed，与 canUseModule 口径一致
        return false;
    }

    /**
     * 检查未授权的资源限制（用户数、动态数）
     * 未授权时：超过上限返回 false
     */
    public static function checkResourceLimit(): array
    {
        $status = self::getStatus();
        if (!$status['enabled'] || $status['authorized']) {
            // 已授权：检查授权中的上限
            $maxUsers = $status['max_users'];
            $maxPosts = $status['max_posts'];
            $ok = true;
            $users = 0;
            $posts = 0;
            try {
                $users = (int)Database::fetchColumn("SELECT COUNT(*) FROM users WHERE status = 1");
                $posts = (int)Database::fetchColumn("SELECT COUNT(*) FROM posts WHERE status = 1");
            } catch (\Throwable $e) {
                // ignore
            }
            if ($maxUsers !== null && $users > $maxUsers) $ok = false;
            if ($maxPosts !== null && $posts > $maxPosts) $ok = false;
            return ['ok' => $ok, 'users' => $users, 'max_users' => $maxUsers, 'posts' => $posts, 'max_posts' => $maxPosts];
        }

        // 未授权：从配置取
        // 试用总量上限：放宽到能真正跑起来的量级（原来是 3 用户 / 10 动态，太死）
        $maxUsers = min((int)self::getSetting('license_unauth_max_users', '20'), self::QUOTA_CAP['license_unauth_max_users'] ?? 20);
        $maxPosts = min((int)self::getSetting('license_unauth_max_posts', '100'), self::QUOTA_CAP['license_unauth_max_posts'] ?? 100);
        $users = 0;
        $posts = 0;
        try {
            $users = (int)Database::fetchColumn("SELECT COUNT(*) FROM users WHERE status = 1");
            $posts = (int)Database::fetchColumn("SELECT COUNT(*) FROM posts WHERE status = 1");
        } catch (\Throwable $e) {
            // ignore
        }
        $ok = true;
        if ($maxUsers > 0 && $users > $maxUsers) $ok = false;
        if ($maxPosts > 0 && $posts > $maxPosts) $ok = false;
        return ['ok' => $ok, 'users' => $users, 'max_users' => $maxUsers, 'posts' => $posts, 'max_posts' => $maxPosts];
    }

    /**
     * 检查是否允许注册新用户
     */
    public static function canRegister(): bool
    {
        $limit = self::checkResourceLimit();
        if (!$limit['ok']) {
            return false;
        }
        if ($limit['max_users'] > 0 && $limit['users'] >= $limit['max_users']) {
            return false;
        }
        return true;
    }

    /**
     * 检查是否允许发布动态
     */
    public static function canPost(): bool
    {
        $limit = self::checkResourceLimit();
        if (!$limit['ok']) {
            return false;
        }
        if ($limit['max_posts'] > 0 && $limit['posts'] >= $limit['max_posts']) {
            return false;
        }
        return true;
    }

    /**
     * 仅更新 settings 里的授权码（续期自动换码用）
     *
     * 与 activateLicense 的区别：不写 licenses 历史表、不做本地验签，
     * 就是「换个键」。调用方（computeStatus 的 SUPSERSEDED 分支）必须先验过新码再调它。
     */
    public static function saveLicenseKeySetting(string $licenseKey): bool
    {
        $licenseKey = trim($licenseKey);
        if ($licenseKey === '') {
            return false;
        }
        try {
            $exists = Database::fetchColumn("SELECT COUNT(*) FROM settings WHERE `key` = 'license_key'");
            $now = Helper::now();
            if ($exists) {
                Database::update(
                    'settings',
                    ['value' => $licenseKey, 'updated_at' => $now],
                    "`key` = 'license_key'"
                );
            } else {
                Database::insert('settings', [
                    'key' => 'license_key',
                    'value' => $licenseKey,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            // 清除 license_data 缓存和上次验证时间
            foreach (['license_data', 'license_last_check'] as $k) {
                try {
                    Database::update('settings', ['value' => '', 'updated_at' => $now], "`key` = ?", [$k]);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            Cache::delete('site_settings');
            self::clearCache();
            return true;
        } catch (\Throwable $e) {
            Logger::error('[Hublog License saveLicenseKeySetting] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 激活授权码（保存到数据库并清除缓存）
     */
    public static function activateLicense(string $licenseKey): array
    {
        $licenseKey = trim($licenseKey);

        // 先验证
        $verifyMode = self::getSetting('license_verify_mode', 'local');
        if ($verifyMode === 'remote') {
            // 远端模式不做本地校验，保存后由getStatus走远端验证
        } else {
            $localResult = self::verifyLocal($licenseKey);
            if ($localResult === null) {
                // verifyLocal 在每个失败点已把具体原因写入 self::$lastVerifyError
                // 这里取出抛给前端，让管理员能直接看到是公钥问题还是授权码问题，
                // 而不是笼统的「授权码无效或格式错误」（之前这个笼统提示把公钥损坏、
                // 授权码空白字符、签名不匹配全部混在一起，极难排查）
                $reason = self::getLastVerifyError();
                if ($reason === '') {
                    // 走到这里说明 verifyLocal 在很早的阶段（base64 解码/JSON 解析）就挂了，
                    // 没有触达到设置 lastVerifyError 的代码分支
                    $reason = '授权码格式错误或公钥未配置';
                }
                throw new HublogException($reason, 400);
            }
            // 签名合法但官方已吊销的码不能激活，否则撤销等于白做
            if (is_array($localResult['revoked'] ?? null)) {
                throw new HublogException('该授权码已被官方吊销' . (($localResult['revoked']['reason'] ?? '') !== '' ? '：' . $localResult['revoked']['reason'] : ''), 400);
            }
        }

        // 保存到 settings 表（与续期自动换码共用同一写入路径）
        if (!self::saveLicenseKeySetting($licenseKey)) {
            throw new HublogException('授权码保存失败，请检查 settings 表写入权限', 500);
        }
        $now = Helper::now();

        // 写入 licenses 表（方便后台查看历史记录）
        try {
            $type = 'personal';
            $domain = null;
            $expiresAt = null;
            $customerName = null;
            $customerEmail = null;
            $local = self::verifyLocal($licenseKey);
            if ($local) {
                $type = $local['type'];
                $domain = $local['domain'];
                $expiresAt = $local['expires_at'];
                $customerName = $local['customer_name'];
                $customerEmail = $local['customer_email'];
            }
            $existsRec = Database::fetchColumn(
                "SELECT id FROM licenses WHERE license_key = ? LIMIT 1",
                [$licenseKey]
            );
            if (!$existsRec) {
                Database::insert('licenses', [
                    'license_key' => $licenseKey,
                    'license_type' => $type,
                    'domain' => $domain,
                    'expires_at' => $expiresAt,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'status' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                Database::update('licenses', [
                    'license_type' => $type,
                    'domain' => $domain,
                    'expires_at' => $expiresAt,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'status' => 1,
                    'updated_at' => $now,
                ], 'id = ?', [$existsRec]);
            }
        } catch (\Throwable $e) {
            // 历史记录写入失败不影响激活
            Logger::error('[Hublog License activate] save history failed: ' . $e->getMessage());
        }

        Cache::delete('site_settings');
        self::clearCache();

        return self::getStatus(true);
    }

    /**
     * 移除当前授权码
     */
    public static function deactivateLicense(): void
    {
        $now = Helper::now();
        try {
            Database::update(
                'settings',
                ['value' => '', 'updated_at' => $now],
                "`key` = 'license_key'"
            );
            Database::update(
                'settings',
                ['value' => '', 'updated_at' => $now],
                "`key` = 'license_data'"
            );
        } catch (\Throwable $e) {
            // ignore
        }
        Cache::delete('site_settings');
        self::clearCache();
    }

    /**
     * 获取用于前端展示的授权状态（精简版 + 未授权限制提示）
     */
    public static function getPublicStatus(int $userId = 0): array
    {
        $status = self::getStatus();
        if (!$status['enabled']) {
            return [
                'authorized' => true,
                'type' => 'disabled',
                'show_banner' => false,
                'banner_text' => '',
                'expires_at' => null,
                'customer_name' => null,
            ];
        }
        $showBanner = !$status['authorized'] && self::getSetting('license_unauth_show_banner', '1') === '1';
        $bannerText = '';
        if ($showBanner) {
            switch ($status['type']) {
                case 'domain_mismatch':
                    $bannerText = '当前域名未获得授权，请联系管理员更换授权域名';
                    break;
                case 'expired':
                    $bannerText = '授权已过期，请续费或联系管理员获取新的授权码';
                    break;
                case 'integrity_failed':
                    $bannerText = '检测到核心文件可能被篡改，请联系官方获取正版授权';
                    break;
                default:
                    $bannerText = '您正在使用未授权版本，功能受限。请前往后台输入授权码解锁全部功能。';
            }
        }
        return [
            'authorized' => $status['authorized'],
            'type' => $status['type'],
            'show_banner' => $showBanner,
            'banner_text' => $bannerText,
            'expires_at' => $status['expires_at'],
            'customer_name' => $status['customer_name'],
            // 模块权限（前端展示用）。ai 走「限量试用」：只要配额 > 0 就放行入口，
            // 真正的限制在发送时按每日配额拦，避免未授权连入口都看不到、没法体验。
            'can_use' => [
                'ai'         => self::canUseModule('ai'),
                'scheduled'  => self::canUseModule('scheduled'),
                'dataops'    => self::canUseModule('dataops'),
                'circles'    => self::canUseModule('circles'),
                'shop'       => self::canUseModule('shop'),
                'points'     => self::canUseModule('points'),
                'finance'    => self::canUseModule('finance'),
                'activities' => self::canUseModule('activities'),
                'realname'   => self::canUseModule('realname'),
                'badges'     => self::canUseModule('badges'),
                'moderation' => self::canUseModule('moderation'),
            ],
            // 未授权时把试用配额一并返回，前端可显示「今日还剩 N 条」
            'trial' => $status['authorized'] ? null : [
                'post'         => self::checkQuota('post', $userId),
                'chat'         => self::checkQuota('chat', $userId),
                'conversation' => self::checkQuota('conversation', $userId),
            ],
        ];
    }
}
