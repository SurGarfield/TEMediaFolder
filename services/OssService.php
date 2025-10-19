<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;

class OssService extends BaseService
{
    private $ossConfig;

    public function __construct(ConfigManager $config)
    {
        parent::__construct($config);
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
            return $this->buildUploadResult(false, '', '', ['msg' => 'Missing OSS config']);
        }

        try {
            // 使用父类方法处理压缩
            $compressionResult = $this->processImageCompression($filePath, $fileName, 'oss');
            $processedFilePath = $compressionResult['path'];
            $isCompressed = $compressionResult['compressed'];
            
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
            
            // 构建返回结果
            $options = ['thumbnail' => $this->getThumbnailUrl($publicUrl)];
            if ($isCompressed) {
                $options['compressed'] = true;
                $options['compression_info'] = $compressionResult;
            }
            
            return $this->buildUploadResult(true, $publicUrl, basename($processedFilePath), $options);
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

    private function getThumbnailUrl($originalUrl)
    {
        // 获取配置的缩略图尺寸
        $thumbSize = $this->config->get('thumbSize', 120);
   
        return $originalUrl . '?x-oss-process=image/resize,m_fill,w_' . $thumbSize . ',h_' . $thumbSize
            . '/quality,Q_75'
            . '/format,webp';
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
