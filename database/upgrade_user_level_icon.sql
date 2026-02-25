-- 升级 user_level 表结构，支持自定义徽章图片
-- 将 icon 字段从 varchar(255) 改为 TEXT 类型

ALTER TABLE `user_level` MODIFY COLUMN `icon` TEXT DEFAULT '' COMMENT '等级图标';
