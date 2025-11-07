<?php
// ===== 0. 初始化 =====
require __DIR__ . '/thinkphp/base.php';
\think\Container::get('app')->path(__DIR__ . '/application/')->initialize();

// ===== 1. 连接 Redis 队列 =====
$redis = new \Redis();
$redis->connect('127.0.0.1', 26739);
$redis->auth('csE88ifakDGC8PfH');   // 如有密码请取消注释

echo "Starting conflict check queue worker for 外贸CRM...\n";

while (true) {

    /* ---------- 2. 阻塞取任务 ---------- */
    $job = $redis->blPop(['waimao_conflict_queue'], 0);  // 使用外贸前缀的队列键
    if (!$job) continue;

    $payload = json_decode($job[1], true);
    if (!$payload) continue;

    $taskId        = $payload['id']      ?? '';
    $keywordOrigin = $payload['keyword'] ?? '';

    if (!$taskId || $keywordOrigin === '') continue;

    /* ---------- 3. 清洗关键词 ---------- */
    $keyword = trim(preg_replace('/[+\-\s]/', '', $keywordOrigin));

    /* ---------- 4. 查询数据库 ---------- */
    $rawList = [];

    try {
        // 4-1 客户名称匹配
        $leadsQuery = \think\Db::name('crm_leads')
            ->alias('l')
            ->field([
                'l.id', 'l.kh_name', 'l.xs_area', 'l.kh_rank', 'l.kh_status',
                'l.at_user', 'l.at_time', 'l.pr_gh_type', 'l.pr_user',
                \think\Db::raw('NULL AS contact_type'),
                \think\Db::raw('NULL AS contact_value')
            ])
            ->whereLike('l.kh_name', "%{$keyword}%");

        // 4-2 联系方式匹配
        $contactsQuery = \think\Db::name('crm_contacts')
            ->alias('c')
            ->leftJoin('crm_leads l', 'l.id = c.leads_id')
            ->where('c.is_delete', 0)
            ->where(function ($q) use ($keyword, $keywordOrigin) {
                $q->whereLike('c.contact_value', "%{$keyword}%")
                ->whereOr('c.vdigits', 'like',"%{$keyword}%")
                  ->whereOrRaw("CONCAT(c.contact_extra, c.contact_value) LIKE '%{$keyword}%'");
                if ($keywordOrigin !== $keyword) {
                    // 原串（含空格 / + -）再查一次，避免漏匹配
                    $q->whereOr('c.contact_value', 'like',"%{$keywordOrigin}%");

                }
            })
            ->field([
                'l.id', 'l.kh_name', 'l.xs_area', 'l.kh_rank', 'l.kh_status',
                'l.at_user', 'l.at_time', 'l.pr_gh_type', 'l.pr_user',
                'c.contact_type', 'c.contact_value'
            ]);

        // UNION
        $sql      = '(' . $leadsQuery->buildSql() . ') UNION (' . $contactsQuery->buildSql() . ')';
        $rawList  = \think\Db::query($sql);

    } catch (\Exception $e) {
        // 记录或忽略异常
        $rawList = [];
    }

    /* ---------- 5. 去重并生成 repeat_info ---------- */
    $seenIds   = [];
    $finalList = [];

    foreach ($rawList as &$row) {

        if (in_array($row['id'], $seenIds, true)) continue; // 每条线索保留一次
        $seenIds[] = $row['id'];

        if (isset($row['contact_type']) && $row['contact_type'] !== null) {
            switch ((int)$row['contact_type']) {
                case 1: $row['repeat_info'] = '主电话：'     . $row['contact_value']; break;
                case 2: $row['repeat_info'] = '邮箱：'     . $row['contact_value']; break;
                case 3: $row['repeat_info'] = '辅助电话：' . $row['contact_value']; break;
                case 4: $row['repeat_info'] = '阿里ID：'   . $row['contact_value']; break;
                case 5: $row['repeat_info'] = '微信：'     . $row['contact_value']; break;
                default:$row['repeat_info'] = '未知类型(' . $row['contact_type'] . ')：' . $row['contact_value'];
            }
        } else {
            $row['repeat_info'] = '客户名称重复';
        }

        $finalList[] = $row;
    }
    unset($row);

    // 时间倒序
    usort($finalList, function ($a, $b) {
        return strtotime($b['at_time']) <=> strtotime($a['at_time']);
    });

    /* ---------- 6. 写入 Redis 结果 & 状态 ---------- */
    $resultKey = 'waimao_conflict_result:' . $taskId;
    $statusKey = 'waimao_conflict_status:' . $taskId;

    $redis->set($resultKey, json_encode($finalList, JSON_UNESCAPED_UNICODE));
    $redis->expire($resultKey, 300);          // 结果 5 分钟有效

    // 设置状态为 done
    $redis->set($statusKey, 'done');
    $redis->expire($statusKey, 300);         // 状态 5 分钟有效

    // —— 循环下一任务 ——
}
