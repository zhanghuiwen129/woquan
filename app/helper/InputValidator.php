<?php

namespace app\helper;

class InputValidator
{
    private static $allowedTags = '<p><br><strong><em><u><a><ul><ol><li><blockquote><code><pre><h1><h2><h3><h4><h5><h6>';
    
    public static function sanitizeString($input, $allowTags = false)
    {
        if ($allowTags) {
            return strip_tags($input, self::$allowedTags);
        }
        return strip_tags($input);
    }
    
    public static function sanitizeHtml($input)
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    public static function sanitizeInt($input, $default = 0)
    {
        return filter_var($input, FILTER_VALIDATE_INT) !== false ? (int)$input : $default;
    }
    
    public static function sanitizeFloat($input, $default = 0.0)
    {
        return filter_var($input, FILTER_VALIDATE_FLOAT) !== false ? (float)$input : $default;
    }
    
    public static function sanitizeEmail($input)
    {
        return filter_var($input, FILTER_SANITIZE_EMAIL);
    }
    
    public static function sanitizeUrl($input)
    {
        return filter_var($input, FILTER_SANITIZE_URL);
    }
    
    public static function validateUsername($username)
    {
        return preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]{2,20}$/u', $username);
    }
    
    public static function validateMobile($mobile)
    {
        return preg_match('/^1[3-9]\d{9}$/', $mobile);
    }
    
    public static function validatePassword($password)
    {
        return strlen($password) >= 6 && strlen($password) <= 32;
    }
    
    public static function sanitizeArray(array $data, $rules = [])
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (isset($rules[$key])) {
                $sanitized[$key] = self::applyRule($value, $rules[$key]);
            } else {
                $sanitized[$key] = is_string($value) ? self::sanitizeString($value) : $value;
            }
        }
        return $sanitized;
    }
    
    private static function applyRule($value, $rule)
    {
        switch ($rule) {
            case 'int':
                return self::sanitizeInt($value);
            case 'float':
                return self::sanitizeFloat($value);
            case 'email':
                return self::sanitizeEmail($value);
            case 'url':
                return self::sanitizeUrl($value);
            case 'html':
                return self::sanitizeHtml($value);
            case 'string':
            default:
                return self::sanitizeString($value);
        }
    }
    
    public static function preventXSS($input)
    {
        if (is_array($input)) {
            return array_map([self::class, 'preventXSS'], $input);
        }
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
