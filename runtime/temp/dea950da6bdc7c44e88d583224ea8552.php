<?php /*a:2:{s:36:"D:\wwwroot\view\index\following.html";i:1770122626;s:38:"D:\wwwroot\view\index\main-layout.html";i:1770774785;}*/ ?>
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
    .follow-item {
        background: white;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .follow-item:hover {
        box-shadow: 0 4px 12px rgba(74, 144, 226, 0.15);
        transform: translateY(-2px);
        cursor: pointer;
    }

    .follow-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
    }

    .follow-time {
        font-size: 12px;
        color: var(--text-tertiary);
    }

    /* 名片弹窗样式 */
    #cardModal {
        z-index: 9999;
    }

    .modal-card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        max-width: 400px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
    }

    .modal-avatar {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid var(--primary-color);
    }

    .stat-number {
        font-size: 18px;
        font-weight: bold;
        color: var(--text-primary);
    }

    .stat-label {
        font-size: 12px;
        color: var(--text-secondary);
    }
</style>

<!-- 名片弹窗 -->
<div id="cardModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center p-4">
    <div class="modal-card p-6">
        <div class="flex justify-between items-start mb-4">
            <div class="flex items-center gap-3">
                <img id="modalAvatar" src="" alt="头像" class="modal-avatar">
                <div>
                    <h3 id="modalNickname" class="text-xl font-bold text-gray-800"></h3>
                    <p id="modalUsername" class="text-sm text-gray-500"></p>
                </div>
            </div>
            <button onclick="closeCardModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                <i class="fa fa-times"></i>
            </button>
        </div>

        <div id="modalBio" class="text-sm text-gray-600 mb-4 p-3 bg-gray-50 rounded-lg"></div>

        <div class="grid grid-cols-4 gap-4 mb-4 text-center">
            <div>
                <p id="modalMoments" class="stat-number">0</p>
                <p class="stat-label">动态</p>
            </div>
            <div>
                <p id="modalFollowing" class="stat-number">0</p>
                <p class="stat-label">关注</p>
            </div>
            <div>
                <p id="modalFollowers" class="stat-number">0</p>
                <p class="stat-label">粉丝</p>
            </div>
            <div>
                <p id="modalLikes" class="stat-number">0</p>
                <p class="stat-label">获赞</p>
            </div>
        </div>

        <div class="flex gap-3">
            <button id="modalFollowBtn" class="flex-1 bg-primary text-white py-2 rounded-full font-medium hover:bg-secondary transition-colors">
                关注
            </button>
            <a id="modalCardLink" href="" class="flex-1 bg-gray-100 text-gray-700 py-2 rounded-full font-medium hover:bg-gray-200 transition-colors text-center">
                查看完整名片
            </a>
        </div>
    </div>
</div>

<!-- 关注列表 - 使用PHP渲染的数据 -->
<?php if(isset($following) && count($following) > 0): ?>
<div id="follow-list" class="space-y-4">
    <?php if(is_array($following) || $following instanceof \think\Collection || $following instanceof \think\Paginator): $i = 0; $__LIST__ = $following;if( count($__LIST__)==0 ) : echo "" ;else: foreach($__LIST__ as $key=>$user): $mod = ($i % 2 );++$i;?>
    <div class="follow-item" data-user-id="<?php echo htmlentities((string) $user['id']); ?>" onclick="showCardModal(this)">
        <div class="flex items-center gap-3 flex-1">
            <img src="<?php echo htmlentities((string) (isset($user['avatar']) && ($user['avatar'] !== '')?$user['avatar']:'/static/images/default-avatar.png')); ?>" alt="头像" class="follow-avatar">
            <div class="flex-1">
                <h3 class="font-semibold text-gray-800"><?php echo htmlentities((string) (isset($user['nickname']) && ($user['nickname'] !== '')?$user['nickname']:$user['username'])); ?></h3>
                <p class="text-sm text-gray-500 truncate"><?php echo htmlentities((string) (isset($user['bio']) && ($user['bio'] !== '')?$user['bio']:'暂无个性签名')); ?></p>
            </div>
        </div>
    </div>
    <?php endforeach; endif; else: echo "" ;endif; ?>
</div>
<?php else: ?>
<div id="empty" class="text-center py-16">
    <div class="w-24 h-24 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <i class="fa fa-user-plus text-4xl text-blue-500"></i>
    </div>
    <h2 class="text-xl font-bold text-gray-800 mb-2">暂无关注</h2>
    <p class="text-gray-500 mb-4">去发现有趣的人并关注他们吧</p>
    <a href="/discover#recommended" class="inline-block bg-primary text-white px-6 py-2 rounded-full hover:bg-secondary transition-colors">
        去发现
    </a>
</div>
<?php endif; ?>

<script>
    function showToast(message) {
        const toast = document.createElement('div');
        toast.className = 'fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-gray-800 text-white px-4 py-2 rounded-lg z-50';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function() {
            toast.remove();
        }, 2000);
    }

    // 显示名片弹窗
    async function showCardModal(element) {
        try {
            // 从元素上获取用户ID
            const userId = element.getAttribute('data-user-id');
            if (!userId) {
                showToast('用户ID不存在');
                return;
            }

            // 根据设备选择弹窗：PC端优先使用PC弹窗，移动端优先使用移动端弹窗
            let isMobile = window.innerWidth <= 768;
            let modal = isMobile
                ? document.getElementById('cardModalMobile')
                : document.getElementById('cardModal');
            if (!modal) {
                console.error('找不到 cardModal 元素');
                showToast('弹窗加载失败');
                return;
            }
            modal.classList.remove('hidden');

            // 获取用户名片信息
            const response = await fetch(`/user/card?user_id=${userId}`);
            const result = await response.json();

            if (result.code === 200 && result.data) {
                const user = result.data.user;
                const stats = result.data.stats;

                // 在当前选中的弹窗内查找元素
                const modalAvatar = modal.querySelector('#modalAvatar');
                const modalNickname = modal.querySelector('#modalNickname');
                const modalUsername = modal.querySelector('#modalUsername');
                const modalBio = modal.querySelector('#modalBio');
                const modalMoments = modal.querySelector('#modalMoments');
                const modalFollowing = modal.querySelector('#modalFollowing');
                const modalFollowers = modal.querySelector('#modalFollowers');
                const modalLikes = modal.querySelector('#modalLikes');
                const modalFollowBtn = modal.querySelector('#modalFollowBtn');
                const modalCardLink = modal.querySelector('#modalCardLink');

                // 检查所有必需元素是否存在
                if (!modalAvatar || !modalNickname || !modalUsername || !modalBio ||
                    !modalMoments || !modalFollowing || !modalFollowers || !modalLikes ||
                    !modalFollowBtn || !modalCardLink) {
                    console.error('弹窗元素加载失败');
                    showToast('弹窗元素加载失败');
                    return;
                }

                // 更新弹窗内容
                modalAvatar.src = user.avatar || '/static/images/default-avatar.png';
                modalNickname.textContent = user.nickname || user.username;
                modalUsername.textContent = '@' + user.username;
                modalBio.textContent = user.bio || user.motto || '暂无个性签名';

                // 更新统计数据
                modalMoments.textContent = stats.moments || 0;
                modalFollowing.textContent = stats.following || 0;
                modalFollowers.textContent = stats.followers || 0;
                modalLikes.textContent = stats.likes || 0;

                // 更新关注按钮状态
                if (result.data.is_following) {
                    modalFollowBtn.textContent = '已关注';
                    modalFollowBtn.className = 'flex-1 bg-gray-300 text-gray-700 py-2 rounded-full font-medium hover:bg-gray-400 transition-colors';
                } else {
                    modalFollowBtn.textContent = '关注';
                    modalFollowBtn.className = 'flex-1 bg-primary text-white py-2 rounded-full font-medium hover:bg-secondary transition-colors';
                }

                // 绑定关注按钮点击事件
                modalFollowBtn.onclick = async function() {
                    const action = modalFollowBtn.textContent === '关注' ? 'follow' : 'unfollow';
                    try {
                        const followResponse = await fetch('/user/follow', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ target_id: userId, action: action })
                        });
                        const followResult = await followResponse.json();

                        if (followResult.code === 200) {
                            if (followResult.data && followResult.data.following) {
                                modalFollowBtn.textContent = '已关注';
                                modalFollowBtn.className = 'flex-1 bg-gray-300 text-gray-700 py-2 rounded-full font-medium hover:bg-gray-400 transition-colors';
                                showToast('关注成功');
                            } else {
                                modalFollowBtn.textContent = '关注';
                                modalFollowBtn.className = 'flex-1 bg-primary text-white py-2 rounded-full font-medium hover:bg-secondary transition-colors';
                                showToast('取消关注成功');
                            }
                        }
                    } catch (error) {
                        console.error('关注操作失败:', error);
                        showToast('操作失败，请稍后重试');
                    }
                };

                // 更新查看完整名片链接
                modalCardLink.href = `/card/${userId}`;
            } else {
                showToast('获取用户信息失败');
                closeCardModal();
            }
        } catch (error) {
            console.error('加载名片失败:', error);
            showToast('加载名片失败');
            closeCardModal();
        }
    }

    // 关闭名片弹窗
    function closeCardModal() {
        const modal = document.getElementById('cardModal');
        const modalMobile = document.getElementById('cardModalMobile');
        if (modal) modal.classList.add('hidden');
        if (modalMobile) modalMobile.classList.add('hidden');
    }

    // DOM 加载完成后初始化事件监听器
    document.addEventListener('DOMContentLoaded', function() {
        const cardModal = document.getElementById('cardModal');
        if (cardModal) {
            cardModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeCardModal();
                }
            });
        } else {
            console.error('找不到 cardModal 元素，无法初始化事件监听器');
        }
    });
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
                
<style>
    .follow-item {
        background: white;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
    }

    .follow-item:hover {
        box-shadow: 0 4px 12px rgba(74, 144, 226, 0.15);
        transform: translateY(-2px);
    }

    .follow-item:active {
        transform: scale(0.98);
    }

    .follow-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        flex-shrink: 0;
    }

    /* 移动端名片弹窗样式 */
    #cardModal {
        z-index: 9999;
    }

    .modal-card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        max-width: 400px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
    }

    .modal-avatar {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid var(--primary-color);
    }

    .stat-number {
        font-size: 18px;
        font-weight: bold;
        color: var(--text-primary);
    }

    .stat-label {
        font-size: 12px;
        color: var(--text-secondary);
    }
</style>

<!-- 移动端名片弹窗 -->
<div id="cardModalMobile" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center p-4">
    <div class="modal-card p-6">
        <div class="flex justify-between items-start mb-4">
            <div class="flex items-center gap-3">
                <img id="modalAvatar" src="" alt="头像" class="modal-avatar">
                <div>
                    <h3 id="modalNickname" class="text-xl font-bold text-gray-800"></h3>
                    <p id="modalUsername" class="text-sm text-gray-500"></p>
                </div>
            </div>
            <button onclick="closeCardModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                <i class="fa fa-times"></i>
            </button>
        </div>

        <div id="modalBio" class="text-sm text-gray-600 mb-4 p-3 bg-gray-50 rounded-lg"></div>

        <div class="grid grid-cols-4 gap-4 mb-4 text-center">
            <div>
                <p id="modalMoments" class="stat-number">0</p>
                <p class="stat-label">动态</p>
            </div>
            <div>
                <p id="modalFollowing" class="stat-number">0</p>
                <p class="stat-label">关注</p>
            </div>
            <div>
                <p id="modalFollowers" class="stat-number">0</p>
                <p class="stat-label">粉丝</p>
            </div>
            <div>
                <p id="modalLikes" class="stat-number">0</p>
                <p class="stat-label">获赞</p>
            </div>
        </div>

        <div class="flex gap-3">
            <button id="modalFollowBtn" class="flex-1 bg-primary text-white py-2 rounded-full font-medium hover:bg-secondary transition-colors">
                关注
            </button>
            <a id="modalCardLink" href="" class="flex-1 bg-gray-100 text-gray-700 py-2 rounded-full font-medium hover:bg-gray-200 transition-colors text-center">
                查看完整名片
            </a>
        </div>
    </div>
</div>

<!-- 关注列表 - 使用PHP渲染的数据 -->
<?php if(isset($following) && count($following) > 0): ?>
<div id="follow-list-mobile" class="px-4 py-4 space-y-4">
    <?php if(is_array($following) || $following instanceof \think\Collection || $following instanceof \think\Paginator): $i = 0; $__LIST__ = $following;if( count($__LIST__)==0 ) : echo "" ;else: foreach($__LIST__ as $key=>$user): $mod = ($i % 2 );++$i;?>
    <div class="follow-item" data-user-id="<?php echo htmlentities((string) $user['id']); ?>" onclick="showCardModal(this)">
        <div class="flex items-center gap-3 flex-1">
            <img src="<?php echo htmlentities((string) (isset($user['avatar']) && ($user['avatar'] !== '')?$user['avatar']:'/static/images/default-avatar.png')); ?>" alt="头像" class="follow-avatar">
            <div class="flex-1">
                <h3 class="font-semibold text-gray-800"><?php echo htmlentities((string) (isset($user['nickname']) && ($user['nickname'] !== '')?$user['nickname']:$user['username'])); ?></h3>
                <p class="text-sm text-gray-500 truncate"><?php echo htmlentities((string) (isset($user['bio']) && ($user['bio'] !== '')?$user['bio']:'暂无个性签名')); ?></p>
            </div>
        </div>
    </div>
    <?php endforeach; endif; else: echo "" ;endif; ?>
</div>
<?php else: ?>
<div id="empty-mobile" class="text-center py-16 px-4">
    <div class="w-24 h-24 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <i class="fa fa-user-plus text-4xl text-blue-500"></i>
    </div>
    <h2 class="text-xl font-bold text-gray-800 mb-2">暂无关注</h2>
    <p class="text-gray-500 mb-4">去发现有趣的人并关注他们吧</p>
    <a href="/discover#recommended" class="inline-block bg-primary text-white px-6 py-2 rounded-full hover:bg-secondary transition-colors">
        去发现
    </a>
</div>
<?php endif; ?>

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
    function showToast(message) {
        const toast = document.createElement('div');
        toast.className = 'fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-gray-800 text-white px-4 py-2 rounded-lg z-50';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function() {
            toast.remove();
        }, 2000);
    }

    function closeCardModal() {
        const modal = document.getElementById('cardModal');
        const modalMobile = document.getElementById('cardModalMobile');
        if (modal) modal.classList.add('hidden');
        if (modalMobile) modalMobile.classList.add('hidden');
    }

    async function showCardModal(element) {
        try {
            const userId = element.getAttribute('data-user-id');
            if (!userId) {
                showToast('用户ID不存在');
                return;
            }

            // 检查是否有弹窗元素，如果没有则创建
            let isMobile = window.innerWidth <= 768;
            let modal = isMobile
                ? document.getElementById('cardModalMobile')
                : document.getElementById('cardModal');

            if (!modal) {
                // 创建弹窗
                modal = document.createElement('div');
                modal.id = 'cardModal';
                modal.className = 'fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center p-4 z-[9999]';
                modal.innerHTML = `
                    <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full max-h-[90vh] overflow-y-auto">
                        <div class="flex justify-between items-start mb-4 p-4">
                            <div class="flex items-center gap-3">
                                <img id="modalAvatar" src="" alt="头像" class="w-16 h-16 rounded-full object-cover border-3 border-primary">
                                <div>
                                    <h3 id="modalNickname" class="text-xl font-bold text-gray-800"></h3>
                                    <p id="modalUsername" class="text-sm text-gray-500"></p>
                                </div>
                            </div>
                            <button onclick="closeCardModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>

                        <div id="modalBio" class="text-sm text-gray-600 mb-4 px-4 py-3 bg-gray-50 rounded-lg"></div>

                        <div class="grid grid-cols-4 gap-4 mb-4 px-4 text-center">
                            <div>
                                <p id="modalMoments" class="text-lg font-bold text-gray-800">0</p>
                                <p class="text-xs text-gray-500">动态</p>
                            </div>
                            <div>
                                <p id="modalFollowing" class="text-lg font-bold text-gray-800">0</p>
                                <p class="text-xs text-gray-500">关注</p>
                            </div>
                            <div>
                                <p id="modalFollowers" class="text-lg font-bold text-gray-800">0</p>
                                <p class="text-xs text-gray-500">粉丝</p>
                            </div>
                            <div>
                                <p id="modalLikes" class="text-lg font-bold text-gray-800">0</p>
                                <p class="text-xs text-gray-500">获赞</p>
                            </div>
                        </div>

                        <div class="flex gap-3 px-4 pb-4">
                            <button id="modalFollowBtn" class="flex-1 bg-primary text-white py-2 rounded-full font-medium hover:bg-secondary transition-colors text-sm">
                                关注
                            </button>
                            <a id="modalCardLink" href="" class="flex-1 bg-gray-100 text-gray-700 py-2 rounded-full font-medium hover:bg-gray-200 transition-colors text-center text-sm">
                                查看名片
                            </a>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);

                // 添加点击背景关闭弹窗的事件
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeCardModal();
                    }
                });
            }

            modal.classList.remove('hidden');

            const response = await fetch(`/user/card?user_id=${userId}`);
            const result = await response.json();

            if (result.code === 200 && result.data) {
                const user = result.data.user;
                const stats = result.data.stats;

                // 在当前选中的弹窗内查找元素
                const modalAvatar = modal.querySelector('#modalAvatar');
                const modalNickname = modal.querySelector('#modalNickname');
                const modalUsername = modal.querySelector('#modalUsername');
                const modalBio = modal.querySelector('#modalBio');
                const modalMoments = modal.querySelector('#modalMoments');
                const modalFollowing = modal.querySelector('#modalFollowing');
                const modalFollowers = modal.querySelector('#modalFollowers');
                const modalLikes = modal.querySelector('#modalLikes');
                const modalFollowBtn = modal.querySelector('#modalFollowBtn');
                const modalCardLink = modal.querySelector('#modalCardLink');

                if (modalAvatar) modalAvatar.src = user.avatar || '/static/images/default-avatar.png';
                if (modalNickname) modalNickname.textContent = user.nickname || user.username;
                if (modalUsername) modalUsername.textContent = '@' + user.username;
                if (modalBio) modalBio.textContent = user.bio || user.motto || '暂无个性签名';
                if (modalMoments) modalMoments.textContent = stats.moments || 0;
                if (modalFollowing) modalFollowing.textContent = stats.following || 0;
                if (modalFollowers) modalFollowers.textContent = stats.followers || 0;
                if (modalLikes) modalLikes.textContent = stats.likes || 0;

                if (modalFollowBtn) {
                    if (result.data.is_following) {
                        modalFollowBtn.textContent = '已关注';
                        modalFollowBtn.className = 'flex-1 bg-gray-300 text-gray-700 py-2 rounded-full font-medium hover:bg-gray-400 transition-colors text-sm';
                    } else {
                        modalFollowBtn.textContent = '关注';
                        modalFollowBtn.className = 'flex-1 bg-primary text-white py-2 rounded-full font-medium hover:bg-secondary transition-colors text-sm';
                    }

                    modalFollowBtn.onclick = async function() {
                        const action = modalFollowBtn.textContent === '关注' ? 'follow' : 'unfollow';
                        try {
                            const followResponse = await fetch('/user/follow', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ target_id: userId, action: action })
                            });
                            const followResult = await followResponse.json();

                            if (followResult.code === 200) {
                                if (followResult.data && followResult.data.following) {
                                    modalFollowBtn.textContent = '已关注';
                                    modalFollowBtn.className = 'flex-1 bg-gray-300 text-gray-700 py-2 rounded-full font-medium hover:bg-gray-400 transition-colors text-sm';
                                    showToast('关注成功');
                                } else {
                                    modalFollowBtn.textContent = '关注';
                                    modalFollowBtn.className = 'flex-1 bg-primary text-white py-2 rounded-full font-medium hover:bg-secondary transition-colors text-sm';
                                    showToast('取消关注成功');
                                }
                            }
                        } catch (error) {
                            console.error('关注操作失败:', error);
                            showToast('操作失败，请稍后重试');
                        }
                    };
                }

                if (modalCardLink) {
                    modalCardLink.href = `/card/${userId}`;
                }
            } else {
                showToast('获取用户信息失败');
                closeCardModal();
            }
        } catch (error) {
            console.error('加载名片失败:', error);
            showToast('加载名片失败');
            closeCardModal();
        }
    }
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
