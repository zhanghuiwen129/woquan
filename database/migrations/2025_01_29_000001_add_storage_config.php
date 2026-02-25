<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddStorageConfig extends Migrator
{
    public function change()
    {
        $time = time();

        $this->table('system_config')
            ->insert([
                [
                    'config_key' => 'storage_type',
                    'config_value' => 'local',
                    'config_name' => '存储方式',
                    'config_type' => 'select',
                    'config_group' => 'upload',
                    'config_options' => '{"local":"本地存储","oss":"阿里云OSS","cos":"腾讯云COS","qiniu":"七牛云"}',
                    'sort' => 1,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'oss_access_key_id',
                    'config_value' => '',
                    'config_name' => '阿里云OSS AccessKey ID',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 10,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'oss_access_key_secret',
                    'config_value' => '',
                    'config_name' => '阿里云OSS AccessKey Secret',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 11,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'oss_bucket',
                    'config_value' => '',
                    'config_name' => '阿里云OSS Bucket',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 12,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'oss_endpoint',
                    'config_value' => '',
                    'config_name' => '阿里云OSS Endpoint',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 13,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'oss_domain',
                    'config_value' => '',
                    'config_name' => '阿里云OSS Bucket域名',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 14,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'oss_directory',
                    'config_value' => 'uploads',
                    'config_name' => '阿里云OSS存储目录',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 15,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'cos_secret_id',
                    'config_value' => '',
                    'config_name' => '腾讯云COS SecretId',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 20,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'cos_secret_key',
                    'config_value' => '',
                    'config_name' => '腾讯云COS SecretKey',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 21,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'cos_bucket',
                    'config_value' => '',
                    'config_name' => '腾讯云COS Bucket',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 22,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'cos_region',
                    'config_value' => '',
                    'config_name' => '腾讯云COS Region',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 23,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'cos_domain',
                    'config_value' => '',
                    'config_name' => '腾讯云COS Bucket域名',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 24,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'cos_directory',
                    'config_value' => 'uploads',
                    'config_name' => '腾讯云COS存储目录',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 25,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'qiniu_access_key',
                    'config_value' => '',
                    'config_name' => '七牛云AccessKey',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 30,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'qiniu_secret_key',
                    'config_value' => '',
                    'config_name' => '七牛云SecretKey',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 31,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'qiniu_bucket',
                    'config_value' => '',
                    'config_name' => '七牛云Bucket',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 32,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'qiniu_domain',
                    'config_value' => '',
                    'config_name' => '七牛云Domain',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 33,
                    'create_time' => $time,
                    'update_time' => $time
                ],
                [
                    'config_key' => 'qiniu_directory',
                    'config_value' => 'uploads',
                    'config_name' => '七牛云存储目录',
                    'config_type' => 'text',
                    'config_group' => 'upload',
                    'sort' => 34,
                    'create_time' => $time,
                    'update_time' => $time
                ]
            ])
            ->saveData();
    }
}
