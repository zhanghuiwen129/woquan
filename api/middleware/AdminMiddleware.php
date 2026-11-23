<?php
/**
 * Hublog 管理员权限中间件
 */

namespace Hublog\Middleware;

use Hublog\Core\Request;
use Hublog\Core\Auth;
use Hublog\Core\Database;
use Hublog\Core\Exception\HublogException;

class AdminMiddleware
{
    public function handle(Request $request): void
    {
        $path = $request->getPath();
        // V80：活动管理接口（报名活动 + 圈子下拉）向「圈主」开放（仅限自己圈子），
        // 其余后台接口仍仅站点管理员可访问。圈主判定依据 circles.owner_id。
        $ownerAllowed = ($path === '/api/admin/activities' || $path === '/api/admin/my-circles');
        if ($ownerAllowed) {
            $user = Auth::check($request); // 未登录抛 401
            if ($user['role'] === 'admin') {
                return;
            }
            $isOwner = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM circles WHERE owner_id = ?", [$user['id']]
            ) > 0;
            if (!$isOwner) {
                throw new HublogException('权限不足', 403);
            }
            return;
        }
        Auth::admin($request);
    }
}
