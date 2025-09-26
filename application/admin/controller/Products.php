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
        $category_list = $this->getCategoryList();
        $this->assign('category_list', $category_list);
        return $this->fetch();
    }
    public function productSearch()
    {
        $current_admin = Admin::getMyInfo();
        $product_name = Request::param('product_name');
        $pageSize = Request::param('limit', 10);
        $page = Request::param('page', 1);
        $query = Db::name('crm_products p')->leftJoin('crm_product_category c', 'p.category_id = c.id');
        if (!empty($product_name)) {
            $query->where('p.product_name', 'like', '%' . $product_name . '%');
        }
        $category_id = Request::param('category_id');
        if (!empty($category_id)) {
            $query->where('p.category_id', $category_id);
        }
        $list = $query->field('p.*, c.category_name')->where([$this->getOrgWhere($current_admin['org'], 'p')])->order('p.id desc')->paginate([
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
                $category_id = (int)Request::param('category_id');
                $current_admin = Admin::getMyInfo();
                $data['org'] = $current_admin['org'];
                $data['product_name'] = $product_name;
                $data['category_id'] = $category_id;
                $res = Db::name('crm_products')->insert($data);
                return $this->result([], 200, '操作成功');
            } else {
                return $this->result([], 500, '商品已存在');
            }
        }
        $category_list = $this->getCategoryList();
        $this->assign('category_list', $category_list);
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
            $current_admin = Admin::getMyInfo();
            $exists = Db::name('crm_products')
                ->where('product_name', $product_name)
                ->where('id', '<>', $id)
                ->where([$this->getOrgWhere($current_admin['org'])])
                ->find();
            if ($exists) {
                return $this->result([], 500, '商品已存在');
            }
            $category_id = (int)Request::param('category_id');
            $result = Db::name('crm_products')->where('id', $id)->update(['product_name' => $product_name, 'category_id' => $category_id]);
            if ($result) {
                return $this->result([], 200, '操作成功');
            } else {
                return $this->result([], 500, '操作失败');
            }
        }
        $category_list = $this->getCategoryList();
        $this->assign('category_list', $category_list);
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
            $timebucket = !empty($keyword['timebucket']) ? $keyword['timebucket'] : $keyword['at_time'];
            $l_where[] = $this->getClientimeWhere($timebucket);
            $o_where[] = $this->buildTimeWhere($timebucket, 'order_time');
            //产品数据
            $oper_prod = Db::table('crm_leads')->join('admin', 'crm_leads.oper_user = admin.username')->where($where)->where($l_where)->where('product_name', '<>', '')->group('product_name')->field('product_name,count(product_name) as count')->order('count', 'desc')->select();
            $order_prod = Db::table('crm_client_order')->join('admin', 'crm_client_order.oper_user = admin.username')->where($where)->where($o_where)->where('product_name', '<>', '')->group('product_name')->field('product_name,count(product_name) as count')->order('count', 'desc')->select();
            $data['product_data']['oper_prod'] = $oper_prod;
            $data['product_data']['order_prod'] = $order_prod;
            $data['product_data'] = array_merge($data['product_data'], $this->productCategoryCount($current_admin['org'], $timebucket));
            $data['product_data'] = array_merge($data['product_data'], $this->productCountryCount($current_admin['org'], $timebucket));
            $this->assign('data', $data);
            return $this->fetch('main_content');
        }
        $this->assign('data', $data);
        return $this->fetch();
    }


    //统计数据时数据库同一组织下不能存在相同名称的产品名称或者分类名称，否则统计数据会不准
    //产品按分类统计
    public function productCategoryCount($org, $timebucket)
    {
        //询盘产品按分类统计
        // select c.category_name,count(*) from crm_products as p join crm_product_category as  c on p.category_id = c.id   join crm_leads as l on l.product_name = p.product_name where   p.org like '%3s%' GROUP BY category_name
        $oper_prod_category = Db::table('crm_leads l')
            ->join('admin a', 'l.oper_user = a.username')
            ->join('crm_products p', 'l.product_name = p.product_name')
            ->join('crm_product_category c', 'p.category_id = c.id')
            ->where([$this->getOrgWhere($org, 'p')])
            ->where([$this->getOrgWhere($org, 'a')])
            ->where('a.is_open', '=', 1)
            ->where('l.product_name', '<>', '')
            ->where('l.status', 1)
            ->where([$this->getClientimeWhere($timebucket, 'l')])
            ->where('c.category_name', '<>', '')
            ->group('c.category_name')
            ->field('c.category_name,count(*) as count')
            ->order('c.category_name', 'asc')
            ->order('count', 'desc')
            ->select();
        //订单产品按分类统计
        $order_prod_category = Db::table('crm_client_order o')
            ->join('admin a', 'o.oper_user = a.username')
            ->join('crm_products p', 'o.product_name = p.product_name')
            ->join('crm_product_category c', 'p.category_id = c.id')
            ->where([$this->getOrgWhere($org, 'p')])
            ->where([$this->getOrgWhere($org, 'a')])
            ->where('a.is_open', '=', 1)
            ->where('o.product_name', '<>', '')
            ->where([$this->buildTimeWhere($timebucket, 'o.order_time')])
            ->where('c.category_name', '<>', '')
            ->group('c.category_name')
            ->field('c.category_name,count(*) as count')
            ->order('c.category_name', 'asc')
            ->order('count', 'desc')
            ->select();
        return ['oper_prod_category' => $oper_prod_category, 'order_prod_category' => $order_prod_category];
    }

    //产品按国家统计
    public function productCountryCount($org, $timebucket)
    {
        //询盘产品按国家统计
        $oper_prod_country = Db::table('crm_leads l')
            ->join('admin a', 'l.oper_user = a.username')
            ->join('crm_products p', 'l.product_name = p.product_name')
            ->where([$this->getOrgWhere($org, 'p')])
            ->where([$this->getOrgWhere($org, 'a')])
            ->where('l.status', 1)
            ->where([$this->getClientimeWhere($timebucket, 'l')])
            ->where('a.is_open', '=', 1)
            ->where('l.product_name', '<>', '')
            ->where('l.xs_area', '<>', '')
            ->group('l.product_name,l.xs_area')
            ->field('l.product_name,l.xs_area,count(*) as count')
            ->order('l.product_name', 'asc')
            ->order('count', 'desc')
            ->select();

        //询盘产品分类按国家统计
        // select l.product_name,xs_area,count(*) from crm_products as p join crm_product_category as  c on p.category_id = c.id   join crm_leads as l on l.product_name = p.product_name where   p.org like '%3s%' and l.xs_area != '' GROUP BY xs_area,l.product_name 
        $oper_prod_category_country = Db::table('crm_leads l')
            ->join('admin a', 'l.oper_user = a.username')
            ->join('crm_products p', 'l.product_name = p.product_name')
            ->join('crm_product_category c', 'p.category_id = c.id')
            ->where([$this->getOrgWhere($org, 'p')])
            ->where([$this->getOrgWhere($org, 'a')])
            ->where('l.status', 1)
            ->where([$this->getClientimeWhere($timebucket, 'l')])
            ->where('a.is_open', '=', 1)
            ->where('l.product_name', '<>', '')
            ->where('c.category_name', '<>', '')
            ->where('l.xs_area', '<>', '')
            ->group('c.category_name,l.xs_area')
            ->field('c.category_name,l.xs_area,count(*) as count')
            ->order('c.category_name', 'asc')
            ->order('count', 'desc')
            ->select();

        //订单产品按国家统计
        $order_prod_country = Db::table('crm_client_order o')
            ->join('admin a', 'o.oper_user = a.username')
            ->join('crm_products p', 'o.product_name = p.product_name')
            ->where([$this->getOrgWhere($org, 'p')])
            ->where([$this->getOrgWhere($org, 'a')])
            ->where('a.is_open', '=', 1)
            ->where([$this->buildTimeWhere($timebucket, 'o.order_time')])
            ->where('o.product_name', '<>', '')
            ->where('o.country', '<>', '')
            ->group('o.product_name,o.country')
            ->field('o.product_name,o.country,count(*) as count')
            ->order('o.product_name', 'asc')
            ->order('count', 'desc')
            ->select();
        //订单产品分类按国家统计
        $order_prod_category_country = Db::table('crm_client_order o')
            ->join('admin a', 'o.oper_user = a.username')
            ->join('crm_products p', 'o.product_name = p.product_name')
            ->join('crm_product_category c', 'p.category_id = c.id')
            ->where([$this->getOrgWhere($org, 'p')])
            ->where([$this->getOrgWhere($org, 'a')])
            ->where('a.is_open', '=', 1)
            ->where([$this->buildTimeWhere($timebucket, 'o.order_time')])
            ->where('o.product_name', '<>', '')
            ->where('c.category_name', '<>', '')
            ->where('o.country', '<>', '')
            ->group('c.category_name,o.country')
            ->field('c.category_name,o.country,count(*) as count')
            ->order('c.category_name', 'asc')
            ->order('count', 'desc')
            ->select();
        return [
            'oper_prod_country' => $oper_prod_country,
            'oper_prod_category_country' => $oper_prod_category_country,
            'order_prod_country' => $order_prod_country,
            'order_prod_category_country' => $order_prod_category_country,
        ];
    }

}
