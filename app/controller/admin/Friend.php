<?php
declare (strict_types = 1);

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;
use think\facade\Session;

/**
 * 后台好友管理控制器
 */
class Friend extends AdminController
{
    /**
     * 好友列表
     */
    public function index()
    {
        // 获取筛选参数
        $keyword = input('get.keyword', '');
        $page = input('get.page', 1);
        $pageSize = input('get.page_size', 20);

        try {
            // 构建查询 - 查询双向关注的用户（互相关注即为好友）
            $query = Db::name('follows')
                ->alias('f')
                ->join('user u1', 'f.follower_id = u1.id', 'LEFT')
                ->join('user u2', 'f.following_id = u2.id', 'LEFT')
                ->join('follows f2', 'f.follower_id = f2.following_id AND f.following_id = f2.follower_id AND f2.status = 1', 'LEFT')
                ->where('f.status', 1)
                ->where('f2.id', '<>', null)
                ->field('f.*, u1.nickname as user_nickname, u1.avatar as user_avatar, u2.nickname as friend_nickname, u2.avatar as friend_avatar, u1.id as user_id, u2.id as friend_id');

            // 关键词搜索
            if ($keyword) {
                $escapedKeyword = Db::escape($keyword);
                $query->where('u1.nickname|u2.nickname', 'like', '%' . $escapedKeyword . '%');
            }

            // 分页查询
            $friends = $query->order('f.create_time', 'desc')
                ->group('f.id')
                ->paginate([
                    'list_rows' => $pageSize,
                    'page' => $page
                ]);

            View::assign([
                'friends' => $friends,
                'keyword' => $keyword,
                'admin_name' => Session::get('admin_name', '管理员')
            ]);

            return View::fetch('admin/friend/index');
        } catch (\Exception $e) {
            // 输出错误信息用于调试
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * 删除好友关系（删除双向关注）
     */
    public function delete()
    {
        $id = input('post.id');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        // 获取关注关系
        $follow = Db::name('follows')->where('id', $id)->find();

        if ($follow) {
            // 删除双向关注
            Db::name('follows')->where('id', $id)->delete();
            Db::name('follows')->where([
                'follower_id' => $follow['following_id'],
                'following_id' => $follow['follower_id']
            ])->delete();
        }

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

        // 批量删除
        foreach ($ids as $id) {
            $follow = Db::name('follows')->where('id', $id)->find();
            if ($follow) {
                Db::name('follows')->where('id', $id)->delete();
                Db::name('follows')->where([
                    'follower_id' => $follow['following_id'],
                    'following_id' => $follow['follower_id']
                ])->delete();
            }
        }

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
