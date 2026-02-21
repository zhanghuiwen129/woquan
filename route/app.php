<?php
// +----------------------------------------------------------------------
// | 前台路由配置
// +----------------------------------------------------------------------

use think\facade\Route;
use think\facade\Db;

// 先定义动态详情路由（必须放在其他路由之前）
Route::get('moments/:id', 'Moments/detailPage')->pattern(['id' => '\d+']);

// 先加载所有路由文件
// 加载后台路由
require_once __DIR__ . '/admin.php';

// 加载名片路由
require_once __DIR__ . '/card.php';

// 加载API路由
require_once __DIR__ . '/api.php';

// 首页路由（游客可访问）
Route::get('/', 'Index/index');
Route::get('index', 'Index/index');

// 网站配置API（无需登录）
Route::get('site-config', 'Index/siteConfig');

// JavaScript错误日志API
Route::post('api/js-error-log', 'Index/jsErrorLog');

// 发现页路由
Route::get('discover', 'Discovery/index')->middleware('Auth');
Route::get('activities', 'Discovery/activities')->middleware('Auth');
Route::get('discovery/getActivityList', 'Discovery/getActivityList');
Route::get('discovery/getHotActivities', 'Discovery/getHotActivities');
Route::get('discovery/getActivityDetail', 'Discovery/getActivityDetail');
Route::post('discovery/participateActivity', 'Discovery/participateActivity');
Route::post('discovery/cancelParticipation', 'Discovery/cancelParticipation');
Route::get('discovery/getMyActivities', 'Discovery/getMyActivities');

// 话题页路由
Route::get('topic', 'Topics/index')->middleware('Auth');
Route::get('topic/:id', 'Topics/index')->middleware('Auth');

// 登录注册页面路由（必须在user/:id之前）
Route::get('login', 'User/login');
Route::get('register', 'User/register');

// 用户API路由（必须在user/:id之前）
Route::get('user/check', 'User/check');
Route::get('user/getUserProfile', 'User/getUserProfile');
Route::get('user/profile/:id', 'User/profile');
Route::get('user/register-config', 'User/registerConfig');
Route::post('user/login', 'User/login');
Route::post('user/register', 'User/register');
Route::post('user/logout', 'User/logout');
Route::post('api/logout', 'User/logout');
Route::get('user/captcha', 'User/captcha');
Route::post('user/sendSms', 'User/sendSms');
Route::get('users/recommended', 'User/getRecommendedUsers');
// 关注相关路由
Route::post('user/follow', 'User/follow')->middleware('Auth');
Route::post('user/unfollow', 'User/unfollow')->middleware('Auth');
Route::get('user/following', 'User/getFollowing')->middleware('Auth');
Route::get('user/followers', 'User/getFollowers')->middleware('Auth');
Route::get('user/card', 'User/card');
Route::post('user/updateCardSettings', 'User/updateCardSettings')->middleware('Auth');
Route::get('user/card/visitors', 'User/cardVisitors')->middleware('Auth');
Route::get('user/visitors', 'User/cardVisitors')->middleware('Auth');
Route::get('user/moments', 'User/getUsermoments')->middleware('Auth');
Route::get('user/favorites', 'User/getUserFavorites')->middleware('Auth');
Route::get('user/notifications', 'User/getNotifications')->middleware('Auth');
Route::get('user/mentions', 'User/getMentions')->middleware('Auth');
Route::post('user/markMentionRead', 'User/markMentionRead')->middleware('Auth');
Route::get('user/login-logs', 'User/getLoginLogs')->middleware('Auth');
Route::post('user/uploadAvatar', 'User/uploadAvatar')->middleware('Auth');
Route::post('upload/uploadFile', 'Upload/uploadFile')->middleware('Auth');
Route::get('user/getCurrentUser', 'User/getCurrentUser');
Route::get('user/getCurrentUserProfile', 'User/getCurrentUserProfile');
Route::post('user/updateProfile', 'User/updateProfile');
Route::post('user/changePassword', 'User/changePassword');
Route::post('user/updateSettings', 'User/updateSettings')->middleware('Auth');
Route::get('user/list', 'User/listUsers')->middleware('Auth');
Route::get('user/search', 'User/searchUsers')->middleware('Auth');
Route::post('user/sendResetSms', 'User/sendResetSms');
Route::post('user/resetPassword', 'User/resetPassword');

// 用户头像路由
Route::get('user/avatar', 'User/avatar');

// 用户相关路由(单数)
Route::get('user/:id', 'User/index');
Route::get('user/:id/following', 'User/following');
Route::get('user/:id/followers', 'User/followers');
Route::get('user/:id/moments', 'User/moments');

// 用户相关路由(复数,兼容性)
Route::get('users/:id', 'User/index');
Route::get('users/:id/following', 'User/following');
Route::get('users/:id/followers', 'User/followers');
Route::get('users/:id/moments', 'User/moments');

// 个人资料页路由
Route::get('profile', 'Index/profile')->middleware('Auth');

// 钱包相关路由
Route::get('wallet', 'Wallet/index')->middleware('Auth');
Route::get('wallet/recharge', 'Wallet/recharge')->middleware('Auth');
Route::get('wallet/withdraw', 'Wallet/withdraw')->middleware('Auth');
Route::get('wallet/transactions', 'Wallet/transactions')->middleware('Auth');

// 钱包API路由
Route::get('wallet/getWalletInfo', 'Wallet/getWalletInfo')->middleware('Auth');
Route::post('wallet/createRechargeOrder', 'Wallet/createRechargeOrder')->middleware('Auth');
Route::post('wallet/createWithdraw', 'Wallet/createWithdraw')->middleware('Auth');
Route::post('wallet/rechargeCallback', 'Wallet/rechargeCallback');

// 关注相关页面路由
Route::get('following', 'User/following')->middleware('Auth');
Route::get('followers', 'User/followers')->middleware('Auth');
// 收藏相关页面路由
Route::get('favorites', 'Index/favorites')->middleware('Auth');
// 草稿相关页面路由
Route::get('drafts', 'Index/drafts')->middleware('Auth');
// 访客记录页面路由
Route::get('visitors', 'Index/visitors')->middleware('Auth');
// @提及页面路由
Route::get('mentions', 'Index/mentions')->middleware('Auth');
// 登录日志页面路由
Route::get('login-logs', 'Index/loginLogs')->middleware('Auth');
// 搜索历史页面路由
Route::get('search-history', 'Index/searchHistory')->middleware('Auth');

// 动态相关路由（游客可查看动态）
Route::get('moments', 'Moments/page');
Route::get('api/moments/detail', 'Moments/detail');
Route::post('moments/publish', 'Moments/publish')->middleware('Auth');
Route::post('moments/getUnreadCount', 'Moments/getUnreadCount');
Route::post('moments/getConversationList', 'Moments/getConversationList');
Route::post('moments/getTopics', 'Moments/getTopics');

// 消息相关路由
Route::get('messages/unread', 'Moments/unread');
// 移除Auth中间件,在控制器内部检查登录状态
Route::get('messages', 'Messages/index');
Route::get('messages/:type', 'Messages/index');
Route::get('message', 'Messages/index');

// 聊天相关路由
// /chat?to_user_id=xxx 或 /chat/xxx
Route::get('chat', 'Chat/index')->middleware('Auth');
Route::get('chat/:id', 'Chat/index')->middleware('Auth');

// 搜索相关路由
Route::get('search', 'Search/index')->middleware('Auth');

// 设置相关路由
Route::get('settings', 'Settings/index')->middleware('Auth');
Route::get('settings/getNotificationSettings', 'Settings/getNotificationSettings')->middleware('Auth');
Route::post('settings/updateNotificationSettings', 'Settings/updateNotificationSettings')->middleware('Auth');
Route::get('settings/getRealnameAuth', 'Settings/getRealnameAuth')->middleware('Auth');
Route::post('settings/submitRealnameAuth', 'Settings/submitRealnameAuth')->middleware('Auth');

// 用户相关路由
Route::get('user/getLoginHistory', 'User/getLoginHistory')->middleware('Auth');
Route::get('user/exportUserData', 'User/exportUserData')->middleware('Auth');
Route::post('user/deleteAccount', 'User/deleteAccount')->middleware('Auth');

// 通知相关路由
Route::get('notifications/getNotifications', 'Notifications/getNotifications')->middleware('Auth');
Route::post('notifications/markAsRead', 'Notifications/markAsRead')->middleware('Auth');
Route::post('notifications/batchMarkAsRead', 'Notifications/batchMarkAsRead')->middleware('Auth');
Route::post('notifications/batchDelete', 'Notifications/batchDelete')->middleware('Auth');
Route::get('notifications/getUnreadCount', 'Notifications/getUnreadCount')->middleware('Auth');
Route::get('notifications/getBadge', 'Notifications/getBadge')->middleware('Auth');
Route::get('notifications', 'Notifications/index')->middleware('Auth');

// 等级相关路由
Route::get('levels', 'Levels/index')->middleware('Auth');
Route::get('level', 'Levels/index')->middleware('Auth');
Route::get('api/levels/info', 'Levels/getUserLevel')->middleware('Auth');
Route::get('api/levels/ranking', 'Levels/getRanking')->middleware('Auth');

// FAQ相关路由
Route::get('faq', 'Faq/index')->middleware('Auth');

// 好友相关路由
Route::get('friends', 'Friends/index')->middleware('Auth');

// 积分相关路由
Route::get('points', 'Points/index')->middleware('Auth');
Route::get('api/points/info', 'Points/getUserPoints')->middleware('Auth');
Route::get('api/points/history', 'Points/getPointsRecords')->middleware('Auth');
Route::get('api/points/rules', 'Points/getPointsRules')->middleware('Auth');
Route::post('api/points/exchange', 'Points/exchangePoints')->middleware('Auth');
Route::get('api/exchange/items', 'Points/getExchangeItems')->middleware('Auth');
Route::get('api/exchange/orders', 'Points/getExchangeOrders')->middleware('Auth');
Route::post('api/points/history/clear', 'Points/clearHistory')->middleware('Auth');

// 任务相关路由
Route::get('api/tasks/daily', 'Tasks/getDailyTasks')->middleware('Auth');
Route::get('api/tasks/growth', 'Tasks/getGrowthTasks')->middleware('Auth');
Route::post('api/tasks/complete', 'Tasks/completeTask')->middleware('Auth');

// 用户信息API
Route::get('api/user/info', 'User/getCurrentUserProfile')->middleware('Auth');

// 操作相关路由
Route::get('operation', 'Operation/index')->middleware('Auth');

// 安全相关路由
Route::get('security', 'Security/index')->middleware('Auth');

// 主题相关路由
Route::get('themes', 'Themes/index')->middleware('Auth');

// 评论相关路由
Route::get('api/comments/list', 'Comments/list');
Route::post('api/comments/add', 'Comments/add');
Route::post('api/comments/like', 'Comments/like');
Route::post('api/comments/delete', 'Comments/delete');

// 文章相关 API 路由
Route::get('api/articles/detail', '\app\controller\Article@detail');
Route::get('api/articles/categories', '\app\controller\Article@getCategories');
Route::post('api/fix/articles/table', '\app\controller\Article@fixTable');
Route::get('api/articles', '\app\controller\Article@apiList');
Route::post('api/articles/publish', '\app\controller\Article@publish')->middleware('Auth');
Route::post('api/articles/update', '\app\controller\Article@update');
Route::post('api/articles/delete', '\app\controller\Article@delete')->middleware('Auth');
Route::post('api/articles/like', '\app\controller\Article@like')->middleware('Auth');
Route::post('api/articles/collect', '\app\controller\Article@collect')->middleware('Auth');
Route::get('api/article-comments/list', '\app\controller\Article@comments');
Route::post('api/article-comments/add', '\app\controller\Article@addComment')->middleware('Auth');
Route::post('api/article-comments/like', '\app\controller\Article@likeComment')->middleware('Auth');

// 文章页面路由 - 使用 Index 控制器
Route::get('article/publish', '\app\controller\Index@articlePublish');
Route::get('article/edit/:id', '\app\controller\Index@articleEdit')->pattern(['id' => '\d+']);
Route::get('article/drafts', '\app\controller\Index@articleDrafts');
Route::get('article/:id', '\app\controller\Index@articleDetail')->pattern(['id' => '\d+']);
Route::get('article', '\app\controller\Index@articleList');
Route::get('articles/publish', '\app\controller\Index@articlePublish');
Route::get('articles/edit/:id', '\app\controller\Index@articleEdit')->pattern(['id' => '\d+']);
Route::get('articles/drafts', '\app\controller\Index@articleDrafts');
Route::get('articles/:id', '\app\controller\Index@articleDetail')->pattern(['id' => '\d+']);
Route::get('articles', '\app\controller\Index@articleList');



// 定位相关路由
Route::post('api/location/save', 'Location/save');
Route::get('api/location/nearby', 'Location/nearby');
Route::get('api/location/reverse', 'Location/reverseGeocode');
Route::get('api/location/popular', 'Location/popular');

// 动态API路由
Route::get('api/moments', 'Moments/apiList');
Route::get('api/recommended-users', 'User/getRecommendedUsers');
Route::get('api/hot-topics', 'Moments/getHotTopics');
Route::get('api/activities', 'Activities/index');
Route::get('api/online-users', 'Moments/onlineUsers');
Route::post('api/user/heartbeat', 'User/heartbeat');

// 表情API路由
Route::get('emojis/getEmojiList', 'Emojis/getEmojiList');
Route::post('emojis/uploadEmoji', 'Emojis/uploadEmoji');
Route::post('emojis/deleteEmoji', 'Emojis/deleteEmoji');
Route::post('emojis/recordUsage', 'Emojis/recordUsage');
Route::get('emojis/searchEmojis', 'Emojis/searchEmojis');
