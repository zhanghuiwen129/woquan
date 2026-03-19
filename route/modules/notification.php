<?php

use think\facade\Route;

Route::group(function () {
    Route::get('notifications', 'Notifications/index');
    Route::get('notifications/getNotifications', 'Notifications/getNotifications');
    Route::post('notifications/markAsRead', 'Notifications/markAsRead');
    Route::post('notifications/batchMarkAsRead', 'Notifications/batchMarkAsRead');
    Route::post('notifications/batchDelete', 'Notifications/batchDelete');
    Route::get('notifications/getUnreadCount', 'Notifications/getUnreadCount');
    Route::get('notifications/getBadge', 'Notifications/getBadge');
})->middleware('Auth');
