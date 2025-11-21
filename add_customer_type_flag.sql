-- 为 crm_client_order 表添加 customer_type_flag 字段
-- 0 = 公司，1 = 个人
ALTER TABLE `crm_client_order` 
ADD COLUMN `customer_type_flag` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '用户属性：0=公司，1=个人' AFTER `cname`;

