<?php
declare(strict_types=1);

namespace Hublog\Core;

/**
 * 后台「目标可写性」统一守卫。
 *
 * 所有会改其他用户身份/状态的管理员入口都必须先过这里：不允许操作当前登录账号、
 * 不允许触碰 id=1 的超级管理员、不允许对管理员执行破坏性动作（封禁/禁言/删除/停用）。
 * 仓储层的 WHERE 兜底仍然存在，本类负责给出明确的 4xx 响应，而不是静默 0 行影响。
 *
 * 设计选择：返回 ?Response 而非抛异常，控制器可直接 `if ($e = ...::check(...)) return $e;`，
 * 与现有控制器风格一致；恢复类动作（unban/unmute/解封 status=1）通过 isRestore=true
 * 对管理员放行，以便清理异常状态，但仍受「自己 / id=1」两条前置约束。
 */
final class AdminTargetGuard
{
    public const ACTION_BAN = 'ban';
    public const ACTION_UNBAN = 'unban';
    public const ACTION_DELETE = 'delete';
    public const ACTION_MUTE = 'mute';
    public const ACTION_UNMUTE = 'unmute';
    public const ACTION_STATUS = 'status';

    /** 对管理员账号禁止的动作集合（STATUS 特指 status=0 停用，解封由 isRestore 放行） */
    private const DESTRUCTIVE = [
        self::ACTION_BAN, self::ACTION_DELETE, self::ACTION_MUTE, self::ACTION_STATUS,
    ];

    /**
     * 单个目标守卫。按「参数 -> 自己 -> id=1 -> 存在性 -> 角色」顺序判定。
     *
     * @param array<string,mixed> $admin      当前管理员行（至少含 id）
     * @param bool                $isRestore 恢复类动作（解封/解除禁言）传 true，对管理员放行
     */
    public static function check(array $admin, int $targetId, string $action, bool $isRestore = false): ?Response
    {
        if ($targetId <= 0) {
            return Response::error('参数错误', 400);
        }
        $adminId = (int)($admin['id'] ?? 0);
        if ($adminId > 0 && $targetId === $adminId) {
            return Response::error('不能对当前登录账号执行该操作', 403);
        }
        if ($targetId === 1) {
            return Response::error('超级管理员账号受保护', 403);
        }

        $target = UserRepository::findIdRoleStatusById($targetId);
        if ($target === null) {
            return Response::error('用户不存在', 404);
        }
        if (($target['role'] ?? '') === 'admin'
            && in_array($action, self::DESTRUCTIVE, true)
            && !$isRestore) {
            return Response::error('管理员账号不可被封禁/禁言/删除', 403);
        }
        return null;
    }

    /**
     * 批量目标守卫：命中任一受保护 ID 即整体拒绝，保证零写入。
     *
     * 不存在的 ID 被忽略（与仓储 WHERE 的行为一致）；占位符与目标均在 PHP 侧归一化为 int。
     *
     * @param array<string,mixed> $admin 当前管理员行
     * @param array<mixed>        $ids   原始 ID 列表
     */
    public static function checkBatch(array $admin, array $ids, string $action): ?Response
    {
        $normalized = [];
        foreach ($ids as $id) {
            $value = (int)$id;
            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }
        if ($normalized === []) {
            return null;
        }
        $normalized = array_values($normalized);

        $adminId = (int)($admin['id'] ?? 0);
        if ($adminId > 0 && in_array($adminId, $normalized, true)) {
            return Response::error('不能对当前登录账号执行该操作', 403);
        }
        if (in_array(1, $normalized, true)) {
            return Response::error('超级管理员账号受保护', 403);
        }

        $roles = UserRepository::fetchRolesByIds($normalized);
        foreach ($roles as $row) {
            if (($row['role'] ?? '') === 'admin' && in_array($action, self::DESTRUCTIVE, true)) {
                return Response::error('管理员账号不可被封禁/禁言/删除', 403);
            }
        }
        return null;
    }
}
