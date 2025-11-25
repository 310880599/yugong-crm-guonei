-- 为 crm_client_order 表添加 invoice_type 字段
-- 用于存储票种性质：普票、专票、不开票
ALTER TABLE `crm_client_order` 
ADD COLUMN `invoice_type` VARCHAR(20) DEFAULT NULL COMMENT '票种性质：普票、专票、不开票' AFTER `invoice_amount`;

