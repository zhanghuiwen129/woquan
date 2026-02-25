# 后台侧边栏迁移快速指南

## 问题现状

后台各页面都独立实现了侧边栏导航，导致：
- 代码重复严重
- 维护困难
- 添加新功能需要修改多个文件

## 解决方案

使用统一的侧边栏配置和组件系统。

## 一、核心文件

| 文件 | 说明 |
|------|------|
| `sidebar_config.php` | 侧边栏菜单配置（添加/修改菜单项） |
| `components/sidebar.php` | 侧边栏组件（自动渲染） |
| `layout.html` | 主布局模板（已集成侧边栏） |

## 二、三种使用方式

### 方式1：使用 layout.html（最简单，推荐）

```html
{extend name="admin/layout" /}

{block name="content"}
    <div class="card p-6">
        <h1>我的页面</h1>
    </div>
{/block}
```

**控制器中：**
```php
View::assign('active', 'user');  // 对应 sidebar_config.php 中的 active 值
View::assign('admin_name', '管理员');
return View::fetch();
```

---

### 方式2：手动引入侧边栏（适用于特殊页面）

```html
<!DOCTYPE html>
<html>
<head>
    <title>我的页面</title>
    <script src="https://cdn.tailwindcss.com?hide-warning=true"></script>
    <!-- 其他样式 -->
</head>
<body class="flex">
    <!-- 引入侧边栏 -->
    <?php
        $current_active = 'user';
        include __DIR__ . '/admin/components/sidebar.php';
    ?>

    <!-- 主内容区 -->
    <main class="flex-1 p-6 bg-modern-light">
        <!-- 页面内容 -->
    </main>
</body>
</html>
```

---

### 方式3：迁移现有页面（去掉旧的侧边栏代码）

**步骤：**

1. 找到视图文件中旧的 `<aside>` 标签，删除整个侧边栏代码

2. 在 `<main>` 标签**之前**添加：
```php
<?php
    $current_active = 'user';  // 改为对应的 active 值
    include __DIR__ . '/admin/components/sidebar.php';
?>
```

3. 确保 `<body>` 标签有 `class="flex"`

**示例（迁移前）：**
```html
<body class="font-sans">
    <aside class="sidebar w-64 h-screen">
        <!-- 旧的侧边栏代码，删除 -->
    </aside>
    <main class="flex-1">
        <!-- 内容 -->
    </main>
</body>
```

**示例（迁移后）：**
```html
<body class="flex">
    <?php
        $current_active = 'user';
        include __DIR__ . '/admin/components/sidebar.php';
    ?>
    <main class="flex-1">
        <!-- 内容 -->
    </main>
</body>
```

---

## 三、如何添加新的菜单项

### 步骤1：修改 `sidebar_config.php`

找到合适的位置，添加配置：

```php
// ===== 内容管理 =====
'content' => [
    'title' => '内容管理',
    'icon' => 'fa-file-alt',
    'active' => 'content',
    'children' => [
        ['title' => '文章管理', 'url' => '/admin/content', 'active' => 'content'],
        ['title' => '动态管理', 'url' => '/admin/content/moments', 'active' => 'moments'],
        
        // ✅ 在这里添加新菜单
        ['title' => '视频管理', 'url' => '/admin/content/videos', 'active' => 'videos'],
    ]
],
```

### 步骤2：创建视图文件

使用 layout.html：
```html
{extend name="admin/layout" /}

{block name="title"}
<title>视频管理 - 后台管理</title>
{/block}

{block name="content"}
<div class="card p-6">
    <h1 class="text-2xl font-bold mb-4">视频管理</h1>
    <!-- 视频列表 -->
</div>
{/block}
```

### 步骤3：在控制器中设置 active

```php
public function index()
{
    View::assign('active', 'videos');  // ✅ 对应配置文件中的 active 值
    return View::fetch();
}
```

完成！现在侧边栏会自动显示"视频管理"菜单项。

---

## 四、常用 active 值速查表

| 页面 | active 值 |
|------|-----------|
| 首页 | `index` |
| 用户管理 | `user` |
| 用户标签 | `user_tags` |
| 用户分组 | `user_groups` |
| 文章管理 | `content` |
| 动态管理 | `moments` |
| 评论管理 | `comments` |
| 举报管理 | `reports` |
| 分类管理 | `category` |
| 任务管理 | `task` |
| 系统设置 | `setting` |
| 基本设置 | `setting_basic` |
| 系统日志 | `log` |

完整列表请查看 `sidebar_config.php` 文件。

---

## 五、常见问题

### Q1: 迁移后侧边栏不显示？

**检查：**
1. `$current_active` 变量是否设置
2. `include` 路径是否正确
3. `<body>` 是否有 `class="flex"`

### Q2: 菜单项没有高亮？

**检查：**
1. `$active` 或 `$current_active` 值是否正确
2. `sidebar_config.php` 中是否配置了对应的 active 值

### Q3: 如何让菜单默认展开？

菜单会根据当前激活的 `active` 值自动展开，无需额外配置。

### Q4: 子菜单如何配置？

```php
'my_module' => [
    'title' => '我的模块',
    'icon' => 'fa-icon',
    'active' => 'my_module',  // 父菜单的 active
    'children' => [
        [
            'title' => '子菜单1',
            'url' => '/admin/module/list',
            'active' => 'module_list'  // 子菜单的 active
        ],
        [
            'title' => '子菜单2',
            'url' => '/admin/module/add',
            'active' => 'module_add'
        ]
    ]
]
```

---

## 六、完整示例：添加"广告管理"模块

### 1. 修改 `sidebar_config.php`

在"系统设置"之后添加：

```php
// ===== 系统设置 =====
'setting' => [...],

// ===== 广告管理 =====
'ad' => [
    'title' => '广告管理',
    'icon' => 'fa-ad',
    'active' => 'ad',
    'children' => [
        [
            'title' => '广告列表',
            'url' => '/admin/ad',
            'active' => 'ad'
        ],
        [
            'title' => '广告位管理',
            'url' => '/admin/ad/positions',
            'active' => 'ad_positions'
        ],
        [
            'title' => '投放统计',
            'url' => '/admin/ad/statistics',
            'active' => 'ad_statistics'
        ]
    ]
],

// ===== 系统日志 =====
'log' => [...]
```

### 2. 创建控制器 `app/controller/admin/Ad.php`

```php
<?php
namespace app\controller\admin;

use think\facade\View;

class Ad
{
    public function index()
    {
        View::assign('active', 'ad');
        View::assign('admin_name', session('admin_name'));
        View::assign('ads', []);  // 获取广告数据
        return View::fetch();
    }
    
    public function positions()
    {
        View::assign('active', 'ad_positions');
        return View::fetch();
    }
    
    public function statistics()
    {
        View::assign('active', 'ad_statistics');
        return View::fetch();
    }
}
```

### 3. 创建视图 `view/admin/ad/index.html`

```html
{extend name="admin/layout" /}

{block name="title"}
<title>广告管理 - 后台管理</title>
{/block}

{block name="content"}
<div class="card p-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">广告列表</h1>
        <a href="/admin/ad/add" class="px-4 py-2 bg-blue-500 text-white rounded">
            添加广告
        </a>
    </div>
    
    <!-- 广告列表 -->
    <table class="w-full">
        <thead>
            <tr class="bg-gray-100">
                <th class="p-3 text-left">ID</th>
                <th class="p-3 text-left">标题</th>
                <th class="p-3 text-left">状态</th>
                <th class="p-3 text-left">操作</th>
            </tr>
        </thead>
        <tbody>
            <!-- 广告数据 -->
        </tbody>
    </table>
</div>
{/block}
```

### 4. 配置路由（可选）

```php
// route/admin.php
Route::get('admin/ad', 'admin/Ad/index');
Route::get('admin/ad/positions', 'admin/Ad/positions');
Route::get('admin/ad/statistics', 'admin/Ad/statistics');
```

完成！现在访问 `/admin/ad` 就能看到新的"广告管理"菜单，并且正确高亮显示。

---

## 七、总结

### ✅ 优势
- **统一管理**：所有菜单在一个配置文件中
- **易于维护**：修改一次，全局生效
- **自动高亮**：根据 active 值自动高亮对应菜单
- **折叠记忆**：自动保存用户菜单展开状态
- **响应式**：支持移动端自适应

### 📝 最佳实践
1. 新页面优先使用 `layout.html`
2. 旧页面逐步迁移到新侧边栏系统
3. 保持 active 值的语义化命名
4. 定期清理不再使用的旧侧边栏代码

### 📚 相关文档
- `sidebar_config.php` - 查看完整菜单配置
- `components/sidebar.php` - 侧边栏组件源码
- `layout.html` - 主布局模板
- `README_SIDEBAR.md` - 详细使用文档

---

**最后更新**：2026-01-30
**维护者**：开发团队
