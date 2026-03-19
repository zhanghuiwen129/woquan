-- 数据库优化完整脚本 - 一次性执行版本
-- 表前缀：sns_
-- MySQL 8.0 优化版本

-- 第1部分：moments表索引
ALTER TABLE `sns_moments` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `sns_moments` ADD INDEX `idx_status_create_time` (`status`, `create_time` DESC);
ALTER TABLE `sns_moments` ADD INDEX `idx_privacy_status` (`privacy`, `status`);
ALTER TABLE `sns_moments` ADD INDEX `idx_type_status` (`type`, `status`);
ALTER TABLE `sns_moments` ADD INDEX `idx_is_top_create_time` (`is_top`, `create_time` DESC);
ALTER TABLE `sns_moments` ADD INDEX `idx_likes_comments` (`likes` DESC, `comments` DESC);
ALTER TABLE `sns_moments` ADD INDEX `idx_publish_time` (`publish_time` DESC);

-- 第2部分：comments表索引
ALTER TABLE `sns_comments` ADD INDEX `idx_moment_status_create` (`moment_id`, `status`, `create_time` DESC);
ALTER TABLE `sns_comments` ADD INDEX `idx_parent_status_create` (`parent_id`, `status`, `create_time` DESC);
ALTER TABLE `sns_comments` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `sns_comments` ADD INDEX `idx_moment_parent_status` (`moment_id`, `parent_id`, `status`);

-- 第3部分：likes表索引
ALTER TABLE `sns_likes` ADD INDEX `idx_user_target_type` (`user_id`, `target_type`, `create_time` DESC);
ALTER TABLE `sns_likes` ADD INDEX `idx_target_type_create` (`target_type`, `target_id`, `create_time` DESC);
ALTER TABLE `sns_likes` ADD UNIQUE INDEX `idx_user_target` (`user_id`, `target_type`, `target_id`);

-- 第4部分：follows表索引
ALTER TABLE `sns_follows` ADD INDEX `idx_follower_status` (`follower_id`, `status`, `create_time` DESC);
ALTER TABLE `sns_follows` ADD INDEX `idx_following_status` (`following_id`, `status`, `create_time` DESC);
ALTER TABLE `sns_follows` ADD UNIQUE INDEX `idx_follower_following` (`follower_id`, `following_id`);

-- 第5部分：user表索引
ALTER TABLE `sns_users` ADD INDEX `idx_status_create_time` (`status`, `create_time` DESC);
ALTER TABLE `sns_users` ADD INDEX `idx_is_online_heartbeat` (`is_online`, `last_heartbeat_time`);
ALTER TABLE `sns_users` ADD INDEX `idx_nickname` (`nickname`(20));

-- 第6部分：messages表索引
ALTER TABLE `sns_messages` ADD INDEX `idx_sender_receiver_create` (`sender_id`, `receiver_id`, `create_time` DESC);
ALTER TABLE `sns_messages` ADD INDEX `idx_receiver_status_create` (`receiver_id`, `status`, `create_time` DESC);
ALTER TABLE `sns_messages` ADD INDEX `idx_sender_status_create` (`sender_id`, `status`, `create_time` DESC);
ALTER TABLE `sns_messages` ADD INDEX `idx_conversation_create` (`conversation_id`, `create_time` DESC);

-- 第7部分：notifications表索引
ALTER TABLE `sns_notifications` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `sns_notifications` ADD INDEX `idx_type_status_create` (`type`, `status`, `create_time` DESC);
ALTER TABLE `sns_notifications` ADD INDEX `idx_sender_user_create` (`sender_id`, `user_id`, `create_time` DESC);

-- 第8部分：storage_files表索引
ALTER TABLE `sns_storage_files` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `sns_storage_files` ADD INDEX `idx_storage_type_status` (`storage_type`, `status`, `create_time` DESC);
ALTER TABLE `sns_storage_files` ADD INDEX `idx_mimetype_status` (`mimetype`(50), `status`);

-- 第9部分：mentions表索引
ALTER TABLE `sns_mentions` ADD INDEX `idx_moment_mentioned` (`moment_id`, `mentioned_user_id`);
ALTER TABLE `sns_mentions` ADD INDEX `idx_mentioned_user` (`mentioned_user_id`);

-- 第10部分：moment_topics表索引
ALTER TABLE `sns_moment_topics` ADD INDEX `idx_moment_topic` (`moment_id`, `topic_id`);
ALTER TABLE `sns_moment_topics` ADD INDEX `idx_topic_moment` (`topic_id`, `moment_id`);

-- 第11部分：topics表索引
ALTER TABLE `sns_topics` ADD INDEX `idx_status_create` (`status`, `create_time` DESC);
ALTER TABLE `sns_topics` ADD INDEX `idx_follow_count` (`follow_count` DESC);
ALTER TABLE `sns_topics` ADD INDEX `idx_moment_count` (`moment_count` DESC);
