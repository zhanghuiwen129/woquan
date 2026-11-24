<?php
/**
 * P0-R1 回归护栏：sitemap 只能收录对游客可见的动态与公开可搜索的用户。
 *
 * 历史缺陷：Sitemap::urls() 用 `WHERE status = 1` 拉取全部帖子与用户，
 * private/circle/friend 帖的 /p/{id}、privacy_home=private 或 allow_search=0
 * 用户的 /u/{username} 被公开列出。虽然详情页/主页仍有二次 404（不泄露内容），
 * 但存在性、URL 与 lastmod 已被枚举并可能被搜索引擎收录。
 *
 * 本测试在 SQLite 内存库真实调用 Sitemap::urls()，断言过滤口径与
 * VisibilityPolicy 的游客口径一致，同时校验 XML 的 lastmod 逻辑未变。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Sitemap;

final class SitemapPrivacyBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec(<<<'SQL'
ALTER TABLE `users` ADD COLUMN `privacy_home` TEXT NOT NULL DEFAULT 'public';
ALTER TABLE `users` ADD COLUMN `allow_search` INTEGER NOT NULL DEFAULT 1;
CREATE TABLE `posts` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`     INTEGER NOT NULL DEFAULT 0,
    `circle_id`   INTEGER DEFAULT NULL,
    `visibility`  TEXT NOT NULL DEFAULT 'public',
    `status`      INTEGER NOT NULL DEFAULT 1,
    `created_at`  TEXT DEFAULT NULL,
    `updated_at`  TEXT DEFAULT NULL
);
CREATE TABLE `categories` (
    `id`     INTEGER PRIMARY KEY,
    `status` INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `topics` (
    `id`         INTEGER PRIMARY KEY,
    `name`       TEXT DEFAULT NULL,
    `status`     INTEGER NOT NULL DEFAULT 1,
    `post_count` INTEGER NOT NULL DEFAULT 0,
    `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `post_tags` (
    `id`      INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `tag`     TEXT DEFAULT NULL
);
SQL);

        $users = [
            ['username' => 'alice', 'status' => 1, 'privacy_home' => 'public', 'allow_search' => 1],
            ['username' => 'bob', 'status' => 1, 'privacy_home' => 'private', 'allow_search' => 1],
            ['username' => 'carol', 'status' => 1, 'privacy_home' => 'public', 'allow_search' => 0],
            ['username' => 'dave', 'status' => 0, 'privacy_home' => 'public', 'allow_search' => 1],
        ];
        foreach ($users as $row) {
            $this->seed('users', $row);
        }

        $posts = [
            ['id' => 1, 'visibility' => 'public', 'status' => 1, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 10:00:00'],
            ['id' => 2, 'visibility' => 'private', 'status' => 1, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 10:00:00'],
            ['id' => 3, 'visibility' => 'friend', 'status' => 1, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 10:00:00'],
            ['id' => 4, 'visibility' => 'circle', 'status' => 1, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 10:00:00'],
            ['id' => 5, 'visibility' => 'public', 'status' => 0, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 10:00:00'],
            ['id' => 6, 'visibility' => 'weird', 'status' => 1, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 10:00:00'],
        ];
        foreach ($posts as $row) {
            $this->seed('posts', $row);
        }
    }

    public function testSitemapExcludesNonPublicPostsAndPrivateUsers(): void
    {
        $urls = Sitemap::urls(['site_url' => 'https://sitemap.test']);
        $locs = array_column($urls, 'loc');

        $this->assertContains('https://sitemap.test/', $locs, '首页仍应收录');
        $this->assertContains('https://sitemap.test/p/1', $locs, 'public 帖仍应收录');

        foreach ([2, 3, 4, 5, 6] as $id) {
            $this->assertFalse(
                in_array('https://sitemap.test/p/' . $id, $locs, true),
                "非 public 或已下架的帖子 {$id} 不得出现在 sitemap"
            );
        }

        $this->assertContains('https://sitemap.test/u/alice', $locs, '公开且允许搜索的用户仍应收录');
        foreach (['bob', 'carol', 'dave'] as $username) {
            $this->assertFalse(
                in_array('https://sitemap.test/u/' . $username, $locs, true),
                "隐私主页 / 禁止搜索 / 已注销用户 {$username} 不得出现在 sitemap"
            );
        }

        $byLoc = [];
        foreach ($urls as $url) {
            $byLoc[$url['loc']] = $url;
        }
        $this->assertSame('2026-01-02', $byLoc['https://sitemap.test/p/1']['lastmod'], 'lastmod 生成逻辑应保持不变');
        $this->assertSame('daily', $byLoc['https://sitemap.test/p/1']['changefreq']);
    }

    public function testXmlOutputStillContainsOnlyFilteredLocs(): void
    {
        $xml = Sitemap::xml(['site_url' => 'https://sitemap.test']);

        $this->assertStringContainsString('<loc>https://sitemap.test/p/1</loc>', $xml);
        $this->assertStringContainsString('<loc>https://sitemap.test/u/alice</loc>', $xml);
        $this->assertStringContainsString('<lastmod>2026-01-02</lastmod>', $xml);
        $this->assertStringNotContainsString('<loc>https://sitemap.test/p/2</loc>', $xml);
        $this->assertStringNotContainsString('<loc>https://sitemap.test/u/bob</loc>', $xml);
    }

    public function testTopicAggregationOnlyCountsPublicPosts(): void
    {
        // 两篇 private 帖共用标签，且没有任何 public 帖使用该标签
        $this->seed('posts', [
            'id' => 7, 'visibility' => 'private', 'status' => 1,
            'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 10:00:00',
        ]);
        $this->seed('post_tags', ['post_id' => 2, 'tag' => 'private-secret']);
        $this->seed('post_tags', ['post_id' => 7, 'tag' => 'private-secret']);

        // 两篇 public 帖共用标签，合法话题必须仍被收录（防止误杀）
        $this->seed('posts', [
            'id' => 8, 'visibility' => 'public', 'status' => 1,
            'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 10:00:00',
        ]);
        $this->seed('post_tags', ['post_id' => 1, 'tag' => 'public-topic']);
        $this->seed('post_tags', ['post_id' => 8, 'tag' => 'public-topic']);

        $locs = array_column(Sitemap::urls(['site_url' => 'https://sitemap.test']), 'loc');
        $this->assertContains(
            'https://sitemap.test/topic/public-topic',
            $locs,
            '两篇 public 帖共用的标签仍应作为话题收录'
        );
        $this->assertFalse(
            in_array('https://sitemap.test/topic/private-secret', $locs, true),
            '仅由 private 帖组成的话题不得出现在 sitemap'
        );

        $xml = Sitemap::xml(['site_url' => 'https://sitemap.test']);
        $this->assertStringContainsString('<loc>https://sitemap.test/topic/public-topic</loc>', $xml);
        $this->assertStringNotContainsString('<loc>https://sitemap.test/topic/private-secret</loc>', $xml);
    }
}
