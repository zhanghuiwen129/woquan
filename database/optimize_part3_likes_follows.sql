-- 数据库优化脚本 - 第3部分：索引优化（likes、follows表）
-- MySQL 8.0 优化版本

-- 优化 likes 表索引
ALTER TABLE `likes` ADD INDEX `idx_user_target_type` (`user_id`, `target_type`, `create_time` DESC);
ALTER TABLE `likes` ADD INDEX `idx_target_type_create` (`target_type`, `target_id`, `create_time` DESC);
ALTER TABLE `likes` ADD UNIQUE INDEX `idx_user_target` (`user_id`, `target_type`, `target_id`);

-- 优化 follows 表索引
ALTER TABLE `follows` ADD INDEX `idx_follower_status` (`follower_id`, `status`, `create_time` DESC);
ALTER TABLE `follows` ADD INDEX `idx_following_status` (`following_id`, `status`, `create_time` DESC);
ALTER TABLE `follows` ADD UNIQUE INDEX `idx_follower_following` (`follower_id`, `following_id`);
