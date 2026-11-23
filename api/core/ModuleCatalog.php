<?php
declare(strict_types=1);
/**
 * 站点模式 · 模块注册表（唯一真值源）
 *
 * 新增一个可关模块时只改本文件：key / 关联后台页 / 依赖 / 预设矩阵 / 路由前缀
 * 全在一处定义。后端下发 catalog，后台前端只渲染不硬编码；
 * FeatureFlags、FeatureGuardMiddleware、菜单可见性全部消费这里。
 *
 * 设计铁律：
 *  - 缺省全开（旧库升级零感知）；
 *  - 依赖只列「可关模块」，常驻骨架不构成依赖；
 *  - L2/L3 关联开关仅作界面提示，绝不参与 L1 判定。
 */

namespace Hublog\Core;

final class ModuleCatalog
{
    /** 可关模块：key => [中文名, 说明, 后台页面 id, 依赖, 关联开关(仅提示), API 前缀] */
    public const MODULES = [
        'comments' => [
            'label'   => '评论管理',
            'desc'    => '评论审核、删除与用户评论记录',
            'pages'   => ['comments'],
            'depends' => [],
            'l2'      => 'allow_comment',
            'routes'  => ['/api/site/comments', '/api/user/comments', '/api/comments'],
        ],
        'topics' => [
            'label'   => '话题管理',
            'desc'    => '话题标签与话题动态聚合',
            'pages'   => ['topics'],
            'depends' => [],
            'l2'      => '',
            'routes'  => ['/api/topics', '/api/posts/topic'],
        ],
        'featured' => [
            'label'   => '精华管理',
            'desc'    => '全站精华 / 首页精选楼层',
            'pages'   => ['featured'],
            'depends' => [],
            'l2'      => '',
            'routes'  => ['/api/site/featured'],
        ],
        'friend_links' => [
            'label'   => '友情链接',
            'desc'    => '前台友情链接展示',
            'pages'   => ['friend-links'],
            'depends' => [],
            'l2'      => '',
            'routes'  => ['/api/site/friend-links'],
        ],
        'circles' => [
            'label'   => '圈子管理',
            'desc'    => '圈子、成员与圈内动态',
            'pages'   => ['circles'],
            'depends' => [],
            'l2'      => 'allow_circle',
            'routes'  => ['/api/circles', '/api/circle/'],
        ],
        'chat' => [
            'label'   => '私信管理',
            'desc'    => '私信、会话与音视频通话',
            'pages'   => ['chat'],
            'depends' => [],
            'l2'      => 'nav_messages_enabled',
            'routes'  => ['/api/chat', '/api/messages', '/api/call'],
        ],
        'activities' => [
            'label'   => '活动管理',
            'desc'    => '活动发布与报名',
            'pages'   => ['activities'],
            'depends' => [],
            'l2'      => '',
            'routes'  => ['/api/activities'],
        ],
        'badges' => [
            'label'   => '徽章管理',
            'desc'    => '徽章配置与定时扫描任务',
            'pages'   => ['badges'],
            'depends' => [],
            'l2'      => '',
            'routes'  => ['/api/badges', '/api/user/badges'],
        ],
        'realname' => [
            'label'   => '实名认证',
            'desc'    => '实名核验与审核记录',
            'pages'   => ['realname'],
            'depends' => [],
            'l2'      => 'realname_enabled',
            'routes'  => ['/api/realname'],
        ],
        'reports' => [
            'label'   => '举报管理',
            'desc'    => '用户举报与处理',
            'pages'   => ['reports'],
            'depends' => [],
            'l2'      => '',
            'routes'  => ['/api/reports', '/api/report'],
        ],
        'appeals' => [
            'label'   => '申诉管理',
            'desc'    => '处罚申诉与复核（依赖举报管理）',
            'pages'   => ['appeals'],
            'depends' => ['reports'],
            'l2'      => '',
            'routes'  => ['/api/user/appeal', '/api/user/appeals'],
        ],
        'shop' => [
            'label'   => '商城管理',
            'desc'    => '商品与积分大转盘（依赖财务会员）',
            'pages'   => ['shop'],
            'depends' => ['finance'],
            'l2'      => 'mall_switch',
            'routes'  => ['/api/shop', '/api/points/wheel'],
        ],
        'finance' => [
            'label'   => '财务会员',
            'desc'    => '支付、钱包、会员与积分',
            'pages'   => ['finance'],
            'depends' => [],
            'l2'      => 'pay_enabled',
            'routes'  => ['/api/pay/', '/api/points/'],
        ],
        'contentpay' => [
            'label'   => '内容付费',
            'desc'    => '单篇买断与专栏订阅（依赖财务会员）',
            'pages'   => ['content-pay'],
            'depends' => ['finance'],
            'l2'      => '',
            'routes'  => ['/api/content-pay/', '/api/admin/content-pay/'],
        ],
        'safety_filter' => [
            'label'   => '敏感词与命中记录',
            'desc'    => '后台两个管理页；关闭不停掉本地过滤链',
            'pages'   => ['sensitive-words', 'filter-logs'],
            'depends' => [],
            'l2'      => 'ai_audit_enabled',
            'routes'  => [],
        ],
    ];

    /** 常驻骨架 13 页：任何模式下都保留 */
    public const ALWAYS_ON_PAGES = [
        'dashboard', 'users', 'posts', 'categories', 'attachments', 'settings', 'stats',
        'export', 'plugins', 'license', 'upgrade', 'app-versions', 'crash-logs',
    ];

    /** 三套预设：列出的 key 为开，其余为关 */
    public const PRESETS = [
        'social' => [
            'comments', 'topics', 'featured', 'friend_links', 'circles', 'chat',
            'activities', 'badges', 'realname', 'reports', 'appeals', 'shop',
            'finance', 'safety_filter',
        ],
        'blog' => [
            'comments', 'friend_links', 'reports', 'appeals', 'safety_filter',
        ],
        'news' => [
            'comments', 'friend_links', 'reports', 'appeals', 'safety_filter',
            'topics', 'featured',
        ],
        // V97 内容商业化：内容站 + 财务会员 + 内容付费（contentpay depends finance，必须同开）
        'content' => [
            'comments', 'friend_links', 'reports', 'appeals', 'safety_filter',
            'topics', 'featured', 'finance', 'contentpay',
        ],
    ];

    public const MODE_INFO = [
        'social' => ['label' => '社交模式', 'desc' => '全部模块开启，等效当前全功能社区'],
        'blog'   => ['label' => '博客模式', 'desc' => '纯内容站：只有文章编辑器，去掉社交发布与商业化'],
        'news'   => ['label' => '资讯模式', 'desc' => '内容 + 话题 / 精华运营，无社交与商业化'],
        'content' => ['label' => '内容商业化', 'desc' => '内容站 + 单篇买断 / 专栏订阅变现，开启财务会员与内容付费'],
        'custom' => ['label' => '自定义', 'desc' => '在预设基础上单独微调'],
    ];

    /**
     * 各模式对应的「前台功能档案」：选模式时一并套用到「功能开关」面板。
     * 与 MODULES 预设互补——MODULES 管后台模块可见性，这里管前台形态
     * （帖子类型 / 发布页工具栏 / 底部导航入口）。两者都由同一套模式一键切换驱动。
     *
     * nav 取值：publish(发布按钮) / search(搜索) / messages(消息) / favorites(收藏) / profile(我的)。
     * blog 模式刻意把社交发布页工具栏与底导收掉——写作统一走文章编辑器。
     */
    public const FRONTEND_PRESETS = [
        'social' => [
            'post_types' => ['normal', 'poll', 'question', 'team', 'checkin'],
            'post_tools' => ['image', 'video', 'emoji', 'location', 'mention', 'anonymous', 'link', 'attachment', 'topic', 'draft', 'schedule', 'category'],
            'nav'        => ['publish' => 1, 'search' => 1, 'messages' => 1, 'favorites' => 1, 'profile' => 1],
        ],
        'blog' => [
            'post_types' => ['normal'],
            'post_tools' => ['image', 'video'],   // 文章编辑器自带完整富文本；社交工具栏仅留最基础图文
            'nav'        => ['publish' => 0, 'search' => 1, 'messages' => 0, 'favorites' => 0, 'profile' => 1],
        ],
        'news' => [
            'post_types' => ['normal', 'poll', 'question'],
            'post_tools' => ['image', 'video', 'emoji', 'link', 'topic', 'draft'],
            'nav'        => ['publish' => 1, 'search' => 1, 'messages' => 0, 'favorites' => 1, 'profile' => 1],
        ],
        'content' => [
            'post_types' => ['normal'],
            'post_tools' => ['image', 'video', 'link', 'topic', 'draft'],
            'nav'        => ['publish' => 1, 'search' => 1, 'messages' => 0, 'favorites' => 1, 'profile' => 1],
        ],
    ];

    public const MODES = ['social', 'blog', 'news', 'content', 'custom'];
    public const GUARD_MODES = ['off', 'log', 'on'];

    /** 全开（缺省值） */
    public static function defaults(): array
    {
        return array_fill_keys(array_keys(self::MODULES), 1);
    }

    /** 全关 */
    private static function allOff(): array
    {
        return array_fill_keys(array_keys(self::MODULES), 0);
    }

    /**
     * 归一化：未知 key 丢弃、缺失 key 补 1、值只留 0/1（脏值 fail-open 视为开）。
     * 接受数组或 JSON 字符串。
     */
    public static function normalize(mixed $raw): array
    {
        $arr = [];
        if (is_array($raw)) {
            $arr = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $arr = $decoded;
        }
        $out = [];
        foreach (array_keys(self::MODULES) as $key) {
            $out[$key] = array_key_exists($key, $arr) ? self::toInt($arr[$key]) : 1;
        }
        return $out;
    }

    private static function toInt(mixed $v): int
    {
        if ($v === false || $v === null || $v === '' || $v === 0 || $v === '0') return 0;
        return 1;
    }

    /**
     * 保存入口专用：严格解析，坏 JSON 直接拒绝（不能悄悄回退成全开）。
     * @return array{flags: array<string,int>, error: ?string}
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['flags' => self::defaults(), 'error' => null];
        }
        if (is_array($raw)) {
            return ['flags' => self::normalize($raw), 'error' => null];
        }
        if (!is_string($raw)) {
            return ['flags' => self::defaults(), 'error' => 'module_flags 格式错误'];
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return ['flags' => self::defaults(), 'error' => 'module_flags 不是合法的 JSON 对象'];
        }
        return ['flags' => self::normalize($decoded), 'error' => null];
    }

    /** 依赖二次校验：返回错误信息或 null */
    public static function validate(array $flags): ?string
    {
        $f = self::normalize($flags);
        foreach (self::MODULES as $key => $def) {
            if (!$f[$key]) continue;
            foreach ($def['depends'] as $dep) {
                if (!$f[$dep]) {
                    return '「' . $def['label'] . '」依赖「' . (self::MODULES[$dep]['label'] ?? $dep)
                        . '」，请同时开启后者或关闭前者';
                }
            }
        }
        return null;
    }

    /** 取某套预设的完整 flags */
    public static function presetFlags(string $mode): array
    {
        $flags = self::allOff();
        foreach (self::PRESETS[$mode] ?? [] as $key) {
            if (isset($flags[$key])) $flags[$key] = 1;
        }
        return $flags;
    }

    /** 由最终 flags 反查模式名（后端说了算，不信前端上报） */
    public static function detectMode(array $flags): string
    {
        $f = self::normalize($flags);
        foreach (array_keys(self::PRESETS) as $mode) {
            if (self::presetFlags($mode) === $f) return $mode;
        }
        return 'custom';
    }

    /** 取某模式的前台功能档案（帖子类型 / 发布页工具栏 / 底部导航），无则返回 null */
    public static function frontendProfile(string $mode): ?array
    {
        return self::FRONTEND_PRESETS[$mode] ?? null;
    }

    /** 后台菜单用：页面 id => 是否可见 */
    public static function pageMap(array $flags): array
    {
        $f = self::normalize($flags);
        $out = [];
        foreach (self::ALWAYS_ON_PAGES as $page) $out[$page] = true;
        foreach (self::MODULES as $key => $def) {
            foreach ($def['pages'] as $page) $out[$page] = (bool)$f[$key];
        }
        return $out;
    }

    /** 运行层拦截用：前缀 => 模块 key，按长度降序（长前缀优先） */
    public static function routePrefixes(): array
    {
        $map = [];
        foreach (self::MODULES as $key => $def) {
            foreach ($def['routes'] as $prefix) $map[$prefix] = $key;
        }
        uksort($map, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return $map;
    }

    /**
     * 把请求 URI 归入某个模块 key（access 日志 / 指标的可观测性归因用）。
     *
     * 复用 routePrefixes() 这一唯一真值源，与 FeatureGuardMiddleware 用同一套
     * 「长前缀优先」匹配：被模块门禁认作某模块的请求，在日志/指标里也归到同一模块。
     * 未命中任何可关模块前缀 ⇒ 'core'（常驻骨架，不属于任何可关模块）。
     *
     * 兼容点：
     *  - REQUEST_URI 可能带 query / fragment，先截断，避免 ?token= 干扰路径匹配；
     *  - PATH_INFO 部署下 URI 形如 /api/index.php/site/friend-links，必须先剥掉入口
     *    脚本段，否则前缀永远匹配不上（module 恒为 core）。口径与 Router::resolvePath()
     *    一致：大小写不敏感地剥掉第一个 '/index.php'，剥完为空或不以 '/api' 开头时补 '/api'；
     *  - 子目录部署（如 /hublog/api/site/comments）从第一个 '/api/' 起算；补 '/api' 只在
     *    找不到 '/api/' 时做，否则子目录路径会被错误改写成 /api/hublog/api/...；
     *  - 前缀本身可能以 '/' 结尾（如 /api/circle/），直接按前缀匹配即可。
     */
    public static function moduleForUri(string $uri): string
    {
        $path = $uri;
        $cut = strcspn($path, '?#');
        if ($cut < strlen($path)) {
            $path = substr($path, 0, $cut);
        }

        // PATH_INFO 归一化：与 Router::resolvePath() 同一口径（见 Router.php:167-172）。
        if (stripos($path, '/index.php') !== false) {
            $path = preg_replace('#/index\.php#i', '', $path, 1) ?? $path;
        }

        // 先按 '/api/' 起算，兼容子目录部署（/hublog/api/index.php/topics → /api/topics）。
        $apiPos = strpos($path, '/api/');
        if ($apiPos !== false) {
            $path = substr($path, $apiPos);
        } elseif ($path === '' || !str_starts_with($path, '/api')) {
            // 无 index.php 的裸 PATH_INFO（如 /site/friend-links）按 Router 口径补 /api。
            $path = '/api' . $path;
        }

        foreach (self::routePrefixes() as $prefix => $key) {
            if (str_starts_with($path, (string)$prefix)) {
                return (string)$key;
            }
        }
        return 'core';
    }

    /** 下发给后台设置页的元数据（前端零硬编码） */
    public static function catalog(): array
    {
        $modules = [];
        foreach (self::MODULES as $key => $def) {
            $modules[] = [
                'key'     => $key,
                'label'   => $def['label'],
                'desc'    => $def['desc'],
                'pages'   => $def['pages'],
                'depends' => $def['depends'],
                'l2'      => $def['l2'],
            ];
        }
        $modes = [];
        foreach (self::MODES as $mode) {
            $modes[] = array_merge(['key' => $mode], self::MODE_INFO[$mode]);
        }
        return [
            'modules'      => $modules,
            'presets'      => self::PRESETS,
            'frontend'     => self::FRONTEND_PRESETS,
            'modes'        => $modes,
            'always_on'    => self::ALWAYS_ON_PAGES,
            'guard_modes'  => self::GUARD_MODES,
        ];
    }
}
