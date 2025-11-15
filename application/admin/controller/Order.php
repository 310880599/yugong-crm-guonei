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


    //导出订单
    public function exportindex()
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



    // 新建订单第3版
    public function add()
    {
        if (request()->isPost()) {
            // ====== 验证客户是否属于当前用户或协同人 ======
            $contact = Request::param('contact');
            if (!empty($contact)) {
                $currentUsername = Session::get('username');
                $currentAdminId = Session::get('aid');
                
                // 查找客户信息
                $coninfo = Db::name('crm_contacts')->where('is_delete', 0)->where(function ($query) use ($contact) {
                    $_contact = trim(preg_replace('/[+\-\s]/', '', $contact));
                    $query->whereRaw("CONCAT(contact_extra, contact_value) = '{$contact}'")
                        ->whereOr('contact_value', $contact);
                    if ($contact != $_contact) {
                        $query->whereOr('contact_value', $_contact)
                            ->whereOrRaw("CONCAT(contact_extra, contact_value) = '{$_contact}'");
                    }
                })->find();
                
                if ($coninfo) {
                    $custinfo = Db::name('crm_leads')->where('id', $coninfo['leads_id'])->find();
                    if ($custinfo) {
                        $isMyCustomer = ($custinfo['pr_user'] == $currentUsername);
                        
                        // 检查是否是协同人客户
                        $isCollaboratorCustomer = false;
                        if (!empty($custinfo['joint_person'])) {
                            $jp = $custinfo['joint_person'];
                            $jointPersonIds = [];
                            if (preg_match('/^\s*\[.*\]\s*$/', $jp)) {
                                $tmp = json_decode($jp, true);
                                if (is_array($tmp)) {
                                    $jointPersonIds = $tmp;
                                }
                            } else {
                                $jointPersonIds = array_values(array_filter(explode(',', $jp)));
                            }
                            if (in_array($currentAdminId, $jointPersonIds)) {
                                $isCollaboratorCustomer = true;
                            }
                        }
                        
                        // 如果客户既不是我的客户，也不是协同人客户，则不允许添加订单
                        if (!$isMyCustomer && !$isCollaboratorCustomer) {
                            return fail('该客户不属于您的客户或协同人客户，无法添加订单');
                        }
                    }
                }
            }
            
            // ====== 读取主表字段 ======
            $data = [];
            $data['contact']          = Request::param('contact');        // 客户联系方式
            $data['cname']            = Request::param('cname');          // 客户名称
            $data['client_company']            = Request::param('client_company'); // 客户公司
            $data['country']          = Request::param('country');        // 发货地址
            $data['customer_type']    = Request::param('customer_type');  // 客户性质
            $data['source']           = Request::param('source');         // 询盘来源（运营渠道，存储为文字）
            $data['pr_user']          = Request::param('pr_user') ?: Session::get('username');
            $data['oper_user']        = Request::param('oper_user');      // 运营人员
            $data['bank_account']     = Request::param('bank_account');   // 收款账户
            
            // 处理运营端口：将端口ID转换为端口名称（文字）保存
            $sourcePortId = Request::param('source_port', '');
            $data['source_port'] = '';  // 默认为空
            if (!empty($sourcePortId)) {
                // 从 crm_inquiry_port 表获取端口名称
                $portInfo = Db::name('crm_inquiry_port')
                    ->where('id', $sourcePortId)
                    ->field('port_name')
                    ->find();
                if ($portInfo && !empty($portInfo['port_name'])) {
                    $data['source_port'] = $portInfo['port_name'];  // 保存端口名称（文字）
                }
            }
            $data['team_name']        = Request::param('team_name');      // 团队名称
            $data['at_user']          = Session::get('username');         // 创建人
            $data['order_time']       = Request::param('order_time');     // 成交时间
            $data['shipping_cost']    = Request::param('shipping_cost');  // 估算运费
            $data['invoice_amount']   = Request::param('invoice_amount'); // 开票金额
            $data['tax_amount']       = Request::param('tax_amount');     // 税费金额
            $data['debugging_cost']   = Request::param('debugging_cost'); // 调试费
            $data['sales_commission'] = Request::param('sales_commission'); // 佣金
            $data['split_remarks']    = Request::param('split_remarks');  // 分成备注
            $data['amount_received']  = Request::param('amount_received'); // 已收款金额
            $data['remark']           = Request::param('remark');         // 备注
            $managerIds   = Request::param('product_manager/a'); // ★ 产品经理（管理员）ID 数组
            $data['status']           = '待审核';
            $data['create_time']      = date("Y-m-d H:i:s");
            $data['order_no']         = date("YmdHis") . rand(1000, 9999);


            // 3) 解析并写入协同人（joint_person），支持 数组 / JSON / 逗号分隔
            $jpRaw = Request::param('joint_person');
            $jpIds = [];
            if (is_array($jpRaw)) {
                $jpIds = $jpRaw;
            } else if (is_string($jpRaw)) {
                $jpRaw = trim($jpRaw);
                if ($jpRaw !== '') {
                    if ($jpRaw[0] === '[') {
                        $tmp = json_decode($jpRaw, true);
                        if (is_array($tmp)) $jpIds = $tmp;
                    } else {
                        $jpIds = explode(',', $jpRaw);
                    }
                }
            }
            // 仅保留数字、去空去重
            $jpIds = array_values(array_unique(array_filter(array_map(function ($v) {
                return preg_replace('/\D/', '', (string)$v);
            }, $jpIds), function ($v) {
                return $v !== '';
            })));
            $jpStr = implode(',', $jpIds);
            // 若你的 joint_person 仍为 varchar(30)，做长度保护（推荐把字段扩为 varchar(255)）
            if (strlen($jpStr) > 30) {
                $this->redisUnLock();
                return fail('协同人过多，超出存储限制（请减少选择或扩大 joint_person 字段长度）');
            }
            $data['joint_person'] = $jpStr;



            // ====== 明细字段（注意：product_name[] 现在是【产品ID】）======
            $productIds     = Request::param('product_name/a'); // <-- 产品ID数组
            $specModels     = Request::param('spec_model/a');
            $units          = Request::param('unit/a');
            $qtys           = Request::param('qty/a');
            $unitPrices     = Request::param('unit_price/a');
            $totalPrices    = Request::param('total_price/a');
            $purchasePrices = Request::param('purchase_price/a');
            $subProfits     = Request::param('sub_profit/a');
            $itemRemarks    = Request::param('item_remark/a');

            // 汇总要查询的产品ID
            $idArr = [];
            if (!empty($productIds) && is_array($productIds)) {
                foreach ($productIds as $pid) {
                    $pid = (int)$pid;
                    if ($pid > 0) $idArr[] = $pid;
                }
                $idArr = array_values(array_unique($idArr));
            }

            // 1次查询构建 id => 产品名称 的映射
            // 注意：这里不过滤 status，因为历史订单可能引用已删除的产品，需要保留产品名称
            $idNameMap = [];
            if (!empty($idArr)) {
                $rows = Db::name('crm_products')->alias('p')
                    ->leftJoin('crm_product_category c', 'p.category_id = c.id')
                    ->where('p.id', 'in', $idArr)
                    ->field('p.id, p.product_name, c.category_name')
                    ->select();
                foreach ($rows as $r) {
                    $idNameMap[$r['id']] = $r['product_name']; // 也可拼分类：$r['product_name'].' ('.$r['category_name'].')'
                }
            }

            // 计算并组装明细数据
            $sumTotal = 0;
            $sumProfit = 0;
            $itemsData = [];
            if (!empty($productIds) && is_array($productIds)) {
                foreach ($productIds as $index => $pid) {
                    $pid = (int)$pid;
                    if ($pid <= 0) continue;  // 跳过空行
                    // 产品名称文本
                    $pnameText = $idNameMap[$pid] ?? '';
                    // 获取当前行的数量、价格、成本
                    $qty    = isset($qtys[$index]) ? floatval($qtys[$index]) : 0;
                    $price  = isset($unitPrices[$index]) ? floatval($unitPrices[$index]) : 0;
                    $purchase = isset($purchasePrices[$index]) ? floatval($purchasePrices[$index]) : 0;
                    // 计算行合计和利润
                    $lineTotal  = round($qty * $price, 2);
                    $lineProfit = round($lineTotal - $purchase, 2);
                    $sumTotal  += $lineTotal;
                    $sumProfit += $lineProfit;
                    // 获取当前行的产品经理ID（如未选择则默认为0）
                    $managerId = 0;
                    if (!empty($managerIds[$index])) {
                        $managerId = intval($managerIds[$index]);
                    }
                    // 组装该行明细数组，包括 manager_id 字段
                    $itemsData[] = [
                        'order_id'       => 0,                   // 稍后插入主表后会回填
                        'line_no'        => $index + 1,          // 行号
                        'product_id'     => (string)$pid,        // 产品ID
                        'product_name'   => $pnameText,          // 产品名称文本
                        'spec_model'     => $specModels[$index] ?? '',
                        'unit'           => $units[$index] ?? '',
                        'qty'            => (int)$qty,
                        'unit_price'     => number_format($price, 2, '.', ''),    // 保留两位小数的字符串
                        'total_price'    => number_format($lineTotal, 2, '.', ''),
                        'purchase_price' => number_format($purchase, 2, '.', ''),
                        'sub_profit'     => number_format($lineProfit, 2, '.', ''),
                        'remark'         => $itemRemarks[$index] ?? '',
                        'manager_id'     => $managerId           // ★ 新增：产品经理（管理员）ID
                    ];
                }
            }

            // 计算订单总金额（已存在逻辑）
            $data['money'] = round($sumTotal, 2);

            // 将费用字段从字符串转换为浮点数，然后计算最终利润
            $shippingCost    = floatval(Request::param('shipping_cost'));
            $taxAmount       = floatval(Request::param('tax_amount'));
            $debuggingCost   = floatval(Request::param('debugging_cost'));
            $salesCommission = floatval(Request::param('sales_commission'));

            $finalProfit       = $sumProfit - $shippingCost - $taxAmount - $debuggingCost - $salesCommission;
            $data['profit']    = round($finalProfit, 2);
            $data['margin_rate'] = ($sumTotal > 0) ? round($finalProfit / $sumTotal * 100, 2) : 0;


            // 主表 product_name（存第一个产品名称，非ID）
            if (!empty($productIds)) {
                $firstPid   = (int)($productIds[0] ?? 0);
                $firstName  = $idNameMap[$firstPid] ?? '';
                if ($firstName !== '') {
                    $data['product_name'] = $firstName . (count($productIds) > 1 ? ' 等' : '');
                }
            }

            // 开启事务，插入订单主表和明细表
            Db::startTrans();
            try {
                $orderId = Db::name('crm_client_order')->insertGetId($data);
                if (!$orderId) {
                    throw new \Exception('主订单插入失败');
                }
                // 插入明细行
                if (!empty($itemsData)) {
                    // 回填每个明细的 order_id
                    foreach ($itemsData as &$item) {
                        $item['order_id'] = $orderId;
                    }
                    unset($item);
                    $res = Db::name('crm_order_item')->insertAll($itemsData);
                    if ($res === false || $res != count($itemsData)) {
                        throw new \Exception('订单明细插入失败');
                    }
                }
                Db::commit();
                return json(['code' => 0, 'msg' => '添加成功！']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => -200, 'msg' => '添加失败！' . $e->getMessage()]);
            }
        }

        // ====== GET：渲染页面下拉等 ======

        // 团队/来源/客户性质/运营
        $teamList = $this->getTeamList();
        $this->assign('teamList', $teamList);

        // 从 crm_inquiry 表获取询盘来源列表（客户渠道）
        $currentAdmin = \app\admin\model\Admin::getMyInfo();
        $inquiryWhere = [];
        if ($currentAdmin['org'] && strpos($currentAdmin['org'], 'admin') === false) {
            $inquiryWhere[] = $this->getOrgWhere($currentAdmin['org']);
        }
        // 只获取启用状态的询盘来源（status = 0）
        $inquiryQuery = Db::name('crm_inquiry');
        if (!empty($inquiryWhere)) {
            $inquiryQuery->where($inquiryWhere);
        }
        $inquiryList = $inquiryQuery
            ->where('status', '=', 0)
            ->field('id, inquiry_name')
            ->order('inquiry_name', 'asc')
            ->select();
        
        // 获取询盘来源名称列表（用于下拉框）
        $sourceList = array_column($inquiryList, 'inquiry_name');
        $this->assign('sourceList', $sourceList);

        $this->assign('customer_type', self::CUSTOMER_TYPE);

        $userlist = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $accountList = Db::name('crm_receive_account')->field('id, account')->select();
        //var_dump($bankaccount);
        $this->assign('userlist', $userlist);
        $this->assign('accountList', $accountList);
        $this->assign('username', Session::get('username'));
        $this->assign('team_name', Session::get('team_name'));

        $yyData = $this->getYyList();
        $operUserList = $yyData['_yyList'];
        $this->assign('operUserList', $operUserList);
        $this->assign('yyList', json_encode($yyData['yyList'], JSON_UNESCAPED_UNICODE));

        // 从 crm_inquiry_port 表获取端口列表，按询盘来源（inquiry_id）分组
        $shopList = [];
        foreach ($inquiryList as $inquiry) {
            $inquiryName = $inquiry['inquiry_name'];
            $inquiryId = $inquiry['id'];
            
            // 查询该询盘来源对应的所有端口
            $ports = Db::name('crm_inquiry_port')
                ->where('inquiry_id', $inquiryId)
                ->where('status', '=', 0) // 只获取启用状态的端口
                ->field('id, port_name')
                ->order('port_name', 'asc')
                ->select();
            
            $shops = [];
            foreach ($ports as $port) {
                $shops[] = [
                    'id' => $port['id'],
                    'name' => $port['port_name']
                ];
            }
            
            if (!empty($shops)) {
                $shopList[$inquiryName] = $shops;
            }
        }
        $this->assign('shopList', json_encode($shopList, JSON_UNESCAPED_UNICODE));

        // 产品列表（与客户新增页一致，分组、取最小ID、带分类名）
        $currentAdmin = \app\admin\model\Admin::getMyInfo();
        $where = [];
        if ($currentAdmin['org'] && strpos($currentAdmin['org'], 'admin') === false) {
            $where[] = $this->getOrgWhere($currentAdmin['org'], 'p');
        }
        // 只获取启用状态的产品（status = 0）
        $productRows = Db::name('crm_products')->alias('p')
            ->leftJoin('crm_product_category c', 'p.category_id = c.id')
            ->where($where)
            ->where('p.status', '=', 0)
            ->group('p.product_name, c.category_name')
            ->field('MIN(p.id) as id, p.product_name, c.category_name')
            ->order('p.product_name', 'asc')
            ->select();
        $this->assign('productList', $productRows);

        // 协同人 xmSelect
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

        // 查询所有产品经理（admin表中 group_id=14），按用户名升序
        $managerList = Db::name('admin')
            ->where('group_id', 14)
            ->field('admin_id, username')
            ->order('username', 'asc')
            ->select();
        $this->assign('managerList', $managerList);

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
                // 检查客户是否属于当前用户或协同人
                $currentUsername = Session::get('username');
                $currentAdminId = Session::get('aid');
                $isMyCustomer = ($custinfo['pr_user'] == $currentUsername);
                
                // 检查是否是协同人客户
                $isCollaboratorCustomer = false;
                if (!empty($custinfo['joint_person'])) {
                    $jp = $custinfo['joint_person'];
                    $jointPersonIds = [];
                    if (preg_match('/^\s*\[.*\]\s*$/', $jp)) {
                        // JSON 数组格式
                        $tmp = json_decode($jp, true);
                        if (is_array($tmp)) {
                            $jointPersonIds = $tmp;
                        }
                    } else {
                        // 逗号分隔格式
                        $jointPersonIds = array_values(array_filter(explode(',', $jp)));
                    }
                    // 检查当前用户的 admin_id 是否在协同人列表中
                    if (in_array($currentAdminId, $jointPersonIds)) {
                        $isCollaboratorCustomer = true;
                    }
                }
                
                // 如果客户既不是我的客户，也不是协同人客户，则不允许添加订单
                if (!$isMyCustomer && !$isCollaboratorCustomer) {
                    $res['code'] = 0;
                    $res['msg'] = "该客户不属于您的客户或协同人客户，无法添加订单";
                    $this->success($res);
                    return;
                }
                
                // 获取询盘来源名称（kh_status 可能是 ID 或名称）
                $khStatusValue = $custinfo['kh_status'];
                $sourceName = $khStatusValue;
                
                // 尝试从 crm_inquiry 表查找来源名称
                // 先尝试作为 ID 查找
                if (is_numeric($khStatusValue)) {
                    $inquiryInfo = Db::name('crm_inquiry')->where('id', $khStatusValue)->find();
                    if ($inquiryInfo) {
                        $sourceName = $inquiryInfo['inquiry_name'];
                    } else {
                        // 如果 crm_inquiry 表中找不到，尝试从 crm_client_status 表查找（兼容旧数据）
                        $statusInfo = Db::name('crm_client_status')->where('id', $khStatusValue)->find();
                        if ($statusInfo) {
                            $sourceName = $statusInfo['status_name'];
                        }
                    }
                } else {
                    // 如果已经是名称，先尝试从 crm_inquiry 表验证
                    $inquiryInfo = Db::name('crm_inquiry')->where('inquiry_name', $khStatusValue)->find();
                    if ($inquiryInfo) {
                        $sourceName = $inquiryInfo['inquiry_name'];
                    } else {
                        // 如果 crm_inquiry 表中找不到，直接使用原值（可能是 crm_client_status 的名称，兼容旧数据）
                        $sourceName = $khStatusValue;
                    }
                }
                
                $res['code'] = 1;
                $res['custname'] = $custinfo['kh_name'];
                $res['kh_username'] = $custinfo['kh_username'];
                $res['source'] = $sourceName;  // 返回来源名称
                $res['pr_user'] = $custinfo['pr_user'];
                $res['country'] = $custinfo['xs_area'];
                $res['oper_user'] = $custinfo['oper_user'];
                
                // 获取来源端口（如果字段存在）
                $res['source_port'] = '';
                try {
                    $columns = Db::query("SHOW COLUMNS FROM `crm_leads` LIKE 'source_port'");
                    if (!empty($columns) && isset($custinfo['source_port'])) {
                        $res['source_port'] = $custinfo['source_port'];
                    }
                } catch (\Exception $e) {
                    // 忽略错误
                }
                
                // 获取协同人（joint_person）字段，解析为数组格式
                $jointPersonIds = [];
                if (!empty($custinfo['joint_person'])) {
                    $jp = $custinfo['joint_person'];
                    if (preg_match('/^\s*\[.*\]\s*$/', $jp)) {
                        // JSON 数组格式
                        $tmp = json_decode($jp, true);
                        if (is_array($tmp)) {
                            $jointPersonIds = $tmp;
                        }
                    } else {
                        // 逗号分隔格式
                        $jointPersonIds = array_values(array_filter(explode(',', $jp)));
                    }
                }
                $res['joint_person'] = $jointPersonIds;
                
                // 获取团队名称（通过负责人 pr_user 查找）
                $teamName = '';
                if (!empty($custinfo['pr_user'])) {
                    $adminInfo = Db::name('admin')->where('username', $custinfo['pr_user'])->field('team_name')->find();
                    if ($adminInfo && !empty($adminInfo['team_name'])) {
                        $teamName = $adminInfo['team_name'];
                    }
                }
                $res['team_name'] = $teamName;
                
                // 无论客户是否成交，都不返回历史订单产品信息，让用户手动选择产品（创建新订单）
                $isSuccess = ($custinfo['issuccess'] == 1);
                $res['is_success'] = $isSuccess; // 标记客户是否已成交
                
                // 始终返回空的历史产品数组，不自动填充历史产品信息
                $res['history_products'] = [];
                
                // 构建提示信息
                if ($isSuccess) {
                    $res['msg'] = "【该客户已成交，将创建新订单】客户名称:" . $custinfo['kh_name'] . "询盘来源：" . $sourceName . ",所属业务员:" . $custinfo['pr_user'] . ",所属运营:" . $custinfo['oper_user'];
                } else {
                    $res['msg'] = "客户名称:" . $custinfo['kh_name'] . "询盘来源：" . $sourceName . ",所属业务员:" . $custinfo['pr_user'] . ",所属运营:" . $custinfo['oper_user'];
                }
            } else {
                $res['code'] = 0;
                $res['msg'] = "该客户信息没用找到";
            }
        }


        $this->success($res);
    }


    // //编辑客户第3版
    public function edit()
    {
        if (request()->isPost()) {
            // 获取订单ID
            $id = Request::param('id/d');
            if (!$id) {
                return json(['code' => -200, 'msg' => '缺少订单ID参数']);
            }
            // ====== 读取并整理主表字段 ======
            $data = [];
            $data['contact']          = Request::param('contact');        // 客户联系方式
            $data['cname']            = Request::param('cname');          // 客户名称
            $data['client_company']   = Request::param('client_company'); // 客户公司
            $data['country']          = Request::param('country');        // 发货地址
            $data['customer_type']    = Request::param('customer_type');  // 客户性质
            $data['source']           = Request::param('source');         // 询盘来源（运营渠道，存储为文字）
            $data['bank_account']     = Request::param('bank_account');  // 收款账户 ID (as string)
            $data['pr_user']          = Request::param('pr_user') ?: Session::get('username'); // 客户负责人（默认当前用户）
            $data['oper_user']        = Request::param('oper_user');      // 运营人员
            $data['team_name']        = Request::param('team_name');      // 团队名称
            
            // 处理运营端口：将端口ID转换为端口名称（文字）保存
            $sourcePortId = Request::param('source_port', '');
            $data['source_port'] = '';  // 默认为空
            if (!empty($sourcePortId)) {
                // 从 crm_inquiry_port 表获取端口名称
                $portInfo = Db::name('crm_inquiry_port')
                    ->where('id', $sourcePortId)
                    ->field('port_name')
                    ->find();
                if ($portInfo && !empty($portInfo['port_name'])) {
                    $data['source_port'] = $portInfo['port_name'];  // 保存端口名称（文字）
                }
            }
            
            $data['order_time']       = Request::param('order_time');     // 成交时间
            $data['shipping_cost']    = Request::param('shipping_cost');  // 估算运费
            $data['invoice_amount']   = Request::param('invoice_amount'); // 开票金额
            $data['tax_amount']       = Request::param('tax_amount');     // 税费金额
            $data['debugging_cost']   = Request::param('debugging_cost'); // 调试费
            $data['sales_commission'] = Request::param('sales_commission'); // 佣金
            $data['split_remarks']    = Request::param('split_remarks');  // 分成备注
            $data['amount_received']  = Request::param('amount_received'); // 已收款金额
            $data['remark']           = Request::param('remark');         // 备注
            $data['ut_time']          = date("Y-m-d H:i:s");              // 更新操作时间

            // 解析协同人 joint_person 字段（支持数组/JSON/逗号分隔字符串）
            $jpRaw = Request::param('joint_person');
            $jpIds = [];
            if (is_array($jpRaw)) {
                $jpIds = $jpRaw;
            } else if (is_string($jpRaw)) {
                $jpRaw = trim($jpRaw);
                if ($jpRaw !== '') {
                    if ($jpRaw[0] === '[') {
                        // JSON 字符串
                        $tmp = json_decode($jpRaw, true);
                        if (is_array($tmp)) $jpIds = $tmp;
                    } else {
                        // 逗号分隔字符串
                        $jpIds = explode(',', $jpRaw);
                    }
                }
            }
            // 保留数字字符并去重
            $jpIds = array_values(array_unique(array_filter(array_map(function ($v) {
                return preg_replace('/\D/', '', (string)$v);
            }, $jpIds), function ($v) {
                return $v !== '';
            })));
            $jpStr = implode(',', $jpIds);
            // 若协同人超出字段长度限制则报错
            if (strlen($jpStr) > 255) {
                return json(['code' => -200, 'msg' => '协同人选择过多，超出存储限制']);
            }
            $data['joint_person'] = $jpStr;

            // ====== 获取并处理明细表字段（产品明细多行） ======
            $productIds     = Request::param('product_name/a');    // ★ 产品ID数组（对应每行产品）
            $managerIds     = Request::param('product_manager/a'); // ★ 产品经理ID数组（对应每行产品）
            $specModels     = Request::param('spec_model/a');
            $units          = Request::param('unit/a');
            $qtys           = Request::param('qty/a');
            $unitPrices     = Request::param('unit_price/a');
            $totalPrices    = Request::param('total_price/a');
            $purchasePrices = Request::param('purchase_price/a');
            $subProfits     = Request::param('sub_profit/a');
            $itemRemarks    = Request::param('item_remark/a');

            // 查询涉及的产品名称（用于获取产品名称文本及分类名）
            $idArr = [];
            if (!empty($productIds) && is_array($productIds)) {
                foreach ($productIds as $pid) {
                    $pid = (int)$pid;
                    if ($pid > 0) $idArr[] = $pid;
                }
                $idArr = array_values(array_unique($idArr));
            }
            $idNameMap = [];
            if (!empty($idArr)) {
                // 从产品表获取名称和分类，用于展示和计算
                // 注意：这里不过滤 status，因为历史订单可能引用已删除的产品，需要保留产品名称
                $rows = Db::name('crm_products')->alias('p')
                    ->leftJoin('crm_product_category c', 'p.category_id = c.id')
                    ->where('p.id', 'in', $idArr)
                    ->field('p.id, p.product_name, c.category_name')
                    ->select();
                foreach ($rows as $r) {
                    // 拼接名称和分类（如需）：$r['product_name'].' ('.$r['category_name'].')'
                    $idNameMap[$r['id']] = $r['product_name'];
                }
                
                // 如果某些产品ID查询不到（可能已被删除），尝试从订单明细表中获取产品名称
                foreach ($idArr as $pid) {
                    if (!isset($idNameMap[$pid])) {
                        // 尝试从订单明细表中获取该产品ID对应的产品名称（如果有历史记录）
                        $item = Db::name('crm_order_item')
                            ->where('product_id', $pid)
                            ->where('product_name', '<>', '')
                            ->order('id desc')
                            ->field('product_name')
                            ->find();
                        if ($item && !empty($item['product_name'])) {
                            $idNameMap[$pid] = $item['product_name'];
                        }
                    }
                }
            }

            // 计算订单总金额和利润，并构建明细数据数组
            $sumTotal = 0;
            $sumProfit = 0;
            $itemsData = [];
            if (!empty($productIds) && is_array($productIds)) {
                foreach ($productIds as $index => $pid) {
                    $pid = (int)$pid;
                    if ($pid <= 0) continue;  // 跳过无效行（如空行）
                    // 产品名称文本（用于主表摘要显示）
                    $pnameText = $idNameMap[$pid] ?? '';
                    // 当前行的数量、单价、成本
                    $qty      = isset($qtys[$index]) ? floatval($qtys[$index]) : 0;
                    $price    = isset($unitPrices[$index]) ? floatval($unitPrices[$index]) : 0;
                    $purchase = isset($purchasePrices[$index]) ? floatval($purchasePrices[$index]) : 0;
                    // 计算当前行销售合计和子项利润
                    $lineTotal  = round($qty * $price, 2);
                    $lineProfit = round($lineTotal - $purchase, 2);
                    $sumTotal  += $lineTotal;
                    $sumProfit += $lineProfit;
                    // 当前行对应的产品经理ID（默认为0表示未选择）
                    $managerId = 0;
                    if (!empty($managerIds[$index])) {
                        $managerId = intval($managerIds[$index]);
                    }
                    // 汇总构建当前明细行数据
                    $itemsData[] = [
                        'order_id'       => $id,                  // 关联订单ID
                        'line_no'        => $index + 1,           // 行号
                        'product_id'     => (string)$pid,         // 产品ID（字符串存储）
                        'product_name'   => $pnameText,           // 产品名称文本
                        'spec_model'     => $specModels[$index] ?? '',
                        'unit'           => $units[$index] ?? '',
                        'qty'            => (int)$qty,
                        'unit_price'     => number_format($price, 2, '.', ''),
                        'total_price'    => number_format($lineTotal, 2, '.', ''),
                        'purchase_price' => number_format($purchase, 2, '.', ''),
                        'sub_profit'     => number_format($lineProfit, 2, '.', ''),
                        'remark'         => $itemRemarks[$index] ?? '',
                        'manager_id'     => $managerId
                    ];
                }
            }

            // 汇总订单金额、利润、利润率
            $data['money']       = round($sumTotal, 2);
            $shippingCost        = floatval($data['shipping_cost'] ?? 0);
            $taxAmount           = floatval($data['tax_amount'] ?? 0);
            $debuggingCost       = floatval($data['debugging_cost'] ?? 0);
            $salesCommission     = floatval($data['sales_commission'] ?? 0);
            $finalProfit         = $sumProfit - $shippingCost - $taxAmount - $debuggingCost - $salesCommission;
            $data['profit']      = round($finalProfit, 2);
            $data['margin_rate'] = ($sumTotal > 0) ? round($finalProfit / $sumTotal * 100, 2) : 0;

            // 更新主表产品名称摘要（存入第一个产品名称，多个则加“等”字样）
            if (!empty($productIds)) {
                $firstPid   = (int)($productIds[0] ?? 0);
                $firstName  = $idNameMap[$firstPid] ?? '';
                if ($firstName !== '') {
                    $data['product_name'] = $firstName . (count($productIds) > 1 ? ' 等' : '');
                }
            }

            // ====== 写入数据库（使用事务处理） ======
            Db::startTrans();
            try {
                // 更新订单主表数据
                $resMain = Db::name('crm_client_order')->where('id', $id)->update($data);
                if ($resMain === false) {
                    throw new \Exception('主订单更新失败');
                }
                // 清除旧的明细行记录
                Db::name('crm_order_item')->where('order_id', $id)->delete();
                // 批量插入新的明细行数据
                if (!empty($itemsData)) {
                    $resItems = Db::name('crm_order_item')->insertAll($itemsData);
                    if ($resItems === false || $resItems != count($itemsData)) {
                        throw new \Exception('订单明细更新失败');
                    }
                }
                Db::commit();
                return json(['code' => 0, 'msg' => '编辑成功！']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => -200, 'msg' => '编辑失败！' . $e->getMessage()]);
            }
        }

        // ====== GET 请求：加载编辑页面 ======
        $orderId = Request::param('id/d');
        $order = Db::name('crm_client_order')->where('id', $orderId)->find();
        if (!$order) {
            $this->error('订单不存在或已删除');
        }
        // 读取该订单的所有产品明细行
        $items = Db::name('crm_order_item')->where('order_id', $orderId)->select();

        // 准备下拉选项数据（团队列表、来源列表、客户性质列表、运营人员列表等）
        $teamList   = $this->getTeamList();
        $sourceList = Db::name('crm_client_status')->distinct(true)->column('status_name');
        // 使用 array_map 和 trim 去除每个值的前后空格
        $sourceList = array_map('trim', $sourceList);
        //var_dump($sourceList);
        $accountList = Db::name('crm_receive_account')->field('id, account')->select();  // fetch all accounts (id and name)
        $this->assign('accountList', $accountList);
        $this->assign('teamList', $teamList);
        $this->assign('sourceList', $sourceList);
        $this->assign('customer_type', self::CUSTOMER_TYPE);
        // 当前登录用户信息
        $currentAdmin = \app\admin\model\Admin::getMyInfo();
        $this->assign('username', $currentAdmin['username'] ?? Session::get('username'));
        $this->assign('team_name', $currentAdmin['team_name'] ?? Session::get('team_name'));
        // 获取运营人员列表（以及按询盘来源分类的映射，用于联动下拉）
        $yyData = $this->getYyList();
        $operUserList = $yyData['_yyList'];
        $this->assign('operUserList', $operUserList);
        $this->assign('yyList', json_encode($yyData['yyList'], JSON_UNESCAPED_UNICODE));

        // 产品列表（含分类名）。无组织限制时查询所有产品
        $where = [];
        if (!empty($currentAdmin['org']) && strpos($currentAdmin['org'], 'admin') === false) {
            // 有组织限制时构造过滤条件
            $where[] = $this->getOrgWhere($currentAdmin['org'], 'p');
        }
        // 只获取启用状态的产品（status = 0）
        $productQuery = Db::name('crm_products')->alias('p')
            ->leftJoin('crm_product_category c', 'p.category_id = c.id');
        if (!empty($where)) {
            $productQuery->where($where);
        }
        $productRows = $productQuery
            ->where('p.status', '=', 0)
            ->group('p.product_name, c.category_name')
            ->field('MIN(p.id) as id, p.product_name, c.category_name')
            ->order('p.product_name', 'asc')
            ->select();
        
        // 获取订单中已有的产品ID，检查是否有已删除的产品（status=-1）
        // 如果有，需要添加到产品列表中以便显示，但标记为已废弃
        if (isset($items) && !empty($items)) {
            $existingProductIds = [];
            foreach ($items as $item) {
                if (!empty($item['product_id'])) {
                    $existingProductIds[] = (int)$item['product_id'];
                }
            }
            $existingProductIds = array_unique($existingProductIds);
            
            // 检查这些产品是否已被删除（status=-1）
            if (!empty($existingProductIds)) {
                // 先查询所有可能的产品（包括已删除的），不受组织限制（因为历史订单需要显示）
                $allProducts = Db::name('crm_products')->alias('p')
                    ->leftJoin('crm_product_category c', 'p.category_id = c.id')
                    ->where('p.id', 'in', $existingProductIds)
                    ->field('p.id, p.product_name, c.category_name, p.status')
                    ->select();
                
                // 找出已删除的产品（status=-1）
                foreach ($allProducts as $product) {
                    if (isset($product['status']) && $product['status'] == -1) {
                        $product['is_deleted'] = true; // 标记为已删除
                        $productRows[] = $product;
                    }
                }
                
                // 对于在订单中存在但查询不到的产品（可能已被物理删除），从订单明细中获取产品名称
                $foundProductIds = array_column($allProducts, 'id');
                foreach ($items as $item) {
                    if (!empty($item['product_id']) && !in_array($item['product_id'], $foundProductIds)) {
                        // 产品不存在于产品表中，但从订单明细中获取信息
                        if (!empty($item['product_name'])) {
                            $productRows[] = [
                                'id' => $item['product_id'],
                                'product_name' => $item['product_name'],
                                'category_name' => '无',
                                'is_deleted' => true
                            ];
                        }
                    }
                }
            }
        }
        
        $this->assign('productList', $productRows);

        // 协同人列表（xm-select 数据格式）
        $teamName = $currentAdmin['team_name'] ?? Session::get('team_name') ?: '';
        $adminList = Db::name('admin')
            ->where('group_id', '<>', 1)
            ->where(function ($query) use ($teamName) {
                if ($teamName) {
                    $query->where('team_name', $teamName);
                }
            })
            ->field('admin_id, username')
            ->select();
        $collaboratorData = [];
        $currentJpIds = [];
        if (!empty($order['joint_person'])) {
            $currentJpIds = explode(',', $order['joint_person']);
        }
        foreach ($adminList as $admin) {
            $item = ['name' => $admin['username'], 'value' => $admin['admin_id']];
            if (in_array($admin['admin_id'], $currentJpIds)) {
                $item['selected'] = true;  // 默认选中已有协同人
            }
            $collaboratorData[] = $item;
        }
        $this->assign('collaboratorList', json_encode($collaboratorData, JSON_UNESCAPED_UNICODE));

        // 产品经理列表（group_id = 14）
        $managerList = Db::name('admin')
            ->where('group_id', 14)
            ->field('admin_id, username')
            ->order('username', 'asc')
            ->select();
        $this->assign('managerList', $managerList);

        // 获取来源端口列表（按来源名称分组，与订单新增页一致）
        $khStatusList = Db::name('crm_client_status')->select();
        $has_shop_names = false;
        try {
            $columns = Db::query("SHOW COLUMNS FROM `crm_client_status` LIKE 'shop_names'");
            if (!empty($columns)) {
                $has_shop_names = true;
            }
        } catch (\Exception $e) {
            $has_shop_names = false;
        }
        if ($has_shop_names) {
            $khStatusList = Db::name('crm_client_status')->field('id,status_name,shop_names')->select();
        }
        $shopList = [];
        foreach ($khStatusList as $status) {
            $statusName = $status['status_name'];
            $shops = [];
            
            // 检查是否有shop_names字段
            if ($has_shop_names && isset($status['shop_names']) && !empty(trim($status['shop_names']))) {
                $shop_names = array_filter(array_map('trim', explode(',', $status['shop_names'])));
                foreach ($shop_names as $shop_name) {
                    if (!empty($shop_name)) {
                        $shops[] = [
                            'id' => md5($status['id'] . '_' . $shop_name),
                            'name' => $shop_name
                        ];
                    }
                }
            }
            
            // 如果shop_names为空，尝试从crm_operation_shops表获取
            if (empty($shops)) {
                $commonShops = $this->getShopsByChannel('', $statusName);
                foreach ($commonShops as $shop) {
                    $shops[] = [
                        'id' => $shop['id'],
                        'name' => $shop['name']
                    ];
                }
            }
            
            if (!empty($shops)) {
                $shopList[$statusName] = $shops;
            }
        }
        $this->assign('shopList', json_encode($shopList, JSON_UNESCAPED_UNICODE));

        // 根据订单的 contact 字段，从 crm_leads 表获取 source_port 值
        $orderSourcePort = '';
        if (!empty($order['contact'])) {
            try {
                // 通过联系方式查找 crm_contacts 表
                $custphone = trim($order['contact']);
                $coninfo = Db::name('crm_contacts')->where('is_delete', 0)->where(function ($query) use ($custphone) {
                    $_custphone = trim(preg_replace('/[+\-\s]/', '', $custphone));
                    $query->whereRaw("CONCAT(contact_extra, contact_value) = '{$custphone}'")
                        ->whereOr('contact_value', $custphone);
                    if ($custphone != $_custphone) {
                        $query->whereOr('contact_value', $_custphone)
                            ->whereOrRaw("CONCAT(contact_extra, contact_value) = '{$_custphone}'");
                    }
                })->find();
                
                if ($coninfo && !empty($coninfo['leads_id'])) {
                    // 从 crm_leads 表获取 source_port
                    $custinfo = Db::name('crm_leads')->where('id', $coninfo['leads_id'])->find();
                    if ($custinfo) {
                        // 检查 source_port 字段是否存在
                        $columns = Db::query("SHOW COLUMNS FROM `crm_leads` LIKE 'source_port'");
                        if (!empty($columns) && isset($custinfo['source_port']) && !empty($custinfo['source_port'])) {
                            $orderSourcePort = $custinfo['source_port'];
                        }
                    }
                }
            } catch (\Exception $e) {
                // 忽略错误，保持为空
            }
        }
        
        // 如果订单表本身有 source_port 字段，优先使用订单表的（如果订单表字段存在且不为空）
        if (empty($orderSourcePort)) {
            try {
                $columns = Db::query("SHOW COLUMNS FROM `crm_client_order` LIKE 'source_port'");
                if (!empty($columns) && isset($order['source_port']) && !empty($order['source_port'])) {
                    $orderSourcePort = $order['source_port'];
                }
            } catch (\Exception $e) {
                // 忽略错误
            }
        }
        
        // 如果 source_port 是端口名称（文字），需要找到对应的端口ID以便前端下拉框能正确选中
        $orderSourcePortId = '';
        if (!empty($orderSourcePort)) {
            // 尝试通过端口名称查找端口ID
            $portInfo = Db::name('crm_inquiry_port')
                ->where('port_name', $orderSourcePort)
                ->field('id')
                ->find();
            if ($portInfo && !empty($portInfo['id'])) {
                $orderSourcePortId = $portInfo['id'];
            }
        }
        
        // 将 source_port 值（端口名称）和 source_port_id（端口ID，用于前端回显）添加到订单信息中
        $order['source_port'] = $orderSourcePort;  // 端口名称（文字）
        $order['source_port_id'] = $orderSourcePortId;  // 端口ID（用于前端下拉框选中）

        // 将订单主表和明细数据分配给模板
        $this->assign('orderInfo', $order);
        $this->assign('orderItems', $items);
        return $this->fetch('order/edit');
    }


    // 显示订单详情
    public function details()
    {
        if (request()->isPost()) {
            // 获取订单ID
            $id = Request::param('id/d');
            if (!$id) {
                return json(['code' => -200, 'msg' => '缺少订单ID参数']);
            }
            // ====== 读取并整理主表字段 ======
            $data = [];
            $data['contact']          = Request::param('contact');        // 客户联系方式
            $data['cname']            = Request::param('cname');          // 客户名称
            $data['client_company']   = Request::param('client_company'); // 客户公司
            $data['country']          = Request::param('country');        // 发货地址
            $data['customer_type']    = Request::param('customer_type');  // 客户性质
            $data['source']           = Request::param('source');         // 询盘来源
            $data['bank_account']     = Request::param('bank_account');  // 收款账户 ID (as string)
            $data['pr_user']          = Request::param('pr_user') ?: Session::get('username'); // 客户负责人（默认当前用户）
            $data['oper_user']        = Request::param('oper_user');      // 运营人员
            $data['team_name']        = Request::param('team_name');      // 团队名称
            $data['order_time']       = Request::param('order_time');     // 成交时间
            $data['shipping_cost']    = Request::param('shipping_cost');  // 估算运费
            $data['invoice_amount']   = Request::param('invoice_amount'); // 开票金额
            $data['tax_amount']       = Request::param('tax_amount');     // 税费金额
            $data['debugging_cost']   = Request::param('debugging_cost'); // 调试费
            $data['sales_commission'] = Request::param('sales_commission'); // 佣金
            $data['split_remarks']    = Request::param('split_remarks');  // 分成备注
            $data['amount_received']  = Request::param('amount_received'); // 已收款金额
            $data['remark']           = Request::param('remark');         // 备注
            $data['ut_time']          = date("Y-m-d H:i:s");              // 更新操作时间

            // 解析协同人 joint_person 字段（支持数组/JSON/逗号分隔字符串）
            $jpRaw = Request::param('joint_person');
            $jpIds = [];
            if (is_array($jpRaw)) {
                $jpIds = $jpRaw;
            } else if (is_string($jpRaw)) {
                $jpRaw = trim($jpRaw);
                if ($jpRaw !== '') {
                    if ($jpRaw[0] === '[') {
                        // JSON 字符串
                        $tmp = json_decode($jpRaw, true);
                        if (is_array($tmp)) $jpIds = $tmp;
                    } else {
                        // 逗号分隔字符串
                        $jpIds = explode(',', $jpRaw);
                    }
                }
            }
            // 保留数字字符并去重
            $jpIds = array_values(array_unique(array_filter(array_map(function ($v) {
                return preg_replace('/\D/', '', (string)$v);
            }, $jpIds), function ($v) {
                return $v !== '';
            })));
            $jpStr = implode(',', $jpIds);
            // 若协同人超出字段长度限制则报错
            if (strlen($jpStr) > 255) {
                return json(['code' => -200, 'msg' => '协同人选择过多，超出存储限制']);
            }
            $data['joint_person'] = $jpStr;

            // ====== 获取并处理明细表字段（产品明细多行） ======
            $productIds     = Request::param('product_name/a');    // ★ 产品ID数组（对应每行产品）
            $managerIds     = Request::param('product_manager/a'); // ★ 产品经理ID数组（对应每行产品）
            $specModels     = Request::param('spec_model/a');
            $units          = Request::param('unit/a');
            $qtys           = Request::param('qty/a');
            $unitPrices     = Request::param('unit_price/a');
            $totalPrices    = Request::param('total_price/a');
            $purchasePrices = Request::param('purchase_price/a');
            $subProfits     = Request::param('sub_profit/a');
            $itemRemarks    = Request::param('item_remark/a');

            // 查询涉及的产品名称（用于获取产品名称文本及分类名）
            $idArr = [];
            if (!empty($productIds) && is_array($productIds)) {
                foreach ($productIds as $pid) {
                    $pid = (int)$pid;
                    if ($pid > 0) $idArr[] = $pid;
                }
                $idArr = array_values(array_unique($idArr));
            }
            $idNameMap = [];
            if (!empty($idArr)) {
                // 从产品表获取名称和分类，用于展示和计算
                // 注意：这里不过滤 status，因为历史订单可能引用已删除的产品，需要保留产品名称
                $rows = Db::name('crm_products')->alias('p')
                    ->leftJoin('crm_product_category c', 'p.category_id = c.id')
                    ->where('p.id', 'in', $idArr)
                    ->field('p.id, p.product_name, c.category_name')
                    ->select();
                foreach ($rows as $r) {
                    // 拼接名称和分类（如需）：$r['product_name'].' ('.$r['category_name'].')'
                    $idNameMap[$r['id']] = $r['product_name'];
                }
                
                // 如果某些产品ID查询不到（可能已被删除），尝试从订单明细表中获取产品名称
                foreach ($idArr as $pid) {
                    if (!isset($idNameMap[$pid])) {
                        // 尝试从订单明细表中获取该产品ID对应的产品名称（如果有历史记录）
                        $item = Db::name('crm_order_item')
                            ->where('product_id', $pid)
                            ->where('product_name', '<>', '')
                            ->order('id desc')
                            ->field('product_name')
                            ->find();
                        if ($item && !empty($item['product_name'])) {
                            $idNameMap[$pid] = $item['product_name'];
                        }
                    }
                }
            }

            // 计算订单总金额和利润，并构建明细数据数组
            $sumTotal = 0;
            $sumProfit = 0;
            $itemsData = [];
            if (!empty($productIds) && is_array($productIds)) {
                foreach ($productIds as $index => $pid) {
                    $pid = (int)$pid;
                    if ($pid <= 0) continue;  // 跳过无效行（如空行）
                    // 产品名称文本（用于主表摘要显示）
                    $pnameText = $idNameMap[$pid] ?? '';
                    // 当前行的数量、单价、成本
                    $qty      = isset($qtys[$index]) ? floatval($qtys[$index]) : 0;
                    $price    = isset($unitPrices[$index]) ? floatval($unitPrices[$index]) : 0;
                    $purchase = isset($purchasePrices[$index]) ? floatval($purchasePrices[$index]) : 0;
                    // 计算当前行销售合计和子项利润
                    $lineTotal  = round($qty * $price, 2);
                    $lineProfit = round($lineTotal - $purchase, 2);
                    $sumTotal  += $lineTotal;
                    $sumProfit += $lineProfit;
                    // 当前行对应的产品经理ID（默认为0表示未选择）
                    $managerId = 0;
                    if (!empty($managerIds[$index])) {
                        $managerId = intval($managerIds[$index]);
                    }
                    // 汇总构建当前明细行数据
                    $itemsData[] = [
                        'order_id'       => $id,                  // 关联订单ID
                        'line_no'        => $index + 1,           // 行号
                        'product_id'     => (string)$pid,         // 产品ID（字符串存储）
                        'product_name'   => $pnameText,           // 产品名称文本
                        'spec_model'     => $specModels[$index] ?? '',
                        'unit'           => $units[$index] ?? '',
                        'qty'            => (int)$qty,
                        'unit_price'     => number_format($price, 2, '.', ''),
                        'total_price'    => number_format($lineTotal, 2, '.', ''),
                        'purchase_price' => number_format($purchase, 2, '.', ''),
                        'sub_profit'     => number_format($lineProfit, 2, '.', ''),
                        'remark'         => $itemRemarks[$index] ?? '',
                        'manager_id'     => $managerId
                    ];
                }
            }

            // 汇总订单金额、利润、利润率
            $data['money']       = round($sumTotal, 2);
            $shippingCost        = floatval($data['shipping_cost'] ?? 0);
            $taxAmount           = floatval($data['tax_amount'] ?? 0);
            $debuggingCost       = floatval($data['debugging_cost'] ?? 0);
            $salesCommission     = floatval($data['sales_commission'] ?? 0);
            $finalProfit         = $sumProfit - $shippingCost - $taxAmount - $debuggingCost - $salesCommission;
            $data['profit']      = round($finalProfit, 2);
            $data['margin_rate'] = ($sumTotal > 0) ? round($finalProfit / $sumTotal * 100, 2) : 0;

            // 更新主表产品名称摘要（存入第一个产品名称，多个则加“等”字样）
            if (!empty($productIds)) {
                $firstPid   = (int)($productIds[0] ?? 0);
                $firstName  = $idNameMap[$firstPid] ?? '';
                if ($firstName !== '') {
                    $data['product_name'] = $firstName . (count($productIds) > 1 ? ' 等' : '');
                }
            }

            // ====== 写入数据库（使用事务处理） ======
            Db::startTrans();
            try {
                // 更新订单主表数据
                $resMain = Db::name('crm_client_order')->where('id', $id)->update($data);
                if ($resMain === false) {
                    throw new \Exception('主订单更新失败');
                }
                // 清除旧的明细行记录
                Db::name('crm_order_item')->where('order_id', $id)->delete();
                // 批量插入新的明细行数据
                if (!empty($itemsData)) {
                    $resItems = Db::name('crm_order_item')->insertAll($itemsData);
                    if ($resItems === false || $resItems != count($itemsData)) {
                        throw new \Exception('订单明细更新失败');
                    }
                }
                Db::commit();
                return json(['code' => 0, 'msg' => '编辑成功！']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => -200, 'msg' => '编辑失败！' . $e->getMessage()]);
            }
        }

        // ====== GET 请求：加载编辑页面 ======
        $orderId = Request::param('id/d');
        $order = Db::name('crm_client_order')->where('id', $orderId)->find();
        if (!$order) {
            $this->error('订单不存在或已删除');
        }
        // 读取该订单的所有产品明细行
        $items = Db::name('crm_order_item')->where('order_id', $orderId)->select();

        // 准备下拉选项数据（团队列表、来源列表、客户性质列表、运营人员列表等）
        $teamList   = $this->getTeamList();
        $sourceList = Db::name('crm_client_status')->distinct(true)->column('status_name');
        // 使用 array_map 和 trim 去除每个值的前后空格
        $sourceList = array_map('trim', $sourceList);
        //var_dump($sourceList);
        $accountList = Db::name('crm_receive_account')->field('id, account')->select();  // fetch all accounts (id and name)
        $this->assign('accountList', $accountList);
        $this->assign('teamList', $teamList);
        $this->assign('sourceList', $sourceList);
        $this->assign('customer_type', self::CUSTOMER_TYPE);
        // 当前登录用户信息
        $currentAdmin = \app\admin\model\Admin::getMyInfo();
        $this->assign('username', $currentAdmin['username'] ?? Session::get('username'));
        $this->assign('team_name', $currentAdmin['team_name'] ?? Session::get('team_name'));
        // 获取运营人员列表（以及按询盘来源分类的映射，用于联动下拉）
        $yyData = $this->getYyList();
        $operUserList = $yyData['_yyList'];
        $this->assign('operUserList', $operUserList);
        $this->assign('yyList', json_encode($yyData['yyList'], JSON_UNESCAPED_UNICODE));

        // 产品列表（含分类名）。无组织限制时查询所有产品
        // 不添加status过滤，所有产品数据都进行展示
        $where = [];
        if (!empty($currentAdmin['org']) && strpos($currentAdmin['org'], 'admin') === false) {
            // 有组织限制时构造过滤条件
            $where[] = $this->getOrgWhere($currentAdmin['org'], 'p');
        }
        // 查询所有产品（不限制status状态）
        $productQuery = Db::name('crm_products')->alias('p')
            ->leftJoin('crm_product_category c', 'p.category_id = c.id');
        if (!empty($where)) {
            $productQuery->where($where);
        }
        $productRows = $productQuery
            ->group('p.product_name, c.category_name')
            ->field('MIN(p.id) as id, p.product_name, c.category_name, p.status')
            ->order('p.product_name', 'asc')
            ->select();
        
        // 标记已删除的产品（status=-1）
        foreach ($productRows as &$product) {
            if (isset($product['status']) && $product['status'] == -1) {
                $product['is_deleted'] = true; // 标记为已删除
            }
        }
        unset($product); // 释放引用
        
        // 获取订单中已有的产品ID，检查是否有已被物理删除的产品
        // 如果产品不存在于产品表中，从订单明细中获取产品名称
        if (isset($items) && !empty($items)) {
            $existingProductIds = [];
            foreach ($items as $item) {
                if (!empty($item['product_id'])) {
                    $existingProductIds[] = (int)$item['product_id'];
                }
            }
            $existingProductIds = array_unique($existingProductIds);
            
            // 检查订单中的产品是否都在产品列表中
            if (!empty($existingProductIds)) {
                $foundProductIds = array_column($productRows, 'id');
                foreach ($items as $item) {
                    if (!empty($item['product_id']) && !in_array($item['product_id'], $foundProductIds)) {
                        // 产品不存在于产品表中（可能已被物理删除），从订单明细中获取信息
                        if (!empty($item['product_name'])) {
                            $productRows[] = [
                                'id' => $item['product_id'],
                                'product_name' => $item['product_name'],
                                'category_name' => '无',
                                'status' => -1,
                                'is_deleted' => true
                            ];
                        }
                    }
                }
            }
        }
        
        $this->assign('productList', $productRows);

        // 协同人列表（xm-select 数据格式）
        $teamName = $currentAdmin['team_name'] ?? Session::get('team_name') ?: '';
        $adminList = Db::name('admin')
            ->where('group_id', '<>', 1)
            ->where(function ($query) use ($teamName) {
                if ($teamName) {
                    $query->where('team_name', $teamName);
                }
            })
            ->field('admin_id, username')
            ->select();
        $collaboratorData = [];
        $currentJpIds = [];
        if (!empty($order['joint_person'])) {
            $currentJpIds = explode(',', $order['joint_person']);
        }
        foreach ($adminList as $admin) {
            $item = ['name' => $admin['username'], 'value' => $admin['admin_id']];
            if (in_array($admin['admin_id'], $currentJpIds)) {
                $item['selected'] = true;  // 默认选中已有协同人
            }
            $collaboratorData[] = $item;
        }
        $this->assign('collaboratorList', json_encode($collaboratorData, JSON_UNESCAPED_UNICODE));

        // 产品经理列表（group_id = 14）
        $managerList = Db::name('admin')
            ->where('group_id', 14)
            ->field('admin_id, username')
            ->order('username', 'asc')
            ->select();
        $this->assign('managerList', $managerList);

        // 将订单主表和明细数据分配给模板
        $this->assign('orderInfo', $order);
        $this->assign('orderItems', $items);
        return $this->fetch('order/details');
    }


    /**
     * 删除订单：删除指定ID的订单，并级联删除相关子项
     */
    public function del()
    {
        // 开启事务
        Db::startTrans();
        try {
            // 获取请求中的订单ID
            $id = Request::param('id');
            // 查询订单信息，获取客户手机号
            // $order = Db::table('crm_client_order')->where('id', $id)->find();
            // if (!$order) {
            //     // 未找到订单记录，抛出异常以回滚事务
            //     throw new \Exception('订单不存在');
            // }
            // $custPhone = $order['cphone'];
            // 将对应线索表 (crm_leads) 中该客户的状态更新为 -1（未成交）
            //Db::table('crm_leads')->where('phone', $custPhone)->update(['issuccess' => -1]);
            // 删除订单关联的所有明细记录 (crm_order_item 表)
            Db::table('crm_order_item')->where('order_id', $id)->delete();
            // 删除订单主表记录 (crm_client_order 表)
            $result = Db::table('crm_client_order')->where('id', $id)->delete();
            if (!$result) {
                // 如果删除失败，抛出异常回滚事务
                throw new \Exception('删除订单失败');
            }
            // 提交事务
            Db::commit();
            return json(['code' => 0, 'msg' => '删除成功！']);
        } catch (\Exception $e) {
            // 捕获异常，回滚事务
            Db::rollback();
            return json(['code' => -200, 'msg' => '删除失败！']);
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
        
        // 如果订单主表的 product_name 为空，尝试从订单明细表中获取产品名称
        // 这样可以确保即使产品被删除，订单的产品名称仍然可以显示
        foreach ($list['data'] as &$order) {
            if (empty($order['product_name'])) {
                $firstItem = Db::name('crm_order_item')
                    ->where('order_id', $order['id'])
                    ->where('product_name', '<>', '')
                    ->order('line_no asc')
                    ->field('product_name')
                    ->find();
                if ($firstItem && !empty($firstItem['product_name'])) {
                    $order['product_name'] = $firstItem['product_name'];
                }
            }
        }
        unset($order);


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
