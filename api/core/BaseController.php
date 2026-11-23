<?php
/**
 * Hublog 控制器基类
 * 统一封装分页、响应、缓存清理，消除控制器重复逻辑
 */

namespace Hublog\Core;

class BaseController
{
    /**
     * 标准化分页查询与响应
     */
    protected function paginate(
        Request $request,
        string $sql,
        array $params,
        string $countSql,
        array $countParams,
        callable $formatter = null,
        int $defaultLimit = 20,
        callable $listDecorator = null
    ): Response {
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination($defaultLimit);

        $listParams = array_merge($params, [$offset, $limit]);
        $items = Database::fetchAll($sql . ' LIMIT ?, ?', $listParams);
        if ($formatter) {
            $items = array_map($formatter, $items);
        }
        // V83：整列表装饰器（如批量补内联徽章），在逐行 formatter 之后跑一次，避免 N+1
        if ($listDecorator) {
            $items = $listDecorator($items);
        }
        $total = (int)Database::fetchColumn($countSql, $countParams);

        return Response::success(Helper::paginatedResponse($items, $page, $limit, $total));
    }

    /**
     * 清除指定前缀的缓存
     */
    protected function clearCache(string $prefix): void
    {
        Cache::deleteByPrefix($prefix);
    }

    /**
     * P2-5 鉴权双重化脚手架：路由中间件已校验身份，控制器无需再写一遍
     * 「Auth::user() + 401」块，直接用本方法取当前已认证用户（未登录返回 null）。
     * 后续清理可直接用 $this->currentUser($request) 替代散落各处的重复鉴权代码。
     */
    protected function currentUser(Request $request): ?array
    {
        return Auth::user($request);
    }

    /**
     * 取当前管理员用户，非 admin 返回 null（替代控制器内重复的 admin 鉴权块）。
     */
    protected function currentAdmin(Request $request): ?array
    {
        $user = Auth::user($request);
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            return null;
        }
        return $user;
    }

    /**
     * P2-5 鉴权双重化收口：路由中间件已校验身份，控制器无需再散写
     * 「Auth::user() + 401」块。本方法取当前已认证用户，未登录直接抛 401
     * （与 Auth::admin 抛 403 机制一致，由全局异常处理器转为响应），
     * 调用方直接写 $user = $this->requireUser($request); 即可，省去重复守卫。
     */
    protected function requireUser(Request $request): array
    {
        $user = Auth::user($request);
        if (!$user) {
            throw new Exception\HublogException('请先登录', 401);
        }
        return $user;
    }

    /**
     * 取当前管理员用户，非 admin 直接抛 403（等价于 Auth::admin，统一走 helper）。
     */
    protected function requireAdmin(Request $request): array
    {
        return Auth::admin($request);
    }

    // ==================== 跨 Admin 子类共用的公共 helper ====================
    // 说明：AdminController 拆分子域（P2-4）后，这些方法被多个子类同时调用。
    // PHP 的 import 与方法都不「跨文件继承兄妹类」，父类 AdminController 若保留它们、
    // 兄妹子类（extends AdminController）其实也拿得到 —— 但一旦某个子域被抽走、用到
    // 这些 helper 的方法随子域走了，调用链仍在。放在公共根 BaseController 最稳：
    // 所有控制器（含 38 个）都可继承，且不会再因子域迁移而断链。

    /**
     * 字节数转人类可读体积（后台存储/附件/Dashboard 共用）。
     */
    protected function formatSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 2) . ' KB';
        return round($bytes / (1024 * 1024), 2) . ' MB';
    }

    /**
     * 拉取远端版本信息（Gitee API），失败返回 null。Dashboard 与在线升级共用。
     */
    protected function fetchGiteeApi(string $url): ?array
    {
        // S10：原实现关闭 TLS 校验且不做任何出站 URL 判定，DNS 劫持 / MITM
        // 即可伪造版本信息（后台会据此触发在线升级）。统一走项目 SSRF 白名单。
        $check = Security::validateOutboundUrl($url);
        if (!$check['ok']) {
            return null;
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => 'User-Agent: Hublog-VersionCheck',
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if (!$response) return null;
        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * helper: 插入或更新单个设置项。
     * 后台各子域（设置/聊天/授权）保存配置时共用，避免各处重复写「先查后插」。
     */
    protected function upsertSetting(string $key, string $value, string $now): void
    {
        $exists = Database::fetchColumn(
            "SELECT COUNT(*) FROM settings WHERE `key` = ? LIMIT 1",
            [$key]
        );
        if ($exists) {
            Database::update(
                'settings',
                ['value' => $value, 'updated_at' => $now],
                "`key` = ?",
                [$key]
            );
        } else {
            Database::insert('settings', [
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
