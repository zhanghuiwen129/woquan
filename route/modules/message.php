<?php

use think\facade\Route;

Route::group(function () {
    Route::get('messages', 'Messages/index');
    Route::get('messages/:type', 'Messages/index');
    Route::get('message', 'Messages/index');
    Route::get('messages/unread', 'Moments/unread');
    Route::get('chat', 'Chat/index');
    Route::get('chat/:id', 'Chat/index')->pattern(['id' => '\d+']);
})->middleware('Auth');
