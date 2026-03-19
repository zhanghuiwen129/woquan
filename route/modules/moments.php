<?php

use think\facade\Route;

Route::group(function () {
    Route::get('moments', 'Moments/page');
    Route::get('api/moments', 'Moments/apiList');
    Route::get('api/moments/detail', 'Moments/detail');
    Route::post('moments/publish', 'Moments/publish');
    Route::post('moments/getUnreadCount', 'Moments/getUnreadCount');
    Route::post('moments/getConversationList', 'Moments/getConversationList');
    Route::post('moments/getTopics', 'Moments/getTopics');
    Route::get('api/hot-topics', 'Moments/getHotTopics');
    Route::get('api/online-users', 'Moments/onlineUsers');
    Route::get('moments/:id', 'Moments/detailPage')->pattern(['id' => '\d+']);
})->middleware('Auth');
