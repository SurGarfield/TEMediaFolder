<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;

class LocalFileService
{
    private $config;
    private $fileGroups = null;

    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
    }

    public function getFileGroups()
    {
        if ($this->fileGroups === null) {
            $this->fileGroups = $this->loadFileGroups();
        }
        return $this->fileGroups;
    }

    public function clearCache()
    {
        $this->fileGroups = null;
    }

    private function loadFileGroups()
    {
        $uploadDir = $this->config->getUploadDir();
        $uploadUrl = $this->config->getUploadUrl();
        $allowed = $this->config->get('extensions', []);
        $maxPerMonth = $this->config->get('maxPerMonth', 200);

        if (!is_dir($uploadDir)) {
            return [];
        }

        // 性能优化：将扩展名数组转为哈希表以提高查找速度
        $allowedExtensions = array_flip(array_map('strtolower', $allowed));
        
        $groups = [];
        $fileCount = 0;
        $maxFiles = $maxPerMonth > 0 ? $maxPerMonth * 12 : 0; // 限制总文件数量防止内存溢出

        // 性能优化：使用更高效的迭代器配置
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($uploadDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            // 性能优化：提前退出条件
            if ($maxFiles > 0 && $fileCount >= $maxFiles) {
                break;
            }

            if (!$fileInfo->isFile()) {
                continue;
            }

            $ext = strtolower($fileInfo->getExtension());
            // 性能优化：使用哈希表查找而不是in_array
            if (!isset($allowedExtensions[$ext])) {
                continue;
            }

            $mtime = $fileInfo->getMTime();
            $ym = date('Y-m', $mtime);
            
            // 性能优化：如果该月份已达到限制，跳过旧文件
            if ($maxPerMonth > 0 && isset($groups[$ym]) && count($groups[$ym]) >= $maxPerMonth) {
                // 检查是否比现有最旧的文件更新
                $oldestInMonth = end($groups[$ym])['mtime'];
                if ($mtime <= $oldestInMonth) {
                    continue;
                }
                // 移除最旧的文件为新文件腾出空间
                array_pop($groups[$ym]);
            }

            $fullPath = $fileInfo->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($fullPath, strlen($uploadDir) + 1));
            $url = $uploadUrl . $relative;

            $fileData = [
                'url' => $url,
                'name' => $fileInfo->getFilename(),
                'mtime' => $mtime,
                'size' => $fileInfo->getSize()
            ];
            
            // 性能优化：只为图片生成缩略图URL，使用预计算的扩展名检查
            if (isset($allowedExtensions[$ext]) && $this->isImageExtension($ext)) {
                $fileData['thumbnail'] = $this->getThumbnailUrl($url);
            }
            
            // 性能优化：按时间插入排序，保持每月文件按时间降序
            if (!isset($groups[$ym])) {
                $groups[$ym] = [];
            }
            
            // 二分插入排序以保持时间顺序
            $this->insertSorted($groups[$ym], $fileData);
            
            $fileCount++;
        }

        // 性能优化：按年月倒序排列
        krsort($groups);

        return $groups;
    }

    /**
     * 性能优化：二分插入排序
     */
    private function insertSorted(&$array, $item) {
        $count = count($array);
        if ($count === 0) {
            $array[] = $item;
            return;
        }

        // 如果比最新的还新，直接插入到开头
        if ($item['mtime'] >= $array[0]['mtime']) {
            array_unshift($array, $item);
            return;
        }

        // 如果比最旧的还旧，插入到末尾
        if ($item['mtime'] <= $array[$count - 1]['mtime']) {
            $array[] = $item;
            return;
        }

        // 二分查找插入位置
        $left = 0;
        $right = $count - 1;
        
        while ($left <= $right) {
            $mid = intval(($left + $right) / 2);
            if ($array[$mid]['mtime'] > $item['mtime']) {
                $left = $mid + 1;
            } else {
                $right = $mid - 1;
            }
        }

        array_splice($array, $left, 0, [$item]);
    }

    /**
     * 性能优化：快速检查是否为图片扩展名
     */
    private function isImageExtension($extension) {
        static $imageExtensions = null;
        if ($imageExtensions === null) {
            $imageExtensions = array_flip(['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']);
        }
        return isset($imageExtensions[strtolower($extension)]);
    }

    /**
     * 获取文件列表 - 为多模式兼容
     */
    public function getFileList($path = '')
    {
        $groups = $this->getFileGroups();
        $files = [];
        
        foreach ($groups as $ym => $items) {
            foreach ($items as $item) {
                $fileData = [
                    'url' => $item['url'],
                    'name' => $item['name'],
                    'mtime' => $item['mtime'],
                    'size' => $item['size'],
                    'group' => $ym
                ];
                
                // 动态生成缩略图URL（使用URL参数）
                if ($this->isImageFile($item['name'])) {
                    $fileData['thumbnail'] = $this->getThumbnailUrl($item['url']);
                }
                
                $files[] = $fileData;
            }
        }
        
        // 返回与其他服务一致的格式
        return [
            'ok' => true,
            'files' => $files,
            'folders' => [] // 本地模式没有文件夹概念，返回空数组
        ];
    }

    /**
     * 上传文件 - 多模式兼容
     */
    public function uploadFile($filePath, $fileName, $targetPath = '')
    {
        try {
            // 检查文件是否存在
            if (!file_exists($filePath) || !is_readable($filePath)) {
                return ['ok' => false, 'msg' => 'Source file not found or not readable'];
            }

            // 图片压缩处理
            try {
                if (class_exists('\\TypechoPlugin\\TEMediaFolder\\Core\\ImageCompressor')) {
                    $compressionResult = \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::processImage($filePath, $fileName);
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
            
            // 验证文件扩展名
            $allowedExtensions = $this->config->get('extensions', ['jpg','jpeg','png','gif','webp','svg']);
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            if (!in_array($fileExtension, $allowedExtensions, true)) {
                return ['ok' => false, 'msg' => 'File type not allowed'];
            }
            
            // 检查文件大小（使用处理后的文件）
            $maxSize = 10 * 1024 * 1024; // 10MB
            $fileSize = filesize($processedFilePath);
            if ($fileSize > $maxSize) {
                // 清理临时文件
                \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::cleanupTempFile($processedFilePath);
                return ['ok' => false, 'msg' => 'File too large'];
            }
            
            // 准备目标目录
            $uploadDir = $this->config->getUploadDir();
            $currentDate = date('Y/m');
            $targetDir = $uploadDir . DIRECTORY_SEPARATOR . $currentDate;
            
            if (!is_dir($targetDir)) {
                if (!mkdir($targetDir, 0755, true)) {
                    return ['ok' => false, 'msg' => 'Failed to create upload directory'];
                }
            }
            
            // 处理文件名冲突 - 智能处理压缩后的文件名
            if ($isCompressed && $processedFilePath !== $filePath) {
                // 压缩成功，使用压缩后的文件名
                $actualFileName = basename($processedFilePath);
            } else {
                // 压缩失败或未压缩，使用原始文件名
                $actualFileName = $fileName;
            }
            
            $fileInfo = pathinfo($actualFileName);
            $baseName = $fileInfo['filename'];
            $extension = $fileInfo['extension'];
            $counter = 1;
            $finalFileName = $actualFileName;
            
            while (file_exists($targetDir . DIRECTORY_SEPARATOR . $finalFileName)) {
                $finalFileName = $baseName . '_' . $counter . '.' . $extension;
                $counter++;
            }
            
            $targetFilePath = $targetDir . DIRECTORY_SEPARATOR . $finalFileName;
            
            // 复制文件（使用处理后的文件）
            if (!copy($processedFilePath, $targetFilePath)) {
                // 清理临时文件
                \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::cleanupTempFile($processedFilePath);
                return ['ok' => false, 'msg' => 'Failed to copy uploaded file'];
            }
            
            // 生成缩略图URL (仅对图片类型) - 使用URL参数
            $thumbnailUrl = null;
            if ($this->isImageFile($extension)) {
                // 先生成文件URL用于创建缩略图URL
                $uploadUrl = $this->config->getUploadUrl();
                $fileUrl = rtrim($uploadUrl, '/') . '/' . $currentDate . '/' . $finalFileName;
                $thumbnailUrl = $this->getThumbnailUrl($fileUrl);
            }
            
            // 生成文件URL
            $uploadUrl = $this->config->getUploadUrl();
            $fileUrl = rtrim($uploadUrl, '/') . '/' . $currentDate . '/' . $finalFileName;
            
            // 添加到缓存
            $this->addFileWithSize($fileUrl, $finalFileName, time(), $fileSize, $thumbnailUrl);
            
            // 清理临时文件
            \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::cleanupTempFile($processedFilePath);
            
            $result = [
                'ok' => true,
                'url' => $fileUrl,
                'name' => $finalFileName,
                'size' => $fileSize
            ];
            
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

    /**
     * 添加新文件到缓存中
     */
    public function addFile($url, $name, $mtime = null)
    {
        $this->addFileWithSize($url, $name, $mtime, 0);
    }

    /**
     * 添加新文件到缓存中（带文件大小和缩略图）
     */
    public function addFileWithSize($url, $name, $mtime = null, $size = 0, $thumbnail = null)
    {
        $mtime = $mtime ?: time();
        $ym = date('Y-m', $mtime);

        if ($this->fileGroups === null) {
            $this->fileGroups = $this->loadFileGroups();
        }

        if (!isset($this->fileGroups[$ym])) {
            $this->fileGroups[$ym] = [];
        }

        $this->fileGroups[$ym] = array_filter($this->fileGroups[$ym], function($item) use ($url) {
            return $item['url'] !== $url;
        });

        $fileData = [
            'url' => $url,
            'name' => $name,
            'mtime' => $mtime,
            'size' => $size
        ];
        
        if ($thumbnail) {
            $fileData['thumbnail'] = $thumbnail;
        }

        array_unshift($this->fileGroups[$ym], $fileData);

        $maxPerMonth = $this->config->get('maxPerMonth', 200);
        if ($maxPerMonth > 0 && count($this->fileGroups[$ym]) > $maxPerMonth) {
            $this->fileGroups[$ym] = array_slice($this->fileGroups[$ym], 0, $maxPerMonth);
        }

        krsort($this->fileGroups);
    }

    /**
     * 检查是否为图片文件
     */
    private function isImageFile($input)
    {
        // 如果输入包含路径分隔符或点，则视为文件名，否则视为扩展名
        if (strpos($input, '/') !== false || strpos($input, '\\') !== false || strpos($input, '.') !== false) {
            // 处理文件名
            $extension = strtolower(pathinfo($input, PATHINFO_EXTENSION));
        } else {
            // 处理扩展名
            $extension = strtolower($input);
        }
        
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        return in_array($extension, $imageExtensions);
    }


    /**
     * 获取缩略图URL（本地存储直接使用原图）
     */
    private function getThumbnailUrl($originalUrl)
    {
        // 本地存储直接返回原始URL，前端会自动处理显示尺寸
        return $originalUrl;
    }

}



