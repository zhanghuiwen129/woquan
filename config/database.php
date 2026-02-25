<?php

return [
    // 默认使用的数据库连接配置
    'default'         => 'mysql',

    // 数据库连接配置信息
    'connections'     => [
        'mysql' => [
            'type'            => 'mysql',
            'hostname' => env('DATABASE_HOSTNAME', 'localhost'),
            'database' => env('DATABASE_DATABASE', ''),
            'username' => env('DATABASE_USERNAME', 'root'),
            'password' => env('DATABASE_PASSWORD', ''),
            'hostport' => env('DATABASE_HOSTPORT', '3306'),
            'charset'         => 'utf8mb4',
            'collation'       => 'utf8mb4_unicode_ci',
            'prefix' => env('DATABASE_PREFIX', ''),
            // 数据库连接参数
            'params'          => [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
            // 数据库调试模式
            'debug'           => env('app.debug', false),
            // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
            'deploy'          => 0,
            // 数据库读写是否分离 主从式有效
            'rw_separate'     => false,
            // 读写分离后 主服务器数量
            'master_num'      => 1,
            // 指定从服务器序号
            'slave_no'        => '',
            // 是否严格检查字段是否存在
            'fields_strict'   => true,
        ],
    ],
    // 数据库日志记录
    // 当开启数据库日志记录时，可以记录执行的SQL语句
    // 建议生产环境关闭，开发环境可以开启用于调试
    'log_sql'         => env('database.log_sql', false),
];
