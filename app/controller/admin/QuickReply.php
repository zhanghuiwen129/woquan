<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class QuickReply extends AdminController
{
    
    public function index()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            return View::fetch('admin/quick-reply/index');
        } catch (\Exception $e) {
            return '<h1>快捷回复列表</h1><p>欢迎，' . Session::get('admin_name', '管理员') . '</p><p>暂无数据</p>';
        }
    }

    public function list()
    {
        try {
            $page = Request::get('page', 1);
            $limit = Request::get('limit', 20);
            $keyword = Request::get('keyword', '');

            $where = [];
            if ($keyword) {
                $escapedKeyword = Db::escape($keyword);
                $where[] = ['title|content', 'like', "%{$escapedKeyword}%"];
            }

            $tableExists = Db::query("SHOW TABLES LIKE 'quick_replies'");

            if (!$tableExists) {
                return json([
                    'code' => 200,
                    'msg' => '暂无数据',
                    'data' => [],
                    'total' => 0
                ]);
            }

            $total = Db::name('quick_replies')->where($where)->count();

            $replies = Db::name('quick_replies')
                ->where($where)
                ->order('create_time', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            foreach ($replies as &$reply) {
                $reply['create_time'] = isset($reply['create_time']) ? date('Y-m-d H:i:s', $reply['create_time']) : '-';
                $reply['update_time'] = isset($reply['update_time']) ? date('Y-m-d H:i:s', $reply['update_time']) : '-';
            }

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $replies,
                'total' => $total
            ]);
        } catch (\Exception $e) {
            return $this->error('获取失败，请稍后重试');
        }
    }

    public function templates()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            return View::fetch('admin/quick-reply/templates');
        } catch (\Exception $e) {
            return '<h1>回复模板</h1><p>欢迎，' . Session::get('admin_name', '管理员') . '</p><p>暂无数据</p>';
        }
    }
}
