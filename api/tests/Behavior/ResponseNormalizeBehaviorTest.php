<?php
/**
 * 行为级测试：Response 统一出口（媒体路径转绝对 URL + 默认头像补全）
 *
 * 这层在 api/core/Response.php 的 toJson() 里，是所有接口的「最后一道出口」。
 * 它被重构过（从两次全树遍历 + 每个字符串 json_decode 试探，
 * 改成单次遍历 + 首字符快速判定），所以这里用「新旧实现跑同一批输入、比对输出」
 * 的方式锁死行为，避免性能优化悄悄改变语义。
 *
 * 新旧实现都在本文件里复刻一份（old* 前缀），不依赖被测类的私有方法。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\MediaHelper;
use Hublog\Core\Response;

final class ResponseNormalizeBehaviorTest extends BehaviorTestCase
{
    // ── 旧实现复刻（重构前 Response.php 的逻辑） ──────────────────────────

    private static function oldNormalizeMediaPaths(mixed $value): mixed
    {
        if (is_string($value)) {
            if (preg_match('#^(https?://|data:|//)#i', $value)) {
                return $value;
            }
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return self::oldNormalizeMediaPaths($decoded);
            }
            if (preg_match('#^\/?(uploads|assets|templates)\/#', $value)) {
                return MediaHelper::absoluteUrl($value);
            }
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::oldNormalizeMediaPaths($v);
            }
        }
        return $value;
    }

    private static function oldFillDefaultAvatar(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if ($k === 'avatar' && ($v === null || (is_string($v) && trim($v) === ''))) {
                    $value[$k] = MediaHelper::absoluteUrl('assets/images/default-avatar.svg');
                    continue;
                }
                $value[$k] = self::oldFillDefaultAvatar($v);
            }
        }
        return $value;
    }

    private static function oldPipeline(mixed $data): mixed
    {
        return self::oldNormalizeMediaPaths(self::oldFillDefaultAvatar($data));
    }

    // ── 新实现：走真实的 Response::toJson() ────────────────────────────────

    private function newPipeline(mixed $data): mixed
    {
        $json = (new Response(200, 'success', $data))->toJson();
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'toJson 输出必须是合法 JSON');
        return $decoded['data'];
    }

    /**
     * 核心用例：在「真实代码会产生的形态」上，新旧实现必须产出完全一致的结果。
     *
     * 真实形态的依据：
     *   - images / videos / attachments：PostFormatter 已 json_decode 成 PHP 数组；
     *     ShopController 的商品 images 才是 JSON 字符串 → 两种都要覆盖；
     *   - tags：PostRepository / PostFormatter 产出的是 PHP 数组，不是 JSON 字符串；
     *   - avatar / cover：普通字符串路径。
     */
    public function testNewImplementationMatchesOldOutputExactly(): void
    {
        $cases = [
            // 站点根相对媒体路径
            ['avatar' => '/uploads/2026/a.png', 'cover' => '/templates/moments/assets/x.jpg'],
            // 裸相对路径（历史数据）
            ['avatar' => 'assets/images/old.png', 'cover' => 'uploads/legacy/b.jpg'],
            // 已是绝对 URL —— 必须原样返回，不能二次加工
            ['img' => 'https://cdn.example.com/a.png', 'b' => 'http://x.com/b.png'],
            // data URI
            ['inline' => 'data:image/png;base64,iVBORw0KGgo='],
            // 协议相对地址
            ['proto' => '//cdn.example.com/c.png'],
            // 大写协议（旧实现正则带 /i）
            ['upper' => 'HTTPS://CDN.EXAMPLE.COM/D.PNG'],
            // JSON 字符串形态的媒体字段（ShopController 商品图 / 未走 PostFormatter 的接口）
            ['images' => json_encode(['/uploads/1.jpg', '/uploads/2.jpg'], JSON_UNESCAPED_UNICODE)],
            ['videos' => json_encode([], JSON_UNESCAPED_UNICODE)],
            ['attachments' => json_encode([['url' => '/uploads/a.pdf']], JSON_UNESCAPED_UNICODE)],
            // PHP 数组形态的媒体字段（PostFormatter 的真实产物）
            ['images' => ['/uploads/1.jpg', '/uploads/2.jpg']],
            ['videos' => [['url' => '/uploads/v.mp4', 'cover' => '/uploads/vc.jpg']]],
            ['tags' => ['生活', '旅行']],
            // 空 avatar（需要补默认头像）
            ['avatar' => null],
            ['avatar' => ''],
            ['avatar' => '   '],
            // 普通文本字段（不应被当成媒体路径）
            ['content' => '今天天气不错，去公园散步拍了很多照片。'],
            ['nickname' => '用户123'],
            ['created_at' => '2026-11-12 10:00:00'],
            // 数字与布尔
            ['id' => 123, 'ok' => true, 'score' => 1.5],
            // 嵌套结构
            ['user' => ['avatar' => null, 'profile' => ['avatar' => '/uploads/n.png']]],
        ];

        foreach ($cases as $i => $case) {
            $this->assertSame(
                self::oldPipeline($case),
                $this->newPipeline($case),
                "用例 #{$i} 新旧实现输出不一致：" . json_encode($case, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * 白名单带来的行为变更（这是修复，不是回归）：
     * 非媒体白名单字段里的 JSON 文本不再被解包改写。
     *
     * 旧实现对每个字符串都 json_decode 试探，用户正文只要恰好是合法 JSON 文本
     * （比如动态里贴了段 JSON 代码），内容就会被解包成数组改写 —— 真实的内容损坏 bug。
     * 现在按字段名白名单判断，只有 images / videos / attachments 才解包。
     */
    public function testJsonTextInNonMediaFieldIsNotUnwrapped(): void
    {
        $jsonText = '{"type":"note","items":[1,2,3]}';

        $new = $this->newPipeline(['content' => $jsonText]);

        $this->assertSame(
            $jsonText,
            $new['content'],
            '正文里的 JSON 文本必须原样返回，不能被解包改写'
        );
    }

    /**
     * 反过来：媒体白名单字段里的 JSON 仍然必须被解包并转换路径。
     */
    public function testJsonInMediaFieldIsStillUnwrapped(): void
    {
        $new = $this->newPipeline([
            'images' => json_encode(['/uploads/1.jpg'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertIsArray($new['images'], '媒体白名单字段的 JSON 必须被解包');
        $this->assertMatchesRegularExpression('#^https?://#', $new['images'][0], '解包后内部路径要转绝对 URL');
    }

    /**
     * JSON 字符串内部的 avatar 不补默认头像 —— 与旧实现语义一致。
     * （旧实现先跑 fillDefaultAvatar（只看数组，不解码 JSON 字符串），
     *   再跑 normalizeMediaPaths（会解码 JSON 字符串），所以 JSON 内部本来就不补。）
     *
     * 这里用媒体白名单字段（attachments）来验证「解包后内部不补 avatar」这条语义。
     */
    public function testAvatarInsideJsonStringIsNotFilled(): void
    {
        $case = ['attachments' => json_encode(['avatar' => null, 'url' => '/uploads/x.jpg'])];

        $old = self::oldPipeline($case);
        $new = $this->newPipeline($case);

        $this->assertSame($old, $new, 'JSON 字符串内的 avatar 处理必须与旧实现一致');
        // 注意：这里不能用 ?? 判断存在性 —— ?? 把 null 当作「不存在」。
        // 必须用 array_key_exists 区分「键不存在」和「键存在但值为 null」。
        $this->assertArrayHasKey('avatar', $new['attachments']);
        $this->assertNull($new['attachments']['avatar'], 'JSON 字符串内部的 avatar 不应被补默认头像');
    }

    /**
     * 站点根相对路径必须被转成绝对 URL（这是这层兜底的核心目的）。
     */
    public function testRelativeMediaPathBecomesAbsoluteUrl(): void
    {
        $new = $this->newPipeline(['avatar' => '/uploads/2026/a.png']);
        $this->assertIsString($new['avatar']);
        $this->assertMatchesRegularExpression('#^https?://#', $new['avatar'], '相对路径应转成绝对 URL');
        $this->assertStringContainsString('/uploads/2026/a.png', $new['avatar']);
    }

    /**
     * 绝对 URL 必须原样返回（不能被二次加工）。
     */
    public function testAbsoluteUrlIsLeftUntouched(): void
    {
        $url = 'https://cdn.example.com/path/a.png';
        $new = $this->newPipeline(['img' => $url]);
        $this->assertSame($url, $new['img']);
    }

    /**
     * 空 avatar 必须补默认头像绝对 URL。
     */
    public function testEmptyAvatarGetsDefaultAbsoluteUrl(): void
    {
        $new = $this->newPipeline(['avatar' => null]);
        $this->assertIsString($new['avatar']);
        $this->assertMatchesRegularExpression('#^https?://#', $new['avatar']);
        $this->assertStringContainsString('default-avatar.svg', $new['avatar']);
    }

    /**
     * 深层嵌套的 avatar 也要能补到（递归深度不能只处理一层）。
     */
    public function testDeeplyNestedAvatarIsFilled(): void
    {
        $new = $this->newPipeline([
            'a' => ['b' => ['c' => ['d' => ['avatar' => null]]]],
        ]);
        $this->assertIsString($new['a']['b']['c']['d']['avatar']);
        $this->assertStringContainsString('default-avatar.svg', $new['a']['b']['c']['d']['avatar']);
    }

    /**
     * 数字、布尔、null 等非字符串类型必须原样透传，不能被误处理。
     */
    public function testNonStringScalarsPassThroughUntouched(): void
    {
        $new = $this->newPipeline(['id' => 42, 'ok' => true, 'none' => null, 'f' => 1.25]);
        $this->assertSame(42, $new['id']);
        $this->assertTrue($new['ok']);
        $this->assertNull($new['none']);
        $this->assertSame(1.25, $new['f']);
    }

    /**
     * 响应外层结构（code / message / data）必须保持原样。
     */
    public function testEnvelopeStructureIsPreserved(): void
    {
        $json = (new Response(201, '创建成功', ['id' => 9]))->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame(201, $decoded['code']);
        $this->assertSame('创建成功', $decoded['message']);
        $this->assertSame(['id' => 9], $decoded['data']);
    }

    public function testGetCodeReturnsHttpStatus(): void
    {
        $this->assertSame(404, (new Response(404, '未找到'))->getCode());
    }
}
