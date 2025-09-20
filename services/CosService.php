<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;

class CosService
{
    private $config;
    private $cosConfig;

    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
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

    public function uploadFile($filePath, $fileName, $targetPath = '')
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'msg' => 'Missing COS config'];
        }

        try {
            if (!file_exists($filePath) || !is_readable($filePath)) {
                return ['ok' => false, 'msg' => 'File not found or not readable'];
            }
            
            // 图片压缩处理（传递目标存储类型）
            try {
                if (class_exists('\\TypechoPlugin\\TEMediaFolder\\Core\\ImageCompressor')) {
                    $compressionResult = \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::processImage($filePath, $fileName, 'cos');
                    $processedFilePath = $compressionResult['path'];
                    $isCompressed = $compressionResult['compressed'];
                } else {
                    // 压缩器类不存在时的降级处理
                    $compressionResult = ['path' => $filePath, 'compressed' => false];
                    $processedFilePath = $filePath;
                    $isCompressed = false;
                }
            } catch (\Exception $e) {
                // 压缩处理失败时的降级处理
                $compressionResult = ['path' => $filePath, 'compressed' => false];
                $processedFilePath = $filePath;
                $isCompressed = false;
            }
            
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
                // 清理临时文件
                \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::cleanupTempFile($processedFilePath);
                return ['ok' => false, 'msg' => 'Failed to read file content'];
            }
            
            $response = $this->makeRequest($url, 'PUT', [
                'Host: ' . $host,
                'Authorization: ' . $authorization,
                'User-Agent: TEMediaFolder/1.0',
                'Content-Type: application/octet-stream'
            ], $body);

            $publicUrl = $this->getPublicUrl($objectKey);
            
            // 生成缩略图URL (仅对图片文件)
            $thumbnailUrl = null;
            if ($this->isImageFile($safeFileName)) {
                $thumbnailUrl = $this->getThumbnailUrl($publicUrl);
            }
            
            // 清理临时文件
            \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::cleanupTempFile($processedFilePath);
            
            $result = ['ok' => true, 'url' => $publicUrl, 'name' => $safeFileName];
            if ($thumbnailUrl) {
                $result['thumbnail'] = $thumbnailUrl;
            }
            if ($isCompressed) {
                $result['compressed'] = true;
                $result['compression_info'] = $compressionResult;
            }
            
            return $result;
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

    /**
     * 检查是否为图片文件
     */
    private function isImageFile($fileName)
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        return in_array($extension, $imageExtensions);
    }

    /**
     * 获取COS缩略图URL
     * 使用腾讯云数据处理服务
     */
    private function getThumbnailUrl($originalUrl)
    {
        // 获取配置的缩略图尺寸
        $thumbSize = $this->config->get('thumbSize', 120);
        // 腾讯云COS图片处理参数：居中裁剪成正方形缩略图
        return $originalUrl . '?imageMogr2/crop/' . $thumbSize . 'x' . $thumbSize . '/gravity/center';
    }

    private function generateAuthorization($method, $path, $query = [], $host = '')
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
        
        $headerList = 'host';
        $headerStr = 'host=' . rawurlencode(strtolower($host));
        
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
                    'mtime' => $lastModified
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
