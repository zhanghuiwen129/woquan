<?php
/**
 * P2-2：从 core/UploadProvider.php 单文件多类结构中拆分出来的直传签名返回结构。
 * 归到 Hublog\Core\UploadProvider 命名空间。
 */

namespace Hublog\Core\UploadProvider;

/** 直传签名返回结构（固定契约，经验 1277439 建议的 host/dir/policy/signature/accessId 统一封装） */
class UploadDirectSign
{
    public string $driver;
    public string $upload_url;
    public string $method = 'POST';
    /** @var array<string,string> */
    public array  $form_data = [];
    public string $object_key;
    public string $final_url;
    public int    $expires_at;
    public string $provider_hint = '';
    public string $file_field_name = 'file';

    public function toArray(): array
    {
        return [
            'driver'           => $this->driver,
            'upload_url'       => $this->upload_url,
            'method'           => $this->method,
            'form_data'        => $this->form_data,
            'object_key'       => $this->object_key,
            'final_url'        => $this->final_url,
            'expires_at'       => $this->expires_at,
            'provider_hint'    => $this->provider_hint,
            'file_field_name'  => $this->file_field_name,
        ];
    }
}
