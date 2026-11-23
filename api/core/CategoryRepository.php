<?php
declare(strict_types=1);

namespace Hublog\Core;

/**
 * 分类（频道）域数据仓储。
 *
 * 承载 CategoryController（前台关注）与 AdminCategoryController（后台管理）下沉的全部 SQL。
 * 所有值一律走 ? 绑定；字段清单、顺序、JOIN、ORDER BY、LIMIT 与迁移前 HEAD 原文逐字一致。
 *
 * 【相似但不同的查询刻意不合并】
 *  - fetchAllCategories（无 WHERE，后台全量含停用）≠ fetchActiveCategories（status = 1），
 *    两者字段相同但结果口径不同，各建方法；
 *  - fetchFollowingCategories（f + c JOIN，按 f.created_at DESC）≠ 后台两张扁平列表；
 *  - countChildCategories（COUNT(*)）≠ fetchChildCategoryIds（id 列表，递归用）；
 *  - findActiveCategoryId 前后台 SQL 逐字相同（SELECT id ... status = 1 LIMIT 1），故共用。
 *
 * 关注切换事务完整下沉，入口守卫（分类必须存在且启用、ID 正数）在本仓储内复核。
 * 控制器保留鉴权、参数校验、缓存清理与响应包装。
 */
class CategoryRepository
{
    /** categories 可写字段（后台 saveCategory 硬编码集合） */
    private const CATEGORY_FIELDS = ['name', 'parent_id', 'sort_order', 'status', 'updated_at'];

    // ==================== 前台分类关注（CategoryController） ====================

    /**
     * 前后台共用的「启用分类存在性」探针。
     * 原文 SQL 在 CategoryController::follow 与 AdminCategoryController::saveCategory 中逐字相同。
     */
    public static function findActiveCategoryId(int $categoryId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM categories WHERE id = ? AND status = 1 LIMIT 1",
            [$categoryId]
        );
    }

    /** follow() / status() —— 关注关系行（id，LIMIT 1） */
    public static function findFollowEdge(int $userId, int $categoryId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM category_follows WHERE user_id = ? AND category_id = ? LIMIT 1",
            [$userId, $categoryId]
        );
    }

    /**
     * follow() —— 关注 / 取消关注切换事务。
     *
     * 原控制器在事务前已取过边，这里允许传入避免多余查询；内部直接调用时自行读取。
     * 入口守卫下沉：非正数 ID 或分类不存在 / 已停用时不写库，返回 false。
     * 返回 true 表示新建关注（控制器据此选择 '已关注频道' 文案）。
     */
    public static function toggleFollow(
        int $categoryId,
        int $userId,
        string $now,
        ?array $existingEdge = null
    ): bool {
        if ($categoryId <= 0 || $userId <= 0) {
            return false;
        }
        if (self::findActiveCategoryId($categoryId) === null) {
            return false;
        }

        $exists = $existingEdge ?? self::findFollowEdge($userId, $categoryId);
        $following = false;

        Database::beginTransaction();
        try {
            if ($exists) {
                Database::delete('category_follows', 'id = ?', [$exists['id']]);
            } else {
                Database::insert('category_follows', [
                    'user_id'     => $userId,
                    'category_id' => $categoryId,
                    'created_at'  => $now,
                ]);
                $following = true;
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        return $following;
    }

    /** followerCount() —— 关注人数（COUNT(*) 无 LIMIT） */
    public static function countFollowers(int $categoryId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM category_follows WHERE category_id = ?",
            [$categoryId]
        );
    }

    /** following() —— 我关注的启用分类（JOIN + ORDER BY f.created_at DESC） */
    public static function fetchFollowingCategories(int $userId): array
    {
        return Database::fetchAll(
            "SELECT c.id, c.name, c.parent_id, c.description, c.cover, c.post_count
             FROM category_follows f
             INNER JOIN categories c ON c.id = f.category_id
             WHERE f.user_id = ? AND c.status = 1
             ORDER BY f.created_at DESC",
            [$userId]
        );
    }

    // ==================== 后台分类管理（AdminCategoryController） ====================

    /** categories() —— 后台全量分类树（含停用，双字段排序） */
    public static function fetchAllCategories(): array
    {
        return Database::fetchAll(
            "SELECT id, parent_id, name, sort_order, status FROM categories ORDER BY sort_order ASC, id ASC"
        );
    }

    /** allCategories() —— 后台启用分类树（status = 1，字段/排序与全量版一致但口径不同） */
    public static function fetchActiveCategories(): array
    {
        return Database::fetchAll(
            "SELECT id, parent_id, name, sort_order, status FROM categories WHERE status = 1 ORDER BY sort_order ASC, id ASC"
        );
    }

    /** saveCategory() —— 更新分类；列名白名单 + 正则双重校验 */
    public static function updateCategoryFields(int $id, array $data): void
    {
        if ($id <= 0 || $data === []) {
            throw new \InvalidArgumentException('参数错误');
        }
        self::assertCategoryFields($data, false);
        Database::update('categories', $data, 'id = ?', [$id]);
    }

    /** saveCategory() —— 新建分类；比更新额外允许 created_at */
    public static function insertCategory(array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('参数错误');
        }
        self::assertCategoryFields($data, true);
        return Database::insert('categories', $data);
    }

    /** deleteCategory() —— 直接子分类数量（COUNT(*)） */
    public static function countChildCategories(int $parentId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM categories WHERE parent_id = ?",
            [$parentId]
        );
    }

    /** deleteCategory() —— 按主键删除 */
    public static function deleteCategoryById(int $id): int
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('参数错误');
        }
        return Database::delete('categories', 'id = ?', [$id]);
    }

    /** getCategoryChildIds() —— 递归取直接子分类 ID（无 LIMIT / 无 ORDER BY） */
    public static function fetchChildCategoryIds(int $categoryId): array
    {
        return Database::fetchAll("SELECT id FROM categories WHERE parent_id = ?", [$categoryId]);
    }

    /**
     * categories 写入字段白名单：动态列名只能来自此处，且必须匹配 /^[a-z_]+$/。
     *
     * @param array<string,mixed> $data
     */
    private static function assertCategoryFields(array $data, bool $allowCreated): void
    {
        $allowed = self::CATEGORY_FIELDS;
        if ($allowCreated) {
            $allowed[] = 'created_at';
        }
        foreach (array_keys($data) as $column) {
            $fragment = (string)$column . ' = ?';
            if (!is_string($column)
                || !in_array($column, $allowed, true)
                || preg_match('/^[a-z_]+ = \?$/', $fragment) !== 1
            ) {
                throw new \InvalidArgumentException('非法字段名');
            }
        }
    }
}
