<?php

namespace app\admin\controller;

use function MongoDB\BSON\toJSON;
use think\Db;
use clt\Leftnav;
use app\admin\model\Admin;
use app\admin\model\AuthGroup;
use app\admin\model\authRule;
use think\facade\Request;
use think\Validate;

class Auth extends Common
{
    //增删改权限判断
    private  function checkAuth($rule)
    {
        $current_admin = Admin::getMyInfo();
        //超级管理员
        if ($current_admin['group_id'] == 1) {
            return true;
        }
        //运营主管
        // $where = [['group_id', $this->yygid], ['position', '<>', 0]];
        if ($current_admin['group_id'] == $this->yygid && $current_admin['position'] != 0) {
            return true;
        }

        //业务主管
        if ($current_admin['group_id'] == $this->ywzgid) {

            return true;
        }
        return false;
    }


    //管理员列表
    public function adminList()
    {
        if (Request::isAjax()) {
            $val = input('val');
            $url['val'] = $val;
            $this->assign('testval', $val);

            // 获取当前用户信息
            $admin_id = session('aid');
            $current_admin = Admin::get($admin_id);

            // 构建查询条件
            $map = [];
            if ($val) {
                $map['username|email|tel'] = ['like', "%" . $val . "%"];
            }
            if ($current_admin && $current_admin->org != 'admin') {
                $map['org'] = $current_admin->org;
            }

            // 权限控制逻辑
            if ($current_admin && $current_admin->group_id == 11) {
                // 如果是主管(group_id=11)，显示同team_name的管理员
                if (!empty($current_admin->team_name)) {
                    $map['team_name'] = $current_admin->team_name;
                } else {
                    // 如果team_name为空，只显示自己
                    $map['admin_id'] = $admin_id;
                }
            } else if ($current_admin && $current_admin->group_id == 12) {
                //运营主管
                if (!empty($current_admin->team_name)) {
                    $map['team_name'] = $current_admin->team_name;
                    if ($current_admin->position == 0) {
                        $map['admin_id'] = $admin_id;
                    } elseif ($current_admin->position == 2) {
                        $map['channel'] = $current_admin->channel;
                    }
                } else {
                    // 如果team_name为空，只显示自己
                    $map['admin_id'] = $admin_id;
                }
            } else if (session('aid') != 1) {
                // 非超级管理员只能查看自己的信息
                $map['admin_id'] = $admin_id;
            }

            // 查询数据
            $list = Db::table(config('database.prefix') . 'admin')->alias('a')
                ->join(config('database.prefix') . 'auth_group ag', 'a.group_id = ag.group_id', 'left')
                ->field('a.*,ag.title')
                ->where($map)
                ->select();

            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list, 'rel' => 1];
        }
        return view();
    }

    public function adminAdd()
    {
        if (Request::isAjax()) {
            //判断是否有添加权限
            if (!$this->checkAuth('adminAdd')) {
                return $result = ['code' => 0, 'msg' => '您无此操作权限'];
            }

            $data = input('post.');
            $check_user = Admin::get(['username' => $data['username']]);
            if ($check_user) {
                return $result = ['code' => 0, 'msg' => '用户已存在，请重新输入用户名!'];
            }
            $data['pwd'] = input('post.pwd', '', 'md5');
            //默认开启
            $data['is_open'] = 1;
            $data['add_time'] = time();
            $data['ip'] = request()->ip();
            //验证
            $msg = $this->validate($data, 'app\admin\validate\Admin');
            if ($msg != 'true') {
                return $result = ['code' => 0, 'msg' => $msg];
            }
            //单独验证密码
            $checkPwd = Validate::make([input('post.pwd') => 'require']);
            if (false === $checkPwd) {
                return $result = ['code' => 0, 'msg' => '密码不能为空！'];
            }
            //添加
            if (Admin::create($data)) {
                //判断是否是新增的团队
                if (!empty($data['team_name'])) {
                    $teamList = $this->getTeamList();
                    if (!in_array($data['team_name'], $teamList)) {
                        //缓存更新
                        $this->getTeamList(true);
                    }
                }
                return ['code' => 1, 'msg' => '管理员添加成功!', 'url' => url('adminList')];
            } else {
                return ['code' => 0, 'msg' => '管理员添加失败!'];
            }
        } else {
            // 获取当前用户信息
            $admin_id = session('aid');
            $current_admin = Admin::get($admin_id);
            if (!$current_admin) {
                return $this->error('用户不存在');
            }
            $result = [];
            // 组织列表
            $orgList = self::ORG;
            // 查询用户组
            if ($current_admin && $current_admin['group_id'] == 1) {
                // 超级管理员可以显示所有用户组
                $auth_group = AuthGroup::all();
            } else {
                $orgList = ['' => $current_admin['org']];
                if ($current_admin->group_id == $this->yygid) {
                    //运营只能看到运营
                    $auth_group = AuthGroup::where('group_id', '=', $this->yygid)->select();
                    $result['is_yy'] = 1;
                } else {
                    // 其他用户只能看到普通员工组
                    $auth_group = AuthGroup::where('group_id', '=', 10)->select();
                }
            }
            $result['channel'] = $current_admin['channel'] ?? "";
            $result['username'] = $current_admin['username'] ?? "";
            $result['is_admin'] = $current_admin['group_id'] == 1 ? 1 : 0;
            $result['is_position'] = $current_admin['position'] == 1 ? 1 : 0;


            // 获取主管列表
            $leaderList = $this->getLeaderList($current_admin['group_id']);

            $this->assign('team_name', $current_admin['team_name']);
            $this->assign('orgList', $orgList);
            $this->assign('leaderList', $leaderList);
            $this->assign('authGroup', $auth_group);
            $this->assign('title', lang('add') . lang('admin'));
            $this->assign('info', 'null');
            $this->assign('selected', 'null');
            $this->assign('result', $result);
            return view('adminForm');
        }
    }

    //获取主管列表
    private function getLeaderList($group_id)
    {
        if ($group_id == $this->yygid) {
            $leaderList = \app\admin\model\Admin::where('group_id', $group_id)->where('position', '<>', 0)
                ->field('admin_id, username')->select();
        } else {
            $leaderList = \app\admin\model\Admin::where('group_id', 11)
                ->field('admin_id, username')->select();
        }
        return $leaderList;
    }

    //删除管理员
    public function adminDel()
    {
        //判断是否有删除权限
        if (!$this->checkAuth('adminDel')) {
            return $result = ['code' => 0, 'msg' => '您无此操作权限'];
        }

        $admin_id = input('post.admin_id');
        if (session('aid') == 1) {
            Admin::where('admin_id', '=', $admin_id)->delete();
            return $result = ['code' => 1, 'msg' => '删除成功!'];
        } else {
            return $result = ['code' => 0, 'msg' => '您没有删除管理员的权限!'];
        }
    }
    //修改管理员状态
    public function adminState()
    {
        $id = input('post.id');
        $is_open = input('post.is_open');
        if (empty($id)) {
            $result['status'] = 0;
            $result['info'] = '用户ID不存在!';
            $result['url'] = url('adminList');
            return $result;
        }
        db('admin')->where('admin_id=' . $id)->update(['is_open' => $is_open]);
        $result['status'] = 1;
        $result['info'] = '用户状态修改成功!';
        $result['url'] = url('adminList');
        return $result;
    }
    //更新管理员信息
    public function adminEdit()
    {
        if (request()->isPost()) {
            //判断是否有修改权限
            if (!$this->checkAuth('adminEdit')) {
                return $result = ['code' => 0, 'msg' => '您无此操作权限'];
            }
            //return $result = ['code'=>0,'msg'=>'当前为演示系统无法修改信息!'];
            $data = input('post.');
            $pwd = input('post.pwd');
            $map[] = ['admin_id', '<>', $data['admin_id']];
            $where['admin_id'] = $data['admin_id'];
            $info = Admin::getInfo(input('admin_id'));
            if (!$info) {
                return $result = ['code' => 0, 'msg' => '用户不存在!'];
            }
            if ($data['username']) {
                $map[] = ['username', '=', $data['username']];
                $check_user = Admin::where($map)->find();
                if ($check_user) {
                    return $result = ['code' => 0, 'msg' => '用户已存在，请重新输入用户名!'];
                }
            }
            if ($pwd && $pwd != $info['pwd']) {
                $data['pwd'] = input('post.pwd', '', 'md5');
            } else {
                unset($data['pwd']);
            }
            $msg = $this->validate($data, 'app\admin\validate\Admin');
            if ($msg != 'true') {
                return $result = ['code' => 0, 'msg' => $msg];
            }
            Admin::update($data, $where);
            if ($data['admin_id'] == session('aid')) {
                session('username', $data['username']);
                $avatar = $data['avatar'] == '' ? '/static/admin/images/0.jpg' : $data['avatar'];
                session('avatar', $avatar);
            }
            return $result = ['code' => 1, 'msg' => '管理员修改成功!', 'url' => url('adminList')];
        } else {
            // $auth_group = AuthGroup::all();
            // $admin = new Admin();
            // $info = $admin->getInfo(input('admin_id'));
            // $this->assign('info', json_encode($info,true));
            // $this->assign('authGroup',$auth_group);
            // $this->assign('title',lang('edit').lang('admin'));
            // return view('adminForm');
            $auth_group = AuthGroup::all();

            $info = Admin::getInfo(input('admin_id'));
            // 获取主管列表供下拉选择
            $leaderList = \app\admin\model\Admin::where('group_id', 11)
                ->field('admin_id, username')->select();
            $this->assign('leaderList', $leaderList);
            //当前用户信息\
            $current_admin = Admin::getInfo(session('aid'));
            // 组织列表
            $orgList = self::ORG;
            $result['is_yy'] = $current_admin['group_id'] == $this->yygid ? 1 : 0;
            $result['channel'] = $current_admin['channel'] ?? "";
            $result['is_admin'] = $current_admin['group_id'] == 1 ? 1 : 0;
            $result['is_position'] = $current_admin['position'] == 1 ? 1 : 0;
            $this->assign('orgList', $orgList);
            $this->assign('info', json_encode($info, true));
            $this->assign('authGroup', $auth_group);
            $this->assign('is_edit', 1);
            $this->assign('title', lang('edit') . lang('admin'));
            $this->assign('result', $result);
            return view('adminForm');
        }
    }
    /*-----------------------用户组管理----------------------*/
    //用户组管理
    public function adminGroup()
    {
        if (request()->isPost()) {
            $list = AuthGroup::all();
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list, 'rel' => 1];
        }
        return view();
    }
    //删除管理员分组
    public function groupDel()
    {
        AuthGroup::where('group_id', '=', input('id'))->delete();
        return $result = ['code' => 1, 'msg' => '删除成功!'];
    }
    //添加分组
    public function groupAdd()
    {
        if (request()->isPost()) {
            $data = input('post.');
            $data['addtime'] = time();
            AuthGroup::create($data);
            $result['msg'] = '用户组添加成功!';
            $result['url'] = url('adminGroup');
            $result['code'] = 1;
            return $result;
        } else {
            $this->assign('title', '添加用户组');
            $this->assign('info', 'null');
            return $this->fetch('groupForm');
        }
    }
    //修改分组
    public function groupEdit()
    {
        if (request()->isPost()) {
            $data = input('post.');
            $where['group_id'] = $data['group_id'];
            AuthGroup::update($data, $where);
            $result = ['code' => 1, 'msg' => '用户组修改成功!', 'url' => url('adminGroup')];
            return $result;
        } else {
            $id = input('id');
            $info = AuthGroup::get(['group_id' => $id]);
            $this->assign('info', json_encode($info, true));
            $this->assign('title', '编辑用户组');
            return $this->fetch('groupForm');
        }
    }
    //分组配置规则
    public function groupAccess()
    {
        $nav = new Leftnav();
        $admin_rule = db('auth_rule')->field('id,pid,title')->order('sort asc')->select();
        $rules = db('auth_group')->where('group_id', input('id'))->value('rules');
        $arr = $nav->auth($admin_rule, $pid = 0, $rules);
        $arr[] = array(
            "id" => 0,
            "pid" => 0,
            "title" => "全部",
            "open" => true
        );
        $this->assign('data', json_encode($arr, true));
        return $this->fetch();
    }
    public function groupSetaccess()
    {
        $rules = input('post.rules');
        if (empty($rules)) {
            return array('msg' => '请选择权限!', 'code' => 0);
        }
        $data = input('post.');
        $where['group_id'] = $data['group_id'];
        if (AuthGroup::update($data, $where)) {
            return array('msg' => '权限配置成功!', 'url' => url('adminGroup'), 'code' => 1);
        } else {
            return array('msg' => '保存错误', 'code' => 0);
        }
    }

    /********************************权限管理*******************************/
    public function adminRule()
    {
        if (request()->isPost()) {
            $arr = cache('authRuleList');
            if (!$arr) {
                $arr = Db::name('authRule')->order('pid asc,sort asc')->select();
                foreach ($arr as $k => $v) {
                    $arr[$k]['lay_is_open'] = false;
                }
                cache('authRuleList', $arr, 3600);
            }
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $arr, 'is' => true, 'tip' => '操作成功'];
        }
        return view();
    }
    public function clear()
    {
        $arr = Db::name('authRule')->where('pid', 'neq', 0)->select();
        foreach ($arr as $k => $v) {
            $p = Db::name('authRule')->where('id', $v['pid'])->find();
            if (!$p) {
                Db::name('authRule')->where('id', $v['id'])->delete();
            }
        }
        cache('authRule', NULL);
        cache('authRuleList', NULL);
        $this->success('清除成功');
    }
    public function ruleAdd()
    {
        if (request()->isPost()) {
            $data = input('post.');
            $data['addtime'] = time();
            authRule::create($data);
            cache('authRule', NULL);
            cache('authRuleList', NULL);
            cache('addAuthRuleList', NULL);
            return $result = ['code' => 1, 'msg' => '权限添加成功!', 'url' => url('adminRule')];
        } else {
            $nav = new Leftnav();
            $arr = cache('addAuthRuleList');
            if (!$arr) {
                $authRule = authRule::all(function ($query) {
                    $query->order('sort', 'asc');
                });
                $arr = $nav->menu($authRule);
                cache('addAuthRuleList', $arr, 3600);
            }
            $this->assign('admin_rule', $arr); //权限列表
            return $this->fetch();
        }
    }
    public function ruleOrder()
    {
        $auth_rule = db('auth_rule');
        $data = input('post.');
        if ($auth_rule->update($data) !== false) {
            cache('authRuleList', NULL);
            cache('authRule', NULL);
            cache('addAuthRuleList', NULL);
            return $result = ['code' => 1, 'msg' => '排序更新成功!', 'url' => url('adminRule')];
        } else {
            return $result = ['code' => 0, 'msg' => '排序更新失败!'];
        }
    }
    //设置权限菜单显示或者隐藏
    public function ruleState()
    {
        $id = input('post.id');
        $menustatus = input('post.menustatus');
        if (db('auth_rule')->where('id=' . $id)->update(['menustatus' => $menustatus]) !== false) {
            cache('authRule', NULL);
            cache('authRuleList', NULL);
            cache('addAuthRuleList', NULL);
            return ['status' => 1, 'msg' => '设置成功!'];
        } else {
            return ['status' => 0, 'msg' => '设置失败!'];
        }
    }
    //设置权限是否验证
    public function ruleTz()
    {
        $id = input('post.id');
        $authopen = input('post.authopen');
        if (db('auth_rule')->where('id=' . $id)->update(['authopen' => $authopen]) !== false) {
            cache('authRule', NULL);
            cache('authRuleList', NULL);
            cache('addAuthRuleList', NULL);
            return ['status' => 1, 'msg' => '设置成功!'];
        } else {
            return ['status' => 0, 'msg' => '设置失败!'];
        }
    }
    public function ruleDel()
    {
        authRule::destroy(['id' => input('param.id')]);
        cache('authRule', NULL);
        cache('authRuleList', NULL);
        cache('addAuthRuleList', NULL);
        return $result = ['code' => 1, 'msg' => '删除成功!'];
    }

    public function ruleEdit()
    {
        if (request()->isPost()) {
            $datas = input('post.');
            if (authRule::update($datas)) {
                cache('authRule', NULL);
                cache('authRuleList', NULL);
                cache('addAuthRuleList', NULL);
                return json(['code' => 1, 'msg' => '保存成功!', 'url' => url('adminRule')]);
            } else {
                return json(['code' => 0, 'msg' => '保存失败！']);
            }
        } else {
            $admin_rule = authRule::get(function ($query) {
                $query->where(['id' => input('id')])->field('id,href,title,icon,sort,menustatus');
            });
            $this->assign('rule', $admin_rule);
            return $this->fetch();
        }
    }


    public function getChannels()
    {
        $list = Db::name('crm_client_status')->field('id,status_name')->select();
        $channels = [];
        foreach ($list as $li) {
            $name = strtolower($li['status_name']);
            if (empty($this->channel_map[$name])) {
                continue;
            }
            $channels[] = ['id' => $li['id'], 'name' => $this->channel_map[$name]];
        }

        return json(['code' => 1, 'msg' => '获取成功!', 'data' => $channels]);
    }
}
