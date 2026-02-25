<?php /*a:2:{s:35:"D:\wwwroot\view\index\settings.html";i:1771618890;s:38:"D:\wwwroot\view\index\main-layout.html";i:1770774785;}*/ ?>
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
                
<style>
    /* 隐藏左下角的用户信息区域 */
    aside > div:last-child {
        display: none !important;
    }
    .toast-container {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 1000;
        max-width: 400px;
    }
    .toast {
        background: white;
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 12px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        display: flex;
        align-items: center;
        gap: 12px;
        transform: translateX(100%);
        transition: transform 0.3s ease-out, opacity 0.3s ease-out;
        opacity: 0;
    }
    .toast.show {
        transform: translateX(0);
        opacity: 1;
    }
    .toast.hide {
        transform: translateX(100%);
        opacity: 0;
    }
    .toast-success {
        border-left: 4px solid #00B42A;
    }
    .toast-error {
        border-left: 4px solid #F53F3F;
    }
    .toast-warning {
        border-left: 4px solid #FF7D00;
    }
    .toast-info {
        border-left: 4px solid #4080FF;
    }
    .toast-icon {
        font-size: 20px;
        width: 24px;
        text-align: center;
    }
    .toast-success .toast-icon { color: #00B42A; }
    .toast-error .toast-icon { color: #F53F3F; }
    .toast-warning .toast-icon { color: #FF7D00; }
    .toast-info .toast-icon { color: #4080FF; }
    .toast-content {
        flex: 1;
    }
    .toast-title {
        font-weight: 600;
        font-size: 14px;
        margin-bottom: 2px;
        color: #1D2129;
    }
    .toast-message {
        font-size: 13px;
        color: #86909C;
        line-height: 1.4;
    }
    .toast-close {
        background: none;
        border: none;
        color: #86909C;
        cursor: pointer;
        padding: 4px;
        border-radius: 4px;
        transition: background-color 0.2s;
    }
    .toast-close:hover {
        background-color: #F2F3F5;
        color: #1D2129;
    }

    /* 设置页面美化样式 */
    .settings-container {
        max-width: 1200px;
        margin-right: auto;
        margin-left: 0;
    }
    .settings-layout {
        display: grid;
        grid-template-columns: 280px 1fr;
        gap: 16px;
        align-items: start;
    }
    @media (max-width: 1024px) {
        .settings-layout {
            grid-template-columns: 1fr;
        }
        .settings-sidebar {
            display: none;
        }
    }
    .settings-sidebar {
        background: white;
        border-radius: 16px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        overflow: hidden;
        position: sticky;
        top: 24px;
    }
    .sidebar-menu-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        cursor: pointer;
        transition: all 0.2s;
        border-left: 3px solid transparent;
        color: #4E5969;
        font-weight: 500;
    }
    .sidebar-menu-item:hover {
        background: #F7F8FA;
        color: #1D2129;
    }
    .sidebar-menu-item.active {
        background: linear-gradient(135deg, #F2F8FF 0%, #EDF4FF 100%);
        border-left-color: #4080FF;
        color: #1D2129;
    }
    .sidebar-menu-item i {
        width: 20px;
        text-align: center;
        color: #86909C;
    }
    .sidebar-menu-item.active i {
        color: #4080FF;
    }
    .settings-content {
        display: none;
        animation: fadeIn 0.3s ease;
    }
    .settings-content.active {
        display: block;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .settings-card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        padding: 32px;
        margin-bottom: 20px;
    }
    .settings-title {
        font-size: 20px;
        font-weight: 600;
        color: #1D2129;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .settings-title i {
        width: 28px;
        height: 28px;
        background: linear-gradient(135deg, #4080FF 0%, #3366CC 100%);
        color: white;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
    }
    .form-group {
        margin-bottom: 20px;
    }
    .form-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #1D2129;
        margin-bottom: 8px;
    }
    .form-label .required {
        color: #F53F3F;
        margin-left: 4px;
    }
    .form-input,
    .form-select,
    .form-textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #E5E6EB;
        border-radius: 8px;
        font-size: 14px;
        color: #1D2129;
        background: #F7F8FA;
        transition: all 0.2s;
    }
    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
        outline: none;
        border-color: #4080FF;
        background: white;
        box-shadow: 0 0 0 3px rgba(64, 128, 255, 0.1);
    }
    .form-input::placeholder,
    .form-textarea::placeholder {
        color: #C9CDD4;
    }
    .form-textarea {
        resize: vertical;
        min-height: 80px;
    }
    .form-hint {
        font-size: 12px;
        color: #86909C;
        margin-top: 6px;
    }
    .avatar-section {
        display: flex;
        align-items: center;
        gap: 20px;
        padding: 24px;
        background: linear-gradient(135deg, #F7F8FA 0%, #EDF0F3 100%);
        border-radius: 12px;
        margin-bottom: 24px;
    }
    .avatar-preview {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        border: 4px solid white;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        object-fit: cover;
    }
    .avatar-info h4 {
        font-size: 16px;
        font-weight: 600;
        color: #1D2129;
        margin-bottom: 6px;
    }
    .avatar-info p {
        font-size: 13px;
        color: #86909C;
        margin-bottom: 12px;
    }
    .btn {
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
    }
    .btn-primary {
        background: linear-gradient(135deg, #4080FF 0%, #3366CC 100%);
        color: white;
        box-shadow: 0 2px 8px rgba(64, 128, 255, 0.3);
    }
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(64, 128, 255, 0.4);
    }
    .btn-secondary {
        background: #F7F8FA;
        color: #4E5969;
    }
    .btn-secondary:hover {
        background: #E5E6EB;
    }
    .radio-group {
        display: flex;
        gap: 24px;
    }
    .radio-item {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }
    .radio-item input[type="radio"] {
        width: 18px;
        height: 18px;
        accent-color: #4080FF;
    }
    .section-divider {
        height: 1px;
        background: linear-gradient(90deg, #E5E6EB 0%, transparent 100%);
        margin: 32px 0;
    }
    .info-card {
        background: linear-gradient(135deg, #E8F3FF 0%, #F0F7FF 100%);
        border-radius: 12px;
        padding: 20px;
        margin-top: 24px;
    }
    .info-card-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        color: #1D2129;
        margin-bottom: 12px;
    }
    .info-card-title i {
        color: #4080FF;
    }
    .info-card-content {
        font-size: 13px;
        color: #4E5969;
        line-height: 1.6;
    }
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-top: 24px;
    }
    .quick-action-item {
        padding: 20px;
        background: #F7F8FA;
        border-radius: 12px;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s;
    }
    .quick-action-item:hover {
        background: #E8F3FF;
        transform: translateY(-2px);
    }
    .quick-action-item i {
        font-size: 24px;
        color: #4080FF;
        margin-bottom: 12px;
    }
    .quick-action-item span {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #1D2129;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        background: #E8FFEA;
        color: #00B42A;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
    }
    .status-badge i {
        font-size: 12px;
    }
</style>

<script>
    // Cookie 工具函数
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }

    function setCookie(name, value, days) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        const expires = '; expires=' + date.toUTCString();
        document.cookie = name + '=' + value + expires + '; path=/';
    }

    // Toast 功能实现
    function showToast(type, title, message) {
        // 创建 Toast 元素
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        // 设置 Toast 内容
        toast.innerHTML = `
            <div class="toast-icon">
                <i class="fa fa-${getIconByType(type)}"></i>
            </div>
            <div class="toast-content">
                <div class="toast-title">${title}</div>
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close" onclick="this.parentElement.remove()">
                <i class="fa fa-times"></i>
            </button>
        `;

        // 添加到容器
        const container = document.getElementById('toastContainer');
        container.appendChild(toast);

        // 显示 Toast
        setTimeout(() => toast.classList.add('show'), 10);

        // 自动关闭
        setTimeout(() => {
            toast.classList.remove('show');
            toast.classList.add('hide');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // 获取图标
    function getIconByType(type) {
        const icons = {
            success: 'check-circle',
            error: 'times-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        return icons[type] || 'info-circle';
    }

    // 头像预览
    function previewAvatar(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const avatarPreview = document.getElementById('avatarPreview');
                const avatarPreviewMobile = document.getElementById('avatarPreviewMobile');
                if (avatarPreview) avatarPreview.src = e.target.result;
                if (avatarPreviewMobile) avatarPreviewMobile.src = e.target.result;
                showToast('success', '头像预览', '头像已成功预览');
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // 保存基本设置和隐私设置
    async function saveSettings() {
        const nicknameInput = document.getElementById('nickname');
        const nicknameInputMobile = document.getElementById('nicknameMobile');
        const nickname = nicknameInput ? nicknameInput.value : (nicknameInputMobile ? nicknameInputMobile.value : '');

        const gender = document.querySelector('input[name="gender"]:checked')?.value;

        const birthdayInput = document.getElementById('birthday');
        const birthdayInputMobile = document.getElementById('birthdayMobile');
        const birthday = birthdayInput ? birthdayInput.value : (birthdayInputMobile ? birthdayInputMobile.value : '');

        const occupationInput = document.getElementById('occupation');
        const occupationInputMobile = document.getElementById('occupationMobile');
        const occupation = occupationInput ? occupationInput.value : (occupationInputMobile ? occupationInputMobile.value : '');

        const bioInput = document.getElementById('bio');
        const bioInputMobile = document.getElementById('bioMobile');
        const bio = bioInput ? bioInput.value : (bioInputMobile ? bioInputMobile.value : '');

        const cardPrivacyInput = document.getElementById('cardPrivacy');
        const cardPrivacyInputMobile = document.getElementById('momentPrivacyMobile2');
        const cardPrivacy = cardPrivacyInput ? cardPrivacyInput.value : (cardPrivacyInputMobile ? cardPrivacyInputMobile.value : '');

        if (!nickname.trim()) {
            showToast('error', '保存失败', '昵称不能为空');
            return;
        }

        const formData = new FormData();
        formData.append('nickname', nickname);
        formData.append('gender', gender);
        formData.append('birthday', birthday);
        formData.append('occupation', occupation);
        formData.append('bio', bio);
        formData.append('card_privacy', cardPrivacy);

        const avatarInput = document.getElementById('avatarInput');
        const avatarInputMobile = document.getElementById('avatarInputMobile');
        if (avatarInput && avatarInput.files.length > 0) {
            formData.append('avatar', avatarInput.files[0]);
        }
        if (avatarInputMobile && avatarInputMobile.files.length > 0) {
            formData.append('avatar', avatarInputMobile.files[0]);
        }

        showToast('info', '保存中', '正在保存您的基本设置...');

        try {
            const response = await fetch('/user/updateSettings', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.code === 200) {
                setCookie('nickname', nickname, 7);
                showToast('success', '保存成功', '您的基本设置已成功保存');
            } else {
                showToast('error', '保存失败', result.msg || '保存失败，请稍后重试');
            }
        } catch (error) {
            console.error('保存失败:', error);
            showToast('error', '保存失败', '网络错误，请稍后重试');
        }
    }

    // 保存账户安全设置（邮箱）
    async function saveAccountSecurity() {
        const emailInput = document.getElementById('email');
        const emailInputMobile = document.getElementById('emailMobile');
        const email = emailInput ? emailInput.value : (emailInputMobile ? emailInputMobile.value : '');

        if (email && !validateEmail(email)) {
            showToast('error', '保存失败', '邮箱格式不正确');
            return;
        }

        const formData = new FormData();
        formData.append('email', email);

        showToast('info', '保存中', '正在保存您的账户安全设置...');

        try {
            const response = await fetch('/user/updateSettings', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.code === 200) {
                showToast('success', '保存成功', '您的账户安全设置已成功保存');
            } else {
                showToast('error', '保存失败', result.msg || '保存失败，请稍后重试');
            }
        } catch (error) {
            console.error('保存失败:', error);
            showToast('error', '保存失败', '网络错误，请稍后重试');
        }
    }

    // 修改密码
    async function changePassword() {
        const oldPasswordInput = document.getElementById('oldPassword');
        const oldPasswordInputMobile = document.getElementById('oldPasswordMobile');
        const oldPassword = oldPasswordInput ? oldPasswordInput.value : (oldPasswordInputMobile ? oldPasswordInputMobile.value : '');

        const newPasswordInput = document.getElementById('newPassword');
        const newPasswordInputMobile = document.getElementById('newPasswordMobile');
        const newPassword = newPasswordInput ? newPasswordInput.value : (newPasswordInputMobile ? newPasswordInputMobile.value : '');

        const confirmPasswordInput = document.getElementById('confirmPassword');
        const confirmPasswordInputMobile = document.getElementById('confirmPasswordMobile');
        const confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : (confirmPasswordInputMobile ? confirmPasswordInputMobile.value : '');

        if (!oldPassword || !newPassword || !confirmPassword) {
            showToast('error', '修改失败', '密码不能为空');
            return;
        }

        if (newPassword.length < 6) {
            showToast('error', '修改失败', '新密码长度不能少于6位');
            return;
        }

        if (newPassword !== confirmPassword) {
            showToast('error', '修改失败', '两次输入的密码不一致');
            return;
        }

        showToast('info', '修改中', '正在修改您的密码...');

        try {
            const response = await fetch('/user/changePassword', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    old_password: oldPassword,
                    new_password: newPassword
                })
            });

            const result = await response.json();

            if (result.code === 200) {
                showToast('success', '修改成功', '密码已成功修改，请重新登录');

                if (oldPasswordInput) oldPasswordInput.value = '';
                if (oldPasswordInputMobile) oldPasswordInputMobile.value = '';
                if (newPasswordInput) newPasswordInput.value = '';
                if (newPasswordInputMobile) newPasswordInputMobile.value = '';
                if (confirmPasswordInput) confirmPasswordInput.value = '';
                if (confirmPasswordInputMobile) confirmPasswordInputMobile.value = '';

                setTimeout(() => {
                    window.location.href = '/login';
                }, 2000);
            } else {
                showToast('error', '修改失败', result.msg || '修改失败，请稍后重试');
            }
        } catch (error) {
            console.error('修改密码失败:', error);
            showToast('error', '修改失败', '网络错误，请稍后重试');
        }
    }

    // 邮箱验证
    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    // 页面加载完成后初始化
    document.addEventListener('DOMContentLoaded', async function() {
        await loadUserSettings();
    });

    // 加载用户设置
    async function loadUserSettings() {
        try {
            const response = await fetch('/user/getCurrentUserProfile');

            if (!response.ok) {
                throw new Error('网络请求失败: ' + response.status);
            }

            const result = await response.json();

            if (result.code === 200 && result.data) {
                const userData = result.data;

                if (userData.nickname) {
                    const nicknameInput = document.getElementById('nickname');
                    const nicknameInputMobile = document.getElementById('nicknameMobile');
                    if (nicknameInput) nicknameInput.value = userData.nickname;
                    if (nicknameInputMobile) nicknameInputMobile.value = userData.nickname;
                }
                if (userData.gender !== undefined) {
                    const genderRadio = document.querySelector(`input[name="gender"][value="${userData.gender}"]`);
                    if (genderRadio) {
                        genderRadio.checked = true;
                    }
                    const genderRadioMobile = document.querySelector(`input[name="gender"][value="${userData.gender}"]`);
                    if (genderRadioMobile) {
                        genderRadioMobile.checked = true;
                    }
                }
                if (userData.birthday) {
                    const birthdayInput = document.getElementById('birthday');
                    const birthdayInputMobile = document.getElementById('birthdayMobile');
                    if (birthdayInput) birthdayInput.value = userData.birthday;
                    if (birthdayInputMobile) birthdayInputMobile.value = userData.birthday;
                }
                if (userData.occupation) {
                    const occupationInput = document.getElementById('occupation');
                    const occupationInputMobile = document.getElementById('occupationMobile');
                    if (occupationInput) occupationInput.value = userData.occupation;
                    if (occupationInputMobile) occupationInputMobile.value = userData.occupation;
                }
                if (userData.bio) {
                    const bioInput = document.getElementById('bio');
                    const bioInputMobile = document.getElementById('bioMobile');
                    if (bioInput) bioInput.value = userData.bio;
                    if (bioInputMobile) bioInputMobile.value = userData.bio;
                }
                if (userData.card_privacy) {
                    const cardPrivacyInput = document.getElementById('cardPrivacy');
                    const cardPrivacyInputMobile = document.getElementById('momentPrivacyMobile2');
                    if (cardPrivacyInput) cardPrivacyInput.value = userData.card_privacy;
                    if (cardPrivacyInputMobile) cardPrivacyInputMobile.value = userData.card_privacy;
                    const momentPrivacyInput = document.getElementById('momentPrivacy');
                    const momentPrivacyInputMobile = document.getElementById('momentPrivacyMobile');
                    if (momentPrivacyInput) momentPrivacyInput.value = userData.card_privacy;
                    if (momentPrivacyInputMobile) momentPrivacyInputMobile.value = userData.card_privacy;
                }
                if (userData.email) {
                    const emailInput = document.getElementById('email');
                    const emailInputMobile = document.getElementById('emailMobile');
                    if (emailInput) emailInput.value = userData.email;
                    if (emailInputMobile) emailInputMobile.value = userData.email;
                }
                if (userData.avatar) {
                    const avatarPreview = document.getElementById('avatarPreview');
                    const avatarPreviewMobile = document.getElementById('avatarPreviewMobile');
                    if (avatarPreview) avatarPreview.src = userData.avatar;
                    if (avatarPreviewMobile) avatarPreviewMobile.src = userData.avatar;
                }
            } else if (result.code === 200 && result.msg === '未登录') {
                window.location.href = '/login';
            }
        } catch (error) {
            console.error('加载用户设置失败:', error);
            // 显示错误提示
            showToast('error', '加载失败', '无法加载用户设置，请稍后重试');
        }
    }
</script>

<script>
    // 标签页切换功能（已禁用 - 现在三列布局中同时显示所有标签）
    /*
    document.addEventListener('DOMContentLoaded', function() {
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabPanes = document.querySelectorAll('.tab-pane');

        tabButtons.forEach(button => {
            button.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');

                // 移除所有按钮的活动状态
                tabButtons.forEach(btn => {
                    btn.classList.remove('active', 'border-primary', 'text-primary');
                    btn.classList.add('border-transparent', 'text-text-secondary', 'dark:text-dark-text-secondary');
                });

                // 添加当前按钮的活动状态
                this.classList.add('active', 'border-primary', 'text-primary');
                this.classList.remove('border-transparent', 'text-text-secondary', 'dark:text-dark-text-secondary');

                // 隐藏所有标签内容
                tabPanes.forEach(pane => {
                    pane.classList.add('hidden');
                    pane.classList.remove('active');
                });

                // 显示当前标签内容
                const activePane = document.getElementById(tabId);
                activePane.classList.remove('hidden');
                activePane.classList.add('active');
            });
        });
    });
    */
</script>

<div id="toastContainer" class="toast-container"></div>

<div class="settings-container">
        <!-- 页面标题 -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">账户设置</h1>
            <p class="text-gray-500 mt-2">管理您的个人信息、账户安全和隐私设置</p>
        </div>

        <div class="settings-layout">
            <!-- 左侧导航栏 -->
            <div class="settings-sidebar">
                <div class="sidebar-menu-item active" data-tab="basic" onclick="switchTab('basic')">
                    <i class="fa fa-user"></i>
                    <span>基本信息</span>
                </div>
                <div class="sidebar-menu-item" data-tab="account" onclick="switchTab('account')">
                    <i class="fa fa-shield"></i>
                    <span>账户安全</span>
                </div>
                <div class="sidebar-menu-item" data-tab="privacy" onclick="switchTab('privacy')">
                    <i class="fa fa-user-secret"></i>
                    <span>隐私设置</span>
                </div>
                <div class="sidebar-menu-item" data-tab="notification" onclick="switchTab('notification')">
                    <i class="fa fa-bell"></i>
                    <span>通知设置</span>
                </div>
                <div class="sidebar-menu-item" data-tab="realname" onclick="switchTab('realname')">
                    <i class="fa fa-id-card"></i>
                    <span>实名认证</span>
                </div>
            </div>

            <!-- 右侧内容区域 -->
            <div class="settings-main">
                <!-- 基本信息 -->
                <div id="basic" class="settings-content active">
                    <div class="settings-card">
                        <div class="settings-title">
                            <i class="fa fa-user"></i>
                            <span>基本信息</span>
                        </div>

                        <!-- 头像区域 -->
                        <div class="avatar-section">
                            <img id="avatarPreview" src="/static/images/default-avatar.png" alt="头像" class="avatar-preview">
                            <div class="avatar-info">
                                <h4>个人头像</h4>
                                <p>支持 JPG、PNG 格式，建议尺寸 200x200</p>
                                <label class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;">
                                    <i class="fa fa-camera"></i>
                                    更换头像
                                    <input type="file" id="avatarInput" accept="image/*" class="hidden" onchange="previewAvatar(this)">
                                </label>
                            </div>
                        </div>

                        <!-- 表单区域 -->
                        <form id="basicForm">
                            <div class="form-group">
                                <label class="form-label">昵称<span class="required">*</span></label>
                                <input type="text" class="form-input" id="nickname" placeholder="请输入您的昵称">
                            </div>

                            <div class="form-group">
                                <label class="form-label">性别</label>
                                <div class="radio-group">
                                    <label class="radio-item">
                                        <input type="radio" name="gender" value="1" checked> 男
                                    </label>
                                    <label class="radio-item">
                                        <input type="radio" name="gender" value="2"> 女
                                    </label>
                                    <label class="radio-item">
                                        <input type="radio" name="gender" value="0"> 其他
                                    </label>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">生日</label>
                                <input type="date" class="form-input" id="birthday">
                                <p class="form-hint">您的生日信息仅用于个性化服务</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">职业</label>
                                <input type="text" class="form-input" id="occupation" placeholder="请输入您的职业">
                            </div>

                            <div class="form-group">
                                <label class="form-label">个人简介</label>
                                <textarea class="form-textarea" id="bio" rows="4" placeholder="介绍一下自己吧..."></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">动态隐私设置</label>
                                <select class="form-select" id="momentPrivacy">
                                    <option value="1">公开 - 所有人可见</option>
                                    <option value="2">仅好友 - 仅好友可见</option>
                                    <option value="3">私密 - 仅自己可见</option>
                                </select>
                                <p class="form-hint">控制谁可以看到您的动态内容</p>
                            </div>

                            <button type="button" class="btn btn-primary" onclick="saveSettings()">
                                <i class="fa fa-save"></i> 保存基本信息
                            </button>
                        </form>
                    </div>
                </div>
                <!-- 账户安全 -->
                <div id="account" class="settings-content">
                    <div class="settings-card">
                        <div class="settings-title">
                            <i class="fa fa-shield"></i>
                            <span>账户安全</span>
                        </div>

                        <!-- 账户状态 -->
                        <div class="info-card" style="margin-bottom: 24px;">
                            <div class="info-card-title">
                                <i class="fa fa-check-circle"></i>
                                <span>账户状态</span>
                            </div>
                            <div class="info-card-content">
                                <p class="mb-2">您的账户安全状况良好</p>
                                <div class="status-badge">
                                    <i class="fa fa-shield-alt"></i>
                                    已开启安全保护
                                </div>
                            </div>
                        </div>

                        <form id="accountForm">
                            <div class="form-group">
                                <label class="form-label">邮箱地址</label>
                                <input type="email" class="form-input" id="email" placeholder="请输入您的邮箱地址">
                                <p class="form-hint">用于找回密码和接收重要通知</p>
                            </div>

                            <button type="button" class="btn btn-primary" onclick="saveAccountSecurity()">
                                <i class="fa fa-save"></i> 保存邮箱
                            </button>
                        </form>

                        <div class="section-divider"></div>

                        <!-- 修改密码 -->
                        <h3 style="font-size: 18px; font-weight: 600; color: #1D2129; margin-bottom: 20px;">
                            <i class="fa fa-lock" style="color: #4080FF; margin-right: 8px;"></i>
                            修改密码
                        </h3>

                        <form id="passwordForm">
                            <div class="form-group">
                                <label class="form-label">原密码</label>
                                <input type="password" class="form-input" id="oldPassword" placeholder="请输入原密码">
                            </div>

                            <div class="form-group">
                                <label class="form-label">新密码</label>
                                <input type="password" class="form-input" id="newPassword" placeholder="请输入新密码（至少6位）">
                                <p class="form-hint">密码长度不少于6位，建议包含字母、数字和符号</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">确认新密码</label>
                                <input type="password" class="form-input" id="confirmPassword" placeholder="请再次输入新密码">
                            </div>

                            <button type="button" class="btn btn-primary" onclick="changePassword()">
                                <i class="fa fa-key"></i> 修改密码
                            </button>
                        </form>
                    </div>
                </div>

                <!-- 隐私设置 -->
                <div id="privacy" class="settings-content">
                    <div class="settings-card">
                        <div class="settings-title">
                            <i class="fa fa-user-secret"></i>
                            <span>隐私设置</span>
                        </div>

                        <form id="privacyForm">
                            <div class="form-group">
                                <label class="form-label">动态可见范围</label>
                                <select class="form-select" id="cardPrivacy">
                                    <option value="1">公开 - 所有人可见</option>
                                    <option value="2">仅好友 - 仅好友可见</option>
                                    <option value="3">私密 - 仅自己可见</option>
                                </select>
                                <p class="form-hint">控制谁可以查看您的动态内容</p>
                            </div>

                            <button type="button" class="btn btn-primary" onclick="saveSettings()">
                                <i class="fa fa-save"></i> 保存隐私设置
                            </button>
                        </form>
                    </div>

                    <!-- 快捷操作 -->
                    <div class="settings-card">
                        <h3 style="font-size: 18px; font-weight: 600; color: #1D2129; margin-bottom: 20px;">
                            <i class="fa fa-bolt" style="color: #FF7D00; margin-right: 8px;"></i>
                            快捷操作
                        </h3>

                        <div class="quick-actions">
                            <div class="quick-action-item" onclick="switchTab('realname')">
                                <i class="fa fa-id-card"></i>
                                <span>实名认证</span>
                            </div>
                            <div class="quick-action-item" onclick="getLoginHistory()">
                                <i class="fa fa-history"></i>
                                <span>登录历史</span>
                            </div>
                            <div class="quick-action-item" onclick="exportUserData()">
                                <i class="fa fa-download"></i>
                                <span>导出数据</span>
                            </div>
                            <div class="quick-action-item" onclick="switchTab('notification')">
                                <i class="fa fa-bell"></i>
                                <span>通知设置</span>
                            </div>
                        </div>
                    </div>

                    <!-- 危险区域 -->
                    <div class="settings-card" style="border: 1px solid #F53F3F;">
                        <h3 style="font-size: 18px; font-weight: 600; color: #F53F3F; margin-bottom: 16px;">
                            <i class="fa fa-exclamation-triangle" style="margin-right: 8px;"></i>
                            危险区域
                        </h3>
                        <p style="color: #86909C; margin-bottom: 16px; line-height: 1.6;">
                            以下操作不可逆，请谨慎操作
                        </p>
                        <button class="btn btn-secondary" style="border: 1px solid #F53F3F; color: #F53F3F;" onclick="deleteAccount()">
                            <i class="fa fa-trash"></i> 注销账户
                        </button>
                    </div>
                </div>

                <!-- 通知设置 -->
                <div id="notification" class="settings-content">
                    <div class="settings-card">
                        <div class="settings-title">
                            <i class="fa fa-bell"></i>
                            <span>通知设置</span>
                        </div>

                        <form id="notificationForm">
                            <div class="form-group">
                                <label class="form-label">邮件通知</label>
                                <div class="radio-item">
                                    <input type="checkbox" id="emailNotification" checked>
                                    <span>接收邮件通知</span>
                                </div>
                                <p class="form-hint">通过邮件接收重要通知</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">短信通知</label>
                                <div class="radio-item">
                                    <input type="checkbox" id="smsNotification">
                                    <span>接收短信通知</span>
                                </div>
                                <p class="form-hint">通过短信接收重要通知</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">推送通知</label>
                                <div class="radio-item">
                                    <input type="checkbox" id="pushNotification" checked>
                                    <span>接收推送通知</span>
                                </div>
                                <p class="form-hint">接收应用推送通知</p>
                            </div>

                            <div class="section-divider"></div>

                            <div class="form-group">
                                <label class="form-label">点赞通知</label>
                                <div class="radio-item">
                                    <input type="checkbox" id="likeNotification" checked>
                                    <span>当有人点赞时通知我</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">评论通知</label>
                                <div class="radio-item">
                                    <input type="checkbox" id="commentNotification" checked>
                                    <span>当有人评论时通知我</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">关注通知</label>
                                <div class="radio-item">
                                    <input type="checkbox" id="followNotification" checked>
                                    <span>当有人关注我时通知我</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">私信通知</label>
                                <div class="radio-item">
                                    <input type="checkbox" id="messageNotification" checked>
                                    <span>当收到私信时通知我</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">系统通知</label>
                                <div class="radio-item">
                                    <input type="checkbox" id="systemNotification" checked>
                                    <span>接收系统通知</span>
                                </div>
                            </div>

                            <div class="section-divider"></div>

                            <div class="form-group">
                                <label class="form-label">通知声音</label>
                                <div class="radio-item">
                                    <input type="checkbox" id="notificationSound" checked>
                                    <span>播放通知声音</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">免打扰时间</label>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="time" class="form-input" id="quietHoursStart" style="width: 150px;" value="22:00">
                                    <span>至</span>
                                    <input type="time" class="form-input" id="quietHoursEnd" style="width: 150px;" value="08:00">
                                </div>
                                <p class="form-hint">在此时间段内不会收到通知</p>
                            </div>

                            <button type="button" class="btn btn-primary" onclick="saveNotificationSettings()">
                                <i class="fa fa-save"></i> 保存通知设置
                            </button>
                        </form>
                    </div>
                </div>

                <!-- 实名认证 -->
                <div id="realname" class="settings-content">
                    <div class="settings-card">
                        <div class="settings-title">
                            <i class="fa fa-id-card"></i>
                            <span>实名认证</span>
                        </div>

                        <div class="info-card" style="margin-bottom: 24px;">
                            <div class="info-card-title">
                                <i class="fa fa-info-circle"></i>
                                <span>认证状态</span>
                            </div>
                            <div class="info-card-content">
                                <span id="authStatus" class="status-badge">未认证</span>
                            </div>
                        </div>

                        <form id="realnameForm">
                            <div class="form-group">
                                <label class="form-label">真实姓名<span class="required">*</span></label>
                                <input type="text" class="form-input" id="realName" placeholder="请输入您的真实姓名">
                                <p class="form-hint">请与身份证上的姓名保持一致</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">身份证号<span class="required">*</span></label>
                                <input type="text" class="form-input" id="idCard" placeholder="请输入您的身份证号" maxlength="18">
                                <p class="form-hint">请输入18位身份证号码</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">身份证正面照片<span class="required">*</span></label>
                                <div style="display: flex; gap: 20px; align-items: flex-start;">
                                    <img id="idCardFrontPreview" src="/images/default-idcard-front.png" alt="身份证正面" style="width: 200px; height: 130px; object-fit: cover; border: 1px solid #E5E6EB; border-radius: 8px;">
                                    <div>
                                        <label class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;">
                                            <i class="fa fa-upload"></i>
                                            上传照片
                                            <input type="file" id="idCardFrontInput" accept="image/*" class="hidden" onchange="previewImage(this, 'idCardFrontPreview')">
                                        </label>
                                        <p class="form-hint">支持 JPG、PNG 格式，大小不超过 5MB</p>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">身份证背面照片<span class="required">*</span></label>
                                <div style="display: flex; gap: 20px; align-items: flex-start;">
                                    <img id="idCardBackPreview" src="/images/default-idcard-back.png" alt="身份证背面" style="width: 200px; height: 130px; object-fit: cover; border: 1px solid #E5E6EB; border-radius: 8px;">
                                    <div>
                                        <label class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;">
                                            <i class="fa fa-upload"></i>
                                            上传照片
                                            <input type="file" id="idCardBackInput" accept="image/*" class="hidden" onchange="previewImage(this, 'idCardBackPreview')">
                                        </label>
                                        <p class="form-hint">支持 JPG、PNG 格式，大小不超过 5MB</p>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">手持身份证照片<span class="required">*</span></label>
                                <div style="display: flex; gap: 20px; align-items: flex-start;">
                                    <img id="handheldIdCardPreview" src="/images/default-handheld-idcard.png" alt="手持身份证" style="width: 200px; height: 130px; object-fit: cover; border: 1px solid #E5E6EB; border-radius: 8px;">
                                    <div>
                                        <label class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;">
                                            <i class="fa fa-upload"></i>
                                            上传照片
                                            <input type="file" id="handheldIdCardInput" accept="image/*" class="hidden" onchange="previewImage(this, 'handheldIdCardPreview')">
                                        </label>
                                        <p class="form-hint">支持 JPG、PNG 格式，大小不超过 5MB</p>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-primary" id="submitAuthBtn" onclick="submitRealnameAuth()">
                                <i class="fa fa-paper-plane"></i> 提交认证
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 登录历史弹窗 -->
    <div id="loginHistoryModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 16px; width: 90%; max-width: 600px; max-height: 80vh; overflow: hidden; display: flex; flex-direction: column;">
            <div style="padding: 20px; border-bottom: 1px solid #E5E6EB; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 18px; font-weight: 600; color: #1D2129; margin: 0;">登录历史</h3>
                <button onclick="document.getElementById('loginHistoryModal').style.display='none'" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #86909C;">&times;</button>
            </div>
            <div id="loginHistoryContainer" style="padding: 20px; overflow-y: auto; flex: 1;">
                <p style="text-align: center; color: #86909C; padding: 20px;">加载中...</p>
            </div>
        </div>
    </div>

<script>
    // 标签页切换
    function switchTab(tabId) {
        // 隐藏所有内容
        document.querySelectorAll('.settings-content').forEach(el => {
            el.classList.remove('active');
        });
        // 显示选中的内容
        document.getElementById(tabId).classList.add('active');

        // 更新侧边栏状态
        document.querySelectorAll('.sidebar-menu-item').forEach(el => {
            el.classList.remove('active');
            if (el.dataset.tab === tabId) {
                el.classList.add('active');
            }
        });
    }

    // 页面加载时初始化
    document.addEventListener('DOMContentLoaded', function() {
        loadUserSettings();
        getNotificationSettings();
        getRealnameAuth();
    });

    // 获取登录历史
    async function getLoginHistory() {
        try {
            document.getElementById('loginHistoryModal').style.display = 'flex';
            document.getElementById('loginHistoryContainer').innerHTML = '<p style="text-align: center; color: #86909C; padding: 20px;">加载中...</p>';

            const response = await fetch('/user/getLoginHistory?page=1&limit=20');
            const result = await response.json();

            if (result.code === 200) {
                displayLoginHistory(result.data.list);
            } else {
                showToast('error', '获取失败', result.msg || '获取登录历史失败');
            }
        } catch (error) {
            console.error('获取登录历史失败:', error);
            showToast('error', '获取失败', '网络错误，请稍后重试');
        }
    }

    // 显示登录历史
    function displayLoginHistory(logs) {
        const container = document.getElementById('loginHistoryContainer');
        if (!container) return;

        if (logs.length === 0) {
            container.innerHTML = '<p style="text-align: center; color: #86909C; padding: 20px;">暂无登录历史</p>';
            return;
        }

        let html = '<div style="max-height: 400px; overflow-y: auto;">';
        logs.forEach(log => {
            const loginTime = new Date(log.login_time * 1000).toLocaleString('zh-CN');
            html += `
                <div style="padding: 12px; border-bottom: 1px solid #E5E6EB; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="font-weight: 500; color: #1D2129; margin-bottom: 4px;">
                            <i class="fa fa-${log.device_type === '手机' ? 'mobile' : 'desktop'}" style="margin-right: 8px; color: #86909C;"></i>
                            ${log.device_type || '未知设备'} - ${log.browser || '未知浏览器'}
                        </div>
                        <div style="font-size: 12px; color: #86909C;">
                            ${log.location || log.login_ip || '未知地点'}
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 12px; color: #86909C;">${loginTime}</div>
                        <div style="font-size: 12px; color: ${log.status === 1 ? '#00B42A' : '#F53F3F'};">
                            ${log.status === 1 ? '登录成功' : '登录失败'}
                        </div>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
    }

    // 导出用户数据
    async function exportUserData() {
        try {
            showToast('info', '导出中', '正在导出您的数据，请稍候...');

            const response = await fetch('/user/exportUserData?type=all');
            const result = await response.json();

            if (result.code === 200) {
                showToast('success', '导出成功', '数据导出成功，正在下载...');
                window.location.href = result.data.download_url;
            } else {
                showToast('error', '导出失败', result.msg || '导出数据失败');
            }
        } catch (error) {
            console.error('导出数据失败:', error);
            showToast('error', '导出失败', '网络错误，请稍后重试');
        }
    }

    // 获取通知设置
    async function getNotificationSettings() {
        try {
            const response = await fetch('/settings/getNotificationSettings', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                console.error('获取通知设置失败: HTTP', response.status);
                return;
            }

            const result = await response.json();

            if (result.code === 200 && result.data) {
                const settings = result.data;
                const emailNotification = document.getElementById('emailNotification');
                const emailNotificationMobile = document.getElementById('emailNotificationMobile');
                const smsNotification = document.getElementById('smsNotification');
                const smsNotificationMobile = document.getElementById('smsNotificationMobile');
                const pushNotification = document.getElementById('pushNotification');
                const pushNotificationMobile = document.getElementById('pushNotificationMobile');
                const likeNotification = document.getElementById('likeNotification');
                const likeNotificationMobile = document.getElementById('likeNotificationMobile');
                const commentNotification = document.getElementById('commentNotification');
                const commentNotificationMobile = document.getElementById('commentNotificationMobile');
                const followNotification = document.getElementById('followNotification');
                const followNotificationMobile = document.getElementById('followNotificationMobile');
                const messageNotification = document.getElementById('messageNotification');
                const messageNotificationMobile = document.getElementById('messageNotificationMobile');
                const systemNotification = document.getElementById('systemNotification');
                const systemNotificationMobile = document.getElementById('systemNotificationMobile');
                const notificationSound = document.getElementById('notificationSound');
                const notificationSoundMobile = document.getElementById('notificationSoundMobile');
                const quietHoursStart = document.getElementById('quietHoursStart');
                const quietHoursStartMobile = document.getElementById('quietHoursStartMobile');
                const quietHoursEnd = document.getElementById('quietHoursEnd');
                const quietHoursEndMobile = document.getElementById('quietHoursEndMobile');

                if (emailNotification) emailNotification.checked = settings.email_notification === 1;
                if (emailNotificationMobile) emailNotificationMobile.checked = settings.email_notification === 1;
                if (smsNotification) smsNotification.checked = settings.sms_notification === 1;
                if (smsNotificationMobile) smsNotificationMobile.checked = settings.sms_notification === 1;
                if (pushNotification) pushNotification.checked = settings.push_notification === 1;
                if (pushNotificationMobile) pushNotificationMobile.checked = settings.push_notification === 1;
                if (likeNotification) likeNotification.checked = settings.like_notification === 1;
                if (likeNotificationMobile) likeNotificationMobile.checked = settings.like_notification === 1;
                if (commentNotification) commentNotification.checked = settings.comment_notification === 1;
                if (commentNotificationMobile) commentNotificationMobile.checked = settings.comment_notification === 1;
                if (followNotification) followNotification.checked = settings.follow_notification === 1;
                if (followNotificationMobile) followNotificationMobile.checked = settings.follow_notification === 1;
                if (messageNotification) messageNotification.checked = settings.message_notification === 1;
                if (messageNotificationMobile) messageNotificationMobile.checked = settings.message_notification === 1;
                if (systemNotification) systemNotification.checked = settings.system_notification === 1;
                if (systemNotificationMobile) systemNotificationMobile.checked = settings.system_notification === 1;
                if (notificationSound) notificationSound.checked = settings.notification_sound === 1;
                if (notificationSoundMobile) notificationSoundMobile.checked = settings.notification_sound === 1;
                if (quietHoursStart) quietHoursStart.value = settings.quiet_hours_start || '22:00';
                if (quietHoursStartMobile) quietHoursStartMobile.value = settings.quiet_hours_start || '22:00';
                if (quietHoursEnd) quietHoursEnd.value = settings.quiet_hours_end || '08:00';
                if (quietHoursEndMobile) quietHoursEndMobile.value = settings.quiet_hours_end || '08:00';
            }
        } catch (error) {
            console.error('获取通知设置失败:', error);
        }
    }

    // 保存通知设置
    async function saveNotificationSettings() {
        try {
            const emailNotification = document.getElementById('emailNotification')?.checked ? 1 : (document.getElementById('emailNotificationMobile')?.checked ? 1 : 0);
            const smsNotification = document.getElementById('smsNotification')?.checked ? 1 : (document.getElementById('smsNotificationMobile')?.checked ? 1 : 0);
            const pushNotification = document.getElementById('pushNotification')?.checked ? 1 : (document.getElementById('pushNotificationMobile')?.checked ? 1 : 0);
            const likeNotification = document.getElementById('likeNotification')?.checked ? 1 : (document.getElementById('likeNotificationMobile')?.checked ? 1 : 0);
            const commentNotification = document.getElementById('commentNotification')?.checked ? 1 : (document.getElementById('commentNotificationMobile')?.checked ? 1 : 0);
            const followNotification = document.getElementById('followNotification')?.checked ? 1 : (document.getElementById('followNotificationMobile')?.checked ? 1 : 0);
            const messageNotification = document.getElementById('messageNotification')?.checked ? 1 : (document.getElementById('messageNotificationMobile')?.checked ? 1 : 0);
            const systemNotification = document.getElementById('systemNotification')?.checked ? 1 : (document.getElementById('systemNotificationMobile')?.checked ? 1 : 0);
            const notificationSound = document.getElementById('notificationSound')?.checked ? 1 : (document.getElementById('notificationSoundMobile')?.checked ? 1 : 0);
            const quietHoursStart = document.getElementById('quietHoursStart')?.value || document.getElementById('quietHoursStartMobile')?.value || '';
            const quietHoursEnd = document.getElementById('quietHoursEnd')?.value || document.getElementById('quietHoursEndMobile')?.value || '';

            const settings = {
                email_notification: emailNotification,
                sms_notification: smsNotification,
                push_notification: pushNotification,
                like_notification: likeNotification,
                comment_notification: commentNotification,
                follow_notification: followNotification,
                message_notification: messageNotification,
                system_notification: systemNotification,
                notification_sound: notificationSound,
                quiet_hours_start: quietHoursStart,
                quiet_hours_end: quietHoursEnd
            };

            showToast('info', '保存中', '正在保存通知设置...');

            const response = await fetch('/settings/updateNotificationSettings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(settings)
            });

            const result = await response.json();

            if (result.code === 200) {
                showToast('success', '保存成功', '通知设置已保存');
            } else {
                showToast('error', '保存失败', result.msg || '保存失败');
            }
        } catch (error) {
            console.error('保存通知设置失败:', error);
            showToast('error', '保存失败', '网络错误，请稍后重试');
        }
    }

    // 获取实名认证信息
    async function getRealnameAuth() {
        try {
            const response = await fetch('/settings/getRealnameAuth', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                console.error('获取实名认证信息失败: HTTP', response.status);
                return;
            }

            const result = await response.json();

            if (result.code === 200 && result.data) {
                const auth = result.data;
                const realName = document.getElementById('realName');
                const realNameMobile = document.getElementById('realNameMobile');
                const idCard = document.getElementById('idCard');
                const idCardMobile = document.getElementById('idCardMobile');
                const idCardFrontPreview = document.getElementById('idCardFrontPreview');
                const idCardFrontPreviewMobile = document.getElementById('idCardFrontPreviewMobile');
                const idCardBackPreview = document.getElementById('idCardBackPreview');
                const idCardBackPreviewMobile = document.getElementById('idCardBackPreviewMobile');
                const handheldIdCardPreview = document.getElementById('handheldIdCardPreview');
                const handheldIdCardPreviewMobile = document.getElementById('handheldIdCardPreviewMobile');
                const authStatus = document.getElementById('authStatus');
                const authStatusMobile = document.getElementById('authStatusMobile');

                if (realName) realName.value = auth.real_name || '';
                if (realNameMobile) realNameMobile.value = auth.real_name || '';
                if (idCard) idCard.value = auth.id_card || '';
                if (idCardMobile) idCardMobile.value = auth.id_card || '';
                if (idCardFrontPreview) idCardFrontPreview.src = auth.id_card_front || '';
                if (idCardFrontPreviewMobile) idCardFrontPreviewMobile.src = auth.id_card_front || '';
                if (idCardBackPreview) idCardBackPreview.src = auth.id_card_back || '';
                if (idCardBackPreviewMobile) idCardBackPreviewMobile.src = auth.id_card_back || '';
                if (handheldIdCardPreview) handheldIdCardPreview.src = auth.handheld_id_card || '';
                if (handheldIdCardPreviewMobile) handheldIdCardPreviewMobile.src = auth.handheld_id_card || '';

                const statusText = {
                    0: '待审核',
                    1: '已通过',
                    2: '已拒绝'
                };
                const statusTextValue = statusText[auth.status] || '未认证';
                const statusClass = auth.status === 1 ? 'status-success' : auth.status === 2 ? 'status-error' : 'status-pending';
                
                if (authStatus) {
                    authStatus.textContent = statusTextValue;
                    authStatus.className = `status-badge ${statusClass}`;
                }
                if (authStatusMobile) {
                    authStatusMobile.textContent = statusTextValue;
                    authStatusMobile.className = `status-badge ${statusClass}`;
                }

                if (auth.status === 1) {
                    disableAuthForm();
                }
            }
        } catch (error) {
            console.error('获取实名认证信息失败:', error);
        }
    }

    // 禁用认证表单
    function disableAuthForm() {
        const realName = document.getElementById('realName');
        const realNameMobile = document.getElementById('realNameMobile');
        const idCard = document.getElementById('idCard');
        const idCardMobile = document.getElementById('idCardMobile');
        const idCardFrontInput = document.getElementById('idCardFrontInput');
        const idCardFrontInputMobile = document.getElementById('idCardFrontInputMobile');
        const idCardBackInput = document.getElementById('idCardBackInput');
        const idCardBackInputMobile = document.getElementById('idCardBackInputMobile');
        const handheldIdCardInput = document.getElementById('handheldIdCardInput');
        const handheldIdCardInputMobile = document.getElementById('handheldIdCardInputMobile');
        const submitAuthBtn = document.getElementById('submitAuthBtn');
        const submitAuthBtnMobile = document.getElementById('submitAuthBtnMobile');

        if (realName) realName.disabled = true;
        if (realNameMobile) realNameMobile.disabled = true;
        if (idCard) idCard.disabled = true;
        if (idCardMobile) idCardMobile.disabled = true;
        if (idCardFrontInput) idCardFrontInput.disabled = true;
        if (idCardFrontInputMobile) idCardFrontInputMobile.disabled = true;
        if (idCardBackInput) idCardBackInput.disabled = true;
        if (idCardBackInputMobile) idCardBackInputMobile.disabled = true;
        if (handheldIdCardInput) handheldIdCardInput.disabled = true;
        if (handheldIdCardInputMobile) handheldIdCardInputMobile.disabled = true;
        if (submitAuthBtn) submitAuthBtn.disabled = true;
        if (submitAuthBtnMobile) submitAuthBtnMobile.disabled = true;
    }

    async function submitRealnameAuth() {
        try {
            const realNameInput = document.getElementById('realName');
            const realNameInputMobile = document.getElementById('realNameMobile');
            const realName = realNameInput ? realNameInput.value : (realNameInputMobile ? realNameInputMobile.value : '');

            const idCardInput = document.getElementById('idCard');
            const idCardInputMobile = document.getElementById('idCardMobile');
            const idCard = idCardInput ? idCardInput.value : (idCardInputMobile ? idCardInputMobile.value : '');

            const idCardFrontInput = document.getElementById('idCardFrontInput');
            const idCardFrontInputMobile = document.getElementById('idCardFrontInputMobile');
            const idCardFront = idCardFrontInput ? idCardFrontInput.files[0] : (idCardFrontInputMobile ? idCardFrontInputMobile.files[0] : null);

            const idCardBackInput = document.getElementById('idCardBackInput');
            const idCardBackInputMobile = document.getElementById('idCardBackInputMobile');
            const idCardBack = idCardBackInput ? idCardBackInput.files[0] : (idCardBackInputMobile ? idCardBackInputMobile.files[0] : null);

            const handheldIdCardInput = document.getElementById('handheldIdCardInput');
            const handheldIdCardInputMobile = document.getElementById('handheldIdCardInputMobile');
            const handheldIdCard = handheldIdCardInput ? handheldIdCardInput.files[0] : (handheldIdCardInputMobile ? handheldIdCardInputMobile.files[0] : null);

            if (!realName || !idCard || !idCardFront || !idCardBack || !handheldIdCard) {
                showToast('error', '提交失败', '请填写完整信息');
                return;
            }

            showToast('info', '提交中', '正在提交实名认证...');

            const formData = new FormData();
            formData.append('real_name', realName);
            formData.append('id_card', idCard);
            formData.append('id_card_front', idCardFront);
            formData.append('id_card_back', idCardBack);
            formData.append('handheld_id_card', handheldIdCard);

            const response = await fetch('/settings/submitRealnameAuth', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const result = await response.json();

            if (result.code === 200) {
                showToast('success', '提交成功', result.msg);
                getRealnameAuth();
            } else {
                showToast('error', '提交失败', result.msg || '提交失败');
            }
        } catch (error) {
            console.error('提交实名认证失败:', error);
            showToast('error', '提交失败', '网络错误，请稍后重试');
        }
    }

    // 注销账户
    async function deleteAccount() {
        const password = prompt('请输入密码以确认注销账户（此操作不可逆）');
        if (!password) {
            return;
        }

        try {
            showToast('info', '注销中', '正在注销您的账户...');

            const response = await fetch('/user/deleteAccount', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ password: password })
            });

            const result = await response.json();

            if (result.code === 200) {
                showToast('success', '注销成功', result.msg);
                setTimeout(() => {
                    window.location.href = '/';
                }, 2000);
            } else {
                showToast('error', '注销失败', result.msg || '注销失败');
            }
        } catch (error) {
            console.error('注销账户失败:', error);
            showToast('error', '注销失败', '网络错误，请稍后重试');
        }
    }

    // 图片预览
    function previewImage(input, previewId) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById(previewId).src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
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
                
<!-- 移动端内容 -->
<div class="settings-mobile p-4">
    <div class="bg-white dark:bg-dark-bg-card rounded-xl shadow-soft p-4 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-text-primary dark:text-dark-text-primary">设置</h2>
        </div>

        <!-- 移动端标签 -->
        <div class="border-b border-border-color dark:border-dark-border-color mb-4">
            <div class="flex space-x-4 overflow-x-auto">
                <button class="tab-button-mobile active px-3 py-2 border-b-2 border-primary text-primary font-medium whitespace-nowrap" data-tab="basic-mobile">基本信息</button>
                <button class="tab-button-mobile px-3 py-2 border-b-2 border-transparent text-text-secondary dark:text-dark-text-secondary hover:text-primary dark:hover:text-primary whitespace-nowrap" data-tab="account-mobile">账户安全</button>
                <button class="tab-button-mobile px-3 py-2 border-b-2 border-transparent text-text-secondary dark:text-dark-text-secondary hover:text-primary dark:hover:text-primary whitespace-nowrap" data-tab="privacy-mobile">隐私设置</button>
                <button class="tab-button-mobile px-3 py-2 border-b-2 border-transparent text-text-secondary dark:text-dark-text-secondary hover:text-primary dark:hover:text-primary whitespace-nowrap" data-tab="notification-mobile">通知设置</button>
                <button class="tab-button-mobile px-3 py-2 border-b-2 border-transparent text-text-secondary dark:text-dark-text-secondary hover:text-primary dark:hover:text-primary whitespace-nowrap" data-tab="realname-mobile">实名认证</button>
            </div>
        </div>

        <!-- 移动端标签内容 -->
        <div class="tab-content-mobile">
            <!-- 基本信息 -->
            <div id="basic-mobile" class="tab-pane-mobile active">
                <h3 class="text-lg font-semibold text-text-primary dark:text-dark-text-primary mb-4">基本信息</h3>
                <div class="text-center mb-4">
                    <div class="avatar-upload relative inline-block">
                        <img id="avatarPreviewMobile" src="/static/images/default-avatar.png" alt="头像" class="rounded-full w-24 h-24 object-cover border-2 border-border-color dark:border-dark-border-color">
                        <div class="avatar-overlay absolute inset-0 flex items-center justify-center bg-black bg-opacity-50 rounded-full opacity-0 hover:opacity-100 transition-opacity">
                            <label for="avatarInputMobile" class="text-white bg-primary hover:bg-darkPrimary px-3 py-1 rounded-lg cursor-pointer text-sm">更换头像</label>
                            <input type="file" id="avatarInputMobile" accept="image/*" class="hidden" onchange="previewAvatar(this)">
                        </div>
                    </div>
                </div>
                <form id="basicFormMobile">
                    <div class="mb-4">
                        <label for="nicknameMobile" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">昵称</label>
                        <input type="text" class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="nicknameMobile" placeholder="请输入昵称">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">性别</label>
                        <div class="flex gap-4">
                            <div class="flex items-center">
                                <input class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color" type="radio" name="gender" id="genderMaleMobile" value="1" checked>
                                <label class="ml-2 text-sm text-text-primary dark:text-dark-text-primary" for="genderMaleMobile">男</label>
                            </div>
                            <div class="flex items-center">
                                <input class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color" type="radio" name="gender" id="genderFemaleMobile" value="2">
                                <label class="ml-2 text-sm text-text-primary dark:text-dark-text-primary" for="genderFemaleMobile">女</label>
                            </div>
                            <div class="flex items-center">
                                <input class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color" type="radio" name="gender" id="genderOtherMobile" value="0">
                                <label class="ml-2 text-sm text-text-primary dark:text-dark-text-primary" for="genderOtherMobile">其他</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="birthdayMobile" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">生日</label>
                        <input type="date" class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="birthdayMobile">
                    </div>
                    <div class="mb-4">
                        <label for="occupationMobile" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">职业</label>
                        <input type="text" class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="occupationMobile" placeholder="请输入职业">
                    </div>
                    <div class="mb-4">
                        <label for="bioMobile" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">个人简介</label>
                        <textarea class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="bioMobile" rows="3" placeholder="请输入个人简介"></textarea>
                    </div>
                    <div class="mb-4">
                        <label for="momentPrivacyMobile2" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">动态隐私设置</label>
                        <select class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="momentPrivacyMobile2">
                            <option value="1">公开</option>
                            <option value="2">仅好友可见</option>
                            <option value="3">私密</option>
                        </select>
                    </div>
                    <button type="button" class="w-full bg-primary hover:bg-darkPrimary text-white py-2 rounded-lg transition-colors" onclick="saveSettings()">保存基本信息</button>
                </form>
            </div>

            <!-- 账户安全 -->
            <div id="account-mobile" class="tab-pane-mobile hidden">
                <h3 class="text-lg font-semibold text-text-primary dark:text-dark-text-primary mb-4">账户安全</h3>
                <form id="accountFormMobile">
                    <div class="mb-4">
                        <label for="emailMobile" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">邮箱</label>
                        <input type="email" class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="emailMobile" placeholder="请输入邮箱">
                    </div>
                    <button type="button" class="w-full bg-primary hover:bg-darkPrimary text-white py-2 rounded-lg transition-colors mb-6" onclick="saveAccountSecurity()">保存账户安全设置</button>

                    <div class="border-t border-border-color dark:border-dark-border-color my-4"></div>

                    <h4 class="text-base font-semibold text-text-primary dark:text-dark-text-primary mb-4">修改密码</h4>
                    <div class="mb-4">
                        <label for="oldPasswordMobile" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">原密码</label>
                        <input type="password" class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="oldPasswordMobile" placeholder="请输入原密码">
                    </div>
                    <div class="mb-4">
                        <label for="newPasswordMobile" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">新密码</label>
                        <input type="password" class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="newPasswordMobile" placeholder="请输入新密码（至少6位）">
                    </div>
                    <div class="mb-4">
                        <label for="confirmPasswordMobile" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">确认新密码</label>
                        <input type="password" class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="confirmPasswordMobile" placeholder="请再次输入新密码">
                    </div>
                    <button type="button" class="w-full bg-primary hover:bg-darkPrimary text-white py-2 rounded-lg transition-colors" onclick="changePassword()">修改密码</button>
                </form>
            </div>

            <!-- 隐私设置 -->
            <div id="privacy-mobile" class="tab-pane-mobile hidden">
                <h3 class="text-lg font-semibold text-text-primary dark:text-dark-text-primary mb-4">隐私设置</h3>
                <form id="privacyFormMobile">
                    <div class="mb-4">
                        <label for="momentPrivacyMobile" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">动态隐私设置</label>
                        <select class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="momentPrivacyMobile">
                            <option value="1">公开</option>
                            <option value="2">仅好友可见</option>
                            <option value="3">私密</option>
                        </select>
                    </div>
                    <button type="button" class="w-full bg-primary hover:bg-darkPrimary text-white py-2 rounded-lg transition-colors" onclick="saveSettings()">保存隐私设置</button>
                </form>
            </div>

            <div id="notification-mobile" class="tab-pane-mobile hidden">
                <h3 class="text-lg font-semibold text-text-primary dark:text-dark-text-primary mb-4">通知设置</h3>
                <form id="notificationFormMobile">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">邮件通知</label>
                        <div class="flex items-center">
                            <input type="checkbox" id="emailNotificationMobile" checked class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color">
                            <span class="ml-2 text-sm text-text-primary dark:text-dark-text-primary">接收邮件通知</span>
                        </div>
                        <p class="text-xs text-text-secondary dark:text-dark-text-secondary mt-1">通过邮件接收重要通知</p>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">短信通知</label>
                        <div class="flex items-center">
                            <input type="checkbox" id="smsNotificationMobile" class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color">
                            <span class="ml-2 text-sm text-text-primary dark:text-dark-text-primary">接收短信通知</span>
                        </div>
                        <p class="text-xs text-text-secondary dark:text-dark-text-secondary mt-1">通过短信接收重要通知</p>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">推送通知</label>
                        <div class="flex items-center">
                            <input type="checkbox" id="pushNotificationMobile" checked class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color">
                            <span class="ml-2 text-sm text-text-primary dark:text-dark-text-primary">接收推送通知</span>
                        </div>
                        <p class="text-xs text-text-secondary dark:text-dark-text-secondary mt-1">接收应用推送通知</p>
                    </div>

                    <div class="border-t border-border-color dark:border-dark-border-color my-4"></div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">点赞通知</label>
                        <div class="flex items-center">
                            <input type="checkbox" id="likeNotificationMobile" checked class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color">
                            <span class="ml-2 text-sm text-text-primary dark:text-dark-text-primary">当有人点赞时通知我</span>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">评论通知</label>
                        <div class="flex items-center">
                            <input type="checkbox" id="commentNotificationMobile" checked class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color">
                            <span class="ml-2 text-sm text-text-primary dark:text-dark-text-primary">当有人评论时通知我</span>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">关注通知</label>
                        <div class="flex items-center">
                            <input type="checkbox" id="followNotificationMobile" checked class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color">
                            <span class="ml-2 text-sm text-text-primary dark:text-dark-text-primary">当有人关注我时通知我</span>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">私信通知</label>
                        <div class="flex items-center">
                            <input type="checkbox" id="messageNotificationMobile" checked class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color">
                            <span class="ml-2 text-sm text-text-primary dark:text-dark-text-primary">当收到私信时通知我</span>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">系统通知</label>
                        <div class="flex items-center">
                            <input type="checkbox" id="systemNotificationMobile" checked class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color">
                            <span class="ml-2 text-sm text-text-primary dark:text-dark-text-primary">接收系统通知</span>
                        </div>
                    </div>

                    <div class="border-t border-border-color dark:border-dark-border-color my-4"></div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">通知声音</label>
                        <div class="flex items-center">
                            <input type="checkbox" id="notificationSoundMobile" checked class="w-4 h-4 text-primary focus:ring-primary border-border-color dark:border-dark-border-color">
                            <span class="ml-2 text-sm text-text-primary dark:text-dark-text-primary">播放通知声音</span>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">免打扰时间</label>
                        <div class="flex gap-2 items-center">
                            <input type="time" class="flex-1 px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="quietHoursStartMobile" value="22:00">
                            <span class="text-text-primary dark:text-dark-text-primary">至</span>
                            <input type="time" class="flex-1 px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="quietHoursEndMobile" value="08:00">
                        </div>
                        <p class="text-xs text-text-secondary dark:text-dark-text-secondary mt-1">在此时间段内不会收到通知</p>
                    </div>

                    <button type="button" class="w-full bg-primary hover:bg-darkPrimary text-white py-2 rounded-lg transition-colors" onclick="saveNotificationSettings()">保存通知设置</button>
                </form>
            </div>

            <div id="realname-mobile" class="tab-pane-mobile hidden">
                <h3 class="text-lg font-semibold text-text-primary dark:text-dark-text-primary mb-4">实名认证</h3>
                
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 mb-4">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="fa fa-info-circle text-blue-500"></i>
                        <span class="text-sm font-medium text-blue-700 dark:text-blue-300">认证状态</span>
                    </div>
                    <span id="authStatusMobile" class="text-sm text-text-secondary dark:text-dark-text-secondary">未认证</span>
                </div>

                <form id="realnameFormMobile">
                    <div class="mb-4">
                        <label for="realNameMobile" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">真实姓名<span class="text-red-500">*</span></label>
                        <input type="text" class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="realNameMobile" placeholder="请输入您的真实姓名">
                        <p class="text-xs text-text-secondary dark:text-dark-text-secondary mt-1">请与身份证上的姓名保持一致</p>
                    </div>

                    <div class="mb-4">
                        <label for="idCardMobile" class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">身份证号<span class="text-red-500">*</span></label>
                        <input type="text" class="w-full px-3 py-2 border border-border-color dark:border-dark-border-color rounded-lg bg-white dark:bg-dark-bg-card text-text-primary dark:text-dark-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" id="idCardMobile" placeholder="请输入您的身份证号" maxlength="18">
                        <p class="text-xs text-text-secondary dark:text-dark-text-secondary mt-1">请输入18位身份证号码</p>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">身份证正面照片<span class="text-red-500">*</span></label>
                        <div class="flex flex-col gap-2">
                            <img id="idCardFrontPreviewMobile" src="/images/default-idcard-front.png" alt="身份证正面" class="w-full h-32 object-cover rounded-lg border border-border-color dark:border-dark-border-color">
                            <label class="bg-gray-100 dark:bg-gray-700 text-text-primary dark:text-dark-text-primary px-4 py-2 rounded-lg text-center cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                                <i class="fa fa-upload mr-2"></i>上传照片
                                <input type="file" id="idCardFrontInputMobile" accept="image/*" class="hidden" onchange="previewImage(this, 'idCardFrontPreviewMobile')">
                            </label>
                            <p class="text-xs text-text-secondary dark:text-dark-text-secondary">支持 JPG、PNG 格式，大小不超过 5MB</p>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">身份证背面照片<span class="text-red-500">*</span></label>
                        <div class="flex flex-col gap-2">
                            <img id="idCardBackPreviewMobile" src="/images/default-idcard-back.png" alt="身份证背面" class="w-full h-32 object-cover rounded-lg border border-border-color dark:border-dark-border-color">
                            <label class="bg-gray-100 dark:bg-gray-700 text-text-primary dark:text-dark-text-primary px-4 py-2 rounded-lg text-center cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                                <i class="fa fa-upload mr-2"></i>上传照片
                                <input type="file" id="idCardBackInputMobile" accept="image/*" class="hidden" onchange="previewImage(this, 'idCardBackPreviewMobile')">
                            </label>
                            <p class="text-xs text-text-secondary dark:text-dark-text-secondary">支持 JPG、PNG 格式，大小不超过 5MB</p>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-text-primary dark:text-dark-text-primary mb-2">手持身份证照片<span class="text-red-500">*</span></label>
                        <div class="flex flex-col gap-2">
                            <img id="handheldIdCardPreviewMobile" src="/images/default-handheld-idcard.png" alt="手持身份证" class="w-full h-32 object-cover rounded-lg border border-border-color dark:border-dark-border-color">
                            <label class="bg-gray-100 dark:bg-gray-700 text-text-primary dark:text-dark-text-primary px-4 py-2 rounded-lg text-center cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                                <i class="fa fa-upload mr-2"></i>上传照片
                                <input type="file" id="handheldIdCardInputMobile" accept="image/*" class="hidden" onchange="previewImage(this, 'handheldIdCardPreviewMobile')">
                            </label>
                            <p class="text-xs text-text-secondary dark:text-dark-text-secondary">支持 JPG、PNG 格式，大小不超过 5MB</p>
                        </div>
                    </div>

                    <button type="button" class="w-full bg-primary hover:bg-darkPrimary text-white py-2 rounded-lg transition-colors" id="submitAuthBtnMobile" onclick="submitRealnameAuth()">
                        <i class="fa fa-paper-plane mr-2"></i>提交认证
                    </button>
                </form>
            </div>

            <div class="mt-6 pt-6 border-t border-border-color dark:border-dark-border-color">
                <button type="button" class="w-full bg-red-500 hover:bg-red-600 text-white py-2 rounded-lg transition-colors" onclick="deleteAccount()">
                    <i class="fa fa-trash mr-2"></i>注销账户
                </button>
                <p class="text-xs text-text-secondary dark:text-dark-text-secondary mt-2 text-center">此操作不可逆，请谨慎操作</p>
            </div>
        </div>
    </div>
</div>

<script>
    // 移动端标签页切换功能
    document.addEventListener('DOMContentLoaded', function() {
        const tabButtonsMobile = document.querySelectorAll('.tab-button-mobile');
        const tabPanesMobile = document.querySelectorAll('.tab-pane-mobile');

        tabButtonsMobile.forEach(button => {
            button.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');

                // 移除所有按钮的活动状态
                tabButtonsMobile.forEach(btn => {
                    btn.classList.remove('active', 'border-primary', 'text-primary');
                    btn.classList.add('border-transparent', 'text-text-secondary', 'dark:text-dark-text-secondary');
                });

                // 添加当前按钮的活动状态
                this.classList.add('active', 'border-primary', 'text-primary');
                this.classList.remove('border-transparent', 'text-text-secondary', 'dark:text-dark-text-secondary');

                // 隐藏所有标签内容
                tabPanesMobile.forEach(pane => {
                    pane.classList.add('hidden');
                    pane.classList.remove('active');
                });

                // 显示当前标签内容
                const activePane = document.getElementById(tabId);
                activePane.classList.remove('hidden');
                activePane.classList.add('active');
            });
        });
    });
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
