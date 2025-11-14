<?php

namespace app\admin\controller;

use think\Db;
use think\facade\Request;
use think\Queue; 
use think\facade\Session;
use app\admin\model\Admin;
use PhpOffice\PhpSpreadsheet\IOFactory;

class InquiryPort extends Common
{
    public function index()
    {
        if (request()->isPost()) {
            return $this->inquiryPortSearch();
        }
        $inquiry_list = $this->getInquiryList();
        $this->assign('inquiry_list', $inquiry_list);
        return $this->fetch();
    }
    public function inquiryPortSearch()
    {
        $current_admin = Admin::getMyInfo();
        $port_name = Request::param('port_name');
        $pageSize = Request::param('limit', 10);
        $page = Request::param('page', 1);
        $query = Db::name('crm_inquiry_port p')->leftJoin('crm_inquiry c', 'p.inquiry_id = c.id');
        if (!empty($port_name)) {
            $query->where('p.port_name', 'like', '%' . $port_name . '%');
        }
        $inquiry_id = Request::param('inquiry_id');
        if (!empty($inquiry_id)) {
            $query->where('p.inquiry_id', $inquiry_id);
        }
        $list = $query->field('p.*, c.inquiry_name')->where([$this->getOrgWhere($current_admin['org'], 'p')])->order('p.id desc')->paginate([
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
            //新增运营端口
            $port_name = Request::param('port_name');
            $inquiry_id = (int)Request::param('inquiry_id');

            if (empty($port_name)) {
                return $this->result([], 500, '运营端口不能为空');
            }
            $port = $this->checkPortInquiry($port_name, $inquiry_id);
            if (!$port) {
                $current_admin = Admin::getMyInfo();
                $data['org'] = $current_admin['org'];
                $data['port_name'] = $port_name;
                $data['inquiry_id'] = $inquiry_id;
                $data['add_time'] = time();
                $data['edit_time'] = time();
                $data['submit_person'] = $current_admin['username'];
                $res = Db::name('crm_inquiry_port')->insert($data);
                return $this->result([], 200, '操作成功');
            } else {
                return $this->result([], 500, '运营端口已存在');
            }
        }
        $inquiry_rows = $this->getInquiryList();
        $inquiry_list = array_map(function ($row) {
            return [
                'id'   => (int)$row['id'],
                'name' => (string)$row['inquiry_name'],
            ];
        }, $inquiry_rows);
        $inquiry_list = json_encode($inquiry_list, JSON_UNESCAPED_UNICODE);
        $this->assign('inquiry_list', $inquiry_list);
        return $this->fetch();
    }


    public function edit()
    {
        $id = Request::param('id');
        if (empty($id)) {
            return $this->result([], 500, '参数错误');
        }
        $result = Db::name('crm_inquiry_port')->where('id', $id)->find();
        if (empty($result)) {
            return $this->result([], 500, '参数错误');
        }

        // 权限判定
        $current_admin = Admin::getMyInfo();
        $isSuper = (session('aid') == 1) || ($current_admin['username'] === 'admin');

        if (request()->isPost()) {
            $port_name = Request::param('port_name');
            $inquiry_id  = (int)Request::param('inquiry_id');

            if (empty($port_name)) {
                return $this->result([], 500, '运营端口名称不能为空');
            }

            // 同组织 + 同询盘来源 下运营端口名不可重复（排除当前ID）
            $exists = Db::name('crm_inquiry_port')
                ->where('port_name', $port_name)
                ->where('inquiry_id', '=', $inquiry_id)
                ->where([$this->getOrgWhere($current_admin['org'])])
                ->where('id', '<>', $id)
                ->find();
            if ($exists) {
                return $this->result([], 500, '运营端口已存在');
            }

            $current_time = time();

            // 非超管：限制只能修改自己提交的记录
            $updateQuery = Db::name('crm_inquiry_port')->where('id', $id);
            if (!$isSuper) {
                $updateQuery->where('submit_person', $current_admin['username']);
            }

            $aff = $updateQuery->update([
                'port_name' => $port_name,
                'inquiry_id'  => $inquiry_id,
                'edit_time'    => $current_time
            ]);

            if ($aff) {
                return $this->result([], 200, '操作成功');
            } else {
                // 可能是无权限或未变更
                if (!$isSuper && $result['submit_person'] !== $current_admin['username']) {
                    return $this->result([], 500, '无权限操作他人记录');
                }
                return $this->result([], 500, '操作失败');
            }
        }

        // GET：非超管禁止打开他人记录编辑页
        if (!$isSuper && ($result['submit_person'] ?? '') !== ($current_admin['username'] ?? '')) {
            return $this->error('无权限编辑他人记录');
        }

        // 非提交：准备下拉数据为 {id,name} + 默认ID
        $defaultInquiryId = (int)$result['inquiry_id'];
        $inquiry_rows = $this->getInquiryList();
        $inquiry_list = array_map(function ($row) {
            return [
                'id'   => (int)$row['id'],
                'name' => (string)$row['inquiry_name'],
            ];
        }, $inquiry_rows);
        $inquiry_list = json_encode($inquiry_list, JSON_UNESCAPED_UNICODE);
        $this->assign('inquiry_list', $inquiry_list);
        $this->assign('default_inquiry_id', $defaultInquiryId);
        $this->assign('result', $result);
        return $this->fetch();
    }

    public function del()
    {
        $id = Request::param('id');
        if (empty($id)) {
            return $this->result([], 500, '参数错误');
        }

        $current_admin = Admin::getMyInfo();
        $isSuper = (session('aid') == 1) || ($current_admin['username'] === 'admin');

        $query = Db::name('crm_inquiry_port')->where('id', $id);
        if (!$isSuper) {
            $query->where('submit_person', $current_admin['username']);
        }

        $aff = $query->delete();
        if ($aff) {
            return $this->result([], 200, '删除成功');
        } else {
            // 可能是记录不存在或无权限
            return $this->result([], 500, '无权限或记录不存在');
        }
    }


    // 批量删除
    public function batchDel()
    {
        if (!request()->isPost()) {
            return json(['code' => -200, 'msg' => '非法请求']);
        }
        $ids = input('post.ids/a', []);
        if (empty($ids)) {
            return json(['code' => -200, 'msg' => '未选择任何记录']);
        }

        $current_admin = Admin::getMyInfo();
        $isSuper = (session('aid') == 1) || ($current_admin['username'] === 'admin');

        try {
            if ($isSuper) {
                $delCount = Db::name('crm_inquiry_port')->whereIn('id', $ids)->delete();
                if ($delCount > 0) {
                    return json(['code' => 0, 'msg' => '删除成功', 'data' => ['count' => $delCount]]);
                }
                return json(['code' => -200, 'msg' => '删除失败或记录不存在']);
            } else {
                // 仅允许删除本人提交的记录
                $ownIds = Db::name('crm_inquiry_port')
                    ->whereIn('id', $ids)
                    ->where('submit_person', $current_admin['username'])
                    ->column('id');

                if (empty($ownIds)) {
                    return json(['code' => -200, 'msg' => '无可删除的记录（仅能删除本人提交的记录）']);
                }

                $delCount = Db::name('crm_inquiry_port')->whereIn('id', $ownIds)->delete();
                $skipped  = count($ids) - $delCount;

                if ($delCount > 0) {
                    $msg = '删除成功：' . $delCount . ' 条';
                    if ($skipped > 0) $msg .= '，跳过(非本人记录)：' . $skipped . ' 条';
                    return json(['code' => 0, 'msg' => $msg, 'data' => ['count' => $delCount, 'skipped' => $skipped]]);
                }
                return json(['code' => -200, 'msg' => '删除失败或记录不存在（或无权限）']);
            }
        } catch (\Throwable $e) {
            return json(['code' => -200, 'msg' => '删除异常：' . $e->getMessage()]);
        }
    }



    // 导入页（弹窗）
    public function import()
    {
        return $this->fetch();  // 渲染 view/inquiry_port/import.html
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

        // 解析为二维数组 rows：每行是 [运营端口, 询盘来源, 询盘来源ID(可选), 状态(可选)]
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
                    // 识别并跳过表头（含“运营”或“询盘”字样即视为表头）
                    $joined = implode('', $data);
                    if ($lineNo === 1 && (mb_strpos($joined, '运营') !== false || mb_strpos($joined, '询盘') !== false)) {
                        continue;
                    }
                    $data = array_pad($data, 4, '');
                    $rows[] = [
                        trim((string)$data[0]), // port_name
                        trim((string)$data[1]), // inquiry_name
                        trim((string)$data[2]), // inquiry_id (optional)
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

                // 判断首行是否表头（含“运营”或“询盘”）
                $firstRow = [
                    (string)$sheet->getCell('A1')->getValue(),
                    (string)$sheet->getCell('B1')->getValue(),
                    (string)$sheet->getCell('C1')->getValue(),
                    (string)$sheet->getCell('D1')->getValue(),
                ];
                $hasHeader = (mb_strpos(implode('', $firstRow), '运营') !== false) || (mb_strpos(implode('', $firstRow), '询盘') !== false);
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
            list($port_name, $inquiry_name, $inquiry_id_raw, $status_raw) = $row;

            if ($port_name === '' && $inquiry_name === '' && $inquiry_id_raw === '') {
                $skippedEmpty++;
                continue;
            }

            // 解析 status（可选，默认0）
            $status = ($status_raw === '' ? 0 : (int)$status_raw);

            // 解析 inquiry_id：优先C列ID，否则用B列名称在当前组织下查找/创建
            $inquiry_id = 0;
            if ($inquiry_id_raw !== '' && is_numeric($inquiry_id_raw)) {
                $inquiry_id = (int)$inquiry_id_raw;
            } elseif ($inquiry_name !== '') {
                $cat = \think\Db::name('crm_inquiry')
                    ->where('inquiry_name', $inquiry_name)
                    ->where([$this->getOrgWhere($org)])
                    ->find();

                if (!$cat) {
                    // 自动创建该供应商（分类）
                    $cid = \think\Db::name('crm_inquiry')->insertGetId([
                        'inquiry_name' => $inquiry_name,
                        'org'           => $org,
                        'add_time'      => $now,
                        'edit_time'     => $now,
                        'submit_person' => $user,
                    ]);
                    if ($cid) {
                        $inquiry_id = (int)$cid;
                        $createdCats++;
                    }
                } else {
                    $inquiry_id = (int)$cat['id'];
                }
            }

            // 运营端口必填
            if ($port_name === '') {
                $skippedEmpty++;
                continue;
            }
            // 仍未拿到询盘来源ID，跳过
            if ($inquiry_id <= 0) {
                $skippedEmpty++;
                continue;
            }

            // 组合唯一键（同组织+同询盘来源+同运营端口 视为同一条）
            $key = md5($org . '|' . $inquiry_id . '|' . $port_name);
            if (isset($seenKeys[$key])) {
                $skippedExist++;
                continue;
            }

            // DB去重：同 org 范围 + inquiry_id + port_name
            $exists = \think\Db::name('crm_inquiry_port')
                ->where('port_name', $port_name)
                ->where('inquiry_id', $inquiry_id)
                ->where([$this->getOrgWhere($org)])
                ->find();
            if ($exists) {
                $skippedExist++;
                continue;
            }

            // 插入
            $ok = \think\Db::name('crm_inquiry_port')->insert([
                'port_name'  => $port_name,
                'org'           => $org,
                'inquiry_id'   => $inquiry_id,
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
        $filename = '运营端口导入模板_' . date('Ymd_His') . '.csv';

        $csvLine = function (array $cols) {
            $safe = array_map(function ($v) {
                $v = (string)$v;
                $v = str_replace('"', '""', $v);
                return '"' . $v . '"';
            }, $cols);
            return implode(',', $safe) . "\r\n";
        };

        // 表头：A=运营端口 B=询盘来源 C=询盘来源ID(可选) D=状态(0/1，可选)
        $header = ['运营端口', '询盘来源', '询盘来源ID(可选)', '状态(0或1，可选)'];
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




}
