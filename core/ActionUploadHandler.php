<?php

namespace TypechoPlugin\TEMediaFolder\Core;

use TypechoPlugin\TEMediaFolder\Services\LskyService;

class ActionUploadHandler
{
    private $action;

    public function __construct($action)
    {
        $this->action = $action;
    }

    public function cloudStorageUpload($storageType)
    {
        try {
            $serviceClass = $this->action->getServiceClass($storageType);
            if (!$serviceClass) {
                $this->action->sendJsonResponse(['ok' => false, 'msg' => 'Storage type not supported']);
                return;
            }

            $upload = $this->action->getUploadedFileInfo();
            if ($upload === null) {
                return;
            }

            $service = new $serviceClass($this->action->getActionConfig());
            $request = $this->action->getActionRequest();

            $filePath = $upload['tmp_name'];
            $fileName = $upload['name'];
            $targetPath = $request->get('temf_path', '');

            $result = $service->uploadFile($filePath, $fileName, $targetPath);
            $this->action->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['ok' => false, 'msg' => 'Upload failed']);
        }
    }

    public function localUpload()
    {
        try {
            $upload = $this->action->getUploadedFileInfo();
            if ($upload === null) {
                return;
            }

            $request = $this->action->getActionRequest();
            $filePath = $upload['tmp_name'];
            $fileName = $upload['name'];
            $targetPath = $request->get('temf_path', '');
            $targetYear = $request->get('temf_year', '');
            $targetMonth = $request->get('temf_month', '');

            $localService = $this->action->getServiceOrFail('local');
            if ($localService === null) {
                return;
            }

            $result = $localService->uploadFile($filePath, $fileName, $targetPath, $targetYear, $targetMonth);
            $this->action->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['ok' => false, 'msg' => 'Upload failed: ' . $e->getMessage()]);
        }
    }

    public function lskyUpload()
    {
        try {
            $upload = $this->action->getUploadedFileInfo();
            if ($upload === null) {
                return;
            }

            $request = $this->action->getActionRequest();
            $service = new LskyService($this->action->getActionConfig());

            $filePath = $upload['tmp_name'];
            $fileName = $upload['name'];
            $targetPath = $request->get('temf_path', '');

            $result = $service->uploadFile($filePath, $fileName, $targetPath);
            $this->action->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['ok' => false, 'msg' => 'Upload failed']);
        }
    }

    public function multiUpload()
    {
        try {
            $request = $this->action->getActionRequest();
            $storageType = $request->get('storage_type', 'local');

            $upload = $this->action->getUploadedFileInfo();
            if ($upload === null) {
                return;
            }

            if (!$this->action->ensureStorageConfigured($storageType)) {
                return;
            }

            $service = $this->action->getServiceOrFail($storageType);
            if ($service === null) {
                return;
            }

            $filePath = $upload['tmp_name'];
            $fileName = $upload['name'];
            $targetPath = $request->get('temf_path', '');

            $result = $service->uploadFile($filePath, $fileName, $targetPath);
            $this->action->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse([
                'ok' => false,
                'msg' => 'Multi upload error: ' . $e->getMessage()
            ]);
        }
    }

    public function testUpyunConnection()
    {
        try {
            $bucket = isset($_POST['bucket']) ? trim($_POST['bucket']) : '';
            $operator = isset($_POST['operator']) ? trim($_POST['operator']) : '';
            $password = isset($_POST['password']) ? trim($_POST['password']) : '';

            if (empty($bucket) || empty($operator) || empty($password)) {
                $this->action->sendJsonResponse([
                    'success' => false,
                    'message' => '请填写完整的配置信息'
                ]);
                return;
            }

            $method = 'GET';
            $uri = "/{$bucket}/";
            $date = gmdate('D, d M Y H:i:s \G\M\T');
            $signString = "{$method}&{$uri}&{$date}";
            $passwordMD5 = md5($password);
            $signature = base64_encode(hash_hmac('sha1', $signString, $passwordMD5, true));
            $authorization = "UPYUN {$operator}:{$signature}";

            $url = "https://v0.api.upyun.com{$uri}";
            $requestResult = HttpClient::request($url, 'GET', [
                "Authorization: {$authorization}",
                "Date: {$date}",
                "User-Agent: TEMediaFolder/1.0"
            ], null, [
                'timeout' => 10,
                'connect_timeout' => 5,
                'verify_ssl' => false,
                'follow_location' => false,
                'max_redirs' => 0
            ]);

            $response = (string)($requestResult['body'] ?? '');
            $httpCode = (int)($requestResult['status'] ?? 0);
            $curlError = (string)($requestResult['error'] ?? '');

            if ($httpCode === 200) {
                $lines = !empty($response) ? explode("\n", trim($response)) : [];
                $fileCount = count($lines);

                $this->action->sendJsonResponse([
                    'success' => true,
                    'message' => "连接成功！已授权访问服务 '{$bucket}'",
                    'permissions' => '读取、写入（已测试读取权限）',
                    'fileCount' => $fileCount
                ]);
            } elseif ($httpCode === 401) {
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

                $this->action->sendJsonResponse([
                    'success' => false,
                    'message' => "认证失败 (HTTP 401)\n\n{$detailMsg}"
                ]);
            } elseif ($curlError) {
                $this->action->sendJsonResponse([
                    'success' => false,
                    'message' => "网络错误：{$curlError}"
                ]);
            } else {
                $errorData = json_decode($response, true);
                $errorMsg = isset($errorData['msg']) ? $errorData['msg'] : '未知错误';

                $this->action->sendJsonResponse([
                    'success' => false,
                    'message' => "请求失败 (HTTP {$httpCode})\n\n{$errorMsg}"
                ]);
            }
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse([
                'success' => false,
                'message' => '测试失败：' . $e->getMessage()
            ]);
        }
    }
}
