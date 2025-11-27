<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 加载插件的自动加载器
require_once __DIR__ . '/Plugin.php';

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;
use TypechoPlugin\TEMediaFolder\Services\CosService;
use TypechoPlugin\TEMediaFolder\Services\OssService;
use TypechoPlugin\TEMediaFolder\Services\LskyService;
use TypechoPlugin\TEMediaFolder\Services\UpyunService;

class TEMediaFolder_Action extends \Typecho_Widget implements \Widget\ActionInterface
{
    private $config;
    private $serviceCache = []; // Service 实例缓存
    
    // 定义动作映射，减少重复代码
    private static $actionMap = [
        'temf-cos-list' => ['handler' => 'handleCloudStorageList', 'type' => 'cos'],
        'temf-cos-upload' => ['handler' => 'handleCloudStorageUpload', 'type' => 'cos'],
        'temf-cos-delete' => ['handler' => 'handleCloudDeleteAction', 'type' => 'cos'],
        'temf-cos-rename' => ['handler' => 'handleCloudRenameAction', 'type' => 'cos'],
        'temf-oss-list' => ['handler' => 'handleCloudStorageList', 'type' => 'oss'],
        'temf-oss-upload' => ['handler' => 'handleCloudStorageUpload', 'type' => 'oss'],
        'temf-oss-delete' => ['handler' => 'handleCloudDeleteAction', 'type' => 'oss'],
        'temf-oss-rename' => ['handler' => 'handleCloudRenameAction', 'type' => 'oss'],
        'temf-upyun-list' => ['handler' => 'handleCloudStorageList', 'type' => 'upyun'],
        'temf-upyun-upload' => ['handler' => 'handleCloudStorageUpload', 'type' => 'upyun'],
        'temf-upyun-delete' => ['handler' => 'handleCloudDeleteAction', 'type' => 'upyun'],
        'temf-upyun-rename' => ['handler' => 'handleCloudRenameAction', 'type' => 'upyun'],
        'temf-lsky-list' => ['handler' => 'handleLskyListAction', 'type' => 'lsky'],
        'temf-lsky-upload' => ['handler' => 'handleCloudStorageUpload', 'type' => 'lsky'],
        'temf-lsky-delete' => ['handler' => 'handleCloudDeleteAction', 'type' => 'lsky'],
        'temf-local-upload' => ['handler' => 'handleLocalUploadAction', 'type' => 'local'],
        'temf-local-rename' => ['handler' => 'handleLocalRenameAction', 'type' => null],
        'temf-local-delete' => ['handler' => 'handleLocalDeleteAction', 'type' => null],
        'temf-storage-types' => ['handler' => 'handleGetStorageTypesAction', 'type' => null],
        'temf-multi-list' => ['handler' => 'handleMultiListAction', 'type' => 'multi'],
        'temf-multi-upload' => ['handler' => 'handleMultiUploadAction', 'type' => 'multi'],
        'temf-multi-rename' => ['handler' => 'handleMultiRenameAction', 'type' => null],
        'temf-multi-delete' => ['handler' => 'handleMultiDeleteAction', 'type' => null],
        'temf-test-upyun' => ['handler' => 'handleTestUpyunConnection', 'type' => null],
    ];
    
    public function __construct($request = null, $response = null, $params = null)
    {
        parent::__construct($request, $response, $params);
        $this->config = ConfigManager::getInstance();
    }
    
    public function action()
    {
        if (!defined('__TYPECHO_ROOT_DIR__')) {
            exit;
        }
        
        \Widget\Security::alloc()->protect();

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // 使用映射表处理动作，减少重复代码
        foreach (self::$actionMap as $actionName => $actionConfig) {
            if (strpos($uri, $actionName) !== false) {
                $handler = $actionConfig['handler'];
                $type = $actionConfig['type'];
                
                if ($type !== null && method_exists($this, $handler)) {
                    $this->$handler($type);
                } elseif (method_exists($this, $handler)) {
                    $this->$handler();
                }
                return;
            }
        }
        
        $this->sendJsonResponse(['ok' => false, 'msg' => 'Unknown action']);
    }
    
    /**
     * 通用云存储列表处理 - 合并COS/OSS逻辑
     */
    private function handleCloudStorageList($storageType)
    {
        try {
            $serviceClass = $this->getServiceClass($storageType);
            if (!$serviceClass) {
                $this->sendJsonResponse(['folders' => [], 'files' => []]);
                return;
            }
            
            $service = new $serviceClass($this->config);
            $path = $this->request->get('temf_path', '');
            $result = $service->getFileList($path);
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['folders' => [], 'files' => []]);
        }
    }

    private function handleCloudRenameAction($storageType)
    {
        try {
            $service = $this->getService($storageType);
            if (!$service) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'Service not found']);
                return;
            }

            if (!method_exists($service, 'renameFile')) {
                $this->sendJsonResponse(['ok' => false, 'msg' => '当前存储暂不支持重命名']);
                return;
            }

            $fileUrl = $this->request->get('file_url', '');
            $newName = $this->request->get('new_name', '');
            $fileId = $this->request->get('file_id', '');

            if ($fileUrl === '' || $newName === '') {
                $this->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $result = $service->renameFile($fileUrl, $newName, $fileId);
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => '重命名失败: ' . $e->getMessage()]);
        }
    }

    private function handleCloudDeleteAction($storageType)
    {
        try {
            $service = $this->getService($storageType);
            if (!$service) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'Service not found']);
                return;
            }

            if (!method_exists($service, 'deleteFile')) {
                $this->sendJsonResponse(['ok' => false, 'msg' => '当前存储暂不支持删除']);
                return;
            }

            $fileUrls = $this->request->getArray('file_urls');
            if (!is_array($fileUrls) || empty($fileUrls)) {
                $single = $this->request->get('file_url', '');
                $fileUrls = $single !== '' ? [$single] : [];
            }

            if (empty($fileUrls)) {
                $this->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $fileIds = $this->request->getArray('file_ids');
            $results = [];
            foreach ($fileUrls as $index => $url) {
                $fileId = null;
                if (is_array($fileIds)) {
                    if (array_key_exists($index, $fileIds)) {
                        $fileId = $fileIds[$index];
                    } elseif (isset($fileIds[$url])) {
                        $fileId = $fileIds[$url];
                    }
                }

                $results[] = $service->deleteFile($url, $fileId);
            }

            foreach ($results as $res) {
                if (!$res['ok']) {
                    $this->sendJsonResponse(['ok' => false, 'results' => $results]);
                    return;
                }
            }

            $this->sendJsonResponse(['ok' => true]);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
    }

    private function handleMultiDeleteAction()
    {
        try {
            $storageType = $this->request->get('storage_type', '');
            $fileUrls = $this->request->getArray('file_urls');
            if (!is_array($fileUrls) || empty($fileUrls)) {
                $single = $this->request->get('file_url', '');
                $fileUrls = $single !== '' ? [$single] : [];
            }

            if ($storageType === '' || empty($fileUrls)) {
                $this->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $service = $this->getService($storageType);
            if (!$service) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'Service not found']);
                return;
            }

            if (!method_exists($service, 'deleteFile')) {
                $this->sendJsonResponse(['ok' => false, 'msg' => '当前存储暂不支持删除']);
                return;
            }

            $fileIds = $this->request->getArray('file_ids');
            $results = [];
            foreach ($fileUrls as $index => $url) {
                $fileId = null;
                if (is_array($fileIds)) {
                    if (array_key_exists($index, $fileIds)) {
                        $fileId = $fileIds[$index];
                    } elseif (isset($fileIds[$url])) {
                        $fileId = $fileIds[$url];
                    }
                }

                $results[] = $service->deleteFile($url, $fileId);
            }

            $hasError = false;
            foreach ($results as $res) {
                if (!$res['ok']) {
                    $hasError = true;
                    break;
                }
            }

            if ($hasError) {
                $this->sendJsonResponse(['ok' => false, 'results' => $results]);
            } else {
                $this->sendJsonResponse(['ok' => true]);
            }
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 通用云存储上传处理 - 合并COS/OSS/LSKY逻辑
     */
    private function handleCloudStorageUpload($storageType)
    {
        try {
            $serviceClass = $this->getServiceClass($storageType);
            if (!$serviceClass) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'Storage type not supported']);
                return;
            }
            
            if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'No file uploaded']);
                return;
            }

            $service = new $serviceClass($this->config);
            $filePath = $_FILES['file']['tmp_name'];
            $fileName = basename($_FILES['file']['name']);
            $targetPath = $this->request->get('temf_path', '');
            
            $result = $service->uploadFile($filePath, $fileName, $targetPath);
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'Upload failed']);
        }
    }
    
    /**
     * 获取服务类名
     */
    private function getServiceClass($storageType)
    {
        $serviceMap = [
            'cos' => CosService::class,
            'oss' => OssService::class,
            'upyun' => UpyunService::class,
            'lsky' => LskyService::class,
        ];
        
        return $serviceMap[$storageType] ?? null;
    }
    
    /**
     * 获取服务实例（带缓存）
     * 避免重复创建相同的 Service 对象
     */
    private function getService($storageType)
    {
        // 检查缓存
        if (isset($this->serviceCache[$storageType])) {
            return $this->serviceCache[$storageType];
        }
        
        // 处理 local 类型
        if ($storageType === 'local') {
            $this->serviceCache[$storageType] = new \TypechoPlugin\TEMediaFolder\Services\LocalFileService($this->config);
            return $this->serviceCache[$storageType];
        }
        
        // 创建其他类型的服务实例
        $serviceClass = $this->getServiceClass($storageType);
        if (!$serviceClass) {
            return null;
        }
        
        // 缓存实例
        $this->serviceCache[$storageType] = new $serviceClass($this->config);
        return $this->serviceCache[$storageType];
    }
    
    private function handleLocalRenameAction()
    {
        try {
            $fileUrl = $this->request->get('file_url', '');
            $newName = $this->request->get('new_name', '');

            if ($fileUrl === '' || $newName === '') {
                $this->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $service = $this->getService('local');
            if (!$service) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'Service not found']);
                return;
            }

            $result = $service->renameFile($fileUrl, $newName);
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => '重命名失败: ' . $e->getMessage()]);
        }
    }

    private function handleLocalDeleteAction()
    {
        try {
            $fileUrls = $this->request->getArray('file_urls');
            if (!is_array($fileUrls) || empty($fileUrls)) {
                $single = $this->request->get('file_url', '');
                $fileUrls = $single !== '' ? [$single] : [];
            }

            if (empty($fileUrls)) {
                $this->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $service = $this->getService('local');
            if (!$service) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'Service not found']);
                return;
            }

            $results = [];
            foreach ($fileUrls as $url) {
                $results[] = $service->deleteFile($url);
            }

            $hasError = false;
            foreach ($results as $res) {
                if (!$res['ok']) {
                    $hasError = true;
                    break;
                }
            }

            if ($hasError) {
                $this->sendJsonResponse(['ok' => false, 'results' => $results]);
            } else {
                $this->sendJsonResponse(['ok' => true]);
            }
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
    }

    private function handleMultiRenameAction()
    {
        try {
            $storageType = $this->request->get('storage_type', '');
            $fileUrl = $this->request->get('file_url', '');
            $newName = $this->request->get('new_name', '');
            $fileId = $this->request->get('file_id', '');

            if ($storageType === '' || $fileUrl === '' || $newName === '') {
                $this->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $service = $this->getService($storageType);
            if (!$service) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'Service not found']);
                return;
            }

            if (!method_exists($service, 'renameFile')) {
                $this->sendJsonResponse(['ok' => false, 'msg' => '当前存储暂不支持重命名']);
                return;
            }

            $result = $service->renameFile($fileUrl, $newName, $fileId);
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => '重命名失败: ' . $e->getMessage()]);
        }
    }

    private function handleLocalUploadAction()
    {
        try {
            if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'No file uploaded']);
                return;
            }
            
            $filePath = $_FILES['file']['tmp_name'];
            $fileName = basename($_FILES['file']['name']);
            $targetPath = $this->request->get('temf_path', '');
            
            // 使用LocalFileService的uploadFile方法，包含图片压缩功能
            $localService = new \TypechoPlugin\TEMediaFolder\Services\LocalFileService($this->config);
            $result = $localService->uploadFile($filePath, $fileName, $targetPath);
            
            $this->sendJsonResponse($result);
            
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'Upload failed: ' . $e->getMessage()]);
        }
    }
    
    private function handleLskyListAction()
    {
        try {
            $lskyService = new LskyService($this->config);
            $path = $this->request->get('temf_path', '');
            
            // 检查是否请求相册模式
            $useAlbum = $this->request->get('use_album', '0') === '1';
            if ($useAlbum) {
                // 使用相册ID获取图片列表（相册模式）
                $result = $lskyService->getFileList('', true); // 第二个参数表示强制使用相册过滤
            } else {
                // 获取全部图片（全部模式）
                $result = $lskyService->getFileList($path, false); // 明确指定不使用相册过滤
            }
            
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['folders' => [], 'files' => []]);
        }
    }
    
    private function handleLskyUploadAction()
    {
        try {
            $lskyService = new LskyService($this->config);
            
            if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'No file uploaded']);
                return;
            }
            
            $filePath = $_FILES['file']['tmp_name'];
            $fileName = basename($_FILES['file']['name']);
            $targetPath = $this->request->get('temf_path', '');
            
            $result = $lskyService->uploadFile($filePath, $fileName, $targetPath);
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'Upload failed']);
        }
    }
    
    /**
     * 获取可用的存储类型
     */
    private function handleGetStorageTypesAction()
    {
        try {
            $types = $this->config->getAvailableStorageTypes();
            $this->sendJsonResponse(['ok' => true, 'types' => $types]);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'Failed to get storage types: ' . $e->getMessage()]);
        }
    }

    /**
     * 多模式文件列表获取（优化版）
     */
    private function handleMultiListAction()
    {
        try {
            $storageType = $this->request->get('storage_type', 'local');
            $path = $this->request->get('temf_path', '');
            
            // 检查存储是否已配置
            if ($storageType !== 'local' && !$this->config->isStorageConfigured($storageType)) {
                $this->sendJsonResponse([
                    'ok' => false, 
                    'msg' => ucfirst($storageType) . ' not configured'
                ]);
                return;
            }
            
            // 获取服务实例（使用缓存）
            $service = $this->getService($storageType);
            if (!$service) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'Service not found']);
                return;
            }
            
            // 特殊处理 Lsky 的相册模式
            if ($storageType === 'lsky') {
                $useAlbum = $this->request->get('use_album', '0') === '1';
                $result = $service->getFileList($path, $useAlbum);
            } else {
                $result = $service->getFileList($path);
            }
            
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse([
                'ok' => false, 
                'msg' => 'Multi list error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 多模式文件上传（优化版）
     */
    private function handleMultiUploadAction()
    {
        try {
            $storageType = $this->request->get('storage_type', 'local');
            
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'No file uploaded']);
                return;
            }
            
            // 检查存储是否已配置
            if ($storageType !== 'local' && !$this->config->isStorageConfigured($storageType)) {
                $this->sendJsonResponse([
                    'ok' => false, 
                    'msg' => ucfirst($storageType) . ' not configured'
                ]);
                return;
            }
            
            // 获取服务实例（使用缓存）
            $service = $this->getService($storageType);
            if (!$service) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'Service not found']);
                return;
            }
            
            $filePath = $_FILES['file']['tmp_name'];
            $fileName = basename($_FILES['file']['name']);
            $targetPath = $this->request->get('temf_path', '');
            
            $result = $service->uploadFile($filePath, $fileName, $targetPath);
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse([
                'ok' => false, 
                'msg' => 'Multi upload error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 测试又拍云连接
     */
    private function handleTestUpyunConnection()
    {
        try {
            // 获取POST参数
            $bucket = isset($_POST['bucket']) ? trim($_POST['bucket']) : '';
            $operator = isset($_POST['operator']) ? trim($_POST['operator']) : '';
            $password = isset($_POST['password']) ? trim($_POST['password']) : '';
            
            // 验证参数
            if (empty($bucket) || empty($operator) || empty($password)) {
                $this->sendJsonResponse([
                    'success' => false,
                    'message' => '请填写完整的配置信息'
                ]);
                return;
            }
            
            // 构造临时配置
            $testConfig = [
                'bucket' => $bucket,
                'operator' => $operator,
                'password' => $password
            ];
            
            // 生成签名
            $method = 'GET';
            $uri = "/{$bucket}/";
            $date = gmdate('D, d M Y H:i:s \G\M\T');
            
            // 签名字符串
            $signString = "{$method}&{$uri}&{$date}";
            
            // 使用密码MD5作为密钥进行 HMAC-SHA1 签名
            $passwordMD5 = md5($password);
            $signature = base64_encode(hash_hmac('sha1', $signString, $passwordMD5, true));
            $authorization = "UPYUN {$operator}:{$signature}";
            
            // 发送测试请求
            $url = "https://v0.api.upyun.com{$uri}";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: {$authorization}",
                "Date: {$date}",
                "User-Agent: TEMediaFolder/1.0"
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            // 判断结果
            if ($httpCode === 200) {
                // 成功
                $lines = !empty($response) ? explode("\n", trim($response)) : [];
                $fileCount = count($lines);
                
                $this->sendJsonResponse([
                    'success' => true,
                    'message' => "连接成功！已授权访问服务 '{$bucket}'",
                    'permissions' => '读取、写入（已测试读取权限）',
                    'fileCount' => $fileCount
                ]);
            } elseif ($httpCode === 401) {
                // 认证失败
                $errorData = json_decode($response, true);
                $errorMsg = isset($errorData['msg']) ? $errorData['msg'] : '认证失败';
                $errorCode = isset($errorData['code']) ? $errorData['code'] : '';
                
                $detailMsg = '';
                if ($errorCode === 40100005) {
                    $detailMsg = '签名错误。请确认操作员密码是否正确。';
                } elseif ($errorCode === 40100006) {
                    $detailMsg = '操作员不存在。请确认操作员账号是否正确。';
                } elseif ($errorCode === 40100007) {
                    $detailMsg = "操作员未授权到服务 '{$bucket}'。请在又拍云控制台授权操作员。";
                } elseif ($errorCode === 40100012) {
                    $detailMsg = "服务 '{$bucket}' 不存在。请确认服务名称是否正确。";
                } else {
                    $detailMsg = $errorMsg;
                }
                
                $this->sendJsonResponse([
                    'success' => false,
                    'message' => "认证失败 (HTTP 401)\n\n{$detailMsg}"
                ]);
            } elseif ($curlError) {
                // cURL错误
                $this->sendJsonResponse([
                    'success' => false,
                    'message' => "网络错误：{$curlError}"
                ]);
            } else {
                // 其他错误
                $errorData = json_decode($response, true);
                $errorMsg = isset($errorData['msg']) ? $errorData['msg'] : '未知错误';
                
                $this->sendJsonResponse([
                    'success' => false,
                    'message' => "请求失败 (HTTP {$httpCode})\n\n{$errorMsg}"
                ]);
            }
        } catch (\Exception $e) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => '测试失败：' . $e->getMessage()
            ]);
        }
    }

    private function sendJsonResponse($data)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}