<?php
declare(strict_types=1);
/**
 * Hublog 后台「站点设置」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\SettingsRepository;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Security;
use Hublog\Core\Helper;
use Hublog\Core\PathResolver;
use Hublog\Core\Upload;
use Hublog\Core\Upgrade;
use Hublog\Core\Watermark;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\BadgeService;
use Hublog\Core\MediaHelper;
use Hublog\Core\Theme;
use Hublog\Core\ModuleCatalog;
use Hublog\Core\FeatureFlags;

class AdminSettingsController extends AdminController
{
    /** 掩码占位串前缀：后端与前端共用同一约定，避免自动回填把占位串写回库 */
    private const MASKED_PREFIX = '******HUBLOG_SENSITIVE_MASKED:';

    /**
     * 敏感配置键权威清单。
     *
     * 掩码（GET）与跳过（PUT）共用本集合；不再用 stripos 模糊匹配，
     * 避免 amap_web_key / alipay_private_key / license_key 等漏网键明文外泄。
     * 公钥、bucket、endpoint、CDN 域名等非密钥项刻意不在表内。
     */
    public const SENSITIVE_KEYS = [
        'mail_password',
        'realname_appcode', 'realname_baidu_ak', 'realname_baidu_sk',
        'realname_tencent_secret_id', 'realname_tencent_secret_key',
        'sms_aliyun_access_key_id', 'sms_aliyun_access_key_secret',
        'sms_tencent_secret_id', 'sms_tencent_secret_key',
        'sms_baidu_ak', 'sms_baidu_sk',
        'ai_comment_api_key', 'ai_audit_api_key',
        'upload_oss_access_key_id', 'upload_oss_access_key_secret',
        'upload_cos_secret_id', 'upload_cos_secret_key',
        'upload_qiniu_access_key', 'upload_qiniu_secret_key',
        'wxpay_private_key', 'wxpay_api_v3_key', 'alipay_private_key',
        'amap_web_key', 'license_key',
        'cron_secret', 'backup_cron_secret', 'app_center_site_secret', 'metrics_secret',
        'oauth_wechat_app_secret', 'oauth_wechat_mini_secret', 'oauth_wechat_mp_secret', 'oauth_wechat_secret',
        'trtc_secret_key', 'push_jpush_master_secret', 'push_unipush_app_secret', 'push_fcm_server_key',
        'upgrade_repo_token', 'smart_search_api_key', 'smart_summary_api_key',
    ];

    public static function isSensitiveKey(string $key): bool
    {
        return in_array($key, self::SENSITIVE_KEYS, true);
    }

    public static function maskedValue(string $key): string
    {
        return self::MASKED_PREFIX . $key;
    }

    public function settings(Request $request): Response
    {
        $this->requireAdmin($request);

        if ($request->getMethod() === 'GET') {
            $settings = SettingsRepository::fetchKeyValueRows();
            $result = [];
            foreach ($settings as $s) {
                $isSensitive = self::isSensitiveKey((string)$s['key']);
                if (!empty($s['value']) && $isSensitive) {
                    $result[$s['key']] = self::maskedValue((string)$s['key']);
                } else {
                    $result[$s['key']] = $s['value'];
                }
            }
            // 站点模式元数据：注册表单一真值源，后台设置页据此渲染，前端零硬编码
            $result['module_catalog'] = ModuleCatalog::catalog();
            return Response::success($result);
        }

        $data = $request->all();
        // seo_robots_txt 由 /robots.txt 读取，此前漏在白名单外导致框架读得到、后台改不了。
        // 内容是纯文本，Security::purify 只做清理与 trim，不会吃掉换行。
        $allowed = ['site_name', 'site_url', 'site_desc', 'site_icp', 'site_keywords', 'site_description', 'site_stats_code', 'seo_robots_txt', 'homepage_cover', 'active_theme', 'allow_register', 'allow_post', 'allow_like', 'allow_comment', 'allow_guest_download_attachment', 'allow_upload_image', 'allow_upload_video', 'allow_upload_attachment', 'allow_mention', 'allow_location', 'allow_anonymous_post', 'allow_circle', 'mention_max_count', 'upload_max_images', 'upload_max_videos', 'upload_max_size', 'upload_max_video_size', 'upload_compress', 'upload_max_width', 'upload_max_attachments', 'upload_max_attachment_size', 'upload_attachment_exts', 'upload_max_avatar_size', 'post_max_length', 'post_collapse_length', 'post_title_preview_length', 'category_required', 'title_required', 'url_rewrite', 'rate_limit_requests', 'rate_limit_window', 'forbidden_nicknames', 'allowed_email_domains', 'nav_search_enabled', 'nav_messages_enabled', 'nav_favorites_enabled', 'nav_profile_enabled',
            // V43：图片水印
            'watermark_enabled', 'watermark_type', 'watermark_text', 'watermark_font_size', 'watermark_color',
            'watermark_opacity', 'watermark_position', 'watermark_margin', 'watermark_rotate', 'watermark_shadow',
            'watermark_image', 'watermark_image_scale', 'watermark_min_size', 'watermark_apply_avatar', 'watermark_font_path', 'ai_comment_enabled', 'ai_comment_provider', 'ai_comment_api_url', 'ai_comment_api_key', 'ai_comment_model', 'ai_comment_prompt', 'ai_comment_max_length', 'ai_comment_timeout', 'ai_audit_enabled', 'ai_audit_provider', 'ai_audit_api_url', 'ai_audit_api_key', 'ai_audit_model', 'ai_audit_custom_words',
            // 云存储直传（settings 同名配置覆盖 config.php 常量）
            'upload_driver', 'upload_sign_ttl', 'upload_dir_prefix', 'upload_cdn_common_domain',
            'upload_oss_access_key_id', 'upload_oss_access_key_secret', 'upload_oss_bucket', 'upload_oss_endpoint',
            'upload_oss_cdn_domain', 'upload_oss_ssl',
            'upload_cos_secret_id', 'upload_cos_secret_key', 'upload_cos_bucket', 'upload_cos_region',
            'upload_cos_cdn_domain', 'upload_cos_ssl',
            'upload_qiniu_access_key', 'upload_qiniu_secret_key', 'upload_qiniu_bucket', 'upload_qiniu_cdn_domain',
            'upload_qiniu_region', 'upload_qiniu_ssl',
            // 邮件 / 密码 / 语言
            'mail_enabled', 'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_from_name', 'mail_from_email', 'mail_encryption',
            'password_min_length', 'password_require_upper', 'password_require_lower', 'password_require_digit', 'password_require_special',
            'site_language', 'allow_language_switch',
            // V51：实名认证（多厂商）
            'realname_enabled', 'realname_provider', 'realname_appcode', 'realname_api_url',
            'realname_tencent_secret_id', 'realname_tencent_secret_key', 'realname_tencent_endpoint',
            'realname_tencent_action', 'realname_tencent_version', 'realname_tencent_region',
            'realname_baidu_ak', 'realname_baidu_sk', 'realname_baidu_endpoint', 'realname_baidu_path',
            // V82：短信服务（多厂商）
            'sms_provider',
            'sms_aliyun_access_key_id', 'sms_aliyun_access_key_secret', 'sms_aliyun_sign_name', 'sms_aliyun_template_code',
            'sms_tencent_secret_id', 'sms_tencent_secret_key', 'sms_tencent_app_id', 'sms_tencent_sign_name', 'sms_tencent_template_id', 'sms_tencent_region',
            'sms_baidu_ak', 'sms_baidu_sk', 'sms_baidu_endpoint', 'sms_baidu_path', 'sms_baidu_sign_id', 'sms_baidu_template_id',
            // 定位：高德 Web 服务 Key（经纬度反查中文地名用，后台填的值优先级高于 config.php 常量）
            'amap_web_key',
            // V64：发布功能开关（帖子类型 / 工具栏），具体取值见 self::$switchOptions
            'post_types', 'post_tools',
            // V65：定时发布调度 token（供外部 cron 打 /api/cron/scheduled）
            'cron_secret',
            // V67：会员定价
            'vip_price_1', 'vip_price_2', 'vip_price_3',
            // V68：积分汇率（每多少积分抵 1 元，默认 10）
            'point_rate',
            // V96：付费圈子订阅周期 / 到期提醒
            'circle_subscribe_days', 'circle_expire_remind_days', 'circle_sub_auto_cleanup',
            // V69：在线充值（微信支付 v3 / 支付宝）。私钥属敏感信息，仅管理员可读写。
            'pay_enabled', 'pay_wxpay_enabled', 'pay_alipay_enabled',
            'wxpay_appid', 'wxpay_mchid', 'wxpay_serial_no', 'wxpay_private_key', 'wxpay_api_v3_key',
            'alipay_appid', 'alipay_private_key', 'alipay_public_key',
            // V96：商城 + 积分大转盘开关与配置
            'wheel_cost', 'wheel_free_daily', 'mall_switch', 'shop_user_post', 'shop_notice',
            // 站点模式三键：由下方 saveSiteMode() 专用分支处理（不能进 purify 循环）
            'site_mode', 'module_flags', 'feature_guard_mode'
        ];

        // V64：多选开关。用「逗号分隔的合法取值串」而不是 N 个布尔键：
        // 后台界面只是一个复选框组，settings 表里也只占一行，避免每次加功能都要改三处白名单。
        // 合法取值表，保存时按它过滤（防前端传一堆乱值塞进 settings）。
        $listKeys = [
            'post_types' => [
                'normal', 'poll', 'question', 'team', 'checkin',
            ],
            'post_tools' => [
                'image', 'video', 'emoji', 'location', 'mention', 'anonymous',
                'link', 'attachment', 'topic', 'draft', 'schedule', 'category',
            ],
        ];
        $boolKeys = ['allow_register', 'allow_post', 'allow_like', 'allow_comment', 'upload_compress', 'allow_guest_download_attachment', 'allow_upload_image', 'allow_upload_video', 'allow_upload_attachment', 'allow_mention', 'allow_location', 'allow_anonymous_post', 'category_required', 'title_required', 'url_rewrite', 'nav_search_enabled', 'nav_messages_enabled', 'nav_favorites_enabled', 'nav_profile_enabled', 'ai_comment_enabled', 'ai_audit_enabled', 'upload_oss_ssl', 'upload_cos_ssl', 'upload_qiniu_ssl', 'mail_enabled', 'password_require_upper', 'password_require_lower', 'password_require_digit', 'password_require_special', 'allow_language_switch', 'watermark_enabled', 'watermark_apply_avatar', 'watermark_shadow', 'realname_enabled'];
        // 圈子功能总开关：App 发布页据此隐藏「发布到圈子」入口
        $boolKeys[] = 'allow_circle';
        // V96：过期订阅自动清退（关掉后只置 status=0，不删成员位）
        $boolKeys[] = 'circle_sub_auto_cleanup';

        // 站点模式三键必须走专用分支：下面的 purify 循环会 (string) 强转，
        // 数组 / JSON 塞进去会触发 Array to string conversion 并污染 settings 表。
        if (array_intersect_key($data, array_flip(['site_mode', 'module_flags', 'feature_guard_mode']))) {
            $modeError = self::saveSiteMode($data);
            if ($modeError instanceof Response) return $modeError;
        }

        // site_url 必须是绝对地址（http/https），非法值直接清空：
        // 该值会直接进入 canonical / og:url / sitemap 的 loc，填错会污染全站 SEO 地址。
        if (array_key_exists('site_url', $data)) {
            $siteUrl = trim((string)$data['site_url']);
            $data['site_url'] = ($siteUrl !== '' && preg_match('#^https?://[^\s/]+#i', $siteUrl)) ? $siteUrl : '';
        }

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;

            // V64：多选开关（post_types / post_tools）
            if (isset($listKeys[$key])) {
                $picked = [];
                foreach (explode(',', (string)$value) as $piece) {
                    $piece = strtolower(trim($piece));
                    if ($piece !== '' && in_array($piece, $listKeys[$key], true)) $picked[] = $piece;
                }
                $picked = array_values(array_unique($picked));
                // 一个都没勾 → 退回默认值。宁可「关不掉功能」也不能让站点变成啥都发不了。
                if ($picked === []) {
                    $picked = $key === 'post_types' ? ['normal'] : self::defaultPostTools();
                }
                // 普通帖永远是兜底帖型，不在后台提供关闭入口，这里兜一层防前端绕过
                if ($key === 'post_types' && !in_array('normal', $picked, true)) {
                    array_unshift($picked, 'normal');
                    $picked = array_values(array_unique($picked));
                }
                $value = implode(',', $picked);
            }

            // 掩码与跳过共用同一敏感键集合：占位串一旦落库会永久损坏支付/密钥配置
            if (self::isSensitiveKey((string)$key) && is_string($value) && str_starts_with($value, self::MASKED_PREFIX)) {
                continue;
            }

            $value = in_array($key, $boolKeys, true)
                ? ((string)$value === '1' || $value === true || $value === 1 ? '1' : '0')
                : Security::purify((string)$value);
            // upsert 内部先查后写，与原来的 exists 分支等价（含首次插入）。
            SettingsRepository::upsert($key, $value, Helper::now());
        }

        Cache::delete('site_settings');
        \Hublog\Core\AiService::clearConfigCache();
        Watermark::clearConfig();
        return Response::success(null, '保存成功');
    }

    /**
     * 站点模式三键专用保存分支。
     *
     * 为什么不走上面的通用循环：通用循环对每个值做 (string) 强转 + Security::purify，
     * module_flags 是数组 / JSON，进去会触发 Array to string conversion 并写脏值。
     * 校验全部前置，通过后才落库，避免「guard 写进去了、flags 却被拒绝」的半截状态。
     *
     * 模式名由最终 flags 反查（detectMode），前端上报的 site_mode 一律不采信。
     *
     * @return Response|null 非 null 表示校验失败，调用方直接返回给前端
     */
    private static function saveSiteMode(array &$data): ?Response
    {
        // 入口守卫（guard 取值、module_flags 成对提交、依赖二次校验）全部保留在本方法；
        // upsert 只做 SQL，不存在仓储内部 self:: 调用绕过校验的路径。
        $upsert = static function (string $key, string $value): void {
            SettingsRepository::upsert($key, $value, Helper::now());
        };

        // 1) 灰度总闸：允许单独提交，先校验不落库
        $guard = null;
        if (array_key_exists('feature_guard_mode', $data)) {
            $guard = (string)$data['feature_guard_mode'];
            if (!in_array($guard, ModuleCatalog::GUARD_MODES, true)) {
                return Response::error('运行层拦截灰度模式取值非法', 400);
            }
        }

        // 2) 模式名与开关必须成对提交，避免孤立改 site_mode 造成回显与实际不一致
        if (!array_key_exists('module_flags', $data)) {
            if ($guard !== null) $upsert('feature_guard_mode', $guard);
            unset($data['site_mode'], $data['module_flags'], $data['feature_guard_mode']);
            return null;
        }

        // 3) 严格解析 + 依赖二次校验
        $parsed = ModuleCatalog::parse($data['module_flags']);
        if ($parsed['error'] !== null) return Response::error($parsed['error'], 400);
        $flags = $parsed['flags'];
        $depError = ModuleCatalog::validate($flags);
        if ($depError !== null) return Response::error($depError, 400);

        // 4) 全部通过才写库
        $mode = ModuleCatalog::detectMode($flags);
        if ($guard !== null) $upsert('feature_guard_mode', $guard);
        $upsert('module_flags', json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $upsert('site_mode', $mode);

        $on = array_keys(array_filter($flags, static fn(int $v): bool => $v === 1));
        error_log('[SiteMode] mode=' . $mode . ' enabled=' . implode(',', $on) // @allow-error-log
            . ' guard=' . ($guard ?? FeatureFlags::guardMode()));

        // 5) 从通用循环里摘掉，避免二次写入 / purify
        unset($data['site_mode'], $data['module_flags'], $data['feature_guard_mode']);
        return null;
    }

    /**
     * 前台主题发现（P1-4）
     *
     * 扫 templates 下各主题的 theme.json，返回可用主题及其契约校验结果，供后台「站点与界面」
     * 的主题卡片渲染。契约校验（18 页 + JS/CSS/图片资源 + 清单必填字段）不通过的主题
     * 仍返回但标 valid=false + issues，前端据此置灰、禁止选中，避免半成品主题上线后静默丢功能。
     *
     * 不挂授权：theme 在 License 里是已废弃的幽灵功能（纯前端 CSS 后端拦不住），
     * 主题系统免费开放，避免引入无意义的付费拦截。
     */
    public function themes(Request $request): Response
    {
        $this->requireAdmin($request);
        return Response::success([
            'active'  => Theme::current(),
            'default' => Theme::default(),
            'themes'  => Theme::discover(),
        ]);
    }

    /**
     * GET /admin/theme-options — 当前主题的外观选项 schema 与已存值。
     * 选项按主题分别持久化（theme_opts_{theme}），切换主题不清掉其它主题存档，切回原值仍在。
     */
    public function themeOptions(Request $request): Response
    {
        $this->requireAdmin($request);
        $theme = Theme::current();
        $schema = Theme::optionsSchema($theme);
        $saved = Theme::readSavedOptions($theme);
        $values = Theme::mergeOptions($schema, $saved);
        return Response::success([
            'theme'  => $theme,
            'fields' => $schema,
            'values' => $values,
        ]);
    }

    /**
     * PUT /admin/theme-options — 保存当前主题外观选项，按 schema 逐字段校验后 JSON 落库。
     * 仅保存 schema 声明过的键，前端无法写入未声明项。
     */
    public function saveThemeOptions(Request $request): Response
    {
        $this->requireAdmin($request);
        $theme = Theme::current();
        $schema = Theme::optionsSchema($theme);

        $body = $request->all();
        $input = isset($body['options']) && is_array($body['options']) ? $body['options'] : [];
        $saved = Theme::readSavedOptions($theme);

        $clean = [];
        foreach ($schema as $f) {
            $key = $f['key'];
            if (array_key_exists($key, $input)) {
                $clean[$key] = $this->normalizeOptionValue($f, $input[$key]);
            } elseif (array_key_exists($key, $saved)) {
                // 请求未携带的声明字段保留已存值：管理员停留在旧页面期间主题新增了字段时，
                // 全量保存不会把新字段静默重置为默认值
                $clean[$key] = $saved[$key];
            } else {
                $clean[$key] = $this->normalizeOptionValue($f, $f['default']);
            }
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $optKey = 'theme_opts_' . $theme;
        SettingsRepository::upsert($optKey, $json, Helper::now());

        Cache::delete('site_settings');
        return Response::success(null, '外观设置已保存');
    }

    /** 按字段类型校验并规范化单个选项值；非法值回退字段默认值 */
    private function normalizeOptionValue(array $field, $raw)
    {
        switch ($field['type']) {
            case 'switch':
                return ($raw === true || $raw === '1' || $raw === 1 || $raw === 'on') ? true : false;
            case 'color':
                $v = is_string($raw) ? trim($raw) : '';
                return (preg_match('/^#[0-9a-fA-F]{6}$/', $v) || preg_match('/^#[0-9a-fA-F]{3}$/', $v)) ? $v : $field['default'];
            case 'select':
                $allowed = array_column($field['options'], 'value');
                $v = (string)$raw;
                return in_array($v, $allowed, true) ? $v : $field['default'];
            case 'number':
                return is_numeric($raw) ? $raw + 0 : $field['default'];
            case 'image':
                // 图片地址只做严格白名单（http(s) 绝对地址或站点根相对路径，禁空白/引号/尖括号），
                // 不走 Security::purify 以免转义 & 破坏查询串；正则本身已排除注入面
                $v = trim((string)$raw);
                if ($v === '') return '';
                return (preg_match('#^(https?://|/)[^\s"\'<>]+$#i', $v) && mb_strlen($v) <= 500) ? $v : $field['default'];
            case 'textarea':
                return mb_substr(Security::purify((string)$raw), 0, 2000);
            default:
                return mb_substr(Security::purify((string)$raw), 0, 200);
        }
    }

    /**
     * V64：发布页工具栏默认全开（新装站点 / 开关被清空时的兜底）
     *
     * 返回后台「功能开关」里工具栏复选框的完整列表，顺序即界面展示顺序。
     */
    public static function defaultPostTools(): array
    {
        return ['image', 'video', 'emoji', 'location', 'mention', 'anonymous',
            'link', 'attachment', 'topic', 'draft', 'schedule', 'category', 'ai'];
    }

    /**
     * V64：后台「功能开关」页的展示元数据
     *
     * 前端直接照这个表渲染复选框，新增一个工具栏按钮只要在这里加一行，
     * 不用同时改后台 HTML、保存逻辑和 App 端判断。
     *
     * @return array{tools: array<int, array{key:string,label:string,desc:string,gated:string|null}>, types: array<int, array{key:string,label:string,desc:string}>}
     */
    public static function featureSwitchMeta(): array
    {
        return [
            'tools' => [
                ['key' => 'image', 'label' => '图片', 'desc' => '从相册拍照 / 选图，可一次选多张', 'gated' => 'allow_upload_image'],
                ['key' => 'video', 'label' => '视频', 'desc' => '拍摄 / 选择视频上传', 'gated' => 'allow_upload_video'],
                ['key' => 'emoji', 'label' => '表情', 'desc' => '插入常用表情符号', 'gated' => null],
                ['key' => 'location', 'label' => '位置', 'desc' => '附加地理位置，需高德 Key 才能显示精确地名', 'gated' => 'allow_location'],
                ['key' => 'mention', 'label' => '@提及', 'desc' => '发布时 @ 其他用户', 'gated' => 'allow_mention'],
                ['key' => 'anonymous', 'label' => '匿名', 'desc' => '勾选后动态隐藏发布者昵称与头像', 'gated' => 'allow_anonymous_post'],
                ['key' => 'link', 'label' => '链接', 'desc' => '粘贴网页链接，自动抓取标题与简介生成卡片', 'gated' => null],
                ['key' => 'attachment', 'label' => '附件', 'desc' => '上传文档 / 压缩包等文件', 'gated' => 'allow_upload_attachment'],
                ['key' => 'topic', 'label' => '话题', 'desc' => '给动态打 #话题# 标签，可选已有话题或新建', 'gated' => null],
                ['key' => 'draft', 'label' => '草稿', 'desc' => '把没写完的动态存到本地草稿箱', 'gated' => null],
                ['key' => 'schedule', 'label' => '定时', 'desc' => '定时发布，需站点授权开通', 'gated' => 'allow_scheduled_post'],
                ['key' => 'category', 'label' => '分类', 'desc' => '发布时选择动态分类（需后台先建分类）', 'gated' => null],
                ['key' => 'ai', 'label' => 'AI 助手', 'desc' => '发布时用 AI 润色 / 扩写 / 续写 / 精简 / 换风格', 'gated' => null],
            ],
            'types' => [
                ['key' => 'normal', 'label' => '普通', 'desc' => '纯文字 / 图片 / 视频', 'locked' => true],
                ['key' => 'poll', 'label' => '投票', 'desc' => '发起多选 / 单选投票，附结果统计', 'locked' => false],
                ['key' => 'question', 'label' => '问答', 'desc' => '提问，等别人回答', 'locked' => false],
                ['key' => 'team', 'label' => '组队', 'desc' => '招募队友，展示人数与截止日期', 'locked' => false],
                ['key' => 'checkin', 'label' => '打卡', 'desc' => '发起每日打卡目标，统计连续天数', 'locked' => false],
            ],
        ];
    }

    /**
     * 云存储连通性测试（后台一键"测试"按钮调用）
     *
     * 注意：
     *   - 如果传 force_driver，会用当前 DB 中已保存的同名配置项（而不是 config.php 常量）
     *     但因为 settings 表 override 优先级最高，实际就是测后台当前"还没保存"的配置不对——
     *     所以前端务必先"保存"再点"测试"，测试逻辑会去 settings 表拿刚保存的最新值。
     *   - 也可以直接不传 driver，按当前生效配置测试。
     */
    public function storageTestConnection(Request $request): Response
    {
        $this->requireAdmin($request);
        $forceDriver = trim((string)$request->get('driver', ''));
        try {
            $result = Upload::testConnection($forceDriver);
        } catch (\Throwable $e) {
            $result = [
                'ok'        => false,
                'code'      => 'SERVER_ERROR',
                'reason'    => $e->getMessage(),
                'diagnosis' => $e->getTraceAsString(),
                'driver'    => $forceDriver ?: (string)\Hublog\Core\UploadProvider::currentDriver()
            ];
        }
        // 隐藏敏感诊断（上线时避免直接把堆栈全抛给浏览器）
        if (!empty($result['diagnosis']) && (!defined('HUBLOG_DEBUG') || !HUBLOG_DEBUG)) {
            $result['diagnosis'] = null;
        }
        return Response::success($result);
    }

    public function siteConfig(Request $request): Response
    {
        // 与 PostController 共享原始配置缓存，确保权限开关类型一致且能被统一失效
        $settings = Cache::remember('site_settings', function () {
            $rows = SettingsRepository::fetchKeyValueRows();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 300);

        $categoryRows = SettingsRepository::fetchActiveCategories();
        $categoryMap = [];
        foreach ($categoryRows as $row) {
            $row['parent_id'] = $row['parent_id'] ? (int)$row['parent_id'] : null;
            $row['sort_order'] = (int)$row['sort_order'];
            $row['children'] = [];
            $categoryMap[(int)$row['id']] = $row;
        }
        $categories = [];
        foreach ($categoryMap as $id => $row) {
            if ($row['parent_id'] && isset($categoryMap[$row['parent_id']])) {
                $categoryMap[$row['parent_id']]['children'][] = &$categoryMap[$id];
            } else {
                $categories[] = &$categoryMap[$id];
            }
        }

        $theme = Theme::current();

        return Response::success([
            'app_center_url' => $settings['app_center_url'] ?? (defined('APP_CENTER_URL') ? APP_CENTER_URL : ''),
            'site_name' => $settings['site_name'] ?? '我圈',
            'site_desc' => $settings['site_desc'] ?? '轻量级多用户社区博客系统',
            'site_icp' => $settings['site_icp'] ?? '',
            'site_keywords' => $settings['site_keywords'] ?? '我圈,博客,社区,朋友圈',
            'site_description' => $settings['site_description'] ?? '轻量级多用户社区博客系统，分享生活点滴。',
            'site_stats_code' => $settings['site_stats_code'] ?? '',
            'homepage_cover' => $settings['homepage_cover'] ?? '',
            'allow_register' => ($settings['allow_register'] ?? '1') === '1',
            'allow_post' => ($settings['allow_post'] ?? '1') === '1',
            'allow_like' => ($settings['allow_like'] ?? '1') === '1',
            'allow_comment' => ($settings['allow_comment'] ?? '1') === '1',
            'allow_guest_download_attachment' => ($settings['allow_guest_download_attachment'] ?? '1') === '1',
            'allow_upload_image' => ($settings['allow_upload_image'] ?? '1') === '1',
            'allow_upload_video' => ($settings['allow_upload_video'] ?? '1') === '1',
            'allow_upload_attachment' => ($settings['allow_upload_attachment'] ?? '1') === '1',
            'upload_max_images' => (int)($settings['upload_max_images'] ?? '9'),
            'upload_max_videos' => (int)($settings['upload_max_videos'] ?? '3'),
            'upload_max_size' => (int)($settings['upload_max_size'] ?? '10'),
            'upload_max_video_size' => (int)($settings['upload_max_video_size'] ?? '50'),
            'upload_compress' => ($settings['upload_compress'] ?? '1') === '1',
            'upload_max_width' => (int)($settings['upload_max_width'] ?? '1920'),
            'upload_max_attachments' => (int)($settings['upload_max_attachments'] ?? '5'),
            'upload_max_attachment_size' => (int)($settings['upload_max_attachment_size'] ?? '50'),
            'upload_attachment_exts' => $settings['upload_attachment_exts'] ?? 'zip,rar,7z,pdf,doc,docx,xls,xlsx,ppt,pptx,txt',
            'upload_max_avatar_size' => (int)($settings['upload_max_avatar_size'] ?? '5'),
            'allow_mention' => ($settings['allow_mention'] ?? '1') === '1',
            'allow_location' => ($settings['allow_location'] ?? '1') === '1',
            'allow_anonymous_post' => ($settings['allow_anonymous_post'] ?? '1') === '1',
            'allow_circle' => ($settings['allow_circle'] ?? '1') === '1',
            'mention_max_count' => (int)($settings['mention_max_count'] ?? '10'),
            'post_max_length' => (int)($settings['post_max_length'] ?? '2000'),
            'post_collapse_length' => (int)($settings['post_collapse_length'] ?? '300'),
            'post_title_preview_length' => (int)($settings['post_title_preview_length'] ?? '150'),
            'category_required' => ($settings['category_required'] ?? '0') === '1',
            'title_required' => ($settings['title_required'] ?? '0') === '1',
            'url_rewrite' => ($settings['url_rewrite'] ?? '0') === '1',
            'categories' => $categories,
            'rate_limit_requests' => (int)($settings['rate_limit_requests'] ?? '300'),
            'rate_limit_window' => (int)($settings['rate_limit_window'] ?? '60'),
            'forbidden_nicknames' => $settings['forbidden_nicknames'] ?? '',
            'allowed_email_domains' => $settings['allowed_email_domains'] ?? '',
            'nav_search_enabled' => ($settings['nav_search_enabled'] ?? '1') === '1',
            'nav_messages_enabled' => ($settings['nav_messages_enabled'] ?? '1') === '1',
            'nav_favorites_enabled' => ($settings['nav_favorites_enabled'] ?? '1') === '1',
            'nav_profile_enabled' => ($settings['nav_profile_enabled'] ?? '1') === '1',
            'nav_publish_enabled' => ($settings['nav_publish_enabled'] ?? '1') === '1',
            'nav_items_order' => $settings['nav_items_order'] ?? 'home,search,messages,favorites,profile',
            'site_language' => $settings['site_language'] ?? 'zh-CN',
            'allow_language_switch' => ($settings['allow_language_switch'] ?? '0') === '1',
            'license' => \Hublog\Core\License::getPublicStatus(),
            'theme_options' => Theme::mergeOptions(
                Theme::optionsSchema($theme),
                Theme::readSavedOptions($theme)
            ),
        ] + FeatureFlags::publicPayload());
    }

    /**
     * 上传首页封面图（管理员专用）
     */
    public function uploadHomepageCover(Request $request): Response
    {
        $this->requireAdmin($request);

        if (empty($_FILES['cover']) || $_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
            return Response::error('请选择要上传的封面图片', 400);
        }

        $file = $_FILES['cover'];
        $maxSize = defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : (10 * 1024 * 1024);
        if ($file['size'] > $maxSize) {
            return Response::error('图片大小超过限制', 400);
        }

        $maxWidth = (int)(SettingsRepository::fetchValueByKey('upload_max_width') ?: 1920);
        $compress = (SettingsRepository::fetchValueByKey('upload_compress') ?: '1') === '1';

        // P2-11：image() 返回 [url, file_hash]，取第 0 位，否则数组入库变成字符串 "Array"
        [$url] = Upload::image($file, \UPLOAD_IMAGE_DIR, $maxWidth, 99999, $compress, $maxSize);

        // 必须 upsert：settings 表里若还没有 homepage_cover 行，单纯 UPDATE 会静默影响 0 行，
        // 表现为「图片已落盘、接口返回成功、但前台永远不显示」。首次设置封面时必现。
        SettingsRepository::upsert('homepage_cover', $url, Helper::now());

        Cache::delete('site_settings');

        return Response::success(['url' => $url], '封面上传成功');
    }

    /**
     * V43：上传水印图片素材
     *
     * 关键点：水印素材本身绝不能再被叠加水印，否则会出现「水印套水印」的俄罗斯套娃，
     * 因此上传期间调用 Watermark::suspend(true) 临时挂起。
     */
    public function uploadWatermark(Request $request): Response
    {
        $this->requireAdmin($request);

        if (empty($_FILES['watermark']) || $_FILES['watermark']['error'] !== UPLOAD_ERR_OK) {
            return Response::error('请选择要上传的水印图片', 400);
        }

        $file = $_FILES['watermark'];
        $maxSize = defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : (10 * 1024 * 1024);
        if ($file['size'] > $maxSize) {
            return Response::error('水印图片大小超过限制', 400);
        }

        // 仅接受带透明通道的 PNG / WEBP（JPG 有不透明白底，做水印会挡住原图）
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string)finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
        if (!in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
            return Response::error('水印图片请使用带透明背景的 PNG / WEBP', 400);
        }

        try {
            Watermark::suspend(true);
            // compress=false 只表示不缩放；Upload::image() 自 S10 起仍会 GD 重编码
            // 剥离图片马，并保留 PNG/WEBP 透明通道（水印挂起避免自我叠加）。
            [$url] = Upload::image($file, \UPLOAD_IMAGE_DIR, 1920, 1920, false, $maxSize);
        } finally {
            Watermark::suspend(false);
        }

        // 直接写库（不走 settings 白名单接口，避免前端还要多点一次保存）
        SettingsRepository::upsert('watermark_image', $url, Helper::now());
        Cache::delete('site_settings');
        Watermark::clearConfig();

        return Response::success(['url' => $url], '水印图片上传成功');
    }

    /**
     * V43：水印效果预览
     *
     * 使用前端当前表单值（不落库）在一张示例底图上渲染，返回 base64 PNG，
     * 方便管理员调位置 / 透明度 / 缩放比例时实时对照。
     */
    public function watermarkPreview(Request $request): Response
    {
        $this->requireAdmin($request);

        if (!function_exists('imagecreatetruecolor')) {
            return Response::error('服务器未安装 GD 扩展，无法预览', 500);
        }

        $cfg = Watermark::config();
        $data = $request->all();
        foreach (array_keys(Watermark::defaults()) as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $cfg[$key] = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
            }
        }
        $cfg['watermark_enabled'] = '1';

        $width = 720;
        $height = 460;
        $canvas = imagecreatetruecolor($width, $height);

        // 示例底图：上浅下深的灰阶渐变 + 若干色块，便于检验浅色/深色背景下的可读性
        for ($y = 0; $y < $height; $y++) {
            $tone = (int)(225 - 90 * ($y / $height));
            $line = imagecolorallocate($canvas, $tone, $tone, $tone);
            imageline($canvas, 0, $y, $width, $y, $line);
        }
        $block1 = imagecolorallocate($canvas, 90, 130, 200);
        $block2 = imagecolorallocate($canvas, 60, 60, 70);
        $block3 = imagecolorallocate($canvas, 235, 235, 235);
        imagefilledrectangle($canvas, 40, 60, 260, 220, $block1);
        imagefilledrectangle($canvas, 300, 120, 520, 300, $block2);
        imagefilledrectangle($canvas, 540, 60, 680, 180, $block3);

        $applied = Watermark::applyWithConfig($canvas, $cfg);

        ob_start();
        imagepng($canvas);
        $binary = ob_get_clean();
        imagedestroy($canvas);

        return Response::success([
            'image'   => 'data:image/png;base64,' . base64_encode((string)$binary),
            'applied' => $applied,
        ]);
    }

    /**
     * POST /admin/theme-options/upload — 上传主题外观用图片（如主页通栏背景图）。
     * 仅落盘返回 URL，不写任何设置；URL 由前端填进 image 类型字段后随 options 一并保存。
     */
    public function uploadThemeImage(Request $request): Response
    {
        $this->requireAdmin($request);

        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            return Response::error('请选择要上传的图片', 400);
        }

        $file = $_FILES['image'];
        $maxSize = defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : (10 * 1024 * 1024);
        if ($file['size'] > $maxSize) {
            return Response::error('图片大小超过限制', 400);
        }

        $maxWidth = (int)(SettingsRepository::fetchValueByKey('upload_max_width') ?: 1920);
        $compress = (SettingsRepository::fetchValueByKey('upload_compress') ?: '1') === '1';

        [$url] = Upload::image($file, \UPLOAD_IMAGE_DIR, $maxWidth, 99999, $compress, $maxSize);

        return Response::success(['url' => $url], '图片上传成功');
    }

    /**
     * V36: 获取语言包
     */
    public function getLang(Request $request): Response
    {
        $lang = $request->get('lang', 'zh-CN');
        $allowed = ['zh-CN', 'en'];
        if (!in_array($lang, $allowed, true)) {
            $lang = 'zh-CN';
        }
        $langFile = dirname(__DIR__) . '/lang/' . $lang . '.php';
        if (!file_exists($langFile)) {
            $lang = 'zh-CN';
            $langFile = dirname(__DIR__) . '/lang/zh-CN.php';
        }
        $strings = require $langFile;
        return Response::success(['lang' => $lang, 'strings' => $strings]);
    }

}
