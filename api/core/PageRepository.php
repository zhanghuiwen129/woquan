<?php
/**
 * 通用单页仓储（B2-1 / B2-2 / B2-4）
 *
 * pages 表由 T01 migrate101 建立；slug 形如 about/help 或 agreement_{type}，
 * 唯一且受 ^[a-z0-9]+(-[a-z0-9]+)*$ 约束。
 *
 * 协议回退链（Q6 兼容红线）：
 *   ① pages.slug='agreement_{type}' 且 status=1 → content / updated_at 来自通用页；
 *   ② 旧配置 settings.agreement_{type}_text 非空 → 原内容与原 updated_at 键；
 *   ③ 控制器内三类硬编码缺省正文（由 AppController 经 seedAgreements() 注入默认值数组）。
 *
 * 注意：T01 已写入三条 source='seed' / status=0 / content 空的占位行，
 * seedAgreements() 刻意不写库、不重复 INSERT、不覆盖运营内容，只登记回退用的缺省正文。
 */

declare(strict_types=1);

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;

class PageRepository
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const AGREEMENT_TYPES = ['user', 'privacy', 'realname'];

    /**
     * 系统保留 slug：架构 §4.3 的回退链强制要求 agreement_{type} 形态（含下划线），
     * 而 §7 T04 给站长自建页的校验模式只允许小写字母/数字/连字符。
     * 这里把三类协议页作为**系统保留例外**放开，普通自建 slug 仍严格走 SLUG_PATTERN，
     * 既不让用户随便建下划线页，也保证站长能在后台发布协议页覆盖回退正文。
     */
    public const SYSTEM_SLUGS = ['agreement_user', 'agreement_privacy', 'agreement_realname'];

    /** @var array<string,string> 由 AppController 注入的硬编码缺省正文（进程内，非 DB） */
    private static array $agreementDefaults = [];

    /** slug 合法性：普通页严格模式 + 三类系统保留协议页例外 */
    public static function isValidSlug(string $slug): bool
    {
        if (preg_match(self::SLUG_PATTERN, $slug) === 1) {
            return true;
        }
        return in_array($slug, self::SYSTEM_SLUGS, true);
    }

    /** 按 slug 读取；onlyPublished=true 时只认 status=1 */
    public static function findBySlug(string $slug, bool $onlyPublished = true): ?array
    {
        if (!self::isValidSlug($slug)) {
            return null;
        }
        try {
            $sql = 'SELECT * FROM `pages` WHERE `slug` = ?' . ($onlyPublished ? ' AND `status` = 1' : '');
            $row = Database::fetchOne($sql, [$slug]);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[Hublog Page] 按 slug 读取失败 ' . $slug . ': ' . $e->getMessage());
            return null;
        }
    }

    /** 后台列表：按排序与 id 升序 */
    public static function listAll(): array
    {
        try {
            return Database::fetchAll('SELECT * FROM `pages` ORDER BY `sort_order` ASC, `id` ASC');
        } catch (\Throwable $e) {
            Logger::error('[Hublog Page] 列表读取失败: ' . $e->getMessage());
            return [];
        }
    }

    public static function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $row = Database::fetchOne('SELECT * FROM `pages` WHERE `id` = ?', [$id]);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[Hublog Page] 按 ID 读取失败 ' . $id . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 新建 / 更新单页，返回页面 id。
     *
     * @param array<string,mixed> $data id>0 为更新，否则新建
     * @throws HublogException 400=参数/slug 非法，404=要更新的页不存在，409=slug 重复
     */
    public static function save(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $slug = strtolower(trim((string)($data['slug'] ?? '')));
        if (!self::isValidSlug($slug)) {
            throw new HublogException('slug 只能包含小写字母、数字和连字符', 400);
        }
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new HublogException('标题不能为空', 400);
        }
        $conflict = self::slugExists($slug, $id);
        if ($conflict) {
            throw new HublogException('slug 已存在', 409);
        }

        $row = [
            'slug' => $slug,
            'title' => $title,
            'content' => (string)($data['content'] ?? ''),
            'status' => !empty($data['status']) ? 1 : 0,
            'template' => (string)($data['template'] ?? ''),
            'seo_title' => (string)($data['seo_title'] ?? ''),
            'seo_description' => (string)($data['seo_description'] ?? ''),
            'seo_keywords' => (string)($data['seo_keywords'] ?? ''),
            'sort_order' => (int)($data['sort_order'] ?? 0),
        ];

        if ($id > 0) {
            if (self::findById($id) === null) {
                throw new HublogException('页面不存在', 404);
            }
            // 发布/编辑后同步刷新 updated_at：协议回退链把它作为 App 端版本时间下发
            $row['updated_at'] = Helper::now();
            Database::update('pages', $row, '`id` = ?', [$id]);
            return $id;
        }

        $row['source'] = (string)($data['source'] ?? 'manual');
        $row['created_at'] = Helper::now();
        $row['updated_at'] = Helper::now();
        return Database::insert('pages', $row);
    }

    public static function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        return Database::delete('pages', '`id` = ?', [$id]) > 0;
    }

    public static function slugExists(string $slug, int $exceptId = 0): bool
    {
        $sql = 'SELECT COUNT(*) FROM `pages` WHERE `slug` = ?';
        $params = [$slug];
        if ($exceptId > 0) {
            $sql .= ' AND `id` <> ?';
            $params[] = $exceptId;
        }
        return (int)Database::fetchColumn($sql, $params) > 0;
    }

    /**
     * 登记控制器内三类硬编码缺省正文，供 resolveAgreement() 第三级回退使用。
     *
     * 【刻意不写库】T01 migrate101 已插入三条 agreement_* 种子行（source=seed/status=0/正文空），
     * 这里只把缺省正文放进进程内回退表；站长在后台发布单页后才由 pages 覆盖旧内容。
     *
     * @param array<string,string> $defaults type => 缺省正文
     * @return int 成功登记的缺省正文条数
     */
    public static function seedAgreements(array $defaults): int
    {
        $count = 0;
        foreach (self::AGREEMENT_TYPES as $type) {
            $text = $defaults[$type] ?? null;
            if (is_string($text) && $text !== '') {
                self::$agreementDefaults[$type] = $text;
                $count++;
            }
        }
        return $count;
    }

    /**
     * 协议正文三级回退，返回 {content, updated_at} 两字段供控制器组装四字段响应。
     *
     * @param array<string,mixed> $settings 站点配置键值
     * @return array{content:string,updated_at:string}
     */
    public static function resolveAgreement(string $type, array $settings): array
    {
        $type = in_array($type, self::AGREEMENT_TYPES, true) ? $type : 'user';
        $key = 'agreement_' . $type . '_text';
        $updatedAt = (string)($settings[$key . '_updated_at'] ?? '');

        $page = self::findBySlug('agreement_' . $type, true);
        // Q6 兼容红线：T01 的 seed 页 status=0/content 空，站长若只点「发布」不改正文，
        // 就会变成「已发布但空正文」。第一级必须要求 content 非空才采纳，否则继续下钻，
        // 保证协议正文永远不空。读取侧兜底覆盖后台发布/导入/二开脚本等所有来源。
        if ($page !== null && (string)($page['content'] ?? '') !== '') {
            return [
                'content' => (string)$page['content'],
                'updated_at' => (string)($page['updated_at'] ?? $updatedAt),
            ];
        }

        $legacy = (string)($settings[$key] ?? '');
        if ($legacy !== '') {
            return ['content' => $legacy, 'updated_at' => $updatedAt];
        }

        return ['content' => (string)(self::$agreementDefaults[$type] ?? ''), 'updated_at' => $updatedAt];
    }
}
