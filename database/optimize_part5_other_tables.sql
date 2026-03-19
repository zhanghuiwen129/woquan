-- 数据库优化脚本 - 第5部分：索引优化（其他表）
-- MySQL 8.0 优化版本

-- 优化 storage_files 表索引
ALTER TABLE `storage_files` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `storage_files` ADD INDEX `idx_storage_type_status` (`storage_type`, `status`, `create_time` DESC);
ALTER TABLE `storage_files` ADD INDEX `idx_mimetype_status` (`mimetype`(50), `status`);

-- 优化 mentions 表索引
ALTER TABLE `mentions` ADD INDEX `idx_moment_mentioned` (`moment_id`, `mentioned_user_id`);
ALTER TABLE `mentions` ADD INDEX `idx_mentioned_user` (`mentioned_user_id`);

-- 优化 moment_topics 表索引
ALTER TABLE `moment_topics` ADD INDEX `idx_moment_topic` (`moment_id`, `topic_id`);
ALTER TABLE `moment_topics` ADD INDEX `idx_topic_moment` (`topic_id`, `moment_id`);

-- 优化 topics 表索引
ALTER TABLE `topics` ADD INDEX `idx_status_create` (`status`, `create_time` DESC);
ALTER TABLE `topics` ADD INDEX `idx_follow_count` (`follow_count` DESC);
ALTER TABLE `topics` ADD INDEX `idx_moment_count` (`moment_count` DESC);
