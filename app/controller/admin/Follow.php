<?php
declare (strict_types = 1);

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;
use think\facade\Session;

/**
 * 后台关注管理控制器
 */
class Follow extends AdminController
{
    /**
     * 关注列表
     */
    public function index()
    {
        // 获取筛选参数
        $keyword = input('get.keyword', '');
        $status = input('get.status', '-1');
        $page = input('get.page', 1);
        $pageSize = input('get.page_size', 20);

        try {
            // 构建查询
            $query = Db::name('follows')
                ->alias('f')
                ->join('user u1', 'f.follower_id = u1.id', 'LEFT')
                ->join('user u2', 'f.following_id = u2.id', 'LEFT')
                ->field('f.*, u1.nickname as user_nickname, u1.avatar as user_avatar, u1.id as user_id, u2.nickname as friend_nickname, u2.avatar as friend_avatar, u2.id as friend_id');

            // 关键词搜索
            if ($keyword) {
                $escapedKeyword = Db::escape($keyword);
                $query->where('u1.nickname|u2.nickname', 'like', '%' . $escapedKeyword . '%');
            }

            // 状态筛选
            if ($status !== '-1') {
                $query->where('f.status', $status);
            }

            // 分页查询
            $follows = $query->order('f.create_time', 'desc')
                ->paginate([
                    'list_rows' => $pageSize,
                    'page' => $page
                ]);

            View::assign([
                'follows' => $follows,
                'keyword' => $keyword,
                'status' => $status,
                'admin_name' => Session::get('admin_name', '管理员')
            ]);

            return View::fetch('admin/follow/index');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * 删除关注关系
     */
    public function delete()
    {
        $id = input('post.id');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        Db::name('follows')->where('id', $id)->delete();

        return json(['code' => 200, 'msg' => '删除成功']);
    }

    /**
     * 批量删除
     */
    public function batchDelete()
    {
        $ids = input('post.ids/a', []);

        if (empty($ids)) {
            return json(['code' => 400, 'msg' => '请选择要删除的数据']);
        }

        Db::name('follows')->where('id', 'in', $ids)->delete();

        return json(['code' => 200, 'msg' => '批量删除成功']);
    }

    /**
     * 更新状态
     */
    public function updateStatus()
    {
        $id = input('post.id');
        $status = input('post.status');

        if (!$id || $status === null) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        Db::name('follows')->where('id', $id)->update(['status' => $status]);

        return json(['code' => 200, 'msg' => '更新成功']);
    }
}
