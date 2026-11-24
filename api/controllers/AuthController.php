<?php
/**
 * Hublog 用户认证控制器
 * Token 版本号递增机制：修改密码/登出后旧 Token 强制失效
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Database;
use Hublog\Core\Auth;
use Hublog\Core\Validator;
use Hublog\Core\Security;
use Hublog\Core\Upload;
use Hublog\Core\Helper;
use Hublog\Core\PathResolver;
use Hublog\Core\Cache;
use Hublog\Core\MediaHelper;
use Hublog\Core\RefreshToken;
use Hublog\Core\Moderation;
use Hublog\Core\BadgeService;
use Hublog\Core\Logger;
use Hublog\Core\UserRepository;
use Hublog\Core\PhoneCodeService;

class AuthController extends BaseController
{
    public function register(Request $request): Response
    {
        // 站点未开放注册时直接拒绝（后台 系统设置 → 权限控制 → allow_register）
        // 未配置时默认开放（与 Migration 默认值 '1' 一致）
        $regOpen = UserRepository::fetchSetting('allow_register');
        if ($regOpen !== null && $regOpen !== false && $regOpen !== '1') {
            return Response::error('站点已关闭注册', 403);
        }

        // 会员系统治理 P0-1：注册独立限流（IP 维度，默认 1 小时 10 次）。
        // 全局 rateLimit 是 600 次/分钟，拦不住慢速批量注册；此处补一道专用闸门。
        // 放在所有校验之前：无效请求同样计入配额，防止用垃圾参数反复试探绕过。
        if (!Security::checkRegisterLock($request->getIp())) {
            return Response::error('注册过于频繁，请稍后再试', 429);
        }

        $data = (new Validator($request->all()))
            ->required('username')
            ->string('username', 3, 20)
            ->regex('username', '/^[a-zA-Z0-9]+$/', '用户名只能包含字母和数字')
            ->required('password')
            ->required('password_confirm')
            ->required('email')
            ->email('email')
            ->validate();

        if ($data['password'] !== $data['password_confirm']) {
            return Response::error('两次输入的密码不一致', 422);
        }

        $strength = Security::checkPasswordStrength($data['password']);
        if (!$strength['valid']) {
            return Response::error(implode(', ', $strength['errors']), 422);
        }

        // 分别校验用户名/邮箱重复
        $usernameExists = UserRepository::userIdByUsername($data['username']);
        if ($usernameExists) {
            return Response::error('用户名已被注册', 409, ['field' => 'username']);
        }
        $emailExists = UserRepository::userIdByEmail($data['email']);
        if ($emailExists) {
            return Response::error('邮箱已被注册', 409, ['field' => 'email']);
        }

        // 读取注册相关配置
        $settings = $this->getRegisterSettings();

        // 校验邮箱后缀
        if (!empty($settings['allowed_email_domains'])) {
            $domain = strtolower(substr(strrchr($data['email'], '@'), 1));
            $allowed = array_map('trim', explode(',', $settings['allowed_email_domains']));
            $allowed = array_filter($allowed);
            if (!in_array($domain, $allowed, true)) {
                return Response::error('该邮箱后缀不允许注册', 422, ['field' => 'email']);
            }
        }

        // V54：deleted_ 前缀是注销账号专用（用于识别「已注销」与「被封禁」），禁止注册占用
        if (stripos($data['username'], 'deleted_') === 0) {
            return Response::error('该用户名不可用', 422, ['field' => 'username']);
        }

        // 校验用户名是否包含禁用昵称词（注册时用户名默认作为昵称）
        if (!empty($settings['forbidden_nicknames'])) {
            $usernameLower = mb_strtolower($data['username'], 'UTF-8');
            $forbidden = array_map(function ($v) { return mb_strtolower(trim($v), 'UTF-8'); }, explode(',', $settings['forbidden_nicknames']));
            $forbidden = array_filter($forbidden);
            foreach ($forbidden as $word) {
                if ($word !== '' && strpos($usernameLower, $word) !== false) {
                    return Response::error('用户名包含禁用词汇，请更换', 422, ['field' => 'username']);
                }
            }
        }

        $userId = UserRepository::insertUser([
            'username' => Helper::sanitizeName($data['username']),
            'password' => Auth::hashPassword($data['password']),
            'email' => $data['email'],
            // V53：nickname 有唯一索引，注册默认昵称=用户名，仍可能与他人昵称冲突 → 消歧
            'nickname' => Helper::uniqueNickname(Helper::sanitizeName($data['username'])),
            'avatar' => '/templates/moments/assets/images/default-avatar.svg',
            'role' => 'user',
            'status' => 1,
            'token_version' => 1,
            'created_at' => Helper::now(),
            'updated_at' => Helper::now()
        ]);

        $user = \Hublog\Core\UserRepository::findBasicById($userId);
        $token = Auth::generateToken($user);
        Auth::setAuthCookie($token);
        MediaHelper::processUser($user);
        return Response::success([
            'user' => $user,
            'token' => $token
        ], '注册成功');
    }

    private function getRegisterSettings(): array
    {
        $rows = UserRepository::fetchSettingsByKeys(['forbidden_nicknames', 'allowed_email_domains']);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }

    /**
     * 实时检测用户名是否可用
     */
    public function checkUsername(Request $request): Response
    {
        // P1-1：限流防止用户名枚举（每 IP 每分钟 10 次）
        if (!Security::rateLimit($request->getIp(), 'check_username', null, 10, 60)) {
            return Response::error('请求过于频繁，请稍后再试', 429);
        }
        $username = $request->getString('username');
        if (empty($username)) {
            return Response::error('用户名不能为空', 400);
        }
        if (!preg_match('/^[a-zA-Z0-9]{3,20}$/', $username)) {
            return Response::success(['available' => false, 'message' => '用户名只能包含3-20位字母和数字']);
        }

        $settings = $this->getRegisterSettings();
        if (!empty($settings['forbidden_nicknames'])) {
            $usernameLower = mb_strtolower($username, 'UTF-8');
            $forbidden = array_map(function ($v) { return mb_strtolower(trim($v), 'UTF-8'); }, explode(',', $settings['forbidden_nicknames']));
            $forbidden = array_filter($forbidden);
            foreach ($forbidden as $word) {
                if ($word !== '' && strpos($usernameLower, $word) !== false) {
                    return Response::success(['available' => false, 'message' => '用户名包含禁用词汇']);
                }
            }
        }

        $exists = UserRepository::userIdByUsername($username);
        if ($exists) {
            return Response::success(['available' => false, 'message' => '用户名已被注册']);
        }

        return Response::success(['available' => true, 'message' => '用户名可用']);
    }

    /**
     * 实时检测邮箱是否可用
     */
    public function checkEmail(Request $request): Response
    {
        // P1-1：限流防止邮箱枚举（每 IP 每分钟 10 次）
        if (!Security::rateLimit($request->getIp(), 'check_email', null, 10, 60)) {
            return Response::error('请求过于频繁，请稍后再试', 429);
        }
        $email = $request->getString('email');
        if (empty($email)) {
            return Response::error('邮箱不能为空', 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::success(['available' => false, 'message' => '邮箱格式不正确']);
        }

        $settings = $this->getRegisterSettings();
        if (!empty($settings['allowed_email_domains'])) {
            $domain = strtolower(substr(strrchr($email, '@'), 1));
            $allowed = array_map('trim', explode(',', $settings['allowed_email_domains']));
            $allowed = array_filter($allowed);
            if (!in_array($domain, $allowed, true)) {
                return Response::success(['available' => false, 'message' => '该邮箱后缀不允许注册']);
            }
        }

        $exists = UserRepository::userIdByEmail($email);
        if ($exists) {
            return Response::success(['available' => false, 'message' => '邮箱已被注册']);
        }

        return Response::success(['available' => true, 'message' => '邮箱可用']);
    }

    public function login(Request $request): Response
    {
        $data = (new Validator($request->all()))
            ->required('username')
            ->required('password')
            ->validate();

        $ip = $request->getIp();
        // P1-1：登录防爆破——先检查是否已被锁
        $lock = Security::checkLoginLock($data['username'], $ip);
        if ($lock['locked']) {
            $mins = (int)ceil($lock['retry_after'] / 60);
            return Response::error("登录尝试过于频繁，请 {$mins} 分钟后再试", 423);
        }

        $user = UserRepository::findByUsernameOrEmailWithPassword($data['username']);

        if (!$user || !Auth::verifyPassword($data['password'], $user['password'])) {
            // P1-1：记录失败 + 阶梯锁定
            Security::recordLoginFailure($data['username'], $ip);
            return Response::error('账号或密码错误', 401);
        }
        if ($user['status'] != 1) {
            return Response::error('账号已被禁用', 403);
        }

        // P1-1：登录成功清除失败计数
        Security::clearLoginFailures($data['username'], $ip);

        unset($user['password']);
        $token = Auth::generateToken($user);
        Auth::setAuthCookie($token);
        UserRepository::updateLastLogin((int)$user['id'], Helper::now());
        MediaHelper::processUser($user);

        // V44：签发长效 refresh_token（App/多端场景），30 天滑动续期
        // 仅在客户端显式携带 device_id 或声明客户端类型时下发，保持 Web 端原响应结构不变
        $result = [
            'user' => $user,
            'token' => $token
        ];
        if ($this->wantsRefreshToken($request)) {
            $issued = RefreshToken::issue(
                (int)$user['id'],
                trim((string)$request->getString('device_id')),
                $request->getIp(),
                $request->getHeader('user-agent')
            );
            $result['refresh_token'] = $issued['token'];
            $result['expires_in'] = (int)JWT_EXPIRE;
            $result['refresh_expires_in'] = $issued['expires_in'];
        }

        return Response::success($result, '登录成功');
    }

    /**
     * V58 / P2-1：手机号验证码登录 —— 发送验证码（scene=login）
     * POST /api/auth/phone-code
     * 未接入短信服务商时只写服务端日志；dev_code 仅在 APP_DEBUG=true（本地联调）时
     * 才回带，生产环境一律失败关闭（V147：防任意手机号账号接管）。
     */
    public function sendPhoneLoginCode(Request $request): Response
    {
        // S1：签发逻辑统一收敛到 PhoneCodeService，避免与 UserController 各写一套
        // 导致「生产失败关闭 / IP 限流」这类加固只落地一处。
        $phone = preg_replace('/\D/', '', trim((string)$request->getString('phone')));
        $result = PhoneCodeService::issue($phone, 'login', $request->getIp());
        if (!$result['ok']) {
            return Response::error($result['message'], $result['status']);
        }
        return Response::success($result['data'], $result['message']);
    }

    /**
     * V58 / P2-1：手机号验证码登录 —— 校验并登录（账号不存在则自动注册）
     * POST /api/auth/login-phone  { phone, code }
     */
    public function loginByPhone(Request $request): Response
    {
        $phone = preg_replace('/\D/', '', trim((string)$request->getString('phone')));
        $code = preg_replace('/\D/', '', trim((string)$request->getString('code')));
        if (!preg_match('/^1[3-9]\d{9}$/', $phone) || strlen($code) !== 6) {
            return Response::error('参数不正确', 400);
        }

        // 校验逻辑统一收敛到 PhoneCodeService::verify：锁定 / scene 过滤 /
        // 恒定时间比对 / 5 次锁定 / 原子核销全部一处实现，scene 服务端写死为 login。
        $verified = PhoneCodeService::verify($phone, $code, 'login');
        if (!$verified['ok']) {
            return Response::error($verified['message'], $verified['status']);
        }

        $user = UserRepository::findByPhoneWithPassword($phone);

        $isNew = false;
        if ($user) {
            if ((int)$user['status'] !== 1) {
                return Response::error('账号已被禁用', 403);
            }
            Security::clearLoginFailures($phone, $request->getIp());
        } else {
            // 首次用该手机号登录 → 自动开号
            $username = $phone;
            $suffix = 0;
            while (UserRepository::userIdByUsername($username)) {
                $suffix++;
                $username = $phone . 'x' . $suffix;
            }
            $nickname = '用户' . substr($phone, -4);
            $suffix = 0;
            while (UserRepository::userIdByNickname($nickname)) {
                $suffix++;
                $nickname = '用户' . substr($phone, -4) . $suffix;
            }

            UserRepository::insertUser([
                'username'         => $username,
                // 手机号开号的用户没有密码，随机哈希保证不会被撞库登录
                'password'         => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
                'email'            => null,
                'nickname'         => $nickname,
                'phone'            => $phone,
                'phone_verified_at'=> Helper::now(),
                'status'           => 1,
                'last_active_at'   => Helper::now(),
            ]);
            $user = UserRepository::findByPhoneWithPassword($phone);
            $isNew = true;
        }

        unset($user['password']);
        // 验证码的原子核销与失败计数清理已由 PhoneCodeService::verify 原子完成。
        UserRepository::updateLastLoginAndActive((int)$user['id'], Helper::now());

        // V82：凭短信验证码登录成功 = 证明本机号可控 → 视为手机号已认证。
        // 新开号已在注册时写入 phone_verified_at；老账号首次用验证码登录时补齐，并触发手机徽章。
        if (empty($user['phone_verified_at'])) {
            UserRepository::updatePhoneVerifiedAt((int)$user['id'], Helper::now());
            BadgeService::onEvent((int)$user['id'], 'phone_bound');
        } elseif (!empty($isNew)) {
            // 新开号：确保徽章已解锁（注册时已写时间，这里再保险触发一次）
            BadgeService::onEvent((int)$user['id'], 'phone_bound');
        }

        $token = Auth::generateToken($user);
        Auth::setAuthCookie($token);
        MediaHelper::processUser($user);

        $result = [
            'user'  => $user,
            'token' => $token,
            'is_new'=> $isNew,
        ];
        if ($this->wantsRefreshToken($request)) {
            $issued = RefreshToken::issue(
                (int)$user['id'],
                trim((string)$request->getString('device_id')),
                $request->getIp(),
                $request->getHeader('user-agent')
            );
            $result['refresh_token'] = $issued['token'];
            $result['expires_in'] = (int)JWT_EXPIRE;
            $result['refresh_expires_in'] = $issued['expires_in'];
        }

        return Response::success($result, $isNew ? '手机号注册成功' : '登录成功');
    }

    public function me(Request $request): Response
    {
        $user = Auth::check($request);
        MediaHelper::processUser($user);
        // V80：圈主标识（拥有 ≥1 个圈子），供后台 SPA 决定是否放行活动管理页
        $user['is_circle_owner'] = UserRepository::countOwnedCircles((int)$user['id']) > 0;
        return Response::success($user);
    }

    /**
     * GET /api/auth/profile —— 本人完整可编辑资料（供前台「账户设置」表单回填）。
     *
     * /auth/me 只带登录态判定所需的少量列，性别 / 生日 / 地区 / 标签 / 隐私开关
     * 都不在里面；/user/profile 又是公开视角（且刻意不返回 email 等私有字段）。
     * 这里单独按本人 id 读一次完整列，字段与 PUT /auth/profile 的可写项一一对应。
     */
    public function profile(Request $request): Response
    {
        $auth = Auth::check($request);
        $user = UserRepository::findProfileById((int)$auth['id']);
        if (!$user) {
            return Response::error('用户不存在', 404);
        }
        MediaHelper::processUser($user);
        return Response::success($user);
    }

    /**
     * Token 续期：在旧 Token 尚未过期时，签发一个全新的 Token（重置有效期）
     * 前端可在 Token 即将过期前调用此接口实现无感续期
     */
    public function refresh(Request $request): Response
    {
        // Web refresh 一律以已鉴权登录态为准：Auth::user 同时接受 Bearer 与 httpOnly Cookie，
        // 不再手工解析 Bearer 头或手工校验 JWT，避免 Cookie 模式前端 refresh 必然 401。
        $user = Auth::user($request);
        if (!$user) {
            return Response::error('请先登录', 401);
        }
        $fresh = UserRepository::findRefreshUserById((int)$user['id']);
        if (!$fresh || (int)$fresh['status'] !== 1) {
            return Response::error('账号异常，无法操作', 403);
        }
        if ((int)$user['token_version'] !== (int)$fresh['token_version']) {
            return Response::error('登录状态已失效，请重新登录', 401);
        }
        $newToken = Auth::generateToken($fresh);
        Auth::setAuthCookie($newToken);
        MediaHelper::processUser($fresh);
        return Response::success(['user' => $fresh, 'token' => $newToken], '续期成功');
    }

    /**
     * 退出登录：递增 Token 版本号，使当前 Token 及所有旧 Token 失效
     */
    /**
     * V44：判断是否需要下发 refresh_token
     * Web 模板端不传 X-Client-Type / device_id，保持原有响应结构；
     * App / 小程序 / H5 客户端则会显式声明，从而启用双令牌机制。
     */
    private function wantsRefreshToken(Request $request): bool
    {
        $clientType = strtolower(trim((string)$request->getHeader('x-client-type')));
        $explicitTypes = ['app', 'android', 'ios', 'miniapp', 'h5'];
        return in_array($clientType, $explicitTypes, true)
            || trim((string)$request->getString('device_id')) !== '';
    }

    /**
     * POST /api/auth/token-refresh —— 用 refresh_token 静默换新 access_token
     *
     * 与旧 /api/auth/refresh 的区别：不依赖未过期的 access_token，
     * 即使 JWT 已过期（7 天后）也能续期，满足 App 长期登录需求。
     * 单次有效：刷新后旧 refresh_token 立即作废并返回新的（防重放）。
     */
    public function tokenRefresh(Request $request): Response
    {
        $raw = trim((string)$request->getString('refresh_token'));
        if ($raw === '') {
            return Response::error('缺少 refresh_token', 400);
        }

        try {
            $result = RefreshToken::rotate($raw);
        } catch (\Hublog\Core\Exception\HublogException $e) {
            return Response::error($e->getMessage(), 401);
        } catch (\Throwable $e) {
            error_log('[Hublog token-refresh] ' . $e->getMessage()); // @allow-error-log
            return Response::error('刷新令牌失败，请重新登录', 500);
        }

        Auth::setAuthCookie($result['token']);
        return Response::success([
            'user'          => $result['user'],
            'token'         => $result['token'],
            'refresh_token' => $result['refresh_token'],
            'expires_in'    => (int)JWT_EXPIRE,
            'refresh_expires_in' => $result['expires_in']
        ], '已刷新');
    }

    public function logout(Request $request): Response
    {
        $user = Auth::check($request);

        // V44：先注销本设备的 refresh_token，避免"退出登录后仍能凭旧令牌续期"
        RefreshToken::revoke(trim((string)$request->getString('refresh_token')));
        RefreshToken::revokeAllForUser((int)$user['id']);

        // P0-4b：改为 SQL 原子自增，避免并发登出读改写竞态
        UserRepository::bumpTokenVersion((int)$user['id'], Helper::now());
        Auth::clear();
        Auth::clearAuthCookie();
        return Response::success(null, '退出成功');
    }

    public function updateProfile(Request $request): Response
    {
        $user = Auth::check($request);

        // 个性签名（users.bio）：产品规则 = 最多 20 字。App「我的」页签名区只有一行多点的宽度，
        // 放开会让信息区排版失控（2026-10-11）。Validator 直接拿字段名拼报错（「bio最多20个字符」），
        // 所以先在这里拦一次给中文提示，下面 Validator 再限一遍做兜底。
        $rawBio = $request->all()['bio'] ?? null;
        if ($rawBio !== null && mb_strlen(trim((string)$rawBio)) > 20) {
            return Response::error('个性签名最多 20 个字', 422);
        }

        $data = (new Validator($request->all()))
            ->string('nickname', 1, 50)
            ->regex('nickname', '/^[\p{Han}a-zA-Z0-9\s]+$/u', '昵称不能包含特殊字符')
            ->string('bio', 0, 20)
            ->email('email')
            ->validate();

        $updateData = [];
        if (isset($data['nickname'])) {
            $nickname = Helper::sanitizeName($data['nickname']);
            $exists = UserRepository::nicknameExistsElsewhere($nickname, (int)$user['id']);
            if ($exists) {
                return Response::error('昵称已被使用', 409);
            }
            // 校验禁用昵称
            $regSettings = $this->getRegisterSettings();
            if (!empty($regSettings['forbidden_nicknames'])) {
                $nicknameLower = mb_strtolower($nickname, 'UTF-8');
                $forbidden = array_map(function ($v) { return mb_strtolower(trim($v), 'UTF-8'); }, explode(',', $regSettings['forbidden_nicknames']));
                $forbidden = array_filter($forbidden);
                foreach ($forbidden as $word) {
                    if ($word !== '' && strpos($nicknameLower, $word) !== false) {
                        return Response::error('昵称包含禁用词汇，请更换', 422);
                    }
                }
            }
            // V71：昵称过敏感词（profile 场景）。昵称没有"待审"状态，命中 review 也按 block 处理：
            // 昵称是全局唯一且高频展示的，放进去再等人工审核等于把违规内容先挂上去。
            $fNick = Moderation::filter($nickname, 'profile', (int)$user['id'], (int)$user['id']);
            if (!$fNick['ok'] || $fNick['action'] === 'review') {
                return Response::error('昵称包含违规词汇，请更换', 422);
            }
            $updateData['nickname'] = $fNick['text'];
        }
        if (isset($data['bio'])) {
            $fBio = Moderation::filter((string)$data['bio'], 'profile', (int)$user['id'], (int)$user['id']);
            if (!$fBio['ok']) {
                return Response::error($fBio['msg'], 422);
            }
            $updateData['bio'] = Security::purify($fBio['text']);
        }
        if (isset($data['email'])) {
            $mail = trim((string)$data['email']);
            if ($mail === '') {
                // 清空邮箱：置 NULL（email 已可空；uk_email 唯一索引允许多个 NULL）
                $updateData['email'] = null;
            } else {
                $exists = UserRepository::emailExistsElsewhere($mail, (int)$user['id']);
                if ($exists) {
                    return Response::error('邮箱已被使用', 409);
                }
                $updateData['email'] = $mail;
            }
        }

        // App/多端：头像、封面走云存储直传后回填 URL
        // 注意：URL 由客户端提供，必须校验域名，否则可被用于注入任意外链（钓鱼/追踪）
        if (isset($data['avatar']) && $data['avatar'] !== '') {
            $avatar = trim((string)$data['avatar']);
            if (!$this->isSafeMediaUrl($avatar, (int)$user['id'])) {
                return Response::error('头像地址不合法', 422);
            }
            $updateData['avatar'] = $avatar;
        }
        if (isset($data['cover']) && $data['cover'] !== '') {
            $cover = trim((string)$data['cover']);
            if (!$this->isSafeMediaUrl($cover, (int)$user['id'])) {
                return Response::error('封面地址不合法', 422);
            }
            $updateData['cover'] = $cover;
        }

        // ====== V58：P1-4 隐私权限 + P2-2 资料扩展（性别/生日/地区/标签/隐私开关）======
        $all = $request->all();
        $privacyMap = [
            'privacy_home'       => 'enum',
            'allow_search'       => 'int',
            'allow_mention'      => 'int',
            'allow_stranger_msg' => 'int',
            'hide_online'        => 'int',
            'font_scale'         => 'enum',
            'msg_sound'          => 'int',
        ];
        foreach ($privacyMap as $field => $kind) {
            if (!array_key_exists($field, $all) || $all[$field] === null || $all[$field] === '') {
                continue;
            }
            if ($kind === 'enum') {
                $val = (string)$all[$field];
                $allowed = $field === 'privacy_home'
                    ? ['public', 'followers', 'private']
                    : ['small', 'normal', 'large', 'xlarge'];
                if (!in_array($val, $allowed, true)) {
                    continue;
                }
                $updateData[$field] = $val;
            } else {
                $updateData[$field] = ((int)$all[$field]) ? 1 : 0;
            }
        }

        if (array_key_exists('gender', $all) && $all['gender'] !== null && $all['gender'] !== '') {
            $gender = (int)$all['gender'];
            $updateData['gender'] = in_array($gender, [0, 1, 2], true) ? $gender : 0;
        }
        if (array_key_exists('birthday', $all) && $all['birthday'] !== null && $all['birthday'] !== '') {
            $birthday = trim((string)$all['birthday']);
            $updateData['birthday'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday) ? $birthday : null;
        }
        if (array_key_exists('region', $all) && $all['region'] !== null) {
            $region = mb_substr(trim((string)$all['region']), 0, 50);
            $updateData['region'] = $region !== '' ? $region : null;
        }
        if (array_key_exists('user_tags', $all) && $all['user_tags'] !== null && $all['user_tags'] !== '') {
            $updateData['user_tags'] = $this->normalizeUserTags($all['user_tags']);
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = Helper::now();
            try {
                UserRepository::updateUserFields((int)$user['id'], $updateData);
            } catch (\Throwable $e) {
                // V53：users.nickname 唯一索引兜底。并发下两个人同时改成同一个昵称时，
                // 上面的 SELECT 查重可能都通过，最终由数据库抛 1062 → 转成 409 友好提示
                $code = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : (int)$e->getCode();
                if ($code === 1062 || strpos($e->getMessage(), 'uk_users_nickname') !== false
                    || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    return Response::error('昵称或邮箱已被使用', 409);
                }
                throw $e;
            }
            // V129：资料（昵称/头像/封面等）变更 → 全量清理用户可见缓存。
            // 时间线/动态详情/评论区都内嵌 user 快照，只清 user_profile_ 不够
            // （web 端改资料同样会「其他页面还是旧的」）。
            $this->clearUserProfileCaches($user['username']);
            // V82：资料变更 → 徽章实时校验（资料完整达人等）
            BadgeService::onEvent((int)$user['id'], 'profile_update');
        }

        Auth::clear();
        $user = UserRepository::findUpdatedProfileById((int)$user['id']);
        MediaHelper::processUser($user);
        return Response::success($user, '更新成功');
    }

    /**
     * V58：个人标签归一化（最多 5 个、每个 ≤10 字、去重、逗号分隔）
     */
    private function normalizeUserTags($raw): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        $parts = preg_split('/[,，\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_map(function ($v) { return mb_substr(trim($v), 0, 10); }, $parts);
        $parts = array_values(array_unique(array_filter($parts)));
        if (count($parts) > 5) {
            $parts = array_slice($parts, 0, 5);
        }
        return $parts ? implode(',', $parts) : null;
    }

    /**
     * 校验头像/封面 URL 是否可信：仅允许相对路径、或本站 / MEDIA_BASE_URL / APP_URL 同域。
     * 防止客户端通过 updateProfile 写入任意外链（可用于钓鱼或隐私追踪）。
     */
    private function isSafeMediaUrl(string $url, int $userId = 0): bool
    {
        // 统一实现已下沉到 MediaHelper，供 App / OAuth 等入口共用（含 provider CDN 白名单扩展位）
        return MediaHelper::isSafeMediaUrl($url, $userId);
    }

    public function changePassword(Request $request): Response
    {
        $user = Auth::check($request);
        $data = (new Validator($request->all()))
            ->required('old_password')
            ->required('new_password')
            ->required('new_password_confirm')
            ->validate();

        if ($data['new_password'] !== $data['new_password_confirm']) {
            return Response::error('两次输入的新密码不一致', 422);
        }

        $dbUser = UserRepository::findPasswordById((int)$user['id']);
        if (!Auth::verifyPassword($data['old_password'], $dbUser['password'])) {
            return Response::error('原密码错误', 400);
        }

        $strength = Security::checkPasswordStrength($data['new_password']);
        if (!$strength['valid']) {
            return Response::error(implode(', ', $strength['errors']), 422);
        }

        // 修改密码：SQL 侧 token_version+1 并同事务撤销全部 refresh_token（原子）
        UserRepository::changePasswordAndInvalidateSessions(
            (int)$user['id'],
            Auth::hashPassword($data['new_password']),
            Helper::now()
        );

        return Response::success(null, '密码修改成功，请重新登录');
    }

    /**
     * 设置/重置密码（短信验证码校验，不要求原密码）
     * 适用场景：手机号登录用户从未设置过密码（或忘记原密码），用本机号验证码设置新密码。
     * 与 changePassword（需原密码）互补，不冲突。
     * POST /api/auth/set-password  { phone, code, new_password, new_password_confirm }
     */
    public function setPassword(Request $request): Response
    {
        $user = Auth::check($request);
        $data = (new Validator($request->all()))
            ->required('phone')
            ->required('code')
            ->required('new_password')
            ->required('new_password_confirm')
            ->validate();

        $phone = preg_replace('/\D/', '', trim((string)$data['phone']));
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return Response::error('手机号格式不正确', 400);
        }
        if (strlen((string)$data['code']) !== 6) {
            return Response::error('验证码不正确', 400);
        }
        if ($data['new_password'] !== $data['new_password_confirm']) {
            return Response::error('两次输入的新密码不一致', 422);
        }

        // Auth::check 的 SELECT 不含 phone，按登录用户 id 单独查，防止越权给别的号设密码
        $accountPhone = UserRepository::findPhoneById((int)$user['id']);
        if (empty($accountPhone) || $accountPhone !== $phone) {
            return Response::error('手机号与当前账号不符', 403);
        }

        // 校验统一收敛到 PhoneCodeService::verify，scene 服务端写死为 set_password。
        $verified = PhoneCodeService::verify($phone, (string)$data['code'], 'set_password');
        if (!$verified['ok']) {
            return Response::error($verified['message'], $verified['status']);
        }

        $strength = Security::checkPasswordStrength($data['new_password']);
        if (!$strength['valid']) {
            return Response::error(implode(', ', $strength['errors']), 422);
        }

        // 统一入口：版本号 SQL 侧 +1，同事务撤销全部 refresh_token。
        // 验证码原子核销已由 PhoneCodeService::verify 在校验阶段原子完成（不在本事务内）。
        UserRepository::changePasswordAndInvalidateSessions(
            (int)$user['id'],
            Auth::hashPassword($data['new_password']),
            Helper::now()
        );

        return Response::success(null, '密码设置成功，请重新登录');
    }

    /**
     * V129：用户资料（头像/昵称/封面）变更后的缓存全量清理。
     * 时间线（timeline_v4_*）、动态详情（post_*）、评论区（post_comments_*）的
     * 响应里都内嵌 user.avatar / nickname 快照，只清 user_profile_ 会导致
     * 「改了资料，其他页面还是旧的」。
     * 历史坑：时间线缓存键已从 v2 升到 v4（PostController::timeline），
     * 老代码清 timeline_v2_ 等于没清——换头像后信息流一直返回旧头像的真因。
     */
    private function clearUserProfileCaches(string $username): void
    {
        Cache::delete("user_profile_{$username}");
        // P0-11：timeline 命名空间升版（同时覆盖 timeline_v4_* 列表与 timeline_total_* 计数缓存）
        Cache::bumpNsVersion('timeline');
        Cache::deleteByPrefix('post_');
        Auth::clear();
    }

    public function uploadAvatar(Request $request): Response
    {
        $user = Auth::check($request);
        $file = $request->getFile('avatar');
        if (!$file) {
            return Response::error('请选择图片', 400);
        }

        $settings = Cache::remember('site_settings', function () {
            $rows = UserRepository::fetchAllSettings();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);
        $maxSize = (int)($settings['upload_max_avatar_size'] ?? '5') * 1024 * 1024;

        $url = Upload::avatar($file, $maxSize);
        UserRepository::updateUserFields((int)$user['id'], [
            'avatar' => $url,
            'updated_at' => Helper::now()
        ]);

        // V129：头像属于用户资料，时间线/动态详情/评论区都内嵌 user.avatar 快照，必须全清
        // （timeline_v2_ 是废弃键，清它等于没清，见 clearUserProfileCaches 注释）
        $this->clearUserProfileCaches($user['username']);
        return Response::success(['avatar' => $url], '上传成功');
    }

    /**
     * 上传用户封面（支持图片和视频）
     */
    public function uploadCover(Request $request): Response
    {
        $user = Auth::check($request);
        $file = $request->getFile('cover');
        if (!$file) {
            return Response::error('请选择文件', 400);
        }

        $settings = Cache::remember('site_settings', function () {
            $rows = UserRepository::fetchAllSettings();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);
        $maxImageSize = (int)($settings['upload_max_avatar_size'] ?? '5') * 1024 * 1024;
        $maxVideoSize = 30 * 1024 * 1024; // 封面视频最大 30MB

        // 检测文件类型
        $realMime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
        if ($realMime === null && function_exists('mime_content_type')) {
            $realMime = @mime_content_type($file['tmp_name']);
        }

        $isVideo = $realMime && str_starts_with($realMime, 'video/');
        $isImage = $realMime && in_array($realMime, \UPLOAD_IMAGE_TYPES, true);

        if (!$isVideo && !$isImage) {
            return Response::error('仅支持图片（JPEG/PNG/GIF/WebP）或视频（MP4/WebM）', 400);
        }

        if ($isImage) {
            // 图片封面
            if ($file['size'] > $maxImageSize) {
                return Response::error('图片不能超过 ' . ($maxImageSize / 1024 / 1024) . 'MB', 400);
            }
            // P2-11：image() 返回 [url, file_hash]，必须解构，否则数组入库变成字符串 "Array"
            [$url, $realFileHash] = Upload::image($file, \UPLOAD_IMAGE_DIR, 1920, 600, true, $maxImageSize);
            // 预写入 uploads 表（与 PostController::uploadImage 保持一致，供秒传去重使用）
            UserRepository::insertUpload([
                'user_id' => $user['id'],
                'post_id' => null,
                'type' => 'image',
                'filename' => $file['name'] ?? '',
                'url' => $url,
                'size' => $file['size'] ?? 0,
                'file_hash' => $realFileHash !== '' ? $realFileHash : null,
                'status' => 1,
                'created_at' => Helper::now(),
                'updated_at' => Helper::now()
            ]);
        } else {
            // 视频封面
            if ($file['size'] > $maxVideoSize) {
                return Response::error('视频不能超过 30MB', 400);
            }
            $allowedVideoExts = ['mp4', 'webm', 'mov'];
            // Upload::file() 返回 [url, hash]，必须解构取第 0 位
            [$url] = Upload::file($file, \UPLOAD_ATTACHMENT_DIR, $maxVideoSize, null, $allowedVideoExts);
        }

        UserRepository::updateUserFields((int)$user['id'], [
            'cover' => $url,
            'updated_at' => Helper::now()
        ]);

        // V129：封面在个人主页（user_profile_ 缓存）——统一走全量清理
        // （原来清的 timeline_v2_ 是废弃键，等于没清）
        $this->clearUserProfileCaches($user['username']);

        return Response::success(['cover' => $url], '上传成功');
    }

    public function csrf(Request $request): Response
    {
        // 必须传入 $request：Csrf::generate 靠它把 token 绑定到当前登录用户（u:<id>）。
        // 缺省时 currentSubject 走 guest 分支绑定全新随机指纹，与 verify 侧解析出的
        // 登录身份永远不等 → 已登录用户所有写请求 403 CSRF验证失败 且重试无法自愈
        // （P0-3 Cookie 鉴权模式下的回归，2026-11-13 修复）。
        return Response::success(['token' => \Hublog\Core\Csrf::generate($request)]);
    }

    // ==================== V36 新增：邮箱验证 ====================

    /**
     * 发送邮箱验证码
     */
    public function sendVerification(Request $request): Response
    {
        $data = (new Validator($request->all()))
            ->required('email')->email('email')->validate();

        $email = $data['email'];
        $userId = null;
        $user = Auth::user($request);
        if ($user) {
            $userId = $user['id'];
        }

        // 检查邮箱是否已注册（注册验证场景）
        $existing = UserRepository::findEmailVerificationState($email);
        if ($existing && $existing['email_verified']) {
            return Response::error('该邮箱已验证', 422);
        }

        // P1-5：同 IP 频率限制——原实现无任何频率控制，可被用于邮件轰炸
        // （每小时 20 封 / 每天 60 封，正常用户 1~2 封）
        if (!Security::rateLimit($request->getIp(), 'email_ip_hour', null, 20, 3600)) {
            return Response::error('该网络环境发送验证码过于频繁，请稍后再试', 429);
        }
        if (!Security::rateLimit($request->getIp(), 'email_ip_day', null, 60, 86400)) {
            return Response::error('今日发送验证码次数已达上限，请明天再试', 429);
        }

        // P1-2：邮箱维度日限 + 同邮箱 60 秒冷却。identity 与查询统一小写，
        // 防止 Victim@X.com / victim@x.com 变体绕过限流与冷却。
        $emailKey = mb_strtolower($email);
        if (!Security::rateLimit($emailKey, 'email_code_email_day', null, 10, 86400)) {
            return Response::error('该邮箱今日验证码次数已达上限', 429);
        }
        $last = UserRepository::findLatestVerificationByEmail($emailKey, 'register');
        if ($last && time() - strtotime((string)$last['created_at']) < 60) {
            return Response::error('发送过于频繁，请 60 秒后再试', 429);
        }

        // 生成 6 位验证码
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 分钟有效

        // 存入 email_verifications 表
        UserRepository::insertEmailVerification([
            'user_id' => $userId ?? ($existing['id'] ?? 0),
            'email' => $email,
            'code' => password_hash($code, PASSWORD_DEFAULT),
            'type' => 'register',
            'expires_at' => $expiresAt,
            'used' => 0,
            'created_at' => Helper::now()
        ]);

        // 发送邮件
        $mailer = new \Hublog\Core\Mailer();
        $sent = $mailer->sendVerificationCode($email, $code, 'register');
        if (!$sent) {
            return Response::error('邮件发送失败，请检查邮件配置或稍后重试', 500);
        }

        return Response::success(null, '验证码已发送至邮箱，10 分钟内有效');
    }

    /**
     * 验证邮箱
     */
    public function verifyEmail(Request $request): Response
    {
        $data = (new Validator($request->all()))
            ->required('email')->email('email')
            ->required('code')->validate();

        $email = $data['email'];
        $code = $data['code'];
        $emailKey = mb_strtolower($email);

        // P1-2：先判锁 → 再原子核销 → 最后改状态，避免并发双花与无限猜码。
        $lock = Security::checkCodeLock($emailKey, 'email_register');
        if ($lock['locked']) {
            return Response::error("验证失败次数过多，请 {$lock['retry_after']} 秒后再试", 429);
        }

        // 查询同样走小写键（仓储 SQL 对 email 做 LOWER 比较，兼容历史大小写混合记录）
        $record = UserRepository::findLatestUnusedVerification($emailKey, 'register');
        if (!$record || strtotime((string)$record['expires_at']) < time()) {
            return Response::error('验证码已失效，请重新发送', 422);
        }
        // 归属校验：记录带 user_id（已注册邮箱）时，无论是否登录都必须持有同一 uid 的登录态。
        // 原实现在 $viewer=null（未登录）时直接跳过，导致攻击者可为他人邮箱置 email_verified。
        $recordUid = (int)($record['user_id'] ?? 0);
        $viewer = Auth::user($request);
        if ($recordUid > 0 && (!$viewer || (int)$viewer['id'] !== $recordUid)) {
            return Response::error('验证码归属校验失败', 403);
        }
        if (!password_verify($code, $record['code'])) {
            $attempts = Security::recordCodeFailure($emailKey, 'email_register');
            return Response::error($attempts >= 5 ? '错误次数过多，已锁定 1 小时' : '验证码错误', 422);
        }
        if (!UserRepository::claimEmailVerification((int)$record['id'], Helper::now())) {
            return Response::error('验证码已失效，请重新发送', 422);
        }
        Security::clearCodeFailures($emailKey, 'email_register');
        // 回写 users 表时用记录中存储的原始大小写，确保与注册时的 email 完全一致
        $canonicalEmail = (string)($record['email'] ?? $email);
        UserRepository::markEmailVerified($canonicalEmail, Helper::now());

        // V82：邮箱验证通过 → 徽章实时校验（邮箱已认证）
        $verifiedUser = UserRepository::findIdUsernameByEmail($canonicalEmail);
        if ($verifiedUser) {
            BadgeService::onEvent((int)$verifiedUser['id'], 'email_verified');
        }

        return Response::success(null, '邮箱验证成功');
    }

    // ==================== V36 新增：密码找回 ====================

    /**
     * 发送密码重置链接
     */
    public function forgotPassword(Request $request): Response
    {
        $data = (new Validator($request->all()))
            ->required('email')->email('email')->validate();

        $email = $data['email'];
        $emailKey = mb_strtolower($email);

        // P2-7：三闸限流。非注册邮箱同样消耗 IP 配额，但文案与成功分支完全一致，
        // 不暴露邮箱是否存在。
        $ip = $request->getIp();
        if (!Security::rateLimit($ip, 'forgot_ip_hour', null, 10, 3600)) {
            return Response::error('操作过于频繁，请稍后再试', 429);
        }
        if (!Security::rateLimit($emailKey, 'forgot_email_day', null, 5, 86400)) {
            return Response::error('请求过于频繁，请明天再试', 429);
        }
        $last = UserRepository::findLatestVerificationByEmail($emailKey, 'reset');
        if ($last && time() - strtotime((string)$last['created_at']) < 60) {
            return Response::error('发送过于频繁，请 60 秒后再试', 429);
        }

        $user = UserRepository::findIdUsernameByEmail($email);
        if (!$user) {
            // 出于安全考虑，不暴露邮箱是否存在
            return Response::success(null, '如果该邮箱已注册，重置链接已发送');
        }

        // ===== 修复竞态和旧 token 复用：
        // 先把这个邮箱所有仍未使用(used=0) 且 type=reset 的记录标记为 used=2（=被新重置请求覆盖作废），
        // 避免用户之前被截获的邮件里的 token 还能被用 ORDER BY id DESC 覆盖的"看似过期实际还能登录"的窗口期。
        $now = Helper::now();
        // PHPStan 提级后揪出：PDO::exec() 只接受 1 个参数，
        // 传第 2 个参数会抛 ArgumentCountError（致命错误 → 500），
        // 「忘记密码」这条路径原本必然失败。改用仓储方法（prepare + 绑定参数）。
        UserRepository::invalidateResetVerifications($emailKey, $now);

        // 生成重置 Token
        $token = Security::randomString(32);
        $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 分钟有效

        UserRepository::insertEmailVerification([
            'user_id' => $user['id'],
            'email' => $email,
            'code' => password_hash($token, PASSWORD_DEFAULT),
            'type' => 'reset',
            'expires_at' => $expiresAt,
            'used' => 0,
            'created_at' => Helper::now()
        ]);

        // 构建重置链接
        $baseUrl = PathResolver::baseUrl();
        $resetLink = $baseUrl . '/index.php/reset-password?token=' . $token . '&email=' . urlencode($email);

        $mailer = new \Hublog\Core\Mailer();
        $mailer->sendPasswordReset($email, $resetLink);

        return Response::success(null, '如果该邮箱已注册，重置链接已发送');
    }

    /**
     * 重置密码
     */
    public function resetPassword(Request $request): Response
    {
        $data = (new Validator($request->all()))
            ->required('email')->email('email')
            ->required('token')
            ->required('password')
            ->required('password_confirm')->validate();

        if ($data['password'] !== $data['password_confirm']) {
            return Response::error('两次输入的密码不一致', 422);
        }

        $strength = Security::checkPasswordStrength($data['password']);
        if (!$strength['valid']) {
            return Response::error(implode(', ', $strength['errors']), 422);
        }

        // 验证 Token（邮箱统一小写键；仓储 SQL 对 email 做 LOWER 比较，大小写变体等价）
        $emailKey = mb_strtolower($data['email']);
        $record = UserRepository::findLatestUnusedVerification($emailKey, 'reset');
        if (!$record || strtotime($record['expires_at']) < time() || !password_verify($data['token'], $record['code'])) {
            return Response::error('重置链接无效或已过期', 422);
        }

        // 原子核销 + 改密 + 版本递增 + 撤销全部 refresh_token 在同一事务内完成；
        // 并发重复提交时只有一个请求能抢到 used=0 的记录。
        if (!UserRepository::resetPasswordWithToken(
            (int)$record['id'],
            (int)$record['user_id'],
            Auth::hashPassword($data['password']),
            Helper::now()
        )) {
            return Response::error('重置链接无效或已过期', 422);
        }

        return Response::success(null, '密码重置成功，请重新登录');
    }

    // ==================== V36 新增：第三方 OAuth 登录 ====================

    /**
     * OAuth 重定向
     */
    public function oauthRedirect(Request $request): Response
    {
        $provider = $request->getString('provider');
        $allowed = ['github', 'google', 'wechat'];
        if (!in_array($provider, $allowed, true)) {
            return Response::error('不支持的登录方式', 400);
        }

        $settings = Cache::remember('site_settings', function () {
            $rows = UserRepository::fetchAllSettings();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);

        $clientId = $settings["oauth_{$provider}_client_id"] ?? '';
        if (empty($clientId)) {
            return Response::error("{$provider} 登录未配置", 400);
        }

        $baseUrl = PathResolver::baseUrl();
        $redirectUri = $baseUrl . '/api/index.php/auth/oauth/' . $provider . '/callback';
        $state = Security::randomString(16);
        // P2-5：state 绑定浏览器 Cookie（抗登录 CSRF 主力），IP 只作诊断记录。
        $browserId = bin2hex(random_bytes(16));
        Cache::set('oauth_state_' . $state, [
            'provider'   => $provider,
            'ip'         => $request->getIp(),
            'browser_id' => $browserId,
        ], 300);
        // secure 判定与 Auth::setAuthCookie 保持一致（HTTPS 或反代声明 HTTPS）
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie('oauth_state_browser', $browserId, [
            'expires'  => time() + 300,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);

        $urls = [
            'github' => "https://github.com/login/oauth/authorize?client_id={$clientId}&redirect_uri=" . urlencode($redirectUri) . "&scope=user:email&state={$state}",
            'google' => "https://accounts.google.com/o/oauth2/v2/auth?client_id={$clientId}&redirect_uri=" . urlencode($redirectUri) . "&response_type=code&scope=openid+email+profile&state={$state}",
            'wechat' => "https://open.weixin.qq.com/connect/qrconnect?appid={$clientId}&redirect_uri=" . urlencode($redirectUri) . "&response_type=code&scope=snsapi_login&state={$state}#wechat_redirect",
        ];

        header('Location: ' . $urls[$provider]);
        exit;
    }

    /**
     * OAuth 回调处理
     * Web 端：302 跳转回前端页面（?oauth_token=xxx）
     * App/小程序：通过 ?response=json 参数返回 JSON {token, user}
     */
    public function oauthCallback(Request $request): Response
    {
        $provider = $request->getString('provider');
        $code = $request->getString('code');
        $state = $request->getString('state');
        $jsonMode = $request->getString('response') === 'json';

        if (empty($code) || empty($state)) {
            return Response::error('授权失败：缺少必要参数', 400);
        }

        $stateData = Cache::get('oauth_state_' . $state);
        $bid = (string)($_COOKIE['oauth_state_browser'] ?? '');
        // P2-5：Cookie 绑定是抗登录 CSRF 主力——攻击者诱导受害者浏览器访问回调时，
        // 拿不到受害者浏览器里的 oauth_state_browser，state 再泄露也无法通过。
        if (!$stateData || !is_array($stateData)
            || ($stateData['provider'] ?? '') !== $provider
            || empty($stateData['browser_id'])
            || !hash_equals((string)$stateData['browser_id'], $bid)) {
            return Response::error('授权失败：状态验证错误', 400);
        }
        // IP 不一致降级为告警：移动网络切换 / XFF 部署会误杀，不作为拒绝条件。
        if (($stateData['ip'] ?? '') !== $request->getIp()) {
            Logger::warning('[Hublog OAuth] state IP 不一致（Cookie 绑定校验通过，已放行）', [
                'provider' => $provider,
            ]);
        }
        Cache::delete('oauth_state_' . $state);
        setcookie('oauth_state_browser', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $settings = Cache::remember('site_settings', function () {
            $rows = UserRepository::fetchAllSettings();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);

        $clientId = $settings["oauth_{$provider}_client_id"] ?? '';
        $clientSecret = $settings["oauth_{$provider}_client_secret"] ?? '';

        // 获取 access_token
        $tokenUrls = [
            'github' => 'https://github.com/login/oauth/access_token',
            'google' => 'https://oauth2.googleapis.com/token',
            'wechat' => 'https://api.weixin.qq.com/sns/oauth2/access_token',
        ];

        $postFields = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);

        $ch = curl_init($tokenUrls[$provider]);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $tokenData = json_decode($response, true);
        $accessToken = $tokenData['access_token'] ?? '';

        if (empty($accessToken)) {
            return Response::error('授权失败：无法获取访问令牌', 400);
        }

        // 获取用户信息
        $userUrls = [
            'github' => 'https://api.github.com/user',
            'google' => 'https://www.googleapis.com/oauth2/v2/userinfo',
            'wechat' => 'https://api.weixin.qq.com/sns/userinfo',
        ];

        $ch = curl_init($userUrls[$provider]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}", 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $userInfo = json_decode($response, true);
        $oauthId = (string)($userInfo['id'] ?? '');
        $email = $userInfo['email'] ?? '';
        $nickname = $userInfo['name'] ?? ($userInfo['login'] ?? 'User');
        $avatar = $userInfo['avatar_url'] ?? ($userInfo['picture'] ?? ($userInfo['headimgurl'] ?? ''));
        // 头像来自 provider 响应：仅放行本站域名或该 provider 的已知 CDN 域名
        $cdnHosts = MediaHelper::OAUTH_CDN[$provider] ?? [];
        if ($avatar !== '' && !MediaHelper::isSafeMediaUrl((string)$avatar, 0, $cdnHosts)) {
            $avatar = '/templates/moments/assets/images/default-avatar.svg';
        }

        if (empty($oauthId)) {
            return Response::error('授权失败：无法获取用户信息', 400);
        }

        // P0-2 修复：仅信任 OAuth 提供商明确标记为已验证的邮箱，防止 ATO
        // - GitHub: /user 接口的 email 字段是公开资料，未经验证；需额外请求 /user/emails 取 verified+primary
        // - Google: /userinfo 返回 email_verified=true|false
        // - WeChat: 通常无 verified 邮箱，按未验证处理
        $verifiedEmail = '';
        if ($provider === 'github') {
            $ch = curl_init('https://api.github.com/user/emails');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}", 'Accept: application/json'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            $emailsResp = curl_exec($ch);
            curl_close($ch);
            $emails = json_decode((string)$emailsResp, true);
            if (is_array($emails)) {
                foreach ($emails as $item) {
                    if (!empty($item['verified']) && !empty($item['primary']) && !empty($item['email'])) {
                        $verifiedEmail = $item['email'];
                        break;
                    }
                }
            }
        } elseif ($provider === 'google') {
            if (!empty($userInfo['email_verified']) && !empty($email)) {
                $verifiedEmail = $email;
            }
        }
        // 仅当取得已验证邮箱时，才允许后续自动关联现有账号；否则 $email 仅用于填充新用户字段且不触发关联
        $emailForBind = $verifiedEmail;
        if ($emailForBind === '') {
            // 无已验证邮箱时，将 $email 置空以阻止自动关联，但仍保留原始邮箱用于创建新用户的初始 email 字段
            $emailForBind = '';
        }

        // 查找已关联的用户
        $oauthAccount = UserRepository::findOauthAccount($provider, $oauthId);

        if ($oauthAccount) {
            // 已关联，直接登录
            $user = UserRepository::findActiveBasicById((int)$oauthAccount['user_id']);
            if (!$user) {
                return Response::error('账号已被禁用', 403);
            }
            $token = Auth::generateToken($user);
            Auth::setAuthCookie($token);
            if ($jsonMode) {
                MediaHelper::processUser($user);
                return Response::success(['user' => $user, 'token' => $token], 'OAuth 登录成功');
            }
            $baseUrl = PathResolver::baseUrl();
            header('Location: ' . $baseUrl . '/?oauth_token=' . urlencode($token));
            exit;
        }

        // 未关联：仅当存在已验证邮箱时，才允许自动关联到现有账号
        $userId = null;
        if ($emailForBind !== '') {
            $existing = UserRepository::findEmailVerificationState($emailForBind);
            if ($existing && !empty($existing['email_verified'])) {
                $userId = $existing['id'];
            }
        }

        if (!$userId) {
            // 创建新用户
            $username = $provider . '_' . substr($oauthId, 0, 10);
            $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username);
            $userId = UserRepository::insertUser([
                'username' => $username,
                'password' => Auth::hashPassword(bin2hex(random_bytes(32))),
                'email' => $email ?: ($provider . '_user_' . $oauthId . '@oauth.local'),
                'email_verified' => $emailForBind !== '' ? 1 : 0,
                'nickname' => Helper::uniqueNickname($nickname),
                'avatar' => $avatar ?: '/templates/moments/assets/images/default-avatar.svg',
                'role' => 'user',
                'status' => 1,
                'token_version' => 1,
                'oauth_provider' => $provider,
                'oauth_id' => $oauthId,
                'created_at' => Helper::now(),
                'updated_at' => Helper::now()
            ]);
        }

        // 关联 OAuth 账号
        UserRepository::insertOauthAccount([
            'user_id' => $userId,
            'provider' => $provider,
            'oauth_id' => $oauthId,
            'access_token' => $accessToken,
            'raw_data' => json_encode($userInfo, JSON_UNESCAPED_UNICODE),
            'created_at' => Helper::now(),
            'updated_at' => Helper::now()
        ]);

        $user = UserRepository::findBasicById($userId);
        $token = Auth::generateToken($user);
        Auth::setAuthCookie($token);
        if ($jsonMode) {
            MediaHelper::processUser($user);
            return Response::success(['user' => $user, 'token' => $token], 'OAuth 注册成功');
        }
        $baseUrl = PathResolver::baseUrl();
        header('Location: ' . $baseUrl . '/?oauth_token=' . urlencode($token));
        exit;
    }

    /**
     * OAuth Code 兑换（App/小程序模式）
     * 客户端自行完成 OAuth 跳转拿到 code 后，POST 给后端兑换 JWT
     * 参数: provider(github/google/wechat), code, state(必填，/auth/oauth/{provider} 签发)
     * 返回: {user, token} JSON
     */
    public function oauthExchange(Request $request): Response
    {
        $provider = $request->getString('provider');
        $code = $request->getString('code');
        $allowed = ['github', 'google', 'wechat'];
        if (!in_array($provider, $allowed, true)) {
            return Response::error('不支持的登录方式', 400);
        }
        if (empty($code)) {
            return Response::error('缺少授权码 code', 400);
        }

        // 会员系统治理 P0-2：state 强制校验（原 P2-5 兼容方案允许缺省/未命中放行，
        // 存在登录 CSRF 与账号绑定劫持面）。现网 App 未调用本接口，可安全收紧。
        // 校验口径与 Web oauthCallback 一致：provider 匹配 + 存在性；IP 不一致仅告警
        // （移动网络切换 / XFF 部署会误杀），不设浏览器 Cookie 绑定（App 无浏览器态）。
        $state = $request->getString('state');
        if (empty($state)) {
            return Response::error('授权失败：缺少 state', 400);
        }
        $stateKey = 'oauth_state_' . $state;
        $stateData = Cache::get($stateKey);
        if (!is_array($stateData) || ($stateData['provider'] ?? '') !== $provider) {
            return Response::error('授权失败：状态验证错误', 400);
        }
        if (($stateData['ip'] ?? '') !== $request->getIp()) {
            Logger::warning('[Hublog OAuth] exchange state IP 不一致（已放行）', [
                'provider' => $provider,
            ]);
        }
        // 先删后用：保证同一 state 不能被兑换两次（防重放）
        Cache::delete($stateKey);

        $settings = Cache::remember('site_settings', function () {
            $rows = UserRepository::fetchAllSettings();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);

        $clientId = $settings["oauth_{$provider}_client_id"] ?? '';
        $clientSecret = $settings["oauth_{$provider}_client_secret"] ?? '';
        if (empty($clientId)) {
            return Response::error("{$provider} 登录未配置", 400);
        }

        // 获取 access_token
        $tokenUrls = [
            'github' => 'https://github.com/login/oauth/access_token',
            'google' => 'https://oauth2.googleapis.com/token',
            'wechat' => 'https://api.weixin.qq.com/sns/oauth2/access_token',
        ];

        $postFields = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);

        $ch = curl_init($tokenUrls[$provider]);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $tokenData = json_decode($response, true);
        $accessToken = $tokenData['access_token'] ?? '';
        if (empty($accessToken)) {
            return Response::error('授权失败：无法获取访问令牌', 400);
        }

        // 获取用户信息
        $userUrls = [
            'github' => 'https://api.github.com/user',
            'google' => 'https://www.googleapis.com/oauth2/v2/userinfo',
            'wechat' => 'https://api.weixin.qq.com/sns/userinfo',
        ];

        $ch = curl_init($userUrls[$provider]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}", 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $userInfo = json_decode($response, true);
        $oauthId = (string)($userInfo['id'] ?? '');
        $email = $userInfo['email'] ?? '';
        $nickname = $userInfo['name'] ?? ($userInfo['login'] ?? 'User');
        $avatar = $userInfo['avatar_url'] ?? ($userInfo['picture'] ?? ($userInfo['headimgurl'] ?? ''));
        // 头像来自 provider 响应：仅放行本站域名或该 provider 的已知 CDN 域名
        $cdnHosts = MediaHelper::OAUTH_CDN[$provider] ?? [];
        if ($avatar !== '' && !MediaHelper::isSafeMediaUrl((string)$avatar, 0, $cdnHosts)) {
            $avatar = '/templates/moments/assets/images/default-avatar.svg';
        }

        if (empty($oauthId)) {
            return Response::error('授权失败：无法获取用户信息', 400);
        }

        // P0-2 修复：仅信任已验证邮箱，避免 ATO（与 oauthCallback 同逻辑）
        $verifiedEmail = '';
        if ($provider === 'github') {
            $ch = curl_init('https://api.github.com/user/emails');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}", 'Accept: application/json'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            $emailsResp = curl_exec($ch);
            curl_close($ch);
            $emails = json_decode((string)$emailsResp, true);
            if (is_array($emails)) {
                foreach ($emails as $item) {
                    if (!empty($item['verified']) && !empty($item['primary']) && !empty($item['email'])) {
                        $verifiedEmail = $item['email'];
                        break;
                    }
                }
            }
        } elseif ($provider === 'google') {
            if (!empty($userInfo['email_verified']) && !empty($email)) {
                $verifiedEmail = $email;
            }
        }
        $emailForBind = $verifiedEmail;

        // 查找已关联的用户
        $oauthAccount = UserRepository::findOauthAccount($provider, $oauthId);

        if ($oauthAccount) {
            $user = UserRepository::findActiveBasicById((int)$oauthAccount['user_id']);
            if (!$user) {
                return Response::error('账号已被禁用', 403);
            }
            $token = Auth::generateToken($user);
            Auth::setAuthCookie($token);
            MediaHelper::processUser($user);
            return Response::success(['user' => $user, 'token' => $token], 'OAuth 登录成功');
        }

        // 未关联：仅当存在已验证邮箱时，才允许自动关联到现有账号
        $userId = null;
        if ($emailForBind !== '') {
            $existing = UserRepository::findEmailVerificationState($emailForBind);
            if ($existing && !empty($existing['email_verified'])) {
                $userId = $existing['id'];
            }
        }

        if (!$userId) {
            $username = $provider . '_' . substr($oauthId, 0, 10);
            $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username);
            $userId = UserRepository::insertUser([
                'username' => $username,
                'password' => Auth::hashPassword(bin2hex(random_bytes(32))),
                'email' => $email ?: ($provider . '_user_' . $oauthId . '@oauth.local'),
                'email_verified' => $emailForBind !== '' ? 1 : 0,
                'nickname' => Helper::uniqueNickname($nickname),
                'avatar' => $avatar ?: '/templates/moments/assets/images/default-avatar.svg',
                'role' => 'user',
                'status' => 1,
                'token_version' => 1,
                'oauth_provider' => $provider,
                'oauth_id' => $oauthId,
                'created_at' => Helper::now(),
                'updated_at' => Helper::now()
            ]);
        }

        // 关联 OAuth 账号
        UserRepository::insertOauthAccount([
            'user_id' => $userId,
            'provider' => $provider,
            'oauth_id' => $oauthId,
            'access_token' => $accessToken,
            'raw_data' => json_encode($userInfo, JSON_UNESCAPED_UNICODE),
            'created_at' => Helper::now(),
            'updated_at' => Helper::now()
        ]);

        $user = UserRepository::findBasicById($userId);
        $token = Auth::generateToken($user);
        Auth::setAuthCookie($token);
        return Response::success(['user' => $user, 'token' => $token], 'OAuth 注册成功');
    }

    /**
     * 微信小程序登录
     * 流程：小程序前端 wx.login() 获取 code → POST 给后端
     *       后端调用 jscode2session 换 openid → 查找/创建用户 → 签发 JWT
     * 参数: code(小程序 wx.login 的 code), nickname/avatar(可选，首次登录时传入)
     * 返回: {user, token} JSON
     */
    public function wxMiniLogin(Request $request): Response
    {
        $code = $request->getString('code');
        if (empty($code)) {
            return Response::error('缺少小程序登录码 code', 400);
        }

        $settings = Cache::remember('site_settings', function () {
            $rows = UserRepository::fetchAllSettings();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);

        $appId = $settings['oauth_wechat_mini_appid'] ?? '';
        $appSecret = $settings['oauth_wechat_mini_secret'] ?? '';
        if (empty($appId) || empty($appSecret)) {
            return Response::error('微信小程序登录未配置', 400);
        }

        // 调用微信 jscode2session 接口换取 openid + session_key
        $url = sprintf(
            'https://api.weixin.qq.com/sns/jscode2session?appid=%s&secret=%s&js_code=%s&grant_type=authorization_code',
            $appId,
            $appSecret,
            $code
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return Response::error('微信接口请求失败', 500);
        }

        $sessionData = json_decode($response, true);
        if (empty($sessionData['openid'])) {
            $errMsg = $sessionData['errmsg'] ?? '未知错误';
            $errCode = $sessionData['errcode'] ?? -1;
            return Response::error("微信登录失败: {$errMsg} ({$errCode})", 400);
        }

        $openid = $sessionData['openid'];
        $sessionKey = $sessionData['session_key'] ?? '';
        $unionid = $sessionData['unionid'] ?? '';

        // 查找已关联的小程序用户（provider = wechat_mini）
        $oauthAccount = UserRepository::findWechatMiniAccount($openid);

        if ($oauthAccount) {
            // 已关联，直接登录
            $user = UserRepository::findActiveBasicById((int)$oauthAccount['user_id']);
            if (!$user) {
                return Response::error('账号已被禁用', 403);
            }
            // 更新 session_key（用于后续解密小程序数据）
            UserRepository::updateOauthAccountTokens(
                (int)$oauthAccount['id'],
                $sessionKey,
                json_encode($sessionData, JSON_UNESCAPED_UNICODE),
                Helper::now()
            );

            $token = Auth::generateToken($user);
            Auth::setAuthCookie($token);
            return Response::success(['user' => $user, 'token' => $token], '登录成功');
        }

        // 首次登录，创建新用户
        $nickname = $request->getString('nickname') ?: '微信用户';
        // 头像由客户端上报：不信外部域名，非法值回落默认图（不 400，保持登录可用）
        $avatar = $request->getString('avatar') ?: '/templates/moments/assets/images/default-avatar.svg';
        if ($avatar !== '' && !MediaHelper::isSafeMediaUrl($avatar, 0)) {
            $avatar = '/templates/moments/assets/images/default-avatar.svg';
        }
        $username = 'wx_' . substr($openid, 0, 12);
        $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username);

        // 确保用户名唯一
        $suffix = 0;
        $baseUsername = $username;
        while (UserRepository::userIdByUsername($username)) {
            $suffix++;
            $username = $baseUsername . '_' . $suffix;
        }

        $userId = UserRepository::insertUser([
            'username' => $username,
            'password' => Auth::hashPassword(bin2hex(random_bytes(32))),
            'email' => 'wx_mini_' . $openid . '@oauth.local',
            'email_verified' => 0,
            'nickname' => Helper::uniqueNickname(Helper::sanitizeName($nickname)),
            'avatar' => $avatar,
            'role' => 'user',
            'status' => 1,
            'token_version' => 1,
            'oauth_provider' => 'wechat_mini',
            'oauth_id' => $openid,
            'created_at' => Helper::now(),
            'updated_at' => Helper::now()
        ]);

        // 关联 OAuth 账号
        UserRepository::insertOauthAccount([
            'user_id' => $userId,
            'provider' => 'wechat_mini',
            'oauth_id' => $openid,
            'access_token' => $sessionKey,
            'raw_data' => json_encode($sessionData, JSON_UNESCAPED_UNICODE),
            'created_at' => Helper::now(),
            'updated_at' => Helper::now()
        ]);

        $user = UserRepository::findBasicById($userId);
        $token = Auth::generateToken($user);
        Auth::setAuthCookie($token);
        return Response::success(['user' => $user, 'token' => $token], '注册成功');
    }
}
