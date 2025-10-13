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
            $category_id = (int)Request::param('category_id');

            if (empty($product_name)) {
                return $this->result([], 500, '商品名称不能为空');
            }
            $product = $this->checkProductCategory($product_name, $category_id);
            if (!$product) {
                $current_admin = Admin::getMyInfo();
                $data['org'] = $current_admin['org'];
                $data['product_name'] = $product_name;
                $data['category_id'] = $category_id;
                $data['add_time'] = time();
                $data['edit_time'] = time();
                $data['submit_person'] = $current_admin['username'];
                $res = Db::name('crm_products')->insert($data);
                return $this->result([], 200, '操作成功');
            } else {
                return $this->result([], 500, '商品已存在');
            }
        }
        $category_rows = $this->getCategoryList();
        $category_list = array_map(function ($row) {
            return [
                'id'   => (int)$row['id'],
                'name' => (string)$row['category_name'],
            ];
        }, $category_rows);
        $category_list = json_encode($category_list, JSON_UNESCAPED_UNICODE);
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
            $category_id = (int)Request::param('category_id');
            if (empty($product_name)) {
                return $this->result([], 500, '商品名称不能为空');
            }
            $current_admin = Admin::getMyInfo();
            $exists = Db::name('crm_products')
                ->where('product_name', $product_name)
                ->where('category_id', '=', $category_id)
                ->where([$this->getOrgWhere($current_admin['org'])])
                ->where('id', '<>', $id)
                ->find();
            if ($exists) {
                return $this->result([], 500, '商品已存在');
            }
            $category_id = (int)Request::param('category_id');
            $current_time = time();
            $result = Db::name('crm_products')->where('id', $id)->update(['product_name' => $product_name, 'category_id' => $category_id, 'edit_time' => $current_time]);
            if ($result) {
                return $this->result([], 200, '操作成功');
            } else {
                return $this->result([], 500, '操作失败');
            }
        }
        // 非提交：准备下拉数据为 {id,name} + 默认ID
        $defaultCategoryId = (int)$result['category_id'];
        $category_rows = $this->getCategoryList();
        $category_list = array_map(function ($row) {
            return [
                'id'   => (int)$row['id'],
                'name' => (string)$row['category_name'],
            ];
        }, $category_rows);
        $category_list = json_encode($category_list, JSON_UNESCAPED_UNICODE);
        $this->assign('category_list', $category_list);
        $this->assign('default_category_id', $defaultCategoryId);
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


    // 批量删除
    public function batchDel()
    {
        if (!request()->isPost()) {
            return json(['code' => -200, 'msg' => '非法请求']);
        }
        // ids 既可为数组也可为逗号串
        $ids = input('post.ids/a', []); // /a 过滤为数组
        if (empty($ids)) {
            return json(['code' => -200, 'msg' => '未选择任何记录']);
        }

        // 建议再做一次超管校验（双保险）
        // if (session('aid') != 1) return json(['code'=>-200,'msg'=>'无权限']);

        try {
            // 方式1：模型批量删除
            // $res = InquirySourceModel::destroy($ids);
            // 方式2：DB 批量删除（更直观）
            $res = \think\Db::name('crm_products')->whereIn('id', $ids)->delete();
            if ($res > 0) {
                return json(['code' => 0, 'msg' => '删除成功', 'data' => ['count' => $res]]);
            }
            return json(['code' => -200, 'msg' => '删除失败或记录不存在']);
        } catch (\Throwable $e) {
            return json(['code' => -200, 'msg' => '删除异常：' . $e->getMessage()]);
        }
    }


    // 导入页（弹窗）
    public function import()
    {
        return $this->fetch();  // 渲染 view/products/import.html
    }


    // 执行导入
    public function importDo()
    {
        if (!request()->isPost()) {
            return json(['code' => -200, 'msg' => '非法请求']);
        }

        $file = request()->file('file');
        if (!$file) {
            return json(['code' => -200, 'msg' => '请上传Excel或CSV文件']);
        }

        // 获取上传临时信息
        $info = $file->getInfo(); // ['name'=>..., 'tmp_name'=>...]
        $tmpPath = $info['tmp_name'];
        $ext = strtolower(pathinfo($info['name'], PATHINFO_EXTENSION));

        // 解析为二维数组 rows：每行是 [产品名称, 供应商名称, 供应商ID(可选), 状态(可选)]
        $rows = [];
        try {
            if ($ext === 'csv') {
                $fp = fopen($tmpPath, 'r');
                if (!$fp) throw new \Exception('CSV文件读取失败');

                // 尝试去除UTF-8 BOM
                $first = fgets($fp);
                if ($first === false) {
                    fclose($fp);
                    throw new \Exception('CSV空文件');
                }
                if (substr($first, 0, 3) === "\xEF\xBB\xBF") $first = substr($first, 3);
                $buffer = $first . stream_get_contents($fp);
                fclose($fp);

                $tmp = tmpfile();
                fwrite($tmp, $buffer);
                fseek($tmp, 0);

                $lineNo = 0;
                while (($data = fgetcsv($tmp)) !== false) {
                    $lineNo++;
                    // 识别并跳过表头（含“产品”或“供应”字样即视为表头）
                    $joined = implode('', $data);
                    if ($lineNo === 1 && (mb_strpos($joined, '产品') !== false || mb_strpos($joined, '供应') !== false)) {
                        continue;
                    }
                    $data = array_pad($data, 4, '');
                    $rows[] = [
                        trim((string)$data[0]), // product_name
                        trim((string)$data[1]), // category_name
                        trim((string)$data[2]), // category_id (optional)
                        trim((string)$data[3]), // status (optional)
                    ];
                }
                fclose($tmp);
            } else {
                // xlsx/xls：存在 PhpSpreadsheet 时使用；否则提示改用 CSV
                if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                    return json([
                        'code' => -200,
                        'msg' => '服务器未安装 phpoffice/phpspreadsheet，暂不支持 .xlsx/.xls，请使用 CSV 导入或安装依赖后再试'
                    ]);
                }
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
                $sheet      = $spreadsheet->getActiveSheet();
                $highestRow = $sheet->getHighestRow();

                // 判断首行是否表头（含“产品”或“供应”）
                $firstRow = [
                    (string)$sheet->getCell('A1')->getValue(),
                    (string)$sheet->getCell('B1')->getValue(),
                    (string)$sheet->getCell('C1')->getValue(),
                    (string)$sheet->getCell('D1')->getValue(),
                ];
                $hasHeader = (mb_strpos(implode('', $firstRow), '产品') !== false) || (mb_strpos(implode('', $firstRow), '供应') !== false);
                $start = $hasHeader ? 2 : 1;

                for ($r = $start; $r <= $highestRow; $r++) {
                    $a = trim((string)$sheet->getCell("A{$r}")->getValue());
                    $b = trim((string)$sheet->getCell("B{$r}")->getValue());
                    $c = trim((string)$sheet->getCell("C{$r}")->getValue());
                    $d = trim((string)$sheet->getCell("D{$r}")->getValue());
                    if ($a === '' && $b === '' && $c === '' && $d === '') continue;
                    $rows[] = [$a, $b, $c, $d];
                }
            }
        } catch (\Throwable $e) {
            return json(['code' => -200, 'msg' => '解析失败：' . $e->getMessage()]);
        }

        if (empty($rows)) {
            return json(['code' => -200, 'msg' => '没有可导入的数据']);
        }

        // 入库
        $now = time();
        $inserted = 0;
        $skippedExist = 0;
        $skippedEmpty = 0;
        $createdCats = 0;
        $seenKeys = []; // 防止同一批次文件内重复

        $current_admin = \app\admin\model\Admin::getMyInfo();
        $org = $current_admin['org'] ?? '';
        $user = $current_admin['username'] ?? '';

        foreach ($rows as $row) {
            list($product_name, $category_name, $category_id_raw, $status_raw) = $row;

            if ($product_name === '' && $category_name === '' && $category_id_raw === '') {
                $skippedEmpty++;
                continue;
            }

            // 解析 status（可选，默认0）
            $status = ($status_raw === '' ? 0 : (int)$status_raw);

            // 解析 category_id：优先C列ID，否则用B列名称在当前组织下查找/创建
            $category_id = 0;
            if ($category_id_raw !== '' && is_numeric($category_id_raw)) {
                $category_id = (int)$category_id_raw;
            } elseif ($category_name !== '') {
                $cat = \think\Db::name('crm_product_category')
                    ->where('category_name', $category_name)
                    ->where([$this->getOrgWhere($org)])
                    ->find();

                if (!$cat) {
                    // 自动创建该供应商（分类）
                    $cid = \think\Db::name('crm_product_category')->insertGetId([
                        'category_name' => $category_name,
                        'org'           => $org,
                        'add_time'      => $now,
                        'edit_time'     => $now,
                        'submit_person' => $user,
                    ]);
                    if ($cid) {
                        $category_id = (int)$cid;
                        $createdCats++;
                    }
                } else {
                    $category_id = (int)$cat['id'];
                }
            }

            // 产品名必填
            if ($product_name === '') {
                $skippedEmpty++;
                continue;
            }
            // 仍未拿到供应商ID，跳过
            if ($category_id <= 0) {
                $skippedEmpty++;
                continue;
            }

            // 组合唯一键（同组织+同供应商+同产品名 视为同一条）
            $key = md5($org . '|' . $category_id . '|' . $product_name);
            if (isset($seenKeys[$key])) {
                $skippedExist++;
                continue;
            }

            // DB去重：同 org 范围 + category_id + product_name
            $exists = \think\Db::name('crm_products')
                ->where('product_name', $product_name)
                ->where('category_id', $category_id)
                ->where([$this->getOrgWhere($org)])
                ->find();
            if ($exists) {
                $skippedExist++;
                continue;
            }

            // 插入
            $ok = \think\Db::name('crm_products')->insert([
                'product_name'  => $product_name,
                'org'           => $org,
                'category_id'   => $category_id,
                'status'        => $status,
                'add_time'      => $now,
                'edit_time'     => $now,
                'submit_person' => $user,
            ]);
            if ($ok) {
                $inserted++;
                $seenKeys[$key] = 1;
            }
        }

        return json([
            'code' => 0,
            'msg' => '导入完成',
            'data' => [
                'inserted' => $inserted,
                'skipped_exist' => $skippedExist,
                'skipped_empty' => $skippedEmpty,
                'created_cats' => $createdCats,
            ],
        ]);
    }



    // 下载导入模板（CSV）
    public function tpl()
    {
        $filename = '产品导入模板_' . date('Ymd_His') . '.csv';

        $csvLine = function (array $cols) {
            $safe = array_map(function ($v) {
                $v = (string)$v;
                $v = str_replace('"', '""', $v);
                return '"' . $v . '"';
            }, $cols);
            return implode(',', $safe) . "\r\n";
        };

        // 表头：A=产品名称 B=供应商(分类名称) C=供应商ID(可选) D=状态(0/1，可选)
        $header = ['产品名称', '供应商(分类名称)', '供应商ID(可选)', '状态(0或1，可选)'];
        $examples = [
            ['喷播机7', '喷播机供应商', '', '0'],
            ['A产品', 'A厂家', '', '0'],
            // 也可直接给出ID，名称留空（推荐更精准）
            ['B产品', '', '23', '1'],
        ];

        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

        echo "\xEF\xBB\xBF"; // BOM
        echo $csvLine($header);
        foreach ($examples as $row) echo $csvLine($row);
        exit;
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
