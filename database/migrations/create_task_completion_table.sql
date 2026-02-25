-- 创建任务完成记录表
CREATE TABLE IF NOT EXISTS `task_completion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `task_id` int(11) NOT NULL COMMENT '任务ID',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '完成时间',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `task_id` (`task_id`),
  KEY `create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务完成记录表';
