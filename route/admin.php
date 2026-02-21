<?php
// +----------------------------------------------------------------------
// | 后台路由配置
// +----------------------------------------------------------------------

use think\facade\Route;

// 应用授权验证中间件到所有后台路由
Route::group(function () {

// 后台评论管理路由 - 必须放在最前面，避免被其他路由覆盖
Route::get('admin/comment/statistics', '\app\controller\admin\Comment@statistics');
Route::get('admin/comment/detail/:id', '\app\controller\admin\Comment@detail');
Route::get('admin/comment', '\app\controller\admin\Comment@index');
Route::post('admin/comment/delete/:id', '\app\controller\admin\Comment@delete');
Route::post('admin/comment/batchDelete', 'admin/Comment/batchDelete');
Route::post('admin/comment/batchAudit', 'admin/Comment/batchAudit');
Route::post('admin/comment/batchTop', 'admin/Comment/batchTop');
Route::post('admin/comment/batchCancelTop', 'admin/Comment/batchCancelTop');
Route::post('admin/comment/updateStatus/:id', 'admin/Comment/updateStatus');
Route::post('admin/comment/toggleTop', 'admin/Comment/toggleTop');

// 后台登录相关路由
Route::get('admin/login', '\app\controller\admin\Login@index');
Route::post('admin/login', '\app\controller\admin\Login@login');
Route::get('admin/logout', '\app\controller\admin\Login@logout');
Route::get('admin/captcha', '\app\controller\admin\Login@captcha');
Route::post('admin/changePassword', '\app\controller\admin\Login@changePassword');

// 后台首页路由 - 注意：这个路由会匹配所有以 admin 开头的URL
// 必须在具体路由之后定义，否则会覆盖其他路由
Route::get('admin/index', '\app\controller\admin\Index@index');
Route::get('admin/welcome', '\app\controller\admin\Index@welcome');

// 后台用户管理路由 - 使用完整命名空间路径
Route::rule('admin/user$', '\app\controller\admin\User@index', 'GET');
Route::rule('admin/user/list', '\app\controller\admin\User@index', 'GET');
Route::rule('admin/user/detail', '\app\controller\admin\User@detail', 'GET');
Route::rule('admin/user/edit', '\app\controller\admin\User@edit', 'GET');
Route::rule('admin/user/update', '\app\controller\admin\User@update', 'POST');
Route::rule('admin/user/delete', '\app\controller\admin\User@delete', 'POST');
Route::rule('admin/user/changePassword', '\app\controller\admin\User@changePassword', 'POST');
Route::rule('admin/user/batchDelete', '\app\controller\admin\User@batchDelete', 'POST');
Route::rule('admin/user/batchUpdateStatus', '\app\controller\admin\User@batchUpdateStatus', 'POST');
Route::rule('admin/user/batchUpdateSpeakStatus', '\app\controller\admin\User@batchUpdateSpeakStatus', 'POST');
Route::rule('admin/user/batchSendNotification', '\app\controller\admin\User@batchSendNotification', 'POST');
Route::rule('admin/user/updateStatus', '\app\controller\admin\User@updateStatus', 'POST');
Route::rule('admin/user/toggleSpeakStatus', '\app\controller\admin\User@toggleSpeakStatus', 'POST');
Route::rule('admin/user/loginLogs', '\app\controller\admin\User@loginLogs', 'GET');
Route::rule('admin/user/login-logs', '\app\controller\admin\User@loginLogs', 'GET');
Route::rule('admin/user/groups', '\app\controller\admin\User@groups', 'GET');
Route::rule('admin/user/tags', '\app\controller\admin\User@tags', 'GET');
Route::rule('admin/user/assignGroup', '\app\controller\admin\User@assignGroup', 'POST');
Route::rule('admin/user/assignTag', '\app\controller\admin\User@assignTag', 'POST');
Route::rule('admin/user/removeGroup', '\app\controller\admin\User@removeGroup', 'POST');
Route::rule('admin/user/removeTag', '\app\controller\admin\User@removeTag', 'POST');
Route::rule('admin/user/statistics', '\app\controller\admin\User@statistics', 'GET');
Route::rule('admin/user/loginLogsData', '\app\controller\admin\User@loginLogsData', 'GET');
Route::rule('admin/user/addViolation', '\app\controller\admin\User@addViolation', 'POST');
Route::rule('admin/user/addPunishment', '\app\controller\admin\User@addPunishment', 'POST');
Route::rule('admin/user/getViolations', '\app\controller\admin\User@getViolations', 'GET');
Route::rule('admin/user/getPunishments', '\app\controller\admin\User@getPunishments', 'GET');
Route::rule('admin/user/removePunishment', '\app\controller\admin\User@removePunishment', 'POST');

// 后台用户标签管理 API 路由
Route::rule('api/addUserTag', '\app\controller\admin\User@addUserTag', 'POST');
Route::rule('api/deleteUserTag', '\app\controller\admin\User@deleteUserTag', 'POST');
Route::rule('api/batchDeleteUserTags', '\app\controller\admin\User@batchDeleteUserTags', 'POST');
Route::rule('api/editUserTag', '\app\controller\admin\User@editUserTag', 'POST');

// 后台用户分组管理 API 路由
Route::rule('api/addUserGroup', '\app\controller\admin\User@addGroup', 'POST');
Route::rule('api/deleteUserGroup', '\app\controller\admin\User@deleteGroup', 'POST');
Route::rule('api/batchDeleteUserGroups', '\app\controller\admin\User@batchDeleteGroups', 'POST');
Route::rule('api/editUserGroup', '\app\controller\admin\User@editGroup', 'POST');

// 后台内容管理路由
Route::rule('admin/content$', '\app\controller\admin\Content@moments', 'GET');
Route::rule('admin/content/moments', '\app\controller\admin\Content@moments', 'GET');

// 后台文章管理路由
Route::rule('admin/articles$', '\app\controller\admin\Article@index', 'GET');
Route::rule('admin/article$', '\app\controller\admin\Article@index', 'GET');
Route::rule('admin/article/detail', '\app\controller\admin\Article@detail', 'GET');
Route::rule('admin/article/list', '\app\controller\admin\Article@list', 'GET');
Route::rule('admin/article/save', '\app\controller\admin\Article@save', 'POST');
Route::rule('admin/article/delete', '\app\controller\admin\Article@delete', 'POST');
Route::rule('admin/article/batchDelete', '\app\controller\admin\Article@batchDelete', 'POST');
Route::rule('admin/article/batchPublish', '\app\controller\admin\Article@batchPublish', 'POST');
Route::rule('admin/article/changeStatus', '\app\controller\admin\Article@changeStatus', 'POST');
Route::rule('admin/article/changeTop', '\app\controller\admin\Article@changeTop', 'POST');
Route::rule('admin/article/changeRecommend', '\app\controller\admin\Article@changeRecommend', 'POST');
Route::rule('admin/article/categories', '\app\controller\admin\Article@categories', 'GET');
Route::rule('admin/article/categoryList', '\app\controller\admin\Article@categoryList', 'GET');
Route::rule('admin/article/saveCategory', '\app\controller\admin\Article@saveCategory', 'POST');
Route::rule('admin/article/deleteCategory', '\app\controller\admin\Article@deleteCategory', 'POST');

Route::rule('admin/content/comments', '\app\controller\admin\Comment@index', 'GET');
Route::get('admin/content/commentStatistics', '\app\controller\admin\Content@commentStatistics');
Route::rule('admin/content/deleteMoment', '\app\controller\admin\Content@deleteMoment', 'POST');
Route::rule('admin/content/deleteMoment', '\app\controller\admin\Content@deleteMoment', 'POST');
Route::rule('admin/content/auditMoment', '\app\controller\admin\Content@auditMoment', 'POST');
Route::rule('admin/content/reports', '\app\controller\admin\Content@reports', 'GET');
Route::rule('admin/content/reportsData', '\app\controller\admin\Content@reportsData', 'GET');
Route::rule('admin/content/handleReport', '\app\controller\admin\Content@handleReport', 'POST');
Route::rule('admin/content/statistics', '\app\controller\admin\Content@statistics', 'GET');
Route::rule('admin/content/topics', '\app\controller\admin\Content@topics', 'GET');
Route::rule('admin/content/topicForm', '\app\controller\admin\Content@topicForm', 'GET');
Route::rule('admin/content/saveTopic', '\app\controller\admin\Content@saveTopic', 'POST');
Route::rule('admin/content/deleteTopic', '\app\controller\admin\Content@deleteTopic', 'POST');
Route::rule('admin/content/toggleHot', '\app\controller\admin\Content@toggleHot', 'POST');
Route::rule('admin/content/toggleStatus', '\app\controller\admin\Content@toggleStatus', 'POST');
Route::rule('admin/upload/image', '\app\controller\admin\Upload@image', 'POST');
Route::rule('admin/content/auditComment', '\app\controller\admin\Content@auditComment', 'POST');
Route::rule('admin/content/deleteComment', '\app\controller\admin\Content@deleteComment', 'POST');
Route::rule('admin/content/batchDeleteMoments', '\app\controller\admin\Content@batchDeleteMoments', 'POST');
Route::rule('admin/content/batchAuditMoments', '\app\controller\admin\Content@batchAuditMoments', 'POST');
Route::rule('admin/content/batchDeleteComments', '\app\controller\admin\Content@batchDeleteComments', 'POST');
Route::rule('admin/content/batchAuditComments', '\app\controller\admin\Content@batchAuditComments', 'POST');
Route::rule('admin/content/batchDeleteTopics', '\app\controller\admin\Content@batchDeleteTopics', 'POST');
Route::rule('admin/content/toggleTop', '\app\controller\admin\Content@toggleTop', 'POST');
Route::rule('admin/content/batchTopMoments', '\app\controller\admin\Content@batchTopMoments', 'POST');
Route::rule('admin/content/batchCancelTopMoments', '\app\controller\admin\Content@batchCancelTopMoments', 'POST');

// 后台数据分析路由
Route::get('admin/dataanalysis', 'admin/DataAnalysis/index');
Route::get('admin/dataanalysis/content', 'admin/DataAnalysis/contentStatistics');
Route::get('admin/dataanalysis/users', 'admin/DataAnalysis/userStatistics');

// 后台系统设置路由 - 具体路由放在前面
Route::get('admin/setting/basic', '\app\controller\admin\Setting@basic');
Route::get('admin/setting/website', '\app\controller\admin\Setting@website');
Route::get('admin/setting/register', '\app\controller\admin\Setting@register');
Route::get('admin/setting/email', '\app\controller\admin\Setting@email');
Route::get('admin/setting/upload', '\app\controller\admin\Setting@upload');
Route::get('admin/setting/seo', '\app\controller\admin\Setting@website');
Route::get('admin/setting/notification', '\app\controller\admin\Setting@notification');
Route::get('admin/setting/publish', '\app\controller\admin\Setting@publish');
Route::get('admin/setting/security', '\app\controller\admin\Setting@security');
Route::get('admin/setting/social', '\app\controller\admin\Setting@social');
Route::get('admin/setting/tools', '\app\controller\admin\Setting@tools');
Route::get('admin/setting/resource', '\app\controller\admin\Setting@resource');
Route::get('admin/setting/operation', '\app\controller\admin\Setting@operation');
Route::get('admin/setting', '\app\controller\admin\Setting@index');
Route::post('admin/setting/save', '\app\controller\admin\Setting@save');
Route::post('admin/setting/saveWebsite', '\app\controller\admin\Setting@saveWebsite');
Route::post('admin/setting/savePublish', '\app\controller\admin\Setting@savePublish');
Route::post('admin/setting/saveUpload', '\app\controller\admin\Setting@saveUpload');
Route::post('admin/setting/saveEmail', '\app\controller\admin\Setting@saveEmail');
Route::get('admin/setting/testEmail', '\app\controller\admin\Setting@testEmail');
Route::post('admin/setting/saveSeo', '\app\controller\admin\Setting@saveSeo');
Route::post('admin/setting/saveSocial', '\app\controller\admin\Setting@saveSocial');
Route::post('admin/setting/saveSite', '\app\controller\admin\Setting@saveSite');
Route::post('admin/setting/saveBasic', '\app\controller\admin\Setting@saveBasic');
Route::post('admin/setting/saveSecurity', '\app\controller\admin\Setting@saveSecurity');
Route::post('admin/setting/saveNotification', '\app\controller\admin\Setting@saveNotification');
Route::post('admin/setting/saveOperation', '\app\controller\admin\Setting@saveOperation');
Route::post('admin/setting/saveRegister', '\app\controller\admin\Setting@saveRegister');
Route::post('admin/setting/backupDatabase', '\app\controller\admin\Setting@backupDatabase');
Route::post('admin/setting/optimizeDatabase', '\app\controller\admin\Setting@optimizeDatabase');
Route::post('admin/setting/repairDatabase', '\app\controller\admin\Setting@repairDatabase');
Route::get('admin/setting/viewErrorLogs', '\app\controller\admin\Setting@viewErrorLogs');
Route::post('admin/setting/clearErrorLogs', '\app\controller\admin\Setting@clearErrorLogs');
Route::get('admin/setting/viewOperationLogs', '\app\controller\admin\Setting@viewOperationLogs');
Route::post('admin/setting/clearOperationLogs', '\app\controller\admin\Setting@clearOperationLogs');
Route::post('admin/setting/clearCache', '\app\controller\admin\Setting@clearCache');
Route::post('admin/setting/clearTempFiles', '\app\controller\admin\Setting@clearTempFiles');
Route::post('admin/setting/saveResource', '\app\controller\admin\Setting@saveResource');
Route::post('admin/setting/password', '\app\controller\admin\Setting@password');
Route::get('admin/setting/userGroups', '\app\controller\admin\Setting@userGroups');
Route::post('admin/setting/saveUserGroup', '\app\controller\admin\Setting@saveUserGroup');
Route::post('admin/setting/deleteUserGroup', '\app\controller\admin\Setting@deleteUserGroup');
Route::get('admin/setting/sensitiveWords', '\app\controller\admin\Setting@sensitiveWords');
Route::post('admin/setting/saveSensitiveWords', '\app\controller\admin\Setting@saveSensitiveWords');
Route::get('admin/setting/errorPages', '\app\controller\admin\Setting@errorPages');
Route::post('admin/setting/saveErrorPages', '\app\controller\admin\Setting@saveErrorPages');

// 后台管理员管理路由
Route::get('admin/admin', 'admin/AdminManager/index');
Route::get('admin/admin/create', 'admin/AdminManager/create');
Route::post('admin/admin/store', 'admin/AdminManager/store');
Route::get('admin/admin/edit/:id', 'admin/AdminManager/edit');
Route::post('admin/admin/update/:id', 'admin/AdminManager/update');
Route::post('admin/admin/delete/:id', 'admin/AdminManager/delete');

// 后台角色管理路由
Route::get('admin/role', 'admin/RoleController/index');
Route::get('admin/role/create', 'admin/RoleController/create');
Route::post('admin/role/store', 'admin/RoleController/store');
Route::get('admin/role/edit/:id', 'admin/RoleController/edit');
Route::post('admin/role/update', 'admin/RoleController/update');
Route::post('admin/role/delete', 'admin/RoleController/delete');
Route::get('admin/role/permission/:id', 'admin/RoleController/permission');
Route::post('admin/role/permission', 'admin/RoleController/permission');

// 后台公告管理路由 - 子路由必须在父路由之前定义
Route::rule('admin/announcement/add', '\app\controller\admin\Announcement@add', 'GET');
Route::rule('admin/announcement/save', '\app\controller\admin\Announcement@save', 'POST');
Route::rule('admin/announcement/edit/:id', '\app\controller\admin\Announcement@edit', 'GET');
Route::rule('admin/announcement/update', '\app\controller\admin\Announcement@update', 'POST');
Route::rule('admin/announcement/update/:id', '\app\controller\admin\Announcement@update', 'POST');
Route::rule('admin/announcement/delete', '\app\controller\admin\Announcement@delete', 'POST');
Route::rule('admin/announcement/delete/:id', '\app\controller\admin\Announcement@delete', 'POST');
Route::rule('admin/announcement/toggleStatus', '\app\controller\admin\Announcement@toggleStatus', 'POST');
Route::rule('admin/announcement/togglePublish', '\app\controller\admin\Announcement@togglePublish', 'POST');
Route::rule('admin/announcement/togglePopup', '\app\controller\admin\Announcement@togglePopup', 'POST');
Route::get('admin/announcement', '\app\controller\admin\Announcement@index');

// 后台授权管理路由
Route::rule('admin/authorization/add', '\app\controller\admin\Authorization@add', 'GET');
Route::rule('admin/authorization/detail/:id', '\app\controller\admin\Authorization@detail', 'GET');
Route::rule('admin/authorization/save', '\app\controller\admin\Authorization@save', 'POST');
Route::rule('admin/authorization/edit/:id', '\app\controller\admin\Authorization@edit', 'GET');
Route::rule('admin/authorization/update/:id', '\app\controller\admin\Authorization@update', 'POST');
Route::rule('admin/authorization/delete/:id', '\app\controller\admin\Authorization@delete', 'POST');
Route::rule('admin/authorization/permissions', '\app\controller\admin\Authorization@permissions', 'GET');
Route::rule('admin/authorization/roles', '\app\controller\admin\Authorization@roles', 'GET');
Route::rule('admin/authorization/batch', '\app\controller\admin\Authorization@batch', 'GET');
Route::rule('admin/authorization/batchGenerate', '\app\controller\admin\Authorization@batchGenerate', 'POST');
Route::rule('admin/authorization/generateCode', '\app\controller\admin\Authorization@generateCode', 'GET');
Route::rule('admin/authorization/statistics', '\app\controller\admin\Authorization@statistics', 'GET');
Route::rule('admin/authorization/export', '\app\controller\admin\Authorization@export', 'GET');
Route::rule('admin/authorization/search', '\app\controller\admin\Authorization@search', 'GET');
Route::rule('admin/authorization$', '\app\controller\admin\Authorization@index', 'GET');

// 后台货币管理路由
Route::rule('admin/currency$', '\app\controller\admin\Currency@index', 'GET');
Route::get('admin/currency/add_type', '\app\controller\admin\Currency@addType');
Route::get('admin/currency/addType', '\app\controller\admin\Currency@addType');
Route::post('admin/currency/save_type', '\app\controller\admin\Currency@saveType');
Route::post('admin/currency/saveType', '\app\controller\admin\Currency@saveType');
Route::get('admin/currency/edit_type/:id', '\app\controller\admin\Currency@editType');
Route::get('admin/currency/editType/:id', '\app\controller\admin\Currency@editType');
Route::get('admin/currency/edit_type', '\app\controller\admin\Currency@editType');
Route::get('admin/currency/editType', '\app\controller\admin\Currency@editType');
Route::post('admin/currency/update_type/:id', '\app\controller\admin\Currency@updateType');
Route::post('admin/currency/updateType/:id', '\app\controller\admin\Currency@updateType');
Route::post('admin/currency/update_type', '\app\controller\admin\Currency@updateType');
Route::post('admin/currency/updateType', '\app\controller\admin\Currency@updateType');
Route::post('admin/currency/delete_type/:id', '\app\controller\admin\Currency@deleteType');
Route::post('admin/currency/deleteType/:id', '\app\controller\admin\Currency@deleteType');
Route::get('admin/currency/log_list', '\app\controller\admin\Currency@logList');
Route::get('admin/currency/logList', '\app\controller\admin\Currency@logList');
Route::get('admin/currency/user_currency_list', '\app\controller\admin\Currency@userCurrencyList');
Route::get('admin/currency/userCurrencyList', '\app\controller\admin\Currency@userCurrencyList');

// 后台服务器管理路由
Route::get('admin/server', 'admin/Server/index');
Route::get('admin/server/detail/:id', 'admin/Server/detail');
Route::post('admin/server/save', 'admin/Server/save');
Route::post('admin/server/delete/:id', 'admin/Server/delete');

// 后台软件管理路由
Route::rule('admin/software$', '\app\controller\admin\Software@index', 'GET');
Route::rule('admin/software/add', '\app\controller\admin\Software@add', 'GET');
Route::rule('admin/software/edit/:id', '\app\controller\admin\Software@edit', 'GET');
Route::post('admin/software/save', 'admin/Software/save');
Route::post('admin/software/delete/:id', 'admin/Software/delete');

// 后台话题管理路由
Route::rule('admin/topic$', '\app\controller\admin\Topic@index', 'GET');
Route::get('admin/topic/add', 'admin/Topic/add');
Route::post('admin/topic/save', 'admin/Topic/save');
Route::get('admin/topic/edit/:id', 'admin/Topic/edit');
Route::post('admin/topic/update/:id', 'admin/Topic/update');
Route::post('admin/topic/delete/:id', 'admin/Topic/delete');

// 后台分类管理路由
Route::get('admin/category/add', '\app\controller\admin\Category@add');
Route::post('admin/category/save', '\app\controller\admin\Category@save');
Route::get('admin/category/edit/:id', '\app\controller\admin\Category@edit');
Route::post('admin/category/update/:id', '\app\controller\admin\Category@update');
Route::post('admin/category/delete/:id', '\app\controller\admin\Category@delete');
Route::post('admin/category/toggleStatus', '\app\controller\admin\Category@toggleStatus');
Route::post('admin/category/batchDelete', '\app\controller\admin\Category@batchDelete');
Route::rule('admin/category$', '\app\controller\admin\Category@index', 'GET');

// 后台聊天管理路由
Route::get('admin/chat', 'admin/Chat/index');
Route::get('admin/chat/messages', 'admin/Chat/messages');
Route::get('admin/chat/sensitive', 'admin/Chat/sensitive');
Route::post('admin/chat/deleteChat', 'admin/Chat/deleteChat');
Route::post('admin/chat/deleteMessage', 'admin/Chat/deleteMessage');
Route::post('admin/chat/ignoreSensitive', 'admin/Chat/ignoreSensitive');

// 后台通话记录路由
Route::rule('admin/call$', '\app\controller\admin\Call@index', 'GET');
Route::get('admin/call/list', '\app\controller\admin\Call@list');
Route::post('admin/call/delete', '\app\controller\admin\Call@delete');
Route::get('admin/call/statistics', '\app\controller\admin\Call@statistics');

// 后台活动管理路由
Route::rule('admin/activity$', '\app\controller\admin\Activity@index', 'GET');
Route::rule('admin/activity/add', '\app\controller\admin\Activity@add', 'GET');
Route::post('admin/activity/save', '\app\controller\admin\Activity@save');
Route::rule('admin/activity/edit/:id', '\app\controller\admin\Activity@edit', 'GET');
Route::post('admin/activity/update/:id', '\app\controller\admin\Activity@update');
Route::post('admin/activity/delete/:id', '\app\controller\admin\Activity@delete');
Route::rule('admin/activity/participants', '\app\controller\admin\Activity@participants', 'GET');
Route::post('admin/activity/deleteParticipant', '\app\controller\admin\Activity@deleteParticipant');
Route::rule('admin/activity/statistics', '\app\controller\admin\Activity@statistics', 'GET');

// 后台版本管理路由
Route::rule('admin/version$', '\app\controller\admin\Version@index', 'GET');
Route::get('admin/version/add', '\app\controller\admin\Version@add');
Route::get('admin/version/edit/:id', '\app\controller\admin\Version@edit');
Route::post('admin/version/save', '\app\controller\admin\Version@save');
Route::post('admin/version/update', '\app\controller\admin\Version@update');
Route::post('admin/version/delete/:id', '\app\controller\admin\Version@delete');

// 后台VIP管理路由
Route::get('admin/vip/add_level', '\app\controller\admin\Vip@addLevel');
Route::post('admin/vip/save_level', '\app\controller\admin\Vip@saveLevel');
Route::get('admin/vip/edit_level/:id', '\app\controller\admin\Vip@editLevel');
Route::post('admin/vip/update_level/:id', '\app\controller\admin\Vip@updateLevel');
Route::post('admin/vip/delete_level/:id', '\app\controller\admin\Vip@deleteLevel');
Route::get('admin/vip/order_list', '\app\controller\admin\Vip@orderList');
Route::get('admin/vip/user_vip_list', '\app\controller\admin\Vip@userVipList');
Route::get('admin/vip', '\app\controller\admin\Vip@index');

// 后台通知管理路由
Route::get('admin/notification', 'admin/Notification/index');

// 后台风险控制路由
Route::get('admin/riskControl', 'admin/RiskControl/index');

// 后台操作日志路由
Route::get('admin/operation', 'admin/Operation/index');

// 后台前端配置路由
Route::get('admin/frontendConfig', 'admin/FrontendConfig/index');

// 后台系统工具路由
Route::get('admin/systemTools', 'admin/SystemTools/index');

// 新的模块化设置路由
Route::get('admin/websiteSetting', '\app\controller\admin\WebsiteSetting@index');
Route::post('admin/websiteSetting/save', '\app\controller\admin\WebsiteSetting@save');

Route::get('admin/contentSetting', '\app\controller\admin\ContentSetting@index');
Route::post('admin/contentSetting/save', '\app\controller\admin\ContentSetting@save');

Route::get('admin/uploadSetting', '\app\controller\admin\UploadSetting@index');
Route::post('admin/uploadSetting/save', '\app\controller\admin\UploadSetting@save');

Route::get('admin/emailSetting', '\app\controller\admin\EmailSetting@index');
Route::post('admin/emailSetting/save', '\app\controller\admin\EmailSetting@save');
Route::get('admin/emailSetting/test', '\app\controller\admin\EmailSetting@test');

Route::get('admin/seoSetting', '\app\controller\admin\SeoSetting@index');
Route::post('admin/seoSetting/save', '\app\controller\admin\SeoSetting@save');

Route::post('admin/systemTool/backupDatabase', 'admin/SystemTool/backupDatabase');
Route::post('admin/systemTool/optimizeDatabase', 'admin/SystemTool/optimizeDatabase');
Route::post('admin/systemTool/repairDatabase', 'admin/SystemTool/repairDatabase');
Route::get('admin/systemTool/viewErrorLogs', 'admin/SystemTool/viewErrorLogs');
Route::post('admin/systemTool/clearErrorLogs', 'admin/SystemTool/clearErrorLogs');
Route::post('admin/systemTool/clearCache', 'admin/SystemTool/clearCache');
Route::post('admin/systemTool/clearTempFiles', 'admin/SystemTool/clearTempFiles');

// 后台主题管理路由
Route::get('admin/theme', 'admin/Theme/index');

// 后台用户扩展管理路由
Route::get('admin/userExtend', 'admin/UserExtend/index');

// 后台账户安全路由
Route::get('admin/accountSecurity', 'admin/AccountSecurity/index');

// 后台社交管理路由
Route::rule('admin/friend$', '\app\controller\admin\Friend@index', 'GET');
Route::post('admin/friend/delete', '\app\controller\admin\Friend@delete');
Route::post('admin/friend/batchDelete', '\app\controller\admin\Friend@batchDelete');
Route::post('admin/friend/updateStatus', '\app\controller\admin\Friend@updateStatus');

Route::rule('admin/follow$', '\app\controller\admin\Follow@index', 'GET');
Route::post('admin/follow/delete', '\app\controller\admin\Follow@delete');
Route::post('admin/follow/batchDelete', '\app\controller\admin\Follow@batchDelete');
Route::post('admin/follow/updateStatus', '\app\controller\admin\Follow@updateStatus');

Route::rule('admin/blacklist$', '\app\controller\admin\Blacklist@index', 'GET');
Route::post('admin/blacklist/delete', '\app\controller\admin\Blacklist@delete');
Route::post('admin/blacklist/batchDelete', '\app\controller\admin\Blacklist@batchDelete');
Route::post('admin/blacklist/unblock', '\app\controller\admin\Blacklist@unblock');

Route::rule('admin/like$', '\app\controller\admin\Like@index', 'GET');
Route::post('admin/like/delete', '\app\controller\admin\Like@delete');
Route::post('admin/like/batchDelete', '\app\controller\admin\Like@batchDelete');
Route::get('admin/like/statistics', '\app\controller\admin\Like@statistics');

Route::rule('admin/visitor$', '\app\controller\admin\Visitor@index', 'GET');
Route::post('admin/visitor/delete', '\app\controller\admin\Visitor@delete');
Route::post('admin/visitor/batchDelete', '\app\controller\admin\Visitor@batchDelete');
Route::post('admin/visitor/clear', '\app\controller\admin\Visitor@clear');
Route::get('admin/visitor/statistics', '\app\controller\admin\Visitor@statistics');

// 后台任务管理路由
// 后台任务管理路由
Route::rule('admin/task$', '\app\controller\admin\Task@index', 'GET');
Route::rule('admin/task/add', '\app\controller\admin\Task@add', 'GET');
Route::rule('admin/task/edit/:id', '\app\controller\admin\Task@edit', 'GET');
Route::post('admin/task/save', 'admin/Task/save');
Route::post('admin/task/update', 'admin/Task/update');
Route::post('admin/task/delete/:id', 'admin/Task/delete');
Route::get('admin/task/statistics', '\app\controller\admin\Task@statistics');
Route::get('admin/task/effect', '\app\controller\admin\Task@effect');

// 后台搜索管理路由
Route::get('admin/search', '\app\controller\admin\Search@index');
Route::get('admin/search/hot', '\app\controller\admin\Search@hot');
Route::get('admin/search/history', '\app\controller\admin\Search@history');

// 后台系统消息路由
Route::rule('admin/system-message$', '\app\controller\admin\SystemMessage@index', 'GET');
Route::get('admin/system-message/templates', 'admin/SystemMessage/templates');

// 后台推送记录路由
Route::get('admin/notification/records', '\app\controller\admin\Notification@records');

// 后台FAQ管理路由
Route::rule('admin/faq$', '\app\controller\admin\Faq@index', 'GET');
Route::rule('admin/faq/add', '\app\controller\admin\Faq@add', 'GET');
Route::post('admin/faq/save', 'admin/Faq/save');
Route::rule('admin/faq/edit/:id', '\app\controller\admin\Faq@edit', 'GET');
Route::post('admin/faq/update', 'admin/Faq/update');
Route::post('admin/faq/delete/:id', 'admin/Faq/delete');
Route::get('admin/faq/categories', 'admin/Faq/categories');
Route::rule('admin/faq/category/add', '\app\controller\admin\Faq@addCategory', 'GET');
Route::post('admin/faq/category/save', 'admin/Faq/saveCategory');
Route::rule('admin/faq/category/edit/:id', '\app\controller\admin\Faq@editCategory', 'GET');
Route::post('admin/faq/category/update', 'admin/Faq/updateCategory');
Route::post('admin/faq/category/delete/:id', 'admin/Faq/deleteCategory');

// 后台积分金币管理路由
Route::get('admin/currency/points', '\app\controller\admin\Currency@points');
Route::get('admin/currency/coins', '\app\controller\admin\Currency@coins');

// 后台钱包管理路由
Route::rule('admin/wallet$', '\app\controller\admin\Wallet@index', 'GET');

// 后台资产管理路由
Route::get('admin/assets/recharge', '\app\controller\admin\Assets@recharge');
Route::get('admin/assets/withdraw', '\app\controller\admin\Assets@withdraw');
Route::post('admin/assets/confirm-recharge', '\app\controller\admin\Assets@confirmRecharge');
Route::post('admin/assets/cancel-recharge', '\app\controller\admin\Assets@cancelRecharge');
Route::post('admin/assets/approve-withdraw', '\app\controller\admin\Assets@approveWithdraw');
Route::post('admin/assets/reject-withdraw', '\app\controller\admin\Assets@rejectWithdraw');

// 后台收藏管理路由
Route::rule('admin/favorite$', '\app\controller\admin\Favorite@index', 'GET');
Route::get('admin/favorite/statistics', 'admin/Favorite/statistics');
Route::get('admin/favorite/hot', 'admin/Favorite/hot');

// 后台草稿管理路由
Route::rule('admin/draft$', '\app\controller\admin\Draft@index', 'GET');
Route::get('admin/draft/list', '\app\controller\admin\Draft@list');
Route::post('admin/draft/delete', '\app\controller\admin\Draft@delete');
Route::get('admin/draft/statistics', 'admin/Draft/statistics');

// 后台等级管理路由
Route::rule('admin/level$', '\app\controller\admin\Level@index', 'GET');
Route::get('admin/level/list', '\app\controller\admin\Level@list');
Route::post('admin/level/save', '\app\controller\admin\Level@save');
Route::post('admin/level/delete', '\app\controller\admin\Level@delete');
Route::get('admin/level/privileges', '\app\controller\admin\Level@privileges');

// 后台表情管理路由
Route::rule('admin/emoji$', '\app\controller\admin\Emoji@index', 'GET');
Route::get('admin/emoji/list', '\app\controller\admin\Emoji@list');
Route::post('admin/emoji/save', '\app\controller\admin\Emoji@save');
Route::post('admin/emoji/delete', '\app\controller\admin\Emoji@delete');
Route::get('admin/emoji/categories', '\app\controller\admin\Emoji@categories');

// 后台快捷回复路由
Route::rule('admin/quick-reply$', '\app\controller\admin\QuickReply@index', 'GET');
Route::get('admin/quick-reply/list', '\app\controller\admin\QuickReply@list');
Route::get('admin/quick-reply/templates', 'admin/QuickReply/templates');

// 后台位置记录路由
Route::rule('admin/location$', '\app\controller\admin\Location@index', 'GET');
Route::get('admin/location/list', '\app\controller\admin\Location@list');
Route::get('admin/location/heatmap', 'admin/Location/heatmap');

// 后台在线用户路由
Route::rule('admin/online$', '\app\controller\admin\Online@index', 'GET');
Route::get('admin/online/statistics', 'admin/Online/statistics');
Route::post('admin/online/kickOut', 'admin/Online/kickOut');

// 后台名片管理路由
Route::rule('admin/card$', '\app\controller\admin\Card@index', 'GET');
Route::get('admin/card/list', '\app\controller\admin\Card@list');
Route::get('admin/card/templates', 'admin/Card/templates');

// 后台日志管理路由
Route::rule('admin/log$', '\app\controller\admin\Log@index', 'GET');
Route::get('admin/log/operation', 'admin/Log/operation');
Route::get('admin/log/error', 'admin/Log/error');
Route::get('admin/log/slow-query', 'admin/Log/slowQuery');

// 后台监控路由
Route::get('admin/monitor/performance', '\app\controller\admin\Monitor@performance');
Route::get('admin/monitor/server', '\app\controller\admin\Monitor@server');
Route::get('admin/monitor/database', '\app\controller\admin\Monitor@database');

// 后台数据分析路由
Route::rule('admin/analytics$', '\app\controller\admin\Analytics@index', 'GET');
Route::get('admin/analytics/user', 'admin/Analytics/user');
Route::get('admin/analytics/content', 'admin/Analytics/content');
Route::get('admin/analytics/retention', 'admin/Analytics/retention');
Route::get('admin/analytics/conversion', 'admin/Analytics/conversion');

// 后台API管理路由
// 注意：带参数的路由需要放在前面，避免被其他路由覆盖
Route::rule('admin/api/keys/add', '\app\controller\admin\Api@add', 'GET');
Route::rule('admin/api/keys$', '\app\controller\admin\Api@keys', 'GET');
Route::rule('admin/api/calls$', '\app\controller\admin\Api@calls', 'GET');
Route::rule('admin/api/rate-limit$', '\app\controller\admin\Api@rateLimit', 'GET');

// 后台API数据接口路由
Route::get('admin/api/getKeys', '\app\controller\admin\Api@getKeys');
Route::post('admin/api/toggleKeyStatus', '\app\controller\admin\Api@toggleKeyStatus');
Route::post('admin/api/deleteKey', '\app\controller\admin\Api@deleteKey');
Route::get('admin/api/getCalls', '\app\controller\admin\Api@getCalls');
Route::post('admin/api/saveKey', '\app\controller\admin\Api@saveKey');

// 后台存储管理路由
Route::rule('admin/storage$', '\app\controller\admin\Storage@index', 'GET');
Route::get('admin/storage/statistics', '\app\controller\admin\Storage@statistics');
Route::get('admin/storage/cdn', '\app\controller\admin\Storage@cdn');
Route::get('admin/storage/files', '\app\controller\admin\Storage@files');
Route::get('admin/storage/fileDetail/:id', '\app\controller\admin\Storage@fileDetail');
Route::get('admin/storage/upload', '\app\controller\admin\Storage@upload');
Route::get('admin/storage/clean', '\app\controller\admin\Storage@clean');
Route::get('admin/storage/config', '\app\controller\admin\Storage@config');
Route::get('admin/storage/getFileInfo/:id', '\app\controller\admin\Storage@getFileInfo');
Route::post('admin/storage/deleteFile', '\app\controller\admin\Storage@deleteFile');
Route::post('admin/storage/doClean', '\app\controller\admin\Storage@doClean');
Route::post('admin/storage/doUpload', '\app\controller\admin\Storage@doUpload');
Route::post('admin/storage/saveConfig', '\app\controller\admin\Storage@saveConfig');

// 后台定时任务路由
Route::rule('admin/cron$', '\app\controller\admin\Cron@index', 'GET');
Route::get('admin/cron/records', 'admin/Cron/records');

// 后台集成管理路由
Route::get('admin/integration/login', '\app\controller\admin\Integration@login');
Route::get('admin/integration/payment', '\app\controller\admin\Integration@payment');
Route::get('admin/integration/storage', '\app\controller\admin\Integration@storage');
Route::get('admin/integration/map', '\app\controller\admin\Integration@map');

// 移除了单独的 /admin 路由，避免匹配所有以 admin 开头的URL
// 如需访问后台首页，请使用 /admin/index

});

