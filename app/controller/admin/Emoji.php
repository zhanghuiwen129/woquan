<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Emoji extends AdminController
{
    
    public function index()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            return View::fetch('admin/emoji/index');
        } catch (\Exception $e) {
            return '<h1>表情列表</h1><p>欢迎，' . Session::get('admin_name', '管理员') . '</p><p>暂无数据</p>';
        }
    }

    public function list()
    {
        try {
            $page = Request::param('page', 1);
            $limit = Request::param('limit', 20);
            $keyword = Request::param('keyword', '');
            $category = Request::param('category', '');

            $where = [];
            if ($keyword) {
                $escapedKeyword = Db::escape($keyword);
                $where[] = ['name', 'like', "%{$escapedKeyword}%"];
            }
            if ($category) {
                $where[] = ['category', '=', $category];
            }

            $emojis = Db::name('emojis')
                ->where($where)
                ->order('create_time desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $emojis->items(),
                'total' => $emojis->total()
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }

    public function save()
    {
        try {
            $data = Request::post();
            
            $emojiData = [
                'name' => $data['name'] ?? '',
                'url' => $data['image'] ?? '',
                'category' => $data['category'] ?? '',
                'status' => $data['status'] ?? 1,
                'type' => 'default',
                'user_id' => 0,
                'create_time' => time()
            ];

            if (!empty($data['id'])) {
                $emojiData['id'] = $data['id'];
                unset($emojiData['create_time']);
                Db::name('emojis')->update($emojiData);
            } else {
                Db::name('emojis')->insert($emojiData);
            }

            return json([
                'code' => 200,
                'msg' => '保存成功'
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '保存失败: ' . $e->getMessage()
            ]);
        }
    }

    public function delete()
    {
        try {
            $id = Request::param('id');
            
            if (empty($id)) {
                return json([
                    'code' => 400,
                    'msg' => '参数错误'
                ]);
            }

            Db::name('emojis')->where('id', $id)->delete();

            return json([
                'code' => 200,
                'msg' => '删除成功'
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '删除失败: ' . $e->getMessage()
            ]);
        }
    }

    public function categories()
    {
        try {
            View::assign([
                'admin_name' => Session::get('admin_name', '管理员')
            ]);
            return View::fetch('admin/emoji/categories');
        } catch (\Exception $e) {
            return '<h1>表情分类</h1><p>欢迎，' . Session::get('admin_name', '管理员') . '</p><p>暂无数据</p>';
        }
    }
}
