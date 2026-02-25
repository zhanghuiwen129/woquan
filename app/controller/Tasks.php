<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use app\model\Task;
use app\model\TaskRecord;

class Tasks extends BaseFrontendController
{
    /**
     * 获取每日任务列表
     */
    public function getDailyTasks()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取每日任务列表
            $tasks = Task::getDailyTasks();
            
            // 转换任务数据格式并检查是否已完成
            $taskList = [];
            foreach ($tasks as $task) {
                $taskList[] = [
                    'id' => $task->id,
                    'name' => $task->name,
                    'description' => $task->description,
                    'points' => $task->points,
                    'type' => $task->type,
                    'icon' => $task->icon,
                    'completed' => TaskRecord::hasCompleted($userId, $task->id)
                ];
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $taskList
            ]);
        } catch (\Exception $e) {
            return $this->error('获取每日任务失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取成长任务列表
     */
    public function getGrowthTasks()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取成长任务列表
            $tasks = Task::getGrowthTasks();
            
            // 转换任务数据格式并检查是否已完成
            $taskList = [];
            foreach ($tasks as $task) {
                $taskList[] = [
                    'id' => $task->id,
                    'name' => $task->name,
                    'description' => $task->description,
                    'points' => $task->points,
                    'type' => $task->type,
                    'icon' => $task->icon,
                    'completed' => TaskRecord::hasCompleted($userId, $task->id)
                ];
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $taskList
            ]);
        } catch (\Exception $e) {
            return $this->error('获取成长任务失败: ' . $e->getMessage());
        }
    }

    /**
     * 完成任务
     */
    public function completeTask()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return $this->unauthorized();
            }

            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['task_id']) || !isset($data['task_type'])) {
                return $this->badRequest('参数错误');
            }

            $taskId = $data['task_id'];
            $taskType = $data['task_type'];

            $task = Task::getTaskById($taskId);
            if (!$task) {
                return $this->notFound('任务不存在');
            }

            if ($task->type != $taskType) {
                return $this->badRequest('任务类型不匹配');
            }

            if (TaskRecord::hasCompleted($userId, $taskId)) {
                return $this->badRequest('任务已完成');
            }

            // 记录任务完成
            if (!TaskRecord::recordCompletion($userId, $taskId)) {
                return $this->error('任务完成失败');
            }

            return json([
                'code' => 200,
                'msg' => '任务完成，获得 ' . $task->points . ' 积分！',
                'data' => [
                    'task_id' => $taskId,
                    'task_name' => $task->name,
                    'points' => $task->points
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error('完成任务失败: ' . $e->getMessage());
        }
    }

    /**
     * 清空任务记录
     */
    public function clearHistory()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return $this->unauthorized();
            }

            $result = Db::name('task_record')->where('user_id', $userId)->delete();

            return json([
                'code' => 200,
                'msg' => '历史记录已清空'
            ]);
        } catch (\Exception $e) {
            return $this->error('清空历史记录失败: ' . $e->getMessage());
        }
    }
}
