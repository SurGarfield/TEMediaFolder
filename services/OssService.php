<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;

class OssService
{
    private $config;
    private $ossConfig;

    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
        $this->ossConfig = $config->getOssConfig();
    }

    public function isConfigured()
    {
        return !empty($this->ossConfig['bucket']) 
            && !empty($this->ossConfig['endpoint']) 
            && !empty($this->ossConfig['accessKeyId']) 
            && !empty($this->ossConfig['accessKeySecret']);
    }

    public function getFileList($path = '')
    {
        if (!$this->isConfigured()) {
            return ['folders' => [], 'files' => []];
        }

        try {
            $fullPrefix = $this->buildPrefix($path);
            $host = $this->getHost();
            $scheme = $this->getScheme();
            
            $query = [
                'list-type' => '2',
                'prefix' => $fullPrefix,
                'delimiter' => '/',
                'max-keys' => '1000'
            ];

            $date = gmdate('D, d M Y H:i:s \G\M\T');
            $authorization = $this->generateAuthorization('GET', '/', $date);
            
            $url = $scheme . '://' . $host . '/?' . http_build_query($query);
            
            $response = $this->makeRequest($url, 'GET', [
                'Date: ' . $date,
                'Authorization: ' . $authorization,
                'Host: ' . $host,
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
            return ['ok' => false, 'msg' => 'Missing OSS config'];
        }

        try {
            // 图片压缩处理（传递目标存储类型）
            try {
                if (class_exists('\\TypechoPlugin\\TEMediaFolder\\Core\\ImageCompressor')) {
                    $compressionResult = \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::processImage($filePath, $fileName, 'oss');
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
            
            $fullPrefix = $this->buildPrefix($targetPath);
            // 使用压缩后文件名（如 .webp）
            $objectKey = ltrim($fullPrefix . basename($processedFilePath), '/');
            $host = $this->getHost();
            $scheme = $this->getScheme();
            
            $date = gmdate('D, d M Y H:i:s \G\M\T');
            $contentType = 'application/octet-stream';
            $authorization = $this->generateAuthorization('PUT', '/' . $objectKey, $date, $contentType);
            
            $encodedPath = '/' . implode('/', array_map('rawurlencode', explode('/', $objectKey)));
            $url = $scheme . '://' . $host . $encodedPath;
            $body = file_get_contents($processedFilePath);
            
            $response = $this->makeRequest($url, 'PUT', [
                'Date: ' . $date,
                'Authorization: ' . $authorization,
                'User-Agent: TEMediaFolder/1.0',
                'Content-Type: application/octet-stream'
            ], $body);

            $publicUrl = $this->getPublicUrl($objectKey);
            
            $result = ['ok' => true, 'url' => $publicUrl];
            $result['thumbnail'] = $this->getThumbnailUrl($publicUrl);
            if ($isCompressed) {
                $result['compressed'] = true;
                $result['compression_info'] = $compressionResult;
            }
            
            return $result;
        } catch (\Exception $e) {
            return ['ok' => false, 'msg' => 'Upload failed'];
        }
    }

    private function buildPrefix($path)
    {
        $prefix = rtrim($this->ossConfig['prefix'], '/');
        if (!empty($path)) {
            $prefix = ($prefix === '' ? '' : $prefix . '/') . trim($path, '/');
        }
        return $prefix === '' ? '' : $prefix . '/';
    }

    private function getEndpoint()
    {
        $domain = $this->ossConfig['domain'];
        if (!empty($domain)) {
            return $domain;
        }
        
        $endpoint = $this->ossConfig['endpoint'];
        $bucket = $this->ossConfig['bucket'];
        $endpoint = preg_replace('#^https?://#i', '', $endpoint);
        return 'https://' . $bucket . '.' . $endpoint;
    }

    private function getHost()
    {
        $endpoint = $this->ossConfig['endpoint'];
        $bucket = $this->ossConfig['bucket'];
        $endpoint = preg_replace('#^https?://#i', '', $endpoint);
        return $bucket . '.' . $endpoint;
    }

    private function getScheme()
    {
        return 'https';
    }

    private function getPublicUrl($objectKey)
    {
        $domain = $this->ossConfig['domain'];
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
     * 获取OSS缩略图URL
     * 使用阿里云图片处理服务
     */
    private function getThumbnailUrl($originalUrl)
    {
        // 获取配置的缩略图尺寸
        $thumbSize = $this->config->get('thumbSize', 120);
        // 阿里云OSS图片处理参数：先自适应缩放到合适大小，再居中裁剪成正方形
        return $originalUrl . '?x-oss-process=image/resize,m_fill,w_' . $thumbSize . ',h_' . $thumbSize;
    }

    private function generateAuthorization($method, $resource, $date, $contentType = '')
    {
        $canonicalizedResource = '/' . $this->ossConfig['bucket'] . $resource;
        $stringToSign = $method . "\n\n" . $contentType . "\n" . $date . "\n" . $canonicalizedResource;
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->ossConfig['accessKeySecret'], true));
        
        return 'OSS ' . $this->ossConfig['accessKeyId'] . ':' . $signature;
    }

    private function makeRequest($url, $method = 'GET', $headers = [], $body = null)
    {
        $context = [
            'http' => [
                'method' => $method,
                'timeout' => 30,
                'header' => implode("\r\n", $headers)
            ]
        ];
        
        if ($body !== null) {
            $context['http']['content'] = $body;
        }
        
        $ctx = stream_context_create($context);
        $response = @file_get_contents($url, false, $ctx);
        
        if ($response === false) {
            throw new \Exception('Request failed');
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
            usort($items, function($a, $b) { return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0); });
            $files = array_merge($files, $items);
        }

        return ['folders' => $folders, 'files' => $files];
    }
}
