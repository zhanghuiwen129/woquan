-- Hublog 数据库初始化脚本
-- 仅包含迁移版本记录表，全部建表逻辑由 Migration 系统负责
-- 全新安装流程：创建空库 → 导入本文件 → 触发 Migration::run()

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `schema_versions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `version` int(11) unsigned NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='迁移版本记录';

INSERT INTO `schema_versions` (`version`, `applied_at`) VALUES (0, NOW())
ON DUPLICATE KEY UPDATE `version` = 0;

SET FOREIGN_KEY_CHECKS = 1;
