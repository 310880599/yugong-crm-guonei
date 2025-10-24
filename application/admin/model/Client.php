<?php

namespace app\admin\model;

use app\admin\controller\Client as ControllerClient;
use think\Model;
use think\Db;
use app\admin\model\Contacts;


class Client extends Model
{
    protected $table = 'crm_leads';


    public function contacts()
    {
        return $this->hasMany(Contacts::class, 'leads_id', 'id')->where('is_delete', 0)->field('leads_id,contact_type,contact_extra,contact_value,vdigits');
    }

    public function getContactAttr($value)
    {
        $contactMap = array_flip(ControllerClient::CONTACT_MAP);
        $v_foramt = [];
        foreach ($this->contacts as $v) {
            $contactType = $contactMap[$v['contact_type']] ?? '';
            $contactValue = $contactType . ':' . $v['contact_extra'] . $v['contact_value'];
            $v_foramt[] = $contactValue;
        }
        return $v_foramt;
    }

    //查询
    public function getClientSearchList($page, $limit, $keyword)
    {

        $mapAtTime = []; //添加时间
        $mapKhRank = []; //客户级别
        $mapKhStatus = []; //客户状态
        $mapPhone = []; //手机号模糊查询
        $mapKhName = []; //客户名称
        $mapXsSource = []; //线索/客户来源
        $mapPrUser = []; //业务员/负责人

        if (!empty($keyword['timebucket'])) {
            $mapAtTime[] = $keyword['timebucket'];
        }
        if ($keyword['kh_rank'] != '') {

            $mapKhRank =  ['kh_rank' => $keyword['kh_rank']];
        }

        if ($keyword['kh_status'] != '') {

            $mapKhStatus =  ['kh_status' => $keyword['kh_status']];
        }

        if ($keyword['phone'] != '') {
            $mapPhone = $this->getContactSearch($keyword['phone']);
        }

        if ($keyword['kh_name'] != '') {
            $mapKhName = [['kh_name', 'like', '%' . $keyword['kh_name'] . '%']];
        }

        if ($keyword['xs_source'] != '') {

            $mapXsSource =  ['xs_source' => $keyword['xs_source']];
        }

        if ($keyword['pr_user'] != '') {
            $mapPrUser = [['pr_user', 'like', '%' . $keyword['pr_user'] . '%']];
        }
        $current_admin = Admin::getMyInfo();
        $team_name = $current_admin['team_name'] ?? '';
        $a_where = [];
        if (strpos($current_admin['org'], 'admin') === false) {
            $a_where = [(new ControllerClient())->getorgWhere($current_admin['org'])];
        }
        $usernames  = [$current_admin['username']];
        if ($current_admin['group_id'] == 1) {
            $usernames = [];
            if ($a_where) {
                $usernames = Db::name('admin')->where($a_where)->column('username');
            }
        } else if ($team_name) {
            // 主管查看直属下属及自己的客户
            $usernames = Db::name('admin')->where('team_name', $team_name)->where($a_where)->column('username');
        }

        $result  = Db::table('crm_leads')
            ->where(function ($query) use ($usernames) {
                if ($usernames) {
                    $query->whereIn('pr_user', $usernames);
                }
            })
            ->where($mapPhone)
            ->where($mapKhName)
            ->where($mapKhStatus)
            ->where($mapKhRank)
            ->where($mapXsSource)
            ->where($mapPrUser)
            ->where($mapAtTime)
            ->where(['status' => 1, 'issuccess' => -1])
            ->order('at_time desc')
            ->paginate(array('list_rows' => $limit, 'page' => $page))
            ->toArray();

        //数据集判断方式
        //if($result->isEmpty()){return null;}
        if ($result['total'] == 0) {
            return null;
        } else {
            return $result;
        }
    }


    public function getContactSearch($phone)
    {
        $phone = trim((string)$phone);
        if ($phone === '') {
            return []; // 不加条件
        }

        // 用户可能输入含空格/短横线/国家码，取数字部分做模糊
        $phoneKeyword = preg_replace('/\D+/', '', $phone);

        $rows = Db::table('crm_contacts')
            ->where('is_delete', 0)
            ->where('contact_type', 'in', [1, 3]) // 1主 3辅:contentReference[oaicite:4]{index=4}
            ->where('contact_value', 'like', "%{$phoneKeyword}%")
            ->field('leads_id')
            ->select();

        $leadsIds = array_column($rows, 'leads_id');

        if (empty($leadsIds)) {
            // 返回一个必不成立条件，避免 SQL 报错
            return [['id', '=', -1]];
        }
        return [['id', 'in', $leadsIds]];
    }


    // 在 application/admin/model/Client.php 内新增以下方法
    public function getJointClientSearchList($page, $limit, $keyword)
    {
        $mapAtTime   = [];
        $mapKhRank   = [];
        $mapKhStatus = [];
        $mapPhone    = [];
        $mapKhName   = [];
        $mapXsSource = [];
        $where       = [];

        // 时间范围（控制器已转成 between 等 where 数组）
        if (!empty($keyword['timebucket'])) {
            $mapAtTime[] = $keyword['timebucket'];
        }
        if ($keyword['kh_rank'] !== '' && $keyword['kh_rank'] !== null) {
            $mapKhRank = ['kh_rank' => $keyword['kh_rank']];
        }
        if ($keyword['kh_status'] !== '' && $keyword['kh_status'] !== null) {
            $mapKhStatus = ['kh_status' => $keyword['kh_status']];
        }
        if (!empty($keyword['phone'])) {
            // 复用你已有的按电话查 leads_id 的逻辑（需在本模型中存在 getContactSearch 方法，TP5.1 版）
            $mapPhone = $this->getContactSearch($keyword['phone']);
        }
        if (!empty($keyword['oper_user'])) {
            $where[] = ['oper_user', 'like', '%' . $keyword['oper_user'] . '%'];
        }
        if (!empty($keyword['kh_name'])) {
            $mapKhName = [['kh_name', 'like', '%' . $keyword['kh_name'] . '%']];
        }
        if ($keyword['xs_source'] !== '' && $keyword['xs_source'] !== null) {
            $mapXsSource = ['xs_source' => $keyword['xs_source']];
        }

        // 当前登录用户，只取“我作为协同人”的客户，且不是我负责
        $currentUsername = session('username');
        $currentAdminId  = session('aid');

        $result = Db::table('crm_leads')
            ->where($mapPhone)
            ->where($mapKhName)
            ->where($mapKhStatus)
            ->where($mapKhRank)
            ->where($mapXsSource)
            ->where($mapAtTime)
            ->where($where)
            ->where(['status' => 1, 'issuccess' => -1])                  // 仅有效客户且未成交
            ->where('pr_user', '<>', $currentUsername)                    // 负责人不是我
            ->where(function ($query) use ($currentAdminId) {
                $query->whereRaw("FIND_IN_SET('{$currentAdminId}', joint_person)");
            })
            ->order('at_time desc')
            ->paginate(['list_rows' => $limit, 'page' => $page])
            ->toArray();

        return ($result['total'] == 0) ? null : $result;
    }



    //个人查询
    public function getPersonClientSearchList($page, $limit, $keyword)
    {


        $mapAtTime = []; //添加时间
        $mapKhRank = []; //客户级别
        $mapKhStatus = []; //客户状态
        $mapPhone = []; //手机号模糊查询
        $mapKhName = []; //客户名称
        $mapXsSource = []; //线索/客户来源
        $where = [];


        if (!empty($keyword['timebucket'])) {
            $mapAtTime[] = $keyword['timebucket'];
        }

        if ($keyword['kh_rank'] != '') {

            $mapKhRank =  ['kh_rank' => $keyword['kh_rank']];
        }

        if ($keyword['kh_status'] != '') {

            $mapKhStatus =  ['kh_status' => $keyword['kh_status']];
        }

        if ($keyword['phone'] != '') {
            $mapPhone = $this->getContactSearch($keyword['phone']);
        }

        if (!empty($keyword['oper_user'])) {
            $where[] = ['oper_user', 'like', '%' . $keyword['oper_user'] . '%'];
        }

        if ($keyword['kh_name'] != '') {
            $mapKhName = [['kh_name', 'like', '%' . $keyword['kh_name'] . '%']];
        }

        if ($keyword['xs_source'] != '') {

            $mapXsSource =  ['xs_source' => $keyword['xs_source']];
        }

        $result  = Db::table('crm_leads')
            ->where($mapPhone)
            ->where($mapKhName)
            ->where($mapKhStatus)
            ->where($mapKhRank)
            ->where($mapXsSource)
            ->where($mapAtTime)
            ->where($where)
            ->where(['status' => 1, 'issuccess' => -1]) //0 线索，1客户，2公海
            ->where(['pr_user' => session('username')]) //负责人
            ->order('at_time desc')
            ->paginate(array('list_rows' => $limit, 'page' => $page))
            ->toArray();

        //数据集判断方式
        //if($result->isEmpty()){return null;}
        if ($result['total'] == 0) {
            return null;
        } else {
            return $result;
        }
    }
    //成交客户查询
    public function getChengjiaoClientSearchList($page, $limit, $keyword)
    {


        $mapAtTime = []; //添加时间
        $mapKhRank = []; //客户级别
        $mapKhStatus = []; //客户状态
        $mapPhone = []; //手机号模糊查询
        $mapKhName = []; //客户名称
        $mapXsSource = []; //线索/客户来源
        $mapPrUser = []; //业务员/负责人

        if ($keyword['pr_user'] != '') {
            $mapPrUser['pr_user'] = $keyword['pr_user'];
            //$mapPrUser = [['pr_user','like','%'.$keyword['pr_user'].'%']];
        } else {
            if (session('aid') == 1) {
            } else {
                $mapPrUser['pr_user'] = session('username');
            }
        }
        if ($keyword['at_time'] != '') {
            $at = $keyword['at_time']; //日期
            $end_at = date('Y-m-d', strtotime("$at+1day"));
            $mapAtTime = [['at_time', 'between time', [strtotime($at), strtotime($end_at)]]];
        }

        if ($keyword['kh_rank'] != '') {

            $mapKhRank =  ['kh_rank' => $keyword['kh_rank']];
        }

        if ($keyword['kh_status'] != '') {

            $mapKhStatus =  ['kh_status' => $keyword['kh_status']];
        }

        if ($keyword['phone'] != '') {
            $mapPhone = $this->getContactSearch($keyword['phone']);
        }

        if ($keyword['kh_name'] != '') {
            $mapKhName = [['kh_name', 'like', '%' . $keyword['kh_name'] . '%']];
        }

        if ($keyword['xs_source'] != '') {

            $mapXsSource =  ['xs_source' => $keyword['xs_source']];
        }



        $result  = Db::table('crm_leads')
            ->where($mapPhone)
            ->where($mapKhName)
            ->where($mapKhStatus)
            ->where($mapKhRank)
            ->where($mapXsSource)
            ->where($mapAtTime)
            ->where($mapPrUser)
            ->where(['status' => 1, 'issuccess' => 1]) //0 线索，1客户，2公海
            // ->where(['pr_user' => session('username')]) //负责人
            ->whereTime('at_time', $keyword['timebucket'] ? $keyword['timebucket'] : null)
            ->order('at_time desc')
            ->paginate(array('list_rows' => $limit, 'page' => $page))
            ->toArray();

        //数据集判断方式
        //if($result->isEmpty()){return null;}
        if ($result['total'] == 0) {
            return null;
        } else {
            return $result;
        }
    }


    //客户列表查询所有
    // 查询（客户列表页用）
    public function getClientSearchListAll($page, $limit, $keyword)
    {
        $mapAtTime   = []; // 添加时间
        $mapKhRank   = []; // 客户级别
        $mapKhStatus = []; // 客户状态
        $mapPhone    = []; // 手机号模糊 -> leads_id 集合
        $mapKhName   = []; // 客户名称
        $mapXsSource = []; // 客户来源
        $mapPrUser   = []; // 负责人

        if (!empty($keyword['timebucket'])) $mapAtTime[] = $keyword['timebucket'];
        if ($keyword['kh_rank']   !== '' && $keyword['kh_rank']   !== null) $mapKhRank   = ['kh_rank'   => $keyword['kh_rank']];
        if ($keyword['kh_status'] !== '' && $keyword['kh_status'] !== null) $mapKhStatus = ['kh_status' => $keyword['kh_status']];
        if (!empty($keyword['phone']))  $mapPhone = $this->getContactSearchAll($keyword['phone'], 'l'); // 传别名
        if (!empty($keyword['kh_name'])) $mapKhName = [['kh_name', 'like', '%' . $keyword['kh_name'] . '%']];
        if ($keyword['xs_source'] !== '' && $keyword['xs_source'] !== null) $mapXsSource = ['xs_source' => $keyword['xs_source']];
        if (!empty($keyword['pr_user'])) $mapPrUser = [['pr_user', 'like', '%' . $keyword['pr_user'] . '%']];

        $current_admin = Admin::getMyInfo();
        $team_name = $current_admin['team_name'] ?? '';
        $a_where = [];
        if (strpos($current_admin['org'], 'admin') === false) {
            $a_where = [(new ControllerClient())->getorgWhere($current_admin['org'])];
        }
        $usernames  = [$current_admin['username']];
        if ($current_admin['group_id'] == 1) {
            $usernames = [];
            if ($a_where) $usernames = Db::name('admin')->where($a_where)->column('username');
        } elseif ($team_name) {
            $usernames = Db::name('admin')->where('team_name', $team_name)->where($a_where)->column('username');
        }

        // 主查询：别名 l；单次左连接 contacts，聚合主/辅电话
        $result = Db::table('crm_leads')->alias('l')
            ->where(function ($query) use ($usernames) {
                if ($usernames) $query->whereIn('l.pr_user', $usernames);
            })
            ->where($mapPhone)
            ->where($mapKhName)
            ->where($mapKhStatus)
            ->where($mapKhRank)
            ->where($mapXsSource)
            ->where($mapPrUser)
            ->where($mapAtTime)
            ->where(['l.status' => 1, 'l.issuccess' => -1])
            ->leftJoin('crm_contacts c', "c.leads_id = l.id AND c.is_delete = 0 AND c.contact_type IN (1,3)")
            ->field([
                'l.*',
                // 主电话
                "GROUP_CONCAT(DISTINCT IF(c.contact_type = 1, c.contact_value, NULL) ORDER BY c.id SEPARATOR '<br>') AS main_phone",
                // 辅助电话
                "GROUP_CONCAT(DISTINCT IF(c.contact_type = 3, c.contact_value, NULL) ORDER BY c.id SEPARATOR '<br>') AS aux_phone",
            ])
            ->group('l.id')
            ->order('l.at_time desc')
            ->paginate(['list_rows' => $limit, 'page' => $page])
            ->toArray();

        return ($result['total'] == 0) ? null : $result;
    }



    /**
     * 号码模糊 -> leads_id 条件
     * @param string $phone   输入号码（可含空格/符号/国家码）
     * @param string $alias   可选主表别名（如 'l'），用于避免 id 歧义
     * @return array  形如  [['l.id','in',[...]]] 或 [['id','in',[...]]]
     */
    public function getContactSearchAll($phone, $alias = '')
    {
        $phone = trim((string)$phone);
        if ($phone === '') return [];

        // 仅取数字做模糊
        $phoneKeyword = preg_replace('/\D+/', '', $phone);

        $rows = Db::table('crm_contacts')
            ->where('is_delete', 0)
            ->where('contact_type', 'in', [1, 3]) // 只在主/辅电话里匹配
            ->where('contact_value', 'like', "%{$phoneKeyword}%")
            ->field('leads_id')
            ->select();

        $leadsIds = array_column($rows, 'leads_id');
        if (empty($leadsIds)) {
            return [[$alias ? $alias . '.id' : 'id', '=', -1]]; // 返回一个必不成立条件
        }
        return [[$alias ? $alias . '.id' : 'id', 'in', $leadsIds]];
    }



}
