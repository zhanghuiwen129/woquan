<?php
/**
 * Copyright 2026 woquan. All rights reserved.
 * 本文件为本项目新增代码，商业使用须取得书面授权。
 *
 * 上报请求签名内核：派生密钥 + 规范化 + HMAC-SHA256（hex 小写）。
 * 服务端 remote_verify.php::rv_verify_report_signature() 必须逐字节复现本文件的公式。
 *
 * 【签名用途边界 · 安全 B-1 / 路径A】本 HMAC 仅作「数据完整性痕迹」，用于授权中心识别上报
 * 是否被篡改与统计告警；它**不是身份认证、绝不作为授权 / 配额 / entitlement 判定的输入**。
 * 原因：license_code、fingerprint 随包明文外发，seed 又随公开源码发布，密钥材料对任何人可得，
 * 因此该签名可被离线重算——它能证明"数据未被中间人改动"，但证明不了"上报方是谁"。
 * 真正防伪只能由授权中心服务端侧证据（IP / 域名白名单、速率、异常告警）提供。
 * 被检测方控制自己的客户端与源码，任何客户端侧密钥都保不住，禁止靠"换更隐蔽的常量"来修。
 */

declare(strict_types=1);

namespace Hublog\Core;

/**
 * 心跳上报签名器（客户端唯一签名出口）。
 *
 * 派生密钥：hash_hmac('sha256', licenseCode . '|' . fingerprint, REPORT_KEY_SEED)
 * 签名串  ："POST\n" . PATH . "\n" . timestamp . "\n" . nonce . "\n" . hash('sha256', canonical(fields))
 * 签名字段：固定 9 个（不含 signature 自身，也不含服务端自填的 server_ip）
 */
final class PayloadSigner
{
    /** 签名串固定的请求路径（与授权中心 remote_verify.php 一致） */
    public const PATH = '/remote_verify.php';

    /** 参与签名的 9 个字段，顺序不影响结果（规范化时 ksort） */
    private const SIGNED_FIELDS = [
        'installation_id',
        'fingerprint',
        'license_code',
        'site_host',
        'version',
        'users',
        'posts',
        'timestamp',
        'nonce',
    ];

    /**
     * 由授权码 + 硬件指纹派生本次请求的密钥。
     *
     * seed 不做保密假设（明文 PHP 无绝对安全），目标是提高剔除 / 伪造成本；
     * 客户端常量 License::REPORT_KEY_SEED 与服务端 LS_REPORT_KEY_SEED 必须逐字节一致。
     */
    public static function deriveKey(string $licenseCode, string $fingerprint): string
    {
        return hash_hmac('sha256', $licenseCode . '|' . $fingerprint, License::REPORT_KEY_SEED);
    }

    /**
     * 规范化：所有值标量字符串化 → ksort(SORT_STRING) → json_encode(不转义 Unicode / 斜杠)。
     */
    public static function canonical(array $fields): string
    {
        $normalized = [];
        foreach ($fields as $key => $value) {
            if (is_scalar($value)) {
                $normalized[(string)$key] = (string)$value;
            } else {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $normalized[(string)$key] = $encoded === false ? '' : $encoded;
            }
        }
        ksort($normalized, SORT_STRING);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }

    /**
     * 对固定 9 字段签名，返回 64 位小写 hex。
     *
     * 只取白名单字段，避免调用方多塞一个字段导致客户端 / 服务端规范化不一致。
     * 缺失字段使用确定的默认值（字符串空、整数 0），服务端按同样规则复现。
     */
    public static function sign(array $fields, string $licenseCode, string $fingerprint): string
    {
        $picked = [];
        foreach (self::SIGNED_FIELDS as $name) {
            if ($name === 'users' || $name === 'posts' || $name === 'timestamp') {
                $picked[$name] = (int)($fields[$name] ?? 0);
            } else {
                $picked[$name] = (string)($fields[$name] ?? '');
            }
        }

        $stringToSign = "POST\n"
            . self::PATH . "\n"
            . (string)$picked['timestamp'] . "\n"
            . (string)$picked['nonce'] . "\n"
            . hash('sha256', self::canonical($picked));

        return hash_hmac('sha256', $stringToSign, self::deriveKey($licenseCode, $fingerprint));
    }
}
