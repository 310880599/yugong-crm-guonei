<?php
namespace app\admin\model;

use think\Model;
use think\Db;

class Admin extends Model
{
    protected $pk = 'admin_id';

    public function login($data)
    {
        $user = Db::name('admin')->where('username', $data['username'])->find();
        if ($user) {
            if ($user['is_open'] == 1 && $user['pwd'] == md5($data['password'])) {
                session('username', $user['username']);
                session('aid', $user['admin_id']);
                $avatar = $user['avatar'] == '' ? '/static/admin/images/0.jpg' : $user['avatar'];
                session('avatar', $avatar);
                //记录团队名称
                session('team_name', $user['team_name']);
                return ['code' => 1, 'msg' => '登录成功!']; //信息正确
            } else {
                return ['code' => 0, 'msg' => '用户名或者密码错误，重新输入!']; //密码错误
            }
        } else {
            return ['code' => 0, 'msg' => '用户名或者密码错误，重新输入!']; //用户不存在
        }
    }

    public function getInfo($userId)
    {
        return Db::name('admin')->where('admin_id', $userId)->find();
    }

    // 验证码检查方法已移除
}