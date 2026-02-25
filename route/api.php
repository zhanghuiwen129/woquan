<?php
// +----------------------------------------------------------------------
// | API路由配置
// +----------------------------------------------------------------------

use think\facade\Route;

// API路由（无版本号）
Route::group('api', function() {
    // 用户相关API
    Route::get('user/check', '\app\controller\User@check');
    Route::get('users/recommended', '\app\controller\User@recommended');
    Route::get('recommended-users', '\app\controller\User@getRecommendedUsers');
    Route::get('online-users', '\app\controller\Moments@onlineUsers');
    Route::post('user/heartbeat', '\app\controller\User@heartbeat');
    Route::post('user/follow', '\app\controller\User@follow')->middleware('Auth');
    Route::get('user/following', '\app\controller\User@getFollowing')->middleware('Auth');

    // 动态相关API
    Route::get('moments/index', '\app\controller\Moments@index');
    Route::post('moments/like', '\app\controller\Moments@like');
    Route::post('moments/unlike', '\app\controller\Moments@unlike');
    Route::post('moments/collect', '\app\controller\Moments@collect');
    Route::post('moments/fix-likes', '\app\controller\Moments@fixLikes');
    Route::post('moments/delete', '\app\controller\Moments@delete');
    Route::post('moments/hide', '\app\controller\Moments@hide');
    Route::post('moments/top', '\app\controller\Moments@top');
    Route::post('moments/report', '\app\controller\Moments@report');
    Route::post('moments/visibility', '\app\controller\Moments@visibility');

    // 活动相关API
    Route::get('activities/index', '\app\controller\Activities@index');
    Route::get('activities/detail', '\app\controller\Activities@detail');
    Route::post('activities/participate', '\app\controller\Activities@participate');
    Route::post('activities/cancel', '\app\controller\Activities@cancelParticipate');

    // 评论相关API
    Route::get('comments/list', '\app\controller\Comments@list');
    Route::post('comments/add', '\app\controller\Comments@add');
    Route::post('comments/like', '\app\controller\Comments@like');
    Route::post('comments/delete', '\app\controller\Comments@delete');
    Route::get('comments/replies', '\app\controller\Comments@replies');
    Route::post('comments/setTop', '\app\controller\Comments@setTop');
    Route::post('comments/setHot', '\app\controller\Comments@setHot');

    // 举报相关API
    Route::post('reports/add', '\app\controller\Reports@add');

    // 消息相关API
    Route::get('messages/unread', '\app\controller\Moments@unread');
    Route::get('messages/getMessageList', '\app\controller\Messages@getMessageList');
    Route::get('messages/getChatHistory', '\app\controller\Messages@getChatHistory');
    Route::get('messages/getNewMessages', '\app\controller\Messages@getNewMessages');
    Route::get('messages/searchMessages', '\app\controller\Messages@searchMessages');
    Route::post('messages/sendMessage', '\app\controller\Messages@sendMessage');
    Route::post('messages/markAsRead', '\app\controller\Messages@markAsRead');
    Route::post('messages/deleteMessage', '\app\controller\Messages@deleteMessage');
    Route::post('messages/recallMessage', '\app\controller\Messages@recallMessage');
    Route::post('messages/forwardMessage', '\app\controller\Messages@forwardMessage');
    Route::post('messages/pinMessage', '\app\controller\Messages@pinMessage');
    Route::post('messages/favoriteMessage', '\app\controller\Messages@favoriteMessage');
    Route::get('messages/getFavorites', '\app\controller\Messages@getFavorites');
    Route::post('messages/blockUser', '\app\controller\Messages@blockUser');
    Route::post('messages/unblockUser', '\app\controller\Messages@unblockUser');
    Route::post('messages/setMute', '\app\controller\Messages@setMute');

    // 草稿相关API
    Route::get('drafts/list', '\app\controller\Moments@getDrafts');
    Route::post('drafts/delete', '\app\controller\Moments@deleteDraft');
    Route::post('drafts/clear-expired', '\app\controller\Moments@clearExpiredDrafts');

    // 积分相关API
    Route::get('points/info', '\app\controller\Points@getUserPoints');
    Route::get('points/history', '\app\controller\Points@getPointsRecords');
    Route::post('points/history/clear', '\app\controller\Points@clearHistory');

    // 任务相关API
    Route::get('tasks/daily', '\app\controller\Tasks@getDailyTasks');
    Route::get('tasks/growth', '\app\controller\Tasks@getGrowthTasks');
    Route::post('tasks/complete', '\app\controller\Tasks@completeTask');

    // 兑换相关API
    Route::get('exchange/items', '\app\controller\Points@getExchangeItems');
    Route::post('exchange/do', '\app\controller\Points@exchangePoints');
});

// API版本控制
Route::group('api/v1', function() {
    // 用户相关API
    Route::group('user', function() {
        Route::post('login', '\app\controller\Api@login');
        Route::post('register', '\app\controller\Api@register');
        Route::get('info', '\app\controller\Api@userInfo');
        Route::get('check', '\app\controller\User@check');
        Route::post('update', '\app\controller\Api@updateUser');
        Route::post('password', '\app\controller\Api@changePassword');
        Route::get('moments/:id', '\app\controller\Api@userMoments');
        Route::get('following/:id', '\app\controller\Api@userFollowing');
        Route::get('followers/:id', '\app\controller\Api@userFollowers');
        Route::post('follow', '\app\controller\User@follow');
        Route::post('follow/:id', '\app\controller\Api@followUser');
        Route::post('unfollow/:id', '\app\controller\Api@unfollowUser');
        Route::get('recommended', '\app\controller\User@recommended');
    });

    // 动态相关API
    Route::group('moments', function() {
        Route::get('', '\app\controller\Api@index');
        Route::get('index', '\app\controller\Moments@index');
        Route::get('home', '\app\controller\Api@home');
        Route::post('create', '\app\controller\Api@createMoment');
        Route::get(':id', '\app\controller\Api@momentDetail');
        Route::post('update/:id', '\app\controller\Api@updateMoment');
        Route::post('delete/:id', '\app\controller\Api@deleteMoment');
        Route::post('like', '\app\controller\Moments@like');
        Route::post('like/:id', '\app\controller\Api@likeMoment');
        Route::post('unlike/:id', '\app\controller\Api@unlikeMoment');
        Route::get('comments/:id', '\app\controller\Api@momentComments');
        Route::get('list', '\app\controller\Comments@list');
        Route::post('comment', '\app\controller\Comments@add');
        Route::post('comment/:id', '\app\controller\Api@commentMoment');
        Route::post('share/:id', '\app\controller\Api@shareMoment');
        Route::post('collect', '\app\controller\Moments@collect');
    });

    // 文章相关API
    Route::group('essay', function() {
        Route::get('', '\app\controller\Api@essayList');
        Route::get(':id', '\app\controller\Api@essayDetail');
        Route::post('like/:id', '\app\controller\Api@likeEssay');
        Route::post('unlike/:id', '\app\controller\Api@unlikeEssay');
        Route::get('comments/:id', '\app\controller\Api@essayComments');
        Route::post('comment/:id', '\app\controller\Api@commentEssay');
    });

    // 话题相关API
    Route::group('topic', function() {
        Route::get('', '\app\controller\Api@topicList');
        Route::get(':id', '\app\controller\Api@topicDetail');
        Route::get(':id/moments', '\app\controller\Api@topicMoments');
        Route::post('follow/:id', '\app\controller\Api@followTopic');
        Route::post('unfollow/:id', '\app\controller\Api@unfollowTopic');
        Route::get('search', '\app\controller\Moments@searchTopics');
    });

    // 消息相关API
    Route::group('message', function() {
        Route::get('', '\app\controller\Api@messageList');
        Route::get(':type', '\app\controller\Api@messageListByType');
        Route::get('detail/:id', '\app\controller\Api@messageDetail');
        Route::post('read/:id', '\app\controller\Api@readMessage');
        Route::post('readAll', '\app\controller\Api@readAllMessages');
        Route::post('delete/:id', '\app\controller\Api@deleteMessage');
    });

    // 消息相关API（复数形式）
    Route::group('messages', function() {
        Route::get('unread', '\app\controller\Moments@unread');
        Route::get('unreadCount', '\app\controller\Messages@getUnreadCount');
        Route::get('getMessageList', '\app\controller\Messages@getMessageList');
        Route::get('getChatHistory', '\app\controller\Messages@getChatHistory');
        Route::get('getNewMessages', '\app\controller\Messages@getNewMessages');
        Route::get('searchMessages', '\app\controller\Messages@searchMessages');
        Route::post('sendMessage', '\app\controller\Messages@sendMessage');
        Route::post('markAsRead', '\app\controller\Messages@markAsRead');
        Route::post('deleteMessage', '\app\controller\Messages@deleteMessage');
        Route::post('recallMessage', '\app\controller\Messages@recallMessage');
        Route::post('forwardMessage', '\app\controller\Messages@forwardMessage');
        Route::post('pinMessage', '\app\controller\Messages@pinMessage');
        Route::post('favoriteMessage', '\app\controller\Messages@favoriteMessage');
        Route::get('getFavorites', '\app\controller\Messages@getFavorites');
        Route::post('blockUser', '\app\controller\Messages@blockUser');
        Route::post('unblockUser', '\app\controller\Messages@unblockUser');
        Route::post('setMute', '\app\controller\Messages@setMute');
        Route::get('getChatSettings', '\app\controller\Messages@getChatSettings');
        Route::get('getLinkPreview', '\app\controller\Messages@getLinkPreview');
        Route::get('favorites', '\app\controller\Messages@favorites');
    });

    // 搜索相关API
    Route::group('search', function() {
        Route::get('user', '\app\controller\Api@searchUser');
        Route::get('moment', '\app\controller\Api@searchMoment');
        Route::get('topic', '\app\controller\Api@searchTopic');
        Route::get('essay', '\app\controller\Api@searchEssay');
    });

    // 通知相关API
    Route::group('notification', function() {
        Route::get('', '\app\controller\Api@notificationList');
        Route::get('unread', '\app\controller\Api@unreadNotification');
        Route::post('read/:id', '\app\controller\Api@readNotification');
        Route::post('readAll', '\app\controller\Api@readAllNotifications');
        Route::post('delete/:id', '\app\controller\Api@deleteNotification');
    });

    // 好友相关API
    Route::group('friend', function() {
        Route::get('', '\app\controller\Api@friendList');
        Route::post('add/:id', '\app\controller\Api@addFriend');
        Route::post('accept/:id', '\app\controller\Api@acceptFriend');
        Route::post('reject/:id', '\app\controller\Api@rejectFriend');
        Route::post('delete/:id', '\app\controller\Api@deleteFriend');
        Route::post('remark/:id', '\app\controller\Api@remarkFriend');
    });

    // 积分相关API
    Route::group('point', function() {
        Route::get('', '\app\controller\Api@pointInfo');
        Route::get('log', '\app\controller\Api@pointLog');
    });

    // 等级相关API
    Route::group('level', function() {
        Route::get('', '\app\controller\Api@levelInfo');
        Route::get('list', '\app\controller\Api@levelList');
    });

    // 设置相关API
    Route::group('setting', function() {
        Route::get('', '\app\controller\Api@settingInfo');
        Route::post('update', '\app\controller@Api@updateSetting');
        Route::post('privacy', '\app\controller\Api@updatePrivacy');
    });

    // 统计相关API
    Route::group('statistic', function() {
        Route::get('core', '\app\controller\Api@coreStatistics');
        Route::get('content', '\app\controller\Api@contentStatistics');
        Route::get('user', '\app\controller\Api@userStatistics');
    });

    // 上传相关API
    Route::group('upload', function() {
        Route::post('chat', '\app\controller\Upload@chat');
        Route::post('chatFile', '\app\controller\Upload@chatFile');
        Route::post('voice', '\app\controller\Upload@uploadAudio');
        Route::post('image', '\app\controller\Upload@image');
        Route::post('moment', '\app\controller\Upload@moment');
        Route::post('avatar', '\app\controller\Upload@avatar');
    });

    // 快捷回复相关API
    Route::group('quickReplies', function() {
        Route::get('list', '\app\controller\QuickReplies@list');
        Route::post('create', '\app\controller\QuickReplies@create');
        Route::post('update', '\app\controller\QuickReplies@update');
        Route::post('delete', '\app\controller\QuickReplies@delete');
        Route::post('use', '\app\controller\QuickReplies@use');
        Route::get('detail', '\app\controller\QuickReplies@detail');
        Route::post('batchDelete', '\app\controller\QuickReplies@batchDelete');
        Route::get('stats', '\app\controller\QuickReplies@stats');
    });
})->middleware('Auth');

// 后台管理相关API
Route::group('admin', function() {
    // 用户标签相关API（后台管理）
    Route::post('addUserTag', '\app\controller\admin\User@addUserTag');
    Route::post('deleteUserTag', '\app\controller\admin\User@deleteUserTag');
    Route::post('batchDeleteUserTags', '\app\controller\admin\User@batchDeleteUserTags');
    Route::post('editUserTag', '\app\controller\admin\User@editUserTag');

    // 用户分组相关API（后台管理）
    Route::post('addUserGroup', '\app\controller\admin\User@addUserGroup');
    Route::post('deleteUserGroup', '\app\controller\admin\User@deleteUserGroup');
    Route::post('batchDeleteUserGroups', '\app\controller\admin\User@batchDeleteUserGroups');
});
