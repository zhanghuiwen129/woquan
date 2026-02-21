<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Faq extends AdminController
{
    
    public function index()
    {
        try {
            $page = Request::param('page', 1);
            $limit = Request::param('limit', 20);
            $keyword = Request::param('keyword', '');
            $categoryId = Request::param('category_id/d', 0);

            $where = [];
            if ($keyword) {
                $escapedKeyword = Db::escape($keyword);
                $where[] = ['question', 'like', "%{$escapedKeyword}%"];
            }
            if ($categoryId > 0) {
                $where[] = ['category_id', '=', $categoryId];
            }

            $faqs = Db::name('faqs')
                ->where($where)
                ->order('sort_order asc, create_time desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);

            $categories = Db::name('faq_categories')->select();

            if (Request::isAjax()) {
                return json([
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => [
                        'list' => $faqs->items(),
                        'total' => $faqs->total()
                    ]
                ]);
            }

            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员'),
                'faqs' => $faqs,
                'categories' => $categories,
                'keyword' => $keyword,
                'categoryId' => $categoryId,
            ]);

            return View::fetch('admin/faq/index');
        } catch (\Exception $e) {
            if (Request::isAjax()) {
                return json([
                    'code' => 500,
                    'msg' => '获取FAQ列表失败: ' . $e->getMessage()
                ]);
            }
            return '<h1>FAQ管理</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }

    public function add()
    {
        try {
            $categories = Db::name('faq_categories')
                ->where('status', 1)
                ->order('sort_order asc')
                ->select();

            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员'),
                'categories' => $categories
            ]);

            return View::fetch('admin/faq/add');
        } catch (\Exception $e) {
            return '<h1>添加FAQ</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }

    public function save()
    {
        $data = Request::post();
        $data['create_time'] = time();
        $data['update_time'] = time();

        try {
            $faqId = Db::name('faqs')->insertGetId($data);

            if ($faqId) {
                return json(['code' => 200, 'msg' => '添加成功']);
            }

            return json(['code' => 500, 'msg' => '添加失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '添加失败：' . $e->getMessage()]);
        }
    }

    public function edit()
    {
        try {
            $id = Request::param('id/d');

            if (!$id) {
                return redirect('/admin/faq');
            }

            $faq = Db::name('faqs')->where('id', $id)->find();
            if (!$faq) {
                return redirect('/admin/faq');
            }

            $categories = Db::name('faq_categories')
                ->where('status', 1)
                ->order('sort_order asc')
                ->select();

            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员'),
                'faq' => $faq,
                'categories' => $categories
            ]);

            return View::fetch('admin/faq/edit');
        } catch (\Exception $e) {
            return '<h1>编辑FAQ</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }

    public function update()
    {
        $id = Request::param('id/d');
        $data = Request::post();
        $data['update_time'] = time();

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $result = Db::name('faqs')->where('id', $id)->update($data);

            if ($result !== false) {
                return json(['code' => 200, 'msg' => '更新成功']);
            }

            return json(['code' => 500, 'msg' => '更新失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '更新失败：' . $e->getMessage()]);
        }
    }

    public function delete()
    {
        $id = Request::param('id/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $result = Db::name('faqs')->delete($id);

            if ($result) {
                return json(['code' => 200, 'msg' => '删除成功']);
            } else {
                return json(['code' => 500, 'msg' => '删除失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除失败']);
        }
    }

    public function categories()
    {
        try {
            $categories = Db::name('faq_categories')
                ->order('sort_order asc, create_time desc')
                ->select();

            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员'),
                'categories' => $categories
            ]);

            return View::fetch('admin/faq/categories');
        } catch (\Exception $e) {
            return '<h1>FAQ分类管理</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }

    public function addCategory()
    {
        try {
            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员')
            ]);

            return View::fetch('admin/faq/add_category');
        } catch (\Exception $e) {
            return '<h1>添加FAQ分类</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }

    public function saveCategory()
    {
        $data = Request::post();
        $data['create_time'] = time();
        $data['update_time'] = time();

        try {
            $categoryId = Db::name('faq_categories')->insertGetId($data);

            if ($categoryId) {
                return json(['code' => 200, 'msg' => '添加成功']);
            }

            return json(['code' => 500, 'msg' => '添加失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '添加失败：' . $e->getMessage()]);
        }
    }

    public function editCategory()
    {
        try {
            $id = Request::param('id/d');

            if (!$id) {
                return redirect('/admin/faq/categories');
            }

            $category = Db::name('faq_categories')->where('id', $id)->find();
            if (!$category) {
                return redirect('/admin/faq/categories');
            }

            View::assign([
                'admin_username' => Session::get('admin_username'),
                'admin_name' => Session::get('admin_username', '管理员'),
                'category' => $category
            ]);

            return View::fetch('admin/faq/edit_category');
        } catch (\Exception $e) {
            return '<h1>编辑FAQ分类</h1><p>错误: ' . $e->getMessage() . '</p>';
        }
    }

    public function updateCategory()
    {
        $id = Request::param('id/d');
        $data = Request::post();
        $data['update_time'] = time();

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $result = Db::name('faq_categories')->where('id', $id)->update($data);

            if ($result !== false) {
                return json(['code' => 200, 'msg' => '更新成功']);
            }

            return json(['code' => 500, 'msg' => '更新失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '更新失败：' . $e->getMessage()]);
        }
    }

    public function deleteCategory()
    {
        $id = Request::param('id/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $result = Db::name('faq_categories')->delete($id);

            if ($result) {
                return json(['code' => 200, 'msg' => '删除成功']);
            } else {
                return json(['code' => 500, 'msg' => '删除失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除失败']);
        }
    }
}
