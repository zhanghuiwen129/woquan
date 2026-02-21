<?php

namespace app\model;

use think\Model;

class Authorization extends Model
{
    protected $name = 'authorizations';
    protected $autoWriteTimestamp = false;
    protected $pk = 'id';
    
    public function software()
    {
        return $this->belongsTo(Software::class, 'software_id');
    }
    
    public static function getAllAuthorizations()
    {
        return self::with(['software'])->select();
    }
    
    public static function getAuthorizationById($id)
    {
        return self::with(['software'])->find($id);
    }
    
    public static function getAuthorizationsBySoftwareId($software_id)
    {
        return self::where('software_id', $software_id)->select();
    }
    
    public static function getAuthorizationByLicenseNumber($license_number)
    {
        return self::where('license_number', $license_number)->find();
    }
    
    public static function addAuthorization($data)
    {
        $data['create_time'] = time();
        $data['update_time'] = time();
        return self::create($data);
    }
    
    public static function updateAuthorization($id, $data)
    {
        $data['update_time'] = time();
        return self::where('id', $id)->update($data);
    }
    
    public static function deleteAuthorization($id)
    {
        return self::where('id', $id)->delete();
    }
    
    public function getStatusText()
    {
        return $this->status ? '有效' : '无效';
    }
    
    public function getExpiryStatus()
    {
        if ($this->end_time == 0) {
            return '永久';
        } elseif ($this->end_time < time()) {
            return '已过期';
        } else {
            return '有效期至 ' . date('Y-m-d', $this->end_time);
        }
    }
}
