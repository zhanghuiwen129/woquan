<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Session;
use think\facade\Request;
use think\facade\Db;
use think\facade\Redirect;

/**
 * 运营管理控制器
 */
class Operation extends AdminController
{
    // 需要管理员认证
    public function initialize()
    {
        parent::initialize();
        if (!Session::has('admin_id')) {
            return Redirect::to('/admin/login');
        }
    }

    // 运营管理首页
    public function index()
    {
        View::assign('admin_name', Session::get('admin_name'));
        return View::fetch('admin/operation_index');
    }

    // 首页轮播图配置
    public function banners()
    {
        // 轮播图配置存储在系统配置表中
        $banners = Db::name('system_config')->where('config_key', 'home_banners')->value('config_value');
        $bannersList = $banners ? json_decode($banners, true) : [];
        
        View::assign('banners_list', $bannersList);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/operation_banners');
    }

    // 保存轮播图配置
    public function saveBanners()
    {
        if (Request::isPost()) {
            $banners = Request::param('banners', '[]');
            
            try {
                // 验证JSON格式
                $bannersArray = json_decode($banners, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '轮播图数据格式错误']);
                }
                
                // 保存到系统配置
                $config = Db::name('system_config')->where('config_key', 'home_banners')->find();
                if ($config) {
                    Db::name('system_config')->where('config_key', 'home_banners')->update([
                        'config_value' => $banners,
                        'update_time' => time()
                    ]);
                } else {
                    Db::name('system_config')->insert([
                        'config_key' => 'home_banners',
                        'config_value' => $banners,
                        'config_name' => '首页轮播图',
                        'config_type' => 'textarea',
                        'config_group' => 'operation',
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '轮播图配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 活动列表
    public function activities()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status', '');

        $where = [];
        if ($keyword) {
            $where[] = ['title', 'like', "%{$keyword}%"];
        }
        if ($status !== '' && $status !== null) {
            $where[] = ['status', '=', $status];
        }

        $activities = Db::name('operations')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'activities' => $activities,
            'keyword' => $keyword,
            'status' => $status,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/operation_activities');
    }

    // 活动表单
    public function activityForm()
    {
        $id = Request::param('id', 0);
        $activity = [];

        if ($id) {
            $activity = Db::name('operations')->find($id);
        }

        View::assign('activity', $activity);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/operation_activity_form');
    }

    // 保存活动
    public function saveActivity()
    {
        if (Request::isPost()) {
            $data = Request::param();
            $id = $data['id'] ?? 0;
            
            try {
                if ($id > 0) {
                    // 更新活动
                    Db::name('operations')->where('id', $id)->update([
                        'title' => $data['title'],
                        'description' => $data['description'],
                        'cover' => $data['cover'],
                        'start_time' => strtotime($data['start_time']),
                        'end_time' => strtotime($data['end_time']),
                        'status' => $data['status'] ?? 1,
                        'update_time' => time()
                    ]);
                    
                    return json(['code' => 200, 'msg' => '活动更新成功']);
                } else {
                    // 创建新活动
                    Db::name('operations')->insert([
                        'title' => $data['title'],
                        'description' => $data['description'],
                        'cover' => $data['cover'],
                        'start_time' => strtotime($data['start_time']),
                        'end_time' => strtotime($data['end_time']),
                        'status' => $data['status'] ?? 1,
                        'participant_count' => 0,
                        'view_count' => 0,
                        'creator_id' => Session::get('admin_id'),
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                    
                    return json(['code' => 200, 'msg' => '活动创建成功']);
                }
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 删除活动
    public function deleteActivity()
    {
        $id = Request::param('id/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            Db::startTrans();
            
            // 删除活动
            Db::name('operations')->where('id', $id)->delete();
            
            // 删除活动参与者记录
            Db::name('operation_participants')->where('operation_id', $id)->delete();
            
            // 删除活动奖励记录
            Db::name('operation_rewards')->where('operation_id', $id)->delete();
            
            Db::commit();
            return json(['code' => 200, 'msg' => '活动删除成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }

    // 积分任务配置
    public function tasks()
    {
        $tasks = Db::name('system_config')->where('config_key', 'point_tasks')->value('config_value');
        $tasksList = $tasks ? json_decode($tasks, true) : [];
        
        View::assign('tasks_list', $tasksList);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/operation_tasks');
    }

    // 保存积分任务配置
    public function saveTasks()
    {
        if (Request::isPost()) {
            $tasks = Request::param('tasks', '[]');
            
            try {
                // 验证JSON格式
                $tasksArray = json_decode($tasks, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '任务数据格式错误']);
                }
                
                // 保存到系统配置
                $config = Db::name('system_config')->where('config_key', 'point_tasks')->find();
                if ($config) {
                    Db::name('system_config')->where('config_key', 'point_tasks')->update([
                        'config_value' => $tasks,
                        'update_time' => time()
                    ]);
                } else {
                    Db::name('system_config')->insert([
                        'config_key' => 'point_tasks',
                        'config_value' => $tasks,
                        'config_name' => '积分任务配置',
                        'config_type' => 'textarea',
                        'config_group' => 'operation',
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '积分任务配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 素材管理
    public function materials()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $type = Request::param('type', '');

        $where = [];
        if ($type) {
            $where[] = ['file_type', 'like', "%{$type}%"];
        }

        $materials = Db::name('uploads')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'materials' => $materials,
            'type' => $type,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/operation_materials');
    }

    // 删除素材
    public function deleteMaterial()
    {
        $id = Request::param('id/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $material = Db::name('uploads')->find($id);
            if ($material) {
                // 删除数据库记录
                Db::name('uploads')->where('id', $id)->delete();
                
                // 可以在这里添加文件系统删除逻辑
            }
            
            return json(['code' => 200, 'msg' => '素材删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }
}
