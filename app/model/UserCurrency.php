<?php

namespace app\model;

use think\Model;
use think\facade\Db;

class UserCurrency extends Model
{
    protected $name = 'user_currency';
    protected $autoWriteTimestamp = false;
    
    // 关联货币类型
    public function currencyType()
    {
        return $this->belongsTo(CurrencyType::class, 'currency_id', 'id');
    }
    
    // 获取用户的货币信息
    public static function getUserCurrency($userId, $currencyId = null)
    {
        if ($currencyId) {
            return self::where('user_id', $userId)->where('currency_id', $currencyId)->find();
        }
        return self::where('user_id', $userId)->select();
    }
    
    // 获取用户的货币余额
    public static function getUserBalance($userId, $currencyId)
    {
        $userCurrency = self::getUserCurrency($userId, $currencyId);
        return $userCurrency ? $userCurrency->amount : 0;
    }
    
    // 获取用户的可用余额
    public static function getUserAvailableBalance($userId, $currencyId)
    {
        $userCurrency = self::getUserCurrency($userId, $currencyId);
        if (!$userCurrency) {
            return 0;
        }
        return bcsub($userCurrency->amount, $userCurrency->freeze_amount, 2);
    }
    
    // 增加用户货币
    public static function increaseUserCurrency($userId, $currencyId, $amount, $type = 'reward', $remark = '', $sourceId = null, $sourceType = null)
    {
        if ($amount <= 0) {
            return false;
        }
        
        Db::startTrans();
        try {
            // 获取或创建用户货币记录
            $userCurrency = self::getUserCurrency($userId, $currencyId);
            if (!$userCurrency) {
                $userCurrency = new self();
                $userCurrency->user_id = $userId;
                $userCurrency->currency_id = $currencyId;
                $userCurrency->amount = 0;
                $userCurrency->freeze_amount = 0;
                $userCurrency->update_time = time();
            }
            
            $beforeAmount = $userCurrency->amount;
            $afterAmount = bcadd($beforeAmount, $amount, 2);
            
            // 更新用户货币
            $userCurrency->amount = $afterAmount;
            $userCurrency->update_time = time();
            $userCurrency->save();
            
            // 记录变动日志
            CurrencyLog::createLog($userId, $currencyId, $amount, $beforeAmount, $afterAmount, $type, $remark, $sourceId, $sourceType);
            
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            return false;
        }
    }
    
    // 减少用户货币
    public static function decreaseUserCurrency($userId, $currencyId, $amount, $type = 'consume', $remark = '', $sourceId = null, $sourceType = null)
    {
        if ($amount <= 0) {
            return false;
        }
        
        // 检查余额是否充足
        $availableBalance = self::getUserAvailableBalance($userId, $currencyId);
        if (bccomp($availableBalance, $amount, 2) < 0) {
            return false;
        }
        
        Db::startTrans();
        try {
            // 获取用户货币记录
            $userCurrency = self::getUserCurrency($userId, $currencyId);
            if (!$userCurrency) {
                Db::rollback();
                return false;
            }
            
            $beforeAmount = $userCurrency->amount;
            $afterAmount = bcsub($beforeAmount, $amount, 2);
            
            // 更新用户货币
            $userCurrency->amount = $afterAmount;
            $userCurrency->update_time = time();
            $userCurrency->save();
            
            // 记录变动日志
            CurrencyLog::createLog($userId, $currencyId, -$amount, $beforeAmount, $afterAmount, $type, $remark, $sourceId, $sourceType);
            
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            return false;
        }
    }
    
    // 冻结用户货币
    public static function freezeUserCurrency($userId, $currencyId, $amount, $remark = '')
    {
        if ($amount <= 0) {
            return false;
        }
        
        // 检查余额是否充足
        $availableBalance = self::getUserAvailableBalance($userId, $currencyId);
        if (bccomp($availableBalance, $amount, 2) < 0) {
            return false;
        }
        
        Db::startTrans();
        try {
            // 获取用户货币记录
            $userCurrency = self::getUserCurrency($userId, $currencyId);
            if (!$userCurrency) {
                Db::rollback();
                return false;
            }
            
            // 更新冻结金额
            $userCurrency->amount = bcsub($userCurrency->amount, $amount, 2);
            $userCurrency->freeze_amount = bcadd($userCurrency->freeze_amount, $amount, 2);
            $userCurrency->update_time = time();
            $userCurrency->save();
            
            // 记录变动日志
            CurrencyLog::createLog($userId, $currencyId, -$amount, $userCurrency->amount + $amount, $userCurrency->amount, 'freeze', $remark);
            
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            return false;
        }
    }
    
    // 解冻用户货币
    public static function unfreezeUserCurrency($userId, $currencyId, $amount, $remark = '')
    {
        if ($amount <= 0) {
            return false;
        }
        
        Db::startTrans();
        try {
            // 获取用户货币记录
            $userCurrency = self::getUserCurrency($userId, $currencyId);
            if (!$userCurrency) {
                Db::rollback();
                return false;
            }
            
            // 检查冻结金额是否充足
            if (bccomp($userCurrency->freeze_amount, $amount, 2) < 0) {
                Db::rollback();
                return false;
            }
            
            // 更新冻结金额
            $userCurrency->amount = bcadd($userCurrency->amount, $amount, 2);
            $userCurrency->freeze_amount = bcsub($userCurrency->freeze_amount, $amount, 2);
            $userCurrency->update_time = time();
            $userCurrency->save();
            
            // 记录变动日志
            CurrencyLog::createLog($userId, $currencyId, $amount, $userCurrency->amount - $amount, $userCurrency->amount, 'unfreeze', $remark);
            
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            return false;
        }
    }
}
