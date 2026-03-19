-- 数据库优化脚本 - 添加索引和约束
-- MySQL 8.0 优化版本

-- 1. 优化 moments 表索引
-- 添加复合索引以优化常见查询
ALTER TABLE `moments` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `moments` ADD INDEX `idx_status_create_time` (`status`, `create_time` DESC);
ALTER TABLE `moments` ADD INDEX `idx_privacy_status` (`privacy`, `status`);
ALTER TABLE `moments` ADD INDEX `idx_type_status` (`type`, `status`);
ALTER TABLE `moments` ADD INDEX `idx_is_top_create_time` (`is_top`, `create_time` DESC);
ALTER TABLE `moments` ADD INDEX `idx_likes_comments` (`likes` DESC, `comments` DESC);
ALTER TABLE `moments` ADD INDEX `idx_publish_time` (`publish_time` DESC);

-- 2. 优化 comments 表索引
ALTER TABLE `comments` ADD INDEX `idx_moment_status_create` (`moment_id`, `status`, `create_time` DESC);
ALTER TABLE `comments` ADD INDEX `idx_parent_status_create` (`parent_id`, `status`, `create_time` DESC);
ALTER TABLE `comments` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `comments` ADD INDEX `idx_moment_parent_status` (`moment_id`, `parent_id`, `status`);

-- 3. 优化 likes 表索引
ALTER TABLE `likes` ADD INDEX `idx_user_target_type` (`user_id`, `target_type`, `create_time` DESC);
ALTER TABLE `likes` ADD INDEX `idx_target_type_create` (`target_type`, `target_id`, `create_time` DESC);
ALTER TABLE `likes` ADD UNIQUE INDEX `idx_user_target` (`user_id`, `target_type`, `target_id`);

-- 4. 优化 follows 表索引
ALTER TABLE `follows` ADD INDEX `idx_follower_status` (`follower_id`, `status`, `create_time` DESC);
ALTER TABLE `follows` ADD INDEX `idx_following_status` (`following_id`, `status`, `create_time` DESC);
ALTER TABLE `follows` ADD UNIQUE INDEX `idx_follower_following` (`follower_id`, `following_id`);

-- 5. 优化 user 表索引
ALTER TABLE `user` ADD INDEX `idx_status_create_time` (`status`, `create_time` DESC);
ALTER TABLE `user` ADD INDEX `idx_is_online_heartbeat` (`is_online`, `last_heartbeat_time`);
ALTER TABLE `user` ADD INDEX `idx_nickname` (`nickname`(20));

-- 6. 优化 messages 表索引
ALTER TABLE `messages` ADD INDEX `idx_sender_receiver_create` (`sender_id`, `receiver_id`, `create_time` DESC);
ALTER TABLE `messages` ADD INDEX `idx_receiver_status_create` (`receiver_id`, `status`, `create_time` DESC);
ALTER TABLE `messages` ADD INDEX `idx_sender_status_create` (`sender_id`, `status`, `create_time` DESC);
ALTER TABLE `messages` ADD INDEX `idx_conversation_create` (`conversation_id`, `create_time` DESC);

-- 7. 优化 notifications 表索引
ALTER TABLE `notifications` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `notifications` ADD INDEX `idx_type_status_create` (`type`, `status`, `create_time` DESC);
ALTER TABLE `notifications` ADD INDEX `idx_sender_user_create` (`sender_id`, `user_id`, `create_time` DESC);

-- 8. 优化 storage_files 表索引
ALTER TABLE `storage_files` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);
ALTER TABLE `storage_files` ADD INDEX `idx_storage_type_status` (`storage_type`, `status`, `create_time` DESC);
ALTER TABLE `storage_files` ADD INDEX `idx_mimetype_status` (`mimetype`(50), `status`);

-- 9. 优化 mentions 表索引
ALTER TABLE `mentions` ADD INDEX `idx_moment_mentioned` (`moment_id`, `mentioned_user_id`);
ALTER TABLE `mentions` ADD INDEX `idx_mentioned_user` (`mentioned_user_id`);

-- 10. 优化 moment_topics 表索引
ALTER TABLE `moment_topics` ADD INDEX `idx_moment_topic` (`moment_id`, `topic_id`);
ALTER TABLE `moment_topics` ADD INDEX `idx_topic_moment` (`topic_id`, `moment_id`);

-- 11. 优化 topics 表索引
ALTER TABLE `topics` ADD INDEX `idx_status_create` (`status`, `create_time` DESC);
ALTER TABLE `topics` ADD INDEX `idx_follow_count` (`follow_count` DESC);
ALTER TABLE `topics` ADD INDEX `idx_moment_count` (`moment_count` DESC);

-- 12. 优化 collections 表索引
ALTER TABLE `collections` ADD INDEX `idx_user_target_type` (`user_id`, `target_type`, `create_time` DESC);
ALTER TABLE `collections` ADD UNIQUE INDEX `idx_user_target` (`user_id`, `target_type`, `target_id`);

-- 13. 优化 hidden_moments 表索引
ALTER TABLE `hidden_moments` ADD UNIQUE INDEX `idx_user_moment` (`user_id`, `moment_id`);

-- 14. 优化 reports 表索引
ALTER TABLE `reports` ADD INDEX `idx_reporter_status` (`reporter_id`, `status`, `create_time` DESC);
ALTER TABLE `reports` ADD INDEX `idx_reported_status` (`reported_id`, `status`, `create_time` DESC);
ALTER TABLE `reports` ADD INDEX `idx_type_status_create` (`type`, `status`, `create_time` DESC);

-- 15. 优化 search_logs 表索引
ALTER TABLE `search_logs` ADD INDEX `idx_user_create_time` (`user_id`, `create_time` DESC);
ALTER TABLE `search_logs` ADD INDEX `idx_keyword_create` (`keyword`(50), `create_time` DESC);

-- 16. 优化 login_logs 表索引
ALTER TABLE `login_logs` ADD INDEX `idx_user_create_time` (`user_id`, `create_time` DESC);
ALTER TABLE `login_logs` ADD INDEX `idx_ip_create_time` (`ip`(45), `create_time` DESC);

-- 17. 优化 activities 表索引
ALTER TABLE `activities` ADD INDEX `idx_status_start_time` (`status`, `start_time` DESC);
ALTER TABLE `activities` ADD INDEX `idx_organizer_status` (`organizer_id`, `status`, `start_time` DESC);
ALTER TABLE `activities` ADD INDEX `idx_type_status` (`type`, `status`);

-- 18. 优化 activity_participants 表索引
ALTER TABLE `activity_participants` ADD INDEX `idx_user_status_time` (`user_id`, `status`, `participant_time` DESC);
ALTER TABLE `activity_participants` ADD INDEX `idx_activity_status_time` (`activity_id`, `status`, `participant_time` DESC);

-- 19. 优化 articles 表索引
ALTER TABLE `articles` ADD INDEX `idx_author_status_create` (`author_id`, `status`, `create_time` DESC);
ALTER TABLE `articles` ADD INDEX `idx_category_status_create` (`category_id`, `status`, `create_time` DESC);
ALTER TABLE `articles` ADD INDEX `idx_views_likes` (`views` DESC, `likes` DESC);

-- 20. 优化 article_comments 表索引
ALTER TABLE `article_comments` ADD INDEX `idx_article_status_create` (`article_id`, `status`, `create_time` DESC);
ALTER TABLE `article_comments` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);

-- 21. 优化 article_likes 表索引
ALTER TABLE `article_likes` ADD UNIQUE INDEX `idx_user_article` (`user_id`, `article_id`);

-- 22. 优化 admin_log 表索引
ALTER TABLE `admin_log` ADD INDEX `idx_admin_create_time` (`admin_id`, `create_time` DESC);

-- 23. 优化 user_wallet 表索引
ALTER TABLE `user_wallet` ADD UNIQUE INDEX `idx_user_id` (`user_id`);

-- 24. 优化 user_currency 表索引
ALTER TABLE `user_currency` ADD UNIQUE INDEX `idx_user_currency` (`user_id`, `currency_type_id`);

-- 25. 优化 user_points 表索引
ALTER TABLE `user_points` ADD UNIQUE INDEX `idx_user_id` (`user_id`);

-- 26. 优化 recharge_records 表索引
ALTER TABLE `recharge_records` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);

-- 27. 优化 withdraw_records 表索引
ALTER TABLE `withdraw_records` ADD INDEX `idx_user_status_create` (`user_id`, `status`, `create_time` DESC);

-- 28. 优化 favorites 表索引
ALTER TABLE `favorites` ADD INDEX `idx_user_folder_create` (`user_id`, `folder_id`, `create_time` DESC);
ALTER TABLE `favorites` ADD UNIQUE INDEX `idx_user_target` (`user_id`, `target_type`, `target_id`);

-- 29. 优化 shares 表索引
ALTER TABLE `shares` ADD INDEX `idx_user_target_create` (`user_id`, `target_type`, `create_time` DESC);
ALTER TABLE `shares` ADD INDEX `idx_target_type_create` (`target_type`, `target_id`, `create_time` DESC);

-- 30. 添加外键约束（MySQL 8.0）
ALTER TABLE `comments` ADD CONSTRAINT `fk_comments_moment` FOREIGN KEY (`moment_id`) REFERENCES `moments` (`id`) ON DELETE CASCADE;
ALTER TABLE `comments` ADD CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `likes` ADD CONSTRAINT `fk_likes_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `follows` ADD CONSTRAINT `fk_follows_follower` FOREIGN KEY (`follower_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `follows` ADD CONSTRAINT `fk_follows_following` FOREIGN KEY (`following_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `messages` ADD CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `messages` ADD CONSTRAINT `fk_messages_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `notifications` ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `notifications` ADD CONSTRAINT `fk_notifications_sender` FOREIGN KEY (`sender_id`) REFERENCES `user` (`id`) ON DELETE SET NULL;
ALTER TABLE `storage_files` ADD CONSTRAINT `fk_storage_files_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `mentions` ADD CONSTRAINT `fk_mentions_moment` FOREIGN KEY (`moment_id`) REFERENCES `moments` (`id`) ON DELETE CASCADE;
ALTER TABLE `mentions` ADD CONSTRAINT `fk_mentions_user` FOREIGN KEY (`mentioned_user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `moment_topics` ADD CONSTRAINT `fk_moment_topics_moment` FOREIGN KEY (`moment_id`) REFERENCES `moments` (`id`) ON DELETE CASCADE;
ALTER TABLE `moment_topics` ADD CONSTRAINT `fk_moment_topics_topic` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE;
ALTER TABLE `collections` ADD CONSTRAINT `fk_collections_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `hidden_moments` ADD CONSTRAINT `fk_hidden_moments_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `hidden_moments` ADD CONSTRAINT `fk_hidden_moments_moment` FOREIGN KEY (`moment_id`) REFERENCES `moments` (`id`) ON DELETE CASCADE;
ALTER TABLE `reports` ADD CONSTRAINT `fk_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `reports` ADD CONSTRAINT `fk_reports_reported` FOREIGN KEY (`reported_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `activity_participants` ADD CONSTRAINT `fk_activity_participants_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;
ALTER TABLE `activity_participants` ADD CONSTRAINT `fk_activity_participants_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `article_comments` ADD CONSTRAINT `fk_article_comments_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE;
ALTER TABLE `article_comments` ADD CONSTRAINT `fk_article_comments_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `article_likes` ADD CONSTRAINT `fk_article_likes_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `article_likes` ADD CONSTRAINT `fk_article_likes_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE;
ALTER TABLE `article_views` ADD CONSTRAINT `fk_article_views_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `article_views` ADD CONSTRAINT `fk_article_views_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE;
ALTER TABLE `article_collections` ADD CONSTRAINT `fk_article_collections_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `article_collections` ADD CONSTRAINT `fk_article_collections_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE;
ALTER TABLE `favorites` ADD CONSTRAINT `fk_favorites_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `shares` ADD CONSTRAINT `fk_shares_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `recharge_records` ADD CONSTRAINT `fk_recharge_records_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `withdraw_records` ADD CONSTRAINT `fk_withdraw_records_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `user_wallet` ADD CONSTRAINT `fk_user_wallet_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `user_currency` ADD CONSTRAINT `fk_user_currency_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `user_points` ADD CONSTRAINT `fk_user_points_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;

-- 31. 优化表引擎和字符集
ALTER TABLE `moments` ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
ALTER TABLE `comments` ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
ALTER TABLE `likes` ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
ALTER TABLE `follows` ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
ALTER TABLE `messages` ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
ALTER TABLE `notifications` ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
ALTER TABLE `user` ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
ALTER TABLE `storage_files` ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- 32. 添加全文索引（MySQL 8.0 支持 ngram 分词器）
ALTER TABLE `moments` ADD FULLTEXT INDEX `ft_content` (`content`) WITH PARSER ngram;
ALTER TABLE `comments` ADD FULLTEXT INDEX `ft_content` (`content`) WITH PARSER ngram;
ALTER TABLE `articles` ADD FULLTEXT INDEX `ft_title_content` (`title`, `content`) WITH PARSER ngram;
ALTER TABLE `topics` ADD FULLTEXT INDEX `ft_name_description` (`name`, `description`) WITH PARSER ngram;

-- 33. 添加检查约束（MySQL 8.0）
ALTER TABLE `moments` ADD CONSTRAINT `chk_moments_privacy` CHECK (`privacy` IN (1, 2, 3, 4));
ALTER TABLE `moments` ADD CONSTRAINT `chk_moments_type` CHECK (`type` IN (1, 2, 3, 4));
ALTER TABLE `moments` ADD CONSTRAINT `chk_moments_status` CHECK (`status` IN (0, 1));
ALTER TABLE `comments` ADD CONSTRAINT `chk_comments_status` CHECK (`status` IN (0, 1));
ALTER TABLE `likes` ADD CONSTRAINT `chk_likes_target_type` CHECK (`target_type` IN (1, 2, 3, 4));
ALTER TABLE `follows` ADD CONSTRAINT `chk_follows_status` CHECK (`status` IN (0, 1, 2));
ALTER TABLE `messages` ADD CONSTRAINT `chk_messages_status` CHECK (`status` IN (0, 1));
ALTER TABLE `notifications` ADD CONSTRAINT `chk_notifications_type` CHECK (`type` IN (1, 2, 3, 4, 5));
ALTER TABLE `notifications` ADD CONSTRAINT `chk_notifications_status` CHECK (`status` IN (0, 1));
ALTER TABLE `user` ADD CONSTRAINT `chk_user_status` CHECK (`status` IN (0, 1));
ALTER TABLE `user` ADD CONSTRAINT `chk_user_gender` CHECK (`gender` IN (0, 1, 2));
ALTER TABLE `activities` ADD CONSTRAINT `chk_activities_type` CHECK (`type` IN (1, 2));
ALTER TABLE `activities` ADD CONSTRAINT `chk_activities_status` CHECK (`status` IN (0, 1, 2, 3));
ALTER TABLE `articles` ADD CONSTRAINT `chk_articles_status` CHECK (`status` IN (0, 1));
ALTER TABLE `reports` ADD CONSTRAINT `chk_reports_type` CHECK (`type` IN (1, 2, 3, 4));
ALTER TABLE `reports` ADD CONSTRAINT `chk_reports_status` CHECK (`status` IN (0, 1, 2));

-- 34. 添加生成列（MySQL 8.0）
ALTER TABLE `moments` ADD COLUMN `engagement_score` DECIMAL(10,2) GENERATED ALWAYS AS (`likes` * 1.5 + `comments` * 2 + `views` * 0.1) STORED;
ALTER TABLE `moments` ADD INDEX `idx_engagement_score` (`engagement_score` DESC);

-- 35. 优化查询缓存相关表
ALTER TABLE `system_config` ADD INDEX `idx_config_key` (`config_key`(50));
ALTER TABLE `system_config` ADD UNIQUE INDEX `uk_config_key` (`config_key`);

-- 36. 添加分区表建议（大表分区）
-- 注意：分区表需要根据实际数据量和查询模式来决定是否使用
-- ALTER TABLE `moments` PARTITION BY RANGE (YEAR(create_time)) (
--     PARTITION p2023 VALUES LESS THAN (2024),
--     PARTITION p2024 VALUES LESS THAN (2025),
--     PARTITION p2025 VALUES LESS THAN (2026),
--     PARTITION pmax VALUES LESS THAN MAXVALUE
-- );

-- 37. 添加性能优化建议的表配置
SET GLOBAL innodb_buffer_pool_size = 1073741824; -- 1GB
SET GLOBAL innodb_log_file_size = 268435456; -- 256MB
SET GLOBAL innodb_flush_log_at_trx_commit = 2;
SET GLOBAL innodb_flush_method = O_DIRECT;

-- 38. 添加分析表命令
ANALYZE TABLE `moments`;
ANALYZE TABLE `comments`;
ANALYZE TABLE `likes`;
ANALYZE TABLE `follows`;
ANALYZE TABLE `messages`;
ANALYZE TABLE `notifications`;
ANALYZE TABLE `user`;
ANALYZE TABLE `storage_files`;

-- 39. 优化表统计信息
SET GLOBAL innodb_stats_persistent = ON;
SET GLOBAL innodb_stats_auto_recalc = ON;

-- 40. 添加慢查询日志配置
SET GLOBAL slow_query_log = ON;
SET GLOBAL long_query_time = 2;
SET GLOBAL log_queries_not_using_indexes = ON;
