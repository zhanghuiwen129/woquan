<?php
namespace app\model;

use think\Model;

/**
 * 系统公告模型
 */
class Announcement extends Model
{
    protected $name = 'announcements';
    
    // 自动时间戳
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    /**
     * 获取所有公告列表
     * @param array $where 条件
     * @param array $order 排序
     * @param int $limit 数量
     * @param int $page 页码
     * @return array
     */
    public static function getAnnouncements($where = [], $order = ['id' => 'desc'], $limit = 20, $page = 1)
    {
        $query = self::where($where);
        
        if ($page > 0) {
            $data = $query->order($order)->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        } else {
            $data = $query->order($order)->limit($limit)->select();
        }
        
        return $data;
    }
    
    /**
     * 获取单条公告
     * @param int $id 公告ID
     * @return array|null
     */
    public static function getAnnouncement($id)
    {
        return self::where('id', $id)->find();
    }
    
    /**
     * 获取当前生效的公告
     * @param bool $isPopup 是否获取弹窗公告
     * @return array
     */
    public static function getActiveAnnouncements($isPopup = false)
    {
        $now = time();
        $where = [
            ['status', '=', 1],
            ['is_publish', '=', 1],
            ['publish_time', '<=', $now],
        ];
        
        if ($isPopup) {
            $where[] = ['is_popup', '=', 1];
        }
        
        $where[] = ['expire_time', 'in', [0, ['>', $now]]];
        
        return self::where($where)
            ->order(['is_popup' => 'desc', 'publish_time' => 'desc'])
            ->select();
    }
    
    /**
     * 创建公告
     * @param array $data 公告数据
     * @return bool
     */
    public static function createAnnouncement($data)
    {
        $announcement = new self();
        $announcement->save($data);
        return $announcement->id > 0;
    }
    
    /**
     * 更新公告
     * @param int $id 公告ID
     * @param array $data 公告数据
     * @return bool
     */
    public static function updateAnnouncement($id, $data)
    {
        return self::where('id', $id)->update($data);
    }
    
    /**
     * 删除公告
     * @param int $id 公告ID
     * @return bool
     */
    public static function deleteAnnouncement($id)
    {
        return self::where('id', $id)->delete();
    }
    
    /**
     * 批量删除公告
     * @param array $ids 公告ID数组
     * @return bool
     */
    public static function batchDeleteAnnouncements($ids)
    {
        return self::where('id', 'in', $ids)->delete();
    }
    
    /**
     * 切换公告状态
     * @param int $id 公告ID
     * @param int $status 状态
     * @return bool
     */
    public static function toggleStatus($id, $status)
    {
        return self::where('id', $id)->update(['status' => $status]);
    }
    
    /**
     * 切换公告发布状态
     * @param int $id 公告ID
     * @param int $isPublish 发布状态
     * @return bool
     */
    public static function togglePublish($id, $isPublish)
    {
        $data = ['is_publish' => $isPublish];
        
        if ($isPublish) {
            $data['publish_time'] = time();
        }
        
        return self::where('id', $id)->update($data);
    }
    
    /**
     * 切换公告弹窗状态
     * @param int $id 公告ID
     * @param int $isPopup 弹窗状态
     * @return bool
     */
    public static function togglePopup($id, $isPopup)
    {
        return self::where('id', $id)->update(['is_popup' => $isPopup]);
    }
    
    /**
     * 更新点击次数
     * @param int $id 公告ID
     * @return bool
     */
    public static function incrementClickCount($id)
    {
        return self::where('id', $id)->inc('click_count')->update();
    }
}
