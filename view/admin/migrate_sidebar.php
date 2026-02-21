<?php
/**
 * 后台侧边栏迁移助手
 * 
 * 此脚本用于帮助将现有页面迁移到使用新的统一侧边栏系统
 * 
 * 使用方法：
 * 1. 在浏览器访问 /admin/sidebar-migrate
 * 2. 选择要迁移的页面
 * 3. 点击"开始迁移"按钮
 * 4. 查看迁移结果
 */

use think\facade\View;
use think\facade\Request;

// 模拟控制器代码（仅供参考）

class SidebarMigrate
{
    /**
     * 迁移页面列表
     */
    public function index()
    {
        $pages = [
            [
                'name' => '用户管理',
                'file' => 'user.html',
                'active' => 'user',
                'status' => 'pending'
            ],
            [
                'name' => '用户编辑',
                'file' => 'user_edit.html',
                'active' => 'user',
                'status' => 'pending'
            ],
            [
                'name' => '用户详情',
                'file' => 'user_detail.html',
                'active' => 'user',
                'status' => 'pending'
            ],
            [
                'name' => '动态管理',
                'file' => 'content_moments.html',
                'active' => 'moments',
                'status' => 'pending'
            ],
            [
                'name' => '举报管理',
                'file' => 'content_reports.html',
                'active' => 'reports',
                'status' => 'pending'
            ],
            // 添加更多需要迁移的页面
        ];

        View::assign('pages', $pages);
        return View::fetch('admin/sidebar_migrate');
    }

    /**
     * 执行迁移
     */
    public function migrate()
    {
        $file = Request::post('file');
        $active = Request::post('active');

        if (empty($file) || empty($active)) {
            return json(['code' => 0, 'msg' => '参数错误']);
        }

        $viewPath = app_path() . '/view/admin/' . $file;

        if (!file_exists($viewPath)) {
            return json(['code' => 0, 'msg' => '文件不存在']);
        }

        // 读取原文件
        $content = file_get_contents($viewPath);

        // 检查是否已经迁移
        if (strpos($content, 'components/sidebar.php') !== false) {
            return json(['code' => 0, 'msg' => '该页面已经迁移']);
        }

        // 备份原文件
        $backupPath = $viewPath . '.backup.' . time();
        copy($viewPath, $backupPath);

        // 移除旧的侧边栏代码
        $patterns = [
            // 匹配并移除整个 <aside> 标签
            '/<aside[^>]*>.*?<\/aside>/s',
            // 匹配并移除页面的 <header> (如果存在)
            '/<header[^>]*>.*?<\/header>/s',
        ];

        $content = preg_replace($patterns, '', $content);

        // 替换 <body> 标签，添加布局支持
        $content = preg_replace(
            '/<body[^>]*>/',
            '<body class="flex">',
            $content
        );

        // 在主内容区之前添加侧边栏引入
        $sidebarCode = "\n    <!-- 侧边栏 -->\n";
        $sidebarCode .= "    <?php\n";
        $sidebarCode .= "        \$current_active = '{$active}';\n";
        $sidebarCode .= "        include __DIR__ . '/components/sidebar.php';\n";
        $sidebarCode .= "    ?>\n\n";

        // 查找主内容区并添加侧边栏
        $content = preg_replace(
            '/(<main[^>]*>)/',
            $sidebarCode . '$1',
            $content
        );

        // 写入新文件
        file_put_contents($viewPath, $content);

        return json([
            'code' => 1,
            'msg' => '迁移成功',
            'data' => [
                'file' => $file,
                'backup' => basename($backupPath)
            ]
        ]);
    }
}

/**
 * 迁移说明：
 * 
 * 1. 手动迁移步骤：
 *    a. 打开需要迁移的视图文件
 *    b. 移除 <aside> 标签及其内容（旧的侧边栏）
 *    c. 移除顶部的 <header> 标签（如果存在）
 *    d. 在 <main> 标签之前添加：
 *       <?php
 *           $current_active = '对应的active值';
 *           include __DIR__ . '/components/sidebar.php';
 *       ?>
 *    e. 确保 <body> 标签有 class="flex"
 * 
 * 2. 推荐的页面结构：
 *    {extend name="admin/layout" /}
 *    {block name="content"}
 *        <!-- 页面内容 -->
 *    {/block}
 * 
 * 3. 对于简单页面，可以直接使用 layout.html
 */

// ===== 自动迁移脚本示例 =====
// 将此代码保存为 PHP 文件并运行

$pagesToMigrate = [
    'user.html' => 'user',
    'user_edit.html' => 'user',
    'user_detail.html' => 'user',
    'content_moments.html' => 'moments',
    'content_reports.html' => 'reports',
];

$adminViewPath = __DIR__ . '/admin/';

foreach ($pagesToMigrate as $file => $active) {
    $filePath = $adminViewPath . $file;
    
    if (!file_exists($filePath)) {
        echo "文件不存在: $file\n";
        continue;
    }
    
    echo "处理文件: $file\n";
    
    $content = file_get_contents($filePath);
    
    // 检查是否已经迁移
    if (strpos($content, 'components/sidebar.php') !== false) {
        echo "  已跳过（已迁移）\n";
        continue;
    }
    
    // 备份
    $backupPath = $filePath . '.backup.' . time();
    copy($filePath, $backupPath);
    echo "  已备份: " . basename($backupPath) . "\n";
    
    // 移除旧的侧边栏代码（包含多个不同的模式）
    $patterns = [
        '/<!-- 左侧导航栏 -->[\s\S]*?<\/aside>/',
        '/<aside class="sidebar[^>]*>[\s\S]*?<\/aside>/',
        '/<header[^>]*>[\s\S]*?<\/header>/',
    ];
    
    foreach ($patterns as $pattern) {
        $content = preg_replace($pattern, '', $content);
    }
    
    // 替换 body 标签
    $content = preg_replace(
        '/<body([^>]*)>/',
        '<body$1 class="flex">',
        $content
    );
    
    // 查找合适的位置插入侧边栏代码
    if (strpos($content, '<main') !== false) {
        $sidebarCode = "\n    <!-- 侧边栏 -->\n";
        $sidebarCode .= "    <?php\n";
        $sidebarCode .= "        \$current_active = '{$active}';\n";
        $sidebarCode .= "        include __DIR__ . '/components/sidebar.php';\n";
        $sidebarCode .= "    ?>\n\n";
        
        $content = preg_replace(
            '/(<main[^>]*>)/',
            $sidebarCode . '$1',
            $content
        );
    }
    
    // 写入文件
    file_put_contents($filePath, $content);
    echo "  迁移完成\n";
}

echo "\n所有文件处理完成！\n";

// ===== 使用说明 =====
/*
1. 将此脚本放在 view/admin/ 目录下
2. 修改 $pagesToMigrate 数组，添加需要迁移的页面
3. 在命令行运行：php migrate_sidebar.php
4. 检查迁移结果，如有问题可使用备份文件恢复

注意事项：
- 确保已备份原始文件
- 迁移后检查页面显示是否正常
- 如有问题，可手动恢复备份文件
*/
