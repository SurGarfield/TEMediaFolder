<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;

abstract class BaseService
{
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
        
        // 验证扩展名
        $allowedExtensions = $this->config->get('extensions', ['jpg','jpeg','png','gif','webp','svg']);
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        if (!in_array($fileExtension, $allowedExtensions, true)) {
            return ['valid' => false, 'error' => 'File type not allowed'];
        }
        
        // 验证文件大小
        $maxSize = $maxSize ?? 10 * 1024 * 1024; // 默认10MB
        $fileSize = filesize($filePath);
        if ($fileSize > $maxSize) {
            return ['valid' => false, 'error' => 'File too large'];
        }
        
        return ['valid' => true];
    }
}

