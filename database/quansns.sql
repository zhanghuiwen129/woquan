-- phpMyAdmin SQL Dump
-- version 4.4.15.10
-- https://www.phpmyadmin.net
--
-- Host: localhost:3306
-- Generation Time: 2026-03-19 21:34:55
-- 服务器版本： 8.0.29
-- PHP Version: 7.3.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `quansns`
--

-- --------------------------------------------------------

--
-- 表的结构 `sns_activities`
--

CREATE TABLE IF NOT EXISTS `sns_activities` (
  `id` int unsigned NOT NULL,
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '活动标题',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '活动内容',
  `cover_image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '活动封面图片',
  `type` tinyint NOT NULL DEFAULT '1' COMMENT '活动类型:1-线上活动,2-线下活动',
  `start_time` int NOT NULL COMMENT '活动开始时间',
  `end_time` int NOT NULL COMMENT '活动结束时间',
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '活动地点(线下活动必填)',
  `organizer_id` int NOT NULL COMMENT '活动组织者ID',
  `organizer_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '活动组织者名称',
  `participant_count` int NOT NULL DEFAULT '0' COMMENT '参与人数',
  `max_participants` int DEFAULT '0' COMMENT '最大参与人数(0表示无限)',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '活动状态0-未发布1-进行中2-已结束3-已取消',
  `is_hot` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否热门活动:0-否1-是',
  `sort` tinyint NOT NULL DEFAULT '0' COMMENT '排序',
  `create_time` int NOT NULL COMMENT '创建时间',
  `update_time` int NOT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_activity_participants`
--

CREATE TABLE IF NOT EXISTS `sns_activity_participants` (
  `id` int unsigned NOT NULL,
  `activity_id` int unsigned NOT NULL COMMENT '活动ID',
  `user_id` int NOT NULL COMMENT '参与用户ID',
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '参与用户昵称',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '参与用户头像',
  `participant_time` int NOT NULL COMMENT '参与时间',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '参与状态1-已参与2-已取消'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动参与表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_admin`
--

CREATE TABLE IF NOT EXISTS `sns_admin` (
  `id` int NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '管理员用户名',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '管理员密码(bcrypt加密)',
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '管理员昵称',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '管理员邮箱',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '管理员头像',
  `role` tinyint(1) NOT NULL DEFAULT '1' COMMENT '管理员角色1-超级管理员2-普通管理员',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-正常,0-禁用',
  `last_login_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `last_login_time` int NOT NULL DEFAULT '0' COMMENT '最后登录时间',
  `login_count` int NOT NULL DEFAULT '0' COMMENT '登录次数',
  `create_time` int NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int NOT NULL DEFAULT '0' COMMENT '更新时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间'
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统管理员表';

--
-- 转存表中的数据 `sns_admin`
--

INSERT INTO `sns_admin` (`id`, `username`, `password`, `nickname`, `email`, `avatar`, `role`, `status`, `last_login_ip`, `last_login_time`, `login_count`, `create_time`, `update_time`, `deleted_at`) VALUES
(1, 'admin', '$2y$10$SjbghGwHNlR/jNF3cKVBUuiBNi39BM39QCqiCt4HS0QeRF9GhzTNm', '微圈', '1@1.com', '', 1, 1, '', 0, 0, 1773409332, 0, NULL);

-- --------------------------------------------------------

--
-- 表的结构 `sns_admin_log`
--

CREATE TABLE IF NOT EXISTS `sns_admin_log` (
  `id` int NOT NULL,
  `admin_id` int NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `create_time` int NOT NULL
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员操作日志表';

--
-- 转存表中的数据 `sns_admin_log`
--

INSERT INTO `sns_admin_log` (`id`, `admin_id`, `username`, `action`, `ip`, `create_time`) VALUES
(1, 1, 'admin', '登录后台', '127.0.0.1', 1773409389);

-- --------------------------------------------------------

--
-- 表的结构 `sns_announcements`
--

CREATE TABLE IF NOT EXISTS `sns_announcements` (
  `id` int unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '公告标题',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '公告内容',
  `status` int DEFAULT '1' COMMENT '状态0-禁用,1-启用',
  `is_publish` int DEFAULT '0' COMMENT '是否发布:0-未发布1-已发布',
  `publish_time` int DEFAULT '0' COMMENT '发布时间',
  `expire_time` int DEFAULT '0' COMMENT '过期时间',
  `is_popup` int DEFAULT '1' COMMENT '是否弹窗:0-否1-是',
  `click_count` int DEFAULT '0' COMMENT '点击次数',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间',
  `admin_id` int DEFAULT '0' COMMENT '发布管理员ID'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统公告表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_api_calls`
--

CREATE TABLE IF NOT EXISTS `sns_api_calls` (
  `id` int NOT NULL,
  `api_key_id` int NOT NULL COMMENT 'API密钥ID',
  `method` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '请求方法',
  `endpoint` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '接口地址',
  `params` text COLLATE utf8mb4_unicode_ci COMMENT '请求参数',
  `response` text COLLATE utf8mb4_unicode_ci COMMENT '响应结果',
  `status_code` int DEFAULT '0' COMMENT '状态码',
  `execute_time` decimal(10,4) DEFAULT '0.0000' COMMENT '执行时间(秒)',
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'IP地址',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API调用记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_api_keys`
--

CREATE TABLE IF NOT EXISTS `sns_api_keys` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '密钥名称',
  `access_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Access Key',
  `secret_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Secret Key',
  `permissions` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '权限列表',
  `ip_whitelist` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'IP白名单',
  `rate_limit` int DEFAULT '1000' COMMENT '限流次数/小时',
  `status` tinyint(1) DEFAULT '1',
  `expire_time` int DEFAULT '0' COMMENT '过期时间 0永不过期',
  `create_time` int DEFAULT '0',
  `update_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API密钥表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_articles`
--

CREATE TABLE IF NOT EXISTS `sns_articles` (
  `id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '文章标题',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文章内容',
  `summary` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '文章摘要',
  `cover_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '封面图片',
  `images` text COLLATE utf8mb4_unicode_ci COMMENT '文章配图(JSON数组)',
  `category_id` int DEFAULT NULL COMMENT '分类ID',
  `tags` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '标签',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态0=草稿1=已发布2=已删除',
  `view_count` int DEFAULT '0' COMMENT '浏览量',
  `like_count` int DEFAULT '0' COMMENT '点赞数',
  `collect_count` int DEFAULT '0' COMMENT '收藏数',
  `comment_count` int DEFAULT '0' COMMENT '评论数',
  `publish_time` int DEFAULT '0' COMMENT '发布时间',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_article_categories`
--

CREATE TABLE IF NOT EXISTS `sns_article_categories` (
  `id` int unsigned NOT NULL,
  `parent_id` int unsigned DEFAULT '0' COMMENT '父分类ID,0表示顶级分类',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分类名称',
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '分类别名',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '分类描述',
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '分类图标',
  `cover_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '分类封面图',
  `sort_order` int DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态:1启用,0禁用',
  `article_count` int DEFAULT '0' COMMENT '文章数量',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章分类表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_article_collections`
--

CREATE TABLE IF NOT EXISTS `sns_article_collections` (
  `id` int unsigned NOT NULL,
  `article_id` int unsigned NOT NULL COMMENT '文章ID',
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `folder_id` int unsigned DEFAULT '0' COMMENT '收藏夹ID,0表示默认收藏夹',
  `create_time` int DEFAULT '0' COMMENT '收藏时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章收藏表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_article_comments`
--

CREATE TABLE IF NOT EXISTS `sns_article_comments` (
  `id` int unsigned NOT NULL,
  `article_id` int unsigned NOT NULL COMMENT '文章ID',
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `parent_id` int unsigned DEFAULT '0' COMMENT '父评论ID,0表示顶级评论',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '评论内容',
  `likes` int DEFAULT '0' COMMENT '点赞数',
  `replies` int DEFAULT '0' COMMENT '回复数',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态:1正常,0删除',
  `create_time` int DEFAULT '0' COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章评论表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_article_likes`
--

CREATE TABLE IF NOT EXISTS `sns_article_likes` (
  `id` int unsigned NOT NULL,
  `article_id` int unsigned NOT NULL COMMENT '文章ID',
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `create_time` int DEFAULT '0' COMMENT '点赞时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章点赞表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_article_logs`
--

CREATE TABLE IF NOT EXISTS `sns_article_logs` (
  `id` int unsigned NOT NULL,
  `article_id` int unsigned NOT NULL COMMENT '文章ID',
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作类型',
  `remark` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '备注',
  `create_time` int DEFAULT '0' COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章操作日志表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_article_views`
--

CREATE TABLE IF NOT EXISTS `sns_article_views` (
  `id` int unsigned NOT NULL,
  `article_id` int unsigned NOT NULL COMMENT '文章ID',
  `user_id` int unsigned DEFAULT '0' COMMENT '用户ID,0表示游客',
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'IP地址',
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '用户代理',
  `create_time` int DEFAULT '0' COMMENT '浏览时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章浏览记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_authorizations`
--

CREATE TABLE IF NOT EXISTS `sns_authorizations` (
  `id` int NOT NULL,
  `license_number` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '授权编号',
  `software_id` int NOT NULL COMMENT '软件ID',
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '授权域名',
  `server_ip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '服务器IP',
  `start_time` int DEFAULT '0' COMMENT '开始时间',
  `end_time` int DEFAULT '0' COMMENT '结束时间0表示永久',
  `signature` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '授权签名',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-有效0-无效',
  `verify_count` int DEFAULT '0' COMMENT '验证次数',
  `last_verify_time` int DEFAULT '0' COMMENT '最后验证时间',
  `features` text COLLATE utf8mb4_unicode_ci COMMENT '功能权限JSON',
  `create_time` int DEFAULT '0',
  `update_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='授权管理表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_blacklist`
--

CREATE TABLE IF NOT EXISTS `sns_blacklist` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `block_id` int NOT NULL COMMENT '被拉黑用户ID',
  `create_time` int DEFAULT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='黑名单表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_blocked_users`
--

CREATE TABLE IF NOT EXISTS `sns_blocked_users` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `blocked_user_id` int NOT NULL COMMENT '被屏蔽的用户ID',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '屏蔽原因',
  `create_time` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='屏蔽用户表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_call_records`
--

CREATE TABLE IF NOT EXISTS `sns_call_records` (
  `id` int unsigned NOT NULL COMMENT '通话记录ID',
  `caller_id` int NOT NULL COMMENT '发起人用户ID',
  `caller_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '发起人昵称',
  `callee_id` int NOT NULL COMMENT '接收人用户ID',
  `callee_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '接收人昵称',
  `call_type` tinyint(1) DEFAULT '1' COMMENT '通话类型:1-语音通话2-视频通话',
  `status` tinyint(1) DEFAULT '0' COMMENT '通话状态:0-未接通1-已接通2-已挂断',
  `duration` int DEFAULT '0' COMMENT '通话时长(秒)',
  `room_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'WebRTC房间ID',
  `create_time` int NOT NULL COMMENT '发起时间',
  `end_time` int DEFAULT NULL COMMENT '结束时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通话记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_card_templates`
--

CREATE TABLE IF NOT EXISTS `sns_card_templates` (
  `id` int NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模板名称',
  `preview` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '预览图',
  `is_system` tinyint(1) DEFAULT '1' COMMENT '是否系统模板:1-是0-否',
  `is_free` tinyint(1) DEFAULT '1' COMMENT '是否免费:1-是0-否',
  `price` decimal(10,2) DEFAULT '0.00' COMMENT '价格',
  `creator_id` int DEFAULT NULL COMMENT '创建者ID',
  `sort` int DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-启用,0-禁用',
  `create_time` int DEFAULT NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='名片模板表';

--
-- 转存表中的数据 `sns_card_templates`
--

INSERT INTO `sns_card_templates` (`id`, `name`, `preview`, `is_system`, `is_free`, `price`, `creator_id`, `sort`, `status`, `create_time`) VALUES
(1, '简约白', '/static/card_templates/simple-white.jpg', 1, 1, '0.00', NULL, 1, 1, 1773409299),
(2, '简约蓝', '/static/card_templates/simple-blue.jpg', 1, 1, '0.00', NULL, 2, 1, 1773409299),
(3, '商务黑', '/static/card_templates/business-black.jpg', 1, 1, '0.00', NULL, 3, 1, 1773409299),
(4, '清新绿', '/static/card_templates/fresh-green.jpg', 1, 1, '0.00', NULL, 4, 1, 1773409299),
(5, '暖阳橙', '/static/card_templates/warm-orange.jpg', 1, 1, '0.00', NULL, 5, 1, 1773409299),
(6, '少女粉', '/static/card_templates/pink.jpg', 1, 1, '0.00', NULL, 6, 1, 1773409299),
(7, '星空紫', '/static/card_templates/starry-purple.jpg', 1, 0, '19.90', NULL, 7, 1, 1773409299),
(8, '渐变蓝紫', '/static/card_templates/gradient-blue-purple.jpg', 1, 0, '29.90', NULL, 8, 1, 1773409299),
(9, '金色尊享', '/static/card_templates/gold-premium.jpg', 1, 0, '99.90', NULL, 9, 1, 1773409299),
(10, '钻石闪耀', '/static/card_templates/diamond-shine.jpg', 1, 0, '199.90', NULL, 10, 1, 1773409299);

-- --------------------------------------------------------

--
-- 表的结构 `sns_card_visitors`
--

CREATE TABLE IF NOT EXISTS `sns_card_visitors` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '被访问用户ID',
  `visitor_id` int NOT NULL COMMENT '访客ID',
  `visit_time` int NOT NULL COMMENT '访问时间',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '访客IP',
  `user_agent` text COLLATE utf8mb4_unicode_ci COMMENT '用户代理信息'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='名片访客记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_categories`
--

CREATE TABLE IF NOT EXISTS `sns_categories` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分类名称',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '分类描述',
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '分类图标',
  `sort_order` int DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-启用0-禁用',
  `create_time` int DEFAULT '0',
  `update_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='错误日志表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_chat_settings`
--

CREATE TABLE IF NOT EXISTS `sns_chat_settings` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `other_user_id` int NOT NULL COMMENT '对方用户ID',
  `is_muted` tinyint(1) DEFAULT '0' COMMENT '是否免打扰',
  `is_pinned` tinyint(1) DEFAULT '0' COMMENT '会话是否置顶',
  `create_time` int NOT NULL DEFAULT '0',
  `update_time` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='聊天设置表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_collections`
--

CREATE TABLE IF NOT EXISTS `sns_collections` (
  `id` int unsigned NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `moment_id` int NOT NULL COMMENT '动态ID',
  `create_time` int NOT NULL COMMENT '收藏时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态收藏表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_comm`
--

CREATE TABLE IF NOT EXISTS `sns_comm` (
  `id` int NOT NULL,
  `moment_id` int NOT NULL COMMENT '动态ID',
  `user_id` int NOT NULL COMMENT '用户ID',
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户昵称',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户头像',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '评论内容',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-正常,0-隐藏',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '更新时间',
  `parent_id` int DEFAULT '0' COMMENT '父评论ID(用于回复)',
  `reply_to_user_id` int DEFAULT NULL COMMENT '回复的用户ID',
  `like_count` int DEFAULT '0' COMMENT '点赞数',
  `reply_count` int DEFAULT '0' COMMENT '回复数',
  `floor_number` int DEFAULT '0' COMMENT '楼层号',
  `is_top` tinyint(1) DEFAULT '0' COMMENT '是否置顶',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='评论表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_comments`
--

CREATE TABLE IF NOT EXISTS `sns_comments` (
  `id` int unsigned NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `moment_id` int NOT NULL COMMENT '动态ID',
  `parent_id` int NOT NULL DEFAULT '0' COMMENT '父评论ID,0表示顶级评论',
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '用户昵称',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户头像',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '评论内容',
  `likes` int NOT NULL DEFAULT '0' COMMENT '点赞数',
  `replies` int NOT NULL DEFAULT '0' COMMENT '回复数',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-正常,0-删除',
  `create_time` int NOT NULL COMMENT '创建时间',
  `is_top` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否置顶:0-否1-仅一级评论',
  `is_hot` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否热评:0-否1-是',
  `is_author` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否作者评论0-否1-是',
  `reply_to_user_id` int DEFAULT NULL COMMENT '用户ID',
  `reply_to_nickname` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户昵称',
  `media` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '媒体资源(图片/表情)多个用逗号分隔',
  `update_time` int DEFAULT NULL COMMENT '更新时间',
  `comment_status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '评论状态0-关闭评论1-开启评论',
  `top_comment_id` int DEFAULT NULL COMMENT '置顶评论ID',
  `comments_count` int NOT NULL DEFAULT '0' COMMENT '评论数'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态评论表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_comment_likes`
--

CREATE TABLE IF NOT EXISTS `sns_comment_likes` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `comment_id` int NOT NULL COMMENT '评论ID',
  `create_time` int NOT NULL COMMENT '点赞时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='评论点赞表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_configx`
--

CREATE TABLE IF NOT EXISTS `sns_configx` (
  `id` int unsigned NOT NULL,
  `title` text COLLATE utf8mb4_unicode_ci COMMENT '配置名称',
  `text` text COLLATE utf8mb4_unicode_ci COMMENT '配置信息'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='配置表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_cron_jobs`
--

CREATE TABLE IF NOT EXISTS `sns_cron_jobs` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '任务名称',
  `expression` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'cron表达式',
  `command` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '执行命令',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '任务描述',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-禁用1-启用',
  `last_run_time` int DEFAULT '0' COMMENT '上次运行时间',
  `next_run_time` int DEFAULT '0' COMMENT '下次运行时间',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定时任务表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_cron_records`
--

CREATE TABLE IF NOT EXISTS `sns_cron_records` (
  `id` int NOT NULL,
  `job_id` int NOT NULL COMMENT '任务ID',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-失败1-成功',
  `output` text COLLATE utf8mb4_unicode_ci COMMENT '输出内容',
  `error_msg` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  `execute_time` decimal(10,4) DEFAULT '0.0000' COMMENT '执行时间(秒)',
  `start_time` int DEFAULT '0' COMMENT '开始时间',
  `end_time` int DEFAULT '0' COMMENT '结束时间',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定时任务记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_currency_logs`
--

CREATE TABLE IF NOT EXISTS `sns_currency_logs` (
  `id` int unsigned NOT NULL,
  `user_id` int DEFAULT NULL COMMENT '用户ID',
  `currency_id` int DEFAULT NULL COMMENT '货币类型ID',
  `amount` decimal(15,2) DEFAULT NULL COMMENT '变动数量',
  `before_amount` decimal(15,2) DEFAULT NULL COMMENT '变动前数量',
  `after_amount` decimal(15,2) DEFAULT NULL COMMENT '变动后数量',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '变动类型:recharge,withdraw,reward,consume',
  `remark` text COLLATE utf8mb4_unicode_ci COMMENT '备注',
  `source_id` int DEFAULT NULL COMMENT '关联ID',
  `source_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '关联类型',
  `create_time` int DEFAULT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='货币日志表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_currency_types`
--

CREATE TABLE IF NOT EXISTS `sns_currency_types` (
  `id` int unsigned NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '货币名称',
  `symbol` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '货币符号',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '货币描述',
  `is_primary` int DEFAULT '0' COMMENT '是否主要货币',
  `sort` int DEFAULT '0' COMMENT '排序',
  `status` int DEFAULT '1' COMMENT '状态0-禁用1-启用'
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='货币类型表';

--
-- 转存表中的数据 `sns_currency_types`
--

INSERT INTO `sns_currency_types` (`id`, `name`, `symbol`, `description`, `is_primary`, `sort`, `status`) VALUES
(1, '积分', '积分', '社区积分，通过签到、发帖、评论等活动获得', 1, 1, 1),
(2, '金币', '金币', '虚拟金币，用于购买VIP、打赏等消费', 0, 2, 1),
(3, '钻石', '钻石', '高级货币，可用于购买稀有道具', 0, 3, 1);

-- --------------------------------------------------------

--
-- 表的结构 `sns_emojis`
--

CREATE TABLE IF NOT EXISTS `sns_emojis` (
  `id` int NOT NULL COMMENT '表情ID',
  `user_id` int NOT NULL COMMENT '用户ID(0表示系统默认表情)',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '表情名称',
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '表情图片URL',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'custom' COMMENT '表情类型：default-默认，custom-自定义',
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '表情分类',
  `create_time` int NOT NULL COMMENT '创建时间',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态：0-禁用1-启用'
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表情表';

--
-- 转存表中的数据 `sns_emojis`
--

INSERT INTO `sns_emojis` (`id`, `user_id`, `name`, `url`, `type`, `category`, `create_time`, `status`) VALUES
(1, 0, '微笑', '/static/emojis/default/13413817034075935.gif', 'default', '基础', 1773409300, 1),
(2, 0, '大笑', '/static/emojis/default/13413817104559803.gif', 'default', '基础', 1773409300, 1),
(3, 0, '开心', '/static/emojis/default/13413817151837409.gif', 'default', '基础', 1773409300, 1),
(4, 0, '调皮', '/static/emojis/default/13413817162773160.gif', 'default', '基础', 1773409300, 1),
(5, 0, '呲牙', '/static/emojis/default/13413817168943889.gif', 'default', '基础', 1773409300, 1),
(6, 0, '惊讶', '/static/emojis/default/13413817173283676.gif', 'default', '基础', 1773409300, 1),
(7, 0, '撇嘴', '/static/emojis/default/13413817177200576.gif', 'default', '基础', 1773409300, 1),
(8, 0, '难过', '/static/emojis/default/13413817181284408.gif', 'default', '基础', 1773409300, 1),
(9, 0, '大哭', '/static/emojis/default/13413817185093159.gif', 'default', '基础', 1773409300, 1),
(10, 0, '生气', '/static/emojis/default/13413817188664963.gif', 'default', '基础', 1773409300, 1),
(11, 0, '酷', '/static/emojis/default/13413817192124428.gif', 'default', '基础', 1773409300, 1),
(12, 0, '发呆', '/static/emojis/default/13413817196163080.gif', 'default', '基础', 1773409300, 1),
(13, 0, '点赞', '/static/emojis/default/13413817200409309.gif', 'default', '手势', 1773409300, 1),
(14, 0, '踩', '/static/emojis/default/13413817204533292.gif', 'default', '手势', 1773409300, 1),
(15, 0, '拳头', '/static/emojis/default/13413817208526996.gif', 'default', '手势', 1773409300, 1),
(16, 0, '握手', '/static/emojis/default/13413817213184230.gif', 'default', '手势', 1773409300, 1),
(17, 0, '胜利', '/static/emojis/default/13413817245809559.gif', 'default', '手势', 1773409300, 1),
(18, 0, 'OK手势', '/static/emojis/default/13413817250006626.gif', 'default', '手势', 1773409300, 1),
(19, 0, '害羞', '/static/emojis/default/13413817254157271.gif', 'default', '表情包', 1773409300, 1),
(20, 0, '害羞2', '/static/emojis/default/13413817260702591.gif', 'default', '表情包', 1773409300, 1),
(21, 0, '偷笑', '/static/emojis/default/13413817264842563.gif', 'default', '表情包', 1773409300, 1),
(22, 0, '偷笑2', '/static/emojis/default/13413817270806233.gif', 'default', '表情包', 1773409300, 1),
(23, 0, '得意', '/static/emojis/default/13413817274590779.gif', 'default', '表情包', 1773409300, 1),
(24, 0, '得意2', '/static/emojis/default/13413817288864222.gif', 'default', '表情包', 1773409300, 1),
(25, 0, '害羞3', '/static/emojis/default/13413817293493374.gif', 'default', '表情包', 1773409300, 1),
(26, 0, '害羞4', '/static/emojis/default/13413817297556370.gif', 'default', '表情包', 1773409300, 1),
(27, 0, '偷笑3', '/static/emojis/default/13413817301164001.gif', 'default', '表情包', 1773409300, 1),
(28, 0, '偷笑4', '/static/emojis/default/13413817304698562.gif', 'default', '表情包', 1773409300, 1),
(29, 0, '疑惑', '/static/emojis/default/13413817308193972.gif', 'default', '表情包', 1773409300, 1),
(30, 0, '疑惑2', '/static/emojis/default/13413817311926244.gif', 'default', '表情包', 1773409300, 1),
(31, 0, '无语', '/static/emojis/default/13413817319183613.gif', 'default', '表情包', 1773409300, 1),
(32, 0, '无语2', '/static/emojis/default/13413817327484190.gif', 'default', '表情包', 1773409300, 1),
(33, 0, '眨眼', '/static/emojis/default/13413817332123884.gif', 'default', '表情包', 1773409300, 1),
(34, 0, '眨眼2', '/static/emojis/default/13413817337085224.gif', 'default', '表情包', 1773409300, 1),
(35, 0, '汗', '/static/emojis/default/13413817342184588.gif', 'default', '表情包', 1773409300, 1),
(36, 0, '汗2', '/static/emojis/default/13413817346306968.gif', 'default', '表情包', 1773409300, 1),
(37, 0, '震惊', '/static/emojis/default/13413817351007329.gif', 'default', '表情包', 1773409300, 1),
(38, 0, '震惊2', '/static/emojis/default/13413817357366380.gif', 'default', '表情包', 1773409300, 1),
(39, 0, '猫', '/static/emojis/default/13413817361863498.gif', 'default', '动物', 1773409300, 1),
(40, 0, '狗', '/static/emojis/default/13413817365809591.gif', 'default', '动物', 1773409300, 1),
(41, 0, '兔子', '/static/emojis/default/13413817371298210.gif', 'default', '动物', 1773409300, 1),
(42, 0, '熊猫', '/static/emojis/default/13413817380080107.gif', 'default', '动物', 1773409300, 1),
(43, 0, '狐狸', '/static/emojis/default/13413817387834933.gif', 'default', '动物', 1773409300, 1),
(44, 0, '熊', '/static/emojis/default/13413817393601535.gif', 'default', '动物', 1773409300, 1),
(45, 0, '猴子', '/static/emojis/default/13413817398664584.gif', 'default', '动物', 1773409300, 1),
(46, 0, '猪', '/static/emojis/default/13413817432476927.gif', 'default', '动物', 1773409300, 1),
(47, 0, '牛', '/static/emojis/default/13413817436831068.gif', 'default', '动物', 1773409300, 1),
(48, 0, '马', '/static/emojis/default/13413817441596630.gif', 'default', '动物', 1773409300, 1),
(49, 0, '咖啡', '/static/emojis/default/13413817445643527.gif', 'default', '食物', 1773409300, 1),
(50, 0, '汉堡', '/static/emojis/default/13413817452603332.gif', 'default', '食物', 1773409300, 1),
(51, 0, '披萨', '/static/emojis/default/13413817457288960.gif', 'default', '食物', 1773409300, 1),
(52, 0, '蛋糕', '/static/emojis/default/13413817461037332.gif', 'default', '食物', 1773409300, 1),
(53, 0, '冰淇淋', '/static/emojis/default/13413817465864845.gif', 'default', '食物', 1773409300, 1),
(54, 0, '奶茶', '/static/emojis/default/13413817483624845.gif', 'default', '食物', 1773409300, 1),
(55, 0, '饮料', '/static/emojis/default/13413817487848264.gif', 'default', '食物', 1773409300, 1),
(56, 0, '水果', '/static/emojis/default/13413817497838353.gif', 'default', '食物', 1773409300, 1),
(57, 0, '面包', '/static/emojis/default/13413817502265431.gif', 'default', '食物', 1773409300, 1),
(58, 0, '糖果', '/static/emojis/default/13413817506364086.gif', 'default', '食物', 1773409300, 1),
(59, 0, '爱心', '/static/emojis/default/13413817517104426.gif', 'default', '符号', 1773409300, 1),
(60, 0, '爱心破碎', '/static/emojis/default/13413817521176348.gif', 'default', '符号', 1773409300, 1),
(61, 0, '星星', '/static/emojis/default/13413817525517313.gif', 'default', '符号', 1773409300, 1),
(62, 0, '太阳', '/static/emojis/default/13413817529632337.gif', 'default', '符号', 1773409300, 1),
(63, 0, '月亮', '/static/emojis/default/13413817533501893.gif', 'default', '符号', 1773409300, 1),
(64, 0, '闪电', '/static/emojis/default/13413817537454183.gif', 'default', '符号', 1773409300, 1),
(65, 0, '雨伞', '/static/emojis/default/13413817543755541.gif', 'default', '符号', 1773409300, 1),
(66, 0, '云', '/static/emojis/default/13413817548641699.gif', 'default', '符号', 1773409300, 1),
(67, 0, '雪花', '/static/emojis/default/13413817554194664.gif', 'default', '符号', 1773409300, 1),
(68, 0, '彩虹', '/static/emojis/default/13413817558664117.gif', 'default', '符号', 1773409300, 1),
(69, 0, '足球', '/static/emojis/default/13413817564849882.gif', 'default', '运动', 1773409300, 1),
(70, 0, '篮球', '/static/emojis/default/13413817570396800.gif', 'default', '运动', 1773409300, 1),
(71, 0, '跑步', '/static/emojis/default/13413817575688235.gif', 'default', '运动', 1773409300, 1),
(72, 0, '游泳', '/static/emojis/default/13413817584160683.gif', 'default', '运动', 1773409300, 1),
(73, 0, '骑行', '/static/emojis/default/13413817588724383.gif', 'default', '运动', 1773409300, 1),
(74, 0, '网球', '/static/emojis/default/13413817595825685.gif', 'default', '运动', 1773409300, 1),
(75, 0, '乒乓球', '/static/emojis/default/13413817603264672.gif', 'default', '运动', 1773409300, 1),
(76, 0, '羽毛球', '/static/emojis/default/13413817609147875.gif', 'default', '运动', 1773409300, 1),
(77, 0, '滑雪', '/static/emojis/default/13413817623514269.gif', 'default', '运动', 1773409300, 1),
(78, 0, '滑板', '/static/emojis/default/13413817630449877.gif', 'default', '运动', 1773409300, 1),
(79, 0, '圣诞树', '/static/emojis/default/13413817651278378.gif', 'default', '节日', 1773409300, 1),
(80, 0, '礼物', '/static/emojis/default/13413817658476316.gif', 'default', '节日', 1773409300, 1),
(81, 0, '烟花', '/static/emojis/default/13413817664443253.gif', 'default', '节日', 1773409300, 1),
(82, 0, '灯笼', '/static/emojis/default/13413817669121632.gif', 'default', '节日', 1773409300, 1),
(83, 0, '鞭炮', '/static/emojis/default/13413817673657516.gif', 'default', '节日', 1773409300, 1),
(84, 0, '气球', '/static/emojis/default/13413817678637027.gif', 'default', '节日', 1773409300, 1),
(85, 0, '彩带', '/static/emojis/default/13413817685986909.gif', 'default', '节日', 1773409300, 1),
(86, 0, '派对帽', '/static/emojis/default/13413817709236894.gif', 'default', '节日', 1773409300, 1),
(87, 0, '生日蛋糕', '/static/emojis/default/13413817715005410.gif', 'default', '节日', 1773409300, 1),
(88, 0, '许愿', '/static/emojis/default/13413817721796405.gif', 'default', '节日', 1773409300, 1),
(89, 0, '绅士', '/static/emojis/default/13413817730804926.gif', 'default', '人物', 1773409300, 1),
(90, 0, '女士', '/static/emojis/default/13413817738948122.gif', 'default', '人物', 1773409300, 1),
(91, 0, '老人', '/static/emojis/default/13413817751019241.gif', 'default', '人物', 1773409300, 1),
(92, 0, '小孩', '/static/emojis/default/13413817773118790.gif', 'default', '人物', 1773409300, 1),
(93, 0, '医生', '/static/emojis/default/13413817784627001.gif', 'default', '人物', 1773409300, 1),
(94, 0, '警察', '/static/emojis/default/13413817791608442.gif', 'default', '人物', 1773409300, 1),
(95, 0, '厨师', '/static/emojis/default/13413817800686832.gif', 'default', '人物', 1773409300, 1),
(96, 0, '艺术家', '/static/emojis/default/13413817807803654.gif', 'default', '人物', 1773409300, 1),
(97, 0, '音乐家', '/static/emojis/default/13413817819192741.gif', 'default', '人物', 1773409300, 1),
(98, 0, '宇航员', '/static/emojis/default/13413817829503671.gif', 'default', '人物', 1773409300, 1),
(99, 0, '问号', '/static/emojis/default/13413817834264246.gif', 'default', '其他', 1773409300, 1),
(100, 0, '感叹号', '/static/emojis/default/13413817839687211.gif', 'default', '其他', 1773409300, 1);

-- --------------------------------------------------------

--
-- 表的结构 `sns_emoji_usage`
--

CREATE TABLE IF NOT EXISTS `sns_emoji_usage` (
  `id` int NOT NULL COMMENT '记录ID',
  `user_id` int NOT NULL COMMENT '用户ID',
  `emoji_id` int NOT NULL COMMENT '表情ID',
  `use_time` int NOT NULL COMMENT '使用时间',
  `use_count` int DEFAULT '1' COMMENT '使用次数'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表情使用记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_error_log`
--

CREATE TABLE IF NOT EXISTS `sns_error_log` (
  `id` int NOT NULL,
  `level` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '错误级别',
  `message` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  `file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '文件路径',
  `line` int DEFAULT '0' COMMENT '行号',
  `context` text COLLATE utf8mb4_unicode_ci COMMENT '上下文',
  `trace` text COLLATE utf8mb4_unicode_ci COMMENT '堆栈跟踪',
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'IP地址',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='错误日志表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_essay`
--

CREATE TABLE IF NOT EXISTS `sns_essay` (
  `id` int unsigned NOT NULL,
  `ptpuser` text COLLATE utf8mb4_unicode_ci COMMENT '发布者账号',
  `ptpimg` text COLLATE utf8mb4_unicode_ci COMMENT '发布者头像',
  `ptpname` text COLLATE utf8mb4_unicode_ci COMMENT '发布者昵称',
  `ptptext` text COLLATE utf8mb4_unicode_ci COMMENT '文章内容',
  `ptpimag` text COLLATE utf8mb4_unicode_ci COMMENT '文章图片',
  `ptpvideo` text COLLATE utf8mb4_unicode_ci COMMENT '文章视频',
  `ptpmusic` text COLLATE utf8mb4_unicode_ci COMMENT '文章音乐',
  `ptplx` text COLLATE utf8mb4_unicode_ci COMMENT '文章类型(img=图文 video=视频 music=音乐 only=仅文字)',
  `ptpdw` text COLLATE utf8mb4_unicode_ci COMMENT '文章发布时间',
  `ptptime` text COLLATE utf8mb4_unicode_ci COMMENT '文章发布时间',
  `ptpgg` text COLLATE utf8mb4_unicode_ci COMMENT '文章是否为广告0=不是1=是',
  `ptpggurl` text COLLATE utf8mb4_unicode_ci COMMENT '广告跳转链接',
  `ptpys` text COLLATE utf8mb4_unicode_ci COMMENT '文章是否可见(0=不可见1=可见)',
  `commauth` text COLLATE utf8mb4_unicode_ci COMMENT '是否允许评论(0=否1=开)',
  `ptpaud` text COLLATE utf8mb4_unicode_ci COMMENT '审核状态0=未审核1=已审核',
  `ip` text COLLATE utf8mb4_unicode_ci COMMENT '文章发布时的ip',
  `cid` text COLLATE utf8mb4_unicode_ci COMMENT '文章cid'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_faqs`
--

CREATE TABLE IF NOT EXISTS `sns_faqs` (
  `id` int NOT NULL,
  `question` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '问题',
  `answer` text COLLATE utf8mb4_unicode_ci COMMENT '答案',
  `category_id` int DEFAULT '0' COMMENT '分类ID',
  `sort_order` int DEFAULT '0',
  `status` tinyint(1) DEFAULT '1',
  `create_time` int DEFAULT '0',
  `update_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='版本日志表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_faq_categories`
--

CREATE TABLE IF NOT EXISTS `sns_faq_categories` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分类名称',
  `description` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int DEFAULT '0',
  `status` tinyint(1) DEFAULT '1',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户钱包表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_favorites`
--

CREATE TABLE IF NOT EXISTS `sns_favorites` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `target_id` int NOT NULL COMMENT '目标ID',
  `target_type` tinyint(1) NOT NULL COMMENT '目标类型:1-动态2-评论',
  `folder_id` int DEFAULT '0' COMMENT '收藏夹ID',
  `folder_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '默认收藏' COMMENT '收藏夹名称',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_favorite_folders`
--

CREATE TABLE IF NOT EXISTS `sns_favorite_folders` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '收藏夹名称',
  `description` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '描述',
  `is_public` tinyint(1) DEFAULT '0' COMMENT '是否公开',
  `sort_order` int DEFAULT '0' COMMENT '排序',
  `cover` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '封面图',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` timestamp NULL DEFAULT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏夹表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_follows`
--

CREATE TABLE IF NOT EXISTS `sns_follows` (
  `id` int NOT NULL,
  `follower_id` int NOT NULL COMMENT '关注者ID',
  `following_id` int NOT NULL COMMENT '被关注者ID',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-正常,0已取消',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='关注表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_friend_groups`
--

CREATE TABLE IF NOT EXISTS `sns_friend_groups` (
  `id` int unsigned NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `name` varchar(50) NOT NULL COMMENT '分组名称',
  `sort` tinyint NOT NULL DEFAULT '0' COMMENT '排序',
  `create_time` int NOT NULL COMMENT '创建时间',
  `update_time` int NOT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='好友分组表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_friend_group_members`
--

CREATE TABLE IF NOT EXISTS `sns_friend_group_members` (
  `id` int unsigned NOT NULL,
  `group_id` int unsigned NOT NULL COMMENT '分组ID',
  `user_id` int NOT NULL COMMENT '用户ID',
  `friend_id` int NOT NULL COMMENT '好友ID',
  `add_time` int NOT NULL COMMENT '添加时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='好友分组成员表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_hidden_moments`
--

CREATE TABLE IF NOT EXISTS `sns_hidden_moments` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `moment_id` int NOT NULL COMMENT '动态ID',
  `create_time` int DEFAULT '0' COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='隐藏动态记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_hot_searches`
--

CREATE TABLE IF NOT EXISTS `sns_hot_searches` (
  `id` int unsigned NOT NULL,
  `keyword` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '搜索关键词',
  `search_count` int NOT NULL DEFAULT '0' COMMENT '搜索次数',
  `today_count` int NOT NULL DEFAULT '0' COMMENT '今日搜索次数',
  `yesterday_count` int NOT NULL DEFAULT '0' COMMENT '昨日搜索次数',
  `is_hot` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否热门:0-否1-是',
  `rank` tinyint NOT NULL DEFAULT '0' COMMENT '排名',
  `update_time` int NOT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='热搜榜表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_likes`
--

CREATE TABLE IF NOT EXISTS `sns_likes` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `target_id` int NOT NULL COMMENT '目标ID',
  `target_type` tinyint(1) NOT NULL COMMENT '目标类型:1-动态2-评论',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='点赞表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_link`
--

CREATE TABLE IF NOT EXISTS `sns_link` (
  `id` int unsigned NOT NULL,
  `url` text COLLATE utf8mb4_unicode_ci COMMENT '友链地址',
  `urls` text COLLATE utf8mb4_unicode_ci COMMENT '友链说明',
  `urlimg` text COLLATE utf8mb4_unicode_ci COMMENT '友链图标'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='友链表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_login_logs`
--

CREATE TABLE IF NOT EXISTS `sns_login_logs` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `login_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '登录IP',
  `login_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '登录时间',
  `device_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '设备类型',
  `browser` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '浏览器',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态0-失败1-成功',
  `is_abnormal` tinyint(1) DEFAULT '0' COMMENT '是否异常登录',
  `abnormal_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '异常原因'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录日志表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_mentions`
--

CREATE TABLE IF NOT EXISTS `sns_mentions` (
  `id` int unsigned NOT NULL,
  `moment_id` int NOT NULL COMMENT '动态ID',
  `user_id` int NOT NULL COMMENT '发布动态的用户ID',
  `mentioned_user_id` int NOT NULL COMMENT '被@的用户ID',
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '被@用户的昵称',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '被@用户的头像',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '相关内容',
  `create_time` int NOT NULL COMMENT '创建时间',
  `read_status` tinyint(1) DEFAULT '0' COMMENT '阅读状态0-未读,1已读',
  `read_time` int DEFAULT '0' COMMENT '阅读时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态@提及表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_messages`
--

CREATE TABLE IF NOT EXISTS `sns_messages` (
  `id` int unsigned NOT NULL,
  `sender_id` int NOT NULL COMMENT '发送者ID',
  `receiver_id` int NOT NULL COMMENT '接收者ID',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '消息内容',
  `message_type` tinyint(1) DEFAULT '1' COMMENT '消息类型:1-文本,2-图片,3-视频',
  `reply_to_id` int DEFAULT '0' COMMENT '引用回复的消息ID',
  `file_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '文件URL',
  `voice_duration` int DEFAULT '0' COMMENT '语音时长(秒)',
  `is_read` tinyint(1) DEFAULT '0' COMMENT '是否已读:0-未读,1-已读',
  `read_time` int DEFAULT '0' COMMENT '阅读时间',
  `is_recalled` tinyint(1) DEFAULT '0' COMMENT '是否撤回0-未撤回，1-已撤回',
  `is_pinned` tinyint(1) DEFAULT '0' COMMENT '是否置顶',
  `pin_time` int DEFAULT NULL COMMENT '置顶时间',
  `recall_time` int DEFAULT NULL COMMENT '撤回时间（时间戳）',
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '文件URL',
  `file_size` int DEFAULT '0' COMMENT '文件大小(字节)',
  `send_status` tinyint(1) DEFAULT '1' COMMENT '发送状态0-发送中,1-成功,2-失败',
  `send_time` int DEFAULT NULL COMMENT '发送完成时间',
  `self_destruct_time` int DEFAULT NULL COMMENT '阅后即焚时间(秒)',
  `create_time` int DEFAULT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='私信消息表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_message_favorites`
--

CREATE TABLE IF NOT EXISTS `sns_message_favorites` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `message_id` int NOT NULL COMMENT '消息ID',
  `create_time` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='消息收藏表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_message_templates`
--

CREATE TABLE IF NOT EXISTS `sns_message_templates` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模板名称',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模板类型',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '消息标题',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '消息内容',
  `variables` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '可用变量',
  `status` tinyint(1) DEFAULT '1',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='版本表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_migrations`
--

CREATE TABLE IF NOT EXISTS `sns_migrations` (
  `version` bigint NOT NULL,
  `migration_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_time` timestamp NULL DEFAULT NULL,
  `end_time` timestamp NULL DEFAULT NULL,
  `breakpoint` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='迁移记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_moments`
--

CREATE TABLE IF NOT EXISTS `sns_moments` (
  `id` int unsigned NOT NULL,
  `user_id` int NOT NULL DEFAULT '1' COMMENT '用户ID',
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '用户昵称',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户头像',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '动态内容',
  `images` longtext COLLATE utf8mb4_unicode_ci COMMENT '图片列表JSON',
  `videos` mediumtext COLLATE utf8mb4_unicode_ci,
  `location` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '位置信息',
  `latitude` decimal(10,7) DEFAULT NULL COMMENT '纬度',
  `longitude` decimal(10,7) DEFAULT NULL COMMENT '经度',
  `type` tinyint(1) DEFAULT '1' COMMENT '动态类型1-文本,2图片,3视频,4链接',
  `privacy` tinyint(1) DEFAULT '1' COMMENT '隐私设置:1公开,2私密,3仅好友可见4部分可见',
  `is_top` tinyint(1) DEFAULT '0' COMMENT '是否置顶',
  `top_expire_time` timestamp NULL DEFAULT NULL COMMENT '置顶过期时间',
  `is_recommend` tinyint(1) DEFAULT '0' COMMENT '是否推荐',
  `likes` int DEFAULT '0' COMMENT '点赞数',
  `comments` int DEFAULT '0' COMMENT '评论数',
  `share_count` int NOT NULL DEFAULT '0' COMMENT '分享次数',
  `shares` int DEFAULT '0' COMMENT '分享数',
  `views` int DEFAULT '0' COMMENT '浏览数',
  `collect_count` int DEFAULT '0' COMMENT '收藏数',
  `publish_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '发布时间',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-正常,0删除',
  `is_anonymous` tinyint(1) DEFAULT '0' COMMENT '是否匿名发布',
  `comments_count` int NOT NULL DEFAULT '0' COMMENT '评论数',
  `top_comment_id` int DEFAULT NULL COMMENT '置顶评论ID'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_moment_drafts`
--

CREATE TABLE IF NOT EXISTS `sns_moment_drafts` (
  `id` int NOT NULL COMMENT '草稿ID',
  `user_id` int NOT NULL COMMENT '用户ID',
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '显示名称',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '显示头像',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '动态内容',
  `images` varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '图片数组JSON',
  `videos` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '视频数组JSON',
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '位置信息',
  `privacy` tinyint DEFAULT '1' COMMENT '隐私设置:1-公开,2-仅自己可见3-仅好友可见',
  `is_anonymous` tinyint DEFAULT '0' COMMENT '是否匿名:0-非匿名1-匿名',
  `create_time` int NOT NULL COMMENT '创建时间',
  `updated_time` int NOT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态草稿表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_moment_likes`
--

CREATE TABLE IF NOT EXISTS `sns_moment_likes` (
  `id` int unsigned NOT NULL,
  `moment_id` int NOT NULL COMMENT '动态ID',
  `user_id` int NOT NULL COMMENT '用户ID',
  `create_time` int NOT NULL COMMENT '点赞时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态点赞表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_moment_topics`
--

CREATE TABLE IF NOT EXISTS `sns_moment_topics` (
  `id` int unsigned NOT NULL,
  `moment_id` int NOT NULL COMMENT '动态ID',
  `topic_id` int NOT NULL COMMENT '话题ID',
  `create_time` int NOT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态话题关联表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_notifications`
--

CREATE TABLE IF NOT EXISTS `sns_notifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '接收用户ID',
  `sender_id` int DEFAULT '0' COMMENT '发送者ID(0为系统)',
  `type` tinyint(1) NOT NULL COMMENT '通知类型:1点赞,2评论,3关注,4私信,5系统通知',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标题',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '内容',
  `target_id` int DEFAULT '0' COMMENT '目标ID',
  `target_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '目标类型',
  `is_read` tinyint(1) DEFAULT '0' COMMENT '是否已读',
  `read_time` timestamp NULL DEFAULT NULL COMMENT '阅读时间',
  `message_type` tinyint(1) DEFAULT '1' COMMENT '消息类型:1文本,2图片,3语音,4表情',
  `file_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '文件URL',
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '文件URL',
  `file_size` int DEFAULT '0' COMMENT '文件大小',
  `duration` int DEFAULT '0' COMMENT '语音时长(秒)',
  `is_recalled` tinyint(1) DEFAULT '0' COMMENT '是否撤回',
  `recall_time` timestamp NULL DEFAULT NULL COMMENT '撤回时间',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_operations`
--

CREATE TABLE IF NOT EXISTS `sns_operations` (
  `id` int unsigned NOT NULL,
  `title` varchar(100) NOT NULL COMMENT '活动标题',
  `description` text NOT NULL COMMENT '活动描述',
  `cover` varchar(255) NOT NULL COMMENT '活动封面',
  `start_time` int NOT NULL COMMENT '开始时间',
  `end_time` int NOT NULL COMMENT '结束时间',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-未开始2-进行中3-已结束4-已下架',
  `participant_count` int NOT NULL DEFAULT '0' COMMENT '参与人数',
  `view_count` int NOT NULL DEFAULT '0' COMMENT '浏览人数',
  `creator_id` int NOT NULL COMMENT '创建人ID',
  `create_time` int NOT NULL COMMENT '创建时间',
  `update_time` int NOT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='运营活动表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_operation_log`
--

CREATE TABLE IF NOT EXISTS `sns_operation_log` (
  `id` int NOT NULL,
  `user_id` int DEFAULT '0' COMMENT '用户ID',
  `username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '用户名',
  `action` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '操作行为',
  `module` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '模块',
  `method` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '请求方法',
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '请求URL',
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'IP地址',
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'User-Agent',
  `param` text COLLATE utf8mb4_unicode_ci COMMENT '请求参数',
  `result` text COLLATE utf8mb4_unicode_ci COMMENT '操作结果',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='慢查询日志表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_operation_participants`
--

CREATE TABLE IF NOT EXISTS `sns_operation_participants` (
  `id` int unsigned NOT NULL,
  `operation_id` int NOT NULL COMMENT '活动ID',
  `user_id` int NOT NULL COMMENT '用户ID',
  `participant_time` int NOT NULL COMMENT '参与时间',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-已参与2-已完成3-已获得',
  `reward_id` int DEFAULT NULL COMMENT '获得的奖励ID'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动参与表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_operation_rewards`
--

CREATE TABLE IF NOT EXISTS `sns_operation_rewards` (
  `id` int unsigned NOT NULL,
  `operation_id` int NOT NULL COMMENT '活动ID',
  `name` varchar(50) NOT NULL COMMENT '奖励名称',
  `description` varchar(200) NOT NULL COMMENT '奖励描述',
  `type` tinyint(1) NOT NULL COMMENT '奖励类型:1-积分,2-虚拟货币,3-实物奖励,4-优惠券',
  `value` varchar(50) NOT NULL COMMENT '奖励金额',
  `quantity` int NOT NULL COMMENT '奖励数量',
  `remaining_quantity` int NOT NULL COMMENT '剩余数量',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-有效,2-无效',
  `create_time` int NOT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='活动奖励表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_operation_reward_records`
--

CREATE TABLE IF NOT EXISTS `sns_operation_reward_records` (
  `id` int unsigned NOT NULL,
  `operation_id` int NOT NULL COMMENT '活动ID',
  `reward_id` int NOT NULL COMMENT '奖励ID',
  `user_id` int NOT NULL COMMENT '用户ID',
  `reward_value` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '奖励金额',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-待发放2-已发放3-已领取4-已过期',
  `create_time` int NOT NULL COMMENT '创建时间',
  `issue_time` int DEFAULT NULL COMMENT '发放时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='奖励发放记录';

-- --------------------------------------------------------

--
-- 表的结构 `sns_post_media`
--

CREATE TABLE IF NOT EXISTS `sns_post_media` (
  `id` int NOT NULL,
  `post_id` int NOT NULL COMMENT '动态ID',
  `user_id` int NOT NULL COMMENT '用户ID',
  `media_type` tinyint(1) NOT NULL COMMENT '媒体类型:1图片,2视频,3音频,4文件',
  `file_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文件URL',
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文件URL',
  `file_size` int NOT NULL COMMENT '文件大小(字节)',
  `file_mime` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '文件MIME类型',
  `width` int DEFAULT NULL COMMENT '图片/视频宽度',
  `height` int DEFAULT NULL COMMENT '图片/视频高度',
  `duration` int DEFAULT NULL COMMENT '音视频时长',
  `thumbnail_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '缩略图URL',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '描述',
  `sort_order` int DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态0-删除,1正常',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态多媒体表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_push_records`
--

CREATE TABLE IF NOT EXISTS `sns_push_records` (
  `id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '推送标题',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '推送内容',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'system' COMMENT '推送类型',
  `target_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'all' COMMENT '目标类型 all/user/tag',
  `target_value` text COLLATE utf8mb4_unicode_ci COMMENT '目标值',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-待发送1发送中,2已发送3发送失败',
  `send_time` int DEFAULT '0' COMMENT '发送时间',
  `success_count` int DEFAULT '0' COMMENT '成功数量',
  `fail_count` int DEFAULT '0' COMMENT '失败数量',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='软件表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_rate_limit_rules`
--

CREATE TABLE IF NOT EXISTS `sns_rate_limit_rules` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '规则名称',
  `endpoint` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '接口地址',
  `method` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '请求方法',
  `max_requests` int DEFAULT '100' COMMENT '最大请求数',
  `time_window` int DEFAULT '3600' COMMENT '时间窗口(秒)',
  `status` tinyint(1) DEFAULT '1',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件上传表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_recharge_records`
--

CREATE TABLE IF NOT EXISTS `sns_recharge_records` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `amount` decimal(10,2) NOT NULL COMMENT '充值金额',
  `pay_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '支付方式',
  `pay_time` int DEFAULT '0' COMMENT '支付时间',
  `transaction_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '交易流水号',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-待支付1成功2失败',
  `remark` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '备注',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统消息表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_reports`
--

CREATE TABLE IF NOT EXISTS `sns_reports` (
  `id` int NOT NULL,
  `reporter_id` int NOT NULL COMMENT '举报人ID',
  `reported_user_id` int DEFAULT NULL COMMENT '被举报人ID',
  `moment_id` int DEFAULT NULL COMMENT '动态ID',
  `comment_id` int DEFAULT NULL COMMENT '评论ID',
  `type` tinyint(1) NOT NULL COMMENT '类型:1-动态2-评论3-用户',
  `reason` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '举报原因',
  `evidence_urls` text COLLATE utf8mb4_unicode_ci COMMENT '证据图片URL列表(JSON)',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-待处理1-已处理2-已忽略',
  `handle_time` int DEFAULT NULL COMMENT '处理时间',
  `handle_result` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '处理结果',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `handler_id` int DEFAULT NULL COMMENT '处理人ID',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_search_history`
--

CREATE TABLE IF NOT EXISTS `sns_search_history` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `keyword` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '搜索关键词',
  `result_count` int DEFAULT '0' COMMENT '结果数量',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='搜索历史表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_search_logs`
--

CREATE TABLE IF NOT EXISTS `sns_search_logs` (
  `id` int NOT NULL,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '搜索关键词',
  `count` int DEFAULT '1' COMMENT '搜索次数',
  `last_search_time` int DEFAULT '0' COMMENT '最后搜索时间',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_sessions`
--

CREATE TABLE IF NOT EXISTS `sns_sessions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `session_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '会话ID',
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IP地址',
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户代理',
  `device_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '设备类型',
  `device_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '设备名称',
  `expire_time` int DEFAULT NULL COMMENT '过期时间',
  `create_time` int DEFAULT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会话表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_shares`
--

CREATE TABLE IF NOT EXISTS `sns_shares` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `target_id` int NOT NULL COMMENT '目标ID',
  `target_type` tinyint(1) NOT NULL COMMENT '目标类型:1-动态2-评论',
  `share_type` tinyint(1) DEFAULT '1' COMMENT '分享类型:1-朋友圈2QQ,3微博,4链接',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分享记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_slow_query_log`
--

CREATE TABLE IF NOT EXISTS `sns_slow_query_log` (
  `id` int NOT NULL,
  `sql_text` text COLLATE utf8mb4_unicode_ci COMMENT 'SQL语句',
  `execute_time` decimal(10,4) DEFAULT '0.0000' COMMENT '执行时间(秒)',
  `file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '文件路径',
  `line` int DEFAULT '0' COMMENT '行号',
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'IP地址',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_software`
--

CREATE TABLE IF NOT EXISTS `sns_software` (
  `id` int NOT NULL,
  `software_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '软件名称',
  `software_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '软件代码',
  `version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '当前版本',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '软件描述',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-启用0-禁用',
  `create_time` int DEFAULT '0',
  `update_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_storage_files`
--

CREATE TABLE IF NOT EXISTS `sns_storage_files` (
  `id` int NOT NULL,
  `user_id` int DEFAULT '0' COMMENT '上传用户ID',
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原始文件URL',
  `filepath` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '存储路径',
  `filesize` int DEFAULT '0' COMMENT '文件大小(字节)',
  `mimetype` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'MIME类型',
  `storage_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'local' COMMENT '存储类型',
  `md5` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'MD5值',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-正常,0-已删除',
  `create_time` int DEFAULT '0' COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_system_config`
--

CREATE TABLE IF NOT EXISTS `sns_system_config` (
  `id` int unsigned NOT NULL,
  `config_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '配置键',
  `config_value` text COLLATE utf8mb4_unicode_ci COMMENT '配置值',
  `config_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '配置名称',
  `config_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'text' COMMENT '配置类型:text,textarea,number,select,radio,checkbox',
  `config_group` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'base' COMMENT '配置分组',
  `config_options` text COLLATE utf8mb4_unicode_ci COMMENT '配置选项(JSON)',
  `sort` int DEFAULT '0' COMMENT '排序',
  `create_time` int NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int NOT NULL DEFAULT '0' COMMENT '更新时间'
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置表';

--
-- 转存表中的数据 `sns_system_config`
--

INSERT INTO `sns_system_config` (`id`, `config_key`, `config_value`, `config_name`, `config_type`, `config_group`, `config_options`, `sort`, `create_time`, `update_time`) VALUES
(1, 'site_name', '圈子社区', '网站名称', 'text', 'base', '', 1, 1773409302, 1773409302),
(2, 'site_subtitle', '连接你我，分享精彩', '网站副标题', 'text', 'base', '', 2, 1773409302, 1773409302),
(3, 'site_url', 'http://localhost', '网站地址', 'text', 'base', '', 3, 1773409302, 1773409302),
(4, 'site_keywords', '圈子,社区,社交', '网站关键词', 'text', 'base', '', 4, 1773409302, 1773409302),
(5, 'site_description', '一个优秀的社区平台', '网站描述', 'textarea', 'base', '', 5, 1773409302, 1773409302),
(6, 'site_icp', '', 'ICP备案号', 'text', 'base', '', 6, 1773409302, 1773409302),
(7, 'site_logo', '', '网站Logo', 'text', 'base', '', 7, 1773409302, 1773409302),
(8, 'site_favicon', '', '网站图标', 'text', 'base', '', 8, 1773409302, 1773409302),
(9, 'site_homimg', '', '顶部背景图片URL', 'text', 'base', '', 9, 1773409302, 1773409302),
(10, 'site_sign', '', '网站签名/标语', 'text', 'base', '', 10, 1773409302, 1773409302),
(11, 'site_copyright', '© 2025 我圈社交平台 版权所有', '版权信息', 'text', 'base', '', 11, 1773409302, 1773409302),
(12, 'site_title', '', '网站标题', 'text', 'seo', '', 1, 1773409302, 1773409302),
(13, 'share_enabled', '1', '是否开启分享功能', 'radio', 'seo', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 2, 1773409302, 1773409302),
(14, 'site_status', '0', '站点状态', 'select', 'site', '[{"label":"正常运行","value":"0"},{"label":"维护中","value":"1"}]', 1, 1773409302, 1773409302),
(15, 'site_timezone', 'Asia/Shanghai', '系统时区', 'select', 'site', '[{"label":"东八区 (Asia/Shanghai)","value":"Asia/Shanghai"},{"label":"东八区 (Asia/Hong_Kong)","value":"Asia/Hong_Kong"},{"label":"东八区 (Asia/Taipei)","value":"Asia/Taipei"}]', 2, 1773409302, 1773409302),
(16, 'site_domain', '', '主域名', 'text', 'site', '', 3, 1773409302, 1773409302),
(17, 'static_domain', '', '静态资源域名', 'text', 'site', '', 4, 1773409302, 1773409302),
(18, 'maintenance_text', '', '维护文案', 'textarea', 'site', '', 5, 1773409302, 1773409302),
(19, 'maintenance_end', '', '预计恢复时间', 'text', 'site', '', 6, 1773409302, 1773409302),
(20, 'register_open', '1', '开启注册', 'radio', 'base', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 12, 1773409302, 1773409302),
(21, 'register_verify', '0', '注册验证', 'radio', 'base', '[{"label":"需要","value":"1"},{"label":"不需要","value":"0"}]', 13, 1773409302, 1773409302),
(22, 'register_phone_verify', '0', '手机号验证', 'radio', 'register', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 1, 1773409302, 1773409401),
(23, 'register_sms_verify', '0', '短信验证码验证', 'radio', 'register', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 2, 1773409302, 1773409401),
(24, 'register_captcha_verify', '0', '图形验证码验证', 'radio', 'register', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 3, 1773409302, 1773409401),
(25, 'upload_max_size', '10485760', '上传文件大小限制(字节)', 'number', 'base', '', 14, 1773409302, 1773409302),
(26, 'upload_allow_ext', 'jpg,jpeg,png,gif', '允许上传的文件扩展名', 'text', 'base', '', 15, 1773409302, 1773409302),
(27, 'allowed_types', 'jpg,jpeg,png,gif,mp4,mp3,zip,doc,docx,pdf', '允许上传的文件格式', 'text', 'upload', '', 1, 1773409302, 1773409302),
(28, 'storage_type', 'local', '存储方式', 'select', 'upload', '[{"label":"本地存储","value":"local"},{"label":"阿里云OSS","value":"oss"},{"label":"腾讯云COS","value":"cos"},{"label":"七牛云","value":"qiniu"}]', 2, 1773409302, 1773409302),
(29, 'image_compress_enabled', '0', '启用图片压缩', 'select', 'upload', '[{"label":"关闭","value":"0"},{"label":"开启","value":"1"}]', 3, 1773409302, 1773409302),
(30, 'image_compress_quality', '75', '压缩质量 (1-100)', 'number', 'upload', '', 4, 1773409302, 1773409302),
(31, 'image_max_width', '1920', '最大宽度 (像素)', 'number', 'upload', '', 5, 1773409302, 1773409302),
(32, 'image_max_height', '1080', '最大高度 (像素)', 'number', 'upload', '', 6, 1773409302, 1773409302),
(33, 'oss_access_key_id', '', 'OSS AccessKey ID', 'text', 'upload', '', 7, 1773409302, 1773409302),
(34, 'oss_access_key_secret', '', 'OSS AccessKey Secret', 'text', 'upload', '', 8, 1773409302, 1773409302),
(35, 'oss_bucket', '', 'OSS Bucket', 'text', 'upload', '', 9, 1773409302, 1773409302),
(36, 'oss_endpoint', '', 'OSS Endpoint', 'text', 'upload', '', 10, 1773409302, 1773409302),
(37, 'oss_domain', '', 'OSS Bucket域名', 'text', 'upload', '', 11, 1773409302, 1773409302),
(38, 'oss_directory', 'uploads', 'OSS 存储目录', 'text', 'upload', '', 12, 1773409302, 1773409302),
(39, 'cos_secret_id', '', 'COS SecretId', 'text', 'upload', '', 13, 1773409302, 1773409302),
(40, 'cos_secret_key', '', 'COS SecretKey', 'text', 'upload', '', 14, 1773409302, 1773409302),
(41, 'cos_bucket', '', 'COS Bucket', 'text', 'upload', '', 15, 1773409302, 1773409302),
(42, 'cos_region', '', 'COS Region', 'text', 'upload', '', 16, 1773409302, 1773409302),
(43, 'cos_domain', '', 'COS Bucket域名', 'text', 'upload', '', 17, 1773409302, 1773409302),
(44, 'cos_directory', 'uploads', 'COS 存储目录', 'text', 'upload', '', 18, 1773409302, 1773409302),
(45, 'qiniu_access_key', '', '七牛 AccessKey', 'text', 'upload', '', 19, 1773409302, 1773409302),
(46, 'qiniu_secret_key', '', '七牛 SecretKey', 'text', 'upload', '', 20, 1773409302, 1773409302),
(47, 'qiniu_bucket', '', '七牛 Bucket', 'text', 'upload', '', 21, 1773409302, 1773409302),
(48, 'qiniu_domain', '', '七牛 Domain', 'text', 'upload', '', 22, 1773409302, 1773409302),
(49, 'qiniu_directory', 'uploads', '七牛 存储目录', 'text', 'upload', '', 23, 1773409302, 1773409302),
(50, 'user_default_avatar', '', '用户默认头像', 'text', 'base', '', 16, 1773409302, 1773409302),
(51, 'user_default_nickname', '用户', '用户默认昵称', 'text', 'base', '', 17, 1773409302, 1773409302),
(52, 'comment_audit', '0', '评论审核', 'radio', 'base', '[{"label":"需要","value":"1"},{"label":"不需要","value":"0"}]', 18, 1773409302, 1773409302),
(53, 'moment_audit', '0', '动态审核', 'radio', 'base', '[{"label":"需要","value":"1"},{"label":"不需要","value":"0"}]', 19, 1773409302, 1773409302),
(54, 'post_max_words', '500', '单条动态最大文字数', 'number', 'publish', '', 1, 1773409302, 1773409302),
(55, 'post_max_images', '9', '最多上传图片数', 'number', 'publish', '', 2, 1773409302, 1773409302),
(56, 'post_max_video_size', '50', '视频文件大小限制(MB)', 'number', 'publish', '', 3, 1773409302, 1773409302),
(57, 'allow_text_only', '1', '允许发布纯文字', 'radio', 'publish', '[{"label":"允许","value":"1"},{"label":"禁止","value":"0"}]', 4, 1773409302, 1773409302),
(58, 'allow_image_only', '1', '允许发布纯图片', 'radio', 'publish', '[{"label":"允许","value":"1"},{"label":"禁止","value":"0"}]', 5, 1773409302, 1773409302),
(59, 'allow_video', '1', '允许发布视频', 'radio', 'publish', '[{"label":"允许","value":"1"},{"label":"禁止","value":"0"}]', 6, 1773409302, 1773409302),
(60, 'daily_post_limit', '50', '单用户单日最大发布数', 'number', 'publish', '', 7, 1773409302, 1773409302),
(61, 'repeat_post_interval', '10', '同内容重复发布间隔(分钟)', 'number', 'publish', '', 8, 1773409302, 1773409302),
(62, 'comment_max_words', '200', '评论文字数限制', 'number', 'publish', '', 9, 1773409302, 1773409302),
(63, 'allow_image_comments', '1', '允许带图片评论', 'radio', 'publish', '[{"label":"允许","value":"1"},{"label":"禁止","value":"0"}]', 10, 1773409302, 1773409302),
(64, 'allow_nested_comments', '1', '允许评论楼中楼', 'radio', 'publish', '[{"label":"允许","value":"1"},{"label":"禁止","value":"0"}]', 11, 1773409302, 1773409302),
(65, 'allow_likes', '1', '开启点赞功能', 'radio', 'publish', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 12, 1773409302, 1773409302),
(66, 'allow_anonymous_comments', '0', '允许匿名评论', 'radio', 'publish', '[{"label":"允许","value":"1"},{"label":"禁止","value":"0"}]', 13, 1773409302, 1773409302),
(67, 'daily_comment_limit', '200', '单用户单日最大评论数', 'number', 'publish', '', 14, 1773409302, 1773409302),
(68, 'post_moderation', '0', '动态审核', 'select', 'publish', '[{"label":"无需审核","value":"0"},{"label":"全部审核","value":"1"},{"label":"仅新用户审核","value":"2"}]', 15, 1773409302, 1773409302),
(69, 'comment_moderation', '0', '评论审核', 'select', 'publish', '[{"label":"无需审核","value":"0"},{"label":"全部审核","value":"1"}]', 16, 1773409302, 1773409302),
(70, 'sensitive_word_action', 'replace', '敏感词触发规则', 'select', 'publish', '[{"label":"替换为*","value":"replace"},{"label":"拒绝发布","value":"refuse"},{"label":"标记待审核","value":"moderate"}]', 17, 1773409302, 1773409302),
(71, 'post_default_sort', 'latest', '动态流默认排序', 'select', 'publish', '[{"label":"最新发布","value":"latest"},{"label":"热度排序","value":"popular"}]', 18, 1773409302, 1773409302),
(72, 'post_default_visibility', 'public', '默认动态可见范围', 'select', 'publish', '[{"label":"全部用户","value":"public"},{"label":"仅粉丝","value":"followers"},{"label":"仅自己","value":"private"}]', 19, 1773409302, 1773409302),
(73, 'post_edit_limit', '24', '用户可编辑动态时限(小时)', 'number', 'publish', '', 20, 1773409302, 1773409302),
(74, 'post_delete_limit', '24', '用户可删除动态时限(小时)', 'number', 'publish', '', 21, 1773409302, 1773409302),
(75, 'message_enabled', '1', '消息功能', 'radio', 'social', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 1, 1773409302, 1773409302),
(76, 'topic_enabled', '1', '话题功能', 'radio', 'social', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 2, 1773409302, 1773409302),
(77, 'follow_enabled', '1', '关注功能', 'radio', 'social', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 3, 1773409302, 1773409302),
(78, 'comment_enabled', '1', '评论功能', 'radio', 'social', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 4, 1773409302, 1773409302),
(79, 'like_enabled', '1', '点赞功能', 'radio', 'social', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 5, 1773409302, 1773409302),
(80, 'group_enabled', '1', '好友分组功能', 'radio', 'social', '[{"label":"开启","value":"1"},{"label":"关闭","value":"0"}]', 6, 1773409302, 1773409302);

-- --------------------------------------------------------

--
-- 表的结构 `sns_system_messages`
--

CREATE TABLE IF NOT EXISTS `sns_system_messages` (
  `id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '消息标题',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '消息内容',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'system' COMMENT '消息类型',
  `target_user` int DEFAULT '0' COMMENT '目标用户 0全部',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_task`
--

CREATE TABLE IF NOT EXISTS `sns_task` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '任务名称',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '任务描述',
  `points` int NOT NULL DEFAULT '0' COMMENT '任务积分',
  `daily_limit` int NOT NULL DEFAULT '1' COMMENT '每日完成次数限制',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '任务类型：daily, growth',
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fa-tasks' COMMENT '任务图标',
  `sort` int NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1-激活0-未激活',
  `create_time` int NOT NULL DEFAULT '0' COMMENT '创建时间',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任务表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_task_completion`
--

CREATE TABLE IF NOT EXISTS `sns_task_completion` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `task_id` int NOT NULL COMMENT '任务ID',
  `create_time` int NOT NULL DEFAULT '0' COMMENT '完成时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任务完成记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_task_record`
--

CREATE TABLE IF NOT EXISTS `sns_task_record` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `task_id` int NOT NULL COMMENT '任务ID',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1-已完成0-未完成',
  `points` int NOT NULL DEFAULT '0' COMMENT '获得积分',
  `create_time` int NOT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任务记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_theme_rules`
--

CREATE TABLE IF NOT EXISTS `sns_theme_rules` (
  `id` int NOT NULL,
  `rule_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '规则名称',
  `rule_type` tinyint DEFAULT '0' COMMENT '规则类型:0-主题使用权限,1-主题可见性',
  `rule_content` json DEFAULT NULL COMMENT '规则内容',
  `status` tinyint DEFAULT '1' COMMENT '规则状态-禁用,1-启用',
  `create_time` int DEFAULT NULL,
  `update_time` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='主题规则表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_theme_templates`
--

CREATE TABLE IF NOT EXISTS `sns_theme_templates` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '主题名称',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '主题描述',
  `type` tinyint DEFAULT '0' COMMENT '主题类型:0-官方主题,1-用户自定义主题',
  `style` json DEFAULT NULL COMMENT '主题样式配置',
  `config` json DEFAULT NULL COMMENT '主题配置参数',
  `status` tinyint DEFAULT '1' COMMENT '主题状态-禁用,1-启用',
  `is_default` tinyint DEFAULT '0' COMMENT '是否默认主题:0-否1-是',
  `create_time` int DEFAULT NULL,
  `update_time` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='主题模板表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_topics`
--

CREATE TABLE IF NOT EXISTS `sns_topics` (
  `id` int NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '话题名称',
  `description` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '话题描述',
  `cover` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '话题封面',
  `post_count` int DEFAULT '0' COMMENT '帖子数',
  `follower_count` int DEFAULT '0' COMMENT '关注人数',
  `is_hot` tinyint(1) DEFAULT '0' COMMENT '是否热门:0-否1-是',
  `sort` int DEFAULT '0' COMMENT '排序',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-正常,0-禁用',
  `sort_order` int DEFAULT '0' COMMENT '排序'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='话题表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_topic_follows`
--

CREATE TABLE IF NOT EXISTS `sns_topic_follows` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `topic_id` int NOT NULL COMMENT '话题ID',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='话题关注表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_uploads`
--

CREATE TABLE IF NOT EXISTS `sns_uploads` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文件URL',
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文件路径',
  `file_size` bigint DEFAULT NULL COMMENT '文件大小(字节)',
  `file_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '文件类型',
  `file_ext` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '文件扩展名',
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'MIME类型',
  `width` int DEFAULT NULL COMMENT '图片宽度',
  `height` int DEFAULT NULL COMMENT '图片高度',
  `create_time` int DEFAULT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件上传表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user`
--

CREATE TABLE IF NOT EXISTS `sns_user` (
  `id` int unsigned NOT NULL COMMENT '用户ID',
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '用户名',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '密码(bcrypt加密)',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '邮箱',
  `mobile` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '手机号码',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '真实姓名',
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '头像URL',
  `chat_background` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '聊天背景图',
  `chat_opacity` tinyint DEFAULT '90' COMMENT '聊天背景透明度(0-100)',
  `real_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '真实姓名',
  `gender` tinyint(1) DEFAULT '0' COMMENT '性别:0未知,1-男2-女',
  `birthday` date DEFAULT NULL COMMENT '生日',
  `bio` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '个人简介',
  `occupation` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '职业',
  `interests` text COLLATE utf8mb4_unicode_ci COMMENT '兴趣爱好JSON',
  `province` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '省份',
  `city` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '城市',
  `district` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '区县',
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '个人网址',
  `homeimg` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '主页背景图',
  `sign` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '个性签名',
  `card_background` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '名片背景图',
  `card_theme_color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '#1890ff' COMMENT '名片主题色',
  `card_layout` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'default' COMMENT '名片布局模板',
  `card_privacy` text COLLATE utf8mb4_unicode_ci COMMENT '隐私设置JSON',
  `card_stealth` tinyint(1) DEFAULT '0' COMMENT '隐身访问:0-否1-是',
  `vip_level` tinyint(1) DEFAULT '0' COMMENT 'VIP等级',
  `coins` int DEFAULT '0' COMMENT '金币数量',
  `experience` int DEFAULT '0' COMMENT '经验值',
  `level` int DEFAULT '1' COMMENT '用户等级',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态正常,0禁用',
  `is_online` tinyint(1) DEFAULT '0' COMMENT '是否在线:0离线,1在线',
  `last_heartbeat_time` int DEFAULT NULL COMMENT '最后心跳时间',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '更新时间',
  `last_login_time` int DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '最后登录IP',
  `register_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '注册IP',
  `banned_until` timestamp NULL DEFAULT NULL COMMENT '封禁截止时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',
  `device_info` text COLLATE utf8mb4_unicode_ci COMMENT '设备信息JSON',
  `regtime` int DEFAULT '0' COMMENT '注册时间',
  `regip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '注册IP',
  `logtime` int DEFAULT '0' COMMENT '最后登录时间',
  `logip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '最后登录IP',
  `can_speak` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否允许发言',
  `last_latitude` decimal(10,7) DEFAULT NULL COMMENT '最后位置纬度',
  `last_longitude` decimal(10,7) DEFAULT NULL COMMENT '最后位置经度',
  `last_city` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '最后位置城市',
  `last_location_time` int DEFAULT NULL COMMENT '最后位置更新时间',
  `post_count` int DEFAULT '0' COMMENT '帖子数量',
  `comment_count` int DEFAULT '0' COMMENT '评论数量',
  `like_count` int DEFAULT '0' COMMENT '点赞数量',
  `follow_count` int DEFAULT '0' COMMENT '关注数量',
  `follower_count` int DEFAULT '0' COMMENT '粉丝数量',
  `favorite_count` int DEFAULT '0' COMMENT '收藏数量'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户名';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_currency`
--

CREATE TABLE IF NOT EXISTS `sns_user_currency` (
  `id` int unsigned NOT NULL,
  `user_id` int DEFAULT NULL COMMENT '用户ID',
  `currency_id` int DEFAULT NULL COMMENT '货币类型ID',
  `amount` decimal(15,2) DEFAULT '0.00' COMMENT '数量',
  `freeze_amount` decimal(15,2) DEFAULT '0.00' COMMENT '冻结数量',
  `update_time` int DEFAULT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户货币表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_groups`
--

CREATE TABLE IF NOT EXISTS `sns_user_groups` (
  `id` int NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分组名称',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '分组描述',
  `is_system` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否系统分组',
  `sort` int NOT NULL DEFAULT '0' COMMENT '排序',
  `create_time` int NOT NULL COMMENT '创建时间',
  `update_time` int NOT NULL COMMENT '更新时间'
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户分组表';

--
-- 转存表中的数据 `sns_user_groups`
--

INSERT INTO `sns_user_groups` (`id`, `name`, `description`, `is_system`, `sort`, `create_time`, `update_time`) VALUES
(1, '默认分组', '系统默认分组', 1, 1, 1773409302, 1773409302),
(2, 'VIP用户', 'VIP会员用户分组', 1, 2, 1773409302, 1773409302),
(3, '管理员', '系统管理员分组', 1, 3, 1773409302, 1773409302),
(4, '活跃用户', '社区活跃用户', 1, 4, 1773409302, 1773409302),
(5, '大神用户', '社区资深大神', 1, 5, 1773409302, 1773409302),
(6, '新用户', '新注册用户', 1, 6, 1773409302, 1773409302);

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_group_relation`
--

CREATE TABLE IF NOT EXISTS `sns_user_group_relation` (
  `id` int unsigned NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `group_id` int unsigned NOT NULL COMMENT '分组ID',
  `create_time` int NOT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户分组关联表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_level`
--

CREATE TABLE IF NOT EXISTS `sns_user_level` (
  `id` int unsigned NOT NULL COMMENT 'ID',
  `level` int NOT NULL COMMENT '等级',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '等级名称',
  `required_points` int DEFAULT '0' COMMENT '所需积分',
  `icon` text COLLATE utf8mb4_unicode_ci COMMENT '等级图标',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '等级描述',
  `privileges` text COLLATE utf8mb4_unicode_ci COMMENT '等级特权(JSON格式)',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态启用0禁用',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间'
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户等级表';

--
-- 转存表中的数据 `sns_user_level`
--

INSERT INTO `sns_user_level` (`id`, `level`, `name`, `required_points`, `icon`, `description`, `privileges`, `status`, `create_time`, `update_time`) VALUES
(1, 1, '新手', 0, 'fas fa-seedling', '新注册用户', '["可以发布动态","可以关注用户","可以评论"]', 1, 1773409303, 1773409303),
(2, 2, '初级用户', 100, 'fas fa-leaf', '活跃参与社区', '["可以发布动态","可以关注用户","可以评论","可以点赞"]', 1, 1773409303, 1773409303),
(3, 3, '中级用户', 500, 'fas fa-tree', '社区活跃成员', '["可以发布动态","可以关注用户","可以评论","可以点赞","可以收藏"]', 1, 1773409303, 1773409303),
(4, 4, '高级用户', 2000, 'fas fa-star', '社区核心成员', '["可以发布动态","可以关注用户","可以评论","可以点赞","可以收藏","可以创建话题"]', 1, 1773409303, 1773409303),
(5, 5, '资深用户', 5000, 'fas fa-crown', '社区资深成员', '["可以发布动态","可以关注用户","可以评论","可以点赞","可以收藏","可以创建话题","可以创建群组"]', 1, 1773409303, 1773409303);

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_locations`
--

CREATE TABLE IF NOT EXISTS `sns_user_locations` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `latitude` decimal(10,7) NOT NULL COMMENT '纬度',
  `longitude` decimal(10,7) NOT NULL COMMENT '经度',
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '详细地址',
  `city` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '城市',
  `district` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '区县',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IP地址',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户位置记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_notification_settings`
--

CREATE TABLE IF NOT EXISTS `sns_user_notification_settings` (
  `id` int unsigned NOT NULL COMMENT 'ID',
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `email_notification` tinyint(1) DEFAULT '1' COMMENT '邮件通知:1-开启0-关闭',
  `sms_notification` tinyint(1) DEFAULT '0' COMMENT '短信通知:1-开启0-关闭',
  `push_notification` tinyint(1) DEFAULT '1' COMMENT '推送通知:1-开启0-关闭',
  `like_notification` tinyint(1) DEFAULT '1' COMMENT '点赞通知:1-开启0-关闭',
  `comment_notification` tinyint(1) DEFAULT '1' COMMENT '评论通知:1-开启0-关闭',
  `follow_notification` tinyint(1) DEFAULT '1' COMMENT '关注通知:1-开启0-关闭',
  `message_notification` tinyint(1) DEFAULT '1' COMMENT '私信通知:1-开启0-关闭',
  `system_notification` tinyint(1) DEFAULT '1' COMMENT '系统通知:1-开启0-关闭',
  `notification_sound` tinyint(1) DEFAULT '1' COMMENT '通知声音:1-开启0-关闭',
  `quiet_hours_start` varchar(5) DEFAULT '22:00' COMMENT '免打扰开始时间',
  `quiet_hours_end` varchar(5) DEFAULT '08:00' COMMENT '免打扰结束时间',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户通知设置表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_points`
--

CREATE TABLE IF NOT EXISTS `sns_user_points` (
  `id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `points` int NOT NULL DEFAULT '0' COMMENT '积分数量',
  `total_points` int NOT NULL DEFAULT '0' COMMENT '总积分',
  `available_points` int NOT NULL DEFAULT '0' COMMENT '可用积分',
  `frozen_points` int NOT NULL DEFAULT '0' COMMENT '冻结积分',
  `level` int NOT NULL DEFAULT '1' COMMENT '等级',
  `create_time` int NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int NOT NULL DEFAULT '0' COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户积分表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_profiles`
--

CREATE TABLE IF NOT EXISTS `sns_user_profiles` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `real_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '真实姓名',
  `id_card` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '身份证号',
  `education` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '学历',
  `occupation` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '职业',
  `company` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '公司',
  `income_range` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '收入范围',
  `hobby_tags` text COLLATE utf8mb4_unicode_ci COMMENT '兴趣爱好标签JSON',
  `signature` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '个性签名',
  `website` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '个人网站',
  `social_wechat` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '微信账号',
  `social_qq` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'QQ账号',
  `social_weibo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '微博',
  `background_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '主页背景图',
  `theme_preference` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'default' COMMENT '主题偏好',
  `privacy_settings` text COLLATE utf8mb4_unicode_ci COMMENT '隐私设置JSON',
  `notification_settings` text COLLATE utf8mb4_unicode_ci COMMENT '通知设置JSON',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` timestamp NULL DEFAULT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户详情表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_punishments`
--

CREATE TABLE IF NOT EXISTS `sns_user_punishments` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `violation_id` int NOT NULL COMMENT '关联违规记录ID',
  `punishment_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '惩罚类型:warning,ban_speak,ban_login,ban_forever',
  `punishment_reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '惩罚原因',
  `start_time` int NOT NULL COMMENT '惩罚开始时间',
  `end_time` int DEFAULT NULL COMMENT '惩罚结束时间(永久封禁为NULL)',
  `operator_id` int NOT NULL COMMENT '操作管理员ID',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-生效0-已过期2-已解除'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户惩罚记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_realname_auth`
--

CREATE TABLE IF NOT EXISTS `sns_user_realname_auth` (
  `id` int unsigned NOT NULL COMMENT 'ID',
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `real_name` varchar(50) NOT NULL COMMENT '真实姓名',
  `id_card` varchar(18) NOT NULL COMMENT '身份证号',
  `id_card_front` varchar(255) DEFAULT NULL COMMENT '身份证正面照片',
  `id_card_back` varchar(255) DEFAULT NULL COMMENT '身份证背面照片',
  `handheld_id_card` varchar(255) DEFAULT NULL COMMENT '手持身份证照片',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-待审核1-审核通过,2-审核拒绝',
  `reject_reason` varchar(500) DEFAULT NULL COMMENT '拒绝原因',
  `audit_time` int DEFAULT NULL COMMENT '审核时间',
  `auditor_id` int DEFAULT NULL COMMENT '审核人ID',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户实名认证表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_tags`
--

CREATE TABLE IF NOT EXISTS `sns_user_tags` (
  `id` int NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标签名称',
  `color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '#3B82F6' COMMENT '标签颜色',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '标签描述',
  `create_time` int NOT NULL COMMENT '创建时间',
  `update_time` int NOT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户标签表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_tag_relation`
--

CREATE TABLE IF NOT EXISTS `sns_user_tag_relation` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `tag_id` int NOT NULL COMMENT '标签ID',
  `create_time` int NOT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户标签关联表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_themes`
--

CREATE TABLE IF NOT EXISTS `sns_user_themes` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `theme_id` int NOT NULL COMMENT '主题ID',
  `custom_config` json DEFAULT NULL COMMENT '用户自定义主题配置',
  `create_time` int DEFAULT NULL,
  `update_time` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户主题表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_violations`
--

CREATE TABLE IF NOT EXISTS `sns_user_violations` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `violation_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '违规类型:spam,abuse,fraud等',
  `violation_reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '违规原因',
  `violation_content` text COLLATE utf8mb4_unicode_ci COMMENT '违规内容',
  `violation_time` int NOT NULL COMMENT '违规时间',
  `violation_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '违规IP',
  `operator_id` int NOT NULL COMMENT '操作管理员ID',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态0-待处理1-已处理'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户违规记录表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_vip`
--

CREATE TABLE IF NOT EXISTS `sns_user_vip` (
  `id` int unsigned NOT NULL,
  `user_id` int DEFAULT NULL COMMENT '用户ID',
  `level_id` int DEFAULT NULL COMMENT '等级ID',
  `start_time` int DEFAULT NULL COMMENT '开始时间',
  `end_time` int DEFAULT NULL COMMENT '结束时间',
  `is_permanent` int DEFAULT '0' COMMENT '是否永久',
  `auto_renew` int DEFAULT '0' COMMENT '是否自动续费',
  `create_time` int DEFAULT NULL COMMENT '开通时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户VIP表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_user_wallet`
--

CREATE TABLE IF NOT EXISTS `sns_user_wallet` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `balance` decimal(10,2) DEFAULT '0.00' COMMENT '余额',
  `frozen` decimal(10,2) DEFAULT '0.00' COMMENT '冻结金额',
  `total_income` decimal(10,2) DEFAULT '0.00' COMMENT '总收入',
  `total_expenditure` decimal(10,2) DEFAULT '0.00' COMMENT '总支出',
  `create_time` int DEFAULT '0',
  `update_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_versions`
--

CREATE TABLE IF NOT EXISTS `sns_versions` (
  `id` int NOT NULL,
  `version_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '版本号',
  `version_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '版本名称',
  `download_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '下载地址',
  `update_log` text COLLATE utf8mb4_unicode_ci COMMENT '更新日志',
  `file_size` bigint DEFAULT '0' COMMENT '文件大小',
  `force_update` tinyint(1) DEFAULT '0' COMMENT '是否强制更新',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-启用0-禁用',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_version_logs`
--

CREATE TABLE IF NOT EXISTS `sns_version_logs` (
  `id` int NOT NULL,
  `version_id` int NOT NULL COMMENT '版本ID',
  `log_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'update' COMMENT '日志类型',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '日志内容',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_vip_levels`
--

CREATE TABLE IF NOT EXISTS `sns_vip_levels` (
  `id` int unsigned NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '等级名称',
  `max_moments` int DEFAULT '10' COMMENT '每日最大发布数',
  `max_images` int DEFAULT '9' COMMENT '每次最大上传图片数',
  `price_month` decimal(10,2) DEFAULT NULL COMMENT '月费',
  `price_quarter` decimal(10,2) DEFAULT NULL COMMENT '季费',
  `price_year` decimal(10,2) DEFAULT NULL COMMENT '年费',
  `price_permanent` decimal(10,2) DEFAULT NULL COMMENT '永久费用',
  `privileges` text COLLATE utf8mb4_unicode_ci COMMENT '其他特权JSON',
  `sort` int DEFAULT '0' COMMENT '排序',
  `status` int DEFAULT '1' COMMENT '状态-禁用1-启用'
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VIP等级表';

--
-- 转存表中的数据 `sns_vip_levels`
--

INSERT INTO `sns_vip_levels` (`id`, `name`, `max_moments`, `max_images`, `price_month`, `price_quarter`, `price_year`, `price_permanent`, `privileges`, `sort`, `status`) VALUES
(1, '普通会员', 10, 9, '0.00', '0.00', '0.00', '0.00', '[]', 0, 1),
(2, 'VIP月卡', 20, 9, '9.90', '0.00', '0.00', '0.00', '["每日发布20条动态","专属标识"]', 1, 1),
(3, 'VIP季卡', 20, 9, '0.00', '29.90', '0.00', '0.00', '["每日发布20条动态","专属标识","优先推荐"]', 2, 1),
(4, 'VIP年卡', 50, 9, '0.00', '0.00', '99.90', '0.00', '["每日发布50条动态","专属标识","优先推荐","专属客服"]', 3, 1),
(5, 'VIP永久', 100, 9, '0.00', '0.00', '0.00', '299.90', '["每日发布100条动态","专属标识","优先推荐","专属客服","永久特权"]', 4, 1);

-- --------------------------------------------------------

--
-- 表的结构 `sns_vip_orders`
--

CREATE TABLE IF NOT EXISTS `sns_vip_orders` (
  `id` int unsigned NOT NULL,
  `order_no` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '订单号',
  `user_id` int DEFAULT NULL COMMENT '用户ID',
  `level_id` int DEFAULT NULL COMMENT '等级ID',
  `duration_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '时长类型 month/quarter/year/permanent',
  `amount` decimal(10,2) DEFAULT NULL COMMENT '支付金额',
  `pay_status` int DEFAULT '0' COMMENT '支付状态0-未支付1-已支付',
  `pay_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '支付方式',
  `pay_time` int DEFAULT NULL COMMENT '支付时间',
  `create_time` int DEFAULT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VIP订单表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_visitors`
--

CREATE TABLE IF NOT EXISTS `sns_visitors` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '被访问用户ID',
  `visitor_id` int NOT NULL COMMENT '访客用户ID',
  `visit_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '访问时间',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '访客IP地址',
  `user_agent` text COLLATE utf8mb4_unicode_ci COMMENT '访客浏览器信息'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='访客表';

-- --------------------------------------------------------

--
-- 表的结构 `sns_withdraw_records`
--

CREATE TABLE IF NOT EXISTS `sns_withdraw_records` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT '用户ID',
  `amount` decimal(10,2) NOT NULL COMMENT '提现金额',
  `fee` decimal(10,2) DEFAULT '0.00' COMMENT '手续费',
  `real_amount` decimal(10,2) DEFAULT '0.00' COMMENT '实际到账',
  `account_info` text COLLATE utf8mb4_unicode_ci COMMENT '账户信息',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-待审核1-已通过2-已拒绝',
  `remark` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '备注',
  `handle_time` int DEFAULT '0' COMMENT '处理时间',
  `create_time` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='提现记录表';

--
-- Indexes for dumped tables
--

--
-- Indexes for table `sns_activities`
--
ALTER TABLE `sns_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `is_hot` (`is_hot`),
  ADD KEY `start_time` (`start_time`),
  ADD KEY `organizer_id` (`organizer_id`);

--
-- Indexes for table `sns_activity_participants`
--
ALTER TABLE `sns_activity_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `activity_user` (`activity_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `participant_time` (`participant_time`);

--
-- Indexes for table `sns_admin`
--
ALTER TABLE `sns_admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `email` (`email`),
  ADD KEY `status` (`status`),
  ADD KEY `role` (`role`);

--
-- Indexes for table `sns_admin_log`
--
ALTER TABLE `sns_admin_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_announcements`
--
ALTER TABLE `sns_announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `is_publish` (`is_publish`),
  ADD KEY `publish_time` (`publish_time`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_api_calls`
--
ALTER TABLE `sns_api_calls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `api_key_id` (`api_key_id`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_api_keys`
--
ALTER TABLE `sns_api_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `access_key` (`access_key`);

--
-- Indexes for table `sns_articles`
--
ALTER TABLE `sns_articles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_category_id` (`category_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_publish_time` (`publish_time`);

--
-- Indexes for table `sns_article_categories`
--
ALTER TABLE `sns_article_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_parent_id` (`parent_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sort_order` (`sort_order`);

--
-- Indexes for table `sns_article_collections`
--
ALTER TABLE `sns_article_collections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_article_user` (`article_id`,`user_id`),
  ADD KEY `idx_article_id` (`article_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_folder_id` (`folder_id`);

--
-- Indexes for table `sns_article_comments`
--
ALTER TABLE `sns_article_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_article_id` (`article_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_parent_id` (`parent_id`);

--
-- Indexes for table `sns_article_likes`
--
ALTER TABLE `sns_article_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_article_user` (`article_id`,`user_id`),
  ADD KEY `idx_article_id` (`article_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `sns_article_logs`
--
ALTER TABLE `sns_article_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_article_id` (`article_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `sns_article_views`
--
ALTER TABLE `sns_article_views`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_article_id` (`article_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_create_time` (`create_time`);

--
-- Indexes for table `sns_authorizations`
--
ALTER TABLE `sns_authorizations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `license_number` (`license_number`),
  ADD KEY `software_id` (`software_id`),
  ADD KEY `domain` (`domain`);

--
-- Indexes for table `sns_blacklist`
--
ALTER TABLE `sns_blacklist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_block` (`user_id`,`block_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_blocked_users`
--
ALTER TABLE `sns_blocked_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_blocked` (`user_id`,`blocked_user_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `sns_call_records`
--
ALTER TABLE `sns_call_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_caller_id` (`caller_id`),
  ADD KEY `idx_callee_id` (`callee_id`),
  ADD KEY `idx_create_time` (`create_time`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_call_type` (`call_type`);

--
-- Indexes for table `sns_card_templates`
--
ALTER TABLE `sns_card_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `sns_card_visitors`
--
ALTER TABLE `sns_card_visitors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_time` (`user_id`,`visit_time`),
  ADD KEY `visitor_time` (`visitor_id`,`visit_time`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_visitor_id` (`visitor_id`);

--
-- Indexes for table `sns_categories`
--
ALTER TABLE `sns_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_chat_settings`
--
ALTER TABLE `sns_chat_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_chat` (`user_id`,`other_user_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `sns_collections`
--
ALTER TABLE `sns_collections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_moment` (`user_id`,`moment_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `moment_id` (`moment_id`);

--
-- Indexes for table `sns_comm`
--
ALTER TABLE `sns_comm`
  ADD PRIMARY KEY (`id`),
  ADD KEY `moment_id` (`moment_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_comments`
--
ALTER TABLE `sns_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `moment_id` (`moment_id`),
  ADD KEY `parent_id` (`parent_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_create_time` (`create_time`),
  ADD KEY `idx_likes` (`likes`),
  ADD KEY `idx_is_hot` (`is_hot`),
  ADD KEY `idx_is_top` (`is_top`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_moment_parent` (`moment_id`,`parent_id`),
  ADD KEY `idx_parent_id` (`parent_id`),
  ADD KEY `idx_moment_status_create` (`moment_id`,`status`,`create_time` DESC),
  ADD KEY `idx_parent_status_create` (`parent_id`,`status`,`create_time` DESC),
  ADD KEY `idx_user_status_create` (`user_id`,`status`,`create_time` DESC),
  ADD KEY `idx_moment_parent_status` (`moment_id`,`parent_id`,`status`);

--
-- Indexes for table `sns_comment_likes`
--
ALTER TABLE `sns_comment_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_comment` (`user_id`,`comment_id`),
  ADD KEY `comment_id` (`comment_id`);

--
-- Indexes for table `sns_configx`
--
ALTER TABLE `sns_configx`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_cron_jobs`
--
ALTER TABLE `sns_cron_jobs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_cron_records`
--
ALTER TABLE `sns_cron_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_id` (`job_id`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_currency_logs`
--
ALTER TABLE `sns_currency_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `currency_id` (`currency_id`),
  ADD KEY `type` (`type`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_currency_types`
--
ALTER TABLE `sns_currency_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `is_primary` (`is_primary`);

--
-- Indexes for table `sns_emojis`
--
ALTER TABLE `sns_emojis`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_category` (`category`);

--
-- Indexes for table `sns_emoji_usage`
--
ALTER TABLE `sns_emoji_usage`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_user_emoji` (`user_id`,`emoji_id`),
  ADD KEY `idx_use_time` (`use_time`);

--
-- Indexes for table `sns_error_log`
--
ALTER TABLE `sns_error_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_essay`
--
ALTER TABLE `sns_essay`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_faqs`
--
ALTER TABLE `sns_faqs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_faq_categories`
--
ALTER TABLE `sns_faq_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_favorites`
--
ALTER TABLE `sns_favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_target` (`user_id`,`target_id`,`target_type`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `target_id` (`target_id`),
  ADD KEY `target_type` (`target_type`),
  ADD KEY `folder_id` (`folder_id`),
  ADD KEY `create_time` (`create_time`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_user_type` (`user_id`,`target_type`),
  ADD KEY `idx_target` (`target_id`,`target_type`);

--
-- Indexes for table `sns_favorite_folders`
--
ALTER TABLE `sns_favorite_folders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `sort_order` (`sort_order`);

--
-- Indexes for table `sns_follows`
--
ALTER TABLE `sns_follows`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `follower_following` (`follower_id`,`following_id`),
  ADD KEY `following_id` (`following_id`),
  ADD KEY `idx_follower_id` (`follower_id`),
  ADD KEY `idx_following_id` (`following_id`),
  ADD KEY `idx_follower_following` (`follower_id`,`following_id`),
  ADD KEY `idx_follower_status` (`follower_id`,`status`,`create_time` DESC),
  ADD KEY `idx_following_status` (`following_id`,`status`,`create_time` DESC);

--
-- Indexes for table `sns_friend_groups`
--
ALTER TABLE `sns_friend_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_name` (`user_id`,`name`);

--
-- Indexes for table `sns_friend_group_members`
--
ALTER TABLE `sns_friend_group_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `group_user_friend` (`group_id`,`user_id`,`friend_id`),
  ADD KEY `user_friend` (`user_id`,`friend_id`);

--
-- Indexes for table `sns_hidden_moments`
--
ALTER TABLE `sns_hidden_moments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_moment` (`user_id`,`moment_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `moment_id` (`moment_id`);

--
-- Indexes for table `sns_hot_searches`
--
ALTER TABLE `sns_hot_searches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `keyword` (`keyword`),
  ADD KEY `search_count` (`search_count`),
  ADD KEY `today_count` (`today_count`),
  ADD KEY `is_hot` (`is_hot`),
  ADD KEY `rank` (`rank`);

--
-- Indexes for table `sns_likes`
--
ALTER TABLE `sns_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_target` (`user_id`,`target_id`,`target_type`),
  ADD UNIQUE KEY `idx_user_target` (`user_id`,`target_type`,`target_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `target_id` (`target_id`),
  ADD KEY `target_type` (`target_type`),
  ADD KEY `create_time` (`create_time`),
  ADD KEY `idx_target_id` (`target_id`),
  ADD KEY `idx_target_type` (`target_type`),
  ADD KEY `idx_target_id_type` (`target_id`,`target_type`),
  ADD KEY `idx_user_target_type` (`user_id`,`target_type`,`create_time` DESC),
  ADD KEY `idx_target_type_create` (`target_type`,`target_id`,`create_time` DESC);

--
-- Indexes for table `sns_link`
--
ALTER TABLE `sns_link`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_login_logs`
--
ALTER TABLE `sns_login_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `login_time` (`login_time`),
  ADD KEY `login_ip` (`login_ip`);

--
-- Indexes for table `sns_mentions`
--
ALTER TABLE `sns_mentions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `moment_id` (`moment_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `mentioned_user_id` (`mentioned_user_id`),
  ADD KEY `create_time` (`create_time`),
  ADD KEY `read_status` (`read_status`);

--
-- Indexes for table `sns_messages`
--
ALTER TABLE `sns_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `receiver_id` (`receiver_id`),
  ADD KEY `is_read` (`is_read`),
  ADD KEY `create_time` (`create_time`),
  ADD KEY `idx_reply_to` (`reply_to_id`),
  ADD KEY `idx_is_pinned` (`is_pinned`),
  ADD KEY `idx_is_recalled` (`is_recalled`),
  ADD KEY `idx_send_status` (`send_status`),
  ADD KEY `idx_sender_receiver` (`sender_id`,`receiver_id`),
  ADD KEY `idx_receiver_read` (`receiver_id`,`is_read`),
  ADD KEY `idx_create_time` (`create_time`),
  ADD KEY `idx_sender_receiver_time` (`sender_id`,`receiver_id`,`create_time`);

--
-- Indexes for table `sns_message_favorites`
--
ALTER TABLE `sns_message_favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_message` (`user_id`,`message_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `sns_message_templates`
--
ALTER TABLE `sns_message_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_migrations`
--
ALTER TABLE `sns_migrations`
  ADD PRIMARY KEY (`version`);

--
-- Indexes for table `sns_moments`
--
ALTER TABLE `sns_moments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `create_time` (`create_time`),
  ADD KEY `status` (`status`),
  ADD KEY `is_top` (`is_top`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_user_status_create` (`user_id`,`status`,`create_time` DESC),
  ADD KEY `idx_status_create_time` (`status`,`create_time` DESC),
  ADD KEY `idx_privacy_status` (`privacy`,`status`),
  ADD KEY `idx_type_status` (`type`,`status`),
  ADD KEY `idx_is_top_create_time` (`is_top`,`create_time` DESC),
  ADD KEY `idx_likes_comments` (`likes` DESC,`comments` DESC),
  ADD KEY `idx_publish_time` (`publish_time` DESC),
  ADD KEY `idx_user_status_publish` (`user_id`,`status`,`publish_time` DESC);

--
-- Indexes for table `sns_moment_drafts`
--
ALTER TABLE `sns_moment_drafts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `sns_moment_likes`
--
ALTER TABLE `sns_moment_likes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `moment_id` (`moment_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `sns_moment_topics`
--
ALTER TABLE `sns_moment_topics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `moment_topic` (`moment_id`,`topic_id`),
  ADD KEY `moment_id` (`moment_id`),
  ADD KEY `topic_id` (`topic_id`);

--
-- Indexes for table `sns_notifications`
--
ALTER TABLE `sns_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `type` (`type`),
  ADD KEY `is_read` (`is_read`),
  ADD KEY `create_time` (`create_time`),
  ADD KEY `user_read` (`user_id`,`is_read`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_create_time` (`create_time`);

--
-- Indexes for table `sns_operations`
--
ALTER TABLE `sns_operations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `start_time` (`start_time`),
  ADD KEY `end_time` (`end_time`),
  ADD KEY `creator_id` (`creator_id`);

--
-- Indexes for table `sns_operation_log`
--
ALTER TABLE `sns_operation_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_operation_participants`
--
ALTER TABLE `sns_operation_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `operation_user` (`operation_id`,`user_id`),
  ADD KEY `operation_id` (`operation_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `participant_time` (`participant_time`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `sns_operation_rewards`
--
ALTER TABLE `sns_operation_rewards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `operation_id` (`operation_id`),
  ADD KEY `type` (`type`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `sns_operation_reward_records`
--
ALTER TABLE `sns_operation_reward_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `operation_id` (`operation_id`),
  ADD KEY `reward_id` (`reward_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `sns_post_media`
--
ALTER TABLE `sns_post_media`
  ADD PRIMARY KEY (`id`),
  ADD KEY `post_id` (`post_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `media_type` (`media_type`),
  ADD KEY `create_time` (`create_time`),
  ADD KEY `post_id_sort` (`post_id`,`sort_order`);

--
-- Indexes for table `sns_push_records`
--
ALTER TABLE `sns_push_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_rate_limit_rules`
--
ALTER TABLE `sns_rate_limit_rules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_recharge_records`
--
ALTER TABLE `sns_recharge_records`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_reports`
--
ALTER TABLE `sns_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reporter_id` (`reporter_id`),
  ADD KEY `reported_user_id` (`reported_user_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `sns_search_history`
--
ALTER TABLE `sns_search_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `keyword` (`keyword`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_search_logs`
--
ALTER TABLE `sns_search_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `keyword` (`keyword`),
  ADD KEY `count` (`count`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_sessions`
--
ALTER TABLE `sns_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_id` (`session_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `sns_shares`
--
ALTER TABLE `sns_shares`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `target_id` (`target_id`),
  ADD KEY `target_type` (`target_type`),
  ADD KEY `share_type` (`share_type`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_slow_query_log`
--
ALTER TABLE `sns_slow_query_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `execute_time` (`execute_time`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_software`
--
ALTER TABLE `sns_software`
  ADD PRIMARY KEY (`id`),
  ADD KEY `software_code` (`software_code`);

--
-- Indexes for table `sns_storage_files`
--
ALTER TABLE `sns_storage_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `md5` (`md5`);

--
-- Indexes for table `sns_system_config`
--
ALTER TABLE `sns_system_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `config_key` (`config_key`),
  ADD KEY `config_group` (`config_group`),
  ADD KEY `sort` (`sort`);

--
-- Indexes for table `sns_system_messages`
--
ALTER TABLE `sns_system_messages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_task`
--
ALTER TABLE `sns_task`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_task_completion`
--
ALTER TABLE `sns_task_completion`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_task_record`
--
ALTER TABLE `sns_task_record`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `task_id` (`task_id`);

--
-- Indexes for table `sns_theme_rules`
--
ALTER TABLE `sns_theme_rules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_theme_templates`
--
ALTER TABLE `sns_theme_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_topics`
--
ALTER TABLE `sns_topics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `is_hot` (`is_hot`),
  ADD KEY `post_count` (`post_count`);

--
-- Indexes for table `sns_topic_follows`
--
ALTER TABLE `sns_topic_follows`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_topic` (`user_id`,`topic_id`),
  ADD KEY `topic_id` (`topic_id`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_uploads`
--
ALTER TABLE `sns_uploads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `file_type` (`file_type`);

--
-- Indexes for table `sns_user`
--
ALTER TABLE `sns_user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `email` (`email`),
  ADD KEY `mobile` (`mobile`),
  ADD KEY `nickname` (`nickname`),
  ADD KEY `status` (`status`),
  ADD KEY `level` (`level`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_nickname` (`nickname`),
  ADD KEY `is_online` (`is_online`),
  ADD KEY `last_heartbeat_time` (`last_heartbeat_time`);

--
-- Indexes for table `sns_user_currency`
--
ALTER TABLE `sns_user_currency`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_currency` (`user_id`,`currency_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `currency_id` (`currency_id`);

--
-- Indexes for table `sns_user_groups`
--
ALTER TABLE `sns_user_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `sns_user_group_relation`
--
ALTER TABLE `sns_user_group_relation`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_group` (`user_id`,`group_id`),
  ADD KEY `group_id` (`group_id`);

--
-- Indexes for table `sns_user_level`
--
ALTER TABLE `sns_user_level`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `level` (`level`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `sns_user_locations`
--
ALTER TABLE `sns_user_locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `latitude` (`latitude`),
  ADD KEY `longitude` (`longitude`),
  ADD KEY `city` (`city`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_user_notification_settings`
--
ALTER TABLE `sns_user_notification_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `sns_user_points`
--
ALTER TABLE `sns_user_points`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `sns_user_profiles`
--
ALTER TABLE `sns_user_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `real_name` (`real_name`),
  ADD KEY `occupation` (`occupation`);

--
-- Indexes for table `sns_user_punishments`
--
ALTER TABLE `sns_user_punishments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `violation_id` (`violation_id`),
  ADD KEY `punishment_type` (`punishment_type`),
  ADD KEY `status` (`status`),
  ADD KEY `start_time` (`start_time`),
  ADD KEY `end_time` (`end_time`);

--
-- Indexes for table `sns_user_realname_auth`
--
ALTER TABLE `sns_user_realname_auth`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_id_card` (`id_card`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `sns_user_tags`
--
ALTER TABLE `sns_user_tags`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_user_tag_relation`
--
ALTER TABLE `sns_user_tag_relation`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `tag_id` (`tag_id`);

--
-- Indexes for table `sns_user_themes`
--
ALTER TABLE `sns_user_themes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `sns_user_violations`
--
ALTER TABLE `sns_user_violations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `violation_type` (`violation_type`),
  ADD KEY `status` (`status`),
  ADD KEY `violation_time` (`violation_time`);

--
-- Indexes for table `sns_user_vip`
--
ALTER TABLE `sns_user_vip`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `end_time` (`end_time`);

--
-- Indexes for table `sns_user_wallet`
--
ALTER TABLE `sns_user_wallet`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `sns_versions`
--
ALTER TABLE `sns_versions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `version_code` (`version_code`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `sns_version_logs`
--
ALTER TABLE `sns_version_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `version_id` (`version_id`),
  ADD KEY `create_time` (`create_time`);

--
-- Indexes for table `sns_vip_levels`
--
ALTER TABLE `sns_vip_levels`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sns_vip_orders`
--
ALTER TABLE `sns_vip_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_no` (`order_no`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `sns_visitors`
--
ALTER TABLE `sns_visitors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `visitor_id` (`visitor_id`),
  ADD KEY `visit_time` (`visit_time`);

--
-- Indexes for table `sns_withdraw_records`
--
ALTER TABLE `sns_withdraw_records`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `sns_activities`
--
ALTER TABLE `sns_activities`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_activity_participants`
--
ALTER TABLE `sns_activity_participants`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_admin`
--
ALTER TABLE `sns_admin`
  MODIFY `id` int NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=2;
--
-- AUTO_INCREMENT for table `sns_admin_log`
--
ALTER TABLE `sns_admin_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=2;
--
-- AUTO_INCREMENT for table `sns_announcements`
--
ALTER TABLE `sns_announcements`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_api_calls`
--
ALTER TABLE `sns_api_calls`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_api_keys`
--
ALTER TABLE `sns_api_keys`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_articles`
--
ALTER TABLE `sns_articles`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_article_categories`
--
ALTER TABLE `sns_article_categories`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_article_collections`
--
ALTER TABLE `sns_article_collections`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_article_comments`
--
ALTER TABLE `sns_article_comments`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_article_likes`
--
ALTER TABLE `sns_article_likes`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_article_logs`
--
ALTER TABLE `sns_article_logs`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_article_views`
--
ALTER TABLE `sns_article_views`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_authorizations`
--
ALTER TABLE `sns_authorizations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_blacklist`
--
ALTER TABLE `sns_blacklist`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_blocked_users`
--
ALTER TABLE `sns_blocked_users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_call_records`
--
ALTER TABLE `sns_call_records`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '通话记录ID';
--
-- AUTO_INCREMENT for table `sns_card_templates`
--
ALTER TABLE `sns_card_templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=11;
--
-- AUTO_INCREMENT for table `sns_card_visitors`
--
ALTER TABLE `sns_card_visitors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_categories`
--
ALTER TABLE `sns_categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_chat_settings`
--
ALTER TABLE `sns_chat_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_collections`
--
ALTER TABLE `sns_collections`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_comm`
--
ALTER TABLE `sns_comm`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_comments`
--
ALTER TABLE `sns_comments`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_comment_likes`
--
ALTER TABLE `sns_comment_likes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_configx`
--
ALTER TABLE `sns_configx`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_cron_jobs`
--
ALTER TABLE `sns_cron_jobs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_cron_records`
--
ALTER TABLE `sns_cron_records`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_currency_logs`
--
ALTER TABLE `sns_currency_logs`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_currency_types`
--
ALTER TABLE `sns_currency_types`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=4;
--
-- AUTO_INCREMENT for table `sns_emojis`
--
ALTER TABLE `sns_emojis`
  MODIFY `id` int NOT NULL AUTO_INCREMENT COMMENT '表情ID',AUTO_INCREMENT=101;
--
-- AUTO_INCREMENT for table `sns_emoji_usage`
--
ALTER TABLE `sns_emoji_usage`
  MODIFY `id` int NOT NULL AUTO_INCREMENT COMMENT '记录ID';
--
-- AUTO_INCREMENT for table `sns_error_log`
--
ALTER TABLE `sns_error_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_essay`
--
ALTER TABLE `sns_essay`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_faqs`
--
ALTER TABLE `sns_faqs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_faq_categories`
--
ALTER TABLE `sns_faq_categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_favorites`
--
ALTER TABLE `sns_favorites`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_favorite_folders`
--
ALTER TABLE `sns_favorite_folders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_follows`
--
ALTER TABLE `sns_follows`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_friend_groups`
--
ALTER TABLE `sns_friend_groups`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_friend_group_members`
--
ALTER TABLE `sns_friend_group_members`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_hidden_moments`
--
ALTER TABLE `sns_hidden_moments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_hot_searches`
--
ALTER TABLE `sns_hot_searches`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_likes`
--
ALTER TABLE `sns_likes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_link`
--
ALTER TABLE `sns_link`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_login_logs`
--
ALTER TABLE `sns_login_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_mentions`
--
ALTER TABLE `sns_mentions`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_messages`
--
ALTER TABLE `sns_messages`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_message_favorites`
--
ALTER TABLE `sns_message_favorites`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_message_templates`
--
ALTER TABLE `sns_message_templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_moments`
--
ALTER TABLE `sns_moments`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_moment_drafts`
--
ALTER TABLE `sns_moment_drafts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT COMMENT '草稿ID';
--
-- AUTO_INCREMENT for table `sns_moment_likes`
--
ALTER TABLE `sns_moment_likes`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_moment_topics`
--
ALTER TABLE `sns_moment_topics`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_notifications`
--
ALTER TABLE `sns_notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_operations`
--
ALTER TABLE `sns_operations`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_operation_log`
--
ALTER TABLE `sns_operation_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_operation_participants`
--
ALTER TABLE `sns_operation_participants`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_operation_rewards`
--
ALTER TABLE `sns_operation_rewards`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_operation_reward_records`
--
ALTER TABLE `sns_operation_reward_records`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_post_media`
--
ALTER TABLE `sns_post_media`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_push_records`
--
ALTER TABLE `sns_push_records`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_rate_limit_rules`
--
ALTER TABLE `sns_rate_limit_rules`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_recharge_records`
--
ALTER TABLE `sns_recharge_records`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_reports`
--
ALTER TABLE `sns_reports`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_search_history`
--
ALTER TABLE `sns_search_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_search_logs`
--
ALTER TABLE `sns_search_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_sessions`
--
ALTER TABLE `sns_sessions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_shares`
--
ALTER TABLE `sns_shares`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_slow_query_log`
--
ALTER TABLE `sns_slow_query_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_software`
--
ALTER TABLE `sns_software`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_storage_files`
--
ALTER TABLE `sns_storage_files`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_system_config`
--
ALTER TABLE `sns_system_config`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=81;
--
-- AUTO_INCREMENT for table `sns_system_messages`
--
ALTER TABLE `sns_system_messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_task`
--
ALTER TABLE `sns_task`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_task_completion`
--
ALTER TABLE `sns_task_completion`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_task_record`
--
ALTER TABLE `sns_task_record`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_theme_rules`
--
ALTER TABLE `sns_theme_rules`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_theme_templates`
--
ALTER TABLE `sns_theme_templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_topics`
--
ALTER TABLE `sns_topics`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_topic_follows`
--
ALTER TABLE `sns_topic_follows`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_uploads`
--
ALTER TABLE `sns_uploads`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user`
--
ALTER TABLE `sns_user`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID';
--
-- AUTO_INCREMENT for table `sns_user_currency`
--
ALTER TABLE `sns_user_currency`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user_groups`
--
ALTER TABLE `sns_user_groups`
  MODIFY `id` int NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=7;
--
-- AUTO_INCREMENT for table `sns_user_group_relation`
--
ALTER TABLE `sns_user_group_relation`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user_level`
--
ALTER TABLE `sns_user_level`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',AUTO_INCREMENT=6;
--
-- AUTO_INCREMENT for table `sns_user_locations`
--
ALTER TABLE `sns_user_locations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user_notification_settings`
--
ALTER TABLE `sns_user_notification_settings`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID';
--
-- AUTO_INCREMENT for table `sns_user_points`
--
ALTER TABLE `sns_user_points`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user_profiles`
--
ALTER TABLE `sns_user_profiles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user_punishments`
--
ALTER TABLE `sns_user_punishments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user_realname_auth`
--
ALTER TABLE `sns_user_realname_auth`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID';
--
-- AUTO_INCREMENT for table `sns_user_tags`
--
ALTER TABLE `sns_user_tags`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user_tag_relation`
--
ALTER TABLE `sns_user_tag_relation`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user_themes`
--
ALTER TABLE `sns_user_themes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user_violations`
--
ALTER TABLE `sns_user_violations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user_vip`
--
ALTER TABLE `sns_user_vip`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_user_wallet`
--
ALTER TABLE `sns_user_wallet`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_versions`
--
ALTER TABLE `sns_versions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_version_logs`
--
ALTER TABLE `sns_version_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_vip_levels`
--
ALTER TABLE `sns_vip_levels`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=6;
--
-- AUTO_INCREMENT for table `sns_vip_orders`
--
ALTER TABLE `sns_vip_orders`
  MODIFY `id` int unsigned NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_visitors`
--
ALTER TABLE `sns_visitors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `sns_withdraw_records`
--
ALTER TABLE `sns_withdraw_records`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
