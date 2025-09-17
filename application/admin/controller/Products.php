<?php

namespace app\admin\controller;

use think\Db;
use think\facade\Request;
use think\facade\Session;
use app\admin\model\Admin;

class Products extends Common
{
    public function index()
    {
        if (request()->isPost()) {
            return $this->productSearch();
        }
        return $this->fetch();
    }
    public function productSearch()
    {
        $current_admin = Admin::getMyInfo();
        $product_name = Request::param('product_name');
        $pageSize = Request::param('limit', 10);
        $page = Request::param('page', 1);
        $query = Db::name('crm_products');
        if (!empty($product_name)) {
            $query->where('product_name', 'like', '%' . $product_name . '%');
        }
        $list = $query->where([$this->getOrgWhere($current_admin['org'])])->order('id desc')->paginate([
            'list_rows' => $pageSize,
            'page' => $page
        ])->toArray();
        return json([
            'code'  => 0,
            'msg'   => '获取成功!',
            'data'  => $list['data'],
            'count' => $list['total'],
            'rel'   => 1
        ]);
    }

    public function add()
    {
        if (request()->isPost()) {
            //新增商品
            $product_name = Request::param('product_name');
            if (empty($product_name)) {
                return $this->result([], 500, '商品名称不能为空');
            }
            $product = $this->checkProduct($product_name);
            if (!$product) {
                $this->addProduct($product_name);
                return $this->result([], 200, '操作成功');
            } else {
                return $this->result([], 500, '商品已存在');
            }
        }
        return $this->fetch();
    }


    public function edit()
    {
        $id = Request::param('id');
        if (empty($id)) {
            return $this->result([], 500, '参数错误');
        }
        $result = Db::name('crm_products')->where('id', $id)->find();
        if (empty($result)) {
            return $this->result([], 500, '参数错误');
        }
        if (request()->isPost()) {
            $product_name = Request::param('product_name');
            if (empty($product_name)) {
                return $this->result([], 500, '商品名称不能为空');
            }
            $result = Db::name('crm_products')->where('id', $id)->update(['product_name' => $product_name]);
            return $this->result([], 200, '操作成功');
        }

        $this->assign('result', $result);
        return $this->fetch();
    }

    public function del()
    {
        $id = Request::param('id');
        $result = Db::name('crm_products')->where('id', $id)->delete();
        if ($result) {
            return $this->result([], 200, '删除成功');
        } else {
            return $this->result([], 500, '删除失败');
        }
    }

    public function main()
    {
        $current_admin = Admin::getMyInfo();
        $data['org'] = trim($current_admin['org'], $this->org_fgx);
        if (request()->isPost()) {
            $keyword  = Request::param('keyword') ?? [];
            $where = [$this->getOrgWhere($current_admin['org']), ['is_open', '=', 1],];
            $l_where = [['status', '=', 1]];
            $o_where = [];
            if (!empty($keyword['timebucket'])) {
                $l_where[] = $this->getClientimeWhere($keyword['timebucket']);
                $o_where[] = $this->buildTimeWhere($keyword['timebucket'], 'order_time');
            }
            if (!empty($keyword['at_time'])) {
                $l_where[] = $this->getClientimeWhere($keyword['at_time']);
                $o_where[] = $this->buildTimeWhere($keyword['at_time'], 'order_time');
            }
            //产品数据
            $oper_prod = Db::table('crm_leads')->join('admin', 'crm_leads.pr_user = admin.username')->where($where)->where($l_where)->where('product_name', '<>', '')->group('product_name')->field('product_name,count(product_name) as count')->order('count', 'desc')->select();
            $order_prod = Db::table('crm_client_order')->join('admin', 'crm_client_order.oper_user = admin.username')->where($where)->where($o_where)->where('product_name', '<>', '')->group('product_name')->field('product_name,count(product_name) as count')->order('count', 'desc')->select();
            $data['product_data']['oper_prod'] = $oper_prod;
            $data['product_data']['order_prod'] = $order_prod;

            return json([
                'code' => 0,
                'msg'  => '获取成功',
                'data' => $data,
            ]);
        }
        $this->assign('data', $data);
        return $this->fetch();
    }
}
