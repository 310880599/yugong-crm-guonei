<?php
// ===== 0. 框架初始化 =====
require __DIR__ . '/thinkphp/base.php';
\think\Container::get('app')->path(__DIR__ . '/application/')->initialize();

// ===== 1. 连接 Redis 队列 =====
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);        // 如有密码：$redis->auth('****');

echo "Starting conflict check queue worker...\n";
while (true) {

    /* ---------- 2. 等待并解析任务 ---------- */
    $job = $redis->blPop(['conflict_queue'], 0);   // 阻塞取任务
    if (!$job) continue;

    $payload = json_decode($job[1], true);
    if (!$payload) continue;

    $taskId        = $payload['id']      ?? '';
    $keywordOrigin = $payload['keyword'] ?? '';

    if (!$taskId || $keywordOrigin === '') continue;

    /* ---------- 3. 查库 ---------- */
    $keyword = trim(preg_replace('/[+\-\s]/', '', $keywordOrigin));

    try {
        // 3-1 : 客户名称匹配
        $leadsQuery = \think\Db::name('crm_leads')
            ->alias('l')
            ->field([
                'l.id','l.kh_name','l.xs_area','l.kh_rank','l.kh_status',
                'l.at_user','l.at_time','l.pr_gh_type','l.pr_user',
                \think\Db::raw('NULL AS contact_type'),
                \think\Db::raw('NULL AS contact_value')
            ])
            ->whereLike('l.kh_name', "%{$keyword}%");

        // 3-2 : 联系方式匹配
        $contactsQuery = \think\Db::name('crm_contacts')
            ->alias('c')
            ->leftJoin('crm_leads l','l.id = c.leads_id')
            ->where('c.is_delete', 0)
            ->where(function($q) use($keyword,$keywordOrigin){
                $q->whereLike('c.contact_value', "%{$keyword}%")
                  ->whereOrRaw("CONCAT(c.contact_extra,c.contact_value) LIKE '%{$keyword}%'");
                if ($keywordOrigin !== $keyword) {
                    $q->whereOrLike('c.contact_value', "%{$keywordOrigin}%");
                }
            })
            ->field([
                'l.id','l.kh_name','l.xs_area','l.kh_rank','l.kh_status',
                'l.at_user','l.at_time','l.pr_gh_type','l.pr_user',
                'c.contact_type','c.contact_value'
            ]);

        $sql = '(' . $leadsQuery->buildSql() . ') UNION (' . $contactsQuery->buildSql() . ')';
        $rawList = \think\Db::query($sql);
    } catch (\Exception $e) {
        $rawList = [];
    }

    /* ---------- 4. 去重并补 repeat_info ---------- */
    $seenIds   = [];
    $finalList = [];

    foreach ($rawList as &$row) {
        if (in_array($row['id'], $seenIds, true)) continue;
        $seenIds[] = $row['id'];

        // === 新增：重复类型描述 ===
        if (isset($row['contact_type']) && $row['contact_type'] !== null) {
            switch ((int)$row['contact_type']) {
                case 3: $row['repeat_info'] = 'WhatsApp：' . $row['contact_value']; break;
                case 1: $row['repeat_info'] = '电话：'     . $row['contact_value']; break;
                case 2: $row['repeat_info'] = '邮箱：'     . $row['contact_value']; break;
                case 4: $row['repeat_info'] = '阿里ID：'  . $row['contact_value']; break;
                case 5: $row['repeat_info'] = '微信：'     . $row['contact_value']; break;
                default:$row['repeat_info'] = '未知类型(' . $row['contact_type'] . ')：' . $row['contact_value'];
            }
        } else {
            $row['repeat_info'] = '客户名称重复';
        }

        $finalList[] = $row;
    }
    unset($row);

    // （可选）按添加时间倒序
    usort($finalList, function ($a, $b) {
        return strtotime($b['at_time']) <=> strtotime($a['at_time']);
    });

    /* ---------- 5. 写入 Redis 结果 ---------- */
    $redis->set('conflict_result:' . $taskId,
                json_encode($finalList, JSON_UNESCAPED_UNICODE));
    $redis->expire('conflict_result:' . $taskId, 300);   // 5 分钟

    // 循环进入下一轮
}
