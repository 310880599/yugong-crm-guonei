-- 为 crm_leads 表添加 source_port（来源端口）字段
-- 执行此 SQL 语句后，来源端口功能即可正常使用

ALTER TABLE `crm_leads` 
ADD COLUMN `source_port` VARCHAR(100) DEFAULT NULL COMMENT '来源端口' 
AFTER `oper_user`;

