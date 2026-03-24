<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;
use TypechoPlugin\TEMediaFolder\Core\HttpClient;

class BitifulService extends BaseService
{
    private $bitifulConfig;

    public function __construct(ConfigManager $config)
    {
        parent::__construct($config);
        $this->bitifulConfig = $config->getBitifulConfig();
        $this->bitifulConfig['endpoint'] = $this->normalizeEndpoint($this->bitifulConfig['endpoint'] ?? '');
        $this->bitifulConfig['domain'] = trim((string)($this->bitifulConfig['domain'] ?? ''));
    }

    public function isConfigured()
    {
        return !empty($this->bitifulConfig['bucket'])
            && !empty($this->bitifulConfig['region'])
            && !empty($this->bitifulConfig['endpoint'])
            && !empty($this->bitifulConfig['accessKey'])
            && !empty($this->bitifulConfig['secretKey']);
    }

    public function getFileList($path = '', $options = [])
    {
        if (!$this->isConfigured()) {
            return ['folders' => [], 'files' => []];
        }

        try {
            $fullPrefix = $this->buildPrefix($path);
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
                'list-type' => '2',
                'prefix' => $fullPrefix,
                'delimiter' => '/',
                'max-keys' => (string)$pageSize
            ];
            if ($pageToken !== '') {
                $query['continuation-token'] = $pageToken;
            }

            $url = $this->getApiEndpoint() . '/?' . $this->buildQueryString($query);
            $headers = $this->buildSignedHeaders('GET', '/', $query, [], '');
            $response = $this->makeRequest($url, 'GET', $headers);

            return $this->parseListResponse($response, $fullPrefix, $path, $pageToken, $pageSize);
        } catch (\Exception $e) {
            return ['folders' => [], 'files' => []];
        }
    }

    private function getFolderListOnly($path)
    {
        $fullPrefix = $this->buildPrefix($path);
        $folders = [];
        $seen = [];
        $pageToken = '';

        do {
            $query = [
                'list-type' => '2',
                'prefix' => $fullPrefix,
                'delimiter' => '/',
                'max-keys' => '1000'
            ];
            if ($pageToken !== '') {
                $query['continuation-token'] = $pageToken;
            }

            $url = $this->getApiEndpoint() . '/?' . $this->buildQueryString($query);
            $headers = $this->buildSignedHeaders('GET', '/', $query, [], '');
            $response = $this->makeRequest($url, 'GET', $headers);
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

    public function uploadFile($filePath, $fileName, $targetPath = '')
    {
        if (!$this->isConfigured()) {
            return $this->buildUploadResult(false, '', '', ['msg' => 'Missing Bitiful config']);
        }

        try {
            $validation = $this->validateUploadFile($filePath, $fileName);
            if (!$validation['valid']) {
                return $this->buildUploadResult(false, '', '', ['msg' => $validation['error']]);
            }

            $compressionResult = $this->processImageCompression($filePath, $fileName, 'bitiful');
            $processedFilePath = $compressionResult['path'];
            $isCompressed = $compressionResult['compressed'];

            $safeFileName = $this->sanitizeFileName(basename($processedFilePath));
            $targetPath = $this->resolveNetworkUploadPath($targetPath);
            $fullPrefix = $this->buildPrefix($targetPath);
            $objectKey = ltrim($fullPrefix . $safeFileName, '/');
            $canonicalUri = '/' . $this->encodeKey($objectKey);
            $body = file_get_contents($processedFilePath);

            if ($body === false) {
                $this->cleanupTempFile($processedFilePath);
                return $this->buildUploadResult(false, '', '', ['msg' => 'Failed to read file content']);
            }

            $url = $this->getApiEndpoint() . $canonicalUri;
            $headers = $this->buildSignedHeaders('PUT', $canonicalUri, [], [], $body);
            $headers[] = 'Content-Type: application/octet-stream';
            $this->makeRequest($url, 'PUT', $headers, $body);

            $publicUrl = $this->getPublicUrl($objectKey);
            $previewUrl = $this->getPreviewUrl($objectKey);
            $this->cleanupTempFile($processedFilePath);

            $options = [
                'id' => $objectKey,
                'directory' => $this->extractDirectoryFromKey($objectKey),
                'preview_url' => $previewUrl
            ];
            $options = $this->buildImageUploadOptions(
                $safeFileName,
                $publicUrl,
                $isCompressed,
                $compressionResult,
                $options,
                $this->isImageFile($safeFileName) ? $this->getThumbnailUrl($objectKey, $previewUrl) : null
            );
            $options['size'] = strlen($body);
            $options['size_human'] = $this->formatFileSizeHuman($options['size']);

            return $this->buildUploadResult(true, $publicUrl, $safeFileName, $options);
        } catch (\Exception $e) {
            return $this->buildUploadResult(false, '', '', ['msg' => 'Upload failed: ' . $e->getMessage()]);
        }
    }

    public function deleteFile($fileUrl, $fileId = null)
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'msg' => 'Missing Bitiful config'];
        }

        $objectKey = $fileId !== null && $fileId !== '' ? trim($fileId) : $this->extractObjectKey($fileUrl);
        if ($objectKey === '') {
            return ['ok' => false, 'msg' => '无法解析对象路径'];
        }

        try {
            $canonicalUri = '/' . $this->encodeKey($objectKey);
            $url = $this->getApiEndpoint() . $canonicalUri;
            $headers = $this->buildSignedHeaders('DELETE', $canonicalUri, [], [], '');
            $this->makeRequest($url, 'DELETE', $headers);

            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'msg' => '删除失败: ' . $e->getMessage()];
        }
    }

    public function renameFile($fileUrl, $newBaseName, $fileId = null)
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'msg' => 'Missing Bitiful config'];
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
            $previewUrl = $this->getPreviewUrl($objectKey);
            $result = [
                'ok' => true,
                'url' => $publicUrl,
                'name' => $newFilename,
                'id' => trim($objectKey, '/'),
                'directory' => $normalizedDirectory,
                'preview_url' => $previewUrl
            ];
            if ($this->isImageFile($newFilename)) {
                $result['thumbnail'] = $this->getThumbnailUrl($objectKey, $previewUrl);
            }
            return $result;
        }

        try {
            $encodedNewKey = $this->encodeKey($newKey);
            $copySource = '/' . $this->bitifulConfig['bucket'] . '/' . $this->encodeKey($objectKey);
            $headers = $this->buildSignedHeaders('PUT', '/' . $encodedNewKey, [], [
                'x-amz-copy-source' => $copySource
            ], '');

            $this->makeRequest($this->getApiEndpoint() . '/' . $encodedNewKey, 'PUT', $headers);

            if ($this->encodeKey($objectKey) !== $encodedNewKey) {
                $this->deleteFile($fileUrl, $objectKey);
            }

            $newUrl = $this->getPublicUrl($newKey);
            $previewUrl = $this->getPreviewUrl($newKey);
            $result = [
                'ok' => true,
                'url' => $newUrl,
                'name' => $newFilename,
                'id' => $newKey,
                'directory' => $normalizedDirectory,
                'preview_url' => $previewUrl
            ];
            if ($this->isImageFile($newFilename)) {
                $result['thumbnail'] = $this->getThumbnailUrl($newKey, $previewUrl);
            }
            return $result;
        } catch (\Exception $e) {
            return ['ok' => false, 'msg' => '重命名失败: ' . $e->getMessage()];
        }
    }

    private function buildPrefix($path)
    {
        return $this->buildPathPrefix($this->bitifulConfig['prefix'] ?? '', $path);
    }

    private function normalizeEndpoint($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '') {
            $endpoint = 'https://s3.bitiful.net';
        }
        if (!preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }
        return rtrim($endpoint, '/');
    }

    private function getScheme()
    {
        $scheme = parse_url($this->bitifulConfig['endpoint'], PHP_URL_SCHEME);
        return $scheme ? strtolower($scheme) : 'https';
    }

    private function getEndpointHost()
    {
        $host = parse_url($this->bitifulConfig['endpoint'], PHP_URL_HOST);
        if (!$host) {
            $host = preg_replace('#^https?://#i', '', $this->bitifulConfig['endpoint']);
        }
        return trim((string)$host, '/');
    }

    private function getHost()
    {
        return $this->bitifulConfig['bucket'] . '.' . $this->getEndpointHost();
    }

    private function getApiEndpoint()
    {
        return $this->getScheme() . '://' . $this->getHost();
    }

    private function getPublicUrl($objectKey)
    {
        $encodedKey = $this->encodeKey($objectKey);
        $domain = trim((string)$this->bitifulConfig['domain']);
        if ($domain !== '') {
            if (!preg_match('#^https?://#i', $domain)) {
                $domain = 'https://' . $domain;
            }
            return rtrim($domain, '/') . '/' . ltrim($encodedKey, '/');
        }

        return $this->getApiEndpoint() . '/' . ltrim($encodedKey, '/');
    }

    private function getThumbnailUrl($objectKey, $fallbackUrl = '')
    {
        $previewUrl = $fallbackUrl !== '' ? $fallbackUrl : $this->getPreviewUrl($objectKey);
        if ($previewUrl === '') {
            return $this->getPublicUrl($objectKey);
        }
        return $previewUrl;
    }

    private function getPreviewUrl($objectKey, $expires = 3600)
    {
        $publicUrl = $this->getPublicUrl($objectKey);
        if (trim((string)$this->bitifulConfig['domain']) !== '') {
            return $publicUrl;
        }

        $canonicalUri = '/' . $this->encodeKey($objectKey);
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $credentialScope = $date . '/' . $this->bitifulConfig['region'] . '/s3/aws4_request';
        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->bitifulConfig['accessKey'] . '/' . $credentialScope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string)max(1, min((int)$expires, 604800)),
            'X-Amz-SignedHeaders' => 'host'
        ];

        $canonicalHeaders = 'host:' . $this->getHost() . "\n";
        $canonicalRequest = 'GET' . "\n"
            . $canonicalUri . "\n"
            . $this->buildQueryString($query) . "\n"
            . $canonicalHeaders . "\n"
            . 'host' . "\n"
            . 'UNSIGNED-PAYLOAD';

        $stringToSign = 'AWS4-HMAC-SHA256' . "\n"
            . $amzDate . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $query['X-Amz-Signature'] = hash_hmac('sha256', $stringToSign, $this->getSigningKey($date));

        return $this->getApiEndpoint() . $canonicalUri . '?' . $this->buildQueryString($query);
    }

    private function buildSignedHeaders($method, $canonicalUri, $query = [], $extraHeaders = [], $body = '')
    {
        $payloadHash = hash('sha256', (string)$body);
        $amzDate = gmdate('Ymd\\THis\\Z');
        $headers = array_merge([
            'host' => $this->getHost(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate
        ], $extraHeaders);

        $authorization = $this->generateAuthorization($method, $canonicalUri, $query, $headers, $payloadHash, $amzDate);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $this->formatHeaderName($name) . ': ' . $value;
        }
        $headerLines[] = 'Authorization: ' . $authorization;
        $headerLines[] = 'User-Agent: TEMediaFolder/1.0';

        return $headerLines;
    }

    private function generateAuthorization($method, $canonicalUri, $query, $headers, $payloadHash, $amzDate)
    {
        $date = substr($amzDate, 0, 8);
        $canonicalHeaders = [];
        foreach ($headers as $name => $value) {
            $lowerName = strtolower(trim((string)$name));
            $canonicalHeaders[$lowerName] = preg_replace('/\s+/', ' ', trim((string)$value));
        }
        ksort($canonicalHeaders);

        $signedHeaders = implode(';', array_keys($canonicalHeaders));
        $canonicalHeaderString = '';
        foreach ($canonicalHeaders as $name => $value) {
            $canonicalHeaderString .= $name . ':' . $value . "\n";
        }

        $canonicalRequest = strtoupper($method) . "\n"
            . $canonicalUri . "\n"
            . $this->buildQueryString($query) . "\n"
            . $canonicalHeaderString . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $credentialScope = $date . '/' . $this->bitifulConfig['region'] . '/s3/aws4_request';
        $stringToSign = 'AWS4-HMAC-SHA256' . "\n"
            . $amzDate . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSigningKey($date);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return 'AWS4-HMAC-SHA256 Credential=' . $this->bitifulConfig['accessKey'] . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;
    }

    private function getSigningKey($date)
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->bitifulConfig['secretKey'], true);
        $kRegion = hash_hmac('sha256', $this->bitifulConfig['region'], $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function buildQueryString($query)
    {
        if (empty($query)) {
            return '';
        }

        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = [
                rawurlencode((string)$key),
                rawurlencode((string)$value)
            ];
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

    private function formatHeaderName($name)
    {
        $name = strtolower((string)$name);
        if ($name === 'content-type') {
            return 'Content-Type';
        }
        if ($name === 'user-agent') {
            return 'User-Agent';
        }
        if (strpos($name, 'x-amz-') === 0) {
            return 'x-amz-' . substr($name, 6);
        }
        return $name;
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
                    : $this->stripConfiguredPrefix($prefix, $this->bitifulConfig['prefix'] ?? '');
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
                $size = isset($content->Size) ? (int)$content->Size : 0;
                $fileData = [
                    'name' => $name,
                    'url' => $publicUrl,
                    'preview_url' => $this->getPreviewUrl($key),
                    'mtime' => $lastModified,
                    'size' => $size,
                    'directory' => $this->extractDirectoryFromKey($key),
                    'id' => $key
                ];
                if ($this->isImageFile($name)) {
                    $fileData['thumbnail'] = $this->getThumbnailUrl($key, $fileData['preview_url']);
                }
                $items[] = $fileData;
            }

            usort($items, function ($a, $b) {
                return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
            });
            $files = array_merge($files, $items);
        }

        $nextToken = isset($xml->NextContinuationToken) ? trim((string)$xml->NextContinuationToken) : '';
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
        $bucket = trim((string)$this->bitifulConfig['bucket']);
        if ($bucket !== '' && strpos($key, $bucket . '/') === 0) {
            $key = substr($key, strlen($bucket) + 1);
        }

        return rawurldecode($key);
    }

    private function extractDirectoryFromKey($key)
    {
        if (!is_string($key) || $key === '') {
            return '';
        }

        $normalized = $this->stripConfiguredPrefix($key, $this->bitifulConfig['prefix'] ?? '');
        if ($normalized === '') {
            return '';
        }

        $segments = explode('/', $normalized);
        if (count($segments) <= 1) {
            return '';
        }

        array_pop($segments);
        return implode('/', $segments);
    }

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

    private function encodeKey($key)
    {
        return $this->encodeObjectKey($key);
    }
}
