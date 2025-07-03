<?php
return [
    // 'connector'  => 'sync',//立刻执行
    'connector'  => 'Redis',
    'expire'     => 60,         // 任务最大存活时间，单位秒
    'default'    => 'default',  // 默认队列名称
    'host'       => '127.0.0.1',
    'port'       => 6379,
    'password'   => '',
    'select'     => 0,
    'timeout'    => 0,
    'persistent' => false,
];

// return [
//     'connector' => 'Database',
//     'expire'    => 60,
//     'default'   => 'default',
// ];