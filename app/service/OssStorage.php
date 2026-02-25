<?php
declare(strict_types = 1);

namespace app\service;

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
