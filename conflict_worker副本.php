<?php
// 引入ThinkPHP框架初始化文件（根据项目实际路径调整）
require __DIR__ . '/thinkphp/base.php';
\think\Container::get('app')->path(__DIR__ . '/application/')->initialize();

// 使用 Redis 扩展连接Redis队列
$redis = new \Redis();
$redis->connect('127.0.0.1', 26739);
// 如有密码，请取消下面注释并设置密码
// $redis->auth('YOUR_REDIS_PASSWORD');

// 阻塞地从队列读取任务并处理
echo "Starting conflict check queue worker...\n";
while (true) {
    // 从队列左侧阻塞获取任务（列表名称为 'conflict_queue'）
    $job = $redis->blPop(['conflict_queue'], 0);
    // $job 通常是一个数组 [ 'conflict_queue', '任务JSON字符串' ]
    if (!$job) {
        continue; // 意外返回空，继续循环
    }
    $data = json_decode($job[1], true);
    if (!$data) {
        // 数据格式不对，跳过
        continue;
    }
    $taskId        = $data['id'] ?? '';
    $keywordOrigin = $data['keyword'] ?? '';
    if (empty($taskId) || $keywordOrigin === '') {
        // 缺少必要信息，跳过
        continue;
    }

    // **执行查重逻辑**：按照原有冲突查询规则查询数据库
    // 对关键词进行格式化（与控制器一致的处理）
    $keyword = trim(preg_replace('/[+\-\s]/', '', $keywordOrigin));
    try {
        // 构建查询：在 crm_leads 表中按客户名称模糊查询
        $leadsQuery = \think\Db::name('crm_leads')
            ->alias('l')
            ->field('l.id, l.kh_name, l.xs_area, l.kh_rank, l.kh_status, l.at_user, l.at_time, l.pr_gh_type, l.pr_user')
            ->where('l.kh_name', 'like', "%{$keyword}%");
        // 构建查询：在 crm_contacts 表中按联系方式精确或模糊查询
        $contactsQuery = \think\Db::name('crm_contacts')
            ->alias('c')
            ->leftJoin('crm_leads l', 'l.id = c.leads_id')
            ->where('c.is_delete', 0)
            ->where(function($q) use ($keyword, $keywordOrigin) {
                // 联系方式完全匹配或“扩展+号码”拼接匹配
                $q->where('c.contact_value', 'like', "%{$keyword}%")
                  ->whereOrRaw("CONCAT(c.contact_extra, c.contact_value) like '%{$keyword}%'");
                // 如果原始keyword含特殊字符（如空格或+），也对未经trim的keyword做like查询
                if ($keywordOrigin !== $keyword) {
                    $q->whereOr('c.contact_value', 'like', "%{$keywordOrigin}%");
                }
            })
            ->field('l.id, l.kh_name, l.xs_area, l.kh_rank, l.kh_status, l.at_user, l.at_time, l.pr_gh_type, l.pr_user');
        // 执行 UNION 查询，将两部分结果合并
        $sql1 = $leadsQuery->buildSql();
        $sql2 = $contactsQuery->buildSql();
        $rawList = \think\Db::query("({$sql1}) UNION ({$sql2})");
    } catch (\Exception $e) {
        // 查询出现异常，将空结果存入并继续
        $rawList = [];
    }

    // 去除重复的客户记录（按ID去重）
    $seenIds = [];
    $finalList = [];
    foreach ($rawList as $row) {
        if (!in_array($row['id'], $seenIds)) {
            $seenIds[] = $row['id'];
            $finalList[] = $row;
        }
    }

    // **存储查重结果**：将结果列表缓存到Redis，供前端轮询获取
    if (!empty($finalList)) {
        // 可选：根据需要对结果排序（例如按添加时间倒序），确保结果有序
        // usort($finalList, function($a, $b){ return strcmp($b['at_time'], $a['at_time']); });
    }
    $resultKey = 'conflict_result:' . $taskId;
    $redis->set($resultKey, json_encode($finalList));
    // 设置结果键过期时间，例如5分钟，避免长期占用内存
    $redis->expire($resultKey, 300);

    // （循环继续等待下一个任务）
}
