# 后台侧边栏导航使用指南

## 一、概述

现在后台管理系统已经实现了统一的侧边栏导航，所有菜单配置都在 `sidebar_config.php` 中管理。

## 二、文件结构

```
view/admin/
├── components/
│   └── sidebar.php           # 侧边栏组件（自动渲染）
├── sidebar_config.php          # 侧边栏菜单配置（添加新菜单项）
├── layout.html                # 主布局模板（已集成侧边栏）
└── [其他页面]
```

## 三、如何在现有页面中使用

### 方式一：使用 layout.html 模板（推荐）

在控制器中传入 `$active` 参数即可：

```php
// 控制器代码
public function index()
{
    // 设置当前激活的菜单项
    View::assign('active', 'user');
    
    return View::fetch('admin/user');
}
```

在视图中：
```html
{extend name="admin/layout" /}

{block name="content"}
    <!-- 页面内容 -->
{/block}
```

### 方式二：直接引入侧边栏组件（不推荐）

如果某些页面不使用 layout.html，可以手动引入：

```php
// 控制器代码
public function index()
{
    View::assign('current_active', 'user');
    View::assign('admin_name', session('admin_name'));
    return View::fetch('admin/user');
}
```

在视图中：
```html
<!DOCTYPE html>
<html>
<head>
    <!-- 头部代码 -->
</head>
<body>
    <!-- 引入侧边栏组件 -->
    <?php include __DIR__ . '/admin/components/sidebar.php'; ?>

    <!-- 主内容区 -->
    <main>
        <!-- 页面内容 -->
    </main>
</body>
</html>
```

## 四、添加新的菜单项

### 步骤1：编辑 `sidebar_config.php`

打开 `view/admin/sidebar_config.php`，在相应位置添加菜单项：

```php
return [
    // ... 其他菜单项
    
    // ===== 新增菜单组 =====
    'new_module' => [
        'title' => '新模块',
        'icon' => 'fa-plus',
        'active' => 'new_module',
        'children' => [
            [
                'title' => '列表',
                'url' => '/admin/new-module',
                'active' => 'new_module'
            ],
            [
                'title' => '添加',
                'url' => '/admin/new-module/add',
                'active' => 'new_module_add'
            ]
        ]
    ]
];
```

### 步骤2：创建对应的视图文件

创建视图文件（如 `view/admin/new_module/index.html`）：

```html
{extend name="admin/layout" /}

{block name="title"}
<title>新模块 - 后台管理</title>
{/block}

{block name="content"}
<div class="card p-6">
    <h1 class="text-2xl font-bold mb-4">新模块列表</h1>
    <!-- 页面内容 -->
</div>
{/block}
```

### 步骤3：在控制器中设置 active 参数

```php
namespace app\controller\admin;

use think\facade\View;

class NewModule
{
    public function index()
    {
        // 设置当前激活的菜单项
        View::assign('active', 'new_module');
        
        // 其他数据
        View::assign('list', []);
        
        return View::fetch();
    }
    
    public function add()
    {
        View::assign('active', 'new_module_add');
        return View::fetch();
    }
}
```

## 五、配置参数说明

### 菜单项配置

```php
[
    'title' => '菜单标题',      // 必填：显示的菜单名称
    'icon' => 'fa-icon',       // 必填：Font Awesome 图标类名
    'url' => '/admin/path',     // 有子菜单时为必填：菜单链接
    'active' => 'menu_key',     // 必填：用于匹配当前页面的标识
    'children' => [...]         // 可选：子菜单数组
]
```

### 子菜单配置

```php
[
    'title' => '子菜单标题',    // 必填
    'url' => '/admin/path',     // 必填
    'active' => 'submenu_key'   // 必填：用于匹配当前页面
]
```

## 六、现有菜单 active 值参考

| 菜单项 | active 值 | 说明 |
|--------|-----------|------|
| 控制台 | `index` | 首页 |
| 用户管理 | `user` | 用户列表 |
| 用户标签 | `user_tags` | 用户标签页 |
| 用户分组 | `user_groups` | 用户分组页 |
| 登录日志 | `user_login_logs` | 登录日志页 |
| 用户统计 | `user_statistics` | 用户统计页 |
| 内容管理 | `content` | 文章管理 |
| 动态管理 | `moments` | 动态列表 |
| 评论管理 | `comments` | 评论列表 |
| 举报管理 | `reports` | 举报列表 |
| 分类管理 | `category` | 分类列表 |
| 话题管理 | `topic` | 话题列表 |
| 任务管理 | `task` | 任务列表 |
| 系统设置 | `setting` | 系统设置首页 |
| 基本设置 | `setting_basic` | 基本设置页 |
| 网站设置 | `setting_website` | 网站设置页 |
| ... | ... | 更多请参考 `sidebar_config.php` |

## 七、特殊功能

### 1. 菜单折叠状态记忆

侧边栏会自动保存用户的菜单折叠状态到 `localStorage`，下次访问时自动恢复。

### 2. 当前页面高亮

系统会自动匹配当前激活的菜单项，高亮显示对应的菜单和父菜单。

### 3. 响应式设计

侧边栏支持移动端响应式，在小屏幕上会自动折叠。

## 八、常见问题

### Q: 如何让某个菜单默认展开？

在 `sidebar_config.php` 中，该菜单的 `active` 值会默认匹配当前页面，如果匹配成功会自动展开。

### Q: 如何禁用某个菜单项？

暂时不支持直接禁用，可以注释掉对应的配置项。

### Q: 如何修改菜单顺序？

直接调整 `sidebar_config.php` 中菜单项的数组顺序即可。

### Q: 如何添加分割线？

目前不支持直接添加分割线，可以通过创建一个不可点击的菜单项来模拟。

## 九、示例：完整的添加流程

假设要添加一个"订单管理"模块：

1. 编辑 `sidebar_config.php`，在合适位置添加：

```php
// ===== 资产管理 =====
'assets' => [...],

// ===== 订单管理 =====
'order' => [
    'title' => '订单管理',
    'icon' => 'fa-shopping-cart',
    'active' => 'order',
    'children' => [
        [
            'title' => '订单列表',
            'url' => '/admin/order',
            'active' => 'order'
        ],
        [
            'title' => '订单统计',
            'url' => '/admin/order/statistics',
            'active' => 'order_statistics'
        ]
    ]
],

// ===== 收藏管理 =====
'favorite' => [...]
```

2. 创建视图文件：

```html
{extend name="admin/layout" /}

{block name="title"}
<title>订单列表 - 后台管理</title>
{/block}

{block name="content"}
<div class="card p-6">
    <h1 class="text-2xl font-bold mb-4">订单列表</h1>
    <!-- 订单列表内容 -->
</div>
{/block}
```

3. 创建控制器：

```php
<?php
namespace app\controller\admin;

use think\facade\View;

class Order
{
    public function index()
    {
        View::assign('active', 'order');
        View::assign('admin_name', session('admin_name'));
        
        // 获取订单数据
        $orders = [];
        View::assign('orders', $orders);
        
        return View::fetch();
    }
    
    public function statistics()
    {
        View::assign('active', 'order_statistics');
        // 统计逻辑
        return View::fetch();
    }
}
```

4. 配置路由（如果需要）：

```php
// route/admin.php
Route::get('admin/order', 'admin/Order/index');
Route::get('admin/order/statistics', 'admin/Order/statistics');
```

完成！现在访问 `/admin/order` 就能看到新的菜单项并正确高亮显示。

## 十、维护建议

1. **保持 active 值唯一**：确保每个菜单项的 `active` 值是唯一的
2. **使用语义化的命名**：active 值应该清晰表达页面含义
3. **及时更新文档**：添加新菜单后，更新本文档中的参考表
4. **测试高亮效果**：每次修改后，测试菜单高亮是否正常工作
