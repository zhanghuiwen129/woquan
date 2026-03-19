-- 数据库优化脚本 - 第4部分：索引优化（user、messages、notifications表）
-- MySQL 8.0 优化版本

-- 优化 user 表索引
ALTER TABLE `user` ADD INDEX `idx_status_create_time` (`status`, `create_time` DESC);
ALTER TABLE `user` ADD INDEX `idx_is_online_heartbeat` (`is_online`, `last_heartbeat_time`);
ALTER TABLE `user` ADD INDEX `idx_nickname` (`nickname`(20));

-- 优化 messages 表索引
ALTER TABLE `messages` ADD INDEX `idx_sender_receiver_create` (`sender_id`, `receiver_id`, `create_time` DESC);
ALTER TABLE `messages` ADD INDEX `idx_receiver_status_create` (`receiver_id`, `status`, `create_time` DESC);
ALTER TABLE `messages` ADD INDEX `idx_sender_status_create` (`sender_id`, `status`, `create_time` DESC);
ALTER TABLE `messages` ADD INDEX `idx_conversation_create` (`conversation_id`, `create_time` DESC);

-- 优化 notifications 表索引
ALTER TABLE `notifications` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `notifications` ADD INDEX `idx_type_status_create` (`type`, `status`, `create_time` DESC);
ALTER TABLE `notifications` ADD INDEX `idx_sender_user_create` (`sender_id`, `user_id`, `create_time` DESC);
