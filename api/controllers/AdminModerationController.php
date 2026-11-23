<?php
/**
 * Hublog 管理员后台——内容治理子域控制器（从 AdminController 拆分）
 * 敏感词库 CRUD + 命中日志 + 全站/圈子违规处理（禁言 / 限流 / 下架）
 *
 * 数据访问统一收敛到 ModerationRepository；本类只保留权限、参数校验、
 * 缓存失效与响应包装。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
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
use Hublog\Core\ModerationRepository;
use Hublog\Core\AdminTargetGuard;

class AdminModerationController extends AdminController
{
    // ==================== V71：内容治理（P0） ====================
    // 敏感词库 CRUD + 命中日志 + 违规处理（全站禁言 / 圈子限流下架）
    // 所有写操作都要清缓存：词库走 Moderation::CACHE_KEY，用户禁言走 auth_user_ 前缀。

    /**
     * GET /admin/sensitive-words —— 敏感词列表（支持按词模糊搜索）
     */
    public function sensitiveWords(Request $request): Response
    {
        $this->requireAdmin($request);

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 100);
        $keyword = trim($request->getString('keyword'));

        $rows = ModerationRepository::fetchSensitiveWords($keyword, $offset, $limit);
        $total = ModerationRepository::countSensitiveWords($keyword);

        return Response::success(Helper::paginatedResponse($rows, $page, $limit, $total), '获取成功');
    }

    /**
     * POST /admin/sensitive-words —— 新增/编辑敏感词
     * {id?, word, level(replace|block|review), replacement, scene(逗号分隔，空=全场景), status}
     */
    public function saveSensitiveWord(Request $request): Response
    {
        $this->requireAdmin($request);

        $id    = $request->getInt('id');
        $word  = trim($request->getString('word'));
        $level = strtolower(trim($request->getString('level'))) ?: 'replace';

        if ($word === '') {
            return Response::error('敏感词不能为空', 400);
        }
        if (!in_array($level, ['replace', 'block', 'review'], true)) {
            return Response::error('处理级别取值无效', 400);
        }

        $replacement = trim($request->getString('replacement'));
        if ($replacement === '') {
            $replacement = '***';
        }

        // scene 空 = 全场景；否则每个值都必须落在 Moderation::SCENES 里
        $scene = strtolower(trim($request->getString('scene')));
        if ($scene !== '') {
            $parts = array_filter(array_map('trim', explode(',', $scene)));
            foreach ($parts as $p) {
                if (!in_array($p, Moderation::SCENES, true)) {
                    return Response::error('场景取值无效：' . $p, 400);
                }
            }
            $scene = implode(',', $parts);
        }

        $payload = [
            'word'        => $word,
            'level'       => $level,
            'replacement' => $replacement,
            'scene'       => $scene,
            'status'      => $request->getInt('status', 1) === 1 ? 1 : 0,
            'updated_at'  => Helper::now(),
        ];

        if ($id > 0) {
            // 改词也要判重（排除自己）
            $dup = ModerationRepository::findOtherSensitiveWordId($word, $id);
            if ($dup) {
                return Response::error('已存在相同的敏感词', 409);
            }
            ModerationRepository::updateSensitiveWord($id, $payload);
        } else {
            $exists = ModerationRepository::findSensitiveWordId($word);
            if ($exists) {
                return Response::error('该敏感词已存在', 409);
            }
            $payload['created_at'] = Helper::now();
            ModerationRepository::insertSensitiveWord($payload);
        }

        Moderation::clearWordsCache();
        return Response::success(null, '保存成功');
    }

    /**
     * DELETE /admin/sensitive-words —— 删除敏感词
     */
    public function deleteSensitiveWord(Request $request): Response
    {
        $this->requireAdmin($request);

        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }
        ModerationRepository::deleteSensitiveWord($id);
        Moderation::clearWordsCache();

        return Response::success(null, '删除成功');
    }

    /**
     * POST /admin/sensitive-words/import —— 批量导入（一行一个词）
     * {text, level, replacement, scene}
     */
    public function importSensitiveWords(Request $request): Response
    {
        $this->requireAdmin($request);

        $text = (string)$request->getString('text');
        if (trim($text) === '') {
            return Response::error('导入内容不能为空', 400);
        }

        $level = strtolower(trim($request->getString('level'))) ?: 'replace';
        if (!in_array($level, ['replace', 'block', 'review'], true)) {
            $level = 'replace';
        }
        $replacement = trim($request->getString('replacement')) ?: '***';
        $scene = strtolower(trim($request->getString('scene')));

        // 兼容 \r\n / \r / \n 三种换行（Windows 后台与移动端粘贴来源不同）
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $added = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            $w = trim($line);
            if ($w === '') {
                continue;
            }
            $exists = ModerationRepository::findSensitiveWordId($w);
            if ($exists) {
                $skipped++;
                continue;
            }
            ModerationRepository::insertSensitiveWord([
                'word'        => $w,
                'level'       => $level,
                'replacement' => $replacement,
                'scene'       => $scene,
                'status'      => 1,
                'created_at'  => Helper::now(),
                'updated_at'  => Helper::now(),
            ]);
            $added++;
        }

        Moderation::clearWordsCache();
        return Response::success(
            ['added' => $added, 'skipped' => $skipped],
            "导入完成：新增 {$added} 个，跳过重复 {$skipped} 个"
        );
    }

    /**
     * GET /admin/filter-logs —— 敏感词命中记录
     */
    public function filterLogs(Request $request): Response
    {
        $this->requireAdmin($request);

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 100);
        $scene = strtolower(trim($request->getString('scene')));

        $rows = ModerationRepository::fetchFilterLogs($scene, $offset, $limit);
        $total = ModerationRepository::countFilterLogs($scene);

        return Response::success(Helper::paginatedResponse($rows, $page, $limit, $total), '获取成功');
    }

    /**
     * POST /admin/users/mute —— 全站禁言
     * {user_id, minutes? / hours? / days?}（三者可叠加，总和必须 > 0）
     */
    public function muteUser(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        $userId = $request->getInt('user_id') ?: $request->getInt('id');
        if (!$userId) {
            return Response::error('参数错误', 400);
        }

        $totalMinutes = $request->getInt('minutes')
            + $request->getInt('hours') * 60
            + $request->getInt('days') * 1440;
        if ($totalMinutes <= 0) {
            return Response::error('禁言时长必须大于 0', 400);
        }
        if ($error = AdminTargetGuard::check($admin, $userId, AdminTargetGuard::ACTION_MUTE)) {
            return $error;
        }

        $until = date('Y-m-d H:i:s', time() + $totalMinutes * 60);
        ModerationRepository::updateUserMutedUntil($userId, $until, Helper::now());

        // Auth::user() 把 muted_until 缓进了 auth_user_ 前缀，不清的话最长 5 分钟禁言不生效
        Auth::clearUserCache($userId);

        return Response::success(['muted_until' => $until], '已禁言至 ' . $until);
    }

    /**
     * POST /admin/users/unmute —— 解除禁言
     */
    public function unmuteUser(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        $userId = $request->getInt('user_id') ?: $request->getInt('id');
        if (!$userId) {
            return Response::error('参数错误', 400);
        }
        if ($error = AdminTargetGuard::check($admin, $userId, AdminTargetGuard::ACTION_UNMUTE)) {
            return $error;
        }

        ModerationRepository::clearUserMutedUntil($userId, Helper::now());
        Auth::clearUserCache($userId);

        return Response::success(null, '已解除禁言');
    }

}
