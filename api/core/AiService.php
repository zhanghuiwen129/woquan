<?php
/**
 * Hublog AI 服务抽象层
 * 兼容 OpenAI Chat Completions 协议，支持 DeepSeek / 智谱 / Moonshot / Ollama 等
 */

namespace Hublog\Core;

class AiService
{
    /**
     * 调用 OpenAI 兼容的 chat completions 接口
     *
     * @param string $systemPrompt 系统提示词
     * @param string $userMessage   用户消息（动态内容等）
     * @param array  $opts          覆盖配置：api_url / api_key / model / timeout / max_tokens / temperature
     * @return string|null 成功返回模型回复文本，失败返回 null
     */
    public static function chatCompletion(string $systemPrompt, string $userMessage, array $opts = []): ?string
    {
        $apiUrl = $opts['api_url'] ?? '';
        $apiKey = $opts['api_key'] ?? '';
        $model = $opts['model'] ?? 'deepseek-chat';
        $timeout = (int)($opts['timeout'] ?? 15);
        $maxTokens = (int)($opts['max_tokens'] ?? 300);
        $temperature = isset($opts['temperature']) ? (float)$opts['temperature'] : 0.8;

        if ($apiUrl === '') {
            Logger::error('[Hublog AI] 缺少 api_url');
            return null;
        }

        if (!function_exists('curl_init')) {
            Logger::error('[Hublog AI] 服务器未启用 cURL 扩展');
            return null;
        }

        // P2-2：只看 host，不能对整条 URL 做子串匹配。
        // 原实现会让 https://attacker.tld/ollama 被判为本地 → 关闭 TLS 校验并放行 http，
        // api_key 在传输中可被 MITM 窃取。端口白名单仍由 validateOutboundUrl 的 localAiPorts 决定。
        $host = (string)parse_url($apiUrl, PHP_URL_HOST);
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
                || (bool)preg_match('/^127\.\d+\.\d+\.\d+$/', $host);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage]
            ],
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'stream' => false
        ];

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        // 本地 Ollama 等可免 Key；云端服务仍需传入 Key
        if (!$isLocal || $apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        // ===== SSRF 防护 =====
        // AI API 允许本地回环 + 常见 AI 端口（Ollama/11434 等），其余一律禁止内网、云元数据、危险协议/端口
        $ssrfChk = Security::validateOutboundUrl($apiUrl, [
            'allowHttp'    => $isLocal,
            'allowLocalAi' => $isLocal,
        ]);
        if (!$ssrfChk['ok']) {
            Logger::error(sprintf('[Hublog AI] SSRF 拒绝：%s (URL=%s)', $ssrfChk['reason'] ?? 'unknown', $apiUrl));
            throw new \RuntimeException('目标 AI API 地址被 SSRF 防护拦截：' . ($ssrfChk['reason'] ?? '校验失败'));
        }

        $ch = curl_init($apiUrl);
        Security::applyCurlSsrfOptions($ch, $isLocal);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_SSL_VERIFYPEER => !$isLocal,
            CURLOPT_SSL_VERIFYHOST => $isLocal ? 0 : 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error(sprintf('[Hublog AI] cURL 错误: %s (HTTP %s)', $error, $httpCode));
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            Logger::error(sprintf('[Hublog AI] 接口返回非 2xx: HTTP %s, body=%s', $httpCode, substr((string)$response, 0, 500)));
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['choices'][0]['message']['content'])) {
            Logger::error('[Hublog AI] 响应格式异常: ' . substr((string)$response, 0, 500));
            return null;
        }

        $content = (string)$data['choices'][0]['message']['content'];
        // 去掉模型常见的多余引号包裹
        $content = trim($content);
        if ($content !== '' && $content[0] === '"' && substr($content, -1) === '"') {
            $content = substr($content, 1, -1);
        }
        return $content;
    }

    /**
     * 从 settings 表读取 AI 配置（带缓存）
     */
    public static function getConfig(): array
    {
        return Cache::remember('ai_comment_settings', function () {
            $rows = Database::fetchAll(
                "SELECT `key`, `value` FROM settings WHERE `key` LIKE 'ai_comment_%'"
            );
            $cfg = [];
            foreach ($rows as $row) {
                $cfg[$row['key']] = $row['value'];
            }
            return $cfg;
        }, 3600);
    }

    /**
     * 清除 AI 配置缓存（后台修改设置后调用）
     */
    public static function clearConfigCache(): void
    {
        Cache::delete('ai_comment_settings');
    }

    /**
     * 获取 AI 用户的 ID（查找 ai_assistant 虚拟用户）
     */
    public static function getAiUserId(): int
    {
        $id = Database::fetchColumn(
            "SELECT id FROM users WHERE username = 'ai_assistant' AND status = 1 LIMIT 1"
        );
        return (int)($id ?: 0);
    }
}
