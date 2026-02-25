<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;

/**
 * 评论管理控制器
 */
class Comment extends AdminController
{
    
    protected function formatContent($content)
    {
        if (!$content) return '';
        
        $content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        
        $content = preg_replace_callback(
            '/&lt;img[^&]*class\s*=\s*["\']inline-emoji["\'][^&]*&gt;/i',
            function($matches) {
                preg_match('/src\s*=\s*["\']([^"\']*)["\']/', $matches[0], $srcMatch);
                $src = isset($srcMatch[1]) ? htmlspecialchars_decode($srcMatch[1]) : '';
                return '<img src="' . $src . '" class="inline-emoji" style="width: 16px; height: 16px; vertical-align: middle; display: inline-block;">';
            },
            $content
        );
        
        return $content;
    }
    
    // 评论列表页面
    public function index()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status/d', -1);
        $momentId = Request::param('moment_id/d', 0);
        
        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['content', 'like', "%{$escapedKeyword}%"];
        }
        if ($status >= 0) {
            $where[] = ['status', '=', $status];
        }
        if ($momentId > 0) {
            $where[] = ['moment_id', '=', $momentId];
        }
        
        $comments = Db::name('comments')
            ->where($where)
            ->order('is_top DESC, create_time DESC')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        foreach ($comments as &$comment) {
            $comment['content'] = $this->formatContent($comment['content']);
        }
        
        View::assign([
            'comments' => $comments,
            'keyword' => $keyword,
            'status' => $status,
            'momentId' => $momentId,
            'admin_name' => \think\facade\Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/comment/index');
    }
    
    // 查看评论详情
    public function detail()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return redirect('/admin/comment');
        }
        
        $comment = Db::name('comments')->where('id', $id)->find();
        if (!$comment) {
            return redirect('/admin/comment');
        }

        // 获取回复列表
        $replies = Db::name('comments')->where('parent_id', $id)->order('create_time ASC')->select();
        
        // 格式化内容
        $comment['content'] = $this->formatContent($comment['content']);
        foreach ($replies as &$reply) {
            $reply['content'] = $this->formatContent($reply['content']);
        }
        
        View::assign([
            'comment' => $comment,
            'replies' => $replies
        ]);
        
        return View::fetch('admin/comment/detail');
    }
    
    // 审核评论
    public function audit()
    {
        $id = Request::param('id/d');
        $status = Request::param('status/d');
        
        if (!$id || $status < 0) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = Db::name('comments')->where('id', $id)->update(['status' => $status]);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '审核成功']);
            }
            
            return json(['code' => 500, 'msg' => '审核失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '审核失败：' . $e->getMessage()]);
        }
    }
    
    // 更新评论状态（用于前端AJAX调用）
    public function updateStatus()
    {
        $id = Request::param('id/d');
        $status = Request::param('status/d');
        
        if (!$id || $status < 0) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = Db::name('comments')->where('id', $id)->update(['status' => $status]);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '状态更新成功']);
            }
            
            return json(['code' => 500, 'msg' => '状态更新失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '状态更新失败：' . $e->getMessage()]);
        }
    }
    
    // 删除评论
    public function delete()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            // 删除评论的同时删除所有回复
            Db::startTrans();
            
            // 删除回复
            Db::name('comments')->where('parent_id', $id)->delete();

            // 删除评论
            $result = Db::name('comments')->where('id', $id)->delete();
            
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
    
    // 批量删除评论
    public function batchDelete()
    {
        $ids = Request::post('ids');
        
        if (empty($ids)) {
            $ids = Request::param('ids');
        }
        
        if (empty($ids)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        
        try {
            Db::startTrans();
            
            Db::name('comments')->where('parent_id', 'in', $ids)->delete();
            $result = Db::name('comments')->where('id', 'in', $ids)->delete();
            
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
    
    // 批量审核评论
    public function batchAudit()
    {
        $ids = Request::post('ids');
        $status = Request::post('status/d', 1);
        
        if (empty($ids)) {
            $ids = Request::param('ids');
        }
        
        if (empty($ids)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        
        try {
            $result = Db::name('comments')->where('id', 'in', $ids)->update(['status' => $status]);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '批量操作成功']);
            }
            
            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
    
    // 批量置顶评论
    public function batchTop()
    {
        $ids = Request::post('ids');
        
        if (empty($ids)) {
            $ids = Request::param('ids');
        }
        
        if (empty($ids)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        
        try {
            $result = Db::name('comments')->where('id', 'in', $ids)->update(['is_top' => 1]);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '批量置顶成功']);
            }
            
            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
    
    // 批量取消置顶评论
    public function batchCancelTop()
    {
        $ids = Request::post('ids');
        
        if (empty($ids)) {
            $ids = Request::param('ids');
        }
        
        if (empty($ids)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        
        try {
            $result = Db::name('comments')->where('id', 'in', $ids)->update(['is_top' => 0]);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '批量取消置顶成功']);
            }
            
            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
    
    // 统计数据
    public function statistics()
    {
        try {
            $total = Db::name('comments')->count();

            $today = Db::name('comments')
                ->where('create_time', '>=', strtotime('today'))
                ->count();

            $pending = Db::name('comments')
                ->where('status', 0)
                ->count();

            $blocked = Db::name('comments')
                ->where('status', 0)
                ->count();

            $data = [
                'total' => $total,
                'today' => $today,
                'pending' => $pending,
                'blocked' => $blocked
            ];

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '获取失败：' . $e->getMessage()]);
        }
    }
    
    // 切换置顶状态
    public function toggleTop()
    {
        $id = Request::param('id/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $comment = Db::name('comments')->where('id', $id)->find();
            if (!$comment) {
                return json(['code' => 404, 'msg' => '评论不存在']);
            }

            $newTopStatus = isset($comment['is_top']) ? ($comment['is_top'] ? 0 : 1) : 1;
            $result = Db::name('comments')->where('id', $id)->update(['is_top' => $newTopStatus]);

            if ($result !== false) {
                return json(['code' => 200, 'msg' => '操作成功', 'data' => ['is_top' => $newTopStatus]]);
            }

            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
}
