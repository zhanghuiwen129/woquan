<?php
declare(strict_types = 1);

namespace app\service;

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
