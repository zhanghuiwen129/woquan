<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;
use Exception;

/**
 * 内容管理控制器
 */
class Content extends AdminController
{

    // 动态列表页面
    public function moments()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status', '');
        $sort = Request::param('sort', 'time');

        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['m.content', 'like', "%{$escapedKeyword}%"];
        }
        if ($status !== '' && $status !== null) {
            $where[] = ['m.status', '=', $status];
        }

        // 排序
        switch ($sort) {
            case 'likes':
                $order = 'm.likes desc, m.create_time desc';
                break;
            case 'comments':
                $order = 'm.comments desc, m.create_time desc';
                break;
            default:
                $order = 'm.is_top desc, m.create_time desc';
                break;
        }

        $moments = Db::name('moments')
            ->alias('m')
            ->leftJoin('user u', 'm.user_id = u.id')
            ->field('m.*, u.username, u.nickname, u.avatar')
            ->where($where)
            ->orderRaw($order)
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'moments' => $moments,
            'keyword' => $keyword,
            'status' => $status,
            'active' => 'moments',  // 设置当前激活的菜单
            'page_title' => '动态管理',
            'title' => '动态管理 - 后台管理系统',
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/content_moments');
    }

    // 删除动态
    public function deleteMoment()
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $id = $data['id'] ?? Request::param('id/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        Db::startTrans();
        try {
            $moment = Db::name('moments')->where('id', $id)->find();
            if ($moment) {
                Db::name('comments')->where('moment_id', $id)->delete();
                Db::name('likes')->where('moment_id', $id)->delete();
                Db::name('favorites')->where('moment_id', $id)->delete();
                Db::name('moments')->where('id', $id)->delete();
            }

            Db::commit();
            return json(['code' => 200, 'msg' => '删除成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }

    // 审核动态
    public function auditMoment()
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $id = $data['id'] ?? Request::param('id/d');
        $status = $data['status'] ?? Request::param('status/d', 1);

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        $result = Db::name('moments')->where('id', $id)->update(['status' => $status]);

        if ($result !== false) {
            return json(['code' => 200, 'msg' => '审核成功']);
        }

        return json(['code' => 500, 'msg' => '审核失败']);
    }

    // 举报管理页面
    public function reports()
    {
        View::assign([
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        return View::fetch('admin/content_reports');
    }

    // 获取举报数据（API）
    public function reportsData()
    {
        $page = Request::param('page', 1);
        $status = Request::param('status', '');
        $type = Request::param('type', '');
        $limit = 20;

        $where = [];
        if ($status !== '' && $status !== null) {
            $where[] = ['status', '=', $status];
        }
        if ($type) {
            // 类型转换：字符串转数字
            $typeMap = [
                'spam' => 1,
                'inappropriate' => 1,
                'harassment' => 1,
                'other' => 1
            ];
            if (isset($typeMap[$type])) {
                $where[] = ['type', '=', $typeMap[$type]];
            } else {
                $where[] = ['type', '=', intval($type)];
            }
        }

        $reports = Db::name('reports')
            ->alias('r')
            ->leftJoin('user u', 'r.reporter_id = u.id')
            ->leftJoin('moments m', 'r.moment_id = m.id')
            ->field('r.*, u.username, u.nickname, u.avatar as user_avatar, m.content as moment_content')
            ->where($where)
            ->order('r.create_time', 'desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $reports->items(),
                'total' => $reports->total()
            ]
        ]);
    }

    // 处理举报
    public function handleReport()
    {
        $id = Request::param('id/d');
        $action = Request::param('action');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        Db::startTrans();
        try {
            $report = Db::name('reports')->where('id', $id)->find();

            if ($action == 'delete_moment' && $report['moment_id']) {
                Db::name('moments')->where('id', $report['moment_id'])->delete();
                Db::name('comments')->where('moment_id', $report['moment_id'])->delete();
                Db::name('likes')->where('target_id', $report['moment_id'])->where('target_type', 1)->delete();
                Db::name('favorites')->where('target_id', $report['moment_id'])->where('target_type', 1)->delete();
            }

            Db::name('reports')->where('id', $id)->update([
                'status' => 1,
                'handle_time' => time()
            ]);

            Db::commit();
            return json(['code' => 200, 'msg' => '处理成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '处理失败：' . $e->getMessage()]);
        }
    }

    // 统计信息
    public function statistics()
    {
        $today = strtotime(date('Y-m-d'));

        $stats = [
            'total_moments' => Db::name('moments')->count(),
            'today_moments' => Db::name('moments')->where('create_time', '>=', $today)->count(),
            'total_reports' => Db::name('reports')->count(),
            'pending_reports' => Db::name('moments')->where('status', 0)->count(),
            'blocked_moments' => Db::name('moments')->where('status', -1)->count(),
        ];

        return json(['code' => 200, 'data' => $stats]);
    }

    // 话题列表页面
    public function topics()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $is_hot = Request::param('is_hot', '');
        $status = Request::param('status', '');

        $where = [];
        if ($keyword) {
            $where[] = ['name', 'like', "%{$keyword}%"];
        }
        if ($is_hot !== '' && $is_hot !== null) {
            $where[] = ['is_hot', '=', $is_hot];
        }
        if ($status !== '' && $status !== null && $status != '-1') {
            $where[] = ['status', '=', $status];
        }

        $topics = Db::name('topics')
            ->where($where)
            ->order('sort_order desc, create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'topics' => $topics,
            'keyword' => $keyword,
            'isHot' => $is_hot,
            'status' => $status,
            'admin_name' => Session::get('admin_name', '管理员'),
        ]);

        return View::fetch('admin/topic/index');
    }

    // 添加/编辑话题页面
    public function topicForm()
    {
        $id = Request::param('id', 0);
        $topic = [];

        if ($id) {
            $topic = Db::name('topics')->find($id);
        }

        View::assign([
            'topic' => $topic,
            'admin_name' => Session::get('admin_name', '管理员'),
        ]);
        return View::fetch('admin/topic/add');
    }

    // 保存话题
    public function saveTopic()
    {
        if (Request::isPost()) {
            $data = Request::param();
            $id = $data['id'] ?? 0;

            try {
                // 检查话题名称是否已存在
                $existingTopic = Db::name('topics')
                    ->where('name', $data['name'])
                    ->where('id', '<>', $id) // 排除当前话题
                    ->find();
                
                if ($existingTopic) {
                    return json(['code' => 400, 'msg' => '话题名称已存在']);
                }
                
                if ($id > 0) {
                    // 更新话题
                    Db::name('topics')->where('id', $id)->update([
                        'name' => $data['name'],
                        'description' => $data['description'],
                        'cover' => $data['cover'],
                        'is_hot' => $data['is_hot'] ?? 0,
                        'sort' => $data['sort_order'] ?? 0,
                        'status' => $data['status'] ?? 1
                    ]);
                    
                    return json(['code' => 200, 'msg' => '话题更新成功']);
                } else {
                    // 创建新话题
                    Db::name('topics')->insert([
                        'name' => $data['name'],
                        'description' => $data['description'],
                        'cover' => $data['cover'],
                        'is_hot' => $data['is_hot'] ?? 0,
                        'sort' => $data['sort_order'] ?? 0,
                        'status' => $data['status'] ?? 1,
                        'create_time' => time()
                    ]);
                    
                    return json(['code' => 200, 'msg' => '话题创建成功']);
                }
            } catch (Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 删除话题
    public function deleteTopic()
    {
        $id = Request::param('id/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            Db::startTrans();
            
            // 删除话题
            Db::name('topics')->where('id', $id)->delete();
            
            // 删除动态话题关联
            Db::name('moment_topics')->where('topic_id', $id)->delete();
            
            Db::commit();
            return json(['code' => 200, 'msg' => '话题删除成功']);
        } catch (Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }

    // 切换热门状态
    public function toggleHot()
    {
        $id = Request::param('id/d');
        $is_hot = Request::param('is_hot/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $result = Db::name('topics')->where('id', $id)->update(['is_hot' => $is_hot]);

            if ($result !== false) {
                return json(['code' => 200, 'msg' => '操作成功']);
            }

            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }

    // 切换状态
    public function toggleStatus()
    {
        $id = Request::param('id/d');
        $status = Request::param('status/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $result = Db::name('topics')->where('id', $id)->update(['status' => $status]);

            if ($result !== false) {
                return json(['code' => 200, 'msg' => '操作成功']);
            }

            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }

    // 评论列表页面
    public function comments()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            return View::fetch('admin/content/comments');
        } catch (\Exception $e) {
            return '<h1>评论管理</h1><p>欢迎，' . Session::get('admin_name', '管理员') . '</p><p>暂无数据</p>';
        }
    }

    // 审核评论
    public function auditComment()
    {
        $id = Request::param('id/d');
        $status = Request::param('status/d', 1);

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        $result = Db::name('comments')->where('id', $id)->update(['status' => $status]);

        if ($result !== false) {
            return json(['code' => 200, 'msg' => '审核成功']);
        }

        return json(['code' => 500, 'msg' => '审核失败']);
    }

    // 删除评论
    public function deleteComment()
    {
        $id = Request::param('id/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            Db::startTrans();
            
            // 删除评论
            Db::name('comments')->where('id', $id)->delete();
            
            // 删除该评论的回复
            Db::name('comments')->where('parent_id', $id)->delete();
            
            Db::commit();
            return json(['code' => 200, 'msg' => '评论删除成功']);
        } catch (Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }

    // 批量删除动态
    public function batchDeleteMoments()
    {
        if (Request::isPost()) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            $ids = $data['ids'] ?? Request::param('ids', '');
            
            if (empty($ids)) {
                return json(['code' => 400, 'msg' => '请选择要删除的动态']);
            }
            
            try {
                $idsArray = explode(',', $ids);
                
                Db::startTrans();
                
                // 删除相关评论、点赞、收藏和动态话题关联
                Db::name('comments')->whereIn('moment_id', $idsArray)->delete();
                Db::name('likes')->whereIn('target_id', $idsArray)->where('target_type', 1)->delete();
                Db::name('favorites')->whereIn('target_id', $idsArray)->where('target_type', 1)->delete();
                Db::name('moment_topics')->whereIn('moment_id', $idsArray)->delete();
                
                // 删除动态
                Db::name('moments')->whereIn('id', $idsArray)->delete();
                
                Db::commit();
                return json(['code' => 200, 'msg' => '批量删除成功']);
            } catch (Exception $e) {
                Db::rollback();
                return json(['code' => 500, 'msg' => '批量删除失败：' . $e->getMessage()]);
            }
        }
    }

    // 批量审核动态
    public function batchAuditMoments()
    {
        if (Request::isPost()) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            $ids = $data['ids'] ?? Request::param('ids', '');
            $status = $data['status'] ?? Request::param('status', 1);
            
            if (empty($ids)) {
                return json(['code' => 400, 'msg' => '请选择要审核的动态']);
            }
            
            try {
                $idsArray = explode(',', $ids);
                
                // 批量更新动态状态
                Db::name('moments')->whereIn('id', $idsArray)->update(['status' => $status]);
                
                return json(['code' => 200, 'msg' => '批量审核成功']);
            } catch (Exception $e) {
                return json(['code' => 500, 'msg' => '批量审核失败：' . $e->getMessage()]);
            }
        }
    }

    // 批量删除评论
    public function batchDeleteComments()
    {
        if (Request::isPost()) {
            $ids = Request::param('ids', '');
            
            if (empty($ids)) {
                return json(['code' => 400, 'msg' => '请选择要删除的评论']);
            }
            
            try {
                $idsArray = explode(',', $ids);
                
                Db::startTrans();
                
                // 删除评论
                Db::name('comments')->whereIn('id', $idsArray)->delete();
                
                // 删除这些评论的回复
                Db::name('comments')->whereIn('parent_id', $idsArray)->delete();
                
                Db::commit();
                return json(['code' => 200, 'msg' => '批量删除成功']);
            } catch (Exception $e) {
                Db::rollback();
                return json(['code' => 500, 'msg' => '批量删除失败：' . $e->getMessage()]);
            }
        }
    }

    // 批量审核评论
    public function batchAuditComments()
    {
        if (Request::isPost()) {
            $ids = Request::param('ids', '');
            $status = Request::param('status', 1);
            
            if (empty($ids)) {
                return json(['code' => 400, 'msg' => '请选择要审核的评论']);
            }
            
            try {
                $idsArray = explode(',', $ids);
                
                // 批量更新评论状态
                Db::name('comments')->whereIn('id', $idsArray)->update(['status' => $status]);
                
                return json(['code' => 200, 'msg' => '批量审核成功']);
            } catch (Exception $e) {
                return json(['code' => 500, 'msg' => '批量审核失败：' . $e->getMessage()]);
            }
        }
    }

    // 批量删除话题
    public function batchDeleteTopics()
    {
        if (Request::isPost()) {
            $ids = Request::param('ids', '');
            
            if (empty($ids)) {
                return json(['code' => 400, 'msg' => '请选择要删除的话题']);
            }
            
            try {
                $idsArray = explode(',', $ids);
                
                Db::startTrans();
                
                // 删除话题
                Db::name('topics')->whereIn('id', $idsArray)->delete();
                
                // 删除动态话题关联
                Db::name('moment_topics')->whereIn('topic_id', $idsArray)->delete();
                
                Db::commit();
                return json(['code' => 200, 'msg' => '批量删除成功']);
            } catch (Exception $e) {
                Db::rollback();
                return json(['code' => 500, 'msg' => '批量删除失败：' . $e->getMessage()]);
            }
        }
    }

    // 置顶/取消置顶动态
    public function toggleTop()
    {
        if (Request::isPost()) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            $id = $data['id'] ?? Request::param('id/d');
            $isTop = $data['isTop'] ?? Request::param('isTop/d', 0);

            if (!$id) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }

            $result = Db::name('moments')->where('id', $id)->update(['is_top' => $isTop]);

            if ($result !== false) {
                return json(['code' => 200, 'msg' => $isTop ? '置顶成功' : '取消置顶成功']);
            }

            return json(['code' => 500, 'msg' => '操作失败']);
        }
    }

    // 批量置顶动态
    public function batchTopMoments()
    {
        if (Request::isPost()) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            $ids = $data['ids'] ?? Request::param('ids', '');
            
            if (empty($ids)) {
                return json(['code' => 400, 'msg' => '请选择要置顶的动态']);
            }
            
            try {
                $idsArray = explode(',', $ids);
                
                // 批量置顶动态
                Db::name('moments')->whereIn('id', $idsArray)->update(['is_top' => 1]);
                
                return json(['code' => 200, 'msg' => '批量置顶成功']);
            } catch (Exception $e) {
                return json(['code' => 500, 'msg' => '批量置顶失败：' . $e->getMessage()]);
            }
        }
    }

    // 批量取消置顶动态
    public function batchCancelTopMoments()
    {
        if (Request::isPost()) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            $ids = $data['ids'] ?? Request::param('ids', '');
            
            if (empty($ids)) {
                return json(['code' => 400, 'msg' => '请选择要取消置顶的动态']);
            }
            
            try {
                $idsArray = explode(',', $ids);
                
                // 批量取消置顶
                Db::name('moments')->whereIn('id', $idsArray)->update(['is_top' => 0]);
                
                return json(['code' => 200, 'msg' => '取消置顶成功']);
            } catch (Exception $e) {
                return json(['code' => 500, 'msg' => '取消置顶失败：' . $e->getMessage()]);
            }
        }
    }

    /**
     * 评论统计数据
     */
    public function commentStatistics()
    {
        try {
            \think\facade\Log::info('Content commentStatistics called');

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

            \think\facade\Log::info('Content commentStatistics result', $data);

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $data
            ]);
        } catch (Exception $e) {
            \think\facade\Log::error('Content commentStatistics error', ['error' => $e->getMessage()]);
            return json(['code' => 500, 'msg' => '获取失败：' . $e->getMessage()]);
        }
    }
}
