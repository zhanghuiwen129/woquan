<?php
declare (strict_types = 1);

namespace app\controller;

use think\Request;
use think\Response;
use think\facade\Db;
use think\facade\View;
use think\facade\Session;
use think\facade\Cookie;
use think\exception\DbException;

class User extends BaseFrontendController
{
    // 使用框架的数据库连接，不再需要手动创建PDO

    /**
     * 用户详情页面
     */
    public function index($id)
    {
        // 获取当前登录用户信息
        $currentUserId = session('user_id') ?: cookie('user_id');
        $currentUser = null;
        if ($currentUserId) {
            $currentUser = [
                'id' => $currentUserId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
        }

        // 获取用户基本信息
        $user = Db::name('user')
            ->where('id', $id)
            ->find();
        
        if (!$user) {
            return redirect('/404');
        }
        
        // 获取用户统计信息
        $followingCount = Db::name('follows')
            ->where('follower_id', $id)
            ->count();
        
        $followerCount = Db::name('follows')
            ->where('following_id', $id)
            ->count();
        
        $momentsCount = Db::name('moments')
            ->where('user_id', $id)
            ->count();
        
        // 检查当前用户是否关注该用户
        $isFollowing = false;
        if ($currentUserId) {
            $isFollowing = Db::name('follows')
                ->where('follower_id', $currentUserId)
                ->where('following_id', $id)
                ->find() ? true : false;
        }

        // 检查是否是本人页面
        $isMyPage = ($currentUserId == $id);

        // 配置信息已在基类中加载
        $config = View::get('config', []);

        // 分配模板变量
        View::assign([
            'user' => $user,
            'followingCount' => $followingCount,
            'followerCount' => $followerCount,
            'momentsCount' => $momentsCount,
            'isFollowing' => $isFollowing,
            'currentUser' => $currentUser,
            'isLogin' => !empty($currentUserId),
            'isMyPage' => $isMyPage
        ]);
        
        // 渲染模板
        return View::fetch('index/user');
    }
    
    /**
     * 用户关注列表页面
     */
    public function following($id = null)
    {
        // 如果没有传递ID，使用当前登录用户的ID
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (empty($id)) {
            $id = $userId;
            if (empty($id)) {
                return redirect('/login');
            }
        }

        // 获取用户基本信息
        $user = Db::name('user')
            ->where('id', $id)
            ->find();

        if (!$user) {
            return redirect('/404');
        }

        // 获取用户关注列表
        $following = Db::name('follows')
            ->alias('uf')
            ->join('user u', 'uf.following_id = u.id')
            ->where('uf.follower_id', $id)
            ->field('u.id, u.username, u.nickname, u.avatar, u.bio')
            ->order('uf.create_time', 'desc')
            ->select();

        // 获取当前登录用户信息
        $currentUser = null;
        if ($isLogin) {
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
        }

        // 分配模板变量
        View::assign([
            'user' => $user,
            'following' => $following,
            'pageTitle' => '关注列表',
            'currentUser' => $currentUser,
            'isLogin' => $isLogin,
            'current_url' => '/following'
        ]);

        // 渲染模板
        return View::fetch('index/following');
    }
    
    /**
     * 用户粉丝列表页面
     */
    public function followers($id = null)
    {
        // 如果没有传递ID，使用当前登录用户的ID
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (empty($id)) {
            $id = $userId;
            if (empty($id)) {
                return redirect('/login');
            }
        }

        // 获取用户基本信息
        $user = Db::name('user')
            ->where('id', $id)
            ->find();

        if (!$user) {
            return redirect('/404');
        }

        // 获取用户粉丝列表
        $followers = Db::name('follows')
            ->alias('uf')
            ->join('user u', 'uf.follower_id = u.id')
            ->where('uf.following_id', $id)
            ->field('u.id, u.username, u.nickname, u.avatar, u.bio')
            ->order('uf.create_time', 'desc')
            ->select();

        // 获取当前登录用户信息
        $currentUser = null;
        if ($isLogin) {
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
        }

        // 分配模板变量
        View::assign([
            'user' => $user,
            'followers' => $followers,
            'pageTitle' => '粉丝列表',
            'currentUser' => $currentUser,
            'isLogin' => $isLogin,
            'current_url' => '/followers'
        ]);

        // 渲染模板
        return View::fetch('index/followers');
    }
    
    /**
     * 用户动态列表页面
     */
    public function moments($id)
    {
        // 获取用户基本信息
        $user = Db::name('user')
            ->where('id', $id)
            ->find();
        
        if (!$user) {
            return redirect('/404');
        }
        
        // 获取用户动态列表
        $moments = Db::name('moments')
            ->where('user_id', $id)
            ->where('status', 1)
            ->order('create_time', 'desc')
            ->select();
        
        // 分配模板变量
        View::assign([
            'user' => $user,
            'moments' => $moments,
            'pageTitle' => '用户动态'
        ]);
        
        // 渲染模板
        return View::fetch('user/moments');
    }
    
    /**
     * 获取推荐关注用户
     */
    public function getRecommendedUsers(Request $request)
    {
        try {
            $currentUserId = session('user_id');

            $query = Db::name('user')
                ->field('id, username, nickname, avatar, bio, level, vip_level')
                ->where('status', 1);

            if ($currentUserId) {
                $query->where('id', '<>', $currentUserId);
            }

            $allUserIds = $query->column('id');

            if ($currentUserId && !empty($allUserIds)) {
                $followingIds = Db::name('follows')
                    ->where('follower_id', $currentUserId)
                    ->where('status', 1)
                    ->where('following_id', 'in', $allUserIds)
                    ->column('following_id');

                if (!empty($followingIds)) {
                    $allUserIds = array_diff($allUserIds, $followingIds);
                }
            }

            $recommendedUsers = [];
            if (!empty($allUserIds)) {
                shuffle($allUserIds);
                $randomIds = array_slice($allUserIds, 0, 5);

                $recommendedUsers = Db::name('user')
                    ->field('id, username, nickname, avatar, bio, level, vip_level')
                    ->where('id', 'in', $randomIds)
                    ->select()
                    ->toArray();
            }

            foreach ($recommendedUsers as &$user) {
                $user['is_following'] = false;
            }

            return $this->success($recommendedUsers);

        } catch (\Exception $e) {
            return $this->error('获取推荐用户失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取推荐关注用户（API接口别名）
     */
    public function recommended(Request $request)
    {
        return $this->getRecommendedUsers($request);
    }

    /**
     * 调试：查看用户列表和关注关系
     */
    public function debugUsers()
    {
        try {
            $currentUserId = session('user_id');

            // 获取所有用户
            $allUsers = Db::name('user')
                ->field('id, username, nickname, avatar, status, create_time')
                ->order('create_time', 'desc')
                ->select()
                ->toArray();

            // 获取当前用户的关注列表
            $following = [];
            if ($currentUserId) {
                $following = Db::name('follows')
                    ->where('user_id', $currentUserId)
                    ->column('follow_id');
            }

            return $this->success([
                'current_user_id' => $currentUserId,
                'following_ids' => $following,
                'total_users' => count($allUsers),
                'users' => $allUsers
            ], '调试信息');
        } catch (\Exception $e) {
            return $this->error('获取调试信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成图形验证码
     */
    public function registerConfig()
    {
        try {
            // 获取注册配置
            $registerPhoneVerify = Db::name('system_config')
                ->where('config_key', 'register_phone_verify')
                ->value('config_value') ?? '0';
            $registerSmsVerify = Db::name('system_config')
                ->where('config_key', 'register_sms_verify')
                ->value('config_value') ?? '0';
            $registerCaptchaVerify = Db::name('system_config')
                ->where('config_key', 'register_captcha_verify')
                ->value('config_value') ?? '0';

            return $this->success([
                'register_phone_verify' => $registerPhoneVerify,
                'register_sms_verify' => $registerSmsVerify,
                'register_captcha_verify' => $registerCaptchaVerify
            ], 'success');
        } catch (\Exception $e) {
            return $this->error('获取注册配置失败');
        }
    }

    public function captcha()
    {
        try {
            $width = 120;
            $height = 40;
            $length = 4;
            $fontSize = 5; // imagestring使用的字体大�?1-5)

            // 创建画布
            $image = imagecreatetruecolor($width, $height);

            // 设置背景�?
            $bgColor = imagecolorallocate($image, 255, 255, 255);
            imagefill($image, 0, 0, $bgColor);

            // 生成随机验证�?
            $code = '';
            $charset = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $charsetLength = strlen($charset);

            for ($i = 0; $i < $length; $i++) {
                $code .= $charset[mt_rand(0, $charsetLength - 1)];
            }

            // 保存验证码到session
            session('captcha', strtoupper($code));

            // 预先分配一些颜�?
            $colors = [];
            for ($c = 0; $c < 10; $c++) {
                $colors[] = imagecolorallocate($image, mt_rand(0, 100), mt_rand(0, 100), mt_rand(0, 100));
            }

            // 绘制验证�?
            for ($i = 0; $i < $length; $i++) {
                $x = ($i * $width / $length) + mt_rand(5, 10);
                $y = $height / 2 + mt_rand(-5, 5);
                $colorIndex = mt_rand(0, count($colors) - 1);
                imagestring($image, $fontSize, $x, $y - 8, $code[$i], $colors[$colorIndex]);
            }

            // 绘制干扰�?
            for ($i = 0; $i < 5; $i++) {
                $color = imagecolorallocate($image, mt_rand(100, 150), mt_rand(100, 150), mt_rand(100, 150));
                imageline($image, mt_rand(0, $width), 0, mt_rand(0, $width), $height, $color);
            }

            // 绘制噪点
            for ($i = 0; $i < 50; $i++) {
                $color = imagecolorallocate($image, mt_rand(150, 200), mt_rand(150, 200), mt_rand(150, 200));
                imagesetpixel($image, mt_rand(0, $width), mt_rand(0, $height), $color);
            }

            // 设置响应�?
            header('Content-Type: image/png');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            imagepng($image);
            imagedestroy($image);

            return null;
        } catch (\Exception $e) {
            // 输出错误信息（用于调试）
            error_log('验证码生成失败 ' . $e->getMessage());
            return $this->returnError('验证码生成失败');
        }
    }

    /**
     * 发送短信验证码
     */
    public function sendSms(Request $request)
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $data = $request->post();
            
            // 验证数据
            if (empty($data['mobile'])) {
                return $this->returnError('手机号不能为空');
            }
            
            if (empty($data['captcha'])) {
                return $this->returnError('图形验证码不能为空');
            }
            
            // 验证手机号格式
            if (!preg_match('/^1[3-9]\d{9}$/', $data['mobile'])) {
                return $this->returnError('手机号格式不正确');
            }
            
            // 验证图形验证�?
            if (strtoupper($data['captcha']) !== session('captcha')) {
                return $this->returnError('图形验证码不正确');
            }
            
            // 检查手机号是否已注�?
            $existingUser = Db::name('user')
                ->where('mobile', $data['mobile'])
                ->field('id')
                ->find();
            
            if ($existingUser) {
                return $this->returnError('该手机号已注册');
            }
            
            // 检查发送频率
            $smsCacheKey = 'sms_code_' . $data['mobile'];
            $lastSendTime = session($smsCacheKey . '_time');
            
            if ($lastSendTime && (time() - $lastSendTime) < 60) {
                return $this->returnError('短信发送过于频繁，�?0秒后重试');
            }
            
            // 生成6位短信验证码
            $smsCode = mt_rand(100000, 999999);
            
            // 这里应该调用短信API发送验证码，现在我们只是模�?
            // 实际开发中需要替换为真实的短信发送接�?
            
            // 保存短信验证码到session
            session($smsCacheKey, $smsCode);
            session($smsCacheKey . '_time', time());
            session($smsCacheKey . '_expire', time() + 300); // 5分钟过期
            
            return $this->success(['sms_code' => $smsCode], '短信验证码发送成功');
        } catch (\Exception $e) {
            return $this->error('短信发送失败 ' . $e->getMessage());
        }
    }

    /**
     * 用户注册
     */
    public function register(Request $request)
    {
        // 处理GET请求，显示注册页�?
        if ($request->isGet()) {
            // 配置已在基类的initialize()方法中自动加�?
            return view('user/register');
        }
        
        // 处理POST请求，执行注册逻辑
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $data = $request->post();

            // 获取系统配置 - 直接查询数据�?不使用缓�?
            $registerPhoneVerify = (int)(Db::name('system_config')
                ->cache(false)
                ->where('config_key', 'register_phone_verify')
                ->value('config_value') ?? 0);
            $registerSmsVerify = (int)(Db::name('system_config')
                ->cache(false)
                ->where('config_key', 'register_sms_verify')
                ->value('config_value') ?? 0);
            $registerCaptchaVerify = (int)(Db::name('system_config')
                ->cache(false)
                ->where('config_key', 'register_captcha_verify')
                ->value('config_value') ?? 0);

            // 调试日志
            error_log("Register config - phone_verify: $registerPhoneVerify, sms_verify: $registerSmsVerify, captcha_verify: $registerCaptchaVerify");

            // 验证数据
            if (empty($data['username'])) {
                return $this->returnError('账号不能为空');
            }
            
            // 验证账号格式（使用正确的Unicode格式�?
            if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]{2,20}$/u', $data['username'])) {
                return $this->returnError('账号必须2-20位中文、字母、数字或下划线组成');
            }
            
            // 检查账号是否已存在
            $existingUser = Db::name('user')
                ->where('username', $data['username'])
                ->field('id')
                ->find();
            
            if ($existingUser) {
                return $this->returnError('该账号已注册');
            }
            
            // 如果需要手机号验证
            if ($registerPhoneVerify == 1) {
                if (empty($data['mobile'])) {
                    return $this->returnError('手机号不能为空');
                }
                
                // 验证手机号格式
                if (!preg_match('/^1[3-9]\d{9}$/', $data['mobile'])) {
                    return $this->returnError('手机号格式不正确');
                }
                
                // 检查手机号是否已注�?
                $existingMobile = Db::name('user')
                    ->where('mobile', $data['mobile'])
                    ->field('id')
                    ->find();
                
                if ($existingMobile) {
                    return $this->returnError('该手机号已注册');
                }
            }
            
            if (empty($data['password'])) {
                return $this->returnError('密码不能为空');
            }
            
            // 条件化验证图形验证码
            if ($registerCaptchaVerify == 1) {
                if (empty($data['captcha'])) {
                    return $this->returnError('图形验证码不能为空');
                }
                
                // 验证图形验证码
                if (strtoupper($data['captcha']) !== session('captcha')) {
                    return $this->returnError('图形验证码不正确');
                }
            }
            
            // 验证短信验证�?
            if ($registerPhoneVerify == 1 && $registerSmsVerify == 1) {
                if (empty($data['sms_code'])) {
                    return $this->returnError('短信验证码不能为空');
                }
                
                $smsCacheKey = 'sms_code_' . $data['mobile'];
                $smsCode = session($smsCacheKey);
                $smsExpire = session($smsCacheKey . '_expire');
                
                if (empty($smsCode) || empty($smsExpire)) {
                    return $this->returnError('短信验证码已过期，请重新获取');
                }
                
                if (time() > $smsExpire) {
                    return $this->returnError('短信验证码已过期，请重新获取');
                }
                
                if ($data['sms_code'] != $smsCode) {
                    return $this->returnError('短信验证码不正确');
                }
            }
            
            // 验证密码格式
            if (!preg_match('/^[a-zA-Z0-9]{6,16}$/', $data['password'])) {
                return $this->returnError('密码必须6-16位字母数字组成');
            }
            
            // 密码加密
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            $currentTime = time();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            
            // 插入用户数据
            $nickname = $data['nickname'] ?? '';

            $userId = Db::name('user')->insertGetId([
                'username' => $data['username'],
                'password' => $hashedPassword,
                'mobile' => $data['mobile'] ?? '',
                'name' => $nickname, // name字段使用昵称
                'nickname' => $nickname,
                'regtime' => $currentTime,
                'regip' => $ip,
                'logtime' => $currentTime,
                'logip' => $ip,
                'status' => 1, // 状态正�?
                'create_time' => $currentTime,
                'update_time' => $currentTime
            ]);
            
            if ($userId) {
                // 清除短信验证码（如果存在�?
                if (isset($smsCacheKey)) {
                    session($smsCacheKey, null);
                    session($smsCacheKey . '_time', null);
                    session($smsCacheKey . '_expire', null);
                }
                
                // 获取用户信息（不包含密码�?
                $user = Db::name('user')
                    ->where('id', $userId)
                    ->field('id, username, mobile, name, nickname, avatar, gender, birthday, bio, status, create_time')
                    ->find();
                
                return $this->success($user, '注册成功');
            } else {
                return $this->error('注册失败');
            }

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();

            if (strpos($errorMsg, 'SQLSTATE[HY000]') !== false || strpos($errorMsg, 'Access denied') !== false) {
                $errorMsg = '数据库连接失败，请检查数据库配置是否正确';
            } elseif (strpos($errorMsg, 'Connection refused') !== false) {
                $errorMsg = '无法连接到数据库服务器，请检查服务是否启动';
            } elseif (strpos($errorMsg, 'Unknown database') !== false) {
                $errorMsg = '数据库不存在，请检查数据库名称是否正确';
            } elseif (strpos($errorMsg, 'SQLSTATE[42S02]') !== false) {
                $errorMsg = '数据表不存在，请先完成数据库安装';
            } elseif (strpos($errorMsg, 'SQLSTATE[23000]') !== false || strpos($errorMsg, 'Duplicate entry') !== false) {
                $errorMsg = '该手机号已注册';
            }

            return $this->error('注册失败: ' . $errorMsg);
        }
    }

    /**
     * 用户登录（支持账�?手机�?密码、手机号+短信验证码三种方式）
     */
    public function login(Request $request)
    {
        // 处理GET请求，显示登录页�?
        if ($request->isGet()) {
            // 配置已在基类的initialize()方法中自动加�?
            return view('user/login');
        }
        
        // 处理POST请求，执行登录逻辑
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $data = $request->post();
            
            // 验证数据
            if (empty($data['username'])) {
                return $this->returnError('账号或手机号不能为空');
            }
            
            // 构建查询条件
            $where = [];
            if (preg_match('/^1[3-9]\d{9}$/', $data['username'])) {
                // 输入的是手机�?
                $where['mobile'] = $data['username'];
            } else {
                // 输入的是账号
                $where['username'] = $data['username'];
            }
            
            // 查找用户
            $user = Db::name('user')
                ->where($where)
                ->field('id, username, password, email, name, nickname, avatar, mobile, gender, birthday, bio, status, last_login_time, last_login_ip')
                ->find();
            
            if (!$user) {
                return $this->returnError('用户不存在');
            }
            
            // 检查账户状态
            if ($user['status'] != 1) {
                return $this->returnError('账户已被禁用');
            }
            
            // 判断登录方式
            if (!empty($data['password'])) {
                // 手机�?密码登录
                if (!password_verify($data['password'], $user['password'])) {
                    return $this->returnError('密码错误');
                }
            } elseif (!empty($data['sms_code'])) {
                // 手机�?短信验证码登�?
                $smsCacheKey = 'login_sms_code_' . $data['mobile'];
                $smsCode = session($smsCacheKey);
                $smsExpire = session($smsCacheKey . '_expire');
                
                if (empty($smsCode) || empty($smsExpire)) {
                    return $this->returnError('短信验证码已过期，请重新获取');
                }
                
                if (time() > $smsExpire) {
                    return $this->returnError('短信验证码已过期，请重新获取');
                }
                
                if ($data['sms_code'] != $smsCode) {
                    return $this->returnError('短信验证码不正确');
                }
                
                // 清除短信验证�?
                session($smsCacheKey, null);
                session($smsCacheKey . '_time', null);
                session($smsCacheKey . '_expire', null);
            } else {
                return $this->returnError('请选择登录方式（密码或短信验证码）');
            }
            
            // 更新登录信息
            $currentTime = time();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';

            // 获取设备信息
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $deviceType = $this->getDeviceType($userAgent);
            $browser = $this->getBrowser($userAgent);

            // 记录登录日志
            Db::name('login_logs')->insert([
                'user_id' => $user['id'],
                'login_ip' => $ip,
                'login_time' => date('Y-m-d H:i:s'),
                'device_type' => $deviceType,
                'browser' => $browser,
                'status' => 1,
                'is_abnormal' => 0,
                'abnormal_reason' => ''
            ]);

            Db::name('user')
                ->where('id', $user['id'])
                ->update([
                    'logtime' => $currentTime,
                    'logip' => $ip,
                    'last_login_time' => $currentTime,
                    'last_login_ip' => $ip,
                    'update_time' => $currentTime
                ]);

            // 移除密码字段
            unset($user['password']);

            // 使用think\facade\Session设置session
            Session::set('user_id', $user['id']);
            Session::set('username', $user['username']);
            Session::set('nickname', $user['nickname'] ?? $user['username']);
            Session::set('avatar', $user['avatar'] ?? '');
            Session::set('mobile', $user['mobile']);

            // 使用think\facade\Cookie设置cookie作为备用（24小时过期）
            $cookieConfig = [
                'expire' => 86400, // 24小时
                'path' => '/',
                'domain' => $_SERVER['HTTP_HOST'] ?? 'localhost', // 自动获取当前域名
            ];
            
            Cookie::set('user_id', (string)$user['id'], $cookieConfig);
            Cookie::set('username', (string)($user['username'] ?? ''), $cookieConfig);
            Cookie::set('nickname', (string)($user['nickname'] ?? $user['username'] ?? ''), $cookieConfig);
            Cookie::set('avatar', (string)($user['avatar'] ?? ''), $cookieConfig);
            Cookie::set('mobile', (string)($user['mobile'] ?? ''), $cookieConfig);

            // 插入会话记录到sessions�?
            $sessionId = session_id();
            if (empty($sessionId)) {
                $sessionId = md5($user['id'] . time() . mt_rand(1000, 9999));
            }
            
            $expireTime = $currentTime + 86400; // 24小时后过�?
            
            Db::name('sessions')->insert([
                'user_id' => $user['id'],
                'session_id' => $sessionId,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'device_type' => $deviceType,
                'device_name' => $browser,
                'expire_time' => $expireTime,
                'create_time' => $currentTime
            ]);

            return $this->success($user, '登录成功');

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();

            if (strpos($errorMsg, 'SQLSTATE[HY000]') !== false || strpos($errorMsg, 'Access denied') !== false) {
                $errorMsg = '数据库连接失败，请检查数据库配置是否正确';
            } elseif (strpos($errorMsg, 'Connection refused') !== false) {
                $errorMsg = '无法连接到数据库服务器，请检查服务是否启动';
            } elseif (strpos($errorMsg, 'Unknown database') !== false) {
                $errorMsg = '数据库不存在，请检查数据库名称是否正确';
            } elseif (strpos($errorMsg, 'SQLSTATE[42S02]') !== false) {
                $errorMsg = '数据表不存在，请先完成数据库安装';
            }

            return $this->error('登录失败: ' . $errorMsg);
        }
    }

    /**
     * 登录短信验证码发�?
     */
    public function sendLoginSms(Request $request)
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $data = $request->post();
            
            // 验证数据
            if (empty($data['mobile'])) {
                return $this->returnError('手机号不能为空');
            }
            
            // 验证手机号格式
            if (!preg_match('/^1[3-9]\d{9}$/', $data['mobile'])) {
                return $this->returnError('手机号格式不正确');
            }
            
            // 检查手机号是否已注�?
            $existingUser = Db::name('user')
                ->where('mobile', $data['mobile'])
                ->field('id')
                ->find();
            
            if (!$existingUser) {
                return $this->returnError('该手机号未注册');
            }
            
            // 检查发送频率
            $smsCacheKey = 'login_sms_code_' . $data['mobile'];
            $lastSendTime = session($smsCacheKey . '_time');
            
            if ($lastSendTime && (time() - $lastSendTime) < 60) {
                return $this->returnError('短信发送过于频繁，�?0秒后重试');
            }
            
            // 生成6位短信验证码
            $smsCode = mt_rand(100000, 999999);
            
            // 这里应该调用短信API发送验证码，现在我们只是模�?
            // 实际开发中需要替换为真实的短信发送接�?
            
            // 保存短信验证码到session
            session($smsCacheKey, $smsCode);
            session($smsCacheKey . '_time', time());
            session($smsCacheKey . '_expire', time() + 300); // 5分钟过期
            
            return $this->success(['sms_code' => $smsCode], '短信验证码发送成功');
        } catch (\Exception $e) {
            return $this->error('短信发送失败 ' . $e->getMessage());
        }
    }

    /**
     * 获取用户信息
     */
    public function profile(Request $request, $id = null)
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $userId = $id ?? $request->param('id') ?? $request->post('user_id');
            
            if (empty($userId)) {
                return $this->returnError('用户ID不能为空');
            }
            
            // 获取用户信息
            $user = Db::name('user')
                ->where('id', $userId)
                ->field('id, username, email, name, nickname, avatar, mobile, gender, birthday, bio, status, create_time, update_time, last_login_time, last_login_ip, card_background')
                ->find();
            
            // 兼容前端使用motto和bio两种字段�?
            if (isset($user['bio'])) {
                $user['motto'] = $user['bio'];
            }
            
            if (!$user) {
                return $this->returnError('用户不存在');
            }
            
            // 获取用户动态数
            $user['posts'] = Db::name('moments')->where('user_id', $userId)->count();

            // 获取用户粉丝�?
            $user['followers'] = Db::name('follows')->where('following_id', $userId)->count();

            // 获取用户关注�?
            $user['following'] = Db::name('follows')->where('follower_id', $userId)->count();
            
            // 获取用户收藏�?
            $user['favorites'] = Db::name('favorites')->where('user_id', $userId)->count();
            
            // 检查当前登录用户是否关注了该用�?
            $currentUserId = session('user_id');
            $user['is_following'] = false;
            if ($currentUserId && $currentUserId != $userId) {
                $followRecord = Db::name('follows')
                    ->where('follower_id', $currentUserId)
                    ->where('following_id', $userId)
                    ->find();
                $user['is_following'] = !!$followRecord;
            }
            
            return $this->success($user);
        } catch (\Exception $e) {
            return $this->error('获取用户信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新用户信息
     */
    public function updateProfile(Request $request)
    {
        try {
            $userId = session('user_id') ?? 0;

            if (empty($userId)) {
                return $this->unauthorized();
            }

            $data = $request->post();

            $user = Db::name('user')->where('id', $userId)->find();

            if (!$user) {
                return $this->notFound('用户不存在');
            }

            $updateData = [];

            $allowedFields = ['nickname', 'email', 'mobile', 'gender', 'birthday', 'bio', 'avatar', 'motto', 'occupation', 'interests', 'card_background'];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if ($field == 'bio' && isset($data['motto'])) {
                        $updateData['bio'] = $data['motto'];
                    } elseif ($field == 'motto' && isset($data['motto']) && !isset($data['bio'])) {
                        $updateData['bio'] = $data['motto'];
                    } else {
                        $updateData[$field] = $data[$field];
                    }
                }
            }

            if (empty($updateData)) {
                return $this->badRequest('没有要更新的字段');
            }

            $updateData['update_time'] = time();

            $result = Db::name('user')->where('id', $userId)->update($updateData);

            if ($result !== false) {
                if (isset($updateData['nickname'])) {
                    Db::name('comments')->where('user_id', $userId)->update(['nickname' => $updateData['nickname']]);
                    Db::name('moments')->where('user_id', $userId)->update(['nickname' => $updateData['nickname']]);
                }

                if (isset($updateData['avatar'])) {
                    Db::name('comments')->where('user_id', $userId)->update(['avatar' => $updateData['avatar']]);
                    Db::name('moments')->where('user_id', $userId)->update(['avatar' => $updateData['avatar']]);
                }

                $updatedUser = Db::name('user')
                    ->where('id', $userId)
                    ->field('id, username, email, name, nickname, avatar, mobile, gender, birthday, bio, status, create_time, update_time, last_login_time, last_login_ip, card_background')
                    ->find();

                if (isset($updatedUser['bio'])) {
                    $updatedUser['motto'] = $updatedUser['bio'];
                }

                return $this->success($updatedUser, '更新成功');
            } else {
                return $this->error('更新失败');
            }
        } catch (\Exception $e) {
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 修改密码
     */
    public function changePassword(Request $request)
    {
        try {
            $data = $request->post();
            $userId = $data['user_id'] ?? session('user_id') ?? cookie('user_id');
            $oldPassword = $data['old_password'] ?? '';
            $newPassword = $data['new_password'] ?? '';

            if (empty($userId) || empty($oldPassword) || empty($newPassword)) {
                return $this->badRequest('参数不完整');
            }

            if (strlen($newPassword) < 6) {
                return $this->badRequest('新密码长度不能少于6位');
            }

            $user = Db::name('user')->where('id', $userId)->find();

            if (!$user) {
                return $this->badRequest('用户不存在');
            }

            if (!password_verify($oldPassword, $user['password'])) {
                return $this->badRequest('原密码错误');
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $result = Db::name('user')
                ->where('id', $userId)
                ->update([
                    'password' => $hashedPassword,
                    'update_time' => time()
                ]);

            if ($result !== false) {
                return $this->success(null, '密码修改成功');
            } else {
                return $this->error('密码修改失败');
            }
        } catch (\Exception $e) {
            return $this->error('密码修改失败，请稍后重试');
        }
    }

    /**
     * 获取注册配置
     */
    public function getRegisterConfig()
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');
            
            // 获取系统配置
            $registerPhoneVerify = Db::name('system_config')->where('config_key', 'register_phone_verify')->value('config_value') ?? '1';
            $registerSmsVerify = Db::name('system_config')->where('config_key', 'register_sms_verify')->value('config_value') ?? '1';
            $registerCaptchaVerify = Db::name('system_config')->where('config_key', 'register_captcha_verify')->value('config_value') ?? '1';
            
            return $this->success([
                'register_phone_verify' => $registerPhoneVerify,
                'register_sms_verify' => $registerSmsVerify,
                'register_captcha_verify' => $registerCaptchaVerify
            ]);
        } catch (\Exception $e) {
            return $this->error('获取配置失败: ' . $e->getMessage());
        }
    }

    /**
     * 发送重置密码短信验证码
     */
    public function sendResetSms(Request $request)
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $data = $request->post();
            
            // 验证数据
            if (empty($data['mobile'])) {
                return $this->badRequest('手机号不能为空');
            }
            
            if (empty($data['captcha'])) {
                return $this->badRequest('图形验证码不能为空');
            }
            
            // 验证手机号格�?
            if (!preg_match('/^1[3-9]\d{9}$/', $data['mobile'])) {
                return $this->badRequest('手机号格式不正确');
            }
            
            // 验证图形验证�?
            if (strtoupper($data['captcha']) !== session('captcha')) {
                return $this->badRequest('图形验证码不正确');
            }
            
            // 检查手机号是否已注�?
            $existingUser = Db::name('user')
                ->where('mobile', $data['mobile'])
                ->field('id')
                ->find();
            
            if (!$existingUser) {
                return $this->badRequest('该手机号未注册');
            }
            
            // 检查发送频率
            $smsCacheKey = 'reset_sms_code_' . $data['mobile'];
            $lastSendTime = session($smsCacheKey . '_time');
            
            if ($lastSendTime && (time() - $lastSendTime) < 60) {
                return $this->badRequest('短信发送过于频繁，60秒后重试');
            }
            
            // 生成6位短信验证码
            $smsCode = mt_rand(100000, 999999);
            
            // 这里应该调用短信API发送验证码，现在我们只是模�?
            // 实际开发中需要替换为真实的短信发送接�?
            
            return $this->success(['sms_code' => $smsCode], '短信验证码发送成功');
        } catch (\Exception $e) {
            return $this->error('短信发送失败 ' . $e->getMessage());
        }
    }

    /**
     * 重置密码
     */
    public function resetPassword(Request $request)
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $data = $request->post();
            
            // 验证数据
            if (empty($data['mobile'])) {
                return $this->badRequest('手机号不能为空');
            }
            
            if (empty($data['sms_code'])) {
                return $this->badRequest('短信验证码不能为空');
            }
            
            if (empty($data['new_password'])) {
                return $this->badRequest('新密码不能为空');
            }
            
            // 验证手机号格式
            if (!preg_match('/^1[3-9]\d{9}$/', $data['mobile'])) {
                return $this->badRequest('手机号格式不正确');
            }
            
            // 验证新密码格�?
            if (!preg_match('/^[a-zA-Z0-9]{6,16}$/', $data['new_password'])) {
                return $this->badRequest('密码必须6-16位字母数字组成');
            }
            
            // 验证短信验证码
            $smsCacheKey = 'reset_sms_code_' . $data['mobile'];
            $smsCode = session($smsCacheKey);
            $smsExpire = session($smsCacheKey . '_expire');
            $userId = session($smsCacheKey . '_user_id');
            
            if (empty($smsCode) || empty($smsExpire) || empty($userId)) {
                return $this->badRequest('短信验证码已过期，请重新获取');
            }
            
            if (time() > $smsExpire) {
                return $this->badRequest('短信验证码已过期，请重新获取');
            }
            
            if ($data['sms_code'] != $smsCode) {
                return $this->badRequest('短信验证码不正确');
            }
            
            // 加密新密�?
            $newPasswordHash = password_hash($data['new_password'], PASSWORD_DEFAULT);
            
            // 更新密码
            $result = Db::name('user')
                ->where('id', $userId)
                ->where('mobile', $data['mobile']) // 双重验证
                ->update(['password' => $newPasswordHash]);
            
            if ($result !== false) {
                // 清除重置密码相关session
                session($smsCacheKey, null);
                session($smsCacheKey . '_time', null);
                session($smsCacheKey . '_expire', null);
                session($smsCacheKey . '_user_id', null);
                
                return $this->success(null, '密码重置成功');
            } else {
                return $this->error('密码重置失败');
            }
        } catch (\Exception $e) {
            return $this->error('密码重置失败: ' . $e->getMessage());
        }
    }

    /**
     * 用户列表（分页）
     */
    public function listUsers(Request $request)
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $page = $request->get('page', 1);
            $limit = $request->get('limit', 20);
            $keyword = $request->get('keyword', '');
            
            $page = max(1, intval($page));
            $limit = max(1, min(100, intval($limit)));
            $offset = ($page - 1) * $limit;
            
            // 构建查询条件
            $where = "status = 1";
            $params = [];
            
            if (!empty($keyword)) {
                $where .= " AND (username LIKE ? OR nickname LIKE ? OR email LIKE ?)";
                $likeKeyword = "%$keyword%";
                $params = array_merge($params, [$likeKeyword, $likeKeyword, $likeKeyword]);
            }
            
            // 使用ThinkPHP Db类构建查�?
            $query = Db::name('user');
            
            // 动态添加条�?
            if (isset($status)) {
                $query->where('status', $status);
            }
            
            if (isset($role)) {
                $query->where('role', $role);
            }
            
            if (!empty($keyword)) {
                $escapedKeyword = Db::escape($keyword);
                $query->where(function($query) use ($escapedKeyword) {
                    $query->where('username', 'like', '%' . $escapedKeyword . '%')
                          ->whereOr('nickname', 'like', '%' . $escapedKeyword . '%')
                          ->whereOr('email', 'like', '%' . $escapedKeyword . '%');
                });
            }
            
            // 获取总数
            $total = $query->count();
            
            // 获取用户列表
            $users = $query->field('id, username, email, nickname, avatar, gender, bio, create_time, last_login_time')
                          ->order('create_time', 'desc')
                          ->page($page, $limit)
                          ->select()
                          ->toArray();
            
            return $this->success([
                'list' => $users,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]);
        } catch (\Exception $e) {
            return $this->error('获取用户列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新用户设置
     */
    /**
     * 搜索用户
     */
    public function searchUsers(Request $request)
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $keyword = $request->get('keyword', '');
            
            if (empty($keyword)) {
                return $this->returnError('搜索关键词不能为空');
            }
            
            // 使用ThinkPHP Db类查询
            $users = Db::name('user')
                ->field('id, username, nickname, avatar, bio')
                ->where('status', 1)
                ->where(function($query) use ($keyword) {
                    $query->where('username', 'like', '%' . $keyword . '%')
                          ->whereOr('nickname', 'like', '%' . $keyword . '%')
                          ->whereOr('email', 'like', '%' . $keyword . '%');
                })
                ->order('create_time', 'desc')
                ->limit(20)
                ->select()
                ->toArray();
            
            return $this->success($users);
        } catch (\Exception $e) {
            return $this->error('搜索用户失败: ' . $e->getMessage());
        }
    }

    /**
     * 退出登�?
     */
    public function logout()
    {
        try {
            // 获取当前用户ID
            $userId = session('user_id') ?: cookie('user_id');
            
            // 更新用户在线状态为离线
            if ($userId) {
                Db::name('user')
                    ->where('id', $userId)
                    ->update([
                        'is_online' => 0,
                        'last_heartbeat_time' => null
                    ]);
                
                // 删除sessions表中的会话记�?
                Db::name('sessions')
                    ->where('user_id', $userId)
                    ->delete();
            }
            
            // 清除session
            session(null);

            // 清除cookie
            cookie('user_id', null);
            cookie('username', null);
            cookie('nickname', null);
            cookie('avatar', null);

            return $this->success(null, '退出成功');
        } catch (\Exception $e) {
            return $this->error('退出失败: ' . $e->getMessage());
        }
    }

    /**
     * 心跳检测API
     * 用于保持用户在线状态
     */
    public function heartbeat()
    {
        try {
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $currentTime = time();

            // 更新用户在线状态
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'is_online' => 1,
                    'last_heartbeat_time' => $currentTime
                ]);

            return $this->success([
                'timestamp' => $currentTime
            ], '心跳成功');
        } catch (\Exception $e) {
            return $this->error('心跳失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取当前登录用户信息（允许游客访问）
     */
    public function getCurrentUser()
    {
        try {
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->success(null, '未登录');
            }

            $user = Db::name('user')
                ->field('id, username, email, name, nickname, avatar, mobile, gender, birthday, bio, status, real_name, occupation, card_background, card_stealth, card_layout')
                ->where('id', $userId)
                ->find();

            if (!$user) {
                session(null);
                return $this->success(null, '用户不存在');
            }

            return $this->success($user, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取用户信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取用户头像
     */
    public function avatar()
    {
        try {
            $userId = input('id');

            if (empty($userId)) {
                // 返回默认头像
                $defaultAvatarPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'default-avatar.svg';
                if (file_exists($defaultAvatarPath)) {
                    $content = file_get_contents($defaultAvatarPath);
                    return response($content, 200)->contentType('image/svg+xml');
                }
                // 如果默认头像不存在，返回SVG格式的默认头�?
                $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="120" height="120"><defs><linearGradient id="avatarGradient" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:#4facfe;stop-opacity:1"/><stop offset="100%" style="stop-color:#00f2fe;stop-opacity:1"/></linearGradient></defs><circle cx="60" cy="60" r="60" fill="url(#avatarGradient)"/><circle cx="60" cy="45" r="25" fill="white" fill-opacity="0.9"/><ellipse cx="60" cy="95" rx="35" ry="20" fill="white" fill-opacity="0.9"/></svg>';
                return response($svg, 200)->contentType('image/svg+xml');
            }

            // 获取用户头像信息
            $user = Db::name('user')
                ->field('avatar')
                ->where('id', $userId)
                ->find();

            if (!$user || empty($user['avatar'])) {
                // 用户没有设置头像，返回默认头�?
                $defaultAvatarPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'default-avatar.png';
                if (file_exists($defaultAvatarPath)) {
                    $content = file_get_contents($defaultAvatarPath);
                    return response($content, 200)->contentType('image/png');
                }
                $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="120" height="120"><defs><linearGradient id="avatarGradient" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:#4facfe;stop-opacity:1"/><stop offset="100%" style="stop-color:#00f2fe;stop-opacity:1"/></linearGradient></defs><circle cx="60" cy="60" r="60" fill="url(#avatarGradient)"/><circle cx="60" cy="45" r="25" fill="white" fill-opacity="0.9"/><ellipse cx="60" cy="95" rx="35" ry="20" fill="white" fill-opacity="0.9"/></svg>';
                return response($svg, 200)->contentType('image/svg+xml');
            }

            // 用户有自定义头像
            $avatarPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . ltrim($user['avatar'], '/');
            if (file_exists($avatarPath)) {
                $content = file_get_contents($avatarPath);
                $ext = pathinfo($avatarPath, PATHINFO_EXTENSION);
                $contentType = 'image/' . $ext;
                return response($content, 200)->contentType($contentType);
            }

            // 如果头像文件不存在，返回默认头像
            $defaultAvatarPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'default-avatar.png';
            if (file_exists($defaultAvatarPath)) {
                $content = file_get_contents($defaultAvatarPath);
                return response($content, 200)->contentType('image/png');
            }

            return $this->returnError('头像不存在');

        } catch (\Exception $e) {
            return $this->returnError('获取头像失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取用户动态列�?
     */
    public function getUsermoments(Request $request)
    {
        try {
            // 优先从session获取，如果没有则从cookie获取
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            // 同时支持GET和POST请求
            $page = intval($request->param('page', 1));
            $limit = intval($request->param('limit', 20));
            $offset = ($page - 1) * $limit;

            // 使用ThinkPHP Db类查�?
            $prefix = Db::getConfig('database.prefix');
            $query = Db::name('moments')
                ->field([
                    'm.*',
                    '(SELECT COUNT(*) FROM `{$prefix}moments_likes` WHERE moment_id = m.id) as like_count',
                    '(SELECT COUNT(*) FROM `{$prefix}moments_comments` WHERE moment_id = m.id) as comment_count',
                    '(SELECT COUNT(*) FROM `{$prefix}favorites` WHERE target_id = m.id AND target_type = 1) as favorite_count'
                ])
                ->alias('m')
                ->where('m.user_id', $userId)
                ->where('m.status', 1)
                ->order('m.create_time', 'desc');

            // 获取总数
            $total = $query->count();

            // 获取动态列�?
            $moments = $query->page($page, $limit)
                          ->select()
                          ->toArray();

            return $this->success([
                'list' => $moments,
                'page' => $page,
                'limit' => $limit,
                'total' => $total
            ], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取动态列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取用户收藏列表
     */
    public function getUserFavorites(Request $request)
    {
        try {
            $userId = session('user_id');
            
            if (!$userId) {
                return $this->unauthorized();
            }

            $page = $request->get('page', 1);
            $limit = $request->get('limit', 10);
            $offset = ($page - 1) * $limit;
            
            // 使用ThinkPHP Db类查�?
            $favorites = Db::name('favorites')
                ->alias('f')
                ->join('moments m', 'f.target_id = m.id')
                ->field('m.*, f.create_time as favorite_time')
                ->where('f.user_id', $userId)
                ->where('f.target_type', 1)
                ->where('m.status', 1)
                ->order('f.create_time', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            return $this->success([
                'list' => $favorites,
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (\Exception $e) {
            return $this->error('获取收藏列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取用户关注列表
     */
    public function getFollowing(Request $request)
    {
        try {
            $sessionUserId = session('user_id');
            $cookieUserId = cookie('user_id');
            $userId = $sessionUserId ?: $cookieUserId;

            if (!$userId) {
                return $this->unauthorized();
            }

            $keyword = $request->param('keyword', '');
            $keyword = trim($keyword);

            $query = Db::name('follows')
                ->alias('uf')
                ->join('user u', 'uf.following_id = u.id')
                ->where('uf.follower_id', $userId)
                ->field('u.id as user_id, u.username, u.nickname, u.avatar, u.bio');

            if (!empty($keyword)) {
                $query->where('u.nickname', 'like', '%' . $keyword . '%')
                    ->whereOr('u.username', 'like', '%' . $keyword . '%');
            }

            $following = $query
                ->order('uf.create_time', 'desc')
                ->select()
                ->toArray();

            $users = [];
            foreach ($following as $item) {
                $users[] = [
                    'user_id' => $item['user_id'],
                    'username' => $item['username'],
                    'nickname' => $item['nickname'],
                    'avatar' => $item['avatar'],
                    'bio' => $item['bio']
                ];
            }

            return $this->success(['users' => $users], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取关注列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取用户粉丝列表
     */
    public function getFollowers(Request $request)
    {
        try {
            // 优先从session获取，如果没有则从cookie获取
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            // 同时支持GET和POST请求
            $page = intval($request->param('page', 1));
            $limit = intval($request->param('limit', 20));
            $offset = ($page - 1) * $limit;

            $followers = Db::name('follows')
                ->alias('uf')
                ->join('user u', 'uf.follower_id = u.id')
                ->where('uf.following_id', $userId)
                ->field('u.id, u.username, u.nickname, u.avatar, u.bio, uf.create_time as follow_time')
                ->order('uf.create_time', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            return $this->success([
                'list' => $followers,
                'page' => $page,
                'limit' => $limit,
                'total' => Db::name('follows')->where('following_id', $userId)->count()
            ], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取粉丝列表失败: ' . $e->getMessage());
        }
    }



    /**
     * 取消关注用户
     */
    public function unfollow(Request $request)
    {
        try {
            // 优先从session获取，如果没有则从cookie获取
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $followingId = intval($request->param('user_id'));

            if (!$followingId) {
                return $this->badRequest('目标用户ID不能为空');
            }

            if ($userId == $followingId) {
                return $this->badRequest('不能取消关注自己');
            }

            $exists = Db::name('follows')
                ->where('follower_id', $userId)
                ->where('following_id', $followingId)
                ->find();

            if (!$exists) {
                return $this->badRequest('尚未关注该用户');
            }

            Db::name('follows')
                ->where('follower_id', $userId)
                ->where('following_id', $followingId)
                ->delete();

            Db::name('user')->where('id', $followingId)->setDec('follower_count');
            Db::name('user')->where('id', $userId)->setDec('following_count');

            return $this->success(['is_following' => false], '取消关注成功');
        } catch (\Exception $e) {
            return $this->error('取消关注失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取用户通知列表
     */
    public function getNotifications(Request $request)
    {
        try {
            $userId = session('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $page = $request->get('page', 1);
            $limit = $request->get('limit', 20);
            $offset = ($page - 1) * $limit;

            $prefix = Db::getConfig('database.prefix');
            $notifications = Db::query("                SELECT * FROM `{$prefix}notifications` 
                WHERE user_id = :userId
                ORDER BY create_time DESC
                LIMIT :limit OFFSET :offset
            ", [
                'userId' => $userId,
                'limit' => intval($limit),
                'offset' => intval($offset)
            ]);

            $unreadCount = Db::query("                SELECT COUNT(*) as count FROM `{$prefix}notifications` 
                WHERE user_id = :userId AND is_read = 0
            ", [
                'userId' => $userId
            ])[0]['count'];

            return $this->success([
                'list' => $notifications,
                'unread_count' => $unreadCount,
                'page' => $page,
                'limit' => $limit
            ], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取通知失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取用户登录日志
     */
    public function getLoginLogs(Request $request)
    {
        try {
            $userId = session('user_id');
            
            if (!$userId) {
                return $this->unauthorized();
            }

            $page = $request->get('page', 1);
            $limit = $request->get('limit', 20);
            $offset = ($page - 1) * $limit;

            $prefix = Db::getConfig('database.prefix');
            $logs = Db::query("                SELECT * FROM `{$prefix}user_login_logs` 
                WHERE user_id = :userId
                ORDER BY login_time DESC
                LIMIT :limit OFFSET :offset
            ", [
                'userId' => $userId,
                'limit' => intval($limit),
                'offset' => intval($offset)
            ]);

            return $this->success([
                'list' => $logs,
                'page' => $page,
                'limit' => $limit
            ], '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取登录日志失败: ' . $e->getMessage());
        }
    }

    /**
     * 上传用户头像
     */
    public function uploadAvatar(Request $request)
    {
        try {
            // 设置CORS头部
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $userId = session('user_id');
            
            if (!$userId) {
                return $this->unauthorized();
            }

            $file = $request->file('avatar');

            if (!$file) {
                return $this->badRequest('请选择要上传的图片');
            }

            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = $file->getMime();
            if (!in_array($fileType, $allowedTypes)) {
                return $this->badRequest('只允许上传JPG、PNG、GIF和WEBP格式的图片');
            }

            $maxSize = 2 * 1024 * 1024;
            if ($file->getSize() > $maxSize) {
                return $this->badRequest('图片大小不能超过2MB');
            }

            // 创建上传目录
            $savePath = app()->getRootPath() . 'public/uploads/avatar/' . date('Ym');
            if (!is_dir($savePath)) {
                mkdir($savePath, 0755, true);
            }
            
            $ext = strtolower(pathinfo($file->getOriginalName(), PATHINFO_EXTENSION));
            $filename = $userId . '_' . time() . '.' . $ext;
            $file->move($savePath, $filename);
            
            // 更新数据�?
            $avatarUrl = '/uploads/avatar/' . date('Ym') . '/' . $filename;
            
            // 更新用户�?
            Db::name('user')->where('id', $userId)->update([
                'avatar' => $avatarUrl,
                'update_time' => time()
            ]);

            // 同时更新相关表中的头�?
            Db::name('comments')->where('user_id', $userId)->update(['avatar' => $avatarUrl]);
            Db::name('moments')->where('user_id', $userId)->update(['avatar' => $avatarUrl]);

            return $this->success(['avatar' => $avatarUrl], '头像上传成功');
        } catch (\Exception $e) {
            error_log('头像上传失败: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
            return $this->error('头像上传失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取名片信息
     */
    public function card()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $user_id = input('user_id');
            $visitor_id = session('user_id');

            if (empty($user_id)) {
                return $this->returnError('用户ID不能为空');
            }

            // 确保 user_id 是数�?
            if (!is_numeric($user_id)) {
                return $this->returnError('无效的用户ID');
            }

            // 缓存�?
            $cacheKey = 'card_' . $user_id;
            $cacheTime = 3600; // 1小时缓存
            
            // 尝试从缓存获�?
            $cardData = cache($cacheKey);
            
            if (!$cardData) {
                // 缓存不存在，从数据库获取
                // 使用User模型获取用户信息和统计数�?
                $userModel = \app\model\User::withCount([
                    // 粉丝数（别人关注我）
                    'followers' => function($query) {
                        $query->where('status', 1);
                    },
                    // 关注数（我关注别人）
                    'followings' => function($query) {
                        $query->where('status', 1);
                    },
                    // 动态数
                    'moments' => function($query) {
                        $query->where('status', 1);
                    },
                    // 收藏�?
                    'favorites'
                ])->where('id', intval($user_id))->find();
                
                if (!$userModel) {
                    return $this->returnError('用户不存在');
                }
                
                // 转换为数组
                $user = $userModel->toArray();
                
                // 兼容前端使用motto和bio两种字段�?
                if (isset($user['bio'])) {
                    $user['motto'] = $user['bio'];
                }

                // 格式化生�?
                if ($user['birthday']) {
                    // 如果是日期格式，转换为时间戳
                    if (strpos($user['birthday'], '-') !== false) {
                        $user['birthday'] = strtotime($user['birthday']);
                    }
                }

                // 计算获赞数（需要联表查询）
                $likeCount = \think\facade\Db::name('likes')
                    ->alias('l')
                    ->join('moments m', 'l.target_id = m.id')
                    ->where('l.target_type', 1)
                    ->where('m.user_id', $user_id)
                    ->count();
                
                // 计算访问�?
                $visitorCount = \think\facade\Db::name('card_visitors')
                    ->where('user_id', $user_id)
                    ->count();
                
                // 组装统计数据
                $stats = [
                    'followers' => $user['followers_count'] ?? 0,
                    'following' => $user['followings_count'] ?? 0,
                    'moments' => $user['moments_count'] ?? 0,
                    'likes' => $likeCount,
                    'favorites' => $user['favorites_count'] ?? 0,
                    'visitors' => $visitorCount
                ];
                
                // 移除模型自动添加的count字段，避免干扰前�?
                unset($user['followers_count'], $user['followings_count'], $user['moments_count'], $user['favorites_count']);
                
                // 组装卡片数据
                $cardData = [
                    'user' => $user,
                    'stats' => $stats
                ];
                
                // 存入缓存
                cache($cacheKey, $cardData, $cacheTime);
            } else {
                // 从缓存获取数�?
                $user = $cardData['user'];
                $stats = $cardData['stats'];
            }

            if (!isset($cardData['recent_moments']) || !isset($cardData['recent_moments'][0]['videos'])) {
                $recent_moments = Db::name('moments')
                    ->where('user_id', $user_id)
                    ->where('status', 1)
                    ->order('create_time', 'desc')
                    ->limit(3)
                    ->field('id,content,images,videos,likes,comments,create_time')
                    ->select()
                    ->toArray();

                foreach ($recent_moments as &$moment) {
                    if (!empty($moment['images'])) {
                        if (is_string($moment['images'])) {
                            $decoded = json_decode($moment['images'], true);
                            $moment['images'] = is_array($decoded) ? $decoded : [];
                        } else if (!is_array($moment['images'])) {
                            $moment['images'] = [];
                        }
                    } else {
                        $moment['images'] = [];
                    }

                    if (!empty($moment['videos'])) {
                        if (is_string($moment['videos'])) {
                            $decoded = json_decode($moment['videos'], true);
                            $moment['videos'] = is_array($decoded) ? $decoded : [];
                        } else if (!is_array($moment['videos'])) {
                            $moment['videos'] = [];
                        }
                    } else {
                        $moment['videos'] = [];
                    }
                }

                $cardData['recent_moments'] = $recent_moments;
                cache($cacheKey, $cardData, $cacheTime);
            } else {
                $recent_moments = $cardData['recent_moments'];
            }

            // 记录访客
            if ($visitor_id && $visitor_id != $user_id) {
                $stealth = Db::name('user')->where('id', $visitor_id)->value('card_stealth');
                if (!$stealth) {
                    $todayStart = strtotime(date('Y-m-d 00:00:00'));
                    $todayEnd = strtotime(date('Y-m-d 23:59:59'));

                    $exists = Db::name('card_visitors')
                        ->where('user_id', $user_id)
                        ->where('visitor_id', $visitor_id)
                        ->where('visit_time', '>=', $todayStart)
                        ->where('visit_time', '<=', $todayEnd)
                        ->find();

                    if (!$exists) {
                        Db::name('card_visitors')->insert([
                            'user_id' => $user_id,
                            'visitor_id' => $visitor_id,
                            'visit_time' => time()
                        ]);
                    }
                }
            }

            // 检查关注状�?
            $is_following = false;
            if ($visitor_id) {
                $is_following = Db::name('follows')
                    ->where('follower_id', $visitor_id)
                    ->where('following_id', $user_id)
                    ->find() ? true : false;
            }

            $is_blocked = false;
            if ($visitor_id) {
                $is_blocked = Db::name('blacklist')
                    ->where('user_id', $visitor_id)
                    ->where('block_id', $user_id)
                    ->find() ? true : false;
            }

            return $this->success([
                'user' => $user,
                'stats' => $stats,
                'recent_moments' => $recent_moments,
                'is_following' => $is_following,
                'is_blocked' => $is_blocked,
                'can_view' => true
            ]);
        } catch (\Exception $e) {
            return $this->error('获取名片信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新名片设置
     */
    public function updateCardSettings()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $userId = session('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $data = request()->post();

            $updateData = [];
            $allowedFields = [
                'card_background', 'card_theme_color', 'card_layout',
                'real_name', 'gender', 'birthday',
                'occupation', 'bio', 'interests', 'card_stealth'
            ];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if ($field == 'birthday' && !empty($data[$field])) {
                        if (strpos($data[$field], '-') !== false) {
                            $updateData[$field] = $data[$field];
                        } else {
                            $updateData[$field] = date('Y-m-d', $data[$field]);
                        }
                    } else {
                        $updateData[$field] = $data[$field];
                    }
                }
            }

            if (empty($updateData)) {
                return $this->error('没有要更新的字段');
            }

            $updateData['update_time'] = time();

            $result = Db::name('user')->where('id', $userId)->update($updateData);

            if ($result !== false) {
                return $this->success(null, '更新成功');
            } else {
                return $this->error('更新失败');
            }
        } catch (\Exception $e) {
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取访客列表
     */
    public function cardVisitors()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $userId = session('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $page = intval(input('page', 1));
            $limit = intval(input('limit', 20));

            $visitors = Db::name('card_visitors')
                ->alias('cv')
                ->join('user u', 'cv.visitor_id = u.id')
                ->where('cv.user_id', $userId)
                ->field('cv.visitor_id, cv.visit_time, u.username, u.nickname, u.avatar, u.bio, u.level, u.vip_level')
                ->order('cv.visit_time', 'desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);

            return $this->success([
                'list' => $visitors->items(),
                'total' => $visitors->total(),
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (\Exception $e) {
            return $this->error('获取访客列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取@提及列表
     */
    public function getMentions()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $userId = session('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $page = intval(input('page', 1));
            $limit = intval(input('limit', 20));

            $mentions = Db::name('mentions')
                ->alias('m')
                ->join('user u', 'm.user_id = u.id')
                ->where('m.mentioned_user_id', $userId)
                ->field('m.id, m.moment_id, m.user_id, m.nickname, m.avatar, m.content, m.create_time, m.read_status, u.username')
                ->order('m.create_time', 'desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);

            return $this->success([
                'list' => $mentions->items(),
                'total' => $mentions->total(),
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (\Exception $e) {
            return $this->error('获取@提及列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 标记@提及为已�?
     */
    public function markMentionRead()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $userId = session('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $mentionId = intval(input('mention_id'));

            if (!$mentionId) {
                return $this->badRequest('参数错误');
            }

            $result = Db::name('mentions')
                ->where('id', $mentionId)
                ->where('mentioned_user_id', $userId)
                ->update([
                    'read_status' => 1,
                    'read_time' => time()
                ]);

            return $this->success(null, '标记成功');
        } catch (\Exception $e) {
            return $this->error('标记失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新账户设置
     */
    public function updateSettings()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $userId = input('user_id') ?: session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $request = \think\facade\Request::instance();
            $updateData = [];
            $allowedFields = [
                'nickname', 'email', 'birthday', 'occupation', 'bio',
                'gender', 'card_privacy'
            ];

            foreach ($allowedFields as $field) {
                $value = $request->post($field);
                if ($value !== null && $value !== '') {
                    $updateData[$field] = $value;
                }
            }

            if (isset($updateData['nickname'])) {
                $updateData['name'] = $updateData['nickname'];
            }

            if (!empty($updateData)) {
                Db::name('user')->where('id', $userId)->update($updateData);

                // 如果更新了nickname，同时更新session
                if (isset($updateData['nickname'])) {
                    session('nickname', $updateData['nickname']);
                    cookie('nickname', $updateData['nickname'], 7 * 86400);
                }

                // 如果更新了avatar，同时更新session
                if (isset($updateData['avatar'])) {
                    session('avatar', $updateData['avatar']);
                    cookie('avatar', $updateData['avatar'], 7 * 86400);
                }
            }

            return $this->success($updateData, '保存成功');
        } catch (\Exception $e) {
            return $this->error('保存失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取当前登录用户资料
     */
    public function getCurrentUserProfile()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = input('user_id') ?: session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $user = Db::name('user')
                ->where('id', $userId)
                ->field('id,username,nickname,avatar,email,real_name,gender,birthday,occupation,bio,interests,card_background,card_theme_color,card_layout,card_privacy,card_stealth,vip_level,level,experience,coins,province,city,district')
                ->find();

            if (!$user) {
                return $this->notFound('用户不存在');
            }

            $followingCount = Db::name('follows')
                ->where('follower_id', $userId)
                ->where('status', 1)
                ->count();

            $followersCount = Db::name('follows')
                ->where('following_id', $userId)
                ->where('status', 1)
                ->count();

            $postsCount = Db::name('moments')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->count();

            $favoritesCount = Db::name('favorites')
                ->where('user_id', $userId)
                ->count();

            $user['following'] = $followingCount;
            $user['followers'] = $followersCount;
            $user['posts'] = $postsCount;
            $user['favorites'] = $favoritesCount;

            return $this->success($user);
        } catch (\Exception $e) {
            return $this->error('获取用户信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成名片二维�?
     */
    public function cardQrcode()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $userId = session('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $cardUrl = request()->domain() . '/card/' . $userId;
            $qrcodeUrl = $cardUrl;

            return $this->success([
                'qrcode_url' => $qrcodeUrl,
                'card_url' => $cardUrl
            ]);
        } catch (\Exception $e) {
            return $this->error('生成二维码失败: ' . $e->getMessage());
        }
    }

    /**
     * 关注/取消关注用户
     */
    public function follow(Request $request)
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = session('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $data = $request->post();
            $targetUserId = $data['target_id'] ?? null;
            $action = $data['action'] ?? 'follow'; // follow �?unfollow

            if (!$targetUserId) {
                return $this->returnError('目标用户ID不能为空');
            }

            if ($userId == $targetUserId) {
                return $this->returnError('不能关注自己');
            }

            // 检查目标用户是否存�?
            $targetUser = Db::name('user')->where('id', $targetUserId)->find();
            if (!$targetUser) {
                return $this->returnError('目标用户不存在');
            }

            // 检查是否已关注
            $existingFollow = Db::name('follows')
                ->where('follower_id', $userId)
                ->where('following_id', $targetUserId)
                ->find();

            if ($action == 'follow') {
                if ($existingFollow) {
                    return $this->success(['following' => true], '已关注');
                }

                Db::name('follows')->insert([
                    'follower_id' => $userId,
                    'following_id' => $targetUserId,
                    'create_time' => time()
                ]);

                $userInfo = Db::name('user')->where('id', $userId)->field('nickname')->find();
                $nickname = $userInfo['nickname'] ?? '用户';

                Db::name('notifications')->insert([
                    'user_id' => $targetUserId,
                    'sender_id' => $userId,
                    'type' => 3,
                    'title' => '新的关注',
                    'content' => $nickname . '关注了你',
                    'target_id' => $userId,
                    'target_type' => 'user',
                    'create_time' => date('Y-m-d H:i:s')
                ]);

                return $this->success(['following' => true], '关注成功');
            } else {
                if (!$existingFollow) {
                    return $this->success(['following' => false], '未关注');
                }

                Db::name('follows')
                    ->where('follower_id', $userId)
                    ->where('following_id', $targetUserId)
                    ->delete();

                return $this->success(['following' => false], '取消关注成功');
            }
        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 检查登录状�?
     */
    public function check()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return json([
                    'code' => 200,
                    'msg' => '未登录',
                    'data' => null
                ]);
            }

            $user = Db::name('user')
                ->field('id, username, nickname, avatar')
                ->where('id', $userId)
                ->find();

            if (!$user) {
                return json([
                    'code' => 200,
                    'msg' => '用户不存在',
                    'data' => null
                ]);
            }

            return json([
                'code' => 200,
                'msg' => '已登录',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '检查登录状态失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 拉黑/解除拉黑用户
     */
    public function block()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $userId = session('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $targetUserId = input('target_id');

            if (!$targetUserId) {
                return $this->badRequest('目标用户ID不能为空');
            }

            if ($userId == $targetUserId) {
                return $this->badRequest('不能拉黑自己');
            }

            $targetUser = Db::name('user')->where('id', $targetUserId)->find();
            if (!$targetUser) {
                return $this->badRequest('目标用户不存在');
            }

            // 检查是否已在黑名单 - 使用user_blacklist表名
            $existingBlock = Db::name('user_blacklist')
                ->where('user_id', $userId)
                ->where('block_id', $targetUserId)
                ->find();

            $action = input('action', 'block'); // block �?unblock

            if ($action == 'block') {
                if ($existingBlock) {
                    return $this->success(null, '已在黑名单');
                }

                Db::name('user_blacklist')->insert([
                    'user_id' => $userId,
                    'block_id' => $targetUserId,
                    'create_time' => time()
                ]);

                return $this->success(null, '拉黑成功');
            } else {
                if (!$existingBlock) {
                    return $this->success(null, '未拉黑');
                }

                Db::name('user_blacklist')
                    ->where('user_id', $userId)
                    ->where('block_id', $targetUserId)
                    ->delete();

                return $this->success(null, '解除拉黑成功');
            }
        } catch (\Exception $e) {
            return $this->returnError('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取关注列表
     */
    public function getFollowings()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = input('user_id') ?: session('user_id');
            $page = intval(input('page', 1));
            $limit = intval(input('limit', 10));
            $page = max(1, $page);
            $limit = max(1, min(50, $limit));
            $offset = ($page - 1) * $limit;
            $currentUserId = session('user_id') ?? null;

            if (!$userId) {
                return $this->badRequest('用户ID不能为空');
            }

            // 获取关注列表
            $followings = Db::name('follows')
                ->alias('f')
                ->join('user u', 'f.following_id = u.id')
                ->where('f.follower_id', $userId)
                ->field('u.id, u.username, u.nickname, u.avatar, u.bio, u.level, u.vip_level')
                ->order('f.create_time', 'desc')
                ->limit($offset, $limit)
                ->select();

            // 获取总数
            $total = Db::name('follows')
                ->where('follower_id', $userId)
                ->count();

            // 获取当前用户对关注列表中用户的关注状�?
            if ($currentUserId && $currentUserId != $userId) {
                $followingIds = array_column($followings, 'id');
                if (!empty($followingIds)) {
                    $currentUserFollowings = Db::name('follows')
                        ->where('follower_id', $currentUserId)
                        ->where('following_id', 'in', $followingIds)
                        ->column('following_id');

                    foreach ($followings as &$user) {
                        $user['is_following'] = in_array($user['id'], $currentUserFollowings) ? 1 : 0;
                    }
                }
            }

            return $this->success([
                'list' => $followings,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (\Exception $e) {
            return $this->error('获取关注列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取关注状�?
     */
    public function getFollowStatus()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $currentUserId = session('user_id') ?? null;
            $targetUserId = input('target_id') ?: input('user_id');

            if (!$currentUserId) {
                return $this->unauthorized();
            }

            if (!$targetUserId) {
                return $this->badRequest('目标用户ID不能为空');
            }

            if ($currentUserId == $targetUserId) {
                return $this->success(['is_following' => 0]);
            }

            // 检查关注状�?
            $isFollowing = Db::name('follows')
                ->where('follower_id', $currentUserId)
                ->where('following_id', $targetUserId)
                ->find() ? 1 : 0;

            return $this->success(['is_following' => $isFollowing]);
        } catch (\Exception $e) {
            return $this->error('获取关注状态失败: ' . $e->getMessage());
        }
    }

    public function getUserProfile(Request $request)
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $currentUserId = session('user_id');
            $targetUserId = input('user_id');

            if (!$targetUserId) {
                return $this->badRequest('目标用户ID不能为空');
            }

            if (!is_numeric($targetUserId)) {
                return $this->badRequest('无效的用户ID');
            }

            $user = Db::name('user')
                ->field('id, username, nickname, avatar, bio, level, vip_level, create_time, status, card_background')
                ->where('id', intval($targetUserId))
                ->where('status', 1)
                ->find();

            if (!$user) {
                return $this->notFound('用户不存在或已被禁用');
            }

            // 获取用户统计数据
            $stats = [
                'following_count' => Db::name('follows')->where('follower_id', $targetUserId)->count(),
                'followers_count' => Db::name('follows')->where('following_id', $targetUserId)->count(),
                'moments_count' => Db::name('moments')->where('user_id', $targetUserId)->where('status', 1)->count(),
                'topics_count' => Db::name('topic_follows')->where('user_id', $targetUserId)->count()
            ];

            // 获取用户最新动�?
            $momentsQuery = Db::name('moments')
                ->field('id, user_id, nickname, avatar, content, images, location, likes, comments, create_time, privacy')
                ->where('user_id', $targetUserId)
                ->where('status', 1)
                ->where(function($query) use ($currentUserId, $targetUserId) {
                    if ($currentUserId && $currentUserId == $targetUserId) {
                        // 查看自己的主页，可以看到所有可见性的动�?
                        return;
                    }
                    // 查看他人主页，只能看到公开动�?(privacy = 0)
                    $query->where('privacy', 0);
                })
                ->order('create_time', 'desc')
                ->limit(10);

            $moments = $momentsQuery->select();

            // 如果当前用户已登录，获取对动态的点赞状�?
            if ($currentUserId && !empty($moments)) {
                $momentIds = $moments->column('id');
                $likedMomentIds = Db::name('likes')
                    ->where('user_id', $currentUserId)
                    ->where('target_id', 'in', $momentIds)
                    ->where('target_type', 1)
                    ->column('target_id');

                foreach ($moments as &$moment) {
                    $moment['is_liked'] = in_array($moment['id'], $likedMomentIds) ? 1 : 0;
                }
            } else {
                foreach ($moments as &$moment) {
                    $moment['is_liked'] = 0;
                }
            }

            // 获取当前用户对目标用户的关注状�?
            $isFollowing = 0;
            if ($currentUserId && $currentUserId != $targetUserId) {
                $isFollowing = Db::name('follows')
                    ->where('follower_id', $currentUserId)
                    ->where('following_id', $targetUserId)
                    ->find() ? 1 : 0;
            }

            $profile = [
                'user_info' => $user,
                'stats' => $stats,
                'latest_moments' => $moments,
                'is_following' => $isFollowing
            ];

            return $this->success($profile);
        } catch (\Exception $e) {
            return $this->error('获取用户个人主页失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取设备类型
     */
    private function getDeviceType($userAgent)
    {
        if (preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $userAgent)) {
            if (preg_match('/iPad/i', $userAgent)) {
                return '平板';
            }
            return '手机';
        }
        return '电脑';
    }

    /**
     * 获取浏览器信�?
     */
    private function getBrowser($userAgent)
    {
        if (preg_match('/MicroMessenger/i', $userAgent)) {
            return '微信';
        }
        if (preg_match('/Chrome/i', $userAgent)) {
            return 'Chrome';
        }
        if (preg_match('/Firefox/i', $userAgent)) {
            return 'Firefox';
        }
        if (preg_match('/Safari/i', $userAgent) && !preg_match('/Chrome/i', $userAgent)) {
            return 'Safari';
        }
        if (preg_match('/Edge/i', $userAgent)) {
            return 'Edge';
        }
        if (preg_match('/MSIE|Trident/i', $userAgent)) {
            return 'IE';
        }
        return '未知';
    }

    /**
     * 获取登录历史
     */
    public function getLoginHistory(Request $request)
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = session('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $page = $request->get('page', 1);
            $limit = $request->get('limit', 20);
            $offset = ($page - 1) * $limit;

            $logs = Db::name('user_login_history')
                ->where('user_id', $userId)
                ->order('login_time', 'desc')
                ->limit($limit)
                ->offset($offset)
                ->select();

            $total = Db::name('user_login_history')
                ->where('user_id', $userId)
                ->count();

            return $this->success([
                'list' => $logs,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (\Exception $e) {
            return $this->error('获取登录历史失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出用户数据
     */
    public function exportUserData(Request $request)
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = session('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $exportType = $request->get('type', 'all');

            $data = [];

            if ($exportType === 'all' || $exportType === 'profile') {
                $user = Db::name('user')
                    ->field('id, username, nickname, avatar, email, mobile, gender, birthday, bio, occupation, create_time, update_time')
                    ->where('id', $userId)
                    ->find();
                $data['profile'] = $user;
            }

            if ($exportType === 'all' || $exportType === 'moments') {
                $moments = Db::name('moments')
                    ->where('user_id', $userId)
                    ->order('create_time', 'desc')
                    ->select();
                $data['moments'] = $moments;
            }

            if ($exportType === 'all' || $exportType === 'comments') {
                $comments = Db::name('comments')
                    ->where('user_id', $userId)
                    ->order('create_time', 'desc')
                    ->select();
                $data['comments'] = $comments;
            }

            if ($exportType === 'all' || $exportType === 'follows') {
                $following = Db::name('follows')
                    ->where('follower_id', $userId)
                    ->select();
                $followers = Db::name('follows')
                    ->where('following_id', $userId)
                    ->select();
                $data['follows'] = [
                    'following' => $following,
                    'followers' => $followers
                ];
            }

            if ($exportType === 'all' || $exportType === 'likes') {
                $likes = Db::name('likes')
                    ->where('user_id', $userId)
                    ->select();
                $data['likes'] = $likes;
            }

            $filename = 'user_data_' . $userId . '_' . date('YmdHis') . '.json';
            $filepath = runtime_path('export') . $filename;
            if (!is_dir(dirname($filepath))) {
                mkdir(dirname($filepath), 0755, true);
            }
            file_put_contents($filepath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $this->success([
                'filename' => $filename,
                'download_url' => '/runtime/export/' . $filename
            ]);
        } catch (\Exception $e) {
            return $this->error('导出数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 注销账户
     */
    public function deleteAccount(Request $request)
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = session('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $password = $request->post('password');
            if (!$password) {
                return $this->badRequest('请输入密码');
            }

            $user = Db::name('user')->where('id', $userId)->find();
            if (!$user) {
                return $this->notFound('用户不存在');
            }

            if (!password_verify($password, $user['password'])) {
                return $this->badRequest('密码错误');
            }

            Db::startTrans();
            try {
                Db::name('user')->where('id', $userId)->update(['status' => 0, 'deleted_at' => date('Y-m-d H:i:s')]);
                Db::name('moments')->where('user_id', $userId)->update(['status' => 0]);
                Db::name('comments')->where('user_id', $userId)->update(['status' => 0]);
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }

            session(null);
            cookie('user_id', null);
            cookie('username', null);
            cookie('nickname', null);
            cookie('avatar', null);

            return $this->success(null, '账户已注销');
        } catch (\Exception $e) {
            return $this->error('注销账户失败: ' . $e->getMessage());
        }
    }
}


