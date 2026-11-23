<?php
/**
 * Hublog 管理员后台——批量操作子域控制器（从 AdminController 拆分）
 * 帖子/评论/用户的批量处理
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\AdminRepository;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Security;
use Hublog\Core\Helper;
use Hublog\Core\PathResolver;
use Hublog\Core\Upload;
use Hublog\Core\Upgrade;
use Hublog\Core\Watermark;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\BadgeService;
use Hublog\Core\MediaHelper;
use Hublog\Core\AdminTargetGuard;

class AdminBatchController extends AdminController
{
    /**
     * 批量操作帖子
     */
    public function batchPosts(Request $request): Response
    {
        $this->requireAdmin($request);
        $ids = $request->getArray('ids');
        $action = $request->getString('action'); // delete, hide, show, pin, unpin
        if (empty($ids) || empty($action)) {
            return Response::error('参数错误', 400);
        }
        $ids = array_map('intval', $ids);
        $now = Helper::now();

        switch ($action) {
            case 'delete':
                AdminRepository::deletePostsByIds($ids);
                break;
            case 'hide':
                AdminRepository::hidePostsByIds($ids, $now);
                break;
            case 'show':
                AdminRepository::showPostsByIds($ids, $now);
                break;
            case 'pin':
                AdminRepository::pinPostsByIds($ids, $now);
                break;
            case 'unpin':
                AdminRepository::unpinPostsByIds($ids);
                break;
            default:
                return Response::error('不支持的操作', 400);
        }
        // P0-11：timeline 命名空间升版（覆盖 timeline_v4_* / timeline_total_*）
        Cache::bumpNsVersion('timeline');
        Cache::deleteByPrefix('posts_');
        return Response::success(['affected' => count($ids)], '批量操作成功');
    }

    /**
     * 批量操作评论
     */
    public function batchComments(Request $request): Response
    {
        $this->requireAdmin($request);
        $ids = $request->getArray('ids');
        $action = $request->getString('action');
        if (empty($ids) || empty($action)) {
            return Response::error('参数错误', 400);
        }
        $ids = array_map('intval', $ids);

        if ($action === 'delete') {
            AdminRepository::deleteCommentsByIds($ids);
            Cache::deleteByPrefix('post_comments_');
            return Response::success(['affected' => count($ids)], '批量删除成功');
        }
        return Response::error('不支持的操作', 400);
    }

    /**
     * 批量操作用户
     */
    public function batchUsers(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $ids = $request->getArray('ids');
        $action = $request->getString('action'); // ban, unban, delete
        if (empty($ids) || empty($action)) {
            return Response::error('参数错误', 400);
        }
        $ids = array_map('intval', $ids);

        $guardActions = [
            'ban' => AdminTargetGuard::ACTION_BAN,
            'unban' => AdminTargetGuard::ACTION_UNBAN,
            'delete' => AdminTargetGuard::ACTION_DELETE,
        ];
        if (!isset($guardActions[$action])) {
            return Response::error('不支持的操作', 400);
        }
        if ($error = AdminTargetGuard::checkBatch($admin, $ids, $guardActions[$action])) {
            return $error;
        }

        switch ($action) {
            case 'ban':
                AdminRepository::banUsersByIds($ids);
                break;
            case 'unban':
                AdminRepository::unbanUsersByIds($ids);
                break;
            case 'delete':
                // P2-11：软删除，避免硬删 users 触发 20+ 条外键 CASCADE（高产用户级联删会锁表数分钟）。
                // 标记 deleted_at + status=0 + 匿名化，保留行避免外键悬挂，与 App 注销（deleteAccount）语义一致。
                // 管理员账号不允许删除（role != 'admin'）。
                $now = Helper::now();
                $deadPwd = Auth::hashPassword(bin2hex(random_bytes(32)));
                foreach ($ids as $uid) {
                    AdminRepository::softDeleteUser(
                        $uid,
                        $now,
                        $deadPwd,
                        Helper::uniqueNickname('已注销用户', $uid),
                        (int)(microtime(true) * 1000)
                    );
                }
                break;
            default:
                return Response::error('不支持的操作', 400);
        }
        // 每个目标单独清身份缓存：Auth 记忆化与 auth_user_ 前缀都按用户 ID 缓存
        foreach ($ids as $uid) {
            Auth::clearUserCache((int)$uid);
        }
        Cache::deleteByPrefix('user_profile_');
        return Response::success(['affected' => count($ids)], '批量操作成功');
    }

    /**
     * 数据导出（GDPR 合规）
     */
}
