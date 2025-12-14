<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;

class UpyunService extends BaseService
{
    private $upyunConfig;

    public function __construct(ConfigManager $config)
    {
        parent::__construct($config);
        $this->upyunConfig = $config->getUpyunConfig();
    }

    public function isConfigured()
    {
        return !empty($this->upyunConfig['bucket']) 
            && !empty($this->upyunConfig['operator']) 
            && !empty($this->upyunConfig['password']);
    }

    public function getFileList($path = '')
    {
        if (!$this->isConfigured()) {
            return ['folders' => [], 'files' => []];
        }

        try {
            $bucket = $this->upyunConfig['bucket'];
            $cleanPath = trim($path, '/');
            
            // 构造 URI：/bucket/path/ 或 /bucket/
            if (!empty($cleanPath)) {
                $uri = "/{$bucket}/{$cleanPath}/";
            } else {
                $uri = "/{$bucket}/";
            }
            $method = 'GET';
            
            $authData = $this->generateAuthorization($method, $uri);
            $url = "https://v0.api.upyun.com{$uri}";
            
            $response = $this->makeRequest($url, $method, [
                'Authorization: ' . $authData['authorization'],
                'Date: ' . $authData['date'],
                'User-Agent: TEMediaFolder/1.0',
                'X-List-Limit: 1000',
                'X-List-Order: asc'
            ]);

            return $this->parseListResponse($response, $path);
        } catch (\Exception $e) {
            return ['folders' => [], 'files' => []];
        }
    }

    public function renameFile($fileUrl, $newBaseName, $fileId = null)
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'msg' => 'Missing Upyun config'];
        }

        $objectPath = $fileId !== null && $fileId !== '' ? trim($fileId) : $this->extractObjectPath($fileUrl);
        if ($objectPath === '') {
            return ['ok' => false, 'msg' => '无法解析对象路径'];
        }

        $newBaseName = trim((string)$newBaseName);
        if ($newBaseName === '') {
            return ['ok' => false, 'msg' => '文件名不能为空'];
        }

        $pathInfo = pathinfo($objectPath);
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
        $newPath = ($normalizedDirectory !== '' ? $normalizedDirectory . '/' : '') . $newFilename;

        if ($newPath === trim($objectPath, '/')) {
            $newUrl = $this->getPublicUrl($newPath);
            $result = [
                'ok' => true,
                'url' => $newUrl,
                'name' => $newFilename,
                'id' => trim($newPath, '/'),
                'directory' => $normalizedDirectory
            ];
            if ($this->isImageFile($newFilename)) {
                $result['thumbnail'] = $this->getThumbnailUrl($newUrl);
            }
            return $result;
        }

        $bucket = $this->upyunConfig['bucket'];
        $sourceUri = '/' . $bucket . '/' . ltrim($this->normalizePath($objectPath), '/');
        $destinationUri = '/' . $bucket . '/' . ltrim($this->normalizePath($newPath), '/');

        try {
            $authData = $this->generateAuthorization('POST', $sourceUri);
            $url = 'https://v0.api.upyun.com' . $sourceUri;
            $headers = [
                'Authorization: ' . $authData['authorization'],
                'Date: ' . $authData['date'],
                'User-Agent: TEMediaFolder/1.0',
                'Content-Length: 0',
                'X-Upyun-Move-To: ' . $destinationUri,
                'X-Upyun-Overwrite: true'
            ];

            $this->makeRequest($url, 'POST', $headers, '');

            $newUrl = $this->getPublicUrl($newPath);
            $result = [
                'ok' => true,
                'url' => $newUrl,
                'name' => $newFilename,
                'id' => trim($newPath, '/'),
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

    public function uploadFile($filePath, $fileName, $targetPath = '')
    {
        if (!$this->isConfigured()) {
            return $this->buildUploadResult(false, '', '', ['msg' => 'Missing Upyun config']);
        }

        try {
            // 验证和压缩
            $validation = $this->validateUploadFile($filePath, $fileName);
            if (!$validation['valid']) {
                return $this->buildUploadResult(false, '', '', ['msg' => $validation['error']]);
            }
            
            $compressionResult = $this->processImageCompression($filePath, $fileName, 'upyun');
            $processedFilePath = $compressionResult['path'];
            $isCompressed = $compressionResult['compressed'];
            
            // 使用原始文件名，而不是处理后的临时文件名
            $safeFileName = $this->sanitizeFileName($fileName);
            $bucket = $this->upyunConfig['bucket'];
            $cleanPath = trim($targetPath, '/');
            
            // 构造 URI：/bucket/path/file.jpg
            if (!empty($cleanPath)) {
                $uri = "/{$bucket}/{$cleanPath}/{$safeFileName}";
            } else {
                $uri = "/{$bucket}/{$safeFileName}";
            }
            $method = 'PUT';
            
            $body = file_get_contents($processedFilePath);
            if ($body === false) {
                $this->cleanupTempFile($processedFilePath);
                return $this->buildUploadResult(false, '', '', ['msg' => 'Failed to read file content']);
            }
            
            $authData = $this->generateAuthorization($method, $uri, $body);
            $url = "https://v0.api.upyun.com{$uri}";
            
            // 构造请求头
            $headers = [
                'Authorization: ' . $authData['authorization'],
                'Date: ' . $authData['date'],
                'User-Agent: TEMediaFolder/1.0',
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($body)
            ];
            
            // 如果签名中包含了 Content-MD5，请求头也必须包含
            if (!empty($authData['content_md5'])) {
                $headers[] = 'Content-MD5: ' . $authData['content_md5'];
            }
            
            $response = $this->makeRequest($url, $method, $headers, $body);

            $publicUrl = $this->getPublicUrl($cleanPath . '/' . $safeFileName);
            
            // 清理临时文件
            $this->cleanupTempFile($processedFilePath);
            
            // 构建返回结果
            $options = ['name' => $safeFileName];
            if ($this->isImageFile($safeFileName)) {
                $options['thumbnail'] = $this->getThumbnailUrl($publicUrl);
            }
            if ($isCompressed) {
                $options['compressed'] = true;
            }
            
            return $this->buildUploadResult(true, $publicUrl, $safeFileName, $options);
        } catch (\Exception $e) {
            return $this->buildUploadResult(false, '', '', ['msg' => $e->getMessage()]);
        }
    }

    public function deleteFile($fileUrl, $fileId = null)
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'msg' => 'Missing Upyun config'];
        }

        $objectPath = $fileId !== null && $fileId !== '' ? trim($fileId) : $this->extractObjectPath($fileUrl);
        if ($objectPath === '') {
            return ['ok' => false, 'msg' => '无法解析对象路径'];
        }

        $bucket = $this->upyunConfig['bucket'];
        $uri = '/' . $bucket . '/' . ltrim($objectPath, '/');
        $method = 'DELETE';

        try {
            $authData = $this->generateAuthorization($method, $uri);
            $url = 'https://v0.api.upyun.com' . $uri;

            $headers = [
                'Authorization: ' . $authData['authorization'],
                'Date: ' . $authData['date'],
                'User-Agent: TEMediaFolder/1.0'
            ];

            if (!empty($authData['content_md5'])) {
                $headers[] = 'Content-MD5: ' . $authData['content_md5'];
            }

            $this->makeRequest($url, $method, $headers);

            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'msg' => '删除失败: ' . $e->getMessage()];
        }
    }

    private function generateAuthorization($method, $uri, $body = '')
    {
        $operator = $this->upyunConfig['operator'];
        $password = $this->upyunConfig['password'];
        
        // 生成 GMT 时间
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        
        // 计算 Content-MD5（如果有body，使用十六进制MD5）
        $contentMD5 = '';
        if ($body && strlen($body) > 0) {
            $contentMD5 = md5($body);
        }
        
        // 又拍云签名格式（REST API）: METHOD&URI&DATE&CONTENT_MD5
        // 重要：非必选参数为空时，连同后面的 & 一起不参与签名计算
        // 参考：https://docs.upyun.com/api/authorization/#_2
        if ($contentMD5) {
            $signString = "{$method}&{$uri}&{$date}&{$contentMD5}";
        } else {
            $signString = "{$method}&{$uri}&{$date}";
        }
        
        // 使用密码MD5作为密钥进行 HMAC-SHA1 签名
        $passwordMD5 = md5($password);
        $signature = base64_encode(hash_hmac('sha1', $signString, $passwordMD5, true));
        
        return [
            'authorization' => "UPYUN {$operator}:{$signature}",
            'date' => $date,
            'content_md5' => $contentMD5  // 返回 Content-MD5 以便在请求头中使用
        ];
    }

    private function parseListResponse($response, $path)
    {
        $folders = [];
        $files = [];
        
        if (empty($response)) {
            return ['folders' => $folders, 'files' => $files];
        }
        
        $lines = explode("\n", trim($response));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            // 又拍云返回格式: 文件名\t类型\t大小\t时间
            $parts = explode("\t", $line);
            if (count($parts) < 4) continue;
            
            $name = $parts[0];
            $type = $parts[1]; // F=文件夹, N=文件
            $size = isset($parts[2]) ? intval($parts[2]) : 0;
            $mtime = isset($parts[3]) ? intval($parts[3]) : 0;
            
            if ($type === 'F') {
                // 文件夹
                $folders[] = [
                    'name' => $name,
                    'path' => trim($path . '/' . $name, '/')
                ];
            } else if ($type === 'N' && $this->isImageFile($name)) {
                // 文件（只保留图片）
                $cleanPath = '/' . trim($path . '/' . $name, '/');
                $publicUrl = $this->getPublicUrl($cleanPath);
                
                $files[] = [
                    'name' => $name,
                    'url' => $publicUrl,
                    'thumbnail' => $this->getThumbnailUrl($publicUrl),
                    'size' => $size,
                    'mtime' => $mtime,
                    'directory' => $this->extractDirectoryFromPath(trim($path . '/' . $name, '/')),
                    'id' => trim($path . '/' . $name, '/')
                ];
            }
        }
        
        return ['folders' => $folders, 'files' => $files];
    }

    private function getPublicUrl($path)
    {
        $domain = rtrim($this->upyunConfig['domain'], '/');
        $cleanPath = '/' . ltrim($path, '/');
        return $domain . $cleanPath;
    }

    private function extractObjectPath($fileUrl)
    {
        if (empty($fileUrl)) {
            return '';
        }

        $path = parse_url($fileUrl, PHP_URL_PATH);
        if (!$path) {
            return '';
        }

        return trim($path, '/');
    }

    private function extractDirectoryFromPath($path)
    {
        if (!is_string($path) || $path === '') {
            return '';
        }

        $normalized = trim(str_replace('\\', '/', $path), '/');
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

        return implode('/', $segments);
    }

    private function makeRequest($url, $method = 'GET', $headers = [], $body = null)
    {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        if ($body !== null && ($method === 'PUT' || $method === 'POST')) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return $response;
        }
        
        // 构造详细的错误信息
        $errorMsg = "又拍云请求失败 (HTTP {$httpCode})";
        if ($curlError) {
            $errorMsg .= ": {$curlError}";
        } elseif ($response) {
            // 尝试解析又拍云的错误响应
            $errorMsg .= ": " . substr($response, 0, 200);
        }
        
        throw new \Exception($errorMsg);
    }

    private function sanitizeFileName($fileName)
    {
        // 移除或替换不安全的字符
        $fileName = preg_replace('/[^\w\-\.]+/u', '_', $fileName);
        return $fileName;
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

    private function normalizePath($path)
    {
        $normalized = str_replace('\\', '/', $path);
        return trim($normalized, '/');
    }
    
    /**
     * 获取又拍云缩略图URL
     * 使用又拍云图片处理服务
     * 文档：https://help.upyun.com/knowledge-base/image/
     */
    private function getThumbnailUrl($originalUrl)
    {
        // 获取配置的缩略图尺寸
        $thumbSize = $this->config->get('thumbSize', 120);
        
        // 又拍云图片处理参数：居中裁剪成正方形缩略图
        // 格式：!/crop/宽x高/gravity/center
        return $originalUrl . '!/crop/' . $thumbSize . 'x' . $thumbSize . '/gravity/center';
    }
}


