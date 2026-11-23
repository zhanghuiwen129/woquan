<?php
declare(strict_types=1);

/**
 * Hublog 邮件发送类
 * 零依赖，支持 PHP mail() 和 SMTP（TLS/SSL），HTML 邮件模板
 */

namespace Hublog\Core;

class Mailer
{
    /**
     * 读取邮件配置（从 settings 表，带缓存）
     * 与 Security.php 使用相同的缓存模式
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
     * 发送邮件（支持 SMTP 和 PHP mail()）
     *
     * @param string $to      收件人邮箱
     * @param string $subject 邮件主题
     * @param string $body    邮件正文
     * @param bool   $isHtml  是否 HTML 格式
     */
    public static function send(string $to, string $subject, string $body, bool $isHtml = true): bool
    {
        $settings = self::getSettings();

        // mail_enabled 为 '0' 或 mail_host 为空时，回退到 PHP mail()
        $useSmtp = ($settings['mail_enabled'] ?? '0') === '1'
            && !empty($settings['mail_host']);

        if ($useSmtp) {
            return self::sendWithSmtp($to, $subject, $body, $isHtml, $settings);
        }

        return self::sendWithMail($to, $subject, $body, $isHtml, $settings);
    }

    /**
     * 发送验证码邮件
     *
     * @param string $to   收件人邮箱
     * @param string $code 验证码
     * @param string $type 验证类型（register/login/change_email）
     */
    public static function sendVerificationCode(string $to, string $code, string $type = 'register'): bool
    {
        $typeNames = [
            'register' => '注册',
            'login' => '登录',
            'change_email' => '更换邮箱',
        ];
        $typeName = $typeNames[$type] ?? '验证';

        $subject = "【{$typeName}验证码】您的验证码是 {$code}";
        $html = self::renderVerificationCodeTemplate($code, $typeName);

        return self::send($to, $subject, $html, true);
    }

    /**
     * 发送密码重置邮件
     *
     * @param string $to        收件人邮箱
     * @param string $resetLink 重置链接
     */
    public static function sendPasswordReset(string $to, string $resetLink): bool
    {
        $subject = '【密码重置】请重置您的账户密码';
        $html = self::renderPasswordResetTemplate($resetLink);

        return self::send($to, $subject, $html, true);
    }

    // ----------------------------------------------------------------------
    // SMTP 发送
    // ----------------------------------------------------------------------

    /**
     * 通过 SMTP 发送邮件（使用 stream_socket_client，无外部依赖）
     */
    private static function sendWithSmtp(string $to, string $subject, string $body, bool $isHtml, array $settings): bool
    {
        $host = (string)($settings['mail_host'] ?? '');
        $port = (int)($settings['mail_port'] ?? 587);
        $username = (string)($settings['mail_username'] ?? '');
        $password = (string)($settings['mail_password'] ?? '');
        $encryption = strtolower((string)($settings['mail_encryption'] ?? 'tls'));
        $fromAddress = (string)($settings['mail_from_address'] ?? $username);
        $fromName = (string)($settings['mail_from_name'] ?? (defined('APP_NAME') ? APP_NAME : '我圈'));
        $timeout = 30;

        if ($fromAddress === '') {
            $fromAddress = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        // 构建连接地址：SSL 直接加密连接，TLS/none 使用明文连接后升级
        if ($encryption === 'ssl') {
            $remote = "ssl://{$host}:{$port}";
        } else {
            $remote = "tcp://{$host}:{$port}";
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ]
        ]);

        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            Logger::error("[Hublog Mailer] SMTP 连接失败: {$errstr} ({$errno})");
            return false;
        }

        stream_set_timeout($fp, $timeout);

        try {
            // 读取服务器问候（220）
            $greeting = self::smtpRead($fp);
            if (self::smtpCode($greeting) !== 220) {
                Logger::error("[Hublog Mailer] SMTP 问候异常: {$greeting}");
                return false;
            }

            // EHLO
            $hostname = $_SERVER['SERVER_NAME'] ?? 'hublog.local';
            if (self::smtpCommand($fp, "EHLO {$hostname}", 250) === null) {
                // 某些服务器仅支持 HELO，尝试降级
                if (self::smtpCommand($fp, "HELO {$hostname}", 250) === null) {
                    return false;
                }
            }

            // STARTTLS 升级
            if ($encryption === 'tls') {
                if (self::smtpCommand($fp, 'STARTTLS', 220) === null) {
                    return false;
                }
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    Logger::error('[Hublog Mailer] TLS 握手失败');
                    return false;
                }
                // TLS 后重新 EHLO
                if (self::smtpCommand($fp, "EHLO {$hostname}", 250) === null) {
                    return false;
                }
            }

            // AUTH LOGIN 认证
            if ($username !== '' && $password !== '') {
                if (self::smtpCommand($fp, 'AUTH LOGIN', 334) === null) {
                    return false;
                }
                if (self::smtpCommand($fp, base64_encode($username), 334, 'AUTH USER ***') === null) {
                    return false;
                }
                if (self::smtpCommand($fp, base64_encode($password), 235, 'AUTH PASSWORD ***') === null) {
                    Logger::error('[Hublog Mailer] SMTP 认证失败');
                    return false;
                }
            }

            // MAIL FROM
            if (self::smtpCommand($fp, "MAIL FROM:<{$fromAddress}>", 250) === null) {
                return false;
            }

            // RCPT TO（收件人地址脱敏后再进日志）
            if (self::smtpCommand($fp, "RCPT TO:<{$to}>", 250, 'RCPT TO:<' . Security::maskEmail($to) . '>') === null) {
                return false;
            }

            // DATA
            if (self::smtpCommand($fp, 'DATA', 354) === null) {
                return false;
            }

            // 构建并发送邮件内容
            $email = self::buildEmailContent($to, $fromAddress, $fromName, $subject, $body, $isHtml);
            fputs($fp, $email . "\r\n.\r\n");

            $dataResp = self::smtpRead($fp);
            if (self::smtpCode($dataResp) !== 250) {
                Logger::error("[Hublog Mailer] SMTP DATA 发送失败: {$dataResp}");
                return false;
            }

            // QUIT
            self::smtpCommand($fp, 'QUIT', 221);

            return true;
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    // ----------------------------------------------------------------------
    // PHP mail() 发送
    // ----------------------------------------------------------------------

    /**
     * 通过 PHP mail() 函数发送邮件（回退方案）
     */
    private static function sendWithMail(string $to, string $subject, string $body, bool $isHtml, array $settings): bool
    {
        $fromAddress = (string)($settings['mail_from_address'] ?? '');
        $fromName = (string)($settings['mail_from_name'] ?? (defined('APP_NAME') ? APP_NAME : '我圈'));

        if ($fromAddress === '') {
            $fromAddress = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        $encodedSubject = self::encodeHeader($subject);
        $headers = self::buildMailHeaders($fromAddress, $fromName, $isHtml);
        $encodedBody = chunk_split(base64_encode($body));

        // 设置信封发件人，防止邮件被标记为垃圾邮件
        $additionalParams = '-f ' . escapeshellarg($fromAddress);

        $result = @mail($to, $encodedSubject, $encodedBody, $headers, $additionalParams);
        if (!$result) {
            Logger::error('[Hublog Mailer] mail() 发送失败: to=' . Security::maskEmail($to) . ', subject=' . mb_substr($subject, 0, 80));
        }
        return $result;
    }

    // ----------------------------------------------------------------------
    // SMTP 协议辅助方法
    // ----------------------------------------------------------------------

    /**
     * 读取 SMTP 响应（支持多行续行）
     */
    private static function smtpRead($fp): string
    {
        $response = '';
        while ($line = fgets($fp, 515)) {
            $response .= $line;
            // 第 4 个字符为空格表示最后一行，'-' 表示还有后续行
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }

    /**
     * 提取 SMTP 响应状态码
     */
    private static function smtpCode(string $response): int
    {
        return (int)substr(trim($response), 0, 3);
    }

    /**
     * 发送 SMTP 命令并检查预期状态码
     *
     * @param resource $fp           socket 连接
     * @param string   $command      SMTP 命令
     * @param int      $expectedCode 预期状态码
     * @param string|null $logLabel  失败日志里替代 $command 的脱敏标签（AUTH 密码等敏感命令必传）
     * @return string|null 响应文本，失败返回 null
     */
    private static function smtpCommand($fp, string $command, int $expectedCode, ?string $logLabel = null): ?string
    {
        fputs($fp, $command . "\r\n");
        $response = self::smtpRead($fp);
        $code = self::smtpCode($response);
        if ($code !== $expectedCode) {
            Logger::error('[Hublog Mailer] SMTP 命令失败: ' . ($logLabel ?? $command) . ' -> ' . $response);
            return null;
        }
        return $response;
    }

    // ----------------------------------------------------------------------
    // MIME 头与编码辅助方法
    // ----------------------------------------------------------------------

    /**
     * 构建完整邮件内容（用于 SMTP DATA，包含所有头和正文）
     */
    private static function buildEmailContent(string $to, string $fromAddress, string $fromName, string $subject, string $body, bool $isHtml): string
    {
        $encodedFrom = self::encodeHeader($fromName);
        $encodedSubject = self::encodeHeader($subject);
        $contentType = $isHtml ? 'text/html' : 'text/plain';
        $messageId = '<' . uniqid('hublog_') . '@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>';

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            "From: \"{$encodedFrom}\" <{$fromAddress}>",
            "To: {$to}",
            "Subject: {$encodedSubject}",
            "Message-ID: {$messageId}",
            'MIME-Version: 1.0',
            "Content-Type: {$contentType}; charset=UTF-8",
            'Content-Transfer-Encoding: base64',
            'X-Mailer: Hublog Mailer',
        ];

        $encodedBody = chunk_split(base64_encode($body));

        return implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
    }

    /**
     * 构建 mail() 附加头（不含 Subject 和 To，它们作为 mail() 参数传递）
     */
    private static function buildMailHeaders(string $fromAddress, string $fromName, bool $isHtml): string
    {
        $encodedFrom = self::encodeHeader($fromName);
        $contentType = $isHtml ? 'text/html' : 'text/plain';

        $headers = [
            "From: \"{$encodedFrom}\" <{$fromAddress}>",
            "Reply-To: {$fromAddress}",
            'MIME-Version: 1.0',
            "Content-Type: {$contentType}; charset=UTF-8",
            'Content-Transfer-Encoding: base64',
            'X-Mailer: Hublog Mailer',
        ];

        return implode("\r\n", $headers);
    }

    /**
     * 编码邮件头（仅对非 ASCII 字符进行 MIME 编码）
     */
    private static function encodeHeader(string $text): string
    {
        if (mb_detect_encoding($text, 'ASCII', true)) {
            return $text;
        }
        return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
    }

    // ----------------------------------------------------------------------
    // 邮件模板
    // ----------------------------------------------------------------------

    /**
     * 验证码邮件 HTML 模板
     */
    private static function renderVerificationCodeTemplate(string $code, string $typeName): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f7;padding:32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
          <tr>
            <td style="padding:32px 40px 8px;">
              <h1 style="margin:0;font-size:20px;font-weight:600;color:#1a1a1a;">{$typeName}验证码</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 40px 16px;">
              <p style="margin:0;font-size:14px;color:#6b7280;line-height:1.6;">您正在进行{$typeName}操作，验证码为：</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 40px 24px;">
              <div style="background-color:#f0f4ff;border:1px dashed #c7d2fe;border-radius:6px;padding:20px;text-align:center;">
                <span style="font-size:32px;font-weight:700;letter-spacing:6px;color:#4f46e5;">{$code}</span>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:0 40px 32px;">
              <p style="margin:0;font-size:13px;color:#9ca3af;line-height:1.6;">验证码有效期为 10 分钟，请尽快使用。如果不是您本人操作，请忽略此邮件。</p>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 40px;background-color:#f9fafb;border-top:1px solid #f3f4f6;">
              <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;">此邮件由系统自动发送，请勿回复</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * 密码重置邮件 HTML 模板
     */
    private static function renderPasswordResetTemplate(string $resetLink): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f7;padding:32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
          <tr>
            <td style="padding:32px 40px 8px;">
              <h1 style="margin:0;font-size:20px;font-weight:600;color:#1a1a1a;">重置密码</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 40px 24px;">
              <p style="margin:0 0 16px;font-size:14px;color:#6b7280;line-height:1.6;">我们收到了您的密码重置请求，请点击下方按钮重置密码：</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 40px 24px;" align="center">
              <a href="{$resetLink}" style="display:inline-block;padding:12px 32px;background-color:#4f46e5;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:6px;">重置密码</a>
            </td>
          </tr>
          <tr>
            <td style="padding:0 40px 32px;">
              <p style="margin:0 0 8px;font-size:13px;color:#9ca3af;line-height:1.6;">如果按钮无法点击，请复制以下链接到浏览器打开：</p>
              <p style="margin:0;word-break:break-all;font-size:12px;color:#6b7280;line-height:1.6;">{$resetLink}</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 40px 32px;">
              <p style="margin:0;font-size:13px;color:#9ca3af;line-height:1.6;">该链接有效期为 30 分钟。如果不是您本人操作，请忽略此邮件，您的密码不会受到影响。</p>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 40px;background-color:#f9fafb;border-top:1px solid #f3f4f6;">
              <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;">此邮件由系统自动发送，请勿回复</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
