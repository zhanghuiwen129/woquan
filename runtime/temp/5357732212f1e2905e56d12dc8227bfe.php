<?php /*a:2:{s:31:"D:\wwwroot\view\index\card.html";i:1771617579;s:38:"D:\wwwroot\view\index\main-layout.html";i:1770774785;}*/ ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo isset($page_title) ? htmlentities((string) $page_title) : '首页'; ?> - <?php echo htmlentities((string) $name); ?> - <?php echo htmlentities((string) $subtitle); ?></title>
    <script src="https://cdn.tailwindcss.com?hide-warning=true"></script>
    <!-- 使用本地 FontAwesome 图标库 -->
    <link rel="stylesheet" href="/fontawesome/css/font-awesome.min.css">
    <!-- 网站配置 -->
    <script src="/static/js/site-config.js"></script>
    <!-- 存储当前用户信息到JavaScript变量 -->
    <script>
        window.currentUserInfo = <?php echo isset($isLogin) && $isLogin && $currentUser ? json_encode([
            'id' => $currentUser['id'] ?? '',
            'username' => $currentUser['username'] ?? '',
            'nickname' => $currentUser['nickname'] ?? '',
            'avatar' => $currentUser['avatar'] ?? '/static/images/default-avatar.png'
        ]) : 'null'; ?>;
        if (window.currentUserInfo === 'null') {
            window.currentUserInfo = null;
        }
    </script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        // 日间模式配色 - 低饱和雾霾蓝系
                        primary: '#4A90E2',
                        secondary: '#6AA9F2',
                        darkPrimary: '#3A7BC8',
                        lightBlue: '#E8F3FF',
                        danger: '#F04848',
                        success: '#52C480',
                        warning: '#F5A623',

                        // 背景和文字色
                        'bg-main': '#FFFFFF',
                        'bg-card': '#F7F8FA',
                        'text-primary': '#333647',
                        'text-secondary': '#6B6E7F',
                        'text-tertiary': '#9EA0AF',
                        'border-color': '#E5E6EB',

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
                        'hover': '0 4px 12px rgba(74, 144, 226, 0.15)',
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
            .nav-active {
                @apply text-primary font-medium;
            }
        }
    </style>
    <style>
        /* 移动端样式 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Helvetica Neue", "PingFang SC", "Microsoft YaHei", Arial, sans-serif; line-height: 1.6; }

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
    </style>
</head>
<body class="bg-bg-card text-text-primary dark:bg-dark-bg-main dark:text-dark-text-primary pb-16 md:pb-0">

    <!-- 桌面端布局 -->
    <div class="hidden md:flex min-h-screen">
        <!-- 左侧边栏 -->
        <aside class="w-64 bg-white dark:bg-dark-bg-card shadow-soft fixed left-0 top-0 h-full border-r border-border-color dark:border-dark-border-color z-40">
            <div class="p-6">
                <a href="/" class="flex items-center gap-3 mb-8">
                    <div class="w-10 h-10 bg-gradient-to-br from-primary to-secondary rounded-xl flex items-center justify-center">
                        <i class="fa fa-share-alt text-white text-lg"></i>
                    </div>
                    <span class="text-xl font-bold text-text-primary dark:text-dark-text-primary"><?php echo htmlentities((string) $name); ?></span>
                </a>

                <nav class="space-y-1">
                    <a href="/" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-lightBlue dark:hover:bg-dark-bg-card transition-colors <?php echo $current_url=='/' ? 'nav-active bg-lightBlue' : ''; ?>">
                        <i class="fa fa-home w-5 text-center"></i>
                        <span>首页</span>
                    </a>
                    <a href="/discover" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-lightBlue dark:hover:bg-dark-bg-card transition-colors <?php echo $current_url=='/discover' ? 'nav-active bg-lightBlue' : ''; ?>">
                        <i class="fa fa-compass w-5 text-center"></i>
                        <span>发现</span>
                    </a>
                    <a href="/moments" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-lightBlue dark:hover:bg-dark-bg-card transition-colors <?php echo $current_url=='/moments' ? 'nav-active bg-lightBlue' : ''; ?>">
                        <i class="fa fa-rss w-5 text-center"></i>
                        <span>动态</span>
                    </a>
                    <a href="/article" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-lightBlue dark:hover:bg-dark-bg-card transition-colors <?php echo $current_url=='/article' ? 'nav-active bg-lightBlue' : ''; ?>">
                        <i class="fa fa-book w-5 text-center"></i>
                        <span>文章</span>
                    </a>
                    <a href="/messages" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-lightBlue dark:hover:bg-dark-bg-card transition-colors <?php echo $current_url=='/messages' ? 'nav-active bg-lightBlue' : ''; ?>">
                        <div class="relative">
                            <i class="fa fa-envelope w-5 text-center"></i>
                            <span id="pc-message-badge" class="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full w-4 h-4 flex items-center justify-center hidden min-w-[16px] min-h-[16px]"></span>
                        </div>
                        <span>消息</span>
                    </a>
                    <a href="/notifications" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-lightBlue dark:hover:bg-dark-bg-card transition-colors <?php echo $current_url=='/notifications' ? 'nav-active bg-lightBlue' : ''; ?>">
                        <div class="relative">
                            <i class="fa fa-bell w-5 text-center"></i>
                            <span id="pc-notification-badge" class="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full w-4 h-4 flex items-center justify-center hidden min-w-[16px] min-h-[16px]"></span>
                        </div>
                        <span>通知</span>
                    </a>
                    <a href="/profile" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-lightBlue dark:hover:bg-dark-bg-card transition-colors <?php echo $current_url=='/profile' ? 'nav-active bg-lightBlue' : ''; ?>">
                        <i class="fa fa-user w-5 text-center"></i>
                        <span>我的</span>
                    </a>
                </nav>
            </div>

            <?php if($isLogin): ?>
            <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-border-color dark:border-dark-border-color">
                <a href="/profile" class="flex items-center gap-3">
                    <img src="<?php echo isset($currentUser['avatar']) ? htmlentities((string) $currentUser['avatar']) : '/static/images/default-avatar.png'; ?>" alt="头像" class="w-10 h-10 rounded-full object-cover">
                    <div class="flex-1 min-w-0">
                        <p class="font-medium truncate text-text-primary dark:text-dark-text-primary"><?php echo isset($currentUser['nickname']) ? htmlentities((string) $currentUser['nickname']) : htmlentities((string) $currentUser['username']); ?></p>
                    </div>
                    <i class="fa fa-cog text-text-secondary dark:text-dark-text-secondary"></i>
                </a>
            </div>
            <?php else: ?>
            <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-border-color dark:border-dark-border-color space-y-2">
                <a href="/login" class="block w-full text-center py-2 bg-primary text-white rounded-lg hover:bg-darkPrimary transition-colors">
                    登录
                </a>
                <a href="/register" class="block w-full text-center py-2 border border-border-color dark:border-dark-border-color rounded-lg hover:bg-lightBlue dark:hover:bg-dark-bg-card transition-colors">
                    注册
                </a>
            </div>
            <?php endif; ?>
        </aside>

        <!-- 主内容区域 -->
        <main class="flex-1 ml-64 p-8" style="min-width: 0;">
            <div class="block">
                
<div class="max-w-xl mx-auto px-4 py-6">
    <!-- 加载中 -->
    <div id="loading" class="text-center py-20">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-gray-300 mx-auto mb-4"></div>
        <p class="text-gray-500 text-sm">加载中...</p>
    </div>

    <!-- 名片内容 -->
    <div id="card-content" class="hidden">
        <!-- 头部个人信息区域 -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4">
            <!-- 背景图和头像 -->
            <div class="relative">
                <div class="h-32 bg-gradient-to-r from-blue-400 to-purple-500 rounded-t-lg"></div>
                <div class="absolute -bottom-12 left-6">
                    <div class="w-24 h-24 rounded-full border-4 border-white bg-white overflow-hidden shadow-lg">
                        <img id="card-avatar" src="" alt="头像" class="w-full h-full object-cover">
                    </div>
                </div>
            </div>
            
            <!-- 用户信息和操作按钮 -->
            <div class="pt-14 px-6 pb-4">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex-1">
                        <h2 id="card-nickname" class="text-xl font-bold text-gray-900 mb-1 truncate"></h2>
                        <p id="card-username" class="text-sm text-gray-500 mb-2">@<span id="username-text"></span></p>
                        <div id="card-gender-region" class="text-xs text-gray-400 flex items-center gap-2 mb-2"></div>
                        <p id="card-bio" class="text-sm text-gray-700 leading-relaxed line-clamp-2 mb-3"></p>
                    </div>
                    <div class="flex gap-2 ml-4">
                        <button id="btn-follow-desktop" onclick="handleFollow()" 
                                class="px-4 py-2 rounded-full text-sm font-medium transition-all border-none whitespace-nowrap"></button>
                        <button onclick="handleMessage()"
                                class="px-4 py-2 bg-white border border-gray-300 rounded-full text-sm font-medium text-gray-600 hover:bg-gray-50 transition-all">
                            <i class="fa fa-comment mr-1"></i>私信
                        </button>
                    </div>
                </div>

                <!-- 用户标签 -->
                <div id="card-tags" class="flex flex-wrap gap-2 mb-4"></div>

                <!-- 统计数据 -->
                <div class="grid grid-cols-4 gap-4 border-t border-gray-100 pt-3">
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">
                            <span id="stat-following" class="block">0</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">关注</div>
                    </div>
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">
                            <span id="stat-followers" class="block">0</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">粉丝</div>
                    </div>
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">
                            <span id="stat-moments" class="block">0</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">动态</div>
                    </div>
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">
                            <span id="stat-likes" class="block">0</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">获赞</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 详细统计卡片 -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                <i class="fa fa-chart-bar mr-2 text-blue-500"></i>详细数据
            </h3>
            <div class="grid grid-cols-2 gap-4">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center">
                        <i class="fa fa-heart text-red-500 mr-2"></i>
                        <span class="text-sm text-gray-600">获赞</span>
                    </div>
                    <span id="stat-likes-detail" class="text-md font-bold text-gray-900">0</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center">
                        <i class="fa fa-star text-yellow-500 mr-2"></i>
                        <span class="text-sm text-gray-600">收藏</span>
                    </div>
                    <span id="stat-favorites" class="text-md font-bold text-gray-900">0</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center">
                        <i class="fa fa-eye text-green-500 mr-2"></i>
                        <span class="text-sm text-gray-600">访客</span>
                    </div>
                    <span id="stat-visitors" class="text-md font-bold text-gray-900">0</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center">
                        <i class="fa fa-share-alt text-purple-500 mr-2"></i>
                        <span class="text-sm text-gray-600">分享</span>
                    </div>
                    <span class="text-md font-bold text-gray-900">0</span>
                </div>
            </div>
        </div>

        <!-- 最近动态 -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
                <i class="fa fa-list-alt mr-2 text-green-500"></i>最新动态
            </h3>
            <div id="recent-moments" class="space-y-3">
                <!-- 动态将由JS渲染 -->
            </div>
        </div>
    </div>

    <!-- Toast提示 -->
    <div id="toast" class="hidden fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-gray-800/90 text-white px-6 py-3 rounded-xl text-sm z-50"></div>
</div>

<style>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

            </div>
        </main>
    </div>

    <!-- 移动端布局 -->
    <div class="md:hidden">
        <!-- 移动端顶部导航 -->
        <header class="sticky top-0 z-50 bg-white dark:bg-dark-bg-card shadow-sm border-b border-border-color dark:border-dark-border-color">
            <div class="flex items-center justify-between px-4 py-3">
                <a href="/" class="flex items-center gap-2">
                    <?php if(isset($config['logo']) && $config['logo'] != ''): ?>
                    <img src="<?php echo htmlentities((string) $config['logo']); ?>" alt="<?php echo htmlentities((string) $name); ?>" class="h-8 w-auto object-contain" style="max-height: 32px;">
                    <?php else: ?>
                    <div class="w-8 h-8 bg-gradient-to-br from-primary to-secondary rounded-lg flex items-center justify-center">
                        <i class="fa fa-share-alt text-white text-sm"></i>
                    </div>
                    <?php endif; ?>
                    <span class="font-bold text-text-primary dark:text-dark-text-primary"><?php echo htmlentities((string) $name); ?></span>
                </a>
                <div class="flex items-center gap-3">
                    <?php if($isLogin): ?>
                    <a href="/notifications" class="relative text-text-secondary dark:text-dark-text-secondary">
                        <i class="fa fa-bell text-xl"></i>
                        <span id="mobile-notification-badge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-4 h-4 flex items-center justify-center hidden"></span>
                    </a>
                    <?php else: ?>
                    <a href="/login" class="text-sm text-primary font-medium">登录</a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <!-- 移动端主内容 -->
        <div class="min-h-screen">
            <div class="md:hidden">
                
<div class="px-4 py-4 pb-24">
    <!-- 加载中 -->
    <div id="loading-mobile" class="text-center py-20">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-gray-300 mx-auto mb-4"></div>
        <p class="text-gray-500 text-sm">加载中...</p>
    </div>

    <!-- 名片内容 -->
    <div id="card-content-mobile" class="hidden">
        <!-- 头部个人信息区域 -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4">
            <!-- 背景图和头像 -->
            <div class="relative">
                <div class="h-32 bg-gradient-to-r from-blue-400 to-purple-500 rounded-t-lg"></div>
                <div class="absolute -bottom-12 left-6">
                    <div class="w-24 h-24 rounded-full border-4 border-white bg-white overflow-hidden shadow-lg">
                        <img id="card-avatar-mobile" src="" alt="头像" class="w-full h-full object-cover">
                    </div>
                </div>
            </div>

            <!-- 用户信息和操作按钮 -->
            <div class="pt-14 px-6 pb-4">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex-1">
                        <h2 id="card-nickname-mobile" class="text-xl font-bold text-gray-900 mb-1 truncate"></h2>
                        <p id="card-username-mobile" class="text-sm text-gray-500 mb-2">@<span id="username-text-mobile"></span></p>
                        <div id="card-gender-region-mobile" class="text-xs text-gray-400 flex items-center gap-2 mb-2"></div>
                        <p id="card-bio-mobile" class="text-sm text-gray-700 leading-relaxed line-clamp-2 mb-3"></p>
                    </div>
                </div>

                <!-- 用户标签 -->
                <div id="card-tags-mobile" class="flex flex-wrap gap-2 mb-4"></div>

                <!-- 统计数据 -->
                <div class="grid grid-cols-4 gap-4 border-t border-gray-100 pt-3">
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">
                            <span id="stat-following-mobile" class="block">0</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">关注</div>
                    </div>
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">
                            <span id="stat-followers-mobile" class="block">0</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">粉丝</div>
                    </div>
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">
                            <span id="stat-moments-mobile" class="block">0</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">动态</div>
                    </div>
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">
                            <span id="stat-likes-mobile" class="block">0</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">获赞</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 详细统计卡片 -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                <i class="fa fa-chart-bar mr-2 text-blue-500"></i>详细数据
            </h3>
            <div class="grid grid-cols-2 gap-4">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center">
                        <i class="fa fa-heart text-red-500 mr-2"></i>
                        <span class="text-sm text-gray-600">获赞</span>
                    </div>
                    <span id="stat-likes-detail-mobile" class="text-md font-bold text-gray-900">0</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center">
                        <i class="fa fa-star text-yellow-500 mr-2"></i>
                        <span class="text-sm text-gray-600">收藏</span>
                    </div>
                    <span id="stat-favorites-mobile" class="text-md font-bold text-gray-900">0</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center">
                        <i class="fa fa-eye text-green-500 mr-2"></i>
                        <span class="text-sm text-gray-600">访客</span>
                    </div>
                    <span id="stat-visitors-mobile" class="text-md font-bold text-gray-900">0</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center">
                        <i class="fa fa-share-alt text-purple-500 mr-2"></i>
                        <span class="text-sm text-gray-600">分享</span>
                    </div>
                    <span class="text-md font-bold text-gray-900">0</span>
                </div>
            </div>
        </div>

        <!-- 最近动态 -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
                <i class="fa fa-list-alt mr-2 text-green-500"></i>最新动态
            </h3>
            <div id="recent-moments-mobile" class="space-y-3">
                <!-- 动态将由JS渲染 -->
            </div>
        </div>
    </div>

    <!-- 移动端底部操作栏 -->
    <div id="action-bar-mobile" class="hidden fixed bottom-16 left-0 right-0 bg-white p-4 border-t border-gray-200 shadow-lg z-40 flex gap-2">
        <button id="btn-follow-mobile" onclick="handleFollow()"
                class="flex-1 py-3 rounded-full font-medium text-sm transition-all border-none"></button>
        <button onclick="handleMessage()"
                class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 rounded-full font-medium text-sm hover:bg-gray-50 transition-all">
            <i class="fa fa-comment mr-1"></i>私信
        </button>
        <button onclick="showMoreActions()"
                class="w-12 h-12 bg-gray-100 rounded-full text-sm hover:bg-gray-200 transition-all border-none">
            <i class="fa fa-ellipsis-h"></i>
        </button>
    </div>

    <!-- 更多操作弹窗 -->
    <div id="more-actions-modal-mobile" class="hidden fixed inset-0 bg-black/50 z-50 items-end justify-center">
        <div class="bg-white w-full max-w-md rounded-t-2xl p-4">
            <div id="action-list-mobile" class="flex flex-col gap-1"></div>
            <button onclick="closeMoreActions()"
                    class="mt-3 py-3 bg-gray-100 border-none rounded-xl text-base font-medium cursor-pointer text-gray-700 hover:bg-gray-200 transition-colors w-full">
                取消
            </button>
        </div>
    </div>

    <!-- Toast提示 -->
    <div id="toast-mobile" class="hidden fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-gray-800/90 text-white px-6 py-3 rounded-xl text-sm z-50"></div>
</div>

<style>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

            </div>
        </div>

        <!-- 移动端底部导航栏 -->
        <nav class="fixed bottom-0 left-0 right-0 bg-white dark:bg-dark-bg-card shadow-lg border-t border-border-color dark:border-dark-border-color z-50" id="mobile-footer-nav">
            <div class="flex justify-around items-center py-2">
                <a href="/" class="flex flex-col items-center gap-1 py-1 <?php echo $current_url=='/' ? 'text-primary' : 'text-text-secondary dark:text-dark-text-secondary'; ?>">
                    <i class="fa fa-home text-xl"></i>
                    <span class="text-xs">首页</span>
                </a>
                <a href="/discover" class="flex flex-col items-center gap-1 py-1 <?php echo $current_url=='/discover' ? 'text-primary' : 'text-text-secondary dark:text-dark-text-secondary'; ?>">
                    <i class="fa fa-compass text-xl"></i>
                    <span class="text-xs">发现</span>
                </a>
                <a href="/notifications" class="flex flex-col items-center gap-1 py-1 <?php echo $current_url=='/notifications' ? 'text-primary' : 'text-text-secondary dark:text-dark-text-secondary'; ?> relative">
                    <i class="fa fa-bell text-xl"></i>
                    <span id="footer-notification-badge" class="absolute -top-1 -right-2 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center hidden min-w-[20px] min-h-[20px]">0</span>
                    <span class="text-xs">通知</span>
                </a>
                <a href="/profile" class="flex flex-col items-center gap-1 py-1 <?php echo $current_url=='/profile' ? 'text-primary' : 'text-text-secondary dark:text-dark-text-secondary'; ?>">
                    <i class="fa fa-user text-xl"></i>
                    <span class="text-xs">我的</span>
                </a>
            </div>
        </nav>
    </div>

    <!-- 移动端全屏容器（用于聊天等全屏页面） -->
    <div class="hidden md:hidden fixed inset-0 bg-white dark:bg-dark-bg-main z-50" id="mobile-full-page-container">
        
    </div>

    <!-- 全局提示框 -->
    <div id="toast" class="fixed top-20 left-1/2 transform -translate-x-1/2 px-6 py-3 rounded-lg shadow-lg z-50 bg-green-500 text-white hidden">
        <div id="toast-message"></div>
    </div>

    <!-- 登录弹窗 -->
    <div id="loginModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden">
            <div class="bg-gradient-to-r from-red-500 to-red-600 p-6 text-center">
                <?php if(isset($config['logo']) && $config['logo'] != ''): ?>
                <img src="<?php echo htmlentities((string) $config['logo']); ?>" alt="<?php echo htmlentities((string) (isset($config['name']) && ($config['name'] !== '')?$config['name']:'社交平台')); ?>" class="w-16 h-auto mx-auto mb-3 object-contain" style="max-height: 64px;">
                <?php else: ?>
                <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center mx-auto mb-3 shadow-lg">
                    <i class="fa fa-comments text-2xl text-red-500"></i>
                </div>
                <?php endif; ?>
                <h2 class="text-xl font-bold text-white mb-1">欢迎回来</h2>
                <p class="text-white/90 text-xs">登录您的账号，继续社交之旅</p>
            </div>
            <div class="p-6">
                <div id="loginError" class="bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded-lg mb-4 text-center hidden"></div>
                <form id="loginForm">
                    <div class="mb-4">
                        <label for="loginUsername" class="block text-sm font-medium text-gray-700 mb-1">账号或手机号</label>
                        <input type="text" id="loginUsername" name="username" placeholder="请输入账号或手机号" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                    </div>
                    <div class="mb-4">
                        <label for="loginPassword" class="block text-sm font-medium text-gray-700 mb-1">密码</label>
                        <div class="relative">
                            <input type="password" id="loginPassword" name="password" placeholder="请输入密码" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent pr-10">
                            <span class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 cursor-pointer" onclick="togglePassword('loginPassword', this)">
                                <i class="fa fa-eye-slash"></i>
                            </span>
                        </div>
                    </div>
                    <div class="flex justify-between items-center mb-6">
                        <div class="flex items-center">
                            <input type="checkbox" id="remember" name="remember" class="h-4 w-4 text-blue-500 focus:ring-blue-500 border-gray-300 rounded">
                            <label for="remember" class="ml-2 block text-sm text-gray-700">记住我</label>
                        </div>
                        <a href="#" class="text-sm text-red-500 hover:text-red-700">忘记密码？</a>
                    </div>
                    <button type="submit" class="w-full bg-gradient-to-r from-red-500 to-red-600 text-white py-2 rounded-lg font-medium hover:opacity-90 transition-opacity">立即登录</button>
                </form>
                <div class="mt-4 text-center text-sm text-gray-600">
                    还没有账号？ <a href="#" class="text-red-500 hover:text-red-700 font-medium" onclick="toggleModal('loginModal', 'registerModal')">立即注册</a>
                </div>
            </div>
            <div class="absolute top-4 right-4">
                <button onclick="closeModal('loginModal')" class="w-8 h-8 rounded-full bg-white bg-opacity-20 flex items-center justify-center text-white hover:bg-opacity-30 transition-opacity">
                    <i class="fa fa-times"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- 注册弹窗 -->
    <div id="registerModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden">
            <div class="bg-gradient-to-r from-red-500 to-red-600 p-6 text-center">
                <?php if(isset($config['logo']) && $config['logo'] != ''): ?>
                <img src="<?php echo htmlentities((string) $config['logo']); ?>" alt="<?php echo htmlentities((string) (isset($config['name']) && ($config['name'] !== '')?$config['name']:'社交平台')); ?>" class="w-16 h-auto mx-auto mb-3 object-contain" style="max-height: 64px;">
                <?php else: ?>
                <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center mx-auto mb-3 shadow-lg">
                    <i class="fa fa-comments text-2xl text-red-500"></i>
                </div>
                <?php endif; ?>
                <h2 class="text-xl font-bold text-white mb-1">欢迎加入<?php echo htmlentities((string) (isset($config['name']) && ($config['name'] !== '')?$config['name']:'我圈')); ?></h2>
                <p class="text-white/90 text-xs">创建您的社交账号，开启精彩旅程</p>
            </div>
            <div class="p-6">
                <div id="registerSuccess" class="bg-green-50 border border-green-200 text-green-700 px-4 py-2 rounded-lg mb-4 text-center hidden">
                    <i class="fa fa-check-circle mr-2"></i>
                    注册成功！正在跳转到登录页面...
                </div>
                <div id="registerError" class="bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded-lg mb-4 text-center hidden"></div>
                <form id="registerForm">
                    <div class="mb-4">
                        <label for="registerUsername" class="block text-sm font-medium text-gray-700 mb-1">账号</label>
                        <input type="text" id="registerUsername" name="username" placeholder="请输入账号" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                    </div>
                    <div class="mb-4">
                        <label for="registerPassword" class="block text-sm font-medium text-gray-700 mb-1">密码</label>
                        <div class="relative">
                            <input type="password" id="registerPassword" name="password" placeholder="6-16位字母数字组合" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent pr-10">
                            <span class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 cursor-pointer" onclick="togglePassword('registerPassword', this)">
                                <i class="fa fa-eye-slash"></i>
                            </span>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="registerNickname" class="block text-sm font-medium text-gray-700 mb-1">昵称（可选）</label>
                        <input type="text" id="registerNickname" name="nickname" placeholder="显示给其他用户的名称" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                    </div>
                    <div class="mb-6">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" id="agreement" name="agreement" required class="mt-1 h-4 w-4 text-blue-500 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="text-xs text-gray-600">
                                我已阅读并同意
                                <a href="javascript:void(0)" class="text-red-500 hover:text-red-700">《用户协议》</a>
                                和
                                <a href="javascript:void(0)" class="text-red-500 hover:text-red-700">《隐私政策》</a>
                            </span>
                        </label>
                    </div>
                    <button type="submit" class="w-full bg-gradient-to-r from-red-500 to-red-600 text-white py-2 rounded-lg font-medium hover:opacity-90 transition-opacity">
                        <i class="fa fa-user-plus mr-2"></i>立即注册
                    </button>
                </form>
                <div class="mt-4 text-center text-sm text-gray-600">
                    已有账号？ <a href="#" class="text-red-500 hover:text-red-700 font-medium" onclick="toggleModal('registerModal', 'loginModal')">立即登录</a>
                </div>
            </div>
            <div class="absolute top-4 right-4">
                <button onclick="closeModal('registerModal')" class="w-8 h-8 rounded-full bg-white bg-opacity-20 flex items-center justify-center text-white hover:bg-opacity-30 transition-opacity">
                    <i class="fa fa-times"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- 页面脚本块 -->
    
<script>
    // 获取用户ID和当前用户信息（从后端输出）
    var targetUserId = <?php echo $targetUserId; ?>;
    var currentUser = <?php echo json_encode($currentUser); ?>;

    // 获取Cookie
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }

    // HTML转义函数
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    var currentUserFromCookie = null;
    if (!currentUser) {
        const userId = getCookie('user_id');
        if (userId) {
            currentUserFromCookie = {
                id: parseInt(userId),
                username: getCookie('username') || '',
                nickname: getCookie('nickname') || '',
                avatar: getCookie('avatar') || ''
            };
        }
    }

    var effectiveUser = currentUser || currentUserFromCookie;
    var cardData = null;
    var isFollowing = false;

    // 加载名片数据
    async function loadCardData() {
        try {
            const response = await fetch(`/user/card?user_id=${targetUserId}`);
            const result = await response.json();

            if (result.code === 200) {
                cardData = result.data;
                isFollowing = result.data.is_following;
                renderCard(result.data);
            } else {
                showToast(result.msg || '加载失败');
            }
        } catch (error) {
            console.error('加载名片失败:', error);
            showToast('加载失败，请重试');
        }
    }

    // 渲染名片
    function renderCard(data) {
        const user = data.user;
        const stats = data.stats;
        const isMobileDevice = window.innerWidth < 768;
        const suffix = isMobileDevice ? '-mobile' : '';

        console.log('渲染名片，设备类型:', isMobileDevice ? '移动端' : 'PC端', '后缀:', suffix);

        // 检查元素是否存在
        const loading = document.getElementById(`loading${suffix}`);
        const cardContent = document.getElementById(`card-content${suffix}`);
        const actionBar = document.getElementById(`action-bar${suffix}`);

        if (loading) loading.classList.add('hidden');
        if (cardContent) cardContent.classList.remove('hidden');
        if (actionBar) {
            actionBar.classList.remove('hidden');
            actionBar.classList.add('flex');
        }

        const elements = {
            [`card-avatar${suffix}`]: document.getElementById(`card-avatar${suffix}`),
            [`card-nickname${suffix}`]: document.getElementById(`card-nickname${suffix}`),
            [`card-username${suffix}`]: document.getElementById(`card-username${suffix}`),
            [`username-text${suffix}`]: document.getElementById(`username-text${suffix}`),
            [`card-gender-region${suffix}`]: document.getElementById(`card-gender-region${suffix}`),
            [`card-bio${suffix}`]: document.getElementById(`card-bio${suffix}`),
            [`stat-followers${suffix}`]: document.getElementById(`stat-followers${suffix}`),
            [`stat-following${suffix}`]: document.getElementById(`stat-following${suffix}`),
            [`stat-moments${suffix}`]: document.getElementById(`stat-moments${suffix}`),
            [`stat-likes${suffix}`]: document.getElementById(`stat-likes${suffix}`),
            [`stat-favorites${suffix}`]: document.getElementById(`stat-favorites${suffix}`),
            [`stat-visitors${suffix}`]: document.getElementById(`stat-visitors${suffix}`),
            [`stat-likes-detail${suffix}`]: document.getElementById(`stat-likes-detail${suffix}`)
        };

        // 检查所有必需元素
        for (const [key, element] of Object.entries(elements)) {
            if (!element) {
                console.error(`找不到元素: ${key}`);
            }
        }

        if (elements[`card-avatar${suffix}`]) elements[`card-avatar${suffix}`].src = user.avatar || '/static/images/default-avatar.png';
        if (elements[`card-nickname${suffix}`]) elements[`card-nickname${suffix}`].textContent = user.nickname || user.username || '未知用户';
        const usernameText = user.username || '';
        if (elements[`card-username${suffix}`]) elements[`card-username${suffix}`].textContent = '@' + usernameText;
        if (elements[`username-text${suffix}`]) elements[`username-text${suffix}`].textContent = usernameText;

        let genderRegion = '';
        if (user.gender) {
            const genderIcon = user.gender === 1 ? '<i class="fa fa-mars"></i>' : '<i class="fa fa-venus"></i>';
            genderRegion += genderIcon + ' ';
        }
        if (user.region) {
            genderRegion += '<i class="fa fa-map-marker-alt"></i> ' + escapeHtml(user.region);
        }
        if (elements[`card-gender-region${suffix}`]) elements[`card-gender-region${suffix}`].innerHTML = genderRegion;

        if (user.bio !== undefined && user.bio !== null && elements[`card-bio${suffix}`]) {
            elements[`card-bio${suffix}`].textContent = user.bio;
        }

        if (elements[`stat-followers${suffix}`]) elements[`stat-followers${suffix}`].textContent = stats.followers || 0;
        if (elements[`stat-following${suffix}`]) elements[`stat-following${suffix}`].textContent = stats.following || 0;
        if (elements[`stat-moments${suffix}`]) elements[`stat-moments${suffix}`].textContent = stats.moments || 0;
        if (elements[`stat-likes${suffix}`]) elements[`stat-likes${suffix}`].textContent = stats.likes || 0;
        if (elements[`stat-favorites${suffix}`]) elements[`stat-favorites${suffix}`].textContent = stats.favorites || 0;
        if (elements[`stat-visitors${suffix}`]) elements[`stat-visitors${suffix}`].textContent = stats.visitors || 0;
        if (elements[`stat-likes-detail${suffix}`]) elements[`stat-likes-detail${suffix}`].textContent = stats.likes || 0;

        renderUserTags(user);
        renderRecentMoments(data.recent_moments || []);
        updateFollowButton();
    }

    // 渲染用户标签
    function renderUserTags(user) {
        const isMobileDevice = window.innerWidth < 768;
        const suffix = isMobileDevice ? '-mobile' : '';
        const tagsContainer = document.getElementById(`card-tags${suffix}`);

        if (!tagsContainer) {
            console.error('找不到标签容器:', `card-tags${suffix}`);
            return;
        }

        let tags = [];

        if (user.vip_level && user.vip_level > 0) {
            tags.push(`<span class="bg-gradient-to-r from-yellow-400 to-yellow-500 text-gray-900 px-3 py-1 rounded-full text-xs font-bold">VIP${user.vip_level}</span>`);
        }

        if (user.level) {
            tags.push(`<span class="bg-gradient-to-r from-primary to-secondary text-white px-3 py-1 rounded-full text-xs">Lv.${user.level}</span>`);
        }

        if (user.occupation) {
            tags.push(`<span class="bg-gray-100 dark:bg-dark-bg-main text-gray-700 dark:text-dark-text-secondary px-3 py-1 rounded-full text-xs">${escapeHtml(user.occupation)}</span>`);
        }

        if (tags.length === 0) {
            tagsContainer.style.display = 'none';
        } else {
            tagsContainer.innerHTML = tags.join('');
            tagsContainer.style.display = 'flex';
        }
    }

    function renderRecentMoments(moments) {
        const isMobileDevice = window.innerWidth < 768;
        const suffix = isMobileDevice ? '-mobile' : '';
        const container = document.getElementById(`recent-moments${suffix}`);

        if (!container) {
            console.error('找不到动态容器:', `recent-moments${suffix}`);
            return;
        }

        if (moments.length === 0) {
            container.innerHTML = '<p class="text-center text-gray-500 py-8">暂无动态</p>';
            return;
        }

        container.innerHTML = moments.map(moment => {
            const hasImages = moment.images && moment.images.length > 0;
            const hasVideos = moment.videos && moment.videos.length > 0;

            let mediaHtml = '';

            if (hasVideos) {
                mediaHtml = `
                    <div class="mt-2">
                        ${moment.videos.slice(0, 1).map(video => `
                            <video src="${video}" class="w-full rounded-lg max-h-64 object-cover" controls></video>
                        `).join('')}
                    </div>
                `;
            } else if (hasImages) {
                mediaHtml = `
                    <div class="grid grid-cols-3 gap-1 mt-2">
                        ${moment.images.slice(0, 9).map(img => `<img src="${img}" class="w-full aspect-square object-cover rounded-lg">`).join('')}
                    </div>
                `;
            }

            return `<div class="bg-gray-50 dark:bg-dark-bg-main p-3 rounded-xl">
                <p class="text-sm leading-relaxed text-gray-800 dark:text-dark-text-primary whitespace-pre-wrap">${escapeHtml(moment.content)}</p>
                ${mediaHtml}
                <div class="flex gap-5 mt-2 text-gray-500 text-xs">
                    <span><i class="fa fa-heart"></i> ${moment.likes || 0}</span>
                    <span><i class="fa fa-comment"></i> ${moment.comments || 0}</span>
                </div>
            </div>`;
        }).join('');
    }

    // 关注/取消关注
    async function handleFollow() {
        if (!effectiveUser) {
            showToast('请先登录');
            window.location.href = '/login';
            return;
        }

        const currentUserId = parseInt(effectiveUser.id);
        const targetIdNum = parseInt(targetUserId);

        if (currentUserId === targetIdNum) {
            showToast('不能关注自己');
            return;
        }

        try {
            const action = isFollowing ? 'unfollow' : 'follow';
            const response = await fetch('/user/follow', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: effectiveUser.id,
                    target_id: targetUserId,
                    action: action
                })
            });

            const result = await response.json();

            if (result.code === 200) {
                isFollowing = result.data.following;
                updateFollowButton();
                showToast(result.msg);

                const isMobileDevice = window.innerWidth < 768;
                const suffix = isMobileDevice ? '-mobile' : '';
                const statFollowers = document.getElementById(`stat-followers${suffix}`);
                if (statFollowers) {
                    statFollowers.textContent = parseInt(statFollowers.textContent) + (isFollowing ? 1 : -1);
                }
            } else {
                showToast(result.msg || '操作失败');
            }
        } catch (error) {
            console.error('操作失败:', error);
            showToast('操作失败，请重试');
        }
    }

    // 更新关注按钮
    function updateFollowButton() {
        const currentUserId = effectiveUser ? parseInt(effectiveUser.id) : null;
        const targetIdNum = parseInt(targetUserId);
        const isMobileDevice = window.innerWidth < 768;

        const btn = document.getElementById(isMobileDevice ? 'btn-follow-mobile' : 'btn-follow-desktop');

        if (!btn) {
            console.error('找不到关注按钮:', isMobileDevice ? 'btn-follow-mobile' : 'btn-follow-desktop');
            return;
        }

        if (effectiveUser && currentUserId === targetIdNum) {
            if (isMobileDevice) {
                btn.style.background = '#4A90E2';
                btn.style.color = '#fff';
                btn.innerHTML = '<i class="fa fa-edit"></i> 编辑名片';
                btn.onclick = () => window.location.href = '/card/settings';
            }
        } else if (isFollowing) {
            btn.style.background = '#f5f5f5';
            btn.style.color = '#333';
            btn.innerHTML = isMobileDevice ? '<i class="fa fa-check"></i> 已关注' : '已关注';
            btn.onclick = handleFollow;
        } else {
            btn.style.background = 'linear-gradient(135deg, #4A90E2 0%, #6AA9F2 100%)';
            btn.style.color = '#fff';
            btn.innerHTML = isMobileDevice ? '<i class="fa fa-plus"></i> 关注' : '关注';
            btn.onclick = handleFollow;
        }
    }

    // 发送私信
    function handleMessage() {
        if (!effectiveUser) {
            showToast('请先登录');
            window.location.href = '/login';
            return;
        }
        window.location.href = `/chat?user_id=${targetUserId}`;
    }

    // 显示更多操作
    function showMoreActions() {
        const actions = [];
        const currentUserId = effectiveUser ? parseInt(effectiveUser.id) : null;
        const targetIdNum = parseInt(targetUserId);

        if (effectiveUser && currentUserId !== targetIdNum) {
            if (isFollowing) {
                actions.push({ icon: 'fa-user-minus', text: '取消关注', action: 'handleFollow()' });
            }
            actions.push({ icon: 'fa-ban', text: '拉黑用户', action: 'handleBlock()' });
            actions.push({ icon: 'fa-flag', text: '举报用户', action: 'handleReport()' });
        }

        actions.push({ icon: 'fa-share-alt', text: '分享名片', action: 'handleShare()' });

        if (effectiveUser && currentUserId === targetIdNum) {
            actions.push({ icon: 'fa-edit', text: '编辑名片', action: "window.location.href='/card/settings'" });
        }

        const isMobileDevice = window.innerWidth < 768;

        // PC端不需要更多操作弹窗，直接执行分享
        if (!isMobileDevice) {
            handleShare();
            return;
        }

        const suffix = isMobileDevice ? '-mobile' : '';
        const actionList = document.getElementById(`action-list${suffix}`);
        const modal = document.getElementById(`more-actions-modal${suffix}`);

        if (actionList) {
            actionList.innerHTML = actions.map(item => `
                <button onclick="${item.action}; closeMoreActions();"
                        class="w-full py-4 bg-white dark:bg-dark-bg-card border border-gray-100 dark:border-dark-border-color rounded-xl text-base cursor-pointer text-left hover:bg-gray-50 dark:hover:bg-dark-bg-main transition-colors flex items-center gap-3">
                    <i class="fa ${item.icon} text-primary"></i>${item.text}
                </button>
            `).join('');
        }

        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        } else {
            console.error('找不到更多操作弹窗:', `more-actions-modal${suffix}`);
        }
    }

    // 关闭更多操作
    function closeMoreActions() {
        const isMobileDevice = window.innerWidth < 768;
        const suffix = isMobileDevice ? '-mobile' : '';
        const modal = document.getElementById(`more-actions-modal${suffix}`);
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        } else {
            console.error('找不到更多操作弹窗:', `more-actions-modal${suffix}`);
        }
    }

    // 拉黑用户
    async function handleBlock() {
        if (!confirm('确定要拉黑该用户吗？')) return;
        try {
            const response = await fetch('/user/block', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ target_id: targetUserId, action: 'block' })
            });
            const result = await response.json();
            showToast(result.msg || '操作成功');
        } catch (error) {
            console.error('操作失败:', error);
            showToast('操作失败，请重试');
        }
    }

    // 举报用户
    function handleReport() {
        showToast('举报功能开发中...');
    }

    // 分享名片
    function handleShare() {
        const url = window.location.href;
        const isMobileDevice = window.innerWidth < 768;
        const suffix = isMobileDevice ? '-mobile' : '';
        const nicknameElement = document.getElementById(`card-nickname${suffix}`);

        if (navigator.share) {
            const nickname = nicknameElement ? nicknameElement.textContent : '用户';
            navigator.share({ title: nickname + '的个人名片', url })
                .catch(() => copyToClipboard(url));
        } else {
            copyToClipboard(url);
        }
    }

    // 复制到剪贴板
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => showToast('链接已复制'))
            .catch(() => {
                const input = document.createElement('input');
                input.value = text;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                showToast('链接已复制');
            });
    }

    // 显示Toast
    function showToast(message) {
        const isMobileDevice = window.innerWidth < 768;
        const suffix = isMobileDevice ? '-mobile' : '';
        const toast = document.getElementById(`toast${suffix}`);
        if (toast) {
            toast.textContent = message;
            toast.classList.remove('hidden');
            setTimeout(() => toast.classList.add('hidden'), 2000);
        } else {
            console.error('找不到toast元素:', `toast${suffix}`);
        }
    }

    document.getElementById('more-actions-modal-mobile')?.addEventListener('click', function(e) {
        if (e.target === this) closeMoreActions();
    });

    document.addEventListener('DOMContentLoaded', () => {
        if (targetUserId) loadCardData();
        else {
            showToast('用户ID不能为空');
            setTimeout(() => window.location.href = '/profile', 1500);
        }
    });

    // 监听窗口大小变化，当从PC切换到移动端或反之时重新渲染
    let lastDeviceType = window.innerWidth < 768 ? 'mobile' : 'desktop';
    let resizeTimeout;

    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            const currentDeviceType = window.innerWidth < 768 ? 'mobile' : 'desktop';

            // 当设备类型改变时重新渲染
            if (currentDeviceType !== lastDeviceType && cardData) {
                console.log('设备类型改变:', lastDeviceType, '->', currentDeviceType, '，重新渲染名片');
                renderCard(cardData);
                lastDeviceType = currentDeviceType;
            }
        }, 300); // 防抖，避免频繁触发
    });
</script>


    <!-- 加载未读通知数量的全局脚本 -->
    <script>
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

                    // 更新移动端顶部导航栏的未读数量
                    const mobileBadge = document.getElementById('mobile-notification-badge');
                    if (mobileBadge) {
                        if (totalUnread > 0) {
                            mobileBadge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                            mobileBadge.classList.remove('hidden');
                        } else {
                            mobileBadge.classList.add('hidden');
                        }
                    }

                    // 更新移动端底部导航栏的未读数量
                    const footerBadge = document.getElementById('footer-notification-badge');
                    if (footerBadge) {
                        if (totalUnread > 0) {
                            footerBadge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                            footerBadge.classList.remove('hidden');
                        } else {
                            footerBadge.classList.add('hidden');
                        }
                    }

                    // 更新PC端通知徽章
                    const pcNotificationBadge = document.getElementById('pc-notification-badge');
                    if (pcNotificationBadge) {
                        if (totalUnread > 0) {
                            pcNotificationBadge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                            pcNotificationBadge.classList.remove('hidden');
                        } else {
                            pcNotificationBadge.classList.add('hidden');
                        }
                    }
                }
            } catch (error) {
                console.error('加载未读通知数量失败:', error);
            }
        }

        // 加载未读私信数量
        async function loadUnreadMessageCount() {
            try {
                if (!window.currentUserInfo || !window.currentUserInfo.id) {
                    return;
                }
                const response = await fetch('/api/v1/messages/getMessageList?user_id=' + window.currentUserInfo.id);
                const result = await response.json();

                if (result.code === 200 && result.data) {
                    const list = result.data.list || [];
                    const totalUnread = list.reduce((total, chat) => total + (chat.unread_count || 0), 0);

                    // 更新PC端消息徽章
                    const pcMessageBadge = document.getElementById('pc-message-badge');
                    if (pcMessageBadge) {
                        if (totalUnread > 0) {
                            pcMessageBadge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                            pcMessageBadge.classList.remove('hidden');
                        } else {
                            pcMessageBadge.classList.add('hidden');
                        }
                    }
                }
            } catch (error) {
                console.error('加载未读私信数量失败:', error);
            }
        }

        // 页面加载完成后加载未读数量
        document.addEventListener('DOMContentLoaded', function() {
            if (window.currentUserInfo && window.currentUserInfo.id) {
                loadUnreadNotificationCount();
                loadUnreadMessageCount();
                // 每30秒刷新一次未读数量
                setInterval(function() {
                    loadUnreadNotificationCount();
                    loadUnreadMessageCount();
                }, 30000);
            }

            // 检测是否使用了 mobileFullPage block
            const mobileFullPageContainer = document.getElementById('mobile-full-page-container');
            const mobileHeader = document.querySelector('.md\\:hidden header');
            const mobileFooterNav = document.getElementById('mobile-footer-nav');
            const mobileContent = document.querySelector('.md\\:hidden .min-h-screen');

            // 检查 mobileFullPageContainer 是否有子元素
            if (mobileFullPageContainer && mobileFullPageContainer.children.length > 0) {
                // 显示全屏容器
                mobileFullPageContainer.classList.remove('hidden');
                mobileFullPageContainer.classList.add('flex', 'flex-col');

                // 隐藏移动端顶部导航
                if (mobileHeader) {
                    mobileHeader.classList.add('hidden');
                }

                // 隐藏移动端底部导航栏
                if (mobileFooterNav) {
                    mobileFooterNav.classList.add('hidden');
                }

                // 隐藏移动端主内容
                if (mobileContent) {
                    mobileContent.classList.add('hidden');
                }

                console.log('检测到 mobileFullPage block，已切换到全屏模式');
            }

            // 初始化登录和注册表单事件
            initAuthForms();
        });

        // 初始化登录和注册表单
        function initAuthForms() {
            // 登录表单提交
            const loginForm = document.getElementById('loginForm');
            if (loginForm) {
                loginForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const username = document.getElementById('loginUsername').value;
                    const password = document.getElementById('loginPassword').value;
                    const remember = document.getElementById('remember').checked ? 1 : 0;
                    
                    const errorDiv = document.getElementById('loginError');
                    errorDiv.classList.add('hidden');
                    
                    try {
                        const response = await fetch('/user/login', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                username: username,
                                password: password,
                                remember: remember
                            })
                        });

                        const result = await response.json();

                        if (result.code === 200) {
                            // 登录成功，刷新页面
                            window.location.reload();
                        } else {
                            // 显示错误信息
                            errorDiv.textContent = result.msg;
                            errorDiv.classList.remove('hidden');
                        }
                    } catch (error) {
                        console.error('登录失败:', error);
                        errorDiv.textContent = '网络错误，请稍后重试';
                        errorDiv.classList.remove('hidden');
                    }
                });
            }

            // 注册表单提交
            const registerForm = document.getElementById('registerForm');
            if (registerForm) {
                registerForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const username = document.getElementById('registerUsername').value;
                    const password = document.getElementById('registerPassword').value;
                    const nickname = document.getElementById('registerNickname').value;
                    const agreement = document.getElementById('agreement').checked;
                    
                    const errorDiv = document.getElementById('registerError');
                    const successDiv = document.getElementById('registerSuccess');
                    errorDiv.classList.add('hidden');
                    successDiv.classList.add('hidden');
                    
                    if (!agreement) {
                        errorDiv.textContent = '请阅读并同意用户协议和隐私政策';
                        errorDiv.classList.remove('hidden');
                        return;
                    }
                    
                    try {
                        const response = await fetch('/user/register', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                username: username,
                                password: password,
                                nickname: nickname
                            })
                        });

                        const result = await response.json();

                        if (result.code === 200) {
                            // 注册成功，显示成功消息并切换到登录弹窗
                            successDiv.classList.remove('hidden');
                            setTimeout(() => {
                                toggleModal('registerModal', 'loginModal');
                                successDiv.classList.add('hidden');
                            }, 2000);
                        } else {
                            // 显示错误信息
                            errorDiv.textContent = result.msg;
                            errorDiv.classList.remove('hidden');
                        }
                    } catch (error) {
                        console.error('注册失败:', error);
                        errorDiv.textContent = '网络错误，请稍后重试';
                        errorDiv.classList.remove('hidden');
                    }
                });
            }
        }

        // 切换密码显示/隐藏
        function togglePassword(inputId, toggleElement) {
            const passwordInput = document.getElementById(inputId);
            const icon = toggleElement.querySelector('i');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
        }

        // 打开弹窗
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        // 关闭弹窗
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        // 切换弹窗
        function toggleModal(currentModalId, targetModalId) {
            closeModal(currentModalId);
            openModal(targetModalId);
        }

        // 为登录和注册链接添加点击事件
        document.addEventListener('DOMContentLoaded', function() {
            // 桌面端登录链接
            const desktopLoginLink = document.querySelector('a[href="/login"]');
            if (desktopLoginLink) {
                desktopLoginLink.setAttribute('href', '#');
                desktopLoginLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    openModal('loginModal');
                });
            }

            // 桌面端注册链接
            const desktopRegisterLink = document.querySelector('a[href="/register"]');
            if (desktopRegisterLink) {
                desktopRegisterLink.setAttribute('href', '#');
                desktopRegisterLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    openModal('registerModal');
                });
            }

            // 移动端登录链接
            const mobileLoginLink = document.querySelector('.md\\:hidden a[href="/login"]');
            if (mobileLoginLink) {
                mobileLoginLink.setAttribute('href', '#');
                mobileLoginLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    openModal('loginModal');
                });
            }
        });
    </script>

</body>
</html>
