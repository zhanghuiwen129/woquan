<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Session;
use think\facade\Request;
use think\facade\Db;
use think\facade\Redirect;
use think\facade\Filesystem;
use think\helper\Str;

/**
 * 系统工具控制器
 */
class SystemTools extends AdminController
{
    // 需要管理员认证
    public function initialize()
    {
        parent::initialize();
        if (!Session::has('admin_id')) {
            return Redirect::to('/admin/login');
        }
    }

    // 系统工具首页
    public function index()
    {
        View::assign('admin_name', Session::get('admin_name'));
        return View::fetch('admin/systemtools_index');
    }

    // 数据导入/导出页面
    public function dataImportExport()
    {
        View::assign('admin_name', Session::get('admin_name'));
        return View::fetch('admin/systemtools_data');
    }

    // 数据导出
    public function dataExport()
    {
        if (Request::isPost()) {
            $table = Request::param('table', '');
            $fields = Request::param('fields', '');
            $where = Request::param('where', '');
            $format = Request::param('format', 'csv');
            
            try {
                // 验证必填字段
                if (empty($table)) {
                    return json(['code' => 400, 'msg' => '请选择要导出的数据表']);
                }
                
                // 验证表是否存在
                $tables = Db::query('SHOW TABLES');
                $tableExists = false;
                foreach ($tables as $t) {
                    if (reset($t) === $table) {
                        $tableExists = true;
                        break;
                    }
                }
                if (!$tableExists) {
                    return json(['code' => 400, 'msg' => '数据表不存在']);
                }
                
                // 构建查询条件
                $query = Db::name(str_replace('pt_', '', $table));
                
                // 处理字段选择
                if (!empty($fields)) {
                    $fieldsArray = explode(',', $fields);
                    $query->field($fieldsArray);
                }
                
                // 处理查询条件
                if (!empty($where)) {
                    $whereArray = json_decode($where, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $query->where($whereArray);
                    }
                }
                
                // 获取数据
                $data = $query->select()->toArray();
                
                if (empty($data)) {
                    return json(['code' => 400, 'msg' => '没有数据可导出']);
                }
                
                // 生成文件名
                $filename = $table . '_' . date('YmdHis');
                
                // 导出为CSV
                if ($format === 'csv') {
                    $filename .= '.csv';
                    $this->exportToCsv($data, $filename);
                } else {
                    // 导出为Excel（这里简化处理，实际项目中可以使用PHPExcel等库）
                    $filename .= '.csv';
                    $this->exportToCsv($data, $filename);
                }
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '数据导出',
                    'content' => '导出表: ' . $table . ', 格式: ' . $format,
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '导出失败：' . $e->getMessage()]);
            }
        }
    }

    // 导出为CSV文件
    private function exportToCsv($data, $filename)
    {
        // 生成CSV内容
        $csvContent = '';
        
        // 添加表头
        $headers = array_keys($data[0]);
        $csvContent .= implode(',', $headers) . "\n";
        
        // 添加数据行
        foreach ($data as $row) {
            $values = [];
            foreach ($row as $value) {
                // 处理包含逗号、引号和换行符的数据
                if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, '\n') !== false) {
                    $value = '"' . str_replace('"', '""', $value) . '"';
                }
                $values[] = $value;
            }
            $csvContent .= implode(',', $values) . "\n";
        }
        
        // 设置响应头
        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');
        header('Pragma: public');
        
        // 输出CSV内容
        echo $csvContent;
        exit;
    }

    // 数据导入
    public function dataImport()
    {
        if (Request::isPost()) {
            $table = Request::param('table', '');
            $file = Request::file('file');
            $update = Request::param('update', 0);
            
            try {
                // 验证必填字段
                if (empty($table)) {
                    return json(['code' => 400, 'msg' => '请选择要导入的数据表']);
                }
                
                if (empty($file)) {
                    return json(['code' => 400, 'msg' => '请选择要导入的文件']);
                }
                
                // 验证表是否存在
                $tables = Db::query('SHOW TABLES');
                $tableExists = false;
                foreach ($tables as $t) {
                    if (reset($t) === $table) {
                        $tableExists = true;
                        break;
                    }
                }
                if (!$tableExists) {
                    return json(['code' => 400, 'msg' => '数据表不存在']);
                }
                
                // 验证文件类型
                $fileInfo = $file->getInfo();
                $ext = pathinfo($fileInfo['name'], PATHINFO_EXTENSION);
                if (!in_array($ext, ['csv'])) {
                    return json(['code' => 400, 'msg' => '只支持CSV格式的文件']);
                }
                
                // 保存文件
                $saveName = Filesystem::putFile('import', $file, 'md5');
                $filePath = Filesystem::path('import/' . $saveName);
                
                // 读取文件内容
                $handle = fopen($filePath, 'r');
                if (!$handle) {
                    return json(['code' => 400, 'msg' => '文件读取失败']);
                }
                
                // 读取表头
                $headers = fgetcsv($handle);
                if (empty($headers)) {
                    return json(['code' => 400, 'msg' => '文件格式错误，无法读取表头']);
                }
                
                // 获取表结构
                $tableStruct = Db::query('DESCRIBE ' . $table);
                $tableFields = array_column($tableStruct, 'Field');
                
                // 验证表头是否与表结构匹配
                $validHeaders = [];
                foreach ($headers as $header) {
                    if (in_array($header, $tableFields)) {
                        $validHeaders[] = $header;
                    }
                }
                
                if (empty($validHeaders)) {
                    return json(['code' => 400, 'msg' => '文件表头与表结构不匹配']);
                }
                
                // 读取数据行
                $data = [];
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) == count($headers)) {
                        $rowData = [];
                        foreach ($validHeaders as $index => $header) {
                            $rowIndex = array_search($header, $headers);
                            $rowData[$header] = $row[$rowIndex];
                        }
                        // 添加创建时间和更新时间（如果表中有这些字段）
                        if (in_array('create_time', $tableFields) && !isset($rowData['create_time'])) {
                            $rowData['create_time'] = time();
                        }
                        if (in_array('update_time', $tableFields) && !isset($rowData['update_time'])) {
                            $rowData['update_time'] = time();
                        }
                        $data[] = $rowData;
                    }
                }
                fclose($handle);
                
                if (empty($data)) {
                    return json(['code' => 400, 'msg' => '文件中没有有效数据']);
                }
                
                // 导入数据
                Db::startTrans();
                try {
                    $count = 0;
                    $tableName = str_replace('pt_', '', $table);
                    
                    foreach ($data as $row) {
                        if ($update && isset($row['id'])) {
                            // 更新数据
                            $affected = Db::name($tableName)->where('id', $row['id'])->update($row);
                            if ($affected > 0) {
                                $count++;
                            }
                        } else {
                            // 插入数据
                            Db::name($tableName)->insert($row);
                            $count++;
                        }
                    }
                    
                    Db::commit();
                    
                    // 记录操作日志
                    Db::name('admin_log')->insert([
                        'admin_id' => Session::get('admin_id'),
                        'username' => Session::get('admin_name'),
                        'action' => '数据导入',
                        'content' => '导入表: ' . $table . ', 记录数: ' . $count,
                        'ip' => Request::ip(),
                        'create_time' => time()
                    ]);
                    
                    return json(['code' => 200, 'msg' => '数据导入成功，共导入 ' . $count . ' 条记录']);
                } catch (\Exception $e) {
                    Db::rollback();
                    return json(['code' => 500, 'msg' => '数据导入失败：' . $e->getMessage()]);
                }
                
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 数据库备份/恢复页面
    public function dbBackupRestore()
    {
        // 获取备份列表
        $backupDir = runtime_path() . 'backup/';
        $backups = [];
        if (is_dir($backupDir)) {
            $files = scandir($backupDir);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..' && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                    $filePath = $backupDir . $file;
                    $backups[] = [
                        'name' => $file,
                        'size' => filesize($filePath),
                        'time' => filemtime($filePath),
                        'path' => $filePath
                    ];
                }
            }
            // 按时间倒序排列
            usort($backups, function($a, $b) {
                return $b['time'] - $a['time'];
            });
        }
        
        View::assign([
            'backups' => $backups,
            'admin_name' => Session::get('admin_name')
        ]);
        return View::fetch('admin/systemtools_db');
    }

    // 数据库备份
    public function dbBackup()
    {
        if (Request::isPost()) {
            $tables = Request::param('tables', '');
            $type = Request::param('type', 'full'); // full 全量备份，custom 自定义备份
            
            try {
                // 获取所有表
                $allTables = Db::query('SHOW TABLES');
                $allTables = array_map('reset', $allTables);
                
                // 确定要备份的表
                $backupTables = [];
                if ($type === 'full') {
                    $backupTables = $allTables;
                } else {
                    if (!empty($tables)) {
                        $backupTables = explode(',', $tables);
                        // 验证表是否存在
                        $backupTables = array_intersect($backupTables, $allTables);
                    }
                }
                
                if (empty($backupTables)) {
                    return json(['code' => 400, 'msg' => '没有要备份的表']);
                }
                
                // 创建备份目录
                $backupDir = runtime_path() . 'backup/';
                if (!is_dir($backupDir)) {
                    mkdir($backupDir, 0755, true);
                }
                
                // 生成备份文件名
                $filename = 'backup_' . date('YmdHis') . '_' . Str::random(6) . '.sql';
                $filePath = $backupDir . $filename;
                
                // 打开文件
                $handle = fopen($filePath, 'w');
                if (!$handle) {
                    return json(['code' => 500, 'msg' => '无法创建备份文件']);
                }
                
                // 写入备份头部信息
                $header = "-- 数据库备份\n";
                $header .= "-- 备份时间: " . date('Y-m-d H:i:s') . "\n";
                $header .= "-- 备份表: " . implode(', ', $backupTables) . "\n\n";
                fwrite($handle, $header);
                
                // 备份每个表
                foreach ($backupTables as $table) {
                    // 写入表结构
                    $createTableSql = Db::query('SHOW CREATE TABLE ' . $table);
                    if ($createTableSql) {
                        $sql = "\n-- 表结构: {$table}\n";
                        $sql .= $createTableSql[0]['Create Table'] . ";\n\n";
                        fwrite($handle, $sql);
                    }
                    
                    // 写入表数据
                    $rows = Db::query('SELECT * FROM ' . $table);
                    if (!empty($rows)) {
                        $columns = array_keys($rows[0]);
                        $columnsStr = '`' . implode('`, `', $columns) . '`';
                        
                        $sql = "-- 表数据: {$table}\n";
                        fwrite($handle, $sql);
                        
                        // 分批写入数据
                        $batch = [];
                        foreach ($rows as $row) {
                            $values = [];
                            foreach ($columns as $column) {
                                $value = $row[$column];
                                if ($value === null) {
                                    $values[] = 'NULL';
                                } elseif (is_string($value)) {
                                    $values[] = "'" . addslashes($value) . "'";
                                } else {
                                    $values[] = $value;
                                }
                            }
                            $valuesStr = implode(', ', $values);
                            $batch[] = "({$valuesStr})";
                            
                            // 每100行写入一次
                            if (count($batch) >= 100) {
                                $insertSql = "INSERT INTO {$table} ({$columnsStr}) VALUES " . implode(', ', $batch) . ";\n";
                                fwrite($handle, $insertSql);
                                $batch = [];
                            }
                        }
                        
                        // 写入剩余数据
                        if (!empty($batch)) {
                            $insertSql = "INSERT INTO {$table} ({$columnsStr}) VALUES " . implode(', ', $batch) . ";\n";
                            fwrite($handle, $insertSql);
                        }
                        
                        fwrite($handle, "\n");
                    }
                }
                
                // 关闭文件
                fclose($handle);
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '数据库备份',
                    'content' => '备份类型: ' . $type . ', 备份表数: ' . count($backupTables) . ', 备份文件: ' . $filename,
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => '数据库备份成功', 'data' => ['filename' => $filename]]);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '数据库备份失败：' . $e->getMessage()]);
            }
        }
    }

    // 数据库恢复
    public function dbRestore()
    {
        if (Request::isPost()) {
            $filename = Request::param('filename', '');
            
            try {
                if (empty($filename)) {
                    return json(['code' => 400, 'msg' => '请选择要恢复的备份文件']);
                }
                
                // 检查文件是否存在
                $backupDir = runtime_path() . 'backup/';
                $filePath = $backupDir . $filename;
                if (!file_exists($filePath)) {
                    return json(['code' => 400, 'msg' => '备份文件不存在']);
                }
                
                // 读取备份文件内容
                $sqlContent = file_get_contents($filePath);
                if (empty($sqlContent)) {
                    return json(['code' => 400, 'msg' => '备份文件内容为空']);
                }
                
                // 执行SQL语句
                Db::startTrans();
                try {
                    // 分割SQL语句
                    $sqls = $this->splitSql($sqlContent);
                    $count = 0;
                    
                    foreach ($sqls as $sql) {
                        $sql = trim($sql);
                        if (!empty($sql) && strpos($sql, '--') !== 0) {
                            Db::execute($sql);
                            $count++;
                        }
                    }
                    
                    Db::commit();
                    
                    // 记录操作日志
                    Db::name('admin_log')->insert([
                        'admin_id' => Session::get('admin_id'),
                        'username' => Session::get('admin_name'),
                        'action' => '数据库恢复',
                        'content' => '恢复文件: ' . $filename . ', 执行SQL数: ' . $count,
                        'ip' => Request::ip(),
                        'create_time' => time()
                    ]);
                    
                    return json(['code' => 200, 'msg' => '数据库恢复成功，共执行 ' . $count . ' 条SQL语句']);
                } catch (\Exception $e) {
                    Db::rollback();
                    return json(['code' => 500, 'msg' => '数据库恢复失败：' . $e->getMessage()]);
                }
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 删除备份文件
    public function deleteBackup()
    {
        if (Request::isPost()) {
            $filename = Request::param('filename', '');
            
            try {
                if (empty($filename)) {
                    return json(['code' => 400, 'msg' => '请选择要删除的备份文件']);
                }
                
                // 检查文件是否存在
                $backupDir = runtime_path() . 'backup/';
                $filePath = $backupDir . $filename;
                if (!file_exists($filePath)) {
                    return json(['code' => 400, 'msg' => '备份文件不存在']);
                }
                
                // 删除文件
                unlink($filePath);
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '删除备份文件',
                    'content' => '删除文件: ' . $filename,
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => '备份文件删除成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 下载备份文件
    public function downloadBackup()
    {
        $filename = Request::param('filename', '');
        
        try {
            if (empty($filename)) {
                return json(['code' => 400, 'msg' => '请选择要下载的备份文件']);
            }
            
            // 检查文件是否存在
            $backupDir = runtime_path() . 'backup/';
            $filePath = $backupDir . $filename;
            if (!file_exists($filePath)) {
                return json(['code' => 400, 'msg' => '备份文件不存在']);
            }
            
            // 设置响应头
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename=' . $filename);
            header('Content-Length: ' . filesize($filePath));
            
            // 输出文件内容
            readfile($filePath);
            exit;
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }

    // 分割SQL语句
    private function splitSql($sqlContent)
    {
        // 去除UTF-8 BOM
        $sqlContent = str_replace("\xEF\xBB\xBF", '', $sqlContent);
        
        // 分割SQL语句
        $sqls = [];
        $delimiter = ';';
        $sql = '';
        $lines = explode("\n", $sqlContent);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // 跳过注释行
            if (empty($line) || strpos($line, '--') === 0) {
                continue;
            }
            
            // 处理DELIMITER语句
            if (strpos(strtoupper($line), 'DELIMITER') === 0) {
                $delimiter = trim(substr($line, 9));
                continue;
            }
            
            // 检查是否到达语句结束
            if (strpos($line, $delimiter) !== false) {
                $sql .= substr($line, 0, strpos($line, $delimiter));
                $sqls[] = $sql;
                $sql = '';
            } else {
                $sql .= $line . "\n";
            }
        }
        
        if (!empty(trim($sql))) {
            $sqls[] = trim($sql);
        }
        
        return $sqls;
    }
}
