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

        //查询所有公司
        $user = \app\admin\model\Admin::getMyInfo();
        $orgList = self::ORG;
        if ($user['org']) {
            $orgList = $this->getOrg($user['org']);
        }
        $this->assign('orgList', $orgList);

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
            // 获取订单主信息字段
            $data = [];
            $data['contact']        = Request::param('contact');        // 客户联系方式
            $data['cname']          = Request::param('cname');          // 客户名称
            $data['country']        = Request::param('country');        // 发货地址
            $data['customer_type']  = Request::param('customer_type');  // 客户性质
            $data['source']         = Request::param('source');         // 询盘来源
            $data['pr_user']        = Request::param('pr_user') ?: Session::get('username'); // 业务员（如果前端未填写则默认当前用户）
            $data['oper_user']      = Request::param('oper_user');      // 运营人员
            $data['team_name']      = Request::param('team_name');      // 团队名称
            $data['at_user']        = Session::get('username');         // 创建人（当前登录用户）
            $data['order_time']     = Request::param('order_time');     // 成交时间
            $data['shipping_cost']  = Request::param('shipping_cost');  // 估算运费
            $data['invoice_amount'] = Request::param('invoice_amount'); // 开票金额
            $data['tax_amount']     = Request::param('tax_amount');     // 税费金额
            $data['debugging_cost'] = Request::param('debugging_cost'); // 调试费
            $data['sales_commission'] = Request::param('sales_commission'); // 佣金
            $data['split_remarks']  = Request::param('split_remarks');  // 分成备注
            $data['amount_received'] = Request::param('amount_received'); // 已收款金额
            $data['remark']         = Request::param('remark');         // 订单整体备注
            // 初始化订单状态、创建时间和订单编号
            $data['status']      = '待审核';
            $data['create_time'] = date("Y-m-d H:i:s");
            $data['order_no']    = date("YmdHis") . rand(1000, 9999);   // 生成唯一订单号

            // 获取产品明细字段数组
            $productNames   = Request::param('product_name/a');
            $specModels     = Request::param('spec_model/a');
            $units          = Request::param('unit/a');
            $qtys           = Request::param('qty/a');
            $unitPrices     = Request::param('unit_price/a');
            $totalPrices    = Request::param('total_price/a');
            $purchasePrices = Request::param('purchase_price/a');
            $subProfits     = Request::param('sub_profit/a');
            $itemRemarks    = Request::param('item_remark/a');

            // 计算订单总金额和总利润（服务器端再次计算以保证准确）
            $sumTotal = 0;
            $sumProfit = 0;
            $itemsData = [];  // 准备插入明细表的数组
            if (!empty($productNames) && is_array($productNames)) {
                foreach ($productNames as $index => $pname) {
                    if (empty($pname)) continue;  // 跳过空产品行（如有）
                    // 当前行各字段值
                    $qty    = isset($qtys[$index]) ? floatval($qtys[$index]) : 0;
                    $price  = isset($unitPrices[$index]) ? floatval($unitPrices[$index]) : 0;
                    $purchase = isset($purchasePrices[$index]) ? floatval($purchasePrices[$index]) : 0;
                    $lineTotal  = round($qty * $price, 2);
                    $lineProfit = round($lineTotal - $purchase, 2);
                    // 汇总总金额和利润
                    $sumTotal  += $lineTotal;
                    $sumProfit += $lineProfit;
                    // 准备当前产品行的数据
                    $itemsData[] = [
                        'order_id'      => 0,  // 占位，稍后填入实际订单ID
                        'product_name'  => $pname,
                        'spec_model'    => $specModels[$index] ?? '',
                        'unit'          => $units[$index] ?? '',
                        'qty'           => $qty,
                        'unit_price'    => $price,
                        'total_price'   => $lineTotal,
                        'purchase_price' => $purchase,
                        'sub_profit'    => $lineProfit,
                        'remark'        => $itemRemarks[$index] ?? ''
                    ];
                }
            }
            // 将汇总金额、利润等填入主订单数据
            $data['money']       = round($sumTotal, 2);
            $data['profit']      = round($sumProfit, 2);
            $data['margin_rate'] = ($sumTotal > 0) ? round($sumProfit / $sumTotal * 100, 2) : 0;
            // 如果原主表有字段存储产品名称（如存第一个产品名称），也可赋值：
            if (!empty($productNames)) {
                $data['product_name'] = $productNames[0] . (count($productNames) > 1 ? ' 等' : '');
            }

            // 开始事务，保存主表和明细表
            Db::startTrans();
            try {
                // 插入主订单数据
                $orderId = Db::name('crm_client_order')->insertGetId($data);
                if (!$orderId) {
                    throw new \Exception('主订单插入失败');
                }
                // 插入明细数据
                if (!empty($itemsData)) {
                    // 将生成的订单ID回填到每个明细
                    foreach ($itemsData as &$item) {
                        $item['order_id'] = $orderId;
                    }
                    unset($item);
                    // 批量插入明细表
                    $res = Db::name('crm_order_item')->insertAll($itemsData);
                    if ($res === false || $res != count($itemsData)) {
                        throw new \Exception('订单明细插入失败');
                    }
                }
                // 提交事务
                Db::commit();
                return json(['code' => 0, 'msg' => '添加成功！', 'data' => []]);
            } catch (\Exception $e) {
                // 出现错误，回滚事务
                Db::rollback();
                return json(['code' => -200, 'msg' => '添加失败！', 'data' => []]);
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
        $yyData = $this->getYyList();
        $operUserList = $yyData['_yyList'];
        $this->assign('operUserList', $operUserList);
        $this->assign('yyList', json_encode($yyData['yyList'], JSON_UNESCAPED_UNICODE));
        //新增商品
        $productList = $this->getProductList();
        $this->assign('productList', $productList);
        $teamName = session('team_name') ?: '';
        $adminList = Db::name('admin')
            ->where('group_id', '<>', 1)
            ->where(function ($query) use ($teamName) {
                if ($teamName) $query->where('team_name', $teamName);
            })
            ->field('admin_id, username')
            ->select();
        $collaboratorData = [];
        foreach ($adminList as $admin) {
            $collaboratorData[] = ['name' => $admin['username'], 'value' => $admin['admin_id']];
        }
        $this->assign('collaboratorList', json_encode($collaboratorData, JSON_UNESCAPED_UNICODE));
        // var_dump($sourceList);
        // var_dump($teamList);
        // var_dump(self::CUSTOMER_TYPE);
        //var_dump($yyList['yyList']);
        //var_dump($yyList['_yyList']);
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
                    $res['oper_user'] = $custinfo['oper_user'];
                    $res['msg'] = "客户名称:" . $custinfo['kh_name'] . "询盘来源：" . $custinfo['kh_status'] . ",所属业务员:" . $custinfo['pr_user'] . ",所属运营:" . $custinfo['oper_user'];
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
                //新增商品
                // $product_name = Request::param('product_name');
                // $product = $this->checkProduct($product_name);
                // if(!$product){
                //     $this->addProduct($product_name);
                // }

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
        $yyList = $this->getYyList();
        $this->assign('yyList', json_encode($yyList['yyList']));
        $this->assign('_yyList', json_encode($yyList['_yyList']));
        //新增商品
        $productList = $this->getProductList();
        $this->assign('productList', $productList);

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
        $user = \app\admin\model\Admin::getMyInfo();
        $team_name = $user['team_name'] ?? '';
        if ($team_name) $where[] = ['team_name', '=', $team_name];
        $page = input('page') ?? 1;
        $limit = input('limit') ?? config('pageSize');
        $keyword = Request::param('keyword');
        // 过滤掉 null 元素
        if ($keyword) $keyword = array_filter($keyword);
        if (isset($keyword['timebucket']) || isset($keyword['at_time'])) $keyword['timebucket'] = isset($keyword['timebucket']) ? $keyword['timebucket'] : $keyword['at_time'];
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
            $client_where[] = ['product_name', 'like', "%{$keyword['product_name']}%"];
        }
        if (!$team_name && isset($keyword['team_name'])) {
            $where[] = ['team_name', '=', $keyword['team_name']];
            $team_name = $keyword['team_name'];
        }
        $org_where = [];
        if ($user['org']) {
            $org_where[] =  $this->getOrgWhere($user['org']);
        }
        if (!empty($keyword['org'])) {
            $org_where[] =  $this->getOrgWhere($keyword['org']);
        }
        if ($team_name) {
            $usernames = Db::table('admin')->where('team_name', $team_name)->where($org_where)->column('username');
        } else {
            if (!empty($org_where)) {
                $usernames = Db::table('admin')->where($org_where)->column('username');
            }
        }
        if (isset($usernames)) {
            if (!$usernames) {
                $client_where[] = ['pr_user', '=', time()];
                $where[] = ['pr_user', '=', time()];
            } else {
                $client_where[] = ['pr_user', 'in', $usernames];
                $client_where[] = ['oper_user', 'in', $usernames];

                $where[] = ['pr_user', 'in', $usernames];
            }
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
            ->order('order_time desc')
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
            'totalProfitRate' => $totalMoney > 0 ? number_format($totalProfit / $totalMoney * 100, 2) : 0,
            'totalCount' => $successOrders,
        ];
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
