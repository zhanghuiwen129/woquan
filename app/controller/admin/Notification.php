<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Session;
use think\facade\Request;
use think\facade\Db;
use think\facade\Redirect;

/**
 * 消息通知管理控制器
 */
class Notification extends AdminController
{
    // 需要管理员认证
    public function initialize()
    {
        parent::initialize();
        if (!Session::has('admin_id')) {
            return Redirect::to('/admin/login');
        }
    }

    // 消息通知管理首页
    public function index()
    {
        View::assign('admin_name', Session::get('admin_name'));
        return View::fetch('admin/notification_index');
    }

    // 通知渠道配置
    public function channels()
    {
        // 通知渠道配置存储在系统配置表中
        $channels = Db::name('system_config')->where('config_key', 'notification_channels')->value('config_value');
        $channelsList = $channels ? json_decode($channels, true) : [];
        
        View::assign('channels_list', $channelsList);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/notification_channels');
    }

    // 保存通知渠道配置
    public function saveChannels()
    {
        if (Request::isPost()) {
            $channels = Request::param('channels', '[]');
            
            try {
                // 验证JSON格式
                $channelsArray = json_decode($channels, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '通知渠道数据格式错误']);
                }
                
                // 保存到系统配置
                $config = Db::name('system_config')->where('config_key', 'notification_channels')->find();
                if ($config) {
                    Db::name('system_config')->where('config_key', 'notification_channels')->update([
                        'config_value' => $channels,
                        'update_time' => time()
                    ]);
                } else {
                    Db::name('system_config')->insert([
                        'config_key' => 'notification_channels',
                        'config_value' => $channels,
                        'config_name' => '通知渠道配置',
                        'config_type' => 'textarea',
                        'config_group' => 'notification',
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '通知渠道配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 通知模板列表
    public function templates()
    {
        // 通知模板配置存储在系统配置表中
        $templates = Db::name('system_config')->where('config_key', 'notification_templates')->value('config_value');
        $templatesList = $templates ? json_decode($templates, true) : [];
        
        View::assign('templates_list', $templatesList);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/notification_templates');
    }

    // 保存通知模板
    public function saveTemplates()
    {
        if (Request::isPost()) {
            $templates = Request::param('templates', '[]');
            
            try {
                // 验证JSON格式
                $templatesArray = json_decode($templates, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '通知模板数据格式错误']);
                }
                
                // 保存到系统配置
                $config = Db::name('system_config')->where('config_key', 'notification_templates')->find();
                if ($config) {
                    Db::name('system_config')->where('config_key', 'notification_templates')->update([
                        'config_value' => $templates,
                        'update_time' => time()
                    ]);
                } else {
                    Db::name('system_config')->insert([
                        'config_key' => 'notification_templates',
                        'config_value' => $templates,
                        'config_name' => '通知模板配置',
                        'config_type' => 'textarea',
                        'config_group' => 'notification',
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '通知模板保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 系统公告列表
    public function announcements()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status', '');
        $is_publish = Request::param('is_publish', '');

        $where = [];
        if ($keyword) {
            $where[] = ['title', 'like', "%{$keyword}%"];
        }
        if ($status !== '' && $status !== null) {
            $where[] = ['status', '=', $status];
        }
        if ($is_publish !== '' && $is_publish !== null) {
            $where[] = ['is_publish', '=', $is_publish];
        }

        $announcements = Db::name('announcements')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'announcements' => $announcements,
            'keyword' => $keyword,
            'status' => $status,
            'is_publish' => $is_publish,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/notification_announcements');
    }

    // 公告表单
    public function announcementForm()
    {
        $id = Request::param('id', 0);
        $announcement = [];

        if ($id) {
            $announcement = Db::name('announcements')->find($id);
        }

        View::assign('announcement', $announcement);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/notification_announcement_form');
    }

    // 保存公告
    public function saveAnnouncement()
    {
        if (Request::isPost()) {
            $data = Request::param();
            $id = $data['id'] ?? 0;
            
            try {
                if ($id > 0) {
                    // 更新公告
                    $updateData = [
                        'title' => $data['title'],
                        'content' => $data['content'],
                        'status' => $data['status'] ?? 1,
                        'is_publish' => $data['is_publish'] ?? 0,
                        'expire_time' => strtotime($data['expire_time']),
                        'is_popup' => $data['is_popup'] ?? 1,
                        'update_time' => time()
                    ];
                    
                    // 如果设置为发布且发布时间为空，则设置当前时间
                    if ($updateData['is_publish'] == 1 && empty($data['publish_time'])) {
                        $updateData['publish_time'] = time();
                    }
                    
                    Db::name('announcements')->where('id', $id)->update($updateData);
                    
                    return json(['code' => 200, 'msg' => '公告更新成功']);
                } else {
                    // 创建新公告
                    $insertData = [
                        'title' => $data['title'],
                        'content' => $data['content'],
                        'status' => $data['status'] ?? 1,
                        'is_publish' => $data['is_publish'] ?? 0,
                        'expire_time' => strtotime($data['expire_time']),
                        'is_popup' => $data['is_popup'] ?? 1,
                        'click_count' => 0,
                        'admin_id' => Session::get('admin_id'),
                        'create_time' => time(),
                        'update_time' => time()
                    ];
                    
                    // 如果设置为发布，则设置发布时间
                    if ($insertData['is_publish'] == 1) {
                        $insertData['publish_time'] = time();
                    }
                    
                    Db::name('announcements')->insert($insertData);
                    
                    return json(['code' => 200, 'msg' => '公告创建成功']);
                }
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 删除公告
    public function deleteAnnouncement()
    {
        $id = Request::param('id/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            Db::name('announcements')->where('id', $id)->delete();
            
            return json(['code' => 200, 'msg' => '公告删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }

    // 通知发送记录
    public function sendRecords()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $type = Request::param('type', '');
        $is_read = Request::param('is_read', '');

        $where = [];
        if ($keyword) {
            $where[] = ['title', 'like', "%{$keyword}%"];
        }
        if ($type !== '' && $type !== null) {
            $where[] = ['type', '=', $type];
        }
        if ($is_read !== '' && $is_read !== null) {
            $where[] = ['is_read', '=', $is_read];
        }

        $notifications = Db::name('notifications')
            ->alias('n')
            ->leftJoin('user u', 'n.user_id = u.id')
            ->field('n.*, u.username, u.nickname')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'notifications' => $notifications,
            'keyword' => $keyword,
            'type' => $type,
            'is_read' => $is_read,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/notification_records');
    }

    // 批量操作通知记录
    public function batchOperateRecords()
    {
        if (Request::isPost()) {
            $ids = Request::param('ids', '');
            $action = Request::param('action', '');
            
            if (empty($ids) || empty($action)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            $idList = explode(',', $ids);
            
            try {
                switch ($action) {
                    case 'mark_read':
                        Db::name('notifications')->where('id', 'in', $idList)->update([
                            'is_read' => 1,
                            'read_time' => time()
                        ]);
                        break;
                    case 'mark_unread':
                        Db::name('notifications')->where('id', 'in', $idList)->update([
                            'is_read' => 0,
                            'read_time' => null
                        ]);
                        break;
                    case 'delete':
                        Db::name('notifications')->where('id', 'in', $idList)->delete();
                        break;
                    default:
                        return json(['code' => 400, 'msg' => '无效的操作类型']);
                }
                
                return json(['code' => 200, 'msg' => '操作成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 推送记录
    public function records()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $type = Request::param('type', '');
        $status = Request::param('status', '');

        $where = [];
        if ($keyword) {
            $where[] = ['title', 'like', "%{$keyword}%"];
        }
        if ($type !== '' && $type !== null) {
            $where[] = ['type', '=', $type];
        }
        if ($status !== '' && $status !== null) {
            $where[] = ['status', '=', $status];
        }

        $records = Db::name('push_records')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'records' => $records,
            'keyword' => $keyword,
            'type' => $type,
            'status' => $status,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/notification_records');
    }
}
