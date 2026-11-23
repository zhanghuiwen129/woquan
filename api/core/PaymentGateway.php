<?php
declare(strict_types=1);

/**
 * 支付网关：微信支付 v3（App 支付）+ 支付宝（App 支付，RSA2）
 *
 * 站点没有 composer / vendor，所以这里不引 SDK，全部用 openssl + curl 手签：
 *
 *   微信：下单 POST /v3/pay/transactions/app（WECHATPAY2-SHA256-RSA2048 头签名），
 *         拿到 prepay_id 后再做一次 App 调起参数签名。
 *         回调不验签 —— 而是用「主动查单」确认 trade_state=SUCCESS 才入账：
 *         验签要下载并轮换微信平台证书，主动查单既省掉证书管理，也更抗伪造通知。
 *
 *   支付宝：下单 alipay.trade.app.pay（RSA2 签名拼出 orderString 给 App 调起），
 *         回调直接用支付宝公钥验签。
 *
 * 入账一律以回调为准且幂等（见 PaymentController::markRechargePaid）。
 */

namespace Hublog\Core;

class PaymentGateway
{
    /** 环境自检：缺扩展就直接告诉站长，别让下单时抛莫名其妙的错 */
    public static function available(): array
    {
        $miss = [];
        if (!extension_loaded('curl')) {
            $miss[] = 'curl';
        }
        if (!extension_loaded('openssl')) {
            $miss[] = 'openssl';
        }
        return ['ok' => $miss === [], 'missing' => $miss];
    }

    // ==================== 微信支付 v3 ====================

    /**
     * App 支付下单
     *
     * @param array $cfg appid / mchid / serial_no / private_key(PEM) / notify_url
     * @return array ok=true 时带 params（直接喂给 uni.requestPayment 的 orderInfo）
     */
    public static function wxpayAppOrder(string $orderNo, float $amount, string $desc, array $cfg): array
    {
        $check = self::available();
        if (!$check['ok']) {
            return ['ok' => false, 'reason' => 'PHP 缺少扩展：' . implode(',', $check['missing'])];
        }

        $path = '/v3/pay/transactions/app';
        $body = json_encode([
            'appid' => $cfg['appid'],
            'mchid' => $cfg['mchid'],
            'description' => $desc,
            'out_trade_no' => $orderNo,
            'notify_url' => $cfg['notify_url'],
            'amount' => [
                'total' => (int)round($amount * 100),
                'currency' => 'CNY',
            ],
        ], JSON_UNESCAPED_UNICODE);

        $timestamp = (string)time();
        $nonce = self::nonce();
        $message = "POST\n{$path}\n{$timestamp}\n{$nonce}\n{$body}\n";
        try {
            $signature = self::rsaSign($message, (string)$cfg['private_key']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => '商户私钥无效：' . $e->getMessage()];
        }

        $auth = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
            $cfg['mchid'],
            $nonce,
            $timestamp,
            $cfg['serial_no'],
            $signature
        );

        $res = self::http('POST', 'https://api.mch.weixin.qq.com' . $path, $body, [
            'Authorization: ' . $auth,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: Hublog',
        ]);
        if (!$res['ok']) {
            return ['ok' => false, 'reason' => '微信下单失败：' . self::errText($res)];
        }
        $prepayId = (string)($res['data']['prepay_id'] ?? '');
        if ($prepayId === '') {
            return ['ok' => false, 'reason' => '微信下单未返回 prepay_id：' . self::errText($res)];
        }

        // App 调起参数（二次签名，字段顺序固定：appid/timestamp/noncestr/prepayid）
        $ts = (string)time();
        $nonce2 = self::nonce();
        $raw = "{$cfg['appid']}\n{$ts}\n{$nonce2}\n{$prepayId}\n";
        try {
            $paySign = self::rsaSign($raw, (string)$cfg['private_key']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => '调起签名失败：' . $e->getMessage()];
        }

        return [
            'ok' => true,
            'params' => [
                'appid' => $cfg['appid'],
                'partnerid' => $cfg['mchid'],
                'prepayid' => $prepayId,
                'package' => 'Sign=WXPay',
                'noncestr' => $nonce2,
                'timestamp' => $ts,
                'sign' => $paySign,
            ],
        ];
    }

    /**
     * 从查单响应 data 解析金额(分)；缺失/异常返回 null。
     *
     * 公开供单测钉死字段路径：微信 v3 查单金额在 $data['amount']['total']（int 分），
     * 与 trade_state 同属 http() 包装的 data 顶层，不可写成 $res['amount']['total']。
     */
    public static function parseQueryAmount(array $data): ?int
    {
        return isset($data['amount']['total']) && is_numeric($data['amount']['total'])
            ? (int)$data['amount']['total'] : null;
    }

    /**
     * 主动查单（回调入账前确认，避免依赖平台证书验签）
     *
     * @return array ok=true 时带 trade_state / transaction_id / amount_total(?int 分)
     */
    public static function wxpayQuery(string $orderNo, array $cfg): array
    {
        $check = self::available();
        if (!$check['ok']) {
            return ['ok' => false, 'reason' => 'PHP 缺少扩展：' . implode(',', $check['missing'])];
        }

        $path = '/v3/pay/transactions/out-trade-no/' . rawurlencode($orderNo) . '?mchid=' . rawurlencode((string)$cfg['mchid']);
        $timestamp = (string)time();
        $nonce = self::nonce();
        $message = "GET\n{$path}\n{$timestamp}\n{$nonce}\n\n";
        try {
            $signature = self::rsaSign($message, (string)$cfg['private_key']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => '商户私钥无效：' . $e->getMessage()];
        }
        $auth = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
            $cfg['mchid'],
            $nonce,
            $timestamp,
            $cfg['serial_no'],
            $signature
        );

        $res = self::http('GET', 'https://api.mch.weixin.qq.com' . $path, '', [
            'Authorization: ' . $auth,
            'Accept: application/json',
            'User-Agent: Hublog',
        ]);
        if (!$res['ok']) {
            return ['ok' => false, 'reason' => '微信查单失败：' . self::errText($res)];
        }
        return [
            'ok' => true,
            'trade_state' => (string)($res['data']['trade_state'] ?? ''),
            'transaction_id' => (string)($res['data']['transaction_id'] ?? ''),
            // P1-2：带出查单金额（分），供控制器按 settings 开关做一致性校验。
            // 字段路径必须是 data 顶层下的 amount.total，与 trade_state 同前缀。
            'amount_total' => self::parseQueryAmount($res['data']),
        ];
    }

    /**
     * 解密微信 v3 回调的 resource（AES-256-GCM）
     *
     * 回调里的 out_trade_no 在密文中，不解密连查单都无从下手。
     * 微信的 ciphertext = base64(密文 || 16 字节 GCM tag)，所以要先把 tag 摘出来。
     *
     * @param array $resource 回调里的 resource 段
     * @param string $apiV3Key 商户 APIv3 密钥（32 字节）
     * @return array|null 解密后的数组，失败返回 null
     */
    public static function wxpayDecryptResource(array $resource, string $apiV3Key): ?array
    {
        if ($apiV3Key === '' || strlen($apiV3Key) !== 32) {
            return null;
        }
        $ciphertext = (string)($resource['ciphertext'] ?? '');
        $nonce = (string)($resource['nonce'] ?? '');
        $aad = (string)($resource['associated_data'] ?? '');
        if ($ciphertext === '' || $nonce === '') {
            return null;
        }
        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) <= 16) {
            return null;
        }
        $tag = substr($raw, -16);
        $data = substr($raw, 0, -16);
        $plain = openssl_decrypt($data, 'aes-256-gcm', $apiV3Key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($plain === false) {
            return null;
        }
        $arr = json_decode($plain, true);
        return is_array($arr) ? $arr : null;
    }

    // ==================== 支付宝 ====================

    /**
     * App 支付下单
     *
     * @param array $cfg appid / private_key(PEM, PKCS8) / notify_url
     * @return array ok=true 时带 orderInfo（orderString，直接给 uni.requestPayment）
     */
    public static function alipayAppOrder(string $orderNo, float $amount, string $desc, array $cfg): array
    {
        $check = self::available();
        if (!$check['ok']) {
            return ['ok' => false, 'reason' => 'PHP 缺少扩展：' . implode(',', $check['missing'])];
        }

        $params = [
            'app_id' => (string)$cfg['appid'],
            'method' => 'alipay.trade.app.pay',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => (string)$cfg['notify_url'],
            'biz_content' => json_encode([
                'out_trade_no' => $orderNo,
                'total_amount' => number_format($amount, 2, '.', ''),
                'subject' => $desc,
                'product_code' => 'QUICK_MSECURITY_PAY',
            ], JSON_UNESCAPED_UNICODE),
        ];
        ksort($params);
        $plain = self::buildPlainQuery($params);
        try {
            $sign = self::rsaSign($plain, (string)$cfg['private_key'], OPENSSL_ALGO_SHA256);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => '应用私钥无效：' . $e->getMessage()];
        }

        // App 端要的是「待签串 + sign」的 urlencode 版本
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . rawurlencode((string)$v);
        }
        $parts[] = 'sign=' . rawurlencode($sign);

        return ['ok' => true, 'orderInfo' => implode('&', $parts)];
    }

    /**
     * 支付宝异步通知验签（RSA2）
     *
     * @param array $post 原始 $_POST（不含 $_GET）
     * @param string $publicKey 支付宝公钥 PEM
     */
    public static function alipayVerify(array $post, string $publicKey): bool
    {
        if (!isset($post['sign']) || $publicKey === '') {
            return false;
        }
        $sign = (string)$post['sign'];
        $data = $post;
        unset($data['sign'], $data['sign_type']);
        ksort($data);
        $plain = self::buildPlainQuery($data);

        $key = openssl_pkey_get_public(self::normalizePublicKey($publicKey));
        if (!$key) {
            return false;
        }
        $ok = openssl_verify($plain, base64_decode($sign), $key, OPENSSL_ALGO_SHA256);
        return $ok === 1;
    }

    // ==================== 公共私有方法 ====================

    private static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** RSA-SHA256 签名，返回 base64 */
    private static function rsaSign(string $data, string $privateKey, int $algo = OPENSSL_ALGO_SHA256): string
    {
        $key = openssl_pkey_get_private(self::normalizePrivateKey($privateKey));
        if (!$key) {
            throw new \RuntimeException('openssl_pkey_get_private 失败');
        }
        $raw = '';
        if (!openssl_sign($data, $raw, $key, $algo)) {
            throw new \RuntimeException('openssl_sign 失败');
        }
        return base64_encode($raw);
    }

    /** 裸 base64（无 PEM 头尾）的私钥/公钥补成 PEM，兼容后台只粘了密钥串的情况 */
    private static function normalizePrivateKey(string $key): string
    {
        $key = trim($key);
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }
        $body = trim(chunk_split(preg_replace('/\s+/', '', $key), 64, "\n"));
        return "-----BEGIN PRIVATE KEY-----\n{$body}\n-----END PRIVATE KEY-----";
    }

    private static function normalizePublicKey(string $key): string
    {
        $key = trim($key);
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }
        $body = trim(chunk_split(preg_replace('/\s+/', '', $key), 64, "\n"));
        return "-----BEGIN PUBLIC KEY-----\n{$body}\n-----END PUBLIC KEY-----";
    }

    /** 待签串：原始值（未 urlencode）按 key 字典序拼接 */
    private static function buildPlainQuery(array $params): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $parts[] = $k . '=' . $v;
        }
        return implode('&', $parts);
    }

    /**
     * @return array{ok:bool,code:int,data:array,raw:string}
     */
    private static function http(string $method, string $url, string $body = '', array $headers = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return ['ok' => false, 'code' => 0, 'data' => [], 'raw' => 'CURL_ERR ' . $errno . ' ' . $err];
        }
        $json = json_decode($raw, true);
        return [
            'ok' => $code >= 200 && $code < 300,
            'code' => $code,
            'data' => is_array($json) ? $json : [],
            'raw' => (string)$raw,
        ];
    }

    private static function errText(array $res): string
    {
        $d = $res['data'];
        if (isset($d['message']) && $d['message'] !== '') {
            return (string)$d['message'] . (isset($d['code']) ? ' (' . $d['code'] . ')' : '');
        }
        return 'HTTP ' . $res['code'] . ' ' . $res['raw'];
    }
}
