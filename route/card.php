<?php
// +----------------------------------------------------------------------
// | 个人名片路由配置
// +----------------------------------------------------------------------

use think\facade\Route;

// 名片相关路由
Route::get('card/:id', 'index/card')->name('card');
Route::get('card/visitors', 'index/cardVisitors')->name('card-visitors');
Route::get('card/settings', 'index/cardSettings')->name('card-settings');

// API路由
Route::any('user/card', 'user/card');
Route::post('user/updateCardSettings', 'user/updateCardSettings');
Route::post('user/cardVisitors', 'user/cardVisitors');
Route::post('user/cardQrcode', 'user/cardQrcode');
