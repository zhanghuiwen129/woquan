<?php
declare(strict_types=1);

/**
 * Hublog 独立图库数据仓储
 *
 * 只承载 GalleryController 下沉的 SQL：相册分组 CRUD、图片 CRUD、移动分组、
 * 所有权校验。不做权限判定、响应包装、媒体 URL 处理——这些留在控制器。
 *
 * 约定（与 docs/内联SQL收敛规范.md 一致）：
 *  - 所有值一律通过 ? 占位符绑定，禁止字符串拼接值；
 *  - 所有查询按 user_id 隔离，避免越权访问他人图库；
 *  - album_id=0 表示未分组，删除相册时图片统一归 0，不删物理文件。
 */

namespace Hublog\Core;

class GalleryRepository
{
    // ===== 相册分组 =====

    /** 相册列表（带图片数与封面回退），按 sort_order DESC, id DESC */
    public static function listAlbums(int $userId): array
    {
        return Database::fetchAll(
            "SELECT a.id, a.user_id, a.name, a.description, a.cover_url, a.sort_order, a.created_at,
                    (SELECT COUNT(*) FROM gallery_images i WHERE i.album_id = a.id AND i.user_id = a.user_id) AS image_count,
                    COALESCE(a.cover_url,
                        (SELECT i.url FROM gallery_images i WHERE i.album_id = a.id AND i.user_id = a.user_id ORDER BY i.id DESC LIMIT 1)
                    ) AS resolved_cover
             FROM gallery_albums a
             WHERE a.user_id = ?
             ORDER BY a.sort_order DESC, a.id DESC",
            [$userId]
        );
    }

    public static function findAlbum(int $userId, int $albumId): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM gallery_albums WHERE id = ? AND user_id = ?',
            [$albumId, $userId]
        );
    }

    public static function countUserAlbums(int $userId): int
    {
        return (int)Database::fetchColumn(
            'SELECT COUNT(*) FROM gallery_albums WHERE user_id = ?',
            [$userId]
        );
    }

    /** 读取图库上传相关站点设置（键 => 值），复用 SettingsRepository 统一入口 */
    public static function uploadSettings(): array
    {
        $all = SettingsRepository::fetchKeyValueRows();
        $wanted = ['upload_max_size', 'upload_compress', 'upload_max_width'];
        $out = [];
        foreach ($all as $r) {
            if (in_array($r['key'], $wanted, true)) {
                $out[$r['key']] = $r['value'];
            }
        }
        return $out;
    }

    public static function createAlbum(int $userId, string $name, string $description, int $sortOrder): int
    {
        return Database::insert('gallery_albums', [
            'user_id'     => $userId,
            'name'        => $name,
            'description' => $description,
            'sort_order'  => $sortOrder,
        ]);
    }

    public static function updateAlbum(int $userId, int $albumId, array $data): int
    {
        return Database::update('gallery_albums', $data, 'id = ? AND user_id = ?', [$albumId, $userId]);
    }

    /** 删除相册：先将其下图片归到未分组，再删分组行；返回被影响的图片数 */
    public static function deleteAlbum(int $userId, int $albumId): int
    {
        Database::beginTransaction();
        try {
            $moved = Database::update(
                'gallery_images',
                ['album_id' => 0],
                'album_id = ? AND user_id = ?',
                [$albumId, $userId]
            );
            Database::delete('gallery_albums', 'id = ? AND user_id = ?', [$albumId, $userId]);
            Database::commit();
            return $moved;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ===== 图片 =====

    /** 图片列表：album_id=null 全部；>=0 按分组（0=未分组）。分页 offset/limit */
    public static function listImages(int $userId, ?int $albumId, int $offset, int $limit): array
    {
        $where = 'i.user_id = ?';
        $params = [$userId];
        if ($albumId !== null) {
            $where .= ' AND i.album_id = ?';
            $params[] = $albumId;
        }
        return Database::fetchAll(
            "SELECT i.id, i.user_id, i.album_id, i.upload_id, i.url, i.filename, i.size, i.created_at,
                    a.name AS album_name
             FROM gallery_images i
             LEFT JOIN gallery_albums a ON a.id = i.album_id AND a.user_id = i.user_id
             WHERE {$where}
             ORDER BY i.id DESC
             LIMIT {$offset}, {$limit}",
            $params
        );
    }

    public static function countImages(int $userId, ?int $albumId): int
    {
        $where = 'user_id = ?';
        $params = [$userId];
        if ($albumId !== null) {
            $where .= ' AND album_id = ?';
            $params[] = $albumId;
        }
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM gallery_images WHERE {$where}", $params);
    }

    public static function findImage(int $userId, int $imageId): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM gallery_images WHERE id = ? AND user_id = ?',
            [$imageId, $userId]
        );
    }

    public static function insertImage(
        int $userId,
        int $albumId,
        int $uploadId,
        string $url,
        string $filename,
        int $size
    ): int {
        return Database::insert('gallery_images', [
            'user_id'  => $userId,
            'album_id' => $albumId,
            'upload_id'=> $uploadId,
            'url'      => $url,
            'filename' => $filename,
            'size'     => $size,
        ]);
    }

    /** 移动图片到分组（album_id=0 移回未分组） */
    public static function moveImage(int $userId, int $imageId, int $albumId): int
    {
        return Database::update(
            'gallery_images',
            ['album_id' => $albumId],
            'id = ? AND user_id = ?',
            [$imageId, $userId]
        );
    }

    /** 删除图片记录，返回该行的 upload_id 供控制器清理 uploads 记录与物理文件 */
    public static function deleteImage(int $userId, int $imageId): ?int
    {
        $row = self::findImage($userId, $imageId);
        if (!$row) {
            return null;
        }
        Database::delete('gallery_images', 'id = ? AND user_id = ?', [$imageId, $userId]);
        return (int)$row['upload_id'];
    }

    /** 按 upload_id 取 uploads.url（删除时判断物理文件可否清理） */
    public static function findUploadUrl(int $uploadId): ?string
    {
        $url = Database::fetchColumn('SELECT url FROM uploads WHERE id = ?', [$uploadId]);
        return $url !== false && $url !== null ? (string)$url : null;
    }

    /** 统计 uploads 表中仍引用同一 url 的记录数（含自身，删记录后据此判断是否删物理文件） */
    public static function countUploadRefsByUrl(string $url): int
    {
        return (int)Database::fetchColumn('SELECT COUNT(*) FROM uploads WHERE url = ?', [$url]);
    }

    /** 删除 uploads 记录（仅当确认无其它引用时由控制器调用） */
    public static function deleteUploadRecord(int $uploadId): void
    {
        Database::delete('uploads', 'id = ?', [$uploadId]);
    }

    /** 取相册内最新一张图作为封面（删除图片后重算用） */
    public static function refreshAlbumCover(int $userId, int $albumId): void
    {
        $url = Database::fetchColumn(
            'SELECT url FROM gallery_images WHERE album_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1',
            [$albumId, $userId]
        );
        Database::update(
            'gallery_albums',
            ['cover_url' => $url !== false && $url !== null ? (string)$url : null],
            'id = ? AND user_id = ?',
            [$albumId, $userId]
        );
    }
}
