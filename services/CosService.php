<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;

class CosService extends BaseService
{
    private $cosConfig;

    public function __construct(ConfigManager $config)
    {
        parent::__construct($config);
        $this->cosConfig = $config->getCosConfig();
    }

    public function isConfigured()
    {
        return !empty($this->cosConfig['bucket']) 
            && !empty($this->cosConfig['region']) 
            && !empty($this->cosConfig['secretId']) 
            && !empty($this->cosConfig['secretKey']);
    }

    public function getFileList($path = '')
    {
        if (!$this->isConfigured()) {
            return ['folders' => [], 'files' => []];
        }

        try {
            $fullPrefix = $this->buildPrefix($path);
            $endpoint = $this->getEndpoint();
            $host = $this->getHost();
            
            $query = [
                'prefix' => $fullPrefix,
                'delimiter' => '/',
                'max-keys' => 1000
            ];

            $authorization = $this->generateAuthorization('GET', '/', $query, $host);
            $url = $endpoint . '/?' . http_build_query($query);
            
            $response = $this->makeRequest($url, 'GET', [
                'Host: ' . $host,
                'Authorization: ' . $authorization,
                'User-Agent: TEMediaFolder/1.0'
            ]);

            return $this->parseListResponse($response, $fullPrefix, $path);
        } catch (\Exception $e) {
            return ['folders' => [], 'files' => []];
        }
    }

    public function renameFile($fileUrl, $newBaseName, $fileId = null)
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'msg' => 'Missing COS config'];
        }

        $objectKey = $fileId !== null && $fileId !== '' ? trim($fileId) : $this->extractObjectKey($fileUrl);
        if ($objectKey === '') {
            return ['ok' => false, 'msg' => '无法解析对象路径'];
        }

        $newBaseName = trim((string)$newBaseName);
        if ($newBaseName === '') {
            return ['ok' => false, 'msg' => '文件名不能为空'];
        }

        $pathInfo = pathinfo($objectKey);
        $extension = isset($pathInfo['extension']) ? $pathInfo['extension'] : '';
        $directory = isset($pathInfo['dirname']) ? $pathInfo['dirname'] : '';
        if ($directory === '.' || $directory === DIRECTORY_SEPARATOR) {
            $directory = '';
        }
        $normalizedDirectory = $directory !== '' ? trim(str_replace('\\', '/', $directory), '/') : '';

        $sanitizedBase = $this->sanitizeBaseName($newBaseName);
        if ($sanitizedBase === '') {
            return ['ok' => false, 'msg' => '文件名无效'];
        }

        $newFilename = $extension !== '' ? $sanitizedBase . '.' . $extension : $sanitizedBase;
        $newKey = ($normalizedDirectory !== '' ? $normalizedDirectory . '/' : '') . $newFilename;

        if ($newKey === trim($objectKey, '/')) {
            $publicUrl = $this->getPublicUrl($objectKey);
            $result = [
                'ok' => true,
                'url' => $publicUrl,
                'name' => $newFilename,
                'id' => trim($objectKey, '/'),
                'directory' => $normalizedDirectory
            ];
            if ($this->isImageFile($newFilename)) {
                $result['thumbnail'] = $this->getThumbnailUrl($publicUrl);
            }
            return $result;
        }

        $endpoint = $this->getEndpoint();
        $host = $this->getHost();

        $encodedNewPath = '/' . $this->encodeKey($newKey);
        $encodedSourcePath = $this->encodeKey($objectKey);
        $copySource = '/' . $this->cosConfig['bucket'] . '/' . $encodedSourcePath;

        $signHeaders = [
            'x-cos-copy-source' => $copySource,
            'x-cos-metadata-directive' => 'Copy'
        ];

        try {
            $authorization = $this->generateAuthorization('PUT', $encodedNewPath, [], $host, $signHeaders);
            $requestHeaders = [
                'Host: ' . $host,
                'Authorization: ' . $authorization,
                'User-Agent: TEMediaFolder/1.0',
                'x-cos-copy-source: ' . $copySource,
                'x-cos-metadata-directive: Copy'
            ];

            $this->makeRequest($endpoint . $encodedNewPath, 'PUT', $requestHeaders);

            if ($this->encodeKey($objectKey) !== $this->encodeKey($newKey)) {
                $this->deleteFile($fileUrl, $objectKey);
            }

            $newUrl = $this->getPublicUrl($newKey);
            $result = [
                'ok' => true,
                'url' => $newUrl,
                'name' => $newFilename,
                'id' => $newKey,
                'directory' => $normalizedDirectory
            ];
            if ($this->isImageFile($newFilename)) {
                $result['thumbnail'] = $this->getThumbnailUrl($newUrl);
            }
            return $result;
        } catch (\Exception $e) {
            return ['ok' => false, 'msg' => '重命名失败: ' . $e->getMessage()];
        }
    }

    public function deleteFile($fileUrl, $fileId = null)
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'msg' => 'Missing COS config'];
        }

        $objectKey = $fileId !== null && $fileId !== '' ? trim($fileId) : $this->extractObjectKey($fileUrl);
        if ($objectKey === '') {
            return ['ok' => false, 'msg' => '无法解析对象路径'];
        }

        try {
            $endpoint = $this->getEndpoint();
            $host = $this->getHost();
            $encodedPath = '/' . implode('/', array_map('rawurlencode', explode('/', $objectKey)));
            $authorization = $this->generateAuthorization('DELETE', $encodedPath, [], $host);

            $url = $endpoint . $encodedPath;
            $this->makeRequest($url, 'DELETE', [
                'Host: ' . $host,
                'Authorization: ' . $authorization,
                'User-Agent: TEMediaFolder/1.0'
            ]);

            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'msg' => '删除失败: ' . $e->getMessage()];
        }
    }

    public function uploadFile($filePath, $fileName, $targetPath = '')
    {
        if (!$this->isConfigured()) {
            return $this->buildUploadResult(false, '', '', ['msg' => 'Missing COS config']);
        }

        try {
            // 使用父类方法验证和压缩
            $validation = $this->validateUploadFile($filePath, $fileName);
            if (!$validation['valid']) {
                return $this->buildUploadResult(false, '', '', ['msg' => $validation['error']]);
            }
            
            $compressionResult = $this->processImageCompression($filePath, $fileName, 'cos');
            $processedFilePath = $compressionResult['path'];
            $isCompressed = $compressionResult['compressed'];
            
            // 使用压缩后的文件名（如 .webp）
            $safeFileName = $this->sanitizeFileName(basename($processedFilePath));
            $fullPrefix = $this->buildPrefix($targetPath);
            $objectKey = ltrim($fullPrefix . $safeFileName, '/');
            
            $endpoint = $this->getEndpoint();
            $host = $this->getHost();
            
            $encodedPath = '/' . implode('/', array_map('rawurlencode', explode('/', $objectKey)));
            $authorization = $this->generateAuthorization('PUT', $encodedPath, [], $host);
            
            $url = $endpoint . $encodedPath;            
            $body = file_get_contents($processedFilePath);
            
            if ($body === false) {
                $this->cleanupTempFile($processedFilePath);
                return $this->buildUploadResult(false, '', '', ['msg' => 'Failed to read file content']);
            }
            
            $response = $this->makeRequest($url, 'PUT', [
                'Host: ' . $host,
                'Authorization: ' . $authorization,
                'User-Agent: TEMediaFolder/1.0',
                'Content-Type: application/octet-stream'
            ], $body);

            $publicUrl = $this->getPublicUrl($objectKey);
            
            // 清理临时文件
            $this->cleanupTempFile($processedFilePath);
            
            // 构建返回结果
            $options = [];
            if ($this->isImageFile($safeFileName)) {
                $options['thumbnail'] = $this->getThumbnailUrl($publicUrl);
            }
            if ($isCompressed) {
                $options['compressed'] = true;
                $options['compression_info'] = $compressionResult;
            }
            
            return $this->buildUploadResult(true, $publicUrl, $safeFileName, $options);
        } catch (\Exception $e) {
            return ['ok' => false, 'msg' => 'Upload failed: ' . $e->getMessage()];
        }
    }

    private function buildPrefix($path)
    {
        $prefix = rtrim($this->cosConfig['prefix'], '/');
        if (!empty($path)) {
            $prefix = ($prefix === '' ? '' : $prefix . '/') . trim($path, '/');
        }
        return $prefix === '' ? '' : $prefix . '/';
    }

    private function getEndpoint()
    {
        return "https://" . $this->cosConfig['bucket'] . ".cos." . $this->cosConfig['region'] . ".myqcloud.com";
    }

    private function getHost()
    {
        return $this->cosConfig['bucket'] . ".cos." . $this->cosConfig['region'] . ".myqcloud.com";
    }

    private function getPublicUrl($objectKey)
    {
        $domain = $this->cosConfig['domain'];
        if (!empty($domain)) {
            if (!preg_match('#^https?://#i', $domain)) {
                $domain = 'https://' . $domain;
            }
            return rtrim($domain, '/') . '/' . ltrim($objectKey, '/');
        }
        
        return $this->getEndpoint() . '/' . ltrim($objectKey, '/');
    }


    private function getThumbnailUrl($originalUrl)
    {
        // 获取配置的缩略图尺寸
        $thumbSize = $this->config->get('thumbSize', 120);
 
        return $originalUrl . '?imageMogr2/crop/' . $thumbSize . 'x' . $thumbSize 
            . '/gravity/center'
            . '/rquality/75'
            . '/format/webp';
    }

    private function generateAuthorization($method, $path, $query = [], $host = '', $headers = [])
    {
        $start = time() - 60;
        $end = $start + 3600;
        $qSignTime = $start . ';' . $end;
        $qKeyTime = $qSignTime;
        
        $signKey = hash_hmac('sha1', $qKeyTime, $this->cosConfig['secretKey']);
        
        $lowerParams = [];
        foreach ($query as $k => $v) {
            $lowerParams[strtolower($k)] = (string)$v;
        }
        ksort($lowerParams);
        
        $paramList = implode(';', array_keys($lowerParams));
        $paramStrParts = [];
        foreach ($lowerParams as $k => $v) {
            $paramStrParts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $paramStr = implode('&', $paramStrParts);

        $lowerHeaders = ['host' => strtolower($host)];
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower(trim($key));
            $lowerHeaders[$lowerKey] = trim($value);
        }
        ksort($lowerHeaders);
        $headerList = implode(';', array_keys($lowerHeaders));
        $headerStrParts = [];
        foreach ($lowerHeaders as $k => $v) {
            $headerStrParts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $headerStr = implode('&', $headerStrParts);

        $httpString = strtolower($method) . "\n" . $path . "\n" . $paramStr . "\n" . $headerStr . "\n";
        $httpStringSha1 = sha1($httpString);
        $stringToSign = "sha1\n" . $qSignTime . "\n" . $httpStringSha1 . "\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);
        
        return 'q-sign-algorithm=sha1'
            . '&q-ak=' . rawurlencode($this->cosConfig['secretId'])
            . '&q-sign-time=' . $qSignTime
            . '&q-key-time=' . $qKeyTime
            . '&q-header-list=' . $headerList
            . '&q-url-param-list=' . $paramList
            . '&q-signature=' . $signature;
    }

    private function sanitizeBaseName($baseName)
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9\-_]+/', '_', $baseName);
        $sanitized = trim($sanitized, '._-');
        if ($sanitized === '') {
            $sanitized = 'file_' . date('YmdHis');
        }
        return substr($sanitized, 0, 80);
    }

    private function encodeKey($key)
    {
        $key = ltrim(str_replace(['\\'], '/', $key), '/');
        if ($key === '') {
            return '';
        }
        $segments = array_map('rawurlencode', explode('/', $key));
        return implode('/', $segments);
    }

    private function makeRequest($url, $method = 'GET', $headers = [], $body = null)
    {
        $context = [
            'http' => [
                'method' => $method,
                'timeout' => 30,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true  // 允许获取错误响应
            ]
        ];
        
        if ($body !== null) {
            $context['http']['content'] = $body;
        }
        
        $ctx = stream_context_create($context);
        $response = @file_get_contents($url, false, $ctx);
        
        if ($response === false) {
            $error = error_get_last();
            throw new \Exception('Request failed: ' . ($error['message'] ?? 'Unknown error'));
        }
        
        $httpHeaders = $http_response_header ?? [];
        if (!empty($httpHeaders)) {
            $statusLine = $httpHeaders[0];
            if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches)) {
                $statusCode = (int)$matches[1];
                if ($statusCode >= 400) {
                    $errorMsg = 'HTTP ' . $statusCode;
                    if ($response) {
                        $xml = @simplexml_load_string($response);
                        if ($xml && isset($xml->Message)) {
                            $errorMsg .= ': ' . (string)$xml->Message;
                        } elseif ($xml && isset($xml->Code)) {
                            $errorMsg .= ': ' . (string)$xml->Code;
                        }
                    }
                    throw new \Exception($errorMsg);
                }
            }
        }
        
        return $response;
    }

    private function parseListResponse($response, $fullPrefix, $path)
    {
        $xml = @simplexml_load_string($response);
        if ($xml === false) {
            return ['folders' => [], 'files' => []];
        }

        $folders = [];
        if (isset($xml->CommonPrefixes)) {
            foreach ($xml->CommonPrefixes as $cp) {
                $prefix = (string)$cp->Prefix;
                $name = trim($prefix, '/');
                
                if ($fullPrefix !== '') {
                    $name = substr($name, strlen($fullPrefix));
                }
                
                if ($name !== '') {
                    $folders[] = [
                        'name' => $name,
                        'path' => ($path ? $path . '/' : '') . $name
                    ];
                }
            }
        }

        $files = [];
        if (isset($xml->Contents)) {
            $allowedExtensions = $this->config->get('extensions', ['jpg','jpeg','png','gif','webp','svg']);
            $items = [];
            foreach ($xml->Contents as $content) {
                $key = (string)$content->Key;
                
                if ($key === $fullPrefix) {
                    continue;
                }
                
                $name = basename($key);
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                
                if (!in_array($ext, $allowedExtensions, true)) {
                    continue;
                }
                
                $publicUrl = $this->getPublicUrl($key);
                $lastModified = isset($content->LastModified) ? strtotime((string)$content->LastModified) : 0;
                $fileData = [
                    'name' => $name,
                    'url' => $publicUrl,
                    'mtime' => $lastModified,
                    'id' => $key
                ];
                
                // 为图片文件添加缩略图URL
                if ($this->isImageFile($name)) {
                    $fileData['thumbnail'] = $this->getThumbnailUrl($publicUrl);
                }
                
                $items[] = $fileData;
            }
            // 按修改时间倒序，最新在前
            usort($items, function($a, $b) { return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0); });
            $files = array_merge($files, $items);
        }

        return ['folders' => $folders, 'files' => $files];
    }

    private function extractObjectKey($fileUrl)
    {
        if (empty($fileUrl)) {
            return '';
        }

        $path = parse_url($fileUrl, PHP_URL_PATH);
        if (!$path) {
            return '';
        }

        $key = ltrim($path, '/');
        return rawurldecode($key);
    }
    
    /**
     * 清理文件名，确保安全上传
     */
    private function sanitizeFileName($fileName)
    {
        $pathInfo = pathinfo($fileName);
        $extension = isset($pathInfo['extension']) ? $pathInfo['extension'] : '';
        $baseName = isset($pathInfo['filename']) ? $pathInfo['filename'] : 'file';
        
        $timestamp = date('YmdHis');
        $random = mt_rand(1000, 9999);
        $safeBaseName = preg_replace('/[^a-zA-Z0-9\-_]/', '', $baseName);
        
        if (empty($safeBaseName)) {
            $safeBaseName = 'upload';
        }
        
        $safeBaseName = substr($safeBaseName, 0, 50);
        
        $finalName = $timestamp . '_' . $random . '_' . $safeBaseName;
        if (!empty($extension)) {
            $finalName .= '.' . $extension;
        }
        
        return $finalName;
    }
}
