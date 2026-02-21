<?php

namespace app\model;

use think\Model;

class CurrencyLog extends Model
{
    protected $name = 'currency_logs';
    protected $autoWriteTimestamp = false;
    
    // 关联货币类型
    public function currencyType()
    {
        return $this->belongsTo(CurrencyType::class, 'currency_id', 'id');
    }
    
    // 关联用户
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
    
    // 创建货币变动日志
    public static function createLog($userId, $currencyId, $amount, $beforeAmount, $afterAmount, $type, $remark = '', $sourceId = null, $sourceType = null)
    {
        $log = new self();
        $log->user_id = $userId;
        $log->currency_id = $currencyId;
        $log->amount = $amount;
        $log->before_amount = $beforeAmount;
        $log->after_amount = $afterAmount;
        $log->type = $type;
        $log->remark = $remark;
        $log->source_id = $sourceId;
        $log->source_type = $sourceType;
        $log->create_time = time();
        $log->save();
        
        return $log;
    }
    
    // 获取用户的货币变动日志
    public static function getUserLogs($userId, $currencyId = null, $type = null, $page = 1, $pageSize = 20)
    {
        $query = self::where('user_id', $userId);
        
        if ($currencyId) {
            $query->where('currency_id', $currencyId);
        }
        
        if ($type) {
            $query->where('type', $type);
        }
        
        return $query->order('create_time DESC')
            ->page($page, $pageSize)
            ->select();
    }
    
    // 获取货币变动日志列表（后台管理用）
    public static function getAdminLogs($keyword = '', $currencyId = null, $type = null, $startTime = null, $endTime = null, $page = 1, $pageSize = 20)
    {
        $query = self::field('currency_logs.*, u.username, u.nickname, ct.name as currency_name, ct.symbol');
        
        $query->join('user u', 'currency_logs.user_id = u.id');
        $query->join('currency_types ct', 'currency_logs.currency_id = ct.id');
        
        if ($keyword) {
            $escapedKeyword = \think\facade\Db::escape($keyword);
            $query->where('u.username|u.nickname', 'like', "%{$escapedKeyword}%");
        }
        
        if ($currencyId) {
            $query->where('currency_logs.currency_id', $currencyId);
        }
        
        if ($type) {
            $query->where('currency_logs.type', $type);
        }
        
        if ($startTime) {
            $query->where('currency_logs.create_time', '>=', $startTime);
        }
        
        if ($endTime) {
            $query->where('currency_logs.create_time', '<=', $endTime);
        }
        
        return $query->order('currency_logs.create_time DESC')
            ->page($page, $pageSize)
            ->paginate([
                'list_rows' => $pageSize,
                'page' => $page
            ]);
    }
    
    // 获取货币变动类型统计
    public static function getTypeStats($userId = null, $currencyId = null, $startTime = null, $endTime = null)
    {
        $query = self::field('type, SUM(amount) as total_amount, COUNT(*) as count');
        
        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        if ($currencyId) {
            $query->where('currency_id', $currencyId);
        }
        
        if ($startTime) {
            $query->where('create_time', '>=', $startTime);
        }
        
        if ($endTime) {
            $query->where('create_time', '<=', $endTime);
        }
        
        return $query->group('type')
            ->select();
    }
}
