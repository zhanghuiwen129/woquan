<?php
declare (strict_types = 1);

namespace app\controller\admin;

use think\Request;
use think\facade\Db;
use think\facade\Session;

class Upload extends BaseController
{
    public function image()
    {
        $adminId = Session::get('admin_id');
        
        if (!$adminId) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $file = Request::file('file');
        
        if (!$file) {
            return json(['code' => 400, 'msg' => '请选择要上传的图片']);
        }

        try {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = $file->getMime();
            
            if (!in_array($fileType, $allowedTypes)) {
                return json(['code' => 400, 'msg' => '只允许上传JPG、PNG、GIF和WEBP格式的图片']);
            }
            
            $maxSize = 5 * 1024 * 1024;
            if ($file->getSize() > $maxSize) {
                return json(['code' => 400, 'msg' => '图片大小不能超过5MB']);
            }
            
            $imageInfo = getimagesize($file->getPathname());
            if (!$imageInfo) {
                return json(['code' => 400, 'msg' => '无效的图片文件']);
            }

            $uploadDir = app()->getRootPath() . 'public/uploads/topics/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $ext = strtolower(pathinfo($file->getOriginalName(), PATHINFO_EXTENSION));
            $filename = 'topic_' . time() . '_' . uniqid() . '.' . $ext;
            $file->move($uploadDir, $filename);
            
            $imageUrl = '/uploads/topics/' . $filename;

            return json([
                'code' => 200,
                'msg' => '上传成功',
                'data' => [
                    'url' => $imageUrl,
                    'path' => $uploadDir . $filename
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '上传失败：' . $e->getMessage()]);
        }
    }
}
