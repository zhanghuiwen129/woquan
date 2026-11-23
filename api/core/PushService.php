<?php
declare(strict_types=1);

/**
 * Hublog App 离线推送服务
 *
 * 设计目标：
 *  1. 与 SSE 互补——SSE 只覆盖前台页面，App 退后台/进程被杀时由厂商通道补推；
 *  2. 完全可降级——未配置或调用失败只写日志，绝不阻断业务主流程；
 *  3. 多厂商适配——UniPush(个推 v2)、极光 JPush、FCM、APNs，按 push_tokens.provider 分发；
 *  4. 统一事件映射——业务侧只管抛事件，标题/文案在此集中维护。
 *
 * 配置（后台 settings 表，键名如下）：
 *  push_enabled                  1/0 总开关
 *  push_provider                 unipush|jpush|fcm|apns（默认 unipush）
 *  push_unipush_app_id           UniPush / 个推 AppID
 *  push_unipush_app_key          AppKey
 *  push_unipush_app_secret       AppSecret（v2 鉴权用）
 *  push_unipush_master_secret    MasterSecret（v1 鉴权用，DCloud UniPush 1.0 场景）
 *  push_jpush_app_key            JPush AppKey
 *  push_jpush_master_secret      JPush MasterSecret
 *  push_fcm_server_key           FCM Server Key
 *  push_apns_*                   APNs（默认不启用，走 UniPush 的 iOS 通道即可）
 */

namespace Hublog\Core;

class PushService
{
    /** 单次推送最大失败重试次数 */
    private const MAX_RETRY = 2;

    /** 事件 → 推送文案映射（title/content） */
    private const EVENT_MAP = [
        'new_message'      => ['title' => '收到一条新私信',   'content_key' => 'content'],
        // ChatService::sendMessage 抛的是 chat_message，data 里只有 preview（没有 content）
        'chat_message'     => ['title' => '收到一条新私信',   'content_key' => 'preview'],
        'new_comment'      => ['title' => '有人评论了你',     'content_key' => 'content'],
        'comment_reply'    => ['title' => '有人回复了你',     'content_key' => 'content'],
        'new_like'         => ['title' => '有人赞了你',       'content_key' => ''],
        'new_follow'       => ['title' => '新增一位粉丝',     'content_key' => ''],
        'new_favorite'     => ['title' => '有人收藏了你的动态', 'content_key' => ''],
        'comment_like'     => ['title' => '有人赞了你的评论', 'content_key' => 'content'],
        'post_pinned'      => ['title' => '你的动态被置顶',   'content_key' => ''],
        'system_notice'    => ['title' => '系统通知',         'content_key' => 'content'],
        // V55：来电（离线时靠它唤醒被叫端，payload 里带 room_id/type，点击后进来电页）
        'call_invite'      => ['title' => '来电',            'content_key' => 'nickname'],
    ];

    /* ==================================================================
     * 对外：事件入口（由 SsePush::push 调用）
     * ================================================================== */

    /**
     * 统一事件推送入口：把 SSE 事件翻译成厂商推送
     *
     * @param int    $userId 接收者
     * @param string $event  事件名
     * @param array  $data   事件数据
     */
    public static function pushFromEvent(int $userId, string $event, array $data): void
    {
        if ($userId <= 0) {
            return;
        }
        // 未显式开启（push_enabled=1）则不推送，避免配置不全时产生噪音
        if ((string)self::cfg('push_enabled', '0') !== '1') {
            return;
        }

        $map = self::EVENT_MAP[$event] ?? null;
        if ($map === null) {
            return; // 未知事件不推送，避免噪音
        }

        $title = $map['title'];
        $content = '';
        if ($map['content_key'] !== '' && isset($data[$map['content_key']])) {
            $content = self::clip((string)$data[$map['content_key']], 60);
        }
        if ($content === '' && isset($data['message'])) {
            $content = self::clip((string)$data['message'], 60);
        }
        if ($content === '') {
            $content = $title;
        }

        self::pushToUser($userId, $title, $content, [
            'event' => $event,
            'data'  => $data,
        ]);
    }

    /**
     * 向某用户的所有已登记设备推送
     *
     * @param int    $userId
     * @param string $title
     * @param string $content
     * @param array  $payload 附加业务数据（会放进 intent/passthrough）
     * @return array{sent:int,failed:int}
     */
    public static function pushToUser(int $userId, string $title, string $content, array $payload = []): array
    {
        $result = ['sent' => 0, 'failed' => 0];

        try {
            $rows = Database::fetchAll(
                "SELECT id, provider, token FROM push_tokens WHERE user_id = ? ORDER BY updated_at DESC LIMIT 10",
                [$userId]
            );
        } catch (\Throwable $e) {
            Logger::error('[Hublog PushService] 读取 push_tokens 失败: ' . $e->getMessage());
            return $result;
        }

        if (!$rows) {
            return $result;
        }

        // 按 provider 分组，减少鉴权次数
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['provider']][] = $row['token'];
        }

        foreach ($grouped as $provider => $tokens) {
            foreach (array_chunk($tokens, 100) as $chunk) {
                try {
                    $ok = self::dispatch($provider, $chunk, $title, $content, $payload);
                    $ok ? ($result['sent'] += count($chunk)) : ($result['failed'] += count($chunk));
                } catch (\Throwable $e) {
                    $result['failed'] += count($chunk);
                    Logger::error('[Hublog PushService] 推送失败 provider=' . $provider . ' msg=' . $e->getMessage());
                }
            }
        }

        return $result;
    }

    /* ==================================================================
     * 厂商分发
     * ================================================================== */

    private static function dispatch(string $provider, array $tokens, string $title, string $content, array $payload): bool
    {
        switch (strtolower($provider)) {
            case 'unipush':
            case 'getui':
                return self::pushUniPush($tokens, $title, $content, $payload);
            case 'jpush':
                return self::pushJPush($tokens, $title, $content, $payload);
            case 'fcm':
                return self::pushFcm($tokens, $title, $content, $payload);
            case 'apns':
                return self::pushApns($tokens, $title, $content, $payload);
            default:
                Logger::warning('[Hublog PushService] 未适配的 provider: ' . $provider);
                return false;
        }
    }

    /**
     * UniPush（个推 REST v2 / DCloud UniPush）
     * 文档：https://docs.getui.com/getui/server/rest_v2/push/
     */
    private static function pushUniPush(array $cids, string $title, string $content, array $payload): bool
    {
        $appId     = (string)self::cfg('push_unipush_app_id', '');
        $appKey    = (string)self::cfg('push_unipush_app_key', '');
        $appSecret = (string)self::cfg('push_unipush_app_secret', '');
        if ($appId === '' || $appKey === '' || $appSecret === '') {
            return false;
        }

        // 1. 鉴权（v2）：POST /v2/{appId}/auth
        $authBody = json_encode([
            'sign'      => hash('sha256', $appKey . time() . $appSecret),
            'timestamp' => (string)(time() * 1000),
            'appkey'    => $appKey,
        ], JSON_UNESCAPED_UNICODE);

        $authResp = self::httpJson(
            "https://restapi.getui.com/v2/{$appId}/auth",
            'POST',
            $authBody,
            ['Content-Type: application/json']
        );
        if (!$authResp || empty($authResp['data']['token'])) {
            Logger::error('[Hublog PushService] UniPush 鉴权失败: ' . json_encode($authResp, JSON_UNESCAPED_UNICODE));
            return false;
        }
        $token = $authResp['data']['token'];

        // 2. 按 CID 单推（个推 v2 单推接口上限 1000/次，此处已分块）
        $requestId = bin2hex(random_bytes(12));
        $body = [
            'request_id'   => $requestId,
            'settings'     => ['ttl' => 3600000, 'strategy' => ['default' => 1]],
            'audience'     => ['cid' => array_values($cids)],
            'push_message' => [
                'notification' => [
                    'title'        => $title,
                    'body'         => $content,
                    'click_type'   => 'payload',
                    'payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'push_channel' => [
                'android' => [
                    'ups' => [
                        'notification' => [
                            'title'      => $title,
                            'body'       => $content,
                            'click_type' => 'payload',
                            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
                'ios' => [
                    'type'     => 'notify',
                    'payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'aps'      => [
                        'alert' => ['title' => $title, 'body' => $content],
                        'sound' => 'default',
                        'badge' => '+1',
                    ],
                ],
            ],
        ];

        $resp = self::httpJson(
            "https://restapi.getui.com/v2/{$appId}/push/single/cid",
            'POST',
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json', 'token: ' . $token]
        );

        return is_array($resp) && ($resp['code'] ?? '1') === '0';
    }

    /**
     * 极光推送 JPush（REST v3）
     */
    private static function pushJPush(array $regIds, string $title, string $content, array $payload): bool
    {
        $appKey       = (string)self::cfg('push_jpush_app_key', '');
        $masterSecret = (string)self::cfg('push_jpush_master_secret', '');
        if ($appKey === '' || $masterSecret === '') {
            return false;
        }

        $body = json_encode([
            'platform'     => 'all',
            'audience'     => ['registration_id' => array_values($regIds)],
            'notification' => [
                'android' => ['alert' => $content, 'title' => $title, 'extras' => $payload],
                'ios'     => ['alert' => ['title' => $title, 'body' => $content], 'sound' => 'default', 'badge' => '+1', 'extras' => $payload],
            ],
            'options'      => ['apns_production' => true, 'time_to_live' => 3600],
        ], JSON_UNESCAPED_UNICODE);

        $resp = self::httpJson(
            'https://api.jpush.cn/v3/push',
            'POST',
            $body,
            [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($appKey . ':' . $masterSecret),
            ]
        );

        return is_array($resp) && !isset($resp['error']);
    }

    /**
     * Firebase Cloud Messaging（海外/Google 通道）
     */
    private static function pushFcm(array $tokens, string $title, string $content, array $payload): bool
    {
        $serverKey = (string)self::cfg('push_fcm_server_key', '');
        if ($serverKey === '') {
            return false;
        }

        $ok = true;
        foreach ($tokens as $token) {
            $body = json_encode([
                'to'           => $token,
                'notification' => ['title' => $title, 'body' => $content, 'sound' => 'default'],
                'data'         => self::flattenPayload($payload),
            ], JSON_UNESCAPED_UNICODE);

            $resp = self::httpJson(
                'https://fcm.googleapis.com/fcm/send',
                'POST',
                $body,
                ['Content-Type: application/json', 'Authorization: key=' . $serverKey]
            );
            if (!is_array($resp) || empty($resp['success'])) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * APNs（直连，一般不需要——iOS 走 UniPush 的 push_channel.ios 即可）
     * 这里给出最小可用实现，便于自建通道时替换证书路径。
     */
    private static function pushApns(array $tokens, string $title, string $content, array $payload): bool
    {
        // 需要 curl 的 HTTP/2 + 证书支持，未配置时静默跳过
        $certPath = (string)self::cfg('push_apns_cert_path', '');
        if ($certPath === '' || !function_exists('curl_init')) {
            return false;
        }

        $ok = true;
        foreach ($tokens as $token) {
            $body = json_encode([
                'aps' => [
                    'alert' => ['title' => $title, 'body' => $content],
                    'sound' => 'default',
                    'badge' => 1,
                ],
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => 'https://api.push.apple.com/3/device/' . $token,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSLCERT        => $certPath,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) {
                $ok = false;
                Logger::error('[Hublog PushService] APNs 推送失败 http=' . $code . ' resp=' . (string)$resp);
            }
        }
        return $ok;
    }

    /* ==================================================================
     * 工具
     * ================================================================== */

    /** 读取配置（settings 表优先，DB 不可用时降级为空→视为未启用） */
    private static function cfg(string $key, $default = null)
    {
        try {
            $v = License::getSetting($key, null);
        } catch (\Throwable $e) {
            return $default;
        }
        return ($v === null || $v === '') ? $default : $v;
    }

    /** 文本裁剪，避免厂商长度限制 */
    private static function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max) . '…';
        }
        return $text;
    }

    /** FCM data 只接受字符串，做一层摊平 */
    private static function flattenPayload(array $payload): array
    {
        $out = [];
        foreach ($payload as $k => $v) {
            $out[$k] = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $out;
    }

    /** 发起 JSON HTTP 请求（带重试，失败返回 null） */
    private static function httpJson(string $url, string $method, string $body, array $headers = [], int $retry = self::MAX_RETRY): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        for ($i = 0; $i <= $retry; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($raw !== false && $code >= 200 && $code < 300) {
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? $decoded : ['raw' => $raw];
            }
            if ($i === $retry) {
                Logger::error('[Hublog PushService] HTTP 失败 url=' . $url . ' code=' . $code . ' err=' . $err . ' body=' . substr((string)$raw, 0, 300));
            }
        }
        return null;
    }
}
