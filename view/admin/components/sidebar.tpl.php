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

if (!isset($current_active)) {
    $current_active = '';
}

$menuConfig = require __DIR__ . '/../sidebar_config.php';

function isActive($item, $current) {
    if (!isset($item['active'])) {
        return false;
    }
    
    // 如果有子菜单，检查是否有子菜单项匹配
    if (isset($item['children'])) {
        foreach ($item['children'] as $child) {
            if (isset($child['active']) && $child['active'] === $current) {
                return true;
            }
        }
    }
    
    // 检查自身是否匹配
    return $item['active'] === $current;
}

function isChildActive($item, $current) {
    if (!isset($item['children'])) {
        return false;
    }
    
    foreach ($item['children'] as $child) {
        if (isset($child['active']) && $child['active'] === $current) {
            return true;
        }
    }
    
    return false;
}
?>

<!-- 侧边栏 -->
<aside class="sidebar w-64 h-screen flex flex-col overflow-hidden">
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
                    $active = isActive($menuItem, $current_active);
                    $childActive = isChildActive($menuItem, $current_active);
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
                                    $childIsActive = isset($child['active']) && $child['active'] === $current_active;
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
        padding-left: 50px;
        transition: all 0.3s ease;
    }
    
    .submenu li a {
        padding: 10px 20px;
        font-size: 0.875rem;
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
            } else {
                submenu.classList.add('show');
                chevron.classList.add('rotate-180');
            }
        });
    });
    
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
    
    // 页面加载时恢复菜单状态
    loadMenuState();
    
    // 监听菜单点击事件并保存状态
    menuToggles.forEach(toggle => {
        toggle.addEventListener('click', function() {
            const menuKey = this.getAttribute('data-menu');
            const submenu = document.getElementById('submenu-' + menuKey);
            saveMenuState(menuKey, submenu.classList.contains('show'));
        });
    });
})();
</script>
