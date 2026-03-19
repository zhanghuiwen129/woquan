<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\StorageFactory;
use app\helper\FileValidator;
use think\facade\Db;
use think\facade\Request;

class Upload extends BaseFrontendController
{
    private function getStorageConfig()
    {
        $storageType = Db::name('system_config')
            ->where('config_key', 'storage_type')
            ->value('config_value');

        if (empty($storageType)) {
            $storageType = 'local';
        }

        $config = Db::name('system_config')
            ->where('config_key', 'like', Db::escape($storageType) . '_%')
            ->column('config_value', 'config_key');

        return [
            'type' => $storageType,
            'config' => $config
        ];
    }

    public function uploadFile()
    {
        $userId = session('user_id') ?: cookie('user_id');

        if (empty($userId)) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $file = Request::file('file');
        $type = input('type', 'file');

        if (!$file) {
            return json(['code' => 400, 'msg' => '未选择文件']);
        }

        try {
            $storageConfig = $this->getStorageConfig();
            $storage = StorageFactory::create($storageConfig['type'], $storageConfig['config']);

            $fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $file->extension();

            $directory = '';
            if ($type === 'image') {
                $directory = 'images';
            } elseif ($type === 'video') {
                $directory = 'videos';
            } elseif ($type === 'audio') {
                $directory = 'audio';
            }

            $fileSize = $file->getSize();
            $fileMime = $file->getMime();
            $fileOriginalName = $file->getOriginalName();
            
            // 文件大小限制（10MB）
            $maxSize = 10 * 1024 * 1024;
            if ($fileSize > $maxSize) {
                return json(['code' => 400, 'msg' => '文件大小不能超过10MB']);
            }

            $result = $storage->upload($file, $directory, $fileName);

            $fileInfo = [
                'name' => $fileOriginalName,
                'size' => $fileSize,
                'type' => $fileMime,
                'url' => $result['url'],
                'path' => $result['path']
            ];

            // 将文件信息记录到storage_files表
            try {
                $storageFileInfo = [
                    'user_id' => $userId,
                    'filename' => $fileName,
                    'filepath' => $result['url'],
                    'filesize' => $fileSize,
                    'mimetype' => $fileMime,
                    'storage_type' => $storageConfig['type'],
                    'status' => 1,
                    'create_time' => time()
                ];
                Db::name('storage_files')->insert($storageFileInfo);
            } catch (\Exception $e) {
                // 记录失败不影响上传流程，只记录错误
                error_log('记录文件信息到storage_files表失败: ' . $e->getMessage());
            }

            return json([
                'code' => 200,
                'msg' => '上传成功',
                'data' => $fileInfo
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '上传失败: ' . $e->getMessage()]);
        }
    }

    public function uploadImage()
    {
        $userId = session('user_id') ?: cookie('user_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        $file = Request::file('file');

        if (!$file) {
            return $this->badRequest('未选择文件');
        }

        $maxSize = 10 * 1024 * 1024;
        $sizeCheck = FileValidator::checkFileSize($file, $maxSize);
        if (!$sizeCheck['valid']) {
            return $this->badRequest($sizeCheck['message']);
        }

        $imageCheck = FileValidator::validateImage($file);
        if (!$imageCheck['valid']) {
            return $this->badRequest($imageCheck['message']);
        }

        try {
            $storageConfig = $this->getStorageConfig();
            $storage = StorageFactory::create($storageConfig['type'], $storageConfig['config']);

            $fileName = FileValidator::generateSafeFileName($file->getOriginalName(), 'img');
            $result = $storage->upload($file, 'images', $fileName);

            $fileInfo = [
                'name' => $file->getOriginalName(),
                'size' => $file->getSize(),
                'type' => $file->getMime(),
                'url' => $result['url'],
                'path' => $result['path'],
                'width' => $imageCheck['width'],
                'height' => $imageCheck['height']
            ];

            $storageFileInfo = [
                'user_id' => $userId,
                'filename' => $fileName,
                'filepath' => $result['url'],
                'filesize' => $file->getSize(),
                'mimetype' => $file->getMime(),
                'storage_type' => $storageConfig['type'],
                'status' => 1,
                'create_time' => time()
            ];
            Db::name('storage_files')->insert($storageFileInfo);

            return $this->success($fileInfo, '上传成功');
        } catch (\Exception $e) {
            return $this->error('上传失败: ' . $e->getMessage());
        }
    }

    public function uploadVideo()
    {
        $userId = session('user_id') ?: cookie('user_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        $file = Request::file('file');

        if (!$file) {
            return $this->badRequest('未选择文件');
        }

        $maxSize = 50 * 1024 * 1024;
        $sizeCheck = FileValidator::checkFileSize($file, $maxSize);
        if (!$sizeCheck['valid']) {
            return $this->badRequest($sizeCheck['message']);
        }

        $videoCheck = FileValidator::validateVideo($file);
        if (!$videoCheck['valid']) {
            return $this->badRequest($videoCheck['message']);
        }

        try {
            $storageConfig = $this->getStorageConfig();
            $storage = StorageFactory::create($storageConfig['type'], $storageConfig['config']);

            $fileName = FileValidator::generateSafeFileName($file->getOriginalName(), 'video');
            $result = $storage->upload($file, 'videos', $fileName);

            $fileInfo = [
                'name' => $file->getOriginalName(),
                'size' => $file->getSize(),
                'type' => $file->getMime(),
                'url' => $result['url'],
                'path' => $result['path']
            ];

            $storageFileInfo = [
                'user_id' => $userId,
                'filename' => $fileName,
                'filepath' => $result['url'],
                'filesize' => $file->getSize(),
                'mimetype' => $file->getMime(),
                'storage_type' => $storageConfig['type'],
                'status' => 1,
                'create_time' => time()
            ];
            Db::name('storage_files')->insert($storageFileInfo);

            return $this->success($fileInfo, '上传成功');
        } catch (\Exception $e) {
            return $this->error('上传失败: ' . $e->getMessage());
        }
    }

    public function uploadAudio()
    {
        $userId = session('user_id') ?: cookie('user_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        $file = Request::file('file');

        if (!$file) {
            return $this->badRequest('未选择文件');
        }

        $maxSize = 20 * 1024 * 1024;
        $sizeCheck = FileValidator::checkFileSize($file, $maxSize);
        if (!$sizeCheck['valid']) {
            return $this->badRequest($sizeCheck['message']);
        }

        $audioCheck = FileValidator::validateAudio($file);
        if (!$audioCheck['valid']) {
            return $this->badRequest($audioCheck['message']);
        }

        try {
            $storageConfig = $this->getStorageConfig();
            $storage = StorageFactory::create($storageConfig['type'], $storageConfig['config']);

            $fileName = FileValidator::generateSafeFileName($file->getOriginalName(), 'audio');
            $result = $storage->upload($file, 'audio', $fileName);

            $duration = input('duration', 0);

            $fileInfo = [
                'name' => $file->getOriginalName(),
                'size' => $file->getSize(),
                'type' => $file->getMime(),
                'url' => $result['url'],
                'path' => $result['path'],
                'duration' => $duration
            ];

            $storageFileInfo = [
                'user_id' => $userId,
                'filename' => $fileName,
                'filepath' => $result['url'],
                'filesize' => $file->getSize(),
                'mimetype' => $file->getMime(),
                'storage_type' => $storageConfig['type'],
                'status' => 1,
                'create_time' => time()
            ];
            Db::name('storage_files')->insert($storageFileInfo);

            return $this->success($fileInfo, '上传成功');
        } catch (\Exception $e) {
            return $this->error('上传失败: ' . $e->getMessage());
        }
    }

    /**
     * 聊天文件上传（支持图片、音频、视频）
     */
    public function chat()
    {
        $userId = session('user_id') ?: cookie('user_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        $file = Request::file('file');

        if (!$file) {
            return $this->badRequest('未选择文件');
        }

        $mimeType = $file->getMime();

        $maxSize = 10 * 1024 * 1024;
        $sizeCheck = FileValidator::checkFileSize($file, $maxSize);
        if (!$sizeCheck['valid']) {
            return $this->badRequest($sizeCheck['message']);
        }

        $subDir = '';
        $fileCheck = null;

        if (strpos($mimeType, 'image') === 0) {
            $subDir = 'images';
            $fileCheck = FileValidator::validateImage($file);
        } elseif (strpos($mimeType, 'audio') === 0) {
            $subDir = 'audio';
            $fileCheck = FileValidator::validateAudio($file);
        } elseif (strpos($mimeType, 'video') === 0) {
            $subDir = 'videos';
            $fileCheck = FileValidator::validateVideo($file);
        } else {
            return $this->badRequest('不支持的文件类型');
        }

        if (!$fileCheck['valid']) {
            return $this->badRequest($fileCheck['message']);
        }

        try {
            $storageConfig = $this->getStorageConfig();
            $storage = StorageFactory::create($storageConfig['type'], $storageConfig['config']);

            $fileName = FileValidator::generateSafeFileName($file->getOriginalName(), 'chat');
            $result = $storage->upload($file, $subDir, $fileName);

            $fileInfo = [
                'name' => $file->getOriginalName(),
                'size' => $file->getSize(),
                'type' => $mimeType,
                'url' => $result['url'],
                'path' => $result['path']
            ];

            if (strpos($mimeType, 'image') === 0 && isset($fileCheck['width'])) {
                $fileInfo['width'] = $fileCheck['width'];
                $fileInfo['height'] = $fileCheck['height'];
            }

            $storageFileInfo = [
                'user_id' => $userId,
                'filename' => $fileName,
                'filepath' => $result['url'],
                'filesize' => $file->getSize(),
                'mimetype' => $mimeType,
                'storage_type' => $storageConfig['type'],
                'status' => 1,
                'create_time' => time(),
                'update_time' => time()
            ];
            Db::name('storage_files')->insert($storageFileInfo);

            return $this->success($fileInfo, '上传成功');
        } catch (\Exception $e) {
            return $this->error('上传失败: ' . $e->getMessage());
        }
    }
}
