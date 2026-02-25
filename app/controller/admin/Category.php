<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Category extends AdminController
{
    
    public function index()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status/d', -1);
        
        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['name', 'like', "%{$escapedKeyword}%"];
        }
        if ($status >= 0) {
            $where[] = ['status', '=', $status];
        }
        
        $categories = Db::name('categories')
            ->where($where)
            ->order('sort_order ASC, create_time DESC')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'categories' => $categories,
            'keyword' => $keyword,
            'status' => $status,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/category/index');
    }
    
    public function add()
    {
        View::assign([
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        return View::fetch('admin/category/add');
    }
    
    public function save()
    {
        $data = Request::only([
            'name', 'description', 'icon', 'sort_order', 'status'
        ]);
        
        if (empty($data['name'])) {
            return json(['code' => 400, 'msg' => '请输入分类名称']);
        }
        
        try {
            $categoryData = [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'icon' => $data['icon'] ?? '',
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => $data['status'] ?? 1,
                'create_time' => time()
            ];
            
            $categoryId = Db::name('categories')->insertGetId($categoryData);
            
            if ($categoryId) {
                return json(['code' => 200, 'msg' => '添加成功']);
            }
            
            return json(['code' => 500, 'msg' => '添加失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '添加失败：' . $e->getMessage()]);
        }
    }
    
    public function edit()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return redirect('/admin/category');
        }
        
        $category = Db::name('categories')->where('id', $id)->find();
        if (!$category) {
            return redirect('/admin/category');
        }
        
        View::assign('category', $category);
        return View::fetch('admin/category/edit');
    }
    
    public function update()
    {
        $id = Request::param('id/d');
        $data = Request::only([
            'name', 'description', 'icon', 'sort_order', 'status'
        ]);
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        if (empty($data['name'])) {
            return json(['code' => 400, 'msg' => '请输入分类名称']);
        }
        
        try {
            $categoryData = [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'icon' => $data['icon'] ?? '',
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => $data['status'] ?? 1
            ];
            
            $result = Db::name('categories')->where('id', $id)->update($categoryData);
            
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
            Db::startTrans();
            
            Db::name('moments')->where('category_id', $id)->update(['category_id' => 0]);
            
            $result = Db::name('categories')->where('id', $id)->delete();
            
            Db::commit();
            
            if ($result) {
                return json(['code' => 200, 'msg' => '删除成功']);
            }
            
            return json(['code' => 500, 'msg' => '删除失败']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }
    
    public function batchDelete()
    {
        $ids = Request::post('ids');
        
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            Db::startTrans();
            
            Db::name('moments')->where('category_id', 'in', $ids)->update(['category_id' => 0]);
            
            $result = Db::name('categories')->where('id', 'in', $ids)->delete();
            
            Db::commit();
            
            if ($result) {
                return json(['code' => 200, 'msg' => '删除成功']);
            }
            
            return json(['code' => 500, 'msg' => '删除失败']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }
    
    public function toggleStatus()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $category = Db::name('categories')->where('id', $id)->find();
            if (!$category) {
                return json(['code' => 404, 'msg' => '分类不存在']);
            }
            
            $newStatus = $category['status'] ? 0 : 1;
            $result = Db::name('categories')->where('id', $id)->update(['status' => $newStatus]);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '操作成功', 'data' => ['status' => $newStatus]]);
            }
            
            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
}
