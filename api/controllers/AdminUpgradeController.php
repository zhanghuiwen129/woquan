<?php

namespace Hublog\Controllers;

use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\SystemRepository;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\PathResolver;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Upgrade;

class AdminUpgradeController extends AdminController
{
    /**
     * 检测 Gitee 仓库是否有新版本
     */
    public function versionCheck(Request $request): Response
    {
        $this->requireAdmin($request);

        $currentVersion = defined('APP_VERSION') ? APP_VERSION : 'v2.9.0';
        $cacheKey = 'admin_version_check';

        // 如果缓存的版本比当前程序版本还旧，强制重新检测（例如刚升级完）
        $cached = Cache::get($cacheKey);
        if ($cached && isset($cached['latest']) && version_compare(ltrim($currentVersion, 'vV'), ltrim($cached['latest'], 'vV'), '>')) {
            Cache::delete($cacheKey);
        }

        $result = Cache::remember($cacheKey, function () use ($currentVersion) {
            // 动态获取远程仓库配置（优先 settings 表，默认 aiwoquan/woquan）
            $repoOwner = 'aiwoquan';
            $repoName  = 'woquan';
            $repoProvider = 'gitee';
            try {
                // 白名单 key 列表与 FETCH_KEY_PAIR 返回形态已下沉到仓储本体
                $rows = SystemRepository::fetchUpgradeRepoSettings();
                if (!empty($rows['upgrade_repo_owner']))    $repoOwner    = $rows['upgrade_repo_owner'];
                if (!empty($rows['upgrade_repo_name']))     $repoName     = $rows['upgrade_repo_name'];
                if (!empty($rows['upgrade_repo_provider'])) $repoProvider = $rows['upgrade_repo_provider'];
            } catch (\Throwable $e) { /* 忽略，用默认值 */ }

            $latest = null;
            $downloadUrl = '';
            $releasePage = "https://gitee.com/{$repoOwner}/{$repoName}/releases";

            // 优先获取最新 Release（Gitee）
            if ($repoProvider === 'gitee') {
                $release = $this->fetchGiteeApi("https://gitee.com/api/v5/repos/{$repoOwner}/{$repoName}/releases/latest");
                if ($release && !empty($release['tag_name'])) {
                    $latest = ltrim($release['tag_name'], 'vV');
                    $downloadUrl = $release['html_url'] ?? $releasePage;
                } else {
                    // 无 Release 时回退到标签列表
                    $tags = $this->fetchGiteeApi("https://gitee.com/api/v5/repos/{$repoOwner}/{$repoName}/tags");
                    if (!empty($tags) && is_array($tags) && !empty($tags[0]['name'])) {
                        $latest = ltrim($tags[0]['name'], 'vV');
                    }
                }
            } else {
                // GitHub 等其他平台：简单走 tags API
                $tags = $this->fetchGiteeApi("https://api.github.com/repos/{$repoOwner}/{$repoName}/tags");
                if (!empty($tags) && is_array($tags) && !empty($tags[0]['name'])) {
                    $latest = ltrim($tags[0]['name'], 'vV');
                    $releasePage = "https://github.com/{$repoOwner}/{$repoName}/releases";
                }
            }

            // 兜底：如果完全拿不到远程版本，直接用当前版本（无更新）
            if (!$latest) {
                return ['has_new' => false, 'current' => $currentVersion, 'latest' => $currentVersion,
                        'download_url' => '', 'release_page' => $releasePage,
                        'message' => '无法获取远程版本信息（仓库可能不存在或网络异常）'];
            }

            return [
                'has_new'      => version_compare($latest, ltrim($currentVersion, 'vV'), '>'),
                'current'      => $currentVersion,
                'latest'       => 'v' . $latest,
                'download_url' => $downloadUrl ?: "https://{$repoProvider}.com/{$repoOwner}/{$repoName}",
                'release_page' => $releasePage,
                'repo'         => ['provider' => $repoProvider, 'owner' => $repoOwner, 'name' => $repoName],
                'message'      => '检测成功'
            ];
        }, 3600);

        return Response::success($result);
    }

    public function upgrade(Request $request): Response
    {
        $this->requireAdmin($request);

        if (empty($_FILES['package']) || $_FILES['package']['error'] !== UPLOAD_ERR_OK) {
            return Response::error('请上传升级包', 400);
        }

        $file = $_FILES['package'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return Response::error('升级包必须是 zip 格式', 400);
        }

        $maxSize = 100 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            return Response::error('升级包大小超过 100MB 限制', 400);
        }

        $tmpPath = $file['tmp_name'];
        // MIME 校验：fileinfo 不可用时降级为 mime_content_type，均不可用时跳过（扩展名校验已兜底）
        $zipMimes = ['application/zip', 'application/x-zip-compressed'];
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            if (!in_array($mime, $zipMimes, true)) {
                return Response::error('文件类型不是合法的 zip 压缩包', 400);
            }
        } elseif (function_exists('mime_content_type')) {
            $mime = @mime_content_type($tmpPath);
            if ($mime && !in_array($mime, $zipMimes, true)) {
                return Response::error('文件类型不是合法的 zip 压缩包', 400);
            }
        }

        try {
            $upgrade = new Upgrade();
            $result = $upgrade->run($tmpPath);
            @unlink($tmpPath);
            return Response::success($result, $result['message']);
        } catch (HublogException $e) {
            @unlink($tmpPath);
            return Response::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            return Response::error('升级失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 升级前差异预览
     * 管理员上传 zip 升级包 → 后端只解压、不覆盖 → 对比远程文件清单与本地文件清单，
     * 返回 added / modified / removed / protected / skipped 分类报告，
     * 前端可以据此生成「升级会动哪些文件」的预览表格，避免升级时误操作或意料之外的变更。
     *
     * 同时检查 cache/local_fingerprint.json（上次升级成功后自动生成），
     * 如果管理员在两次升级之间手动改过某些文件，会出现在 skipped_local_custom 里给出警告。
     */
    public function upgradeCompare(Request $request): Response
    {
        $this->requireAdmin($request);

        if (empty($_FILES['package']) || $_FILES['package']['error'] !== UPLOAD_ERR_OK) {
            return Response::error('请上传升级包', 400);
        }

        $file = $_FILES['package'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return Response::error('升级包必须是 zip 格式', 400);
        }

        $maxSize = 100 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            return Response::error('升级包大小超过 100MB 限制', 400);
        }

        try {
            $upgrade = new Upgrade();
            $report = $upgrade->comparePackage($file['tmp_name'], true);
            @unlink($file['tmp_name']);

            $summary = $report['summary'];
            $parts = [];
            if ($summary['added'] > 0)    $parts[] = "{$summary['added']} 个新增文件";
            if ($summary['modified'] > 0) $parts[] = "{$summary['modified']} 个修改文件";
            if ($summary['removed'] > 0)  $parts[] = "{$summary['removed']} 个待删除文件";
            if ($summary['protected'] > 0) $parts[] = "{$summary['protected']} 个受保护文件（不覆盖）";
            $msg = '差异分析完成：' . ($parts ? implode('，', $parts) : '远程与本地完全一致，无需升级');

            return Response::success($report, $msg);
        } catch (HublogException $e) {
            @unlink($file['tmp_name']);
            return Response::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            @unlink($file['tmp_name']);
            return Response::error('差异分析失败：' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // 在线升级（从远程仓库自动下载 → 对比 → 升级）
    // ================================================================

    /**
     * 在线升级预检：获取远程仓库分支、commit、当前本地版本
     * 用于后台「系统升级」页面展示远程状态，不下载大文件。
     */
    public function onlinePreflight(Request $request): Response
    {
        $this->requireAdmin($request);
        $ref = $request->query('ref'); // 可选：指定分支/commit/tag

        try {
            $upgrade = new Upgrade();
            $info = $upgrade->onlinePreflight($ref);
            return Response::success($info);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return Response::error('在线预检失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 在线升级差异预览：
     * 自动从 Gitee/GitHub/GitLab 下载源码 zip → 对比远程与本地文件清单 → 返回差异报告
     * 与 upload 方式的区别：无需管理员手动上传 zip，全自动。
     */
    public function onlineCompare(Request $request): Response
    {
        $this->requireAdmin($request);
        $ref = $request->query('ref');

        try {
            $upgrade = new Upgrade();
            $report = $upgrade->onlineCompare($ref);

            $summary = $report['summary'];
            $parts = [];
            if ($summary['added'] > 0)    $parts[] = "{$summary['added']} 个新增文件";
            if ($summary['modified'] > 0) $parts[] = "{$summary['modified']} 个修改文件";
            if ($summary['removed'] > 0)  $parts[] = "{$summary['removed']} 个待删除文件";
            if ($summary['protected'] > 0) $parts[] = "{$summary['protected']} 个受保护文件（不覆盖）";
            $branch = $report['source']['ref'] ?? 'main';
            $msg = "[{$branch}] 在线差异分析完成：" . ($parts ? implode('，', $parts) : '远程与本地完全一致，无需升级');

            return Response::success($report, $msg);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return Response::error('在线差异分析失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 在线升级执行：下载远程源码 → 自动备份 → 覆盖代码 → 数据库迁移 → 写文件指纹
     * 两步流程：先调 onlineCompare 看预览 → 再调 onlineRun 正式升级。
     */
    public function onlineRun(Request $request): Response
    {
        $this->requireAdmin($request);
        $ref = $request->query('ref');

        try {
            $upgrade = new Upgrade();
            $result = $upgrade->onlineRun($ref);
            return Response::success($result, $result['message'] ?? '在线升级成功');
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return Response::error('在线升级失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 回滚到最近一次升级前的备份
     */
    public function rollback(Request $request): Response
    {
        $this->requireAdmin($request);

        try {
            $upgrade = new Upgrade();
            $result = $upgrade->rollback();
            return Response::success($result, $result['message']);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return Response::error('回滚失败：' . $e->getMessage(), 500);
        }
    }

    public function cleanupBackups(Request $request): Response
    {
        $this->requireAdmin($request);

        try {
            $upgrade = new Upgrade();
            $deleted = $upgrade->cleanupOldBackups(2);
            return Response::success(['deleted' => $deleted], '已清理 ' . $deleted . ' 个旧备份');
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return Response::error('清理备份失败：' . $e->getMessage(), 500);
        }
    }

    public function backup(Request $request): Response
    {
        $this->requireAdmin($request);
        $tables = ['users', 'posts', 'comments', 'likes', 'settings', 'notifications', 'uploads'];
        $backup = [];

        // 动态表名白名单校验在仓储本体，非法表名会在仓储抛异常
        foreach ($tables as $table) {
            $backup[$table] = SystemRepository::fetchBackupTableRows($table);
        }

        $backupDir = PathResolver::basePath() . '/backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            throw new HublogException('备份目录创建失败', 500);
        }

        // 文件名加入 16 字节随机串，防止被按日期枚举下载；同时 .htaccess 已强制该目录禁止外部访问
        $rand = bin2hex(random_bytes(8));
        $filename = 'hublog_backup_' . date('Ymd_His') . '_' . $rand . '.json';
        $filepath = $backupDir . '/' . $filename;
        file_put_contents($filepath, json_encode($backup, JSON_UNESCAPED_UNICODE));

        // 备份文件不再返回 HTTP 可下载 URL（该目录已禁止外部访问），需要下载请通过 FTP / 文件管理器
        return Response::success([
            'filename' => $filename,
            'filesize_bytes' => filesize($filepath),
            'note' => '备份文件已保存到 backups/ 目录（该目录已禁止外部下载，请通过 FTP/SFTP 获取）'
        ], '备份成功');
    }

    /**
     * AI 接口测试（调用一次 chat completion 验证连通性）
     */
}
