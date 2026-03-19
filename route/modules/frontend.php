<?php

use think\facade\Route;

Route::group(function () {
    Route::get('discover', 'Discovery/index');
    Route::get('activities', 'Discovery/activities');
    Route::get('discovery/getActivityList', 'Discovery/getActivityList');
    Route::get('discovery/getHotActivities', 'Discovery/getHotActivities');
    Route::get('discovery/getActivityDetail', 'Discovery/getActivityDetail');
    Route::post('discovery/participateActivity', 'Discovery/participateActivity');
    Route::post('discovery/cancelParticipation', 'Discovery/cancelParticipation');
    Route::get('discovery/getMyActivities', 'Discovery/getMyActivities');
})->middleware('Auth');

Route::group(function () {
    Route::get('topic', 'Topics/index');
    Route::get('topic/:id', 'Topics/index')->pattern(['id' => '\d+']);
})->middleware('Auth');

Route::group(function () {
    Route::get('wallet', 'Wallet/index');
    Route::get('wallet/recharge', 'Wallet/recharge');
    Route::get('wallet/withdraw', 'Wallet/withdraw');
    Route::get('wallet/transactions', 'Wallet/transactions');
    Route::get('wallet/getWalletInfo', 'Wallet/getWalletInfo');
    Route::post('wallet/createRechargeOrder', 'Wallet/createRechargeOrder');
    Route::post('wallet/createWithdraw', 'Wallet/createWithdraw');
    Route::post('wallet/rechargeCallback', 'Wallet/rechargeCallback');
})->middleware('Auth');

Route::group(function () {
    Route::get('settings', 'Settings/index');
    Route::get('settings/getNotificationSettings', 'Settings/getNotificationSettings');
    Route::post('settings/updateNotificationSettings', 'Settings/updateNotificationSettings');
    Route::get('settings/getRealnameAuth', 'Settings/getRealnameAuth');
    Route::post('settings/submitRealnameAuth', 'Settings/submitRealnameAuth');
})->middleware('Auth');

Route::group(function () {
    Route::get('levels', 'Levels/index');
    Route::get('level', 'Levels/index');
    Route::get('api/levels/info', 'Levels/getUserLevel');
    Route::get('api/levels/ranking', 'Levels/getRanking');
})->middleware('Auth');

Route::group(function () {
    Route::get('faq', 'Faq/index');
})->middleware('Auth');

Route::group(function () {
    Route::get('points', 'Points/index');
    Route::get('api/points/info', 'Points/getUserPoints');
    Route::get('api/points/history', 'Points/getPointsRecords');
    Route::get('api/points/rules', 'Points/getPointsRules');
    Route::post('api/points/exchange', 'Points/exchangePoints');
    Route::get('api/exchange/items', 'Points/getExchangeItems');
    Route::get('api/exchange/orders', 'Points/getExchangeOrders');
    Route::post('api/points/history/clear', 'Points/clearHistory');
})->middleware('Auth');

Route::group(function () {
    Route::get('api/tasks/daily', 'Tasks/getDailyTasks');
    Route::get('api/tasks/growth', 'Tasks/getGrowthTasks');
    Route::post('api/tasks/complete', 'Tasks/completeTask');
})->middleware('Auth');

Route::group(function () {
    Route::get('operation', 'Operation/index');
})->middleware('Auth');

Route::group(function () {
    Route::get('security', 'Security/index');
})->middleware('Auth');

Route::group(function () {
    Route::get('themes', 'Themes/index');
})->middleware('Auth');

Route::group(function () {
    Route::get('friends', 'Friends/index');
})->middleware('Auth');

Route::group(function () {
    Route::get('favorites', 'Index/favorites');
    Route::get('drafts', 'Index/drafts');
    Route::get('visitors', 'Index/visitors');
    Route::get('mentions', 'Index/mentions');
    Route::get('login-logs', 'Index/loginLogs');
    Route::get('search-history', 'Index/searchHistory');
})->middleware('Auth');

Route::group(function () {
    Route::get('article/publish', 'Index/articlePublish');
    Route::get('article/edit/:id', 'Index/articleEdit')->pattern(['id' => '\d+']);
    Route::get('article/drafts', 'Index/articleDrafts');
    Route::get('article/:id', 'Index/articleDetail')->pattern(['id' => '\d+']);
    Route::get('article', 'Index/articleList');
    Route::get('articles/publish', 'Index/articlePublish');
    Route::get('articles/edit/:id', 'Index/articleEdit')->pattern(['id' => '\d+']);
    Route::get('articles/drafts', 'Index/articleDrafts');
    Route::get('articles/:id', 'Index/articleDetail')->pattern(['id' => '\d+']);
    Route::get('articles', 'Index/articleList');
})->middleware('Auth');

Route::group(function () {
    Route::get('api/articles/detail', 'Article/detail');
    Route::get('api/articles/categories', 'Article/getCategories');
    Route::post('api/fix/articles/table', 'Article/fixTable');
    Route::get('api/articles', 'Article/apiList');
    Route::post('api/articles/publish', 'Article/publish');
    Route::post('api/articles/update', 'Article/update');
    Route::post('api/articles/delete', 'Article/delete');
    Route::post('api/articles/like', 'Article/like');
    Route::post('api/articles/collect', 'Article/collect');
    Route::get('api/article-comments/list', 'Article/comments');
    Route::post('api/article-comments/add', 'Article/addComment');
    Route::post('api/article-comments/like', 'Article/likeComment');
})->middleware('Auth');

Route::group(function () {
    Route::get('api/comments/list', 'Comments/list');
    Route::post('api/comments/add', 'Comments/add');
    Route::post('api/comments/like', 'Comments/like');
    Route::post('api/comments/delete', 'Comments/delete');
})->middleware('Auth');

Route::group(function () {
    Route::post('api/location/save', 'Location/save');
    Route::get('api/location/nearby', 'Location/nearby');
    Route::get('api/location/reverse', 'Location/reverseGeocode');
    Route::get('api/location/popular', 'Location/popular');
})->middleware('Auth');

Route::group(function () {
    Route::get('emojis/getEmojiList', 'Emojis/getEmojiList');
    Route::post('emojis/uploadEmoji', 'Emojis/uploadEmoji');
    Route::post('emojis/deleteEmoji', 'Emojis/deleteEmoji');
    Route::post('emojis/recordUsage', 'Emojis/recordUsage');
    Route::get('emojis/searchEmojis', 'Emojis/searchEmojis');
})->middleware('Auth');

Route::group(function () {
    Route::get('api/activities', 'Activities/index');
})->middleware('Auth');

Route::group(function () {
    Route::get('search', 'Search/index');
})->middleware('Auth');
