<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Card extends AdminController
{
    
    public function index()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            return View::fetch('admin/card/index');
        } catch (\Exception $e) {
            return '<h1>名片列表</h1><p>欢迎，' . Session::get('admin_name', '管理员') . '</p><p>暂无数据</p>';
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
                $where[] = ['u.username|u.nickname', 'like', "%{$escapedKeyword}%"];
            }

            // 检查表是否存在
            $tableExists = Db::query("SHOW TABLES LIKE 'user_cards'");

            if (!$tableExists) {
                return json([
                    'code' => 200,
                    'msg' => '暂无数据',
                    'data' => [],
                    'total' => 0
                ]);
            }

            $total = Db::name('user_cards')->alias('c')
                ->leftJoin('user u', 'c.user_id = u.id')
                ->where($where)
                ->count();

            $cards = Db::name('user_cards')
                ->alias('c')
                ->leftJoin('user u', 'c.user_id = u.id')
                ->field('c.*, u.username, u.nickname, u.avatar')
                ->where($where)
                ->order('c.create_time', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            // 格式化时间
            foreach ($cards as &$card) {
                $card['create_time'] = isset($card['create_time']) ? date('Y-m-d H:i:s', $card['create_time']) : '-';
                $card['update_time'] = isset($card['update_time']) ? date('Y-m-d H:i:s', $card['update_time']) : '-';
            }

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $cards,
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

    public function templates()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            return View::fetch('admin/card/templates');
        } catch (\Exception $e) {
            return '<h1>名片模板</h1><p>欢迎，' . Session::get('admin_name', '管理员') . '</p><p>暂无数据</p>';
        }
    }
}
