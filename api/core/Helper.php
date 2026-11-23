<?php
/**
 * Hublog 通用工具函数集
 * 全局辅助方法，精简控制器重复逻辑
 */

namespace Hublog\Core;

class Helper
{
    /**
     * 获取当前时间字符串
     */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * 人性化时间显示（支持国际化）
     */
    public static function timeAgo(string $datetime, ?string $locale = null): string
    {
        $time = strtotime($datetime);
        $diff = time() - $time;
        $locale = $locale ?? (defined('DEFAULT_LOCALE') ? DEFAULT_LOCALE : 'zh-CN');

        if ($locale === 'en') {
            if ($diff < 60) return 'just now';
            if ($diff < 3600) return floor($diff / 60) . ' min ago';
            if ($diff < 86400) return floor($diff / 3600) . ' hrs ago';
            if ($diff < 604800) return floor($diff / 86400) . ' days ago';
            if ($diff < 2592000) return floor($diff / 604800) . ' wks ago';
            if (date('Y') == date('Y', $time)) return date('M d', $time);
            return date('Y M d', $time);
        }

        if ($diff < 60) return '刚刚';
        if ($diff < 3600) return floor($diff / 60) . '分钟前';
        if ($diff < 86400) return floor($diff / 3600) . '小时前';
        if ($diff < 604800) return floor($diff / 86400) . '天前';
        if ($diff < 2592000) return floor($diff / 604800) . '周前';
        if (date('Y') == date('Y', $time)) return date('m月d日', $time);
        return date('Y年m月d日', $time);
    }

    /**
     * 计算分页偏移量
     */
    public static function pagination(Request $request, int $defaultLimit = 20, int $maxLimit = 50): array
    {
        $page = max(1, $request->getInt('page', 1));
        $limit = min($maxLimit, max(1, $request->getInt('limit', $defaultLimit)));
        $offset = ($page - 1) * $limit;
        return compact('page', 'limit', 'offset');;
    }

    /**
     * 标准化分页响应
     *
     * PHPStan 提级后揪出：分类列表页调用时传了第 5 个参数 ['category' => $category]，
     * 而旧签名只有 4 个 —— 多余实参被 PHP 静默丢弃，分类信息从来没有回给前端。
     * 这里补上可选的第 5 参（会合并进响应体顶层），既有 4 参调用行为完全不变。
     */
    public static function paginatedResponse(array $list, int $page, int $limit, int $total, array $extra = []): array
    {
        return array_merge([
            'list' => $list,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit)
            ]
        ], $extra);
    }

    /**
     * HTML 实体转义（防 XSS 输出）
     */
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * 净化用户名/昵称中的特殊字符
     */
    public static function sanitizeName(string $input): string
    {
        // 仅移除控制字符和危险符号，保留中英文、数字、常用符号
        return preg_replace('/[<>"\'%;&\\{}\x00-\x1F]/', '', $input);
    }

    /**
     * 生成不重复的昵称（V53 起 users.nickname 有唯一索引，写库前必须消歧）
     *
     * 规则：
     *  1. base 未被占用 → 原样返回；
     *  2. 被占用 → 依次追加 _2 / _3 ... _50；
     *  3. 极端情况仍冲突 → 追加随机后缀（保证 INSERT/UPDATE 不会因 1062 直接 500）。
     *
     * 返回长度控制在 users.nickname 的 varchar(50) 以内。
     *
     * @param string $base          期望昵称（空值会回退为「用户」）
     * @param int    $excludeUserId 排除的用户ID；更新自己资料时传自身ID，避免跟自己撞
     */
    public static function uniqueNickname(string $base, int $excludeUserId = 0): string
    {
        $base = trim($base);
        if ($base === '') {
            $base = '用户';
        }
        // 预留后缀空间：_2 ~ _50 最多 3 字符，随机后缀最多 7 字符
        if (mb_strlen($base, 'UTF-8') > 42) {
            $base = mb_substr($base, 0, 42, 'UTF-8');
        }

        $candidate = $base;
        for ($i = 2; $i <= 50; $i++) {
            $sql = "SELECT id FROM users WHERE nickname = ?";
            $params = [$candidate];
            if ($excludeUserId > 0) {
                $sql .= " AND id != ?";
                $params[] = $excludeUserId;
            }
            $sql .= " LIMIT 1";

            if (!Database::fetchColumn($sql, $params)) {
                return $candidate;
            }
            $candidate = $base . '_' . $i;
        }

        return $base . '_' . substr(md5(uniqid('', true)), 0, 6);
    }

    /**
     * 判断一个账号是否已被注销（列表展示时统一匿名化用）
     *
     * 判据（V54）：
     *   - username 形如 deleted_{uid}（注销时统一改写，注册时禁止该前缀）；
     *   - 或昵称是注销时写入的「已注销用户」/「已注销用户_N」
     *     （N 是 users.nickname 唯一索引冲突时自动加的消歧后缀）。
     *
     * 用途：动态/评论列表是 JOIN users 取昵称的，第二个注销的用户昵称会变成
     * 「已注销用户_2」，如果不在这里收敛，前端就会露出这个后缀。
     */
    /**
     * 系统通知虚拟账号 system_notice 的 id（V168：徽章/系统类通知的发送人）。
     *
     * notifications.sender_id 有外键约束（→ users.id）不能为 0，
     * 但徽章这类是系统发出的，不该显示为某个真人/AI 账号。
     * V168 前借用 ai_assistant，于是用户看到发送人是「ai评论家」。
     *
     * 账号由 migrate90 创建（昵称「系统通知」）；迁移没跑过时返回 0，调用方自行兜底。
     */
    public static function getSystemUserId(): int
    {
        try {
            $id = Database::fetchColumn(
                "SELECT id FROM users WHERE username = 'system_notice' AND status = 1 LIMIT 1"
            );
            return (int)($id ?: 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 系统类通知的名称与头像（供展示层统一覆盖，让历史数据也显示成「系统通知」）。
     */
    public const SYSTEM_SENDER_NICKNAME = '系统通知';

    public const SYSTEM_SENDER_AVATAR = '/templates/moments/assets/images/default-avatar.svg';

    public static function isDeletedAccount(?string $username, ?string $nickname = null): bool
    {
        if ($username !== null && strpos((string)$username, 'deleted_') === 0) {
            return true;
        }
        if ($nickname !== null && preg_match('/^已注销用户(_\d+)?$/u', (string)$nickname)) {
            return true;
        }
        return false;
    }
}
