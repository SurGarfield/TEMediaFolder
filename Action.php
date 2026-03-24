<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Plugin.php';

use TypechoPlugin\TEMediaFolder\Core\ActionFileOpsHandler;
use TypechoPlugin\TEMediaFolder\Core\ActionListHandler;
use TypechoPlugin\TEMediaFolder\Core\ActionUploadHandler;
use TypechoPlugin\TEMediaFolder\Core\ConfigManager;
use TypechoPlugin\TEMediaFolder\Services\CosService;
use TypechoPlugin\TEMediaFolder\Services\BitifulService;
use TypechoPlugin\TEMediaFolder\Services\LocalFileService;
use TypechoPlugin\TEMediaFolder\Services\LskyService;
use TypechoPlugin\TEMediaFolder\Services\OssService;
use TypechoPlugin\TEMediaFolder\Services\UpyunService;

class TEMediaFolder_Action extends \Typecho_Widget implements \Widget\ActionInterface
{
    private $config;
    private $serviceCache = [];
    private $listHandler;
    private $uploadHandler;
    private $fileOpsHandler;

    private static $actionMap = [
        'temf-cos-list' => ['handler' => 'handleCloudStorageList', 'type' => 'cos'],
        'temf-cos-upload' => ['handler' => 'handleCloudStorageUpload', 'type' => 'cos'],
        'temf-cos-delete' => ['handler' => 'handleCloudDeleteAction', 'type' => 'cos'],
        'temf-cos-rename' => ['handler' => 'handleCloudRenameAction', 'type' => 'cos'],
        'temf-oss-list' => ['handler' => 'handleCloudStorageList', 'type' => 'oss'],
        'temf-oss-upload' => ['handler' => 'handleCloudStorageUpload', 'type' => 'oss'],
        'temf-oss-delete' => ['handler' => 'handleCloudDeleteAction', 'type' => 'oss'],
        'temf-oss-rename' => ['handler' => 'handleCloudRenameAction', 'type' => 'oss'],
        'temf-bitiful-list' => ['handler' => 'handleCloudStorageList', 'type' => 'bitiful'],
        'temf-bitiful-upload' => ['handler' => 'handleCloudStorageUpload', 'type' => 'bitiful'],
        'temf-bitiful-delete' => ['handler' => 'handleCloudDeleteAction', 'type' => 'bitiful'],
        'temf-bitiful-rename' => ['handler' => 'handleCloudRenameAction', 'type' => 'bitiful'],
        'temf-upyun-list' => ['handler' => 'handleCloudStorageList', 'type' => 'upyun'],
        'temf-upyun-upload' => ['handler' => 'handleCloudStorageUpload', 'type' => 'upyun'],
        'temf-upyun-delete' => ['handler' => 'handleCloudDeleteAction', 'type' => 'upyun'],
        'temf-upyun-rename' => ['handler' => 'handleCloudRenameAction', 'type' => 'upyun'],
        'temf-lsky-list' => ['handler' => 'handleLskyListAction', 'type' => 'lsky'],
        'temf-lsky-upload' => ['handler' => 'handleCloudStorageUpload', 'type' => 'lsky'],
        'temf-lsky-delete' => ['handler' => 'handleCloudDeleteAction', 'type' => 'lsky'],
        'temf-local-list' => ['handler' => 'handleLocalListAction', 'type' => null],
        'temf-local-upload' => ['handler' => 'handleLocalUploadAction', 'type' => 'local'],
        'temf-local-rename' => ['handler' => 'handleLocalRenameAction', 'type' => null],
        'temf-local-delete' => ['handler' => 'handleLocalDeleteAction', 'type' => null],
        'temf-storage-types' => ['handler' => 'handleGetStorageTypesAction', 'type' => null],
        'temf-multi-list' => ['handler' => 'handleMultiListAction', 'type' => 'multi'],
        'temf-multi-upload' => ['handler' => 'handleMultiUploadAction', 'type' => 'multi'],
        'temf-multi-rename' => ['handler' => 'handleMultiRenameAction', 'type' => null],
        'temf-multi-delete' => ['handler' => 'handleMultiDeleteAction', 'type' => null],
        'temf-test-upyun' => ['handler' => 'handleTestUpyunConnection', 'type' => null],
        'temf-test-bitiful' => ['handler' => 'handleTestBitifulConnection', 'type' => null],
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
        $actionConfig = $this->resolveActionConfig($_SERVER['REQUEST_URI'] ?? '');
        if ($actionConfig !== null) {
            $handler = $actionConfig['handler'];
            $type = $actionConfig['type'];

            if ($type !== null && method_exists($this, $handler)) {
                $this->$handler($type);
            } elseif (method_exists($this, $handler)) {
                $this->$handler();
            }
            return;
        }

        $this->sendJsonResponse(['ok' => false, 'msg' => 'Unknown action']);
    }

    private function handleCloudStorageList($storageType)
    {
        $this->getListHandler()->cloudStorageList($storageType);
    }

    private function handleCloudRenameAction($storageType)
    {
        $this->getFileOpsHandler()->cloudRename($storageType);
    }

    private function handleCloudDeleteAction($storageType)
    {
        $this->getFileOpsHandler()->cloudDelete($storageType);
    }

    private function handleMultiDeleteAction()
    {
        $this->getFileOpsHandler()->multiDelete();
    }

    private function handleCloudStorageUpload($storageType)
    {
        $this->getUploadHandler()->cloudStorageUpload($storageType);
    }

    private function handleLocalListAction()
    {
        $this->getListHandler()->localList();
    }

    private function handleLocalRenameAction()
    {
        $this->getFileOpsHandler()->localRename();
    }

    private function handleLocalUploadAction()
    {
        $this->getUploadHandler()->localUpload();
    }

    private function handleLocalDeleteAction()
    {
        $this->getFileOpsHandler()->localDelete();
    }

    private function handleMultiRenameAction()
    {
        $this->getFileOpsHandler()->multiRename();
    }

    private function handleLskyListAction()
    {
        $this->getListHandler()->lskyList();
    }

    private function handleLskyUploadAction()
    {
        $this->getUploadHandler()->lskyUpload();
    }

    private function handleGetStorageTypesAction()
    {
        $this->getListHandler()->storageTypes();
    }

    private function handleMultiListAction()
    {
        $this->getListHandler()->multiList();
    }

    private function handleMultiUploadAction()
    {
        $this->getUploadHandler()->multiUpload();
    }

    private function handleTestUpyunConnection()
    {
        $this->getUploadHandler()->testUpyunConnection();
    }

    private function handleTestBitifulConnection()
    {
        $this->getUploadHandler()->testBitifulConnection();
    }

    public function getServiceClass($storageType)
    {
        $serviceMap = [
            'cos' => CosService::class,
            'oss' => OssService::class,
            'bitiful' => BitifulService::class,
            'upyun' => UpyunService::class,
            'lsky' => LskyService::class,
        ];

        return $serviceMap[$storageType] ?? null;
    }

    public function getService($storageType)
    {
        if (isset($this->serviceCache[$storageType])) {
            return $this->serviceCache[$storageType];
        }

        if ($storageType === 'local') {
            $this->serviceCache[$storageType] = new LocalFileService($this->config);
            return $this->serviceCache[$storageType];
        }

        $serviceClass = $this->getServiceClass($storageType);
        if (!$serviceClass) {
            return null;
        }

        $this->serviceCache[$storageType] = new $serviceClass($this->config);
        return $this->serviceCache[$storageType];
    }

    public function getServiceOrFail($storageType)
    {
        $service = $this->getService($storageType);
        if (!$service) {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'Service not found']);
            return null;
        }

        return $service;
    }

    public function ensureStorageConfigured($storageType)
    {
        if ($storageType === 'local') {
            return true;
        }

        if ($this->config->isStorageConfigured($storageType)) {
            return true;
        }

        $this->sendJsonResponse([
            'ok' => false,
            'msg' => ucfirst($storageType) . ' not configured'
        ]);
        return false;
    }

    public function collectFileUrls()
    {
        $fileUrls = $this->request->getArray('file_urls');
        if (is_array($fileUrls) && !empty($fileUrls)) {
            return $fileUrls;
        }

        $single = $this->request->get('file_url', '');
        return $single !== '' ? [$single] : [];
    }

    public function resolveFileId($fileIds, $index, $url)
    {
        if (!is_array($fileIds)) {
            return null;
        }

        if (array_key_exists($index, $fileIds)) {
            return $fileIds[$index];
        }

        if (isset($fileIds[$url])) {
            return $fileIds[$url];
        }

        return null;
    }

    public function sendJsonResponse($data)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function getUploadedFileInfo()
    {
        if (!isset($_FILES['file'])) {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'No file uploaded']);
            return null;
        }

        $uploadError = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $this->sendJsonResponse(['ok' => false, 'msg' => $this->getUploadErrorMessage($uploadError)]);
            return null;
        }

        $tmpName = $_FILES['file']['tmp_name'] ?? '';
        if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
            $this->sendJsonResponse(['ok' => false, 'msg' => 'Invalid upload file']);
            return null;
        }

        return [
            'tmp_name' => $tmpName,
            'name' => basename((string)($_FILES['file']['name'] ?? ''))
        ];
    }

    public function getActionRequest()
    {
        return $this->request;
    }

    public function getActionConfig()
    {
        return $this->config;
    }

    private function getUploadErrorMessage($errorCode)
    {
        switch ((int)$errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'File too large for server upload_max_filesize';
            case UPLOAD_ERR_FORM_SIZE:
                return 'File too large for form limit';
            case UPLOAD_ERR_PARTIAL:
                return 'File upload was interrupted';
            case UPLOAD_ERR_NO_FILE:
                return 'No file uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Server temp directory missing';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Server failed to write uploaded file';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload blocked by server extension';
            default:
                return 'Upload failed';
        }
    }

    private function getListHandler()
    {
        if ($this->listHandler === null) {
            $this->listHandler = new ActionListHandler($this);
        }
        return $this->listHandler;
    }

    private function resolveActionConfig($requestUri)
    {
        $path = parse_url((string)$requestUri, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $actionName = trim((string)basename($path));
            if (isset(self::$actionMap[$actionName])) {
                return self::$actionMap[$actionName];
            }
        }

        foreach (self::$actionMap as $name => $config) {
            if (strpos((string)$requestUri, $name) !== false) {
                return $config;
            }
        }

        return null;
    }

    private function getUploadHandler()
    {
        if ($this->uploadHandler === null) {
            $this->uploadHandler = new ActionUploadHandler($this);
        }
        return $this->uploadHandler;
    }

    private function getFileOpsHandler()
    {
        if ($this->fileOpsHandler === null) {
            $this->fileOpsHandler = new ActionFileOpsHandler($this);
        }
        return $this->fileOpsHandler;
    }
}
