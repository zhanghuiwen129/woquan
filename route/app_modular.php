<?php

use think\facade\Route;

Route::group(function () {
    Route::get('/', 'Index/index');
    Route::get('index', 'Index/index');
    Route::get('site-config', 'Index/siteConfig');
    Route::post('api/js-error-log', 'Index/jsErrorLog');
});

Route::group(function () {
    require_once __DIR__ . '/modules/user.php';
    require_once __DIR__ . '/modules/moments.php';
    require_once __DIR__ . '/modules/message.php';
    require_once __DIR__ . '/modules/notification.php';
    require_once __DIR__ . '/modules/frontend.php';
});
