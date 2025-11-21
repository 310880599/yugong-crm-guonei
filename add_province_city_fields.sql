-- 为 crm_client_order 表添加省份和城市字段
-- 用于存储订单的省市信息，支持运营管理的产品分析统计

ALTER TABLE `crm_client_order` 
ADD COLUMN `province` VARCHAR(50) DEFAULT NULL COMMENT '省份' AFTER `client_company`,
ADD COLUMN `city` VARCHAR(50) DEFAULT NULL COMMENT '城市' AFTER `province`;

