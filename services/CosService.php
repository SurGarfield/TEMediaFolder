<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;
use TypechoPlugin\TEMediaFolder\Core\HttpClient;

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

    public function getFileList($path = '', $options = [])
    {
        if (!$this->isConfigured()) {
            return ['folders' => [], 'files' => []];
        }

        try {
            $fullPrefix = $this->buildPrefix($path);
            $endpoint = $this->getEndpoint();
            $host = $this->getHost();
            $pageSize = isset($options['page_size']) ? (int)$options['page_size'] : 0;
            if ($pageSize <= 0) {
                $pageSize = 60;
            }
            $pageSize = max(1, min($pageSize, 200));
            $pageToken = isset($options['page_token']) ? trim((string)$options['page_token']) : '';
            $foldersOnly = !empty($options['folders_only']);

            if ($foldersOnly) {
                return $this->getFolderListOnly($path);
            }
            
            $query = [
                'prefix' => $fullPrefix,
                'delimiter' => '/',
                'max-keys' => $pageSize
            ];
            if ($pageToken !== '') {
                $query['marker'] = $pageToken;
            }

            $authorization = $this->generateAuthorization('GET', '/', $query, $host);
            $url = $endpoint . '/?' . http_build_query($query);
            
            $response = $this->makeRequest($url, 'GET', [
                'Host: ' . $host,
                'Authorization: ' . $authorization,
                'User-Agent: TEMediaFolder/1.0'
            ]);

            return $this->parseListResponse($response, $fullPrefix, $path, $pageToken, $pageSize);
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
            $targetPath = $this->resolveNetworkUploadPath($targetPath);
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
            $options = $this->buildImageUploadOptions(
                $safeFileName,
                $publicUrl,
                $isCompressed,
                $compressionResult,
                [],
                $this->isImageFile($safeFileName) ? $this->getThumbnailUrl($publicUrl) : null
            );
            $options['size'] = strlen($body);
            $options['size_human'] = $this->formatFileSizeHuman($options['size']);
            $options['directory'] = $this->extractDirectoryFromKey($objectKey);
            
            return $this->buildUploadResult(true, $publicUrl, $safeFileName, $options);
        } catch (\Exception $e) {
            return ['ok' => false, 'msg' => 'Upload failed: ' . $e->getMessage()];
        }
    }

    private function buildPrefix($path)
    {
        return $this->buildPathPrefix($this->cosConfig['prefix'], $path);
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
        $thumbSize = $this->getDefaultThumbSize();
 
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

    private function encodeKey($key)
    {
        return $this->encodeObjectKey($key);
    }

    private function getFolderListOnly($path)
    {
        $fullPrefix = $this->buildPrefix($path);
        $endpoint = $this->getEndpoint();
        $host = $this->getHost();
        $folders = [];
        $seen = [];
        $pageToken = '';

        do {
            $query = [
                'prefix' => $fullPrefix,
                'delimiter' => '/',
                'max-keys' => 1000
            ];
            if ($pageToken !== '') {
                $query['marker'] = $pageToken;
            }

            $authorization = $this->generateAuthorization('GET', '/', $query, $host);
            $url = $endpoint . '/?' . http_build_query($query);
            $response = $this->makeRequest($url, 'GET', [
                'Host: ' . $host,
                'Authorization: ' . $authorization,
                'User-Agent: TEMediaFolder/1.0'
            ]);

            $parsed = $this->parseListResponse($response, $fullPrefix, $path, $pageToken, 1000);
            foreach ($parsed['folders'] as $folder) {
                if (!isset($seen[$folder['path']])) {
                    $seen[$folder['path']] = true;
                    $folders[] = $folder;
                }
            }
            $pageToken = !empty($parsed['has_more']) ? (string)$parsed['next_token'] : '';
        } while ($pageToken !== '');

        return [
            'ok' => true,
            'folders' => $folders,
            'files' => [],
            'server_paged' => false
        ];
    }

    private function makeRequest($url, $method = 'GET', $headers = [], $body = null)
    {
        $result = HttpClient::request($url, $method, $headers, $body, [
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify_ssl' => false,
            'follow_location' => false,
            'max_redirs' => 0
        ]);

        $response = (string)($result['body'] ?? '');
        $statusCode = (int)($result['status'] ?? 0);
        $error = (string)($result['error'] ?? '');

        if ($error !== '' && $statusCode === 0) {
            throw new \Exception('Request failed: ' . $error);
        }

        if ($statusCode >= 400) {
            $errorMsg = 'HTTP ' . $statusCode;
            if ($response !== '') {
                $xml = @simplexml_load_string($response);
                if ($xml && isset($xml->Message)) {
                    $errorMsg .= ': ' . (string)$xml->Message;
                } elseif ($xml && isset($xml->Code)) {
                    $errorMsg .= ': ' . (string)$xml->Code;
                }
            }
            throw new \Exception($errorMsg);
        }

        return $response;
    }

    private function parseListResponse($response, $fullPrefix, $path, $pageToken = '', $pageSize = 60)
    {
        $xml = @simplexml_load_string($response);
        if ($xml === false) {
            return ['folders' => [], 'files' => []];
        }

        $folders = [];
        if (isset($xml->CommonPrefixes)) {
            foreach ($xml->CommonPrefixes as $cp) {
                $prefix = (string)$cp->Prefix;
                $relativePrefix = $fullPrefix !== '' && strpos($prefix, $fullPrefix) === 0
                    ? substr($prefix, strlen($fullPrefix))
                    : $this->stripConfiguredPrefix($prefix, $this->cosConfig['prefix'] ?? '');
                $name = trim($relativePrefix, '/');
                
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
                $size = isset($content->Size) ? (int) $content->Size : 0;

                $fileData = [
                    'name' => $name,
                    'url' => $publicUrl,
                    'mtime' => $lastModified,
                    'size' => $size,
                    'directory' => $this->extractDirectoryFromKey($key),
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

        $nextToken = isset($xml->NextMarker) ? trim((string)$xml->NextMarker) : '';
        $isTruncated = isset($xml->IsTruncated) && strtolower((string)$xml->IsTruncated) === 'true';

        $nextToken = ($isTruncated && $nextToken !== '') ? $nextToken : '';

        return [
            'ok' => true,
            'folders' => $folders,
            'files' => $files,
            'page_token' => $pageToken,
            'next_token' => $nextToken,
            'has_more' => $isTruncated && $nextToken !== '',
            'page_size' => (int)$pageSize,
            'server_paged' => true
        ];
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

    private function extractDirectoryFromKey($key)
    {
        if (!is_string($key) || $key === '') {
            return '';
        }

        $normalized = $this->stripConfiguredPrefix($key, $this->cosConfig['prefix'] ?? '');
        if ($normalized === '') {
            return '';
        }

        $segments = explode('/', $normalized);
        if (count($segments) <= 1) {
            return '';
        }

        array_pop($segments);
        if (empty($segments)) {
            return '';
        }

        $uploadsIndex = array_search('uploads', $segments, true);
        if ($uploadsIndex !== false && $uploadsIndex < count($segments) - 1) {
            $segments = array_slice($segments, $uploadsIndex + 1);
        }

        return implode('/', $segments);
    }
    
    /**
     * 清理文件名，确保安全上传
     */
    protected function sanitizeFileName($fileName)
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
