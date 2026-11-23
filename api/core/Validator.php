<?php
/**
 * Hublog 数据验证类
 */

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;

class Validator
{
    private array $data = [];
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function required(string $field): self
    {
        // PHP 8 下缺失字段直接下标访问会抛 Warning（"Undefined array key"）。
        // 该警告会打印进 HTTP 响应体、拼在 JSON 前面，客户端 json_decode 直接失败。
        // 必须先 isset 判存在，再用 empty 判空（'0' 视为有效值）。
        if (!isset($this->data[$field]) || (empty($this->data[$field]) && $this->data[$field] !== '0')) {
            $this->errors[$field][] = "{$field}不能为空";
        }
        return $this;
    }

    public function string(string $field, int $min = 0, int $max = 65535): self
    {
        if (isset($this->data[$field])) {
            $len = mb_strlen((string)$this->data[$field]);
            if ($len < $min) {
                $this->errors[$field][] = "{$field}最少{$min}个字符";
            }
            if ($len > $max) {
                $this->errors[$field][] = "{$field}最多{$max}个字符";
            }
        }
        return $this;
    }

    public function email(string $field): self
    {
        if (!empty($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = "{$field}格式不正确";
        }
        return $this;
    }

    public function url(string $field): self
    {
        if (!empty($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_URL)) {
            $this->errors[$field][] = "{$field}不是有效的URL";
        }
        return $this;
    }

    public function in(string $field, array $values): self
    {
        if (isset($this->data[$field]) && !in_array($this->data[$field], $values, true)) {
            $this->errors[$field][] = "{$field}值不合法";
        }
        return $this;
    }

    public function regex(string $field, string $pattern, string $message): self
    {
        if (isset($this->data[$field]) && !preg_match($pattern, (string)$this->data[$field])) {
            $this->errors[$field][] = $message;
        }
        return $this;
    }

    public function int(string $field, ?int $min = null, ?int $max = null): self
    {
        if (isset($this->data[$field])) {
            if (!filter_var($this->data[$field], FILTER_VALIDATE_INT)) {
                $this->errors[$field][] = "{$field}必须是整数";
            } else {
                $val = (int)$this->data[$field];
                if ($min !== null && $val < $min) {
                    $this->errors[$field][] = "{$field}最小值为{$min}";
                }
                if ($max !== null && $val > $max) {
                    $this->errors[$field][] = "{$field}最大值为{$max}";
                }
            }
        }
        return $this;
    }

    public function array(string $field): self
    {
        if (isset($this->data[$field]) && !is_array($this->data[$field])) {
            $this->errors[$field][] = "{$field}必须是数组";
        }
        return $this;
    }

    public function validate(): array
    {
        if (!empty($this->errors)) {
            $firstError = '';
            foreach ($this->errors as $fieldErrors) {
                $firstError = $fieldErrors[0];
                break;
            }
            throw new HublogException($firstError, 422);
        }
        return $this->data;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
