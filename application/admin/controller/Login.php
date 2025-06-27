<?php
namespace app\admin\controller;

use think\Controller;
use app\admin\model\Admin;

class Login extends Controller
{
    private $cache_model, $system;

    public function initialize()
    {
        if (session('aid')) {
            $this->redirect('admin/index/index');
        }
        $this->cache_model = array('Module', 'AuthRule', 'Category', 'Posid', 'Field', 'System');
        $this->system = cache('System');
        $this->assign('system', $this->system);
        if (empty($this->system)) {
            foreach ($this->cache_model as $r) {
                savecache($r);
            }
        }
    }

    public function index()
    {
        if (request()->isPost()) {
            $data = input('post.');
            $admin = new Admin();
            $return = $admin->login($data); // 移除第二个参数
            return ['code' => $return['code'], 'msg' => $return['msg']];
        } else {
            return $this->fetch();
        }
    }

    // 验证码方法已移除
}