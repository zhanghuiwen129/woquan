<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Session;
use think\facade\Request;
use think\facade\Db;
use think\facade\Redirect;

/**
 * 账号安全管理控制器
 */
class AccountSecurity extends AdminController
{
    // 需要管理员认证
    public function initialize()
    {
        parent::initialize();
        if (!Session::has('admin_id')) {
            return Redirect::to('/admin/login');
        }
    }

    // 账号安全管理首页
    public function index()
    {
        View::assign('admin_name', Session::get('admin_name'));
        return View::fetch('admin/accountsecurity_index');
    }

    // 账号安全规则配置
    public function securityRules()
    {
        // 账号安全规则配置存储在系统配置表中
        $config = Db::name('system_config')->where('config_key', 'account_security_rules')->value('config_value');
        $securityRules = $config ? json_decode($config, true) : [];
        
        View::assign('security_rules', $securityRules);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/accountsecurity_rules');
    }

    // 保存账号安全规则配置
    public function saveSecurityRules()
    {
        if (Request::isPost()) {
            $rules = Request::param('rules', '[]');
            
            try {
                // 验证JSON格式
                $rulesArray = json_decode($rules, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '规则数据格式错误']);
                }
                
                // 保存到系统配置
                $existingConfig = Db::name('system_config')->where('config_key', 'account_security_rules')->find();
                if ($existingConfig) {
                    Db::name('system_config')->where('config_key', 'account_security_rules')->update([
                        'config_value' => $rules,
                        'update_time' => time()
                    ]);
                } else {
                    Db::name('system_config')->insert([
                        'config_key' => 'account_security_rules',
                        'config_value' => $rules,
                        'config_name' => '账号安全规则配置',
                        'config_type' => 'textarea',
                        'config_group' => 'security',
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '账号安全规则配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 用户隐私数据管控
    public function privacyManagement()
    {
        // 隐私数据配置存储在系统配置表中
        $config = Db::name('system_config')->where('config_key', 'privacy_data_config')->value('config_value');
        $privacyConfig = $config ? json_decode($config, true) : [];
        
        View::assign('privacy_config', $privacyConfig);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/accountsecurity_privacy');
    }

    // 保存隐私数据配置
    public function savePrivacyManagement()
    {
        if (Request::isPost()) {
            $config = Request::param('config', '[]');
            
            try {
                // 验证JSON格式
                $configArray = json_decode($config, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '配置数据格式错误']);
                }
                
                // 保存到系统配置
                $existingConfig = Db::name('system_config')->where('config_key', 'privacy_data_config')->find();
                if ($existingConfig) {
                    Db::name('system_config')->where('config_key', 'privacy_data_config')->update([
                        'config_value' => $config,
                        'update_time' => time()
                    ]);
                } else {
                    Db::name('system_config')->insert([
                        'config_key' => 'privacy_data_config',
                        'config_value' => $config,
                        'config_name' => '隐私数据配置',
                        'config_type' => 'textarea',
                        'config_group' => 'security',
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '隐私数据配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 账号冻结/解冻管理
    public function accountFreeze()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status', '');

        $where = [];
        if ($keyword) {
            $where[] = ['username', 'like', "%{$keyword}%"];
            $where[] = ['nickname', 'like', "%{$keyword}%"];
        }
        if ($status !== '' && $status !== null) {
            $where[] = ['status', '=', $status];
        }

        $users = Db::name('user')
            ->where($where)
            ->order('id desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'users' => $users,
            'keyword' => $keyword,
            'status' => $status,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/accountsecurity_freeze');
    }

    // 冻结账号
    public function freezeAccount()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            $reason = Request::param('reason', '');
            
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                // 冻结账号
                Db::name('user')->where('id', $id)->update([
                    'status' => 0
                ]);
                
                // 记录操作日志
                $user = Db::name('user')->find($id);
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '冻结用户账号',
                    'content' => '用户ID: ' . $id . ', 用户名: ' . $user['username'] . ', 原因: ' . $reason,
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => '账号冻结成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 解冻账号
    public function unfreezeAccount()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            $reason = Request::param('reason', '');
            
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                // 解冻账号
                Db::name('user')->where('id', $id)->update([
                    'status' => 1
                ]);
                
                // 记录操作日志
                $user = Db::name('user')->find($id);
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '解冻用户账号',
                    'content' => '用户ID: ' . $id . ', 用户名: ' . $user['username'] . ', 原因: ' . $reason,
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => '账号解冻成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 密码重置
    public function resetPassword()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            $newPassword = Request::param('new_password', '');
            
            if (empty($id) || empty($newPassword)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                // 密码加密
                $encryptedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                // 更新密码
                Db::name('user')->where('id', $id)->update([
                    'password' => $encryptedPassword
                ]);
                
                // 记录操作日志
                $user = Db::name('user')->find($id);
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '重置用户密码',
                    'content' => '用户ID: ' . $id . ', 用户名: ' . $user['username'],
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => '密码重置成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 登录安全日志查询
    public function loginLogs()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $user_id = Request::param('user_id', '');
        $ip = Request::param('ip', '');
        $start_time = Request::param('start_time', '');
        $end_time = Request::param('end_time', '');
        $is_abnormal = Request::param('is_abnormal', '');

        $where = [];
        if ($user_id) {
            $where[] = ['user_id', '=', $user_id];
        }
        if ($ip) {
            $where[] = ['login_ip', 'like', "%{$ip}%"];
        }
        if ($start_time) {
            $where[] = ['login_time', '>=', strtotime($start_time)];
        }
        if ($end_time) {
            $where[] = ['login_time', '<=', strtotime($end_time) + 86399];
        }
        if ($is_abnormal !== '' && $is_abnormal !== null) {
            $where[] = ['is_abnormal', '=', $is_abnormal];
        }

        $logs = Db::name('login_logs')
            ->alias('ll')
            ->leftJoin('user u', 'll.user_id = u.id')
            ->field('ll.*, u.username, u.nickname')
            ->where($where)
            ->order('ll.login_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'logs' => $logs,
            'user_id' => $user_id,
            'ip' => $ip,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'is_abnormal' => $is_abnormal,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/accountsecurity_login_logs');
    }

    // 查看用户会话
    public function userSessions()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $user_id = Request::param('user_id', '');
        $ip = Request::param('ip', '');

        $where = [];
        if ($user_id) {
            $where[] = ['user_id', '=', $user_id];
        }
        if ($ip) {
            $where[] = ['ip', 'like', "%{$ip}%"];
        }

        $sessions = Db::name('sessions')
            ->alias('s')
            ->leftJoin('user u', 's.user_id = u.id')
            ->field('s.*, u.username, u.nickname')
            ->where($where)
            ->order('s.create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'sessions' => $sessions,
            'user_id' => $user_id,
            'ip' => $ip,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/accountsecurity_sessions');
    }

    // 强制下线用户
    public function forceLogout()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                // 删除用户会话
                Db::name('sessions')->where('user_id', $id)->delete();
                
                // 记录操作日志
                $user = Db::name('user')->find($id);
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '强制用户下线',
                    'content' => '用户ID: ' . $id . ', 用户名: ' . $user['username'],
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => '用户已被强制下线']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }
}
