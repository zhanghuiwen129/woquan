<?php

namespace app\model;

use think\Model;

class Task extends Model
{
    protected $name = 'task';
    protected $autoWriteTimestamp = false;
    
    // 任务类型常量
    const TYPE_DAILY = 'daily'; // 每日任务
    const TYPE_GROWTH = 'growth'; // 成长任务
    
    // 任务状态常量
    const STATUS_ACTIVE = 1; // 激活
    const STATUS_INACTIVE = 0; // 未激活
    
    // 获取每日任务列表
    public static function getDailyTasks()
    {
        return self::where('type', self::TYPE_DAILY)
            ->where('status', self::STATUS_ACTIVE)
            ->order('sort ASC, id ASC')
            ->select();
    }
    
    // 获取成长任务列表
    public static function getGrowthTasks()
    {
        return self::where('type', self::TYPE_GROWTH)
            ->where('status', self::STATUS_ACTIVE)
            ->order('sort ASC, id ASC')
            ->select();
    }
    
    // 根据ID获取任务
    public static function getTaskById($id)
    {
        return self::where('id', $id)->find();
    }
    
    // 检查任务是否存在且激活
    public static function isTaskValid($id)
    {
        $task = self::getTaskById($id);
        return $task && $task->status == self::STATUS_ACTIVE;
    }
}
