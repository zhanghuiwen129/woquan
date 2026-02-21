<?php
namespace app\model;

use think\Model;

class Notification extends Model
{
    // 设置当前模型对应的完整数据表名称
    protected $name = 'notifications';

    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 定义字段类型
    protected $type = [
        'create_time' => 'timestamp',
        'update_time' => 'timestamp',
    ];

    // 获取管理员通知列表
    public static function getAdminNotifications($adminId, $limit = 10)
    {
        return self::where('admin_id', $adminId)
            ->order('is_read ASC, create_time DESC')
            ->limit($limit)
            ->select();
    }

    // 获取未读通知数量
    public static function getUnreadCount($adminId)
    {
        return self::where([
            'admin_id' => $adminId,
            'is_read' => 0
        ])->count();
    }

    // 标记所有通知为已读
    public static function markAllAsRead($adminId)
    {
        return self::where('admin_id', $adminId)
            ->where('is_read', 0)
            ->update(['is_read' => 1, 'update_time' => time()]);
    }
}
