<?php
return [
    'connector'  => 'Redis',        // 使用Redis作为队列后端
    'expire'     => 60,             // 任务最大存活时间（秒）
    'default'    => 'default',      // 默认队列名称
    'host'       => '127.0.0.1',
    'port'       => 26739,          // 外贸CRM使用Redis自定义端口26739
    'password'   => '',             // 如有密码，在此设置
    'select'     => 0,              // 使用第0个Redis数据库
    'timeout'    => 0,              // 超时时间（0表示无限阻塞）
    'persistent' => false,          // 是否使用长连接
];

// return [
//     'connector' => 'Database',
//     'expire'    => 60,
//     'default'   => 'default',
// ];