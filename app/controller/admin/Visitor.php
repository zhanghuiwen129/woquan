<?php
declare (strict_types = 1);

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;
use think\facade\Session;

/**
 * 后台访客管理控制器
 */
class Visitor extends AdminController
{
    /**
     * 访客列表
     */
    public function index()
    {
        // 获取筛选参数
        $keyword = input('get.keyword', '');
        $page = input('get.page', 1);
        $pageSize = input('get.page_size', 20);

        // 构建查询
        $query = Db::name('visitors')
            ->alias('v')
            ->join('user u1', 'v.user_id = u1.id', 'LEFT')
            ->join('user u2', 'v.visitor_id = u2.id', 'LEFT')
            ->field('v.*, u1.nickname as user_nickname, u1.avatar as user_avatar, u2.nickname as visitor_nickname, u2.avatar as visitor_avatar');

        // 关键词搜索
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $query->where('u1.nickname|u2.nickname', 'like', '%' . $escapedKeyword . '%');
        }

        // 分页查询
        $visitors = $query->order('v.last_visit_time', 'desc')
            ->paginate([
                'list_rows' => $pageSize,
                'page' => $page
            ]);

        View::assign([
            'visitors' => $visitors,
            'keyword' => $keyword,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/visitor/index');
    }

    /**
     * 删除访客记录
     */
    public function delete()
    {
        $id = input('post.id');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        Db::name('visitors')->where('id', $id)->delete();

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

        Db::name('visitors')->where('id', 'in', $ids)->delete();

        return json(['code' => 200, 'msg' => '批量删除成功']);
    }

    /**
     * 清空访客记录
     */
    public function clear()
    {
        Db::name('visitors')->delete(true);

        return json(['code' => 200, 'msg' => '清空成功']);
    }

    /**
     * 访客统计
     */
    public function statistics()
    {
        // 总访客数
        $totalVisitors = Db::name('visitors')->count();

        // 今日访客数
        $todayVisitors = Db::name('visitors')
            ->whereTime('last_visit_time', 'today')
            ->count();

        // 本周访客数
        $weekVisitors = Db::name('visitors')
            ->whereTime('last_visit_time', 'week')
            ->count();

        // 本月访客数
        $monthVisitors = Db::name('visitors')
            ->whereTime('last_visit_time', 'month')
            ->count();

        return json([
            'code' => 200,
            'msg' => '获取成功',
            'data' => [
                'total_visitors' => $totalVisitors,
                'today_visitors' => $todayVisitors,
                'week_visitors' => $weekVisitors,
                'month_visitors' => $monthVisitors
            ]
        ]);
    }
}
