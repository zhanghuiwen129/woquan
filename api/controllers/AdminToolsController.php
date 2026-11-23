<?php
declare(strict_types=1);
/**
 * Hublog 后台「工具与导出」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\AdminRepository;
use Hublog\Core\SettingsRepository;
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
use Hublog\Core\Sitemap;
use Hublog\Core\PluginStateRepository;

class AdminToolsController extends AdminController
{
    public function exportData(Request $request): Response
    {
        $this->requireAdmin($request);
        $type = $request->getString('type', 'users'); // users, posts, comments, all
        $format = $request->getString('format', 'json'); // json, csv

        $data = [];
        switch ($type) {
            case 'users':
                $data = AdminRepository::fetchExportUsersOrdered();
                break;
            case 'posts':
                $data = AdminRepository::fetchExportPostsOrdered();
                break;
            case 'comments':
                $data = AdminRepository::fetchExportCommentsOrdered();
                break;
            case 'all':
                // 注意：all 分支的三条 SQL 与上面单类型分支字段/排序不同
                // （comments 不带 is_ai），刻意各建独立方法，不复用。
                $data = [
                    'users' => AdminRepository::fetchExportUsersAll(),
                    'posts' => AdminRepository::fetchExportPostsAll(),
                    'comments' => AdminRepository::fetchExportCommentsAll(),
                ];
                break;
            default:
                return Response::error('不支持的导出类型', 400);
        }

        if ($format === 'csv') {
            // CSV 导出
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="hublog_' . $type . '_' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            if (!empty($data)) {
                fputcsv($output, array_keys($data[0]));
                foreach ($data as $row) {
                    fputcsv($output, $row);
                }
            }
            fclose($output);
            exit;
        }

        // JSON 导出
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="hublog_' . $type . '_' . date('Y-m-d') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 运营数据统计
     */
    public function stats(Request $request): Response
    {
        $this->requireAdmin($request);
        $startDate = $request->getString('start_date', date('Y-m-d', strtotime('-30 days')));
        $endDate = $request->getString('end_date', date('Y-m-d'));

        // 每日发布趋势
        $postsTrend = AdminRepository::fetchPostsTrend($startDate, $endDate);

        // 每日浏览量
        $viewsTrend = AdminRepository::fetchViewsTrend($startDate, $endDate);

        // 活跃用户（30天内有发布或评论）
        $activeUsers = AdminRepository::fetchActiveUsersCount();

        // 分类统计
        $categoryStats = AdminRepository::fetchCategoryStats();

        // 话题统计（Top 10）
        $topicStats = AdminRepository::fetchTopicStats();

        // 用户增长趋势
        $userGrowth = AdminRepository::fetchUserGrowth($startDate, $endDate);

        // 总览数据
        $summary = [
            'total_users' => AdminRepository::countActiveUsers(),
            'total_posts' => AdminRepository::countActivePosts(),
            'total_comments' => AdminRepository::countAllComments(),
            'total_views' => AdminRepository::countTotalViews(),
            'active_users_30d' => (int)$activeUsers,
            'new_users_30d' => AdminRepository::countNewUsers30d(),
        ];

        return Response::success([
            'summary' => $summary,
            'posts_trend' => $postsTrend,
            'views_trend' => $viewsTrend,
            'user_growth' => $userGrowth,
            'category_stats' => $categoryStats,
            'topic_stats' => $topicStats,
        ]);
    }

    /**
     * 话题列表
     */
    public function topics(Request $request): Response
    {
        $this->requireAdmin($request);
        $topics = AdminRepository::fetchAllTopics();
        return Response::success(['list' => $topics]);
    }

    /**
     * 保存话题（新增/编辑）
     */
    public function saveTopic(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        $name = $request->getString('name');
        $description = $request->getString('description');
        $status = $request->getInt('status', 1);
        $cover = $request->getString('cover');

        if (empty($name)) {
            return Response::error('话题名称不能为空', 400);
        }

        // V65：话题名规范化 —— 纯 ASCII 的话题统一小写，否则 #Android开发 与 #android开发
        // 会被当成两个话题，热度拆散、详情页各空各的（中文话题一个字节都不动）。
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $name)) ?: strtolower(bin2hex(random_bytes(4)));

        if ($id > 0) {
            $fields = [
                'name' => $name, 'slug' => $slug, 'description' => $description,
                'status' => $status, 'updated_at' => Helper::now()
            ];
            // V95：话题头图（后台话题管理可设置），仅当本次传入了 cover 才更新，避免误清空
            if ($cover !== '') {
                $fields['cover'] = $cover;
            }
            AdminRepository::updateTopicFields($id, $fields);
        } else {
            AdminRepository::insertTopic(
                $name,
                $slug,
                $description,
                $cover,
                $status,
                Helper::now(),
                Helper::now()
            );
        }
        Cache::delete('topics_list');
        return Response::success(null, '保存成功');
    }

    /**
     * 单独设置话题头图（后台话题管理用）
     * POST /admin/topics/cover  body: { id, cover }
     */
    public function setTopicCover(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        $cover = $request->getString('cover');
        if (!$id) {
            return Response::error('参数错误', 400);
        }
        AdminRepository::setTopicCover($id, $cover, Helper::now());
        Cache::delete('topics_list');
        return Response::success(null, '话题头图已更新');
    }

    /**
     * 删除话题
     */
    public function deleteTopic(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }
        AdminRepository::deleteTopic($id);
        Cache::delete('topics_list');
        return Response::success(null, '删除成功');
    }

    /**
     * 插件列表（含元信息 + 启用状态）
     */
    public function plugins(Request $request): Response
    {
        $this->requireAdmin($request);

        // Plugin::scanAll() 从每个插件的 Plugin.php 顶部 docblock 解析元信息：
        //   @name / @description / @version / @author / @link
        $plugins = \Hublog\Core\Plugin::scanAll();

        return Response::success(['list' => $plugins]);
    }

    /**
     * 启用/禁用插件
     */
    public function togglePlugin(Request $request): Response
    {
        $this->requireAdmin($request);
        $name = $request->getString('name');
        $enabled = $request->getBool('enabled');

        if (empty($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $name)) {
            return Response::error('无效的插件名称', 400);
        }

        // E2-1：plugin_states 是唯一可信源（写库后仓储内部会删除 plugin_states 缓存）；
        // 入参校验与返回体逐字不变，清缓存后下一次请求仍从 DB 重建状态。
        PluginStateRepository::setEnabled($name, $enabled);

        return Response::success(['enabled' => $enabled], $enabled ? '插件已启用' : '插件已禁用');
    }

    /**
     * 定时自动备份（cron 调用）
     *
     * 【安全策略】
     * 1. 必须通过 POST 调用（CsrfMiddleware 会拦截伪造跨站请求的 GET/POST 形式）
     * 2. 必须带 backup_cron_secret ，且 secret 必须 ≥ 16 字符；未配置 secret 时拒绝外部调用（防止空 secret 被撞）
     * 3. 提供了正确 secret 时视为 cron 调用，不再强制管理员登录；否则必须是管理员身份
     */
    public function autoBackup(Request $request): Response
    {
        $cronSecret = $request->getString('secret');

        $settings = Cache::remember('site_settings', function () {
            $rows = SettingsRepository::fetchKeyValueRows();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 300);

        $configuredSecret = (string)($settings['backup_cron_secret'] ?? '');

        // 未配置 secret：直接拒绝，防止被空值/默认值绕过
        if ($configuredSecret === '') {
            return Response::error('未配置自动备份 Cron Secret，已阻止外部调用。请先在后台「系统设置 → 备份」里设置 ≥ 16 位的密钥。', 403);
        }
        if (strlen($configuredSecret) < 16) {
            return Response::error('Cron Secret 长度不足 16 位，不安全，请在后台「系统设置 → 备份」里修改。', 403);
        }

        // 双验证：cron secret 严格 hash 比较防时序，或管理员身份
        $pass = false;
        if ($cronSecret !== '' && hash_equals($configuredSecret, $cronSecret)) {
            $pass = true;
        } else {
            try {
                $this->requireAdmin($request);
                $pass = true;
            } catch (\Throwable $e) {
                $pass = false;
            }
        }
        if (!$pass) {
            return Response::error('自动备份需要合法 Cron Secret 或管理员身份', 403);
        }

        // 检查是否启用自动备份
        if (($settings['backup_auto_enabled'] ?? '0') !== '1') {
            return Response::error('自动备份未启用', 400);
        }

        // 执行备份
        $backup = new \Hublog\Core\Upgrade();
        $result = $backup->createBackup();

        // 清理旧备份，保留指定数量
        $keepCount = (int)($settings['backup_auto_keep'] ?? 7);
        if ($keepCount < 1) $keepCount = 1;
        $backupDir = __DIR__ . '/../../backups/';
        $backups = array_merge(
            (array)glob($backupDir . '*.sql'),
            (array)glob($backupDir . '*.json')
        );
        if (count($backups) > $keepCount) {
            usort($backups, fn($a, $b) => filemtime($b) - filemtime($a));
            for ($i = $keepCount; $i < count($backups); $i++) {
                $toDelete = (string)$backups[$i];
                // 二次确保路径在 backups/ 下，避免误删
                if (str_starts_with(realpath($toDelete) ?: $toDelete, realpath($backupDir) ?: $backupDir)) {
                    @unlink($toDelete);
                }
            }
        }

        return Response::success([
            'backup' => $result,
            'kept_count' => min($keepCount, count($backups))
        ], '自动备份完成');
    }

    /**
     * 邮件发送测试
     */
    public function testMail(Request $request): Response
    {
        $this->requireAdmin($request);
        $to = $request->getString('to');
        if (empty($to)) {
            return Response::error('收件邮箱不能为空', 400);
        }

        $mailer = new \Hublog\Core\Mailer();
        $sent = $mailer->send($to, 'Hublog 邮件测试', '<h2>邮件测试成功</h2><p>这是来自 Hublog 系统的测试邮件，如果您收到此邮件，说明邮件配置正确。</p><p>发送时间：' . date('Y-m-d H:i:s') . '</p>');

        if ($sent) {
            return Response::success(null, '测试邮件发送成功');
        }
        return Response::error('测试邮件发送失败，请检查邮件配置', 500);
    }

    /**
     * 生成 sitemap.xml
     *
     * 与根路径 /sitemap.xml 共用 Hublog\Core\Sitemap，保证两处输出的 loc 格式完全一致
     * （sitemap 的 <loc> 必须是绝对地址，相对路径会被搜索引擎忽略）。
     */
    public function sitemap(Request $request): Response
    {
        $settings = Cache::remember('site_settings', function () {
            $rows = SettingsRepository::fetchKeyValueRows();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 300);

        header('Content-Type: application/xml; charset=utf-8');
        echo Sitemap::xml($settings, PathResolver::baseUrl());
        exit;
    }

    /**
     * 生成 robots.txt
     */
    public function robots(Request $request): Response
    {
        $settings = Cache::remember('site_settings', function () {
            $rows = SettingsRepository::fetchKeyValueRows();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 300);

        $baseUrl = PathResolver::baseUrl();
        $robotsTxt = $settings['seo_robots_txt'] ?? "User-agent: *\nAllow: /";
        $robotsTxt .= "\n\nSitemap: " . $baseUrl . "/sitemap.xml";

        header('Content-Type: text/plain; charset=utf-8');
        echo Sitemap::robots($settings, $baseUrl);
        exit;
    }

    /* ================================================================
     * 私信管理接口
     * ================================================================ */

}
