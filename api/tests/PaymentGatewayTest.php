<?php

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\PaymentGateway;
use PHPUnit\Framework\TestCase;

/**
 * 支付网关安全测试（V167）
 *
 * 这一层代码的特殊性：它错了不会报错，只会「悄悄放行伪造回调」——用户没付钱也能开通会员。
 * 所以这里全部用**真实密钥 + 真实加解密**，不 mock、不跳过。
 *
 * 夹具 api/tests/fixtures/*.pem 是纯粹为跑测试生成的 RSA-2048 密钥对，
 * 与任何商户配置无关，随仓库公开也无害。
 *
 * 【关键知识：待签串的非对称性，勿"顺手统一"】
 *   支付宝官方 SDK 本身是两套规则：
 *     下单 sdkExecute  →  getSignContent($params)   **包含** sign_type
 *     回调 rsaCheckV2  →  先 $params['sign_type'] = null 再算，**排除** sign_type
 *   本系统 alipayAppOrder（含 sign_type）/ alipayVerify（unset sign_type）与之一致。
 *   所以「自己下单的参数喂给自己验签」结果是 false —— 这是对的，不是 bug。
 *   本文件用 testCallback* 系列把这个语义钉死：若哪天有人把两侧统一成同一套规则，
 *   线上所有支付宝异步通知会立刻全部验签失败（用户付款成功却不开通），这里会第一时间报错。
 */
final class PaymentGatewayTest extends TestCase
{
    private string $privatePem;
    private string $publicPem;
    private string $wrongPrivatePem;
    private string $wrongPublicPem;

    protected function setUp(): void
    {
        $dir = __DIR__ . '/fixtures';
        $this->privatePem      = (string)file_get_contents($dir . '/test-rsa-private.pem');
        $this->publicPem       = (string)file_get_contents($dir . '/test-rsa-public.pem');
        $this->wrongPrivatePem = (string)file_get_contents($dir . '/test-rsa-private-alt.pem');
        $this->wrongPublicPem  = (string)file_get_contents($dir . '/test-rsa-public-alt.pem');

        $check = PaymentGateway::available();
        if (!$check['ok']) {
            $this->markTestSkipped('PHP 缺少支付所需扩展：' . implode(',', $check['missing']));
        }
    }

    /**
     * 按支付宝回调语义构造一套已签名的 POST。
     *
     * 默认（$includeSignType=false）模拟支付宝真实回调：sign 与 sign_type 都不进待签串。
     */
    private function signedCallback(array $params, string $privPem = null, bool $includeSignType = false): array
    {
        $privPem ??= $this->privatePem;
        // 回调 POST 里本来就带 sign_type=RSA2；区别只在于它是否进入待签串
        $params['sign_type'] = 'RSA2';
        $data = $params;
        unset($data['sign']);
        if (!$includeSignType) {
            unset($data['sign_type']);
        }
        ksort($data);

        // 复现 buildPlainQuery 的「跳过空值」规则，保证与被测代码一致
        $parts = [];
        foreach ($data as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $parts[] = $k . '=' . $v;
        }
        $plain = implode('&', $parts);

        $ok = openssl_sign($plain, $raw, openssl_pkey_get_private($privPem), OPENSSL_ALGO_SHA256);
        self::assertTrue($ok, '测试夹具签名失败');

        $out         = $params;
        $out['sign'] = base64_encode($raw);
        return $out;
    }

    // ==================== 支付宝异步通知 ====================

    public function testCallbackVerifiesSuccessfully(): void
    {
        $post = $this->signedCallback([
            'out_trade_no' => ' VIP202610310001',
            'trade_no'     => '2026103122001234567890',
            'total_amount' => '19.90',
            'trade_status' => 'TRADE_SUCCESS',
        ]);

        $this->assertTrue(PaymentGateway::alipayVerify($post, $this->publicPem));
    }

    public function testCallbackRejectsTamperedAmount(): void
    {
        $post = $this->signedCallback([
            'out_trade_no' => 'VIP202610310002',
            'total_amount' => '19.90',
            'trade_status' => 'TRADE_SUCCESS',
        ]);
        // 中间人把 19.90 改成 0.01
        $post['total_amount'] = '0.01';

        $this->assertFalse(PaymentGateway::alipayVerify($post, $this->publicPem));
    }

    public function testCallbackRejectsTamperedTradeStatus(): void
    {
        $post = $this->signedCallback([
            'out_trade_no' => 'VIP202610310003',
            'total_amount' => '19.90',
            'trade_status' => 'WAIT_BUYER_PAY',
        ]);
        $post['trade_status'] = 'TRADE_SUCCESS';

        $this->assertFalse(PaymentGateway::alipayVerify($post, $this->publicPem));
    }

    public function testCallbackRejectsSignTypeIncludedInSigningString(): void
    {
        // 反向锁定：若有人把 sign_type 也算进待签串（"两边统一"的常见改法），
        // 真实支付宝回调将全部验签失败。这里用一个「含 sign_type 的签名」证明
        // 验签端确实把它排除在外——即两种签名结果必须不通用。
        $post = $this->signedCallback([
            'out_trade_no' => 'VIP202610310004',
            'total_amount' => '19.90',
        ], null, true);

        $this->assertFalse(
            PaymentGateway::alipayVerify($post, $this->publicPem),
            'sign_type 若被纳入待签串，验签必须失败（真实回调不含它）'
        );
    }

    public function testCallbackRejectsSignatureFromAnotherMerchant(): void
    {
        $post = $this->signedCallback(
            ['out_trade_no' => 'VIP202610310005', 'total_amount' => '19.90'],
            $this->wrongPrivatePem
        );

        $this->assertFalse(
            PaymentGateway::alipayVerify($post, $this->publicPem),
            '用他人私钥伪造的通知绝不能通过'
        );
    }

    public function testCallbackRejectsMissingSign(): void
    {
        $this->assertFalse(PaymentGateway::alipayVerify(
            ['out_trade_no' => 'X', 'total_amount' => '1.00'],
            $this->publicPem
        ));
    }

    public function testCallbackRejectsEmptyPublicKey(): void
    {
        $post = $this->signedCallback(['out_trade_no' => 'X', 'total_amount' => '1.00']);

        $this->assertFalse(
            PaymentGateway::alipayVerify($post, ''),
            '后台没配支付宝公钥时必须拒绝放行，不能因为没有密钥就默认可信'
        );
    }

    public function testCallbackRejectsMalformedPublicKey(): void
    {
        $post = $this->signedCallback(['out_trade_no' => 'X', 'total_amount' => '1.00']);

        $this->assertFalse(PaymentGateway::alipayVerify($post, '这不是一个公钥'));
    }

    public function testCallbackAcceptsRawBase64PublicKey(): void
    {
        // 后台常见粘贴方式：只粘了裸 base64，没有 PEM 头尾
        $body = (string)preg_replace(
            '/-----[^-]+-----|\s+/',
            '',
            $this->publicPem
        );
        $post = $this->signedCallback(['out_trade_no' => 'VIP202610310006', 'total_amount' => '1.00']);

        $this->assertTrue(
            PaymentGateway::alipayVerify($post, $body),
            'normalizePublicKey 必须兼容裸 base64 公钥，否则站长按直觉粘贴就永远验签失败'
        );
    }

    // ==================== 查单金额解析（P1-2：钉死字段路径） ====================

    public function testParseQueryAmountReadsDataTopLevelAmountTotal(): void
    {
        // 字段路径钉死：微信 v3 查单金额在 data['amount']['total']（int 分）。
        // 若有人误写成 $res['amount']['total']（漏掉 data 顶层）会 100% 掉单，这里第一时间报错。
        $this->assertSame(1990, PaymentGateway::parseQueryAmount(['amount' => ['total' => 1990]]));
    }

    public function testParseQueryAmountReturnsNullOnMissingOrMalformed(): void
    {
        $this->assertNull(PaymentGateway::parseQueryAmount([]), '缺字段必须返回 null（fail-safe，不猜 0）');
        $this->assertNull(
            PaymentGateway::parseQueryAmount(['amount' => ['total' => 'abc']]),
            '非数字金额必须返回 null，不得 (int) 强转成 0 后误判相等'
        );
        $this->assertNull(PaymentGateway::parseQueryAmount(['amount' => []]));
        $this->assertNull(PaymentGateway::parseQueryAmount(['amount' => null]));
    }

    public function testParseQueryAmountCastsNumericStringToInt(): void
    {
        // 微信 JSON 若把 total 给成数字字符串，也应被当作合法分金额
        $this->assertSame(1990, PaymentGateway::parseQueryAmount(['amount' => ['total' => '1990']]));
    }

    // ==================== 微信支付回调解密 ====================

    private function buildResource(array $payload, string $key, ?string $nonce = null): array
    {
        $nonce ??= 'abcdefghijkl';
        $aad   = 'transaction';
        $plain = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        self::assertNotFalse($cipher, '测试夹具加密失败');

        return [
            'ciphertext'      => base64_encode($cipher . $tag),
            'nonce'           => $nonce,
            'associated_data' => $aad,
        ];
    }

    public function testWxpayResourceDecryptRoundTrip(): void
    {
        $key = '0123456789abcdef0123456789abcdef'; // 32 字节 APIv3 密钥
        $payload = [
            'out_trade_no' => 'WX202610310001',
            'trade_state'  => 'SUCCESS',
            'amount'       => ['total' => 1990, 'currency' => 'CNY'],
        ];

        $decoded = PaymentGateway::wxpayDecryptResource($this->buildResource($payload, $key), $key);

        $this->assertIsArray($decoded);
        $this->assertSame($payload, $decoded);
    }

    public function testWxpayResourceRejectsTamperedCiphertext(): void
    {
        $key      = '0123456789abcdef0123456789abcdef';
        $resource = $this->buildResource(['out_trade_no' => 'WX2', 'amount' => ['total' => 1]], $key);

        // 翻掉密文的一个字节 —— GCM tag 必须校验失败
        $raw = base64_decode($resource['ciphertext']);
        $raw[3] = chr(ord($raw[3]) ^ 0xFF);
        $resource['ciphertext'] = base64_encode($raw);

        $this->assertNull(
            PaymentGateway::wxpayDecryptResource($resource, $key),
            '密文被篡改后必须返回 null，绝不能解出脏数据继续放款'
        );
    }

    public function testWxpayResourceRejectsWrongApiV3Key(): void
    {
        $correct = '0123456789abcdef0123456789abcdef';
        $wrong   = 'fedcba9876543210fedcba9876543210';

        $resource = $this->buildResource(['out_trade_no' => 'WX3'], $correct);

        $this->assertNull(PaymentGateway::wxpayDecryptResource($resource, $wrong));
    }

    public function testWxpayResourceRejectsBadKeyLength(): void
    {
        // 这一条断言的是**行为契约**而非某行具体实现：密钥长度不对时绝不能解出数据。
        // 注：经变异测试验证，即使把源码里 strlen($apiV3Key) !== 32 这行删掉，
        // openssl 自身也会因 key 长度不足而解密失败——那行是深度防御，不是唯一防线。
        $resource = $this->buildResource(['out_trade_no' => 'WX4'], '0123456789abcdef0123456789abcdef');
        $this->assertNull(PaymentGateway::wxpayDecryptResource($resource, 'short-key'));
        $this->assertNull(PaymentGateway::wxpayDecryptResource($resource, ''));
    }

    public function testWxpayResourceRejectsMissingFields(): void
    {
        $key = '0123456789abcdef0123456789abcdef';

        $this->assertNull(PaymentGateway::wxpayDecryptResource([], $key));
        $this->assertNull(PaymentGateway::wxpayDecryptResource(
            ['ciphertext' => 'AAAA'],
            $key
        ), '缺少 nonce 必须拒绝');
    }

    public function testWxpayResourceRejectsTooShortPayload(): void
    {
        // 只有 tag、没有真实密文 —— 长度保护。
        // 同样是行为契约：这类畸形输入绝不能解出可被下游使用的数组。
        $this->assertNull(PaymentGateway::wxpayDecryptResource(
            ['ciphertext' => base64_encode(str_repeat('x', 16)), 'nonce' => 'abcdefghijkl'],
            '0123456789abcdef0123456789abcdef'
        ));
    }

    public function testWxpayResourceRejectsNonJsonPlaintext(): void
    {
        $key   = '0123456789abcdef0123456789abcdef';
        $nonce = 'abcdefghijkl';
        $aad   = 'transaction';

        $tag = '';
        $cipher = openssl_encrypt('这不是 JSON', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

        $this->assertNull(PaymentGateway::wxpayDecryptResource([
            'ciphertext'      => base64_encode($cipher . $tag),
            'nonce'           => $nonce,
            'associated_data' => $aad,
        ], $key));
    }
}
