<?php

namespace app\admin\controller;

use think\Db;
use think\facade\Request;
use think\facade\Session;
use think\Container;
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
            if (!empty($keyword['kh_name'])) {
                $query->where('kh_name', 'like', '%' . $keyword['kh_name'] . '%');
            }
            if (!empty($keyword['product_name'])) {
                $query->where('product_name', 'like', '%' . $keyword['product_name'] . '%');
            }
            if (!empty($keyword['at_time'])) {
                $query->where('at_time', '>=', $keyword['at_time']);
            }
            if (!empty($keyword['timebucket'])) {
                $where[] = $this->buildTimeWhere($keyword['timebucket'], 'at_time');
                $query->where($where);
            }
            if (!empty($keyword['at_time'])) {
                $where[] = $this->buildTimeWhere($keyword['at_time'], 'at_time');
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

    private function getPanelData($params)
    {
        $data = [
            'yw_data' => [],
            'yy_data' => [],
            'product_data' => [],
        ];
        $keyword  = $params['keyword'] ?? [];
        $current_admin = Admin::getMyInfo();
        $where = [['org', '=', $current_admin['org']]];
        $l_where = [];
        $o_where = [];
        if (!empty($keyword['timebucket'])) {
            $l_where[] = $this->buildTimeWhere($keyword['timebucket'], 'at_time');
            $o_where[] = $this->buildTimeWhere($keyword['timebucket'], 'create_time');
        }
        if (!empty($keyword['at_time'])) {
            $l_where[] = $this->buildTimeWhere($keyword['at_time'], 'at_time');
            $o_where[] = $this->buildTimeWhere($keyword['at_time'], 'create_time');
        }
        //业务询盘数据
        $yw_where = array_merge($where, [['group_id', 'in', [$this->ywgid, $this->ywzgid]]]);
        $ywData = $this->getLeadsSubQuery($l_where)->where($yw_where)->group('a.username,a.team_name')->field('a.username,a.team_name,count(pr_user) as yw_num')->order('yw_num desc')->order('a.team_name')->select();
        $ywData_total = $this->getLeadsSubQuery($l_where)->where('a.team_name', '<>', '')->where($yw_where)->group('a.team_name')->field('a.team_name,count(pr_user) as yw_num')->order('yw_num desc')->order('a.team_name')->select();

        //运营数据
        $yy_where = array_merge($where, [['group_id', '=', $this->yygid]]);
        $yyData = $this->getLeadsSubQuery($l_where, 'oper_user')->where($yy_where)->group('username,team_name,channel')->field('username,team_name,channel,count(oper_user) as yy_num')->order('yy_num', 'desc')->order('team_name')->order('channel')->select();
        $yyData_total = $this->getLeadsSubQuery($l_where, 'oper_user')->where('channel', '<>', '')->where($yy_where)->group('team_name,channel')->field('team_name,channel,count(oper_user) as yy_num')->order('yy_num', 'desc')->order('team_name')->order('channel')->select();

        //产品数据
        $oper_prod = Db::table('crm_leads')->join('admin', 'crm_leads.pr_user = admin.username')->where($where)->where($l_where)->where('product_name', '<>', '')->group('product_name')->field('product_name,count(product_name) as count')->order('count', 'desc')->limit(10)->select();
        $order_prod = Db::table('crm_client_order')->join('admin', 'crm_client_order.oper_user = admin.username')->where($where)->where($o_where)->where('product_name', '<>', '')->group('product_name')->field('product_name,count(product_name) as count')->order('count', 'desc')->limit(10)->select();


        $xp_count = Db::table('crm_leads')->where([$this->buildTimeWhere('month', 'at_time')])->where('oper_user', $current_admin['username'])->count();
        $profit = Db::table('crm_client_order')->where([$this->buildTimeWhere('month', 'create_time')])->where('oper_user', $current_admin['username'])->sum('profit');
        $data['xp_count'] = $xp_count;
        $data['profit'] = $profit;

        $data['yw_data']['list'] = $ywData;
        $data['yw_data']['total'] = $ywData_total;
        $data['yy_data']['list'] = $yyData;
        $data['yy_data']['total'] = $yyData_total;
        $data['product_data']['oper_prod'] = $oper_prod;
        $data['product_data']['order_prod'] = $order_prod;

        $data['org'] = $current_admin['org'];

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
        $where = [['org', '=', $current_admin['org']],];
        $l_where = [['oper_user', '=', $current_admin['username']]];
        if (!empty($keyword['timebucket'])) {
            $l_where[] = $this->buildTimeWhere($keyword['timebucket'], 'at_time');
        }
        if (!empty($keyword['at_time'])) {
            $l_where[] = $this->buildTimeWhere($keyword['at_time'], 'at_time');
        }

        //业务询盘数据
        $yw_where = array_merge($where, [['group_id', 'in', [$this->ywgid, $this->ywzgid]]]);
        $ywData = $this->getLeadsSubQuery($l_where)->where($yw_where)->group('a.username,a.team_name')->field('a.username,a.team_name,count(pr_user) as yw_num')->order('yw_num desc')->order('a.team_name')->select();
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
}
