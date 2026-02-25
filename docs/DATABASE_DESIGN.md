# 数据库设计文档

## 文档概述

本文档详细描述了社交应用系统的数据库设计，包括所有数据表的结构、字段说明、索引设计、关联关系等信息。数据库采用MySQL存储引擎，使用InnoDB引擎支持事务和外键约束。

## 数据库基本信息

### 数据库配置

- **数据库名**: quanzi
- **字符集**: utf8mb4
- **排序规则**: utf8mb4_unicode_ci
- **存储引擎**: InnoDB
- **MySQL版本**: 5.7.26+

### 命名规范

- **表名**: 使用小写字母和下划线，采用复数形式，如 `users`, `moments`
- **字段名**: 使用小写字母和下划线，如 `user_id`, `create_time`
- **索引名**: 以 `idx_` 或 `uk_` 开头，如 `idx_user_id`, `uk_username`
- **主键**: 统一使用 `id` 字段作为主键，类型为 `int(11) unsigned` 或 `bigint(20) unsigned`
- **时间字段**: 统一使用 `create_time` 和 `update_time`，类型为 `int(11)` 或 `timestamp`

## 数据表列表

系统共包含以下数据表（按功能模块分类）：

### 用户模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| user | 用户表 | 存储用户基本信息 |
| user_extend | 用户扩展表 | 存储用户扩展信息 |
| login_logs | 登录日志表 | 记录用户登录历史 |
| card_visitors | 名片访客记录表 | 记录用户名片访问记录 |

### 动态模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| moments | 动态表 | 存储用户发布的动态内容 |
| moment_likes | 动态点赞表 | 记录动态点赞关系 |
| moment_topics | 动态话题关联表 | 记录动态与话题的关联 |
| moment_drafts | 动态草稿表 | 存储用户未发布的动态草稿 |
| hidden_moments | 隐藏动态记录表 | 记录用户隐藏的动态 |

### 评论模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| comments | 评论表 | 存储动态评论内容 |
| comment_likes | 评论点赞表 | 记录评论点赞关系 |
| comm | 评论表（旧版） | 兼容旧版评论系统 |

### 消息模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| messages | 私信消息表 | 存储用户私信内容 |
| message_favorites | 消息收藏表 | 记录用户收藏的消息 |
| message_templates | 消息模板表 | 存储系统消息模板 |

### 通知模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| notifications | 通知表 | 存储系统通知内容 |

### 关注模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| follows | 关注表 | 记录用户关注关系 |
| friend_groups | 好友分组表 | 存储用户好友分组信息 |
| friend_group_members | 好友分组成员表 | 记录好友分组关系 |

### 收藏模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| favorites | 收藏表 | 记录用户收藏内容 |
| favorite_folders | 收藏夹表 | 存储用户收藏夹信息 |

### 话题模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| topics | 话题表 | 存储话题信息 |
| topic_follows | 话题关注表 | 记录用户关注话题关系 |

### 搜索模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| hot_searches | 热搜榜表 | 存储热门搜索关键词 |

### 活动模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| activities | 活动表 | 存储活动信息 |
| activity_participants | 活动参与表 | 记录活动参与关系 |

### 文章模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| articles | 文章表 | 存储文章内容 |
| article_categories | 文章分类表 | 存储文章分类信息 |

### 表情模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| emojis | 表情表 | 存储表情信息 |
| emoji_usage | 表情使用记录表 | 记录表情使用情况 |

### 钱包模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| wallets | 钱包表 | 存储用户钱包信息 |
| wallet_transactions | 钱包交易表 | 记录钱包交易记录 |
| currency_types | 货币类型表 | 存储货币类型信息 |
| currency_logs | 货币日志表 | 记录货币变动记录 |
| withdraw_records | 提现记录表 | 记录用户提现申请 |

### 积分模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| user_points | 用户积分表 | 存储用户积分信息 |
| point_logs | 积分日志表 | 记录积分变动记录 |
| point_rules | 积分规则表 | 存储积分规则信息 |
| point_exchange_items | 积分兑换物品表 | 存储可兑换物品信息 |
| point_exchange_orders | 积分兑换订单表 | 记录积分兑换订单 |

### 等级模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| levels | 等级表 | 存储等级信息 |
| user_levels | 用户等级表 | 记录用户等级关系 |

### 任务模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| tasks | 任务表 | 存储任务信息 |
| user_tasks | 用户任务表 | 记录用户任务完成情况 |

### @提及模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| mentions | @提及表 | 记录动态中的@提及 |

### 黑名单模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| blacklist | 黑名单表 | 记录用户拉黑关系 |
| blocked_users | 屏蔽用户表 | 记录用户屏蔽关系 |

### 系统模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| admin | 管理员表 | 存储管理员信息 |
| admin_log | 管理员操作日志表 | 记录管理员操作历史 |
| announcements | 系统公告表 | 存储系统公告信息 |
| system_config | 系统配置表 | 存储系统配置信息 |
| error_log | 错误日志表 | 记录系统错误信息 |
| operation_log | 操作日志表 | 记录用户操作历史 |

### API模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| api_keys | API密钥表 | 存储API密钥信息 |
| api_calls | API调用记录表 | 记录API调用历史 |

### 定时任务模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| cron_jobs | 定时任务表 | 存储定时任务信息 |
| cron_records | 定时任务记录表 | 记录定时任务执行历史 |

### 其他模块

| 表名 | 说明 | 功能 |
|-----|------|------|
| link | 友链表 | 存储友情链接信息 |
| categories | 分类表 | 存储分类信息 |
| faqs | 常见问题表 | 存储常见问题信息 |
| faq_categories | 常见问题分类表 | 存储常见问题分类信息 |
| card_templates | 名片模板表 | 存储名片模板信息 |
| chat_settings | 聊天设置表 | 存储用户聊天设置信息 |
| configx | 配置表 | 存储系统配置信息 |
| essay | 文章表（旧版） | 兼容旧版文章系统 |

## 核心数据表详解

### 1. 用户表 (user)

存储用户基本信息。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 用户ID（主键） |
| username | varchar | 50 | 否 | '' | 用户名 |
| password | varchar | 255 | 否 | '' | 密码（bcrypt加密） |
| nickname | varchar | 50 | 否 | '' | 昵称 |
| avatar | varchar | 255 | 否 | '' | 头像 |
| email | varchar | 100 | 否 | '' | 邮箱 |
| phone | varchar | 20 | 否 | '' | 手机号 |
| gender | tinyint | 1 | 否 | 0 | 性别：0-保密，1-男，2-女 |
| birthday | date | - | 是 | NULL | 生日 |
| bio | varchar | 500 | 否 | '' | 个人简介 |
| location | varchar | 100 | 否 | '' | 所在地 |
| website | varchar | 255 | 否 | '' | 个人网站 |
| level | int | 11 | 否 | 1 | 用户等级 |
| points | int | 11 | 否 | 0 | 积分 |
| balance | decimal | 10,2 | 否 | 0.00 | 余额 |
| status | tinyint | 1 | 否 | 1 | 状态：0-禁用，1-正常 |
| is_verified | tinyint | 1 | 否 | 0 | 是否认证：0-否，1-是 |
| is_vip | tinyint | 1 | 否 | 0 | 是否VIP：0-否，1-是 |
| vip_expire_time | int | 11 | 否 | 0 | VIP过期时间 |
| last_login_ip | varchar | 45 | 否 | '' | 最后登录IP |
| last_login_time | int | 11 | 否 | 0 | 最后登录时间 |
| create_time | int | 11 | 否 | 0 | 创建时间 |
| update_time | int | 11 | 否 | 0 | 更新时间 |
| deleted_at | timestamp | - | 是 | NULL | 软删除时间 |

**索引**:

- PRIMARY KEY (`id`)
- UNIQUE KEY `username` (`username`)
- UNIQUE KEY `email` (`email`)
- UNIQUE KEY `phone` (`phone`)
- KEY `status` (`status`)
- KEY `level` (`level`)
- KEY `create_time` (`create_time`)

### 2. 动态表 (moments)

存储用户发布的动态内容。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 动态ID（主键） |
| user_id | int | 11 | 否 | 1 | 用户ID |
| nickname | varchar | 50 | 否 | '' | 用户昵称 |
| avatar | varchar | 255 | 是 | NULL | 用户头像 |
| content | text | - | 是 | NULL | 动态内容 |
| images | longtext | - | 是 | NULL | 图片列表JSON |
| videos | mediumtext | - | 是 | NULL | 视频列表JSON |
| location | varchar | 100 | 否 | '' | 位置信息 |
| latitude | decimal | 10,7 | 是 | NULL | 纬度 |
| longitude | decimal | 10,7 | 是 | NULL | 经度 |
| type | tinyint | 1 | 否 | 1 | 动态类型：1-文本，2-图片，3-视频，4-链接 |
| privacy | tinyint | 1 | 否 | 1 | 隐私设置：1-公开，2-私密，3-仅好友可见，4-部分可见 |
| is_top | tinyint | 1 | 否 | 0 | 是否置顶 |
| top_expire_time | timestamp | - | 是 | NULL | 置顶过期时间 |
| is_recommend | tinyint | 1 | 否 | 0 | 是否推荐 |
| likes | int | 11 | 否 | 0 | 点赞数 |
| comments | int | 11 | 否 | 0 | 评论数 |
| share_count | int | 11 | 否 | 0 | 分享次数 |
| shares | int | 11 | 否 | 0 | 分享数 |
| views | int | 11 | 否 | 0 | 浏览数 |
| collect_count | int | 11 | 否 | 0 | 收藏数 |
| publish_time | timestamp | - | 否 | CURRENT_TIMESTAMP | 发布时间 |
| create_time | int | 11 | 是 | NULL | 创建时间 |
| status | tinyint | 1 | 否 | 1 | 状态：1-正常，0-删除 |
| is_anonymous | tinyint | 1 | 否 | 0 | 是否匿名发布 |
| comments_count | int | 11 | 否 | 0 | 评论数 |
| top_comment_id | int | 11 | 是 | NULL | 置顶评论ID |

**索引**:

- PRIMARY KEY (`id`)
- KEY `user_id` (`user_id`)
- KEY `create_time` (`create_time`)
- KEY `status` (`status`)
- KEY `is_top` (`is_top`)
- KEY `idx_user_id` (`user_id`)
- KEY `idx_user_status` (`user_id`,`status`)

### 3. 评论表 (comments)

存储动态评论内容，支持无限楼中楼评论。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 评论ID（主键） |
| user_id | int | 11 | 否 | - | 用户ID |
| moment_id | int | 11 | 否 | - | 动态ID |
| parent_id | int | 11 | 否 | 0 | 父评论ID，0表示顶级评论 |
| nickname | varchar | 50 | 否 | - | 用户昵称 |
| avatar | varchar | 255 | 是 | NULL | 用户头像 |
| content | text | - | 否 | - | 评论内容 |
| likes | int | 11 | 否 | 0 | 点赞数 |
| replies | int | 11 | 否 | 0 | 回复数 |
| status | tinyint | 1 | 否 | 1 | 状态：1-正常，0-删除 |
| create_time | int | 11 | 否 | - | 创建时间 |
| is_top | tinyint | 1 | 否 | 0 | 是否置顶：0-否，1-是（仅一级评论） |
| is_hot | tinyint | 1 | 否 | 0 | 是否热评：0-否，1-是 |
| is_author | tinyint | 1 | 否 | 0 | 是否作者评论：0-否，1-是 |
| reply_to_user_id | int | 11 | 是 | NULL | 回复的用户ID |
| reply_to_nickname | varchar | 50 | 是 | NULL | 回复的用户昵称 |
| media | varchar | 500 | 是 | NULL | 媒体资源（图片/表情）多个用逗号分隔 |
| update_time | int | 11 | 是 | NULL | 更新时间 |
| comment_status | tinyint | 1 | 否 | 1 | 评论状态：0-关闭评论，1-开启评论 |
| top_comment_id | int | 11 | 是 | NULL | 置顶评论ID |
| comments_count | int | 11 | 否 | 0 | 评论数 |

**索引**:

- PRIMARY KEY (`id`)
- KEY `moment_id` (`moment_id`)
- KEY `parent_id` (`parent_id`)
- KEY `user_id` (`user_id`)
- KEY `idx_create_time` (`create_time`)
- KEY `idx_likes` (`likes`)
- KEY `idx_is_hot` (`is_hot`)
- KEY `idx_is_top` (`is_top`)
- KEY `idx_status` (`status`)
- KEY `idx_moment_parent` (`moment_id`,`parent_id`)
- KEY `idx_parent_id` (`parent_id`)

### 4. 关注表 (follows)

记录用户关注关系。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 关注ID（主键） |
| follower_id | int | 11 | 否 | - | 关注者ID |
| following_id | int | 11 | 否 | - | 被关注者ID |
| create_time | int | 11 | 是 | NULL | 创建时间 |
| status | tinyint | 1 | 否 | 1 | 状态：1-正常，0-已取消 |
| deleted_at | timestamp | - | 是 | NULL | 软删除时间 |

**索引**:

- PRIMARY KEY (`id`)
- UNIQUE KEY `follower_following` (`follower_id`,`following_id`)
- KEY `following_id` (`following_id`)
- KEY `idx_follower_id` (`follower_id`)
- KEY `idx_following_id` (`following_id`)
- KEY `idx_follower_following` (`follower_id`,`following_id`)

### 5. 消息表 (messages)

存储用户私信内容。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 消息ID（主键） |
| sender_id | int | 11 | 否 | - | 发送者ID |
| receiver_id | int | 11 | 否 | - | 接收者ID |
| content | text | - | 是 | NULL | 消息内容 |
| message_type | tinyint | 1 | 否 | 1 | 消息类型：1-文本，2-图片，3-视频 |
| reply_to_id | int | 11 | 否 | 0 | 引用回复的消息ID |
| file_url | varchar | 255 | 否 | '' | 文件URL |
| voice_duration | int | 11 | 否 | 0 | 语音时长（秒） |
| is_read | tinyint | 1 | 否 | 0 | 是否已读：0-未读，1-已读 |
| read_time | int | 11 | 否 | 0 | 阅读时间 |
| is_recalled | tinyint | 1 | 否 | 0 | 是否撤回：0-未撤回，1-已撤回 |
| is_pinned | tinyint | 1 | 否 | 0 | 是否置顶 |
| pin_time | int | 11 | 是 | NULL | 置顶时间 |
| recall_time | int | 11 | 是 | NULL | 撤回时间（时间戳） |
| file_name | varchar | 255 | 否 | '' | 文件名 |
| file_size | int | 11 | 否 | 0 | 文件大小（字节） |
| send_status | tinyint | 1 | 否 | 1 | 发送状态：0-发送中，1-成功，2-失败 |
| send_time | int | 11 | 是 | NULL | 发送完成时间 |
| self_destruct_time | int | 11 | 是 | NULL | 阅后即焚时间（秒） |
| create_time | int | 11 | 是 | NULL | 创建时间 |

**索引**:

- PRIMARY KEY (`id`)
- KEY `sender_id` (`sender_id`)
- KEY `receiver_id` (`receiver_id`)
- KEY `is_read` (`is_read`)
- KEY `create_time` (`create_time`)
- KEY `idx_reply_to` (`reply_to_id`)
- KEY `idx_is_pinned` (`is_pinned`)
- KEY `idx_is_recalled` (`is_recalled`)
- KEY `idx_send_status` (`send_status`)
- KEY `idx_sender_receiver` (`sender_id`,`receiver_id`)
- KEY `idx_receiver_read` (`receiver_id`,`is_read`)
- KEY `idx_create_time` (`create_time`)
- KEY `idx_sender_receiver_time` (`sender_id`,`receiver_id`,`create_time`)

### 6. 通知表 (notifications)

存储系统通知内容。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 通知ID（主键） |
| user_id | int | 11 | 否 | - | 接收用户ID |
| sender_id | int | 11 | 否 | 0 | 发送者ID（0为系统） |
| type | tinyint | 1 | 否 | - | 通知类型：1-点赞，2-评论，3-关注，4-私信，5-系统通知 |
| title | varchar | 200 | 否 | - | 标题 |
| content | text | - | 是 | NULL | 内容 |
| target_id | int | 11 | 否 | 0 | 目标ID |
| target_type | varchar | 50 | 否 | '' | 目标类型 |
| is_read | tinyint | 1 | 否 | 0 | 是否已读 |
| read_time | timestamp | - | 是 | NULL | 阅读时间 |
| message_type | tinyint | 1 | 否 | 1 | 消息类型：1-文本，2-图片，3-语音，4-表情 |
| file_url | varchar | 500 | 否 | '' | 文件URL |
| file_name | varchar | 255 | 否 | '' | 文件名 |
| file_size | int | 11 | 否 | 0 | 文件大小 |
| duration | int | 11 | 否 | 0 | 语音时长（秒） |
| is_recalled | tinyint | 1 | 否 | 0 | 是否撤回 |
| recall_time | timestamp | - | 是 | NULL | 撤回时间 |
| create_time | timestamp | - | 否 | CURRENT_TIMESTAMP | 创建时间 |
| deleted_at | timestamp | - | 是 | NULL | 软删除时间 |

**索引**:

- PRIMARY KEY (`id`)
- KEY `user_id` (`user_id`)
- KEY `sender_id` (`sender_id`)
- KEY `type` (`type`)
- KEY `is_read` (`is_read`)
- KEY `create_time` (`create_time`)
- KEY `user_read` (`user_id`,`is_read`)
- KEY `idx_user_read` (`user_id`,`is_read`)
- KEY `idx_create_time` (`create_time`)

### 7. 话题表 (topics)

存储话题信息。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 话题ID（主键） |
| name | varchar | 100 | 否 | - | 话题名称 |
| description | text | - | 是 | NULL | 话题描述 |
| cover_image | varchar | 255 | 是 | NULL | 话题封面图 |
| count | int | 11 | 否 | 0 | 动态数量 |
| follower_count | int | 11 | 否 | 0 | 关注人数 |
| is_hot | tinyint | 1 | 否 | 0 | 是否热门：0-否，1-是 |
| is_recommend | tinyint | 1 | 否 | 0 | 是否推荐：0-否，1-是 |
| sort | int | 11 | 否 | 0 | 排序 |
| status | tinyint | 1 | 否 | 1 | 状态：0-禁用，1-启用 |
| create_time | int | 11 | 否 | 0 | 创建时间 |
| update_time | int | 11 | 否 | 0 | 更新时间 |

**索引**:

- PRIMARY KEY (`id`)
- UNIQUE KEY `name` (`name`)
- KEY `status` (`status`)
- KEY `is_hot` (`is_hot`)
- KEY `is_recommend` (`is_recommend`)
- KEY `count` (`count`)
- KEY `create_time` (`create_time`)

### 8. 收藏表 (favorites)

记录用户收藏内容。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 收藏ID（主键） |
| user_id | int | 11 | 否 | - | 用户ID |
| target_id | int | 11 | 否 | - | 目标ID |
| target_type | tinyint | 1 | 否 | - | 目标类型：1-动态，2-评论 |
| folder_id | int | 11 | 否 | 0 | 收藏夹ID |
| folder_name | varchar | 50 | 否 | '默认收藏' | 收藏夹名称 |
| create_time | timestamp | - | 否 | CURRENT_TIMESTAMP | 创建时间 |

**索引**:

- PRIMARY KEY (`id`)
- UNIQUE KEY `user_target` (`user_id`,`target_id`,`target_type`)
- KEY `user_id` (`user_id`)
- KEY `target_id` (`target_id`)
- KEY `target_type` (`target_type`)
- KEY `folder_id` (`folder_id`)
- KEY `create_time` (`create_time`)
- KEY `idx_user_id` (`user_id`)
- KEY `idx_user_type` (`user_id`,`target_type`)
- KEY `idx_target` (`target_id`,`target_type`)

### 9. 点赞表 (likes)

记录用户点赞内容。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 点赞ID（主键） |
| user_id | int | 11 | 否 | - | 用户ID |
| target_id | int | 11 | 否 | - | 目标ID |
| target_type | tinyint | 1 | 否 | - | 目标类型：1-动态，2-评论 |
| create_time | timestamp | - | 否 | CURRENT_TIMESTAMP | 创建时间 |
| deleted_at | timestamp | - | 是 | NULL | 软删除时间 |

**索引**:

- PRIMARY KEY (`id`)
- UNIQUE KEY `user_target` (`user_id`,`target_id`,`target_type`)
- KEY `user_id` (`user_id`)
- KEY `target_id` (`target_id`)
- KEY `target_type` (`target_type`)
- KEY `create_time` (`create_time`)
- KEY `idx_target_id` (`target_id`)
- KEY `idx_target_type` (`target_type`)
- KEY `idx_target_id_type` (`target_id`,`target_type`)

### 10. 管理员表 (admin)

存储管理员信息。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 管理员ID（主键） |
| username | varchar | 50 | 否 | '' | 管理员用户名 |
| password | varchar | 255 | 否 | '' | 管理员密码（bcrypt加密） |
| nickname | varchar | 50 | 否 | '' | 管理员昵称 |
| email | varchar | 100 | 否 | '' | 管理员邮箱 |
| avatar | varchar | 255 | 否 | '' | 管理员头像 |
| role | tinyint | 1 | 否 | 1 | 管理员角色：1-超级管理员，2-普通管理员 |
| status | tinyint | 1 | 否 | 1 | 状态：1-正常，0-禁用 |
| last_login_ip | varchar | 45 | 否 | '' | 最后登录IP |
| last_login_time | int | 11 | 否 | 0 | 最后登录时间 |
| login_count | int | 11 | 否 | 0 | 登录次数 |
| create_time | int | 11 | 否 | 0 | 创建时间 |
| update_time | int | 11 | 否 | 0 | 更新时间 |
| deleted_at | timestamp | - | 是 | NULL | 软删除时间 |

**索引**:

- PRIMARY KEY (`id`)
- UNIQUE KEY `username` (`username`)
- KEY `email` (`email`)
- KEY `status` (`status`)
- KEY `role` (`role`)

### 11. API密钥表 (api_keys)

存储API密钥信息。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 密钥ID（主键） |
| name | varchar | 100 | 否 | - | 密钥名称 |
| access_key | varchar | 100 | 否 | - | Access Key |
| secret_key | varchar | 100 | 否 | - | Secret Key |
| permissions | varchar | 500 | 否 | '' | 权限列表 |
| ip_whitelist | varchar | 500 | 否 | '' | IP白名单 |
| rate_limit | int | 11 | 否 | 1000 | 限流次数/小时 |
| status | tinyint | 1 | 否 | 1 | 状态 |
| expire_time | int | 11 | 否 | 0 | 过期时间，0表示永不过期 |
| create_time | int | 11 | 否 | 0 | 创建时间 |
| update_time | int | 11 | 否 | 0 | 更新时间 |

**索引**:

- PRIMARY KEY (`id`)
- UNIQUE KEY `access_key` (`access_key`)

### 12. 系统公告表 (announcements)

存储系统公告信息。

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|-------|------|------|---------|--------|------|
| id | int | 11 | 否 | AUTO_INCREMENT | 公告ID（主键） |
| title | varchar | 255 | 是 | NULL | 公告标题 |
| content | text | - | 是 | NULL | 公告内容 |
| status | int | 1 | 否 | 1 | 状态：0-禁用，1-启用 |
| is_publish | int | 1 | 否 | 0 | 是否发布：0-未发布，1-已发布 |
| publish_time | int | 11 | 否 | 0 | 发布时间 |
| expire_time | int | 11 | 否 | 0 | 过期时间 |
| is_popup | int | 1 | 否 | 1 | 是否弹窗：0-否，1-是 |
| click_count | int | 11 | 否 | 0 | 点击次数 |
| create_time | int | 11 | 否 | 0 | 创建时间 |
| update_time | int | 11 | 否 | 0 | 更新时间 |
| admin_id | int | 11 | 否 | 0 | 发布管理员ID |

**索引**:

- PRIMARY KEY (`id`)
- KEY `status` (`status`)
- KEY `is_publish` (`is_publish`)
- KEY `publish_time` (`publish_time`)
- KEY `create_time` (`create_time`)

## 数据库关系图

### 用户相关关系

```
user (用户表)
├── follows (关注表) - follower_id, following_id
├── login_logs (登录日志表) - user_id
├── card_visitors (名片访客记录表) - user_id, visitor_id
├── moments (动态表) - user_id
├── comments (评论表) - user_id
├── messages (消息表) - sender_id, receiver_id
├── notifications (通知表) - user_id, sender_id
├── favorites (收藏表) - user_id
├── likes (点赞表) - user_id
├── mentions (@提及表) - user_id, mentioned_user_id
└── wallets (钱包表) - user_id
```

### 动态相关关系

```
moments (动态表)
├── comments (评论表) - moment_id
├── moment_likes (动态点赞表) - moment_id
├── moment_topics (动态话题关联表) - moment_id
├── moment_drafts (动态草稿表) - user_id
├── hidden_moments (隐藏动态记录表) - user_id, moment_id
└── mentions (@提及表) - moment_id
```

### 评论相关关系

```
comments (评论表)
├── comment_likes (评论点赞表) - comment_id
└── comments (评论表) - parent_id (自关联)
```

### 话题相关关系

```
topics (话题表)
├── moment_topics (动态话题关联表) - topic_id
└── topic_follows (话题关注表) - topic_id
```

## 索引设计原则

### 1. 主键索引

所有表都使用自增整数作为主键，类型为 `int(11) unsigned` 或 `bigint(20) unsigned`。

### 2. 唯一索引

用于确保数据的唯一性，如用户名、邮箱、手机号等。

### 3. 普通索引

用于加速查询，常用的查询字段都应该建立索引。

### 4. 复合索引

用于多字段查询，按照查询频率和选择性建立复合索引。

### 5. 索引命名规范

- 主键索引：`PRIMARY KEY`
- 唯一索引：`uk_字段名` 或 `UNIQUE KEY`
- 普通索引：`idx_字段名` 或 `KEY`

## 数据库优化建议

### 1. 查询优化

- 避免使用 `SELECT *`，只查询需要的字段
- 使用 `EXPLAIN` 分析查询执行计划
- 合理使用索引，避免全表扫描
- 使用 `LIMIT` 限制返回结果数量

### 2. 索引优化

- 为常用的查询条件建立索引
- 避免过多的索引，影响写入性能
- 定期分析和优化索引
- 使用复合索引提高查询效率

### 3. 表结构优化

- 选择合适的数据类型，避免浪费空间
- 使用 `NOT NULL` 约束，避免空值
- 合理设置字段长度，避免过长
- 使用 `ENUM` 类型代替字符串

### 4. 分表分库

- 当数据量过大时，考虑按时间、用户ID等进行分表
- 使用读写分离，提高查询性能
- 使用缓存减少数据库压力

### 5. 定期维护

- 定期清理过期数据
- 定期优化表结构
- 定期备份重要数据
- 监控数据库性能指标

## 数据库备份与恢复

### 备份策略

1. **全量备份**：每天凌晨进行一次全量备份
2. **增量备份**：每小时进行一次增量备份
3. **日志备份**：实时备份二进制日志

### 备份命令

```bash
# 全量备份
mysqldump -u root -p quanzi > backup_$(date +%Y%m%d).sql

# 增量备份
mysqldump -u root -p --single-transaction --flush-logs --master-data=2 quanzi > incremental_$(date +%Y%m%d_%H).sql
```

### 恢复命令

```bash
# 恢复全量备份
mysql -u root -p quanzi < backup_20260211.sql

# 恢复增量备份
mysql -u root -p quanzi < incremental_20260211_01.sql
```

## 数据库安全

### 1. 访问控制

- 使用强密码
- 限制远程访问
- 使用SSL加密连接
- 定期修改密码

### 2. 数据加密

- 敏感字段加密存储
- 使用bcrypt加密密码
- 使用HTTPS传输数据

### 3. 审计日志

- 记录所有数据库操作
- 定期检查审计日志
- 发现异常及时处理

### 4. 权限管理

- 最小权限原则
- 定期审查权限
- 及时回收不需要的权限

## 版本历史

| 版本 | 日期 | 更新内容 |
|-----|------|---------|
| v1.0.0 | 2026-02-11 | 初始版本 |

---

**© 2026 社交应用系统 - 数据库设计文档**