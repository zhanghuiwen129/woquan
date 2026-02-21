<?php
declare(strict_types = 1);

namespace app\service;

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
