<?php
namespace app\admin\model;

use think\Model;

class ProductCategory extends Model
{
    // 指定对应的数据表名
    protected $table = 'crm_product_category';
    protected $pk    = 'id';

    // 用 int 时间戳写入/读取；字段名对应你的表
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'add_time';
    protected $updateTime = 'edit_time';
    protected $dateFormat = false;  // 不让 TP 在取出时自动格式化
}
