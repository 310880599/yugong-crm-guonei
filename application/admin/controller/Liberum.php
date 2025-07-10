<?php
namespace app\admin\controller;
use think\facade\Request;
use think\Db;
use think\facade\Session;
use app\admin\behavior\ContactMap; 

class Liberum extends Common{

    // 公海列表
    public function index(){
        if(request()->isPost()){
            $key = input('post.key');
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            
            // 添加关联查询crm_contacts表获取详细联系方式
            $list = db('crm_leads')
                ->alias('l')
                ->join('crm_contacts c', 'l.id = c.leads_id', 'left')
                ->where(['l.status' => 2, 'l.issuccess' => -1])
                ->field([
                    'l.*',
                    // 按类型分组获取联系方式
                    "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN c.contact_type = ".ContactMap::CONTACT_MAP['whatsapp']." THEN c.contact_value END), ',', 1) AS whatsapp",
                    "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN c.contact_type = ".ContactMap::CONTACT_MAP['email']." THEN c.contact_value END), ',', 1) AS email",
                    "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN c.contact_type = ".ContactMap::CONTACT_MAP['phone']." THEN c.contact_value END), ',', 1) AS phone"
                ])
                ->group('l.id')
                ->order('l.ut_time desc')
                ->paginate(['list_rows' => $pageSize, 'page' => $page])
                ->toArray();
                
            return ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }

        $ghTypeList = Db::table('crm_liberum_type')->select();
        $this->assign('ghTypeList', $ghTypeList);
        return $this->fetch();
    }

    // 公海类型
    public function libTypeList(){
        if(request()->isPost()){
            $page = input('page') ?: 1;
            $pageSize = input('limit') ?: config('pageSize');
            $list = db('crm_liberum_type')
                ->paginate(['list_rows' => $pageSize, 'page' => $page])
                ->toArray();
            return ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }
        return $this->fetch('liberum/lib_type_list');
    }

    // 添加公海类型
    public function libTypeAdd(){
        if(request()->isPost()){
            $data['type_name'] = Request::param('type_name');
            $data['add_time'] = time();
            $result = Db::table('crm_liberum_type')->insert($data);
            return $result 
                ? ['code' => 0, 'msg' => '添加成功！', 'data' => []]
                : ['code' => -200, 'msg' => '添加失败！', 'data' => []];
        }
        return $this->fetch('liberum/lib_type_add');
    }

    // 编辑公海类型
    public function libTypeEdit(){
        if(Request::isAjax()){
            $data = Request::param();
            $result = Db::table('crm_liberum_type')->where(['id' => $data['id']])->update($data);
            return $result 
                ? ['code' => 0, 'msg' => '编辑成功！', 'data' => []]
                : ['code' => -200, 'msg' => '编辑失败！', 'data' => []];
        }

        $result = Db::table('crm_liberum_type')->where(['id' => Request::param('id')])->find();
        $this->assign('result', $result);
        return $this->fetch('liberum/lib_type_edit');
    }

    // 删除公海类型
    public function libTypeDel(){
        $id = Request::param('id');
        $result = Db::table('crm_liberum_type')->where('id', $id)->delete();
        return $result 
            ? ['code' => 0, 'msg' => '删除成功！', 'data' => []]
            : ['code' => -200, 'msg' => '删除失败！', 'data' => []];
    }

    // 公海搜索
    public function liberumSearch(){
        $page = input('page') ?: 1;
        $limit = input('limit') ?: config('pageSize');
        $keyword = Request::param('keyword');
        $list = model('liberum')->getLiberumSearchList($page, $limit, $keyword);
        return ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
    }

    // 写跟进
    public function libdialog(){
        $id = Request::param('id');
        $result = Db::table('crm_leads')->where(['id' => $id])->find();
        
        $result['comment'] = Db::table('crm_comment')
            ->alias('com')
            ->join('admin adm', 'com.user_id = adm.admin_id')
            ->where(['leads_id' => $id])
            ->field('com.*,adm.username,adm.avatar')
            ->select();
        
        foreach ($result['comment'] as $k => $v){
            $result['comment'][$k]['reply'] = Db::table('crm_reply')->where(['comment_id' => $v['id']])->select();
        }

        $this->assign('result', $result);
        return $this->fetch('liberum/libdialog');
    }

    // 抢客户
    public function robClient(){
        $data['id'] = Request::param('id');
        $curname = Session::get('username');
        
        // 检查抢客户次数限制
        $curget = Db::table('admin')->where(['username' => $curname])->field('curgetnum')->find();
        $sysinfo = Db::table('system')->where(['id' => 1])->field('maxgetnum,custlimit')->find();
        
        if ($curget['curgetnum'] >= $sysinfo['maxgetnum']) {
            return ['code' => -200, 'msg' => "抱歉，您当月抢的次数已经达到上限{$sysinfo['maxgetnum']}次!", 'data' => []];
        }

        // 检查客户数量限制
        $wherecust = [
            'pr_user' => $curname,
            'status' => 1,
            'ispublic' => 2,
            'issuccess' => -1
        ];
        $maxcustnum = Db::table('crm_leads')->where($wherecust)->count('id');
        
        if($maxcustnum >= $sysinfo['custlimit']){
            return ['code' => -200, 'msg' => "抱歉，您抢得的客户数量已达上限{$sysinfo['custlimit']}!", 'data' => []];
        }

        $gh_client = Db::table('crm_leads')->where(['id' => $data['id'], 'status' => 2])->find();
        
        if ($gh_client){
            $data['to_kh_time'] = date("Y-m-d H:i:s");
            $data['ut_time'] = date("Y-m-d H:i:s");
            $data['pr_gh_type'] = NULL;
            $data['status'] = 1;
            $data['pr_user_bef'] = $gh_client['pr_user'];
            $data['pr_user'] = Session::get('username');
            $data['ispublic'] = 2;

            $result = Db::table('crm_leads')->where(['id' => $data['id']])->update($data);
            return $result 
                ? ['code' => 0, 'msg' => '抢客户成功！', 'data' => []]
                : ['code' => -200, 'msg' => '抢客户失败！', 'data' => []];
        }
        
        return ['code' => -200, 'msg' => '抱歉，该客户已被抢走！', 'data' => []];
    }

     // 获取客户详细信息接口
    public function getClientDetails() {
        // 显式定义目录分隔符常量
        defined('DS') || define('DS', DIRECTORY_SEPARATOR);
        
        // 构建日志路径并确保跨平台兼容性
        $appPath = defined('APP_PATH') ? APP_PATH : __DIR__ . '..' . DS . '..' . DS . '..' . DS . '..' . DS;
        $logPath = $appPath . 'runtime' . DS . 'log' . DS . 'admin' . DS;
        
        // 优化目录创建逻辑
        if (!is_dir($logPath)) {
            // 添加递归创建参数并增强错误处理
            if (!mkdir($logPath, 0755, true)) {
                // 记录目录创建失败日志
                error_log("Failed to create log directory: $logPath");
            }
        }
        
        // 初始化日志配置
        \think\Log::init([
            'type' => 'File',
            'path' => $logPath,
            'level' => ['error', 'debug', 'sql']
        ]);
        
        try {
            $id = input('id');
            if(!is_numeric($id)) {
                return ['code' => 1, 'msg' => '参数错误'];
            }

            // 查询客户基本信息
            $clientInfo = db('crm_leads')
                ->alias('l')
                ->join('crm_contacts c', 'l.id = c.leads_id', 'left')
                ->where(['l.id' => $id, 'l.status' => 2])
                ->field([
                    'l.*',
                    // 获取所有联系方式
                    "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN c.contact_type = ".ContactMap::CONTACT_MAP['whatsapp']." THEN c.contact_value END), ',', 1) AS whatsapp",
                    "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN c.contact_type = ".ContactMap::CONTACT_MAP['email']." THEN c.contact_value END), ',', 1) AS email",
                    "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN c.contact_type = ".ContactMap::CONTACT_MAP['phone']." THEN c.contact_value END), ',', 1) AS phone",
                    "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN c.contact_type = ".ContactMap::CONTACT_MAP['ali_id']." THEN c.contact_value END), ',', 1) AS ali_id",
                    "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN c.contact_type = ".ContactMap::CONTACT_MAP['wechat']." THEN c.contact_value END), ',', 1) AS wechat"
                ])
                ->group('l.id')
                ->find();

            if(!$clientInfo) {
                return ['code' => 1, 'msg' => '客户信息不存在'];
            }

            // 权限验证
            $currentUser = Session::get('username');
            if($clientInfo['at_user'] !== $currentUser && !in_array(Session::get('role'), ['admin', 'manager'])) {
                return ['code' => 1, 'msg' => '无权查看该客户信息'];
            }

            return ['code' => 0, 'msg' => '获取成功', 'data' => $clientInfo];
            
        } catch (\Exception $e) {
            // 记录详细错误日志
            \think\Log::record('getClientDetails Error: ' . $e->getMessage());
            \think\Log::record('Trace: ' . $e->getTraceAsString());
            
            return ['code' => 1, 'msg' => '服务器内部错误'];
        }
    }
}