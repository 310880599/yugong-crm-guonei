DROP TABLE IF EXISTS `crm_leads`;

CREATE TABLE `crm_leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `xs_name` varchar(60) DEFAULT NULL COMMENT '线索名称',
  `xs_status` varchar(100) DEFAULT NULL COMMENT '线索_状态',
  `last_up_records` varchar(200) DEFAULT NULL COMMENT '最新跟进记录',
  `last_up_time` datetime DEFAULT NULL COMMENT '实际跟进时间',
  `next_up_time` datetime DEFAULT NULL COMMENT '下次跟进时间  ',
  `remark` text DEFAULT NULL COMMENT '备注',
  `wechat` varchar(30) DEFAULT NULL COMMENT '微信号',
  `xs_source` varchar(200) DEFAULT NULL COMMENT '线索_来源',
  `xs_area` varchar(100) DEFAULT NULL COMMENT '地区来源',
  `at_user` varchar(100) DEFAULT NULL COMMENT '创建人',
  `at_time` datetime DEFAULT NULL COMMENT '创建时间',
  `ut_time` datetime DEFAULT NULL COMMENT '更新时间',
  `pr_user` varchar(30) DEFAULT NULL COMMENT '负责人',
  `pr_user_bef` varchar(30) DEFAULT NULL COMMENT '前负责人',
  `pr_dep` varchar(30) DEFAULT NULL COMMENT '所属部门      不使用 ',
  `pr_dep_bef` varchar(30) DEFAULT NULL COMMENT '前所属部门   不使用 ',
  `to_kh_time` datetime DEFAULT NULL COMMENT '转客户时间',
  `to_gh_time` datetime DEFAULT NULL COMMENT '转公海时间',
  `pr_gh_type` varchar(200) DEFAULT NULL COMMENT '所属公海',
  `kh_name` varchar(100) DEFAULT NULL COMMENT '客户名称',
  `kh_contact` varchar(100) DEFAULT NULL COMMENT '客户联系人',
  `kh_hangye` varchar(255) DEFAULT NULL COMMENT '行业类别',
  `kh_rank` varchar(100) DEFAULT NULL COMMENT '客户级别',
  `kh_status` varchar(100) DEFAULT NULL COMMENT '客户状态',
  `kh_need` varchar(600) DEFAULT NULL COMMENT '客户需求   不使用',
  `status` varchar(30) DEFAULT '0' COMMENT '0-线索，1-客户，2-公海，3-删除',
  `issuccess` int(3) NOT NULL DEFAULT '-1' COMMENT '是否成交 1成交 -1未成交',
  `kh_username` varchar(100) DEFAULT NULL COMMENT '客户用户名',
  `ispublic` int(2) DEFAULT '1' COMMENT '1 公共 2 个人',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX kh_name (kh_name),
  INDEX kh_contact (kh_contact)
) ENGINE = InnoDB AUTO_INCREMENT = 1 DEFAULT CHARSET = utf8mb4 ROW_FORMAT = DYNAMIC COMMENT = 'CRM线索单表';

DROP TABLE IF EXISTS `crm_contacts`;

-- 客户联系方式关系表
CREATE TABLE `crm_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `leads_id` int(11) DEFAULT NULL COMMENT '线索ID',
  `contact_type` tinyint(1) DEFAULT '0' COMMENT '联系方式类型（1-手机号/2-邮箱/3-Whatsapp/4-阿里id/5-微信',
  `contact_extra` varchar(100) DEFAULT '' COMMENT 'extra',
  `contact_value` varchar(255) DEFAULT '' COMMENT '联系方式值',
  `is_delete` tinyint(1) DEFAULT '0' COMMENT '0-正常/1-删除',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_contact` (`contact_type`, `contact_value`) USING BTREE COMMENT '唯一索引',
  INDEX leads_id (leads_id),
  INDEX `idx_contact_value` (`contact_value`) USING BTREE COMMENT '联系方式值索引',
  INDEX `idx_is_delete` (`is_delete`) USING BTREE COMMENT '删除索引'
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 ROW_FORMAT = DYNAMIC COMMENT = '客户联系方式关系表';

-- -- 数据初始化
-- -- 客户等级
-- insert into crm_client_rank (id,rank_name) values (1,'A类客户'),(2,'B类客户'),(3,'C类客户'),(4,'D类客户'),(5,'其他');
-- -- 客户来源
-- truncate table crm_clues_source;
-- ALTER table crm_clues_source modify add_time datetime DEFAULT NULL COMMENT '添加时间';
-- insert into crm_clues_source (id,source_name) values (1,'阿里'),(2,'c端'),(3,'SEM'),(4,'SEO'),(5,'抖音'),(6,'亚马逊');
ALTER TABLE
  `admin`
ADD
  COLUMN `parent_id` INT(11) DEFAULT NULL COMMENT '直属主管admin_id（可选）',
ADD
  COLUMN `team_name` VARCHAR(50) DEFAULT NULL COMMENT '所属团队名称（展示用）';

-- 操作日志表
DROP TABLE IF EXISTS `crm_operation_log`;

CREATE TABLE `crm_operation_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `leads_id` int(11) DEFAULT NULL COMMENT '客户ID',
  `oper_type` varchar(50) NOT NULL COMMENT '操作类型',
  `description` text COMMENT '操作描述',
  `user_id` int(11) NOT NULL COMMENT '操作人id',
  `oper_user` varchar(50) NOT NULL COMMENT '操作人',
  `created_at` datetime NOT NULL COMMENT '操作时间',
  PRIMARY KEY (`id`),
  INDEX `idx_leads_id` (`leads_id`),
  INDEX `idx_oper_type` (`oper_type`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COMMENT = '操作日志表';

-- 订单表新增字段
DROP TABLE IF EXISTS `crm_client_order`;

CREATE TABLE `crm_client_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键id',
  `order_no` VARCHAR(100) NOT NULL COMMENT '订单编号',
  `contact` VARCHAR(100) DEFAULT '' COMMENT '联系方式',
  `cphone` varchar(100) NOT NULL COMMENT '客户手机号',
  `pr_user` varchar(100) NOT NULL COMMENT '负责人名称',
  `cname` varchar(100) NOT NULL COMMENT '客户名称',
  `country` VARCHAR(100) DEFAULT '' COMMENT '国家',
  `source` VARCHAR(100) DEFAULT '' COMMENT '询盘来源',
  `team_name` VARCHAR(100) DEFAULT '' COMMENT '团队名称',
  `customer_type` VARCHAR(100) DEFAULT '' COMMENT '客户性质',
  `product_name` VARCHAR(100) DEFAULT '' COMMENT '产品名称',
  `order_time` DATETIME DEFAULT NULL COMMENT '成交时间',
  `money` DECIMAL(10, 2) NOT NULL COMMENT '订单金额',
  `ticheng` DECIMAL(10, 2) DEFAULT 0 COMMENT '提成金额',
  `profit` DECIMAL(10, 2) DEFAULT 0 COMMENT '利润',
  `margin_rate` DECIMAL(10, 2) DEFAULT 0 COMMENT '利润率',
  `status` varchar(20) NOT NULL DEFAULT '1' COMMENT '业绩状态 1 待审核 2 审核不通过 3 审核通过',
  `remark` varchar(255) DEFAULT '' COMMENT '备注',
  `at_user` varchar(100) NOT NULL COMMENT '创建人',
  `create_time` datetime NOT NULL COMMENT '添加时间',
  `ut_time` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COMMENT = '客户订单表';


ALTER TABLE `crm_contacts`
ADD COLUMN `vdigits` varchar(255) DEFAULT '' COMMENT '纯数字' AFTER `contact_value`,
ADD INDEX `inx_vdigits` (`vdigits`) USING BTREE;


-- 运营端需求
ALTER TABLE `admin`
ADD COLUMN `org` varchar(10) DEFAULT '' COMMENT '所属组织(admin,1s,2s,3s)',
ADD COLUMN `channel` varchar(30) DEFAULT '' COMMENT '渠道',
ADD COLUMN `position` tinyint(1) DEFAULT 0 COMMENT '1-运营总监/2-运营主管';

ALTER TABLE `crm_leads`
ADD COLUMN `oper_user` varchar(30) DEFAULT '' COMMENT '所属运营',
ADD COLUMN  `product_name` varchar(100) DEFAULT '' COMMENT '产品名称';

ALTER TABLE `crm_client_order`
ADD COLUMN `oper_user` varchar(30) DEFAULT '' COMMENT '所属运营';