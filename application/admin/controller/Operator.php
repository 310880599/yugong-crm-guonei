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

        $khRankList = Db::table('crm_client_rank')->select();
        $this->assign('khRankList', $khRankList);
        $productList = $this->getProductList();
        $this->assign('productList', $productList);

        return $this->fetch();
    }

    public function perSearch()
    {
        $params  = Request::param();
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

        $list = $this->_search($params, $model, function ($query, $p) {
            $keyword = $p['keyword'] ?? [];
            $query->append(['contact'])->hidden(['contacts']);

            if (!empty($keyword['kh_rank'])) {
                $query->where('kh_rank', '=', $keyword['kh_rank']);
            }
            if (!empty($keyword['status'])) {
                $query->where('status', '=', $keyword['status']);
            }
            if (!empty($keyword['kh_name'])) {
                $query->where('kh_name', 'like', '%' . $keyword['kh_name'] . '%');
            }
            if (!empty($keyword['product_name'])) {
                $query->where('product_name', 'like', '%' . $keyword['product_name'] . '%');
            }
            if (!empty($keyword['timebucket'])) {
                $where[] = $this->getClientimeWhere($keyword['timebucket']);
                $query->where($where);
            }
            if (!empty($keyword['at_time'])) {
                $where[] = $this->getClientimeWhere($keyword['at_time']);
                $query->where($where);
            }
            // 限制当前用户
            $query->where(['oper_user' => Session::get('username')]);
            return $query;
        });

        return [
            'code'  => 0,
            'msg'   => '获取成功!',
            'data'  => $list['data'],
            'count' => $list['total'],
            'rel'   => 1
        ];
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
        $subQuery = Db::table('crm_leads')
            ->where($where)
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
        $l_where = [['status', '=', 1],$this->getClientimeWhere($timebucket)];
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
