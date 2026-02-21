<?php
declare (strict_types = 1);

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;
use think\facade\Session;

/**
 * 后台点赞管理控制器
 */
class Like extends AdminController
{
    /**
     * 点赞列表
     */
    public function index()
    {
        $keyword = input('get.keyword', '');
        $targetType = input('get.target_type', '-1');
        $page = input('get.page', 1);
        $pageSize = input('get.page_size', 20);

        try {
            $query = Db::name('likes');

            if ($targetType !== '-1') {
                $query->where('target_type', $targetType);
            }

            if ($keyword) {
                $query->whereLike('user.nickname', '%' . $keyword . '%');
            }

            $likes = $query->alias('l')
                ->leftJoin('user u', 'l.user_id = u.id')
                ->field('l.*, u.nickname as user_nickname, u.avatar as user_avatar')
                ->order('l.create_time', 'desc')
                ->paginate([
                    'list_rows' => $pageSize,
                    'page' => $page
                ]);

            View::assign([
                'likes' => $likes,
                'keyword' => $keyword,
                'target_type' => $targetType,
                'admin_name' => Session::get('admin_name', '管理员')
            ]);

            return View::fetch('admin/like/index');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . ' Line: ' . $e->getLine();
        }
    }

    /**
     * 删除点赞记录
     */
    public function delete()
    {
        $id = input('post.id');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        Db::name('likes')->where('id', $id)->delete();

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

        Db::name('likes')->where('id', 'in', $ids)->delete();

        return json(['code' => 200, 'msg' => '批量删除成功']);
    }

    /**
     * 点赞统计
     */
    public function statistics()
    {
        // 动态点赞统计
        $momentLikes = Db::name('likes')
            ->where('target_type', 1)
            ->count();

        // 评论点赞统计
        $commentLikes = Db::name('likes')
            ->where('target_type', 2)
            ->count();

        // 总点赞数
        $totalLikes = $momentLikes + $commentLikes;

        // 今日点赞数
        $todayLikes = Db::name('likes')
            ->whereTime('create_time', 'today')
            ->count();

        // 本周点赞数
        $weekLikes = Db::name('likes')
            ->whereTime('create_time', 'week')
            ->count();

        return json([
            'code' => 200,
            'msg' => '获取成功',
            'data' => [
                'moment_likes' => $momentLikes,
                'comment_likes' => $commentLikes,
                'total_likes' => $totalLikes,
                'today_likes' => $todayLikes,
                'week_likes' => $weekLikes
            ]
        ]);
    }
}
