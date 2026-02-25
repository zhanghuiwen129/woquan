<?php /*a:1:{s:39:"D:\wwwroot\view\index\index_mobile.html";i:1771394375;}*/ ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlentities((string) $name); ?> - <?php echo htmlentities((string) $subtitle); ?></title>
    <?php if(isset($config['icon']) && $config['icon'] != ''): ?>
    <link rel="icon" href="<?php echo htmlentities((string) $config['icon']); ?>" type="image/x-icon" />
    <link rel="shortcut icon" href="<?php echo htmlentities((string) $config['icon']); ?>" />
    <?php else: ?>
    <link rel="icon" href="/favicon.ico" type="image/x-icon" />
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com?hide-warning=true"></script>
    <!-- 使用本地 FontAwesome 图标库 -->
    <link rel="stylesheet" href="/fontawesome/css/font-awesome.min.css">
    <!-- 表情选择器样式 -->
    <link rel="stylesheet" href="/static/css/emoji-picker.css">
    <!-- 首页样式 -->
    <link rel="stylesheet" href="/static/css/home.css">
    <!-- App 风格移动端样式 -->
    <link rel="stylesheet" href="/static/css/mobile-app.css">
    <!-- 网站配置 -->
    <script src="/static/js/site-config.js"></script>
    <!-- JavaScript错误日志 -->
    <script src="/static/js/error-logger.js"></script>
    <!-- 表情选择器 -->
    <script src="/static/js/emoji-picker.js"></script>
    <!-- 抖音式评论组件 -->
    <link rel="stylesheet" href="/static/css/comment.css?v=2026012807">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        // 日间模式配色 - 抖音红色系
                        primary: '#FE2C55',           // 抖音红（主色）
                        secondary: '#FFEFF4',         // 抖音红浅背景（辅助色1）
                        darkPrimary: '#D81F42',       // 抖音红深色（辅助色2）
                        lightBlue: '#FFEFF4',         // 浅红背景
                        danger: '#F04848',            // 错误色
                        success: '#52C480',           // 成功色
                        warning: '#F5A623',           // 提示色

                        // 背景和文字色
                        'bg-main': '#FFFFFF',         // 主背景
                        'bg-card': '#F5F5F5',         // 卡片背景
                        'text-primary': '#333333',    // 一级文字（深灰）
                        'text-secondary': '#666666',  // 二级文字（中灰）
                        'text-tertiary': '#999999',   // 三级文字（浅灰）
                        'border-color': '#E5E5E5',    // 分割线/边框

                        // 暗黑模式配色
                        'dark-bg-main': '#18191C',
                        'dark-bg-card': '#25262B',
                        'dark-text-primary': '#F5F6F7',
                        'dark-text-secondary': '#C9CDD4',
                        'dark-text-tertiary': '#868B94',
                        'dark-border-color': '#323338',
                    },
                    fontFamily: {
                        inter: ['Inter', 'system-ui', 'sans-serif'],
                    },
                    boxShadow: {
                        'soft': '0 2px 8px rgba(0, 0, 0, 0.06)',
                        'hover': '0 4px 12px rgba(254, 44, 85, 0.15)',
                    }
                },
            }
        }
    </script>
    <style type="text/tailwindcss">
        @layer utilities {
            .circle-avatar {
                @apply rounded-full object-cover border-2 border-white shadow-sm;
            }
            .dynamic-card {
                @apply w-full bg-bg-card rounded-xl shadow-soft overflow-hidden transition-all duration-200 hover:shadow-hover;
            }
            .nav-active {
                @apply text-primary font-medium;
            }
            .grid-images-2 {
                @apply grid grid-cols-2 gap-1;
            }
            .grid-images-3 {
                @apply grid grid-cols-3 gap-1;
            }
            .grid-images-4 {
                @apply grid grid-cols-2 gap-1;
            }
            .grid-images-more {
                @apply grid grid-cols-3 gap-1;
            }
            .line-clamp-2 {
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
        }
    </style>
    <style>
        /* 移动端样式 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Helvetica Neue", "PingFang SC", "Microsoft YaHei", Arial, sans-serif; line-height: 1.6; }
        html, body { overflow-x: hidden; overflow-y: auto; }
        .moment-content .topic { color: #1677ff; text-decoration: none; }

        /* Contenteditable 元素 placeholder 样式 */
        [contenteditable="true"][placeholder]:empty:before {
            content: attr(placeholder);
            color: var(--app-text-secondary);
            pointer-events: none;
            display: block;
        }

        /* App 风格补充样式 */
        .app-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .app-btn-primary {
            background: linear-gradient(135deg, #FE2C55 0%, #D81F42 100%);
            color: white;
            box-shadow: 0 4px 16px rgba(254, 44, 85, 0.3);
        }

        .app-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(254, 44, 85, 0.4);
        }

        .app-btn-primary:active {
            transform: scale(0.98);
        }

        .app-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .app-card:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        /* 加载动画 */
        .app-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 0;
        }

        .app-loading .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(254, 44, 85, 0.1);
            border-top-color: #FE2C55;
            border-radius: 50%;
            animation: app-spin 0.8s linear infinite;
        }

        @keyframes app-spin {
            to { transform: rotate(360deg); }
        }

        /* contenteditable 中的表情图片样式 */
        .inline-emoji {
            display: inline-block;
            width: 24px;
            height: 24px;
            vertical-align: middle;
            cursor: pointer;
        }

        /* 暗黑模式样式 */
        .dark body {
            background-color: var(--dark-bg-main);
            color: var(--dark-text-primary);
        }
        .dark .bg-white,
        .dark .bg-gray-50 {
            background-color: var(--dark-bg-card);
        }
        .dark .text-gray-600,
        .dark .text-gray-500,
        .dark .text-gray-700 {
            color: var(--dark-text-secondary);
        }
        .dark .text-gray-400 {
            color: var(--dark-text-tertiary);
        }
        .dark .border-gray-200,
        .dark .border-gray-100 {
            border-color: var(--dark-border-color);
        }
        .dark .shadow-sm,
        .dark .shadow-md {
            box-shadow: none;
        }

        /* 评论相关样式 */
        .comment-item {
            transition: background-color 0.2s;
        }
        .comment-item:not(.reply-item):hover {
            background-color: rgba(254, 44, 85, 0.05);
        }
        .emoji-item {
            transition: background-color 0.2s;
        }
        .emoji-item:hover {
            background-color: rgba(254, 44, 85, 0.1);
            border-radius: 4px;
        }
        .comment-menu {
            animation: fadeIn 0.2s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* 点赞动画 */
        .likeBtn {
            transition: all 0.2s;
        }
        .likeBtn:active {
            transform: scale(0.95);
        }
        .likeBtn .fa-heart {
            animation: heartBeat 0.3s ease-in-out;
        }
        @keyframes heartBeat {
            0% { transform: scale(1); }
            50% { transform: scale(1.3); }
            100% { transform: scale(1); }
        }

        /* 滚动条美化 */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        .dark ::-webkit-scrollbar-track {
            background: #25262B;
        }
        .dark ::-webkit-scrollbar-thumb {
            background: #323338;
        }
        .dark ::-webkit-scrollbar-thumb:hover {
            background: #404146;
        }
    </style>
</head>
<body class="font-inter bg-bg-main text-text-primary min-h-screen transition-colors duration-300">
    <!-- 移动端顶部导航（App 风格） -->
    <header class="sticky top-0 z-50 flex items-center justify-between px-4" style="padding-top: calc(0.5rem + var(--safe-area-top)); padding-bottom: 0.5rem;">
        <div class="flex items-center gap-3">
            <?php if(isset($config['logo']) && $config['logo'] != ''): ?>
            <img src="<?php echo htmlentities((string) $config['logo']); ?>" alt="<?php echo htmlentities((string) $name); ?>" class="h-10 w-auto object-contain" style="max-height: 40px;">
            <?php endif; ?>
            <div class="leading-tight">
                <a href="/" class="text-white font-bold text-base tracking-wide"><?php echo htmlentities((string) $name); ?></a>
                <div class="site-subtitle text-white/90 text-[10px]"><?php echo htmlentities((string) $subtitle); ?></div>
            </div>
        </div>
        <div class="flex-1 max-w-[200px] mx-4 relative">
            <input
                type="text"
                id="mobile-search-input"
                placeholder="搜索..."
                class="w-full py-2.5 pl-10 pr-4 rounded-2xl bg-white/95 text-sm placeholder-gray-400 focus:outline-none"
                onfocus="showMobileSearch()"
            >
            <i class="fa fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
        </div>
        <?php if(isset($isLogin) && $isLogin): ?>
        <a href="/profile" class="mobile-profile-link">
            <img src="<?php echo $currentUser['avatar'] ?: '/static/images/default-avatar.png'; ?>" alt="我的头像" style="width:32px;height:32px;border-radius:50%;border:2px solid #fff;object-fit:cover;background:#f0f0f0;"
                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzIiIHZpZXdCb3g9IjAgMCAzMiAzMiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48Y2lyY2xlIGN4PSIxNiIgY3k9IjE2IiByPSIxNiIgZmlsbD0iI0VFMiMzNSIvPjxyZWN0IHg9IjEwIiB5PSIyMCIgd2lkdGg9IjEyIiBoZWlnaHQ9IjgiIHJ4PSI0IiBmaWxsPSIjNkY0QjdDIi8+PC9zdmc+'">
        </a>
        <?php else: ?>
        <a href="/login" class="mobile-profile-link">
            <img src="/static/images/default-avatar.png" alt="我的头像" style="width:32px;height:32px;border-radius:50%;border:2px solid #fff;object-fit:cover;background:#f0f0f0;"
                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzIiIHZpZXdCb3g9IjAgMCAzMiAzMiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48Y2lyY2xlIGN4PSIxNiIgY3k9IjE2IiByPSIxNiIgZmlsbD0iI0VFMiMzNSIvPjxyZWN0IHg9IjEwIiB5PSIyMCIgd2lkdGg9IjEyIiBoZWlnaHQ9IjgiIHJ4PSI0IiBmaWxsPSIjNkY0QjdDIi8+PC9zdmc+'">
        </a>
        <?php endif; ?>
        <!-- 存储当前用户信息到JavaScript变量 -->
        <script>
            <?php if(isset($isLogin) && $isLogin && $currentUser): ?>
            window.isLogin = true;
            window.currentUserInfo = {
                id: <?php echo $currentUser['id']; ?>,
                userId: <?php echo $currentUser['id']; ?>,
                nickname: <?php echo json_encode($currentUser['nickname']); ?>,
                avatar: <?php echo json_encode($currentUser['avatar'] ?: '/static/images/default-avatar.png'); ?>
            };
            <?php else: ?>
            window.isLogin = false;
            window.currentUserInfo = null;
            <?php endif; ?>
        </script>
    </header>

    <!-- 主页内容区 -->
    <main class="container mx-auto px-4 py-4 pb-20">
        <!-- 动态流筛选栏（App 风格分段控制器） -->
        <div class="bg-bg-card rounded-2xl shadow-sm p-2 mb-4">
            <div class="flex gap-2">
                <button onclick="switchFeedType('recommend')" id="feed-recommend" class="flex-1 py-2.5 px-3 rounded-xl font-bold text-base transition-all duration-300">
                    <i class="fa fa-fire mr-1"></i>推荐
                </button>
                <button onclick="switchFeedType('following')" id="feed-following" class="flex-1 py-2.5 px-3 rounded-xl font-bold text-base transition-all duration-300">
                    <i class="fa fa-users mr-1"></i>关注
                </button>
                <button onclick="switchSortType('latest')" id="sort-latest" class="py-2.5 px-4 rounded-lg text-xs font-semibold transition-all duration-300">
                    最新
                </button>
                <button onclick="switchSortType('hot')" id="sort-hot" class="py-2.5 px-4 rounded-lg text-xs font-semibold transition-all duration-300">
                    最热
                </button>
            </div>
        </div>

        <!-- 动态列表 -->
        <div id="moments-list" class="space-y-4">
            <!-- 动态内容由JS动态加载 -->
        </div>

        <!-- 加载更多提示 -->
        <div id="loading-more" class="text-center py-6 text-text-tertiary hidden">
            <i class="fa fa-spinner fa-spin text-lg"></i>
            <p class="text-sm mt-2">加载更多...</p>
        </div>
    </main>

    <!-- 回到顶部按钮 -->
    <button id="back-to-top" onclick="scrollToTop()" class="fixed bottom-24 right-4 w-12 h-12 bg-bg-card shadow-lg rounded-full flex items-center justify-center text-text-secondary hover:text-primary hover:shadow-hover transition-all duration-300 z-40 hidden border border-border-color">
        <i class="fa fa-arrow-up"></i>
    </button>

    <!-- 主题切换按钮 -->
    <button id="theme-toggle" onclick="toggleTheme()" class="fixed bottom-24 left-4 w-12 h-12 bg-bg-card shadow-lg rounded-full flex items-center justify-center text-text-secondary hover:text-primary hover:shadow-hover transition-all duration-300 z-40 hidden border border-border-color">
        <i class="fa fa-moon-o" id="theme-icon"></i>
    </button>

    <!-- 手机端底部导航栏（App 风格） -->
    <nav class="fixed bottom-0 left-0 right-0 z-50">
        <div class="flex justify-around items-center py-2 pb-[calc(0.5rem+var(--safe-area-bottom))]">
            <a href="/" class="flex flex-col items-center gap-1 nav-active flex-1 py-2">
                <div class="relative">
                    <i class="fa fa-home text-xl"></i>
                    <div class="active-indicator"></div>
                </div>
                <span class="text-xs font-medium">首页</span>
            </a>
            <a href="/discover" class="flex flex-col items-center gap-1 text-text-secondary hover:text-primary transition-colors flex-1 py-2">
                <div class="relative">
                    <i class="fa fa-search text-xl"></i>
                    <div class="active-indicator"></div>
                </div>
                <span class="text-xs font-medium">发现</span>
            </a>
            <!-- 移动端发布按钮（中间凸起） -->
            <button onclick="openPublishModal()" class="flex flex-col items-center justify-center text-white bg-gradient-to-br from-primary via-primary to-darkPrimary rounded-full w-16 h-16 -mt-8 shadow-2xl border-4 border-bg-card relative flex-shrink-0">
                <i class="fa fa-pencil text-2xl"></i>
            </button>
            <a href="javascript:void(0)" id="mobile-menu-btn" class="flex flex-col items-center gap-1 text-text-secondary hover:text-primary transition-colors relative flex-1 py-2">
                <div class="relative">
                    <i class="fa fa-th-large text-xl"></i>
                    <!-- 消息数量角标 -->
                    <span id="mobile-menu-badge" class="absolute -top-1 -right-2 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center hidden min-w-[20px] min-h-[20px] font-semibold shadow-md">0</span>
                    <div class="active-indicator"></div>
                </div>
                <span class="text-xs font-medium">更多</span>
            </a>
            <a href="javascript:void(0)" id="mobile-profile-btn" class="flex flex-col items-center gap-1 text-text-secondary hover:text-primary transition-colors flex-1 py-2">
                <div class="relative">
                    <i class="fa fa-user text-xl"></i>
                    <div class="active-indicator"></div>
                </div>
                <span class="text-xs font-medium">我的</span>
            </a>
        </div>
    </nav>

    <style>
        /* 导航栏激活指示器 */
        .active-indicator {
            position: absolute;
            bottom: -8px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            background: var(--app-primary);
            border-radius: 50%;
            opacity: 0;
            transition: all 0.3s ease;
        }

        .nav-active .active-indicator {
            opacity: 1;
        }

        .nav-active {
            color: var(--app-primary);
        }

        .nav-active span {
            font-weight: 600;
        }
    </style>

    <!-- 移动端更多菜单弹窗（App 风格底部抽屉） -->
    <div id="mobile-menu-modal" class="fixed inset-0 z-[1000] hidden">
        <div id="mobile-menu-mask" class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
        <div class="absolute bottom-0 left-0 right-0 bg-bg-card rounded-t-3xl p-6 pb-[calc(2rem+var(--safe-area-bottom))] transform transition-transform" style="box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.15);">
            <!-- 顶部拖拽指示器 -->
            <div class="w-12 h-1.5 bg-gray-300 rounded-full mx-auto mb-4"></div>

            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-text-primary">更多功能</h3>
                <button id="close-mobile-menu-btn" class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center text-text-secondary hover:text-text-primary hover:bg-gray-200 transition-all active:scale-95">
                    <i class="fa fa-times text-lg"></i>
                </button>
            </div>

            <div class="grid grid-cols-4 gap-4">
                <!-- 热门话题 -->
                <a href="/discover" class="flex flex-col items-center gap-3 py-3 active:scale-95 transition-transform">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white shadow-lg">
                        <i class="fa fa-fire text-2xl"></i>
                    </div>
                    <span class="text-xs font-medium text-text-primary">热门话题</span>
                </a>

                <!-- 活动中心 -->
                <a href="/activities" class="flex flex-col items-center gap-3 py-3 active:scale-95 transition-transform">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 flex items-center justify-center text-white shadow-lg">
                        <i class="fa fa-calendar text-2xl"></i>
                    </div>
                    <span class="text-xs font-medium text-text-primary">活动中心</span>
                </a>

                <!-- 消息通知 -->
                <a href="/message" class="flex flex-col items-center gap-3 py-3 relative active:scale-95 transition-transform">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-purple-500 to-purple-600 flex items-center justify-center text-white shadow-lg">
                        <i class="fa fa-envelope text-2xl"></i>
                    </div>
                    <span class="text-xs font-medium text-text-primary">消息通知</span>
                    <span id="mobile-menu-unread" class="absolute top-2 right-3 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center hidden min-w-[20px] min-h-[20px] font-semibold shadow-md border-2 border-bg-card">0</span>
                </a>

                <!-- 我的收藏 -->
                <a href="/favorites" class="flex flex-col items-center gap-3 py-3 active:scale-95 transition-transform">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-yellow-500 to-yellow-600 flex items-center justify-center text-white shadow-lg">
                        <i class="fa fa-bookmark text-2xl"></i>
                    </div>
                    <span class="text-xs font-medium text-text-primary">我的收藏</span>
                </a>

                <!-- 我的草稿箱 -->
                <a href="/drafts" class="flex flex-col items-center gap-3 py-3 active:scale-95 transition-transform">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-green-500 to-green-600 flex items-center justify-center text-white shadow-lg">
                        <i class="fa fa-file-text text-2xl"></i>
                    </div>
                    <span class="text-xs font-medium text-text-primary">草稿箱</span>
                </a>

                <!-- 我的关注 -->
                <a href="/following" class="flex flex-col items-center gap-3 py-3 active:scale-95 transition-transform">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-cyan-500 to-cyan-600 flex items-center justify-center text-white shadow-lg">
                        <i class="fa fa-user-plus text-2xl"></i>
                    </div>
                    <span class="text-xs font-medium text-text-primary">我的关注</span>
                </a>

                <!-- 我的粉丝 -->
                <a href="/followers" class="flex flex-col items-center gap-3 py-3 active:scale-95 transition-transform">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-pink-500 to-pink-600 flex items-center justify-center text-white shadow-lg">
                        <i class="fa fa-users text-2xl"></i>
                    </div>
                    <span class="text-xs font-medium text-text-primary">我的粉丝</span>
                </a>

                <!-- 积分中心 -->
                <a href="/points" class="flex flex-col items-center gap-3 py-3 active:scale-95 transition-transform">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-red-500 to-red-600 flex items-center justify-center text-white shadow-lg">
                        <i class="fa fa-gift text-2xl"></i>
                    </div>
                    <span class="text-xs font-medium text-text-primary">积分中心</span>
                </a>

                <!-- 我的钱包 -->
                <a href="/wallet" class="flex flex-col items-center gap-3 py-3 active:scale-95 transition-transform">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-teal-500 to-teal-600 flex items-center justify-center text-white shadow-lg">
                        <i class="fa fa-wallet text-2xl"></i>
                    </div>
                    <span class="text-xs font-medium text-text-primary">我的钱包</span>
                </a>

                <!-- 等级中心 -->
                <a href="/level" class="flex flex-col items-center gap-3 py-3 active:scale-95 transition-transform">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-600 flex items-center justify-center text-white shadow-lg">
                        <i class="fa fa-star text-2xl"></i>
                    </div>
                    <span class="text-xs font-medium text-text-primary">等级中心</span>
                </a>

                <!-- 设置中心 -->
                <a href="/settings" class="flex flex-col items-center gap-3 py-3 active:scale-95 transition-transform">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-gray-500 to-gray-600 flex items-center justify-center text-white shadow-lg">
                        <i class="fa fa-cog text-2xl"></i>
                    </div>
                    <span class="text-xs font-medium text-text-primary">设置中心</span>
                </a>
            </div>
        </div>
    </div>

    <!-- 移动端底部占位 -->
    <div class="h-[calc(5rem+var(--safe-area-bottom))]"></div>

    <!-- 移动端发布弹窗（App 风格） -->
    <div id="mobile-modal-mask" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[1000] hidden"></div>
    <div id="mobile-publish-modal" class="fixed bottom-0 left-0 right-0 bg-bg-card rounded-t-3xl z-[1001] hidden transform transition-transform overflow-hidden flex flex-col" style="max-height: 85vh; box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.15);">
        <!-- 顶部拖拽指示器 -->
        <div class="w-12 h-1.5 bg-gray-300 rounded-full mx-auto mt-3 flex-shrink-0"></div>

        <div id="mobile-publish-content" class="p-5 flex-1 overflow-y-auto transition-transform duration-300">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-text-primary">发布动态</h3>
                <button id="close-modal-btn" class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center text-text-secondary hover:text-text-primary hover:bg-gray-200 transition-all active:scale-95">
                    <i class="fa fa-times text-lg"></i>
                </button>
            </div>
            <div id="mobile-publish-input" contenteditable="true" placeholder="分享新鲜事..." class="w-full border-2 border-gray-200 bg-white rounded-2xl p-4 resize-none focus:outline-none focus:border-primary text-text-primary placeholder-text-tertiary min-h-[100px] max-h-[180px] overflow-y-auto outline-none leading-relaxed" style="min-height: 100px;"></div>

            <!-- 移动端图片预览区域 -->
            <div id="mobile-image-preview-container" class="mt-3 hidden">
                <div class="flex justify-between items-center mb-3">
                    <span class="text-xs font-medium text-gray-700">图片预览</span>
                    <button id="mobile-close-image-preview" class="text-gray-400 hover:text-gray-600 text-xs">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                <div id="mobile-image-preview-grid" class="grid grid-cols-3 gap-2 max-h-48 overflow-y-auto"></div>
            </div>

            <!-- 移动端视频预览区域 -->
            <div id="mobile-video-preview-container" class="mt-3 hidden">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-xs font-medium text-gray-700">视频预览</span>
                    <button id="mobile-close-video-preview" class="text-gray-400 hover:text-gray-600 text-xs">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                <div class="bg-gray-100 rounded-lg overflow-hidden">
                    <video id="mobile-video-preview" class="w-full max-h-48 object-contain" controls></video>
                </div>
            </div>

            <!-- 移动端隐私设置 -->
            <div class="flex items-center gap-2 mt-3">
                <i class="fa fa-lock text-text-tertiary text-sm"></i>
                <select id="mobile-privacy-select" class="bg-bg-card border border-border-color rounded-lg px-3 py-1 text-sm text-text-primary focus:outline-none focus:border-primary">
                    <option value="0">公开</option>
                    <option value="1">仅好友可见</option>
                    <option value="2">仅自己可见</option>
                </select>
            </div>

            <div class="flex justify-between items-center mt-3">
                <div class="flex gap-2">
                    <input type="file" id="mobile-image-upload" multiple accept="image/*" class="hidden">
                    <button id="mobile-image-btn" class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center text-gray-600 hover:bg-primary hover:text-white transition-colors">
                        <i class="fa fa-picture-o"></i>
                    </button>
                    <input type="file" id="mobile-video-upload" accept="video/*" class="hidden">
                    <button id="mobile-video-btn" class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center text-gray-600 hover:bg-primary hover:text-white transition-colors">
                        <i class="fa fa-video-camera"></i>
                    </button>
                    <button id="mobile-emoji-btn" class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center text-gray-600 hover:bg-primary hover:text-white transition-colors">
                        <i class="fa fa-smile-o"></i>
                    </button>
                    <button id="mobile-topic-btn" class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center text-gray-600 hover:bg-primary hover:text-white transition-colors">
                        <i class="fa fa-hashtag"></i>
                    </button>
                    <button id="mobile-mention-btn" class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center text-gray-600 hover:bg-primary hover:text-white transition-colors">
                        <i class="fa fa-at"></i>
                    </button>
                </div>
                <button id="mobile-publish-btn" class="bg-primary text-white px-5 py-2 rounded-full text-sm font-medium hover:bg-secondary transition-colors">
                    发布
                </button>
            </div>
        </div>

        <!-- 移动端表情选择器（内嵌到发布弹窗底部） -->
        <div id="mobile-emoji-picker-modal" class="bg-bg-card border-t border-border-color p-4 transform transition-transform duration-300 translate-y-full hidden flex-shrink-0">
            <div id="mobile-emoji-grid" class="grid grid-cols-7 gap-2 max-h-[40vh] overflow-y-auto">
                <!-- 表情内容由JS动态加载 -->
            </div>
        </div>

        <!-- 移动端话题选择器（内嵌到发布弹窗底部） -->
        <div id="mobile-topic-picker-modal" class="bg-bg-card border-t border-border-color p-4 transform transition-transform duration-300 translate-y-full hidden flex-shrink-0">
            <div class="flex justify-between items-center mb-3">
                <span class="font-medium text-text-primary">热门话题</span>
                <button id="close-topic-picker-btn" class="px-4 py-1.5 rounded-lg bg-primary text-white text-sm font-medium hover:bg-darkPrimary transition-colors">
                    完成
                </button>
            </div>
            <div id="mobile-topic-list" class="flex flex-wrap gap-2 max-h-[40vh] overflow-y-auto">
                <!-- 话题内容由JS动态加载 -->
            </div>
        </div>

        <!-- 移动端@好友选择器（内嵌到发布弹窗底部） -->
        <div id="mobile-mention-picker-modal" class="bg-bg-card border-t border-border-color transform transition-transform duration-300 translate-y-full hidden flex-shrink-0">
            <div class="flex justify-between items-center p-3 border-b border-border-color">
                <span class="font-medium text-text-primary">@好友</span>
                <button onclick="closeMobilePublishMentionPicker()" class="px-4 py-1.5 rounded-lg bg-primary text-white text-sm font-medium hover:bg-darkPrimary transition-colors">
                    完成
                </button>
            </div>
            <div class="p-3">
                <input type="text" id="mobile-mention-search" placeholder="搜索好友..." class="w-full px-3 py-2 border border-border-color bg-bg-card rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary" oninput="searchPublishMentions(this.value)">
            </div>
            <div id="mobile-mention-list" class="max-h-[40vh] overflow-y-auto">
                <!-- @好友列表由JS动态加载 -->
            </div>
        </div>
    </div>

    <!-- 引入首页JavaScript模块 -->
    <script type="module" src="/static/js/pages/image-viewer.js"></script>
    <script type="module" src="/static/js/pages/index.js"></script>
    <!-- Toast提示组件 -->
    <script src="/static/js/toast.js"></script>
    <!-- Modal弹窗组件 -->
    <script src="/static/js/modal.js"></script>
    <!-- 定位模块 -->
    <script src="/static/js/location.js"></script>
    <!-- 表情配置模块 -->
    <script src="/static/js/emoji-config.js"></script>
    <!-- 抖音风格视频播放器 -->
    <script src="/static/js/tiktok-video-player.js"></script>
    <!-- 评论组件 -->
    <script type="module" src="/static/js/comment.js?v=2026012801"></script>
    <!-- 用户卡片模块 -->
    <script type="module" src="/static/js/pages/user-card.js"></script>

    <!-- 评论组件配置 -->
    <script>
        window.commentConfig = {
            pageSize: 10
        };
    </script>

    <script>
        // 移动端更多菜单控制
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const mobileMenuModal = document.getElementById('mobile-menu-modal');
        const mobileMenuMask = document.getElementById('mobile-menu-mask');
        const closeMobileMenuBtn = document.getElementById('close-mobile-menu-btn');

        // 打开更多菜单
        mobileMenuBtn.addEventListener('click', function() {
            mobileMenuModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        });

        // 关闭更多菜单
        function closeMobileMenu() {
            mobileMenuModal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        closeMobileMenuBtn.addEventListener('click', closeMobileMenu);
        mobileMenuMask.addEventListener('click', closeMobileMenu);

        // 移动端表情和话题选择器控制
        document.addEventListener('DOMContentLoaded', function() {
            const closeEmojiPickerBtn = document.getElementById('close-emoji-picker-btn');
            const closeTopicPickerBtn = document.getElementById('close-topic-picker-btn');

            if (closeEmojiPickerBtn) {
                closeEmojiPickerBtn.addEventListener('click', function() {
                    if (window.closeMobileEmojiPicker) {
                        window.closeMobileEmojiPicker();
                    }
                });
            }

            if (closeTopicPickerBtn) {
                closeTopicPickerBtn.addEventListener('click', function() {
                    if (window.closeMobileTopicPicker) {
                        window.closeMobileTopicPicker();
                    }
                });
            }

            // 加载未读通知数量
            if (window.currentUserInfo && window.currentUserInfo.userId) {
                loadUnreadNotificationCount();
                // 每30秒刷新一次未读通知数量
                setInterval(loadUnreadNotificationCount, 30000);
                
                // 启动心跳检测
                if (typeof startHeartbeat === 'function') {
                    startHeartbeat();
                }
            }
        });

        // 加载未读通知数量
        async function loadUnreadNotificationCount() {
            try {
                const response = await fetch('/notifications/getUnreadCount', {
                    method: 'GET',
                    credentials: 'same-origin'
                });
                const result = await response.json();

                if (result.code === 200 && result.data) {
                    const totalUnread = result.data.total || 0;
                    const typeUnread = result.data.types || {};

                    // 更新移动端顶部通知badge
                    const mobileBadge = document.getElementById('mobile-notification-badge');
                    if (mobileBadge) {
                        if (totalUnread > 0) {
                            mobileBadge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                            mobileBadge.classList.remove('hidden');
                        } else {
                            mobileBadge.classList.add('hidden');
                        }
                    }

                    // 更新移动端底部导航"更多"的通知badge
                    const moreBadge = document.getElementById('mobile-menu-badge');
                    if (moreBadge) {
                        if (totalUnread > 0) {
                            moreBadge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                            moreBadge.classList.remove('hidden');
                        } else {
                            moreBadge.classList.add('hidden');
                        }
                    }

                    // 更新"更多"菜单中"消息通知"的未读数量
                    const menuUnreadBadge = document.getElementById('mobile-menu-unread');
                    if (menuUnreadBadge) {
                        if (totalUnread > 0) {
                            menuUnreadBadge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                            menuUnreadBadge.classList.remove('hidden');
                        } else {
                            menuUnreadBadge.classList.add('hidden');
                        }
                    }
                }
            } catch (error) {
                console.error('加载未读通知数量失败:', error);
            }
        }

        // 页面关闭时停止心跳检测
        window.addEventListener('beforeunload', function() {
            if (typeof stopHeartbeat === 'function') {
                stopHeartbeat();
            }
        });
    </script>

    <!-- 抖音式评论区 -->
    <div class="douyin-comment-mask"></div>
    <div class="douyin-comment-box">
        <div class="douyin-comment-header">
            <button class="douyin-expand-btn" id="douyin-expand-btn">
                <i class="fa fa-expand"></i>
            </button>
        </div>
        <div class="douyin-comment-list" id="douyin-comment-list"></div>
        <div id="douyin-comment-more"></div>
        <div class="douyin-comment-footer">
            <div class="douyin-input-wrap">
                <button class="douyin-cancel-reply" id="douyin-cancel-reply">取消回复</button>
                <div class="douyin-input-container">
                    <div class="douyin-input" id="douyin-input" contenteditable="true" placeholder="说点什么..." maxlength="500"></div>
                    <button class="douyin-emoji-btn" id="douyin-emoji-btn" title="表情">
                        <i class="fa fa-smile-o"></i>
                    </button>
                </div>
                <button class="douyin-send-btn" id="douyin-send-btn">发送</button>
            </div>
        </div>
    </div>

    <!-- 用户名片弹窗 -->
    <div id="userCardModal" class="fixed inset-0 z-[2000] hidden">
        <div id="userCardMask" class="absolute inset-0 bg-black/50"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-[90vw] max-w-[360px] bg-bg-card rounded-2xl shadow-2xl overflow-hidden">
            <!-- 头部背景 -->
            <div class="h-20 bg-gradient-to-r from-primary to-darkPrimary relative">
                <button onclick="closeUserCardModal()" class="absolute top-2 right-2 w-8 h-8 rounded-full bg-black/20 text-white hover:bg-black/30 transition-colors flex items-center justify-center">
                    <i class="fa fa-times"></i>
                </button>
            </div>

            <!-- 用户头像和信息 -->
            <div class="px-4 pb-4 -mt-10">
                <div class="relative">
                    <img id="userCardAvatar" src="/static/images/default-avatar.png" alt="用户头像"
                         class="w-20 h-20 rounded-full border-4 border-bg-card object-cover bg-gray-200"
                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iOTYiIGhlaWdodD0iOTYiIHZpZXdCb3g9IjAgMCA5NiA5NiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48Y2lyY2xlIGN4PSI0OCIgY3k9IjQ4IiByPSI0OCIgZmlsbD0iI0VFRTNDNSIvPjxyZWN0IHg9IjMwIiB5PSI2MCIgd2lkdGg9IjM2IiBoZWlnaHQ9IjI0IiByeD0iMTIiIGZpbGw9IiM3QjdBMkMiLz48L3N2Zz4='">
                </div>

                <div class="mt-3">
                    <h3 id="userCardNickname" class="text-lg font-bold text-text-primary text-center">加载中...</h3>
                    <p id="userCardUsername" class="text-sm text-text-secondary text-center mb-1">@username</p>
                    <p id="userCardBio" class="text-sm text-text-secondary text-center line-clamp-2 h-10">这个人很懒，什么都没留下</p>
                </div>

                <!-- 操作按钮 -->
                <div class="flex gap-3 mt-3">
                    <button id="userCardFollowBtn" class="flex-1 py-2.5 bg-primary hover:bg-darkPrimary text-white rounded-full font-medium transition-colors text-sm">
                        <i class="fa fa-plus mr-1"></i> 关注
                    </button>
                    <a id="userCardProfileBtn" href="#" class="flex-1 py-2.5 border border-border-color text-text-primary hover:bg-gray-100 rounded-full font-medium transition-colors text-center text-sm">
                        主页
                    </a>
                </div>

                <!-- 统计数据 -->
                <div class="flex justify-around mt-4 py-3 border-t border-border-color">
                    <a id="userCardFollowing" href="#" class="text-center cursor-pointer">
                        <div id="userCardFollowingCount" class="text-lg font-bold text-text-primary">0</div>
                        <div class="text-xs text-text-secondary">关注</div>
                    </a>
                    <a id="userCardFollowers" href="#" class="text-center cursor-pointer">
                        <div id="userCardFollowersCount" class="text-lg font-bold text-text-primary">0</div>
                        <div class="text-xs text-text-secondary">粉丝</div>
                    </a>
                    <a id="userCardMoments" href="#" class="text-center cursor-pointer">
                        <div id="userCardMomentsCount" class="text-lg font-bold text-text-primary">0</div>
                        <div class="text-xs text-text-secondary">动态</div>
                    </a>
                    <a id="userCardLikes" href="#" class="text-center cursor-pointer">
                        <div id="userCardLikesCount" class="text-lg font-bold text-text-primary">0</div>
                        <div class="text-xs text-text-secondary">赞</div>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 向后兼容的函数 -->
    <script>
        function showCardModal(userId) {
            if (window.showUserCardModal) {
                window.showUserCardModal(userId);
            }
        }

        function closeCardModal() {
            if (window.closeUserCardModal) {
                window.closeUserCardModal();
            }
        }

        function closeUserCardModal() {
            if (window.closeUserCardModal) {
                window.closeUserCardModal();
            }
        }
    </script>

    <style>
        /* 图片查看器样式 */
        .img-viewer {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.85);
            backdrop-filter: blur(8px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .viewer-img-box {
            max-width: 100%;
            max-height: 100%;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: grab;
            padding: 20px;
            box-sizing: border-box;
        }
        .viewer-img-box:active {
            cursor: grabbing;
        }
        .viewer-img {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 0 30px rgba(255,255,255,0.2);
            transition: transform 0.1s ease-out;
            display: block;
        }
        .viewer-tip {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255,255,255,0.6);
            font-size: 12px;
            text-align: center;
            pointer-events: none;
            padding: 0 20px;
            line-height: 1.5;
            z-index: 10000;
        }
    </style>

    <!-- 登录弹窗 -->
    <div id="loginModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-bg-card rounded-2xl shadow-xl w-full max-w-xs mx-4 overflow-hidden transform transition-all duration-300 scale-95 opacity-0" id="loginModalContent">
            <div class="p-5">
                <div class="flex justify-between items-start mb-4">
                    <div class="text-center flex-1">
                        <h2 class="text-xl font-bold text-text-primary mb-2">登录账号</h2>
                        <p class="text-sm text-text-secondary">欢迎回来，继续您的精彩体验</p>
                    </div>
                    <button onclick="closeModal('loginModal')" class="text-text-tertiary hover:text-text-primary transition-colors">
                        <i class="fa fa-times text-xl"></i>
                    </button>
                </div>
                
                <form id="loginForm" class="space-y-4">
                    <div>
                        <label for="login-username" class="block text-sm font-medium text-text-secondary mb-1">用户名</label>
                        <input type="text" id="login-username" name="username" required placeholder="请输入用户名" 
                               class="w-full px-4 py-3 bg-white border border-border-color rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-text-primary placeholder:text-text-tertiary">
                    </div>
                    
                    <div>
                        <label for="login-password" class="block text-sm font-medium text-text-secondary mb-1">密码</label>
                        <div class="relative">
                            <input type="password" id="login-password" name="password" required placeholder="请输入密码" 
                                   class="w-full px-4 py-3 bg-white border border-border-color rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-text-primary placeholder:text-text-tertiary">
                            <button type="button" onclick="togglePassword('login-password')" 
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-text-tertiary hover:text-text-primary">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input type="checkbox" id="login-remember" name="remember" class="w-4 h-4 text-primary border-border-color rounded focus:ring-primary/20">
                            <label for="login-remember" class="ml-2 text-sm text-text-secondary">记住我</label>
                        </div>
                        <a href="/forgot-password" class="text-sm text-primary hover:underline">忘记密码？</a>
                    </div>
                    
                    <button type="submit" id="loginSubmitBtn" 
                            class="w-full py-3 bg-primary hover:bg-darkPrimary text-white rounded-lg font-medium transition-colors">
                        登录
                    </button>
                </form>
                
                <div class="mt-6 text-center">
                    <p class="text-sm text-text-secondary">还没有账号？ <a href="javascript:void(0)" onclick="toggleModal('loginModal', 'registerModal')" class="text-primary hover:underline">立即注册</a></p>
                </div>
            </div>
        </div>
    </div>

    <!-- 注册弹窗 -->
    <div id="registerModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-bg-card rounded-2xl shadow-xl w-full max-w-xs mx-4 overflow-hidden transform transition-all duration-300 scale-95 opacity-0" id="registerModalContent">
            <div class="p-5">
                <div class="flex justify-between items-start mb-4">
                    <div class="text-center flex-1">
                        <h2 class="text-xl font-bold text-text-primary mb-2">注册账号</h2>
                        <p class="text-sm text-text-secondary">加入我们，开启全新体验</p>
                    </div>
                    <button onclick="closeModal('registerModal')" class="text-text-tertiary hover:text-text-primary transition-colors">
                        <i class="fa fa-times text-xl"></i>
                    </button>
                </div>
                
                <form id="registerForm" class="space-y-4">
                    <div>
                        <label for="register-username" class="block text-sm font-medium text-text-secondary mb-1">用户名</label>
                        <input type="text" id="register-username" name="username" required placeholder="请输入用户名" 
                               class="w-full px-4 py-3 bg-white border border-border-color rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-text-primary placeholder:text-text-tertiary">
                    </div>
                    
                    <div>
                        <label for="register-password" class="block text-sm font-medium text-text-secondary mb-1">密码</label>
                        <div class="relative">
                            <input type="password" id="register-password" name="password" required placeholder="请输入密码" 
                                   class="w-full px-4 py-3 bg-white border border-border-color rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-text-primary placeholder:text-text-tertiary">
                            <button type="button" onclick="togglePassword('register-password')" 
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-text-tertiary hover:text-text-primary">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div>
                        <label for="register-confirm-password" class="block text-sm font-medium text-text-secondary mb-1">确认密码</label>
                        <div class="relative">
                            <input type="password" id="register-confirm-password" name="confirm_password" required placeholder="请再次输入密码" 
                                   class="w-full px-4 py-3 bg-white border border-border-color rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-text-primary placeholder:text-text-tertiary">
                            <button type="button" onclick="togglePassword('register-confirm-password')" 
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-text-tertiary hover:text-text-primary">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div>
                        <label for="register-nickname" class="block text-sm font-medium text-text-secondary mb-1">昵称</label>
                        <input type="text" id="register-nickname" name="nickname" required placeholder="请输入昵称" 
                               class="w-full px-4 py-3 bg-white border border-border-color rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-text-primary placeholder:text-text-tertiary">
                    </div>
                    
                    <?php if((isset($config['register_phone_verify']) && $config['register_phone_verify'] == 1) || (!isset($config['register_phone_verify']))): ?>
                    <div>
                        <label for="register-phone" class="block text-sm font-medium text-text-secondary mb-1">手机号</label>
                        <input type="tel" id="register-phone" name="phone" required placeholder="请输入手机号" 
                               class="w-full px-4 py-3 bg-white border border-border-color rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-text-primary placeholder:text-text-tertiary">
                    </div>
                    <?php endif; if((isset($config['register_sms_verify']) && $config['register_sms_verify'] == 1) || (!isset($config['register_sms_verify']))): ?>
                    <div class="flex gap-3">
                        <div class="flex-1">
                            <label for="register-code" class="block text-sm font-medium text-text-secondary mb-1">验证码</label>
                            <input type="text" id="register-code" name="code" required placeholder="请输入验证码" 
                                   class="w-full px-4 py-3 bg-white border border-border-color rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-text-primary placeholder:text-text-tertiary">
                        </div>
                        <div class="w-28 flex-shrink-0">
                            <label class="block text-sm font-medium text-text-secondary mb-1 invisible">获取验证码</label>
                            <button type="button" id="getCodeBtn" onclick="getSmsCode()" 
                                    class="w-full px-2 py-3 bg-white border border-primary text-primary rounded-lg hover:bg-primary hover:text-white transition-colors text-xs font-medium">
                                获取验证码
                            </button>
                        </div>
                    </div>
                    <?php endif; if(isset($config['register_captcha_verify']) && $config['register_captcha_verify'] == 1): ?>
                    <div class="flex gap-2">
                        <div class="flex-1">
                            <label for="register-captcha" class="block text-sm font-medium text-text-secondary mb-1">图形验证码</label>
                            <input type="text" id="register-captcha" name="captcha" required placeholder="请输入图形验证码" 
                                   class="w-full px-3 py-2.5 bg-white border border-border-color rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-text-primary placeholder:text-text-tertiary text-sm">
                        </div>
                        <div class="w-24 flex-shrink-0">
                            <label class="block text-sm font-medium text-text-secondary mb-1 invisible">图形验证码</label>
                            <div class="relative">
                                <img src="/user/captcha" alt="图形验证码" onclick="this.src='/user/captcha?'+Math.random()" 
                                     class="w-full h-10 object-cover rounded-lg border border-border-color cursor-pointer hover:opacity-90 transition-opacity">
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" id="registerSubmitBtn" 
                            class="w-full py-3 bg-primary hover:bg-darkPrimary text-white rounded-lg font-medium transition-colors">
                        注册
                    </button>
                </form>
                
                <div class="mt-6 text-center">
                    <p class="text-sm text-text-secondary">已有账号？ <a href="javascript:void(0)" onclick="toggleModal('registerModal', 'loginModal')" class="text-primary hover:underline">立即登录</a></p>
                </div>
            </div>
        </div>
    </div>

    <!-- 图片查看器 -->
    <div class="img-viewer" id="imgViewer">
        <div class="viewer-img-box" id="imgBox">
            <img src="" alt="大图" class="viewer-img" id="bigImg">
        </div>
        <div class="viewer-tip">
            滚轮缩放 | 按住左右拖翻页 | 单击图片关闭 | ESC关闭 | ←→切换 | +-缩放
        </div>
    </div>

    <script>
        // 弹窗控制函数
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            const modalContent = document.getElementById(modalId + 'Content');
            if (modal && modalContent) {
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
                
                // 动画效果
                setTimeout(() => {
                    modalContent.classList.remove('scale-95', 'opacity-0');
                    modalContent.classList.add('scale-100', 'opacity-100');
                }, 10);
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            const modalContent = document.getElementById(modalId + 'Content');
            if (modal && modalContent) {
                modalContent.classList.remove('scale-100', 'opacity-100');
                modalContent.classList.add('scale-95', 'opacity-0');
                
                setTimeout(() => {
                    modal.classList.add('hidden');
                    document.body.style.overflow = '';
                }, 300);
            }
        }

        function toggleModal(currentModalId, targetModalId) {
            closeModal(currentModalId);
            setTimeout(() => {
                openModal(targetModalId);
            }, 300);
        }

        // 密码显示/隐藏切换
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // 获取短信验证码
        function getSmsCode() {
            const phoneInput = document.getElementById('register-phone');
            const getCodeBtn = document.getElementById('getCodeBtn');
            
            if (!phoneInput) {
                if (window.Toast) {
                    window.Toast.error('请先填写手机号');
                } else {
                    alert('请先填写手机号');
                }
                return;
            }
            
            const phone = phoneInput.value.trim();
            if (!phone) {
                if (window.Toast) {
                    window.Toast.error('请输入手机号');
                } else {
                    alert('请输入手机号');
                }
                return;
            }
            
            // 手机号格式验证
            const phoneRegex = /^1[3-9]\d{9}$/;
            if (!phoneRegex.test(phone)) {
                if (window.Toast) {
                    window.Toast.error('请输入正确的手机号');
                } else {
                    alert('请输入正确的手机号');
                }
                return;
            }
            
            // 禁用按钮并开始倒计时
            let countdown = 60;
            getCodeBtn.disabled = true;
            getCodeBtn.textContent = `${countdown}秒后重发`;
            
            const timer = setInterval(() => {
                countdown--;
                getCodeBtn.textContent = `${countdown}秒后重发`;
                if (countdown <= 0) {
                    clearInterval(timer);
                    getCodeBtn.disabled = false;
                    getCodeBtn.textContent = '获取验证码';
                }
            }, 1000);
            
            // 发送获取验证码请求
            fetch('/user/sendSms', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ phone }),
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(result => {
                if (result.code !== 200) {
                    // 清除倒计时
                    clearInterval(timer);
                    getCodeBtn.disabled = false;
                    getCodeBtn.textContent = '获取验证码';
                    
                    if (window.Toast) {
                        window.Toast.error(result.msg || '获取验证码失败');
                    } else {
                        alert(result.msg || '获取验证码失败');
                    }
                } else {
                    if (window.Toast) {
                        window.Toast.success('验证码已发送');
                    } else {
                        alert('验证码已发送');
                    }
                }
            })
            .catch(error => {
                console.error('获取验证码失败:', error);
                
                // 清除倒计时
                clearInterval(timer);
                getCodeBtn.disabled = false;
                getCodeBtn.textContent = '获取验证码';
                
                if (window.Toast) {
                    window.Toast.error('网络错误，请稍后重试');
                } else {
                    alert('网络错误，请稍后重试');
                }
            });
        }

        // 初始化登录和注册表单
        function initAuthForms() {
            // 登录表单提交
            const loginForm = document.getElementById('loginForm');
            if (loginForm) {
                loginForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const submitBtn = document.getElementById('loginSubmitBtn');
                    const originalText = submitBtn.textContent;
                    
                    try {
                        submitBtn.textContent = '登录中...';
                        submitBtn.disabled = true;
                        
                        const formData = new FormData(loginForm);
                        const data = Object.fromEntries(formData.entries());
                        
                        const response = await fetch('/user/login', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(data),
                            credentials: 'same-origin'
                        });
                        
                        const result = await response.json();
                        
                        if (result.code === 200) {
                            // 登录成功
                            if (window.Toast) {
                                window.Toast.success('登录成功');
                            } else {
                                alert('登录成功');
                            }
                            
                            // 刷新页面
                            setTimeout(() => {
                                window.location.reload();
                            }, 1000);
                        } else {
                            // 登录失败
                            if (window.Toast) {
                                window.Toast.error(result.msg || '登录失败');
                            } else {
                                alert(result.msg || '登录失败');
                            }
                        }
                    } catch (error) {
                        console.error('登录请求失败:', error);
                        if (window.Toast) {
                            window.Toast.error('网络错误，请稍后重试');
                        } else {
                            alert('网络错误，请稍后重试');
                        }
                    } finally {
                        submitBtn.textContent = originalText;
                        submitBtn.disabled = false;
                    }
                });
            }
            
            // 注册表单提交
            const registerForm = document.getElementById('registerForm');
            if (registerForm) {
                registerForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const submitBtn = document.getElementById('registerSubmitBtn');
                    const originalText = submitBtn.textContent;
                    
                    try {
                        submitBtn.textContent = '注册中...';
                        submitBtn.disabled = true;
                        
                        const formData = new FormData(registerForm);
                        const data = Object.fromEntries(formData.entries());
                        
                        // 验证密码
                        if (data.password !== data.confirm_password) {
                            if (window.Toast) {
                                window.Toast.error('两次输入的密码不一致');
                            } else {
                                alert('两次输入的密码不一致');
                            }
                            return;
                        }
                        
                        const response = await fetch('/user/register', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(data),
                            credentials: 'same-origin'
                        });
                        
                        const result = await response.json();
                        
                        if (result.code === 200) {
                            // 注册成功
                            if (window.Toast) {
                                window.Toast.success('注册成功，请登录');
                            } else {
                                alert('注册成功，请登录');
                            }
                            
                            // 切换到登录弹窗
                            toggleModal('registerModal', 'loginModal');
                        } else {
                            // 注册失败
                            if (window.Toast) {
                                window.Toast.error(result.msg || '注册失败');
                            } else {
                                alert(result.msg || '注册失败');
                            }
                        }
                    } catch (error) {
                        console.error('注册请求失败:', error);
                        if (window.Toast) {
                            window.Toast.error('网络错误，请稍后重试');
                        } else {
                            alert('网络错误，请稍后重试');
                        }
                    } finally {
                        submitBtn.textContent = originalText;
                        submitBtn.disabled = false;
                    }
                });
            }
        }

        // 初始化表单
        document.addEventListener('DOMContentLoaded', function() {
            initAuthForms();
            
            // 为登录链接添加点击事件
            const loginLinks = document.querySelectorAll('a[href="/login"]');
            loginLinks.forEach(link => {
                link.href = 'javascript:void(0)';
                link.addEventListener('click', function() {
                    openModal('loginModal');
                });
            });
            
            // 为注册链接添加点击事件
            const registerLinks = document.querySelectorAll('a[href="/register"]');
            registerLinks.forEach(link => {
                link.href = 'javascript:void(0)';
                link.addEventListener('click', function() {
                    openModal('registerModal');
                });
            });
            
            // 为"我的"链接添加点击事件
            const profileBtn = document.getElementById('mobile-profile-btn');
            if (profileBtn) {
                profileBtn.addEventListener('click', function() {
                    // 检查是否已登录
                    const isLogin = typeof window.isLogin !== 'undefined' ? window.isLogin : false;
                    const currentUser = typeof window.currentUserInfo !== 'undefined' ? window.currentUserInfo : null;
                    
                    if (isLogin && currentUser) {
                        // 已登录，跳转到个人资料页面
                        window.location.href = '/profile';
                    } else {
                        // 未登录，打开登录弹窗
                        openModal('loginModal');
                    }
                });
            }
        });
    </script>
</body>
</html>
