<?php

namespace app\admin\controller;

use think\Db;
use think\facade\Request;
use think\facade\Session;
use think\facade\Env;

class Client extends Common
{
    const CONTACT_MAP = [
        'phone' => 1,
        'email' => 2,
        'whatsapp' => 3,
    ];


    // 添加公共日志
    private function addOperLog($leads_id, $type, $description)
    {
        Db::table('crm_operation_log')->insert([
            'leads_id' => $leads_id,
            'oper_type' => $type,
            'description' => $description,
            'oper_user' => Session::get('username'),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    //客户联系方式格式化
    public function formatContact($contactList)
    {
        $contactGroup = [];
        foreach ($contactList as $contact) {
            if (isset($contactGroup[$contact['leads_id']][$contact['contact_type']])) {
                $contactGroup[$contact['leads_id']][$contact['contact_type']][] = $contact['contact_extra'] ? $contact['contact_extra'] . '-' . $contact['contact_value'] : $contact['contact_value'];
            } else {
                $contactGroup[$contact['leads_id']][$contact['contact_type']] = [$contact['contact_extra'] ? $contact['contact_extra'] . '-' . $contact['contact_value'] : $contact['contact_value']];
            }
        }
        return $contactGroup;
    }

    public function getContactType($contactGroup)
    {
        $con_map = array_flip(self::CONTACT_MAP);
        $result = [];
        foreach ($contactGroup as $key => $vo) {
            $typeName = $con_map[$key] ?? 'unknown';
            $result[$typeName] = $vo;
        }
        return $result;
    }

    //客户列表
    public function index()
    {
        if (request()->isPost()) {
            $key = input('post.key');
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            $adminId = Session::get('aid');
            $subordinates = Db::name('admin')->where('parent_id', $adminId)->column('username');
            if ($adminId == 1) {
                // 超级管理员：查看所有未成交客户
                $list = db('crm_leads')
                    ->where(['status' => 1])
                    ->order('ut_time desc')
                    ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                    ->toArray();
            } elseif (!empty($subordinates)) {
                // 主管：查看直属下属的所有客户（不区分成交状态）
                $usernames = array_merge($subordinates, [Session::get('username')]);
                $list = db('crm_leads')
                    ->where('status', 1)
                    ->whereIn('pr_user', $usernames)
                    ->order('ut_time desc')
                    ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                    ->toArray();
            } else {
                // 普通员工：仅查看自己名下未成交的客户
                $list = db('crm_leads')
                    ->where(['status' => 1])
                    ->where(['pr_user' => Session::get('username')])
                    ->order('ut_time desc')
                    ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                    ->toArray();
            }
            return ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];

        }

        $khRankList = Db::table('crm_client_rank')->select();
        $khStatusList = Db::table('crm_client_status')->select();
        $xsSourceList = Db::table('crm_clues_source')->select();

        //查询所有管理员（去除admin）
        $adminResult = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $this->assign('adminResult', $adminResult);

        $this->assign('khRankList', $khRankList);
        $this->assign('khStatusList', $khStatusList);
        $this->assign('xsSourceList', $xsSourceList);  //线索/客户来源

        return $this->fetch();
    }

    //（我的客户）列表
    public function perCliList()
    {

        if (request()->isPost()) {
            $key = input('post.key');
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            $list = db('crm_leads')
                ->where(['status' => 1, 'issuccess' => -1])
                ->where(['pr_user' => Session::get('username')])
                ->order('ut_time desc')
                ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                ->toArray();
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }

        $khRankList = Db::table('crm_client_rank')->select();
        $khStatusList = Db::table('crm_client_status')->select();
        $xsSourceList = Db::table('crm_clues_source')->select();

        $this->assign('khRankList', $khRankList);
        $this->assign('khStatusList', $khStatusList);
        $this->assign('xsSourceList', $xsSourceList);  //线索/客户来源

        return $this->fetch('personclient/index');
    }

    //成交客户列表
    public function successCliList()
    {

        if (request()->isPost()) {
            $where = [];
            $where['issuccess'] = 1;
            if (session('aid') != 1) {
                $where['pr_user'] = Session::get('username');
            }
            $key = input('post.key');
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            $list = db('crm_leads')
                ->where($where)
                ->order('ut_time desc')
                ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                ->toArray();
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }

        $khRankList = Db::table('crm_client_rank')->select();
        $khStatusList = Db::table('crm_client_status')->select();
        $xsSourceList = Db::table('crm_clues_source')->select();

        $this->assign('khRankList', $khRankList);
        $this->assign('khStatusList', $khStatusList);
        $this->assign('xsSourceList', $xsSourceList);  //线索/客户来源
        //查询所有管理员（去除admin）
        $adminResult = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $this->assign('adminResult', $adminResult);
        return $this->fetch('client/chengjiao');
    }

    


    public function xlsUpload()
{
    $file = request()->file('xlsFile');
    $savePath = Env::get('root_path') . 'public/uploads/';
    $info = $file->move($savePath);

    if (!$info) {
        return json(['code' => -1, 'msg' => '文件上传失败']);
    }

    $filePath = $savePath . $info->getSaveName();
    $objPHPExcel = \PHPExcel_IOFactory::load($filePath);
    $sheet = $objPHPExcel->getActiveSheet();
    $highestRow = $sheet->getHighestRow();

    $insertData = [];

    for ($i = 2; $i <= $highestRow; $i++) {
        $row = [
            'kh_name'     => trim($sheet->getCell("A$i")->getValue()),
            'kh_rank'     => trim($sheet->getCell("B$i")->getValue()),
            'pr_gh_type'  => trim($sheet->getCell("K$i")->getValue()),
            'kh_status'   => trim($sheet->getCell("L$i")->getValue()),
            'xs_area'     => trim($sheet->getCell("M$i")->getValue()),
            'kh_contact'  => trim($sheet->getCell("N$i")->getValue()),
            'kh_hangye'   => trim($sheet->getCell("S$i")->getValue()),
            'phone'       => trim($sheet->getCell("T$i")->getValue()),
        ];

        // 多字段去重
        $exists = Db::name('crm_leads')->where([
            ['kh_name', '=', $row['kh_name']],
            ['phone', '=', $row['phone']],
        ])->find();

        if ($exists) continue;

        $row['pr_user'] = Session::get('username');
        $row['pr_user_bef'] = Session::get('username');
        $row['ut_time'] = date('Y-m-d H:i:s');
        $row['at_time'] = date('Y-m-d H:i:s');
        $row['at_user'] = Session::get('username');
        $row['status'] = 1;
        $row['ispublic'] = 3;
        $row['issuccess'] = -1;

        $insertData[] = $row;
    }

    Db::startTrans();
    try {
        if (!empty($insertData)) {
            Db::name('crm_leads')->insertAll($insertData);
        }
        Db::commit();
        return json(['code' => 0, 'msg' => '成功导入 ' . count($insertData) . ' 条']);
    } catch (\Exception $e) {
        Db::rollback();
        return json(['code' => -1, 'msg' => '导入失败: ' . $e->getMessage()]);
    }
}

    //新建客户
    public function add()
    {
        if (request()->isPost()) {
            // dd(Request::param());
            // <!-- 客户名称、地区、行业类别、联系人、联系号码、客户级别、客户状态、用户名、备注 -->
            $contact['phone'] = Request::param('phone');
            $contact['email'] = Request::param('email');
            $contact['whatsapp'] = Request::param('whatsapp');
            $phone_code = Request::param('phone_code');

            $data['kh_name'] = Request::param('kh_name');
            $data['xs_area'] = Request::param('xs_area');
            // $data['kh_contact'] = Request::param('kh_contact');
            $data['kh_rank'] = Request::param('kh_rank');
            $data['kh_status'] = Request::param('kh_status');
            // $data['kh_username'] = Request::param('kh_username');
            $data['remark'] = Request::param('remark');

            // $data['kh_need'] = Request::param('kh_need');
            $data['at_user'] = Session::get('username');
            $data['pr_user'] = Session::get('username');
            $data['pr_user_bef'] = Session::get('username');
            $data['ut_time'] = date("Y-m-d H:i:s", time());
            $data['at_time'] = date("Y-m-d H:i:s", time());
            $data['status'] = 1;
            $data['ispublic'] = 3;
            //当前录入查重
            $find = db('crm_leads')->whereIn('kh_name', $data['kh_name'])->find();
            if ($find)  return fail($data['kh_name'] . '客户信息已存在,当前所属人' . $find['pr_user']);

            foreach (['email' => '邮箱', 'phone' => '手机号码', 'whatsapp' => 'whatsapp'] as $k => $v) {
                if (is_string($contact[$k])) $contact[$k] = explode(',', $contact[$k]);
                $duplicates = getDuplicates($contact[$k]);
                if ($duplicates) {
                    return fail($v . ':' . implode(',', $duplicates) . ' 重复录入');
                }
                //查询关联表crm_contacts数据表重复
                $contactExist = db('crm_contacts')->where('is_delete', 0)->whereIn('contact_value', $contact[$k])->find();
                if ($contactExist) {
                    $find =  db('crm_leads')->where('id', $contactExist['leads_id'])->find();
                    return fail($contactExist['contact_value'] . '客户信息已存在,当前所属人' . $find['pr_user']);
                }
            }
            Db::startTrans();
            try {
                // 客户信息保存
                db('crm_leads')->insert($data);
                $id = Db::getLastInsID();
                if (!$id) {
                    return fail('客户信息插入失败');
                }
                $contactData = [];
                $contact['phone'] =  array_map(function ($code, $phone) {
                    return [$code, $phone];
                }, $phone_code, $contact['phone']);
                foreach ($contact as $k => $v) {
                    $contact_type = self::CONTACT_MAP[$k];
                    if (is_string($v)) $v = explode(',', $v);
                    foreach ($v as $e => $c_v) {

                        $contact_value = $c_v;
                        $contact_extra = '';
                        if (is_array($c_v)) {
                            $contact_extra = $c_v[0];
                            $contact_value = $c_v[1];
                        }
                        $contactData[] = [
                            'leads_id' => $id,
                            'contact_type' => $contact_type,
                            'contact_value' => $contact_value,
                            'contact_extra' => $contact_extra,
                            'created_at' => date("Y-m-d H:i:s", time()),
                        ];
                    }
                }
                db('crm_contacts')->insertAll($contactData);
                // 提交事务
                Db::commit();
                return success();
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                return fail($e->getMessage());
            }
        }


        // $xsSourceList = Db::table('crm_clues_source')->select();
        $khRankList = Db::table('crm_client_rank')->select();
        $khStatusList = Db::table('crm_client_status')->select();
        $xsAreaList = Db::table('crm_clues_area')->select();
        $xsHangyeList = Db::table('crm_client_hangye')->select();
        $this->assign('xsHangyeList', $xsHangyeList);
        // $this->assign('xsAreaList', $xsAreaList);
        $this->assign('khRankList', $khRankList);
        $this->assign('khStatusList', $khStatusList);

        //新增地区联动
        $countries = $this->getCountries();
        $this->assign('countries', $countries);

        return $this->fetch('client/add');
    }
    //编辑客户
    public function edit()
    {
        if (Request::isAjax()) {
            $data = Request::param();
            $data['ut_time'] = date("Y-m-d H:i:s", time());
            $contact = bulkTransfer($data, ['email', 'phone', 'whatsapp']);
            //当前录入查重
            foreach (['email' => '邮箱', 'phone' => '手机号码'] as $k => $v) {
                $duplicates = getDuplicates($contact[$k]);
                if ($duplicates) {
                    return fail($v . ':' . implode(',', $duplicates) . ' 重复录入');
                }
                //查询关联表crm_contacts数据表重复
                $contactExist = db('crm_contacts')->where(['is_delete' => 0])->where('leads_id', '<>', $data['id'])->whereIn('contact_value', $contact[$k])->find();
                if ($contactExist) {
                    return fail($contactExist['contact_value'] . '客户信息已存在');
                }
            }
            $contact['phone'] =  array_map(function ($code, $phone) {
                return [$code, $phone];
            }, $data['phone_code'], $contact['phone']);
            unset($data['phone_code']);
            Db::startTrans();
            try {
                //删除客户关联联系方式
                db('crm_contacts')->where(['leads_id' => $data['id']])->update(['is_delete' => 1]);
                foreach ($contact as $k => $v) {
                    $contact_type = self::CONTACT_MAP[$k];
                    if (is_string($v)) $v = explode(',', $v);
                    foreach ($v as  $c_v) {

                        $contact_value = $c_v;
                        $contact_extra = '';
                        if (is_array($c_v)) {
                            $contact_extra = $c_v[0];
                            $contact_value = $c_v[1];
                        }
                        $contactData = [
                            'leads_id' => $data['id'],
                            'contact_type' => $contact_type,
                            'contact_value' => $contact_value,
                            'contact_extra' => $contact_extra,
                            'is_delete' => 0,
                            'created_at' => date("Y-m-d H:i:s", time()),
                        ];
                        //插入或更新条数
                        $find = db('crm_contacts')->where(['is_delete' => 1, 'leads_id' => $data['id'], 'contact_type' => $contact_type, 'contact_value' => $contact_value])->find();
                        if ($find) {
                            db('crm_contacts')->where('id', $find['id'])->update($contactData);
                        } else {
                            db('crm_contacts')->insert($contactData);
                        }
                    }
                }
                //客户信息保存
                Db::table('crm_leads')->where(['id' => $data['id']])->where('status', 1)->update($data);
                // 提交事务
                Db::commit();
                return success();
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                return fail($e->getMessage());
            }
        }


        $result = Db::table('crm_leads')->where(['id' => Request::param('id')])->find();

        $this->assign('result', $result);

        // $xsSourceList = Db::table('crm_clues_source')->select();
        $khRankList = Db::table('crm_client_rank')->select();
        $khStatusList = Db::table('crm_client_status')->select();
        // $xsAreaList = Db::table('crm_clues_area')->select();
        $xsHangyeList = Db::table('crm_client_hangye')->select();
        $this->assign('xsHangyeList', $xsHangyeList);
        // $this->assign('xsAreaList', $xsAreaList);
        // $this -> assign('xsSourceList',$xsSourceList);
        $this->assign('khRankList', $khRankList);
        $this->assign('khStatusList', $khStatusList);
        //新增地区联动
        $countries = $this->getCountries();
        $this->assign('countries', $countries);
        //客户关联联系方式
        $select = db('crm_contacts')->where(['leads_id' => $result['id'], 'is_delete' => 0])->select();
        $con_map = array_flip(self::CONTACT_MAP);
        $contact = [];
        foreach ($con_map as $v) {
            $contact[$v] = [];
        }
        foreach ($select as $c) {
            $value = $c['contact_extra'] ? $c['contact_extra'] . '#' . $c['contact_value'] : $c['contact_value'];
            $contact[$con_map[$c['contact_type']]][] = $value;
        }
        // dd($contact);
        $this->assign('contact', $contact);
        return $this->fetch('client/edit');
    }
    //删除客户
    public function del()
    {
        $id = Request::param('id');
        $result = Db::table('crm_leads')->where('id', $id)->where('status', 1)->delete();
        if ($result) {
            Db::table('crm_contacts')->where('leads_id', $id)->delete();
            $msg = ['code' => 0, 'msg' => '删除成功！', 'data' => []];
            return json($msg);
        } else {
            $msg = ['code' => 500, 'msg' => '删除失败！', 'data' => []];
            return json($msg);
        }
    }

    //客户级别
    public function rankList()
    {
        if (request()->isPost()) {
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            $list = db('crm_client_rank')
                ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                ->toArray();
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }
        return $this->fetch();
    }
    //添加客户级别
    public function rankAdd()
    {
        if (request()->isPost()) {
            $data['rank_name'] = Request::param('rank_name');
            $data['add_time'] = time();
            $result = Db::table('crm_client_rank')->insert($data);
            if ($result) {
                $msg = ['code' => 0, 'msg' => '添加成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '添加失败！', 'data' => []];
                return json($msg);
            }
        }
        return $this->fetch('client/rank_list_add');
    }
    //编辑客户级别
    public function rankEdit()
    {
        if (Request::isAjax()) {
            $data  = Request::param();
            // 获取原状态
            $oldstatus = Db::table('crm_client_rank')->where(['id' => $data['id']])->find();
            $oldstatusname = $oldstatus['rank_name'];
            $ischange = false;
            if ($oldstatusname == $data['rank_name']) {
                $msg = ['code' => 500, 'msg' => '没有变化无需修改', 'data' => []];
                return json($msg);
            } else {
                $ischange = true;
            }

            $result = Db::table('crm_client_rank')->where(['id' => $data['id']])->update($data);
            if ($result) {
                // 状态修改后 客户编辑的原来状态都必须修改
                if ($ischange) {
                    // 所有的客户状态全部膝盖
                    $result2 = Db::table('crm_leads')->where(['kh_rank' => $oldstatusname])->update(['kh_rank' => $data['rank_name']]);
                }
                $msg = ['code' => 0, 'msg' => '编辑成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '编辑失败！', 'data' => []];
                return json($msg);
            }
        }

        $result = Db::table('crm_client_rank')->where(['id' => Request::param('id')])->find();
        $this->assign('result', $result);
        return $this->fetch('client/rank_list_edit');
    }
    //删除客户级别
    public function rankDel()
    {
        $id = Request::param('id');
        // 获取原状态
        $oldstatus = Db::table('crm_client_rank')->where(['id' => $data['id']])->find();
        $oldstatusname = $oldstatus['rank_name'];

        $result = Db::table('crm_client_rank')->where('id', $id)->delete();
        if ($result) {
            // 所有的客户状态全部膝盖
            $result2 = Db::table('crm_leads')->where(['kh_rank' => $oldstatusname])->update(['kh_rank' => '']);
            $msg = ['code' => 0, 'msg' => '删除成功！', 'data' => []];
            return json($msg);
        } else {
            $msg = ['code' => 500, 'msg' => '删除失败！', 'data' => []];
            return json($msg);
        }
    }


    //客户状态
    public function statusList()
    {
        if (request()->isPost()) {
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            $list = db('crm_client_status')
                ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                ->toArray();
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }
        return $this->fetch();
    }
    //添加客户状态
    public function statusAdd()
    {
        if (request()->isPost()) {
            $data['status_name'] = Request::param('status_name');
            $data['add_time'] = time();
            $result = Db::table('crm_client_status')->insert($data);
            if ($result) {
                $msg = ['code' => 0, 'msg' => '添加成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '添加失败！', 'data' => []];
                return json($msg);
            }
        }
        return $this->fetch('client/status_list_add');
    }
    //编辑客户状态
    public function statusEdit()
    {
        if (Request::isAjax()) {
            $data  = Request::param();
            // 获取原状态
            $oldstatus = Db::table('crm_client_status')->where(['id' => $data['id']])->find();
            $oldstatusname = $oldstatus['status_name'];
            $newstatusname = $data['status_name'];
            $ischange = false;
            if ($oldstatusname == $newstatusname) {
                $msg = ['code' => 500, 'msg' => '状态没有变化无需修改', 'data' => []];
                return json($msg);
            } else {
                $ischange = true;
            }
            $result = Db::table('crm_client_status')->where(['id' => $data['id']])->update($data);
            if ($result) {
                // 状态修改后 客户编辑的原来状态都必须修改
                if ($ischange) {
                    // 所有的客户状态全部膝盖
                    $result2 = Db::table('crm_leads')->where(['kh_status' => $oldstatusname])->update(['kh_status' => $newstatusname]);
                }
                $msg = ['code' => 0, 'msg' => '编辑成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '编辑失败！', 'data' => []];
                return json($msg);
            }
        }


        $result = Db::table('crm_client_status')->where(['id' => Request::param('id')])->find();
        $this->assign('result', $result);
        return $this->fetch('client/status_list_edit');
    }
    //删除客户状态
    public function statusDel()
    {
        $id = Request::param('id');
        // 获取原状态
        $oldstatus = Db::table('crm_client_status')->where(['id' => $data['id']])->find();
        $oldstatusname = $oldstatus['status_name'];
        // $ischange = false;
        // if ($oldstatusname == $data['status_name']) {
        //     $msg = ['code' => 500,'msg'=>'状态没有变化无需修改','data'=>[]];
        //     return json($msg);
        // }else{
        //     $ischange = true;
        // }
        $result = Db::table('crm_client_status')->where('id', $id)->delete();
        if ($result) {
            // 所有的客户状态全部膝盖
            $result2 = Db::table('crm_leads')->where(['kh_status' => $oldstatusname])->update(['kh_status' => '']);
            $msg = ['code' => 0, 'msg' => '删除成功！', 'data' => []];
            return json($msg);
        } else {
            $msg = ['code' => 500, 'msg' => '删除失败！', 'data' => []];
            return json($msg);
        }
    }


    //移入公海
    public function toMoveGh()
    {
        //1，获取提交的线索ID 【1,2,3,4,】
        $ids = Request::param('ids');
        $this->assign('ids', $ids);
        if (Request::isAjax()) {
            $pr_gh_type = Request::param('pr_gh_type');
            $idsArr = explode(",", $ids);


            $count = 0;
            foreach ($idsArr as $key => $value) {
                // $data['pr_user_bef'] = Db::table('crm_leads')->where(['id'=>$value])->field('pr_user')->find();
                // $data['pr_user'] = $username;
                // $data['id'] = $value;
                // $insertAll = Db::name('crm_leads')->update($data);
                $data['pr_gh_type'] = $pr_gh_type;
                $data['to_gh_time'] = date("Y-m-d H:i:s", time());
                $data['status'] = 2; //0-线索，1客户，2公海
                $data['id']  = $value;
                $result = Db::table('crm_leads')->where(['id' => $value])->update($data);
                if ($result) {
                    $count++;
                }
                // 添加日志记录
                // $this->addOperLog(
                //     $value,
                //     '移入公海',
                //     "移入 [{$pr_gh_type}] 公海池"
                // );
            }
            if ($count > 0) {
                $msg = ['code' => 0, 'msg' => $count . '个客户移入公海成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '转入公海失败！', 'data' => []];
                return json($msg);
            }
            // $data['pr_gh_type'] = Request::param('pr_gh_type');
            // $data['to_gh_time'] = date("Y-m-d H:i:s",time());
            // $data['status'] = 2;//0-线索，1客户，2公海
            // $data['id']  = Request::param('id');
            // $result = Db::table('crm_leads')->where(['id'=>$data['id']])->update($data);
            // if ($result){
            //     $msg = ['code' => 0,'msg'=>'移入公海成功！','data'=>[]];
            //     return json($msg);
            // }else{
            //     $msg = ['code' => 500,'msg'=>'抱歉，移入公海失败！','data'=>[]];
            //     return json($msg);
            // }
        }


        $libTypeList = Db::table('crm_liberum_type')->select();

        $this->assign('libTypeList', $libTypeList);

        // $result = Db::table('crm_leads') ->where(['id' => Request::param('id')])->find();
        // $this -> assign('result',$result);
        return $this->fetch('client/move_gh');
    }
    //客户搜索
    public function clientSearch()
    {
        $page = input('page') ? input('page') : 1;
        $limit = input('limit') ? input('limit') : config('pageSize');
        $keyword = Request::param('keyword');
        $list = model('client')->getClientSearchList($page, $limit, $keyword);
        return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
    }
    //（我的客户）搜索
    public function personClientSearch()
    {
        $page = input('page') ? input('page') : 1;
        $limit = input('limit') ? input('limit') : config('pageSize');
        $keyword = Request::param('keyword');
        $list = model('client')->getPersonClientSearchList($page, $limit, $keyword);
        return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
    }
    //（我的客户）搜索
    public function chengjiaoClientSearch()
    {
        $page = input('page') ? input('page') : 1;
        $limit = input('limit') ? input('limit') : config('pageSize');
        $keyword = Request::param('keyword');
        $list = model('client')->getChengjiaoClientSearchList($page, $limit, $keyword);
        return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
    }
    //写跟进
    public function dialogue()
    {
        $result = Db::table('crm_leads')->where(['id' => Request::param('id')])->find();

        $result['comment'] = Db::table('crm_comment')->alias('com')->join('admin adm', 'com.user_id = adm.admin_id')->where(['leads_id' => Request::param('id')])->field('com.*,adm.username,adm.avatar')->select();
        foreach ($result['comment'] as $k => $v) {
            $result['comment'][$k]['reply'] = Db::table('crm_reply')->where(['comment_id' => $v['id']])->select();
        }
        $result['contacts'] = Db::table('crm_contacts')->where('is_delete', 0)->where(['leads_id' => Request::param('id')])->select();
        $contactGroup = $this->formatContact($result['contacts'])[$result['id']];
        $result['contacts'] = $this->getContactType($contactGroup);


        $cid = Session::get('aid'); //获取当前登录账号
        $curname = Session::get('username'); //获取当前登录账号
        $curname = Session::get('username'); //获取当前登录账号



        //$this ->assign('cid',$cid);  //获取当前登录账号$data['id']
        $group_id = Db::table('admin')->where(['admin_id' => $cid])->field('group_id')->find();


        $this->assign('group_id', $group_id['group_id']);  //获取当前登录权限组账号

        $this->assign('curname', $curname);  //获取当前登录账号

        $this->assign('result', $result);
        //$this ->assign('result1',integer($result['id']));  //跟进上一个  下一个 获取当前id。
        return $this->fetch('client/dialogue');
    }

    //评论
    public function comment()
    {

        $data['leads_id'] = Request::param('leads_id');
        $data['user_id'] = Session::get('aid');
        $data['reply_msg'] = Request::param('reply_msg');
        $data['create_date'] = time();

        //更新跟进记录
        $genjin['last_up_records'] = $data['reply_msg'];
        $genjin['last_up_time'] = date("Y-m-d H:i:s", $data['create_date']);
        $genjin['ut_time'] = date("Y-m-d H:i:s", time());

        Db::table('crm_leads')->where(['id' => $data['leads_id']])->update($genjin);

        $result = Db::table('crm_comment')->insert($data);
        $data['create_date'] = date("Y年m月d日 H:i", $data['create_date']);

        if ($result) {
            return json(['code' => 0, 'msg' => '评论成功！', 'data' => $data]);
        } else {
            return json(['code' => 1, 'msg' => '评论失败！']);
        }
    }

    //回复
    public function reply()
    {

        $data['comment_id'] = Request::param('cid');
        $data['from_user_id'] = Session::get('user.id');
        $data['to_user_id'] = Request::param('to_uid');
        $data['reply_msg'] = Request::param('reply_msg');
        $data['create_date'] = time();

        $result = Db::table('crm_reply')->insert($data);
        $data['create_date'] = date("Y年m月d日 H:i", $data['create_date']);
        if ($result) {
            return json(['code' => 0, 'msg' => '回复成功！', 'data' => $data]);
        } else {

            return json(['code' => 1, 'msg' => '回复失败！']);
        }
    }


    //客户转移，变更负责人
    public function alterPrUser()
    {
        //1，获取提交的线索ID 【1,2,3,4,】
        $ids = Request::param('ids');
        $this->assign('ids', $ids);


        //查询所有管理员（去除admin）
        $adminResult = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $this->assign('adminResult', $adminResult);

        if (Request::isAjax()) {
            $username = Request::param('username');
            $idsArr = explode(",", $ids);


            $count = 0;
            foreach ($idsArr as $key => $value) {
                $data['pr_user_bef'] = Db::table('crm_leads')->where(['id' => $value])->field('pr_user')->find();
                $data['pr_user'] = $username;
                $data['id'] = $value;
                $insertAll = Db::name('crm_leads')->update($data);
                if ($insertAll) {
                    $count++;
                }
            }




            if ($count > 0) {
                $msg = ['code' => 0, 'msg' => '转移' . $count . '个客户成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '转移失败！', 'data' => []];
                return json($msg);
            }
        }

        return $this->fetch('client/alter_pr_user');
    }


    //客户转移，变更负责人(个人)
    public function alterPrUserPri()
    {
        //1，获取提交的线索ID 【1,2,3,4,】
        $ids = Request::param('ids');
        $this->assign('ids', $ids);
        //客户信息
        $clientList = Db::name('crm_leads')->where('id', 'in', $ids)->field('id,kh_name')->select();
        $clientName = implode(',', array_column($clientList, 'kh_name'));
        $this->assign('client_name', $clientName);

        //查询所有管理员（去除admin）
        $adminResult = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $this->assign('adminResult', $adminResult);

        if (Request::isAjax()) {
            $username = Request::param('username');
            $idsArr = explode(",", $ids);


            $count = 0;
            foreach ($idsArr as $key => $value) {
                $data['pr_user_bef'] = Db::table('crm_leads')->where(['id' => $value])->field('pr_user')->find();
                $data['pr_user'] = $username;
                $data['id'] = $value;
                $insertAll = Db::name('crm_leads')->update($data);
                if ($insertAll) {
                    $count++;
                }
                // 添加日志记录
                // $this->addOperLog(
                //     $value,
                //     '转移负责人',
                //     "从 [{$data['pr_user_bef']['pr_user']}] 转移给 [{$username}]"
                // );
            }




            if ($count > 0) {
                $msg = ['code' => 0, 'msg' => '转移' . $count . '个客户成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '转移失败！', 'data' => []];
                return json($msg);
            }
        }

        return $this->fetch('personclient/alter_pr_user');
    }

    //客户行业
    public function hangyeList()
    {
        if (request()->isPost()) {
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            $list = db('crm_client_hangye')
                ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                ->toArray();
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }
        return $this->fetch();
    }
    //添加客户级别
    public function hangyeAdd()
    {
        if (request()->isPost()) {
            $data['hy_name'] = Request::param('hy_name');
            $data['add_time'] = time();
            $result = Db::table('crm_client_hangye')->insert($data);
            if ($result) {
                $msg = ['code' => 0, 'msg' => '添加成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '添加失败！', 'data' => []];
                return json($msg);
            }
        }
        return $this->fetch('client/hangye_list_add');
    }
    //编辑客户级别
    public function hangyeEdit()
    {
        if (Request::isAjax()) {
            $data  = Request::param();
            // 获取原状态
            $oldstatus = Db::table('crm_client_hangye')->where(['id' => $data['id']])->find();
            $oldstatusname = $oldstatus['hy_name'];
            $ischange = false;
            if ($oldstatusname == $data['hy_name']) {
                $msg = ['code' => 500, 'msg' => '没有变化无需修改', 'data' => []];
                return json($msg);
            } else {
                $ischange = true;
            }

            $result = Db::table('crm_client_hangye')->where(['id' => $data['id']])->update($data);
            if ($result) {
                // 状态修改后 客户编辑的原来状态都必须修改
                if ($ischange) {
                    // 所有的客户状态全部膝盖
                    $result2 = Db::table('crm_leads')->where(['kh_hangye' => $oldstatusname])->update(['kh_hangye' => $data['hy_name']]);
                }
                $msg = ['code' => 0, 'msg' => '编辑成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '编辑失败！', 'data' => []];
                return json($msg);
            }
        }

        $result = Db::table('crm_client_hangye')->where(['id' => Request::param('id')])->find();
        $this->assign('result', $result);
        return $this->fetch('client/hangye_list_edit');
    }
    //删除客户级别
    public function hangyeDel()
    {
        $id = Request::param('id');
        // 获取原状态
        $oldstatus = Db::table('crm_client_hangye')->where(['id' => $data['id']])->find();
        $oldstatusname = $oldstatus['hy_name'];

        $result = Db::table('crm_client_hangye')->where('id', $id)->delete();
        if ($result) {
            // 所有的客户状态全部膝盖
            $result2 = Db::table('crm_leads')->where(['kh_hangye' => $oldstatusname])->update(['kh_hangye' => '']);
            $msg = ['code' => 0, 'msg' => '删除成功！', 'data' => []];
            return json($msg);
        } else {
            $msg = ['code' => 500, 'msg' => '删除失败！', 'data' => []];
            return json($msg);
        }
    }

    //新增各国区号
    public function getCountries()
    {
        $countries = cache('countries');
        //清除缓存
        // cache('countries',null);
        if ($countries) {
            return $countries;
        }
        $list = Db::table('countries')->field('phone_code,english_name,chinese_name')->select();
        foreach ($list as $key => $value) {
            $_key = $value['english_name'] . '(' . $value['chinese_name'] . ')';
            $countries[$_key] =  $value['phone_code'];
        }
        cache('countries', $countries);
        return $countries;
    }

    //客户成交
    public function chengjiao(){
        $ids = Request::param('ids');
        if(is_string($ids))$ids = explode(',', $ids);
        $count = 0;
        foreach ($ids as $key => $value) {
            $data['issuccess'] = 1;
            $data['id'] = $value;
            $insertAll = Db::name('crm_leads')->update($data);
            if ($insertAll) {
                $count++;
            }
        }
        if ($count > 0) {
            $msg = ['code' => 0, 'msg' => '成交' . $count . '个客户成功！', 'data' => []];
            return json($msg);
        } else {
            $msg = ['code' => 500, 'msg' => '成交失败！', 'data' => []];
            return json($msg);
        }
    }


}
