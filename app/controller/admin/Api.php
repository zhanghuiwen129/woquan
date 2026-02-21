<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Api extends AdminController
{
    /**
     * 初始化方法
     */
    public function initialize()
    {
        parent::initialize();
        // 检查是否登录
        if (!Session::has('admin_id')) {
            return redirect('/admin/login');
        }
        // 传递管理员名称
        View::assign('admin_name', Session::get('admin_name', '管理员'));
    }
    
    public function keys()
    {
        // 检查是否访问的是 add 页面
        $path = Request::pathinfo();
        if (strpos($path, 'api/keys/add') !== false) {
            return $this->add();
        }
        
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        
        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['name', 'like', "%{$escapedKeyword}%"];
        }
        
        $keys = Db::name('api_keys')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'keys' => $keys,
            'keyword' => $keyword,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/api/keys');
    }
    
    public function calls()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        
        $calls = Db::name('api_calls')
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'calls' => $calls,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/api/calls');
    }
    
    public function rateLimit()
    {
        $rules = Db::name('rate_limit_rules')
            ->order('create_time desc')
            ->select();
        
        View::assign([
            'rules' => $rules,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/api/rate-limit');
    }
    
    /**
     * 获取API密钥列表
     */
    public function getKeys()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        
        $where = [];
        if ($keyword) {
            $where[] = ['name', 'like', "%{$keyword}%"];
        }
        
        $keys = Db::name('api_keys')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $keys->items(),
                'total' => $keys->total()
            ]
        ]);
    }
    
    /**
     * 切换API密钥状态
     */
    public function toggleKeyStatus()
    {
        $id = Request::param('id');
        $status = Request::param('status');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少参数']);
        }
        
        $result = Db::name('api_keys')
            ->where('id', $id)
            ->update(['status' => $status]);
        
        if ($result) {
            return json(['code' => 200, 'msg' => '操作成功']);
        } else {
            return json(['code' => 400, 'msg' => '操作失败']);
        }
    }
    
    /**
     * 删除API密钥
     */
    public function deleteKey()
    {
        $id = Request::param('id');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少参数']);
        }
        
        $result = Db::name('api_keys')
            ->where('id', $id)
            ->delete();
        
        if ($result) {
            return json(['code' => 200, 'msg' => '删除成功']);
        } else {
            return json(['code' => 400, 'msg' => '删除失败']);
        }
    }
    
    /**
     * 获取API调用记录列表
     */
    public function getCalls()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        
        $where = [];
        if ($keyword) {
            $where[] = ['endpoint', 'like', "%{$keyword}%"];
        }
        
        $calls = Db::name('api_calls')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $calls->items(),
                'total' => $calls->total()
            ]
        ]);
    }
    
    /**
     * 添加API密钥页面
     */
    public function add()
    {
        View::assign([
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        
        return View::fetch('admin/api/add');
    }
    
    /**
     * 保存API密钥
     */
    public function saveKey()
    {
        $data = Request::param();
        
        // 验证参数
        if (!$data['name']) {
            return json(['code' => 400, 'msg' => '请输入密钥名称']);
        }
        
        // 生成随机的Access Key
        $accessKey = $this->generateAccessKey();
        
        // 保存到数据库
        try {
            $result = Db::name('api_keys')->insert([
                'name' => $data['name'],
                'access_key' => $accessKey,
                'permissions' => $data['permissions'] ?? '*',
                'status' => 1,
                'create_time' => time(),
                'update_time' => time()
            ]);
            
            if ($result) {
                return json(['code' => 200, 'msg' => '添加成功', 'data' => ['access_key' => $accessKey]]);
            } else {
                return json(['code' => 400, 'msg' => '添加失败']);
            }
        } catch (xception $e) {
            return json(['code' => 500, 'msg' => '添加失败：' . $e->getMessage()]);
        }
    }
    
    /**
     * 生成随机Access Key
     */
    private function generateAccessKey()
    {
        return md5(uniqid() . time() . rand(1000, 9999));
    }
}
