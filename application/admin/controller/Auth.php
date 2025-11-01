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
            if(!$current_admin){
                return $result = ['code' => 0, 'msg' => '用户不存在'];
            }
            // 构建查询条件
            $map = [];
            $where = [];
            if ($val) {
                $map['username'] = ['like', "%" . $val . "%"];
            }
            if ($current_admin['group_id'] != 1 && $current_admin['org'] != 'admin') {
                $where[]=$this->getOrgWhere($current_admin['org']);
            }
            // 权限控制逻辑
            if ($current_admin['group_id'] == 11) {
                // 如果是主管(group_id=11)，显示同team_name的管理员
                if (!empty($current_admin['team_name'])) {
                    $map['team_name'] = $current_admin['team_name'];
                } else {
                    // 如果team_name为空，只显示自己
                    $map['admin_id'] = $admin_id;
                }
            } else if ($current_admin['group_id'] == 12) {
                //运营主管
                if (!empty($current_admin['team_name'])) {
                    $map['team_name'] = $current_admin['team_name'];
                    if ($current_admin['position'] == 0) {
                        $map['admin_id'] = $admin_id;
                    } elseif ($current_admin['position'] == 2) {
                        $map['channel'] = $current_admin['channel'];
                    }
                } else {
                    // 如果team_name为空，只显示自己
                    $map['admin_id'] = $admin_id;
                }
            } else if ($current_admin['group_id'] != 1) {
                // 非超级管理员只能查看自己的信息
                $map['admin_id'] = $admin_id;
            }
            // 查询数据
            $list = Db::table(config('database.prefix') . 'admin')->alias('a')
                ->join(config('database.prefix') . 'auth_group ag', 'a.group_id = ag.group_id', 'left')
                ->field('a.*,ag.title')
                ->where($map)
                ->where($where)
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
            if($data['org'])$data['org'] = $this->org_fgx . implode($this->org_fgx , $data['org']) .$this->org_fgx  ;
            
            // 处理运营端口数据（现在存储的是店铺名称，不是ID）
            if (isset($data['operation_ports']) && is_array($data['operation_ports'])) {
                $ports = array_filter(array_map('trim', $data['operation_ports'])); // 过滤空值并去空格
                if (!empty($ports)) {
                    // 验证店铺是否已被使用（通过店铺名称验证）
                    $channel = $data['channel'] ?? '';
                    if (!empty($channel)) {
                        $used_ports = $this->checkPortsUsed($ports, $channel, 0);
                        if (!empty($used_ports)) {
                            return ['code' => 0, 'msg' => '以下店铺已被其他管理员使用：' . implode('、', $used_ports)];
                        }
                    }
                    $data['operation_ports'] = implode(',', $ports);
                } else {
                    $data['operation_ports'] = '';
                }
            } else {
                $data['operation_ports'] = '';
            }
            
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
            if ($current_admin['group_id'] == 1 || strpos($current_admin['org'],'admin') !== false) {
                // 超级管理员可以显示所有用户组
                $auth_group = AuthGroup::all();
            } else {
                $orgList = $this->getOrg($current_admin['org']);
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
            $info['org'] = $this->getOrg($current_admin['org']);
            $this->assign('team_name', $current_admin['team_name']);
            $this->assign('orgList', $orgList);
            $this->assign('leaderList', $leaderList);
            $this->assign('authGroup', $auth_group);
            $this->assign('title', lang('add') . lang('admin'));
            $this->assign('info', json_encode($info, true));
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
            // 判断是否有修改权限
            if (!$this->checkAuth('adminEdit')) {
                return $result = ['code' => 0, 'msg' => '您无此操作权限'];
            }

            $data = input('post.');
            $pwd = input('post.pwd');
            $map[] = ['admin_id', '<>', $data['admin_id']];
            $where['admin_id'] = $data['admin_id'];
            $info = Admin::getInfo(input('admin_id'));

            if (!$info) {
                return $result = ['code' => 0, 'msg' => '用户不存在!'];
            }

            // 保存旧的用户名
            $oldUsername = $info['username'];

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

            // 处理运营端口数据（现在存储的是店铺名称，不是ID）
            if (isset($data['operation_ports']) && is_array($data['operation_ports'])) {
                $ports = array_filter(array_map('trim', $data['operation_ports'])); // 过滤空值并去空格
                if (!empty($ports)) {
                    // 验证店铺是否已被使用（通过店铺名称验证）
                    $channel = $data['channel'] ?? '';
                    if (!empty($channel)) {
                        $used_ports = $this->checkPortsUsed($ports, $channel, $data['admin_id']);
                        if (!empty($used_ports)) {
                            return ['code' => 0, 'msg' => '以下店铺已被其他管理员使用：' . implode('、', $used_ports)];
                        }
                    }
                    $data['operation_ports'] = implode(',', $ports);
                } else {
                    $data['operation_ports'] = '';
                }
            } else {
                // 如果没有提交端口数据，保持原有值或设为空
                if (!isset($data['operation_ports'])) {
                    unset($data['operation_ports']);
                }
            }

            $msg = $this->validate($data, 'app\admin\validate\Admin');
            if ($msg != 'true') {
                return $result = ['code' => 0, 'msg' => $msg];
            }
            if($data['org'])$data['org'] = $this->org_fgx . implode($this->org_fgx , $data['org']) .$this->org_fgx  ;
            // 开启事务（可选）
            Db::startTrans();
            try {
                Admin::update($data, $where);

                // 如果 username 发生变化，同步更新 crm_leads 表
                if (isset($data['username']) && $data['username'] !== $oldUsername) {
                    Db::name('crm_leads')
                        ->where('pr_user', $oldUsername)
                        ->update(['pr_user' => $data['username']]);
                }

                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                return ['code' => 0, 'msg' => '更新失败，请重试'];
            }

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

            $info = Admin::getInfo(input('admin_id'));

            //当前用户信息
            $current_admin = Admin::getInfo(session('aid'));
            // 获取主管列表供下拉选择
            $leaderList = $this->getLeaderList($current_admin['group_id']);
            $this->assign('leaderList', $leaderList);
            // 组织列表
            $orgList = self::ORG;
            if ($current_admin['group_id'] == 1 || strpos($current_admin['org'],'admin') !== false) {
                // 超级管理员可以显示所有用户组
                $auth_group = AuthGroup::all();
            } else {
                $orgList = $this->getOrg($current_admin['org']);
                if ($current_admin['group_id'] == $this->yygid) {
                    //运营只能看到运营
                    $auth_group = AuthGroup::where('group_id', '=', $this->yygid)->select();
                } else {
                    $auth_group = AuthGroup::where('group_id', '<>', $this->yygid)->where('group_id', '<>', 1)->select();
                }
            }
            $result['is_yy'] = $current_admin['group_id'] == $this->yygid ? 1 : 0;
            $result['channel'] = $current_admin['channel'] ?? "";
            $result['is_admin'] = $current_admin['group_id'] == 1 ? 1 : 0;
            $result['is_position'] = $current_admin['position'] == 1 ? 1 : 0;
            $info['org'] = $this->getOrg($info['org']);
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
        // 查询crm_client_status表，包含店铺字段
        // 先检查shop_names字段是否存在
        try {
            $columns = Db::query("SHOW COLUMNS FROM `crm_client_status` LIKE 'shop_names'");
            $has_shop_names = !empty($columns);
        } catch (\Exception $e) {
            $has_shop_names = false;
        }
        
        // 根据字段是否存在决定查询字段
        if ($has_shop_names) {
            $list = Db::name('crm_client_status')->field('id,status_name,shop_names')->select();
        } else {
            // 如果字段不存在，只查询基本字段
            $list = Db::name('crm_client_status')->field('id,status_name')->select();
        }
        
        $channels = [];
        foreach ($list as $li) {
            $name = strtolower($li['status_name']);
            if (empty($this->channel_map[$name])) {
                continue;
            }
            // 返回渠道信息，包含原始status_name和映射后的渠道名称，以及店铺列表
            $channels[] = [
                'id' => $li['id'], 
                'name' => $this->channel_map[$name],
                'status_name' => $li['status_name'],
                'status_id' => $li['id'],
                'shop_names' => ($has_shop_names && isset($li['shop_names'])) ? $li['shop_names'] : '' // 店铺名称字符串（逗号分隔）
            ];
        }

        return json(['code' => 1, 'msg' => '获取成功!', 'data' => $channels]);
    }

    // 根据渠道获取店铺列表（从crm_client_status表的shop_names字段读取）
    public function getShops()
    {
        try {
            $channel = input('channel', '');
            $status_id = input('status_id', 0); // 获取status_id参数
            
            if (empty($channel)) {
                return json(['code' => 0, 'msg' => '渠道参数不能为空', 'data' => []]);
            }

            // 获取当前管理员ID（用于排除已选中的店铺）
            $current_admin_id = input('admin_id', 0);
            
            // 获取对应的status_id和status_name
            $status_info = null;
            if ($status_id > 0) {
                // 如果提供了status_id，直接从crm_client_status表查询（明确指定字段）
                $status_info = Db::name('crm_client_status')
                    ->where('id', $status_id)
                    ->field('id,status_name,shop_names')
                    ->find();
            } else {
                // 如果没有status_id，通过渠道名称反向查找
                foreach ($this->channel_map as $key => $value) {
                    if ($value === $channel) {
                        // 先尝试精确匹配
                        $status_info = Db::name('crm_client_status')
                            ->where('status_name', $key)
                            ->field('id,status_name,shop_names')
                            ->find();
                        if (!$status_info) {
                            // 尝试模糊匹配
                            $status_info = Db::name('crm_client_status')
                                ->where('status_name', 'like', '%' . $key . '%')
                                ->field('id,status_name,shop_names')
                                ->find();
                        }
                        if ($status_info) {
                            break;
                        }
                    }
                }
            }
            
            if (!$status_info) {
                return json(['code' => 0, 'msg' => '未找到对应的渠道信息，渠道名称：' . $channel, 'data' => []]);
            }
            
            // 从crm_client_status表的shop_names字段获取店铺列表
            $shop_names_str = '';
            if (isset($status_info['shop_names'])) {
                $shop_names_str = trim($status_info['shop_names']);
            }
            
            // 如果字段为空，返回提示信息
            if (empty($shop_names_str)) {
                return json([
                    'code' => 0, 
                    'msg' => '该渠道（' . $status_info['status_name'] . '）暂无店铺数据，请执行SQL：UPDATE crm_client_status SET shop_names = \'店铺1,店铺2,店铺3\' WHERE id = ' . $status_info['id'], 
                    'data' => []
                ]);
            }
            
            // 解析店铺名称（逗号分隔）
            $shop_names = array_filter(array_map('trim', explode(',', $shop_names_str)));
            if (empty($shop_names)) {
                return json(['code' => 0, 'msg' => '该渠道暂无店铺数据', 'data' => []]);
            }
            
            // 构建店铺列表（使用店铺名称作为唯一标识）
            $shops = [];
            foreach ($shop_names as $index => $shop_name) {
                if (!empty($shop_name)) {
                    $shops[] = [
                        'id' => md5($status_info['id'] . '_' . $shop_name), // 生成唯一ID
                        'name' => $shop_name,
                        'channel' => $channel,
                        'shop_name' => $shop_name, // 保留原始店铺名称用于匹配
                        'status_id' => $status_info['id']
                    ];
                }
            }
            
            if (empty($shops)) {
                return json(['code' => 0, 'msg' => '该渠道暂无店铺数据', 'data' => []]);
            }
            
            // 获取已被其他管理员使用的店铺名称（operation_ports字段存储的是店铺名称）
            $used_shop_names = [];
            $current_shop_names = [];
            
            if (!empty($current_admin_id)) {
                // 编辑时，获取当前管理员的店铺
                $current_admin = Admin::get($current_admin_id);
                if ($current_admin && !empty($current_admin['operation_ports'])) {
                    $current_shop_names = array_filter(array_map('trim', explode(',', $current_admin['operation_ports'])));
                }
            }
            
            // 查询同渠道下其他管理员使用的店铺
            $other_admins = Db::name('admin')
                ->where('group_id', $this->yygid)
                ->where('channel', $channel)
                ->where('operation_ports', '<>', '')
                ->where('operation_ports', '<>', null)
                ->field('admin_id, operation_ports')
                ->select();
            
            foreach ($other_admins as $admin) {
                if (!empty($admin['operation_ports'])) {
                    $admin_shops = array_filter(array_map('trim', explode(',', $admin['operation_ports'])));
                    foreach ($admin_shops as $shop_name) {
                        if (!empty($shop_name) && !isset($used_shop_names[$shop_name])) {
                            $used_shop_names[$shop_name] = $admin['admin_id'];
                        }
                    }
                }
            }
            
            // 标记店铺是否已被使用（通过店铺名称匹配）
            foreach ($shops as &$shop) {
                $shop_name = $shop['shop_name'];
                // 检查是否被其他管理员使用
                $shop['is_used'] = isset($used_shop_names[$shop_name]) ? 1 : 0;
                
                // 如果是编辑模式，且当前管理员已选中该店铺，则标记为可用
                if ($shop['is_used'] && !empty($current_admin_id) && !empty($current_shop_names)) {
                    if (in_array($shop_name, $current_shop_names)) {
                        $shop['is_used'] = 0;
                    }
                }
            }
            
            return json(['code' => 1, 'msg' => '获取成功!', 'data' => $shops]);
        } catch (\Exception $e) {
            // 直接返回错误信息，无需记录日志
            return json(['code' => 0, 'msg' => '获取店铺列表失败：' . $e->getMessage(), 'data' => []]);
        }
    }

    // 检查店铺（店铺名称）是否已被使用
    private function checkPortsUsed($shop_names, $channel, $exclude_admin_id = 0)
    {
        if (empty($shop_names) || empty($channel)) {
            return [];
        }
        
        // 确保是数组格式，并去除空格
        $shop_names = is_array($shop_names) ? array_filter(array_map('trim', $shop_names)) : [];
        if (empty($shop_names)) {
            return [];
        }
        
        $used_ports = [];
        
        // 查询同渠道下其他管理员的店铺使用情况
        $query = Db::name('admin')
            ->where('group_id', $this->yygid)
            ->where('channel', $channel)
            ->where('operation_ports', '<>', '')
            ->where('operation_ports', '<>', null);
        
        if ($exclude_admin_id > 0) {
            $query->where('admin_id', '<>', $exclude_admin_id);
        }
        
        $other_admins = $query->field('admin_id, operation_ports, username')->select();
        
        // 检查每个店铺名称是否已被使用
        foreach ($shop_names as $shop_name) {
            foreach ($other_admins as $admin) {
                if (!empty($admin['operation_ports'])) {
                    $admin_shops = array_filter(array_map('trim', explode(',', $admin['operation_ports'])));
                    if (in_array($shop_name, $admin_shops)) {
                        // 店铺已被使用
                        if (!in_array($shop_name, $used_ports)) {
                            $used_ports[] = $shop_name;
                        }
                        break; // 找到一个就够了
                    }
                }
            }
        }
        
        return $used_ports;
    }
}
