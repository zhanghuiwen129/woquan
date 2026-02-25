<?php

namespace app\model;

use think\Model;

class Server extends Model
{
    protected $name = 'servers';
    protected $autoWriteTimestamp = false;
    
    // 获取所有服务器
    public static function getAllServers()
    {
        return self::select();
    }
    
    // 根据ID获取服务器
    public static function getServerById($id)
    {
        return self::find($id);
    }
    
    // 根据IP获取服务器
    public static function getServerByIp($ip)
    {
        return self::where('server_ip', $ip)->find();
    }
    
    // 添加服务器
    public static function addServer($data)
    {
        $data['create_time'] = time();
        $data['update_time'] = time();
        return self::create($data);
    }
    
    // 更新服务器信息
    public static function updateServer($id, $data)
    {
        $data['update_time'] = time();
        return self::where('id', $id)->update($data);
    }
    
    // 删除服务器
    public static function deleteServer($id)
    {
        return self::where('id', $id)->delete();
    }
    
    // 获取服务器状态
    public function getStatusText()
    {
        return $this->status ? '正常' : '异常';
    }
}
