<?php

declare(strict_types=1);

/**
 * Hublog 上传记录数据仓储
 *
 * 承载 PostUploadTrait 的全部 uploads 表访问，以及 PostUpdateTrait 事务内的
 * 上传重绑序列。所有 URL / 用户 ID / 状态值均以 ? 绑定，IN 占位符按数组长度生成。
 * 控制器侧的协议白名单、空串短路、数量钳制等入口守卫一并下沉到方法本体，
 * 避免仓储内部自调用绕过校验。
 */

namespace Hublog\Core;

class UploadRepository
{
    /**
     * 入口守卫：只接受 http(s) 绝对地址或以 / 开头的相对路径。
     * 该校验原在控制器 isOwnUploadUrl()/isUploadBoundToPost() 入口，现下沉到仓储，
     * 保证任何调用路径都无法用 javascript:/data: 等协议命中 uploads 记录。
     */
    private static function isAllowedUrl(string $url): bool
    {
        return preg_match('#^(https?://|/)#i', $url) === 1;
    }

    /** 绝对 URL 转库内可能存储的 path（解析失败时原样返回） */
    private static function urlPath(string $url): string
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return $url;
        }
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : $url;
    }

    /** isOwnUploadUrl() —— 发布态：仅未绑定的上传可通过归属校验 */
    public static function findOwnUnboundUploadId(string $url, int $userId): ?array
    {
        if (!self::isAllowedUrl($url)) {
            return null;
        }
        return Database::fetchOne(
            "SELECT id FROM uploads WHERE url = ? AND user_id = ? AND post_id IS NULL LIMIT 1",
            [$url, $userId]
        );
    }

    /** isOwnUploadUrl() —— 编辑态：未绑定或已绑到本动态的上传均可通过 */
    public static function findOwnUploadIdForEdit(string $url, int $userId, int $postId): ?array
    {
        if (!self::isAllowedUrl($url)) {
            return null;
        }
        return Database::fetchOne(
            "SELECT id FROM uploads WHERE url = ? AND user_id = ? AND (post_id IS NULL OR post_id = ?) LIMIT 1",
            [$url, $userId, $postId]
        );
    }

    /** isUploadBoundToPost() —— 后台代管：按绝对地址或 path 查已绑定到本动态的上传 */
    public static function findUploadBoundToPost(string $url, int $postId): ?array
    {
        if (!self::isAllowedUrl($url)) {
            return null;
        }
        return Database::fetchOne(
            "SELECT id FROM uploads WHERE post_id = ? AND (url = ? OR url = ?) LIMIT 1",
            [$postId, $url, self::urlPath($url)]
        );
    }

    /** canonicalOwnUploadUrl() —— 发布态：返回库中规范 URL（按 id 升序取第一条） */
    public static function findCanonicalUnboundUploadUrl(string $url, int $userId): ?array
    {
        if ($url === '' || !self::isAllowedUrl($url)) {
            return null;
        }
        return Database::fetchOne(
            "SELECT url FROM uploads
                 WHERE user_id = ? AND post_id IS NULL AND (url = ? OR url = ?)
                 ORDER BY id ASC LIMIT 1",
            [$userId, $url, self::urlPath($url)]
        );
    }

    /** canonicalOwnUploadUrl() —— 编辑态：追加「已绑到本动态」的命中范围 */
    public static function findCanonicalUploadUrlForEdit(string $url, int $userId, int $postId): ?array
    {
        if ($url === '' || !self::isAllowedUrl($url)) {
            return null;
        }
        return Database::fetchOne(
            "SELECT url FROM uploads
                 WHERE user_id = ? AND (post_id IS NULL OR post_id = ?) AND (url = ? OR url = ?)
                 ORDER BY id ASC LIMIT 1",
            [$userId, $postId, $url, self::urlPath($url)]
        );
    }

    /** uploadImage() —— 图片上传登记（10 列，file_hash 可能为 null） */
    public static function insertImageUpload(
        int $userId,
        string $filename,
        string $url,
        int $size,
        ?string $fileHash,
        string $now
    ): int {
        return Database::insert('uploads', [
            'user_id' => $userId,
            'post_id' => null,
            'type' => 'image',
            'filename' => $filename,
            'url' => $url,
            'size' => $size,
            'file_hash' => $fileHash !== '' ? $fileHash : null,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }

    /** uploadAttachment() —— 附件上传登记（file_hash 直接取 Upload 返回值） */
    public static function insertAttachmentUpload(
        int $userId,
        string $filename,
        string $url,
        int $size,
        ?string $fileHash,
        string $now
    ): int {
        return Database::insert('uploads', [
            'user_id' => $userId,
            'post_id' => null,
            'type' => 'attachment',
            'filename' => $filename,
            'url' => $url,
            'size' => $size,
            'file_hash' => $fileHash,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }

    /** uploadVideo() —— 视频上传登记（file_hash 用 !empty 判定，与原表达式一致） */
    public static function insertVideoUpload(
        int $userId,
        string $filename,
        string $url,
        int $size,
        ?string $fileHash,
        string $now
    ): int {
        return Database::insert('uploads', [
            'user_id' => $userId,
            'post_id' => null,
            'type' => 'video',
            'filename' => $filename,
            'url' => $url,
            'size' => $size,
            'file_hash' => (!empty($fileHash)) ? $fileHash : null,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }

    /** uploadDirectRegister() —— 直传登记（多一列 expires_at，字段集与其它登记不同） */
    public static function insertDirectRegisterUpload(
        int $userId,
        string $type,
        string $filename,
        string $url,
        int $size,
        string $expiresAt,
        string $now
    ): int {
        return Database::insert('uploads', [
            'user_id'    => $userId,
            'post_id'    => null,
            'type'       => $type,
            'filename'   => $filename,
            'url'        => $url,
            'size'       => $size,
            'file_hash'  => null,
            'status'     => 1,
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** deleteUpload() —— 待删除记录回显（含 post_id，字段集与直传去重查询不同） */
    public static function findUploadRecordForDelete(string $url, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id, url, post_id FROM uploads WHERE url = ? AND user_id = ? ORDER BY id ASC LIMIT 1",
            [$url, $userId]
        );
    }

    /** uploadDirectRegister() —— 直传去重查询（只取 id，与删除回显交叉断言防误合并） */
    public static function findExistingDirectUpload(string $url, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM uploads WHERE url = ? AND user_id = ? ORDER BY id ASC LIMIT 1",
            [$url, $userId]
        );
    }

    /** deleteUpload() —— 按主键删记录（物理文件删除仍由控制器负责） */
    public static function deleteUploadById(int $id): void
    {
        Database::delete('uploads', 'id = ?', [$id]);
    }

    /** cleanupOwnUploads() —— 本人未绑定上传的清理回显 */
    public static function findCleanableOwnUpload(string $url, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id, size FROM uploads WHERE url = ? AND user_id = ? AND post_id IS NULL LIMIT 1",
            [$url, $userId]
        );
    }

    /** recordUploads() —— 把单个未绑定上传绑到动态，返回影响行数（0 表示需 INSERT 兜底） */
    public static function bindUploadToPost(int $userId, int $postId, string $url, string $now): int
    {
        return (int)Database::update(
            'uploads',
            ['post_id' => $postId, 'expires_at' => null, 'updated_at' => $now],
            'url = ? AND user_id = ? AND post_id IS NULL',
            [$url, $userId]
        );
    }

    /** recordUploads() —— 绑定未命中时补建记录（image/video/attachment 三处字段清单相同） */
    public static function insertBoundUpload(
        int $userId,
        int $postId,
        string $type,
        string $filename,
        string $url,
        int $size,
        string $now
    ): int {
        return Database::insert('uploads', [
            'user_id' => $userId,
            'post_id' => $postId,
            'type' => $type,
            'filename' => $filename,
            'url' => $url,
            'size' => $size,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }

    /**
     * persistPostUpdate() —— 编辑事务内的 uploads 全量重绑。
     * 原顺序「DELETE 旧绑定 → 逐条 UPDATE/INSERT」整体下沉；所有 is_string/empty/null
     * 守卫随方法进入仓储，Helper::now() 保持逐点调用时序。
     */
    public static function resetAndBindPostUploads(
        int $userId,
        int $postId,
        array $images,
        array $videos,
        array $attachments
    ): void {
        Database::delete('uploads', 'post_id = ?', [$postId]);
        self::bindUploads($userId, $postId, $images, $videos, $attachments);
    }

    /**
     * recordUploads() —— 不含「先删旧绑定」的纯绑定序列，供 PostCrudTrait::create() 共用。
     * 所有跳过空值/非法结构的守卫都在本方法体内，内部自调用无法绕过。
     */
    public static function bindUploads(
        int $userId,
        int $postId,
        array $images,
        array $videos,
        array $attachments
    ): void {
        foreach ($images as $url) {
            if (!is_string($url) || empty($url)) {
                continue;
            }
            $updated = self::bindUploadToPost($userId, $postId, $url, Helper::now());
            if ($updated <= 0) {
                self::insertBoundUpload($userId, $postId, 'image', basename($url), $url, 0, Helper::now());
            }
        }

        foreach ($videos as $video) {
            if (is_string($video) && !empty($video)) {
                $videoUrl = $video;
                $coverUrl = '';
                $filename = basename($video);
            } elseif (is_array($video) && !empty($video['url'])) {
                $videoUrl = $video['url'];
                $coverUrl = $video['cover'] ?? '';
                $filename = $video['filename'] ?? basename($video['url']);
            } else {
                continue;
            }
            $updated = self::bindUploadToPost($userId, $postId, $videoUrl, Helper::now());
            if ($updated <= 0) {
                self::insertBoundUpload($userId, $postId, 'video', $filename, $videoUrl, 0, Helper::now());
            }
            if (!empty($coverUrl)) {
                self::bindUploadToPost($userId, $postId, $coverUrl, Helper::now());
            }
        }

        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || empty($attachment['url'])) {
                continue;
            }
            $updated = self::bindUploadToPost($userId, $postId, $attachment['url'], Helper::now());
            if ($updated <= 0) {
                self::insertBoundUpload(
                    $userId,
                    $postId,
                    'attachment',
                    $attachment['filename'] ?? basename($attachment['url']),
                    $attachment['url'],
                    $attachment['size'] ?? 0,
                    Helper::now()
                );
            }
        }
    }

    /** cleanupOrphanUploads() —— 过期孤儿记录扫描（LIMIT 1000 改为绑定值） */
    public static function fetchOrphanUploads(string $cutoff, int $limit): array
    {
        return Database::fetchAll(
            "SELECT id, url, size FROM uploads
                 WHERE post_id IS NULL
                   AND (expires_at IS NOT NULL AND expires_at < NOW() OR created_at < ?)
                 LIMIT ?",
            [$cutoff, $limit]
        );
    }

    /** cleanupOrphanUploads() —— 按 URL 批量删；空集合守卫下沉，避免 IN () 语法错误 */
    public static function deleteUploadsByUrls(array $urls): int
    {
        $urls = array_values(array_filter($urls, static fn($url): bool => is_string($url) && $url !== ''));
        if ($urls === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($urls), '?'));
        return Database::query(
            "DELETE FROM uploads WHERE url IN ({$placeholders}) AND post_id IS NULL",
            $urls
        )->rowCount();
    }

    /** cleanupExpiredUploads() —— 按过期时间扫描，limit 钳制（1~500）随方法下沉 */
    public static function fetchExpiredUnboundUploads(string $now, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        return Database::fetchAll(
            "SELECT id, url, size FROM uploads
             WHERE post_id IS NULL AND expires_at IS NOT NULL AND expires_at < ?
             ORDER BY expires_at ASC LIMIT ?",
            [$now, $limit]
        );
    }

    /** cleanupExpiredUploads() —— 按主键批量删；空集合守卫下沉 */
    public static function deleteUploadsByIds(array $ids): int
    {
        $ids = array_values(array_filter($ids, static fn($id): bool => (int)$id > 0));
        if ($ids === []) {
            return 0;
        }
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return Database::query(
            "DELETE FROM uploads WHERE id IN ({$placeholders})",
            $ids
        )->rowCount();
    }

    // ===== 批次五：后台附件管理下沉（只新增，不改既有方法） =====

    /**
     * AdminAttachmentController::updateAttachmentStatus() —— 改状态并回写时间。
     *
     * status 的 0/1 白名单守卫留在控制器，仓储仅按主键更新。
     */
    public static function updateAttachmentStatus(int $id, int $status, string $now): void
    {
        Database::update('uploads', ['status' => $status, 'updated_at' => $now], 'id = ?', [$id]);
    }

    /**
     * AdminAttachmentController::updateAttachmentStatus() —— 取 post_id 用于清缓存。
     *
     * 字段集为 post_id, url，与删除路径的 id, url 不同，刻意分开。
     */
    public static function findAttachmentPostInfo(int $id): ?array
    {
        return Database::fetchOne("SELECT post_id, url FROM uploads WHERE id = ? LIMIT 1", [$id]);
    }

    /** AdminAttachmentController::deleteAttachment() —— 取 id, url 用于引用计数与物理删除 */
    public static function findAttachmentForDelete(int $id): ?array
    {
        return Database::fetchOne("SELECT id, url FROM uploads WHERE id = ? LIMIT 1", [$id]);
    }

    /** AdminAttachmentController::deleteAttachment() —— 同 URL 的其他引用数（排除自身） */
    public static function countUploadsByUrlExceptId(string $url, int $id): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM uploads WHERE url = ? AND id != ?", [$url, $id]);
    }

    // ===== paginate SQL 字面量收敛：附件列表分页 =====

    /** AdminAttachmentController::attachments() 的筛选条件（原控制器逐字搬迁） */
    private static function attachmentWhere(string $type, string $keyword): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if (in_array($type, ['image', 'attachment'], true)) {
            $where .= ' AND u.type = ?';
            $params[] = $type;
        }
        if ($keyword) {
            $where .= ' AND (u.filename LIKE ? OR us.username LIKE ?)';
            $params = array_merge($params, ["%{$keyword}%", "%{$keyword}%"]);
        }
        return [$where, $params];
    }

    /** AdminAttachmentController::attachments() —— 原 paginate() 的 listSql + LIMIT ?, ? */
    public static function fetchAttachmentPage(string $type, string $keyword, int $offset, int $limit): array
    {
        [$where, $params] = self::attachmentWhere($type, $keyword);
        $sql = "SELECT u.id, u.user_id, u.post_id, u.type, u.filename, u.url, u.size, u.status, u.created_at, us.username, us.nickname, p.content AS post_content, p.status AS post_status FROM uploads u INNER JOIN users us ON u.user_id = us.id LEFT JOIN posts p ON u.post_id = p.id {$where} ORDER BY COALESCE(p.created_at, u.created_at) DESC";
        return Database::fetchAll($sql . ' LIMIT ?, ?', array_merge($params, [$offset, $limit]));
    }

    /** AdminAttachmentController::attachments() —— 原 paginate() 的 countSql */
    public static function countAttachments(string $type, string $keyword): int
    {
        [$where, $params] = self::attachmentWhere($type, $keyword);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM uploads u INNER JOIN users us ON u.user_id = us.id {$where}",
            $params
        );
    }
}
