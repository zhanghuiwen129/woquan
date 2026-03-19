-- 数据库优化脚本 - 第1部分：索引优化（moments表）
-- MySQL 8.0 优化版本

-- 优化 moments 表索引
ALTER TABLE `moments` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `moments` ADD INDEX `idx_status_create_time` (`status`, `create_time` DESC);
ALTER TABLE `moments` ADD INDEX `idx_privacy_status` (`privacy`, `status`);
ALTER TABLE `moments` ADD INDEX `idx_type_status` (`type`, `status`);
ALTER TABLE `moments` ADD INDEX `idx_is_top_create_time` (`is_top`, `create_time` DESC);
ALTER TABLE `moments` ADD INDEX `idx_likes_comments` (`likes` DESC, `comments` DESC);
ALTER TABLE `moments` ADD INDEX `idx_publish_time` (`publish_time` DESC);
