<?php
/**
 * ChatService · ChatFileGcTrait
 * V99 结构拆分：附件存储统计与孤儿文件清理。
 * 方法体从 ChatService.php 逐字搬迁，零行为变更。
 */

namespace Hublog\Core;

use Hublog\Core\Database;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\Logger;
use Hublog\Core\Security;
use Hublog\Core\SsePush;
use Hublog\Core\PathResolver;
use Hublog\Core\ChatRepository;
use Hublog\Core\Exception\HublogException;

trait ChatFileGcTrait
{

    /**
     * 管理后台：存储统计
     */
    public static function adminStorageStats(): array
    {
        $db = Database::getInstance();

        $messageCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM messages");
        $convCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM conversations");
        $userCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM users");

        // 按类型统计消息数
        $typeStats = Database::fetchAll(
            "SELECT type, COUNT(*) AS cnt FROM messages GROUP BY type"
        );

        // 统计有附件的消息 URL 并计算物理文件大小
        $mediaMessages = Database::fetchAll(
            "SELECT id, type, extra FROM messages WHERE type IN ('image','video','file')"
        );

        $totalPhysical = 0;
        $existingFiles = 0;
        $orphanFiles = 0;
        $dirSizes = ['images' => 0, 'videos' => 0, 'attachments' => 0];

        // 检查有多少是被引用的
        $referencedUrls = [];
        foreach ($mediaMessages as $m) {
            $extra = !empty($m['extra']) ? json_decode($m['extra'], true) : null;
            if (!empty($extra['url'])) {
                $referencedUrls[] = $extra['url'];
            }
        }

        // 统计 uploads 目录大小（本地存储）
        $basePath = PathResolver::basePath() . '/uploads';
        foreach (['images', 'videos', 'attachments', 'avatars'] as $dir) {
            $fullDir = $basePath . '/' . $dir;
            if (is_dir($fullDir)) {
                $dirSizes[$dir] = self::dirSize($fullDir);
            }
        }
        $totalPhysical = array_sum($dirSizes);

        // 计算孤儿文件：物理存在但未被任何消息引用
        $orphanInfo = self::findOrphanFiles($basePath, $referencedUrls);
        $orphanFiles = $orphanInfo['count'];

        return [
            'messages' => [
                'total' => $messageCount,
                'conversations' => $convCount,
                'users' => $userCount,
                'by_type' => array_column($typeStats, 'cnt', 'type')
            ],
            'storage' => [
                'total_bytes' => $totalPhysical,
                'total_mb' => round($totalPhysical / 1024 / 1024, 2),
                'dirs' => array_map(fn($b) => ['bytes' => $b, 'mb' => round($b / 1024 / 1024, 2)], $dirSizes),
                'orphan_files' => $orphanFiles,
                'orphan_bytes' => $orphanInfo['bytes'],
                'orphan_mb' => round($orphanInfo['bytes'] / 1024 / 1024, 2)
            ]
        ];
    }

    /**
     * 管理后台：清理孤儿文件（本地存储下物理存在但未被任何消息/动态引用的文件）
     */
    public static function adminCleanupOrphanFiles(): array
    {
        $basePath = PathResolver::basePath() . '/uploads';

        // 收集所有被引用的 URL
        $referencedUrls = [];
        try {
            $mediaMessages = Database::fetchAll(
                "SELECT extra FROM messages WHERE type IN ('image','video','file') AND extra IS NOT NULL AND extra != ''"
            );
            foreach ($mediaMessages as $m) {
                $extra = json_decode($m['extra'], true);
                if (!empty($extra['url'])) $referencedUrls[] = $extra['url'];
            }
        } catch (\Throwable $e) {}

        // 附件表引用
        try {
            $attachments = Database::fetchAll("SELECT url FROM attachments WHERE url IS NOT NULL AND url != ''");
            foreach ($attachments as $a) $referencedUrls[] = $a['url'];
        } catch (\Throwable $e) {}

        // users 表头像封面
        try {
            $users = Database::fetchAll("SELECT avatar, cover FROM users");
            foreach ($users as $u) {
                if (!empty($u['avatar'])) $referencedUrls[] = $u['avatar'];
                if (!empty($u['cover'])) $referencedUrls[] = $u['cover'];
            }
        } catch (\Throwable $e) {}

        $referencedSet = array_flip(array_unique($referencedUrls));

        $deleted = 0;
        $freedBytes = 0;

        foreach (['images', 'videos', 'attachments', 'avatars'] as $dir) {
            $fullDir = $basePath . '/' . $dir;
            if (!is_dir($fullDir)) continue;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $relativePath = str_replace('\\', '/', $file->getPathname());
                $uploadsPos = strpos($relativePath, 'uploads/');
                $url = $uploadsPos !== false ? '/' . substr($relativePath, $uploadsPos) : '';

                // 未被引用的就是孤儿
                if (!isset($referencedSet[$url])) {
                    $size = $file->getSize();
                    if (@unlink($file->getPathname())) {
                        $deleted++;
                        $freedBytes += $size;
                    }
                }
            }
        }

        return ['deleted_files' => $deleted, 'freed_bytes' => $freedBytes, 'freed_mb' => round($freedBytes / 1024 / 1024, 2)];
    }

    /**
     * 清理未被任何消息引用的物理文件
     */
    private static function cleanupUnusedFile(string $url, bool $returnBytes = false)
    {
        // 检查是否还有其他消息引用同一个 URL
        $count = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM messages WHERE extra LIKE ?",
            ['%' . $url . '%']
        );

        if ($count > 0) {
            return $returnBytes ? 0 : false;
        }

        // 检查 attachments / users 表
        try {
            $attachCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM attachments WHERE url = ?", [$url]);
            if ($attachCount > 0) return $returnBytes ? 0 : false;

            $userCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM users WHERE avatar = ? OR cover = ?", [$url, $url]);
            if ($userCount > 0) return $returnBytes ? 0 : false;
        } catch (\Throwable $e) {}

        // 物理删除
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = ltrim($path, '/');
        $fullPath = PathResolver::basePath() . '/' . $path;

        if (is_file($fullPath)) {
            $size = filesize($fullPath);
            if (@unlink($fullPath)) {
                return $returnBytes ? $size : true;
            }
        }
        return $returnBytes ? 0 : false;
    }

    private static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) $size += $file->getSize();
        }
        return $size;
    }

    private static function findOrphanFiles(string $basePath, array $referencedUrls): array
    {
        $referencedSet = array_flip(array_unique($referencedUrls));
        $count = 0;
        $bytes = 0;

        foreach (['images', 'videos', 'attachments', 'avatars'] as $dir) {
            $fullDir = $basePath . '/' . $dir;
            if (!is_dir($fullDir)) continue;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $relativePath = str_replace('\\', '/', $file->getPathname());
                $uploadsPos = strpos($relativePath, 'uploads/');
                $url = $uploadsPos !== false ? '/' . substr($relativePath, $uploadsPos) : '';
                if (!isset($referencedSet[$url])) {
                    $count++;
                    $bytes += $file->getSize();
                }
            }
        }
        return ['count' => $count, 'bytes' => $bytes];
    }
}
