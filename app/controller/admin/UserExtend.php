<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Session;
use think\facade\Request;
use think\facade\Db;
use think\facade\Redirect;

/**
 * 用户管理扩展控制器（预留扩展功能）
 */
class UserExtend extends AdminController
{
    // 需要管理员认证
    public function initialize()
    {
        parent::initialize();
        if (!Session::has('admin_id')) {
            return Redirect::to('/admin/login');
        }
    }

    // 用户标签体系扩展
    public function userTags()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status', '');

        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['name', 'like', "%{$escapedKeyword}%"];
        }
        if ($status !== '' && $status !== null) {
            $where[] = ['status', '=', $status];
        }

        $tags = Db::name('user_tags')
            ->where($where)
            ->order('id desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'tags' => $tags,
            'keyword' => $keyword,
            'status' => $status,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/userextend_tags');
    }

    // 保存用户标签
    public function saveUserTag()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            $name = Request::param('name', '');
            $color = Request::param('color', '#3498db');
            $description = Request::param('description', '');
            $status = Request::param('status', 1);
            
            try {
                if (empty($name)) {
                    return json(['code' => 400, 'msg' => '标签名称不能为空']);
                }
                
                $data = [
                    'name' => $name,
                    'color' => $color,
                    'description' => $description,
                    'status' => $status,
                    'update_time' => time()
                ];
                
                if ($id > 0) {
                    // 更新标签
                    Db::name('user_tags')->where('id', $id)->update($data);
                    $msg = '标签更新成功';
                } else {
                    // 创建标签
                    $data['create_time'] = time();
                    Db::name('user_tags')->insert($data);
                    $msg = '标签创建成功';
                }
                
                return json(['code' => 200, 'msg' => $msg]);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 删除用户标签
    public function deleteUserTag()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                // 删除标签
                Db::name('user_tags')->where('id', $id)->delete();
                // 删除用户标签关联
                Db::name('user_tag_relation')->where('tag_id', $id)->delete();
                
                return json(['code' => 200, 'msg' => '标签删除成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 分配用户标签
    public function assignUserTag()
    {
        if (Request::isPost()) {
            $userId = Request::param('user_id', 0);
            $tagIds = Request::param('tag_ids', '');
            
            if (empty($userId)) {
                return json(['code' => 400, 'msg' => '用户ID不能为空']);
            }
            
            try {
                // 删除原有标签关联
                Db::name('user_tag_relation')->where('user_id', $userId)->delete();
                
                // 添加新的标签关联
                if (!empty($tagIds)) {
                    $tagIdsArray = explode(',', $tagIds);
                    $data = [];
                    foreach ($tagIdsArray as $tagId) {
                        $data[] = [
                            'user_id' => $userId,
                            'tag_id' => $tagId,
                            'create_time' => time()
                        ];
                    }
                    Db::name('user_tag_relation')->insertAll($data);
                }
                
                return json(['code' => 200, 'msg' => '标签分配成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 用户行为数据留存接口
    public function userBehavior()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $userId = Request::param('user_id', 0);
        $action = Request::param('action', '');
        $startTime = Request::param('start_time', '');
        $endTime = Request::param('end_time', '');

        $where = [];
        if ($userId) {
            $where[] = ['user_id', '=', $userId];
        }
        if ($action) {
            $where[] = ['action', '=', $action];
        }
        if ($startTime) {
            $where[] = ['create_time', '>=', strtotime($startTime)];
        }
        if ($endTime) {
            $where[] = ['create_time', '<=', strtotime($endTime) + 86399];
        }

        // 预留的用户行为数据接口，实际项目中可能需要创建user_behavior表
        $behaviors = [];
        $total = 0;
        
        // 这里只是模拟数据，实际项目中需要从数据库中查询
        if (false) {
            $behaviors = Db::name('user_behavior')
                ->alias('ub')
                ->leftJoin('user u', 'ub.user_id = u.id')
                ->field('ub.*, u.username, u.nickname')
                ->where($where)
                ->order('ub.create_time desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);
        }

        View::assign([
            'behaviors' => $behaviors,
            'user_id' => $userId,
            'action' => $action,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/userextend_behavior');
    }

    // 导出用户行为数据
    public function exportUserBehavior()
    {
        // 预留的导出用户行为数据接口
        return json(['code' => 200, 'msg' => '功能开发中']);
    }

    // 用户行为统计
    public function userBehaviorStatistics()
    {
        // 预留的用户行为统计接口
        $statistics = [
            'total_visits' => 0,
            'total_actions' => 0,
            'most_active_users' => [],
            'most_common_actions' => []
        ];
        
        return json(['code' => 200, 'data' => $statistics]);
    }
}
