<?php
namespace app\controller\admin;

use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Response;
use think\facade\Session;
use think\exception\ValidateException;
use think\facade\Env;

class Storage
{
    
    public function index()
    {
        try {
            // 计算文件总数
            $totalFiles = Db::name('storage_files')->count();
            
            // 计算总存储空间
            $totalSizeBytes = Db::name('storage_files')->sum('filesize');
            if ($totalSizeBytes < 1024) {
                $totalSize = $totalSizeBytes . ' B';
            } elseif ($totalSizeBytes < 1024 * 1024) {
                $totalSize = round($totalSizeBytes / 1024, 2) . ' KB';
            } else {
                $totalSize = round($totalSizeBytes / (1024 * 1024), 2) . ' MB';
            }
            
            // 获取最近上传的文件列表
            $recentFiles = Db::name('storage_files')
                ->order('create_time desc')
                ->limit(10)
                ->select()
                ->toArray();
            
            // 处理文件大小和上传时间
            foreach ($recentFiles as &$file) {
                $fileSize = $file['filesize'];
                if ($fileSize < 1024) {
                    $file['fileSizeStr'] = $fileSize . ' B';
                } elseif ($fileSize < 1024 * 1024) {
                    $file['fileSizeStr'] = round($fileSize / 1024, 2) . ' KB';
                } else {
                    $file['fileSizeStr'] = round($fileSize / (1024 * 1024), 2) . ' MB';
                }
                $file['uploadTime'] = date('Y-m-d H:i:s', $file['create_time']);
            }
            
            // 传递数据给视图
            View::assign([
                'totalFiles' => $totalFiles,
                'totalSize' => $totalSize,
                'recentFiles' => $recentFiles,
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            
            // 加载视图
            return View::fetch('admin/storage/index');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . '<br>File: ' . $e->getFile() . '<br>Line: ' . $e->getLine();
        }
    }
    
    public function files()
    {
        try {
            $page = Request::param('page', 1);
            $limit = Request::param('limit', 20);
            $keyword = Request::param('keyword', '');
            $fileType = Request::param('file_type', '');
            
            $where = [];
            if ($keyword) {
                $where[] = ['filename', 'like', "%{$keyword}%"];
            }
            if ($fileType) {
                $where[] = ['mimetype', 'like', "%{$fileType}%"];
            }
            
            // 获取文件总数
            $total = Db::name('storage_files')->where($where)->count();
            
            // 计算偏移量
            $offset = ($page - 1) * $limit;
            
            // 获取文件列表
            $fileList = Db::name('storage_files')
                ->where($where)
                ->order('create_time desc')
                ->limit($offset, $limit)
                ->select()
                ->toArray();
            
            // 获取所有动态中的图片路径
            $moments = Db::name('moments')
                ->field('id, images')
                ->where('images', '<>', '')
                ->whereNotNull('images')
                ->select()
                ->toArray();
            
            $usedImages = [];
            $momentImagePaths = [];
            foreach ($moments as $moment) {
                // 假设images字段存储的是JSON格式的图片路径数组
                try {
                    $images = json_decode($moment['images'], true);
                    if (is_array($images)) {
                        foreach ($images as $image) {
                            // 提取图片文件名或路径
                            $usedImages[] = $image;
                            $momentImagePaths[] = [
                                'moment_id' => $moment['id'],
                                'image_path' => $image,
                                'file_name' => basename($image)
                            ];
                        }
                    } else {
                        // 假设是逗号分隔的字符串
                        $imageArray = explode(',', $moment['images']);
                        foreach ($imageArray as $image) {
                            $trimmedImage = trim($image);
                            $usedImages[] = $trimmedImage;
                            $momentImagePaths[] = [
                                'moment_id' => $moment['id'],
                                'image_path' => $trimmedImage,
                                'file_name' => basename($trimmedImage)
                            ];
                        }
                    }
                } catch (Exception $e) {
                    // 忽略JSON解析错误
                }
            }
            
            // 去重
            $usedImages = array_unique($usedImages);
            
            // 获取所有用户信息
            $userIds = array_unique(array_column($fileList, 'user_id'));
            $users = [];
            if (!empty($userIds)) {
                $users = Db::name('user')
                    ->whereIn('id', $userIds)
                    ->column('username', 'id');
            }
            
            // 处理文件大小、上传时间和使用状态
            foreach ($fileList as &$file) {
                $fileSize = $file['filesize'];
                if ($fileSize < 1024) {
                    $file['fileSizeStr'] = $fileSize . ' B';
                } elseif ($fileSize < 1024 * 1024) {
                    $file['fileSizeStr'] = round($fileSize / 1024, 2) . ' KB';
                } else {
                    $file['fileSizeStr'] = round($fileSize / (1024 * 1024), 2) . ' MB';
                }
                $file['uploadTime'] = date('Y-m-d H:i:s', $file['create_time']);

                // 获取上传用户名
                $file['username'] = isset($users[$file['user_id']]) ? $users[$file['user_id']] : '未知用户';
                $file['is_used'] = false;

                // 检查文件是否被使用
                $filePath = $file['filepath'];
                $fileName = $file['filename'];

                // 检查文件路径是否在使用的图片中
                foreach ($usedImages as $usedImage) {
                    // 规范化路径用于比较
                    $usedImageNormalized = ltrim($usedImage, '/');
                    $filePathNormalized = ltrim($filePath, '/');
                    $usedImageBasename = basename($usedImage);
                    $filePathBasename = basename($filePath);
                    $fileNameBasename = basename($fileName);

                    // 尝试多种匹配方式
                    if ($usedImageNormalized === $filePathNormalized ||  // 完整路径匹配
                        $usedImageBasename === $filePathBasename ||      // 文件名匹配
                        $usedImageBasename === $fileNameBasename ||       // 文件名匹配
                        strpos($usedImage, $filePathBasename) !== false || // 路径包含文件名
                        strpos($usedImageNormalized, $fileNameBasename) !== false) { // 路径包含文件名
                        $file['is_used'] = true;
                        break;
                    }
                }
            }
            
            // 构建分页数据
            $files = [
                'items' => $fileList,
                'total' => $total,
                'per_page' => $limit,
                'current_page' => $page,
                'last_page' => ceil($total / $limit)
            ];
            
            // 传递数据给视图
            View::assign([
                'files' => $files,
                'keyword' => $keyword,
                'file_type' => $fileType,
                'admin_name' => Session::get('admin_name', '管理员'),
                'used_images_count' => count($usedImages),
                'storage_files_count' => $total,
                'moment_image_paths' => array_slice($momentImagePaths, 0, 5) // 只传递前5条用于调试
            ]);
            
            // 加载视图
            return View::fetch('admin/storage/files');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . '<br>File: ' . $e->getFile() . '<br>Line: ' . $e->getLine();
        }
    }
    
    public function fileDetail($id)
    {
        try {
            $file = Db::name('storage_files')
                ->where('id', $id)
                ->find();
            
            if (!$file) {
                return '文件不存在';
            }
            
            // 获取上传用户信息
            $user = Db::name('user')->where('id', $file['user_id'])->find();
            $file['username'] = $user ? $user['username'] : '未知用户';
            $file['nickname'] = $user ? ($user['nickname'] ?: $user['username']) : '未知用户';
            
            View::assign([
                'file' => $file,
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            
            return View::fetch('admin/storage/file_detail');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . '<br>File: ' . $e->getFile() . '<br>Line: ' . $e->getLine();
        }
    }
    
    public function deleteFile()
    {
        $ids = Request::param('ids');
        
        if (!$ids) {
            return json(['code' => 0, 'msg' => '请选择要删除的文件']);
        }
        
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        
        Db::startTrans();
        try {
            foreach ($ids as $id) {
                $file = Db::name('storage_files')->where('id', $id)->find();
                if ($file) {
                    // 删除物理文件
                    $filePath = public_path() . $file['filepath'];
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    
                    // 删除数据库记录
                    Db::name('storage_files')->where('id', $id)->delete();
                }
            }
            
            Db::commit();
            return json(['code' => 200, 'msg' => '删除成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 0, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }
    
    public function downloadFile($id)
    {
        try {
            $file = Db::name('storage_files')
                ->where('id', $id)
                ->find();
            
            if (!$file) {
                return '文件不存在';
            }
            
            $filePath = public_path() . $file['filepath'];
            if (!file_exists($filePath)) {
                return '文件不存在';
            }
            
            return Response::file($filePath, $file['filename']);
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . '<br>File: ' . $e->getFile() . '<br>Line: ' . $e->getLine();
        }
    }
    
    public function getFileInfo($id)
    {
        $file = Db::name('storage_files')
            ->where('id', $id)
            ->find();
        
        if (!$file) {
            return json(['code' => 0, 'msg' => '文件不存在']);
        }
        
        // 检查文件是否存在
        $filePath = public_path() . $file['filepath'];
        if (!file_exists($filePath)) {
            return json(['code' => 0, 'msg' => '文件不存在']);
        }
        
        return json([
            'code' => 200, 
            'msg' => '获取成功',
            'data' => $file
        ]);
    }
    
    public function statistics()
    {
        try {
            $totalSize = Db::name('storage_files')->sum('filesize');
            $totalFiles = Db::name('storage_files')->count();
            
            $todayStart = strtotime(date('Y-m-d 00:00:00'));
            $todayEnd = strtotime(date('Y-m-d 23:59:59'));
            $todaySize = Db::name('storage_files')
                ->where('create_time', '>=', $todayStart)
                ->where('create_time', '<=', $todayEnd)
                ->sum('filesize');
            
            $monthStart = strtotime(date('Y-m-01 00:00:00'));
            $monthEnd = strtotime(date('Y-m-t 23:59:59'));
            $monthSize = Db::name('storage_files')
                ->where('create_time', '>=', $monthStart)
                ->where('create_time', '<=', $monthEnd)
                ->sum('filesize');
            
            $imageCount = Db::name('storage_files')
                ->where('mimetype', 'like', 'image%')
                ->count();
            
            $docCount = Db::name('storage_files')
                ->where(function($query) {
                    $query->where('mimetype', 'like', 'text%')
                          ->whereOr('mimetype', 'like', '%pdf%')
                          ->whereOr('mimetype', 'like', '%word%')
                          ->whereOr('mimetype', 'like', '%document%');
                })
                ->count();
            
            $otherCount = Db::name('storage_files')
                ->where(function($query) {
                    $query->where('mimetype', 'not like', 'image%')
                          ->where('mimetype', 'not like', 'text%')
                          ->where('mimetype', 'not like', '%pdf%')
                          ->where('mimetype', 'not like', '%word%')
                          ->where('mimetype', 'not like', '%document%');
                })
                ->count();
            
            function formatFileSize($size) {
                if ($size < 1024) {
                    return $size . ' B';
                } elseif ($size < 1024 * 1024) {
                    return round($size / 1024, 2) . ' KB';
                } else {
                    return round($size / (1024 * 1024), 2) . ' MB';
                }
            }
            
            $statistics = [
                'total_size' => formatFileSize($totalSize),
                'total_files' => $totalFiles,
                'today_size' => formatFileSize($todaySize),
                'month_size' => formatFileSize($monthSize),
                'image_count' => $imageCount,
                'doc_count' => $docCount,
                'other_count' => $otherCount,
            ];
            
            View::assign([
                'statistics' => $statistics,
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            
            return View::fetch('admin/storage/statistics');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . '<br>File: ' . $e->getFile() . '<br>Line: ' . $e->getLine();
        }
    }
    
    public function cdn()
    {
        try {
            $config = Db::name('cdn_config')->find();
            
            View::assign([
                'config' => $config,
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            
            return View::fetch('admin/storage/cdn');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . '<br>File: ' . $e->getFile() . '<br>Line: ' . $e->getLine();
        }
    }
    
    public function config()
    {
        try {
            $storageConfig = Db::name('system_config')
                ->where('config_key', 'like', 'storage_%')
                ->select();
            
            $config = [];
            foreach ($storageConfig as $item) {
                $config[$item['config_key']] = $item['config_value'];
            }
            
            View::assign([
                'config' => $config,
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            
            return View::fetch('admin/storage/config');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . '<br>File: ' . $e->getFile() . '<br>Line: ' . $e->getLine();
        }
    }
    
    public function saveConfig()
    {
        if (Request::isPost()) {
            $data = Request::param();
            unset($data['_method'], $data['__token__']);
            
            Db::startTrans();
            try {
                foreach ($data as $key => $value) {
                    if (strpos($key, 'storage_') === 0) {
                        $config = Db::name('system_config')
                            ->where('config_key', $key)
                            ->find();
                        
                        if ($config) {
                            Db::name('system_config')
                                ->where('config_key', $key)
                                ->update([
                                    'config_value' => $value,
                                    'update_time' => time()
                                ]);
                        } else {
                            Db::name('system_config')
                                ->insert([
                                    'config_key' => $key,
                                    'config_value' => $value,
                                    'config_name' => $key,
                                    'create_time' => time(),
                                    'update_time' => time()
                                ]);
                        }
                    }
                }
                
                Db::commit();
                return json(['code' => 200, 'msg' => '保存成功']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => 0, 'msg' => '保存失败：' . $e->getMessage()]);
            }
        }
    }
    
    public function clean()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            
            return View::fetch('admin/storage/clean');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . '<br>File: ' . $e->getFile() . '<br>Line: ' . $e->getLine();
        }
    }
    
    public function doClean()
    {
        $type = Request::param('type');
        
        try {
            $filesCount = 0;
            $freedSpace = 0;
            
            switch ($type) {
                case 'cache':
                    // 清理缓存
                    $cacheDir = runtime_path() . 'cache';
                    $result = $this->deleteDirWithStats($cacheDir);
                    $filesCount = $result['filesCount'];
                    $freedSpace = $result['freedSpace'];
                    break;
                case 'temp':
                    // 清理临时文件
                    $tempDir = runtime_path() . 'temp';
                    $result = $this->deleteDirWithStats($tempDir);
                    $filesCount = $result['filesCount'];
                    $freedSpace = $result['freedSpace'];
                    break;
                case 'logs':
                    // 清理日志
                    $logsDir = runtime_path() . 'logs';
                    $result = $this->deleteDirWithStats($logsDir);
                    $filesCount = $result['filesCount'];
                    $freedSpace = $result['freedSpace'];
                    break;
                case 'all':
                    // 清理所有
                    $cacheDir = runtime_path() . 'cache';
                    $tempDir = runtime_path() . 'temp';
                    $logsDir = runtime_path() . 'logs';
                    
                    $cacheResult = $this->deleteDirWithStats($cacheDir);
                    $tempResult = $this->deleteDirWithStats($tempDir);
                    $logsResult = $this->deleteDirWithStats($logsDir);
                    
                    $filesCount = $cacheResult['filesCount'] + $tempResult['filesCount'] + $logsResult['filesCount'];
                    $freedSpace = $cacheResult['freedSpace'] + $tempResult['freedSpace'] + $logsResult['freedSpace'];
                    break;
                default:
                    return json(['code' => 0, 'msg' => '清理类型错误']);
            }
            
            return json([
                'code' => 200, 
                'msg' => '清理成功',
                'filesCount' => $filesCount,
                'freedSpace' => $this->formatFileSize($freedSpace)
            ]);
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => '清理失败：' . $e->getMessage()]);
        }
    }
    
    private function deleteDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                $filePath = $dir . '/' . $file;
                if (is_dir($filePath)) {
                    $this->deleteDir($filePath);
                } else {
                    unlink($filePath);
                }
            }
        }
        
        rmdir($dir);
    }
    
    private function deleteDirWithStats($dir)
    {
        $stats = [
            'filesCount' => 0,
            'freedSpace' => 0
        ];
        
        if (!is_dir($dir)) {
            return $stats;
        }
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                $filePath = $dir . '/' . $file;
                if (is_dir($filePath)) {
                    $subStats = $this->deleteDirWithStats($filePath);
                    $stats['filesCount'] += $subStats['filesCount'];
                    $stats['freedSpace'] += $subStats['freedSpace'];
                } else {
                    $stats['filesCount']++;
                    $stats['freedSpace'] += filesize($filePath);
                    unlink($filePath);
                }
            }
        }
        
        rmdir($dir);
        return $stats;
    }
    
    private function formatFileSize($bytes)
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } elseif ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        } else {
            return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        }
    }
    
    public function upload()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            
            return View::fetch('admin/storage/upload');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . '<br>File: ' . $e->getFile() . '<br>Line: ' . $e->getLine();
        }
    }
    
    public function doUpload()
    {
        $file = Request::file('file');
        
        if (!$file) {
            return json(['code' => 0, 'msg' => '请选择要上传的文件']);
        }
        
        try {
            $info = $file->validate([
                'size' => 1024 * 1024 * 50, // 50MB
                'ext' => 'jpg,png,gif,jpeg,doc,docx,xls,xlsx,pdf,zip,rar'
            ])->move(public_path() . 'uploads');
            
            if ($info) {
                $filePath = 'uploads/' . str_replace('\\', '/', $info->getSaveName());
                $fileSize = $info->getSize();
                $mimeType = $info->getMime();
                
                $fileId = Db::name('storage_files')->insertGetId([
                    'user_id' => Session::get('admin_id', 0),
                    'filename' => $info->getOriginalName(),
                    'filepath' => $filePath,
                    'filesize' => $fileSize,
                    'mimetype' => $mimeType,
                    'storage_type' => 'local',
                    'md5' => md5_file(public_path() . $filePath),
                    'status' => 1,
                    'create_time' => time(),
                ]);
                
                return json(['code' => 200, 'msg' => '上传成功', 'data' => ['file_id' => $fileId]]);
            } else {
                return json(['code' => 0, 'msg' => $file->getError()]);
            }
        } catch (ValidateException $e) {
            return json(['code' => 0, 'msg' => $e->getMessage()]);
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => '上传失败：' . $e->getMessage()]);
        }
    }
}

