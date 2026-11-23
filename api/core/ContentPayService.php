<?php

declare(strict_types=1);

namespace Hublog\Core;

/**
 * V97 内容付费墙判定服务（Controller / PostCrudTrait / SSR 共用）。
 *
 * 唯一权威 = content_entitlements；付费墙一律返回 200 + paywall 对象（非 404），
 * 前端才能渲染购买卡。作者本人 / 已解锁 / 有效专栏订阅 → 放行全文。
 */
final class ContentPayService
{
    /**
     * 请求内缓存：post_id:user_id => resolve 结果。
     * 未开内容付费时 resolve 很快（两条空查询），缓存主要避免同一帖子在
     * formatPostBatch + scrubBatch/scrubSingle 双路径重复查库。
     * @var array<string,array>
     */
    private static array $resolveCache = [];

    /** 站点是否开过任意付费商品（请求内缓存；表不存在/未开通时为 false，零成本短路） */
    private static ?bool $hasAnyProduct = null;

    /** 清空请求内缓存（长驻进程/测试隔离用） */
    public static function clearCache(): void
    {
        self::$resolveCache = [];
        self::$hasAnyProduct = null;
    }

    /**
     * 站点是否配置了付费商品。content_* 表可能尚未迁移（老库/测试库），
     * 此时一律当「未开通」短路，保证对现有站点/测试零影响。
     */
    private static function hasAnyProduct(): bool
    {
        if (self::$hasAnyProduct !== null) {
            return self::$hasAnyProduct;
        }
        try {
            self::$hasAnyProduct = (int)\Hublog\Core\Database::fetchColumn(
                "SELECT COUNT(*) FROM content_products WHERE status = 1 LIMIT 1"
            ) > 0;
        } catch (\Throwable $e) {
            self::$hasAnyProduct = false;
        }
        return self::$hasAnyProduct;
    }

    /**
     * 判定单条帖子对当前用户是否锁定。
     *
     * 定价优先级：单篇商品 > 首个命中的上架专栏。
     *
     * @return array{
     *   locked: bool,
     *   product_type?: string,
     *   target_id?: int,
     *   preview_ratio?: int,
     *   price?: float,
     *   reason?: string,
     *   column_id?: int
     * }
     */
    public static function resolve(array $post, int $userId): array
    {
        $postId = (int)($post['id'] ?? 0);
        if ($postId <= 0) {
            return ['locked' => false];
        }
        if (!self::hasAnyProduct()) {
            return ['locked' => false];
        }

        $cacheKey = $postId . ':' . $userId;
        if (isset(self::$resolveCache[$cacheKey])) {
            return self::$resolveCache[$cacheKey];
        }

        $result = self::computeResolve($post, $userId);
        self::$resolveCache[$cacheKey] = $result;
        return $result;
    }

    /** resolve 的实际计算（结果由 resolve 缓存） */
    private static function computeResolve(array $post, int $userId): array
    {
        $postId = (int)($post['id'] ?? 0);

        // 作者本人永远看全文
        if ($userId > 0 && $userId === (int)($post['user_id'] ?? 0)) {
            return ['locked' => false];
        }

        $single = ContentPayRepository::findActivePostProduct($postId);
        $columnIds = ContentPayRepository::columnIdsOfPost($postId);

        // 收集所有可解锁本帖的商品：单篇 + 命中专栏
        $products = [];
        if ($single) {
            $products[] = $single;
        }
        foreach ($columnIds as $cid) {
            $p = ContentPayRepository::findProduct(ContentPayRepository::PRODUCT_COLUMN, $cid);
            if ($p && (int)$p['status'] === 1) {
                $products[] = $p;
            }
        }
        if ($products === []) {
            return ['locked' => false];
        }

        // 一次查当前用户对这些商品的权益（O(1) 命中唯一键集合）
        $owned = ContentPayRepository::findEntitlements($userId, $products);
        foreach ($products as $p) {
            $type = (string)$p['product_type'];
            $tid = (int)$p['target_id'];
            $ent = $owned[$type . ':' . $tid] ?? null;
            if (ContentPayRepository::entitlementActive($ent)) {
                return ['locked' => false];
            }
        }

        // 未解锁：按优先级取第一个商品生成购买卡
        $winner = $products[0];
        return [
            'locked' => true,
            'product_type' => (string)$winner['product_type'],
            'target_id' => (int)$winner['target_id'],
            'post_type' => (string)($winner['post_type'] ?? ($post['post_type'] ?? 'normal')),
            'preview_ratio' => max(0, min(100, (int)($winner['preview_ratio'] ?? 30))),
            'price' => (float)$winner['price'],
            'reason' => 'paywall',
        ];
    }

    /**
     * 详情层补充裁剪：正文/媒体/付费标记已由 formatPostBatch 处理，
     * 这里只清理由 detail() 在 format 之后追加的类型帖独立表数据（question/team/...）。
     * 不改共享缓存，只改内存副本。
     */
    public static function scrubSingle(array &$formatted, array $post, int $userId): void
    {
        // 确保 paywall 标记存在（formatPostBatch 通常已设置；独立调用时兜底）
        if (!array_key_exists('paywall', $formatted)) {
            $r = self::resolve($post, $userId);
            $formatted['paywall'] = $r['locked'] ? $r : ['locked' => false];
        }
        if (empty($formatted['paywall']['locked'])) {
            return;
        }
        // 类型帖独立表正文/答案一并清空，杜绝绕过
        foreach (['question', 'team', 'checkin', 'poll', 'origin_post'] as $k) {
            unset($formatted[$k]);
        }
        if (isset($formatted['question_answers'])) {
            $formatted['question_answers'] = [];
        }
    }

    /**
     * 通用列表裁剪钩子：供 PostFormatter::formatPostBatch 末尾调用，覆盖所有列表入口。
     * 未开通内容付费时 hasAnyProduct() 短路，零额外成本；已 hydrate 的类型帖附加字段由
     * 调用方在更早层（hydratePosts/scrubSingle）清理，这里只处理正文与媒体。
     *
     * @param array $item 已 format 的单项（引用改）
     * @param array $post 原始 post 行（含 id/user_id/content/post_type）
     */
    public static function scrubFormatted(array &$item, array $post, int $userId): void
    {
        $r = self::resolve($post, $userId);
        $item['paywall'] = $r['locked'] ? $r : ['locked' => false];
        if (!$r['locked']) {
            return;
        }
        $item['content'] = self::preview((string)($post['content'] ?? ''), (int)($r['preview_ratio'] ?? 30));
        $item['images'] = [];
        $item['videos'] = [];
        $item['attachments'] = [];
    }

    /**
     * 列表层补充裁剪：正文/媒体/付费标记已由 formatPostBatch 处理，
     * 这里只清理由 hydratePosts 在 format 之后追加的类型帖独立表数据。
     * 无 DB 查询，纯内存操作。
     *
     * @param list<array> $list 已 format 的列表项
     * @param list<array> $rawPosts 原始 post 行（保留签名兼容，当前不消费）
     */
    public static function scrubBatch(array $list, array $rawPosts, int $userId): array
    {
        foreach ($list as &$item) {
            if (empty($item['paywall']['locked'])) {
                continue;
            }
            unset($item['question'], $item['team'], $item['checkin'], $item['poll'], $item['origin_post']);
            if (isset($item['question_answers'])) {
                $item['question_answers'] = [];
            }
        }
        unset($item);
        return $list;
    }

    /**
     * 试读截断：按比例取前 N 字，尽量断在换行处，附提示语。ratio<=0 不试读，>=100 全文。
     */
    public static function preview(string $text, int $ratio): string
    {
        if ($ratio <= 0) {
            return '';
        }
        if ($ratio >= 100) {
            return $text;
        }
        if ($text === '') {
            return '';
        }
        $len = mb_strlen($text, 'UTF-8');
        $take = (int)ceil($len * $ratio / 100);
        $out = mb_substr($text, 0, $take, 'UTF-8');
        $cut = mb_strrpos($out, "\n", 0, 'UTF-8');
        if ($cut !== false && $cut > (int)($take * 0.6)) {
            $out = mb_substr($out, 0, $cut, 'UTF-8');
        }
        return rtrim($out) . "\n\n> ……剩余内容需付费解锁";
    }

    /**
     * SSR 用：判断帖子是否锁定（游客 uid=0）。只返回试读所需信息。
     */
    public static function paywallForSeo(array $post): array
    {
        return self::resolve($post, 0);
    }
}
