-- 数据库优化脚本 - 第2部分：索引优化（comments表）
-- MySQL 8.0 优化版本

-- 优化 comments 表索引
ALTER TABLE `comments` ADD INDEX `idx_moment_status_create` (`moment_id`, `status`, `create_time` DESC);
ALTER TABLE `comments` ADD INDEX `idx_parent_status_create` (`parent_id`, `status`, `create_time` DESC);
ALTER TABLE `comments` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `comments` ADD INDEX `idx_moment_parent_status` (`moment_id`, `parent_id`, `status`);
