<?php

declare(strict_types=1);

namespace Tests;

use Hublog\Core\Validator;
use PHPUnit\Framework\TestCase;

/**
 * V168 守卫：Validator::required() 遇缺失字段不能触发 PHP warning。
 *
 * 现场：POST /api/auth/register 缺 email 时，响应体里混进了
 *   `<b>Warning</b>: Undefined array key "email" in Validator.php on line 22`
 *   然后才是 JSON —— 客户端 json_decode 直接失败（哪怕业务上是正常的 422）。
 * 根因：required() 直接下标访问 $this->data[$field]，而同类方法都用 isset/!empty 保护。
 */
final class ValidatorMissingKeyTest extends TestCase
{
    public function testRequiredOnMissingKeyEmitsNoWarning(): void
    {
        $caught = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
            $caught[] = $errstr;
            return true; // 吞掉，避免污染 PHPUnit 输出
        });
        try {
            $v = new Validator([]);
            $v->required('email')->required('password');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $caught, '缺失字段时 Validator 不应产生任何 PHP warning');
    }

    public function testRequiredStillReportsMissingFields(): void
    {
        $v = new Validator(['zero' => '0']);
        $v->required('email')->required('zero');

        $errors = $v->getErrors();
        $this->assertArrayHasKey('email', $errors, '缺失的 email 必须被记为校验错误');
        $this->assertArrayNotHasKey('zero', $errors, "字符串 '0' 是有效值，不能当成空");
    }
}
