<?php

if (!function_exists('e')) {
    function e($string, $doubleEncode = false)
    {
        if (is_array($string)) {
            return array_map(function($item) use ($doubleEncode) {
                return e($item, $doubleEncode);
            }, $string);
        }
        
        if (is_object($string)) {
            return htmlspecialchars(
                (string)$string,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
                $doubleEncode
            );
        }
        
        return htmlspecialchars(
            (string)$string,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
            $doubleEncode
        );
    }
}

if (!function_exists('clean_input')) {
    function clean_input($data)
    {
        if (is_array($data)) {
            return array_map('clean_input', $data);
        }
        
        return trim(strip_tags($data));
    }
}
