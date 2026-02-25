<?php /*a:2:{s:34:"D:\wwwroot\view\index\message.html";i:1771616041;s:38:"D:\wwwroot\view\index\main-layout.html";i:1770774785;}*/ ?>
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
                
<!-- 主内容区 -->
<main class="container mx-auto px-4 py-6 max-w-4xl">
    <!-- 标签切换 -->
    <div class="flex gap-2 mb-6 bg-bg-card rounded-lg p-1 shadow-soft">
        <button id="tab-notifications" class="flex-1 py-2 px-4 rounded-lg bg-lightBlue text-primary font-medium transition-colors" onclick="switchTab('notifications')">
            <i class="fa fa-bell mr-2"></i>通知
            <span class="ml-1 bg-primary/20 text-xs px-2 py-0.5 rounded-full" id="notification-count">0</span>
        </button>
        <button id="tab-messages" class="flex-1 py-2 px-4 rounded-lg text-text-secondary hover:bg-lightBlue hover:text-primary font-medium transition-colors" onclick="switchTab('messages')">
            <i class="fa fa-envelope mr-2"></i>私信
            <span class="ml-1 bg-border-color text-xs px-2 py-0.5 rounded-full" id="message-count">0</span>
        </button>
    </div>

    <!-- 通知列表 -->
    <div id="notifications-content" class="space-y-3">
        <div class="text-center py-8 text-gray-500">
            <i class="fa fa-spinner fa-spin text-xl mb-2"></i>
            <p>加载中...</p>
        </div>
    </div>

    <!-- 私信列表 -->
    <div id="messages-content" class="space-y-3 hidden">
        <div class="text-center py-8 text-gray-500">
            <i class="fa fa-spinner fa-spin text-xl mb-2"></i>
            <p>加载中...</p>
        </div>
    </div>
</main>

<script>
    // 获取当前用户ID（避免全局变量冲突）
    window.currentUserInfo = <?php echo isset($isLogin) && $isLogin && $currentUser ? json_encode([
        'id' => $currentUser['id'] ?? '',
        'username' => $currentUser['username'] ?? '',
        'nickname' => $currentUser['nickname'] ?? '',
        'avatar' => $currentUser['avatar'] ?? '/static/images/default-avatar.png'
    ]) : 'null'; ?>;

    if (typeof window.MESSAGE_USER_ID === 'undefined') {
        if (window.currentUserInfo && window.currentUserInfo.id) {
            window.MESSAGE_USER_ID = parseInt(window.currentUserInfo.id);
        } else {
            // 未登录则设置一个标记，不跳转
            window.MESSAGE_USER_ID = null;
        }
    }

    // 页面加载完成后获取数据
    window.addEventListener('DOMContentLoaded', function() {
        // 仅登录用户加载数据
        if (window.MESSAGE_USER_ID) {
            loadNotifications();
            loadMessages();
        } else {
            // 游客显示提示
            document.getElementById('notifications-content').innerHTML = `
                <div class="text-center py-12 text-gray-500">
                    <i class="fa fa-user-circle text-4xl mb-3"></i>
                    <p>登录后查看通知</p>
                    <a href="/login" class="inline-block mt-3 px-4 py-2 bg-primary text-white rounded-lg text-sm">立即登录</a>
                </div>
            `;
            document.getElementById('messages-content').innerHTML = `
                <div class="text-center py-12 text-gray-500">
                    <i class="fa fa-envelope text-4xl mb-3"></i>
                    <p>登录后查看私信</p>
                    <a href="/login" class="inline-block mt-3 px-4 py-2 bg-primary text-white rounded-lg text-sm">立即登录</a>
                </div>
            `;
        }
    });

    // 切换标签
    function switchTab(tabName) {

        // 隐藏所有内容
        document.getElementById('notifications-content').classList.add('hidden');
        document.getElementById('messages-content').classList.add('hidden');

        // 重置所有标签样式
        document.getElementById('tab-notifications').classList.remove('bg-lightBlue', 'text-primary');
        document.getElementById('tab-messages').classList.remove('bg-lightBlue', 'text-primary');
        document.getElementById('tab-notifications').classList.add('text-text-secondary', 'hover:bg-lightBlue', 'hover:text-primary');
        document.getElementById('tab-messages').classList.add('text-text-secondary', 'hover:bg-lightBlue', 'hover:text-primary');

        // 显示选中标签的内容
        if (tabName === 'notifications') {
            document.getElementById('notifications-content').classList.remove('hidden');
            document.getElementById('tab-notifications').classList.remove('text-text-secondary', 'hover:bg-lightBlue', 'hover:text-primary');
            document.getElementById('tab-notifications').classList.add('bg-lightBlue', 'text-primary');
            if (window.MESSAGE_USER_ID) {
                loadNotifications();
            }
        } else if (tabName === 'messages') {
            document.getElementById('messages-content').classList.remove('hidden');
            document.getElementById('tab-messages').classList.remove('text-text-secondary', 'hover:bg-lightBlue', 'hover:text-primary');
            document.getElementById('tab-messages').classList.add('bg-lightBlue', 'text-primary');
            if (window.MESSAGE_USER_ID) {
                loadMessages();
            }
        }
    }

    // 加载通知列表
    async function loadNotifications() {
        const container = document.getElementById('notifications-content');

        try {
            const response = await fetch('/notifications/getNotifications', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            });

            const result = await response.json();

            if (result.code === 200) {
                const notifications = result.data.list;

                if (notifications.length > 0) {
                    // 更新未读通知数量
                    const unreadCount = notifications.filter(n => n.is_read === 0).length;
                    document.getElementById('notification-count').textContent = unreadCount;

                    // 渲染通知列表
                    container.innerHTML = '';
                    notifications.forEach(notification => {
                        const notificationElement = createNotificationElement(notification);
                        container.appendChild(notificationElement);
                    });
                } else {
                    container.innerHTML = `
                        <div class="text-center py-12 text-gray-500">
                            <i class="fa fa-bell-o text-4xl mb-3"></i>
                            <p>暂无通知</p>
                        </div>
                    `;
                }
            } else {
                throw new Error(result.msg || '获取通知失败');
            }
        } catch (error) {
            container.innerHTML = `
                <div class="text-center py-12 text-gray-500">
                    <i class="fa fa-exclamation-circle text-xl mb-2"></i>
                    <p>加载失败，请稍后重试</p>
                </div>
            `;
        }
    }

    // 创建通知元素
    function createNotificationElement(notification) {
        const div = document.createElement('div');

        // 判断是否已读
        const isUnread = notification.is_read === 0;
        // 已读/未读标识
        const readStatusText = isUnread
            ? '<span class="inline-flex items-center text-xs font-bold text-red-500 bg-red-50 px-2 py-0.5 rounded-full"><i class="fa fa-circle text-[6px] mr-1"></i>未读</span>'
            : '<span class="inline-flex items-center text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full"><i class="fa fa-check text-[10px] mr-1"></i>已读</span>';

        // 未读通知显示不同的样式
        const itemClass = isUnread ? 'bg-blue-50 border-l-4 border-primary' : 'bg-white';

        div.className = `${itemClass} rounded-xl shadow-sm p-4 flex items-center hover:shadow-md transition-shadow cursor-pointer`;
        div.onclick = async () => {
            // 标记为已读
            if (notification.is_read === 0) {
                await markAsRead(notification.id);
            }
            // 显示弹窗
            showNotificationPopup(notification);
        };

        // 根据通知类型设置不同的图标和背景色
        const typeConfig = {
            1: { icon: 'fa-heart', color: 'from-pink-400 to-red-400', title: '点赞通知', typeStr: 'like' },
            2: { icon: 'fa-comment', color: 'from-purple-400 to-pink-400', title: '评论通知', typeStr: 'comment' },
            3: { icon: 'fa-user-plus', color: 'from-blue-400 to-cyan-400', title: '关注通知', typeStr: 'follow' },
            'like': { icon: 'fa-heart', color: 'from-pink-400 to-red-400', title: '点赞通知', typeStr: 'like' },
            'follow': { icon: 'fa-user-plus', color: 'from-blue-400 to-cyan-400', title: '关注通知', typeStr: 'follow' },
            'comment': { icon: 'fa-comment', color: 'from-purple-400 to-pink-400', title: '评论通知', typeStr: 'comment' },
            'system': { icon: 'fa-bell', color: 'from-yellow-400 to-orange-400', title: '系统通知', typeStr: 'system' },
            'private_message': { icon: 'fa-envelope', color: 'from-green-400 to-teal-400', title: '私信通知', typeStr: 'private_message' }
        };

        const config = typeConfig[notification.type] || typeConfig['system'];

        // 格式化时间
        const time = new Date(notification.create_time);
        const now = new Date();
        const diff = now - time;
        let timeText;

        if (diff < 60000) { // 1分钟内
            timeText = '刚刚';
        } else if (diff < 3600000) { // 1小时内
            timeText = Math.floor(diff / 60000) + '分钟前';
        } else if (diff < 86400000) { // 1天内
            timeText = Math.floor(diff / 3600000) + '小时前';
        } else {
            timeText = time.toLocaleDateString('zh-CN');
        }

        // 如果是关注通知，将用户名部分改为可点击链接
        let contentHtml = notification.content;
        if ((notification.type === 'follow' || notification.type === 3) && notification.sender_nickname) {
            contentHtml = notification.content.replace(
                notification.sender_nickname,
                `<a href="javascript:void(0)" onclick="event.stopPropagation(); goToUserCard(${notification.sender_id})" class="text-primary font-medium hover:underline">${notification.sender_nickname}</a>`
            );
        }

        div.innerHTML = `
            <div class="w-12 h-12 rounded-full bg-gradient-to-br ${config.color} flex items-center justify-center text-white mr-3">
                <i class="fa ${config.icon} text-xl"></i>
            </div>
            <div class="flex-1">
                <div class="font-medium mb-1">${config.title}</div>
                <div class="text-xs text-gray-500">${contentHtml}</div>
            </div>
            <div class="flex flex-col items-end gap-1">
                <div class="text-xs text-gray-400">${timeText}</div>
                ${readStatusText}
            </div>
        `;

        return div;
    }

    // 标记单条通知为已读
    async function markAsRead(notificationId) {
        try {
            const response = await fetch('/notifications/markAsRead', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_id: notificationId })
            });

            const result = await response.json();

            if (result.code === 200) {
                // 重新加载通知列表以更新未读数量
                loadNotifications();
            }
        } catch (error) {
        }
    }

    // 显示通知弹窗
    function showNotificationPopup(notification) {
        // 创建弹窗
        const popup = document.createElement('div');
        popup.className = 'fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm';

        // 格式化时间
        const time = new Date(notification.create_time);
        const timeText = time.toLocaleString('zh-CN');

        // 设置图标
        const typeConfig = {
            1: { icon: 'fa-heart', color: 'text-red-500', title: '点赞通知', typeStr: 'like' },
            2: { icon: 'fa-comment', color: 'text-purple-500', title: '评论通知', typeStr: 'comment' },
            3: { icon: 'fa-user-plus', color: 'text-blue-500', title: '关注通知', typeStr: 'follow' },
            'like': { icon: 'fa-heart', color: 'text-red-500', title: '点赞通知', typeStr: 'like' },
            'follow': { icon: 'fa-user-plus', color: 'text-blue-500', title: '关注通知', typeStr: 'follow' },
            'comment': { icon: 'fa-comment', color: 'text-purple-500', title: '评论通知', typeStr: 'comment' },
            'system': { icon: 'fa-bell', color: 'text-yellow-500', title: '系统通知', typeStr: 'system' },
            'private_message': { icon: 'fa-envelope', color: 'text-green-500', title: '私信通知', typeStr: 'private_message' }
        };
        const config = typeConfig[notification.type] || typeConfig['system'];

        // 处理内容中的用户名链接
        let contentHtml = notification.content;
        if ((notification.type === 'follow' || notification.type === 3) && notification.sender_nickname) {
            contentHtml = notification.content.replace(
                notification.sender_nickname,
                `<a href="javascript:void(0)" onclick="event.stopPropagation(); goToUserCard(${notification.sender_id})" class="text-primary font-medium hover:underline">${notification.sender_nickname}</a>`
            );
        }

        popup.innerHTML = `
            <div class="bg-white rounded-xl shadow-xl p-6 max-w-md w-full mx-4">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <i class="fa ${config.icon} ${config.color} text-2xl mr-2"></i>
                        <h3 class="text-lg font-bold">${config.title}</h3>
                    </div>
                    <button onclick="this.closest('.fixed').remove()" class="text-gray-400 hover:text-gray-600">
                        <i class="fa fa-times text-xl"></i>
                    </button>
                </div>
                <div class="text-gray-600 mb-4">${contentHtml}</div>
                <div class="text-xs text-gray-400 text-right mb-4">${timeText}</div>
                ${notification.target_id && notification.target_type === 'moment' ? `
                    <a href="/moments/${notification.target_id}" class="block w-full text-center py-2 px-4 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors">
                        查看动态
                    </a>
                ` : ''}
            </div>
        `;

        document.body.appendChild(popup);

        // 点击弹窗外部关闭
        popup.addEventListener('click', (e) => {
            if (e.target === popup) {
                popup.remove();
            }
        });
    }

    // 跳转到用户名片
    function goToUserCard(userId) {
        window.location.href = `/card/${userId}`;
    }

    // 加载私信列表
    async function loadMessages() {
        const container = document.getElementById('messages-content');

        try {
            const response = await fetch('/api/messages/getMessageList');

            const result = await response.json();

            if (result.code === 200) {
                const { list } = result.data;

                if (list.length > 0) {
                    // 更新未读私信数量
                    const unreadCount = list.reduce((total, chat) => total + chat.unread_count, 0);
                    document.getElementById('message-count').textContent = unreadCount;

                    // 渲染私信列表
                    container.innerHTML = '';
                    list.forEach(chat => {
                        const chatElement = createChatElement(chat);
                        container.appendChild(chatElement);
                    });
                } else {
                    container.innerHTML = `
                        <div class="text-center py-12 text-gray-500">
                            <i class="fa fa-envelope text-4xl mb-3"></i>
                            <p>暂无私信</p>
                        </div>
                    `;
                }
            } else {
                throw new Error(result.msg || '获取私信失败');
            }
        } catch (error) {
            if (error.message.includes('401') || error.message.includes('未登录')) {
                container.innerHTML = `
                    <div class="text-center py-12 text-gray-500">
                        <i class="fa fa-exclamation-circle text-xl mb-2"></i>
                        <p>请先登录</p>
                    </div>
                `;
            } else {
                container.innerHTML = `
                    <div class="text-center py-12 text-gray-500">
                        <i class="fa fa-exclamation-circle text-xl mb-2"></i>
                        <p>加载失败，请稍后重试</p>
                    </div>
                `;
            }
        }
    }

    // 获取消息文本
    function getMessageText(message) {
        if (!message) return '';

        const messageType = parseInt(message.message_type);
        switch (messageType) {
            case 2:
                return '[图片]';
            case 3:
                return '[视频]';
            case 5:
                return '[语音]';
            case 6:
                return '[位置]';
            default:
                return message.content || '';
        }
    }

    // 创建私信元素
    function createChatElement(chat) {
        const div = document.createElement('div');
        div.className = 'bg-white rounded-xl shadow-sm p-4 flex items-center hover:shadow-md transition-shadow cursor-pointer';
        div.onclick = () => {
            // 标记该用户的聊天记录为已读
            markMessagesAsRead(chat.user_id);
            // 跳转到聊天页面
            window.location.href = `/chat?user_id=${chat.user_id}`;
        };

        // 格式化时间
        const time = new Date((chat.last_message?.create_time || 0) * 1000);
        const now = new Date();
        const diff = now - time;
        let timeText;

        if (diff < 60000) { // 1分钟内
            timeText = '刚刚';
        } else if (diff < 3600000) { // 1小时内
            timeText = Math.floor(diff / 60000) + '分钟前';
        } else if (diff < 86400000) { // 1天内
            timeText = Math.floor(diff / 3600000) + '小时前';
        } else {
            timeText = time.toLocaleDateString('zh-CN');
        }

        // 判断是否已读
        const isUnread = chat.unread_count > 0;
        // 更醒目的已读/未读标识
        const readStatusText = isUnread
            ? '<span class="inline-flex items-center text-xs font-bold text-red-500 bg-red-50 px-2 py-0.5 rounded-full"><i class="fa fa-circle text-[6px] mr-1"></i>未读</span>'
            : '<span class="inline-flex items-center text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full"><i class="fa fa-check text-[10px] mr-1"></i>已读</span>';

        // 设置头像URL
        const avatarUrl = chat.avatar || '/static/images/default-avatar.png';

        // 未读消息显示不同的样式
        const itemClass = isUnread ? 'bg-blue-50 border-l-4 border-primary' : 'bg-white';

        div.className = `${itemClass} rounded-xl shadow-sm p-4 flex items-center hover:shadow-md transition-shadow cursor-pointer`;

        div.innerHTML = `
            <img src="${avatarUrl}" alt="用户头像" class="w-12 h-12 rounded-full object-cover mr-3">
            <div class="flex-1 min-w-0">
                <div class="flex justify-between items-start mb-1">
                    <div class="font-medium text-ellipsis whitespace-nowrap overflow-hidden ${isUnread ? 'text-primary' : ''}">${chat.nickname}</div>
                    <div class="text-xs text-gray-400">${timeText}</div>
                </div>
                <div class="flex justify-between items-center">
                    <div class="text-xs ${isUnread ? 'text-gray-800 font-medium' : 'text-gray-500'} text-ellipsis whitespace-nowrap overflow-hidden">${getMessageText(chat.last_message)}</div>
                    <div class="flex items-center gap-2">
                        ${readStatusText}
                        ${chat.unread_count > 0 ? `<span class="text-xs bg-primary text-white px-2 py-0.5 rounded-full font-bold">${chat.unread_count}</span>` : ''}
                    </div>
                </div>
            </div>
        `;

        return div;
    }

    // 打开聊天窗口
    function openChat(userId) {
        // 标记该用户的聊天记录为已读
        markMessagesAsRead(userId);
        // 跳转到聊天页面
        window.location.href = `/chat?user_id=${userId}`;
    }

    // 标记与某用户的消息为已读
    async function markMessagesAsRead(userId) {
        try {
            const response = await fetch('/api/messages/markAsRead', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sender_id: userId })
            });

            if (response.ok) {
                // 更新页面内的未读数量
                const messageCount = document.getElementById('message-count');
                const currentCount = parseInt(messageCount.textContent) || 0;
                if (currentCount > 0) {
                    messageCount.textContent = Math.max(0, currentCount - 1);
                }

                // 更新PC端导航栏的消息徽章
                const pcMessageBadge = document.getElementById('pc-message-badge');
                if (pcMessageBadge) {
                    const badgeCount = parseInt(pcMessageBadge.textContent) || 0;
                    if (badgeCount > 0) {
                        const newCount = Math.max(0, badgeCount - 1);
                        pcMessageBadge.textContent = newCount > 99 ? '99+' : newCount;
                        if (newCount === 0) {
                            pcMessageBadge.classList.add('hidden');
                        }
                    }
                }
            }
        } catch (error) {
        }
    }

    // 标记所有已读
    async function markAllRead() {
        try {
            const response = await fetch('/notifications/markAsRead', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ all: true })
            });

            const result = await response.json();

            if (result.code === 200) {
                document.getElementById('notification-count').textContent = '0';
                // 重新加载通知列表
                loadNotifications();
                alert('已标记所有通知为已读');
            } else {
                throw new Error(result.msg || '操作失败');
            }
        } catch (error) {
            alert('标记已读失败，请稍后重试');
        }
    }
</script>

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
                
<!-- 移动端主内容区 -->
<main class="container mx-auto px-4 py-4">
    <!-- 标签切换 -->
    <div class="flex gap-2 mb-4 bg-bg-card rounded-lg p-1 shadow-soft">
        <button id="mobile-tab-notifications" class="flex-1 py-2 px-3 rounded-lg bg-lightBlue text-primary font-medium transition-colors text-sm" onclick="mobileSwitchTab('notifications')">
            <i class="fa fa-bell mr-1"></i>通知
            <span class="ml-1 bg-primary/20 text-xs px-2 py-0.5 rounded-full" id="mobile-notification-count">0</span>
        </button>
        <button id="mobile-tab-messages" class="flex-1 py-2 px-3 rounded-lg text-text-secondary hover:bg-lightBlue hover:text-primary font-medium transition-colors text-sm" onclick="mobileSwitchTab('messages')">
            <i class="fa fa-envelope mr-1"></i>私信
            <span class="ml-1 bg-border-color text-xs px-2 py-0.5 rounded-full" id="mobile-message-count">0</span>
        </button>
    </div>

    <!-- 通知列表 -->
    <div id="mobile-notifications-content" class="space-y-2">
        <div class="text-center py-8 text-gray-500">
            <i class="fa fa-spinner fa-spin text-xl mb-2"></i>
            <p class="text-sm">加载中...</p>
        </div>
    </div>

    <!-- 私信列表 -->
    <div id="mobile-messages-content" class="space-y-2 hidden">
        <div class="text-center py-8 text-gray-500">
            <i class="fa fa-spinner fa-spin text-xl mb-2"></i>
            <p class="text-sm">加载中...</p>
        </div>
    </div>
</main>

<script>
    // 获取当前用户ID（避免全局变量冲突）
    window.currentUserInfo = <?php echo isset($isLogin) && $isLogin && $currentUser ? json_encode([
        'id' => $currentUser['id'] ?? '',
        'username' => $currentUser['username'] ?? '',
        'nickname' => $currentUser['nickname'] ?? '',
        'avatar' => $currentUser['avatar'] ?? '/static/images/default-avatar.png'
    ]) : 'null'; ?>;

    if (typeof window.MESSAGE_USER_ID === 'undefined') {
        if (window.currentUserInfo && window.currentUserInfo.id) {
            window.MESSAGE_USER_ID = parseInt(window.currentUserInfo.id);
        } else {
            // 未登录则设置一个标记，不跳转
            window.MESSAGE_USER_ID = null;
        }
    }

    // 页面加载完成后获取数据
    window.addEventListener('DOMContentLoaded', function() {
        // 仅登录用户加载数据
        if (window.MESSAGE_USER_ID) {
            mobileLoadNotifications();
            mobileLoadMessages();
        } else {
            // 游客显示提示
            document.getElementById('mobile-notifications-content').innerHTML = `
                <div class="text-center py-12 text-gray-500">
                    <i class="fa fa-user-circle text-4xl mb-3"></i>
                    <p class="text-sm">登录后查看通知</p>
                    <a href="/login" class="inline-block mt-3 px-4 py-2 bg-primary text-white rounded-lg text-sm">立即登录</a>
                </div>
            `;
            document.getElementById('mobile-messages-content').innerHTML = `
                <div class="text-center py-12 text-gray-500">
                    <i class="fa fa-envelope text-4xl mb-3"></i>
                    <p class="text-sm">登录后查看私信</p>
                    <a href="/login" class="inline-block mt-3 px-4 py-2 bg-primary text-white rounded-lg text-sm">立即登录</a>
                </div>
            `;
        }
    });

    // 切换标签（移动端）
    function mobileSwitchTab(tabName) {
        // 隐藏所有内容
        document.getElementById('mobile-notifications-content').classList.add('hidden');
        document.getElementById('mobile-messages-content').classList.add('hidden');

        // 重置所有标签样式
        document.getElementById('mobile-tab-notifications').classList.remove('bg-lightBlue', 'text-primary');
        document.getElementById('mobile-tab-messages').classList.remove('bg-lightBlue', 'text-primary');
        document.getElementById('mobile-tab-notifications').classList.add('text-text-secondary', 'hover:bg-lightBlue', 'hover:text-primary');
        document.getElementById('mobile-tab-messages').classList.add('text-text-secondary', 'hover:bg-lightBlue', 'hover:text-primary');

        // 显示选中标签的内容
        if (tabName === 'notifications') {
            document.getElementById('mobile-notifications-content').classList.remove('hidden');
            document.getElementById('mobile-tab-notifications').classList.remove('text-text-secondary', 'hover:bg-lightBlue', 'hover:text-primary');
            document.getElementById('mobile-tab-notifications').classList.add('bg-lightBlue', 'text-primary');
            if (window.MESSAGE_USER_ID) {
                mobileLoadNotifications();
            }
        } else if (tabName === 'messages') {
            document.getElementById('mobile-messages-content').classList.remove('hidden');
            document.getElementById('mobile-tab-messages').classList.remove('text-text-secondary', 'hover:bg-lightBlue', 'hover:text-primary');
            document.getElementById('mobile-tab-messages').classList.add('bg-lightBlue', 'text-primary');
            if (window.MESSAGE_USER_ID) {
                mobileLoadMessages();
            }
        }
    }

    // 加载通知列表（移动端）
    async function mobileLoadNotifications() {
        const container = document.getElementById('mobile-notifications-content');

        try {
            const response = await fetch('/notifications/getNotifications', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            });

            const result = await response.json();

            if (result.code === 200) {
                const notifications = result.data.list;

                if (notifications.length > 0) {
                    // 更新未读通知数量
                    const unreadCount = notifications.filter(n => n.is_read === 0).length;
                    document.getElementById('mobile-notification-count').textContent = unreadCount;

                    // 渲染通知列表
                    container.innerHTML = '';
                    notifications.forEach(notification => {
                        const notificationElement = createMobileNotificationElement(notification);
                        container.appendChild(notificationElement);
                    });
                } else {
                    container.innerHTML = `
                        <div class="text-center py-12 text-gray-500">
                            <i class="fa fa-bell-o text-4xl mb-3"></i>
                            <p class="text-sm">暂无通知</p>
                        </div>
                    `;
                }
            } else {
                throw new Error(result.msg || '获取通知失败');
            }
        } catch (error) {
            container.innerHTML = `
                <div class="text-center py-12 text-gray-500">
                    <i class="fa fa-exclamation-circle text-xl mb-2"></i>
                    <p class="text-sm">加载失败，请稍后重试</p>
                </div>
            `;
        }
    }

    // 创建通知元素（移动端）
    function createMobileNotificationElement(notification) {
        const div = document.createElement('div');

        // 判断是否已读
        const isUnread = notification.is_read === 0;
        // 已读/未读标识
        const readStatusText = isUnread
            ? '<span class="inline-flex items-center text-xs font-bold text-red-500 bg-red-50 px-1.5 py-0.5 rounded-full"><i class="fa fa-circle text-[5px] mr-1"></i>未读</span>'
            : '<span class="inline-flex items-center text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded-full"><i class="fa fa-check text-[8px] mr-1"></i>已读</span>';

        // 未读通知显示不同的样式
        const itemClass = isUnread ? 'bg-blue-50 border-l-4 border-primary' : 'bg-white';

        div.className = `${itemClass} rounded-lg shadow-sm p-3 flex items-center hover:shadow-md transition-shadow cursor-pointer`;
        div.onclick = async () => {
            // 标记为已读
            if (notification.is_read === 0) {
                await markAsRead(notification.id);
            }
            // 显示弹窗
            showNotificationPopup(notification);
        };

        // 根据通知类型设置不同的图标和背景色
        const typeConfig = {
            1: { icon: 'fa-heart', color: 'from-pink-400 to-red-400', title: '点赞通知', typeStr: 'like' },
            2: { icon: 'fa-comment', color: 'from-purple-400 to-pink-400', title: '评论通知', typeStr: 'comment' },
            3: { icon: 'fa-user-plus', color: 'from-blue-400 to-cyan-400', title: '关注通知', typeStr: 'follow' },
            'like': { icon: 'fa-heart', color: 'from-pink-400 to-red-400', title: '点赞通知', typeStr: 'like' },
            'follow': { icon: 'fa-user-plus', color: 'from-blue-400 to-cyan-400', title: '关注通知', typeStr: 'follow' },
            'comment': { icon: 'fa-comment', color: 'from-purple-400 to-pink-400', title: '评论通知', typeStr: 'comment' },
            'system': { icon: 'fa-bell', color: 'from-yellow-400 to-orange-400', title: '系统通知', typeStr: 'system' },
            'private_message': { icon: 'fa-envelope', color: 'from-green-400 to-teal-400', title: '私信通知', typeStr: 'private_message' }
        };

        const config = typeConfig[notification.type] || typeConfig['system'];

        // 格式化时间
        const time = new Date(notification.create_time);
        const now = new Date();
        const diff = now - time;
        let timeText;

        if (diff < 60000) { // 1分钟内
            timeText = '刚刚';
        } else if (diff < 3600000) { // 1小时内
            timeText = Math.floor(diff / 60000) + '分钟前';
        } else if (diff < 86400000) { // 1天内
            timeText = Math.floor(diff / 3600000) + '小时前';
        } else {
            timeText = time.toLocaleDateString('zh-CN');
        }

        // 如果是关注通知，将用户名部分改为可点击链接
        let contentHtml = notification.content;
        if ((notification.type === 'follow' || notification.type === 3) && notification.sender_nickname) {
            contentHtml = notification.content.replace(
                notification.sender_nickname,
                `<a href="javascript:void(0)" onclick="event.stopPropagation(); goToUserCard(${notification.sender_id})" class="text-primary font-medium hover:underline">${notification.sender_nickname}</a>`
            );
        }

        div.innerHTML = `
            <div class="w-10 h-10 rounded-full bg-gradient-to-br ${config.color} flex items-center justify-center text-white mr-3 flex-shrink-0">
                <i class="fa ${config.icon} text-lg"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="font-medium text-sm mb-1">${config.title}</div>
                <div class="text-xs text-gray-500 text-ellipsis whitespace-nowrap overflow-hidden">${contentHtml}</div>
            </div>
            <div class="flex flex-col items-end gap-0.5 flex-shrink-0 ml-2">
                <div class="text-xs text-gray-400">${timeText}</div>
                ${readStatusText}
            </div>
        `;

        return div;
    }

    // 加载私信列表（移动端）
    async function mobileLoadMessages() {
        const container = document.getElementById('mobile-messages-content');

        try {
            const response = await fetch('/api/messages/getMessageList');

            const result = await response.json();

            if (result.code === 200) {
                const { list } = result.data;

                if (list.length > 0) {
                    // 更新未读私信数量
                    const unreadCount = list.reduce((total, chat) => total + chat.unread_count, 0);
                    document.getElementById('mobile-message-count').textContent = unreadCount;

                    // 渲染私信列表
                    container.innerHTML = '';
                    list.forEach(chat => {
                        const chatElement = createMobileChatElement(chat);
                        container.appendChild(chatElement);
                    });
                } else {
                    container.innerHTML = `
                        <div class="text-center py-12 text-gray-500">
                            <i class="fa fa-envelope text-4xl mb-3"></i>
                            <p class="text-sm">暂无私信</p>
                        </div>
                    `;
                }
            } else {
                throw new Error(result.msg || '获取私信失败');
            }
        } catch (error) {
            if (error.message.includes('401') || error.message.includes('未登录')) {
                container.innerHTML = `
                    <div class="text-center py-12 text-gray-500">
                        <i class="fa fa-exclamation-circle text-xl mb-2"></i>
                        <p class="text-sm">请先登录</p>
                    </div>
                `;
            } else {
                container.innerHTML = `
                    <div class="text-center py-12 text-gray-500">
                        <i class="fa fa-exclamation-circle text-xl mb-2"></i>
                        <p class="text-sm">加载失败，请稍后重试</p>
                    </div>
                `;
            }
        }
    }

    // 创建私信元素（移动端）
    function createMobileChatElement(chat) {
        const div = document.createElement('div');

        // 判断是否已读
        const isUnread = chat.unread_count > 0;
        // 更醒目的已读/未读标识
        const readStatusText = isUnread
            ? '<span class="inline-flex items-center text-xs font-bold text-red-500 bg-red-50 px-1.5 py-0.5 rounded-full"><i class="fa fa-circle text-[5px] mr-1"></i>未读</span>'
            : '<span class="inline-flex items-center text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded-full"><i class="fa fa-check text-[8px] mr-1"></i>已读</span>';

        // 未读消息显示不同的样式
        const itemClass = isUnread ? 'bg-blue-50 border-l-4 border-primary' : 'bg-white';

        div.className = `${itemClass} rounded-lg shadow-sm p-3 flex items-center hover:shadow-md transition-shadow cursor-pointer`;
        div.onclick = () => {
            // 标记该用户的聊天记录为已读
            markMessagesAsRead(chat.user_id);
            // 跳转到聊天页面
            window.location.href = `/chat?user_id=${chat.user_id}`;
        };

        // 格式化时间
        const time = new Date((chat.last_message?.create_time || 0) * 1000);
        const now = new Date();
        const diff = now - time;
        let timeText;

        if (diff < 60000) { // 1分钟内
            timeText = '刚刚';
        } else if (diff < 3600000) { // 1小时内
            timeText = Math.floor(diff / 60000) + '分钟前';
        } else if (diff < 86400000) { // 1天内
            timeText = Math.floor(diff / 3600000) + '小时前';
        } else {
            timeText = time.toLocaleDateString('zh-CN');
        }

        // 设置头像URL
        const avatarUrl = chat.avatar || '/static/images/default-avatar.png';

        div.innerHTML = `
            <img src="${avatarUrl}" alt="用户头像" class="w-10 h-10 rounded-full object-cover mr-3 flex-shrink-0">
            <div class="flex-1 min-w-0">
                <div class="flex justify-between items-start mb-1">
                    <div class="font-medium text-sm text-ellipsis whitespace-nowrap overflow-hidden ${isUnread ? 'text-primary' : ''}">${chat.nickname}</div>
                    <div class="text-xs text-gray-400 flex-shrink-0 ml-2">${timeText}</div>
                </div>
                <div class="flex justify-between items-center">
                    <div class="text-xs ${isUnread ? 'text-gray-800 font-medium' : 'text-gray-500'} text-ellipsis whitespace-nowrap overflow-hidden">${getMessageText(chat.last_message)}</div>
                    <div class="flex items-center gap-1.5 flex-shrink-0 ml-1">
                        ${readStatusText}
                        ${chat.unread_count > 0 ? `<span class="text-xs bg-primary text-white px-1.5 py-0.5 rounded-full font-bold">${chat.unread_count}</span>` : ''}
                    </div>
                </div>
            </div>
        `;

        return div;
    }

    // 获取消息文本（移动端）
    function getMessageText(message) {
        if (!message) return '';

        const messageType = parseInt(message.message_type);
        switch (messageType) {
            case 2:
                return '[图片]';
            case 3:
                return '[视频]';
            case 5:
                return '[语音]';
            case 6:
                return '[位置]';
            default:
                return message.content || '';
        }
    }

    // 标记与某用户的消息为已读（移动端）
    async function markMessagesAsRead(userId) {
        try {
            const response = await fetch('/api/messages/markAsRead', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sender_id: userId })
            });

            if (response.ok) {
                // 更新页面内的未读数量
                const messageCount = document.getElementById('mobile-message-count');
                const currentCount = parseInt(messageCount.textContent) || 0;
                if (currentCount > 0) {
                    messageCount.textContent = Math.max(0, currentCount - 1);
                }

                // 更新PC端导航栏的消息徽章（移动端也能访问到）
                const pcMessageBadge = document.getElementById('pc-message-badge');
                if (pcMessageBadge) {
                    const badgeCount = parseInt(pcMessageBadge.textContent) || 0;
                    if (badgeCount > 0) {
                        const newCount = Math.max(0, badgeCount - 1);
                        pcMessageBadge.textContent = newCount > 99 ? '99+' : newCount;
                        if (newCount === 0) {
                            pcMessageBadge.classList.add('hidden');
                        }
                    }
                }
            }
        } catch (error) {
        }
    }

    // 标记单条通知为已读（移动端）
    async function markAsRead(notificationId) {
        try {
            const response = await fetch('/notifications/markAsRead', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_id: notificationId })
            });

            const result = await response.json();

            if (result.code === 200) {
                // 重新加载通知列表以更新未读数量
                mobileLoadNotifications();
            }
        } catch (error) {
        }
    }

    // 显示通知弹窗（移动端）
    function showNotificationPopup(notification) {
        // 创建弹窗
        const popup = document.createElement('div');
        popup.className = 'fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm';

        // 格式化时间
        const time = new Date(notification.create_time);
        const timeText = time.toLocaleString('zh-CN');

        // 设置图标
        const typeConfig = {
            1: { icon: 'fa-heart', color: 'text-red-500', title: '点赞通知', typeStr: 'like' },
            2: { icon: 'fa-comment', color: 'text-purple-500', title: '评论通知', typeStr: 'comment' },
            3: { icon: 'fa-user-plus', color: 'text-blue-500', title: '关注通知', typeStr: 'follow' },
            'like': { icon: 'fa-heart', color: 'text-red-500', title: '点赞通知', typeStr: 'like' },
            'follow': { icon: 'fa-user-plus', color: 'text-blue-500', title: '关注通知', typeStr: 'follow' },
            'comment': { icon: 'fa-comment', color: 'text-purple-500', title: '评论通知', typeStr: 'comment' },
            'system': { icon: 'fa-bell', color: 'text-yellow-500', title: '系统通知', typeStr: 'system' },
            'private_message': { icon: 'fa-envelope', color: 'text-green-500', title: '私信通知', typeStr: 'private_message' }
        };
        const config = typeConfig[notification.type] || typeConfig['system'];

        // 处理内容中的用户名链接
        let contentHtml = notification.content;
        if ((notification.type === 'follow' || notification.type === 3) && notification.sender_nickname) {
            contentHtml = notification.content.replace(
                notification.sender_nickname,
                `<a href="javascript:void(0)" onclick="event.stopPropagation(); goToUserCard(${notification.sender_id})" class="text-primary font-medium hover:underline">${notification.sender_nickname}</a>`
            );
        }

        popup.innerHTML = `
            <div class="bg-white rounded-xl shadow-xl p-6 max-w-md w-full mx-4">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <i class="fa ${config.icon} ${config.color} text-2xl mr-2"></i>
                        <h3 class="text-lg font-bold">${config.title}</h3>
                    </div>
                    <button onclick="this.closest('.fixed').remove()" class="text-gray-400 hover:text-gray-600">
                        <i class="fa fa-times text-xl"></i>
                    </button>
                </div>
                <div class="text-gray-600 mb-4">${contentHtml}</div>
                <div class="text-xs text-gray-400 text-right mb-4">${timeText}</div>
                ${notification.target_id && notification.target_type === 'moment' ? `
                    <a href="/moments/${notification.target_id}" class="block w-full text-center py-2 px-4 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors">
                        查看动态
                    </a>
                ` : ''}
            </div>
        `;

        document.body.appendChild(popup);

        // 点击弹窗外部关闭
        popup.addEventListener('click', (e) => {
            if (e.target === popup) {
                popup.remove();
            }
        });
    }

    // 跳转到用户名片（移动端）
    function goToUserCard(userId) {
        window.location.href = `/card/${userId}`;
    }
</script>

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
