<?php

namespace app\admin\controller;

use think\Db;
use think\facade\Request;
use think\facade\Session;
use think\Container;

class Order extends Common
{

    const CUSTOMER_TYPE = [
        '终端用户',
        '经销商/批发商',
        '采购商',
        '零售商',
        '采购代理商',
    ];

    //订单列表
    public function index()
    {
        if (request()->isPost()) {
            //获取函数的所有方法
            $params = Request::param();
            if (!isset($params['keyword'])) {
                $params['keyword'] = [];
            }
            $params['keyword']['timebucket'] = 'month';
            Request::merge($params);
            return $this->clientSearch();
            // $key = input('post.key');
            // $page = input('page') ? input('page') : 1;
            // $pageSize = input('limit') ? input('limit') : config('pageSize');
            // $list = db('crm_client_order')
            //     ->order('create_time desc')
            //     ->paginate(array('list_rows' => $pageSize, 'page' => $page))
            //     ->toArray();
            // return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }

        // $total_money = db('crm_client_order')->sum('money');
        // $total_profit = db('crm_client_order')->sum('profit');
        // $this->assign('total_money', number_format($total_money, 2));
        // $this->assign('total_profit', number_format($total_profit, 2));


        //查询所有管理员（去除admin）
        $adminResult = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $this->assign('adminResult', $adminResult);

        //查询所有团队
        $teamList = $this->getTeamList();
        $this->assign('teamList', $teamList);

        //查询所有客户来源
        $sourceList = Db::name('crm_client_status')->distinct(true)->column('status_name');
        $this->assign('sourceList', $sourceList);
        $this->assign('customer_type', self::CUSTOMER_TYPE);
        return $this->fetch();
    }

    //（我的订单）列表
    public function personindex()
    {

        if (request()->isPost()) {
            $params = Request::param();
            if (!isset($params['keyword'])) {
                $params['keyword'] = [];
            }
            $params['keyword']['timebucket'] = 'month';
            Request::merge($params);
            return $this->personClientSearch();
            // $key = input('post.key');
            // $page = input('page') ? input('page') : 1;
            // $pageSize = input('limit') ? input('limit') : config('pageSize');
            // $list = db('crm_client_order')
            //     ->where(['pr_user' => Session::get('username')])
            //     ->order('create_time desc')
            //     ->paginate(array('list_rows' => $pageSize, 'page' => $page))
            //     ->toArray();
            // return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }
        $this->assign('customer_type', self::CUSTOMER_TYPE);
        $this->assign('sourceList', Db::name('crm_client_status')->distinct(true)->column('status_name'));
        return $this->fetch();
    }



    //新建订单
    public function add()
    {
        if (request()->isPost()) {
            // $data['cphone'] = Request::param('cphone');
            $data['cname'] = Request::param('cname');
            $data['at_user'] = Session::get('username');
            if (Request::param('pr_user')) {
                $data['pr_user'] = Request::param('pr_user');
            } else {
                $data['pr_user'] = Session::get('username');
            }
            $data['money'] = Request::param('money');
            // $data['ticheng'] = Request::param('ticheng');
            $data['remark'] = Request::param('remark');
            $data['create_time'] = date("Y-m-d H:i:s", time());
            $data['status'] = '待审核';

            $data['order_no'] = date("YmdHis", time()) . rand(1000, 9999);
            $data['order_time'] = Request::param('order_time');
            $data['profit'] = Request::param('profit');
            $data['margin_rate'] = Request::param('margin_rate');
            $data['country'] = Request::param('country');
            $data['contact'] = Request::param('contact');
            $data['customer_type'] = Request::param('customer_type');
            $data['product_name'] = Request::param('product_name');
            $data['source'] = Request::param('source');
            $data['team_name'] = Request::param('team_name');
            // $userExist = db('crm_leads')->where('phone', $data['phone'])->find();
            // if ($userExist){
            //     $msg = ['code' => -200,'msg'=>'抱歉，重复号码不可添加！','data'=>[]];
            //     return json($msg);
            // }

            $result = Db::table('crm_client_order')->insert($data);
            if ($result) {
                $msg = ['code' => 0, 'msg' => '添加成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => -200, 'msg' => '添加失败！', 'data' => []];
                return json($msg);
            }
        }

        //查询所有团队
        $teamList = $this->getTeamList();
        $this->assign('teamList', $teamList);
        //查询所有客户来源
        $sourceList = Db::name('crm_client_status')->distinct(true)->column('status_name');
        $this->assign('sourceList', $sourceList);
        //客户性质
        $this->assign('customer_type', self::CUSTOMER_TYPE);

        $userlist = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $this->assign('userlist', $userlist);
        // $userlist = Db::name('admin')->select();
        // var_dump($userlist);
        $this->assign('username', Session::get('username'));
        $this->assign('team_name', Session::get('team_name'));
        return $this->fetch('order/add');
    }
    public function changeyewu()
    {
        $data  = Request::param();
        $custphone = $data['contact'];
        // $where=[];
        // $where['phone'] = $custphone;
        // $custinfo = Db::name('crm_leads')->where($where)->find();
        $coninfo = Db::name('crm_contacts')->where('is_delete', 0)->where(function ($query) use ($custphone) {
            $_custphone = trim(preg_replace('/[+\-\s]/', '', $custphone));
            $query->whereRaw("CONCAT(contact_extra, contact_value) = '{$custphone}'")
                ->whereOr('contact_value', $custphone);
            if ($custphone != $_custphone) {
                $query->whereOr('contact_value', $_custphone)
                    ->whereOrRaw("CONCAT(contact_extra, contact_value) = '{$_custphone}'");
            }
        })->find();
        if (!$coninfo) {
            $res['code'] = 0;
            $res['msg'] = "该客户信息没用找到";
        } else {
            $custinfo =  Db::name('crm_leads')->where('id', $coninfo['leads_id'])->find();
            if ($custinfo) {
                // if ($custinfo['pr_user'] != Session::get('username')) {
                //     $res['code'] = 0;
                //     $res['msg'] = "该客户在" . $custinfo['pr_user'] . "业务员下";
                //     return $this->success($res);
                // }
                if ($custinfo['issuccess'] == 1) {
                    $res['code'] = 0;
                    $res['msg'] = "该客户已经成交了，请检查客户手机信息";
                } else {
                    $res['code'] = 1;
                    $res['custname'] = $custinfo['kh_name'];
                    $res['kh_username'] = $custinfo['kh_username'];
                    $res['source'] = $custinfo['kh_status'];
                    $res['pr_user'] = $custinfo['pr_user'];
                    $res['country'] = $custinfo['xs_area'];
                    // $res['pr_user'] = $custinfo['pr_user'];
                    $res['msg'] = "客户名称:" . $custinfo['kh_name'] . "询盘来源：" . $custinfo['kh_status'] . ",所属业务员:" . $custinfo['pr_user'];
                }
            } else {
                $res['code'] = 0;
                $res['msg'] = "该客户信息没用找到";
            }
        }


        $this->success($res);
    }
    //编辑客户
    public function edit()
    {
        if (Request::isAjax()) {
            $data  = Request::param();
            $data['ut_time'] = date("Y-m-d H:i:s", time());

            $result = Db::table('crm_client_order')->where(['id' => $data['id']])->update($data);
            if ($result) {
                $msg = ['code' => 0, 'msg' => '编辑成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => -200, 'msg' => '编辑失败！', 'data' => []];
                return json($msg);
            }
        }


        $result = Db::table('crm_client_order')->where(['id' => Request::param('id')])->find();

        $this->assign('orderInfo', $result);

        $userlist = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $this->assign('userlist', $userlist);
        //查询所有团队
        $teamList = $this->getTeamList();
        $this->assign('teamList', $teamList);
        //查询所有客户来源
        $sourceList = Db::name('crm_client_status')->distinct(true)->column('status_name');
        $this->assign('sourceList', $sourceList);
        $this->assign('customer_type', self::CUSTOMER_TYPE);

        $this->assign('username', Session::get('username'));
        $this->assign('team_name', Session::get('team_name'));
        return $this->fetch('order/edit');
    }
    //删除客户
    public function del()
    {
        $id = Request::param('id');
        // 对应的客户修改状态
        // $orderinfo = Db::table('crm_client_order')->where('id', $id)->find();
        // $custphone = $orderinfo['cphone'];
        // $updatearr = [];
        // $updatearr['issuccess'] = -1;
        // Db::table('crm_leads')->where('phone', $custphone)->update($updatearr);
        $result = Db::table('crm_client_order')->where('id', $id)->delete();
        if ($result) {
            $msg = ['code' => 0, 'msg' => '删除成功！', 'data' => []];
            return json($msg);
        } else {
            $msg = ['code' => -200, 'msg' => '删除失败！', 'data' => []];
            return json($msg);
        }
    }
    public function shenhe()
    {
        $id = Request::param('id');

        $orderinfo = Db::table('crm_client_order')->where('id', $id)->find();
        $custphone = $orderinfo['cphone'];
        $custphone = trim(preg_replace('/[+\-\s]/', '', $custphone));
        $coninfo = Db::name('crm_contacts')->where('is_delete', 0)->where(function ($query) use ($custphone) {
            $query->whereRaw("CONCAT(contact_extra, contact_value) = '{$custphone}'")
                ->whereOr('contact_value', $custphone);
        })->find();
        if (!$coninfo) {
            $msg['code'] = -200;
            $msg['msg'] = "该客户信息没用找到";
            return json($msg);
        }
        $custinfo =  Db::name('crm_leads')->where('id', $coninfo['leads_id'])->find();
        // $custinfo = Db::table('crm_leads')->where('phone', $custphone)->find();
        if ($custinfo['issuccess'] == 1) {
            $msg = ['code' => -200, 'msg' => '该客户已成交,业绩请勿重复添加', 'data' => []];
            return json($msg);
        }
        $updatearr = [];
        $updatearr['issuccess'] = 1;

        Db::table('crm_leads')->where('id', $custinfo['id'])->update($updatearr);
        $result = Db::table('crm_client_order')->where('id', $id)->update(['status' => '审核通过']);

        if ($result) {
            $msg = ['code' => 0, 'msg' => '审核成功', 'data' => []];
            return json($msg);
        } else {
            $msg = ['code' => -200, 'msg' => '审核已成功', 'data' => []];
            return json($msg);
        }
    }

    //客户搜索
    public function clientSearch()
    {
        $where = [];
        $client_where = [];
        //判断权限
        $team_name = session('team_name') ?? '';
        if ($team_name) $where[] = ['team_name', '=', $team_name];

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
        if ($team_name) {
            $usernames = Db::table('admin')->where('team_name', $team_name)->column('username');
            $client_where[] = ['pr_user', 'in', $usernames];
        } else if (isset($keyword['team_name'])) {
            $where[] = ['team_name', '=', $keyword['team_name']];
            $usernames = Db::table('admin')->where('team_name', $keyword['team_name'])->column('username');
            $client_where[] = ['pr_user', 'in', $usernames];
        }
        if (isset($keyword['source'])) {
            $where[] = ['source', '=', $keyword['source']];
            //兼容历史数据
            $kh_source = strtolower($keyword['source']);
            $client_where[] = ['kh_status', 'like', "%$kh_source%"];
        }
        if (isset($keyword['pr_user'])) {
            $where[] = ['pr_user', '=', $keyword['pr_user']];
            $client_where[] = ['pr_user', '=', $keyword['pr_user']];
        }

        $list = Db::table('crm_client_order')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ])
            ->toArray();


        //成单率

        //询盘数 
        //每个月的询盘数=当月录入询盘数-当月录入的询盘丢入公海数（仅当月）+当月从公海拾取数
        $totalInquiries = Db::table('crm_leads')->where('status', 1)->where($client_where)->count();

        $successOrders = $list['total'];
        $successRate = $totalInquiries > 0 ? ($successOrders / $totalInquiries * 100) : 0;
        $totalMoney = $this->getSum($where, 'money');
        $totalProfit = $this->getSum($where, 'profit');
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
    private function buildTimeWhere($timebucket, $field = 'create_time')
    {
        if (!$timebucket) {
            return [];
        }

        $timeRanges = [
            'today' => ['today', 'today'],
            'yesterday' => ['yesterday', 'yesterday'],
            'week' => ['monday this week', 'sunday this week'],
            'last week' => ['monday last week', 'sunday last week'],
            'month' => ['first day of this month', 'last day of this month'],
            'last month' => ['first day of last month', 'last day of last month'],
            'year' => ['first day of january this year', 'last day of december this year'],
            'last year' => ['first day of january last year', 'last day of december last year'],
            '-2 hours' => [date('Y-m-d H:i:s', strtotime('-2 hours')), null]
        ];

        if (isset($timeRanges[$timebucket])) {
            list($start, $end) = $timeRanges[$timebucket];
            if ($timebucket === '-2 hours') {
                return [[$field, '>=', $start]];
            }
            return [$field, 'between time', [date('Y-m-d 00:00:00', strtotime($start)), date('Y-m-d 23:59:59', strtotime($end))]];
        }
        // 自定义日期
        return [$field, 'between time', [date('Y-m-d 00:00:00', strtotime($timebucket)), date('Y-m-d 23:59:59', strtotime($timebucket . '+1 day'))]];
    }

    /**
     * 获取指定字段的总和
     * @param array $where 查询条件
     * @param string $field 要统计的字段
     * @return float 字段总和
     */
    private function getSum($where, $field)
    {
        return Db::table('crm_client_order')
            ->where($where)
            ->sum($field);
    }


    //（我的客户）搜索
    public function personClientSearchOld()
    {
        $page = input('page') ? input('page') : 1;
        $limit = input('limit') ? input('limit') : config('pageSize');
        $keyword = Request::param('keyword');

        $mapAtTime = []; //添加时间
        $mapKhName = []; //客户名称
        $mapXsSource = []; //线索/客户来源
        $mapPrUser = []; //业务员/负责人
        if ($keyword['create_time'] != '') {
            $at = $keyword['create_time']; //日期
            $end_at = date('Y-m-d', strtotime("$at+1day"));
            $mapAtTime = [['create_time', 'between time', [strtotime($at), strtotime($end_at)]]];
        }
        if ($keyword['cname'] != '') {
            $mapKhName = [['cname', 'like', '%' . $keyword['cname'] . '%']];
        }

        if ($keyword['status'] != '') {

            $mapXsSource =  ['status' => $keyword['status']];
        }

        // if ($keyword['uname'] != ''){
        //     $mapPrUser = [['uname','like','%'.$keyword['uname'].'%']];
        // }
        $mapPrUser['pr_user'] =  Session::get('username');
        $list  = Db::table('crm_client_order')
            ->where($mapKhName)
            ->where($mapXsSource)
            ->where($mapPrUser)
            ->where($mapAtTime)
            ->whereTime('create_time', $keyword['timebucket'] ? $keyword['timebucket'] : null)
            ->order('create_time desc')
            ->paginate(array('list_rows' => $limit, 'page' => $page))
            ->toArray();
        //var_dump($list);
        return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
    }

    public function personClientSearch()
    {
        $where = [];
        $client_where = [];
        $pr_user = Session::get('username') ?? '';
        //展示自己创建的订单
        $where[] = ['at_user', '=', $pr_user];
        // $where[] = ['pr_user', '=', $pr_user];
        $client_where[] = ['pr_user', '=', $pr_user];
        //判断权限
        // $team_name = session('team_name') ?? '';
        // if ($team_name) {
        //     $where[] = ['team_name', '=', $team_name];
        //     $usernames = Db::table('admin')->where('team_name', $team_name)->column('username');
        //     $client_where[] = ['pr_user', 'in', $usernames];
        // }
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
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ])
            ->toArray();


        //成单率

        $totalInquiries = Db::table('crm_leads')->where('status', 1)->where($client_where)->count();

        $successOrders = $list['total'];
        $successRate = $totalInquiries > 0 ? ($successOrders / $totalInquiries * 100) : 0;
        $totalMoney = $this->getSum($where, 'money');
        $totalProfit = $this->getSum($where, 'profit');
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
}
