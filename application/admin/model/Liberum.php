<?php

namespace app\admin\model;

use think\Model;
use think\Db;

class Liberum extends Model
{
    //查询
    public function getLiberumSearchList($page, $limit, $keyword)
    {


        $mapAtTime = []; //添加时间
        $mapXsSource = []; //线索来源
        $mapPhone = []; //手机号模糊查询
        $mapKhName = []; //客户名称




        if ($keyword['at_time'] != '') {
            $at = $keyword['at_time']; //日期
            $end_at = date('Y-m-d', strtotime("$at+1day"));
            $mapAtTime = [['to_gh_time', 'between time', [strtotime($at), strtotime($end_at)]]];
        }

        if ($keyword['pr_gh_type'] != '') {

            $mapXsSource =  ['pr_gh_type' => $keyword['pr_gh_type']];
        }

        if ($keyword['phone'] != '') {
            $mapPhone =function ($q2) use ($keyword) {
                    $phone = str_replace([' ','+'],'',trim($keyword['phone']));
                    $q2->where('c.contact_value', 'like', '%' . $phone . '%')
                        ->whereOrRaw("CONCAT(c.contact_extra, c.contact_value) = '{$phone}'");
                };
            // $mapPhone = [['phone','like','%'.$keyword['phone'].'%']];
        }
        if ($keyword['kh_name'] != '') {
            $mapKhName = [['kh_name', 'like', '%' . $keyword['kh_name'] . '%']];
        }


        // 添加关联查询并分组聚合联系方式
        $result = Db::table('crm_leads')
            ->alias('l')
            ->join('crm_contacts c', 'l.id = c.leads_id', 'left')
            ->where('c.is_delete',0)
            ->where($mapPhone)
            ->where($mapKhName)
            ->where($mapXsSource)
            ->where($mapAtTime)
            ->where(['l.status' => 2])
            ->whereTime('to_gh_time', $keyword['timebucket'] ? $keyword['timebucket'] : null)
            ->field([
                'l.*',
                "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN c.contact_type = 3 THEN c.contact_value END), ',', 1) AS whatsapp",
                "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN c.contact_type = 2 THEN c.contact_value END), ',', 1) AS email",
                "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN c.contact_type = 1 THEN c.contact_value END), ',', 1) AS phone"
            ])
            ->group('l.id')
            ->order('l.to_gh_time desc')
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
