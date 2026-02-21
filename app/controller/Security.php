<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\Session;
use think\facade\View;

class Security
{
    // 安全设置页面
    public function index()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');

        if (!$userId) {
            return redirect('/login');
        }

        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        // 配置信息已在基类中加载
        View::assign([
            'currentUser' => $currentUser,
            'isLogin' => !empty($userId)
        ]);
        return View::fetch('index/security');
    }
    /**
     * 修改密码
     */
    public function changePassword()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['old_password']) || !isset($data['new_password'])) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }

            // 验证旧密码
            $user = Db::name('user')
                ->where('id', $userId)
                ->find();

            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }

            // 验证密码是否正确
            if (!password_verify($data['old_password'], $user['password'])) {
                return json(['code' => 400, 'msg' => '旧密码错误']);
            }

            // 验证新密码强度
            if (!$this->checkPasswordStrength($data['new_password'])) {
                return json(['code' => 400, 'msg' => '密码强度不足，至少8位，包含大小写字母、数字和特殊字符']);
            }

            // 生成新密码的哈希值
            $newPasswordHash = password_hash($data['new_password'], PASSWORD_DEFAULT);

            // 更新密码
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'password' => $newPasswordHash,
                    'update_time' => time()
                ]);

            return json(['code' => 200, 'msg' => '密码修改成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '修改密码失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 绑定手机号
     */
    public function bindPhone()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['phone']) || !isset($data['code'])) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }

            // 验证手机号格式
            if (!preg_match('/^1[3-9]\d{9}$/', $data['phone'])) {
                return json(['code' => 400, 'msg' => '手机号格式错误']);
            }

            // 验证验证码
            $smsCode = session('sms_code_' . $data['phone']);
            $smsExpire = session('sms_code_expire_' . $data['phone']);

            if (!$smsCode || $smsCode != $data['code']) {
                return json(['code' => 400, 'msg' => '验证码错误']);
            }

            if ($smsExpire && time() > $smsExpire) {
                return json(['code' => 400, 'msg' => '验证码已过期']);
            }

            // 检查手机号是否已被其他用户绑定
            $existingUser = Db::name('user')
                ->where('phone', $data['phone'])
                ->where('id', '<>', $userId)
                ->find();

            if ($existingUser) {
                return json(['code' => 400, 'msg' => '该手机号已被其他用户绑定']);
            }

            // 更新用户手机号
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'phone' => $data['phone'],
                    'is_phone_verified' => 1,
                    'update_time' => time()
                ]);

            // 清除验证码
            session('sms_code_' . $data['phone'], null);
            session('sms_code_expire_' . $data['phone'], null);

            return json(['code' => 200, 'msg' => '手机号绑定成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '绑定手机号失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 解绑手机号
     */
    public function unbindPhone()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取用户信息
            $user = Db::name('user')
                ->where('id', $userId)
                ->find();

            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }

            // 检查是否有其他登录方式
            if (!$user['email']) {
                return json(['code' => 400, 'msg' => '至少需要保留一种登录方式']);
            }

            // 解绑手机号
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'phone' => null,
                    'is_phone_verified' => 0,
                    'update_time' => time()
                ]);

            return json(['code' => 200, 'msg' => '手机号解绑成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '解绑手机号失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 绑定邮箱
     */
    public function bindEmail()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['email']) || !isset($data['code'])) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }

            // 验证邮箱格式
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return json(['code' => 400, 'msg' => '邮箱格式错误']);
            }

            // 验证验证码
            $emailCode = session('email_code_' . $data['email']);
            $emailExpire = session('email_code_expire_' . $data['email']);

            if (!$emailCode || $emailCode != $data['code']) {
                return json(['code' => 400, 'msg' => '验证码错误']);
            }

            if ($emailExpire && time() > $emailExpire) {
                return json(['code' => 400, 'msg' => '验证码已过期']);
            }

            // 检查邮箱是否已被其他用户绑定
            $existingUser = Db::name('user')
                ->where('email', $data['email'])
                ->where('id', '<>', $userId)
                ->find();

            if ($existingUser) {
                return json(['code' => 400, 'msg' => '该邮箱已被其他用户绑定']);
            }

            // 更新用户邮箱
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'email' => $data['email'],
                    'is_email_verified' => 1,
                    'update_time' => time()
                ]);

            // 清除验证码
            session('email_code_' . $data['email'], null);
            session('email_code_expire_' . $data['email'], null);

            return json(['code' => 200, 'msg' => '邮箱绑定成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '绑定邮箱失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 解绑邮箱
     */
    public function unbindEmail()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取用户信息
            $user = Db::name('user')
                ->where('id', $userId)
                ->find();

            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }

            // 检查是否有其他登录方式
            if (!$user['phone']) {
                return json(['code' => 400, 'msg' => '至少需要保留一种登录方式']);
            }

            // 解绑邮箱
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'email' => null,
                    'is_email_verified' => 0,
                    'update_time' => time()
                ]);

            return json(['code' => 200, 'msg' => '邮箱解绑成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '解绑邮箱失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取登录设备列表
     */
    public function getLoginDevices()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取登录设备列表
            $devices = Db::name('user_login_log')
                ->where('user_id', $userId)
                ->field('id, login_ip, login_location, login_device, login_time')
                ->order('login_time', 'desc')
                ->limit(20)
                ->select();

            return json(['code' => 200, 'msg' => 'success', 'data' => ['list' => $devices]]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '获取登录设备列表失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 强制下线设备
     */
    public function logoutDevice()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['log_id'])) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }

            // 检查日志是否存在
            $log = Db::name('user_login_log')
                ->where('id', $data['log_id'])
                ->where('user_id', $userId)
                ->find();

            if (!$log) {
                return json(['code' => 404, 'msg' => '登录记录不存在']);
            }

            // 这里可以实现更复杂的设备下线逻辑，比如清除对应设备的session或token
            // 目前只是记录操作

            return json(['code' => 200, 'msg' => '设备已强制下线']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '强制下线设备失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 强制下线所有设备
     */
    public function logoutAllDevices()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 这里可以实现更复杂的设备下线逻辑，比如清除所有设备的session或token
            // 目前只是记录操作

            return json(['code' => 200, 'msg' => '所有设备已强制下线']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '强制下线所有设备失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 设置登录保护
     */
    public function setLoginProtection()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['login_protection'])) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }

            // 更新登录保护设置
            Db::name('user_settings')
                ->where('user_id', $userId)
                ->update([
                    'login_protection' => intval($data['login_protection']),
                    'update_time' => time()
                ]);

            return json(['code' => 200, 'msg' => '登录保护设置更新成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '设置登录保护失败: ' . $e->getMessage()]);
        }
    }



    /**
     * 获取账号安全等级
     */
    public function getSecurityLevel()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取用户信息
            $user = Db::name('user')
                ->where('id', $userId)
                ->find();

            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }

            // 获取用户设置
            $settings = Db::name('user_settings')
                ->where('user_id', $userId)
                ->find() ?? [];

            // 计算安全等级（1-10分制）
            $securityScore = 0;
            $securityFactors = [];

            // 1. 密码强度（默认2分，强度高可以加分，这里简化处理）
            $securityScore += 2;
            $securityFactors[] = [
                'name' => '密码设置',
                'score' => 2,
                'completed' => true
            ];

            // 2. 绑定手机号（3分）
            if (!empty($user['mobile']) || !empty($user['phone'])) {
                $securityScore += 3;
                $securityFactors[] = [
                    'name' => '绑定手机号',
                    'score' => 3,
                    'completed' => true
                ];
            } else {
                $securityFactors[] = [
                    'name' => '绑定手机号',
                    'score' => 3,
                    'completed' => false
                ];
            }

            // 3. 绑定邮箱（3分）
            if (!empty($user['email'])) {
                $securityScore += 3;
                $securityFactors[] = [
                    'name' => '绑定邮箱',
                    'score' => 3,
                    'completed' => true
                ];
            } else {
                $securityFactors[] = [
                    'name' => '绑定邮箱',
                    'score' => 3,
                    'completed' => false
                ];
            }

            // 4. 登录保护（2分）
            if (isset($settings['login_protection']) && $settings['login_protection'] == 1) {
                $securityScore += 2;
                $securityFactors[] = [
                    'name' => '开启登录保护',
                    'score' => 2,
                    'completed' => true
                ];
            } else {
                $securityFactors[] = [
                    'name' => '开启登录保护',
                    'score' => 2,
                    'completed' => false
                ];
            }

            // 确定安全等级
            if ($securityScore >= 9) {
                $level = '高';
                $levelColor = '#52c41a';
            } elseif ($securityScore >= 6) {
                $level = '中';
                $levelColor = '#faad14';
            } else {
                $level = '低';
                $levelColor = '#f5222d';
            }

            // 获取安全建议
            $suggestions = [];
            foreach ($securityFactors as $factor) {
                if (!$factor['completed']) {
                    switch ($factor['name']) {
                        case '绑定手机号':
                            $suggestions[] = '绑定手机号可以提高账号安全性，还可以用于密码找回';
                            break;
                        case '绑定邮箱':
                            $suggestions[] = '绑定邮箱可以提高账号安全性，还可以接收重要通知';
                            break;
                        case '开启登录保护':
                            $suggestions[] = '开启登录保护可以防止账号被盗用';
                            break;
                    }
                }
            }

            if (empty($suggestions)) {
                $suggestions[] = '您的账号安全等级很高，继续保持！';
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'security_level' => $level,
                    'security_score' => $securityScore,
                    'max_score' => 10,
                    'level_color' => $levelColor,
                    'factors' => $securityFactors,
                    'suggestions' => $suggestions
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '获取安全等级失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取登录日志
     */
    public function getLoginLogs()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $page = input('page', 1, 'intval');
            $limit = input('limit', 10, 'intval');
            $days = input('days', 30, 'intval');

            // 计算开始时间
            $startTime = date('Y-m-d H:i:s', strtotime("-$days days"));

            // 获取登录日志
            $loginLogs = Db::name('login_logs')
                ->where('user_id', $userId)
                ->where('login_time', '>=', $startTime)
                ->order('login_time', 'DESC')
                ->paginate([
                    'page' => $page,
                    'list_rows' => $limit,
                    'type' => 'json'
                ]);

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'list' => $loginLogs->items(),
                    'total' => $loginLogs->total(),
                    'page' => $loginLogs->currentPage(),
                    'limit' => $loginLogs->listRows(),
                    'pages' => $loginLogs->lastPage()
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '获取登录日志失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 举报异常登录
     */
    public function reportAbnormalLogin()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            if (!$userId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['login_log_id']) || !isset($data['reason'])) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }

            // 验证登录日志是否存在且属于当前用户
            $loginLog = Db::name('login_logs')
                ->where('id', $data['login_log_id'])
                ->where('user_id', $userId)
                ->find();

            if (!$loginLog) {
                return json(['code' => 404, 'msg' => '登录日志不存在']);
            }

            // 记录异常登录举报
            $reportId = Db::name('reports')
                ->insertGetId([
                    'reporter_id' => $userId,
                    'reported_user_id' => $userId, // 举报自己的异常登录
                    'type' => 4, // 4代表异常登录举报（自定义类型）
                    'reason' => $data['reason'],
                    'evidence_urls' => json_encode([$loginLog['login_ip'], date('Y-m-d H:i:s', strtotime($loginLog['login_time']))]),
                    'status' => 0, // 0待处理
                    'create_time' => time(),
                    'moment_id' => $data['login_log_id'] // 使用moment_id字段存储登录日志ID
                ]);

            // 更新登录日志状态为异常
            Db::name('login_logs')
                ->where('id', $data['login_log_id'])
                ->update([
                    'is_abnormal' => 1,
                    'abnormal_reason' => $data['reason']
                ]);

            // 触发安全措施（例如强制退出该设备）
            // 这里可以根据实际需求添加更多安全措施

            return json(['code' => 200, 'msg' => '异常登录已举报，我们会尽快处理', 'data' => ['report_id' => $reportId]]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '举报异常登录失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 发送短信验证码
     */
    public function sendSmsCode()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['phone'])) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }

            $phone = $data['phone'];
            $type = $data['type'] ?? 'login'; // login, register, reset_password, verify_phone

            // 验证手机号格式
            if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
                return json(['code' => 400, 'msg' => '手机号格式错误']);
            }

            // 检查发送频率（60秒内只能发送一次）
            $lastSendTime = session('sms_code_time_' . $phone);
            if ($lastSendTime && (time() - $lastSendTime) < 60) {
                return json(['code' => 400, 'msg' => '验证码发送过于频繁，请稍后再试']);
            }

            // 生成6位随机验证码
            $code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

            // 存储验证码到session（5分钟有效期）
            session('sms_code_' . $phone, $code);
            session('sms_code_expire_' . $phone, time() + 300);
            session('sms_code_time_' . $phone, time());
            session('sms_code_type_' . $phone, $type);

            // TODO: 实际项目中需要对接短信服务商（如阿里云、腾讯云等）
            // 这里仅做演示，打印验证码到日志
            error_log("SMS验证码 - 手机号: {$phone}, 验证码: {$code}, 类型: {$type}");

            // 返回成功响应（实际生产环境不应返回验证码）
            // 开发环境返回验证码方便测试
            $isDev = getenv('APP_DEBUG') == 'true';

            return json(['code' => 200, 'msg' => '验证码已发送', 'data' => ['code' => $isDev ? $code : null, 'expire' => 300]]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '发送验证码失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 验证手机号
     */
    public function verifyPhone()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['phone']) || !isset($data['code'])) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }

            $phone = $data['phone'];
            $code = $data['code'];
            $type = $data['type'] ?? session('sms_code_type_' . $phone);

            // 验证手机号格式
            if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
                return json(['code' => 400, 'msg' => '手机号格式错误']);
            }

            // 验证验证码
            $storedCode = session('sms_code_' . $phone);
            $storedExpire = session('sms_code_expire_' . $phone);

            if (!$storedCode || $storedCode != $code) {
                return json(['code' => 400, 'msg' => '验证码错误']);
            }

            if ($storedExpire && time() > $storedExpire) {
                return json(['code' => 400, 'msg' => '验证码已过期']);
            }

            // 验证通过，标记手机号已验证
            session('phone_verified_' . $phone, true);
            session('phone_verified_time_' . $phone, time());

            // 清除验证码
            session('sms_code_' . $phone, null);
            session('sms_code_expire_' . $phone, null);

            return json(['code' => (string)200, 'msg' => '手机号验证成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '验证手机号失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 重置密码
     */
    public function resetPassword()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['phone']) || !isset($data['code']) || !isset($data['new_password'])) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }

            $phone = $data['phone'];
            $code = $data['code'];
            $newPassword = $data['new_password'];

            // 验证手机号格式
            if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
                return json(['code' => 400, 'msg' => '手机号格式错误']);
            }

            // 验证手机号是否已注册
            $user = Db::name('user')
                ->where('phone', $phone)
                ->find();

            if (!$user) {
                return json(['code' => 404, 'msg' => '该手机号未注册']);
            }

            // 验证验证码
            $storedCode = session('sms_code_' . $phone);
            $storedExpire = session('sms_code_expire_' . $phone);

            if (!$storedCode || $storedCode != $code) {
                return json(['code' => 400, 'msg' => '验证码错误']);
            }

            if ($storedExpire && time() > $storedExpire) {
                return json(['code' => 400, 'msg' => '验证码已过期']);
            }

            // 验证新密码强度
            if (strlen($newPassword) < 6) {
                return json(['code' => 400, 'msg' => '密码长度不能少于6位']);
            }

            // 生成新密码的哈希值
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            // 更新密码
            Db::name('user')
                ->where('phone', $phone)
                ->update([
                    'password' => $newPasswordHash,
                    'update_time' => time()
                ]);

            // 清除验证码
            session('sms_code_' . $phone, null);
            session('sms_code_expire_' . $phone, null);

            return json(['code' => 200, 'msg' => '密码重置成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '重置密码失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 忘记密码页面
     */
    public function forgotPassword()
    {
        // 这是一个页面方法，返回忘记密码页面
        return view('user/forgot-password');
    }
}
