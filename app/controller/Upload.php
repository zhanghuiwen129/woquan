<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\StorageFactory;
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

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMime(), $allowedTypes)) {
            return $this->badRequest('不支持的图片格式');
        }
        
        // 文件大小限制（10MB）
        $maxSize = 10 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return $this->badRequest('文件大小不能超过10MB');
        }
        
        // 验证文件内容
        $imageInfo = getimagesize($file->getPathname());
        if (!$imageInfo) {
            return $this->badRequest('无效的图片文件');
        }

        try {
            $storageConfig = $this->getStorageConfig();
            $storage = StorageFactory::create($storageConfig['type'], $storageConfig['config']);

            $fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $file->extension();
            $result = $storage->upload($file, 'images', $fileName);

            $imageInfo = getimagesize($result['path']);
            $fileInfo = [
                'name' => $file->getOriginalName(),
                'size' => $file->getSize(),
                'type' => $file->getMime(),
                'url' => $result['url'],
                'path' => $result['path'],
                'width' => $imageInfo[0],
                'height' => $imageInfo[1]
            ];

            // 将文件信息记录到storage_files表
            try {
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
            } catch (\Exception $e) {
                // 记录失败不影响上传流程，只记录错误
                error_log('记录文件信息到storage_files表失败: ' . $e->getMessage());
            }

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

        $allowedTypes = ['video/mp4', 'video/webm', 'video/ogg'];
        if (!in_array($file->getMime(), $allowedTypes)) {
            return $this->badRequest('不支持的视频格式');
        }

        try {
            $uploadDir = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'videos';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileName = date('Ymd_His') . '_' . uniqid() . '.' . $file->extension();
            $filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
            $fileUrl = '/static/upload/videos/' . $fileName;

            $file->move($uploadDir, $fileName);

            $fileInfo = [
                'name' => $file->getOriginalName(),
                'size' => $file->getSize(),
                'type' => $file->getMime(),
                'url' => $fileUrl,
                'path' => $filePath
            ];

            // 将文件信息记录到storage_files表
            try {
                $storageFileInfo = [
                    'user_id' => $userId,
                    'filename' => $fileName,
                    'filepath' => $fileUrl,
                    'filesize' => $file->getSize(),
                    'mimetype' => $file->getMime(),
                    'storage_type' => 'local',
                    'status' => 1,
                    'create_time' => time()
                ];
                Db::name('storage_files')->insert($storageFileInfo);
            } catch (\Exception $e) {
                // 记录失败不影响上传流程，只记录错误
                error_log('记录文件信息到storage_files表失败: ' . $e->getMessage());
            }

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

        $allowedTypes = ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/webm'];
        if (!in_array($file->getMime(), $allowedTypes)) {
            return $this->badRequest('不支持的音频格式');
        }

        try {
            $uploadDir = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'audio';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileName = date('Ymd_His') . '_' . uniqid() . '.' . $file->extension();
            $filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
            $fileUrl = '/static/upload/audio/' . $fileName;

            $file->move($uploadDir, $fileName);

            $duration = input('duration', 0);

            $fileInfo = [
                'name' => $file->getOriginalName(),
                'size' => $file->getSize(),
                'type' => $file->getMime(),
                'url' => $fileUrl,
                'path' => $filePath,
                'duration' => $duration
            ];

            // 将文件信息记录到storage_files表
            try {
                $storageFileInfo = [
                    'user_id' => $userId,
                    'filename' => $fileName,
                    'filepath' => $fileUrl,
                    'filesize' => $file->getSize(),
                    'mimetype' => $file->getMime(),
                    'storage_type' => 'local',
                    'status' => 1,
                    'create_time' => time()
                ];
                Db::name('storage_files')->insert($storageFileInfo);
            } catch (\Exception $e) {
                // 记录失败不影响上传流程，只记录错误
                error_log('记录文件信息到storage_files表失败: ' . $e->getMessage());
            }

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

        // 根据文件类型确定保存目录
        if (strpos($mimeType, 'image') === 0) {
            $subDir = 'images';
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        } elseif (strpos($mimeType, 'audio') === 0) {
            $subDir = 'audio';
            $allowedTypes = ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/webm'];
        } elseif (strpos($mimeType, 'video') === 0) {
            $subDir = 'videos';
            $allowedTypes = ['video/mp4', 'video/webm', 'video/ogg'];
        } else {
            return $this->badRequest('不支持的文件类型');
        }

        if (!in_array($mimeType, $allowedTypes)) {
            return $this->badRequest('不支持的文件格式');
        }

        try {
            $storageConfig = $this->getStorageConfig();
            $storage = StorageFactory::create($storageConfig['type'], $storageConfig['config']);

            $fileName = date('Ymd_His') . '_' . uniqid() . '.' . $file->extension();
            $result = $storage->upload($file, $subDir, $fileName);

            $fileInfo = [
                'name' => $file->getOriginalName(),
                'size' => $file->getSize(),
                'type' => $mimeType,
                'url' => $result['url'],
                'path' => $result['path']
            ];

            // 如果是图片，获取尺寸
            if (strpos($mimeType, 'image') === 0) {
                $imageInfo = getimagesize($result['path']);
                $fileInfo['width'] = $imageInfo[0];
                $fileInfo['height'] = $imageInfo[1];
            }

            // 将文件信息记录到storage_files表
            try {
                $storageFileInfo = [
                    'user_id' => $userId,
                    'filename' => $fileName,
                    'filepath' => $result['url'],
                    'filesize' => $fileSize,
                    'mimetype' => $mimeType,
                    'storage_type' => $storageConfig['type'],
                    'status' => 1,
                    'create_time' => time(),
                    'update_time' => time()
                ];
                Db::name('storage_files')->insert($storageFileInfo);
            } catch (\Exception $e) {
                // 记录失败不影响上传流程，只记录错误
                error_log('记录文件信息到storage_files表失败: ' . $e->getMessage());
            }

            return $this->success($fileInfo, '上传成功');
        } catch (\Exception $e) {
            return $this->error('上传失败: ' . $e->getMessage());
        }
    }
}
