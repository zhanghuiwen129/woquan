<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\Db;
use think\facade\Request;
use think\facade\View;
use think\facade\Session;

class Activity extends AdminController
{
    public function test()
    {
        return json([
            'code' => 200,
            'msg' => 'Activity控制器正常工作',
            'time' => time()
        ]);
    }
    
    public function index()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status/d', -1);

        try {
            $where = [];
            if ($keyword) {
                $escapedKeyword = Db::escape($keyword);
                $where[] = ['title', 'like', "%{$escapedKeyword}%"];
            }
            if ($status >= 0) {
                $where[] = ['status', '=', $status];
            }

            $activities = Db::name('activities')
                ->where($where)
                ->order('create_time desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);

            if (Request::isAjax()) {
                return json([
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => [
                        'list' => $activities->items(),
                        'total' => $activities->total()
                    ]
                ]);
            }
        } catch (\Exception $e) {
            if (Request::isAjax()) {
                return json([
                    'code' => 500,
                    'msg' => '获取活动列表失败: ' . $e->getMessage()
                ]);
            }
            $activities = [];
        }

        View::assign([
            'activities' => $activities,
            'keyword' => $keyword,
            'status' => $status,
            'admin_name' => Session::get('admin_username', '管理员'),
            'page_title' => '活动管理'
        ]);

        return View::fetch('admin/activity/index');
    }
    
    public function add()
    {
        try {
            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员')
            ]);
            return View::fetch('admin/activity/add');
        } catch (\Exception $e) {
            return '<h1>添加活动</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }
    
    public function save()
    {
        $data = Request::post();
        $data['create_time'] = time();
        $data['update_time'] = time();
        
        try {
            $activityId = Db::name('activities')->insertGetId($data);
            
            if ($activityId) {
                return json(['code' => 200, 'msg' => '添加成功']);
            }
            
            return json(['code' => 500, 'msg' => '添加失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '添加失败：' . $e->getMessage()]);
        }
    }
    
    public function edit()
    {
        try {
            $id = Request::param('id/d');

            if (!$id) {
                return redirect('/admin/activity');
            }

            $activity = Db::name('activities')->where('id', $id)->find();
            if (!$activity) {
                return redirect('/admin/activity');
            }

            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员'),
                'activity' => $activity
            ]);
            return View::fetch('admin/activity/edit');
        } catch (\Exception $e) {
            return '<h1>编辑活动</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }
    
    public function update()
    {
        $id = Request::param('id/d');
        $data = Request::post();
        $data['update_time'] = time();
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = Db::name('activities')->where('id', $id)->update($data);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '更新成功']);
            }
            
            return json(['code' => 500, 'msg' => '更新失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '更新失败：' . $e->getMessage()]);
        }
    }
    
    public function delete()
    {
        $id = Request::param('id/d');
        
        Db::startTrans();
        try {
            Db::name('activity_participants')->where('activity_id', $id)->delete();
            Db::name('activities')->delete($id);
            Db::commit();
            
            return json(['code' => 200, 'msg' => '删除成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '删除失败']);
        }
    }
    
    public function participants()
    {
        try {
            $page = Request::param('page', 1);
            $limit = Request::param('limit', 20);
            $activityId = Request::param('activity_id/d', 0);
            $keyword = Request::param('keyword', '');

            try {
                $where = [];
                if ($activityId > 0) {
                    $where[] = ['ap.activity_id', '=', $activityId];
                }
                if ($keyword) {
                    $where[] = ['u.username|u.nickname', 'like', "%{$keyword}%"];
                }

                $participants = Db::name('activity_participants')
                    ->alias('ap')
                    ->leftJoin('user u', 'ap.user_id = u.id')
                    ->leftJoin('activities a', 'ap.activity_id = a.id')
                    ->field('ap.*, u.username, u.nickname, u.avatar, a.title as activity_title')
                    ->where($where)
                    ->order('ap.join_time desc')
                    ->paginate([
                        'list_rows' => $limit,
                        'page' => $page
                    ]);

                $activities = Db::name('activities')->select();
            } catch (\Exception $e) {
                $participants = [];
                $activities = [];
            }

            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员'),
                'participants' => $participants,
                'activities' => $activities,
                'activityId' => $activityId,
                'keyword' => $keyword,
            ]);

            return View::fetch('admin/activity/participants');
        } catch (\Exception $e) {
            return '<h1>参与者管理</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }
    
    public function deleteParticipant()
    {
        $id = Request::param('id/d');
        
        $result = Db::name('activity_participants')->delete($id);
        
        if ($result) {
            return json(['code' => 200, 'msg' => '删除成功']);
        } else {
            return json(['code' => 500, 'msg' => '删除失败']);
        }
    }
    
    public function statistics()
    {
        try {
            try {
                $totalActivities = Db::name('activities')->count();
                $ongoingActivities = Db::name('activities')->where('status', 1)->count();
                $endedActivities = Db::name('activities')->where('status', 2)->count();
                $totalParticipants = Db::name('activity_participants')->count();

                $activityStats = Db::name('activities')
                    ->field('id, title, participant_count, status, start_time, end_time')
                    ->order('participant_count desc')
                    ->limit(10)
                    ->select();

                $recentActivities = Db::name('activities')
                    ->field('id, title, participant_count, status, create_time')
                    ->order('create_time desc')
                    ->limit(10)
                    ->select();
            } catch (\Exception $e) {
                $totalActivities = 0;
                $ongoingActivities = 0;
                $endedActivities = 0;
                $totalParticipants = 0;
                $activityStats = [];
                $recentActivities = [];
            }

            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员'),
                'totalActivities' => $totalActivities,
                'ongoingActivities' => $ongoingActivities,
                'endedActivities' => $endedActivities,
                'totalParticipants' => $totalParticipants,
                'activityStats' => $activityStats,
                'recentActivities' => $recentActivities,
            ]);

            return View::fetch('admin/activity/statistics');
        } catch (\Exception $e) {
            return '<h1>活动统计</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }
}
