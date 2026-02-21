<?php
/**
 * 批量迁移脚本 - 将所有后台页面迁移到使用新的侧边栏组件
 */

$adminDir = __DIR__;
$sidebarInclude = <<<PHP
    <!-- 侧边栏 -->
    <?php
        \$current_active = '%ACTIVE_KEY%';
        include __DIR__ . '/components/sidebar.php';
    ?>
PHP;

$headerInclude = <<<PHP
    <!-- 主内容区 -->
    <div class="flex-1 flex flex-col">
        <!-- 头部导航 -->
        <header class="header h-16 flex items-center justify-between px-6">
            <!-- 页面标题 -->
            <h1 class="text-xl font-semibold text-gray-800">%TITLE%</h1>

            <!-- 头部右侧工具 -->
            <div class="flex items-center space-x-4">
                <!-- 返回前台首页 -->
                <a href="/" class="flex items-center space-x-2 text-gray-500 hover:text-blue-600 transition-colors">
                    <i class="fas fa-home"></i>
                    <span class="text-sm font-medium">返回前台</span>
                </a>
                <!-- 用户信息 -->
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-gray-600"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-700 ml-2">{\$admin_name}</span>
                </div>
            </div>
        </header>

        <!-- 内容区域 -->
        <main class="content flex-1 p-6 overflow-y-auto">
PHP;

// 文件映射：文件名 => [active_key, title]
$fileMapping = [
    'index.html' => ['index', '首页'],
    'user.html' => ['user', '用户管理'],
    'user_detail.html' => ['user', '用户详情'],
    'user_edit.html' => ['user', '编辑用户资料'],
    'user_groups.html' => ['user', '用户分组管理'],
    'user_login_logs.html' => ['user', '用户登录日志'],
    'user_statistics.html' => ['user', '用户统计'],
    'user_tags.html' => ['user', '用户标签管理'],
    'content_moments.html' => ['moments', '动态管理'],
    'content_reports.html' => ['reports', '举报管理'],
    'announcement/index.html' => ['announcement', '公告管理'],
    'announcement/add.html' => ['announcement', '添加公告'],
    'announcement/edit.html' => ['announcement', '编辑公告'],
    'authorization/index.html' => ['authorization', '权限管理'],
    'authorization/add.html' => ['authorization', '添加权限'],
    'authorization/edit.html' => ['authorization', '编辑权限'],
    'authorization/detail.html' => ['authorization', '权限详情'],
    'comment/index.html' => ['comment', '评论管理'],
    'comment/detail.html' => ['comment', '评论详情'],
    'currency/index.html' => ['currency', '货币管理'],
    'currency/add_type.html' => ['currency', '添加货币类型'],
    'currency/edit_type.html' => ['currency', '编辑货币类型'],
    'currency/log_list.html' => ['currency', '货币日志'],
    'currency/user_currency_list.html' => ['currency', '用户货币'],
    'server/index.html' => ['server', '服务器管理'],
    'server/add.html' => ['server', '添加服务器'],
    'server/edit.html' => ['server', '编辑服务器'],
    'server/detail.html' => ['server', '服务器详情'],
    'setting/index.html' => ['setting', '系统设置'],
    'setting/basic.html' => ['setting', '基本设置'],
    'setting/email.html' => ['setting', '邮件设置'],
    'setting/notification.html' => ['setting', '通知设置'],
    'setting/operation.html' => ['setting', '运营设置'],
    'setting/publish.html' => ['setting', '发布设置'],
    'setting/register.html' => ['setting', '注册设置'],
    'setting/resource.html' => ['setting', '资源设置'],
    'setting/security.html' => ['setting', '安全设置'],
    'setting/seo.html' => ['setting', 'SEO设置'],
    'setting/site.html' => ['setting', '站点设置'],
    'setting/social.html' => ['setting', '社交设置'],
    'setting/tools.html' => ['setting', '工具设置'],
    'setting/upload.html' => ['setting', '上传设置'],
    'setting/website.html' => ['setting', '网站设置'],
    'software/index.html' => ['software', '软件管理'],
    'software/add.html' => ['software', '添加软件'],
    'software/edit.html' => ['software', '编辑软件'],
    'software/detail.html' => ['software', '软件详情'],
    'topic/index.html' => ['topic', '话题管理'],
    'topic/add.html' => ['topic', '添加话题'],
    'topic/edit.html' => ['topic', '编辑话题'],
    'version/index.html' => ['version', '版本管理'],
    'version/add.html' => ['version', '添加版本'],
    'vip/index.html' => ['vip', 'VIP管理'],
    'vip/add_level.html' => ['vip', '添加VIP等级'],
    'vip/edit_level.html' => ['vip', '编辑VIP等级'],
    'vip/order_list.html' => ['vip', 'VIP订单'],
    'vip/user_vip_list.html' => ['vip', '用户VIP'],
];

echo "批量迁移脚本\n";
echo "========================\n\n";

$migratedCount = 0;
$failedFiles = [];

foreach ($fileMapping as $file => $config) {
    list($activeKey, $title) = $config;
    $filePath = $adminDir . '/' . $file;

    if (!file_exists($filePath)) {
        echo "[跳过] 文件不存在: $file\n";
        continue;
    }

    if ($file === 'login.html' || $file === 'layout.html') {
        echo "[跳过] 排除文件: $file\n";
        continue;
    }

    $content = file_get_contents($filePath);

    // 检查是否已经迁移
    if (strpos($content, "components/sidebar.php") !== false) {
        echo "[跳过] 已迁移: $file\n";
        continue;
    }

    // 替换侧边栏
    $pattern = '/<!--\s*侧边栏\s*-->.*?<\/aside>/s';
    $replacement = str_replace('%ACTIVE_KEY%', $activeKey, $sidebarInclude);
    $newContent = preg_replace($pattern, $replacement, $content);

    if ($newContent === $content) {
        echo "[失败] 无法替换侧边栏: $file\n";
        $failedFiles[] = $file;
        continue;
    }

    // 替换头部导航和主内容区
    $headerPattern = '/<!--\s*1\.\s*页头导航\s*-->.*?<main[^>]*>/s';
    $headerReplacement = str_replace('%TITLE%', $title, $headerInclude);
    $newContent = preg_replace($headerPattern, $headerReplacement, $newContent);

    if ($newContent === $content) {
        echo "[失败] 无法替换头部: $file\n";
        $failedFiles[] = $file;
        continue;
    }

    // 删除旧的侧边栏底部
    $newContent = preg_replace('/<!--\s*侧边栏底部\s*-->.*?<\/aside>/s', '', $newContent);

    // 保存文件
    file_put_contents($filePath, $newContent);
    echo "[成功] $file\n";
    $migratedCount++;
}

echo "\n========================\n";
echo "迁移完成！\n";
echo "成功: $migratedCount 个文件\n";

if (!empty($failedFiles)) {
    echo "失败: " . count($failedFiles) . " 个文件\n";
    echo "失败文件列表:\n";
    foreach ($failedFiles as $file) {
        echo "  - $file\n";
    }
}

echo "\n注意：请检查迁移后的文件，确保样式和功能正常。\n";
