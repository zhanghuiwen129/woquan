<?php
return [
    'dashboard' => [
        'title' => '控制台',
        'icon' => 'fa-home',
        'url' => '/admin/index',
        'active' => 'index'
    ],
    
    'user' => [
        'title' => '用户管理',
        'icon' => 'fa-users',
        'active' => 'user',
        'children' => [
            [
                'title' => '用户管理',
                'url' => '/admin/user',
                'active' => 'user'
            ],
            [
                'title' => '用户标签',
                'url' => '/admin/user/tags',
                'active' => 'user_tags'
            ],
            [
                'title' => '用户分组',
                'url' => '/admin/user/groups',
                'active' => 'user_groups'
            ],
            [
                'title' => '等级管理',
                'url' => '/admin/level',
                'active' => 'level'
            ],
            [
                'title' => '在线状态',
                'url' => '/admin/online',
                'active' => 'online'
            ]
        ]
    ],

    'license' => [
        'title' => '授权管理',
        'icon' => 'fa-key',
        'active' => 'license',
        'children' => [
            [
                'title' => '授权列表',
                'url' => '/admin/authorization',
                'active' => 'authorization'
            ],
            [
                'title' => '软件管理',
                'url' => '/admin/software',
                'active' => 'software'
            ],
            [
                'title' => '批量生成',
                'url' => '/admin/authorization/batch',
                'active' => 'license_batch'
            ],
            [
                'title' => '授权统计',
                'url' => '/admin/authorization/statistics',
                'active' => 'license_statistics'
            ],
            [
                'title' => '权限管理',
                'url' => '/admin/authorization/permissions',
                'active' => 'license_permissions'
            ],
            [
                'title' => '角色分配',
                'url' => '/admin/authorization/roles',
                'active' => 'license_roles'
            ]
        ]
    ],

    'content' => [
        'title' => '内容管理',
        'icon' => 'fa-file-alt',
        'active' => 'content',
        'children' => [
            [
                'title' => '文章管理',
                'url' => '/admin/article',
                'active' => 'article'
            ],
            [
                'title' => '分类管理',
                'url' => '/admin/category',
                'active' => 'category'
            ],
            [
                'title' => '动态管理',
                'url' => '/admin/content/moments',
                'active' => 'moments'
            ],
            [
                'title' => '评论管理',
                'url' => '/admin/comment',
                'active' => 'comment'
            ],
            [
                'title' => '举报管理',
                'url' => '/admin/content/reports',
                'active' => 'reports'
            ],
            [
                'title' => '话题管理',
                'url' => '/admin/topic',
                'active' => 'topic'
            ],
            [
                'title' => '草稿管理',
                'url' => '/admin/draft',
                'active' => 'draft'
            ]
        ]
    ],

    'social' => [
        'title' => '社交管理',
        'icon' => 'fa-comments',
        'active' => 'social',
        'children' => [
            [
                'title' => '好友管理',
                'url' => '/admin/friend',
                'active' => 'friend'
            ],
            [
                'title' => '关注管理',
                'url' => '/admin/follow',
                'active' => 'follow'
            ],
            [
                'title' => '黑名单',
                'url' => '/admin/blacklist',
                'active' => 'blacklist'
            ],
            [
                'title' => '会话管理',
                'url' => '/admin/chat',
                'active' => 'chat'
            ],
            [
                'title' => '消息记录',
                'url' => '/admin/chat/messages',
                'active' => 'chat_messages'
            ],
            [
                'title' => '通话记录',
                'url' => '/admin/call',
                'active' => 'call'
            ],
            [
                'title' => '快捷回复',
                'url' => '/admin/quick-reply',
                'active' => 'quick_reply'
            ],
            [
                'title' => '位置管理',
                'url' => '/admin/location',
                'active' => 'location'
            ],
            [
                'title' => '名片管理',
                'url' => '/admin/card',
                'active' => 'card'
            ]
        ]
    ],

    'assets' => [
        'title' => '资产管理',
        'icon' => 'fa-wallet',
        'active' => 'assets',
        'children' => [
            [
                'title' => 'VIP管理',
                'url' => '/admin/vip',
                'active' => 'vip'
            ],
            [
                'title' => '货币管理',
                'url' => '/admin/currency',
                'active' => 'currency'
            ],
            [
                'title' => '充值记录',
                'url' => '/admin/assets/recharge',
                'active' => 'recharge'
            ],
            [
                'title' => '提现记录',
                'url' => '/admin/assets/withdraw',
                'active' => 'withdraw'
            ]
        ]
    ],

    'task' => [
        'title' => '任务活动',
        'icon' => 'fa-tasks',
        'active' => 'task',
        'children' => [
            [
                'title' => '任务管理',
                'url' => '/admin/task',
                'active' => 'task'
            ],
            [
                'title' => '任务统计',
                'url' => '/admin/task/statistics',
                'active' => 'task_statistics'
            ],
            [
                'title' => '活动管理',
                'url' => '/admin/activity',
                'active' => 'activity'
            ],
            [
                'title' => '参与者管理',
                'url' => '/admin/activity/participants',
                'active' => 'activity_participants'
            ]
        ]
    ],

    'setting' => [
        'title' => '系统设置',
        'icon' => 'fa-cog',
        'active' => 'setting',
        'children' => [
            [
                'title' => '基本设置',
                'url' => '/admin/setting/basic',
                'active' => 'setting_basic'
            ],
            [
                'title' => '网站设置',
                'url' => '/admin/setting/website',
                'active' => 'setting_website'
            ],
            [
                'title' => '注册设置',
                'url' => '/admin/setting/register',
                'active' => 'setting_register'
            ],
            [
                'title' => '邮件设置',
                'url' => '/admin/setting/email',
                'active' => 'setting_email'
            ],
            [
                'title' => '上传设置',
                'url' => '/admin/setting/upload',
                'active' => 'setting_upload'
            ],
            [
                'title' => '安全设置',
                'url' => '/admin/setting/security',
                'active' => 'setting_security'
            ],
            [
                'title' => '社交设置',
                'url' => '/admin/setting/social',
                'active' => 'setting_social'
            ],
            [
                'title' => '运营设置',
                'url' => '/admin/setting/operation',
                'active' => 'setting_operation'
            ]
        ]
    ],

    'monitor' => [
        'title' => '系统监控',
        'icon' => 'fa-chart-line',
        'active' => 'monitor',
        'children' => [
            [
                'title' => '性能监控',
                'url' => '/admin/monitor/performance',
                'active' => 'monitor_performance'
            ],
            [
                'title' => '数据库监控',
                'url' => '/admin/monitor/database',
                'active' => 'monitor_database'
            ],
            [
                'title' => '存储管理',
                'url' => '/admin/storage',
                'active' => 'storage'
            ]
        ]
    ],

    'analytics' => [
        'title' => '数据分析',
        'icon' => 'fa-chart-bar',
        'active' => 'analytics',
        'children' => [
            [
                'title' => '数据概览',
                'url' => '/admin/analytics',
                'active' => 'analytics'
            ]
        ]
    ],

    'log' => [
        'title' => '日志管理',
        'icon' => 'fa-list-alt',
        'active' => 'log',
        'children' => [
            [
                'title' => '登录日志',
                'url' => '/admin/user/login-logs',
                'active' => 'user_login_logs'
            ],
            [
                'title' => '访问日志',
                'url' => '/admin/log/access',
                'active' => 'log_access'
            ],
            [
                'title' => '操作日志',
                'url' => '/admin/log/operation',
                'active' => 'log_operation'
            ],
            [
                'title' => '错误日志',
                'url' => '/admin/log/error',
                'active' => 'log_error'
            ],
            [
                'title' => '安全日志',
                'url' => '/admin/log/security',
                'active' => 'log_security'
            ],
            [
                'title' => '慢查询日志',
                'url' => '/admin/log/slow-query',
                'active' => 'log_slow_query'
            ]
        ]
    ],

    'api' => [
        'title' => 'API管理',
        'icon' => 'fa-code',
        'active' => 'api',
        'children' => [
            [
                'title' => 'API密钥',
                'url' => '/admin/api/keys',
                'active' => 'api_keys'
            ],
            [
                'title' => 'API调用',
                'url' => '/admin/api/calls',
                'active' => 'api_calls'
            ],
            [
                'title' => '限流设置',
                'url' => '/admin/api/rate-limit',
                'active' => 'api_rate_limit'
            ]
        ]
    ],

    'tools' => [
        'title' => '系统工具',
        'icon' => 'fa-tools',
        'active' => 'tools',
        'children' => [
            [
                'title' => '消息管理',
                'url' => '/admin/announcement',
                'active' => 'announcement'
            ],
            [
                'title' => '定时任务',
                'url' => '/admin/cron',
                'active' => 'cron'
            ],
            [
                'title' => '版本管理',
                'url' => '/admin/version',
                'active' => 'version'
            ],
            [
                'title' => 'FAQ管理',
                'url' => '/admin/faq',
                'active' => 'faq'
            ],
            [
                'title' => '表情管理',
                'url' => '/admin/emoji',
                'active' => 'emoji'
            ],
            [
                'title' => '搜索管理',
                'url' => '/admin/search',
                'active' => 'search'
            ]
        ]
    ],

    'integration' => [
        'title' => '第三方集成',
        'icon' => 'fa-plug',
        'active' => 'integration',
        'children' => [
            [
                'title' => '登录集成',
                'url' => '/admin/integration/login',
                'active' => 'integration_login'
            ],
            [
                'title' => '支付集成',
                'url' => '/admin/integration/payment',
                'active' => 'integration_payment'
            ],
            [
                'title' => '存储集成',
                'url' => '/admin/integration/storage',
                'active' => 'integration_storage'
            ],
            [
                'title' => '地图集成',
                'url' => '/admin/integration/map',
                'active' => 'integration_map'
            ]
        ]
    ]
];
