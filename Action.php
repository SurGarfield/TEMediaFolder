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

class TEMediaFolder_Action extends \Typecho_Widget implements \Widget\ActionInterface
{
    private $config;
    
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
        
        if (strpos($uri, 'temf-cos-list') !== false) {
            $this->handleCosListAction();
        } elseif (strpos($uri, 'temf-cos-upload') !== false) {
            $this->handleCosUploadAction();
        } elseif (strpos($uri, 'temf-oss-list') !== false) {
            $this->handleOssListAction();
        } elseif (strpos($uri, 'temf-oss-upload') !== false) {
            $this->handleOssUploadAction();
        } elseif (strpos($uri, 'temf-local-upload') !== false) {
            $this->handleLocalUploadAction();
        } elseif (strpos($uri, 'temf-lsky-list') !== false) {
            $this->handleLskyListAction();
        } elseif (strpos($uri, 'temf-lsky-upload') !== false) {
            $this->handleLskyUploadAction();
        } elseif (strpos($uri, 'temf-storage-types') !== false) {
            $this->handleGetStorageTypesAction();
        } elseif (strpos($uri, 'temf-multi-list') !== false) {
            $this->handleMultiListAction();
        } elseif (strpos($uri, 'temf-multi-upload') !== false) {
            $this->handleMultiUploadAction();
        } else {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'Unknown action']);
        }
    }
    
    private function handleCosListAction()
    {
        try {
            $cosService = new CosService($this->config);
            $path = $this->request->get('temf_path', '');
            $result = $cosService->getFileList($path);
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['folders' => [], 'files' => []]);
        }
    }
    
    private function handleCosUploadAction()
    {
        try {
            $cosService = new CosService($this->config);
            
            if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'No file uploaded']);
            return;
        }

            $filePath = $_FILES['file']['tmp_name'];
            $fileName = basename($_FILES['file']['name']);
            $targetPath = $this->request->get('temf_path', '');
            
            $result = $cosService->uploadFile($filePath, $fileName, $targetPath);
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'Upload failed']);
        }
    }
    
    private function handleOssListAction()
    {
        try {
            $ossService = new OssService($this->config);
            $path = $this->request->get('temf_path', '');
            $result = $ossService->getFileList($path);
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['folders' => [], 'files' => []]);
        }
    }
    
    private function handleOssUploadAction()
    {
        try {
            $ossService = new OssService($this->config);
            
            if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'No file uploaded']);
                return;
            }
            
            $filePath = $_FILES['file']['tmp_name'];
            $fileName = basename($_FILES['file']['name']);
            $targetPath = $this->request->get('temf_path', '');
            
            $result = $ossService->uploadFile($filePath, $fileName, $targetPath);
            $this->sendJsonResponse($result);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'Upload failed']);
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
     * 多模式文件列表获取
     */
    private function handleMultiListAction()
    {
        try {
            $storageType = $this->request->get('storage_type', 'local');
            $path = $this->request->get('temf_path', '');
            
            switch ($storageType) {
                case 'cos':
                    if ($this->config->isStorageConfigured('cos')) {
                        $cosService = new CosService($this->config);
                        $result = $cosService->getFileList($path);
                        $this->sendJsonResponse($result);
                    } else {
                        $this->sendJsonResponse(['ok' => false, 'msg' => 'COS not configured']);
                    }
                    break;
                    
                case 'oss':
                    if ($this->config->isStorageConfigured('oss')) {
                        $ossService = new OssService($this->config);
                        $result = $ossService->getFileList($path);
                        $this->sendJsonResponse($result);
                    } else {
                        $this->sendJsonResponse(['ok' => false, 'msg' => 'OSS not configured']);
                    }
                    break;
                    
                case 'lsky':
                    if ($this->config->isStorageConfigured('lsky')) {
                        $lskyService = new LskyService($this->config);
                        
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
                    } else {
                        $this->sendJsonResponse(['ok' => false, 'msg' => 'Lsky not configured']);
                    }
                    break;
                    
                case 'local':
                default:
                    $localService = new \TypechoPlugin\TEMediaFolder\Services\LocalFileService($this->config);
                    $result = $localService->getFileList($path);
                    $this->sendJsonResponse($result);
                    break;
            }
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'Multi list error: ' . $e->getMessage()]);
        }
    }

    /**
     * 多模式文件上传
     */
    private function handleMultiUploadAction()
    {
        try {
            $storageType = $this->request->get('storage_type', 'local');
            
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $this->sendJsonResponse(['ok' => false, 'msg' => 'No file uploaded']);
                return;
            }
            
            $filePath = $_FILES['file']['tmp_name'];
            $fileName = basename($_FILES['file']['name']);
            $targetPath = $this->request->get('temf_path', '');
            
            switch ($storageType) {
                case 'cos':
                    if ($this->config->isStorageConfigured('cos')) {
                        $cosService = new CosService($this->config);
                        $result = $cosService->uploadFile($filePath, $fileName, $targetPath);
                        $this->sendJsonResponse($result);
                    } else {
                        $this->sendJsonResponse(['ok' => false, 'msg' => 'COS not configured']);
                    }
                    break;
                    
                case 'oss':
                    if ($this->config->isStorageConfigured('oss')) {
                        $ossService = new OssService($this->config);
                        $result = $ossService->uploadFile($filePath, $fileName, $targetPath);
                        $this->sendJsonResponse($result);
                    } else {
                        $this->sendJsonResponse(['ok' => false, 'msg' => 'OSS not configured']);
                    }
                    break;
                    
                case 'lsky':
                    if ($this->config->isStorageConfigured('lsky')) {
                        $lskyService = new LskyService($this->config);
                        $result = $lskyService->uploadFile($filePath, $fileName, $targetPath);
                        $this->sendJsonResponse($result);
                    } else {
                        $this->sendJsonResponse(['ok' => false, 'msg' => 'Lsky not configured']);
                    }
                    break;
                    
                case 'local':
                default:
                    $localService = new \TypechoPlugin\TEMediaFolder\Services\LocalFileService($this->config);
                    $result = $localService->uploadFile($filePath, $fileName, $targetPath);
                    $this->sendJsonResponse($result);
                    break;
            }
        } catch (\Exception $e) {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'Multi upload error: ' . $e->getMessage()]);
        }
    }

    private function sendJsonResponse($data)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}