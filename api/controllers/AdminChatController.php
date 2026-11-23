<?php
declare(strict_types=1);
/**
 * Hublog 后台「聊天后台」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Database;
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

class AdminChatController extends AdminController
{
    public function chatConversations(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $page = max(1, (int)$request->get('page', 1));
        $limit = max(1, min(100, (int)$request->get('limit', 20)));
        $keyword = trim((string)$request->get('keyword', ''));
        $includeDeleted = ((string)$request->get('include_deleted', '0')) === '1';

        $result = \Hublog\Core\ChatService::adminGetConversations($page, $limit, $keyword, $includeDeleted);
        \Hublog\Core\ChatService::adminLog(
            (int)$admin['id'], 'chat.conversations', 'conversation', '',
            json_encode(['keyword' => $keyword, 'include_deleted' => $includeDeleted], JSON_UNESCAPED_UNICODE),
            $request->getIp()
        );
        return Response::success($result);
    }

    public function chatMessages(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $page = max(1, (int)$request->get('page', 1));
        $limit = max(1, min(100, (int)$request->get('limit', 30)));
        $filters = [
            'conversation_id' => $request->get('conversation_id'),
            'type' => $request->get('type'),
            'keyword' => trim((string)$request->get('keyword', '')),
            'sender_id' => $request->get('sender_id'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
            'include_deleted' => ((string)$request->get('include_deleted', '0')) === '1',
            'deleted_only' => ((string)$request->get('deleted_only', '0')) === '1',
        ];
        $result = \Hublog\Core\ChatService::adminGetMessages($page, $limit, array_filter($filters));
        \Hublog\Core\ChatService::adminLog(
            (int)$admin['id'], 'chat.messages', 'message', (string)($filters['conversation_id'] ?? ''),
            json_encode(array_filter($filters), JSON_UNESCAPED_UNICODE),
            $request->getIp()
        );
        return Response::success($result);
    }

    /**
     * GET /admin/chat/deletions — 删除流水（谁删了什么，附原文快照）
     */
    public function chatDeletions(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $page = max(1, (int)$request->get('page', 1));
        $limit = max(1, min(100, (int)$request->get('limit', 30)));
        $filters = array_filter([
            'conversation_id' => $request->get('conversation_id'),
            'operator_id' => $request->get('operator_id'),
            'scope' => $request->get('scope'),
            'keyword' => trim((string)$request->get('keyword', '')),
        ]);
        $result = \Hublog\Core\ChatService::adminGetDeletions($page, $limit, $filters);
        \Hublog\Core\ChatService::adminLog(
            (int)$admin['id'], 'chat.deletions', 'message_deletion', '',
            json_encode($filters, JSON_UNESCAPED_UNICODE), $request->getIp()
        );
        return Response::success($result);
    }

    /**
     * POST /admin/chat/restore — 恢复一条被删消息
     */
    public function chatRestoreMessage(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $id = (int)$request->get('id');
        if (!$id) return Response::error('缺少消息ID', 400);
        $result = \Hublog\Core\ChatService::adminRestoreMessage($id, (int)$admin['id'], $request->getIp());
        \Hublog\Core\ChatService::adminLog((int)$admin['id'], 'chat.restore', 'message', (string)$id, '', $request->getIp());
        return Response::success($result, '已恢复');
    }

    /**
     * CSV 公式注入中和：把用户可控文本开头的危险字符前补一个单引号，
     * Excel/WPS 打开时整格按文本处理，不会把内容当公式执行。
     *
     * @param mixed $value 原始字段值（可能为 null / 数字 / 字符串）
     * @return string 可安全写入 CSV 的文本
     */
    private static function csvSafe($value): string
    {
        $s = (string)$value;
        // 制表符 / 回车也被部分电子表格软件视为空白前缀，一并中和。
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $s = "'" . $s;
        }
        return $s;
    }

    /**
     * GET /admin/chat/export — 导出聊天记录 CSV（含已删除消息与指纹，取证用）
     */
    public function chatExport(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $filters = array_filter([
            'conversation_id' => $request->get('conversation_id'),
            'type' => $request->get('type'),
            'keyword' => trim((string)$request->get('keyword', '')),
            'sender_id' => $request->get('sender_id'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
        ]);
        $filters['include_deleted'] = true;

        $rows = \Hublog\Core\ChatService::adminExportRows($filters, 5000);

        \Hublog\Core\ChatService::adminLog(
            (int)$admin['id'], 'chat.export', 'message', (string)($filters['conversation_id'] ?? ''),
            json_encode(['rows' => count($rows), 'filters' => $filters], JSON_UNESCAPED_UNICODE),
            $request->getIp()
        );

        $filename = 'chat-export-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            '消息ID', '会话ID', '会话成员ID', '发送者ID', '发送者', '类型', '内容',
            '发送时间', '是否已删', '删除时间', '删除人', '删除类型', '内容指纹', '指纹校验'
        ]);
        foreach ($rows as $r) {
            $scopeMap = ['self' => '用户自己删除', 'admin' => '管理员下架', 'purge' => '系统清理'];
            fputcsv($out, [
                $r['id'],
                $r['conversation_id'],
                (string)($r['member_ids'] ?? ''),
                $r['sender_id'],
                self::csvSafe($r['nickname'] ?: $r['username']),
                $r['type'],
                self::csvSafe($r['content']),
                $r['created_at'],
                !empty($r['deleted_at']) ? '是' : '否',
                $r['deleted_at'] ?? '',
                self::csvSafe(!empty($r['deleted_by']) ? (($r['deleted_by_nickname'] ?: $r['deleted_by_username']) . '#' . $r['deleted_by']) : ''),
                $scopeMap[$r['delete_scope'] ?? ''] ?? '',
                (string)($r['content_hash'] ?? ''),
                isset($r['hash_ok']) ? ($r['hash_ok'] ? '一致' : '不一致') : '无指纹'
            ]);
        }
        fclose($out);
        exit;
    }

    public function chatDeleteMessage(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $id = (int)$request->get('id');
        if (!$id) return Response::error('缺少消息ID', 400);
        $result = \Hublog\Core\ChatService::adminDeleteMessage($id, (int)$admin['id'], $request->getIp());
        return Response::success($result, '已下架（记录保留，可在删除流水查看）');
    }

    public function chatBatchDelete(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $ids = $request->get('ids', []);
        if (!is_array($ids) || empty($ids)) return Response::error('请选择要删除的消息', 400);
        $result = \Hublog\Core\ChatService::adminBatchDeleteMessages($ids, (int)$admin['id'], $request->getIp());
        return Response::success($result, '批量下架完成（记录保留）');
    }

    public function chatCleanupExpired(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $days = max(1, (int)$request->get('days', 90));
        $types = $request->get('types', []);
        if (!is_array($types) || empty($types)) $types = [];
        $result = \Hublog\Core\ChatService::adminCleanupExpired($days, $types, (int)$admin['id'], $request->getIp());
        return Response::success($result, '清理完成（已标记为已删，记录仍保留）');
    }

    public function chatDeleteConversation(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $id = (int)$request->get('id');
        if (!$id) return Response::error('缺少会话ID', 400);
        $result = \Hublog\Core\ChatService::adminDeleteConversation($id, (int)$admin['id'], $request->getIp());
        return Response::success($result, '会话已下架（记录保留，可勾「含已下架」查看）');
    }

    public function chatStorageStats(Request $request): Response
    {
        $this->requireAdmin($request);
        $result = \Hublog\Core\ChatService::adminStorageStats();
        return Response::success($result);
    }

    public function chatCleanupOrphan(Request $request): Response
    {
        $this->requireAdmin($request);
        $result = \Hublog\Core\ChatService::adminCleanupOrphanFiles();
        return Response::success($result, '孤儿文件清理完成');
    }

    public function chatSaveSettings(Request $request): Response
    {
        $this->requireAdmin($request);
        $now = Helper::now();
        $keys = [
            'chat_max_image_size' => '10485760',
            'chat_max_video_size' => '52428800',
            'chat_video_compress' => '1',
            'chat_max_image_width' => '1600',
            'chat_cleanup_days' => '0',
        ];
        foreach ($keys as $k => $default) {
            $val = $request->get($k, null);
            if ($val !== null) {
                $this->upsertSetting($k, (string)$val, $now);
            }
        }
        Cache::delete('site_settings');
        return Response::success(null, '设置已保存');
    }

    public function chatGetSettings(Request $request): Response
    {
        $this->requireAdmin($request);
        $settings = [
            'chat_max_image_size' => (int)(\Hublog\Core\License::getSetting('chat_max_image_size', '10485760') ?: 10485760),
            'chat_max_video_size' => (int)(\Hublog\Core\License::getSetting('chat_max_video_size', '52428800') ?: 52428800),
            'chat_video_compress' => (int)(\Hublog\Core\License::getSetting('chat_video_compress', '1') ?: 1),
            'chat_max_image_width' => (int)(\Hublog\Core\License::getSetting('chat_max_image_width', '1600') ?: 1600),
            'chat_cleanup_days' => (int)(\Hublog\Core\License::getSetting('chat_cleanup_days', '0') ?: 0),
        ];
        return Response::success($settings);
    }
    // upsertSetting 已上提到 BaseController（P2-4 拆域后被多个 Admin 子类共用）
}
