<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;
use app\model\Announcement as AnnouncementModel;

/**
 * 系统公告管理控制器
 */
class Announcement extends AdminController
{

    
    // 公告列表页面
    public function index()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status/d', -1);
        $isPublish = Request::param('is_publish/d', -1);
        $isPopup = Request::param('is_popup/d', -1);
        
        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['title', 'like', "%{$escapedKeyword}%"];
        }
        if ($status >= 0) {
            $where[] = ['status', '=', $status];
        }
        if ($isPublish >= 0) {
            $where[] = ['is_publish', '=', $isPublish];
        }
        if ($isPopup >= 0) {
            $where[] = ['is_popup', '=', $isPopup];
        }
        
        $announcements = AnnouncementModel::getAnnouncements($where, ['id' => 'desc'], $limit, $page);
        
        View::assign([
            'announcements' => $announcements,
            'keyword' => $keyword,
            'status' => $status,
            'isPublish' => $isPublish,
            'isPopup' => $isPopup,
            'admin_name' => Session::get('admin_name', '管理员'),
        ]);
        
        return View::fetch('admin/announcement/index');
    }
    
    // 添加公告页面
    public function add()
    {
        View::assign('admin_name', Session::get('admin_name', '管理员'));
        return View::fetch('admin/announcement/add');
    }
    
    // 保存公告
    public function save()
    {
        $data = Request::only([
            'title', 'content', 'status', 'is_publish', 'publish_time', 'expire_time', 'is_popup'
        ]);
        
        if (empty($data['title'])) {
            return json(['code' => 400, 'msg' => '请输入公告标题']);
        }
        
        if (empty($data['content'])) {
            return json(['code' => 400, 'msg' => '请输入公告内容']);
        }
        
        // 处理时间格式
        if (!empty($data['publish_time'])) {
            $data['publish_time'] = strtotime($data['publish_time']);
        } else {
            $data['publish_time'] = 0;
        }
        
        if (!empty($data['expire_time'])) {
            $data['expire_time'] = strtotime($data['expire_time']);
        } else {
            $data['expire_time'] = 0;
        }
        
        // 设置默认值
        $data['status'] = isset($data['status']) ? intval($data['status']) : 1;
        $data['is_publish'] = isset($data['is_publish']) ? intval($data['is_publish']) : 0;
        $data['is_popup'] = isset($data['is_popup']) ? intval($data['is_popup']) : 1;
        $data['admin_id'] = Session::get('admin_id');
        
        try {
            $result = AnnouncementModel::createAnnouncement($data);
            
            if ($result) {
                return json(['code' => 200, 'msg' => '添加成功']);
            }
            
            return json(['code' => 500, 'msg' => '添加失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '添加失败：' . $e->getMessage()]);
        }
    }
    
    // 编辑公告页面
    public function edit()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return redirect('/admin/announcement');
        }
        
        $announcement = AnnouncementModel::getAnnouncement($id);
        if (!$announcement) {
            return redirect('/admin/announcement');
        }
        
        $announcementArray = is_array($announcement) ? $announcement : $announcement->toArray();
        
        View::assign([
            'announcement' => $announcementArray,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/announcement/edit');
    }
    
    // 更新公告
    public function update()
    {
        $id = Request::param('id/d');
        $data = Request::only([
            'title', 'content', 'status', 'is_publish', 'publish_time', 'expire_time', 'is_popup'
        ]);
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        if (empty($data['title'])) {
            return json(['code' => 400, 'msg' => '请输入公告标题']);
        }
        
        if (empty($data['content'])) {
            return json(['code' => 400, 'msg' => '请输入公告内容']);
        }
        
        // 处理时间格式
        if (!empty($data['publish_time'])) {
            $data['publish_time'] = strtotime($data['publish_time']);
        } else {
            $data['publish_time'] = 0;
        }
        
        if (!empty($data['expire_time'])) {
            $data['expire_time'] = strtotime($data['expire_time']);
        } else {
            $data['expire_time'] = 0;
        }
        
        // 设置值
        $data['status'] = isset($data['status']) ? intval($data['status']) : 0;
        $data['is_publish'] = isset($data['is_publish']) ? intval($data['is_publish']) : 0;
        $data['is_popup'] = isset($data['is_popup']) ? intval($data['is_popup']) : 0;
        
        try {
            $result = AnnouncementModel::updateAnnouncement($id, $data);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '更新成功']);
            }
            
            return json(['code' => 500, 'msg' => '更新失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '更新失败：' . $e->getMessage()]);
        }
    }
    
    // 删除公告
    public function delete()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = AnnouncementModel::deleteAnnouncement($id);
            
            if ($result) {
                return json(['code' => 200, 'msg' => '删除成功']);
            }
            
            return json(['code' => 500, 'msg' => '删除失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }
    
    // 批量删除公告
    public function batchDelete()
    {
        $ids = Request::post('ids');
        
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = AnnouncementModel::batchDeleteAnnouncements($ids);
            
            if ($result) {
                return json(['code' => 200, 'msg' => '删除成功']);
            }
            
            return json(['code' => 500, 'msg' => '删除失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }
    
    // 切换公告状态
    public function toggleStatus()
    {
        $id = Request::param('id/d');
        $status = Request::param('status/d');
        
        if (!$id || !in_array($status, [0, 1])) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = AnnouncementModel::toggleStatus($id, $status);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '操作成功']);
            }
            
            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
    
    // 切换公告发布状态
    public function togglePublish()
    {
        $id = Request::param('id/d');
        $isPublish = Request::param('is_publish/d');
        
        if (!$id || !in_array($isPublish, [0, 1])) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = AnnouncementModel::togglePublish($id, $isPublish);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '操作成功']);
            }
            
            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
    
    // 切换公告弹窗状态
    public function togglePopup()
    {
        $id = Request::param('id/d');
        $isPopup = Request::param('is_popup/d');
        
        if (!$id || !in_array($isPopup, [0, 1])) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = AnnouncementModel::togglePopup($id, $isPopup);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '操作成功']);
            }
            
            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
}
