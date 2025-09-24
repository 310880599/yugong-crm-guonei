<?php

namespace app\admin\controller;

use think\Db;
use think\facade\Request;
use think\facade\Session;
use app\admin\model\Admin;

class Operator extends Common
{
    public function perList()
    {
        if (request()->isPost()) {
            return $this->perSearch();
        }

        $current_admin = Admin::getMyInfo();
        $khRankList = Db::table('crm_client_rank')->select();
        $this->assign('khRankList', $khRankList);
        $productList = $this->getProductList();
        $this->assign('productList', $productList);
      $adminResult = Db::name('admin')
    ->where('group_id', '<>', 1)
    ->where($this->getOrgWhere($current_admin['org']))
    ->field('admin_id,username,team_name') // 添加 team_name
    ->select();
$this->assign('adminResult', $adminResult);
         $teamNames = Db::name('admin')
        ->where('group_id', '<>', 1)
        ->where($this->getOrgWhere($current_admin['org']))
        ->where('team_name', '<>', '') // 排除空团队
        ->distinct(true)
        ->column('team_name');
    
    // 转换为模板需要的格式
    $teamResult = [];
    foreach ($teamNames as $teamName) {
        $teamResult[] = ['team_name' => $teamName];
    }
    $this->assign('teamResult', $teamResult);
        return $this->fetch();
    }

  public function perSearch($params = null)
{
    // 如果没有传入参数，从请求获取
    if ($params === null) {
        $params = Request::param();
    }
    
    $keyword = $params['keyword'] ?? [];
    $model   = model('client');

    // 如果有联系人搜索条件
    if (!empty($keyword['contact'])) {
        $con     = $keyword['contact'];
        $cleaned = preg_replace('/[^\w@._#]/', '', $con);
        $cfun =  function ($q) use ($con, $cleaned) {
            $q->where('contact_value', 'like', '%' . $con . '%')
                ->whereOrRaw("CONCAT(contact_extra, contact_value) like '%{$con}%'")
                ->whereOrRaw("CONCAT(contact_extra, vdigits) like '%{$cleaned}%'");
        };
        $model = $model->hasWhere('contacts', $cfun)->with(['contacts' => $cfun]);
    } else {
        $model = $model->with('contacts');
    }

    // 确保获取分页参数
    $page = $params['page'] ?? input('page', 1);
    $limit = $params['limit'] ?? input('limit', 15); // 恢复默认分页值

    $list = $this->_search($params, $model, function ($query, $p) {
        $keyword = $p['keyword'] ?? [];
         $query->alias('c')
              ->join('admin a', 'c.pr_user = a.username', 'LEFT')
              ->field('c.*, a.team_name')
              ->append(['contact'])
              ->hidden(['contacts']);
        
        if (!empty($keyword['kh_rank'])) {
            $query->where('kh_rank', '=', $keyword['kh_rank']);
        }
        if (!empty($keyword['status'])) {
            $query->where('status', '=', $keyword['status']);
        }
        if (!empty($keyword['kh_name'])) {
            $query->where('kh_name', 'like', '%' . $keyword['kh_name'] . '%');
        }
        if (!empty($keyword['pr_user'])) {
            $query->where('pr_user', '=', $keyword['pr_user']);
        }
        if (!empty($keyword['product_name'])) {
            $query->where('product_name', 'like', '%' . $keyword['product_name'] . '%');
        }
        if (!empty($keyword['timebucket'])) {
            $where = $this->getClientimeWhere($keyword['timebucket']);
            $query->where($where);
        }
        if (!empty($keyword['at_time'])) {
            $where = $this->getClientimeWhere($keyword['at_time']);
            $query->where($where);
        }
        if (!empty($keyword['team_name'])) {
            $current_admin = Admin::getMyInfo();
            $usernames = Db::name('admin')
                ->where('team_name', $keyword['team_name'])
                ->where($this->getOrgWhere($current_admin['org']))
                ->column('username');
            
            if (!empty($usernames)) {
                $query->whereIn('pr_user', $usernames);
            } else {
                // 没有匹配的用户，返回空结果
                $query->where('1=0');
            }
        }
        // 限制当前用户
        $query->where(['oper_user' => Session::get('username')]);
        return $query;
    }, $page, $limit);

    return [
        'code'  => 0,
        'msg'   => '获取成功!',
        'data'  => $list['data'],
        'count' => $list['total'],
        'rel'   => 1
    ];
}

/**
 * 导出全部客户数据
 */
public function exportAll()
{
    // 1. 直接获取所有请求参数
    $allParams = Request::param();
    
    // 2. 提取 keyword 参数（处理可能的嵌套结构）
    $keyword = [];
    if (isset($allParams['keyword']) && is_array($allParams['keyword'])) {
        $keyword = $allParams['keyword'];
    } else {
        // 处理扁平化参数（如 keyword[kh_rank]=xxx）
        foreach ($allParams as $key => $value) {
            if (strpos($key, 'keyword[') === 0) {
                $field = substr($key, 8, -1); // 提取字段名
                $keyword[$field] = $value;
            }
        }
    }
    
    // 3. 确保时间参数一致性
    if (!empty($keyword['timebucket']) && $keyword['timebucket'] !== '') {
        $keyword['at_time'] = ''; // 清空自定义时间范围
    }
    
    // 4. 创建查询参数
    $params = [
        'keyword' => $keyword,
        'page' => 1,
        'limit' => PHP_INT_MAX
    ];
    
    // 5. 记录调试信息（关键！）
    \think\facade\Log::info('ExportAll received params: ' . json_encode($allParams));
    \think\facade\Log::info('ExportAll processed keyword: ' . json_encode($keyword));
    
    // 6. 直接调用 perSearch，传入参数
    $result = $this->perSearch($params);
    
    // 7. 检查是否有数据
    if (empty($result['data'])) {
        $this->error('没有可导出的数据');
    }
    
    // 8. 记录导出数量
    \think\facade\Log::info('Exporting ' . count($result['data']) . ' records');
    
    // 9. 导出Excel
    $this->exportToExcel($result['data']);
}

/**
 * 导出数据到Excel
 * @param array $data 要导出的数据
 */
private function exportToExcel($data)
{
    // 检查是否安装了必要的扩展
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        $this->error('请先安装PhpSpreadsheet库');
    }
    
    // 1. 创建Excel对象
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // 2. 设置标题行
    $headers = [
        '团队名称','客户名称', '产品', '地区', '联系方式', '客户级别', 
        '客户来源', '客户状态', '成交状态', '最新跟进记录', 
        '负责人', '创建时间'
    ];
    
    // 填充标题
    foreach ($headers as $index => $header) {
        $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
    }
    
     // 3. 填充数据
    $row = 2;
    foreach ($data as $item) {
        $col = 1;
        // ✅ 1. 先填充团队名称 (第一列)
        $sheet->setCellValueByColumnAndRow($col++, $row, $item['team_name'] ?? '');
        
        // ✅ 2. 再填充客户名称 (第二列)
        $sheet->setCellValueByColumnAndRow($col++, $row, $item['kh_name']);
        
        // ✅ 3. 其他字段保持不变
        $sheet->setCellValueByColumnAndRow($col++, $row, $item['product_name']);
        $sheet->setCellValueByColumnAndRow($col++, $row, $item['xs_area']);
        
        // 安全处理 contact 字段
        $contact = is_array($item['contact']) ? 
                   implode(',', $item['contact']) : 
                   (string)($item['contact'] ?? '');
        $sheet->setCellValueByColumnAndRow($col++, $row, $contact);
        
        $sheet->setCellValueByColumnAndRow($col++, $row, $item['kh_rank']);
        $sheet->setCellValueByColumnAndRow($col++, $row, $item['kh_status']);
        
        // 客户状态特殊处理
        $status = '';
        if (isset($item['status']) && $item['status'] == 1) {
            $status = $item['to_kh_time'] ? '公海提取' : '正常';
        } else {
            $status = '在公海';
        }
        $sheet->setCellValueByColumnAndRow($col++, $row, $status);
        
        // 成交状态
        $sheet->setCellValueByColumnAndRow($col++, $row, $item['issuccess'] == 1 ? '已成交' : '未成交');
        $sheet->setCellValueByColumnAndRow($col++, $row, $item['last_up_records']);
        $sheet->setCellValueByColumnAndRow($col++, $row, $item['pr_user']);
        $sheet->setCellValueByColumnAndRow($col++, $row, $item['at_time']);
        
        $row++;
    }
    
    // 4. 设置自动列宽
    foreach (range('A', $sheet->getHighestColumn()) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // 5. 设置HTTP头
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="客户列表_' . date('Ymd') . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    // 6. 输出Excel
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

    //跟进
    public function dialogue()
    {
        $result = Db::table('crm_leads')->where(['id' => Request::param('id')])->find();
        $result['comment'] = Db::table('crm_comment')->alias('com')->join('admin adm', 'com.user_id = adm.admin_id')->where(['leads_id' => Request::param('id')])->field('com.*,adm.username,adm.avatar')->select();
        foreach ($result['comment'] as $k => $v) {
            $result['comment'][$k]['reply'] = Db::table('crm_reply')->where(['comment_id' => $v['id']])->select();
        }
        $current_admin = Admin::getMyInfo();
        $this->assign('group_id', $current_admin['group_id']);
        $this->assign('curname', $current_admin['username']);
        $this->assign('result', $result);
        return $this->fetch();
    }

    public function order()
    {
        if (request()->isPost()) {
            $params = Request::param();
            if (!isset($params['keyword'])) {
                $params['keyword'] = [];
            }
            $params['keyword']['timebucket'] = 'month';
            Request::merge($params);
            return $this->personOrderSearch();
        }
        $this->assign('customer_type', Order::CUSTOMER_TYPE);
        $this->assign('sourceList', Db::name('crm_client_status')->distinct(true)->column('status_name'));
        return $this->fetch();
    }

    public function personOrderSearch()
    {
        $where = [];
        $client_where = [];
        $oper_user = Session::get('username') ?? '';
        //展示自己创建的订单
        $where[] = ['oper_user', '=', $oper_user];
        $client_where[] = ['oper_user', '=', $oper_user];
        //判断权限
        $page = input('page') ?? 1;
        $limit = input('limit') ?? config('pageSize');
        $keyword = Request::param('keyword');
        // 过滤掉 null 元素
        if ($keyword) $keyword = array_filter($keyword);

        // if (isset($keyword['status'])) $where[] = ['status', '=', $keyword['status']];
        if (isset($keyword['order_no'])) $where[] = ['order_no', 'like', "%{$keyword['order_no']}%"];
        if (isset($keyword['timebucket'])) {
            $where[] = $this->buildTimeWhere($keyword['timebucket'], 'order_time');

            $timeWhere['at_time'] = $this->buildTimeWhere($keyword['timebucket'], 'at_time');
            $timeWhere['to_kh_time'] = $this->buildTimeWhere($keyword['timebucket'], 'to_kh_time');
            $client_where[] =  function ($query) use ($timeWhere) {
                $query->where(...$timeWhere['at_time']);
                $query->whereOr(...$timeWhere['to_kh_time']);
            };
        }
        if (isset($keyword['min_money'])) $where[] = ['money', '>', $keyword['min_money']];
        if (isset($keyword['max_money'])) $where[] = ['money', '<', $keyword['max_money']];
        if (isset($keyword['min_profit'])) $where[] = ['profit', '>', $keyword['min_profit']];
        if (isset($keyword['max_profit'])) $where[] = ['profit', '<', $keyword['max_profit']];
        if (isset($keyword['min_margin_rate'])) $where[] = ['margin_rate', '>', $keyword['min_margin_rate']];
        if (isset($keyword['max_margin_rate'])) $where[] = ['margin_rate', '<', $keyword['max_margin_rate']];
        if (isset($keyword['cname'])) {
            $where[] = ['cname', 'like', "%{$keyword['cname']}%"];
            // $client_where[] = ['kh_name', 'like', "%{$keyword['cname']}%"];
        }
        if (isset($keyword['contact'])) {
            $where[] = ['contact', 'like', "%{$keyword['contact']}%"];
        }
        if (isset($keyword['customer_type'])) {
            $where[] = ['customer_type', '=', $keyword['customer_type']];
        }
        if (isset($keyword['product_name'])) {
            $where[] = ['product_name', 'like', "%{$keyword['product_name']}%"];
        }
        if (isset($keyword['source'])) {
            $where[] = ['source', '=', $keyword['source']];
            //兼容历史数据
            $kh_source = strtolower($keyword['source']);
            $client_where[] = ['kh_status', 'like', "%$kh_source%"];
        }
        $list = Db::table('crm_client_order')
            ->where($where)
            ->order('order_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ])
            ->toArray();


        //成单率

        $totalInquiries = Db::table('crm_leads')->where('status', 1)->where($client_where)->count();

        $successOrders = $list['total'];
        $successRate = $totalInquiries > 0 ? ($successOrders / $totalInquiries * 100) : 0;
        $totalMoney = Db::table('crm_client_order')
            ->where($where)
            ->sum('money');
        $totalProfit = Db::table('crm_client_order')
            ->where($where)
            ->sum('profit');
        return $result = [
            'code' => 0,
            'msg' => '获取成功!',
            'data' => $list['data'],
            'count' => $list['total'],
            'rel' => 1,
            'totalInquiries' => $totalInquiries,
            'successRate' => number_format($successRate, 2),
            'totalMoney' => number_format($totalMoney, 2),
            'totalProfit' => number_format($totalProfit, 2),
        ];
    }


    //数据分析
    public function main()
    {

        $params  = Request::param();

        //最近跟进动态  
        $result = Db::table('crm_leads')
            ->alias('l')
            ->join('crm_comment c', 'c.leads_id = l.id')
            ->join('admin a', 'c.user_id = a.admin_id')
            ->field('l.id,a.username,a.avatar,l.kh_name,c.reply_msg,c.create_date')
            ->order('c.create_date desc')
            ->where(['l.oper_user' => Session::get('username')])
            ->limit(10)->select();
        $this->assign('result', $result);

        //管理员
        $strTimeToString = "000111222334455556666667";
        $strWenhou = array('夜深了，', '凌晨了，', '早上好！', '上午好！', '中午好！', '下午好！', '晚上好！', '夜深了，');
        //echo $strWenhou[(int)$strTimeToString[(int)date('G',time())]];
        $this->assign('wenhou', '尊敬的管理员' . $strWenhou[(int)$strTimeToString[(int)date('G', time())]]);

        //跟进数据
        $wheretoday = [];
        $wheretoday['oper_user'] = Session::get('username');
        $wheretoday['status'] = 1;
        $wheretoday['issuccess'] = -1;
        $all_count = Db::table('crm_leads')->where($wheretoday)->count();
        $today_count = Db::table('crm_leads')->where($wheretoday)->whereTime('last_up_time', 'today')->count();
        if ($all_count > 0) {
            $genjinlv = $today_count / $all_count * 100;
        } else {
            $genjinlv = 0;
        }

        $this->assign('all_count', $all_count - $today_count);
        $this->assign('today_count', $today_count);
        $this->assign('genjinlv', round($genjinlv, 2));
        if (request()->isPost()) {
            $data = $this->getPanelData($params);
            $this->assign('data', $data);
            return $this->fetch('main_content');
        }
        $params['keyword']['timebucket'] = 'today';
        $data = $this->getPanelData($params);
        $this->assign('data', $data);
        return $this->fetch('op_main');
    }

    public function perPanel()
    {
        $params  = Request::param();
        if (request()->isPost()) {
            $data = $this->getPerPanelData($params);
            $this->assign('data', $data);
            return $this->fetch('per_content');
        }
        $params['keyword']['timebucket'] = 'today';
        $data = $this->getPerPanelData($params);
        $this->assign('data', $data);
        return $this->fetch();
    }

    private function getPanelData($params)
    {
        $data = [
            'yw_data' => [],
            'yy_data' => [],
            'product_data' => [],
        ];
        $keyword  = $params['keyword'] ?? [];
        $current_admin = Admin::getMyInfo();
        $where = [$this->getOrgWhere($current_admin['org']), ['is_open', '=', 1],];
        $l_where = [['status', '=', 1]];
        $o_where = [];
        if (!empty($keyword['timebucket'])) {
            $l_where[] = $this->getClientimeWhere($keyword['timebucket']);
            $o_where[] = $this->buildTimeWhere($keyword['timebucket'], 'order_time');
        }
        if (!empty($keyword['at_time'])) {
            $l_where[] = $this->getClientimeWhere($keyword['at_time']);
            $o_where[] = $this->buildTimeWhere($keyword['at_time'], 'order_time');
        }

        //业务询盘数据
        $yw_where = array_merge($where, [['group_id', 'in', [$this->ywgid, $this->ywzgid]]]);
        $ywData = $this->getLeadsSubQuery($l_where)->where($yw_where)->group('a.username,a.team_name')->field('a.username,a.team_name,count(pr_user) as yw_num')->order('yw_num desc')->order('a.team_name')->order('a.username')->select();
        $ywData_total = $this->getLeadsSubQuery($l_where)->where('a.team_name', '<>', '')->where($yw_where)->group('a.team_name')->field('a.team_name,count(pr_user) as yw_num')->order('yw_num desc')->order('a.team_name')->select();

        //运营数据
        $yy_where = array_merge($where, [['group_id', '=', $this->yygid]]);
        $yyData = $this->getLeadsSubQuery($l_where, 'oper_user')->where($yy_where)->group('username,team_name,channel')->field('username,team_name,channel,count(oper_user) as yy_num')->order('yy_num', 'desc')->order('team_name')->order('channel')->order('username')->select();
        $yyData_total = $this->getLeadsSubQuery($l_where, 'oper_user')->where('channel', '<>', '')->where($yy_where)->group('team_name,channel')->field('team_name,channel,count(oper_user) as yy_num')->order('yy_num', 'desc')->order('team_name')->order('channel')->select();

        //产品数据
        $oper_prod = Db::table('crm_leads')->join('admin', 'crm_leads.pr_user = admin.username')->where($where)->where($l_where)->where('product_name', '<>', '')->group('product_name')->field('product_name,count(product_name) as count')->order('count', 'desc')->limit(10)->select();
        $order_prod = Db::table('crm_client_order')->join('admin', 'crm_client_order.oper_user = admin.username')->where($where)->where($o_where)->where('product_name', '<>', '')->group('product_name')->field('product_name,count(product_name) as count')->order('count', 'desc')->limit(10)->select();

        $xp_count = Db::table('crm_leads')->where('status', 1)->where([$this->getClientimeWhere('month')])->where('oper_user', $current_admin['username'])->count();
        $profit = Db::table('crm_client_order')->where([$this->buildTimeWhere('month', 'order_time')])->where('oper_user', $current_admin['username'])->sum('profit');
        $data['xp_count'] = $xp_count;
        $data['profit'] = $profit;

        $data['yw_data']['list'] = $ywData;
        $data['yw_data']['total'] = $ywData_total;
        $data['yy_data']['list'] = $yyData;
        $data['yy_data']['total'] = $yyData_total;
        $data['product_data']['oper_prod'] = $oper_prod;
        $data['product_data']['order_prod'] = $order_prod;

        $data['org'] = trim($current_admin['org'], $this->org_fgx);

        return $data;
    }

    private function getPerPanelData($params)
    {
        $data = [
            'yw_data' => [],
            'yy_data' => [],
            'product_data' => [],
        ];
        $keyword  = $params['keyword'] ?? [];
        $current_admin = Admin::getMyInfo();
        $where = [$this->getOrgWhere($current_admin['org']), ['is_open', '=', 1]];
        $l_where = [['oper_user', '=', $current_admin['username']], ['status', '=', 1]];
        if (!empty($keyword['timebucket'])) {
            $l_where[] = $this->buildTimeWhere($keyword['timebucket'], 'at_time');
        }
        if (!empty($keyword['at_time'])) {
            $l_where[] = $this->buildTimeWhere($keyword['at_time'], 'at_time');
        }

        //业务询盘数据
        $yw_where = array_merge($where, [['group_id', 'in', [$this->ywgid, $this->ywzgid]]]);
        $ywData = $this->getLeadsSubQuery($l_where)->where($yw_where)->group('a.username,a.team_name')->field('a.username,a.team_name,count(pr_user) as yw_num')->order('yw_num desc')->order('a.team_name')->order('a.username')->select();
        $ywData_total = $this->getLeadsSubQuery($l_where)->where('a.team_name', '<>', '')->where($yw_where)->group('a.team_name')->field('a.team_name,count(pr_user) as yw_num')->order('yw_num desc')->order('a.team_name')->select();


        //产品数据
        $oper_prod = Db::table('crm_leads')->join('admin', 'crm_leads.pr_user = admin.username')->where($where)->where($l_where)->where('product_name', '<>', '')->group('product_name')->field('product_name,count(product_name) as count')->order('count', 'desc')->select();
        $data['yw_data']['list'] = $ywData;
        $data['yw_data']['total'] = $ywData_total;
        $data['product_data']['oper_prod'] = $oper_prod;
        return $data;
    }

    private function getLeadsSubQuery($where, $field = 'pr_user')
    {
        $op_field = $field == 'pr_user'?'oper_user': 'pr_user';
        $current_admin = Admin::getMyInfo();
        $users = Db::table('admin')->where($this->getOrgWhere($current_admin['org']))->column('username');
        $subQuery = Db::table('crm_leads')
            ->where($where)->where($op_field,'in',$users)
            ->buildSql();
        return Db::table('admin')->alias('a')
            ->leftJoin([$subQuery => 'l'], 'a.username = l.' . $field);
    }

    public function getProdAll()
    {
        $type = Request::param('type');
        if (!in_array($type, ['order', 'oper'])) {
            return ['code' => 400, 'msg' => '参数错误'];
        }
        $current_admin = Admin::getMyInfo();
        $where = [$this->getOrgWhere($current_admin['org']), ['is_open', '=', 1],];
        $l_where = [];
        $o_where = [];
        $time = '';
        if (Request::param('timebucket')) {
            $time = Request::param('timebucket');
        }
        if (Request::param('at_time')) {
            $time = Request::param('at_time');
        }
        if ($time) {
            $l_where[] = $this->buildTimeWhere($time, 'at_time');
            $o_where[] = $this->buildTimeWhere($time, 'order_time');
        }
        if ($type == 'oper') {
            $l_where[] = ['status', '=', 1];
            $data = Db::table('crm_leads')->join('admin', 'crm_leads.pr_user = admin.username')->where($where)->where($l_where)->where('product_name', '<>', '')->group('product_name')->field('product_name,count(product_name) as count')->order('count', 'desc')->select();
        } else {
            $data = Db::table('crm_client_order')->join('admin', 'crm_client_order.oper_user = admin.username')->where($where)->where($o_where)->where('product_name', '<>', '')->group('product_name')->field('product_name,count(product_name) as count')->order('count', 'desc')->select();
        }
        return  json([
            'code' => 200,
            'msg' => '获取成功!',
            'data' => $data,
        ]);
    }

    public function getDetail()
    {

        $timebucket = Request::param('timebucket', '');
        $at_time = Request::param('at_time', '');
        if (Request::isPost()) {
            // $type = Request::param('type', 'yw-total');
            if ($timebucket || $at_time) {
                $timebucket = $timebucket ? $timebucket : $at_time;
            }
            if (!$timebucket) $timebucket = 'today';

            // if (!in_array($type, ['yw-total', 'yy-total'])) {
            //     return ['code' => 400, 'msg' => '参数错误'];
            // }

            $data = $this->getCrossData($timebucket);
            return json([
                'code' => 200,
                'msg' => '获取成功!',
                'data' => $data
            ]);
        }
        $this->assign('timebucket', $timebucket);
        $this->assign('at_time', $at_time);
        return $this->fetch();
    }

    private function getCrossData($timebucket)
    {
        $current_admin = Admin::getMyInfo();
        $a_where = [$this->getOrgWhere($current_admin['org']), ['is_open', '=', 1], ['group_id', 'in', [$this->ywgid, $this->ywzgid, $this->yygid]]];
        $l_where = [['status', '=', 1], $this->getClientimeWhere($timebucket)];
        // 时间条件
        if (!empty($timebucket)) {
            $l_where[] = $this->getClientimeWhere($timebucket);
        }
        //时间段内所有客户
        $leads = Db::table('crm_leads')->where($l_where)->fetchCollection()->select();

        //所有业务和运营
        $admins = Db::table('admin')
            ->where($a_where)
            ->where('username', '<>', '')
            ->order('team_name,channel,username')->fetchCollection()
            ->select();

        //所有运营
        $yy_admins = $admins->where('group_id', $this->yygid)->toArray();
        //所有业务
        $yw_admins = $admins->where('group_id', 'in', [$this->ywgid, $this->ywzgid])->toArray();
        // $cross=[['姓名',...$yy_admins,'总计']];
        // 构建交叉数据
        $cross = [];
        foreach ($leads as $lead) {
            if (!isset($cross[$lead['pr_user']])) {
                $cross[$lead['pr_user']] = [];
            }
            if (!isset($cross[$lead['pr_user']][$lead['oper_user']])) {
                $cross[$lead['pr_user']][$lead['oper_user']] = 1;
            } else {
                $cross[$lead['pr_user']][$lead['oper_user']]++;
            }
        }

        // 按团队分组业务人员
        $team_colors = ['#db7070','#c6db70','#70db9b','#709bdb','#c670db',  '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FECA57', '#cf1717', '#aacf17', '#17cf60', '#1760cf', '#aa17cf']; // 每个组的颜色
        $color_index = 0;

        // 构建横向表头（所有运营）
        $headers = ['姓名'];
        foreach ($yy_admins as $yy_admin) {
            $headers[] = $yy_admin['username'];
        }
        $headers[] = '合计';

        // 按团队处理业务人员
        $teams = [];
        foreach ($yw_admins as $yw_admin) {
            $team_name = $yw_admin['team_name'];
            if (!isset($teams[$team_name])) {
                $teams[$team_name] = [
                    'name' => $team_name,
                    'color' => $team_colors[$color_index % count($team_colors)],
                    'headers' => $headers,
                    'rows' => [],
                    'totals' => array_fill(0, count($headers), 0),
                    'grandTotal' => 0
                ];
                $color_index++;
            }

            // 构建行数据
            $row_data = [$yw_admin['username']];
            $row_total = 0;

            foreach ($yy_admins as $yy_admin) {
                $count = isset($cross[$yw_admin['username']][$yy_admin['username']]) ? $cross[$yw_admin['username']][$yy_admin['username']] : 0;
                $row_data[] = $count;
                $row_total += $count;
            }
            $row_data[] = $row_total; // 小计

            $teams[$team_name]['rows'][] = $row_data;
            $teams[$team_name]['grandTotal'] += $row_total;

            // 更新团队列总计
            foreach ($row_data as $col_index => $value) {
                if ($col_index > 0) { // 跳过姓名列
                    $teams[$team_name]['totals'][$col_index] += $value;
                }
            }
        }

        // 计算总统计
        $grand_totals = array_fill(0, count($headers), 0);
        $grand_grand_total = 0;

        foreach ($teams as $team) {
            foreach ($team['totals'] as $index => $total) {
                $grand_totals[$index] += $total;
            }
            $grand_grand_total += $team['grandTotal'];
        }

        // 构建最终数据结构
        $result = [
            'teams' => array_values($teams),
            'grandTotals' => [
                'headers' => $headers,
                'totals' => $grand_totals,
                'grandTotal' => $grand_grand_total
            ]
        ];
        return $result;
    }

    private function getCrossData_style1($timebucket)
    {
        $current_admin = Admin::getMyInfo();
        $a_where = [$this->getOrgWhere($current_admin['org']), ['is_open', '=', 1], ['group_id', 'in', [$this->ywgid, $this->ywzgid, $this->yygid]]];
        $l_where = [['status', '=', 1], $this->getClientimeWhere($timebucket)];
        // 时间条件
        if (!empty($timebucket)) {
            $l_where[] = $this->getClientimeWhere($timebucket);
        }
        //时间段内所有客户
        $leads = Db::table('crm_leads')->where($l_where)->fetchCollection()->select();

        //所有业务和运营
        $admins = Db::table('admin')
            ->where($a_where)
            ->where('username', '<>', '')
            ->order('team_name,channel,username')->fetchCollection()
            ->select();

        $cross = [];
        foreach ($leads as $lead) {
            if (!isset($cross[$lead['pr_user']])) {
                $cross[$lead['pr_user']] = [];
            }
            if (!isset($cross[$lead['pr_user']][$lead['oper_user']])) {
                $cross[$lead['pr_user']][$lead['oper_user']] = 1;
            } else {
                $cross[$lead['pr_user']][$lead['oper_user']]++;
            }
        }

        // 数据结构
        $yw_admins = $admins->where('group_id', 'in', [$this->ywgid, $this->ywzgid]);
        $data = [];
        $team_yy_users = [];
        foreach ($yw_admins as $yw_admin) {
            if (!isset($team_yy_users[$yw_admin['team_name']])) {
                $team_yy_users[$yw_admin['team_name']] = [];
            }

            if (isset($cross[$yw_admin['username']])) {
                foreach ($cross[$yw_admin['username']] as $yy_user => $count) {
                    if (!in_array($yy_user, $team_yy_users[$yw_admin['team_name']])) {
                        $team_yy_users[$yw_admin['team_name']][] = $yy_user;
                    }
                }
            }
        }

        // 构建数据结构
        foreach ($yw_admins as $yw_admin) {
            if (!isset($data[$yw_admin['team_name']])) {
                $data[$yw_admin['team_name']] = [
                    'name' => $yw_admin['team_name'],
                    'headers' => ['姓名'], // 第一列空格
                    'rows' => [],
                    'totals' => [],
                    'grandTotal' => 0
                ];
                // 添加该团队有数据的运营人员表头
                foreach ($team_yy_users[$yw_admin['team_name']] as $yy_user) {
                    $data[$yw_admin['team_name']]['headers'][] = $yy_user;
                }
            }

            // 添加业务人员行数据
            $row_data = [$yw_admin['username']]; // 第一列是业务人员名称
            $row_total = 0;

            foreach ($team_yy_users[$yw_admin['team_name']] as $yy_user) {
                $count = isset($cross[$yw_admin['username']][$yy_user]) ? $cross[$yw_admin['username']][$yy_user] : 0;
                $row_data[] = $count;
                $row_total += $count;
            }

            $data[$yw_admin['team_name']]['rows'][] = $row_data;
            $data[$yw_admin['team_name']]['grandTotal'] += $row_total;
        }

        // 计算每个团队的列总计
        foreach ($data as $team_name => &$team) {
            $col_totals = [0]; // 第一列总计为0
            for ($i = 1; $i < count($team['headers']); $i++) {
                $col_total = 0;
                foreach ($team['rows'] as $row) {
                    $col_total += $row[$i];
                }
                $col_totals[] = $col_total;
            }
            $team['totals'] = [$col_totals];
        }

        return array_values($data);
    }

    private function getYyCrossData($where, $l_where)
    {
        $data = [
            'headers' => [],
            'rows' => [],
            'totals' => [],
            'grandTotal' => 0
        ];

        // 获取业务人员列表（横向表头）
        $yw_where = array_merge($where, [['group_id', 'in', [$this->ywgid, $this->ywzgid]]]);
        $yw_users = Db::table('admin')
            ->where($yw_where)
            ->where('username', '<>', '')
            ->field('username,team_name')
            ->order('team_name,username')
            ->select();

        // 获取运营人员列表（纵向表头）
        $yy_where = array_merge($where, [['group_id', '=', $this->yygid]]);
        $yy_users = Db::table('admin')
            ->where($yy_where)
            ->where('username', '<>', '')
            ->field('username,team_name')
            ->order('team_name,username')
            ->select();

        // 构建业务表头
        $yw_headers = [];
        foreach ($yw_users as $yw_user) {
            $yw_headers[] = $yw_user['username'];
        }
        $data['headers'] = $yw_headers;

        // 构建运营数据行
        foreach ($yy_users as $yy_user) {
            $row = [
                'name' => $yy_user['username'],
                'values' => [],
                'total' => 0
            ];

            // 获取该运营人员与各业务人员的交叉数据
            foreach ($yw_users as $yw_user) {
                $count = Db::table('crm_leads')
                    ->where('pr_user', $yw_user['username'])
                    ->where('oper_user', $yy_user['username'])
                    ->where($l_where)
                    ->count();

                $row['values'][] = $count;
                $row['total'] += $count;
            }

            $data['rows'][] = $row;
        }

        // 计算列总计
        $col_totals = array_fill(0, count($yw_headers), 0);
        $grand_total = 0;

        foreach ($data['rows'] as $row) {
            foreach ($row['values'] as $index => $value) {
                $col_totals[$index] += $value;
                $grand_total += $value;
            }
        }

        $data['totals'] = $col_totals;
        $data['grandTotal'] = $grand_total;

        return $data;
    }
}
