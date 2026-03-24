<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;

class LocalFileService extends BaseService
{
    const INDEX_SCHEMA_VERSION = 1;
    const INDEX_TTL_SECONDS = 300;

    private static $imageExtensionsMap = [
        'jpg' => true,
        'jpeg' => true,
        'png' => true,
        'gif' => true,
        'webp' => true,
        'bmp' => true,
        'tiff' => true,
        'tif' => true,
        'ico' => true,
        'svg' => true,
    ];

    private $fileGroups = null;
    private $indexData = null;

    public function getFileGroups()
    {
        if ($this->fileGroups === null) {
            $index = $this->getIndexData();
            $this->fileGroups = isset($index['groups']) && is_array($index['groups']) ? $index['groups'] : [];
        }
        return $this->fileGroups;
    }

    public function clearCache()
    {
        $this->fileGroups = null;
        $this->indexData = null;
    }

    public function rebuildIndex()
    {
        $this->clearCache();
        return $this->getIndexData(true);
    }

    private function loadFileGroups()
    {
        $index = $this->buildIndexData();
        return isset($index['groups']) && is_array($index['groups']) ? $index['groups'] : [];
    }



    public function getFileList($path = '')
    {
        $uploadDir = $this->config->getUploadDir();
        if (!is_dir($uploadDir)) {
            return ['ok' => true, 'folders' => [], 'files' => []];
        }

        $normalizedPath = trim(str_replace(['\\'], '/', $path), '/');
        $index = $this->getIndexData();
        $folders = $this->collectImmediateSubFoldersFromIndex($normalizedPath, $index);
        $files = $this->collectFilesFromIndex($normalizedPath, $index);

        return [
            'ok' => true,
            'folders' => $folders,
            'files' => $files
        ];
    }

    
    public function uploadFile($filePath, $fileName, $targetPath = '', $targetYear = '', $targetMonth = '')
    {
        try {
            // 增强安全验证（MIME类型、图片内容、文件名清理）
            $validation = $this->validateUploadFile($filePath, $fileName);
            if (!$validation['valid']) {
                return $this->buildUploadResult(false, '', '', ['msg' => $validation['error']]);
            }
            
            // 使用清理后的文件名
            if (isset($validation['sanitizedFileName'])) {
                $fileName = $validation['sanitizedFileName'];
            }
            
            // 使用父类方法处理图片压缩
            $compressionResult = $this->processImageCompression($filePath, $fileName);
            $processedFilePath = $compressionResult['path'];
            $isCompressed = $compressionResult['compressed'];
            
            // 记录处理后的文件大小
            $fileSize = filesize($processedFilePath);
            
            // 计算上传目录（支持指定年月）
            $currentDate = '';
            if ($targetYear && $targetMonth) {
                // 使用指定的年月
                $currentDate = $targetYear . DIRECTORY_SEPARATOR . str_pad($targetMonth, 2, '0', STR_PAD_LEFT);
            } else {
                // 使用当前年月
                $currentDate = date('Y') . DIRECTORY_SEPARATOR . date('m');
            }
            
            // 确定目标目录
            $uploadDir = $this->config->getUploadDir();
            $targetDir = $uploadDir . DIRECTORY_SEPARATOR . $currentDate;
            
            // 确保目录存在
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
            
            // 添加年月信息供前端使用
            $pathParts = explode(DIRECTORY_SEPARATOR, $currentDate);
            if (count($pathParts) >= 2) {
                $options['year'] = $pathParts[0];
                $options['month'] = $pathParts[1];
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
        $directory = $directory ? str_replace('\\', '/', trim($directory, '/')) : '';
        $ym = $this->resolveGroupFromRelativePath(($directory !== '' ? $directory . '/' : '') . $name, $mtime);

        if ($this->fileGroups === null) {
            $this->fileGroups = $this->getFileGroups();
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
                'size_human' => $size > 0 ? $this->formatFileSizeHuman($size) : '',
                'directory' => $directory,
                'group' => $ym
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
        $this->updateIndexForAdd($fileData);
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
            $this->fileGroups = $this->getFileGroups();
        }

        foreach ($this->fileGroups as $ym => &$items) {
            $items = array_values(array_filter($items, function($item) use ($url) {
                return $item['url'] !== $url;
            }));
            if (empty($items)) {
                unset($this->fileGroups[$ym]);
            }
        }

        $this->updateIndexForRemove($url);
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
                        if (preg_match('/^\d{4}$/', $possibleYear) && preg_match('/^(0?[1-9]|1[0-2])$/', $possibleMonth)) {
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

    private function getIndexData($forceRebuild = false)
    {
        if ($this->indexData !== null && !$forceRebuild) {
            return $this->indexData;
        }

        $indexFile = $this->getIndexFilePath();
        if (!$forceRebuild && $this->isIndexFresh($indexFile)) {
            $loaded = $this->loadIndexFile($indexFile);
            if ($loaded !== null) {
                $this->indexData = $loaded;
                return $this->indexData;
            }
        }

        $this->indexData = $this->buildIndexData();
        $this->persistIndexData($this->indexData);
        return $this->indexData;
    }

    private function buildIndexData()
    {
        $uploadDir = $this->config->getUploadDir();
        $uploadUrl = rtrim($this->config->getUploadUrl(), '/') . '/';
        $allowed = $this->config->get('extensions', []);
        $maxPerMonth = $this->config->get('maxPerMonth', 200);

        $index = [
            'version' => self::INDEX_SCHEMA_VERSION,
            'built_at' => time(),
            'config_hash' => $this->getIndexConfigHash(),
            'directories' => [],
            'files' => [],
            'groups' => []
        ];

        if (!is_dir($uploadDir)) {
            return $index;
        }

        $allowedExtensions = array_flip(array_map('strtolower', $allowed));
        $directories = [];
        $groups = [];
        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            $baseReal = realpath($uploadDir);
            if ($baseReal === false) {
                return $index;
            }

            foreach ($iterator as $fileInfo) {
                $realPath = $fileInfo->getRealPath();
                if ($realPath === false || strpos($realPath, $baseReal) !== 0) {
                    continue;
                }

                $relative = ltrim(str_replace($baseReal, '', $realPath), DIRECTORY_SEPARATOR);
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

                if ($fileInfo->isDir()) {
                    $relativeDir = trim($relative, '/');
                    if ($relativeDir !== '') {
                        $directories[$relativeDir] = true;
                    }
                    continue;
                }

                if (!$fileInfo->isFile()) {
                    continue;
                }

                $ext = strtolower($fileInfo->getExtension());
                if (!isset($allowedExtensions[$ext])) {
                    continue;
                }

                $directoryPath = '';
                if (strpos($relative, '/') !== false) {
                    $dirName = trim(str_replace('\\', '/', dirname($relative)), '/');
                    if ($dirName !== '.' && $dirName !== '') {
                        $directoryPath = $dirName;
                    }
                }

                $mtime = $fileInfo->getMTime();
                $group = $this->resolveGroupFromRelativePath($relative, $mtime);
                $url = $uploadUrl . $relative;
                $fileData = [
                    'url' => $url,
                    'name' => $fileInfo->getFilename(),
                    'mtime' => $mtime,
                    'size' => $fileInfo->getSize(),
                    'size_human' => $this->formatFileSizeHuman($fileInfo->getSize()),
                    'directory' => $directoryPath,
                    'group' => $group
                ];

                if (isset(self::$imageExtensionsMap[$ext])) {
                    $fileData['thumbnail'] = $this->getThumbnailUrl($url);
                }

                $files[] = $fileData;
                if (!isset($groups[$group])) {
                    $groups[$group] = [];
                }
                $groups[$group][] = $fileData;
            }
        } catch (\UnexpectedValueException $e) {
            return $index;
        }

        usort($files, function ($a, $b) {
            return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
        });

        foreach ($groups as $ym => &$items) {
            usort($items, function ($a, $b) {
                return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
            });
            if ($maxPerMonth > 0 && count($items) > $maxPerMonth) {
                $items = array_slice($items, 0, $maxPerMonth);
            }
        }
        unset($items);
        krsort($groups);

        $directoryList = array_keys($directories);
        usort($directoryList, 'strnatcasecmp');

        $index['directories'] = $directoryList;
        $index['files'] = $files;
        $index['groups'] = $groups;

        return $index;
    }

    private function getIndexFilePath()
    {
        return rtrim($this->config->getUploadDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.temf_local_index.json';
    }

    private function isIndexFresh($indexFile)
    {
        if (!is_file($indexFile)) {
            return false;
        }

        $mtime = @filemtime($indexFile);
        if (!$mtime) {
            return false;
        }

        return (time() - (int)$mtime) <= self::INDEX_TTL_SECONDS;
    }

    private function loadIndexFile($indexFile)
    {
        $raw = @file_get_contents($indexFile);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        if (($data['version'] ?? 0) !== self::INDEX_SCHEMA_VERSION) {
            return null;
        }

        if (($data['config_hash'] ?? '') !== $this->getIndexConfigHash()) {
            return null;
        }

        if (!isset($data['directories']) || !is_array($data['directories']) || !isset($data['files']) || !is_array($data['files']) || !isset($data['groups']) || !is_array($data['groups'])) {
            return null;
        }

        return $data;
    }

    private function persistIndexData($index)
    {
        $indexFile = $this->getIndexFilePath();
        $dir = dirname($indexFile);
        if (!is_dir($dir)) {
            return;
        }

        $payload = json_encode($index, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || $payload === '') {
            return;
        }

        @file_put_contents($indexFile, $payload, LOCK_EX);
        $this->indexData = $index;
        $this->fileGroups = isset($index['groups']) && is_array($index['groups']) ? $index['groups'] : [];
    }

    private function getIndexConfigHash()
    {
        return md5(json_encode([
            'uploadDir' => $this->config->getUploadDir(),
            'uploadUrl' => $this->config->getUploadUrl(),
            'extensions' => $this->config->get('extensions', []),
            'maxPerMonth' => $this->config->get('maxPerMonth', 200)
        ]));
    }

    private function resolveGroupFromRelativePath($relativePath, $mtime)
    {
        $segments = explode('/', trim(str_replace('\\', '/', $relativePath), '/'));
        if (count($segments) >= 2) {
            $yearSegment = $segments[0];
            $monthSegment = $segments[1];
            if (preg_match('/^\d{4}$/', $yearSegment) && preg_match('/^(0?[1-9]|1[0-2])$/', $monthSegment)) {
                return sprintf('%s-%02d', $yearSegment, (int)$monthSegment);
            }
        }

        return date('Y-m', $mtime);
    }

    private function collectImmediateSubFoldersFromIndex($normalizedPath, $index)
    {
        $normalizedPath = trim(str_replace('\\', '/', $normalizedPath), '/');
        $prefix = $normalizedPath === '' ? '' : $normalizedPath . '/';
        $folders = [];
        $seen = [];
        $directories = isset($index['directories']) && is_array($index['directories']) ? $index['directories'] : [];

        foreach ($directories as $directory) {
            $directory = trim(str_replace('\\', '/', (string)$directory), '/');
            if ($directory === '') {
                continue;
            }

            if ($normalizedPath !== '') {
                if (strpos($directory, $prefix) !== 0) {
                    continue;
                }
                $remaining = substr($directory, strlen($prefix));
            } else {
                $remaining = $directory;
            }

            if ($remaining === '' || strpos($remaining, '/') === false && isset($seen[$remaining])) {
                continue;
            }

            $parts = explode('/', $remaining);
            $name = $parts[0];
            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            $folderPath = $normalizedPath !== '' ? $normalizedPath . '/' . $name : $name;
            $folders[] = [
                'name' => $name,
                'path' => $folderPath
            ];
            $seen[$name] = true;
        }

        usort($folders, function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });

        return $folders;
    }

    private function collectFilesFromIndex($normalizedPath, $index)
    {
        $normalizedPath = trim(str_replace('\\', '/', $normalizedPath), '/');
        $files = isset($index['files']) && is_array($index['files']) ? $index['files'] : [];
        if ($normalizedPath === '') {
            return $files;
        }

        $prefix = $normalizedPath . '/';
        return array_values(array_filter($files, function ($file) use ($normalizedPath, $prefix) {
            $directory = trim(str_replace('\\', '/', (string)($file['directory'] ?? '')), '/');
            return $directory === $normalizedPath || strpos($directory, $prefix) === 0;
        }));
    }

    private function updateIndexForAdd($fileData)
    {
        $index = $this->getIndexData();
        $url = $fileData['url'];

        $index['files'] = array_values(array_filter($index['files'], function ($item) use ($url) {
            return ($item['url'] ?? '') !== $url;
        }));
        $index['files'][] = $fileData;
        usort($index['files'], function ($a, $b) {
            return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
        });

        $group = $fileData['group'] ?? date('Y-m', (int)($fileData['mtime'] ?? time()));
        if (!isset($index['groups'][$group]) || !is_array($index['groups'][$group])) {
            $index['groups'][$group] = [];
        }
        $index['groups'][$group] = array_values(array_filter($index['groups'][$group], function ($item) use ($url) {
            return ($item['url'] ?? '') !== $url;
        }));
        array_unshift($index['groups'][$group], $fileData);

        $maxPerMonth = $this->config->get('maxPerMonth', 200);
        if ($maxPerMonth > 0 && count($index['groups'][$group]) > $maxPerMonth) {
            $index['groups'][$group] = array_slice($index['groups'][$group], 0, $maxPerMonth);
        }
        krsort($index['groups']);

        $directory = trim((string)($fileData['directory'] ?? ''), '/');
        if ($directory !== '') {
            $parts = explode('/', $directory);
            $chain = [];
            foreach ($parts as $part) {
                $chain[] = $part;
                $dirPath = implode('/', $chain);
                if (!in_array($dirPath, $index['directories'], true)) {
                    $index['directories'][] = $dirPath;
                }
            }
            usort($index['directories'], 'strnatcasecmp');
        }

        $index['built_at'] = time();
        $this->persistIndexData($index);
    }

    private function updateIndexForRemove($url)
    {
        $index = $this->getIndexData();
        $index['files'] = array_values(array_filter($index['files'], function ($item) use ($url) {
            return ($item['url'] ?? '') !== $url;
        }));

        foreach ($index['groups'] as $group => &$items) {
            $items = array_values(array_filter($items, function ($item) use ($url) {
                return ($item['url'] ?? '') !== $url;
            }));
            if (empty($items)) {
                unset($index['groups'][$group]);
            }
        }
        unset($items);

        $index['built_at'] = time();
        $this->persistIndexData($index);
    }
}
