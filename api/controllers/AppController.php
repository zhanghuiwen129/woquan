<?php
/**
 * App / 多端专属接口控制器
 *
 * 与 Web 模板端解耦：所有 App 侧能力（版本更新、推送令牌注册）集中在此，
 * 不会影响前端模板已有行为。
 *
 * 路由前缀：/api/app/*
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Database;
use Hublog\Core\Helper;
use Hublog\Core\Cache;
use Hublog\Core\Auth;
use Hublog\Core\Logger;
use Hublog\Core\MediaHelper;
use Hublog\Core\RefreshToken;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\AppRepository;
use Hublog\Core\PageRepository;
use Hublog\Core\AgreementTexts;

class AppController extends BaseController
{
    /** 当前 App 场景：只要客户端声明了类型或携带 device_id，就下发双令牌 */
    private function wantsRefreshToken($request): bool
    {
        $clientType = strtolower(trim((string)$request->getHeader('x-client-type')));
        if (in_array($clientType, ['app', 'android', 'ios', 'miniapp', 'h5'], true)) {
            return true;
        }
        return trim((string)$request->getString('device_id')) !== '';
    }

    /** 读取站点配置（带缓存） */
    private function settings(): array
    {
        return Cache::remember('site_settings', function () {
            $rows = AppRepository::fetchSettings();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);
    }

    /** 统一签发双令牌响应 */
    private function buildLoginResult(array $user, $request): array
    {
        $token = Auth::generateToken($user);
        Auth::setAuthCookie($token); // App 内嵌 WebView 场景同源可见；原生端忽略 Set-Cookie
        $result = ['user' => $user, 'token' => $token];
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
        return $result;
    }
    /**
     * GET /api/app/version —— 检查 App 最新版本
     *
     * 参数：platform=android|ios|h5，code=当前版本整数号（可选）
     * 返回：{ has_update, force, version_name, version_code, changelog, download_url }
     */
    public function version(\Hublog\Core\Request $request): \Hublog\Core\Response
    {
        $platform = strtolower(trim((string)$request->getString('platform')));
        if (!in_array($platform, ['android', 'ios', 'h5'], true)) {
            $platform = 'android';
        }
        $currentCode = max(0, $request->getInt('code', 0));

        $cacheKey = 'app_version_' . $platform;
        $latest = Cache::remember($cacheKey, function () use ($platform) {
            return AppRepository::findLatestVersion($platform);
        }, 300);

        if (!$latest) {
            return \Hublog\Core\Response::success([
                'has_update' => false,
                'force'      => false
            ], '当前已是最新版本');
        }

        $latestCode = (int)$latest['version_code'];
        $hasUpdate = $latestCode > $currentCode;

        return \Hublog\Core\Response::success([
            'has_update'     => $hasUpdate,
            'force'          => $hasUpdate && (int)$latest['update_type'] === 1,
            'version_name'   => $latest['version_name'],
            'version_code'   => $latestCode,
            'changelog'      => $latest['changelog'] ?: '',
            'download_url'   => $latest['download_url'] ?: ''
        ], $hasUpdate ? '发现新版本' : '当前已是最新版本');
    }

    /**
     * POST /api/app/push-token —— 登记设备推送令牌（后续接入 UniPush / 极光）
     *
     * 目前只做登记与去重，等离线推送服务商配置完成后即可在业务事件处触发推送。
     * 参数：{ provider, token, device_id }
     */
    public function registerPushToken(\Hublog\Core\Request $request): \Hublog\Core\Response
    {
        $user = \Hublog\Core\Auth::check($request);

        $provider = strtolower(trim((string)$request->getString('provider')));
        $token    = trim((string)$request->getString('token'));
        $deviceId = trim((string)$request->getString('device_id'));

        if (!in_array($provider, ['unipush', 'jpush', 'getui', 'apns', 'fcm'], true)) {
            return \Hublog\Core\Response::error('不支持的推送服务商', 400);
        }
        if ($token === '' || strlen($token) < 16 || strlen($token) > 512) {
            return \Hublog\Core\Response::error('推送令牌格式不正确', 400);
        }

        $exists = AppRepository::findPushToken((int)$user['id'], $provider, $token);

        if ($exists) {
            AppRepository::updatePushToken((int)$exists['id'], Helper::now(), $deviceId);
            return \Hublog\Core\Response::success(null, '推送令牌已更新');
        }

        AppRepository::insertPushToken([
            'user_id'    => $user['id'],
            'provider'   => $provider,
            'token'      => $token,
            'device_id'  => mb_substr($deviceId, 0, 64),
            'created_at' => Helper::now(),
            'updated_at' => Helper::now()
        ]);

        return \Hublog\Core\Response::success(null, '推送令牌登记成功');
    }

    /**
     * POST /api/app/auth/wx-app-login —— 微信开放平台（移动应用）一键登录
     *
     * 流程：App 端微信 SDK 授权拿到 code → 后端换 access_token/openid/unionid → 拉用户信息
     * → 已绑定直接登录，未绑定则按 unionid 打通小程序账号，仍无则自动注册。
     *
     * 参数：{ code, device_id?, nickname?, avatar? }
     * 配置：settings 表 oauth_wechat_app_appid / oauth_wechat_app_secret
     */
    public function wxAppLogin(Request $request): Response
    {
        $settings = $this->settings();
        $appId     = (string)($settings['oauth_wechat_app_appid'] ?? ($settings['oauth_wechat_appid'] ?? ''));
        $appSecret = (string)($settings['oauth_wechat_app_secret'] ?? ($settings['oauth_wechat_secret'] ?? ''));
        if ($appId === '' || $appSecret === '') {
            return Response::error('微信 App 登录未配置', 400);
        }

        $code        = trim((string)$request->getString('code'));
        $accessToken = trim((string)$request->getString('access_token'));
        $openid      = trim((string)$request->getString('openid'));
        $unionid     = '';

        if ($code !== '') {
            // ① 标准流程：code → access_token / openid / unionid
            $tokenUrl = 'https://api.weixin.qq.com/sns/oauth2/access_token?appid=' . rawurlencode($appId)
                . '&secret=' . rawurlencode($appSecret)
                . '&code=' . rawurlencode($code)
                . '&grant_type=authorization_code';
            $tokenData = $this->httpGetJson($tokenUrl);
            if (!is_array($tokenData) || empty($tokenData['openid'])) {
                $errmsg = $tokenData['errmsg'] ?? '未知错误';
                $errcode = $tokenData['errcode'] ?? -1;
                return Response::error("微信登录失败：{$errmsg}（{$errcode}）", 400);
            }
            $openid      = (string)$tokenData['openid'];
            $accessToken = (string)($tokenData['access_token'] ?? '');
            $unionid     = (string)($tokenData['unionid'] ?? '');
        } elseif ($accessToken !== '' && $openid !== '') {
            // ② App 端 SDK 直出 access_token（uni.login 的 authResult）→ 校验有效性
            $authUrl = 'https://api.weixin.qq.com/sns/auth?access_token=' . rawurlencode($accessToken)
                . '&openid=' . rawurlencode($openid);
            $auth = $this->httpGetJson($authUrl);
            if (!is_array($auth) || (int)($auth['errcode'] ?? 0) !== 0) {
                return Response::error('微信授权已失效，请重新授权', 401);
            }
        } else {
            return Response::error('缺少微信授权凭证（code 或 access_token + openid）', 400);
        }

        // ② 拉用户资料（失败不阻断，用默认昵称兜底）
        $nickname = trim((string)$request->getString('nickname'));
        $avatar   = trim((string)$request->getString('avatar'));
        // 客户端自报头像不可信：非本站域名直接丢弃，落库时回落默认图（不阻断登录）
        if ($avatar !== '' && !MediaHelper::isSafeMediaUrl($avatar, 0)) {
            $avatar = '';
        }
        if ($accessToken !== '') {
            $infoUrl = 'https://api.weixin.qq.com/sns/userinfo?access_token=' . rawurlencode($accessToken)
                . '&openid=' . rawurlencode($openid) . '&lang=zh_CN';
            $info = $this->httpGetJson($infoUrl);
            if (is_array($info) && !empty($info['nickname'])) {
                $nickname = $nickname ?: (string)$info['nickname'];
                $avatar   = $avatar ?: (string)($info['headimgurl'] ?? '');
                // 微信 API 返回的头像：额外放行微信官方 CDN 域名，其余一律回落默认图
                if ($avatar !== '' && !MediaHelper::isSafeMediaUrl($avatar, 0, MediaHelper::OAUTH_CDN['wechat'])) {
                    $avatar = '';
                }
                if ($unionid === '' && !empty($info['unionid'])) {
                    $unionid = (string)$info['unionid'];
                }
            }
        }

        $rawData = json_encode([
            'openid'  => $openid,
            'unionid' => $unionid,
            'nickname'=> $nickname,
            'avatar'  => $avatar,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // ③ 查找已有绑定：先按 wechat_app+openid，再按 unionid 打通小程序账号
        $oauthAccount = AppRepository::findOauthByProviderId('wechat_app', $openid);
        if (!$oauthAccount && $unionid !== '') {
            $oauthAccount = AppRepository::findOauthByUnionid($unionid);
        }

        if ($oauthAccount) {
            $user = AppRepository::findActiveUserById((int)$oauthAccount['user_id']);
            if (!$user) {
                return Response::error('账号已被禁用', 403);
            }
            AppRepository::updateOauthAccount((int)$oauthAccount['id'], $accessToken, $rawData, Helper::now());
            AppRepository::touchUserLogin((int)$user['id'], Helper::now());
            MediaHelper::processUser($user);

            return Response::success($this->buildLoginResult($user, $request), '登录成功');
        }

        // ④ 首次登录：自动注册并与同一 unionid 的小程序账号互通（unionid 为空则新建）
        $targetUserId = 0;
        if ($unionid !== '') {
            $exists = AppRepository::findUserByOauthId($unionid);
            if ($exists) {
                $targetUserId = (int)$exists['id'];
            }
        }

        if ($targetUserId > 0) {
            AppRepository::insertOauthAccount([
                'user_id'      => $targetUserId,
                'provider'     => 'wechat_app',
                'oauth_id'     => $openid,
                'access_token' => $accessToken,
                'raw_data'     => $rawData,
                'created_at'   => Helper::now(),
                'updated_at'   => Helper::now()
            ]);
            $user = AppRepository::findUserById($targetUserId);
            MediaHelper::processUser($user);
            return Response::success($this->buildLoginResult($user, $request), '已绑定微信，登录成功');
        }

        $username = 'wx_' . substr($openid, 0, 12);
        $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username);
        $base = $username;
        $suffix = 0;
        while (AppRepository::usernameExists($username)) {
            $suffix++;
            $username = $base . '_' . $suffix;
        }

        $userId = AppRepository::insertUser([
            'username'       => $username,
            'password'       => Auth::hashPassword(bin2hex(random_bytes(32))),
            'email'          => 'wx_app_' . $openid . '@oauth.local',
            'email_verified' => 0,
            // V53：nickname 唯一索引兜底，微信默认昵称「微信用户」可能已被占用 → 消歧
            'nickname'       => Helper::uniqueNickname(Helper::sanitizeName($nickname ?: '微信用户')),
            'avatar'         => $avatar !== '' ? $avatar : '/templates/moments/assets/images/default-avatar.svg',
            'role'           => 'user',
            'status'         => 1,
            'token_version'  => 1,
            'oauth_provider' => 'wechat_app',
            'oauth_id'       => $unionid !== '' ? $unionid : $openid,
            'created_at'     => Helper::now(),
            'updated_at'     => Helper::now()
        ]);

        AppRepository::insertOauthAccount([
            'user_id'      => $userId,
            'provider'     => 'wechat_app',
            'oauth_id'     => $openid,
            'access_token' => $accessToken,
            'raw_data'     => $rawData,
            'created_at'   => Helper::now(),
            'updated_at'   => Helper::now()
        ]);

        $user = AppRepository::findUserById($userId);
        MediaHelper::processUser($user);
        return Response::success($this->buildLoginResult($user, $request), '注册成功');
    }

    /**
     * GET /api/app/config —— App 启动配置中心
     *
     * 一次请求拿到启动所需的所有开关与文案，避免 App 端硬编码。
     */
    public function config(Request $request): Response
    {
        $s = $this->settings();

        // 发布限制：统一由站点设置下发，App 端不再硬编码（前端写死会与后台配置脱节）
        $maxLength     = (int)($s['post_max_length'] ?? '2000');
        $maxImages     = (int)($s['upload_max_images'] ?? '9');
        $maxVideos     = (int)($s['upload_max_videos'] ?? '3');
        $maxAttachments= (int)($s['upload_max_attachments'] ?? '5');

        $cfg = [
            'site_name'      => (string)($s['site_name'] ?? '我圈'),
            'site_logo'      => (string)($s['site_logo'] ?? ''),
            'site_desc'      => (string)($s['site_desc'] ?? ''),
            'icp'            => (string)($s['icp_no'] ?? ($s['site_icp'] ?? '')),
            'register_open'  => ($s['allow_register'] ?? '1') === '1',
            'upload_max_size'=> (int)(defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : 5 * 1024 * 1024),
            'max_images'     => max(1, $maxImages),
            'video_enabled'  => true,
            // 发布限制（App 端用于输入校验与选择器上限，避免提交后才收到 400）
            'limits'         => [
                'post_max_length'        => max(100, $maxLength),
                'upload_max_images'      => max(1, $maxImages),
                'upload_max_videos'      => max(0, $maxVideos),
                'upload_max_attachments' => max(0, $maxAttachments),
                'upload_max_size'        => (int)(defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : 5 * 1024 * 1024),
                'title_required'         => ($s['title_required'] ?? '0') === '1',
                'category_required'      => ($s['category_required'] ?? '0') === '1',
            ],
            'agreements'     => [
                'user'   => (string)($s['agreement_user_url'] ?? '/h5/#/pages/agreement/agreement?type=user'),
                'privacy'=> (string)($s['agreement_privacy_url'] ?? '/h5/#/pages/agreement/agreement?type=privacy'),
            ],
            'contact'        => (string)($s['site_contact'] ?? ''),
            'wx_app_login'   => !empty($s['oauth_wechat_app_appid'] ?? ($s['oauth_wechat_appid'] ?? '')),
            'wx_mini_login'  => !empty($s['oauth_wechat_mini_appid'] ?? ''),
            'push_enabled'   => ($s['push_enabled'] ?? '0') === '1',
            // 站点开关：关掉的功能 App 端直接隐藏入口，避免用户填完才收到 403
            'allow_location' => ($s['allow_location'] ?? '1') === '1',
            'allow_mention'  => ($s['allow_mention'] ?? '1') === '1',
            // 圈子功能总开关：后台关掉后 App 发布页不再出现「发布到圈子」等任何圈子入口
            'allow_circle'   => ($s['allow_circle'] ?? '1') === '1',
            // 定时发布：站点开关 + 授权双重判定，未授权时 App 端直接隐藏入口，
            // 避免用户填完内容提交才收到 402
            'allow_scheduled_post' => (($s['allow_scheduled_post'] ?? '1') === '1')
                && \Hublog\Core\License::canUseModule('scheduled'),
            // V55：音视频通话。只下发 SDKAppID（不下发 SecretKey），未配置时 App 端隐藏通话入口
            'trtc_enabled'   => \Hublog\Core\TrtcSig::configured(),
            'trtc_sdk_app_id' => \Hublog\Core\TrtcSig::sdkAppId(),
            'server_time'    => time()
        ];

        // ===== V64：发布功能开关（后台「功能开关」页配置，App 端照此渲染入口）=====
        // 只下发白名单内的值；开关串缺失（老站点 / 新装站点）→ 默认全开，
        // 避免升级瞬间因为读不到配置把所有入口都吞掉。
        $cfg['post_types'] = self::resolvePostTypes($s);
        $cfg['post_tools'] = self::resolvePostTools($s);

        // 站点模式：模块开关下发给 App（与 /site/config 共用同一载荷）；
        // 旧版 App 不认识这些字段时按「缺失即全显示」处理，无需强更。
        $cfg += \Hublog\Core\FeatureFlags::publicPayload();

        return Response::success($cfg);
    }

    /**
     * V64：解析「帖子类型」开关 → ['normal'=>true, 'poll'=>true, ...]
     *
     * 规则：
     *   ① 开关串里的取值先过白名单，脏值直接丢弃（后台页面不该塞出脏值，这里是兜底）；
     *   ② normal（普通帖）恒为 true —— 它是兜底帖型，关掉等于站点发不了任何东西；
     *   ③ 开关串缺失 / 解析不出东西 → 全部帖型开启（新装站点默认是全开的）。
     */
    private static function resolvePostTypes(array $s): array
    {
        $white = ['normal', 'poll', 'question', 'team', 'checkin'];
        $raw = strtolower(trim((string)($s['post_types'] ?? '')));
        $on = [];
        if ($raw !== '') {
            foreach (explode(',', $raw) as $piece) {
                $piece = trim($piece);
                if (in_array($piece, $white, true)) $on[] = $piece;
            }
        }
        if ($on === []) {
            $on = $white;
        }
        $out = [];
        foreach ($white as $t) {
            $out[$t] = in_array($t, $on, true);
        }
        $out['normal'] = true;

        return $out;
    }

    /**
     * V64：解析「工具栏」开关 → ['image'=>true, 'video'=>false, ...]
     *
     * 与 resolvePostTypes 的差别：工具栏项还要再过一遍各自的「历史闸门」
     * （allow_location / allow_mention / allow_anonymous_post / allow_upload_attachment /
     * allow_upload_video / allow_scheduled_post）。这些布尔键存在得比 post_tools 早，
     * 关掉它们的位置应该继续生效，所以两边做「与」，不能谁覆盖谁。
     * 同理，开关串缺失时也是全部工具栏开启（老站点升级前默认都是开的）。
     */
    private static function resolvePostTools(array $s): array
    {
        $white = \Hublog\Controllers\AdminSettingsController::defaultPostTools();
        $gateMap = [
            'image'      => 'allow_upload_image',
            'video'      => 'allow_upload_video',
            'location'   => 'allow_location',
            'mention'    => 'allow_mention',
            'anonymous'  => 'allow_anonymous_post',
            'attachment' => 'allow_upload_attachment',
            'schedule'   => 'allow_scheduled_post',
        ];
        $raw = strtolower(trim((string)($s['post_tools'] ?? '')));
        $on = [];
        if ($raw !== '') {
            foreach (explode(',', $raw) as $piece) {
                $piece = trim($piece);
                if (in_array($piece, $white, true)) $on[] = $piece;
            }
        }
        if ($on === []) {
            $on = $white;
        }
        $out = [];
        foreach ($white as $tool) {
            $gate = $gateMap[$tool] ?? null;
            // 历史闸门存在且等于 '0' → 即便 post_tools 里勾了，也照样关掉
            $out[$tool] = in_array($tool, $on, true) && ($gate === null || ($s[$gate] ?? '1') !== '0');
        }
        // V142：AI 助手为新增推荐功能，默认开启（post_tools 是「开启列表」，这里强制开，
        // 确保发布页立即可用，无需先去后台「功能开关」重存配置）
        $out['ai'] = true;

        return $out;
    }

    /**
     * GET /api/app/agreements?type=user|privacy —— 用户协议 / 隐私政策正文
     *
     * 上架审核要求 App 内可完整阅读协议，不能只跳外链。
     */
    public function agreements(Request $request): Response
    {
        $type = strtolower(trim((string)$request->getString('type')));
        if (!in_array($type, ['user', 'privacy', 'realname'], true)) {
            $type = 'user';
        }
        $s = $this->settings();

        // V82：实名认证服务协议（实名页勾选时要能点开全文，以前只有用户/隐私两份）
        if ($type === 'realname') {
            $key = 'agreement_realname_text';
            $text = (string)($s[$key] ?? '');
            if ($text === '') {
                // 缺省正文集中在 AgreementTexts，使用方改一处即可；后台发布单页后仍优先生效
                $text = AgreementTexts::get('realname');
            }
            // B2-2/Q6：先用当前硬编码正文登记第三级回退，再由仓储按「通用页 → 旧配置 → 缺省」解析。
            // 返回四字段结构与字段顺序逐字不变。
            PageRepository::seedAgreements([$type => $text]);
            $resolved = PageRepository::resolveAgreement($type, $s);
            return Response::success([
                'type'       => $type,
                'title'      => '实名认证服务协议',
                'content'    => $resolved['content'],
                'updated_at' => $resolved['updated_at']
            ]);
        }

        $key = $type === 'privacy' ? 'agreement_privacy_text' : 'agreement_user_text';

        $text = (string)($s[$key] ?? '');
        if ($text === '') {
            // 缺省正文集中在 AgreementTexts，使用方改一处即可；后台发布单页后仍优先生效
            $text = AgreementTexts::get($type);
        }

        // B2-2/Q6：与 realname 分支同口径——登记硬编码缺省正文后走三级回退。
        // 返回四字段结构、字段顺序、标题与缺省正文逐字不变。
        PageRepository::seedAgreements([$type => $text]);
        $resolved = PageRepository::resolveAgreement($type, $s);
        return Response::success([
            'type'       => $type,
            'title'      => $type === 'privacy' ? '隐私政策' : '用户协议',
            'content'    => $resolved['content'],
            'updated_at' => $resolved['updated_at']
        ]);
    }

    /**
     * DELETE /api/app/account —— 账号注销（App 上架合规硬指标）
     *
     * 需提交当前密码二次确认。处置原则（V54）：
     *   A. 公开发表的内容（动态 / 评论）→ **保留**，只做身份匿名化。
     *      理由：这些内容上挂着其他用户的评论、收藏、圈子归属，物理删除会连坐破坏
     *      别人的内容与对话上下文；匿名化后已不再是可识别到特定自然人的个人信息。
     *      传 keep_posts=0 可退回「连同内容一起物理删除」。
     *   B. 身份与行为数据（点赞/收藏/关注/私信/通知/上传/OAuth/令牌/实名）→ 删除。
     *   C. users 主记录保留 + 匿名化 + deleted_at 标记 + status=0 + token_version+1
     *     （保留行避免外键悬挂；deleted_at 用于区分「已注销」与「被管理员封禁」）。
     */
    public function deleteAccount(Request $request): Response
    {
        $user = Auth::check($request);
        $password = (string)$request->getString('password');
        $confirm  = strtolower(trim((string)$request->getString('confirm')));

        if ($password === '') {
            return Response::error('请输入密码以确认注销', 400);
        }
        if ($confirm !== '1' && $confirm !== 'yes' && $confirm !== 'true') {
            return Response::error('请确认注销操作', 400);
        }

        $row = AppRepository::findUserCredentials((int)$user['id']);
        if (!$row || !Auth::verifyPassword($password, $row['password'])) {
            return Response::error('密码不正确，注销失败', 401);
        }

        $uid = (int)$user['id'];
        // keep_posts 默认 1（保留内容、匿名化）；显式传 0/no/false 才物理删除动态与评论
        $keepParam = strtolower(trim((string)$request->getString('keep_posts')));
        $keepPosts = !in_array($keepParam, ['0', 'no', 'false'], true);

        // E) 主记录匿名化字段：hash / 昵称生成仍由控制器编排，SQL 与事务下沉 Repository
        $userUpdates = [
            'username'            => 'deleted_' . $uid,
            'nickname'            => Helper::uniqueNickname('已注销用户', $uid),
            // email 有唯一索引 uk_email，置 '' 会让第二次注销撞唯一键（首次注销后库里有 ''）；
            // 列本身可空（migrate C：无邮箱则为 NULL），置 null 语义也正确
            'email'               => null,
            'email_verified'      => 0,
            'avatar'              => '/templates/moments/assets/images/default-avatar.svg',
            'cover'               => '',
            'bio'                 => '',
            'password'            => Auth::hashPassword(bin2hex(random_bytes(32))),
            'oauth_id'            => '',
            'realname_verified'   => 0,
            'realname_verified_at' => null,
            'status'              => 0,
            'deleted_at'          => Helper::now(),
            'token_version'       => (int)$user['token_version'] + 1,
            'updated_at'          => Helper::now()
        ];

        try {
            AppRepository::purgeAndAnonymizeUser($uid, $keepPosts, $userUpdates);
        } catch (\Throwable $e) {
            error_log('[Hublog AppController deleteAccount] ' . $e->getMessage()); // @allow-error-log
            return Response::error('注销失败，请稍后重试或联系管理员', 500);
        }

        // 原用户名会被释放（可被其他人注册），必须清掉它名下的资料缓存，
        // 否则新用户注册同名后 5 分钟内会读到上一个人的资料
        if (!empty($row['username'])) {
            Cache::delete("user_profile_{$row['username']}");
        }

        Auth::clear();
        return Response::success(null, '账号已注销，感谢您的使用');
    }

    /**
     * GET /api/app/categories —— 公开分类列表（发布时选择分类用）
     *
     * 现有 GET /api/admin/categories 属后台接口（需鉴权），App 端无法使用，
     * 这里提供一个只读的公开版本，仅返回启用中的分类。
     */
    public function categories(Request $request): Response
    {
        $rows = Cache::remember('app_categories', function () {
            return AppRepository::fetchActiveCategories();
        }, 600);

        return Response::success([
            'list' => array_map(function ($r) {
                return [
                    'id'        => (int)$r['id'],
                    'parent_id' => $r['parent_id'] === null ? 0 : (int)$r['parent_id'],
                    'name'      => (string)$r['name']
                ];
            }, $rows ?: [])
        ]);
    }

    /**
     * GET /api/app/devices —— 当前账号的登录设备列表（P0 设备/渠道识别的落地）
     *
     * 数据来源 refresh_tokens 表：一条有效 refresh_token 即代表一个登录会话。
     * 返回时标注 is_current（与本次请求的 device_id 一致）。
     */
    public function devices(Request $request): Response
    {
        $user = Auth::check($request);
        $currentDevice = trim((string)$request->getString('device_id'));

        $rows = AppRepository::fetchActiveRefreshTokens((int)$user['id']);

        $list = array_map(function ($r) use ($currentDevice) {
            return [
                'id'           => (int)$r['id'],
                'device_id'    => (string)$r['device_id'],
                'user_agent'   => (string)($r['user_agent'] ?? ''),
                'ip'           => (string)$r['ip'],
                'created_at'   => (string)$r['created_at'],
                'last_used_at' => (string)($r['last_used_at'] ?? ''),
                'expires_at'   => (string)$r['expires_at'],
                'is_current'   => $currentDevice !== '' && $r['device_id'] === $currentDevice
            ];
        }, $rows ?: []);

        return Response::success(['list' => $list]);
    }

    /**
     * POST /api/app/devices/kick —— 踢下线指定设备（远程登出）
     *
     * 参数：{ device_id } 或 { id }；传 all=1 表示踢掉除本机外的所有设备。
     * 实现：把对应 refresh_token 置为 revoked，access_token 到期后即失效；
     * 若需立即生效，可同时自增 token_version（此处采用立即生效策略）。
     */
    public function kickDevice(Request $request): Response
    {
        $user = Auth::check($request);
        $uid  = (int)$user['id'];
        $deviceId = trim((string)$request->getString('device_id'));
        $rowId    = $request->getInt('id');
        $kickAll  = $request->getString('all') === '1';
        $currentDevice = trim((string)$request->getString('current_device_id'));

        if (!$kickAll && $deviceId === '' && !$rowId) {
            return Response::error('请指定要登出的设备', 400);
        }

        if ($kickAll) {
            $mode = $currentDevice !== '' ? 'all_with_current' : 'all';
            $value = $currentDevice;
        } elseif ($rowId) {
            $mode = 'id';
            $value = (string)$rowId;
        } else {
            $mode = 'device';
            $value = $deviceId;
        }

        try {
            AppRepository::revokeDevices($uid, $mode, $value, (int)$user['token_version'], Helper::now());
        } catch (\Throwable $e) {
            error_log('[Hublog AppController kickDevice] ' . $e->getMessage()); // @allow-error-log
            return Response::error('操作失败，请稍后重试', 500);
        }

        return Response::success(null, '已登出该设备');
    }

    /**
     * POST /api/app/track —— App 端行为埋点（P2）
     *
     * 复用现有 Logger 写 JSONL 日志，不新建存储。
     * 参数：{ events: [{ event, ts, props }] } 或单条 { event, props }
     * 限制：单次最多 20 条，event 名白名单化，props 序列化上限 2KB。
     */
    public function track(Request $request): Response
    {
        // 埋点允许匿名（游客行为也有价值），登录则带上 user_id
        $userId = 0;
        try {
            $u = Auth::user($request);
            if ($u && !empty($u['id'])) {
                $userId = (int)$u['id'];
            }
        } catch (\Throwable $e) {
            $userId = 0;
        }

        $events = $request->getArray('events');
        if (empty($events)) {
            $single = $request->getString('event');
            if ($single !== '') {
                $events = [['event' => $single, 'props' => $request->getArray('props')]];
            }
        }
        if (empty($events)) {
            return Response::error('缺少埋点事件', 400);
        }
        if (count($events) > 20) {
            $events = array_slice($events, 0, 20);
        }

        // error = 客户端崩溃/未捕获异常；api_error = 接口层异常（上线后用于发现真实故障）
        $allowed = [
            'launch', 'login', 'logout', 'publish', 'comment', 'like',
            'share', 'search', 'chat_send', 'page_view', 'error', 'api_error'
        ];
        $recorded = 0;

        foreach ($events as $ev) {
            $name = is_array($ev) ? (string)($ev['event'] ?? '') : '';
            if ($name === '' || !in_array($name, $allowed, true)) {
                continue;
            }
            $props = is_array($ev) && isset($ev['props']) && is_array($ev['props']) ? $ev['props'] : [];

            if ($name === 'error' || $name === 'api_error') {
                // 客户端异常：除了保留原 Logger 文本日志外，单独落库到 app_crash_logs，
                // 以便后台「客户端异常」页面检索 / 筛选 / 聚合（error=崩溃，api_error=接口异常）
                // 前端把 platform 放在每个 event 对象里上报（track.js 的 platform 字段），
                // 请求级 platform 参数往往为空，这里优先用 event 级的，保证「按平台筛选」可用
                $evPlatform = is_array($ev) ? (string)($ev['platform'] ?? '') : '';
                $recorded += $this->saveCrashLog($name, $props, $userId, $request, $evPlatform);
            } else {
                $payload = json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($payload === false || strlen($payload) > 2048) {
                    $payload = '';
                }
                try {
                    Logger::info('app_track', [
                        'event'    => $name,
                        'user_id'  => $userId,
                        'client'   => substr((string)$request->getHeader('x-client-type'), 0, 16),
                        'platform' => substr((string)$request->getString('platform'), 0, 24),
                        'ip'       => $request->getIp(),
                        'props'    => $payload
                    ]);
                    $recorded++;
                } catch (\Throwable $e) {
                    // 埋点失败绝不影响业务
                    error_log('[Hublog AppController track] ' . $e->getMessage()); // @allow-error-log
                }
            }
        }

        return Response::success(['recorded' => $recorded]);
    }

    /**
     * 把客户端异常（error / api_error）写入 app_crash_logs 表
     *
     * 前端 utils/track.js 上报的字段：
     *   error     → { message, stack, context, route }
     *   api_error → { url, code, message, route }
     * 入库失败只记 error_log，绝不影响业务主流程（埋点本来就是尽力而为）。
     *
     * @return int 成功写入返回 1，否则 0
     */
    private function saveCrashLog(string $name, array $props, int $userId, Request $request, string $evPlatform = ''): int
    {
        try {
            $stack = (string)($props['stack'] ?? '');
            if ($stack !== '') {
                // 堆栈可能很长，截断到 16KB，避免单行过大撑爆表 / 拖慢检索
                $stack = mb_substr($stack, 0, 16384);
            }
            AppRepository::insertCrashLog([
                'user_id'  => $userId,
                'event'    => $name === 'api_error' ? 'api_error' : 'error',
                'platform' => mb_substr($evPlatform !== '' ? $evPlatform : (string)$request->getString('platform'), 0, 24),
                'client'   => mb_substr((string)$request->getHeader('x-client-type'), 0, 16),
                'route'    => mb_substr((string)($props['route'] ?? ''), 0, 160),
                'api_url'  => mb_substr((string)($props['url'] ?? ''), 0, 255),
                'api_code' => mb_substr((string)($props['code'] ?? ''), 0, 32),
                'message'  => mb_substr((string)($props['message'] ?? ''), 0, 500),
                'stack'    => $stack,
                'context'  => mb_substr((string)($props['context'] ?? ''), 0, 200),
                'ip'       => (string)$request->getIp(),
            ]);
            return 1;
        } catch (\Throwable $e) {
            error_log('[Hublog AppController saveCrashLog] ' . $e->getMessage()); // @allow-error-log
            return 0;
        }
    }

    /**
     * GET /api/app/wx-jsapi-config —— 微信 JS-SDK 注入配置（H5 分享到好友/朋友圈）
     *
     * 参数：url = 当前页面完整 URL（不含 # 及其后部分），必须与调用 JS-SDK 的页面一致
     * 返回：{ appId, timestamp, nonceStr, signature, ready }
     * 未配置公众号时返回 { ready: false }，前端据此降级为「复制链接」分享。
     *
     * 配置：settings 表 oauth_wechat_mp_appid / oauth_wechat_mp_secret
     */
    public function wxJsapiConfig(Request $request): Response
    {
        $settings = $this->settings();
        $appId     = (string)($settings['oauth_wechat_mp_appid'] ?? '');
        $appSecret = (string)($settings['oauth_wechat_mp_secret'] ?? '');
        if ($appId === '' || $appSecret === '') {
            return Response::success(['ready' => false], '未配置微信公众号');
        }

        $url = trim((string)$request->getString('url'));
        if ($url === '') {
            return Response::error('缺少当前页面 url', 400);
        }
        // 签名必须用前端实际 URL（去掉 hash 部分）
        $url = preg_replace('/#.*$/', '', $url);

        $ticket = $this->getWxJsapiTicket($appId, $appSecret);
        if (!$ticket) {
            return Response::success(['ready' => false], '获取 jsapi_ticket 失败');
        }

        $nonceStr  = bin2hex(random_bytes(8));
        $timestamp = time();
        $signStr   = "jsapi_ticket={$ticket}&noncestr={$nonceStr}&timestamp={$timestamp}&url={$url}";

        return Response::success([
            'ready'     => true,
            'appId'     => $appId,
            'timestamp' => $timestamp,
            'nonceStr'  => $nonceStr,
            'signature' => sha1($signStr)
        ]);
    }

    /**
     * 获取并缓存公众号 jsapi_ticket（access_token 与 ticket 均缓存，避免触发调用频率限制）
     */
    private function getWxJsapiTicket(string $appId, string $appSecret): string
    {
        $accessToken = Cache::remember('wx_mp_access_token_' . md5($appId), function () use ($appId, $appSecret) {
            $data = $this->httpGetJson(
                'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential'
                . '&appid=' . rawurlencode($appId)
                . '&secret=' . rawurlencode($appSecret)
            );
            return (is_array($data) && !empty($data['access_token'])) ? (string)$data['access_token'] : '';
        }, 7000);

        if ($accessToken === '') {
            return '';
        }

        $ticket = Cache::remember('wx_mp_jsapi_ticket_' . md5($appId), function () use ($accessToken) {
            $data = $this->httpGetJson(
                'https://api.weixin.qq.com/cgi-bin/ticket/getticket?type=jsapi'
                . '&access_token=' . rawurlencode($accessToken)
            );
            return (is_array($data) && !empty($data['ticket'])) ? (string)$data['ticket'] : '';
        }, 7000);

        return $ticket ?: '';
    }

    /** 简易 GET JSON（失败返回 null） */
    private function httpGetJson(string $url, int $timeout = 10): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
