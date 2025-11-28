<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;

class LocalFileService extends BaseService
{
    private $fileGroups = null;

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

        // 性能优化：使用更高效的迭代器配置
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($uploadDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
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
            $segments = explode('/', $relative);
            if (count($segments) >= 3) {
                $yearSegment = $segments[0];
                $monthSegment = $segments[1];
                if (preg_match('/^\d{4}$/', $yearSegment) && preg_match('/^\d{1,2}$/', $monthSegment)) {
                    $ym = sprintf('%s-%02d', $yearSegment, (int)$monthSegment);
                }
            }

            $url = $uploadUrl . $relative;

            $directoryPath = '';
            if (strpos($relative, '/') !== false) {
                $dirName = trim(str_replace('\\', '/', dirname($relative)), '/');
                if ($dirName !== '.' && $dirName !== '') {
                    $directoryPath = $dirName;
                }
            }

            $fileData = [
                'url' => $url,
                'name' => $fileInfo->getFilename(),
                'mtime' => $mtime,
                'size' => $fileInfo->getSize(),
                'directory' => $directoryPath
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

 
    private function isImageExtension($extension) {
        return $this->isImageFile('file.' . $extension);
    }

  
    public function getFileList($path = '')
    {
        $uploadDir = $this->config->getUploadDir();
        $uploadUrl = rtrim($this->config->getUploadUrl(), '/') . '/';

        if (!is_dir($uploadDir)) {
            return ['ok' => true, 'folders' => [], 'files' => []];
        }

        $normalizedPath = trim(str_replace(['\\'], '/', $path), '/');
        $targetDir = $this->resolveDirectoryPath($uploadDir, $normalizedPath);

        if ($targetDir === null || !is_dir($targetDir)) {
            return ['ok' => true, 'folders' => [], 'files' => []];
        }

        $folders = $this->collectImmediateSubFolders($uploadDir, $targetDir, $normalizedPath);
        $files = $this->collectFilesRecursively($uploadDir, $uploadUrl, $targetDir, $normalizedPath);

        return [
            'ok' => true,
            'folders' => $folders,
            'files' => $files
        ];
    }

    
    public function uploadFile($filePath, $fileName, $targetPath = '')
    {
        try {
            // 使用父类方法验证文件
            $validation = $this->validateUploadFile($filePath, $fileName);
            if (!$validation['valid']) {
                return $this->buildUploadResult(false, '', '', ['msg' => $validation['error']]);
            }

            // 使用父类方法处理图片压缩
            $compressionResult = $this->processImageCompression($filePath, $fileName);
            $processedFilePath = $compressionResult['path'];
            $isCompressed = $compressionResult['compressed'];
            
            // 检查文件大小（使用处理后的文件）
            $fileSize = filesize($processedFilePath);
            if ($fileSize > 10 * 1024 * 1024) {
                $this->cleanupTempFile($processedFilePath);
                return $this->buildUploadResult(false, '', '', ['msg' => 'File too large']);
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
                $this->cleanupTempFile($processedFilePath);
                return $this->buildUploadResult(false, '', '', ['msg' => 'Failed to copy uploaded file']);
            }
            
            // 生成文件URL
            $uploadUrl = $this->config->getUploadUrl();
            $fileUrl = rtrim($uploadUrl, '/') . '/' . $currentDate . '/' . $finalFileName;
            
            // 生成缩略图URL (仅对图片类型)
            $thumbnailUrl = null;
            if ($this->isImageFile($extension)) {
                $thumbnailUrl = $this->getThumbnailUrl($fileUrl);
            }
            
            // 添加到缓存
            $directoryPath = $currentDate !== '' ? str_replace('\\', '/', $currentDate) : '';
            $this->addFileWithSize($fileUrl, $finalFileName, time(), $fileSize, $thumbnailUrl, $directoryPath);
            
            // 清理临时文件
            $this->cleanupTempFile($processedFilePath);
            
            // 构建返回结果
            $options = ['size' => $fileSize];
            if ($thumbnailUrl) {
                $options['thumbnail'] = $thumbnailUrl;
            }
            if ($isCompressed) {
                $options['compressed'] = true;
                $options['compression_info'] = $compressionResult;
            }
            
            return $this->buildUploadResult(true, $fileUrl, $finalFileName, $options);
            
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
    public function addFileWithSize($url, $name, $mtime = null, $size = 0, $thumbnail = null, $directory = '')
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
            'size' => $size,
            'directory' => $directory ? str_replace('\\', '/', trim($directory, '/')) : ''
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

    public function renameFile($fileUrl, $newBaseName)
    {
        try {
            $fileUrl = trim($fileUrl);
            $newBaseName = trim($newBaseName);

            if ($fileUrl === '' || $newBaseName === '') {
                return ['ok' => false, 'msg' => '参数不完整'];
            }

            $uploadUrl = rtrim($this->config->getUploadUrl(), '/') . '/';
            if (strpos($fileUrl, $uploadUrl) !== 0) {
                return ['ok' => false, 'msg' => '无效的文件地址'];
            }

            $relativePath = substr($fileUrl, strlen($uploadUrl));
            $relativePath = ltrim(str_replace(['\\'], '/', $relativePath), '/');

            if ($relativePath === '') {
                return ['ok' => false, 'msg' => '无效的文件路径'];
            }

            $uploadDir = $this->config->getUploadDir();
            $oldFullPath = $uploadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if (!is_file($oldFullPath)) {
                return ['ok' => false, 'msg' => '原文件不存在'];
            }

            $pathInfo = pathinfo($oldFullPath);
            $extension = isset($pathInfo['extension']) ? $pathInfo['extension'] : '';

            $sanitizedBase = $this->sanitizeFilename($newBaseName);
            if ($sanitizedBase === '') {
                return ['ok' => false, 'msg' => '文件名无效'];
            }

            $newFilename = $extension !== '' ? $sanitizedBase . '.' . $extension : $sanitizedBase;

            if ($newFilename === basename($oldFullPath)) {
                return ['ok' => true, 'url' => $fileUrl, 'name' => $newFilename];
            }

            $newRelativePath = ($pathInfo['dirname'] !== '.')
                ? str_replace(DIRECTORY_SEPARATOR, '/', substr($pathInfo['dirname'], strlen($uploadDir) + 1))
                : '';

            $newFullDir = $pathInfo['dirname'];
            $newFullPath = $newFullDir . DIRECTORY_SEPARATOR . $newFilename;

            if (is_file($newFullPath)) {
                return ['ok' => false, 'msg' => '已存在同名文件'];
            }

            if (!@rename($oldFullPath, $newFullPath)) {
                return ['ok' => false, 'msg' => '重命名失败'];
            }

            $newRelativeUrl = $newRelativePath !== '' ? $newRelativePath . '/' . $newFilename : $newFilename;
            $newFileUrl = $uploadUrl . $newRelativeUrl;

            $fileStat = @stat($newFullPath);
            $mtime = $fileStat ? (int)$fileStat['mtime'] : time();
            $size = $fileStat ? (int)$fileStat['size'] : 0;

            $this->removeFileFromCache($fileUrl);
            $thumbnail = $this->isImageFile($newFilename) ? $this->getThumbnailUrl($newFileUrl) : null;
            $this->addFileWithSize($newFileUrl, $newFilename, $mtime, $size, $thumbnail, $newRelativePath);

            return [
                'ok' => true,
                'url' => $newFileUrl,
                'name' => $newFilename,
                'mtime' => $mtime,
                'size' => $size,
                'thumbnail' => $thumbnail,
                'directory' => $newRelativePath
            ];
        } catch (\Exception $e) {
            return ['ok' => false, 'msg' => '重命名失败: ' . $e->getMessage()];
        }
    }

    private function removeFileFromCache($url)
    {
        if ($this->fileGroups === null) {
            $this->fileGroups = $this->loadFileGroups();
        }

        foreach ($this->fileGroups as $ym => &$items) {
            $items = array_values(array_filter($items, function($item) use ($url) {
                return $item['url'] !== $url;
            }));
            if (empty($items)) {
                unset($this->fileGroups[$ym]);
            }
        }
    }

    public function deleteFile($fileUrl, $fileId = null)
    {
        try {
            $fileUrl = trim($fileUrl);
            if ($fileUrl === '') {
                return ['ok' => false, 'msg' => '参数不完整'];
            }

            $uploadUrl = rtrim($this->config->getUploadUrl(), '/') . '/';
            if (strpos($fileUrl, $uploadUrl) !== 0) {
                return ['ok' => false, 'msg' => '无效的文件地址'];
            }

            $relativePath = substr($fileUrl, strlen($uploadUrl));
            $relativePath = ltrim(str_replace(['\\'], '/', $relativePath), '/');
            if ($relativePath === '') {
                return ['ok' => false, 'msg' => '无效的文件路径'];
            }

            $uploadDir = $this->config->getUploadDir();
            $fullPath = $uploadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if (!is_file($fullPath)) {
                return ['ok' => false, 'msg' => '文件不存在'];
            }

            if (!@unlink($fullPath)) {
                return ['ok' => false, 'msg' => '删除失败'];
            }

            $metaPath = $fullPath . '.meta';
            if (is_file($metaPath)) {
                @unlink($metaPath);
            }

            $thumbPath = $fullPath . '.thumb';
            if (is_file($thumbPath)) {
                @unlink($thumbPath);
            }

            $this->removeFileFromCache($fileUrl);

            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'msg' => '删除失败: ' . $e->getMessage()];
        }
    }


    private function sanitizeFilename($name)
    {
        $disallowed = ["\\", "/", ":", "*", "?", '"', "<", ">", "|"];
        $name = str_replace($disallowed, '', $name);
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name);
        return $name;
    }

    private function resolveDirectoryPath($baseDir, $normalizedPath)
    {
        $baseReal = realpath($baseDir);
        if ($baseReal === false) {
            return null;
        }

        $target = $baseReal;
        if ($normalizedPath !== '') {
            $segments = explode('/', $normalizedPath);
            foreach ($segments as $segment) {
                $segment = trim($segment);
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    return null;
                }
                $target .= DIRECTORY_SEPARATOR . $segment;
            }
        }

        $targetReal = realpath($target);
        if ($targetReal === false) {
            return null;
        }

        if (strpos($targetReal, $baseReal) !== 0) {
            return null;
        }

        return $targetReal;
    }

    private function collectImmediateSubFolders($baseDir, $targetDir, $normalizedPath)
    {
        $folders = [];

        try {
            $iterator = new \FilesystemIterator($targetDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO);
            foreach ($iterator as $info) {
                if (!$info->isDir()) {
                    continue;
                }

                $relative = ltrim(str_replace(realpath($baseDir), '', $info->getRealPath()), DIRECTORY_SEPARATOR);
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

                $folders[] = [
                    'name' => $info->getBasename(),
                    'path' => $relative
                ];
            }
        } catch (\UnexpectedValueException $e) {
            // ignore directory read errors
        }

        usort($folders, function($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });

        return $folders;
    }

    private function collectFilesRecursively($baseDir, $baseUrl, $targetDir, $normalizedPath)
    {
        $files = [];
        $allowedExtensions = $this->config->get('extensions', ['jpg','jpeg','png','gif','webp','svg']);
        $allowedMap = array_flip(array_map('strtolower', $allowedExtensions));

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($targetDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            $baseReal = realpath($baseDir);
            foreach ($iterator as $info) {
                if (!$info->isFile()) {
                    continue;
                }

                $ext = strtolower($info->getExtension());
                if (!isset($allowedMap[$ext])) {
                    continue;
                }

                $realPath = $info->getRealPath();
                if ($realPath === false || strpos($realPath, $baseReal) !== 0) {
                    continue;
                }

                $relative = ltrim(str_replace($baseReal, '', $realPath), DIRECTORY_SEPARATOR);
                $relativeForUrl = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
                $fileUrl = $baseUrl . $relativeForUrl;

                $directoryPath = '';
                if (strpos($relativeForUrl, '/') !== false) {
                    $dirName = trim(str_replace('\\', '/', dirname($relativeForUrl)), '/');
                    if ($dirName !== '.' && $dirName !== '') {
                        $directoryPath = $dirName;
                    }
                }

                $mtime = $info->getMTime();
                $fileData = [
                    'url' => $fileUrl,
                    'name' => $info->getBasename(),
                    'mtime' => $mtime,
                    'size' => $info->getSize(),
                    'directory' => $directoryPath,
                    'group' => date('Y-m', $mtime)
                ];

                if ($this->isImageFile($info->getBasename())) {
                    $fileData['thumbnail'] = $this->getThumbnailUrl($fileUrl);
                }

                $yearFromPath = null;
                $monthFromPath = null;
                if ($directoryPath !== '') {
                    $dirParts = explode('/', $directoryPath);
                    if (count($dirParts) >= 2) {
                        $possibleYear = $dirParts[count($dirParts) - 2];
                        $possibleMonth = $dirParts[count($dirParts) - 1];
                        if (preg_match('/^\d{4}$/', $possibleYear) && preg_match('/^\d{1,2}$/', $possibleMonth)) {
                            $yearFromPath = $possibleYear;
                            $monthFromPath = (int)$possibleMonth;
                        }
                    }
                }

                if ($yearFromPath !== null && $monthFromPath !== null) {
                    $fileData['group'] = sprintf('%s-%02d', $yearFromPath, $monthFromPath);
                }
                
                $files[] = $fileData;
            }
        } catch (\UnexpectedValueException $e) {
            // ignore directory read errors
        }

        usort($files, function($a, $b) {
            return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
        });

        return $files;
    }

    private function getThumbnailUrl($originalUrl)
    {
        return $originalUrl;
    }
}
