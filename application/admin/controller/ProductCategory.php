<?php

namespace app\admin\controller;

use think\Db;
use think\facade\Request;
use app\admin\model\Admin;

class ProductCategory extends Common
{
    public function index()
    {
        if (request()->isPost()) {
            return $this->categorySearch();
        }
        return $this->fetch();
    }
    
    public function categorySearch()
    {
        $current_admin = Admin::getMyInfo();
        $category_name = Request::param('category_name');
        $pageSize = Request::param('limit', 10);
        $page = Request::param('page', 1);
        $query = Db::name('crm_product_category');
        
        if (!empty($category_name)) {
            $query->where('category_name', 'like', '%' . $category_name . '%');
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
            $category_name = Request::param('category_name');
            if (empty($category_name)) {
                return $this->result([], 500, '分类名称不能为空');
            }
            
            $current_admin = Admin::getMyInfo();
            $exists = Db::name('crm_product_category')
                ->where('category_name', $category_name)
                ->where([$this->getOrgWhere($current_admin['org'])])
                ->find();
                
            if (!$exists) {
                $data = [
                    'category_name' => $category_name,
                    'org' => $current_admin['org']
                ];
                Db::name('crm_product_category')->insert($data);
                return $this->result([], 200, '操作成功');
            } else {
                return $this->result([], 500, '分类已存在');
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
        
        $result = Db::name('crm_product_category')->where('id', $id)->find();
        if (empty($result)) {
            return $this->result([], 500, '参数错误');
        }
        
        if (request()->isPost()) {
            $category_name = Request::param('category_name');
            if (empty($category_name)) {
                return $this->result([], 500, '分类名称不能为空');
            }
            
            $current_admin = Admin::getMyInfo();
            $exists = Db::name('crm_product_category')
                ->where('category_name', $category_name)
                ->where('id', '<>', $id)
                ->where([$this->getOrgWhere($current_admin['org'])])
                ->find();
                
            if (!$exists) {
                Db::name('crm_product_category')->where('id', $id)->update(['category_name' => $category_name]);
                return $this->result([], 200, '操作成功');
            } else {
                return $this->result([], 500, '分类名称已存在');
            }
        }

        $this->assign('result', $result);
        return $this->fetch();
    }
    
    public function del()
    {
        $id = Request::param('id');
        
        // 检查是否有产品使用了该分类
        $has_products = Db::name('crm_products')->where('category_id', $id)->find();
        if ($has_products) {
            return $this->result([], 500, '该分类下有产品，无法删除');
        }
        
        $result = Db::name('crm_product_category')->where('id', $id)->delete();
        if ($result) {
            return $this->result([], 200, '删除成功');
        } else {
            return $this->result([], 500, '删除失败');
        }
    }
    

}