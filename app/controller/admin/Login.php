<?php

namespace app\controller\admin;

use app\BaseController;
use think\facade\Request;
use think\facade\Session;
use think\facade\View;
use think\facade\Db;
use think\captcha\facade\Captcha;

class Login extends BaseController
{
    // 登录页面
    public function index()
    {
        // 如果已经登录，跳转到后台首页
        if (Session::has('admin_id')) {
            return redirect('/admin/index');
        }
        return View::fetch('admin/login');
    }

    // 登录验证
    public function login()
    {
        if (Request::isPost()) {
            $username = Request::param('username');
            $password = Request::param('password');

            if (empty($username) || empty($password)) {
                return json(['code' => 0, 'msg' => '用户名或密码不能为空']);
            }

            if (!$this->checkLoginAttempts($username)) {
                return json(['code' => 0, 'msg' => '登录失败次数过多，请15分钟后再试']);
            }

            // 查询管理员信息 - 只从admin表中查询，确保后台登录只能使用admin账号
            $admin = Db::name('admin')
                ->field('id, username, password, nickname, email, status, role')
                ->where('username', $username)
                ->where('status', 1) // 只允许状态正常的管理员登录
                ->find();

            if (!$admin) {
                $this->recordLoginFailure($username);
                return json(['code' => 0, 'msg' => '用户名不存在']);
            }

            // 验证密码（使用bcrypt加密验证）
            if (!password_verify($password, $admin['password'])) {
                $this->recordLoginFailure($username);
                return json(['code' => 0, 'msg' => '密码错误']);
            }

            // 登录成功，重新生成Session ID以防止Session固定攻击
            Session::delete('admin_id');
            Session::delete('admin_name');
            
            Session::set('admin_id', $admin['id']);
            Session::set('admin_name', $admin['username']);

            // 记录登录日志
            Db::name('admin_log')
                ->insert([
                    'admin_id' => $admin['id'],
                    'username' => $admin['username'],
                    'action' => '登录后台',
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);

            return json(['code' => 1, 'msg' => '登录成功', 'url' => '/admin/index']);
        }
    }

    // 退出登录
    public function logout()
    {
        // 记录退出日志
        if (Session::has('admin_id')) {
            Db::name('admin_log')
                ->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '退出后台',
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);

            // 清除Session
            Session::clear();
        }

        return redirect('/admin/login');
    }

    // 生成验证码
    public function captcha()
    {
        return Captcha::create(null, [
            'length' => 4,
            'width' => 120,
            'height' => 40,
            'fontSize' => 20,
            'useCurve' => false,
            'useNoise' => false
        ]);
    }

    // 修改密码
    public function changePassword()
    {
        if (Request::isPost()) {
            // 获取当前管理员ID
            $adminId = Session::get('admin_id');
            if (!$adminId) {
                return json(['code' => 0, 'msg' => '请先登录']);
            }

            // 获取请求参数（支持JSON格式）
            $params = Request::post();
            if (empty($params)) {
                // 尝试解析JSON请求体
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }
            
            $oldPassword = isset($params['old_password']) ? $params['old_password'] : '';
            $newPassword = isset($params['new_password']) ? $params['new_password'] : '';
            $confirmPassword = isset($params['confirm_password']) ? $params['confirm_password'] : '';

            // 验证参数
            if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
                return json(['code' => 0, 'msg' => '所有密码字段都不能为空']);
            }

            if ($newPassword !== $confirmPassword) {
                return json(['code' => 0, 'msg' => '两次输入的新密码不一致']);
            }

            if (strlen($newPassword) < 6) {
                return json(['code' => 0, 'msg' => '新密码长度不能少于6个字符']);
            }

            // 获取管理员信息
            $admin = Db::name('admin')->find($adminId);
            if (!$admin) {
                return json(['code' => 0, 'msg' => '管理员不存在']);
            }

            // 验证旧密码
            if (!password_verify($oldPassword, $admin['password'])) {
                return json(['code' => 0, 'msg' => '当前密码输入错误']);
            }

            // 更新密码
            $result = Db::name('admin')->where('id', $adminId)->update([
                'password' => password_hash($newPassword, PASSWORD_BCRYPT),
                'update_time' => time()
            ]);

            if ($result) {
                // 记录操作日志
                $adminName = Session::get('admin_name');
                Db::name('admin_log')->insert([
                    'admin_id' => $adminId,
                    'username' => $adminName,
                    'action' => '修改密码',
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                // 清除会话信息，强制重新登录
                Session::clear();

                return json(['code' => 1, 'msg' => '密码修改成功']);
            } else {
                return json(['code' => 0, 'msg' => '密码修改失败']);
            }
        }

        // 如果是GET请求，返回错误信息（密码修改功能只能通过弹窗使用POST请求）
        return json(['code' => 0, 'msg' => '不支持GET请求，请通过正确的方式修改密码']);
    }

    /**
     * 检查密码强度
     */
    protected function checkPasswordStrength($password)
    {
        if (strlen($password) < 8) {
            return false;
        }

        $hasLower = preg_match('/[a-z]/', $password);
        $hasUpper = preg_match('/[A-Z]/', $password);
        $hasNumber = preg_match('/[0-9]/', $password);
        $hasSpecial = preg_match('/[^a-zA-Z0-9]/', $password);

        return $hasLower && $hasUpper && $hasNumber && $hasSpecial;
    }

    /**
     * 检查登录尝试次数
     */
    protected function checkLoginAttempts($username)
    {
        $cacheKey = 'login_attempts_' . $username;
        $attempts = cache($cacheKey) ?: 0;

        if ($attempts >= 5) {
            return false;
        }

        return true;
    }

    /**
     * 记录登录失败
     */
    protected function recordLoginFailure($username)
    {
        $cacheKey = 'login_attempts_' . $username;
        $attempts = cache($cacheKey) ?: 0;
        cache($cacheKey, $attempts + 1, 900);
    }
}
