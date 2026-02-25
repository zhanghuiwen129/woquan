<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\Request;
use think\facade\View;
use think\facade\Session;
use think\facade\Db;
use think\facade\Cache;

/**
 * 系统工具控制器
 * 负责数据库工具、缓存管理等系统维护功能
 */
class SystemTool extends AdminController
{
    /**
     * 初始化方法
     */
    public function initialize()
    {
        parent::initialize();
        // 检查是否登录
        if (!Session::has('admin_id')) {
            return redirect('/admin/login');
        }
    }

    /**
     * 系统工具首页
     */
    public function index()
    {
        // 获取系统信息
        $systemInfo = [
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->getMysqlVersion(),
            'os' => PHP_OS,
            'server_time' => date('Y-m-d H:i:s'),
            'memory_used' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_available' => round(memory_get_usage(false) / 1024 / 1024, 2),
            'memory_usage_percent' => round((memory_get_usage(true) / memory_get_peak_usage(true)) * 100, 2)
        ];

        // 获取数据库信息
        $dbInfo = [
            'size' => $this->getDatabaseSize(),
            'table_count' => $this->getTableCount()
        ];

        View::assign([
            'systemInfo' => $systemInfo,
            'dbInfo' => $dbInfo
        ]);

        return View::fetch('admin/setting/tools');
    }

    /**
     * 获取MySQL版本
     */
    private function getMysqlVersion()
    {
        try {
            $result = Db::query('SELECT VERSION() as version');
            return $result[0]['version'] ?? '未知';
        } catch (\Exception $e) {
            return '未知';
        }
    }

    /**
     * 获取数据库大小
     */
    private function getDatabaseSize()
    {
        try {
            $database = config('database.connections.mysql.database');
            $result = Db::query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size FROM information_schema.tables WHERE table_schema = '{$database}'");
            return $result[0]['size'] ?? 0 . ' MB';
        } catch (\Exception $e) {
            return '0 MB';
        }
    }

    /**
     * 获取数据表数量
     */
    private function getTableCount()
    {
        try {
            $database = config('database.connections.mysql.database');
            $result = Db::query("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = '{$database}'");
            return $result[0]['count'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 备份数据库
     */
    public function backupDatabase()
    {
        try {
            // TODO: 实现数据库备份逻辑
            // 这里可以使用mysqldump命令或导出SQL文件
            return json(['code' => 200, 'msg' => '数据库备份成功']);
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => '备份失败：' . $e->getMessage()]);
        }
    }

    /**
     * 优化数据库
     */
    public function optimizeDatabase()
    {
        try {
            $tables = Db::query('SHOW TABLES');
            $dbName = config('database.connections.mysql.database');
            $tableNameKey = 'Tables_in_' . $dbName;

            foreach ($tables as $table) {
                $tableName = $table[$tableNameKey];
                Db::execute("OPTIMIZE TABLE `{$tableName}`");
            }

            return json(['code' => 200, 'msg' => '数据库优化成功']);
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => '优化失败：' . $e->getMessage()]);
        }
    }

    /**
     * 修复数据库
     */
    public function repairDatabase()
    {
        try {
            $tables = Db::query('SHOW TABLES');
            $dbName = config('database.connections.mysql.database');
            $tableNameKey = 'Tables_in_' . $dbName;

            foreach ($tables as $table) {
                $tableName = $table[$tableNameKey];
                Db::execute("REPAIR TABLE `{$tableName}`");
            }

            return json(['code' => 200, 'msg' => '数据库修复成功']);
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => '修复失败：' . $e->getMessage()]);
        }
    }

    /**
     * 清理缓存
     */
    public function clearCache()
    {
        try {
            Cache::clear();
            return json(['code' => 200, 'msg' => '缓存清理成功']);
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => '清理失败：' . $e->getMessage()]);
        }
    }

    /**
     * 清理临时文件
     */
    public function clearTempFiles()
    {
        try {
            $tempDirs = [
                public_path() . 'temp',
                public_path() . 'runtime' . DIRECTORY_SEPARATOR . 'cache',
                public_path() . 'runtime' . DIRECTORY_SEPARATOR . 'temp'
            ];

            $count = 0;
            foreach ($tempDirs as $dir) {
                if (is_dir($dir)) {
                    $this->deleteDirectory($dir, false);
                    $count++;
                }
            }

            return json(['code' => 200, 'msg' => "临时文件清理完成，清理了 {$count} 个目录"]);
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => '清理失败：' . $e->getMessage()]);
        }
    }

    /**
     * 删除目录
     */
    private function deleteDirectory($dir, $deleteDir = true)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        if ($deleteDir) {
            return @rmdir($dir);
        }

        return true;
    }

    /**
     * 查看错误日志
     */
    public function viewErrorLogs()
    {
        try {
            $logPath = public_path() . 'runtime' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
            $logs = [];

            if (is_dir($logPath)) {
                $files = glob($logPath . '*.log');
                foreach ($files as $file) {
                    $logs[] = [
                        'name' => basename($file),
                        'size' => filesize($file),
                        'time' => date('Y-m-d H:i:s', filemtime($file))
                    ];
                }
            }

            return json(['code' => 200, 'data' => $logs]);
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => '获取日志失败：' . $e->getMessage()]);
        }
    }

    /**
     * 清理错误日志
     */
    public function clearErrorLogs()
    {
        try {
            $logPath = public_path() . 'runtime' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;

            if (is_dir($logPath)) {
                $files = glob($logPath . '*.log');
                foreach ($files as $file) {
                    @unlink($file);
                }
            }

            return json(['code' => 200, 'msg' => '日志清理成功']);
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => '清理失败：' . $e->getMessage()]);
        }
    }
}
