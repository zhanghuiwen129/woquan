<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

/**
 * 话题管理控制器
 */
class Topic extends AdminController
{

    
    // 话题列表页面
    public function index()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $isHot = Request::param('is_hot/d', -1);
        $status = Request::param('status/d', -1);
        
        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['name', 'like', "%{$escapedKeyword}%"];
        }
        if ($isHot >= 0) {
            $where[] = ['is_hot', '=', $isHot];
        }
        if ($status >= 0) {
            $where[] = ['status', '=', $status];
        }
        
        $topics = Db::name('topics')
            ->where($where)
            ->order('sort_order ASC, create_time DESC')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'topics' => $topics,
            'keyword' => $keyword,
            'isHot' => $isHot,
            'status' => $status,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/topic/index');
    }
    
    // 添加话题页面
    public function add()
    {
        return View::fetch('admin/topic/add');
    }
    
    // 保存话题
    public function save()
    {
        $data = Request::only([
            'name', 'description', 'cover', 'is_hot', 'sort_order', 'status'
        ]);
        
        // 验证参数
        if (empty($data['name'])) {
            return json(['code' => 400, 'msg' => '请输入话题名称']);
        }
        
        // 检查话题名称是否已存在
        $existingTopic = Db::name('topics')
            ->where('name', $data['name'])
            ->find();
        
        if ($existingTopic) {
            return json(['code' => 400, 'msg' => '话题名称已存在']);
        }
        
        try {
            $topicData = [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'cover' => $data['cover'] ?? '',
                'is_hot' => $data['is_hot'] ?? 0,
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => $data['status'] ?? 1,
                'create_time' => time(),
                'update_time' => time()
            ];
            
            $topicId = Db::name('topics')->insertGetId($topicData);
            
            if ($topicId) {
                return json(['code' => 200, 'msg' => '添加成功']);
            }
            
            return json(['code' => 500, 'msg' => '添加失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '添加失败：' . $e->getMessage()]);
        }
    }
    
    // 编辑话题页面
    public function edit()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return redirect('/admin/topic');
        }
        
        $topic = Db::name('topics')->where('id', $id)->find();
        if (!$topic) {
            return redirect('/admin/topic');
        }
        
        View::assign('topic', $topic);
        return View::fetch('admin/topic/edit');
    }
    
    // 更新话题
    public function update()
    {
        $id = Request::param('id/d');
        $data = Request::only([
            'name', 'description', 'cover', 'is_hot', 'sort_order', 'status'
        ]);
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        // 验证参数
        if (empty($data['name'])) {
            return json(['code' => 400, 'msg' => '请输入话题名称']);
        }
        
        // 检查话题名称是否已存在
        $existingTopic = Db::name('topics')
            ->where('name', $data['name'])
            ->where('id', '<>', $id) // 排除当前话题
            ->find();
        
        if ($existingTopic) {
            return json(['code' => 400, 'msg' => '话题名称已存在']);
        }
        
        try {
            $topicData = [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'cover' => $data['cover'] ?? '',
                'is_hot' => $data['is_hot'] ?? 0,
                'sort' => $data['sort_order'] ?? 0,
                'status' => $data['status'] ?? 1
            ];
            
            $result = Db::name('topics')->where('id', $id)->update($topicData);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '更新成功']);
            }
            
            return json(['code' => 500, 'msg' => '更新失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '更新失败：' . $e->getMessage()]);
        }
    }
    
    // 删除话题
    public function delete()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            // 删除话题的同时删除相关关注记录
            Db::startTrans();
            
            // 删除关注记录
            Db::name('topic_follows')->where('topic_id', $id)->delete();
            
            // 删除话题
            $result = Db::name('topics')->where('id', $id)->delete();
            
            Db::commit();
            
            if ($result) {
                return json(['code' => 200, 'msg' => '删除成功']);
            }
            
            return json(['code' => 500, 'msg' => '删除失败']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }
    
    // 批量删除话题
    public function batchDelete()
    {
        $ids = Request::post('ids');
        
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            // 批量删除话题的同时删除相关关注记录
            Db::startTrans();
            
            // 删除关注记录
            Db::name('topic_follows')->where('topic_id', 'in', $ids)->delete();
            
            // 删除话题
            $result = Db::name('topics')->where('id', 'in', $ids)->delete();
            
            Db::commit();
            
            if ($result) {
                return json(['code' => 200, 'msg' => '删除成功']);
            }
            
            return json(['code' => 500, 'msg' => '删除失败']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }
    
    // 切换热门状态
    public function toggleHot()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $topic = Db::name('topics')->where('id', $id)->find();
            if (!$topic) {
                return json(['code' => 404, 'msg' => '话题不存在']);
            }
            
            $newHotStatus = $topic['is_hot'] ? 0 : 1;
            $result = Db::name('topics')->where('id', $id)->update(['is_hot' => $newHotStatus]);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '操作成功', 'data' => ['is_hot' => $newHotStatus]]);
            }
            
            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
    
    // 切换状态
    public function toggleStatus()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $topic = Db::name('topics')->where('id', $id)->find();
            if (!$topic) {
                return json(['code' => 404, 'msg' => '话题不存在']);
            }
            
            $newStatus = $topic['status'] ? 0 : 1;
            $result = Db::name('topics')->where('id', $id)->update(['status' => $newStatus]);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '操作成功', 'data' => ['status' => $newStatus]]);
            }
            
            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
}
