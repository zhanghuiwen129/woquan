<?php
// +----------------------------------------------------------------------
// | 会话设置
// +----------------------------------------------------------------------

return [
    // session name
    'name'           => 'PHPSESSID',
    // SESSION_ID的提交变量,解决flash上传跨域
    'var_session_id' => '',
    // 驱动方式 支持file cache
    'type'           => 'file',
    // 存储连接标识 当type使用cache的时候有效
    'store'          => null,
    // 存储路径 - 使用绝对路径确保可写
    'path'           => runtime_path() . 'session',
    // 过期时间
    'expire'         => 7200,
    // 前缀
    'prefix'         => 'lan_',
    // 自动开启session
    'auto_start'     => true,
    // Cookie配置
    'cookie'         => [
        'path'     => '/',
        'domain'   => '',
        'secure'   => env('app.env') === 'production',
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
