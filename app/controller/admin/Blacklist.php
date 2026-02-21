<?php
declare (strict_types = 1);

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;
use think\facade\Session;

/**
 * 后台黑名单管理控制器
 */
class Blacklist extends AdminController
{
    /**
     * 黑名单列表
     */
    public function index()
    {
        // 获取筛选参数
        $keyword = input('get.keyword', '');
        $page = input('get.page', 1);
        $pageSize = input('get.page_size', 20);

        // 构建查询
        $query = Db::name('blacklist')
            ->alias('b')
            ->join('user u1', 'b.user_id = u1.id', 'LEFT')
            ->join('user u2', 'b.block_id = u2.id', 'LEFT')
            ->field('b.*, u1.nickname as user_nickname, u1.avatar as user_avatar, u2.nickname as block_nickname, u2.avatar as block_avatar');

        // 关键词搜索
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $query->where('u1.nickname|u2.nickname', 'like', '%' . $escapedKeyword . '%');
        }

        // 分页查询
        $blacklists = $query->order('b.create_time', 'desc')
            ->paginate([
                'list_rows' => $pageSize,
                'page' => $page
            ]);

        View::assign([
            'blacklists' => $blacklists,
            'keyword' => $keyword,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/blacklist/index');
    }

    /**
     * 删除黑名单记录
     */
    public function delete()
    {
        $id = input('post.id');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        Db::name('blacklist')->where('id', $id)->delete();

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

        Db::name('blacklist')->where('id', 'in', $ids)->delete();

        return json(['code' => 200, 'msg' => '批量删除成功']);
    }

    /**
     * 解封用户（从黑名单中移除）
     */
    public function unblock()
    {
        $userId = input('post.user_id');
        $blockId = input('post.block_id');

        if (!$userId || !$blockId) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        Db::name('blacklist')->where([
            'user_id' => $userId,
            'block_id' => $blockId
        ])->delete();

        return json(['code' => 200, 'msg' => '解封成功']);
    }
}
