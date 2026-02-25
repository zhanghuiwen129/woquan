<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use app\model\Task as TaskModel;
use think\facade\View;
use think\facade\Request;
use think\facade\Session;
use think\facade\Db;

class Task extends AdminController
{
    public function index()
    {
        try {
            $page = Request::param('page', 1);
            $limit = Request::param('limit', 20);
            $keyword = Request::param('keyword', '');
            $type = Request::param('type', '');
            $status = Request::param('status/d', -1);

            $where = [];
            if ($keyword) {
                $escapedKeyword = Db::escape($keyword);
                $where[] = ['name', 'like', "%{$escapedKeyword}%"];
            }
            if ($type) {
                $where[] = ['type', '=', $type];
            }
            if ($status >= 0) {
                $where[] = ['status', '=', $status];
            }

            $tasks = TaskModel::where($where)
                ->order('sort ASC, id DESC')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);

            if (Request::isAjax()) {
                return json([
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => [
                        'list' => $tasks->items(),
                        'total' => $tasks->total()
                    ]
                ]);
            }

            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员'),
                'tasks' => $tasks,
                'keyword' => $keyword,
                'type' => $type,
                'status' => $status
            ]);
            return View::fetch('admin/task/index');
        } catch (\Exception $e) {
            if (Request::isAjax()) {
                return json([
                    'code' => 500,
                    'msg' => '获取任务列表失败: ' . $e->getMessage()
                ]);
            }
            return '<h1>任务列表</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }

    public function add()
    {
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员')
        ]);
        return View::fetch('admin/task/add');
    }

    public function save()
    {
        $data = Request::post();

        if (empty($data['name'])) {
            return json(['code' => 400, 'msg' => '任务名称不能为空']);
        }

        if (empty($data['type'])) {
            return json(['code' => 400, 'msg' => '任务类型不能为空']);
        }

        if (empty($data['reward'])) {
            return json(['code' => 400, 'msg' => '奖励金额不能为空']);
        }

        $task = new TaskModel();
        $task->name = $data['name'];
        $task->type = $data['type'];
        $task->description = $data['description'] ?? '';
        $task->reward = floatval($data['reward']);
        $task->target = intval($data['target'] ?? 1);
        $task->sort = intval($data['sort'] ?? 0);
        $task->status = intval($data['status'] ?? 1);
        $task->create_time = time();
        $task->save();

        return json(['code' => 200, 'msg' => '添加成功']);
    }

    public function edit($id)
    {
        $task = TaskModel::getTaskById($id);
        if (!$task) {
            return redirect('/admin/task');
        }

        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'task' => $task
        ]);
        return View::fetch('admin/task/edit');
    }

    public function update()
    {
        $id = Request::param('id/d');
        $data = Request::only(['name', 'type', 'description', 'reward', 'target', 'sort', 'status']);

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        if (empty($data['name'])) {
            return json(['code' => 400, 'msg' => '任务名称不能为空']);
        }

        if (empty($data['type'])) {
            return json(['code' => 400, 'msg' => '任务类型不能为空']);
        }

        if (empty($data['reward'])) {
            return json(['code' => 400, 'msg' => '奖励金额不能为空']);
        }

        $task = TaskModel::getTaskById($id);
        if (!$task) {
            return json(['code' => 404, 'msg' => '任务不存在']);
        }

        $task->name = $data['name'];
        $task->type = $data['type'];
        $task->description = $data['description'] ?? '';
        $task->reward = floatval($data['reward']);
        $task->target = intval($data['target'] ?? 1);
        $task->sort = intval($data['sort'] ?? 0);
        $task->status = intval($data['status'] ?? 1);
        $task->update_time = time();
        $task->save();

        return json(['code' => 200, 'msg' => '更新成功']);
    }

    public function delete($id)
    {
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        $task = TaskModel::getTaskById($id);
        if (!$task) {
            return json(['code' => 404, 'msg' => '任务不存在']);
        }

        $task->delete();

        return json(['code' => 200, 'msg' => '删除成功']);
    }

    public function statistics()
    {
        try {
            $totalTasks = TaskModel::count();
            $activeTasks = TaskModel::where('status', 1)->count();
            $dailyTasks = TaskModel::where('type', 'daily')->count();
            $growthTasks = TaskModel::where('type', 'growth')->count();

            $taskStats = [];
            try {
                $taskStats = Db::name('task_completion')
                    ->field('task_id, COUNT(*) as completion_count')
                    ->group('task_id')
                    ->select();
                if ($taskStats) {
                    $taskStats = $taskStats->toArray();
                }
            } catch (\Exception $e) {
                $taskStats = [];
            }

            $stats = [
                'total_tasks' => $totalTasks,
                'active_tasks' => $activeTasks,
                'daily_tasks' => $dailyTasks,
                'growth_tasks' => $growthTasks,
                'completion_stats' => $taskStats
            ];

            if (Request::isAjax()) {
                return json([
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $stats
                ]);
            }

            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员'),
                'stats' => $stats
            ]);
            return View::fetch('admin/task/statistics');
        } catch (\Exception $e) {
            if (Request::isAjax()) {
                return json([
                    'code' => 500,
                    'msg' => '获取统计数据失败: ' . $e->getMessage()
                ]);
            }
            return '<h1>任务统计</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }

    public function effect()
    {
        try {
            $taskEffects = [];
            try {
                $taskEffects = Db::name('task_completion')
                    ->alias('tc')
                    ->field('tc.*, t.name as task_name, t.type as task_type, u.username')
                    ->leftJoin('task t', 'tc.task_id = t.id')
                    ->leftJoin('user u', 'tc.user_id = u.id')
                    ->order('tc.create_time DESC')
                    ->paginate(20);
            } catch (\Exception $e) {
                $taskEffects = [];
            }

            if (Request::isAjax()) {
                return json([
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => [
                        'list' => $taskEffects->items(),
                        'total' => $taskEffects->total()
                    ]
                ]);
            }

            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员'),
                'taskEffects' => $taskEffects
            ]);
            return View::fetch('admin/task/effect');
        } catch (\Exception $e) {
            if (Request::isAjax()) {
                return json([
                    'code' => 500,
                    'msg' => '获取效果数据失败: ' . $e->getMessage()
                ]);
            }
            return '<h1>任务效果分析</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }
}
