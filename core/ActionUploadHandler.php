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
            $result = $this->performStandardUpload($service, $upload);
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

            $service = new LskyService($this->action->getActionConfig());
            $result = $this->performStandardUpload($service, $upload);
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

            $result = $this->performStandardUpload($service, $upload);
            $this->action->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse([
                'ok' => false,
                'msg' => 'Multi upload error: ' . $e->getMessage()
            ]);
        }
    }

    private function performStandardUpload($service, array $upload)
    {
        $request = $this->action->getActionRequest();
        $filePath = $upload['tmp_name'];
        $fileName = $upload['name'];
        $targetPath = $request->get('temf_path', '');

        return $service->uploadFile($filePath, $fileName, $targetPath);
    }

    public function testUpyunConnection()
    {
        if (isset($_POST['provider']) && trim((string)$_POST['provider']) === 'bitiful') {
            $this->testBitifulConnection();
            return;
        }

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

    public function testBitifulConnection()
    {
        try {
            $bucket = isset($_POST['bucket']) ? trim((string)$_POST['bucket']) : '';
            $region = isset($_POST['region']) ? trim((string)$_POST['region']) : '';
            $endpoint = isset($_POST['endpoint']) ? trim((string)$_POST['endpoint']) : '';
            $accessKey = isset($_POST['accessKey']) ? trim((string)$_POST['accessKey']) : '';
            $secretKey = isset($_POST['secretKey']) ? trim((string)$_POST['secretKey']) : '';

            if ($bucket === '' || $region === '' || $endpoint === '' || $accessKey === '' || $secretKey === '') {
                $this->action->sendJsonResponse(['success' => false, 'message' => '请填写完整的缤纷云配置信息']);
                return;
            }

            if (!preg_match('#^https?://#i', $endpoint)) {
                $endpoint = 'https://' . $endpoint;
            }
            $endpoint = rtrim($endpoint, '/');
            $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
            $baseHost = parse_url($endpoint, PHP_URL_HOST);
            if (!$baseHost) {
                $baseHost = preg_replace('#^https?://#i', '', $endpoint);
            }
            $host = $bucket . '.' . trim((string)$baseHost, '/');

            $query = [
                'list-type' => '2',
                'delimiter' => '/',
                'max-keys' => '1'
            ];
            $queryString = $this->buildBitifulQueryString($query);
            $payloadHash = hash('sha256', '');
            $amzDate = gmdate('Ymd\THis\Z');
            $date = substr($amzDate, 0, 8);
            $credentialScope = $date . '/' . $region . '/s3/aws4_request';
            $canonicalHeaders = "host:{$host}\n" . "x-amz-content-sha256:{$payloadHash}\n" . "x-amz-date:{$amzDate}\n";
            $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
            $canonicalRequest = "GET\n/\n{$queryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
            $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
            $signature = hash_hmac('sha256', $stringToSign, $this->getBitifulSigningKey($secretKey, $date, $region));
            $authorization = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $credentialScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

            $url = $scheme . '://' . $host . '/?' . $queryString;
            $result = HttpClient::request($url, 'GET', [
                'Host: ' . $host,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $amzDate,
                'Authorization: ' . $authorization,
                'User-Agent: TEMediaFolder/1.0'
            ], null, [
                'timeout' => 10,
                'connect_timeout' => 5,
                'verify_ssl' => false,
                'follow_location' => false,
                'max_redirs' => 0
            ]);

            $status = (int)($result['status'] ?? 0);
            $body = (string)($result['body'] ?? '');
            $error = (string)($result['error'] ?? '');

            if ($status >= 200 && $status < 300) {
                $this->action->sendJsonResponse([
                    'success' => true,
                    'message' => "连接成功！已授权访问存储桶 '{$bucket}'（区域 {$region}）"
                ]);
                return;
            }

            if ($error !== '') {
                $this->action->sendJsonResponse(['success' => false, 'message' => '网络错误：' . $error]);
                return;
            }

            $message = '请求失败 (HTTP ' . $status . ')';
            $xml = @simplexml_load_string($body);
            if ($xml && isset($xml->Message)) {
                $message .= '\n\n' . (string)$xml->Message;
            }

            $this->action->sendJsonResponse(['success' => false, 'message' => $message]);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['success' => false, 'message' => '测试失败：' . $e->getMessage()]);
        }
    }

    private function buildBitifulQueryString(array $query)
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = [rawurlencode((string)$key), rawurlencode((string)$value)];
        }
        usort($pairs, function ($a, $b) {
            if ($a[0] === $b[0]) {
                return strcmp($a[1], $b[1]);
            }
            return strcmp($a[0], $b[0]);
        });
        $result = [];
        foreach ($pairs as $pair) {
            $result[] = $pair[0] . '=' . $pair[1];
        }
        return implode('&', $result);
    }

    private function getBitifulSigningKey($secretKey, $date, $region)
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
