<?php

declare(strict_types=1);

namespace Hublog\Core;

/**
 * Hublog 友情链接数据仓储
 *
 * 承载 AdminFriendLinkController 的 friend_links 表访问。
 * 后台全量列表（含 sort_order/status/created_at）与前台启用列表（仅 4 列）
 * 字段集不同，刻意拆成两个方法，禁止互相复用。
 * 迁移约定见 docs/内联SQL收敛规范.md。
 */
class FriendLinkRepository
{
    /**
     * AdminFriendLinkController::friendLinks() —— 后台全量列表。
     *
     * 字段清单与字段顺序、ORDER BY sort_order ASC, id ASC 逐字保留。
     */
    public static function fetchAdminFriendLinks(): array
    {
        return Database::fetchAll(
            "SELECT id, name, url, description, sort_order, status, created_at FROM friend_links ORDER BY sort_order ASC, id ASC"
        );
    }

    /**
     * AdminFriendLinkController::publicFriendLinks() —— 前台公开列表。
     *
     * 只取 4 列且带 status = 1；与后台全量列表字段集不同，不可复用。
     * 缓存边界（Cache::remember 3600s）仍由控制器保留。
     */
    public static function fetchActiveFriendLinks(): array
    {
        return Database::fetchAll(
            "SELECT id, name, url, description FROM friend_links WHERE status = 1 ORDER BY sort_order ASC, id ASC"
        );
    }

    /** AdminFriendLinkController::saveFriendLink() —— 更新既有记录 */
    public static function updateFriendLink(int $id, array $data): void
    {
        Database::update('friend_links', $data, 'id = ?', [$id]);
    }

    /** AdminFriendLinkController::saveFriendLink() —— 新增记录，返回新行 ID */
    public static function insertFriendLink(array $data): int
    {
        return Database::insert('friend_links', $data);
    }

    /** AdminFriendLinkController::deleteFriendLink() —— 按主键删除 */
    public static function deleteFriendLink(int $id): void
    {
        Database::delete('friend_links', 'id = ?', [$id]);
    }
}
