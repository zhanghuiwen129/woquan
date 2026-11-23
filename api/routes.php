<?php
/**
 * Hublog API 路由定义
 */

/** @var Hublog\Core\Router $router */

// S4：顶层三条路由原本零中间件（限流注解说「公开接口」但实际不受任何限流）。
// 统一挂 RateLimitMiddleware；CSRF/鉴权仍不需要。
$router->get('/api/csrf', [Hublog\Controllers\AuthController::class, 'csrf'], ['RateLimitMiddleware']);

// SEO 路由（公开，不需要 CSRF，但需要限流）
$router->get('/sitemap.xml', [Hublog\Controllers\AdminToolsController::class, 'sitemap'], ['RateLimitMiddleware']);
$router->get('/robots.txt', [Hublog\Controllers\AdminToolsController::class, 'robots'], ['RateLimitMiddleware']);

// V94：健康检查端点（运维可观测性，免鉴权、只读、无用户输入）
// 监控探针（负载均衡 / 云监控 / 宝塔）频繁打此端点，不挂限流也不要求 token。
// 只暴露「通不通 / 磁盘剩余」，不暴露版本与业务指标。
//
// 两条路径都注册，覆盖不同 Web 服务器的转发方式：
//   - /health       ：nginx 用 `location = /health` 转给 api/index.php 时 REQUEST_URI 不变，
//                     Router 解析为 /health（不补 /api 前缀）→ 命中这一条。
//   - /api/health   ：PATH_INFO 模式(/api/index.php/health)或已配伪静态重写时，
//                     Router 解析为 /api/health → 命中这一条。
// 之前只注册 /health，导致 /api/health 永远 404 —— 监控按后者打就拿到前台首页 HTML。
$router->get('/health', [Hublog\Controllers\HealthController::class, 'check']);
$router->get('/api/health', [Hublog\Controllers\HealthController::class, 'check']);

// P2 可观测性：Prometheus 指标端点。鉴权（X-Metrics-Token / Bearer）在控制器内完成，
// 未配置密钥时直接 404，因此不挂鉴权中间件；监控抓取频率高，也不挂限流。
// 同时注册 /metrics，兼容 nginx `location = /metrics` 直转 api/index.php 的场景。
$router->get('/api/metrics', [Hublog\Controllers\MetricsController::class, 'metrics']);
$router->get('/metrics', [Hublog\Controllers\MetricsController::class, 'metrics']);

// 公开接口（限流）
$router->group('/api', function ($router) {
    // 认证
    $router->post('/auth/register', [Hublog\Controllers\AuthController::class, 'register']);
    $router->post('/auth/login', [Hublog\Controllers\AuthController::class, 'login']);
    // V58 手机号验证码登录 / 注册（无短信服务商时验证码只落服务端日志，响应回带 dev_code）
    $router->post('/auth/phone-code', [Hublog\Controllers\AuthController::class, 'sendPhoneLoginCode']);
    $router->post('/auth/login-phone', [Hublog\Controllers\AuthController::class, 'loginByPhone']);
    $router->get('/auth/check-username', [Hublog\Controllers\AuthController::class, 'checkUsername']);
    $router->get('/auth/check-email', [Hublog\Controllers\AuthController::class, 'checkEmail']);
    // 邮箱验证 & 密码找回（公开接口）
    $router->post('/auth/send-verification', [Hublog\Controllers\AuthController::class, 'sendVerification']);
    $router->post('/auth/verify-email', [Hublog\Controllers\AuthController::class, 'verifyEmail']);
    $router->post('/auth/forgot-password', [Hublog\Controllers\AuthController::class, 'forgotPassword']);
    $router->post('/auth/reset-password', [Hublog\Controllers\AuthController::class, 'resetPassword']);
    // 第三方登录（公开接口）
    $router->get('/auth/oauth/{provider}', [Hublog\Controllers\AuthController::class, 'oauthRedirect']);
    $router->get('/auth/oauth/{provider}/callback', [Hublog\Controllers\AuthController::class, 'oauthCallback']);
    // OAuth code 兑换（App/小程序模式：客户端拿到 code 后发给后端，直接返回 JSON）
    $router->post('/auth/oauth/exchange', [Hublog\Controllers\AuthController::class, 'oauthExchange']);
    // V44：用 refresh_token 静默换发新 access_token（不依赖未过期的 JWT）
    $router->post('/auth/token-refresh', [Hublog\Controllers\AuthController::class, 'tokenRefresh']);
    // V45：App 版本检查（公开）
    $router->get('/app/version', [Hublog\Controllers\AppController::class, 'version']);
    // 微信小程序登录（wx.login → code → openid → JWT）
    $router->post('/auth/wx-mini-login', [Hublog\Controllers\AuthController::class, 'wxMiniLogin']);
    // 微信开放平台（移动应用 App）一键登录：SDK code → openid/unionid → JWT
    $router->post('/app/auth/wx-app-login', [Hublog\Controllers\AppController::class, 'wxAppLogin']);
    // App 启动配置中心（公开）
    $router->get('/app/config', [Hublog\Controllers\AppController::class, 'config']);
    // 用户协议 / 隐私政策正文（上架审核要求 App 内可读）
    $router->get('/app/agreements', [Hublog\Controllers\AppController::class, 'agreements']);
    // B2-2：通用单页公开读取（仅 status=1；草稿 404，不回退到协议正文）
    $router->get('/pages/{slug}', [Hublog\Controllers\PageController::class, 'show']);
    // 公开分类列表（发布时选择分类，比 /api/admin/categories 更适合 App）
    $router->get('/app/categories', [Hublog\Controllers\AppController::class, 'categories']);
    // 行为埋点（允许匿名，事件名走白名单）
    $router->post('/app/track', [Hublog\Controllers\AppController::class, 'track']);
    // 微信 JS-SDK 注入配置（H5 在微信内分享用，未配置公众号时返回 ready=false）
    $router->get('/app/wx-jsapi-config', [Hublog\Controllers\AppController::class, 'wxJsapiConfig']);
    $router->get('/site/config', [Hublog\Controllers\AdminSettingsController::class, 'siteConfig']);
    $router->get('/site/friend-links', [Hublog\Controllers\AdminFriendLinkController::class, 'publicFriendLinks']);
    $router->get('/site/stats', [Hublog\Controllers\AdminStatsController::class, 'siteStats']);
    $router->get('/site/user-ranking', [Hublog\Controllers\AdminStatsController::class, 'userRanking']);
    // V65：排行榜补全（用户/动态/圈子/话题 × 日/周/月/总）+ 全站精华「精选」楼层
    $router->get('/site/ranking', [Hublog\Controllers\AdminStatsController::class, 'ranking']);
    $router->get('/site/featured', [Hublog\Controllers\AdminPointsController::class, 'featuredPosts']);
    // V36: 国际化 — 获取语言包
    $router->get('/site/lang', [Hublog\Controllers\AdminSettingsController::class, 'getLang']);
    // V65：定时发布调度（外部 cron 每 5 分钟打一次，比「靠请求触发」准点）
    $router->post('/cron/scheduled', [Hublog\Controllers\PostController::class, 'cronPublishScheduled']);
    // V82：徽章系统定时扫描（外部 cron 每小时打一次：统计类徽章自动解锁/冻结、限时徽章到期）
    // 鉴权：body {secret} 或请求头 X-Cron-Token，比对 settings.cron_secret（与 /cron/scheduled 同一套 token）
    $router->post('/cron/badges', [Hublog\Controllers\BadgeController::class, 'cron']);
    // V94：异步任务 worker（视频压缩等耗时任务移出请求热路径）
    // 鉴权：body {secret} 或请求头 X-Cron-Token，比对 settings.cron_secret（同一套 token）
    // 建议外部 cron 每分钟打一次
    $router->post('/cron/jobs', [Hublog\Controllers\JobController::class, 'cron']);
    $router->get('/cron/jobs/status', [Hublog\Controllers\JobController::class, 'status']);
    // P2：只增日志表滚动归档（外部 cron 每天一次，鉴权同 /cron/jobs）
    $router->post('/cron/archive', [Hublog\Controllers\JobController::class, 'archive']);
    // V96：付费圈子订阅到期清退 + 提醒（鉴权同 /cron/jobs）
    $router->post('/cron/circle-subscribes', [Hublog\Controllers\CircleSubscriptionCronController::class, 'sweep']);
    // 授权统计上报兜底端点（鉴权同 /cron/jobs；有服务器 cron 的站点可主动触发心跳）
    $router->post('/cron/license-heartbeat', [Hublog\Controllers\CronHeartbeatController::class, 'cron']);

    // 用户
    $router->get('/user/profile', [Hublog\Controllers\UserController::class, 'profile']);
    $router->get('/user/search', [Hublog\Controllers\UserController::class, 'search']);
    // 编辑资料时实时校验昵称是否已被占用
    $router->get('/user/check-nickname', [Hublog\Controllers\UserController::class, 'checkNickname']);
    $router->get('/user/comments', [Hublog\Controllers\UserController::class, 'comments']);
    $router->get('/user/likes', [Hublog\Controllers\UserController::class, 'likes']);
    // V58：隐私权限 + 个性化设置 + 草稿箱 + 通知偏好 + 申诉 + 手机号 + 在线状态
    $router->get('/user/privacy', [Hublog\Controllers\UserController::class, 'privacy']);
    $router->put('/user/privacy', [Hublog\Controllers\UserController::class, 'updatePrivacy']);
    $router->get('/user/drafts', [Hublog\Controllers\UserController::class, 'drafts']);
    $router->post('/user/draft', [Hublog\Controllers\UserController::class, 'saveDraft']);
    $router->delete('/user/draft', [Hublog\Controllers\UserController::class, 'deleteDraft']);
    $router->get('/user/notify-prefs', [Hublog\Controllers\UserController::class, 'notifyPrefs']);
    $router->put('/user/notify-prefs', [Hublog\Controllers\UserController::class, 'updateNotifyPrefs']);
    $router->post('/user/appeal', [Hublog\Controllers\UserController::class, 'submitAppeal']);
    $router->get('/user/appeals', [Hublog\Controllers\UserController::class, 'myAppeals']);
    $router->post('/user/phone-code', [Hublog\Controllers\UserController::class, 'sendPhoneCode']);
    $router->post('/user/phone/verify', [Hublog\Controllers\UserController::class, 'verifyPhone']);
    $router->get('/user/online', [Hublog\Controllers\UserController::class, 'onlineStatus']);
    $router->put('/user/alive', [Hublog\Controllers\UserController::class, 'heartbeat']);
    // GDPR：用户导出自己的全部数据
    $router->get('/user/export-data', [Hublog\Controllers\UserController::class, 'exportMyData']);
    // V70：设置头像框（会员中心）
    $router->post('/user/frame', [Hublog\Controllers\UserController::class, 'setFrame']);
    
    // 内容
    $router->get('/posts/timeline', [Hublog\Controllers\PostController::class, 'timeline']);
    // V66：信息流负反馈（不感兴趣 / 屏蔽标签 / 屏蔽作者）
    $router->post('/posts/feedback', [Hublog\Controllers\PostController::class, 'feedFeedback']);
            $router->get('/posts/detail', [Hublog\Controllers\PostController::class, 'detail']);
            $router->get('/posts/pinned', [Hublog\Controllers\PostController::class, 'pinned']);
    // 我的待发布（定时）动态列表
    $router->get('/posts/scheduled', [Hublog\Controllers\PostController::class, 'scheduledList']);
            $router->get('/posts/topic', [Hublog\Controllers\PostController::class, 'topic']);
    // 公开话题列表（发布页「话题」按钮拉取候选话题用，limit 默认 6）
    $router->get('/topics', [Hublog\Controllers\PostController::class, 'topics']);
    $router->get('/posts/category', [Hublog\Controllers\PostController::class, 'category']);
    $router->get('/posts/latest-ids', [Hublog\Controllers\PostController::class, 'latestIds']);
    $router->get('/posts/check-new', [Hublog\Controllers\PostController::class, 'checkNew']);
    $router->get('/posts/popular', [Hublog\Controllers\PostController::class, 'popular']);
    $router->get('/posts/likes', [Hublog\Controllers\PostController::class, 'postLikes']);

    // 关注系统（公开查看，游客可看他人关注/粉丝数）
    $router->get('/follow/stats', [Hublog\Controllers\FollowController::class, 'followStats']);
    $router->get('/follow/followers', [Hublog\Controllers\FollowController::class, 'followers']);
    $router->get('/follow/following', [Hublog\Controllers\FollowController::class, 'following']);
    $router->get('/follow/suggest', [Hublog\Controllers\FollowController::class, 'suggestUsers']);

    // V80：活动（仅报名制；打卡功能已下线，见 Migration::migrate72）
    $router->get('/activities', [Hublog\Controllers\ActivityController::class, 'list']);
    $router->get('/activity/detail', [Hublog\Controllers\ActivityController::class, 'detail']);
    $router->get('/activity/mine', [Hublog\Controllers\ActivityController::class, 'mine']);
    $router->post('/activity/join', [Hublog\Controllers\ActivityController::class, 'join']);
    $router->post('/activity/leave', [Hublog\Controllers\ActivityController::class, 'leave']);

    // 内容搜索
    $router->get('/search', [Hublog\Controllers\SearchController::class, 'search']);

    // 定位服务（公开接口，基于 IP 自动定位 + 逆地理编码）
    $router->get('/location/ip', [Hublog\Controllers\LocationController::class, 'ipLocate']);
    $router->get('/location/reverse', [Hublog\Controllers\LocationController::class, 'reverseGeocode']);

    // 圈子（公开浏览：列表 / 详情 / 成员 / 圈内动态）
    $router->get('/circles', [Hublog\Controllers\CircleController::class, 'list']);
    $router->get('/circles/detail', [Hublog\Controllers\CircleController::class, 'show']);
    $router->get('/circles/members', [Hublog\Controllers\CircleController::class, 'members']);
    $router->get('/circles/posts', [Hublog\Controllers\CircleController::class, 'posts']);
    // V65：圈内黑名单名单（圈主/平台管理员可见）
    $router->get('/circles/blacklist', [Hublog\Controllers\CircleController::class, 'blacklist']);

    // V96：商城（公开浏览）
    $router->get('/shop/config', [Hublog\Controllers\ShopController::class, 'config']);
    $router->get('/shop/products', [Hublog\Controllers\ShopController::class, 'products']);
    $router->get('/shop/product', [Hublog\Controllers\ShopController::class, 'product']);
    // V96：积分大转盘奖品配置（公开）
    $router->get('/points/wheel-prizes', [Hublog\Controllers\PointController::class, 'wheelPrizes']);
}, ['RateLimitMiddleware', 'CsrfMiddleware', 'LicenseMiddleware', 'FeatureGuardMiddleware']);

// V67：钱包 / 会员（需登录，游客一律 401）
$router->group('/api', function ($router) {
    $router->get('/pay/wallet', [Hublog\Controllers\PaymentController::class, 'wallet']);
    $router->get('/pay/vip-info', [Hublog\Controllers\PaymentController::class, 'vipInfo']);
    $router->get('/pay/vip-orders', [Hublog\Controllers\PaymentController::class, 'vipOrders']);
    $router->post('/pay/vip-buy', [Hublog\Controllers\PaymentController::class, 'buyVip']);
    $router->get('/pay/circle-pay', [Hublog\Controllers\PaymentController::class, 'circlePayInfo']);
    $router->post('/pay/circle-subscribe', [Hublog\Controllers\PaymentController::class, 'circleSubscribe']);
    // V69：在线充值（微信 / 支付宝官方支付）
    $router->get('/pay/recharge-config', [Hublog\Controllers\PaymentController::class, 'rechargeConfig']);
    $router->post('/pay/recharge-create', [Hublog\Controllers\PaymentController::class, 'rechargeCreate']);
    $router->get('/pay/recharge-orders', [Hublog\Controllers\PaymentController::class, 'rechargeOrders']);
    // V97：内容付费墙（单篇买断 / 专栏订阅，需登录）
    $router->get('/content-pay/product', [Hublog\Controllers\ContentPayController::class, 'product']);
    $router->get('/content-pay/column', [Hublog\Controllers\ContentPayController::class, 'column']);
    $router->post('/content-pay/buy', [Hublog\Controllers\ContentPayController::class, 'buy']);
    $router->get('/content-pay/orders', [Hublog\Controllers\ContentPayController::class, 'orders']);
    $router->get('/content-pay/entitlements', [Hublog\Controllers\ContentPayController::class, 'entitlements']);
    // V68：积分中心（签到 / 任务 / 流水 / 领取）
    $router->get('/points/home', [Hublog\Controllers\PointController::class, 'home']);
    $router->get('/points/tasks', [Hublog\Controllers\PointController::class, 'tasks']);
    $router->post('/points/signin', [Hublog\Controllers\PointController::class, 'signin']);
    $router->get('/points/logs', [Hublog\Controllers\PointController::class, 'logs']);
    $router->post('/points/claim', [Hublog\Controllers\PointController::class, 'claim']);
// S4：注释写「需登录，游客一律 401」，中间件链却与公开组完全相同，
// 只靠控制器内 14 处 requireUser 补位。补上 AuthMiddleware 做纵深防御，
// 新增接口忘补 requireUser 也不会变成未授权访问。
}, ['RateLimitMiddleware', 'AuthMiddleware', 'CsrfMiddleware', 'LicenseMiddleware', 'FeatureGuardMiddleware']);

// V69：支付渠道回调 —— 第三方服务器既没有登录态也没有 CSRF token，
// 必须绕开 Auth / Csrf（安全性由「主动查单 + 回调验签 + 幂等入账」保证）。
$router->group('/api', function ($router) {
    $router->post('/pay/notify/wxpay', [Hublog\Controllers\PaymentController::class, 'notifyWxpay']);
    $router->post('/pay/notify/alipay', [Hublog\Controllers\PaymentController::class, 'notifyAlipay']);
}, ['RateLimitMiddleware', 'FeatureGuardMiddleware']);

// 需要登录的接口
$router->group('/api', function ($router) {
    // 认证
    $router->get('/auth/me', [Hublog\Controllers\AuthController::class, 'me']);
    $router->post('/auth/refresh', [Hublog\Controllers\AuthController::class, 'refresh']);
    $router->post('/auth/logout', [Hublog\Controllers\AuthController::class, 'logout']);
    $router->get('/auth/profile', [Hublog\Controllers\AuthController::class, 'profile']);
    $router->put('/auth/profile', [Hublog\Controllers\AuthController::class, 'updateProfile']);
    $router->put('/auth/password', [Hublog\Controllers\AuthController::class, 'changePassword']);
    $router->post('/auth/set-password', [Hublog\Controllers\AuthController::class, 'setPassword']);
    $router->post('/auth/avatar', [Hublog\Controllers\AuthController::class, 'uploadAvatar']);
    $router->post('/auth/cover', [Hublog\Controllers\AuthController::class, 'uploadCover']);

    // V51：实名认证（阿里云云市场二要素核验 + 后台人工复核）
    $router->get('/realname/status', [Hublog\Controllers\RealNameController::class, 'status']);
    $router->post('/realname/verify', [Hublog\Controllers\RealNameController::class, 'verify']);

    // 内容管理
    $router->post('/posts', [Hublog\Controllers\PostController::class, 'create']);
    $router->put('/posts', [Hublog\Controllers\PostController::class, 'update']);
    // V56：转发动态（body: post_id / content / circle_id）
    $router->post('/posts/repost', [Hublog\Controllers\PostController::class, 'repost']);
    // V57：投票帖投票 / 撤回（body: post_id + option_id，重复提交同选项即撤回）
    $router->post('/posts/poll/vote', [Hublog\Controllers\PostController::class, 'pollVote']);
    // V59：P1-1 进阶帖型（问答 / 组队 / 打卡）
    $router->post('/posts/question/answer', [Hublog\Controllers\PostController::class, 'questionAnswer']);
    $router->post('/posts/question/accept', [Hublog\Controllers\PostController::class, 'questionAccept']);
    $router->post('/posts/team/join', [Hublog\Controllers\PostController::class, 'teamJoin']);
    $router->post('/posts/team/leave', [Hublog\Controllers\PostController::class, 'teamLeave']);
    $router->post('/posts/checkin/do', [Hublog\Controllers\PostController::class, 'checkinDo']);

    // V142：发布时 AI 辅助（润色 / 扩写 / 续写 / 精简 / 换风格）。
    // 客户端 app-client/src/api/index.js 的 apiAiAssist 调的就是这个地址。
    // 此前漏注册，线上该 URL 落到 SPA 回落 → App 端 AI 功能整个是坏的。
    // 归「ai」付费模块：LicenseMiddleware 的 MODULE_ROUTES 已登记 '#^/api/ai/#'，
    // 未授权站点会被 canUseModule('ai') 拦下并计每日额度（AI_QUOTA_ROUTES）。
    $router->post('/ai/assist', [Hublog\Controllers\AiController::class, 'assist']);

    $router->delete('/posts', [Hublog\Controllers\PostController::class, 'delete']);
    $router->post('/posts/like', [Hublog\Controllers\PostController::class, 'like']);
    $router->post('/posts/comment-like', [Hublog\Controllers\PostController::class, 'commentLike']);
    $router->post('/posts/comment', [Hublog\Controllers\PostController::class, 'comment']);
    $router->delete('/posts/comment', [Hublog\Controllers\PostController::class, 'deleteComment']);
    // V83：神评标记（帖子作者 / 圈主·管理员 / 平台管理员可标；评论作者本人不可标自己）
    $router->post('/posts/comment/god', [Hublog\Controllers\PostController::class, 'markGodComment']);
    $router->post('/posts/comment/god/unmark', [Hublog\Controllers\PostController::class, 'unmarkGodComment']);
    // V47：博主置顶评论
    $router->post('/posts/comment/pin', [Hublog\Controllers\PostController::class, 'pinComment']);
    $router->post('/posts/upload', [Hublog\Controllers\PostController::class, 'uploadImage']);
    $router->post('/posts/upload-video', [Hublog\Controllers\PostController::class, 'uploadVideo']);
    $router->post('/posts/upload-attachment', [Hublog\Controllers\PostController::class, 'uploadAttachment']);
    // 删除未关联动态的上传文件（用户取消发布时清理）
    $router->post('/posts/delete-upload', [Hublog\Controllers\PostController::class, 'deleteUpload']);

    // 云存储直传：第一步拿签名；第二步登记（前端直传完成后回传 URL 入库）
    $router->post('/upload/sign',     [Hublog\Controllers\PostController::class, 'uploadDirectSign']);
    $router->post('/upload/register', [Hublog\Controllers\PostController::class, 'uploadDirectRegister']);
    // 头像/封面直传登记：复用同一条接口（type=avatar|cover）

    // 收藏系统
    $router->post('/favorites/toggle', [Hublog\Controllers\FavoriteController::class, 'toggle']);
    $router->get('/favorites/list', [Hublog\Controllers\FavoriteController::class, 'list']);
    $router->get('/favorites/check', [Hublog\Controllers\FavoriteController::class, 'check']);

    // 圈子（登录操作：我加入的圈子 / 创建 / 编辑 / 删除 / 加入 / 退出）
    $router->get('/circles/my', [Hublog\Controllers\CircleController::class, 'my']);
    $router->post('/circles', [Hublog\Controllers\CircleController::class, 'create']);
    $router->put('/circles', [Hublog\Controllers\CircleController::class, 'update']);
    $router->delete('/circles', [Hublog\Controllers\CircleController::class, 'delete']);
    $router->post('/circles/join', [Hublog\Controllers\CircleController::class, 'join']);
    $router->post('/circles/leave', [Hublog\Controllers\CircleController::class, 'leave']);
    // V50：圈子加入申请相关接口
    $router->get('/circles/my-requests', [Hublog\Controllers\CircleController::class, 'myJoinRequests']);
    $router->get('/circles/pending-requests', [Hublog\Controllers\CircleController::class, 'pendingRequests']);
    $router->post('/circles/approve', [Hublog\Controllers\CircleController::class, 'approveRequest']);
    $router->post('/circles/reject', [Hublog\Controllers\CircleController::class, 'rejectRequest']);
    // V56：圈主管理（公告 / 成员移除与角色 / 圈内禁言 / 内容置顶与精华下沉）
    $router->post('/circles/announce', [Hublog\Controllers\CircleController::class, 'setAnnouncement']);
    $router->post('/circles/manage-member', [Hublog\Controllers\CircleController::class, 'manageMember']);
    $router->post('/circles/mute-member', [Hublog\Controllers\CircleController::class, 'muteMember']);
    // V65：圈内永久黑名单（拉黑 / 解黑）
    $router->post('/circles/ban-member', [Hublog\Controllers\CircleController::class, 'banMember']);
    $router->post('/circles/unban-member', [Hublog\Controllers\CircleController::class, 'unbanMember']);
    $router->post('/circles/manage-post', [Hublog\Controllers\CircleController::class, 'managePost']);
    // V58 / P1-3：进入圈子群聊（老圈子无群会话时自动补建）
    $router->post('/circles/group', [Hublog\Controllers\CircleController::class, 'joinGroup']);

    // 消息通知
    $router->get('/notifications', [Hublog\Controllers\NotificationController::class, 'index']);
    $router->get('/notifications/unread-count', [Hublog\Controllers\NotificationController::class, 'unreadCount']);
    $router->put('/notifications/{id}/read', [Hublog\Controllers\NotificationController::class, 'markRead']);
    $router->put('/notifications/read-all', [Hublog\Controllers\NotificationController::class, 'markAllRead']);
    $router->delete('/notifications/{id}', [Hublog\Controllers\NotificationController::class, 'delete']);
    $router->delete('/notifications/clear', [Hublog\Controllers\NotificationController::class, 'clear']);

    // 关注系统（仅需要登录的操作）
    $router->post('/follow', [Hublog\Controllers\FollowController::class, 'follow']);
    $router->get('/follow/check', [Hublog\Controllers\FollowController::class, 'checkFollowing']);
    $router->post('/follow/batch-check', [Hublog\Controllers\FollowController::class, 'batchCheckFollowing']);
    $router->post('/follow/block', [Hublog\Controllers\FollowController::class, 'blockUser']);
    $router->post('/follow/unblock', [Hublog\Controllers\FollowController::class, 'unblockUser']);
    $router->get('/follow/blocks', [Hublog\Controllers\FollowController::class, 'listBlocked']);
    $router->get('/follow/blocked-check', [Hublog\Controllers\FollowController::class, 'isBlocked']);

    // 分类（频道）关注（仅需要登录）
    $router->post('/category/follow', [Hublog\Controllers\CategoryController::class, 'follow']);
    $router->get('/category/status', [Hublog\Controllers\CategoryController::class, 'status']);
    $router->get('/category/following', [Hublog\Controllers\CategoryController::class, 'following']);

    // 举报系统
    $router->post('/reports', [Hublog\Controllers\ReportController::class, 'create']);

    // 即时通讯（IM）
    $router->get('/chat/conversations', [Hublog\Controllers\ChatController::class, 'conversations']);
    $router->get('/chat/messages', [Hublog\Controllers\ChatController::class, 'messages']);
    $router->get('/chat/new-messages', [Hublog\Controllers\ChatController::class, 'newMessages']);
    $router->post('/chat/send', [Hublog\Controllers\ChatController::class, 'send']);
    $router->put('/chat/read', [Hublog\Controllers\ChatController::class, 'markRead']);
    // V47：撤回自己的消息（软删除，2 分钟窗口）
    $router->delete('/chat/message', [Hublog\Controllers\ChatController::class, 'recallMessage']);
    // V48：删除（隐藏）自己的会话，不影响对方
    $router->delete('/chat/conversation', [Hublog\Controllers\ChatController::class, 'deleteConversation']);
    // V52：回收站 —— 删掉的会话可在这里找回（消息从未被物理删除）
    $router->get('/chat/deleted', [Hublog\Controllers\ChatController::class, 'deletedConversations']);
    $router->post('/chat/restore', [Hublog\Controllers\ChatController::class, 'restoreConversation']);
    // V47：正在输入状态广播（仅实时通道，不落库）
    $router->post('/chat/typing', [Hublog\Controllers\ChatController::class, 'typing']);
    $router->get('/chat/unread-count', [Hublog\Controllers\ChatController::class, 'unreadCount']);
    $router->post('/chat/private', [Hublog\Controllers\ChatController::class, 'createPrivate']);
    $router->put('/chat/settings', [Hublog\Controllers\ChatController::class, 'updateMemberSettings']);
    // V58 / P1-3：圈子群聊（群信息 / 成员 / 公告 / 禁言 / 踢人 / 解散）
    $router->get('/chat/group/info', [Hublog\Controllers\ChatController::class, 'groupInfo']);
    $router->get('/chat/group/members', [Hublog\Controllers\ChatController::class, 'groupMembers']);
    $router->put('/chat/group/announce', [Hublog\Controllers\ChatController::class, 'groupAnnounce']);
    $router->post('/chat/group/mute', [Hublog\Controllers\ChatController::class, 'groupMute']);
    $router->post('/chat/group/kick', [Hublog\Controllers\ChatController::class, 'groupKick']);
    $router->post('/chat/group/dissolve', [Hublog\Controllers\ChatController::class, 'groupDissolve']);

    // V55：音视频通话（一对一语音/视频，媒体流走腾讯云 TRTC，后端只做信令与记录）
    $router->post('/call/token', [Hublog\Controllers\CallController::class, 'token']);
    $router->post('/call/invite', [Hublog\Controllers\CallController::class, 'invite']);
    $router->post('/call/accept', [Hublog\Controllers\CallController::class, 'accept']);
    $router->post('/call/reject', [Hublog\Controllers\CallController::class, 'reject']);
    $router->post('/call/cancel', [Hublog\Controllers\CallController::class, 'cancel']);
    $router->post('/call/hangup', [Hublog\Controllers\CallController::class, 'hangup']);
    $router->get('/call/pending', [Hublog\Controllers\CallController::class, 'pending']);
    $router->get('/call/records', [Hublog\Controllers\CallController::class, 'records']);

    // SSE 实时推送（需要登录，H5 EventSource）
    $router->get('/sse', [Hublog\Controllers\NotificationController::class, 'sseStream']);
    // 事件长轮询（App/小程序端拿实时事件的通道，与 SSE 共用同一队列）
    $router->get('/app/events', [Hublog\Controllers\NotificationController::class, 'eventsPoll']);

    // V46：登记设备推送令牌（离线推送备用）
    $router->post('/app/push-token', [Hublog\Controllers\AppController::class, 'registerPushToken']);

    // V82：徽章系统 —— 我的徽章（已解锁 + 未解锁进度）
    $router->get('/badges/my', [Hublog\Controllers\BadgeController::class, 'my']);
    // 账号注销（App 上架合规要求：必须提供账号删除入口），DELETE /api/app/account
    $router->delete('/app/account', [Hublog\Controllers\AppController::class, 'deleteAccount']);
    // 登录设备管理（P0 设备/渠道识别）：查看会话列表 + 远程踢下线
    $router->get('/app/devices', [Hublog\Controllers\AppController::class, 'devices']);
    $router->post('/app/devices/kick', [Hublog\Controllers\AppController::class, 'kickDevice']);

    // V42：登录用户主动清理自己的未发布上传文件（前端取消发布时调用）
    $router->post('/uploads/cleanup', [Hublog\Controllers\PostController::class, 'cleanupOwnUploads']);

    // V96：商城（需登录：购买 / 我的订单 / 圈友闲置发布与管理）
    $router->post('/shop/buy', [Hublog\Controllers\ShopController::class, 'buy']);
    $router->get('/shop/orders', [Hublog\Controllers\ShopController::class, 'orders']);
    $router->post('/shop/post', [Hublog\Controllers\ShopController::class, 'post']);
    $router->get('/shop/my-posts', [Hublog\Controllers\ShopController::class, 'myPosts']);
    $router->post('/shop/post-delete', [Hublog\Controllers\ShopController::class, 'postDelete']);
    // V96：积分大转盘（需登录：抽奖 / 我的记录）
    $router->post('/points/wheel', [Hublog\Controllers\PointController::class, 'wheel']);
    $router->get('/points/wheel-records', [Hublog\Controllers\PointController::class, 'wheelRecords']);
}, ['RateLimitMiddleware', 'AuthMiddleware', 'CsrfMiddleware', 'LicenseMiddleware', 'FeatureGuardMiddleware']);

// 管理员接口
$router->group('/api/admin', function ($router) {
    $router->get('/dashboard', [Hublog\Controllers\AdminController::class, 'dashboard']);
    $router->get('/users', [Hublog\Controllers\UserController::class, 'list']);
    $router->put('/users/status', [Hublog\Controllers\UserController::class, 'updateStatus']);
    $router->put('/users/update', [Hublog\Controllers\UserController::class, 'adminUpdate']);
    $router->delete('/users', [Hublog\Controllers\UserController::class, 'delete']);
    $router->get('/posts', [Hublog\Controllers\AdminController::class, 'posts']);
    $router->put('/posts/status', [Hublog\Controllers\AdminController::class, 'updatePostStatus']);
    $router->put('/posts/pin', [Hublog\Controllers\AdminController::class, 'pinPost']);
    // V65：全站精华 / 运营推荐位（slot=home 首页精选，slot=circle 圈子精选）
    $router->get('/featured', [Hublog\Controllers\AdminController::class, 'featuredList']);
    $router->post('/featured/toggle', [Hublog\Controllers\AdminController::class, 'featuredToggle']);
    $router->get('/comments', [Hublog\Controllers\AdminController::class, 'comments']);
    $router->delete('/comments', [Hublog\Controllers\AdminController::class, 'deleteComment']);
    $router->get('/attachments', [Hublog\Controllers\AdminAttachmentController::class, 'attachments']);
    $router->put('/attachments/status', [Hublog\Controllers\AdminAttachmentController::class, 'updateAttachmentStatus']);
    $router->delete('/attachments', [Hublog\Controllers\AdminAttachmentController::class, 'deleteAttachment']);
    $router->get('/settings', [Hublog\Controllers\AdminSettingsController::class, 'settings']);
    $router->put('/settings', [Hublog\Controllers\AdminSettingsController::class, 'settings']);
    // 前台主题发现：扫 templates 下各主题 theme.json + 契约校验，供「站点与界面」主题卡片渲染
    $router->get('/themes', [Hublog\Controllers\AdminSettingsController::class, 'themes']);
    // 主题外观选项：读取 schema + 已存值 / 保存当前主题选项（按主题分别持久化）
    $router->get('/theme-options', [Hublog\Controllers\AdminSettingsController::class, 'themeOptions']);
    $router->put('/theme-options', [Hublog\Controllers\AdminSettingsController::class, 'saveThemeOptions']);
    $router->post('/theme-options/upload', [Hublog\Controllers\AdminSettingsController::class, 'uploadThemeImage']);
    // 云存储连通性测试
    $router->get('/storage/test-connection', [Hublog\Controllers\AdminSettingsController::class, 'storageTestConnection']);
    $router->post('/upload-homepage-cover', [Hublog\Controllers\AdminSettingsController::class, 'uploadHomepageCover']);
    // V43：图片水印
    $router->post('/upload-watermark', [Hublog\Controllers\AdminSettingsController::class, 'uploadWatermark']);
    $router->post('/watermark-preview', [Hublog\Controllers\AdminSettingsController::class, 'watermarkPreview']);
    $router->get('/friend-links', [Hublog\Controllers\AdminFriendLinkController::class, 'friendLinks']);
    $router->post('/friend-links', [Hublog\Controllers\AdminFriendLinkController::class, 'saveFriendLink']);
    $router->put('/friend-links', [Hublog\Controllers\AdminFriendLinkController::class, 'saveFriendLink']);
    $router->delete('/friend-links', [Hublog\Controllers\AdminFriendLinkController::class, 'deleteFriendLink']);
    // V76：App 版本管理（后台「版本管理」页：发布/编辑/删除 App 升级包记录）
    $router->get('/app-versions', [Hublog\Controllers\AdminAppVersionController::class, 'appVersions']);
    $router->post('/app-versions', [Hublog\Controllers\AdminAppVersionController::class, 'saveAppVersion']);
    $router->put('/app-versions', [Hublog\Controllers\AdminAppVersionController::class, 'saveAppVersion']);
    $router->delete('/app-versions', [Hublog\Controllers\AdminAppVersionController::class, 'deleteAppVersion']);
    // V79：活动管理（后台创建报名活动 —— 此前只能手写 SQL 插 activities 表）
    // V80：放宽鉴权 —— 圈主可管理自己圈子的活动；/my-circles 供活动表单拉取可选圈子
    $router->get('/activities', [Hublog\Controllers\AdminActivitiesController::class, 'activities']);
    $router->post('/activities', [Hublog\Controllers\AdminActivitiesController::class, 'saveActivity']);
    $router->put('/activities', [Hublog\Controllers\AdminActivitiesController::class, 'saveActivity']);
    $router->delete('/activities', [Hublog\Controllers\AdminActivitiesController::class, 'deleteActivity']);
    $router->get('/my-circles', [Hublog\Controllers\AdminActivitiesController::class, 'myCircles']);
    $router->get('/categories', [Hublog\Controllers\AdminCategoryController::class, 'categories']);
    $router->post('/categories', [Hublog\Controllers\AdminCategoryController::class, 'saveCategory']);
    $router->put('/categories', [Hublog\Controllers\AdminCategoryController::class, 'saveCategory']);
    $router->delete('/categories', [Hublog\Controllers\AdminCategoryController::class, 'deleteCategory']);
    $router->post('/backup', [Hublog\Controllers\AdminUpgradeController::class, 'backup']);
    $router->post('/backup/cleanup', [Hublog\Controllers\AdminUpgradeController::class, 'cleanupBackups']);
    $router->get('/version-check', [Hublog\Controllers\AdminUpgradeController::class, 'versionCheck']);
    $router->post('/upgrade', [Hublog\Controllers\AdminUpgradeController::class, 'upgrade']);
    // 升级前差异预览（上传 zip 升级包，对比远程与本地文件清单，不执行覆盖）
    $router->post('/upgrade-compare', [Hublog\Controllers\AdminUpgradeController::class, 'upgradeCompare']);
    // 在线升级预检：远程仓库分支/commit 信息
    $router->get('/upgrade/online-preflight', [Hublog\Controllers\AdminUpgradeController::class, 'onlinePreflight']);
    // 在线升级差异预览：自动从远程仓库下载 zip 并对比（不覆盖）
    $router->get('/upgrade/online-compare', [Hublog\Controllers\AdminUpgradeController::class, 'onlineCompare']);
    // 在线升级执行：自动从远程仓库下载 zip 并执行升级
    $router->post('/upgrade/online-run', [Hublog\Controllers\AdminUpgradeController::class, 'onlineRun']);
    $router->post('/rollback', [Hublog\Controllers\AdminUpgradeController::class, 'rollback']);
    $router->get('/reports', [Hublog\Controllers\ReportController::class, 'list']);
    $router->put('/reports/resolve', [Hublog\Controllers\ReportController::class, 'resolve']);
    $router->post('/ai/test', [Hublog\Controllers\AdminAiController::class, 'testAi']);
    $router->get('/ai/ollama-status', [Hublog\Controllers\AdminAiController::class, 'ollamaStatus']);
    $router->post('/ai/ollama-install', [Hublog\Controllers\AdminAiController::class, 'ollamaInstall']);
    // V36 新增：批量操作
    $router->post('/posts/batch', [Hublog\Controllers\AdminBatchController::class, 'batchPosts']);
    $router->post('/comments/batch', [Hublog\Controllers\AdminBatchController::class, 'batchComments']);
    $router->post('/users/batch', [Hublog\Controllers\AdminBatchController::class, 'batchUsers']);
    // V36 新增：数据导出
    $router->get('/export', [Hublog\Controllers\AdminToolsController::class, 'exportData']);
    // V36 新增：运营统计
    $router->get('/stats', [Hublog\Controllers\AdminToolsController::class, 'stats']);
    // V36 新增：话题管理
    $router->get('/topics', [Hublog\Controllers\AdminToolsController::class, 'topics']);
    $router->post('/topics', [Hublog\Controllers\AdminToolsController::class, 'saveTopic']);
    $router->put('/topics', [Hublog\Controllers\AdminToolsController::class, 'saveTopic']);
    $router->delete('/topics', [Hublog\Controllers\AdminToolsController::class, 'deleteTopic']);
    // V95：话题头图设置（后台话题管理）
    $router->post('/topics/cover', [Hublog\Controllers\AdminToolsController::class, 'setTopicCover']);
    // V49 新增：圈子管理（列表/创建/编辑/删除/成员管理）
    $router->get('/circles', [Hublog\Controllers\AdminCircleController::class, 'circles']);
    $router->post('/circles', [Hublog\Controllers\AdminCircleController::class, 'saveCircle']);
    $router->put('/circles', [Hublog\Controllers\AdminCircleController::class, 'saveCircle']);
    $router->delete('/circles', [Hublog\Controllers\AdminCircleController::class, 'deleteCircle']);
    $router->get('/circles/members', [Hublog\Controllers\AdminCircleController::class, 'circleMembers']);
    $router->delete('/circles/members', [Hublog\Controllers\AdminCircleController::class, 'removeCircleMember']);
    // V50 新增：圈子权限扩展（成员角色 / 转让圈主 / 加入申请审批）
    $router->post('/circles/members/role', [Hublog\Controllers\AdminCircleController::class, 'setCircleMemberRole']);
    $router->post('/circles/transfer', [Hublog\Controllers\AdminCircleController::class, 'transferCircleOwner']);
    // V51 新增：实名认证记录（脱敏列表 + 人工通过/驳回/撤销）
    $router->get('/realname', [Hublog\Controllers\RealNameController::class, 'adminList']);
    $router->post('/realname/review', [Hublog\Controllers\RealNameController::class, 'adminReview']);
    $router->get('/circles/requests', [Hublog\Controllers\AdminCircleController::class, 'circlePendingRequests']);
    $router->post('/circles/requests/approve', [Hublog\Controllers\AdminCircleController::class, 'approveCircleRequest']);
    $router->post('/circles/requests/reject', [Hublog\Controllers\AdminCircleController::class, 'rejectCircleRequest']);
    // V36 新增：插件管理
    $router->get('/plugins', [Hublog\Controllers\AdminToolsController::class, 'plugins']);
    $router->put('/plugins', [Hublog\Controllers\AdminToolsController::class, 'togglePlugin']);
    // E2-5 / Q3：插件生命周期（安装/升级/卸载；删表令牌校验在控制器层）
    $router->post('/plugins/install', [Hublog\Controllers\AdminPluginController::class, 'install']);
    $router->post('/plugins/upgrade', [Hublog\Controllers\AdminPluginController::class, 'upgrade']);
    $router->post('/plugins/uninstall', [Hublog\Controllers\AdminPluginController::class, 'uninstall']);
    // E2-3 / E2-4 / Q8：后台受控加载汇总、插件配置 KV、设置页 HTML
    $router->get('/plugin-admin-config', [Hublog\Controllers\AdminPluginController::class, 'adminConfig']);
    $router->get('/plugin-config', [Hublog\Controllers\AdminPluginController::class, 'config']);
    $router->post('/plugin-config', [Hublog\Controllers\AdminPluginController::class, 'saveConfig']);
    $router->get('/plugin-settings-page', [Hublog\Controllers\AdminPluginController::class, 'settingsPage']);
    // B2-3：通用单页管理（免费核心能力，仅视觉归 dataops 展示分组）
    $router->get('/pages', [Hublog\Controllers\AdminPageController::class, 'list']);
    $router->post('/pages', [Hublog\Controllers\AdminPageController::class, 'create']);
    $router->put('/pages', [Hublog\Controllers\AdminPageController::class, 'update']);
    $router->delete('/pages', [Hublog\Controllers\AdminPageController::class, 'delete']);
    $router->post('/pages/publish', [Hublog\Controllers\AdminPageController::class, 'publish']);
    // V36 新增：定时自动备份（POST：防止 CSRF GET 静默触发，要求 secret ≥ 16 位）
    $router->post('/backup/auto', [Hublog\Controllers\AdminToolsController::class, 'autoBackup']);
    // V36 新增：邮件测试
    $router->post('/mail/test', [Hublog\Controllers\AdminToolsController::class, 'testMail']);
    // V37 新增：授权系统
    $router->get('/license', [Hublog\Controllers\AdminLicenseController::class, 'licenseStatus']);
    $router->post('/license/activate', [Hublog\Controllers\AdminLicenseController::class, 'licenseActivate']);
    $router->post('/license/deactivate', [Hublog\Controllers\AdminLicenseController::class, 'licenseDeactivate']);
    $router->post('/license/refresh-hashes', [Hublog\Controllers\AdminLicenseController::class, 'licenseRefreshHashes']);
    $router->post('/license/save-settings', [Hublog\Controllers\AdminLicenseController::class, 'licenseSaveSettings']);
    $router->post('/license/test-remote', [Hublog\Controllers\AdminLicenseController::class, 'licenseTestRemote']);
    $router->post('/license/sync-revoked', [Hublog\Controllers\AdminLicenseController::class, 'licenseSyncRevoked']);
    // V38 新增：私信管理
    $router->get('/chat/conversations', [Hublog\Controllers\AdminChatController::class, 'chatConversations']);
    $router->get('/chat/messages', [Hublog\Controllers\AdminChatController::class, 'chatMessages']);
    // V52：删除流水 + 恢复 + 取证导出
    $router->get('/chat/deletions', [Hublog\Controllers\AdminChatController::class, 'chatDeletions']);
    $router->post('/chat/restore', [Hublog\Controllers\AdminChatController::class, 'chatRestoreMessage']);
    $router->get('/chat/export', [Hublog\Controllers\AdminChatController::class, 'chatExport']);
    $router->delete('/chat/message', [Hublog\Controllers\AdminChatController::class, 'chatDeleteMessage']);
    $router->post('/chat/batch-delete', [Hublog\Controllers\AdminChatController::class, 'chatBatchDelete']);
    $router->post('/chat/cleanup-expired', [Hublog\Controllers\AdminChatController::class, 'chatCleanupExpired']);
    $router->delete('/chat/conversation', [Hublog\Controllers\AdminChatController::class, 'chatDeleteConversation']);
    // V67：财务 / 会员管理（没有支付网关，充值入口就在这几个接口上）
    // 注意：本段已在 group('/api/admin') 内，路径只写 '/xxx' 不能再带 /admin 前缀，
    //      写了会注册成 /api/admin/admin/xxx 直接 404（踩过一次）。
    $router->get('/wallet-logs', [Hublog\Controllers\AdminFinanceController::class, 'walletLogs']);
    $router->post('/wallet-adjust', [Hublog\Controllers\AdminFinanceController::class, 'walletAdjust']);
    $router->get('/vip-orders', [Hublog\Controllers\AdminFinanceController::class, 'vipOrders']);
    $router->post('/grant-vip', [Hublog\Controllers\AdminFinanceController::class, 'grantVip']);
    // V97：内容付费后台管理（定价 / 专栏 / 条目 / 订单 / 赠送权益）
    $router->get('/content-pay/orders', [Hublog\Controllers\AdminContentPayController::class, 'orders']);
    $router->get('/content-pay/columns', [Hublog\Controllers\AdminContentPayController::class, 'columns']);
    $router->post('/content-pay/product', [Hublog\Controllers\AdminContentPayController::class, 'saveProduct']);
    $router->post('/content-pay/column', [Hublog\Controllers\AdminContentPayController::class, 'saveColumn']);
    $router->post('/content-pay/item', [Hublog\Controllers\AdminContentPayController::class, 'saveItem']);
    $router->delete('/content-pay/item', [Hublog\Controllers\AdminContentPayController::class, 'deleteItem']);
    $router->post('/content-pay/grant', [Hublog\Controllers\AdminContentPayController::class, 'grant']);
    // V68：积分管理（流水 / 手动加减 / 任务配置）
    $router->get('/points-logs', [Hublog\Controllers\AdminPointsController::class, 'pointsLogs']);
    $router->post('/points-adjust', [Hublog\Controllers\AdminPointsController::class, 'pointsAdjust']);
    $router->get('/point-tasks', [Hublog\Controllers\AdminPointsController::class, 'pointTasks']);
    $router->post('/point-task-save', [Hublog\Controllers\AdminPointsController::class, 'pointTaskSave']);
    $router->post('/point-task-delete', [Hublog\Controllers\AdminPointsController::class, 'pointTaskDelete']);
    // V96：商城后台管理（商品 / 订单发货 / 圈友闲置由商品表统一承载）
    $router->get('/shop-products', [Hublog\Controllers\ShopController::class, 'adminProducts']);
    $router->post('/shop-product-save', [Hublog\Controllers\ShopController::class, 'adminProductSave']);
    $router->put('/shop-product-save', [Hublog\Controllers\ShopController::class, 'adminProductSave']);
    $router->post('/shop-product-delete', [Hublog\Controllers\ShopController::class, 'adminProductDelete']);
    $router->get('/shop-orders', [Hublog\Controllers\ShopController::class, 'adminOrders']);
    $router->post('/shop-ship', [Hublog\Controllers\ShopController::class, 'adminShip']);
    // V96：积分大转盘后台管理（奖品配置）
    $router->get('/wheel-prizes', [Hublog\Controllers\ShopController::class, 'adminWheelPrizes']);
    $router->post('/wheel-prize-save', [Hublog\Controllers\ShopController::class, 'adminWheelPrizeSave']);
    $router->put('/wheel-prize-save', [Hublog\Controllers\ShopController::class, 'adminWheelPrizeSave']);
    $router->post('/wheel-prize-delete', [Hublog\Controllers\ShopController::class, 'adminWheelPrizeDelete']);
    // V69/V96：在线充值订单查看（后台财务）
    $router->get('/recharge-orders', [Hublog\Controllers\PaymentController::class, 'adminRechargeOrders']);
    $router->get('/chat/storage-stats', [Hublog\Controllers\AdminChatController::class, 'chatStorageStats']);
    $router->post('/chat/cleanup-orphan', [Hublog\Controllers\AdminChatController::class, 'chatCleanupOrphan']);
    $router->get('/chat/settings', [Hublog\Controllers\AdminChatController::class, 'chatGetSettings']);
    $router->put('/chat/settings', [Hublog\Controllers\AdminChatController::class, 'chatSaveSettings']);
    // V39 新增：清理动态孤儿文件（uploads.post_id IS NULL 且超过 N 小时未发布绑定）
    $router->post('/posts/cleanup-orphan-uploads', [Hublog\Controllers\PostController::class, 'cleanupOrphanUploads']);
    // V71 新增：内容治理 —— 敏感词库 CRUD + 命中日志
    $router->get('/sensitive-words', [Hublog\Controllers\AdminModerationController::class, 'sensitiveWords']);
    $router->post('/sensitive-words', [Hublog\Controllers\AdminModerationController::class, 'saveSensitiveWord']);
    $router->put('/sensitive-words', [Hublog\Controllers\AdminModerationController::class, 'saveSensitiveWord']);
    $router->delete('/sensitive-words', [Hublog\Controllers\AdminModerationController::class, 'deleteSensitiveWord']);
    $router->post('/sensitive-words/import', [Hublog\Controllers\AdminModerationController::class, 'importSensitiveWords']);
    $router->get('/filter-logs', [Hublog\Controllers\AdminModerationController::class, 'filterLogs']);
    // V71 新增：违规处理 —— 全站禁言 / 圈子限流下架
    $router->post('/users/mute', [Hublog\Controllers\AdminModerationController::class, 'muteUser']);
    $router->post('/users/unmute', [Hublog\Controllers\AdminModerationController::class, 'unmuteUser']);
    $router->post('/circles/moderate', [Hublog\Controllers\AdminCircleController::class, 'circleModerate']);
    // V82 徽章系统：徽章配置 CRUD / 用户徽章授予冻结回收 / 操作日志 / 手动扫描
    $router->get('/badges', [Hublog\Controllers\BadgeController::class, 'adminList']);
    $router->post('/badge-save', [Hublog\Controllers\BadgeController::class, 'adminSave']);
    $router->put('/badge-save', [Hublog\Controllers\BadgeController::class, 'adminSave']);
    $router->post('/badge-toggle', [Hublog\Controllers\BadgeController::class, 'adminToggle']);
    $router->get('/user-badges', [Hublog\Controllers\BadgeController::class, 'adminUserBadges']);
    $router->post('/user-badge-grant', [Hublog\Controllers\BadgeController::class, 'adminGrant']);
    $router->post('/user-badge-freeze', [Hublog\Controllers\BadgeController::class, 'adminFreeze']);
    $router->post('/user-badge-revoke', [Hublog\Controllers\BadgeController::class, 'adminRevoke']);
    $router->get('/badge-logs', [Hublog\Controllers\BadgeController::class, 'adminLogs']);
    $router->post('/badge-scan', [Hublog\Controllers\BadgeController::class, 'adminScan']);
    // V83 神评标记台账：查询 + 撤销误标（撤标后自动重算该作者徽章）
    $router->get('/badge-god-marks', [Hublog\Controllers\BadgeController::class, 'adminGodMarks']);
    $router->delete('/badge-god-marks', [Hublog\Controllers\BadgeController::class, 'adminDeleteGodMark']);
    // V97：客户端异常收集（error/api_error 落库后的后台检索页）
    $router->get('/crash-logs', [Hublog\Controllers\AdminCrashController::class, 'crashLogs']);
    $router->put('/crash-alert-threshold', [Hublog\Controllers\AdminCrashController::class, 'crashAlertThreshold']);
    // 用户申诉管理（App 我的-设置-我的申诉记录 提交的申诉，后台审核处理）
    $router->get('/appeals', [Hublog\Controllers\UserController::class, 'adminAppeals']);
    $router->post('/appeal-handle', [Hublog\Controllers\UserController::class, 'adminHandleAppeal']);
    // V143：会员折扣卡发放记录
    $router->get('/shop-coupons', [Hublog\Controllers\ShopController::class, 'adminCoupons']);

    // 应用中心 · 站点端 agent（连接唯一中心：市场/授权/在线安装/校验）
    $router->get('/appcenter/config', [Hublog\Controllers\AdminAppCenterController::class, 'config']);
    $router->post('/appcenter/config', [Hublog\Controllers\AdminAppCenterController::class, 'saveConfig']);
    $router->post('/appcenter/register', [Hublog\Controllers\AdminAppCenterController::class, 'register']);
    $router->get('/appcenter/ping', [Hublog\Controllers\AdminAppCenterController::class, 'ping']);
    $router->get('/appcenter/apps', [Hublog\Controllers\AdminAppCenterController::class, 'apps']);
    $router->get('/appcenter/app', [Hublog\Controllers\AdminAppCenterController::class, 'appDetail']);
    $router->post('/appcenter/activate', [Hublog\Controllers\AdminAppCenterController::class, 'activate']);
    $router->get('/appcenter/licenses', [Hublog\Controllers\AdminAppCenterController::class, 'licenses']);
    $router->post('/appcenter/install', [Hublog\Controllers\AdminAppCenterController::class, 'install']);
    $router->get('/appcenter/installed', [Hublog\Controllers\AdminAppCenterController::class, 'installed']);
    $router->post('/appcenter/check-updates', [Hublog\Controllers\AdminAppCenterController::class, 'checkUpdates']);
    $router->post('/appcenter/uninstall', [Hublog\Controllers\AdminAppCenterController::class, 'uninstall']);
    $router->post('/appcenter/baseline', [Hublog\Controllers\AdminAppCenterController::class, 'baseline']);
    $router->get('/appcenter/verify', [Hublog\Controllers\AdminAppCenterController::class, 'verify']);
    $router->get('/appcenter/baselines', [Hublog\Controllers\AdminAppCenterController::class, 'baselineList']);
    $router->post('/appcenter/baseline/delete', [Hublog\Controllers\AdminAppCenterController::class, 'deleteBaseline']);
}, ['RateLimitMiddleware', 'AuthMiddleware', 'CsrfMiddleware', 'AdminMiddleware', 'LicenseMiddleware']);
