<?php
/**
 * Hublog 授权系统后台控制器
 *
 * P2-4 上帝类拆分：从 AdminController（4935 行 / 123 方法）拆出「授权系统」子域。
 * 本类继承 AdminController，仅承载授权相关的 7 个路由 handler；方法体逐字沿用父类原实现，仅 relocate。
 * upsertSetting 等公共 helper 已上提到 BaseController（P2-4 拆域后被多个 Admin 子类共用）。
 * 注：本文件是 legacy 方法的 relocate，未加 declare(strict_types=1)，避免与大量框架内部调用产生无法测试的 TypeError；
 *      该文件将在 P2-7 strict_types 统一推进时随测随加。
 */

namespace Hublog\Controllers;

use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\SettingsRepository;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Security;
use Hublog\Core\Helper;

class AdminLicenseController extends AdminController
{
    // ==================== V37: 授权系统管理接口 ====================

    /**
     * 获取完整授权状态（管理员专用，含内部信息）
     */
    public function licenseStatus(Request $request): Response
    {
        $this->requireAdmin($request);
        $status = \Hublog\Core\License::getStatus(true);
        $resourceLimit = \Hublog\Core\License::checkResourceLimit();
        $public = \Hublog\Core\License::getPublicStatus();

        // 读取所有授权设置
        $settings = [];
        try {
            $rows = SettingsRepository::fetchLicenseSettings();
            foreach ($rows as $row) {
                $k = $row['key'];
                // 公钥需原样返回完整 PEM：截断后前端回填到输入框，
                // 一旦用户再点「保存」就会把截断值写回数据库，导致公钥永久损坏、
                // 后续授权码全部验签失败。公钥本身是公开信息，无需遮蔽。
                $settings[$k] = $row['value'];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 补充诊断字段（不影响授权判断，仅供前端展示）
        if (is_array($status)) {
            try {
                $lastErr = \Hublog\Core\License::getSetting('license_remote_last_error', '');
                $status['remote_last_error'] = is_string($lastErr) ? $lastErr : '';
            } catch (\Throwable $e) {
                $status['remote_last_error'] = '';
            }
        }

        return Response::success([
            'status' => $status,
            'resource_limit' => $resourceLimit,
            'public' => $public,
            'settings' => (object)$settings,
            'current_domain' => \Hublog\Core\License::getCurrentDomain(),
            // 已授权 / 未授权 功能对照表 + 当前用量，后台页直接渲染，做到「限制写得清清楚楚」
            'feature_matrix' => \Hublog\Core\License::moduleMatrix(),
            'usage' => \Hublog\Core\License::usageSummary(),
        ]);
    }

    /**
     * 测试远程授权服务器连接（不从 settings 读，直接用前端传来的「待保存」参数试一次）
     */
    public function licenseTestRemote(Request $request): Response
    {
        $this->requireAdmin($request);

        $url        = trim((string)$request->getString('url', ''));
        $pubKey     = trim((string)$request->getString('pubkey', ''));
        $timeout    = (int)$request->getInt('timeout', 8);
        $sslVerify  = ((string)$request->getString('ssl_verify', '1')) === '1';
        $licenseKey = trim((string)$request->getString('test_license_key', ''));

        $startTime = microtime(true);

        if ($url === '') {
            return Response::success([
                'ok'         => false,
                'latency_ms' => (microtime(true) - $startTime) * 1000,
                'reason'     => 'URL 不能为空',
                'diagnosis'  => '请在「远程授权服务器 URL」输入框填写 remote_verify.php 的完整地址。',
            ]);
        }
        if (!function_exists('curl_init')) {
            return Response::success([
                'ok'         => false,
                'latency_ms' => (microtime(true) - $startTime) * 1000,
                'reason'     => '当前 PHP 环境未启用 curl 扩展',
                'diagnosis'  => '请在 php.ini 中启用 extension=curl，或更换支持 curl 的主机。',
            ]);
        }

        if ($licenseKey === '') {
            // 不做授权验证，只做 HTTP 连通性 ping——向服务端发一个空的示例请求
            $licenseKey = 'PING_TEST_ONLY';
        }

        try {
            // ========== SSRF 防护 ==========
            // 管理员填写的远程验证 URL 仍要拦截内网/元数据/危险端口，防止被盗账号后用来扫描内网
            $ssrfCheck = \Hublog\Core\Security::validateOutboundUrl($url, [
                'allowHttp' => !$sslVerify,
            ]);
            if (!$ssrfCheck['ok']) {
                return Response::success([
                    'ok' => false,
                    'latency_ms' => (microtime(true) - $startTime) * 1000,
                    'ssrf_blocked' => true,
                    'reason' => 'SSRF 防护拦截：' . ($ssrfCheck['reason'] ?? '校验失败'),
                    'diagnosis' => '为避免内网资源/云元数据被扫描，URL 不允许指向：回环地址(127.*)、私网段(10/172.16-31/192.168)、云元数据(169.254.169.254)、数据库/SSH/Redis 等危险端口。如确认此地址安全，可在配置中将主机加入安全白名单。',
                    'ssrf_host'   => $ssrfCheck['host'],
                    'ssrf_scheme' => $ssrfCheck['scheme'],
                    'ssrf_port'   => $ssrfCheck['port'],
                ]);
            }

            $fingerprint = \Hublog\Core\License::getHardwareFingerprint();
            $domain = \Hublog\Core\License::getCurrentDomain();
            $postData = json_encode([
                'license_key' => $licenseKey,
                'domain'      => $domain,
                'fingerprint' => $fingerprint,
                'version'     => defined('APP_VERSION') ? APP_VERSION : 'v2.0',
                'timestamp'   => time(),
                'nonce'       => bin2hex(random_bytes(8)),
                'stats'       => ['users' => 0, 'posts' => 0],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ch = curl_init($url);
            \Hublog\Core\Security::applyCurlSsrfOptions($ch, !$sslVerify);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => max(3, $timeout),
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Accept: application/json'],
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
                CURLOPT_USERAGENT      => 'Hublog-License-Test/1.0',
                CURLOPT_FAILONERROR    => false,
            ]);
            $resp    = curl_exec($ch);
            $http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err     = curl_error($ch);
            $dnsTime = (float)curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME);
            $connTime= (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME);
            curl_close($ch);
            $latencyMs = (microtime(true) - $startTime) * 1000;

            if ($resp === false) {
                return Response::success([
                    'ok'         => false,
                    'latency_ms' => $latencyMs,
                    'reason'     => 'cURL 错误: ' . $err,
                    'diagnosis'  => "DNS: {$dnsTime}s / Connect: {$connTime}s —— 请检查服务器 DNS、防火墙、出口 IP 是否被授权服务器封掉，或 URL 是否正确。",
                ]);
            }
            if ($http !== 200) {
                $snippet = substr((string)$resp, 0, 300);
                return Response::success([
                    'ok'         => false,
                    'latency_ms' => $latencyMs,
                    'reason'     => "HTTP $http",
                    'diagnosis'  => "URL 返回非 200 状态。常见原因：\n1. URL 写错（404）→ 改为指向 remote_verify.php 的完整路径\n2. 授权站点 BasicAuth / WAF 拦截 (401/403)\n3. 程序报错 500 → 看授权服务器 error_log\n\n响应片段:\n$snippet",
                ]);
            }
            $data = json_decode((string)$resp, true);
            if (!is_array($data)) {
                return Response::success([
                    'ok'         => false,
                    'latency_ms' => $latencyMs,
                    'reason'     => '响应不是合法 JSON',
                    'diagnosis'  => '首 200 字节: ' . substr((string)$resp, 0, 200),
                ]);
            }
            // ============ 签名验证 ============
            $code    = (int)($data['code'] ?? 0);
            $payload = $data['data'] ?? null;
            $sig     = (string)($data['signature'] ?? '');
            if ($pubKey !== '' && $sig !== '') {
                $signingStr = \Hublog\Core\License::canonicalJson((array)$payload);
                $key = openssl_pkey_get_public($pubKey);
                if (!$key) {
                    return Response::success([
                        'ok'         => false,
                        'latency_ms' => $latencyMs,
                        'reason'     => '配置的远程服务器公钥格式无效',
                        'diagnosis'  => '请从授权签发系统「🌐 远程验签」页面复制完整公钥。',
                    ]);
                }
                $sigBin = @hex2bin($sig);
                $ok = $sigBin ? openssl_verify($signingStr, $sigBin, $key, OPENSSL_ALGO_SHA256) : 0;
                openssl_free_key($key);
                if ($ok !== 1) {
                    return Response::success([
                        'ok'         => false,
                        'latency_ms' => $latencyMs,
                        'reason'     => '响应签名验证失败',
                        'diagnosis'  => '可能原因：\n1. 使用的不是对应私钥签名的服务器\n2. 响应被篡改（DNS/代理污染）\n3. 粘贴的公钥不是该授权服务器的公钥',
                    ]);
                }
            }
            // ============ 业务结果 ============
            if ($code !== 200) {
                return Response::success([
                    'ok'          => true,
                    'latency_ms'  => $latencyMs,
                    'licensed'    => false,
                    'reason'      => (string)($data['message'] ?? '接口业务 code != 200'),
                    'license_info'=> '',
                ]);
            }
            $valid = !empty($payload['valid']);
            $reason = (string)($payload['reason'] ?? '');
            $info = '';
            if (is_array($payload)) {
                $typeMap = ['trial'=>'试用','personal'=>'个人','business'=>'商业','enterprise'=>'企业'];
                $info  = ($typeMap[$payload['type'] ?? 'personal'] ?? $payload['type'] ?? '') . '版';
                if (!empty($payload['expires_at'])) {
                    $info .= ' · 到期 ' . $payload['expires_at'];
                } else {
                    $info .= ' · 永久';
                }
                if (!empty($payload['customer_name'])) $info .= ' · ' . $payload['customer_name'];
            }
            return Response::success([
                'ok'          => true,
                'latency_ms'  => $latencyMs,
                'licensed'    => $valid,
                'reason'      => $valid ? '' : ($reason ?: '授权无效'),
                'license_info'=> $info,
            ]);
        } catch (\Throwable $e) {
            return Response::success([
                'ok'         => false,
                'latency_ms' => (microtime(true) - $startTime) * 1000,
                'reason'     => '异常: ' . $e->getMessage(),
                'diagnosis'  => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    /**
     * 激活/输入授权码
     */
    public function licenseActivate(Request $request): Response
    {
        $this->requireAdmin($request);
        $licenseKey = $request->getString('license_key');
        if (empty($licenseKey)) {
            return Response::error('授权码不能为空', 400);
        }
        try {
            $status = \Hublog\Core\License::activateLicense($licenseKey);
            if ($status['authorized']) {
                return Response::success($status, '授权激活成功');
            }
            $msg = '授权码已保存，但验证未通过。';
            switch ($status['type']) {
                case 'expired':
                    $msg .= '授权已过期。';
                    break;
                case 'domain_mismatch':
                    $msg .= '当前域名不在授权范围内。';
                    break;
                case 'integrity_failed':
                    $msg .= '核心文件完整性校验失败。';
                    break;
            }
            return Response::success($status, $msg);
        } catch (\Hublog\Core\Exception\HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            return Response::error('激活失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 移除/停用授权码
     */
    public function licenseDeactivate(Request $request): Response
    {
        $this->requireAdmin($request);
        \Hublog\Core\License::deactivateLicense();
        return Response::success(null, '已移除当前授权码');
    }

    /**
     * 刷新核心文件哈希（升级后或手动修改核心文件后执行）
     */
    public function licenseRefreshHashes(Request $request): Response
    {
        $this->requireAdmin($request);
        try {
            $result = \Hublog\Core\License::refreshIntegrityHashes();
            \Hublog\Core\License::clearCache();
            return Response::success($result, sprintf(
                '核心文件哈希已刷新（新增 %d 个，更新 %d 个）',
                $result['added'] ?? 0,
                $result['updated'] ?? 0
            ));
        } catch (\Throwable $e) {
            return Response::error('刷新哈希失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 保存授权系统设置
     */
    public function licenseSaveSettings(Request $request): Response
    {
        $this->requireAdmin($request);

        // 【2026-10-03 收敛授权自主权】
        // 以下项目从「后台可写」里移除，改为系统内置策略（License::TRIAL_QUOTA / KNOWN_MODULES）：
        //   - license_unauth_allow_*（ai/plugins/chat/stats/export/theme/scheduled）
        //     未授权时能用什么功能，是授权策略而非安装者自选；此前后台可勾选 = 用户自己给自己发许可证。
        //   - license_unauth_max_users / max_posts / daily_posts / daily_messages / max_conversations
        //     试用额度同样是官方统一策略。此前可自行填 99999，等于把试用限制自己解除。
        // 现在后台「授权管理」页对这些项只读展示（值仍从 settings 读，未设置则用 License 内置默认值）。
        //
        // 【2026-10-03 再收敛：取消授权总开关】
        // 授权总开关（license_enabled）、文件完整性校验开关（license_integrity_*）、未授权提示横幅
        // （license_unauth_show_banner）已从后台界面与可写白名单同时移除：
        //   买授权 = 全功能可用；没买 = 限制版。能不能用只由授权码决定，
        //   站点自己没有「开关」能一会儿全开一会儿全关（有开关就等于自己能自我授权/自我豁免）。
        // 剩下站点可自主配置的只有验签相关项：验证模式、RSA 公钥、远程地址与超时。
        $boolKeys = [
            'license_remote_ssl_verify',
        ];
        $textKeys = [
            'license_verify_mode',
            'license_remote_url',
            'license_remote_pubkey',
            'license_bind_domain',
            'license_remote_timeout',
        ];

        // 额度项：只有 config.php 里 define('LICENSE_TRIAL_CUSTOM', true) 的内测/自用站点才开放写入
        $allowCustom = defined('LICENSE_TRIAL_CUSTOM') && LICENSE_TRIAL_CUSTOM;
        if ($allowCustom) {
            $textKeys = array_merge($textKeys, [
                'license_unauth_max_users',
                'license_unauth_max_posts',
                'license_unauth_daily_posts',
                'license_unauth_daily_messages',
                'license_unauth_max_conversations',
            ]);
        }

        // 公钥单独处理（较长，不轻易覆盖）
        $pubKey = trim((string)$request->getString('license_public_key'));

        $now = Helper::now();
        $updatedCount = 0;

        // ========== 越权防护 ==========
        // 此前未授权站点可以 POST 本接口：
        //   1) license_enabled = 0        → 直接关掉整个授权系统，所有校验失效（现已无此写入口，界面也无开关）
        //   2) license_unauth_allow_* = 1 → 自己给自己开功能（等于自己发许可证）
        //   3) integrity 校验关掉/放宽    → 抹掉篡改检测（现已无此写入口）
        // 现在未授权状态下仍一律拒绝会削弱防盗的改动；已授权站点不受影响。
        // 官方自有站点如需临时绕过，可在 config.php 里 define('LICENSE_GUARD_OVERRIDE', true)。
        $guardEnabled = !(defined('LICENSE_GUARD_OVERRIDE') && LICENSE_GUARD_OVERRIDE);
        $blockedKeys = [];
        if ($guardEnabled && !\Hublog\Core\License::getStatus()['authorized']) {
            foreach ($boolKeys as $i => $k) {
                if ($k !== 'license_remote_ssl_verify') {
                    continue;
                }
                $raw = $request->get($k, null);
                if ($raw === null) continue;
                $wants = ((string)$raw === '1' || $raw === true || $raw === 1);
                if (!$wants) {
                    $blockedKeys[] = $k;
                    unset($boolKeys[$i]);
                }
            }
            if ($blockedKeys) {
                error_log('[Hublog License] 未授权站点尝试修改受保护的授权设置，已拒绝: ' . implode(',', $blockedKeys)); // @allow-error-log
            }
        }

        foreach ($boolKeys as $k) {
            $val = $request->get($k, null);
            if ($val === null) continue;
            $val = ((string)$val === '1' || $val === true || $val === 1) ? '1' : '0';
            $this->upsertSetting($k, $val, $now);
            $updatedCount++;
        }
        foreach ($textKeys as $k) {
            // 【修复 500】getString() 的 $default 声明为 string，传 null 会直接 TypeError。
            // 这里要区分「没传」和「传了空字符串」，所以必须用 get($k, null) 拿原始值再自己转。
            $raw = $request->get($k, null);
            if ($raw === null) continue;
            $val = (string)$raw;
            $this->upsertSetting($k, Security::purify($val), $now);
            $updatedCount++;
        }
        // 公钥：非空才更新，防止意外清空
        // 这里要同时拒绝两类损坏值：
        //   1) 旧版本后台回填的是 substr 截断版（含 "..." 占位符），用户没改直接点保存会把这个损坏值写回；
        //   2) PEM 格式本身不合法（openssl_pkey_get_public 返回 false）。
        // 一旦写入损坏公钥，所有授权码本地验签都会失败，且后台看着「完整显示」却无法使用，
        // 排查极难。这里在落库前做一次 openssl 加载校验，损坏直接报错、不写入。
        if ($pubKey !== '' && str_contains($pubKey, 'BEGIN PUBLIC KEY')) {
            if (str_contains($pubKey, '...')) {
                return Response::error(
                    '公钥内容里检测到 "..." 占位符，疑似是历史截断版，拒绝写入以免破坏验签。请清空输入框后重新粘贴官方渠道获取的完整 PEM 公钥。',
                    400
                );
            }
            $probe = openssl_pkey_get_public($pubKey);
            if ($probe === false) {
                return Response::error(
                    '公钥 PEM 格式不合法，openssl 无法加载（可能被截断、换行被压成空格、或粘贴时丢字符）。请重新粘贴完整 PEM。',
                    400
                );
            }
            // PHP 8.0+ 自动 GC，无需 openssl_free_key
            $this->upsertSetting('license_public_key', $pubKey, $now);
            $updatedCount++;
        }

        // 统一把「试用额度 / 未授权功能开关」拉回系统内置默认：
        // 此前站点自己填过的大额额度或自开的 allow_* 会一直残留在 settings 里，
        // 后台看着是只读、实际却在按用户填的值执行。保存一次就顺手清干净。
        $resetInfo = ['changed' => 0, 'reset' => []];
        if (!$allowCustom) {
            $resetInfo = \Hublog\Core\License::resetTrialSettingsToDefault();
        }

        Cache::delete('site_settings');
        \Hublog\Core\License::clearCache();

        // 刚改过远程地址/公钥，顺带拉一次吊销清单，免得换了地址还要等后台手动点同步才生效。
        // 失败不报错（fail-open）：清单拉不到只是「暂时不知道有新吊销」，不该打断保存。
        $revokedSync = \Hublog\Core\License::syncRevokedList();

        if ($blockedKeys) {
            return Response::success(
                ['updated' => $updatedCount, 'blocked' => $blockedKeys, 'trial_reset' => $resetInfo],
                '已保存；但 ' . count($blockedKeys) . ' 项受保护设置在未授权状态下不可修改：' . implode('、', $blockedKeys)
            );
        }
        $extra = $resetInfo['changed'] > 0
            ? '（试用额度已恢复为系统默认，清除了 ' . count($resetInfo['reset']) . ' 项自定义值）'
            : '';
        return Response::success([
            'updated' => $updatedCount,
            'trial_reset' => $resetInfo,
            'revoked_sync' => $revokedSync,
        ], '授权设置已保存' . $extra);
    }

    /**
     * 手动同步官方吊销清单
     *
     * 离线验签模式下不会真的问授权服务器「这张码还在不在」，
     * 客户端靠这份带签名的公开清单知道哪些码已被官方撤销/停用。
     */
    public function licenseSyncRevoked(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        $result = \Hublog\Core\License::syncRevokedList();
        \Hublog\Core\License::clearCache();
        $status = \Hublog\Core\License::getStatus(true);

        $data = [
            'count' => (int)$result['count'],
            'synced_at' => (int)$result['synced_at'],
            'authorized' => !empty($status['authorized']),
            'type' => (string)($status['type'] ?? ''),
            'revoked_reason' => (string)($status['revoked_reason'] ?? ''),
        ];

        if (!empty($result['ok'])) {
            return Response::success($data, '吊销清单已同步，共 ' . $data['count'] . ' 条');
        }
        return Response::success($data, '同步未完成：' . (string)($result['reason'] ?? '未知原因'));
    }

}
