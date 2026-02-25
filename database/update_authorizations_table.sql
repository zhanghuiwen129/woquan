-- 更新 w_authorizations 表结构，添加缺失的字段
-- 执行前请备份数据库

USE `127_0_0_1`;

-- 检查并添加 domain 字段
ALTER TABLE `w_authorizations` 
ADD COLUMN IF NOT EXISTS `domain` varchar(255) DEFAULT '' COMMENT '授权域名';

-- 检查并添加 server_ip 字段
ALTER TABLE `w_authorizations` 
ADD COLUMN IF NOT EXISTS `server_ip` varchar(50) DEFAULT '' COMMENT '服务器IP';

-- 检查并添加 signature 字段
ALTER TABLE `w_authorizations` 
ADD COLUMN IF NOT EXISTS `signature` varchar(255) DEFAULT '' COMMENT '授权签名';

-- 检查并添加 verify_count 字段
ALTER TABLE `w_authorizations` 
ADD COLUMN IF NOT EXISTS `verify_count` int(11) DEFAULT '0' COMMENT '验证次数';

-- 检查并添加 last_verify_time 字段
ALTER TABLE `w_authorizations` 
ADD COLUMN IF NOT EXISTS `last_verify_time` int(11) DEFAULT '0' COMMENT '最后验证时间';

-- 检查并添加 features 字段
ALTER TABLE `w_authorizations` 
ADD COLUMN IF NOT EXISTS `features` text COMMENT '功能权限JSON';
