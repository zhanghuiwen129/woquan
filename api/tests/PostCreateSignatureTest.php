<?php

declare(strict_types=1);

namespace Tests;

use Hublog\Controllers\PostController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * V167c 守卫：buildPostData() 可空参数签名。
 *
 * 背景：V160 拆分（标注「行为零变化」）把 locationLat/locationLng 从内联变量
 * 收紧为非空 float 参数，而 Request::getFloat() 返回 ?float（未传位置时为 null），
 * 导致「不带位置发动态」必然 TypeError（生产 500）。
 *
 * 本测试用反射锁死两个事实：
 * 1. 所有来自用户可选输入的参数必须允许 null（可空类型）；
 * 2. 参数名与顺序与 create 里的位置实参一一对应（调用点用位置传参，重排即炸）。
 */
final class PostCreateSignatureTest extends TestCase
{
    public function testOptionalInputParamsMustBeNullable(): void
    {
        $m = new ReflectionMethod(PostController::class, 'buildPostData');

        $expectedNullable = ['locationLat', 'locationLng', 'link', 'scheduledAt'];
        foreach ($expectedNullable as $name) {
            $p = $this->param($m, $name);
            $this->assertNotNull($p, "buildPostData 缺少参数 {$name}");
            $this->assertTrue(
                $p->getType()->allowsNull(),
                "buildPostData::{$name} 必须可空（来自 Request 可选输入，null 是合法值）。" .
                '若改回非空类型会复现 V167c 生产 500：不带位置发动态 TypeError。'
            );
        }
    }

    public function testParamOrderMatchesPositionalCallSite(): void
    {
        $m = new ReflectionMethod(PostController::class, 'buildPostData');

        $expected = [
            'request', 'userId', 'contentText', 'images', 'videos', 'attachments',
            'location', 'locationLat', 'locationLng', 'isAnonymous', 'link',
            'visibility', 'postType', 'declaration', 'isScheduled', 'needReview',
            'scheduledAt', 'categoryId', 'title', 'circleId',
        ];
        $actual = array_map(
            static fn ($p) => $p->getName(),
            $m->getParameters()
        );
        $this->assertSame(
            $expected,
            $actual,
            'buildPostData 参数顺序被改动——create 调用点（PostController::create）使用位置传参，'
            . '重排/增删参数会导致静默错位。同步修改调用点后才能改此处断言。'
        );
    }

    private function param(ReflectionMethod $m, string $name): ?\ReflectionParameter
    {
        foreach ($m->getParameters() as $p) {
            if ($p->getName() === $name) {
                return $p;
            }
        }
        return null;
    }
}
