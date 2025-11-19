-- 为 crm_client_order 表添加两个图片字段（简单版本）
-- 如果字段已存在，执行时会报错，请忽略错误或先手动检查

ALTER TABLE `crm_client_order` 
ADD COLUMN `wechat_receipt_image` VARCHAR(255) DEFAULT NULL COMMENT '客户微信回执图';

ALTER TABLE `crm_client_order` 
ADD COLUMN `inquiry_assign_image` VARCHAR(255) DEFAULT NULL COMMENT '产品询盘分配图';

