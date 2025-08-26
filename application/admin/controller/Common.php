<?php

namespace app\admin\controller;

use think\Db;
use think\Controller;
use app\admin\model\Admin;
class Common extends Controller
{
    const ORG = [
        0 => 'admin',
        1 => '1s',
        2 => '2s',
        3 => '3s'
    ];
    public  $channel_map = [
        'ymx' => 'YMX',
        '亚马逊' => 'YMX',
        'am' => 'AM',
        '阿里' => 'AM',
        'smt' => 'SMT',
        '速卖通' => 'SMT',
        'sem' => 'SEM',
        'seo' => 'SEO',
        'tk' => 'TK',
        'tiktok' => 'TK',
        '抖音' => 'TK',
        'SEM' => 'SEM',
        'SEO' => 'SEO',
        'TK' => 'TK',
        'SMT' => 'SMT',
        'AM' => 'AM',
        'YMX' => 'YMX',
    ];

    public $yygid=12;//运营id
    public $ywzgid=11;//业务主管


    protected $mod, $role, $system, $nav, $menudata, $cache_model, $categorys, $module, $moduleid, $adminRules, $HrefId;
    public function initialize()
    {
        //判断管理员是否登录
        if (!session('aid')) {
            $this->redirect('admin/login/index');
        }
        define('MODULE_NAME', strtolower(request()->controller()));
        define('ACTION_NAME', strtolower(request()->action()));
        //权限管理
        //当前操作权限ID
        if (session('aid') != 1) {
            $this->HrefId = db('auth_rule')->where('href', MODULE_NAME . '/' . ACTION_NAME)->value('id');
            //当前管理员权限
            $map['a.admin_id'] = session('aid');
            $rules = Db::table(config('database.prefix') . 'admin')->alias('a')
                ->join(config('database.prefix') . 'auth_group ag', 'a.group_id = ag.group_id', 'left')
                ->where($map)
                ->value('ag.rules');
            $this->adminRules = explode(',', $rules);
            if ($this->HrefId) {
                if (!in_array($this->HrefId, $this->adminRules)) {
                    $this->error('您无此操作权限');
                }
            }
        }
        $this->cache_model = array('Module', 'AuthRule', 'Category', 'Posid', 'Field', 'System', 'cm');
        foreach ($this->cache_model as $r) {
            if (!cache($r)) {
                savecache($r);
            }
        }
        $this->system = cache('System');
        $this->categorys = cache('Category');
        $this->module = cache('Module');
        $this->mod = cache('Mod');
        $this->rule = cache('AuthRule');
        $this->cm = cache('cm');
    }
    //空操作
    public function _empty()
    {
        return $this->error('空操作，返回上次访问页面中...');
    }


    //使用缓存记录团队数据

    public function getTeamList($flush = false)
    {

        //清除缓存
        if ($flush) cache('teamList', null);
        $teamList = cache('teamList');
        if ($teamList) {
            return $teamList;
        }
        $teamList = Db::name('admin')->group('team_name')->column('team_name');
        cache('teamList', $teamList);
        return $teamList;
    }


    //redis 连点锁定
    public function redisLock()
    {
        $redis_name  = md5(request()->path() . json_encode(request()->param()));
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
        if ($redis->get($redis_name)) return $this->result([],500,'操作过于频繁，请稍后再试');
        $redis->setex($redis_name, 30, 1);
    }

    //redis 解锁
    public function redisUnLock()
    {
        $redis_name  = md5(request()->path() . json_encode(request()->param()));
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
        $redis->del($redis_name);
    }

    //客户来源列表
    public function getSoruceList()
    {
        if($source_list = cache('sourceList')){
            return $source_list;
        }
        $list = DB::table('crm_client_status')->field('id,status_name as name')->select();

        $source_list = [];
        foreach($list as $v){
            $source_list[$v['name']]= $v['id'];
        }
        cache('sourceList', $source_list);
        return $source_list;
    }



    //运营人员列表
    public function getYyList($channel=null)
    {
        $current_admin = Admin::getMyInfo();
        $where = [['group_id','=',$this->yygid]];

        if($current_admin['org']  && $current_admin['org'] !='admin')$where[]=['org','=',$current_admin['org']];

        if($channel)$where[]=['channel','=',$channel];

        $yyList=[];
        $_yyList=[];
        $list = Admin::where($where)->order('org')->order('channel','asc')->field('admin_id,username,channel')->select();
        $channel_list = array_intersect_key( $this->channel_map, $this->getSoruceList());
        $channel_list = array_flip($channel_list);
        
        foreach($list as $v){
            $_yyList[]=['id'=>$v['admin_id'],'name'=>$v['username']];
            $yyList[$channel_list[$v['channel']]][]=['id'=>$v['admin_id'],'name'=>$v['username']];
        }
        return ['yyList'=>$yyList,'_yyList'=>$_yyList];
    }
}
