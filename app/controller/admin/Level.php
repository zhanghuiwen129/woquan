<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Level extends AdminController
{
    
    public function index()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_username', '管理员'),
                'page_title' => '等级管理'
            ]);
            return View::fetch('admin/level/index');
        } catch (\Exception $e) {
            return '<h1>等级列表</h1><p>欢迎，' . Session::get('admin_username', '管理员') . '</p><p>暂无数据</p>';
        }
    }

    public function privileges()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            return View::fetch('admin/level/privileges');
        } catch (\Exception $e) {
            return '<h1>等级特权</h1><p>欢迎，' . Session::get('admin_name', '管理员') . '</p><p>暂无数据</p>';
        }
    }

    public function list()
    {
        try {
            $levels = Db::name('user_level')
                ->field('id, level, name, required_points, icon, status')
                ->order('level', 'asc')
                ->select();

            if (empty($levels)) {
                $this->initDefaultLevels();
                $levels = Db::name('user_level')
                    ->field('id, level, name, required_points, icon, status')
                    ->order('level', 'asc')
                    ->select();
            }

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $levels
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败：' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    public function save()
    {
        try {
            $data = Request::post();
            $id = $data['id'] ?? 0;

            if ($id) {
                Db::name('user_level')->where('id', $id)->update([
                    'name' => $data['name'],
                    'icon' => $data['icon'] ?? '',
                    'required_points' => $data['points'] ?? 0,
                    'status' => $data['status'] ?? 1,
                    'update_time' => time()
                ]);
                $msg = '更新成功';
            } else {
                $maxLevel = Db::name('user_level')->max('level') ?? 0;
                Db::name('user_level')->insert([
                    'level' => $maxLevel + 1,
                    'name' => $data['name'],
                    'icon' => $data['icon'] ?? '',
                    'required_points' => $data['points'] ?? 0,
                    'status' => $data['status'] ?? 1,
                    'description' => '',
                    'privileges' => json_encode([]),
                    'create_time' => time(),
                    'update_time' => time()
                ]);
                $msg = '添加成功';
            }

            return json([
                'code' => 200,
                'msg' => $msg
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '保存失败：' . $e->getMessage()
            ]);
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

            Db::name('user_level')->where('id', $id)->delete();

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

    private function initDefaultLevels()
    {
        $defaultLevels = [
            [
                'level' => 1,
                'name' => '新手',
                'required_points' => 0,
                'icon' => '1',
                'description' => '新注册用户',
                'privileges' => json_encode(['可以发布动态', '可以关注用户', '可以评论']),
                'status' => 1,
                'create_time' => time(),
                'update_time' => time()
            ],
            [
                'level' => 2,
                'name' => '初级用户',
                'required_points' => 100,
                'icon' => '2',
                'description' => '活跃参与社区',
                'privileges' => json_encode(['可以发布动态', '可以关注用户', '可以评论', '可以点赞']),
                'status' => 1,
                'create_time' => time(),
                'update_time' => time()
            ],
            [
                'level' => 3,
                'name' => '中级用户',
                'required_points' => 500,
                'icon' => '3',
                'description' => '社区活跃成员',
                'privileges' => json_encode(['可以发布动态', '可以关注用户', '可以评论', '可以点赞', '可以收藏']),
                'status' => 1,
                'create_time' => time(),
                'update_time' => time()
            ],
            [
                'level' => 4,
                'name' => '高级用户',
                'required_points' => 2000,
                'icon' => '4',
                'description' => '社区核心成员',
                'privileges' => json_encode(['可以发布动态', '可以关注用户', '可以评论', '可以点赞', '可以收藏', '可以创建话题']),
                'status' => 1,
                'create_time' => time(),
                'update_time' => time()
            ],
            [
                'level' => 5,
                'name' => '资深用户',
                'required_points' => 5000,
                'icon' => '5',
                'description' => '社区资深成员',
                'privileges' => json_encode(['可以发布动态', '可以关注用户', '可以评论', '可以点赞', '可以收藏', '可以创建话题', '可以创建群组']),
                'status' => 1,
                'create_time' => time(),
                'update_time' => time()
            ]
        ];

        foreach ($defaultLevels as $level) {
            Db::name('user_level')->insert($level);
        }
    }
}
