<?php
declare(strict_types=1);

/**
 * Hublog 独立图库控制器
 *
 * 用户主动上传、分组管理的图片库，与文章配图聚合（/posts/timeline?media=image）区分。
 * 上传复用 Upload::image + UploadRepository（与发布文章同一条安全链路），
 * 所有 SQL 下沉 GalleryRepository，本类只做参数校验、所有权判定、响应包装。
 * 免费核心能力，不挂 License 门禁。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\GalleryRepository;
use Hublog\Core\Upload;
use Hublog\Core\UploadRepository;
use Hublog\Core\Helper;

class GalleryController extends BaseController
{
    private const MAX_ALBUMS = 60;

    // ==================== 相册分组 ====================

    public function albums(Request $request): Response
    {
        $user = $this->requireUser($request);
        return Response::success(['list' => GalleryRepository::listAlbums((int)$user['id'])]);
    }

    public function createAlbum(Request $request): Response
    {
        $user = $this->requireUser($request);
        $name = trim($request->getString('name'));
        if ($name === '' || mb_strlen($name) > 40) {
            return Response::error('相册名称需为 1-40 字', 400);
        }
        if (GalleryRepository::countUserAlbums((int)$user['id']) >= self::MAX_ALBUMS) {
            return Response::error('相册数量已达上限（' . self::MAX_ALBUMS . '）', 400);
        }
        $id = GalleryRepository::createAlbum(
            (int)$user['id'],
            $name,
            trim($request->getString('description')),
            $request->getInt('sort_order')
        );
        return Response::success(['id' => $id], '相册已创建');
    }

    public function updateAlbum(Request $request): Response
    {
        $user = $this->requireUser($request);
        $id = $request->getInt('id');
        if ($id <= 0 || !GalleryRepository::findAlbum((int)$user['id'], $id)) {
            return Response::error('相册不存在', 404);
        }
        $data = [];
        $name = trim($request->getString('name'));
        if ($name !== '') {
            if (mb_strlen($name) > 40) {
                return Response::error('相册名称最多 40 字', 400);
            }
            $data['name'] = $name;
        }
        if ($request->has('description')) {
            $data['description'] = trim($request->getString('description'));
        }
        if ($request->has('sort_order')) {
            $data['sort_order'] = $request->getInt('sort_order');
        }
        if ($data) {
            GalleryRepository::updateAlbum((int)$user['id'], $id, $data);
        }
        return Response::success(null, '已保存');
    }

    public function deleteAlbum(Request $request): Response
    {
        $user = $this->requireUser($request);
        $id = $request->getInt('id');
        if ($id <= 0 || !GalleryRepository::findAlbum((int)$user['id'], $id)) {
            return Response::error('相册不存在', 404);
        }
        GalleryRepository::deleteAlbum((int)$user['id'], $id);
        return Response::success(null, '相册已删除，图片已移回未分组');
    }

    // ==================== 图片 ====================

    public function images(Request $request): Response
    {
        $user = $this->requireUser($request);
        $uid = (int)$user['id'];
        // album 参数缺省=全部；显式整数（含 0=未分组）按分组筛
        $albumId = $request->has('album') ? $request->getInt('album') : null;
        $page = max(1, $request->getInt('page'));
        $limit = min(60, max(1, $request->getInt('limit') ?: 20));
        $offset = ($page - 1) * $limit;
        $list = GalleryRepository::listImages($uid, $albumId, $offset, $limit);
        $total = GalleryRepository::countImages($uid, $albumId);
        return Response::success([
            'list' => $list,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ]);
    }

    public function uploadImage(Request $request): Response
    {
        $user = $this->requireUser($request);
        $uid = (int)$user['id'];
        $file = $request->getFile('image');
        if (!$file) {
            return Response::error('请上传图片', 400);
        }
        $albumId = max(0, $request->getInt('album'));
        if ($albumId > 0 && !GalleryRepository::findAlbum($uid, $albumId)) {
            return Response::error('目标相册不存在', 404);
        }

        $settings = GalleryRepository::uploadSettings();
        $maxSize = (int)($settings['upload_max_size'] ?? '10') * 1024 * 1024;
        $compress = ($settings['upload_compress'] ?? '1') === '1';
        $maxWidth = (int)($settings['upload_max_width'] ?? '1920');

        try {
            [$url, $hash] = Upload::image($file, \UPLOAD_IMAGE_DIR, $maxWidth, 99999, $compress, $maxSize);
            $uploadId = UploadRepository::insertImageUpload(
                $uid,
                (string)($file['name'] ?? ''),
                $url,
                (int)($file['size'] ?? 0),
                $hash,
                Helper::now()
            );
            $imageId = GalleryRepository::insertImage(
                $uid,
                $albumId,
                $uploadId,
                $url,
                (string)($file['name'] ?? ''),
                (int)($file['size'] ?? 0)
            );
            if ($albumId > 0) {
                GalleryRepository::refreshAlbumCover($uid, $albumId);
            }
        } catch (\Throwable $e) {
            return Response::error('上传失败：' . $e->getMessage(), 500);
        }
        return Response::success(['id' => $imageId, 'url' => $url, 'album_id' => $albumId], '上传成功');
    }

    public function deleteImage(Request $request): Response
    {
        $user = $this->requireUser($request);
        $uid = (int)$user['id'];
        $id = $request->getInt('id');
        $row = $id > 0 ? GalleryRepository::findImage($uid, $id) : null;
        if (!$row) {
            return Response::error('图片不存在', 404);
        }
        $albumId = (int)$row['album_id'];
        $uploadId = (int)$row['upload_id'];
        $url = GalleryRepository::findUploadUrl($uploadId);

        GalleryRepository::deleteImage($uid, $id);
        // 仅当 uploads 表中已无任何引用时才删除记录与物理文件，避免误删被文章/秒传复用的图
        if ($url !== null && GalleryRepository::countUploadRefsByUrl($url) <= 1) {
            GalleryRepository::deleteUploadRecord($uploadId);
            Upload::deleteUpload($url);
        } else {
            GalleryRepository::deleteUploadRecord($uploadId);
        }
        if ($albumId > 0) {
            GalleryRepository::refreshAlbumCover($uid, $albumId);
        }
        return Response::success(null, '已删除');
    }

    public function moveImage(Request $request): Response
    {
        $user = $this->requireUser($request);
        $uid = (int)$user['id'];
        $id = $request->getInt('id');
        $albumId = max(0, $request->getInt('album'));
        $row = $id > 0 ? GalleryRepository::findImage($uid, $id) : null;
        if (!$row) {
            return Response::error('图片不存在', 404);
        }
        if ($albumId > 0 && !GalleryRepository::findAlbum($uid, $albumId)) {
            return Response::error('目标相册不存在', 404);
        }
        $oldAlbum = (int)$row['album_id'];
        GalleryRepository::moveImage($uid, $id, $albumId);
        if ($oldAlbum > 0) {
            GalleryRepository::refreshAlbumCover($uid, $oldAlbum);
        }
        if ($albumId > 0) {
            GalleryRepository::refreshAlbumCover($uid, $albumId);
        }
        return Response::success(null, '已移动');
    }
}
