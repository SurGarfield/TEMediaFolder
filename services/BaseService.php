<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;

abstract class BaseService
{
    const DEFAULT_THUMB_SIZE = 120;

    protected $config;
    
    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
    }
    

    protected function isImageFile($fileName)
    {
        static $imageExtensions = null;
        if ($imageExtensions === null) {
            $imageExtensions = ['jpg' => true, 'jpeg' => true, 'png' => true, 
                              'gif' => true, 'webp' => true, 'bmp' => true];
        }
        
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return isset($imageExtensions[$extension]);
    }
    
 
    protected function processImageCompression($filePath, $fileName, $targetStorage = null)
    {
        try {
            if (class_exists('\\TypechoPlugin\\TEMediaFolder\\Core\\ImageCompressor')) {
                return \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::processImage(
                    $filePath, 
                    $fileName, 
                    $targetStorage
                );
            }
        } catch (\Exception $e) {
            // 压缩失败，继续使用原文件
        }
        
        return ['path' => $filePath, 'compressed' => false];
    }
    
    protected function cleanupTempFile($filePath)
    {
        if (class_exists('\\TypechoPlugin\\TEMediaFolder\\Core\\ImageCompressor')) {
            \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::cleanupTempFile($filePath);
        }
    }

    protected function getDefaultThumbSize()
    {
        return self::DEFAULT_THUMB_SIZE;
    }

    protected function buildPathPrefix($basePrefix, $path)
    {
        $prefix = rtrim((string)$basePrefix, '/');
        if (!empty($path)) {
            $prefix = ($prefix === '' ? '' : $prefix . '/') . trim((string)$path, '/');
        }
        return $prefix === '' ? '' : $prefix . '/';
    }

    protected function sanitizeBaseName($baseName)
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9\-_]+/', '_', (string)$baseName);
        $sanitized = trim($sanitized, '._-');
        if ($sanitized === '') {
            $sanitized = 'file_' . date('YmdHis');
        }
        return substr($sanitized, 0, 80);
    }

    protected function encodeObjectKey($key)
    {
        $key = ltrim(str_replace(['\\'], '/', (string)$key), '/');
        if ($key === '') {
            return '';
        }
        $segments = array_map('rawurlencode', explode('/', $key));
        return implode('/', $segments);
    }

    protected function resolveNetworkUploadPath($targetPath)
    {
        if (!$this->config->get('networkYearMonthFolders', false)) {
            return trim((string)$targetPath, '/');
        }

        return date('Y/m');
    }

    protected function stripConfiguredPrefix($path, $configuredPrefix)
    {
        $normalizedPath = trim((string)$path, '/');
        $normalizedPrefix = trim((string)$configuredPrefix, '/');
        if ($normalizedPrefix !== '' && strpos($normalizedPath, $normalizedPrefix . '/') === 0) {
            return substr($normalizedPath, strlen($normalizedPrefix) + 1);
        }
        return $normalizedPath;
    }

    protected function formatFileSizeHuman($bytes)
    {
        $value = (float)$bytes;
        if (!is_finite($value) || $value <= 0) {
            return '';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        if ($index === 0) {
            return round($value) . ' ' . $units[$index];
        }

        $precision = $value >= 100 ? 0 : ($value >= 10 ? 1 : 2);
        $formatted = number_format($value, $precision, '.', '');
        if ($precision > 0) {
            $formatted = preg_replace('/(\.\d*?[1-9])0+$/', '$1', $formatted);
            $formatted = preg_replace('/\.0+$/', '', $formatted);
        }

        return $formatted . ' ' . $units[$index];
    }
   
    protected function buildUploadResult($success, $url = '', $name = '', $options = [])
    {
        $result = ['ok' => $success];
        
        if ($success) {
            $result['url'] = $url;
            $result['name'] = $name;
            
            // 合并额外选项
            $result = array_merge($result, $options);
        } else {
            $result['msg'] = $options['msg'] ?? 'Upload failed';
        }
        
        return $result;
    }

    protected function buildImageUploadOptions($fileName, $publicUrl, $isCompressed = false, $compressionResult = [], $options = [], $thumbnailUrl = null)
    {
        if ($thumbnailUrl === null && $this->isImageFile($fileName)) {
            $thumbnailUrl = $publicUrl;
        }

        if (!empty($thumbnailUrl)) {
            $options['thumbnail'] = $thumbnailUrl;
        }

        if ($isCompressed) {
            $options['compressed'] = true;
            $options['compression_info'] = $compressionResult;
        }

        return $options;
    }
    

    protected function buildFileListResult($files = [], $folders = [])
    {
        return [
            'ok' => true,
            'folders' => $folders,
            'files' => $files
        ];
    }
    
 
    protected function sortFilesByMtime(&$files)
    {
        usort($files, function($a, $b) {
            return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
        });
    }
    
  
    protected function filterAllowedFiles($files)
    {
        $allowedExtensions = $this->config->get('extensions', ['jpg','jpeg','png','gif','webp','svg']);
        $allowedMap = array_flip(array_map('strtolower', $allowedExtensions));
        
        return array_filter($files, function($file) use ($allowedMap) {
            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            return isset($allowedMap[$ext]);
        });
    }
    
  
    protected function validateUploadFile($filePath, $fileName, $maxSize = null)
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ['valid' => false, 'error' => 'File not found or not readable'];
        }
        
        // 清理文件名（防止路径遍历和特殊字符）
        $sanitizedFileName = $this->sanitizeFileName($fileName);
        if ($sanitizedFileName !== $fileName) {
            // 文件名被修改，返回新文件名
            $fileName = $sanitizedFileName;
        }
        
        // 验证扩展名
        $allowedExtensions = $this->config->get('extensions', ['jpg','jpeg','png','gif','webp','svg']);
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        if (!in_array($fileExtension, $allowedExtensions, true)) {
            return ['valid' => false, 'error' => 'File type not allowed'];
        }
        
        // MIME 类型验证（增强安全性）
        $mimeValidation = $this->validateMimeType($filePath, $fileExtension);
        if (!$mimeValidation['valid']) {
            return $mimeValidation;
        }
        
        // 图片内容验证（确保是真实的图片文件）
        if ($this->isImageFile($fileName)) {
            $imageValidation = $this->validateImageContent($filePath);
            if (!$imageValidation['valid']) {
                return $imageValidation;
            }
        }
        
        // 验证文件大小（优先使用 PHP 上传限制）
        $maxSize = $maxSize ?? $this->getEffectiveUploadLimitBytes();
        $fileSize = filesize($filePath);
        if ($maxSize > 0 && $fileSize > $maxSize) {
            return [
                'valid' => false,
                'error' => 'File too large. Max: ' . $this->formatBytes($maxSize)
            ];
        }
        
        return ['valid' => true, 'sanitizedFileName' => $fileName];
    }

    protected function getEffectiveUploadLimitBytes()
    {
        $uploadMax = $this->parseIniSizeToBytes(ini_get('upload_max_filesize'));
        $postMax = $this->parseIniSizeToBytes(ini_get('post_max_size'));

        if ($uploadMax > 0 && $postMax > 0) {
            return min($uploadMax, $postMax);
        }

        if ($uploadMax > 0) {
            return $uploadMax;
        }

        if ($postMax > 0) {
            return $postMax;
        }

        return 0;
    }

    protected function parseIniSizeToBytes($value)
    {
        if ($value === false || $value === null) {
            return 0;
        }

        $value = trim((string)$value);
        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;
        }

        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMGTP]?B?)?$/i', $value, $matches)) {
            return 0;
        }

        $number = (float)$matches[1];
        $unit = strtoupper($matches[2] ?? '');

        $multiplier = 1;
        if ($unit === 'K' || $unit === 'KB') {
            $multiplier = 1024;
        } elseif ($unit === 'M' || $unit === 'MB') {
            $multiplier = 1024 * 1024;
        } elseif ($unit === 'G' || $unit === 'GB') {
            $multiplier = 1024 * 1024 * 1024;
        } elseif ($unit === 'T' || $unit === 'TB') {
            $multiplier = 1024 * 1024 * 1024 * 1024;
        } elseif ($unit === 'P' || $unit === 'PB') {
            $multiplier = 1024 * 1024 * 1024 * 1024 * 1024;
        }

        return (int) floor($number * $multiplier);
    }

    protected function formatBytes($bytes)
    {
        $bytes = (float)$bytes;
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        if ($index === 0) {
            return (string)round($bytes) . ' ' . $units[$index];
        }

        return rtrim(rtrim(number_format($bytes, 2, '.', ''), '0'), '.') . ' ' . $units[$index];
    }
    
    /**
     * 验证 MIME 类型
     * 防止恶意文件伪装成图片
     */
    protected function validateMimeType($filePath, $extension)
    {
        // MIME 类型白名单
        $allowedMimes = [
            'jpg'  => ['image/jpeg', 'image/pjpeg'],
            'jpeg' => ['image/jpeg', 'image/pjpeg'],
            'png'  => ['image/png', 'image/x-png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'bmp'  => ['image/bmp', 'image/x-bmp', 'image/x-ms-bmp'],
            'svg'  => ['image/svg+xml']
        ];
        
        if (!isset($allowedMimes[$extension])) {
            return ['valid' => false, 'error' => 'Unknown file extension'];
        }
        
        // 使用 finfo 检测 MIME 类型
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                
                if ($mimeType && !in_array($mimeType, $allowedMimes[$extension], true)) {
                    return ['valid' => false, 'error' => 'File content does not match extension'];
                }
            }
        }
        
        return ['valid' => true];
    }
    
    /**
     * 验证图片内容
     * 确保文件是有效的图片格式
     */
    protected function validateImageContent($filePath)
    {
        // 使用 getimagesize 验证图片真实性
        $imageInfo = @getimagesize($filePath);
        
        if ($imageInfo === false) {
            return ['valid' => false, 'error' => 'Invalid image file'];
        }
        
        // 验证图片类型是否在允许范围内
        $allowedImageTypes = [
            IMAGETYPE_JPEG,
            IMAGETYPE_PNG,
            IMAGETYPE_GIF,
            IMAGETYPE_WEBP,
            IMAGETYPE_BMP,
            IMAGETYPE_SWF  // SVG 无法通过 getimagesize 检测，单独处理
        ];
        
        if (!in_array($imageInfo[2], $allowedImageTypes, true)) {
            return ['valid' => false, 'error' => 'Unsupported image type'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * 清理文件名
     * 防止路径遍历攻击和特殊字符问题
     */
    protected function sanitizeFileName($fileName)
    {
        // 移除路径分隔符
        $fileName = str_replace(['/', '\\', '..'], '', $fileName);
        
        // 移除NULL字节
        $fileName = str_replace("\0", '', $fileName);
        
        // 限制文件名长度（保留扩展名）
        $maxLength = 200;
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $basename = pathinfo($fileName, PATHINFO_FILENAME);
        
        if (strlen($basename) > $maxLength) {
            $basename = substr($basename, 0, $maxLength);
        }
        
        // 移除控制字符
        $basename = preg_replace('/[\x00-\x1F\x7F]/u', '', $basename);
        
        return $basename . ($extension ? '.' . $extension : '');
    }
}

