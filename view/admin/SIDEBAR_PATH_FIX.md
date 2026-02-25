# Sidebar 路径修复说明

## 问题描述

错误信息：
```
include(D:\phpstudy_pro\WWW\runtime\temp/components/sidebar.php): Failed to open stream: No such file or directory
```

## 问题原因

ThinkPHP 模板引擎会将模板文件编译到 `runtime/temp` 目录。当模板中使用 `include __DIR__ . '/components/sidebar.php'` 时，`__DIR__` 在编译后的文件中指向的是 `runtime/temp` 目录，而不是原始模板目录 `view/admin`。

这导致编译后的代码尝试从 `runtime/temp/components/sidebar.php` 加载文件，但文件实际位于 `view/admin/components/sidebar.php`。

## 解决方案

将所有使用 `include __DIR__` 的地方改为使用绝对路径：

### 修复前
```php
<?php
    $current_active = 'index';
    include __DIR__ . '/components/sidebar.php';
?>
```

### 修复后
```php
<?php
    $current_active = 'index';
    include app()->getBasePath() . 'view/admin/components/sidebar.php';
?>
```

## 已修复的文件

1. `view/admin/index.html` - 后台首页
2. `view/admin/user.html` - 用户管理
3. `view/admin/content_moments.html` - 动态管理
4. `view/admin/content_reports.html` - 举报管理
5. `view/admin/layout.html` - 布局模板（使用 ThinkPHP 模板引擎）

## 清理缓存

已清理 `runtime/temp/*.php` 缓存文件，强制 ThinkPHP 重新编译模板。

## 注意事项

**在创建新的后台页面时，请务必使用绝对路径引入侧边栏组件：**

```php
<?php
    $current_active = 'your_active_key';
    include app()->getBasePath() . 'view/admin/components/sidebar.php';
?>
```

**不要使用 `__DIR__` 相对路径**，因为 ThinkPHP 模板引擎编译后会改变 `__DIR__` 的值。
