<?php
declare(strict_types = 1);

namespace app\service;

interface StorageInterface
{
    public function upload($file, $directory, $fileName);
    public function delete($filePath);
    public function getUrl($filePath);
}

class LocalStorage implements StorageInterface
{
    private $basePath;

    public function __construct()
    {
        $this->basePath = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'upload';
    }

    public function upload($file, $directory, $fileName)
    {
        $uploadDir = $this->basePath . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
        $file->move($uploadDir, $fileName);

        return [
            'path' => $filePath,
            'url' => '/static/upload/' . $directory . '/' . $fileName
        ];
    }

    public function delete($filePath)
    {
        $fullPath = ROOT_PATH . 'public' . $filePath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function getUrl($filePath)
    {
        return $filePath;
    }
}

class OssStorage implements StorageInterface
{
    private $accessKeyId;
    private $accessKeySecret;
    private $bucket;
    private $endpoint;
    private $domain;
    private $directory;

    public function __construct($config)
    {
        $this->accessKeyId = $config['access_key_id'] ?? '';
        $this->accessKeySecret = $config['access_key_secret'] ?? '';
        $this->bucket = $config['bucket'] ?? '';
        $this->endpoint = $config['endpoint'] ?? '';
        $this->domain = $config['domain'] ?? '';
        $this->directory = $config['directory'] ?? 'uploads';
    }

    public function upload($file, $directory, $fileName)
    {
        $object = $this->directory . '/' . $directory . '/' . $fileName;
        $filePath = $file->getPathname();

        try {
            $ossClient = new \OSS\OssClient(
                $this->accessKeyId,
                $this->accessKeySecret,
                $this->endpoint
            );

            $ossClient->uploadFile(
                $this->bucket,
                $object,
                $filePath
            );

            $url = rtrim($this->domain, '/') . '/' . $object;

            return [
                'path' => $object,
                'url' => $url
            ];
        } catch (\Exception $e) {
            throw new \Exception('OSS上传失败: ' . $e->getMessage());
        }
    }

    public function delete($filePath)
    {
        try {
            $ossClient = new \OSS\OssClient(
                $this->accessKeyId,
                $this->accessKeySecret,
                $this->endpoint
            );

            $ossClient->deleteObject($this->bucket, $filePath);
        } catch (\Exception $e) {
            throw new \Exception('OSS删除失败: ' . $e->getMessage());
        }
    }

    public function getUrl($filePath)
    {
        return rtrim($this->domain, '/') . '/' . $filePath;
    }
}

class CosStorage implements StorageInterface
{
    private $secretId;
    private $secretKey;
    private $bucket;
    private $region;
    private $domain;
    private $directory;

    public function __construct($config)
    {
        $this->secretId = $config['secret_id'] ?? '';
        $this->secretKey = $config['secret_key'] ?? '';
        $this->bucket = $config['bucket'] ?? '';
        $this->region = $config['region'] ?? '';
        $this->domain = $config['domain'] ?? '';
        $this->directory = $config['directory'] ?? 'uploads';
    }

    public function upload($file, $directory, $fileName)
    {
        $object = $this->directory . '/' . $directory . '/' . $fileName;
        $filePath = $file->getPathname();

        try {
            $cosClient = new \Qcloud\Cos\Client([
                'region' => $this->region,
                'credentials' => [
                    'secretId' => $this->secretId,
                    'secretKey' => $this->secretKey
                ]
            ]);

            $cosClient->upload(
                $bucket = $this->bucket,
                $key = $object,
                $body = fopen($filePath, 'rb')
            );

            $url = rtrim($this->domain, '/') . '/' . $object;

            return [
                'path' => $object,
                'url' => $url
            ];
        } catch (\Exception $e) {
            throw new \Exception('COS上传失败: ' . $e->getMessage());
        }
    }

    public function delete($filePath)
    {
        try {
            $cosClient = new \Qcloud\Cos\Client([
                'region' => $this->region,
                'credentials' => [
                    'secretId' => $this->secretId,
                    'secretKey' => $this->secretKey
                ]
            ]);

            $cosClient->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $filePath
            ]);
        } catch (\Exception $e) {
            throw new \Exception('COS删除失败: ' . $e->getMessage());
        }
    }

    public function getUrl($filePath)
    {
        return rtrim($this->domain, '/') . '/' . $filePath;
    }
}

class QiniuStorage implements StorageInterface
{
    private $accessKey;
    private $secretKey;
    private $bucket;
    private $domain;
    private $directory;

    public function __construct($config)
    {
        $this->accessKey = $config['access_key'] ?? '';
        $this->secretKey = $config['secret_key'] ?? '';
        $this->bucket = $config['bucket'] ?? '';
        $this->domain = $config['domain'] ?? '';
        $this->directory = $config['directory'] ?? 'uploads';
    }

    public function upload($file, $directory, $fileName)
    {
        $object = $this->directory . '/' . $directory . '/' . $fileName;
        $filePath = $file->getPathname();

        try {
            $auth = new \Qiniu\Auth($this->accessKey, $this->secretKey);
            $token = $auth->uploadToken($this->bucket, $object, 3600);

            $uploadManager = new \Qiniu\UploadManager();
            list($ret, $error) = $uploadManager->putFile(
                $token,
                $object,
                $filePath
            );

            if ($error !== null) {
                throw new \Exception('七牛上传失败: ' . $error->message());
            }

            $url = rtrim($this->domain, '/') . '/' . $object;

            return [
                'path' => $object,
                'url' => $url
            ];
        } catch (\Exception $e) {
            throw new \Exception('七牛上传失败: ' . $e->getMessage());
        }
    }

    public function delete($filePath)
    {
        try {
            $auth = new \Qiniu\Auth($this->accessKey, $this->secretKey);
            $config = new \Qiniu\Config();
            $bucketManager = new \Qiniu\BucketManager($auth, $config);

            $bucketManager->delete($this->bucket, $filePath);
        } catch (\Exception $e) {
            throw new \Exception('七牛删除失败: ' . $e->getMessage());
        }
    }

    public function getUrl($filePath)
    {
        return rtrim($this->domain, '/') . '/' . $filePath;
    }
}

class StorageFactory
{
    public static function create($type, $config = [])
    {
        switch ($type) {
            case 'oss':
                return new OssStorage($config);
            case 'cos':
                return new CosStorage($config);
            case 'qiniu':
                return new QiniuStorage($config);
            case 'local':
            default:
                return new LocalStorage();
        }
    }
}
