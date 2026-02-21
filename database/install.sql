/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;



DROP TABLE IF EXISTS `comment_list`;





DROP TABLE IF EXISTS `withdraw_records`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `withdraw_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `amount` decimal(10,2) NOT NULL COMMENT '提现金额',
  `fee` decimal(10,2) DEFAULT '0.00' COMMENT '手续费',
  `real_amount` decimal(10,2) DEFAULT '0.00' COMMENT '实际到账',
  `account_info` text COMMENT '账户信息',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-待审核1-已通过2-已拒绝',
  `remark` varchar(500) DEFAULT '' COMMENT '备注',
  `handle_time` int(11) DEFAULT '0' COMMENT '处理时间',
  `create_time` int(11) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='提现记录表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `activities`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `activities` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `title` varchar(100) NOT NULL COMMENT '活动标题',

  `content` text NOT NULL COMMENT '活动内容',

  `cover_image` varchar(255) NOT NULL COMMENT '活动封面图片',

  `type` tinyint(4) NOT NULL DEFAULT '1' COMMENT '活动类型:1-线上活动,2-线下活动',

  `start_time` int(11) NOT NULL COMMENT '活动开始时间',

  `end_time` int(11) NOT NULL COMMENT '活动结束时间',

  `location` varchar(255) DEFAULT '' COMMENT '活动地点(线下活动必填)',

  `organizer_id` int(11) NOT NULL COMMENT '活动组织者ID',

  `organizer_name` varchar(50) NOT NULL COMMENT '活动组织者名称',

  `participant_count` int(11) NOT NULL DEFAULT '0' COMMENT '参与人数',

  `max_participants` int(11) DEFAULT '0' COMMENT '最大参与人数(0表示无限)',

  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '活动状态0-未发布1-进行中2-已结束3-已取消',

  `is_hot` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否热门活动:0-否1-是',

  `sort` tinyint(4) NOT NULL DEFAULT '0' COMMENT '排序',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  `update_time` int(11) NOT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`),

  KEY `status` (`status`),

  KEY `is_hot` (`is_hot`),

  KEY `start_time` (`start_time`),

  KEY `organizer_id` (`organizer_id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `activity_participants`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `activity_participants` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `activity_id` int(11) unsigned NOT NULL COMMENT '活动ID',

  `user_id` int(11) NOT NULL COMMENT '参与用户ID',

  `nickname` varchar(50) NOT NULL COMMENT '参与用户昵称',

  `avatar` varchar(255) DEFAULT '' COMMENT '参与用户头像',

  `participant_time` int(11) NOT NULL COMMENT '参与时间',

  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '参与状态1-已参与2-已取消',

  PRIMARY KEY (`id`),

  UNIQUE KEY `activity_user` (`activity_id`,`user_id`),

  KEY `user_id` (`user_id`),

  KEY `participant_time` (`participant_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动参与表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `admin`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `admin` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `username` varchar(50) NOT NULL DEFAULT '' COMMENT '管理员用户名',

  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '管理员密码(bcrypt加密)',

  `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '管理员昵称',

  `email` varchar(100) NOT NULL DEFAULT '' COMMENT '管理员邮箱',

  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '管理员头像',

  `role` tinyint(1) NOT NULL DEFAULT '1' COMMENT '管理员角色1-超级管理员2-普通管理员',

  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-正常,0-禁用',

  `last_login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',

  `last_login_time` int(11) NOT NULL DEFAULT '0' COMMENT '最后登录时间',

  `login_count` int(11) NOT NULL DEFAULT '0' COMMENT '登录次数',

  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',

  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',

  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `username` (`username`),

  KEY `email` (`email`),

  KEY `status` (`status`),

  KEY `role` (`role`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统管理员表';
/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `admin_log`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `admin_log` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `admin_id` int(11) NOT NULL,

  `username` varchar(50) NOT NULL,

  `action` varchar(100) NOT NULL COMMENT '操作',

  `ip` varchar(45) NOT NULL,

  `create_time` int(11) NOT NULL,

  PRIMARY KEY (`id`),

  KEY `admin_id` (`admin_id`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员操作日志表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `announcements`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `announcements` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `title` varchar(255) DEFAULT NULL COMMENT '公告标题',

  `content` text COMMENT '公告内容',

  `status` int(1) DEFAULT '1' COMMENT '状态0-禁用,1-启用',

  `is_publish` int(1) DEFAULT '0' COMMENT '是否发布:0-未发布1-已发布',

  `publish_time` int(11) DEFAULT '0' COMMENT '发布时间',

  `expire_time` int(11) DEFAULT '0' COMMENT '过期时间',

  `is_popup` int(1) DEFAULT '1' COMMENT '是否弹窗:0-否1-是',

  `click_count` int(11) DEFAULT '0' COMMENT '点击次数',

  `create_time` int(11) DEFAULT '0' COMMENT '创建时间',

  `update_time` int(11) DEFAULT '0' COMMENT '更新时间',

  `admin_id` int(11) DEFAULT '0' COMMENT '发布管理员ID',

  PRIMARY KEY (`id`),

  KEY `status` (`status`),

  KEY `is_publish` (`is_publish`),

  KEY `publish_time` (`publish_time`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统公告表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `api_calls`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `api_calls` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `api_key_id` int(11) NOT NULL COMMENT 'API密钥ID',

  `method` varchar(10) DEFAULT '' COMMENT '请求方法',

  `endpoint` varchar(255) DEFAULT '' COMMENT '接口地址',

  `params` text COMMENT '请求参数',

  `response` text COMMENT '响应结果',

  `status_code` int(11) DEFAULT '0' COMMENT '状态码',

  `execute_time` decimal(10,4) DEFAULT '0.0000' COMMENT '执行时间(秒)',

  `ip` varchar(50) DEFAULT '' COMMENT 'IP地址',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  KEY `api_key_id` (`api_key_id`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API调用记录表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `api_keys`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `api_keys` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(100) NOT NULL COMMENT '密钥名称',

  `access_key` varchar(100) NOT NULL COMMENT 'Access Key',

  `secret_key` varchar(100) NOT NULL COMMENT 'Secret Key',

  `permissions` varchar(500) DEFAULT '' COMMENT '权限列表',

  `ip_whitelist` varchar(500) DEFAULT '' COMMENT 'IP白名单',

  `rate_limit` int(11) DEFAULT '1000' COMMENT '限流次数/小时',

  `status` tinyint(1) DEFAULT '1',

  `expire_time` int(11) DEFAULT '0' COMMENT '过期时间 0永不过期',

  `create_time` int(11) DEFAULT '0',

  `update_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  UNIQUE KEY `access_key` (`access_key`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API密钥表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `blacklist`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `blacklist` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `block_id` int(11) NOT NULL COMMENT '被拉黑用户ID',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_block` (`user_id`,`block_id`),

  KEY `user_id` (`user_id`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='黑名单表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `blocked_users`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `blocked_users` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `blocked_user_id` int(11) NOT NULL COMMENT '被屏蔽的用户ID',

  `reason` varchar(255) DEFAULT NULL COMMENT '屏蔽原因',

  `create_time` int(11) NOT NULL DEFAULT '0',

  PRIMARY KEY (`id`),

  UNIQUE KEY `uk_user_blocked` (`user_id`,`blocked_user_id`),

  KEY `idx_user` (`user_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='屏蔽用户表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `card_templates`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `card_templates` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(50) NOT NULL COMMENT '模板名称',

  `preview` varchar(255) DEFAULT NULL COMMENT '预览图',

  `is_system` tinyint(1) DEFAULT '1' COMMENT '是否系统模板:1-是0-否',

  `is_free` tinyint(1) DEFAULT '1' COMMENT '是否免费:1-是0-否',

  `price` decimal(10,2) DEFAULT '0.00' COMMENT '价格',

  `creator_id` int(11) DEFAULT NULL COMMENT '创建者ID',

  `sort` int(11) DEFAULT '0' COMMENT '排序',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-启用,0-禁用',

  `create_time` int(11) DEFAULT NULL,

  PRIMARY KEY (`id`),

  KEY `status` (`status`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='名片模板表';

LOCK TABLES `card_templates` WRITE;
INSERT INTO `card_templates` (`name`, `preview`, `is_system`, `is_free`, `price`, `creator_id`, `sort`, `status`, `create_time`) VALUES
('简约白', '/static/card_templates/simple-white.jpg', 1, 1, 0.00, NULL, 1, 1, UNIX_TIMESTAMP()),
('简约蓝', '/static/card_templates/simple-blue.jpg', 1, 1, 0.00, NULL, 2, 1, UNIX_TIMESTAMP()),
('商务黑', '/static/card_templates/business-black.jpg', 1, 1, 0.00, NULL, 3, 1, UNIX_TIMESTAMP()),
('清新绿', '/static/card_templates/fresh-green.jpg', 1, 1, 0.00, NULL, 4, 1, UNIX_TIMESTAMP()),
('暖阳橙', '/static/card_templates/warm-orange.jpg', 1, 1, 0.00, NULL, 5, 1, UNIX_TIMESTAMP()),
('少女粉', '/static/card_templates/pink.jpg', 1, 1, 0.00, NULL, 6, 1, UNIX_TIMESTAMP()),
('星空紫', '/static/card_templates/starry-purple.jpg', 1, 0, 19.90, NULL, 7, 1, UNIX_TIMESTAMP()),
('渐变蓝紫', '/static/card_templates/gradient-blue-purple.jpg', 1, 0, 29.90, NULL, 8, 1, UNIX_TIMESTAMP()),
('金色尊享', '/static/card_templates/gold-premium.jpg', 1, 0, 99.90, NULL, 9, 1, UNIX_TIMESTAMP()),
('钻石闪耀', '/static/card_templates/diamond-shine.jpg', 1, 0, 199.90, NULL, 10, 1, UNIX_TIMESTAMP());
UNLOCK TABLES;

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `card_visitors`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `card_visitors` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '被访问用户ID',

  `visitor_id` int(11) NOT NULL COMMENT '访客ID',

  `visit_time` int(11) NOT NULL COMMENT '访问时间',

  `ip` varchar(45) DEFAULT NULL COMMENT '访客IP',

  `user_agent` text COMMENT '用户代理信息',

  PRIMARY KEY (`id`),

  KEY `user_time` (`user_id`,`visit_time`),

  KEY `visitor_time` (`visitor_id`,`visit_time`),

  KEY `idx_user_id` (`user_id`),

  KEY `idx_visitor_id` (`visitor_id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='名片访客记录表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `categories`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `categories` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(100) NOT NULL COMMENT '分类名称',

  `description` text COMMENT '分类描述',

  `icon` varchar(255) DEFAULT '' COMMENT '分类图标',

  `sort_order` int(11) DEFAULT '0' COMMENT '排序',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-启用0-禁用',

  `create_time` int(11) DEFAULT '0',

  `update_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='错误日志表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `chat_settings`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `chat_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `other_user_id` int(11) NOT NULL COMMENT '对方用户ID',

  `is_muted` tinyint(1) DEFAULT '0' COMMENT '是否免打扰',

  `is_pinned` tinyint(1) DEFAULT '0' COMMENT '会话是否置顶',

  `create_time` int(11) NOT NULL DEFAULT '0',

  `update_time` int(11) NOT NULL DEFAULT '0',

  PRIMARY KEY (`id`),

  UNIQUE KEY `uk_user_chat` (`user_id`,`other_user_id`),

  KEY `idx_user` (`user_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='聊天设置表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `collections`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `collections` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `moment_id` int(11) NOT NULL COMMENT '动态ID',

  `create_time` int(11) NOT NULL COMMENT '收藏时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_moment` (`user_id`,`moment_id`),

  KEY `user_id` (`user_id`),

  KEY `moment_id` (`moment_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态收藏表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `comm`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `comm` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `moment_id` int(11) NOT NULL COMMENT '动态ID',

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `nickname` varchar(50) DEFAULT NULL COMMENT '用户昵称',

  `avatar` varchar(255) DEFAULT NULL COMMENT '用户头像',

  `content` text NOT NULL COMMENT '评论内容',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-正常,0-隐藏',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  `update_time` int(11) DEFAULT NULL COMMENT '更新时间',

  `parent_id` int(11) DEFAULT '0' COMMENT '父评论ID(用于回复)',

  `reply_to_user_id` int(11) DEFAULT NULL COMMENT '回复的用户ID',

  `like_count` int(11) DEFAULT '0' COMMENT '点赞数',

  `reply_count` int(11) DEFAULT '0' COMMENT '回复数',

  `floor_number` int(11) DEFAULT '0' COMMENT '楼层号',

  `is_top` tinyint(1) DEFAULT '0' COMMENT '是否置顶',

  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',

  PRIMARY KEY (`id`),

  KEY `moment_id` (`moment_id`),

  KEY `user_id` (`user_id`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='评论表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `comment_likes`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `comment_likes` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `comment_id` int(11) NOT NULL COMMENT '评论ID',

  `create_time` int(11) NOT NULL COMMENT '点赞时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_comment` (`user_id`,`comment_id`),

  KEY `comment_id` (`comment_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='评论点赞表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `comments`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `comments` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `moment_id` int(11) NOT NULL COMMENT '动态ID',

  `parent_id` int(11) NOT NULL DEFAULT '0' COMMENT '父评论ID,0表示顶级评论',

  `nickname` varchar(50) NOT NULL COMMENT '用户昵称',

  `avatar` varchar(255) DEFAULT NULL COMMENT '用户头像',

  `content` text NOT NULL COMMENT '评论内容',

  `likes` int(11) NOT NULL DEFAULT '0' COMMENT '点赞数',

  `replies` int(11) NOT NULL DEFAULT '0' COMMENT '回复数',

  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-正常,0-删除',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  `is_top` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否置顶:0-否1-仅一级评论',

  `is_hot` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否热评:0-否1-是',

  `is_author` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否作者评论0-否1-是',

  `reply_to_user_id` int(11) DEFAULT NULL COMMENT '用户ID',

  `reply_to_nickname` varchar(50) DEFAULT NULL COMMENT '用户昵称',

  `media` varchar(500) DEFAULT NULL COMMENT '媒体资源(图片/表情)多个用逗号分隔',

  `update_time` int(11) DEFAULT NULL COMMENT '更新时间',

  `comment_status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '评论状态0-关闭评论1-开启评论',

  `top_comment_id` int(11) DEFAULT NULL COMMENT '置顶评论ID',

  `comments_count` int(11) NOT NULL DEFAULT '0' COMMENT '评论数',

  PRIMARY KEY (`id`),

  KEY `moment_id` (`moment_id`),

  KEY `parent_id` (`parent_id`),

  KEY `user_id` (`user_id`),

  KEY `idx_create_time` (`create_time`),

  KEY `idx_likes` (`likes`),

  KEY `idx_is_hot` (`is_hot`),

  KEY `idx_is_top` (`is_top`),

  KEY `idx_status` (`status`),

  KEY `idx_moment_parent` (`moment_id`,`parent_id`),

  KEY `idx_parent_id` (`parent_id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态评论表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `configx`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `configx` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `title` text COMMENT '配置名称',

  `text` text COMMENT '配置信息',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='配置表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `cron_jobs`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `cron_jobs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(100) NOT NULL COMMENT '任务名称',

  `expression` varchar(100) NOT NULL COMMENT 'cron表达式',

  `command` varchar(255) NOT NULL COMMENT '执行命令',

  `description` text COMMENT '任务描述',

  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-禁用1-启用',

  `last_run_time` int(11) DEFAULT '0' COMMENT '上次运行时间',

  `next_run_time` int(11) DEFAULT '0' COMMENT '下次运行时间',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定时任务表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `cron_records`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `cron_records` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `job_id` int(11) NOT NULL COMMENT '任务ID',

  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-失败1-成功',

  `output` text COMMENT '输出内容',

  `error_msg` text COMMENT '错误信息',

  `execute_time` decimal(10,4) DEFAULT '0.0000' COMMENT '执行时间(秒)',

  `start_time` int(11) DEFAULT '0' COMMENT '开始时间',

  `end_time` int(11) DEFAULT '0' COMMENT '结束时间',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  KEY `job_id` (`job_id`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定时任务记录表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `currency_logs`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `currency_logs` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `user_id` int(11) DEFAULT NULL COMMENT '用户ID',

  `currency_id` int(11) DEFAULT NULL COMMENT '货币类型ID',

  `amount` decimal(15,2) DEFAULT NULL COMMENT '变动数量',

  `before_amount` decimal(15,2) DEFAULT NULL COMMENT '变动前数量',

  `after_amount` decimal(15,2) DEFAULT NULL COMMENT '变动后数量',

  `type` varchar(50) DEFAULT NULL COMMENT '变动类型:recharge,withdraw,reward,consume',

  `remark` text COMMENT '备注',

  `source_id` int(11) DEFAULT NULL COMMENT '关联ID',

  `source_type` varchar(50) DEFAULT NULL COMMENT '关联类型',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `currency_id` (`currency_id`),

  KEY `type` (`type`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='货币日志表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `currency_types`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `currency_types` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `name` varchar(50) DEFAULT NULL COMMENT '货币名称',

  `symbol` varchar(10) DEFAULT NULL COMMENT '货币符号',

  `description` text COMMENT '货币描述',

  `is_primary` int(1) DEFAULT '0' COMMENT '是否主要货币',

  `sort` int(11) DEFAULT '0' COMMENT '排序',

  `status` int(1) DEFAULT '1' COMMENT '状态0-禁用1-启用',

  PRIMARY KEY (`id`),

  KEY `is_primary` (`is_primary`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='货币类型表';

LOCK TABLES `currency_types` WRITE;
INSERT INTO `currency_types` (`name`, `symbol`, `description`, `is_primary`, `sort`, `status`) VALUES
('积分', '积分', '社区积分，通过签到、发帖、评论等活动获得', 1, 1, 1),
('金币', '金币', '虚拟金币，用于购买VIP、打赏等消费', 0, 2, 1),
('钻石', '钻石', '高级货币，可用于购买稀有道具', 0, 3, 1);
UNLOCK TABLES;

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `emoji_usage`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `emoji_usage` (

  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '记录ID',

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `emoji_id` int(11) NOT NULL COMMENT '表情ID',

  `use_time` int(11) NOT NULL COMMENT '使用时间',

  `use_count` int(11) DEFAULT '1' COMMENT '使用次数',

  PRIMARY KEY (`id`),

  UNIQUE KEY `idx_user_emoji` (`user_id`,`emoji_id`),

  KEY `idx_use_time` (`use_time`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表情使用记录表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `emojis`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `emojis` (

  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '表情ID',

  `user_id` int(11) NOT NULL COMMENT '用户ID(0表示系统默认表情)',

  `name` varchar(100) NOT NULL COMMENT '表情名称',

  `url` varchar(255) NOT NULL COMMENT '表情图片URL',

  `type` varchar(20) NOT NULL DEFAULT 'custom' COMMENT '表情类型：default-默认，custom-自定义',

  `category` varchar(50) DEFAULT NULL COMMENT '表情分类',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态：0-禁用1-启用',

  PRIMARY KEY (`id`),

  KEY `idx_user_id` (`user_id`),

  KEY `idx_type` (`type`),

  KEY `idx_category` (`category`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表情表';

LOCK TABLES `emojis` WRITE;
INSERT INTO `emojis` (`user_id`, `name`, `url`, `type`, `category`, `create_time`, `status`) VALUES
-- 表情分类1: 基础表情 (12个)
(0, '微笑', '/static/emojis/default/13413817034075935.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
(0, '大笑', '/static/emojis/default/13413817104559803.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
(0, '开心', '/static/emojis/default/13413817151837409.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
(0, '调皮', '/static/emojis/default/13413817162773160.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
(0, '呲牙', '/static/emojis/default/13413817168943889.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
(0, '惊讶', '/static/emojis/default/13413817173283676.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
(0, '撇嘴', '/static/emojis/default/13413817177200576.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
(0, '难过', '/static/emojis/default/13413817181284408.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
(0, '大哭', '/static/emojis/default/13413817185093159.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
(0, '生气', '/static/emojis/default/13413817188664963.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
(0, '酷', '/static/emojis/default/13413817192124428.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
(0, '发呆', '/static/emojis/default/13413817196163080.gif', 'default', '基础', UNIX_TIMESTAMP(), 1),
-- 表情分类2: 手势 (6个)
(0, '点赞', '/static/emojis/default/13413817200409309.gif', 'default', '手势', UNIX_TIMESTAMP(), 1),
(0, '踩', '/static/emojis/default/13413817204533292.gif', 'default', '手势', UNIX_TIMESTAMP(), 1),
(0, '拳头', '/static/emojis/default/13413817208526996.gif', 'default', '手势', UNIX_TIMESTAMP(), 1),
(0, '握手', '/static/emojis/default/13413817213184230.gif', 'default', '手势', UNIX_TIMESTAMP(), 1),
(0, '胜利', '/static/emojis/default/13413817245809559.gif', 'default', '手势', UNIX_TIMESTAMP(), 1),
(0, 'OK手势', '/static/emojis/default/13413817250006626.gif', 'default', '手势', UNIX_TIMESTAMP(), 1),
-- 表情分类3: 表情包 (20个)
(0, '害羞', '/static/emojis/default/13413817254157271.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '害羞2', '/static/emojis/default/13413817260702591.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '偷笑', '/static/emojis/default/13413817264842563.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '偷笑2', '/static/emojis/default/13413817270806233.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '得意', '/static/emojis/default/13413817274590779.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '得意2', '/static/emojis/default/13413817288864222.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '害羞3', '/static/emojis/default/13413817293493374.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '害羞4', '/static/emojis/default/13413817297556370.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '偷笑3', '/static/emojis/default/13413817301164001.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '偷笑4', '/static/emojis/default/13413817304698562.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '疑惑', '/static/emojis/default/13413817308193972.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '疑惑2', '/static/emojis/default/13413817311926244.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '无语', '/static/emojis/default/13413817319183613.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '无语2', '/static/emojis/default/13413817327484190.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '眨眼', '/static/emojis/default/13413817332123884.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '眨眼2', '/static/emojis/default/13413817337085224.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '汗', '/static/emojis/default/13413817342184588.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '汗2', '/static/emojis/default/13413817346306968.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '震惊', '/static/emojis/default/13413817351007329.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
(0, '震惊2', '/static/emojis/default/13413817357366380.gif', 'default', '表情包', UNIX_TIMESTAMP(), 1),
-- 表情分类4: 动物 (10个)
(0, '猫', '/static/emojis/default/13413817361863498.gif', 'default', '动物', UNIX_TIMESTAMP(), 1),
(0, '狗', '/static/emojis/default/13413817365809591.gif', 'default', '动物', UNIX_TIMESTAMP(), 1),
(0, '兔子', '/static/emojis/default/13413817371298210.gif', 'default', '动物', UNIX_TIMESTAMP(), 1),
(0, '熊猫', '/static/emojis/default/13413817380080107.gif', 'default', '动物', UNIX_TIMESTAMP(), 1),
(0, '狐狸', '/static/emojis/default/13413817387834933.gif', 'default', '动物', UNIX_TIMESTAMP(), 1),
(0, '熊', '/static/emojis/default/13413817393601535.gif', 'default', '动物', UNIX_TIMESTAMP(), 1),
(0, '猴子', '/static/emojis/default/13413817398664584.gif', 'default', '动物', UNIX_TIMESTAMP(), 1),
(0, '猪', '/static/emojis/default/13413817432476927.gif', 'default', '动物', UNIX_TIMESTAMP(), 1),
(0, '牛', '/static/emojis/default/13413817436831068.gif', 'default', '动物', UNIX_TIMESTAMP(), 1),
(0, '马', '/static/emojis/default/13413817441596630.gif', 'default', '动物', UNIX_TIMESTAMP(), 1),
-- 表情分类5: 食物 (10个)
(0, '咖啡', '/static/emojis/default/13413817445643527.gif', 'default', '食物', UNIX_TIMESTAMP(), 1),
(0, '汉堡', '/static/emojis/default/13413817452603332.gif', 'default', '食物', UNIX_TIMESTAMP(), 1),
(0, '披萨', '/static/emojis/default/13413817457288960.gif', 'default', '食物', UNIX_TIMESTAMP(), 1),
(0, '蛋糕', '/static/emojis/default/13413817461037332.gif', 'default', '食物', UNIX_TIMESTAMP(), 1),
(0, '冰淇淋', '/static/emojis/default/13413817465864845.gif', 'default', '食物', UNIX_TIMESTAMP(), 1),
(0, '奶茶', '/static/emojis/default/13413817483624845.gif', 'default', '食物', UNIX_TIMESTAMP(), 1),
(0, '饮料', '/static/emojis/default/13413817487848264.gif', 'default', '食物', UNIX_TIMESTAMP(), 1),
(0, '水果', '/static/emojis/default/13413817497838353.gif', 'default', '食物', UNIX_TIMESTAMP(), 1),
(0, '面包', '/static/emojis/default/13413817502265431.gif', 'default', '食物', UNIX_TIMESTAMP(), 1),
(0, '糖果', '/static/emojis/default/13413817506364086.gif', 'default', '食物', UNIX_TIMESTAMP(), 1),
-- 表情分类6: 符号 (10个)
(0, '爱心', '/static/emojis/default/13413817517104426.gif', 'default', '符号', UNIX_TIMESTAMP(), 1),
(0, '爱心破碎', '/static/emojis/default/13413817521176348.gif', 'default', '符号', UNIX_TIMESTAMP(), 1),
(0, '星星', '/static/emojis/default/13413817525517313.gif', 'default', '符号', UNIX_TIMESTAMP(), 1),
(0, '太阳', '/static/emojis/default/13413817529632337.gif', 'default', '符号', UNIX_TIMESTAMP(), 1),
(0, '月亮', '/static/emojis/default/13413817533501893.gif', 'default', '符号', UNIX_TIMESTAMP(), 1),
(0, '闪电', '/static/emojis/default/13413817537454183.gif', 'default', '符号', UNIX_TIMESTAMP(), 1),
(0, '雨伞', '/static/emojis/default/13413817543755541.gif', 'default', '符号', UNIX_TIMESTAMP(), 1),
(0, '云', '/static/emojis/default/13413817548641699.gif', 'default', '符号', UNIX_TIMESTAMP(), 1),
(0, '雪花', '/static/emojis/default/13413817554194664.gif', 'default', '符号', UNIX_TIMESTAMP(), 1),
(0, '彩虹', '/static/emojis/default/13413817558664117.gif', 'default', '符号', UNIX_TIMESTAMP(), 1),
-- 表情分类7: 运动 (10个)
(0, '足球', '/static/emojis/default/13413817564849882.gif', 'default', '运动', UNIX_TIMESTAMP(), 1),
(0, '篮球', '/static/emojis/default/13413817570396800.gif', 'default', '运动', UNIX_TIMESTAMP(), 1),
(0, '跑步', '/static/emojis/default/13413817575688235.gif', 'default', '运动', UNIX_TIMESTAMP(), 1),
(0, '游泳', '/static/emojis/default/13413817584160683.gif', 'default', '运动', UNIX_TIMESTAMP(), 1),
(0, '骑行', '/static/emojis/default/13413817588724383.gif', 'default', '运动', UNIX_TIMESTAMP(), 1),
(0, '网球', '/static/emojis/default/13413817595825685.gif', 'default', '运动', UNIX_TIMESTAMP(), 1),
(0, '乒乓球', '/static/emojis/default/13413817603264672.gif', 'default', '运动', UNIX_TIMESTAMP(), 1),
(0, '羽毛球', '/static/emojis/default/13413817609147875.gif', 'default', '运动', UNIX_TIMESTAMP(), 1),
(0, '滑雪', '/static/emojis/default/13413817623514269.gif', 'default', '运动', UNIX_TIMESTAMP(), 1),
(0, '滑板', '/static/emojis/default/13413817630449877.gif', 'default', '运动', UNIX_TIMESTAMP(), 1),
-- 表情分类8: 节日 (10个)
(0, '圣诞树', '/static/emojis/default/13413817651278378.gif', 'default', '节日', UNIX_TIMESTAMP(), 1),
(0, '礼物', '/static/emojis/default/13413817658476316.gif', 'default', '节日', UNIX_TIMESTAMP(), 1),
(0, '烟花', '/static/emojis/default/13413817664443253.gif', 'default', '节日', UNIX_TIMESTAMP(), 1),
(0, '灯笼', '/static/emojis/default/13413817669121632.gif', 'default', '节日', UNIX_TIMESTAMP(), 1),
(0, '鞭炮', '/static/emojis/default/13413817673657516.gif', 'default', '节日', UNIX_TIMESTAMP(), 1),
(0, '气球', '/static/emojis/default/13413817678637027.gif', 'default', '节日', UNIX_TIMESTAMP(), 1),
(0, '彩带', '/static/emojis/default/13413817685986909.gif', 'default', '节日', UNIX_TIMESTAMP(), 1),
(0, '派对帽', '/static/emojis/default/13413817709236894.gif', 'default', '节日', UNIX_TIMESTAMP(), 1),
(0, '生日蛋糕', '/static/emojis/default/13413817715005410.gif', 'default', '节日', UNIX_TIMESTAMP(), 1),
(0, '许愿', '/static/emojis/default/13413817721796405.gif', 'default', '节日', UNIX_TIMESTAMP(), 1),
-- 表情分类9: 人物 (10个)
(0, '绅士', '/static/emojis/default/13413817730804926.gif', 'default', '人物', UNIX_TIMESTAMP(), 1),
(0, '女士', '/static/emojis/default/13413817738948122.gif', 'default', '人物', UNIX_TIMESTAMP(), 1),
(0, '老人', '/static/emojis/default/13413817751019241.gif', 'default', '人物', UNIX_TIMESTAMP(), 1),
(0, '小孩', '/static/emojis/default/13413817773118790.gif', 'default', '人物', UNIX_TIMESTAMP(), 1),
(0, '医生', '/static/emojis/default/13413817784627001.gif', 'default', '人物', UNIX_TIMESTAMP(), 1),
(0, '警察', '/static/emojis/default/13413817791608442.gif', 'default', '人物', UNIX_TIMESTAMP(), 1),
(0, '厨师', '/static/emojis/default/13413817800686832.gif', 'default', '人物', UNIX_TIMESTAMP(), 1),
(0, '艺术家', '/static/emojis/default/13413817807803654.gif', 'default', '人物', UNIX_TIMESTAMP(), 1),
(0, '音乐家', '/static/emojis/default/13413817819192741.gif', 'default', '人物', UNIX_TIMESTAMP(), 1),
(0, '宇航员', '/static/emojis/default/13413817829503671.gif', 'default', '人物', UNIX_TIMESTAMP(), 1),
-- 表情分类10: 其他 (2个)
(0, '问号', '/static/emojis/default/13413817834264246.gif', 'default', '其他', UNIX_TIMESTAMP(), 1),
(0, '感叹号', '/static/emojis/default/13413817839687211.gif', 'default', '其他', UNIX_TIMESTAMP(), 1);
UNLOCK TABLES;

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `error_log`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `error_log` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `level` varchar(20) DEFAULT '' COMMENT '错误级别',

  `message` text COMMENT '错误信息',

  `file` varchar(255) DEFAULT '' COMMENT '文件路径',

  `line` int(11) DEFAULT '0' COMMENT '行号',

  `context` text COMMENT '上下文',

  `trace` text COMMENT '堆栈跟踪',

  `ip` varchar(50) DEFAULT '' COMMENT 'IP地址',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='错误日志表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `essay`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `essay` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `ptpuser` text COMMENT '发布者账号',

  `ptpimg` text COMMENT '发布者头像',

  `ptpname` text COMMENT '发布者昵称',

  `ptptext` text COMMENT '文章内容',

  `ptpimag` text COMMENT '文章图片',

  `ptpvideo` text COMMENT '文章视频',

  `ptpmusic` text COMMENT '文章音乐',

  `ptplx` text COMMENT '文章类型(img=图文 video=视频 music=音乐 only=仅文字)',

  `ptpdw` text COMMENT '文章发布时间',

  `ptptime` text COMMENT '文章发布时间',

  `ptpgg` text COMMENT '文章是否为广告0=不是1=是',

  `ptpggurl` text COMMENT '广告跳转链接',

  `ptpys` text COMMENT '文章是否可见(0=不可见1=可见)',

  `commauth` text COMMENT '是否允许评论(0=否1=开)',

  `ptpaud` text COMMENT '审核状态0=未审核1=已审核',

  `ip` text COMMENT '文章发布时的ip',

  `cid` text COMMENT '文章cid',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `articles`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `articles` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '文章标题',
  `content` text NOT NULL COMMENT '文章内容',
  `summary` varchar(500) DEFAULT '' COMMENT '文章摘要',
  `cover_image` varchar(500) DEFAULT '' COMMENT '封面图片',
  `images` text COMMENT '文章配图(JSON数组)',
  `category_id` int(11) DEFAULT NULL COMMENT '分类ID',
  `tags` varchar(255) DEFAULT '' COMMENT '标签',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态0=草稿1=已发布2=已删除',
  `view_count` int(11) DEFAULT '0' COMMENT '浏览量',
  `like_count` int(11) DEFAULT '0' COMMENT '点赞数',
  `collect_count` int(11) DEFAULT '0' COMMENT '收藏数',
  `comment_count` int(11) DEFAULT '0' COMMENT '评论数',
  `publish_time` int(11) DEFAULT '0' COMMENT '发布时间',
  `create_time` int(11) DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_publish_time` (`publish_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `article_logs`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `article_logs` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `article_id` int(11) unsigned NOT NULL COMMENT '文章ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `action` varchar(50) NOT NULL COMMENT '操作类型',
  `remark` varchar(500) DEFAULT '' COMMENT '备注',
  `create_time` int(11) DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_article_id` (`article_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章操作日志表';

/*!40101 SET character_set_client = @saved_cs_client */;




DROP TABLE IF EXISTS `article_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `article_comments` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `article_id` int(11) unsigned NOT NULL COMMENT '文章ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `parent_id` int(11) unsigned DEFAULT '0' COMMENT '父评论ID,0表示顶级评论',
  `content` text NOT NULL COMMENT '评论内容',
  `likes` int(11) DEFAULT '0' COMMENT '点赞数',
  `replies` int(11) DEFAULT '0' COMMENT '回复数',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态:1正常,0删除',
  `create_time` int(11) DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_article_id` (`article_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_parent_id` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章评论表';
/*!40101 SET character_set_client = @saved_cs_client */;




DROP TABLE IF EXISTS `article_likes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `article_likes` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `article_id` int(11) unsigned NOT NULL COMMENT '文章ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `create_time` int(11) DEFAULT '0' COMMENT '点赞时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_article_user` (`article_id`, `user_id`),
  KEY `idx_article_id` (`article_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章点赞表';
/*!40101 SET character_set_client = @saved_cs_client */;




DROP TABLE IF EXISTS `article_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `article_views` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `article_id` int(11) unsigned NOT NULL COMMENT '文章ID',
  `user_id` int(11) unsigned DEFAULT '0' COMMENT '用户ID,0表示游客',
  `ip` varchar(50) DEFAULT '' COMMENT 'IP地址',
  `user_agent` varchar(500) DEFAULT '' COMMENT '用户代理',
  `create_time` int(11) DEFAULT '0' COMMENT '浏览时间',
  PRIMARY KEY (`id`),
  KEY `idx_article_id` (`article_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章浏览记录表';
/*!40101 SET character_set_client = @saved_cs_client */;




DROP TABLE IF EXISTS `article_collections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `article_collections` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `article_id` int(11) unsigned NOT NULL COMMENT '文章ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `folder_id` int(11) unsigned DEFAULT '0' COMMENT '收藏夹ID,0表示默认收藏夹',
  `create_time` int(11) DEFAULT '0' COMMENT '收藏时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_article_user` (`article_id`, `user_id`),
  KEY `idx_article_id` (`article_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_folder_id` (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章收藏表';
/*!40101 SET character_set_client = @saved_cs_client */;




DROP TABLE IF EXISTS `article_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `article_categories` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) unsigned DEFAULT '0' COMMENT '父分类ID,0表示顶级分类',
  `name` varchar(100) NOT NULL COMMENT '分类名称',
  `slug` varchar(100) DEFAULT '' COMMENT '分类别名',
  `description` text COMMENT '分类描述',
  `icon` varchar(255) DEFAULT '' COMMENT '分类图标',
  `cover_image` varchar(500) DEFAULT '' COMMENT '分类封面图',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态:1启用,0禁用',
  `article_count` int(11) DEFAULT '0' COMMENT '文章数量',
  `create_time` int(11) DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章分类表';
/*!40101 SET character_set_client = @saved_cs_client */;




DROP TABLE IF EXISTS `faq_categories`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `faq_categories` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(100) NOT NULL COMMENT '分类名称',

  `description` text,

  `sort_order` int(11) DEFAULT '0',

  `status` tinyint(1) DEFAULT '1',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户钱包表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `faqs`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `faqs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `question` varchar(500) NOT NULL COMMENT '问题',

  `answer` text COMMENT '答案',

  `category_id` int(11) DEFAULT '0' COMMENT '分类ID',

  `sort_order` int(11) DEFAULT '0',

  `status` tinyint(1) DEFAULT '1',

  `create_time` int(11) DEFAULT '0',

  `update_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='版本日志表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `favorite_folders`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `favorite_folders` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `name` varchar(50) NOT NULL COMMENT '收藏夹名称',

  `description` varchar(200) DEFAULT '' COMMENT '描述',

  `is_public` tinyint(1) DEFAULT '0' COMMENT '是否公开',

  `sort_order` int(11) DEFAULT '0' COMMENT '排序',

  `cover` varchar(255) DEFAULT NULL COMMENT '封面图',

  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',

  `update_time` timestamp NULL DEFAULT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `sort_order` (`sort_order`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏夹表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `favorites`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `favorites` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `target_id` int(11) NOT NULL COMMENT '目标ID',

  `target_type` tinyint(1) NOT NULL COMMENT '目标类型:1-动态2-评论',

  `folder_id` int(11) DEFAULT '0' COMMENT '收藏夹ID',

  `folder_name` varchar(50) DEFAULT '默认收藏' COMMENT '收藏夹名称',

  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_target` (`user_id`,`target_id`,`target_type`),

  KEY `user_id` (`user_id`),

  KEY `target_id` (`target_id`),

  KEY `target_type` (`target_type`),

  KEY `folder_id` (`folder_id`),

  KEY `create_time` (`create_time`),

  KEY `idx_user_id` (`user_id`),

  KEY `idx_user_type` (`user_id`,`target_type`),

  KEY `idx_target` (`target_id`,`target_type`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `follows`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `follows` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `follower_id` int(11) NOT NULL COMMENT '关注者ID',

  `following_id` int(11) NOT NULL COMMENT '被关注者ID',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-正常,0已取消',

  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `follower_following` (`follower_id`,`following_id`),

  KEY `following_id` (`following_id`),

  KEY `idx_follower_id` (`follower_id`),

  KEY `idx_following_id` (`following_id`),

  KEY `idx_follower_following` (`follower_id`,`following_id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='关注表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `friend_group_members`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `friend_group_members` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `group_id` int(11) unsigned NOT NULL COMMENT '分组ID',

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `friend_id` int(11) NOT NULL COMMENT '好友ID',

  `add_time` int(11) NOT NULL COMMENT '添加时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `group_user_friend` (`group_id`,`user_id`,`friend_id`),

  KEY `user_friend` (`user_id`,`friend_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='好友分组成员表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `friend_groups`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `friend_groups` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `name` varchar(50) NOT NULL COMMENT '分组名称',

  `sort` tinyint(4) NOT NULL DEFAULT '0' COMMENT '排序',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  `update_time` int(11) NOT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_name` (`user_id`,`name`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COMMENT='好友分组表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `hidden_moments`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `hidden_moments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `moment_id` int(11) NOT NULL COMMENT '动态ID',

  `create_time` int(11) DEFAULT '0' COMMENT '创建时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_moment` (`user_id`,`moment_id`),

  KEY `user_id` (`user_id`),

  KEY `moment_id` (`moment_id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='隐藏动态记录表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `hot_searches`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `hot_searches` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `keyword` varchar(100) NOT NULL COMMENT '搜索关键词',

  `search_count` int(11) NOT NULL DEFAULT '0' COMMENT '搜索次数',

  `today_count` int(11) NOT NULL DEFAULT '0' COMMENT '今日搜索次数',

  `yesterday_count` int(11) NOT NULL DEFAULT '0' COMMENT '昨日搜索次数',

  `is_hot` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否热门:0-否1-是',

  `rank` tinyint(4) NOT NULL DEFAULT '0' COMMENT '排名',

  `update_time` int(11) NOT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `keyword` (`keyword`),

  KEY `search_count` (`search_count`),

  KEY `today_count` (`today_count`),

  KEY `is_hot` (`is_hot`),

  KEY `rank` (`rank`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='热搜榜表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `likes`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `likes` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `target_id` int(11) NOT NULL COMMENT '目标ID',

  `target_type` tinyint(1) NOT NULL COMMENT '目标类型:1-动态2-评论',

  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',

  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_target` (`user_id`,`target_id`,`target_type`),

  KEY `user_id` (`user_id`),

  KEY `target_id` (`target_id`),

  KEY `target_type` (`target_type`),

  KEY `create_time` (`create_time`),

  KEY `idx_target_id` (`target_id`),

  KEY `idx_target_type` (`target_type`),

  KEY `idx_target_id_type` (`target_id`,`target_type`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='点赞表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `link`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `link` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `url` text COMMENT '友链地址',

  `urls` text COMMENT '友链说明',

  `urlimg` text COMMENT '友链图标',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='友链表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `login_logs`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `login_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `login_ip` varchar(45) NOT NULL COMMENT '登录IP',

  `login_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '登录时间',

  `device_type` varchar(20) DEFAULT '' COMMENT '设备类型',

  `browser` varchar(50) DEFAULT '' COMMENT '浏览器',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态0-失败1-成功',

  `is_abnormal` tinyint(1) DEFAULT '0' COMMENT '是否异常登录',

  `abnormal_reason` varchar(255) DEFAULT '' COMMENT '异常原因',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `login_time` (`login_time`),

  KEY `login_ip` (`login_ip`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录日志表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `mentions`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `mentions` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `moment_id` int(11) NOT NULL COMMENT '动态ID',

  `user_id` int(11) NOT NULL COMMENT '发布动态的用户ID',

  `mentioned_user_id` int(11) NOT NULL COMMENT '被@的用户ID',

  `nickname` varchar(50) NOT NULL COMMENT '被@用户的昵称',

  `avatar` varchar(255) DEFAULT '' COMMENT '被@用户的头像',

  `content` text COMMENT '相关内容',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  `read_status` tinyint(1) DEFAULT '0' COMMENT '阅读状态0-未读,1已读',

  `read_time` int(11) DEFAULT '0' COMMENT '阅读时间',

  PRIMARY KEY (`id`),

  KEY `moment_id` (`moment_id`),

  KEY `user_id` (`user_id`),

  KEY `mentioned_user_id` (`mentioned_user_id`),

  KEY `create_time` (`create_time`),

  KEY `read_status` (`read_status`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态@提及表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `message_favorites`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `message_favorites` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `message_id` int(11) NOT NULL COMMENT '消息ID',

  `create_time` int(11) NOT NULL DEFAULT '0',

  PRIMARY KEY (`id`),

  UNIQUE KEY `uk_user_message` (`user_id`,`message_id`),

  KEY `idx_user` (`user_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='消息收藏表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `message_templates`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `message_templates` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(100) NOT NULL COMMENT '模板名称',

  `type` varchar(50) NOT NULL COMMENT '模板类型',

  `title` varchar(255) DEFAULT '' COMMENT '消息标题',

  `content` text COMMENT '消息内容',

  `variables` varchar(500) DEFAULT '' COMMENT '可用变量',

  `status` tinyint(1) DEFAULT '1',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='版本表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `messages`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `messages` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `sender_id` int(11) NOT NULL COMMENT '发送者ID',

  `receiver_id` int(11) NOT NULL COMMENT '接收者ID',

  `content` text COMMENT '消息内容',

  `message_type` tinyint(1) DEFAULT '1' COMMENT '消息类型:1-文本,2-图片,3-视频',

  `reply_to_id` int(11) DEFAULT '0' COMMENT '引用回复的消息ID',

  `file_url` varchar(255) DEFAULT '' COMMENT '文件URL',

  `voice_duration` int(11) DEFAULT '0' COMMENT '语音时长(秒)',

  `is_read` tinyint(1) DEFAULT '0' COMMENT '是否已读:0-未读,1-已读',

  `read_time` int(11) DEFAULT '0' COMMENT '阅读时间',

  `is_recalled` tinyint(1) DEFAULT '0' COMMENT '是否撤回0-未撤回，1-已撤回',

  `is_pinned` tinyint(1) DEFAULT '0' COMMENT '是否置顶',

  `pin_time` int(11) DEFAULT NULL COMMENT '置顶时间',

  `recall_time` int(11) DEFAULT NULL COMMENT '撤回时间（时间戳）',

  `file_name` varchar(255) DEFAULT '' COMMENT '文件URL',

  `file_size` int(11) DEFAULT '0' COMMENT '文件大小(字节)',

  `send_status` tinyint(1) DEFAULT '1' COMMENT '发送状态0-发送中,1-成功,2-失败',

  `send_time` int(11) DEFAULT NULL COMMENT '发送完成时间',

  `self_destruct_time` int(11) DEFAULT NULL COMMENT '阅后即焚时间(秒)',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  PRIMARY KEY (`id`),

  KEY `sender_id` (`sender_id`),

  KEY `receiver_id` (`receiver_id`),

  KEY `is_read` (`is_read`),

  KEY `create_time` (`create_time`),

  KEY `idx_reply_to` (`reply_to_id`),

  KEY `idx_is_pinned` (`is_pinned`),

  KEY `idx_is_recalled` (`is_recalled`),

  KEY `idx_send_status` (`send_status`),

  KEY `idx_sender_receiver` (`sender_id`,`receiver_id`),

  KEY `idx_receiver_read` (`receiver_id`,`is_read`),

  KEY `idx_create_time` (`create_time`),

  KEY `idx_sender_receiver_time` (`sender_id`,`receiver_id`,`create_time`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='私信消息表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `migrations`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `migrations` (

  `version` bigint(20) NOT NULL,

  `migration_name` varchar(100) DEFAULT NULL,

  `start_time` timestamp NULL DEFAULT NULL,

  `end_time` timestamp NULL DEFAULT NULL,

  `breakpoint` tinyint(1) NOT NULL DEFAULT '0',

  PRIMARY KEY (`version`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='迁移记录表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `moment_drafts`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `moment_drafts` (

  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '草稿ID',

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `nickname` varchar(50) NOT NULL COMMENT '显示名称',

  `avatar` varchar(255) NOT NULL COMMENT '显示头像',

  `content` text COMMENT '动态内容',

  `images` varchar(5000) DEFAULT '' COMMENT '图片数组JSON',

  `videos` varchar(1000) DEFAULT '' COMMENT '视频数组JSON',

  `location` varchar(255) DEFAULT '' COMMENT '位置信息',

  `privacy` tinyint(4) DEFAULT '1' COMMENT '隐私设置:1-公开,2-仅自己可见3-仅好友可见',

  `is_anonymous` tinyint(4) DEFAULT '0' COMMENT '是否匿名:0-非匿名1-匿名',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  `updated_time` int(11) NOT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态草稿表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `moment_likes`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `moment_likes` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `moment_id` int(11) NOT NULL COMMENT '动态ID',

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `create_time` int(11) NOT NULL COMMENT '点赞时间',

  PRIMARY KEY (`id`),

  KEY `moment_id` (`moment_id`),

  KEY `user_id` (`user_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态点赞表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `moment_topics`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `moment_topics` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `moment_id` int(11) NOT NULL COMMENT '动态ID',

  `topic_id` int(11) NOT NULL COMMENT '话题ID',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `moment_topic` (`moment_id`,`topic_id`),

  KEY `moment_id` (`moment_id`),

  KEY `topic_id` (`topic_id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态话题关联表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `moments`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `moments` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL DEFAULT '1' COMMENT '用户ID',

  `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '用户昵称',

  `avatar` varchar(255) DEFAULT NULL COMMENT '用户头像',

  `content` text COMMENT '动态内容',

  `images` longtext COMMENT '图片列表JSON',

  `videos` mediumtext,

  `location` varchar(100) DEFAULT '' COMMENT '位置信息',

  `latitude` decimal(10,7) DEFAULT NULL COMMENT '纬度',

  `longitude` decimal(10,7) DEFAULT NULL COMMENT '经度',

  `type` tinyint(1) DEFAULT '1' COMMENT '动态类型1-文本,2图片,3视频,4链接',

  `privacy` tinyint(1) DEFAULT '1' COMMENT '隐私设置:1公开,2私密,3仅好友可见4部分可见',

  `is_top` tinyint(1) DEFAULT '0' COMMENT '是否置顶',

  `top_expire_time` timestamp NULL DEFAULT NULL COMMENT '置顶过期时间',

  `is_recommend` tinyint(1) DEFAULT '0' COMMENT '是否推荐',

  `likes` int(11) DEFAULT '0' COMMENT '点赞数',

  `comments` int(11) DEFAULT '0' COMMENT '评论数',

  `share_count` int(11) NOT NULL DEFAULT '0' COMMENT '分享次数',

  `shares` int(11) DEFAULT '0' COMMENT '分享数',

  `views` int(11) DEFAULT '0' COMMENT '浏览数',

  `collect_count` int(11) DEFAULT '0' COMMENT '收藏数',

  `publish_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '发布时间',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-正常,0删除',

  `is_anonymous` tinyint(1) DEFAULT '0' COMMENT '是否匿名发布',

  `comments_count` int(11) NOT NULL DEFAULT '0' COMMENT '评论数',

  `top_comment_id` int(11) DEFAULT NULL COMMENT '置顶评论ID',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `create_time` (`create_time`),

  KEY `status` (`status`),

  KEY `is_top` (`is_top`),

  KEY `idx_user_id` (`user_id`),

  KEY `idx_user_status` (`user_id`,`status`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `notifications`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `notifications` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '接收用户ID',

  `sender_id` int(11) DEFAULT '0' COMMENT '发送者ID(0为系统)',

  `type` tinyint(1) NOT NULL COMMENT '通知类型:1点赞,2评论,3关注,4私信,5系统通知',

  `title` varchar(200) NOT NULL COMMENT '标题',

  `content` text COMMENT '内容',

  `target_id` int(11) DEFAULT '0' COMMENT '目标ID',

  `target_type` varchar(50) DEFAULT '' COMMENT '目标类型',

  `is_read` tinyint(1) DEFAULT '0' COMMENT '是否已读',

  `read_time` timestamp NULL DEFAULT NULL COMMENT '阅读时间',

  `message_type` tinyint(1) DEFAULT '1' COMMENT '消息类型:1文本,2图片,3语音,4表情',

  `file_url` varchar(500) DEFAULT '' COMMENT '文件URL',

  `file_name` varchar(255) DEFAULT '' COMMENT '文件URL',

  `file_size` int(11) DEFAULT '0' COMMENT '文件大小',

  `duration` int(11) DEFAULT '0' COMMENT '语音时长(秒)',

  `is_recalled` tinyint(1) DEFAULT '0' COMMENT '是否撤回',

  `recall_time` timestamp NULL DEFAULT NULL COMMENT '撤回时间',

  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',

  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `sender_id` (`sender_id`),

  KEY `type` (`type`),

  KEY `is_read` (`is_read`),

  KEY `create_time` (`create_time`),

  KEY `user_read` (`user_id`,`is_read`),

  KEY `idx_user_read` (`user_id`,`is_read`),

  KEY `idx_create_time` (`create_time`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `operation_log`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `operation_log` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) DEFAULT '0' COMMENT '用户ID',

  `username` varchar(100) DEFAULT '' COMMENT '用户名',

  `action` varchar(100) DEFAULT '' COMMENT '操作行为',

  `module` varchar(50) DEFAULT '' COMMENT '模块',

  `method` varchar(50) DEFAULT '' COMMENT '请求方法',

  `url` varchar(255) DEFAULT '' COMMENT '请求URL',

  `ip` varchar(50) DEFAULT '' COMMENT 'IP地址',

  `user_agent` varchar(500) DEFAULT '' COMMENT 'User-Agent',

  `param` text COMMENT '请求参数',

  `result` text COMMENT '操作结果',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='慢查询日志表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `operation_participants`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `operation_participants` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `operation_id` int(11) NOT NULL COMMENT '活动ID',

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `participant_time` int(11) NOT NULL COMMENT '参与时间',

  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-已参与2-已完成3-已获得',

  `reward_id` int(11) DEFAULT NULL COMMENT '获得的奖励ID',

  PRIMARY KEY (`id`),

  UNIQUE KEY `operation_user` (`operation_id`,`user_id`),

  KEY `operation_id` (`operation_id`),

  KEY `user_id` (`user_id`),

  KEY `participant_time` (`participant_time`),

  KEY `status` (`status`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动参与表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `operation_reward_records`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `operation_reward_records` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `operation_id` int(11) NOT NULL COMMENT '活动ID',

  `reward_id` int(11) NOT NULL COMMENT '奖励ID',

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `reward_value` varchar(50) NOT NULL COMMENT '奖励金额',

  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-待发放2-已发放3-已领取4-已过期',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  `issue_time` int(11) DEFAULT NULL COMMENT '发放时间',

  PRIMARY KEY (`id`),

  KEY `operation_id` (`operation_id`),

  KEY `reward_id` (`reward_id`),

  KEY `user_id` (`user_id`),

  KEY `status` (`status`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='奖励发放记录';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `operation_rewards`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `operation_rewards` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `operation_id` int(11) NOT NULL COMMENT '活动ID',

  `name` varchar(50) NOT NULL COMMENT '奖励名称',

  `description` varchar(200) NOT NULL COMMENT '奖励描述',

  `type` tinyint(1) NOT NULL COMMENT '奖励类型:1-积分,2-虚拟货币,3-实物奖励,4-优惠券',

  `value` varchar(50) NOT NULL COMMENT '奖励金额',

  `quantity` int(11) NOT NULL COMMENT '奖励数量',

  `remaining_quantity` int(11) NOT NULL COMMENT '剩余数量',

  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-有效,2-无效',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  PRIMARY KEY (`id`),

  KEY `operation_id` (`operation_id`),

  KEY `type` (`type`),

  KEY `status` (`status`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COMMENT='活动奖励表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `operations`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `operations` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `title` varchar(100) NOT NULL COMMENT '活动标题',

  `description` text NOT NULL COMMENT '活动描述',

  `cover` varchar(255) NOT NULL COMMENT '活动封面',

  `start_time` int(11) NOT NULL COMMENT '开始时间',

  `end_time` int(11) NOT NULL COMMENT '结束时间',

  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-未开始2-进行中3-已结束4-已下架',

  `participant_count` int(11) NOT NULL DEFAULT '0' COMMENT '参与人数',

  `view_count` int(11) NOT NULL DEFAULT '0' COMMENT '浏览人数',

  `creator_id` int(11) NOT NULL COMMENT '创建人ID',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  `update_time` int(11) NOT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`),

  KEY `status` (`status`),

  KEY `start_time` (`start_time`),

  KEY `end_time` (`end_time`),

  KEY `creator_id` (`creator_id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COMMENT='运营活动表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `post_media`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `post_media` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `post_id` int(11) NOT NULL COMMENT '动态ID',

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `media_type` tinyint(1) NOT NULL COMMENT '媒体类型:1图片,2视频,3音频,4文件',

  `file_url` varchar(500) NOT NULL COMMENT '文件URL',

  `file_name` varchar(255) NOT NULL COMMENT '文件URL',

  `file_size` int(11) NOT NULL COMMENT '文件大小(字节)',

  `file_mime` varchar(100) DEFAULT '' COMMENT '文件MIME类型',

  `width` int(11) DEFAULT NULL COMMENT '图片/视频宽度',

  `height` int(11) DEFAULT NULL COMMENT '图片/视频高度',

  `duration` int(11) DEFAULT NULL COMMENT '音视频时长',

  `thumbnail_url` varchar(500) DEFAULT '' COMMENT '缩略图URL',

  `description` varchar(500) DEFAULT '' COMMENT '描述',

  `sort_order` int(11) DEFAULT '0' COMMENT '排序',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态0-删除,1正常',

  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',

  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',

  PRIMARY KEY (`id`),

  KEY `post_id` (`post_id`),

  KEY `user_id` (`user_id`),

  KEY `media_type` (`media_type`),

  KEY `create_time` (`create_time`),

  KEY `post_id_sort` (`post_id`,`sort_order`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态多媒体表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `push_records`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `push_records` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `title` varchar(255) NOT NULL COMMENT '推送标题',

  `content` text COMMENT '推送内容',

  `type` varchar(50) DEFAULT 'system' COMMENT '推送类型',

  `target_type` varchar(50) DEFAULT 'all' COMMENT '目标类型 all/user/tag',

  `target_value` text COMMENT '目标值',

  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-待发送1发送中,2已发送3发送失败',

  `send_time` int(11) DEFAULT '0' COMMENT '发送时间',

  `success_count` int(11) DEFAULT '0' COMMENT '成功数量',

  `fail_count` int(11) DEFAULT '0' COMMENT '失败数量',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='软件表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `rate_limit_rules`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `rate_limit_rules` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(100) NOT NULL COMMENT '规则名称',

  `endpoint` varchar(255) DEFAULT '' COMMENT '接口地址',

  `method` varchar(10) DEFAULT '' COMMENT '请求方法',

  `max_requests` int(11) DEFAULT '100' COMMENT '最大请求数',

  `time_window` int(11) DEFAULT '3600' COMMENT '时间窗口(秒)',

  `status` tinyint(1) DEFAULT '1',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件上传表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `recharge_records`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `recharge_records` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `amount` decimal(10,2) NOT NULL COMMENT '充值金额',

  `pay_type` varchar(50) DEFAULT '' COMMENT '支付方式',

  `pay_time` int(11) DEFAULT '0' COMMENT '支付时间',

  `transaction_id` varchar(100) DEFAULT '' COMMENT '交易流水号',

  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-待支付1成功2失败',

  `remark` varchar(500) DEFAULT '' COMMENT '备注',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统消息表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `reports`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `reports` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `reporter_id` int(11) NOT NULL COMMENT '举报人ID',

  `reported_user_id` int(11) DEFAULT NULL COMMENT '被举报人ID',

  `moment_id` int(11) DEFAULT NULL COMMENT '动态ID',

  `comment_id` int(11) DEFAULT NULL COMMENT '评论ID',

  `type` tinyint(1) NOT NULL COMMENT '类型:1-动态2-评论3-用户',

  `reason` varchar(200) DEFAULT NULL COMMENT '举报原因',

  `evidence_urls` text COMMENT '证据图片URL列表(JSON)',

  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-待处理1-已处理2-已忽略',

  `handle_time` int(11) DEFAULT NULL COMMENT '处理时间',

  `handle_result` varchar(500) DEFAULT NULL COMMENT '处理结果',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  `handler_id` int(11) DEFAULT NULL COMMENT '处理人ID',

  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',

  PRIMARY KEY (`id`),

  KEY `reporter_id` (`reporter_id`),

  KEY `reported_user_id` (`reported_user_id`),

  KEY `status` (`status`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `search_history`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `search_history` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `keyword` varchar(100) NOT NULL COMMENT '搜索关键词',

  `result_count` int(11) DEFAULT '0' COMMENT '结果数量',

  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `keyword` (`keyword`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='搜索历史表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `search_logs`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `search_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `keyword` varchar(255) NOT NULL COMMENT '搜索关键词',

  `count` int(11) DEFAULT '1' COMMENT '搜索次数',

  `last_search_time` int(11) DEFAULT '0' COMMENT '最后搜索时间',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  KEY `keyword` (`keyword`),

  KEY `count` (`count`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `sessions`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `sessions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `session_id` varchar(128) NOT NULL COMMENT '会话ID',

  `ip` varchar(50) DEFAULT NULL COMMENT 'IP地址',

  `user_agent` varchar(500) DEFAULT NULL COMMENT '用户代理',

  `device_type` varchar(20) DEFAULT NULL COMMENT '设备类型',

  `device_name` varchar(100) DEFAULT NULL COMMENT '设备名称',

  `expire_time` int(11) DEFAULT NULL COMMENT '过期时间',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `session_id` (`session_id`),

  KEY `user_id` (`user_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会话表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `shares`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `shares` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `target_id` int(11) NOT NULL COMMENT '目标ID',

  `target_type` tinyint(1) NOT NULL COMMENT '目标类型:1-动态2-评论',

  `share_type` tinyint(1) DEFAULT '1' COMMENT '分享类型:1-朋友圈2QQ,3微博,4链接',

  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `target_id` (`target_id`),

  KEY `target_type` (`target_type`),

  KEY `share_type` (`share_type`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分享记录表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `slow_query_log`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `slow_query_log` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `sql_text` text COMMENT 'SQL语句',

  `execute_time` decimal(10,4) DEFAULT '0.0000' COMMENT '执行时间(秒)',

  `file` varchar(255) DEFAULT '' COMMENT '文件路径',

  `line` int(11) DEFAULT '0' COMMENT '行号',

  `ip` varchar(50) DEFAULT '' COMMENT 'IP地址',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  KEY `execute_time` (`execute_time`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `software`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `software` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `software_name` varchar(255) NOT NULL COMMENT '软件名称',

  `software_code` varchar(50) DEFAULT '' COMMENT '软件代码',

  `version` varchar(50) DEFAULT '' COMMENT '当前版本',

  `description` text COMMENT '软件描述',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-启用0-禁用',

  `create_time` int(11) DEFAULT '0',

  `update_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  KEY `software_code` (`software_code`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `authorizations`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `authorizations` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `license_number` varchar(100) NOT NULL COMMENT '授权编号',

  `software_id` int(11) NOT NULL COMMENT '软件ID',

  `domain` varchar(255) DEFAULT '' COMMENT '授权域名',

  `server_ip` varchar(50) DEFAULT '' COMMENT '服务器IP',

  `start_time` int(11) DEFAULT '0' COMMENT '开始时间',

  `end_time` int(11) DEFAULT '0' COMMENT '结束时间0表示永久',

  `signature` varchar(255) DEFAULT '' COMMENT '授权签名',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-有效0-无效',

  `verify_count` int(11) DEFAULT '0' COMMENT '验证次数',

  `last_verify_time` int(11) DEFAULT '0' COMMENT '最后验证时间',

  `features` text COMMENT '功能权限JSON',

  `create_time` int(11) DEFAULT '0',

  `update_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  UNIQUE KEY `license_number` (`license_number`),

  KEY `software_id` (`software_id`),

  KEY `domain` (`domain`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='授权管理表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `storage_files`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `storage_files` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) DEFAULT '0' COMMENT '上传用户ID',

  `filename` varchar(255) NOT NULL COMMENT '原始文件URL',

  `filepath` varchar(500) NOT NULL COMMENT '存储路径',

  `filesize` int(11) DEFAULT '0' COMMENT '文件大小(字节)',

  `mimetype` varchar(100) DEFAULT '' COMMENT 'MIME类型',

  `storage_type` varchar(50) DEFAULT 'local' COMMENT '存储类型',

  `md5` varchar(32) DEFAULT '' COMMENT 'MD5值',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-正常,0-已删除',

  `create_time` int(11) DEFAULT '0' COMMENT '创建时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `md5` (`md5`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `system_config`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `system_config` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `config_key` varchar(100) NOT NULL COMMENT '配置键',

  `config_value` text COMMENT '配置值',

  `config_name` varchar(100) DEFAULT NULL COMMENT '配置名称',

  `config_type` varchar(20) DEFAULT 'text' COMMENT '配置类型:text,textarea,number,select,radio,checkbox',

  `config_group` varchar(50) DEFAULT 'base' COMMENT '配置分组',

  `config_options` text COMMENT '配置选项(JSON)',

  `sort` int(11) DEFAULT '0' COMMENT '排序',

  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',

  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `config_key` (`config_key`),

  KEY `config_group` (`config_group`),

  KEY `sort` (`sort`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置表';
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `system_config` WRITE;
INSERT INTO `system_config` (`config_key`, `config_value`, `config_name`, `config_type`, `config_group`, `config_options`, `sort`, `create_time`, `update_time`) VALUES
('site_name', '圈子社区', '网站名称', 'text', 'base', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_subtitle', '连接你我，分享精彩', '网站副标题', 'text', 'base', '', 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_url', 'http://localhost', '网站地址', 'text', 'base', '', 3, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_keywords', '圈子,社区,社交', '网站关键词', 'text', 'base', '', 4, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_description', '一个优秀的社区平台', '网站描述', 'textarea', 'base', '', 5, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_icp', '', 'ICP备案号', 'text', 'base', '', 6, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_logo', '', '网站Logo', 'text', 'base', '', 7, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_favicon', '', '网站图标', 'text', 'base', '', 8, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_homimg', '', '顶部背景图片URL', 'text', 'base', '', 9, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_sign', '', '网站签名/标语', 'text', 'base', '', 10, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_copyright', '© 2025 我圈社交平台 版权所有', '版权信息', 'text', 'base', '', 11, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_title', '', '网站标题', 'text', 'seo', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('share_enabled', '1', '是否开启分享功能', 'radio', 'seo', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_status', '0', '站点状态', 'select', 'site', '[{\"label\":\"正常运行\",\"value\":\"0\"},{\"label\":\"维护中\",\"value\":\"1\"}]', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_timezone', 'Asia/Shanghai', '系统时区', 'select', 'site', '[{\"label\":\"东八区 (Asia/Shanghai)\",\"value\":\"Asia/Shanghai\"},{\"label\":\"东八区 (Asia/Hong_Kong)\",\"value\":\"Asia/Hong_Kong\"},{\"label\":\"东八区 (Asia/Taipei)\",\"value\":\"Asia/Taipei\"}]', 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('site_domain', '', '主域名', 'text', 'site', '', 3, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('static_domain', '', '静态资源域名', 'text', 'site', '', 4, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('maintenance_text', '', '维护文案', 'textarea', 'site', '', 5, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('maintenance_end', '', '预计恢复时间', 'text', 'site', '', 6, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('register_open', '1', '开启注册', 'radio', 'base', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 12, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('register_verify', '0', '注册验证', 'radio', 'base', '[{\"label\":\"需要\",\"value\":\"1\"},{\"label\":\"不需要\",\"value\":\"0\"}]', 13, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('register_phone_verify', '1', '手机号验证', 'radio', 'register', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('register_sms_verify', '1', '短信验证码验证', 'radio', 'register', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('register_captcha_verify', '0', '图形验证码验证', 'radio', 'register', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 3, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('upload_max_size', '10485760', '上传文件大小限制(字节)', 'number', 'base', '', 14, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('upload_allow_ext', 'jpg,jpeg,png,gif', '允许上传的文件扩展名', 'text', 'base', '', 15, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('allowed_types', 'jpg,jpeg,png,gif,mp4,mp3,zip,doc,docx,pdf', '允许上传的文件格式', 'text', 'upload', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('storage_type', 'local', '存储方式', 'select', 'upload', '[{\"label\":\"本地存储\",\"value\":\"local\"},{\"label\":\"阿里云OSS\",\"value\":\"oss\"},{\"label\":\"腾讯云COS\",\"value\":\"cos\"},{\"label\":\"七牛云\",\"value\":\"qiniu\"}]', 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('image_compress_enabled', '0', '启用图片压缩', 'select', 'upload', '[{\"label\":\"关闭\",\"value\":\"0\"},{\"label\":\"开启\",\"value\":\"1\"}]', 3, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('image_compress_quality', '75', '压缩质量 (1-100)', 'number', 'upload', '', 4, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('image_max_width', '1920', '最大宽度 (像素)', 'number', 'upload', '', 5, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('image_max_height', '1080', '最大高度 (像素)', 'number', 'upload', '', 6, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('oss_access_key_id', '', 'OSS AccessKey ID', 'text', 'upload', '', 7, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('oss_access_key_secret', '', 'OSS AccessKey Secret', 'text', 'upload', '', 8, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('oss_bucket', '', 'OSS Bucket', 'text', 'upload', '', 9, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('oss_endpoint', '', 'OSS Endpoint', 'text', 'upload', '', 10, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('oss_domain', '', 'OSS Bucket域名', 'text', 'upload', '', 11, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('oss_directory', 'uploads', 'OSS 存储目录', 'text', 'upload', '', 12, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('cos_secret_id', '', 'COS SecretId', 'text', 'upload', '', 13, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('cos_secret_key', '', 'COS SecretKey', 'text', 'upload', '', 14, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('cos_bucket', '', 'COS Bucket', 'text', 'upload', '', 15, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('cos_region', '', 'COS Region', 'text', 'upload', '', 16, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('cos_domain', '', 'COS Bucket域名', 'text', 'upload', '', 17, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('cos_directory', 'uploads', 'COS 存储目录', 'text', 'upload', '', 18, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('qiniu_access_key', '', '七牛 AccessKey', 'text', 'upload', '', 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('qiniu_secret_key', '', '七牛 SecretKey', 'text', 'upload', '', 20, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('qiniu_bucket', '', '七牛 Bucket', 'text', 'upload', '', 21, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('qiniu_domain', '', '七牛 Domain', 'text', 'upload', '', 22, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('qiniu_directory', 'uploads', '七牛 存储目录', 'text', 'upload', '', 23, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('user_default_avatar', '', '用户默认头像', 'text', 'base', '', 16, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('user_default_nickname', '用户', '用户默认昵称', 'text', 'base', '', 17, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('comment_audit', '0', '评论审核', 'radio', 'base', '[{\"label\":\"需要\",\"value\":\"1\"},{\"label\":\"不需要\",\"value\":\"0\"}]', 18, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('moment_audit', '0', '动态审核', 'radio', 'base', '[{\"label\":\"需要\",\"value\":\"1\"},{\"label\":\"不需要\",\"value\":\"0\"}]', 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('post_max_words', '500', '单条动态最大文字数', 'number', 'publish', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('post_max_images', '9', '最多上传图片数', 'number', 'publish', '', 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('post_max_video_size', '50', '视频文件大小限制(MB)', 'number', 'publish', '', 3, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('allow_text_only', '1', '允许发布纯文字', 'radio', 'publish', '[{\"label\":\"允许\",\"value\":\"1\"},{\"label\":\"禁止\",\"value\":\"0\"}]', 4, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('allow_image_only', '1', '允许发布纯图片', 'radio', 'publish', '[{\"label\":\"允许\",\"value\":\"1\"},{\"label\":\"禁止\",\"value\":\"0\"}]', 5, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('allow_video', '1', '允许发布视频', 'radio', 'publish', '[{\"label\":\"允许\",\"value\":\"1\"},{\"label\":\"禁止\",\"value\":\"0\"}]', 6, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('daily_post_limit', '50', '单用户单日最大发布数', 'number', 'publish', '', 7, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('repeat_post_interval', '10', '同内容重复发布间隔(分钟)', 'number', 'publish', '', 8, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('comment_max_words', '200', '评论文字数限制', 'number', 'publish', '', 9, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('allow_image_comments', '1', '允许带图片评论', 'radio', 'publish', '[{\"label\":\"允许\",\"value\":\"1\"},{\"label\":\"禁止\",\"value\":\"0\"}]', 10, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('allow_nested_comments', '1', '允许评论楼中楼', 'radio', 'publish', '[{\"label\":\"允许\",\"value\":\"1\"},{\"label\":\"禁止\",\"value\":\"0\"}]', 11, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('allow_likes', '1', '开启点赞功能', 'radio', 'publish', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 12, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('allow_anonymous_comments', '0', '允许匿名评论', 'radio', 'publish', '[{\"label\":\"允许\",\"value\":\"1\"},{\"label\":\"禁止\",\"value\":\"0\"}]', 13, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('daily_comment_limit', '200', '单用户单日最大评论数', 'number', 'publish', '', 14, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('post_moderation', '0', '动态审核', 'select', 'publish', '[{\"label\":\"无需审核\",\"value\":\"0\"},{\"label\":\"全部审核\",\"value\":\"1\"},{\"label\":\"仅新用户审核\",\"value\":\"2\"}]', 15, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('comment_moderation', '0', '评论审核', 'select', 'publish', '[{\"label\":\"无需审核\",\"value\":\"0\"},{\"label\":\"全部审核\",\"value\":\"1\"}]', 16, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('sensitive_word_action', 'replace', '敏感词触发规则', 'select', 'publish', '[{\"label\":\"替换为*\",\"value\":\"replace\"},{\"label\":\"拒绝发布\",\"value\":\"refuse\"},{\"label\":\"标记待审核\",\"value\":\"moderate\"}]', 17, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('post_default_sort', 'latest', '动态流默认排序', 'select', 'publish', '[{\"label\":\"最新发布\",\"value\":\"latest\"},{\"label\":\"热度排序\",\"value\":\"popular\"}]', 18, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('post_default_visibility', 'public', '默认动态可见范围', 'select', 'publish', '[{\"label\":\"全部用户\",\"value\":\"public\"},{\"label\":\"仅粉丝\",\"value\":\"followers\"},{\"label\":\"仅自己\",\"value\":\"private\"}]', 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('post_edit_limit', '24', '用户可编辑动态时限(小时)', 'number', 'publish', '', 20, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('post_delete_limit', '24', '用户可删除动态时限(小时)', 'number', 'publish', '', 21, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('message_enabled', '1', '消息功能', 'radio', 'social', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('topic_enabled', '1', '话题功能', 'radio', 'social', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('follow_enabled', '1', '关注功能', 'radio', 'social', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 3, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('comment_enabled', '1', '评论功能', 'radio', 'social', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 4, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('like_enabled', '1', '点赞功能', 'radio', 'social', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 5, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('group_enabled', '1', '好友分组功能', 'radio', 'social', '[{\"label\":\"开启\",\"value\":\"1\"},{\"label\":\"关闭\",\"value\":\"0\"}]', 6, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
UNLOCK TABLES;





DROP TABLE IF EXISTS `system_messages`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `system_messages` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `title` varchar(255) NOT NULL COMMENT '消息标题',

  `content` text COMMENT '消息内容',

  `type` varchar(50) DEFAULT 'system' COMMENT '消息类型',

  `target_user` int(11) DEFAULT '0' COMMENT '目标用户 0全部',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `task`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `task` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(100) NOT NULL COMMENT '任务名称',

  `description` varchar(500) NOT NULL COMMENT '任务描述',

  `points` int(11) NOT NULL DEFAULT '0' COMMENT '任务积分',

  `daily_limit` int(11) NOT NULL DEFAULT '1' COMMENT '每日完成次数限制',

  `type` varchar(20) NOT NULL COMMENT '任务类型：daily, growth',

  `icon` varchar(50) NOT NULL DEFAULT 'fa-tasks' COMMENT '任务图标',

  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',

  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：1-激活0-未激活',

  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',

  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,

  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任务表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `task_record`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `task_record` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `task_id` int(11) NOT NULL COMMENT '任务ID',

  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：1-已完成0-未完成',

  `points` int(11) NOT NULL DEFAULT '0' COMMENT '获得积分',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `task_id` (`task_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任务记录表';

/*!40101 SET character_set_client = @saved_cs_client */;



DROP TABLE IF EXISTS `task_completion`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `task_completion` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `task_id` int(11) NOT NULL COMMENT '任务ID',

  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '完成时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `task_id` (`task_id`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任务完成记录表';

/*!40101 SET character_set_client = @saved_cs_client */;



DROP TABLE IF EXISTS `theme_rules`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `theme_rules` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `rule_name` varchar(100) NOT NULL COMMENT '规则名称',

  `rule_type` tinyint(4) DEFAULT '0' COMMENT '规则类型:0-主题使用权限,1-主题可见性',

  `rule_content` json DEFAULT NULL COMMENT '规则内容',

  `status` tinyint(4) DEFAULT '1' COMMENT '规则状态-禁用,1-启用',

  `create_time` int(11) DEFAULT NULL,

  `update_time` int(11) DEFAULT NULL,

  PRIMARY KEY (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='主题规则表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `theme_templates`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `theme_templates` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(100) NOT NULL COMMENT '主题名称',

  `description` text COMMENT '主题描述',

  `type` tinyint(4) DEFAULT '0' COMMENT '主题类型:0-官方主题,1-用户自定义主题',

  `style` json DEFAULT NULL COMMENT '主题样式配置',

  `config` json DEFAULT NULL COMMENT '主题配置参数',

  `status` tinyint(4) DEFAULT '1' COMMENT '主题状态-禁用,1-启用',

  `is_default` tinyint(4) DEFAULT '0' COMMENT '是否默认主题:0-否1-是',

  `create_time` int(11) DEFAULT NULL,

  `update_time` int(11) DEFAULT NULL,

  PRIMARY KEY (`id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='主题模板表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `topic_follows`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `topic_follows` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `topic_id` int(11) NOT NULL COMMENT '话题ID',

  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',

  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_topic` (`user_id`,`topic_id`),

  KEY `topic_id` (`topic_id`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='话题关注表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `topics`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `topics` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(50) NOT NULL COMMENT '话题名称',

  `description` varchar(200) DEFAULT NULL COMMENT '话题描述',

  `cover` varchar(255) DEFAULT NULL COMMENT '话题封面',

  `post_count` int(11) DEFAULT '0' COMMENT '帖子数',

  `follower_count` int(11) DEFAULT '0' COMMENT '关注人数',

  `is_hot` tinyint(1) DEFAULT '0' COMMENT '是否热门:0-否1-是',

  `sort` int(11) DEFAULT '0' COMMENT '排序',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-正常,0-禁用',

  `sort_order` int(11) DEFAULT '0' COMMENT '排序',

  PRIMARY KEY (`id`),

  UNIQUE KEY `name` (`name`),

  KEY `is_hot` (`is_hot`),

  KEY `post_count` (`post_count`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='话题表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `uploads`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `uploads` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `file_name` varchar(255) NOT NULL COMMENT '文件URL',

  `file_path` varchar(500) NOT NULL COMMENT '文件路径',

  `file_size` bigint(20) DEFAULT NULL COMMENT '文件大小(字节)',

  `file_type` varchar(50) DEFAULT NULL COMMENT '文件类型',

  `file_ext` varchar(10) DEFAULT NULL COMMENT '文件扩展名',

  `mime_type` varchar(100) DEFAULT NULL COMMENT 'MIME类型',

  `width` int(11) DEFAULT NULL COMMENT '图片宽度',

  `height` int(11) DEFAULT NULL COMMENT '图片高度',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `file_type` (`file_type`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件上传表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID',

  `username` varchar(50) NOT NULL COMMENT '用户名',

  `password` varchar(255) NOT NULL COMMENT '密码(bcrypt加密)',

  `email` varchar(100) DEFAULT NULL COMMENT '邮箱',

  `mobile` varchar(20) DEFAULT NULL COMMENT '手机号码',

  `name` varchar(50) DEFAULT '' COMMENT '真实姓名',

  `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称',

  `avatar` varchar(255) DEFAULT NULL COMMENT '头像URL',

  `chat_background` varchar(255) DEFAULT NULL COMMENT '聊天背景图',

  `chat_opacity` tinyint(4) DEFAULT '90' COMMENT '聊天背景透明度(0-100)',

  `real_name` varchar(50) DEFAULT NULL COMMENT '真实姓名',

  `gender` tinyint(1) DEFAULT '0' COMMENT '性别:0未知,1-男2-女',

  `birthday` date DEFAULT NULL COMMENT '生日',

  `bio` varchar(500) DEFAULT NULL COMMENT '个人简介',

  `occupation` varchar(100) DEFAULT NULL COMMENT '职业',

  `interests` text COMMENT '兴趣爱好JSON',

  `province` varchar(50) DEFAULT '' COMMENT '省份',

  `city` varchar(50) DEFAULT '' COMMENT '城市',

  `district` varchar(50) DEFAULT '' COMMENT '区县',

  `url` varchar(255) DEFAULT NULL COMMENT '个人网址',

  `homeimg` varchar(255) DEFAULT NULL COMMENT '主页背景图',

  `sign` varchar(500) DEFAULT NULL COMMENT '个性签名',

  `card_background` varchar(255) DEFAULT NULL COMMENT '名片背景图',

  `card_theme_color` varchar(20) DEFAULT '#1890ff' COMMENT '名片主题色',

  `card_layout` varchar(50) DEFAULT 'default' COMMENT '名片布局模板',

  `card_privacy` text COMMENT '隐私设置JSON',

  `card_stealth` tinyint(1) DEFAULT '0' COMMENT '隐身访问:0-否1-是',

  `vip_level` tinyint(1) DEFAULT '0' COMMENT 'VIP等级',

  `coins` int(11) DEFAULT '0' COMMENT '金币数量',

  `experience` int(11) DEFAULT '0' COMMENT '经验值',

  `level` int(11) DEFAULT '1' COMMENT '用户等级',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态正常,0禁用',

  `is_online` tinyint(1) DEFAULT '0' COMMENT '是否在线:0离线,1在线',

  `last_heartbeat_time` int(11) DEFAULT NULL COMMENT '最后心跳时间',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  `update_time` int(11) DEFAULT NULL COMMENT '更新时间',

  `last_login_time` int(11) DEFAULT NULL COMMENT '最后登录时间',

  `last_login_ip` varchar(50) DEFAULT NULL COMMENT '最后登录IP',

  `register_ip` varchar(45) DEFAULT '' COMMENT '注册IP',

  `banned_until` timestamp NULL DEFAULT NULL COMMENT '封禁截止时间',

  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',

  `device_info` text COMMENT '设备信息JSON',

  `regtime` int(11) DEFAULT '0' COMMENT '注册时间',

  `regip` varchar(50) DEFAULT '' COMMENT '注册IP',

  `logtime` int(11) DEFAULT '0' COMMENT '最后登录时间',

  `logip` varchar(50) DEFAULT '' COMMENT '最后登录IP',

  `can_speak` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否允许发言',

  `last_latitude` decimal(10,7) DEFAULT NULL COMMENT '最后位置纬度',

  `last_longitude` decimal(10,7) DEFAULT NULL COMMENT '最后位置经度',

  `last_city` varchar(50) DEFAULT NULL COMMENT '最后位置城市',

  `last_location_time` int(11) DEFAULT NULL COMMENT '最后位置更新时间',

  `post_count` int(11) DEFAULT '0' COMMENT '帖子数量',

  `comment_count` int(11) DEFAULT '0' COMMENT '评论数量',

  `like_count` int(11) DEFAULT '0' COMMENT '点赞数量',

  `follow_count` int(11) DEFAULT '0' COMMENT '关注数量',

  `follower_count` int(11) DEFAULT '0' COMMENT '粉丝数量',

  `favorite_count` int(11) DEFAULT '0' COMMENT '收藏数量',

  PRIMARY KEY (`id`),

  UNIQUE KEY `username` (`username`),

  KEY `email` (`email`),

  KEY `mobile` (`mobile`),

  KEY `nickname` (`nickname`),

  KEY `status` (`status`),

  KEY `level` (`level`),

  KEY `idx_username` (`username`),

  KEY `idx_nickname` (`nickname`),

  KEY `is_online` (`is_online`),

  KEY `last_heartbeat_time` (`last_heartbeat_time`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户名';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_currency`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_currency` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `user_id` int(11) DEFAULT NULL COMMENT '用户ID',

  `currency_id` int(11) DEFAULT NULL COMMENT '货币类型ID',

  `amount` decimal(15,2) DEFAULT '0.00' COMMENT '数量',

  `freeze_amount` decimal(15,2) DEFAULT '0.00' COMMENT '冻结数量',

  `update_time` int(11) DEFAULT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_currency` (`user_id`,`currency_id`),

  KEY `user_id` (`user_id`),

  KEY `currency_id` (`currency_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户货币表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_group_relation`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_group_relation` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `group_id` int(11) unsigned NOT NULL COMMENT '分组ID',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_group` (`user_id`,`group_id`),

  KEY `group_id` (`group_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户分组关联表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_groups`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_groups` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(50) NOT NULL COMMENT '分组名称',

  `description` text COMMENT '分组描述',

  `is_system` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否系统分组',

  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  `update_time` int(11) NOT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `name` (`name`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户分组表';

LOCK TABLES `user_groups` WRITE;
INSERT INTO `user_groups` (`name`, `description`, `is_system`, `sort`, `create_time`, `update_time`) VALUES
('默认分组', '系统默认分组', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('VIP用户', 'VIP会员用户分组', 1, 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('管理员', '系统管理员分组', 1, 3, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('活跃用户', '社区活跃用户', 1, 4, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('大神用户', '社区资深大神', 1, 5, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('新用户', '新注册用户', 1, 6, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
UNLOCK TABLES;

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_locations`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_locations` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `latitude` decimal(10,7) NOT NULL COMMENT '纬度',

  `longitude` decimal(10,7) NOT NULL COMMENT '经度',

  `address` varchar(255) DEFAULT NULL COMMENT '详细地址',

  `city` varchar(50) DEFAULT NULL COMMENT '城市',

  `district` varchar(50) DEFAULT NULL COMMENT '区县',

  `ip` varchar(45) DEFAULT NULL COMMENT 'IP地址',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  `update_time` int(11) DEFAULT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `latitude` (`latitude`),

  KEY `longitude` (`longitude`),

  KEY `city` (`city`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户位置记录表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_points`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_points` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',

  `points` int(11) NOT NULL DEFAULT '0' COMMENT '积分数量',

  `total_points` int(11) NOT NULL DEFAULT '0' COMMENT '总积分',

  `available_points` int(11) NOT NULL DEFAULT '0' COMMENT '可用积分',

  `frozen_points` int(11) NOT NULL DEFAULT '0' COMMENT '冻结积分',

  `level` int(11) NOT NULL DEFAULT '1' COMMENT '等级',

  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',

  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_id` (`user_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户积分表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_profiles`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_profiles` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `real_name` varchar(50) DEFAULT '' COMMENT '真实姓名',

  `id_card` varchar(20) DEFAULT '' COMMENT '身份证号',

  `education` varchar(20) DEFAULT '' COMMENT '学历',

  `occupation` varchar(50) DEFAULT '' COMMENT '职业',

  `company` varchar(100) DEFAULT '' COMMENT '公司',

  `income_range` varchar(20) DEFAULT '' COMMENT '收入范围',

  `hobby_tags` text COMMENT '兴趣爱好标签JSON',

  `signature` varchar(500) DEFAULT '' COMMENT '个性签名',

  `website` varchar(255) DEFAULT '' COMMENT '个人网站',

  `social_wechat` varchar(50) DEFAULT '' COMMENT '微信账号',

  `social_qq` varchar(20) DEFAULT '' COMMENT 'QQ账号',

  `social_weibo` varchar(100) DEFAULT '' COMMENT '微博',

  `background_image` varchar(500) DEFAULT '' COMMENT '主页背景图',

  `theme_preference` varchar(20) DEFAULT 'default' COMMENT '主题偏好',

  `privacy_settings` text COMMENT '隐私设置JSON',

  `notification_settings` text COMMENT '通知设置JSON',

  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',

  `update_time` timestamp NULL DEFAULT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_id` (`user_id`),

  KEY `real_name` (`real_name`),

  KEY `occupation` (`occupation`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户详情表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_punishments`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_punishments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `violation_id` int(11) NOT NULL COMMENT '关联违规记录ID',

  `punishment_type` varchar(50) NOT NULL COMMENT '惩罚类型:warning,ban_speak,ban_login,ban_forever',

  `punishment_reason` varchar(255) NOT NULL COMMENT '惩罚原因',

  `start_time` int(11) NOT NULL COMMENT '惩罚开始时间',

  `end_time` int(11) DEFAULT NULL COMMENT '惩罚结束时间(永久封禁为NULL)',

  `operator_id` int(11) NOT NULL COMMENT '操作管理员ID',

  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态1-生效0-已过期2-已解除',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `violation_id` (`violation_id`),

  KEY `punishment_type` (`punishment_type`),

  KEY `status` (`status`),

  KEY `start_time` (`start_time`),

  KEY `end_time` (`end_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户惩罚记录表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_tag_relation`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_tag_relation` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `tag_id` int(11) NOT NULL COMMENT '标签ID',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `tag_id` (`tag_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户标签关联表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_tags`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_tags` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `name` varchar(50) NOT NULL COMMENT '标签名称',

  `color` varchar(20) DEFAULT '#3B82F6' COMMENT '标签颜色',

  `description` text COMMENT '标签描述',

  `create_time` int(11) NOT NULL COMMENT '创建时间',

  `update_time` int(11) NOT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户标签表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_themes`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_themes` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `theme_id` int(11) NOT NULL COMMENT '主题ID',

  `custom_config` json DEFAULT NULL COMMENT '用户自定义主题配置',

  `create_time` int(11) DEFAULT NULL,

  `update_time` int(11) DEFAULT NULL,

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_id` (`user_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户主题表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_violations`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_violations` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `violation_type` varchar(50) NOT NULL COMMENT '违规类型:spam,abuse,fraud等',

  `violation_reason` varchar(255) NOT NULL COMMENT '违规原因',

  `violation_content` text COMMENT '违规内容',

  `violation_time` int(11) NOT NULL COMMENT '违规时间',

  `violation_ip` varchar(45) NOT NULL COMMENT '违规IP',

  `operator_id` int(11) NOT NULL COMMENT '操作管理员ID',

  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态0-待处理1-已处理',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `violation_type` (`violation_type`),

  KEY `status` (`status`),

  KEY `violation_time` (`violation_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户违规记录表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_vip`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_vip` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `user_id` int(11) DEFAULT NULL COMMENT '用户ID',

  `level_id` int(11) DEFAULT NULL COMMENT '等级ID',

  `start_time` int(11) DEFAULT NULL COMMENT '开始时间',

  `end_time` int(11) DEFAULT NULL COMMENT '结束时间',

  `is_permanent` int(1) DEFAULT '0' COMMENT '是否永久',

  `auto_renew` int(1) DEFAULT '0' COMMENT '是否自动续费',

  `create_time` int(11) DEFAULT NULL COMMENT '开通时间',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `end_time` (`end_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户VIP表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_wallet`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `user_wallet` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '用户ID',

  `balance` decimal(10,2) DEFAULT '0.00' COMMENT '余额',

  `frozen` decimal(10,2) DEFAULT '0.00' COMMENT '冻结金额',

  `total_income` decimal(10,2) DEFAULT '0.00' COMMENT '总收入',

  `total_expenditure` decimal(10,2) DEFAULT '0.00' COMMENT '总支出',

  `create_time` int(11) DEFAULT '0',

  `update_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  UNIQUE KEY `user_id` (`user_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `version_logs`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `version_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `version_id` int(11) NOT NULL COMMENT '版本ID',

  `log_type` varchar(50) DEFAULT 'update' COMMENT '日志类型',

  `content` text COMMENT '日志内容',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  KEY `version_id` (`version_id`),

  KEY `create_time` (`create_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `versions`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `versions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `version_code` varchar(50) NOT NULL COMMENT '版本号',

  `version_name` varchar(100) NOT NULL COMMENT '版本名称',

  `download_url` varchar(500) DEFAULT '' COMMENT '下载地址',

  `update_log` text COMMENT '更新日志',

  `file_size` bigint(20) DEFAULT '0' COMMENT '文件大小',

  `force_update` tinyint(1) DEFAULT '0' COMMENT '是否强制更新',

  `status` tinyint(1) DEFAULT '1' COMMENT '状态1-启用0-禁用',

  `create_time` int(11) DEFAULT '0',

  PRIMARY KEY (`id`),

  KEY `version_code` (`version_code`),

  KEY `status` (`status`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理系统表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `user_level`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_level` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `level` int(11) NOT NULL COMMENT '等级',
  `name` varchar(50) NOT NULL COMMENT '等级名称',
  `required_points` int(11) DEFAULT '0' COMMENT '所需积分',
  `icon` text COMMENT '等级图标',
  `description` varchar(500) DEFAULT '' COMMENT '等级描述',
  `privileges` text COMMENT '等级特权(JSON格式)',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态启用0禁用',
  `create_time` int(11) DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `level` (`level`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户等级表';
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `user_level` WRITE;
INSERT INTO `user_level` (`level`, `name`, `required_points`, `icon`, `description`, `privileges`, `status`, `create_time`, `update_time`) VALUES
(1, '新手', 0, 'fas fa-seedling', '新注册用户', '[\"可以发布动态\",\"可以关注用户\",\"可以评论\"]', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(2, '初级用户', 100, 'fas fa-leaf', '活跃参与社区', '[\"可以发布动态\",\"可以关注用户\",\"可以评论\",\"可以点赞\"]', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(3, '中级用户', 500, 'fas fa-tree', '社区活跃成员', '[\"可以发布动态\",\"可以关注用户\",\"可以评论\",\"可以点赞\",\"可以收藏\"]', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(4, '高级用户', 2000, 'fas fa-star', '社区核心成员', '[\"可以发布动态\",\"可以关注用户\",\"可以评论\",\"可以点赞\",\"可以收藏\",\"可以创建话题\"]', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(5, '资深用户', 5000, 'fas fa-crown', '社区资深成员', '[\"可以发布动态\",\"可以关注用户\",\"可以评论\",\"可以点赞\",\"可以收藏\",\"可以创建话题\",\"可以创建群组\"]', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
UNLOCK TABLES;

DROP TABLE IF EXISTS `vip_levels`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `vip_levels` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `name` varchar(50) DEFAULT NULL COMMENT '等级名称',

  `max_moments` int(11) DEFAULT '10' COMMENT '每日最大发布数',

  `max_images` int(11) DEFAULT '9' COMMENT '每次最大上传图片数',

  `price_month` decimal(10,2) DEFAULT NULL COMMENT '月费',

  `price_quarter` decimal(10,2) DEFAULT NULL COMMENT '季费',

  `price_year` decimal(10,2) DEFAULT NULL COMMENT '年费',

  `price_permanent` decimal(10,2) DEFAULT NULL COMMENT '永久费用',

  `privileges` text COMMENT '其他特权JSON',

  `sort` int(11) DEFAULT '0' COMMENT '排序',

  `status` int(1) DEFAULT '1' COMMENT '状态-禁用1-启用',

  PRIMARY KEY (`id`)

) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VIP等级表';
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `vip_levels` WRITE;
INSERT INTO `vip_levels` (`name`, `max_moments`, `max_images`, `price_month`, `price_quarter`, `price_year`, `price_permanent`, `privileges`, `sort`, `status`) VALUES
('普通会员', 10, 9, 0.00, 0.00, 0.00, 0.00, '[]', 0, 1),
('VIP月卡', 20, 9, 9.90, 0.00, 0.00, 0.00, '[\"每日发布20条动态\",\"专属标识\"]', 1, 1),
('VIP季卡', 20, 9, 0.00, 29.90, 0.00, 0.00, '[\"每日发布20条动态\",\"专属标识\",\"优先推荐\"]', 2, 1),
('VIP年卡', 50, 9, 0.00, 0.00, 99.90, 0.00, '[\"每日发布50条动态\",\"专属标识\",\"优先推荐\",\"专属客服\"]', 3, 1),
('VIP永久', 100, 9, 0.00, 0.00, 0.00, 299.90, '[\"每日发布100条动态\",\"专属标识\",\"优先推荐\",\"专属客服\",\"永久特权\"]', 4, 1);
UNLOCK TABLES;





DROP TABLE IF EXISTS `vip_orders`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `vip_orders` (

  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,

  `order_no` varchar(32) DEFAULT NULL COMMENT '订单号',

  `user_id` int(11) DEFAULT NULL COMMENT '用户ID',

  `level_id` int(11) DEFAULT NULL COMMENT '等级ID',

  `duration_type` varchar(20) DEFAULT NULL COMMENT '时长类型 month/quarter/year/permanent',

  `amount` decimal(10,2) DEFAULT NULL COMMENT '支付金额',

  `pay_status` int(1) DEFAULT '0' COMMENT '支付状态0-未支付1-已支付',

  `pay_type` varchar(20) DEFAULT NULL COMMENT '支付方式',

  `pay_time` int(11) DEFAULT NULL COMMENT '支付时间',

  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',

  PRIMARY KEY (`id`),

  UNIQUE KEY `order_no` (`order_no`),

  KEY `user_id` (`user_id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VIP订单表';

/*!40101 SET character_set_client = @saved_cs_client */;





DROP TABLE IF EXISTS `visitors`;

/*!40101 SET @saved_cs_client     = @@character_set_client */;

/*!40101 SET character_set_client = utf8 */;

CREATE TABLE `visitors` (

  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL COMMENT '被访问用户ID',

  `visitor_id` int(11) NOT NULL COMMENT '访客用户ID',

  `visit_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '访问时间',

  `ip` varchar(45) DEFAULT NULL COMMENT '访客IP地址',

  `user_agent` text COMMENT '访客浏览器信息',

  PRIMARY KEY (`id`),

  KEY `user_id` (`user_id`),

  KEY `visitor_id` (`visitor_id`),

  KEY `visit_time` (`visit_time`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='访客表';

/*!40101 SET character_set_client = @saved_cs_client */;



-- Final view structure for view `comment_list`


/*!50001 DROP VIEW IF EXISTS `comment_list`*/;

/*!50001 SET @saved_cs_client          = @@character_set_client */;

/*!50001 SET @saved_cs_results         = @@character_set_results */;

/*!50001 SET @saved_col_connection     = @@collation_connection */;

/*!50001 SET character_set_client      = utf8 */;

/*!50001 SET character_set_results     = utf8 */;

/*!50001 SET collation_connection      = utf8_general_ci */;

/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50013 DEFINER=`quanzi`@`localhost` SQL SECURITY DEFINER */

/*!50001 VIEW `comment_list` AS select `c`.`id` AS `id`,`c`.`moment_id` AS `moment_id`,`c`.`user_id` AS `user_id`,`c`.`content` AS `content`,`c`.`likes` AS `likes`,`c`.`replies` AS `replies`,`c`.`status` AS `status`,`c`.`is_top` AS `is_top`,`c`.`is_hot` AS `is_hot`,`c`.`is_author` AS `is_author`,`c`.`reply_to_user_id` AS `reply_to_user_id`,`c`.`reply_to_nickname` AS `reply_to_nickname`,`c`.`media` AS `media`,`c`.`create_time` AS `create_time`,`c`.`update_time` AS `update_time`,`u`.`username` AS `username`,`u`.`nickname` AS `nickname`,`u`.`avatar` AS `avatar`,(case when (`c`.`user_id` = `m`.`user_id`) then 1 else 0 end) AS `is_moment_author` from ((`comments` `c` left join `user` `u` on((`c`.`user_id` = `u`.`id`))) left join `moments` `m` on((`c`.`moment_id` = `m`.`id`))) */;

/*!50001 SET character_set_client      = @saved_cs_client */;

/*!50001 SET character_set_results     = @saved_cs_results */;

/*!50001 SET collation_connection      = @saved_col_connection */;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;



/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;

/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;

/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;

/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;

/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;




DROP TABLE IF EXISTS `user_notification_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_notification_settings` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
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
  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
  `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户通知设置表';
/*!40101 SET character_set_client = @saved_cs_client */;



DROP TABLE IF EXISTS `user_realname_auth`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_realname_auth` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `real_name` varchar(50) NOT NULL COMMENT '真实姓名',
  `id_card` varchar(18) NOT NULL COMMENT '身份证号',
  `id_card_front` varchar(255) DEFAULT NULL COMMENT '身份证正面照片',
  `id_card_back` varchar(255) DEFAULT NULL COMMENT '身份证背面照片',
  `handheld_id_card` varchar(255) DEFAULT NULL COMMENT '手持身份证照片',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态0-待审核1-审核通过,2-审核拒绝',
  `reject_reason` varchar(500) DEFAULT NULL COMMENT '拒绝原因',
  `audit_time` int(11) DEFAULT NULL COMMENT '审核时间',
  `auditor_id` int(11) DEFAULT NULL COMMENT '审核人ID',
  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
  `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_id` (`user_id`),
  KEY `idx_id_card` (`id_card`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户实名认证表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `call_records`
--

DROP TABLE IF EXISTS `call_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `call_records` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '通话记录ID',
  `caller_id` int(11) NOT NULL COMMENT '发起人用户ID',
  `caller_name` varchar(50) DEFAULT '' COMMENT '发起人昵称',
  `callee_id` int(11) NOT NULL COMMENT '接收人用户ID',
  `callee_name` varchar(50) DEFAULT '' COMMENT '接收人昵称',
  `call_type` tinyint(1) DEFAULT '1' COMMENT '通话类型:1-语音通话2-视频通话',
  `status` tinyint(1) DEFAULT '0' COMMENT '通话状态:0-未接通1-已接通2-已挂断',
  `duration` int(11) DEFAULT '0' COMMENT '通话时长(秒)',
  `room_id` varchar(100) DEFAULT '' COMMENT 'WebRTC房间ID',
  `create_time` int(11) NOT NULL COMMENT '发起时间',
  `end_time` int(11) DEFAULT NULL COMMENT '结束时间',
  PRIMARY KEY (`id`),
  KEY `idx_caller_id` (`caller_id`),
  KEY `idx_callee_id` (`callee_id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_status` (`status`),
  KEY `idx_call_type` (`call_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通话记录表';
/*!40101 SET character_set_client = @saved_cs_client */;

