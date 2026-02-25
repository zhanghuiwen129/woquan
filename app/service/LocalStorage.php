<?php
declare(strict_types = 1);

namespace app\service;

class LocalStorage implements StorageInterface
{
    private $basePath;

    public function __construct()
    {
        $this->basePath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'upload';
    }

    public function upload($file, $directory, $fileName)
    {
        $uploadDir = $this->basePath . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

        if ($directory === 'images') {
            $this->compressImage($file, $filePath);
        } else {
            $file->move($uploadDir, $fileName);
        }

        return [
            'path' => $filePath,
            'url' => '/static/upload/' . $directory . '/' . $fileName
        ];
    }

    private function compressImage($file, $targetPath)
    {
        $config = $this->getCompressConfig();

        if (!$config['enabled']) {
            $file->move(dirname($targetPath), basename($targetPath));
            return;
        }

        $sourcePath = $file->getPathname();

        if (!file_exists($sourcePath)) {
            $file->move(dirname($targetPath), basename($targetPath));
            return;
        }

        $imageInfo = getimagesize($sourcePath);

        if (!$imageInfo) {
            $file->move(dirname($targetPath), basename($targetPath));
            return;
        }

        $mimeType = $imageInfo['mime'];
        $sourceImage = null;

        switch ($mimeType) {
            case 'image/jpeg':
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                $sourceImage = imagecreatefromwebp($sourcePath);
                break;
            default:
                $file->move(dirname($targetPath), basename($targetPath));
                return;
        }

        if (!$sourceImage) {
            $file->move(dirname($targetPath), basename($targetPath));
            return;
        }

        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);

        if ($config['max_width'] > 0 && $width > $config['max_width']) {
            $ratio = $config['max_width'] / $width;
            $newWidth = $config['max_width'];
            $newHeight = (int)($height * $ratio);
        } elseif ($config['max_height'] > 0 && $height > $config['max_height']) {
            $ratio = $config['max_height'] / $height;
            $newHeight = $config['max_height'];
            $newWidth = (int)($width * $ratio);
        } else {
            $newWidth = $width;
            $newHeight = $height;
        }

        if ($newWidth !== $width || $newHeight !== $height) {
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($sourceImage);
            $sourceImage = $newImage;
        }

        switch ($mimeType) {
            case 'image/jpeg':
                imagejpeg($sourceImage, $targetPath, $config['quality']);
                break;
            case 'image/png':
                $pngQuality = (int)(9 - ($config['quality'] * 9 / 100));
                imagepng($sourceImage, $targetPath, $pngQuality);
                break;
            case 'image/webp':
                imagewebp($sourceImage, $targetPath, $config['quality']);
                break;
            default:
                imagegif($sourceImage, $targetPath);
                break;
        }

        imagedestroy($sourceImage);
    }

    private function getCompressConfig()
    {
        try {
            $enabled = \think\facade\Db::name('system_config')
                ->where('config_key', 'image_compress_enabled')
                ->value('config_value');

            $quality = \think\facade\Db::name('system_config')
                ->where('config_key', 'image_compress_quality')
                ->value('config_value');

            $maxWidth = \think\facade\Db::name('system_config')
                ->where('config_key', 'image_max_width')
                ->value('config_value');

            $maxHeight = \think\facade\Db::name('system_config')
                ->where('config_key', 'image_max_height')
                ->value('config_value');

            return [
                'enabled' => $enabled == '1',
                'quality' => (int)($quality ?: 75),
                'max_width' => (int)($maxWidth ?: 1920),
                'max_height' => (int)($maxHeight ?: 1080)
            ];
        } catch (\Exception $e) {
            return [
                'enabled' => false,
                'quality' => 75,
                'max_width' => 1920,
                'max_height' => 1080
            ];
        }
    }

    public function delete($filePath)
    {
        $fullPath = app()->getRootPath() . 'public' . $filePath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function getUrl($filePath)
    {
        return $filePath;
    }
}
