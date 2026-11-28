<?php

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\AdminPageController;
use Hublog\Controllers\AppController;
use Hublog\Controllers\PageController;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\PageRepository;
use Hublog\Core\Request;
use ReflectionProperty;

/**
 * T04 通用单页行为护栏
 *
 * 锁定三件事：
 *   1. 单页 CRUD / slug 唯一 / 发布状态语义；
 *   2. 协议正文三级回退链（已发布通用页 → 旧 settings → 控制器缺省正文）；
 *   3. /api/app/agreements 四字段结构与三类缺省正文逐字不变（Q6 兼容红线）。
 */
final class PageBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `pages` (
    `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
    `slug`          TEXT NOT NULL UNIQUE,
    `title`         TEXT NOT NULL,
    `content`       TEXT,
    `status`        INTEGER NOT NULL DEFAULT 0,
    `template`      TEXT NOT NULL DEFAULT '',
    `seo_title`     TEXT NOT NULL DEFAULT '',
    `seo_description` TEXT NOT NULL DEFAULT '',
    `seo_keywords`  TEXT NOT NULL DEFAULT '',
    `sort_order`    INTEGER NOT NULL DEFAULT 0,
    `source`        TEXT NOT NULL DEFAULT 'manual',
    `created_at`    TEXT DEFAULT NULL,
    `updated_at`    TEXT DEFAULT NULL
);
SQL);
        // seedAgreements 是进程内静态缺省表，逐用例复位避免互相污染
        $ref = new ReflectionProperty(PageRepository::class, 'agreementDefaults');
        $ref->setAccessible(true);
        $ref->setValue(null, []);
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /** slug 白名单 + 标题必填 + 唯一冲突码位 */
    public function testSaveValidatesSlugTitleAndConflicts(): void
    {
        try {
            PageRepository::save(['slug' => 'Bad_Slug', 'title' => 'x']);
            $this->fail('非法 slug 应抛异常');
        } catch (HublogException $e) {
            $this->assertSame(400, $e->getCode());
        }
        try {
            PageRepository::save(['slug' => 'about', 'title' => '']);
            $this->fail('空标题应抛异常');
        } catch (HublogException $e) {
            $this->assertSame(400, $e->getCode());
        }

        $id = PageRepository::save(['slug' => 'about', 'title' => '关于我们', 'content' => '正文']);
        $this->assertGreaterThan(0, $id);
        try {
            PageRepository::save(['slug' => 'about', 'title' => '重复页']);
            $this->fail('重复 slug 应抛异常');
        } catch (HublogException $e) {
            $this->assertSame(409, $e->getCode());
        }
        // 同 slug 但排除自身更新不冲突
        $this->assertSame($id, PageRepository::save(['id' => $id, 'slug' => 'about', 'title' => '改后标题']));
    }

    /** 公开读取只看 status=1；草稿不回退 */
    public function testPublicReadOnlyReturnsPublishedPageWithFixedFields(): void
    {
        $id = PageRepository::save([
            'slug' => 'help', 'title' => '帮助中心', 'content' => '帮助正文',
            'seo_title' => 'SEO标题', 'seo_description' => 'SEO描述', 'seo_keywords' => 'SEO关键词',
            'status' => 0,
        ]);
        $this->assertNull(PageRepository::findBySlug('help', true), '草稿不应被公开读取');

        $req = new Request();
        $req->setParam('slug', 'help');
        $res = (new PageController())->show($req);
        $this->assertSame(404, $res->getCode());

        PageRepository::save(['id' => $id, 'slug' => 'help', 'title' => '帮助中心', 'status' => 1]);
        $res = (new PageController())->show($req);
        $this->assertSame(200, $res->getCode());
        $data = json_decode($res->toJson(), true)['data'];
        $this->assertSame(
            ['slug', 'title', 'content', 'seo_title', 'seo_description', 'seo_keywords', 'updated_at'],
            array_keys($data),
            '公开单页字段集合与顺序被改动'
        );
        $this->assertSame('help', $data['slug']);
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('status', $data);

        // 非法 slug 也走 404
        $bad = new Request();
        $bad->setParam('slug', 'Bad_Slug');
        $this->assertSame(404, (new PageController())->show($bad)->getCode());
    }

    /** 三级回退：通用页 > 旧配置 > 缺省正文 */
    public function testAgreementFallbackChain(): void
    {
        PageRepository::seedAgreements(['user' => '缺省正文A', 'privacy' => '缺省正文B', 'realname' => '缺省正文C']);
        $settings = [
            'agreement_user_text' => '旧配置正文',
            'agreement_user_text_updated_at' => '2026-01-01 00:00:00',
        ];

        // ② 旧配置
        $r = PageRepository::resolveAgreement('user', $settings);
        $this->assertSame('旧配置正文', $r['content']);
        $this->assertSame('2026-01-01 00:00:00', $r['updated_at']);

        // ③ 缺省正文
        $r = PageRepository::resolveAgreement('privacy', $settings);
        $this->assertSame('缺省正文B', $r['content']);
        $this->assertSame('', $r['updated_at']);

        // ① 已发布通用页覆盖
        PageRepository::save([
            'slug' => 'agreement_user', 'title' => '用户协议', 'content' => '新协议正文', 'status' => 1,
        ]);
        $r = PageRepository::resolveAgreement('user', $settings);
        $this->assertSame('新协议正文', $r['content']);

        // 草稿页不生效
        PageRepository::save(['slug' => 'agreement_realname', 'title' => '实名', 'content' => '草稿正文', 'status' => 0]);
        $r = PageRepository::resolveAgreement('realname', $settings);
        $this->assertSame('缺省正文C', $r['content']);
    }

    /** 回归：seed 页空正文被发布后不得清空协议正文，继续回退旧配置/缺省正文 */
    public function testPublishedAgreementPageWithEmptyContentFallsBack(): void
    {
        PageRepository::seedAgreements([
            'user' => '缺省用户协议正文',
            'privacy' => '缺省隐私正文',
            'realname' => '缺省实名正文',
        ]);
        $settings = [
            'agreement_user_text' => '旧配置用户协议正文',
            'agreement_user_text_updated_at' => '2026-02-02 00:00:00',
        ];

        // 模拟 T01 seed 行（source=seed/content 空）被站长只点「发布」：status=1、正文仍空
        PageRepository::save([
            'slug' => 'agreement_user', 'title' => '用户协议', 'content' => '', 'status' => 1,
            'source' => 'seed',
        ]);
        $r = PageRepository::resolveAgreement('user', $settings);
        $this->assertSame('旧配置用户协议正文', $r['content'], '空正文已发布页必须继续回退旧配置');
        $this->assertSame('2026-02-02 00:00:00', $r['updated_at']);

        // 旧配置也为空时，落到控制器缺省正文（不得为空）
        $r = PageRepository::resolveAgreement('user', []);
        $this->assertSame('缺省用户协议正文', $r['content']);
        $this->assertNotSame('', $r['content']);

        // privacy/realname 未建页，同样走缺省
        $this->assertSame('缺省隐私正文', PageRepository::resolveAgreement('privacy', [])['content']);
        $this->assertSame('缺省实名正文', PageRepository::resolveAgreement('realname', [])['content']);

        // 通过真实 API 再验一次：四字段结构与标题不变，正文非空
        $_GET = ['type' => 'user'];
        $data = json_decode((new AppController())->agreements(new Request())->toJson(), true)['data'];
        $this->assertSame(['type', 'title', 'content', 'updated_at'], array_keys($data));
        $this->assertSame('用户协议', $data['title']);
        $this->assertNotSame('', $data['content']);
    }

    /** Q6 兼容红线：三类型返回四字段，缺省正文逐字保留 */
    public function testAgreementsApiResponseShapeAndDefaultsAreStable(): void
    {
        $expectedUpdatedAt = [
            'user' => '', 'privacy' => '', 'realname' => '',
        ];
        $expectedTitles = [
            'user' => '用户协议', 'privacy' => '隐私政策', 'realname' => '实名认证服务协议',
        ];
        // 期望值随 6672e1f 集中协议正文的文案更新，此处锁定当前对外展示文本；法务变更协议正文时需同步更新本断言
        $expectedPrefix = [
            'user' => "用户服务协议\n\n生效日期：2026 年 1 月 1 日\n最近更新：2026 年 1 月 1 日\n\n",
            'privacy' => "隐私政策\n\n生效日期：2026 年 1 月 1 日\n最近更新：2026 年 1 月 1 日\n\n",
            'realname' => "实名认证服务协议\n\n生效日期：2026 年 1 月 1 日\n最近更新：2026 年 1 月 1 日\n\n",
        ];

        foreach (['user', 'privacy', 'realname'] as $type) {
            $_GET = ['type' => $type];
            $res = (new AppController())->agreements(new Request());
            $this->assertSame(200, $res->getCode());
            $data = json_decode($res->toJson(), true)['data'];
            $this->assertSame(['type', 'title', 'content', 'updated_at'], array_keys($data), "{$type} 返回字段或顺序被改动");
            $this->assertSame($type, $data['type']);
            $this->assertSame($expectedTitles[$type], $data['title']);
            $this->assertSame($expectedUpdatedAt[$type], $data['updated_at']);
            $this->assertStringStartsWith($expectedPrefix[$type], $data['content'], "{$type} 缺省正文被改动");
        }
    }

    /** 后台发布/删除语义 */
    public function testAdminPublishAndDelete(): void
    {
        $id = PageRepository::save(['slug' => 'about', 'title' => '关于我们', 'content' => '正文']);
        $_GET = [];
        $_POST = ['id' => $id, 'status' => 1];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $req = new Request();

        $ctrl = new class extends AdminPageController {
            protected function requireAdmin(\Hublog\Core\Request $request): array
            {
                // 行为测试只验证业务语义，鉴权由 AdminMiddleware / 单元测试覆盖
                return ['id' => 1, 'role' => 'admin'];
            }
        };
        $res = $ctrl->publish($req);
        $this->assertSame(200, $res->getCode());
        $data = json_decode($res->toJson(), true)['data'];
        $this->assertSame(['slug' => 'about', 'status' => 1], $data);
        $this->assertNotNull(PageRepository::findBySlug('about', true), '发布后应可被公开读取');

        $_POST = ['id' => $id];
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $res = $ctrl->delete(new Request());
        $this->assertSame(200, $res->getCode());
        $this->assertSame(['deleted' => true], json_decode($res->toJson(), true)['data']);
        $this->assertNull(PageRepository::findById($id));
    }
}
