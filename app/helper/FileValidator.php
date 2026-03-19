<?php

namespace app\helper;

class FileValidator
{
    private static $allowedImageTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    
    private static $allowedVideoTypes = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/ogg' => 'ogg'
    ];
    
    private static $allowedAudioTypes = [
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/webm' => 'webm'
    ];
    
    private static $imageSignatures = [
        'ffd8ff' => 'jpg',
        '89504e47' => 'png',
        '47494638' => 'gif',
        '52494646' => 'webp'
    ];
    
    public static function validateImage($file)
    {
        if (!$file || !$file->isValid()) {
            return ['valid' => false, 'message' => '文件无效'];
        }
        
        $mimeType = $file->getMime();
        
        if (!isset(self::$allowedImageTypes[$mimeType])) {
            return ['valid' => false, 'message' => '不支持的图片格式'];
        }
        
        $extension = $file->extension();
        $expectedExtension = self::$allowedImageTypes[$mimeType];
        
        if (strtolower($extension) !== $expectedExtension) {
            return ['valid' => false, 'message' => '文件扩展名与内容不匹配'];
        }
        
        $signatureCheck = self::checkFileSignature($file);
        if (!$signatureCheck['valid']) {
            return $signatureCheck;
        }
        
        $imageInfo = @getimagesize($file->getPathname());
        if (!$imageInfo) {
            return ['valid' => false, 'message' => '无效的图片文件'];
        }
        
        $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if (!in_array($imageInfo[2], $allowedTypes)) {
            return ['valid' => false, 'message' => '不支持的图片类型'];
        }
        
        return [
            'valid' => true,
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'type' => $imageInfo[2]
        ];
    }
    
    public static function validateVideo($file)
    {
        if (!$file || !$file->isValid()) {
            return ['valid' => false, 'message' => '文件无效'];
        }
        
        $mimeType = $file->getMime();
        
        if (!isset(self::$allowedVideoTypes[$mimeType])) {
            return ['valid' => false, 'message' => '不支持的视频格式'];
        }
        
        $extension = $file->extension();
        $expectedExtension = self::$allowedVideoTypes[$mimeType];
        
        if (strtolower($extension) !== $expectedExtension) {
            return ['valid' => false, 'message' => '文件扩展名与内容不匹配'];
        }
        
        return ['valid' => true];
    }
    
    public static function validateAudio($file)
    {
        if (!$file || !$file->isValid()) {
            return ['valid' => false, 'message' => '文件无效'];
        }
        
        $mimeType = $file->getMime();
        
        if (!isset(self::$allowedAudioTypes[$mimeType])) {
            return ['valid' => false, 'message' => '不支持的音频格式'];
        }
        
        $extension = $file->extension();
        $expectedExtension = self::$allowedAudioTypes[$mimeType];
        
        if (strtolower($extension) !== $expectedExtension) {
            return ['valid' => false, 'message' => '文件扩展名与内容不匹配'];
        }
        
        return ['valid' => true];
    }
    
    private static function checkFileSignature($file)
    {
        $handle = fopen($file->getPathname(), 'rb');
        if (!$handle) {
            return ['valid' => false, 'message' => '无法读取文件'];
        }
        
        $bytes = fread($handle, 3);
        fclose($handle);
        
        if ($bytes === false) {
            return ['valid' => false, 'message' => '无法读取文件内容'];
        }
        
        $signature = bin2hex($bytes);
        
        if (!isset(self::$imageSignatures[$signature])) {
            return ['valid' => false, 'message' => '文件内容与扩展名不匹配'];
        }
        
        return ['valid' => true];
    }
    
    public static function sanitizeFileName($fileName)
    {
        $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
        $fileName = preg_replace('/_{2,}/', '_', $fileName);
        $fileName = trim($fileName, '_');
        
        if (empty($fileName)) {
            $fileName = 'file_' . time();
        }
        
        return $fileName;
    }
    
    public static function generateSafeFileName($originalName, $prefix = '')
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $extension = strtolower($extension);
        
        $randomPart = bin2hex(random_bytes(8));
        $timePart = date('Ymd_His');
        
        if (!empty($prefix)) {
            $prefix .= '_';
        }
        
        return $prefix . $timePart . '_' . $randomPart . '.' . $extension;
    }
    
    public static function checkFileSize($file, $maxSize)
    {
        $fileSize = $file->getSize();
        
        if ($fileSize > $maxSize) {
            $maxSizeMB = round($maxSize / 1024 / 1024, 2);
            return [
                'valid' => false,
                'message' => "文件大小不能超过{$maxSizeMB}MB"
            ];
        }
        
        return ['valid' => true];
    }
    
    public static function scanForMaliciousContent($filePath)
    {
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            return ['valid' => false, 'message' => '无法读取文件内容'];
        }
        
        $maliciousPatterns = [
            '/<\?php/i',
            '/<script/i',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/eval\s*\(/i',
            '/base64_decode\s*\(/i'
        ];
        
        foreach ($maliciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return ['valid' => false, 'message' => '文件包含可疑内容'];
            }
        }
        
        return ['valid' => true];
    }
}
