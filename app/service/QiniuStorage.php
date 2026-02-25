<?php
declare(strict_types = 1);

namespace app\service;

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
