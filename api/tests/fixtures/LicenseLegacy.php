<?php
/**
 * 【测试夹具 / 勿改业务逻辑】旧版（功能制）License.php 的原样副本
 *
 * 来源：git commit 3f5f0ea 的 api/core/License.php（模块制改造之前的功能制版本）
 * 用途：LicenseCompatTest 用它跑「旧客户端」的真实逻辑，验证跨版本兼容矩阵。
 *
 * 唯一改动：namespace 由 Hublog\Core 改为 Hublog\Legacy，
 *         以便与新版 Hublog\Core\License 在同一进程中共存（不冲突）。
 * 业务逻辑一行未改 —— 改了就不是「旧客户端真实行为」了，测试就失去意义。
 *
 * ⚠️ 升级客户端版本后，如需更新本夹具，请重新从目标历史提交取出，
 *    并再次确认只改了 namespace 一行。
 */

namespace Hublog\Legacy;

use Hublog\Core\Exception\HublogException;

class License
{
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
        $features = [];
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
                        error_log('[Hublog License] 授权码已续期，客户端自动换用新授权码（renewed_key）');
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
                    $features = $remoteResult['features'] ?? ['*'];
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
                        $features = $localResult['features'];
                        $customerName = $localResult['customer_name'] ?? null;
                        $customerEmail = $localResult['customer_email'] ?? null;
                    }
                }
            }
        }

        // features 单一来源强制 + 档位兜底裁剪（本地/远程两条验签路径汇合后统一处理）：
        //   1. 白名单外功能名丢弃（KNOWN_FEATURES）；
        //   2. trial 档收窄到 TIER_FEATURES['trial']，签发端配超也按档位执行。
        if ($authorized) {
            $features = self::applyTierFeatures((string)$type, self::normalizeFeatures($features));
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
            'features' => $features,
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
     * 本地离线验证授权码
     * 授权码格式：base64(payload_json.signature_hex)
     * payload: {type, expires_at, domain, max_users, max_posts, features, iat, customer_name?, customer_email?}
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
                        'features' => $payload['features'] ?? ['*'],
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
                'features' => $payload['features'] ?? ['*'],
                'customer_name' => $payload['customer_name'] ?? null,
                'customer_email' => $payload['customer_email'] ?? null,
                'jti' => $payload['jti'] ?? null,
                'revoked' => $revokedInfo,
            ];
        } catch (\Throwable $e) {
            error_log('[Hublog License verifyLocal] ' . $e->getMessage());
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
            error_log('[Hublog License syncRevokedList] ' . $e->getMessage());
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
                // 未配置远程公钥——警告但兼容老的纯 HTTP 模式
                error_log('[Hublog License verifyRemote] WARNING: license_remote_pubkey not configured. Response signature NOT verified, spoofing possible!');
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
            error_log('[Hublog License verifyRemote] ' . $e->getMessage());
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
     * 快速获取当前用户/动态数量（附带给远程验证服务器）
     */
    private static function getQuickStats(): array
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
        error_log('[Hublog License RemoteErr] ' . $msg);
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
     * 已登记的功能清单：feature 名 => 未授权时对应的开关设置项
     *
     * 【默认拒绝】此前未登记的功能一律 return true，加上校验只靠中间件路由正则兜底，
     * 漏一条正则就等于整个功能白送。现在改成登记表制：没登记的功能直接拒绝并记日志。
     * 新增收费功能时，必须同时在这里登记 + 在 LicenseMiddleware::FEATURE_ROUTES 配路由。
     */
    private const FEATURE_REGISTRY = [
        // 注：'ai' 虽仍登记在此（后台功能清单展示、默认复位用），但 2026-10-28 起
        // AI 已改为「未授权限量试用」（见 TRIAL_QUOTA），canUseFeature('ai') 走配额分支，
        // 不再读 license_unauth_allow_ai 这个开关。
        'ai'        => 'license_unauth_allow_ai',
        'plugins'   => 'license_unauth_allow_plugins',
        'chat'      => 'license_unauth_allow_chat',
        'stats'     => 'license_unauth_allow_stats',
        'export'    => 'license_unauth_allow_export',
        'theme'     => 'license_unauth_allow_theme',
        'scheduled' => 'license_unauth_allow_scheduled',
    ];

    /**
     * 已登记的功能名白名单（与授权签发系统 shouquan/index.php 的 LS_ALL_FEATURES 一致）。
     *
     * 验签时对授权码里的 features 数组做过滤：白名单外的一律丢弃。
     * 目的：签发端手滑填错功能名（比如把 'export' 写成 'exportt'）时，
     * 客户端不会静默信任一个没登记的名字，也不会因为名字对不上就整体放行。
     *
     * 【同步要求】新增收费功能时三处必须同时改，漏一处 = 一边收费一边白送：
     *   1. shouquan/index.php                    → LS_ALL_FEATURES（表单勾选 + 档位模板）
     *   2. api/middleware/LicenseMiddleware.php  → FEATURE_ROUTES（路由拦截）
     *   3. 本文件的 FEATURE_REGISTRY / KNOWN_FEATURES
     */
    private const KNOWN_FEATURES = ['export', 'stats', 'plugins', 'ai', 'chat', 'scheduled', 'theme'];

    /**
     * 各档位授权码【允许】的功能集合（兜底裁剪，防签约端配错）。
     *
     * 与 shouquan/index.php 的 LS_LICENSE_TIERS 保持一致：
     * trial 档只允许 chat/ai（export/stats/plugins/theme/scheduled 是付费功能，
     * 与 UNAUTH_DENY_FEATURES 口径一致）。即使签发时给 trial 勾了 export，
     * 客户端也按档位裁掉——授权码能力永远可预期，不依赖签约端不手滑。
     *
     * 未列出的档位（personal/business/enterprise）不做裁剪，由 features 字段自身决定。
     */
    private const TIER_FEATURES = [
        'trial' => ['chat', 'ai'],
    ];

    /**
     * 未授权时【系统硬性关闭】的功能，后台不可配置、不可自行勾选开启。
     *
     * 这是授权策略的一部分，不是站点管理员的自由项：
     * 谁都能在后台勾一个「未授权也允许」，授权系统就白做了。
     * 之前后台有 6 个 license_unauth_allow_* 勾选框能干这事，现在从界面移除，
     * 由这里统一判死；canUseFeature() 会先查这张表。
     *
     * 注意：私信 / 发布走 TRIAL_QUOTA 配额（限量可用），不在此列。
     *
     * 【V145 / 2026-10-28】'ai' 从此表移出：AI 辅助（发布页润色/扩写等）改为「未授权
     * 限量试用」，每人每天 license_unauth_daily_ai 次（系统默认 5，硬上限 50）。
     * 理由：未授权一刀切禁掉，试用者根本体验不到 AI，反而没人愿意授权；
     * 但不设限直接白送又会拖垮站点主的 token 成本 —— 所以走配额，与私信/发布同一套路。
     */
    private const UNAUTH_DENY_FEATURES = ['plugins', 'theme', 'scheduled', 'stats', 'export', 'chat_admin'];

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

    /** 已登记功能名列表（后台展示/自检用） */
    public static function registeredFeatures(): array
    {
        return array_keys(self::FEATURE_REGISTRY);
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
     * 规范化授权码里的 features 列表（单一来源强制）
     *
     * 1. 含 '*' → 全部功能，原样返回；
     * 2. 否则按 KNOWN_FEATURES 白名单过滤，没登记的名字一律丢弃
     *    （过滤后可能为空数组 = 一个付费功能都不给，这是 fail-closed 的预期行为）。
     */
    public static function normalizeFeatures($features): array
    {
        if (!is_array($features)) {
            return ['*'];
        }
        if (in_array('*', $features, true)) {
            return ['*'];
        }
        return array_values(array_intersect(array_map('strval', $features), self::KNOWN_FEATURES));
    }

    /**
     * 按授权档位兜底裁剪 features（防签约端把某档配超）
     *
     * 例：trial 档授权码的 features 被签成 ['*'] 或含 'export'，
     * 这里收窄到 TIER_FEATURES['trial'] = ['chat','ai']。
     * 未列档位（personal/business/enterprise）原样返回。
     */
    public static function applyTierFeatures(string $type, array $features): array
    {
        $allowed = self::TIER_FEATURES[$type] ?? null;
        if ($allowed === null) {
            return $features;
        }
        if (in_array('*', $features, true)) {
            return $allowed;
        }
        return array_values(array_intersect($features, $allowed));
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
                    // 所以单独记一张 ai_usage_logs，只统计「成功生成」的调用。
                    return (int)Database::fetchColumn(
                        "SELECT COUNT(*) FROM ai_usage_logs WHERE user_id = ? AND created_at >= ? AND created_at < ?",
                        [$userId, self::todayStart(), self::tomorrowStart()]
                    );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog License countQuotaUsage] ' . $e->getMessage());
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
            // 记不住也不能让本次 AI 结果失败：最坏情况是配额少算一次，不会误伤用户
            error_log('[Hublog License recordQuotaUsage] ' . $feature . ' : ' . $e->getMessage());
        }
    }

    /**
     * 已授权 / 未授权（试用）功能对照表
     *
     * 这是「面向使用方（站点主及其用户）的说明」，不是后台的自管项。
     * 口径一律以代码实际执行为准，避免后台说明和实现各说各话：
     *   - 发布/私信/会话/AI 走「限量试用」配额（TRIAL_QUOTA，官方统一策略，后台只读展示）；
     *     AI 自 V145 起从「付费功能」转为限量试用：未授权每人每天可用 license_unauth_daily_ai 次
     *     （系统默认 5 次，硬上限 50），用完当天不再放行，次日 0 点重置；
     *   - 后台私信管理属运营与合规取证（审核、找回收证、导出），未授权也必须可用；
     *   - 导出/统计/插件/定时发布/主题定制属付费功能，需授权码含对应项，未授权默认不开放。
     */
    public static function featureMatrix(): array
    {
        $maxUsers    = (int)self::getSetting('license_unauth_max_users', '20');
        $maxPosts    = (int)self::getSetting('license_unauth_max_posts', '100');
        $dailyPosts  = self::trialLimit('post');
        $dailyMsgs   = self::trialLimit('chat');
        $maxConv     = self::trialLimit('conversation');
        $dailyAi     = self::trialLimit('ai');

        $fmt = static function (int $n, string $unit): string {
            return $n > 0 ? "每天 {$n} {$unit}" : "不开放";
        };

        // editable：这一项后台能不能改。
        // 【2026-10-03 收敛】全表一律 false：未授权的额度与功能开放范围都是授权策略，
        // 后台只读展示即可。此前额度项标成 editable 与「后台不可改」的实际表现自相矛盾，
        // 站点主看后台会以为自己还能调，实际保存后又被后端复位——这正是「内容不对」的观感来源。
        // V145：'ai' 从「不可调」名单移出 —— 它已是额度项（license_unauth_daily_ai），
        // 与 post/chat 同类，在 LICENSE_TRIAL_CUSTOM 的自用站点下允许后台微调（仍受 QUOTA_CAP 封顶）。
        $notEditable = ['chat_admin', 'export', 'stats', 'plugins', 'scheduled', 'theme'];
        $rows = [
            ['key' => 'post',       'name' => '发布动态',     'authorized' => '不限量',           'trial' => $dailyPosts > 0 ? "每天 {$dailyPosts} 条 · 全站累计 {$maxPosts} 条" : '不开放', 'daily' => true],
            ['key' => 'chat',       'name' => '私信聊天',     'authorized' => '不限量',           'trial' => $dailyMsgs > 0 ? "每天 {$dailyMsgs} 条 · 每人 {$maxConv} 个会话" : '不开放',   'daily' => true],
            ['key' => 'users',      'name' => '注册用户',     'authorized' => '不限量',           'trial' => "累计 {$maxUsers} 个",                                                    'daily' => false],
            // 后台私信管理：审核 / 找回收证 / 导出属站点运营与合规必需，未授权也保持可用。
            // 真正走「限量试用」配额的是终端用户自己的私信，见上面 chat 一行。
            ['key' => 'chat_admin', 'name' => '后台私信管理', 'authorized' => '可用',             'trial' => '可用（运营必需）',                                                      'daily' => false],
            ['key' => 'ai',         'name' => 'AI 智能辅助',  'authorized' => '授权码含 ai（不限量）', 'trial' => $dailyAi > 0 ? "每天 {$dailyAi} 次" : '不开放',                      'daily' => true],
            ['key' => 'export',     'name' => '数据导出备份', 'authorized' => '授权码含 export',  'trial' => '不开放（付费功能）',                                                      'daily' => false],
            ['key' => 'stats',      'name' => '数据统计',     'authorized' => '授权码含 stats',   'trial' => '不开放（付费功能）',                                                      'daily' => false],
            ['key' => 'plugins',    'name' => '插件管理',     'authorized' => '授权码含 plugins', 'trial' => '不开放（付费功能）',                                                      'daily' => false],
            ['key' => 'scheduled',  'name' => '定时发布',     'authorized' => '授权码含 scheduled','trial' => '不开放（付费功能）',                                               'daily' => false],
            ['key' => 'theme',      'name' => '主题定制',     'authorized' => '授权码含 theme',   'trial' => '不开放（付费功能）',                                                      'daily' => false],
        ];
        // 优先级：功能类（付费/运营必需）永远不可调；额度类只有在 config.php 显式
        // define('LICENSE_TRIAL_CUSTOM', true) 的内测/自用站点下才允许后台微调。
        $allowCustom = defined('LICENSE_TRIAL_CUSTOM') && LICENSE_TRIAL_CUSTOM;
        foreach ($rows as &$row) {
            $row['editable'] = $allowCustom && !in_array($row['key'], $notEditable, true);
        }
        unset($row);
        return $rows;
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
        foreach (array_keys(self::FEATURE_REGISTRY) as $feature) {
            $out[self::FEATURE_REGISTRY[$feature]] = '0';
        }
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
                error_log('[Hublog License resetTrialSettings] ' . $key . ' : ' . $e->getMessage());
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
            error_log('[Hublog License usageSummary] ' . $e->getMessage());
        }
        // V145：AI 用量单独一段 try —— ai_usage_logs 是 migrate88 才建的，
        // 迁移没跑完的站点不能因为它把整份用量统计带成 0。
        try {
            $out['today_ai'] = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM ai_usage_logs WHERE created_at >= ? AND created_at < ?",
                [self::todayStart(), self::tomorrowStart()]
            );
        } catch (\Throwable $e) {
            error_log('[Hublog License usageSummary ai] ' . $e->getMessage());
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
     * 是否允许使用某功能
     * 未授权状态下根据配置判断
     * 已授权状态下：features含'*'或指定功能名则允许
     */
    public static function canUseFeature(string $feature): bool
    {
        $status = self::getStatus();
        if (!$status['enabled']) {
            return true;
        }
        if ($status['authorized']) {
            if (in_array('*', $status['features'], true)) {
                return true;
            }
            return in_array($feature, $status['features'], true);
        }

        // 未授权时系统硬性关闭的功能：后台没有开关可以把它打开
        if (in_array($feature, self::UNAUTH_DENY_FEATURES, true)) {
            return false;
        }
        // 私信 / 发布 / AI 这类走配额：配额 > 0 就放行入口，真正限制在发送时按额度拦
        if (in_array($feature, ['chat', 'post', 'ai'], true)) {
            return self::trialLimit($feature) > 0;
        }
        $settingKey = self::FEATURE_REGISTRY[$feature] ?? null;
        if ($settingKey === null) {
            error_log('[Hublog License] canUseFeature() 收到未登记的功能名 "' . $feature . '"，按默认拒绝处理');
            return false;
        }
        return self::getSetting($settingKey, '0') === '1';
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
            error_log('[Hublog License saveLicenseKeySetting] ' . $e->getMessage());
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
            error_log('[Hublog License activate] save history failed: ' . $e->getMessage());
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
            // 功能权限（前端展示用）。chat 走「限量试用」：只要配额 > 0 就放行入口，
            // 真正的限制在发送时按每日配额拦，避免未授权连入口都看不到、没法体验。
            'can_use' => [
                'ai' => self::canUseFeature('ai'),
                'chat' => self::canUseFeature('chat') || self::isTrialOpen('chat'),
                'theme' => self::canUseFeature('theme'),
                'scheduled' => self::canUseFeature('scheduled'),
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
