<?php
namespace app\admin\controller;
use think\Db;
use think\Request;
use think\Controller;
use think\facade\Env;
class UpFiles extends Common
{
    /**
     * 检查并创建上传目录
     * @param string $relativePath 相对路径，如 'uploads'
     * @return array ['success' => bool, 'path' => string, 'error' => string]
     */
    private function checkUploadDir($relativePath = 'uploads') {
        $uploadPath = Env::get('root_path') . 'public' . DIRECTORY_SEPARATOR . $relativePath;
        
        // 如果目录不存在，尝试创建
        if (!is_dir($uploadPath)) {
            // 先尝试 0755 权限
            if (!@mkdir($uploadPath, 0755, true)) {
                // 如果失败，尝试 0777 权限（某些服务器需要）
                if (!@mkdir($uploadPath, 0777, true)) {
                    return [
                        'success' => false,
                        'path' => $uploadPath,
                        'error' => '上传目录创建失败，请检查目录权限：' . $uploadPath
                    ];
                }
            }
        }
        
        // 检查目录是否可写
        if (!is_writable($uploadPath)) {
            // 尝试修改权限
            @chmod($uploadPath, 0755);
            if (!is_writable($uploadPath)) {
                // 再次尝试 0777
                @chmod($uploadPath, 0777);
                if (!is_writable($uploadPath)) {
                    return [
                        'success' => false,
                        'path' => $uploadPath,
                        'error' => '上传目录不可写，请检查目录权限：' . $uploadPath
                    ];
                }
            }
        }
        
        return [
            'success' => true,
            'path' => $uploadPath,
            'error' => ''
        ];
    }
    
    public function upload(){
        // 先尝试直接通过字段名 'file' 获取文件（兼容 XMLHttpRequest 上传）
        $file = request()->file('file');
        
        // 如果获取不到，尝试通过 request()->file() 获取所有文件
        if (!$file) {
            $files = request()->file();
            
            // 检查 request()->file() 是否返回 null 或空数组
            if (is_null($files) || !is_array($files) || empty($files)) {
                // 检查 $_FILES 是否存在
                if (empty($_FILES) || !isset($_FILES['file'])) {
                    return json(['code' => 0, 'info' => '未检测到上传文件', 'url' => '']);
                }
                // 如果 $_FILES 存在但 request()->file() 返回 null，可能是 Content-Type 问题
                // 尝试重新获取
                $file = request()->file('file');
                if (!$file) {
                    return json(['code' => 0, 'info' => '文件获取失败，请确保使用 multipart/form-data 格式上传', 'url' => '']);
                }
            } else {
                // 正常情况：request()->file() 返回数组
                $fileKey = array_keys($files);
                if (empty($fileKey)) {
                    return json(['code' => 0, 'info' => '未检测到上传文件', 'url' => '']);
                }
                // 获取表单上传文件
                $file = request()->file($fileKey[0]);
            }
        }
        
        if (!$file) {
            return json(['code' => 0, 'info' => '文件获取失败', 'url' => '']);
        }
        
        // 检查并创建上传目录
        $dirCheck = $this->checkUploadDir('uploads');
        if (!$dirCheck['success']) {
            return json(['code' => 0, 'info' => $dirCheck['error'], 'url' => '']);
        }
        
        // 移动到框架应用根目录/public/uploads/ 目录下
        $info = $file->validate(['ext' => 'jpg,png,gif,jpeg'])->move('uploads');
        if($info){
            $result['code'] = 1;
            $result['info'] = '图片上传成功!';
            $path = str_replace('\\','/',$info->getSaveName());
            $result['url'] = '/uploads/'. $path;
            return json($result);
        }else{
            // 上传失败获取错误信息
            $errorMsg = $file->getError() ?: '图片上传失败!';
            // 如果是权限相关错误，提供更详细的提示
            if (strpos($errorMsg, 'Permission') !== false || strpos($errorMsg, '权限') !== false || strpos($errorMsg, 'mkdir') !== false) {
                $errorMsg = '上传失败：目录权限不足，请检查 ' . $dirCheck['path'] . ' 目录的写入权限';
            }
            $result['code'] = 0;
            $result['info'] = $errorMsg;
            $result['url'] = '';
            return json($result);
        }
    }
    public function file(){
        $files = request()->file();
        if (is_null($files) || !is_array($files) || empty($files)) {
            return json(['code' => 1, 'info' => '未检测到上传文件', 'url' => '']);
        }
        $fileKey = array_keys($files);
        // 获取表单上传文件 例如上传了001.jpg
        $file = request()->file($fileKey[0]);
        if (!$file) {
            return json(['code' => 1, 'info' => '文件获取失败', 'url' => '']);
        }
        
        // 检查并创建上传目录
        $dirCheck = $this->checkUploadDir('uploads');
        if (!$dirCheck['success']) {
            return json(['code' => 1, 'info' => $dirCheck['error'], 'url' => '']);
        }
        
        // 移动到框架应用根目录/public/uploads/ 目录下
        $info = $file->validate(['ext' => 'zip,rar,pdf,swf,ppt,psd,ttf,txt,xls,doc,docx'])->move('uploads');
        if($info){
            $result['code'] = 0;
            $result['info'] = '文件上传成功!';
            $path=str_replace('\\','/',$info->getSaveName());

            $result['url'] = '/uploads/'. $path;
            $result['ext'] = $info->getExtension();
            $result['size'] = byte_format($info->getSize(),2);
            return $result;
        }else{
            // 上传失败获取错误信息
            $result['code'] =1;
            $result['info'] = '文件上传失败!';
            $result['url'] = '';
            return $result;
        }
    }
    public function pic(){
        // 获取上传文件表单字段名
        $files = request()->file();
        if (is_null($files) || !is_array($files) || empty($files)) {
            return json_encode(['code' => 0, 'info' => '未检测到上传文件', 'url' => ''], true);
        }
        $fileKey = array_keys($files);
        // 获取表单上传文件
        $file = request()->file($fileKey[0]);
        if (!$file) {
            return json_encode(['code' => 0, 'info' => '文件获取失败', 'url' => ''], true);
        }
        
        // 检查并创建上传目录
        $dirCheck = $this->checkUploadDir('uploads');
        if (!$dirCheck['success']) {
            return json_encode(['code' => 0, 'info' => $dirCheck['error'], 'url' => ''], true);
        }
        
        // 移动到框架应用根目录/public/uploads/ 目录下
        $info = $file->validate(['ext' => 'jpg,png,gif,jpeg'])->move(Env::get('root_path') . 'public/uploads');
        if($info){
            $result['code'] = 1;
            $result['info'] = '图片上传成功!';
            $path=str_replace('\\','/',$info->getSaveName());
            $result['url'] = '/uploads/'. $path;
            return json_encode($result,true);
        }else{
            // 上传失败获取错误信息
            $result['code'] =0;
            $result['info'] = '图片上传失败!';
            $result['url'] = '';
            return json_encode($result,true);
        }
    }
    /**
     * 后台：wangEditor
     * @return \think\response\Json
     */
    public function editUpload(){
        // 获取上传文件表单字段名
        $files = request()->file();
        if (is_null($files) || !is_array($files) || empty($files)) {
            return json_encode(['code' => 1, 'msg' => '未检测到上传文件', 'data' => ''], true);
        }
        $fileKey = array_keys($files);
        // 获取表单上传文件
        $file = request()->file($fileKey[0]);
        if (!$file) {
            return json_encode(['code' => 1, 'msg' => '文件获取失败', 'data' => ''], true);
        }
        
        // 检查并创建上传目录
        $dirCheck = $this->checkUploadDir('uploads');
        if (!$dirCheck['success']) {
            return json_encode(['code' => 1, 'msg' => $dirCheck['error'], 'data' => ''], true);
        }
        
        // 移动到框架应用根目录/public/uploads/ 目录下
        $info = $file->validate(['ext' => 'jpg,png,gif,jpeg'])->move('uploads');
        if($info){
            $path=str_replace('\\','/',$info->getSaveName());
            return '/uploads/'. $path;
        }else{
            // 上传失败获取错误信息
            $result['code'] =1;
            $result['msg'] = '图片上传失败!';
            $result['data'] = '';
            return json_encode($result,true);
        }
    }
    //多图上传
    public function upImages(){
        $files = request()->file();
        if (is_null($files) || !is_array($files) || empty($files)) {
            return ['code' => 1, 'msg' => '未检测到上传文件'];
        }
        $fileKey = array_keys($files);
        // 获取表单上传文件
        $file = request()->file($fileKey[0]);
        if (!$file) {
            return ['code' => 1, 'msg' => '文件获取失败'];
        }
        
        // 检查并创建上传目录
        $dirCheck = $this->checkUploadDir('uploads');
        if (!$dirCheck['success']) {
            return ['code' => 1, 'msg' => $dirCheck['error']];
        }
        
        // 移动到框架应用根目录/public/uploads/ 目录下
        $info = $file->validate(['ext' => 'jpg,png,gif,jpeg'])->move(Env::get('root_path') . 'public/uploads');
        if($info){
            $result['code'] = 0;
            $result['msg'] = '图片上传成功!';
            $path=str_replace('\\','/',$info->getSaveName());
            $result["src"] = '/uploads/'. $path;
            return $result;
        }else{
            // 上传失败获取错误信息
            $result['code'] =1;
            $result['msg'] = '图片上传失败!';
            return $result;
        }
    }
    /**
     * 后台：NKeditor
     * @return \think\response\Json
     */
    public function editimg(){
        $allowExtesions = array(
            'image' => 'gif,jpg,jpeg,png,bmp',
            'flash' => 'swf,flv',
            'media' => 'swf,flv,mp3,wav,wma,wmv,mid,avi,mpg,asf,rm,rmvb',
            'file' => 'doc,docx,xls,xlsx,ppt,htm,html,txt,zip,rar,gz,bz2',
        );
        // 获取上传文件表单字段名
        $files = request()->file();
        if (is_null($files) || !is_array($files) || empty($files)) {
            return json(['code' => 001, 'message' => '未检测到上传文件', 'url' => '']);
        }
        $fileKey = array_keys($files);
        // 获取表单上传文件
        $file = request()->file($fileKey[0]);
        if (!$file) {
            return json(['code' => 001, 'message' => '文件获取失败', 'url' => '']);
        }
        
        // 检查并创建上传目录
        $dirCheck = $this->checkUploadDir('uploads');
        if (!$dirCheck['success']) {
            return json(['code' => 001, 'message' => $dirCheck['error'], 'url' => '']);
        }
        
        // 移动到框架应用根目录/public/uploads/ 目录下
        $info = $file->validate(['ext'=>$allowExtesions[input('fileType')]])->move('./uploads');
        if($info){
            $path=str_replace('\\','/',$info->getSaveName());
            $url = '/uploads/'. $path;
            $result['code'] = '000';
            $result['message'] = '图片上传成功!';
            $result['item'] = ['url'=>$url];
            return json($result);
        }else{
            // 上传失败获取错误信息
            $result['code'] =001;
            $result['message'] = $file->getError();
            $result['url'] = '';
            return json($result);
        }
    }
}