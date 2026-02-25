<?php

namespace app\model;

use think\Model;
use think\facade\Db;

class TaskRecord extends Model
{
    protected $name = 'task_record';
    protected $autoWriteTimestamp = false;
    
    // 记录状态常量
    const STATUS_COMPLETED = 1; // 已完成
    const STATUS_PENDING = 0; // 待完成
    
    // 检查用户是否已完成任务
    public static function hasCompleted($userId, $taskId)
    {
        $task = Task::getTaskById($taskId);
        if (!$task) {
            return false;
        }
        
        if ($task->type == Task::TYPE_DAILY) {
            // 每日任务，检查今天是否已完成
            $today = strtotime(date('Y-m-d'));
            return self::where('user_id', $userId)
                ->where('task_id', $taskId)
                ->where('create_time', '>=', $today)
                ->find() ? true : false;
        } else {
            // 成长任务，检查是否已完成
            return self::where('user_id', $userId)
                ->where('task_id', $taskId)
                ->find() ? true : false;
        }
    }
    
    // 记录任务完成
    public static function recordCompletion($userId, $taskId)
    {
        $task = Task::getTaskById($taskId);
        if (!$task) {
            return false;
        }
        
        // 检查是否已完成
        if (self::hasCompleted($userId, $taskId)) {
            return false;
        }
        
        Db::startTrans();
        try {
            // 创建任务记录
            $record = new self();
            $record->user_id = $userId;
            $record->task_id = $taskId;
            $record->status = self::STATUS_COMPLETED;
            $record->create_time = time();
            $record->save();
            
            // 增加用户积分
            $primaryCurrency = CurrencyType::getPrimaryCurrency();
            if ($primaryCurrency) {
                UserCurrency::increaseUserCurrency(
                    $userId,
                    $primaryCurrency->id,
                    $task->points,
                    'task',
                    '完成任务: ' . $task->name,
                    $taskId,
                    'task'
                );
            }
            
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            return false;
        }
    }
    
    // 获取用户任务完成记录
    public static function getUserTaskRecords($userId, $type = null)
    {
        $query = self::where('user_id', $userId);
        
        if ($type) {
            $taskIds = Task::where('type', $type)->column('id');
            if (!empty($taskIds)) {
                $query->where('task_id', 'in', $taskIds);
            } else {
                return [];
            }
        }
        
        return $query->order('create_time', 'desc')->select();
    }
    
    // 清空用户任务记录
    public static function clearUserRecords($userId, $type = null)
    {
        $query = self::where('user_id', $userId);
        
        if ($type) {
            $taskIds = Task::where('type', $type)->column('id');
            if (!empty($taskIds)) {
                $query->where('task_id', 'in', $taskIds);
            } else {
                return true;
            }
        }
        
        return $query->delete() > 0;
    }
}
