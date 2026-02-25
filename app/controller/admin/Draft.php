<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Draft extends AdminController
{
    
    public function index()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_username', '管理员'),
                'page_title' => '草稿管理'
            ]);
            return View::fetch('admin/draft/index');
        } catch (\Exception $e) {
            return '<h1>草稿列表</h1><p>欢迎，' . Session::get('admin_username', '管理员') . '</p><p>暂无数据</p>';
        }
    }

    public function list()
    {
        try {
            $page = Request::get('page', 1);
            $limit = Request::get('limit', 20);
            $keyword = Request::get('keyword', '');

            $where = [];
            if (!empty($keyword)) {
                $escapedKeyword = Db::escape($keyword);
                $where[] = ['content', 'like', '%' . $escapedKeyword . '%'];
            }

            $total = Db::name('moment_drafts')->where($where)->count();
            $drafts = Db::name('moment_drafts')
                ->where($where)
                ->order('updated_time', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            foreach ($drafts as &$draft) {
                $draft['username'] = $draft['nickname'] ?? '未知用户';
                $draft['title'] = mb_substr($draft['content'] ?? '', 0, 50) . (mb_strlen($draft['content'] ?? '') > 50 ? '...' : '');
                $draft['update_time'] = date('Y-m-d H:i:s', $draft['updated_time']);
            }

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $drafts,
                'total' => $total
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败：' . $e->getMessage(),
                'data' => [],
                'total' => 0
            ]);
        }
    }

    public function statistics()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_username', '管理员'),
                'page_title' => '草稿统计'
            ]);
            return View::fetch('admin/draft/statistics');
        } catch (\Exception $e) {
            return '<h1>草稿统计</h1><p>欢迎，' . Session::get('admin_username', '管理员') . '</p><p>暂无数据</p>';
        }
    }

    public function delete()
    {
        try {
            $id = Request::get('id');
            if (!$id) {
                return json([
                    'code' => 400,
                    'msg' => '参数错误'
                ]);
            }

            Db::name('moment_drafts')->where('id', $id)->delete();

            return json([
                'code' => 200,
                'msg' => '删除成功'
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '删除失败：' . $e->getMessage()
            ]);
        }
    }
}
