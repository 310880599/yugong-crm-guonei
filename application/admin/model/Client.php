<?php

namespace app\admin\model;

use app\admin\controller\Client as ControllerClient;
use think\Model;
use think\Db;

class Client extends Model
{
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

        if ($keyword['pr_user'] != '') {
            $mapPrUser = [['pr_user', 'like', '%' . $keyword['pr_user'] . '%']];
        }

        $adminId = session('aid');
        $team_name = session('team_name') ?? '';

        $usernames  = [session('username')];
        if ($adminId == 1) {
            $usernames = [];
        } else if ($team_name) {
            // 主管查看直属下属及自己的客户
            $usernames = Db::name('admin')->where('team_name', $team_name)->column('username');
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
            ->whereTime('at_time', $keyword['timebucket'] ? $keyword['timebucket'] : null)
            //->whereTime('at_time',$keyword['timebucket'] ? $keyword['timebucket'] : '')
            ->order('ut_time desc')
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
        $phone =  preg_split('/-| /', $phone);
        if (count($phone) >= 2) {
            $phone = $phone[1];
        } else $phone = $phone[0];
        $leads_ids =  Db::table('crm_contacts')->where('is_delete', 0)->where('contact_type', ControllerClient::CONTACT_MAP['phone'])
            ->where('contact_value', 'like', '%' . $phone . '%')
            ->field('leads_id')->select();
        $leads_ids = array_column($leads_ids, 'leads_id');
        return [['id', 'in', $leads_ids]];
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
            ->where(['status' => 1, 'issuccess' => -1]) //0 线索，1客户，2公海
            ->where(['pr_user' => session('username')]) //负责人
            ->whereTime('at_time', $keyword['timebucket'] ? $keyword['timebucket'] : null)
            ->order('ut_time desc')
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
            ->order('ut_time desc')
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
}
