<?php
namespace app\admin\job\product;

use think\queue\Job;
use think\Db;

class ProductImportJobV2
{
    /**
     * 自定义入口方法（通过 Class@process 调用）
     */
    public function process(Job $job, $data)
    {
        $batchId    = $data['batch_id']    ?? '';
        $fileName   = $data['file_name']   ?? '';
        $org        = $data['org']         ?? '';
        $userId     = $data['user_id']     ?? 0;
        $username   = $data['username']    ?? '';
        $chunkIndex = $data['chunk_index'] ?? 0;
        $chunkSize  = $data['chunk_size']  ?? 100;
        $baseRow    = $data['base_row']    ?? 1;
        $rows       = $data['rows']        ?? [];
        $nowTs      = time();

        $inserted = 0;
        $skippedExist = 0;
        $skippedEmpty = 0;
        $createdCats = 0;

        $skippedItems = []; // 记录被跳过的行（原因）
        $errorItems   = []; // 记录异常失败的行（原因）
        $seenKeys     = []; // 当前块内去重 key（org|category_id|product_name）

        foreach ($rows as $idx => $row) {
            // 计算“文件中的行号”（便于日志回看），这里按数据区序号
            $fileRowNo = $baseRow + $idx;

            try {
                list($product_name, $category_name, $category_id_raw, $status_raw) = $this->normalizeRow($row);

                // 空行 & 关键字段缺失
                if ($product_name === '' && $category_name === '' && $category_id_raw === '') {
                    $skippedEmpty++;
                    $skippedItems[] = $this->mkSkip($fileRowNo, $row, '空行（产品名/分类信息均为空）');
                    continue;
                }

                // 解析状态
                $status = ($status_raw === '' ? 0 : (int)$status_raw);

                // 解析/获取 category_id：优先用传入的 ID；否则按名称 + org 查找/创建
                $category_id = 0;
                if ($category_id_raw !== '' && is_numeric($category_id_raw)) {
                    $category_id = (int)$category_id_raw;
                } elseif ($category_name !== '') {
                    // org 可能是 '3s' 或 ',3s,' 两种存法，查询时同时兼容
                    $cat = Db::name('crm_product_category')
                        ->where('category_name', $category_name)
                        ->where(function($q) use ($org) {
                            $q->where('org', '=', $org)
                              ->whereOr('org', 'like', "%,{$org},%");
                        })
                        ->find();

                    if (!$cat) {
                        // 自动创建分类
                        $cid = Db::name('crm_product_category')->insertGetId([
                            'category_name' => $category_name,
                            'org'           => $org,          // 如果你库里要求 ',3s,'，可改为 ",{$org},"
                            'add_time'      => $nowTs,
                            'edit_time'     => $nowTs,
                            'submit_person' => $username,
                        ]);
                        if ($cid) {
                            $category_id = (int)$cid;
                            $createdCats++;
                        } else {
                            $skippedEmpty++;
                            $skippedItems[] = $this->mkSkip($fileRowNo, $row, '分类创建失败，已跳过');
                            continue;
                        }
                    } else {
                        $category_id = (int)$cat['id'];
                    }
                }

                if ($product_name === '') {
                    $skippedEmpty++;
                    $skippedItems[] = $this->mkSkip($fileRowNo, $row, '产品名称为空，已跳过');
                    continue;
                }
                if ($category_id <= 0) {
                    $skippedEmpty++;
                    $skippedItems[] = $this->mkSkip($fileRowNo, $row, '无法确定分类ID，已跳过');
                    continue;
                }

                // 块内去重
                $key = md5($org . '|' . $category_id . '|' . $product_name);
                if (isset($seenKeys[$key])) {
                    $skippedExist++;
                    $skippedItems[] = $this->mkSkip($fileRowNo, $row, '同一文件块内重复');
                    continue;
                }

                // 数据库查重（兼容 org 两种写法）
                $exists = Db::name('crm_products')
                    ->where('product_name', $product_name)
                    ->where('category_id', $category_id)
                    ->where(function($q) use ($org) {
                        $q->where('org', '=', $org)
                          ->whereOr('org', 'like', "%,{$org},%");
                    })
                    ->find();
                if ($exists) {
                    $skippedExist++;
                    $skippedItems[] = $this->mkSkip($fileRowNo, $row, '数据库已存在（同组织+分类+产品名）');
                    continue;
                }

                // 插入产品
                $ok = Db::name('crm_products')->insert([
                    'product_name'  => $product_name,
                    'org'           => $org,
                    'category_id'   => $category_id,
                    'status'        => $status,
                    'add_time'      => $nowTs,
                    'edit_time'     => $nowTs,
                    'submit_person' => $username,
                ]);

                if ($ok) {
                    $inserted++;
                    $seenKeys[$key] = 1;
                } else {
                    $errorItems[] = $this->mkErr($fileRowNo, $row, '插入失败（未知原因）');
                }
            } catch (\Throwable $e) {
                $errorItems[] = $this->mkErr($fileRowNo, $row, '异常：' . $e->getMessage());
            }
        }

        // 写入操作日志（每块一条，含详细明细）
        $this->writeOperLog($userId, $username, [
            'batch_id'       => $batchId,
            'file'           => $fileName,
            'chunk'          => $chunkIndex,
            'chunk_size'     => $chunkSize,
            'inserted'       => $inserted,
            'skipped_exist'  => $skippedExist,
            'skipped_empty'  => $skippedEmpty,
            'created_cats'   => $createdCats,
            'skipped_items'  => $skippedItems, // 数组明细：行号+原因+原始行
            'error_items'    => $errorItems,   // 数组明细：行号+原因+原始行
            'finished_at'    => date('Y-m-d H:i:s'),
        ]);

        // 标记任务完成
        $job->delete();
    }

    /**
     * 解析/清洗行
     */
    private function normalizeRow(array $row): array
    {
        $a = trim((string)($row[0] ?? '')); // product_name
        $b = trim((string)($row[1] ?? '')); // category_name
        $c = trim((string)($row[2] ?? '')); // category_id_raw
        $d = trim((string)($row[3] ?? '')); // status_raw
        return [$a, $b, $c, $d];
    }

    private function mkSkip(int $line, array $row, string $reason): array
    {
        return ['line' => $line, 'reason' => $reason, 'row' => $row];
    }

    private function mkErr(int $line, array $row, string $reason): array
    {
        return ['line' => $line, 'reason' => $reason, 'row' => $row];
    }

    /**
     * 写入 crm_operation_log
     * 表结构：id, leads_id, oper_type(varchar), description(text JSON), user_id, oper_user, created_at
     */
    private function writeOperLog(int $userId, string $username, array $payload): void
    {
        Db::name('crm_operation_log')->insert([
            'leads_id'   => null,
            'oper_type'  => '产品导入', // 与你的示例“数据导入”同风格
            'description'=> json_encode($payload, JSON_UNESCAPED_UNICODE),
            'user_id'    => $userId,
            'oper_user'  => $username,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
