<?php
namespace app\admin\controller;
use think\facade\Request;
use think\facade\Env;
use think\Db;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use think\facade\Session;
// require_once '../vendor/PHPExcel/PHPExcel.php';
class Clues extends Common{
    //线索列表
    public function index(){
        if(request()->isPost()){
            $key=input('post.key');
            $page =input('page')?input('page'):1;
            $pageSize =input('limit')?input('limit'):config('pageSize');
            $list = db('crm_leads')
                ->where(['status'=>0,'issuccess'=>-1])
                ->order('ut_time desc')
                ->paginate(array('list_rows'=>$pageSize,'page'=>$page))
                ->toArray();
             // 手机号加密处理
            foreach ($list['data'] as $key => $value) {
               $value['phone'] = mb_substr($value['phone'], 0, 3).'****'. mb_substr($value['phone'], 7, 11);
               $list['data'][$key] = $value;
            }
            return $result = ['code'=>0,'msg'=>'获取成功!','data'=>$list['data'],'count'=>$list['total'],'rel'=>1];
        }

        $xsSourceList = Db::table('crm_clues_source')->select();
        $xsStatusList = Db::table('crm_clues_status')->select();

        $this -> assign('xsSourceList',$xsSourceList);
        $this -> assign('xsStatusList',$xsStatusList);


        return $this->fetch();
    }

    //(我的线索)列表
    public function perClulist(){
        if(request()->isPost()){
            $key=input('post.key');
            $page =input('page')?input('page'):1;
            $pageSize =input('limit')?input('limit'):config('pageSize');

            $list = db('crm_leads')
                ->where(['status'=>0,'issuccess'=>-1])
                ->where(['pr_user'=> Session::get('username')])
                ->order('ut_time desc')
                ->paginate(array('list_rows'=>$pageSize,'page'=>$page))
                ->toArray();
            return $result = ['code'=>0,'msg'=>'获取成功!','data'=>$list['data'],'count'=>$list['total'],'rel'=>1];
        }

        $xsSourceList = Db::table('crm_clues_source')->select();
        $xsStatusList = Db::table('crm_clues_status')->select();

        $this -> assign('xsSourceList',$xsSourceList);
        $this -> assign('xsStatusList',$xsStatusList);


        return $this->fetch('personclues/index');
    }

   public function xlsUpload() {
    $xlsFile = request()->file('xlsFile');

    if (!$xlsFile) {
        return json(['code' => -1, 'msg' => '请上传Excel文件']);
    }

    $uploadPath = Env::get('root_path') . 'public/uploads/';
    $info = $xlsFile->move($uploadPath);
    if (!$info) {
        return json(['code' => -1, 'msg' => '文件上传失败：' . $xlsFile->getError()]);
    }

    $filePath = $uploadPath . $info->getSaveName();

    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, true);
    } catch (\Exception $e) {
        return json(['code' => -1, 'msg' => '读取Excel出错：' . $e->getMessage()]);
    }

    // 第一行为标题行
    $headers = array_shift($data);

    Db::startTrans();
    try {
        $contactsInsertData = [];
        foreach ($data as $row) {
            $rowAssoc = [];
            foreach ($headers as $key => $title) {
                $rowAssoc[$title] = $row[$key] ?? '';
            }

            // 主表数据
            $leadsRow = [
                'kh_name'     => $rowAssoc['客户名称'] ?? '',
                'kh_rank'     => $rowAssoc['客户等级'] ?? '',
                'pr_gh_type'  => $rowAssoc['客户归属公海'] ?? '',
                'kh_status'   => $rowAssoc['客户来源'] ?? '',
                'xs_area'     => $rowAssoc['客户国家'] ?? '',
                'kh_contact'  => $rowAssoc['联系人'] ?? '',
                'remark'      => $rowAssoc['客户备注'] ?? '',
                'pr_user'     => Session::get('username'),
                'ut_time'     => date("Y-m-d H:i:s"),
                'at_time'     => date("Y-m-d H:i:s"),
                'at_user'     => Session::get('username'),
                'status'      => 1
            ];

            db('crm_leads')->insert($leadsRow);
            $leadsId = Db::name('crm_leads')->getLastInsID();

            // 联系方式
            $phone = trim($rowAssoc['联系人电话'] ?? '');
            $email = trim($rowAssoc['联系人邮箱'] ?? '');
            $whatsapp = trim($rowAssoc['联系人WhatsApp'] ?? '');

            if (!empty($phone)) {
                db('crm_contacts')->insert([
                    'leads_id' => $leadsId,
                    'contact_type' => self::CONTACT_MAP['phone'],
                    'contact_value' => $phone,
                    'created_at' => date("Y-m-d H:i:s")
                ]);
            }

            if (!empty($email)) {
                db('crm_contacts')->insert([
                    'leads_id' => $leadsId,
                    'contact_type' => self::CONTACT_MAP['email'],
                    'contact_value' => $email,
                    'created_at' => date("Y-m-d H:i:s")
                ]);
            }

            if (!empty($whatsapp)) {
                db('crm_contacts')->insert([
                    'leads_id' => $leadsId,
                    'contact_type' => self::CONTACT_MAP['whatsapp'],
                    'contact_value' => $whatsapp,
                    'created_at' => date("Y-m-d H:i:s")
                ]);
            }
        }

        Db::commit();
        return json(['code' => 0, 'msg' => '成功导入']);

    } catch (\Exception $e) {
        Db::rollback();
        return json(['code' => -1, 'msg' => '导入失败', 'error' => $e->getMessage()]);
    }
}



    //新建线索
    public function add(){
        if(request()->isPost()){
            // <!-- 线索名称、地区、行业类别、线索来源、联系人、联系号码、用户名、线索状态、备注 -->
            $data['xs_name'] = Request::param('xs_name');
            $data['xs_area'] = Request::param('xs_area');
            $data['kh_hangye'] = Request::param('kh_hangye');
            $data['kh_contact'] = Request::param('kh_contact');
            // $data['kh_username'] = Request::param('kh_username');
            $data['phone'] = Request::param('phone');

            $data['xs_source'] = Request::param('xs_source');
            $data['xs_status'] = Request::param('xs_status');
            $data['remark'] = Request::param('remark');

            $data['at_user'] = Session::get('username');
            $data['at_time'] = date("Y-m-d H:i:s",time());
            $data['ut_time'] = date("Y-m-d H:i:s",time());
            $data['pr_user'] = Session::get('username');
            $data['pr_user_bef'] = Session::get('username');

            $userExist = db('crm_leads')->where('phone', $data['phone'])->find();
            if ($userExist){
                $msg = ['code' => -200,'msg'=>'抱歉，重复线索不可添加！','data'=>[]];
                return json($msg);
            }

            $result = Db::table('crm_leads')->insert($data);
            if ($result){
                $msg = ['code' => 0,'msg'=>'添加成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'添加失败！','data'=>[]];
                return json($msg);
            }
        }


        $xsSourceList = Db::table('crm_clues_source')->select();
        $xsStatusList = Db::table('crm_clues_status')->select();
        $xsAreaList = Db::table('crm_clues_area')->select();
        $xsHangyeList = Db::table('crm_client_hangye')->select();
        $this -> assign('xsHangyeList',$xsHangyeList);
        $this -> assign('xsAreaList',$xsAreaList);

        $this -> assign('xsSourceList',$xsSourceList);
        $this -> assign('xsStatusList',$xsStatusList);

        return $this->fetch('clues/add');
    }
    //编辑线索
    public function edit(){
        if (Request::isAjax()){
            $data  = Request::param();
            $data['ut_time'] = date("Y-m-d H:i:s",time());

            $result = Db::table('crm_leads')->where(['id'=>$data['id']])->where('status',0)->update($data);
            if ($result){
                $msg = ['code' => 0,'msg'=>'编辑成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'编辑失败！','data'=>[]];
                return json($msg);
            }
        }


        $result = Db::table('crm_leads') ->where(['id' => Request::param('id')])->find();
        $this -> assign('result',$result);

        $xsSourceList = Db::table('crm_clues_source')->select();
        $xsStatusList = Db::table('crm_clues_status')->select();
        $xsAreaList = Db::table('crm_clues_area')->select();
        $xsHangyeList = Db::table('crm_client_hangye')->select();
        $this -> assign('xsHangyeList',$xsHangyeList);
        $this -> assign('xsAreaList',$xsAreaList);
        $this -> assign('xsSourceList',$xsSourceList);
        $this -> assign('xsStatusList',$xsStatusList);

        return $this -> fetch('clues/edit');
    }
    //删除线索
    public function del(){
        $id = Request::param('id');
        $result = Db::table('crm_leads')->where('id',$id)->where('status',0)->delete();
        if ($result){
            $msg = ['code' => 0,'msg'=>'删除成功！','data'=>[]];
            return json($msg);
        }else{
            $msg = ['code' => -200,'msg'=>'删除失败！','data'=>[]];
            return json($msg);
        }
    }



    //线索状态
    public function statusList(){
        if(request()->isPost()){
            $page =input('page')?input('page'):1;
            $pageSize =input('limit')?input('limit'):config('pageSize');
            $list = db('crm_clues_status')
                ->paginate(array('list_rows'=>$pageSize,'page'=>$page))
                ->toArray();
            return $result = ['code'=>0,'msg'=>'获取成功!','data'=>$list['data'],'count'=>$list['total'],'rel'=>1];
        }
        return $this->fetch();
    }
    //添加线索状态
    public function statusAdd(){
        if(request()->isPost()){
            $data['status_name'] = Request::param('status_name');
            $data['add_time'] = time();
            $result = Db::table('crm_clues_status')->insert($data);
            if ($result){
                $msg = ['code' => 0,'msg'=>'添加成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'添加失败！','data'=>[]];
                return json($msg);
            }
        }
        return $this->fetch('clues/status_list_add');
    }
    //编辑线索状态
    public function statusEdit(){
        if (Request::isAjax()){
            $data  = Request::param();
            $result = Db::table('crm_clues_status')->where(['id'=>$data['id']])->update($data);
            if ($result){
                $msg = ['code' => 0,'msg'=>'编辑成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'编辑失败！','data'=>[]];
                return json($msg);
            }
        }


        $result = Db::table('crm_clues_status') ->where(['id' => Request::param('id')])->find();
        $this -> assign('result',$result);
        return $this -> fetch('clues/status_list_edit');
    }
    //删除线索状态
    public function statusDel(){
        $id = Request::param('id');
        $result = Db::table('crm_clues_status')->where('id',$id)->delete();
        if ($result){
            $msg = ['code' => 0,'msg'=>'删除成功！','data'=>[]];
            return json($msg);
        }else{
            $msg = ['code' => -200,'msg'=>'删除失败！','data'=>[]];
            return json($msg);
        }
    }





    //线索来源
    public function sourceList(){
        if(request()->isPost()){
            $page =input('page')?input('page'):1;
            $pageSize =input('limit')?input('limit'):config('pageSize');
            $list = db('crm_clues_source')
                ->paginate(array('list_rows'=>$pageSize,'page'=>$page))
                ->toArray();
            return $result = ['code'=>0,'msg'=>'获取成功!','data'=>$list['data'],'count'=>$list['total'],'rel'=>1];
        }
        return $this->fetch();
    }
    //添加线索来源
    public function sourceAdd(){
        if(request()->isPost()){
            $data['source_name'] = Request::param('source_name');
            $data['add_time'] = time();
            $result = Db::table('crm_clues_source')->insert($data);
            if ($result){
                $msg = ['code' => 0,'msg'=>'添加成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'添加失败！','data'=>[]];
                return json($msg);
            }
        }
        return $this->fetch('clues/source_list_add');
    }
    //编辑线索来源
    public function sourceEdit(){
        if (Request::isAjax()){
            $data  = Request::param();
            $result = Db::table('crm_clues_source')->where(['id'=>$data['id']])->update($data);
            if ($result){
                $msg = ['code' => 0,'msg'=>'编辑成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'编辑失败！','data'=>[]];
                return json($msg);
            }
        }


        $result = Db::table('crm_clues_source') ->where(['id' => Request::param('id')])->find();
        $this -> assign('result',$result);
        return $this -> fetch('clues/source_list_edit');
    }
    //删除线索来源
    public function sourceDel(){
        $id = Request::param('id');
        $result = Db::table('crm_clues_source')->where('id',$id)->delete();
        if ($result){
            $msg = ['code' => 0,'msg'=>'删除成功！','data'=>[]];
            return json($msg);
        }else{
            $msg = ['code' => -200,'msg'=>'删除失败！','data'=>[]];
            return json($msg);
        }
    }


    //地区来源
    public function areaList(){
        if(request()->isPost()){
            $page =input('page')?input('page'):1;
            $pageSize =input('limit')?input('limit'):config('pageSize');
            $list = db('crm_clues_area')
                ->paginate(array('list_rows'=>$pageSize,'page'=>$page))
                ->toArray();
            return $result = ['code'=>0,'msg'=>'获取成功!','data'=>$list['data'],'count'=>$list['total'],'rel'=>1];
        }
        return $this->fetch();
    }
    //添加地区来源
    public function areaAdd(){
        if(request()->isPost()){
            $data['area_name'] = Request::param('area_name');
            $data['add_time'] = time();
            $result = Db::table('crm_clues_area')->insert($data);
            if ($result){
                $msg = ['code' => 0,'msg'=>'添加成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'添加失败！','data'=>[]];
                return json($msg);
            }
        }
        return $this->fetch('clues/area_list_add');
    }
    //编辑地区来源
    public function areaEdit(){
        if (Request::isAjax()){
            $data  = Request::param();
            $result = Db::table('crm_clues_area')->where(['id'=>$data['id']])->update($data);
            if ($result){
                $msg = ['code' => 0,'msg'=>'编辑成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'编辑失败！','data'=>[]];
                return json($msg);
            }
        }


        $result = Db::table('crm_clues_area') ->where(['id' => Request::param('id')])->find();
        $this -> assign('result',$result);
        return $this -> fetch('clues/area_list_edit');
    }
    //删除地区来源
    public function areaDel(){
        $id = Request::param('id');
        $result = Db::table('crm_clues_area')->where('id',$id)->delete();
        if ($result){
            $msg = ['code' => 0,'msg'=>'删除成功！','data'=>[]];
            return json($msg);
        }else{
            $msg = ['code' => -200,'msg'=>'删除失败！','data'=>[]];
            return json($msg);
        }
    }




    //转成客户
    public function toTurnKh(){
        // 检测当前剩余抢的次数
        $curname = Session::get('username');
        $curget = Db::table('admin')->where(['username'=>$curname])->field('curgetnum')->find();
        $curgetnum = $curget['curgetnum'];
        $sysinfo = Db::table('system')->where(['id'=>1])->field('maxgetnum,custlimit')->find();
        $maxgetnum = $sysinfo['maxgetnum'];
        $custlimit = $sysinfo['custlimit'];
       
        if ($curgetnum>=$maxgetnum) {
            $msg = ['code' => -200,'msg'=>'抱歉，您当月抢的次数已经达到上限'.$maxgetnum.'次!','data'=>[]];
            return json($msg);
        }
        // 检测当前客户数最大数量
        $wherecust = [];
        $wherecust['pr_user'] = $curname;
        $wherecust['status'] = 1;
        $wherecust['ispublic'] = 2;
        $wherecust['issuccess'] = -1;
        $maxcustnum = Db::table('crm_leads')->where($wherecust)->count('id');
        if($maxcustnum>=$custlimit){
            $msg = ['code' => -200,'msg'=>'抱歉，您抢得的客户数量已达上限'.$custlimit.'!','data'=>[]];
            return json($msg);
        }
        
        if (Request::isAjax()){
            $data['kh_name']  = Request::param('kh_name');
            $data['kh_rank']  = Request::param('kh_rank');
            $data['kh_status']  = Request::param('kh_status');
            $data['kh_need']  = Request::param('kh_need');
            $data['to_kh_time'] = date("Y-m-d H:i:s",time());
            $data['status'] = 1;//0-线索，1客户，2公海
            // 状态变化 设置私人公共变化
            // 抢到客户名称为自己
            $data['pr_user_bef'] = Db::table('crm_leads')->where(['id'=>$value])->field('pr_user')->find();
            $data['pr_user'] = Session::get('username');
            $data['ispublic'] = 2;//1 公共 2私人抢夺 3 私人添加
            $data['id']  = Request::param('id');
            $result = Db::table('crm_leads')->where(['id'=>$data['id']])->update($data);
            if ($result){
                // 抢的次数增加1
                $curgetnum = $curgetnum + 1;
                $curgetnum = Db::table('admin')->where(['username'=>$curname])->update(['curgetnum'=>$curgetnum]);

                $msg = ['code' => 0,'msg'=>'线上客户抢成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'抱歉，线索客户抢失败！','data'=>[]];
                return json($msg);
            }
        }

        $khRankList = Db::table('crm_client_rank')->select();
        $khStatusList = Db::table('crm_client_status')->select();

        $this -> assign('khRankList',$khRankList);
        $this -> assign('khStatusList',$khStatusList);

        $result = Db::table('crm_leads') ->where(['id' => Request::param('id')])->find();
        $this -> assign('result',$result);
        return $this -> fetch('clues/turn_kh');
    }


    //抢客户
    public function toTurnKh2(){
        $data['id'] = Request::param('id');
        //抢客户之前，先去判断是否可抢
        // 检测当前剩余抢的次数
        $curname = Session::get('username');
        $curget = Db::table('admin')->where(['username'=>$curname])->field('curgetnum')->find();
        $curgetnum = $curget['curgetnum'];
        $sysinfo = Db::table('system')->where(['id'=>1])->field('maxgetnum,custlimit')->find();
        $maxgetnum = $sysinfo['maxgetnum'];
        $custlimit = $sysinfo['custlimit'];
       
        if ($curgetnum>=$maxgetnum) {
            $msg = ['code' => -200,'msg'=>'抱歉，您当月抢的次数已经达到上限'.$maxgetnum.'次!','data'=>[]];
            return json($msg);
        }
        // 检测当前客户数最大数量
        $wherecust = [];
        $wherecust['pr_user'] = $curname;
        $wherecust['status'] = 1;
        $wherecust['ispublic'] = 2;
        $wherecust['issuccess'] = -1;
        $maxcustnum = Db::table('crm_leads')->where($wherecust)->count('id');
        if($maxcustnum>=$custlimit){
            $msg = ['code' => -200,'msg'=>'抱歉，您抢得的客户数量已达上限'.$custlimit.'!','data'=>[]];
            return json($msg);
        }

        $gh_client = Db::table('crm_leads')->where(['id' => $data['id']])->where(['status' => 0])->find();
        if ($gh_client){
            // $data['to_kh_time'] = date("Y-m-d H:i:s",time());
            // $data['status'] = 1;//0-线索，1客户，2公海
            // $data['pr_user_bef'] = $gh_client['pr_user'];
            // $data['pr_user'] = Session::get('username');
            //  // 状态变化 设置私人公共变化
            // $data['ispublic'] = 2;//1 公共 2私人抢夺 3 私人添加

            $data['kh_name']  = $gh_client['xs_name'];
            $data['kh_rank']  = '';
            $data['kh_status']  = '';
            $data['to_kh_time'] = date("Y-m-d H:i:s",time());
            $data['ut_time'] = date("Y-m-d H:i:s",time());
            $data['status'] = 1;//0-线索，1客户，2公海
            // 状态变化 设置私人公共变化
            // 抢到客户名称为自己
            $data['pr_user_bef'] = Db::table('crm_leads')->where(['id'=>$value])->field('pr_user')->find();
            $data['pr_user'] = Session::get('username');
            $data['ispublic'] = 2;//1 公共 2私人抢夺 3 私人添加
            $data['id']  = $data['id'];


            $result = Db::table('crm_leads')->where(['id'=>$data['id']])->update($data);
            if ($result){
                // 抢的次数增加1
                $curgetnum = $curgetnum + 1;
                $curgetnum = Db::table('admin')->where(['username'=>$curname])->update(['curgetnum'=>$curgetnum]);

                $msg = ['code' => 0,'msg'=>'抢客户成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'抢客户失败！','data'=>[]];
                return json($msg);
            }
        }else{
            $msg = ['code' => -200,'msg'=>'抱歉，该客户已被抢走！','data'=>[]];
            return json($msg);
        }
    }
    //线索搜索
    public function cluesSearch(){
        $page =input('page')?input('page'):1;
        $limit =input('limit')?input('limit'):config('pageSize');
        $keyword = Request::param('keyword');
        $list= model('clues') -> getCluesSearchList($page,$limit,$keyword);
        return $result = ['code'=>0,'msg'=>'获取成功!','data'=>$list['data'],'count'=>$list['total'],'rel'=>1];

    }


    //（我的线索）搜索
    public function personCluesSearch(){
        $page =input('page')?input('page'):1;
        $limit =input('limit')?input('limit'):config('pageSize');
        $keyword = Request::param('keyword');
        $list= model('clues') -> getPersonCluesSearchList($page,$limit,$keyword);
        return $result = ['code'=>0,'msg'=>'获取成功!','data'=>$list['data'],'count'=>$list['total'],'rel'=>1];

    }


    //线索转移，变更负责人
    public function alterPrUser(){
        //1，获取提交的线索ID 【1,2,3,4,】
        $ids = Request::param('ids');
        $this -> assign('ids',$ids);


        //查询所有管理员（去除admin）
        $adminResult = Db::name('admin')->where('group_id','<>', 1)->field('admin_id,username')->select();
        $this -> assign('adminResult',$adminResult);

        if (Request::isAjax()){
            $username = Request::param('username');
            $idsArr = explode(",",$ids);


            $count = 0;
            foreach ($idsArr as $key => $value){
                $data['pr_user_bef'] = Db::table('crm_leads')->where(['id'=>$value])->field('pr_user')->find();
                $data['pr_user'] = $username;
                $data['id'] = $value;
                $insertAll = Db::name('crm_leads')->update($data);
                if ($insertAll){
                    $count ++;
                }
            }




            if ($count > 0){
                $msg = ['code' => 0,'msg'=>'转移'.$count.'条线索成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'转移失败！','data'=>[]];
                return json($msg);
            }
        }

        return $this -> fetch('clues/alter_pr_user');
    }


    //线索转移，变更负责人（个人）
    public function alterPrUserPri(){
        //1，获取提交的线索ID 【1,2,3,4,】
        $ids = Request::param('ids');
        $this -> assign('ids',$ids);


        //查询所有管理员（去除admin）
        $adminResult = Db::name('admin')->where('group_id','<>', 1)->field('admin_id,username')->select();
        $this -> assign('adminResult',$adminResult);

        if (Request::isAjax()){
            $username = Request::param('username');
            $idsArr = explode(",",$ids);


            $count = 0;
            foreach ($idsArr as $key => $value){
                $data['pr_user_bef'] = Db::table('crm_leads')->where(['id'=>$value])->field('pr_user')->find();
                $data['pr_user'] = $username;
                $data['id'] = $value;
                $insertAll = Db::name('crm_leads')->update($data);
                if ($insertAll){
                    $count ++;
                }
            }




            if ($count > 0){
                $msg = ['code' => 0,'msg'=>'转移'.$count.'条线索成功！','data'=>[]];
                return json($msg);
            }else{
                $msg = ['code' => -200,'msg'=>'转移失败！','data'=>[]];
                return json($msg);
            }
        }

        return $this -> fetch('clues/alter_pr_user');
    }

}