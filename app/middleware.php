<?php

// 全局中间件定义文件
return [
    // Session初始化
    \think\middleware\SessionInit::class,
    // 安装检查中间件
    \app\middleware\InstallCheck::class,
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
];

// 别名定义
\think\facade\Middleware::alias([
    'Auth' => \app\middleware\Auth::class,
    'AdminAuth' => \app\middleware\AdminAuth::class,
]);
