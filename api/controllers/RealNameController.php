<?php
/**
 * 实名认证控制器（V51）
 *
 * 用户端：
 *   GET  /api/realname/status  查询当前用户认证状态
 *   POST /api/realname/verify  提交姓名 + 身份证号做二要素核验
 * 管理端：
 *   GET  /api/admin/realname           认证记录列表（脱敏展示）
 *   POST /api/admin/realname/review    人工通过 / 驳回 / 撤销
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Database;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\RealNameRepository;
use Hublog\Core\Auth;
use Hublog\Core\Helper;
use Hublog\Core\RealName;
use Hublog\Core\BadgeService;

class RealNameController extends BaseController
{
    /** 单账号每日最多核验次数（防止被拿来撞库） */
    private const DAILY_LIMIT = 5;

    /** 唯一键冲突（uk_id_card_hash）判定：MySQL 1062 / SQLSTATE 23000 */
    private static function isDuplicateKey(\Throwable $e): bool
    {
        return (int)($e->errorInfo[1] ?? 0) === 1062
            || ($e instanceof \PDOException && ($e->errorInfo[0] ?? '') === '23000');
    }

    /**
     * 当前用户实名状态
     */
    public function status(Request $request): Response
    {
        $user = Auth::check($request);

        // Auth::user 有 5 分钟缓存，认证状态必须实时查库，否则刚通过会显示未认证
        $me = RealNameRepository::findUserVerification((int)$user['id']);
        $row = RealNameRepository::findLatestRecord((int)$user['id']);

        $cfg = RealName::config();

        return Response::success([
            'available'      => RealName::available(),
            'provider'       => $cfg['provider'],
            'provider_label' => RealName::providerLabel($cfg['provider']),
            'providers'      => RealName::providers(),
            'verified'       => (bool)($me['realname_verified'] ?? 0),
            'verified_at'    => $me['realname_verified_at'] ?? null,
            'status'         => $row['status'] ?? null,
            'real_name'      => $row['real_name_masked'] ?? '',
            'id_card'        => $row['id_card_masked'] ?? '',
            'fail_reason'    => $row['fail_reason'] ?? '',
            'recorded_at'    => $row['created_at'] ?? null,
        ]);
    }

    /**
     * 提交实名核验
     */
    public function verify(Request $request): Response
    {
        $user = Auth::check($request);

        if (!RealName::config()['enabled']) {
            return Response::error('实名认证功能未开启', 403);
        }
        $me = RealNameRepository::findVerifiedFlag((int)$user['id']);
        // 已通过认证不再重复提交
        if (!empty($me['realname_verified'])) {
            return Response::error('你已完成实名认证', 400);
        }

        $name   = trim($request->getString('real_name'));
        $idCard = strtoupper(trim($request->getString('id_card')));

        if (!RealName::checkName($name)) {
            return Response::error('请输入真实姓名（2-30 位中文或英文）', 400);
        }
        if (!RealName::checkIdCard($idCard)) {
            return Response::error('身份证号格式不正确', 400);
        }

        // 频率限制：同一用户每天最多 5 次
        $todayCount = RealNameRepository::countTodaySubmissions((int)$user['id']);
        if ($todayCount >= self::DAILY_LIMIT) {
            return Response::error('今日核验次数已达上限，请明天再试', 429);
        }

        $hash = RealName::hashIdCard($idCard);

        // 查重：同一身份证已被其他账号使用则拒绝
        $existing = RealNameRepository::findRecordByHash($hash);
        if ($existing && (int)$existing['user_id'] !== (int)$user['id']) {
            return Response::error('该身份证号已被其他账号使用', 409);
        }

        $provider = RealName::config()['provider'];
        $now      = date('Y-m-d H:i:s');
        $record = [
            'user_id'         => $user['id'],
            'real_name_masked' => RealName::maskName($name),
            'id_card_masked'  => RealName::maskIdCard($idCard),
            'id_card_hash'    => $hash,
            'provider'        => $provider,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        // 外部 HTTP 核验不进事务；只有短写序列（清理旧记录 + 插入+状态回写）才包事务。
        try {
            // 未配置密钥 → 直接落 pending，由后台人工复核
            if (!RealName::available()) {
                RealNameRepository::submitVerification(array_merge($record, [
                    'status'        => RealName::STATUS_PENDING,
                    'fail_reason'   => '待人工复核（未配置自动核验服务）',
                    'provider_code' => 'manual',
                ]), (int)$user['id'], true);
                return Response::success([
                    'status'  => RealName::STATUS_PENDING,
                    'message' => '已提交，等待人工审核',
                ]);
            }

            $result = RealName::verify($name, $idCard);

            if ($result['ok']) {
                $this->markVerified((int)$user['id'], $name, $idCard, $hash, $provider, $result, $now);
                return Response::success([
                    'status'  => RealName::STATUS_VERIFIED,
                    'message' => '实名认证通过',
                ]);
            }

            if ($result['pending']) {
                RealNameRepository::submitVerification(array_merge($record, [
                    'status'        => RealName::STATUS_PENDING,
                    'fail_reason'   => $result['message'],
                    'provider_code' => $result['code'],
                ]), (int)$user['id'], true);
                return Response::success([
                    'status'  => RealName::STATUS_PENDING,
                    'message' => $result['message'],
                ]);
            }

            RealNameRepository::submitVerification(array_merge($record, [
                'status'        => RealName::STATUS_REJECTED,
                'fail_reason'   => $result['message'],
                'provider_code' => $result['code'],
            ]), (int)$user['id'], true);

            return Response::error($result['message'] ?: '实名核验未通过', 422, [
                'status'       => RealName::STATUS_REJECTED,
                'provider_code' => $result['code'],
            ]);
        } catch (\Throwable $e) {
            Database::rollBack(); // 事务已由仓储收口；此处仅兜底，无活动事务时为 no-op
            if (self::isDuplicateKey($e)
                || ($e instanceof \Hublog\Core\Exception\HublogException && (int)$e->getCode() === 409)) {
                return Response::error('该身份证号已被其他账号使用', 409);
            }
            throw $e;
        }
    }

    /**
     * 后台：认证记录列表
     */
    public function adminList(Request $request): Response
    {
        $this->requireAdmin($request);

        $page    = max(1, $request->getInt('page', 1));
        $limit   = min(50, max(5, $request->getInt('limit', 20)));
        $status  = $request->getString('status');
        $keyword = trim($request->getString('keyword'));
        $offset  = ($page - 1) * $limit;

        // WHERE 助手与 LIMIT/OFFSET 绑定已下沉，计数与列表共用同一套条件
        $total = RealNameRepository::countAdminRecords($status, $keyword);
        $list = RealNameRepository::fetchAdminRecords($status, $keyword, $limit, $offset);
        foreach ($list as &$item) {
            $item['provider_label'] = RealName::providerLabel((string)($item['provider'] ?? ''));
        }
        unset($item);

        return Response::success(Helper::paginatedResponse($list, $page, $limit, $total));
    }

    /**
     * 后台：人工审核（approve 通过 / reject 驳回 / revoke 撤销）
     */
    public function adminReview(Request $request): Response
    {
        $admin  = $this->requireAdmin($request);
        $id     = $request->getInt('id');
        $action = $request->getString('action');
        $reason = trim($request->getString('reason'));

        if (!$id) {
            return Response::error('记录ID不能为空', 400);
        }
        if (!in_array($action, ['approve', 'reject', 'revoke'], true)) {
            return Response::error('操作类型无效', 400);
        }

        $record = RealNameRepository::findRecordById($id);
        if (!$record) {
            return Response::error('记录不存在', 404);
        }

        $now = date('Y-m-d H:i:s');

        try {
            if ($action === 'approve') {
                // 该身份证已被其他通过记录的账号占用时拒绝重复通过
                $conflict = RealNameRepository::findVerifiedConflict(
                    (string)$record['id_card_hash'],
                    (int)$record['user_id']
                );
                if ($conflict) {
                    return Response::error('该身份证号已被其他账号认证通过', 409);
                }
                RealNameRepository::approveWithUser($id, (int)$record['user_id'], [
                    'reviewer_id' => $admin['id'], 'now' => $now,
                ]);
            } elseif ($action === 'reject') {
                RealNameRepository::rejectWithUser($id, (int)$record['user_id'], [
                    'fail_reason' => $reason ?: '人工审核未通过',
                    'reviewer_id' => $admin['id'],
                    'now' => $now,
                ]);
            } else {
                RealNameRepository::revokeWithUser($id, (int)$record['user_id'], [
                    'fail_reason' => $reason ?: '管理员撤销认证',
                    'reviewer_id' => $admin['id'],
                    'now' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            Database::rollBack(); // 仓储已回滚；无活动事务时 no-op
            if ($action === 'approve' && self::isDuplicateKey($e)) {
                return Response::error('该身份证号已被其他账号使用', 409);
            }
            throw $e;
        }

        return Response::success(['id' => $id, 'status' => RealNameRepository::fetchRecordStatus($id)]);
    }

    /**
     * 核验通过：写记录 + 更新用户冗余状态
     */
    private function markVerified(
        int $userId,
        string $name,
        string $idCard,
        string $hash,
        string $provider,
        array $result,
        string $now
    ): void {
        try {
            RealNameRepository::insertVerifiedAndSetUser([
                'user_id'         => $userId,
                'real_name_masked' => RealName::maskName($name),
                'id_card_masked'  => RealName::maskIdCard($idCard),
                'id_card_hash'    => $hash,
                'provider'        => $provider,
                'status'          => RealName::STATUS_VERIFIED,
                'fail_reason'     => '',
                'provider_code'   => $result['code'],
                'reviewer_id'     => 0,
                'reviewed_at'     => $now,
                'verified_at'     => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ], $userId, $now);
        } catch (\Throwable $e) {
            Database::rollBack(); // 仓储已回滚；无活动事务时 no-op
            if (self::isDuplicateKey($e)) {
                throw new \Hublog\Core\Exception\HublogException('该身份证号已被其他账号使用', 409);
            }
            throw $e;
        }

        // V82：事务提交后再触发徽章，避免回滚后留下错误的徽章状态
        BadgeService::onEvent($userId, 'realname_verified');
    }
}
