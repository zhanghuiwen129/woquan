<?php

namespace app\model;

use think\Model;

class VipOrder extends Model
{
    protected $name = 'vip_orders';
    protected $autoWriteTimestamp = false;
    
    // 关联用户
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
    
    // 关联VIP等级
    public function vipLevel()
    {
        return $this->belongsTo(VipLevel::class, 'level_id', 'id');
    }
    
    // 生成唯一订单号
    public static function generateOrderNo()
    {
        return date('YmdHis') . substr(implode(null, array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8);
    }
    
    // 创建VIP订单
    public static function createOrder($userId, $levelId, $durationType, $amount)
    {
        $order = new self();
        $order->order_no = self::generateOrderNo();
        $order->user_id = $userId;
        $order->level_id = $levelId;
        $order->duration_type = $durationType;
        $order->amount = $amount;
        $order->pay_status = 0;
        $order->create_time = time();
        $order->save();
        
        return $order;
    }
    
    // 支付订单
    public function payOrder($payType)
    {
        $this->pay_status = 1;
        $this->pay_type = $payType;
        $this->pay_time = time();
        $this->save();
        
        // 更新用户VIP信息
        $this->updateUserVip();
        
        return true;
    }
    
    // 更新用户VIP信息
    protected function updateUserVip()
    {
        $vipLevel = $this->vipLevel;
        if (!$vipLevel) {
            return;
        }
        
        $isPermanent = $this->duration_type == 'permanent' ? 1 : 0;
        $startTime = time();
        $endTime = $isPermanent ? 0 : $this->calculateEndTime($startTime);
        
        // 更新或创建用户VIP记录
        $userVip = UserVip::getUserVipInfo($this->user_id);
        if (!$userVip) {
            $userVip = new UserVip();
            $userVip->user_id = $this->user_id;
        }
        
        $userVip->level_id = $this->level_id;
        $userVip->start_time = $startTime;
        $userVip->end_time = $endTime;
        $userVip->is_permanent = $isPermanent;
        $userVip->create_time = time();
        $userVip->save();
    }
    
    // 计算结束时间
    protected function calculateEndTime($startTime)
    {
        switch ($this->duration_type) {
            case 'month':
                return strtotime('+1 month', $startTime);
            case 'quarter':
                return strtotime('+3 months', $startTime);
            case 'year':
                return strtotime('+1 year', $startTime);
            default:
                return $startTime;
        }
    }
    
    // 获取用户订单列表
    public static function getUserOrders($userId, $page = 1, $pageSize = 10)
    {
        return self::where('user_id', $userId)
            ->order('create_time DESC')
            ->paginate(['list_rows' => $pageSize, 'page' => $page]);
    }
}
