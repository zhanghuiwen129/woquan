<?php
declare(strict_types=1);
/**
 * Hublog 后台「应用版本」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\AppRepository;
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

class AdminAppVersionController extends AdminController
{
    public function appVersions(Request $request): Response
    {
        $this->requireAdmin($request);
        $rows = AppRepository::fetchAdminAppVersions();
        return Response::success($rows);
    }

    /**
     * POST /api/admin/app-versions —— 发布新版本（发版时调用）
     * PUT（带 id）—— 更新已有记录
     */
    public function saveAppVersion(Request $request): Response
    {
        $this->requireAdmin($request);

        $id = $request->getInt('id');
        $platform = strtolower(trim((string)$request->getString('platform')));
        if (!in_array($platform, ['android', 'ios', 'h5'], true)) {
            return Response::error('platform 必须是 android / ios / h5', 400);
        }
        $versionCode = $request->getInt('version_code', 0);
        $versionName = trim($request->getString('version_name'));
        $updateType = $request->getInt('update_type', 0);
        $changelog = trim($request->getString('changelog'));
        $downloadUrl = trim($request->getString('download_url'));
        $status = $request->getInt('status', 1);

        if ($versionCode <= 0) {
            return Response::error('version_code 必须是正整数', 400);
        }
        if ($versionName === '') {
            return Response::error('version_name 不能为空', 400);
        }
        if (!filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
            return Response::error('download_url 必须是合法的 http(s) 链接', 400);
        }
        if (!in_array($updateType, [0, 1], true)) {
            $updateType = 0;
        }

        $data = [
            'platform'     => $platform,
            'version_code' => $versionCode,
            'version_name' => Security::purify($versionName),
            'update_type'  => $updateType,
            'changelog'    => Security::purify($changelog),
            'download_url' => Security::purify($downloadUrl),
            'status'       => in_array($status, [0, 1], true) ? $status : 1,
            'updated_at'   => Helper::now()
        ];

        if ($id) {
            AppRepository::updateAppVersion($id, $data);
        } else {
            $data['created_at'] = Helper::now();
            AppRepository::insertAppVersion($data);
        }

        // 清掉公开 /app/version 的缓存（按平台三个 key），让新版本立即生效
        Cache::delete('app_version_android');
        Cache::delete('app_version_ios');
        Cache::delete('app_version_h5');

        return Response::success(null, $id ? '更新成功' : '发布成功');
    }

    /**
     * DELETE /api/admin/app-versions?id=xxx —— 删除某条版本记录（撤回误发）
     */
    public function deleteAppVersion(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }
        AppRepository::deleteAppVersion($id);
        Cache::delete('app_version_android');
        Cache::delete('app_version_ios');
        Cache::delete('app_version_h5');
        return Response::success(null, '删除成功');
    }

}
