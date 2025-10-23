<?php
namespace app\admin\controller;

use think\Db;
use think\facade\Request;
use think\facade\Session;
use app\admin\model\ReceiveAccount as ReceiveAccountModel;
use app\admin\model\Admin;
use think\facade\Env;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ReceiveAccount extends Common
{
    public function initialize()
    {
        parent::initialize();
        $currentAdmin = Admin::getMyInfo();
        if ($currentAdmin['group_id'] != 1 && $currentAdmin['username'] != 'admin') {
            $this->error('您无权限访问该模块');
        }
    }

    public function index()
    {
        if (request()->isPost()) {
            $page  = input('page/d', 1);
            $limit = input('limit/d', 10);
            $list = ReceiveAccountModel::order('id desc')
                ->paginate(['list_rows'=>$limit,'page'=>$page])
                ->toArray();
            return ['code'=>0,'msg'=>'获取成功!','data'=>$list['data'],'count'=>$list['total'],'rel'=>1];
        }
        return $this->fetch();
    }

    public function add()
    {
        if (Request::isPost()) {
            $data = Request::only(['account','receiver'], 'post');
            $res = ReceiveAccountModel::create($data); // 模型已开启自动时间戳
            return $res ? json(['code'=>0,'msg'=>'添加成功！'])
                        : json(['code'=>-200,'msg'=>'添加失败！']);
        }
        $currentAdmin = Admin::getMyInfo();
        $this->assign('currentAdmin',$currentAdmin);
        return $this->fetch();
    }

    public function edit()
    {
        $id = input('id/d', 0);
        if (Request::isAjax()) {
            $data = Request::only(['account']); // tag 不允许改
            $data['id'] = $id; // ★ 必须带上主键，模型 update() 才知道更新哪条
    
            // 触发模型的自动时间戳（autoWriteTimestamp='int'），会自动写 update_time
            $res = ReceiveAccountModel::update($data);
    
            return $res ? json(['code' => 0, 'msg' => '修改成功！'])
                        : json(['code' => -200, 'msg' => '修改失败！']);
        }
        $entry = ReceiveAccountModel::find($id);
        $this->assign('entry',$entry);
        return $this->fetch();
    }


    public function del()
    {
        $id = input('id/d',0);
        $res = ReceiveAccountModel::destroy($id);
        return $res ? json(['code'=>0,'msg'=>'删除成功！'])
                    : json(['code'=>-200,'msg'=>'删除失败！']);
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
            // $res = ReceiveAccountModel::destroy($ids);
            // 方式2：DB 批量删除（更直观）
            $res = \think\Db::name('crm_receive_account')->whereIn('id', $ids)->delete();
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
        return $this->fetch();  // 渲染 view/inquiry_source/import.html
    }
    
    
    
    // 执行导入
    public function importDo()
    {
        if (!request()->isPost()) {
            return json(['code'=>-200,'msg'=>'非法请求']);
        }
    
        // 1) 接收上传文件
        $file = request()->file('file');
        if (!$file) {
            return json(['code'=>-200,'msg'=>'请上传Excel文件']);
        }
    
        // 2) 保存到 runtime/upload/excel
        $saveDir = Env::get('root_path') . 'runtime' . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'excel';
        if (!is_dir($saveDir)) {
            @mkdir($saveDir, 0777, true);
        }
        $info = $file->validate(['size'=> 10 * 1024 * 1024, 'ext'=>'xlsx,xls,csv'])->move($saveDir);
        if (!$info) {
            return json(['code'=>-200,'msg'=>$file->getError() ?: '文件保存失败']);
        }
        $filePath = $info->getPathname();
        $ext      = strtolower($info->getExtension());
    
        // 3) 解析
        $rows = [];  // 每个元素：[ 'account'=>..., 'receiver'=>...]
        try {
            if ($ext === 'csv') {
                // ---- 解析 CSV ----
                $handle = fopen($filePath, 'r');
                if (!$handle) throw new \Exception('CSV文件读取失败');
                // 尝试去除 UTF-8 BOM
                $first = fgets($handle);
                if (substr($first, 0, 3) === "\xEF\xBB\xBF") $first = substr($first, 3);
                // 放回第一行
                $buffer = $first . stream_get_contents($handle);
                fclose($handle);
                $tmp = tmpfile();
                fwrite($tmp, $buffer);
                fseek($tmp, 0);
    
                $lineNo = 0;
                while (($data = fgetcsv($tmp)) !== false) {
                    $lineNo++;
                    if ($lineNo === 1 && (mb_strpos(implode('', $data), '收款') !== false)) {
                        // 第一行是表头 -> 跳过
                        continue;
                    }
                    // 兼容列数量不足
                    $data = array_pad($data, 2, '');
                    $rows[] = [
                        'account'       => trim((string)$data[0]),
                        'receiver'     => trim((string)$data[1]),
                    ];
                }
                fclose($tmp);
            } else {
                // ---- 解析 Excel (xlsx/xls) ----
                if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                    $sheet       = $spreadsheet->getActiveSheet();
                    $highestRow  = $sheet->getHighestRow();
    
                    // 判断首行是否表头（包含“询盘”字样即认为是表头）
                    $hasHeader = false;
                    $firstRow  = [
                        (string)$sheet->getCell('A1')->getValue(),
                        (string)$sheet->getCell('B1')->getValue(),
                    ];
                    if (implode('', $firstRow) && (mb_strpos(implode('', $firstRow), '收款') !== false)) {
                        $hasHeader = true;
                    }
    
                    $start = $hasHeader ? 2 : 1;
                    for ($r = $start; $r <= $highestRow; $r++) {
                        $account       = trim((string)$sheet->getCell("A{$r}")->getValue());
                        $receiver     = trim((string)$sheet->getCell("B{$r}")->getValue());
                        if ($account === '' && $receiver === '') {
                            continue; // 跳过空行
                        }
                        $rows[] = compact('account','receiver');
                    }
                } else {
                    return json([
                        'code'=>-200,
                        'msg'=>'服务器未安装 PhpSpreadsheet，无法解析 .xlsx/.xls，请安装 phpoffice/phpspreadsheet 或改用 CSV 导入',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            return json(['code'=>-200,'msg'=>'解析失败：'.$e->getMessage()]);
        }
    
        if (empty($rows)) {
            return json(['code'=>-200,'msg'=>'没有可导入的数据']);
        }
    
        // 4) 清洗 + 批量入库
        $now  = time();
        $data = [];
        foreach ($rows as $r) {
            $account       = $r['account']       ?? '';
            $receiver     = $r['receiver']     ?? '';
            // 必填校验：account至少要有
            if ($account === '') continue;

            $data[] = [
                'account'       => $account,
                'receiver'     => $receiver,
                'create_time'  => $now,
                'update_time'  => $now,
            ];
        }
    
        if (empty($data)) {
            return json(['code'=>-200,'msg'=>'有效数据为空（source 为空已被跳过）']);
        }
    
        try {
            // 直接写表（更快），也可换成 ReceiveAccountModel::insertAll($data)
            $inserted = Db::name('crm_receive_account')->insertAll($data);
            return json(['code'=>0,'msg'=>"导入成功：{$inserted} 条"]);
        } catch (\Throwable $e) {
            return json(['code'=>-200,'msg'=>'写入失败：'.$e->getMessage()]);
        }
    }
    
    
    
    
    // 下载导入模板（CSV）
    public function tpl()
    {
        // 文件名：收款账户导入模板_YYYYMMDD_HHMMSS.csv
        $filename = '收款账户导入模板_' . date('Ymd_His') . '.csv';
    
        // 工具函数：按 CSV 规范输出一行（自动加双引号并转义内部引号）
        $csvLine = function(array $cols) {
            $safe = array_map(function($v){
                $v = (string)$v;
                $v = str_replace('"', '""', $v); // 转义内部的 "
                return '"' . $v . '"';
            }, $cols);
            return implode(',', $safe) . "\r\n";
        };
    
        // 表头（与导入逻辑字段一一对应）
        $header = ['收款账户', '创建人'];
    
        // 示例数据（你也可以只保留一行或自定义）
        $examples = [
            [ '百度推广', 'admin', ],
            [ '抖音短视频', '张三', ],
            [ '官网-表单',  '李四', ],
        ];
    
        // 清空缓冲区，避免输出污染
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) { ob_end_clean(); }
        }
    
        // 下载头
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    
        // 输出 BOM（让 Excel 正确识别 UTF-8）
        echo "\xEF\xBB\xBF";
    
        // 输出表头
        echo $csvLine($header);
    
        // 输出示例数据
        foreach ($examples as $row) {
            echo $csvLine($row);
        }
        exit;
    }
}
