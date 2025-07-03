<?php

namespace app\admin\job;

use app\admin\controller\Client;
use think\Db;
use think\queue\Job;
use PhpOffice\PhpSpreadsheet\IOFactory;
use think\facade\Log;
use think\facade\Session;

class ExcelImport
{


    public function fire(Job $job, $data)
    {
        try {
            // 提取数据
            $filePath = $data['filePath'] ?? '';
            $pr_user = $data['pr_user'] ?? '';
            $chunkData = $data['chunkData'] ?? [];
            $headers = $data['headers'] ?? [];
            $current_time = date("Y-m-d H:i:s");
            Session::set('aid', $data['user_id']);
            Session::set('username', $pr_user);
            $success_row = 0;
            $error_row = 0;
            foreach ($chunkData as $index => $row) {
                // 检查当前行是否为空白行
                if ($this->isRowEmpty($row)) {
                    continue;
                }

                try {
                    // 准备单条数据
                    $rowAssoc = $this->buildAssocRow($row, $headers);
                    $leadsData = $this->buildLeadsRow($rowAssoc, $pr_user, $current_time);
                    $contactsData = [];

                    // 开启单条记录事务
                    Db::startTrans();
                    // 插入客户主表数据
                    $leadsId = $this->insertSingleLeadsData($leadsData);
                    if (!$leadsId) {
                        $error_row++;
                        continue;
                    }
                    // 构建并插入联系人数据
                    $contacts = $this->buildContacts($leadsId, $rowAssoc, $current_time);
                    foreach ($contacts as $contact) {
                        if (!empty($contact['contact_value'])) {
                            $contactsData[] = $contact;
                        }
                    }
                    $this->insertSingleContactsData($contactsData);
                    $success_row++;
                    // 提交单条记录事务
                    Db::commit();
                } catch (\Exception $e) {
                    // 回滚单条记录事务
                    Db::rollback();
                    $error_row++;
                    // 记录详细错误日志
                    $logData = [
                        'message' => "第 {$index} 条 Excel 数据导入失败",
                        'error_message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'task_data' => $row,
                        // 'trace' => $e->getTraceAsString()
                    ];
                    Client::addOperLog(null, '数据导入', $logData);
                    Log::error(json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    continue;
                }
            }


            // 记录成功和失败的条数
            $logData = [
                'message' => 'Excel 导入任务完成',
                'success_count' => $success_row,
                'fail_count' => $error_row,
                'task_data' => $filePath
            ];
            Client::addOperLog(null, '数据导入', $logData);
            Log::info(json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // 删除任务
            $job->delete();
        } catch (\Exception $e) {
            // 记录详细错误日志
            $logData = [
                'message' => 'Excel 导入任务整体失败',
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'task_data' => $filePath,
                // 'trace' => $e->getTraceAsString()
            ];
            Client::addOperLog(null, '数据导入', $logData);
            Log::error(json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            // 重试任务
            if ($job->attempts() < 3) {
                $job->release(10);
            } else {
                $job->delete();
            }
        }
    }


    /**
     * 插入单条联系人数据
     *
     * @param array $contactsData 联系人数据
     */
    private function insertSingleContactsData($contactsData)
    {
        if (!empty($contactsData)) {
            Db::name('crm_contacts')
                ->strict(false)
                ->field(['leads_id', 'contact_type', 'contact_value', 'contact_extra', 'created_at'])
                ->insertAll($contactsData);
        }
    }


    /**
     * 插入单条客户主表数据
     *
     * @param array $leadsData 单条客户主表数据
     * @return int 插入后的客户主表 ID
     * @throws \Exception 插入失败时抛出异常
     */
    private function insertSingleLeadsData($leadsData)
    {
        $id = Db::name('crm_leads')->insertGetId($leadsData);
        return $id;
    }

    /**
     * 处理 Excel 导入任务
     *
     * @param Job $job 队列任务实例
     * @param array $data 任务数据
     */
    public function fireOld(Job $job, $data)
    {
        try {
            // 提取数据
            $filePath = $data['filePath'];
            $pr_user = $data['pr_user'];
            $chunkData = $data['chunkData'];
            $headers = $data['headers'];
            $current_time = date("Y-m-d H:i:s");

            // 准备数据
            list($leadsData, $contactsData) = $this->prepareData($chunkData, $headers, $pr_user, $current_time);

            // 开启事务
            Db::startTrans();
            try {
                // 批量插入客户主表
                $leadsIds = $this->insertLeadsData($leadsData);

                // 构建并插入联系人数据
                $this->insertContactsData($chunkData, $headers, $leadsIds, $current_time, $contactsData);

                // 提交事务
                Db::commit();
                // 删除任务
                $job->delete();
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            // 记录详细错误日志
            $logData = [
                'message' => 'Excel 导入失败',
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'task_data' => $filePath,
                // 'trace' => $e->getTraceAsString()
            ];
            Client::addOperLog(null, '数据导入', $logData);
            $logData['trace'] = $e->getTraceAsString();
            Log::error(json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            // 重试任务
            if ($job->attempts() < 3) {
                $job->release(10);
            } else {
                $job->delete();
            }
        }
    }



    /**
     * 检查行数据是否为空
     *
     * @param array $row 行数据
     * @return bool 如果行为空返回 true，否则返回 false
     */
    private function isRowEmpty($row)
    {
        foreach ($row as $value) {
            if (!empty(trim($value))) {
                return false;
            }
        }
        return true;
    }
    /**
     * 准备客户主表和联系人表的数据
     *
     * @param array $chunkData 数据块
     * @param array $headers 表头
     * @param string $pr_user 操作人
     * @param string $current_time 当前时间
     * @return array 包含客户主表数据和联系人表数据的数组
     */
    private function prepareData($chunkData, $headers, $pr_user, $current_time)
    {
        $leadsData = [];
        $contactsData = [];

        foreach ($chunkData as $row) {
            $rowAssoc = $this->buildAssocRow($row, $headers);
            // 准备客户主表数据
            $leadsRow = $this->buildLeadsRow($rowAssoc, $pr_user, $current_time);
            $leadsData[] = $leadsRow;
        }

        return [$leadsData, $contactsData];
    }

    /**
     * 构建关联数组行
     *
     * @param array $row 原始行数据
     * @param array $headers 表头
     * @return array 关联数组行
     */
    private function buildAssocRow($row, $headers)
    {
        $rowAssoc = [];
        foreach ($headers as $key => $title) {
            $rowAssoc[$title] = $row[$key] ?? '';
        }
        return $rowAssoc;
    }

    /**
     * 构建客户主表数据行
     *
     * @param array $rowAssoc 关联数组行
     * @param string $pr_user 操作人
     * @param string $current_time 当前时间
     * @return array 客户主表数据行
     */
    private function buildLeadsRow($rowAssoc, $pr_user, $current_time)
    {
        return [
            'kh_name'      => $rowAssoc['客户名称'] ?? '',
            'kh_rank'      => $rowAssoc['客户等级'] ?? '',
            'xs_area'      => $rowAssoc['国家'] ?? '',
            'kh_contact'   => $rowAssoc['联系人'] ?? '',
            'remark'       => $rowAssoc['客户备注'] ?? '',
            'kh_status'    => $rowAssoc['客户来源'] ?? '',
            'pr_user'      => $pr_user,
            'ut_time'      => $current_time,
            'at_time'      => $current_time,
            'at_user'      => $pr_user,
            'status'       => 1,
            'ispublic'     => 3,
            'pr_user_bef'  => $pr_user,
        ];
    }

    /**
     * 插入客户主表数据
     *
     * @param array $leadsData 客户主表数据
     * @return array 插入后的客户主表 ID 数组
     */
    private function insertLeadsData($leadsData)
    {
        $leadsIds = [];
        foreach ($leadsData as $data) {
            // 单条插入数据并获取插入后的 ID
            $id = Db::name('crm_leads')->insertGetId($data);
            if (!$id) {
                // 记录错误日志
                $logData = [
                    'message' => '客户主表数据插入失败',
                    'error_message' => '数据库插入操作返回无效 ID',
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'task_data' => $data,
                    // 'trace' => (new \Exception())->getTraceAsString()
                ];
                if (Db::getConfig('trans_begin')) {
                    Db::rollback();
                }
                Client::addOperLog(null, '数据导入', $logData);
                Log::error('客户主表数据插入失败，数据：' . json_encode($data, JSON_UNESCAPED_UNICODE));
                throw new \Exception('客户主表数据插入失败');
            }
            $leadsIds[] = $id;
        }
        return $leadsIds;
    }

    /**
     * 构建并插入联系人数据
     *
     * @param array $chunkData 数据块
     * @param array $headers 表头
     * @param array $leadsIds 客户主表 ID 数组
     * @param string $current_time 当前时间
     * @param array &$contactsData 联系人表数据
     */
    private function insertContactsData($chunkData, $headers, $leadsIds, $current_time, &$contactsData)
    {
        foreach ($chunkData as $index => $row) {
            $rowAssoc = $this->buildAssocRow($row, $headers);
            $leadsId = $leadsIds[$index];

            $contacts = $this->buildContacts($leadsId, $rowAssoc, $current_time);

            foreach ($contacts as $contact) {
                if (!empty($contact['contact_value'])) {
                    $contactsData[] = $contact;
                }
            }
        }

        // 批量插入联系方式表（crm_contacts）
        if (!empty($contactsData)) {
            Db::name('crm_contacts')
                ->strict(false)
                ->field(['leads_id', 'contact_type', 'contact_value', 'contact_extra', 'created_at'])
                ->insertAll($contactsData);
        }
    }

    /**
     * 构建联系人数据
     *
     * @param int $leadsId 客户主表 ID
     * @param array $rowAssoc 关联数组行
     * @param string $current_time 当前时间
     * @return array 联系人数据数组
     */
    private function buildContacts($leadsId, $rowAssoc, $current_time)
    {

        $data = [];
        //联系方式
        $map = [
            '联系人电话' => 'phone',
            '联系人邮箱' => 'email',
            '联系人社交' => 'contacts'
        ];
        foreach ($map as $key => $con_key) {
            $con_v = trim($rowAssoc[$key] ?? '');
            if ($con_v) {
                $contact_extra = '';
                $contact_type = isset(Client::CONTACT_MAP[$con_key]) ? Client::CONTACT_MAP[$con_key] : $con_key;
                $contact_values = explode(',', $con_v);
                foreach ($contact_values as $contact_value) {
                    $contact_value = $contact_value;
                    if ($con_key == 'contacts') {
                        $c = explode(':', $contact_value);
                        if (count($c) == 2) {
                            $c[0] = strtolower($c[0]);
                            $contact_type =  isset(Client::CONTACT_MAP[$c[0]]) ? Client::CONTACT_MAP[$c[0]] : $c[0];
                            $contact_value = $c[1];
                        }
                        $contact_value = trim($contact_value);
                    } elseif ($con_key == 'phone') {
                        $phone = explode('-', $contact_value);
                        if (count($phone) == 2) {
                            $contact_extra = $phone[0];
                            $contact_value = $phone[1];
                        }
                    }
                    $data[] = [
                        'leads_id'      => $leadsId,
                        'contact_type'  => $contact_type,
                        'contact_value' => $contact_value,
                        'contact_extra' => $contact_extra,
                        'created_at'    => $current_time
                    ];
                }
            }
        }
        return $data;
    }
}
