<?php
return [
    // 时间格式
    'time_just_now' => '刚刚',
    'time_minutes_ago' => '分钟前',
    'time_hours_ago' => '小时前',
    'time_days_ago' => '天前',
    'time_weeks_ago' => '周前',
    'time_date_format' => 'm月d日',
    'time_year_format' => 'Y年m月d日',

    // 通知
    'notification_like' => '点赞了你的动态',
    'notification_comment' => '评论了你的动态',
    'notification_reply' => '回复了你的评论',
    'notification_follow' => '关注了你',
    'notification_ai' => 'AI评论家评论了你的动态',
    'notification_system' => '系统通知',
    'notification_mention' => '提到了你',
    'notification_message' => '给你发了一条私信',

    // 邮件系统
    'email_verify_subject' => '验证您的邮箱 - {site_name}',
    'email_verify_body' => '您的验证码是：{code}，10分钟内有效。',
    'email_reset_subject' => '重置密码 - {site_name}',
    'email_reset_body' => '请点击以下链接重置密码：{link}，链接30分钟内有效。',

    // 密码策略
    'password_too_short' => '密码至少需要 {min} 个字符',
    'password_need_upper' => '密码需要包含大写字母',
    'password_need_lower' => '密码需要包含小写字母',
    'password_need_digit' => '密码需要包含数字',
    'password_need_special' => '密码需要包含特殊字符',
    'password_weak' => '密码强度不足',
    'password_medium' => '密码强度中等',
    'password_strong' => '密码强度强',

    // 认证与OAuth
    'login_github' => 'GitHub 登录',
    'login_google' => 'Google 登录',
    'login_wechat' => '微信登录',
    'oauth_failed' => '第三方登录失败',
    'oauth_linked' => '已绑定第三方账号',
    'email_not_verified' => '邮箱未验证',
    'email_verified_success' => '邮箱验证成功',
    'verify_code_sent' => '验证码已发送',
    'reset_link_sent' => '重置链接已发送',
    'reset_password_success' => '密码重置成功',
    'reset_password_failed' => '密码重置失败',

    // 定时发布
    'scheduled_publish' => '定时发布',
    'scheduled_at' => '发布时间',
    'scheduled_published' => '已发布的定时动态',

    // 搜索过滤
    'search_filter_category' => '分类',
    'search_filter_date' => '时间范围',
    'search_filter_media' => '媒体类型',
    'search_filter_all' => '全部',
    'search_filter_7d' => '近7天',
    'search_filter_30d' => '近30天',
    'search_filter_image' => '图片',
    'search_filter_video' => '视频',
    'search_filter_text' => '纯文本',

    // 话题
    'topic' => '话题',
    'topic_create' => '创建话题',
    'topic_edit' => '编辑话题',
    'topic_delete' => '删除话题',
    'topic_name' => '话题名称',
    'topic_description' => '话题描述',
    'topic_posts_count' => '动态数',

    // 数据导出
    'export_title' => '数据导出',
    'export_type' => '导出类型',
    'export_format' => '导出格式',
    'export_posts' => '动态',
    'export_comments' => '评论',
    'export_users' => '用户',
    'export_messages' => '私信',
    'export_download' => '下载',
    'export_success' => '导出成功',

    // 批量操作
    'batch_select_all' => '全选',
    'batch_delete' => '批量删除',
    'batch_confirm' => '确认执行批量操作？',
    'batch_success' => '批量操作完成',

    // 统计
    'stats_title' => '数据统计',
    'stats_total_posts' => '总动态数',
    'stats_total_users' => '总用户数',
    'stats_total_comments' => '总评论数',
    'stats_total_likes' => '总点赞数',
    'stats_today_posts' => '今日动态',
    'stats_today_users' => '今日新用户',
    'stats_trend' => '趋势',
    'stats_summary' => '概览',

    // 插件管理
    'plugin_title' => '插件管理',
    'plugin_enabled' => '已启用',
    'plugin_disabled' => '已禁用',
    'plugin_enable' => '启用',
    'plugin_disable' => '禁用',

    // 主题
    'theme_custom_color' => '自定义主题色',
    'theme_reset_color' => '重置颜色',

    // 富文本
    'rich_text_bold' => '加粗',
    'rich_text_italic' => '斜体',
    'rich_text_link' => '链接',
    'rich_text_quote' => '引用',
    'rich_text_toggle' => '富文本模式',

    // 已读回执
    'message_read' => '已读',
    'message_unread' => '未读',

    // 浏览量
    'view_count' => '浏览',

    // SEO
    'seo_sitemap' => '站点地图',

    // 备份
    'backup_title' => '自动备份',
    'backup_success' => '备份成功',
    'backup_failed' => '备份失败',

    // 通用
    'action_success' => '操作成功',
    'action_failed' => '操作失败',
    'loading' => '加载中...',
    'no_data' => '暂无数据',
    'confirm_delete' => '确认删除？',
];
