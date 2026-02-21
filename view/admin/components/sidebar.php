<?php
/**
 * 侧边栏导航组件
 *
 * 使用方式：
 * 在视图中引入：
 * <?php include view_path() . '/admin/components/sidebar.php'; ?>
 *
 * 参数：
 * $current_active - 当前激活的菜单项（如 'user', 'content', 'moments' 等）
 */

$menuConfig = require __DIR__ . '/../sidebar_config.php';

// 获取当前 URL 路径，用于自动判断激活菜单
$currentUrl = $_SERVER['REQUEST_URI'] ?? '';

function isActive($item, $currentUrl) {
    if (!isset($item['url'])) {
        return false;
    }

    // 检查自身是否匹配
    return strpos($currentUrl, $item['url']) !== false;
}

function isChildActive($item, $currentUrl) {
    if (!isset($item['children'])) {
        return false;
    }

    foreach ($item['children'] as $child) {
        if (isset($child['url']) && strpos($currentUrl, $child['url']) !== false) {
            return true;
        }
    }

    return false;
}

function findActiveChild($item, $currentUrl) {
    if (!isset($item['children'])) {
        return '';
    }

    foreach ($item['children'] as $child) {
        if (isset($child['url']) && strpos($currentUrl, $child['url']) !== false) {
            return $child['url'];
        }
    }

    return '';
}
?>

<!-- 侧边栏 -->
<aside class="sidebar w-64 h-screen flex flex-col overflow-hidden fixed left-0 top-0 z-40">
    <!-- 侧边栏头部 -->
    <div class="p-4 border-b border-gray-700 flex-shrink-0">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-full flex items-center justify-center mr-3">
                <i class="fas fa-shield-alt text-white"></i>
            </div>
            <h2 class="text-white font-bold">后台管理</h2>
        </div>
    </div>

    <!-- 侧边栏菜单 -->
    <nav class="flex-1 overflow-y-auto p-2">
        <ul class="sidebar-menu">
            <?php foreach ($menuConfig as $menuKey => $menuItem): ?>
                <?php
                    $hasChildren = isset($menuItem['children']) && count($menuItem['children']) > 0;
                    $active = isActive($menuItem, $currentUrl);
                    $childActive = isChildActive($menuItem, $currentUrl);
                ?>
                
                <?php if ($hasChildren): ?>
                    <!-- 有子菜单的项 -->
                    <li class="menu-item <?php echo $active || $childActive ? 'active' : ''; ?>">
                        <a href="javascript:void(0)" class="menu-toggle" data-menu="<?php echo $menuKey; ?>">
                            <div class="flex items-center justify-between w-full">
                                <div class="flex items-center">
                                    <i class="<?php echo $menuItem['icon']; ?>"></i>
                                    <span><?php echo $menuItem['title']; ?></span>
                                </div>
                                <i class="fas fa-chevron-down transition-transform <?php echo $active || $childActive ? 'rotate-180' : ''; ?> text-xs"></i>
                            </div>
                        </a>
                        
                        <!-- 子菜单 -->
                        <ul class="submenu <?php echo $active || $childActive ? 'show' : ''; ?>" id="submenu-<?php echo $menuKey; ?>">
                            <?php foreach ($menuItem['children'] as $child): ?>
                                <?php
                                    $childIsActive = isset($child['url']) && strpos($currentUrl, $child['url']) !== false;
                                ?>
                                <li class="<?php echo $childIsActive ? 'active' : ''; ?>">
                                    <a href="<?php echo $child['url']; ?>">
                                        <span><?php echo $child['title']; ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php else: ?>
                    <!-- 没有子菜单的项 -->
                    <li class="<?php echo $active ? 'active' : ''; ?>">
                        <a href="<?php echo $menuItem['url']; ?>">
                            <i class="<?php echo $menuItem['icon']; ?>"></i>
                            <span><?php echo $menuItem['title']; ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </nav>

    <!-- 侧边栏底部 -->
    <div class="p-4 border-t border-gray-700 flex-shrink-0">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-gray-700 rounded-full flex items-center justify-center mr-3">
                <i class="fas fa-user"></i>
            </div>
            <div class="flex-1">
                <p class="text-white text-sm font-medium"><?php echo $admin_name ?? '管理员'; ?></p>
                <p class="text-gray-400 text-xs">管理员</p>
            </div>
            <a href="/admin/login/logout" class="text-gray-400 hover:text-red-400">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</aside>

<!-- 侧边栏折叠/展开的样式 -->
<style>
    .sidebar {
        background: linear-gradient(to bottom, #1e293b, #334155);
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    }

    .sidebar-menu li {
        transition: all 0.3s ease;
    }

    .sidebar-menu li:hover {
        background-color: rgba(148, 163, 184, 0.1);
    }

    .sidebar-menu li.active {
        background-color: rgba(96, 165, 250, 0.2);
        border-left: 4px solid #60a5fa;
    }

    .sidebar-menu li.active a {
        color: #60a5fa;
    }

    .sidebar-menu a {
        display: flex;
        align-items: center;
        padding: 12px 20px;
        color: #cbd5e1;
        transition: all 0.3s ease;
    }

    .sidebar-menu a:hover {
        color: #60a5fa;
    }

    .sidebar-menu i {
        width: 20px;
        margin-right: 10px;
    }

    .submenu {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
    }
    
    .submenu.show {
        max-height: 500px;
    }
    
    .menu-item.active .submenu {
        max-height: 500px;
    }
    
    .submenu li {
        padding-left: 0;
        transition: all 0.3s ease;
    }

    .submenu li a {
        padding: 10px 10px 10px 50px;
        font-size: 0.875rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .submenu li.active a {
        color: #60a5fa;
        background-color: rgba(96, 165, 250, 0.1);
    }
    
    .submenu li:hover a {
        color: #60a5fa;
    }
    
    .fa-chevron-down.rotate-180 {
        transform: rotate(180deg);
    }
</style>

<!-- 侧边栏菜单折叠/展开脚本 -->
<script>
(function() {
    // 获取当前激活的菜单key(从页面中的current_active变量推断)
    function getCurrentActiveMenu() {
        const activeSubmenu = document.querySelector('.submenu li.active');
        if (activeSubmenu) {
            const activeItem = activeSubmenu.closest('li.menu-item');
            if (activeItem) {
                const toggle = activeItem.querySelector('.menu-toggle');
                return toggle ? toggle.getAttribute('data-menu') : null;
            }
        }
        return null;
    }

    // 保存折叠状态到本地存储
    function saveMenuState(menuKey, isOpen) {
        const menuState = JSON.parse(localStorage.getItem('adminMenuState') || '{}');
        menuState[menuKey] = isOpen;
        localStorage.setItem('adminMenuState', JSON.stringify(menuState));
    }

    // 从本地存储加载折叠状态
    function loadMenuState() {
        const menuState = JSON.parse(localStorage.getItem('adminMenuState') || '{}');

        Object.keys(menuState).forEach(menuKey => {
            if (menuState[menuKey]) {
                const submenu = document.getElementById('submenu-' + menuKey);
                const chevron = document.querySelector(`.menu-toggle[data-menu="${menuKey}"] .fa-chevron-down`);

                if (submenu) {
                    submenu.classList.add('show');
                }
                if (chevron) {
                    chevron.classList.add('rotate-180');
                }
            }
        });
    }

    // 获取所有可折叠的菜单
    const menuToggles = document.querySelectorAll('.menu-toggle');

    menuToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();

            const menuKey = this.getAttribute('data-menu');
            const submenu = document.getElementById('submenu-' + menuKey);
            const chevron = this.querySelector('.fa-chevron-down');

            // 切换显示/隐藏
            if (submenu.classList.contains('show')) {
                submenu.classList.remove('show');
                chevron.classList.remove('rotate-180');
                saveMenuState(menuKey, false);
            } else {
                submenu.classList.add('show');
                chevron.classList.add('rotate-180');
                saveMenuState(menuKey, true);
            }
        });
    });

    // 页面加载时恢复菜单状态
    loadMenuState();

    // 自动展开当前激活的菜单
    const activeMenuKey = getCurrentActiveMenu();
    if (activeMenuKey) {
        const submenu = document.getElementById('submenu-' + activeMenuKey);
        const chevron = document.querySelector(`.menu-toggle[data-menu="${activeMenuKey}"] .fa-chevron-down`);

        if (submenu) {
            submenu.classList.add('show');
        }
        if (chevron) {
            chevron.classList.add('rotate-180');
        }
        // 保存当前激活菜单的状态
        saveMenuState(activeMenuKey, true);

        // 滚动到激活的菜单项
        setTimeout(() => {
            const activeSubmenuItem = document.querySelector('.submenu li.active');
            if (activeSubmenuItem) {
                activeSubmenuItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, 100);
    }

    // 导航栏滚动位置处理 - 重置到顶部，避免混乱
    const nav = document.querySelector('.sidebar-menu');
    if (nav) {
        // 页面加载时重置滚动位置到顶部
        nav.scrollTop = 0;
    }
})();
</script>
