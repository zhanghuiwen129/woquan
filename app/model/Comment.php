<?php

namespace app\model;

use think\Model;

class Comment extends Model
{
    protected $name = 'comments';
    protected $autoWriteTimestamp = false;
    
    // 审核状态
    const STATUS_PENDING = '0'; // 待审核
    const STATUS_APPROVED = '1'; // 已审核
    const STATUS_REJECTED = '-1'; // 已拒绝
    
    // 关联用户
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
    
    // 关联动态
    public function moment()
    {
        return $this->belongsTo(Moment::class, 'moment_id', 'id');
    }
    
    // 获取审核状态文本
    public function getStatusTextAttr()
    {
        $map = [
            self::STATUS_PENDING => '待审核',
            self::STATUS_APPROVED => '已审核', 
            self::STATUS_REJECTED => '已拒绝'
        ];
        
        return $map[$this->status] ?? '未知';
    }
}
