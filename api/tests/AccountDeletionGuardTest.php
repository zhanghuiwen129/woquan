<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * V167d 守卫：账号注销（DELETE /api/app/account）数据清理语句。
 *
 * 背景：注销接口在真实环境一触发就 500，共三处缺陷：
 *   1) follows 表列名是 follower_id/following_id，代码写成 user_id（列不存在 → SQL 异常）；
 *   2) blocked_users 表列名是 user_id/blocked_user_id，代码写成 blocked_id（同上）；
 *   3) users.email 有唯一索引 uk_email，匿名化写 email='' 会让第二次注销撞唯一键
 *      （首次注销后库里已有一条 ''；email 列可空，置 null 才对）。
 *
 * 本测试用源码 token 断言锁死这三点（与 PostVisibilityGuardTest 同手法）。
 */
final class AccountDeletionGuardTest extends TestCase
{
    private function source(): string
    {
        // SQL 已下沉 AppRepository，注销编排仍留 AppController：两处都要纳入守卫范围
        $files = [
            dirname(__DIR__) . '/controllers/AppController.php',
            dirname(__DIR__) . '/core/AppRepository.php',
        ];
        $sources = [];
        foreach ($files as $file) {
            $part = file_get_contents($file);
            $this->assertNotFalse($part, '无法读取 ' . $file);
            $sources[] = (string)$part;
        }
        $src = implode("\n", $sources);
        // 去注释，防注释里的列名造成误判/漏判
        $src = \preg_replace('/\/\*.*?\*\//s', '', $src);
        $src = \preg_replace('/\/\/[^\n]*/', '', $src);
        return (string)$src;
    }

    public function testFollowsDeleteUsesFollowerIdColumn(): void
    {
        $src = $this->source();
        $this->assertStringContainsString(
            "Database::delete('follows', 'follower_id = ? OR following_id = ?'",
            $src,
            'follows 表的删除条件必须用 follower_id/following_id（真实列名）。'
            . "写成 user_id 会触发 Unknown column 1054，注销接口 500。"
        );
        $this->assertStringNotContainsString(
            "delete('follows', 'user_id",
            $src,
            "follows 表不存在 user_id 列（V167d 前的历史错误写法）。"
        );
    }

    public function testBlockedUsersDeleteUsesBlockedUserIdColumn(): void
    {
        $src = $this->source();
        $this->assertStringContainsString(
            "Database::delete('blocked_users', 'user_id = ? OR blocked_user_id = ?'",
            $src,
            'blocked_users 表的删除条件必须用 user_id/blocked_user_id（真实列名）。'
            . '写成 blocked_id 会触发 Unknown column 1054，注销接口 500。'
        );
        $this->assertStringNotContainsString(
            "'blocked_id = ?'",
            $src,
            "blocked_users 表不存在 blocked_id 列（V167d 前的历史错误写法）。"
        );
    }

    public function testAnonymizedEmailMustBeNullNotEmptyString(): void
    {
        $src = $this->source();
        // 匿名化 update 数组里 email 必须是 null（或表达式），不能是 ''
        $this->assertStringNotContainsString(
            "'email'               => '',",
            $src,
            'users.email 有唯一索引 uk_email：注销匿名化写 email="" 会让第二次注销撞唯一键 1062。'
            . '必须置 null（列可空）。'
        );
        $this->assertMatchesRegularExpression(
            "/'email'\s+=>\s*null/",
            $src,
            '注销匿名化应把 email 置为 null。'
        );
    }

    public function testDeletionErasesDirectPiiInBothWhitelists(): void
    {
        $src = $this->source();

        if (!preg_match('/\$userUpdates\s*=\s*\[(.*?)\];/s', $src, $m)) {
            $this->fail('未找到 AppController 的 $userUpdates 匿名化数组。');
        }
        $controller = $m[1];
        // AppController 里另有一个同名 $allowed（分类参数白名单），必须锚定仓储方法再取，
        // 否则会拿出错误的数组导致误判。
        if (!preg_match('/function purgeAndAnonymizeUser.*?\$allowed\s*=\s*\[(.*?)\];/s', $src, $m2)) {
            $this->fail('未找到 AppRepository 的 $allowed 更新白名单。');
        }
        $repository = $m2[1];

        // 字段 => 注销时应写入的值。gender 在 FreshInstallSchema 中是 NOT NULL DEFAULT 0，
        // 必须置 0（未知），其余 6 个字段列均可空，置 null。
        $pii = [
            'phone'             => 'null',
            'phone_verified_at' => 'null',
            'gender'            => '0',
            'birthday'          => 'null',
            'region'            => 'null',
            'user_tags'         => 'null',
            'last_active_at'    => 'null',
        ];
        foreach ($pii as $field => $expected) {
            $quoted = preg_quote($field, '/');
            $this->assertMatchesRegularExpression(
                "/'" . $quoted . "'\s*=>\s*" . preg_quote($expected, '/') . "/",
                $controller,
                "AppController 注销必须把 {$field} 擦除为 {$expected}。"
            );
            $this->assertMatchesRegularExpression(
                "/'" . $quoted . "'/",
                $repository,
                "AppRepository 的 \$allowed 必须放行 {$field}，否则该列不会被 UPDATE 写回。"
            );
        }

        // 原 16 字段不能被这次增量挤掉
        foreach (['username', 'email', 'status', 'account_state', 'deleted_at', 'token_version', 'updated_at'] as $kept) {
            $this->assertMatchesRegularExpression(
                "/'" . $kept . "'\s*=>/",
                $controller,
                "注销匿名化原有字段 {$kept} 必须保留。"
            );
        }
    }
}
