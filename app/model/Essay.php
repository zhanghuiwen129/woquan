<?php

namespace app\model;

use think\Model;
use think\facade\Cache;

/**
 * 文章模型类
 */
class Essay extends Model
{
    /**
     * 对应的数据表名
     * @var string
     */
    protected $name = 'essay';
    
    /**
     * 是否自动写入时间戳
     * @var boolean
     */
    protected $autoWriteTimestamp = false;
    
    /**
     * 缓存键前缀
     * @var string
     */
    public const CACHE_KEY_PREFIX = 'essay_';
    
    /**
     * 用户文章列表缓存键前缀
     * @var string
     */
    public const CACHE_KEY_USER_ESSAYS = 'user_essays_';
    
    /**
     * 文章类型映射
     * @var array
     */
    public const TYPE_MAP = [
        'only' => '仅文字',
        'img' => '图文',
        'video' => '视频',
        'music' => '音乐'
    ];
    
    /**
     * 获取文章类型中文
     *
     * @return string 文章类型中文
     */
    public function getTypeTextAttr()
    {
        return self::TYPE_MAP[$this->ptplx] ?? '未知';
    }
    
    /**
     * 获取图片数组
     *
     * @return array 图片URL数组
     */
    public function getImagesAttr()
    {
        if (empty($this->ptpimag)) {
            return [];
        }
        return explode('(+@+)', $this->ptpimag);
    }
    
    /**
     * 获取视频信息
     *
     * @return array 视频信息数组
     */
    public function getVideoInfoAttr()
    {
        if (empty($this->ptpvideo)) {
            return ['url' => '', 'cover' => ''];
        }
        $parts = explode('|', $this->ptpvideo);
        return [
            'url' => $parts[0] ?? '',
            'cover' => $parts[1] ?? ''
        ];
    }
    
    /**
     * 关联用户
     *
     * @return \think\model\Relation 关联关系
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'ptpuser', 'username');
    }
    
    /**
     * 根据ID获取文章（带缓存）
     *
     * @param int $id 文章ID
     * @return mixed|null 文章对象或null
     */
    public static function getById($id)
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $id;
        
        // 尝试从缓存获取
        $essay = Cache::get($cacheKey);
        
        if (empty($essay)) {
            // 缓存不存在，从数据库获取
            $essay = self::find($id);
            
            // 存入缓存，有效期1小时
            if (!empty($essay)) {
                Cache::set($cacheKey, $essay, 3600);
            }
        }
        
        return $essay;
    }
    
    /**
     * 获取用户文章列表（带缓存）
     *
     * @param string $username 用户名
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return mixed|null 文章列表或null
     */
    public static function getUserEssays($username, $page = 1, $limit = 10)
    {
        $cacheKey = self::CACHE_KEY_USER_ESSAYS . md5($username) . '_' . $page . '_' . $limit;
        
        // 尝试从缓存获取
        $essays = Cache::get($cacheKey);
        
        if (empty($essays)) {
            // 缓存不存在，从数据库获取
            $essays = self::where('ptpuser', $username)->where('ptpys', 1)
                ->page($page, $limit)
                ->order('id DESC')
                ->select();
            
            // 存入缓存，有效期10分钟
            if (!empty($essays)) {
                Cache::set($cacheKey, $essays, 600);
            }
        }
        
        return $essays;
    }
    
    /**
     * 清除文章缓存
     *
     * @param int $id 文章ID
     * @return void
     */
    public static function clearCache($id)
    {
        // 清除文章详情缓存
        Cache::delete(self::CACHE_KEY_PREFIX . $id);
        
        // 获取文章信息，清除用户文章列表缓存
        $essay = self::find($id);
        if (!empty($essay)) {
            // 清除该用户的所有文章列表缓存
            Cache::delete(self::CACHE_KEY_USER_ESSAYS . md5($essay['ptpuser']) . '_*');
        }
    }
}
