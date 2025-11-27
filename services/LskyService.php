<?php

namespace TypechoPlugin\TEMediaFolder\Services;

use TypechoPlugin\TEMediaFolder\Core\ConfigManager;

class LskyService
{
    private $config;
    private $lskyConfig;
    private $cachedStorageId = null;

    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
        $this->lskyConfig = $config->getLskyConfig();
    }

    public function isConfigured()
    {
        return !empty($this->lskyConfig['url']) && !empty($this->lskyConfig['token']);
    }

    public function deleteFile($fileUrl, $fileId = null)
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'msg' => 'Lsky not configured'];
        }

        $fileId = $fileId !== null ? trim((string)$fileId) : '';
        if ($fileId === '') {
            return ['ok' => false, 'msg' => '缺少图片ID'];
        }

        if (!ctype_digit($fileId)) {
            return ['ok' => false, 'msg' => '无效的图片ID'];
        }

        $intId = (int)$fileId;
        if ($intId <= 0) {
            return ['ok' => false, 'msg' => '无效的图片ID'];
        }

        try {
            $endpoint = rtrim($this->lskyConfig['url'], '/') . '/api/v2/user/photos';
            $payload = json_encode([$intId]);
            if ($payload === false) {
                throw new \Exception('Failed to encode payload');
            }

            $response = $this->makeRequest($endpoint, 'DELETE', ['Content-Type: application/json'], $payload);

            if ($response) {
                $data = json_decode($response, true);
                if (is_array($data)) {
                    if (isset($data['status']) && ($data['status'] === true || $data['status'] === 'success')) {
                        return ['ok' => true];
                    }
                    if (isset($data['message'])) {
                        return ['ok' => false, 'msg' => $data['message']];
                    }
                }
            }

            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'msg' => '删除失败: ' . $e->getMessage()];
        }
    }

    /**
     * 根据相册ID过滤图片
     */
    private function filterImagesByAlbum($imageData, $albumId)
    {
        $allFiles = [];
        foreach ($imageData as $item) {
            $allFiles[] = [
                'name' => $item['name'] ?? ($item['filename'] ?? 'unknown'),
                'url' => $item['public_url'] ?? ($item['links']['url'] ?? ($item['url'] ?? '')),
                'size' => $item['size'] ?? 0,
                'id' => $item['id'] ?? ($item['key'] ?? ''),
                'albums' => $item['albums'] ?? [],
            ];
        }
        
        // 根据相册ID过滤图片
        $filteredFiles = [];
        foreach ($allFiles as $file) {
            if (isset($file['albums']) && is_array($file['albums'])) {
                foreach ($file['albums'] as $album) {
                    if (isset($album['id']) && $album['id'] == $albumId) {
                        $filteredFiles[] = $file;
                        break;
                    }
                }
            }
        }
        
        return ['folders' => [], 'files' => $filteredFiles];
    }

  
    private function tryApiEndpoints($endpoints, $queryParams = [])
    {
        $baseUrl = rtrim($this->lskyConfig['url'], '/');
        
        foreach ($endpoints as $endpoint) {
            try {
                $url = $baseUrl . $endpoint;
                if (!empty($queryParams)) {
                    $url .= '?' . http_build_query($queryParams);
                }
                
                $response = $this->makeRequest($url, 'GET');
                $data = json_decode($response, true);
                
                if ($data && isset($data['status']) && ($data['status'] === true || $data['status'] === 'success')) {
                    return $data;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        
        return null;
    }

  
    public function testConnection()
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'msg' => 'Lsky not configured'];
        }

        // 尝试不同版本的API进行连接测试
        $testEndpoints = [
            '/api/v2/user/profile',  // v2 用户信息
            '/api/v1/profile',       // v1 用户信息
            '/api/v2/images?page=1&per_page=1',  // v2 图片列表
            '/api/v1/images?page=1&per_page=1'   // v1 图片列表
        ];
        
        foreach ($testEndpoints as $endpoint) {
            try {
                $url = rtrim($this->lskyConfig['url'], '/') . $endpoint;
                $response = $this->makeRequest($url, 'GET');
                $data = json_decode($response, true);
                
                if ($data && isset($data['status']) && $data['status']) {
                    $apiVersion = strpos($endpoint, '/v2/') !== false ? 'v2' : 'v1';
                    
                    if (strpos($endpoint, 'profile') !== false && isset($data['data'])) {
                        return [
                            'ok' => true, 
                            'msg' => 'API连接成功 (API ' . $apiVersion . ')',
                            'user' => $data['data']['name'] ?? 'Unknown',
                            'email' => $data['data']['email'] ?? 'Unknown',
                            'version' => $apiVersion
                        ];
                    } else {
                        return [
                            'ok' => true, 
                            'msg' => 'API连接成功 (API ' . $apiVersion . ' - 通过images接口验证)',
                            'version' => $apiVersion
                        ];
                    }
                }
                
            } catch (\Exception $e) {
                continue; // 尝试下一个端点
            }
        }
        
        return ['ok' => false, 'msg' => 'API连接失败: 所有端点都无法访问'];
    }

    /**
     * 获取正确的存储策略ID（带缓存）
     */
    private function getValidStorageId()
    {
        if (!$this->isConfigured()) {
            return '1';
        }
        
        // 使用缓存避免重复API调用
        if ($this->cachedStorageId !== null) {
            return $this->cachedStorageId;
        }

        try {
            $baseUrl = rtrim($this->lskyConfig['url'], '/');
            
            // 尝试从用户信息中获取默认存储策略ID
            $userEndpoints = [
                '/api/v2/user/profile',  // v2 用户信息
                '/api/v1/profile',       // v1 用户信息
            ];
            
            foreach ($userEndpoints as $endpoint) {
                try {
                    $url = $baseUrl . $endpoint;
                    $response = $this->makeRequest($url, 'GET');
                    $data = json_decode($response, true);
                    
                    if ($data && isset($data['status']) && ($data['status'] === true || $data['status'] === 'success')) {
                        $userData = $data['data'];
                        
                        // 检查各种可能的存储ID字段
                        $storageId = $userData['options']['default_storage_id'] ?? 
                                   $userData['storage_id'] ?? 
                                   $userData['default_storage_id'] ?? null;
                        
                        if ($storageId) {
                            $this->cachedStorageId = strval($storageId);
                            return $this->cachedStorageId;
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
            
            // 如果无法从用户信息获取，尝试存储策略API（虽然可能404）
            $storageEndpoints = [
                '/api/v1/storages',
                '/api/v2/user/storages'
            ];
            
            foreach ($storageEndpoints as $endpoint) {
                try {
                    $url = $baseUrl . $endpoint;
                    $response = $this->makeRequest($url, 'GET');
                    $data = json_decode($response, true);
                    
                    if ($data && isset($data['status']) && ($data['status'] === true || $data['status'] === 'success')) {
                        if (isset($data['data']) && is_array($data['data']) && count($data['data']) > 0) {
                            $firstStorage = $data['data'][0];
                            $storageId = $firstStorage['id'] ?? '1';
                            return strval($storageId);
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
            
        } catch (\Exception $e) {
            // 获取存储ID失败
        }
        
        // 回退到默认值
        // 缓存默认值
        $this->cachedStorageId = '1';
        return $this->cachedStorageId;
    }

    /**
     * 获取存储策略列表
     */
    public function getStorageList()
    {
        if (!$this->isConfigured()) {
            return ['storages' => []];
        }

        try {
            $baseUrl = rtrim($this->lskyConfig['url'], '/');
            
            // 我发现好像兰空图床实例不支持存储策略API，返回默认存储策略
            return [
                'storages' => [
                    ['id' => '1', 'name' => 'Default Storage', 'key' => 'default']
                ]
            ];
            
        } catch (\Exception $e) {
            // error_log('Lsky Storage List Error: ' . $e->getMessage());
            return [
                'storages' => [
                    ['id' => '1', 'name' => 'Default Storage', 'key' => 'default']
                ]
            ];
        }
    }

    /**
     * 获取相册列表
     */
    public function getAlbumList()
    {
        if (!$this->isConfigured()) {
            return ['albums' => []];
        }

        try {
            $baseUrl = rtrim($this->lskyConfig['url'], '/');
            
            // 根据兰空图床API兼容性测试结果，智能选择相册端点
            // 旧版本兰空图床 (BatIMG): V1 ✅, V2 ✅
            // 新版本兰空图床: V1 ✅, V2 ✅
            // 两个版本都支持这些端点，按优先级排序
            $albumEndpoints = [
                '/api/v1/albums?page=1&per_page=100',          // 两个版本都支持
                '/api/v2/user/albums?page=1&per_page=100&q=',  // 两个版本都支持
            ];
            
            foreach ($albumEndpoints as $endpoint) {
                try {
                    $url = $baseUrl . $endpoint;
                    
                    $response = $this->makeRequest($url, 'GET');
                    $data = json_decode($response, true);
                    
                    if (!$data || !isset($data['status'])) {
                        continue;
                    }
                    
                    // 兼容v1和v2的不同status格式
                    $isSuccess = ($data['status'] === true || $data['status'] === 'success');
                    if (!$isSuccess) {
                        $errorMsg = isset($data['message']) ? $data['message'] : 'API request failed';
                        continue;
                    }
                    
                    // 成功获取数据
                    $albums = [];
                    if (isset($data['data']['data']) && is_array($data['data']['data'])) {
                        foreach ($data['data']['data'] as $item) {
                            $albums[] = [
                                'id' => $item['id'] ?? '',
                                'name' => $item['name'] ?? 'Unknown',
                                'intro' => $item['intro'] ?? '',
                            ];
                        }
                    } elseif (isset($data['data']) && is_array($data['data'])) {
                        // 某些API可能直接返回数组
                        foreach ($data['data'] as $item) {
                            $albums[] = [
                                'id' => $item['id'] ?? '',
                                'name' => $item['name'] ?? 'Unknown',
                                'intro' => $item['intro'] ?? '',
                            ];
                        }
                    }
                    
                    return ['albums' => $albums];
                    
                } catch (\Exception $e) {
                    continue;
                }
            }
            
            // 所有端点都失败
            return ['albums' => []];
            
        } catch (\Exception $e) {
            return ['albums' => []];
        }
    }

    public function getFileList($path = '', $forceAlbumFilter = false)
    {
        if (!$this->isConfigured()) {
            return ['folders' => [], 'files' => []];
        }

        try {
            $albumId = !empty($this->lskyConfig['albumId']) ? $this->lskyConfig['albumId'] : null;
            
            // 如果强制使用相册过滤但没有配置相册ID，返回空结果
            if ($forceAlbumFilter && !$albumId) {
                return ['folders' => [], 'files' => []];
            }
            
            // 如果强制使用相册过滤，确保使用相册ID
            if ($forceAlbumFilter) {
                // 强制使用相册过滤时，用于"相册"模式
                $useAlbumFilter = true;
            } else {
                // 正常模式："全部"模式，不进行相册过滤
                $useAlbumFilter = false;
            }
            $baseUrl = rtrim($this->lskyConfig['url'], '/');
            
             // 根据官方API文档，使用正确的图片列表端点
             // V2 API: /api/v2/user/photos 支持 album_id 参数进行过滤
             // V1 API: /api/v1/images 作为备用
             $apiEndpoints = [
                 '/api/v2/user/photos',  // V2官方端点，支持album_id参数
                 '/api/v1/images',       // V1备用端点
             ];
            
            // 智能端点选择：逐个尝试直到成功
            foreach ($apiEndpoints as $endpoint) {
                try {
                    $url = $baseUrl . $endpoint;
                    
                     $query = [
                         'page' => 1,
                         'per_page' => 100
                     ];
                     
                     // 根据模式决定是否添加相册ID过滤
                     if ($useAlbumFilter && $albumId) {
                         $query['album_id'] = $albumId;
                     }
                    
                    $url .= '?' . http_build_query($query);
                    
                    // 尝试图片列表端点
                    $response = $this->makeRequest($url, 'GET');
                    $data = json_decode($response, true);
                    
                    if (!$data || !isset($data['status'])) {
                        continue;
                    }
                    
                    // 兼容v1和v2的不同status格式
                    $isSuccess = ($data['status'] === true || $data['status'] === 'success');
                    if (!$isSuccess) {
                        continue;
                    }
                    
                     // 成功获取数据 - 根据API文档解析正确的字段
                     $files = [];
                     if (isset($data['data']['data']) && is_array($data['data']['data'])) {
                         foreach ($data['data']['data'] as $item) {
                             // 根据官方API文档，V2返回的字段结构
                             $url = $item['public_url'] ?? ($item['links']['url'] ?? ($item['url'] ?? ''));
                             $name = $item['name'] ?? ($item['filename'] ?? 'unknown');
                             
                             $fileData = [
                                 'name' => $name,
                                 'url' => $url,
                                 'size' => $item['size'] ?? 0,
                                 'id' => $item['id'] ?? ($item['key'] ?? ''),
                             ];
                             
                             // 为图片文件添加缩略图URL
                             if ($this->isImageFile($name)) {
                                 $fileData['thumbnail'] = $this->getThumbnailUrl($url, $item);
                             }
                             
                             $files[] = $fileData;
                         }
                     }
                    
                    // 最新在前排序（若返回有时间字段可加权，这里按数组原序/不可靠时不处理）
                    // 尝试按 id 或者 name 中的时间片排序（可选）。此处简单保持原序。
                    return [
                        'folders' => [],
                        'files' => $files
                    ];
                    
                } catch (\Exception $e) {
                    continue;
                }
            }
            
            // 所有端点都失败了
            throw new \Exception('All image list API endpoints failed');
            
        } catch (\Exception $e) {
            
            // 如果需要相册过滤但API调用失败，尝试获取所有图片然后过滤
            if ($useAlbumFilter && $albumId) {
                $fallbackData = $this->tryApiEndpoints(['/api/v1/images'], ['page' => 1, 'per_page' => 100]);
                if ($fallbackData && isset($fallbackData['data']['data'])) {
                    return $this->filterImagesByAlbum($fallbackData['data']['data'], $albumId);
                }
            }
            
            return ['folders' => [], 'files' => []];
        }
    }

    public function uploadFile($filePath, $fileName, $targetPath = '')
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'msg' => 'Lsky not configured'];
        }

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ['ok' => false, 'msg' => 'File not found or not readable'];
        }

        // 图片压缩处理（传递目标存储类型：lsky -> 转JPG）
        try {
            if (class_exists('\\TypechoPlugin\\TEMediaFolder\\Core\\ImageCompressor')) {
                $compressionResult = \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::processImage($filePath, $fileName, 'lsky');
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

        try {
            $baseUrl = rtrim($this->lskyConfig['url'], '/');
            
            // 依据官方文档优先尝试 V2，再尝试 V1
            // V2: servers: /api/v2 + path /upload => /api/v2/upload
            // V1: /api/v1/upload
            $uploadEndpoints = [
                '/api/v2/upload',
                '/api/v1/upload'
            ];
            
            // 使用处理后的文件检测MIME，避免原始扩展与压缩后不一致
            $mimeType = @mime_content_type($processedFilePath);
            if (!$mimeType) {
                $mimeType = 'image/jpeg'; // 默认MIME类型
            }
            
            // 智能上传：基于官方接口与兼容性
            // 新版本测试结果显示：
            // - 'file' 参数：可能成功 (需要进一步测试)
            // - 'image', 'photo', 'upload' 参数：都返回 "file 不能为空" 错误
            $paramNames = ['file']; // 新版本只接受 'file' 参数名
            
            // 先按官方参数构造：仅必需参数
            foreach ($uploadEndpoints as $endpoint) {
                foreach ($paramNames as $paramName) {
                    try {
                        $url = $baseUrl . $endpoint;
                        
                        // 文件 + 根据API版本的必要参数
                        $curlFile = new \CURLFile($processedFilePath, $mimeType, basename($processedFilePath));
                        $postData = [
                            $paramName => $curlFile
                        ];
                        
                        // 根据官方API文档添加必要参数
                        if (strpos($endpoint, '/v1/') !== false) {
                            // V1 API：根据官方文档添加所有可能有用的参数
                            $postData['permission'] = 1; // 1=公开，0=私有
                            // 杜老师说7bu的兼容 等V1实现需要有效策略ID；动态解析
                            $postData['strategy_id'] = $this->resolveStrategyIdV1();
                            if (!empty($this->lskyConfig['albumId'])) {
                                $postData['album_id'] = $this->lskyConfig['albumId'];
                            }
                        } elseif (strpos($endpoint, '/v2/') !== false) {
                            // V2 API：必需参数（storage_id 必填）
                            $validStorageId = $this->getValidStorageId();
                            $postData['storage_id'] = !empty($this->lskyConfig['strategyId']) ? intval($this->lskyConfig['strategyId']) : intval($validStorageId);
                            if (!empty($this->lskyConfig['albumId'])) {
                                $postData['album_id'] = intval($this->lskyConfig['albumId']);
                            }
                            // 可选参数：公开与去除EXIF
                            $postData['is_public'] = true; // 布尔
                            $postData['is_remove_exif'] = true; // 布尔
                        }
                        
                        // 禁用 Expect: 100-continue 以避免大文件延迟
                        $headers = ['Expect:'];
                        $response = $this->makeRequest($url, 'POST', $headers, $postData);
                        
                        
                        $data = json_decode($response, true);
                        
                        if ($data && isset($data['status']) && ($data['status'] === true || $data['status'] === 'success') && isset($data['data'])) {
                            $uploadData = $data['data'];
                            // 根据官方API文档解析URL
                            $url = '';
                            if (isset($uploadData['links']['url'])) {
                                // V1 API格式：data.links.url
                                $url = $uploadData['links']['url'];
                            } elseif (isset($uploadData['public_url'])) {
                                // V2 API格式：data.public_url
                                $url = $uploadData['public_url'];
                            } elseif (isset($uploadData['pathname'])) {
                                // 备用：根据pathname构建URL
                                $baseUrl = rtrim($this->lskyConfig['url'], '/');
                                $url = $baseUrl . '/' . $uploadData['pathname'];
                            } elseif (isset($uploadData['url'])) {
                                // 直接URL
                                $url = $uploadData['url'];
                            }
                            
                            $result = [
                                'ok' => true,
                                'url' => $url,
                                'name' => $uploadData['name'] ?? $fileName,
                                'key' => $uploadData['key'] ?? ($uploadData['id'] ?? ''),
                                'size' => $uploadData['size'] ?? filesize($filePath)
                            ];
                            return $this->addThumbnailToResult($result, $fileName, $uploadData, $compressionResult, $processedFilePath);
                        } else if (strpos($endpoint, '/v1/') !== false && $this->isStrategyNotFound($data)) {
                            // 兼容 7bu: 策略不存在时拉取策略并重试一次
                            $sid = $this->fetchFirstStrategyIdV1();
                            if ($sid) {
                                $postData['strategy_id'] = $sid;
                                $response = $this->makeRequest($url, 'POST', $headers, $postData);
                                $data = json_decode($response, true);
                                if ($data && isset($data['status']) && $data['status'] === true && isset($data['data'])) {
                                    $uploadData = $data['data'];
                                    $urlOut = '';
                                    if (isset($uploadData['links']['url'])) {
                                        $urlOut = $uploadData['links']['url'];
                                    } elseif (isset($uploadData['pathname'])) {
                                        $baseUrl = rtrim($this->lskyConfig['url'], '/');
                                        $urlOut = $baseUrl . '/' . $uploadData['pathname'];
                                    }
                                    $result = [
                                        'ok' => true,
                                        'url' => $urlOut,
                                        'name' => $uploadData['name'] ?? $fileName,
                                        'key' => $uploadData['key'] ?? ($uploadData['id'] ?? ''),
                                        'size' => $uploadData['size'] ?? filesize($filePath)
                                    ];
                                    return $this->addThumbnailToResult($result, $fileName, $uploadData, $compressionResult, $processedFilePath);
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
            
            // 如果简单上传失败，尝试V1特殊认证和参数组合（参考官方V1接口）
            foreach ($uploadEndpoints as $endpoint) {
                if (strpos($endpoint, '/v1/') !== false) {
                    // 针对新版本兰空图床的特殊V1处理
                    // 尝试不同的认证方式和参数组合
                    $authVariants = [
                        ['Authorization: Bearer ' . $this->lskyConfig['token']],
                        ['Authorization: ' . $this->lskyConfig['token']],
                        ['Token: ' . $this->lskyConfig['token']],
                    ];
                    
                    foreach ($authVariants as $authHeaders) {
                        try {
                            $url = $baseUrl . $endpoint;
                            $curlFile = new \CURLFile($processedFilePath, $mimeType, basename($processedFilePath));
                            
                            // 尝试多种V1参数组合以解决新版本"服务异常"问题
                            $paramVariants = [
                                // 完整参数组合
                                [
                                    'file' => $curlFile,
                                    'strategy_id' => $this->resolveStrategyIdV1(),
                                    'permission' => 1,
                                    'album_id' => 0
                                ],
                                // 最小参数组合
                                [
                                    'file' => $curlFile,
                                    'strategy_id' => $this->resolveStrategyIdV1()
                                ],
                                // 无相册ID
                                [
                                    'file' => $curlFile,
                                    'strategy_id' => $this->resolveStrategyIdV1(),
                                    'permission' => 1
                                ],
                                // 尝试字符串形式的permission
                                [
                                    'file' => $curlFile,
                                    'strategy_id' => $this->resolveStrategyIdV1(),
                                    'permission' => '1',
                                    'album_id' => '0'
                                ]
                            ];
                            
                            foreach ($paramVariants as $index => $postData) {
                                
                                $headers = array_merge($authHeaders, ['Expect:']);
                                $response = $this->makeRequest($url, 'POST', $headers, $postData);
                                $data = json_decode($response, true);
                                
                                if ($data && isset($data['status']) && $data['status'] === true && isset($data['data'])) {
                                    $uploadData = $data['data'];
                                    
                                    $url = '';
                                    if (isset($uploadData['links']['url'])) {
                                        $url = $uploadData['links']['url'];
                                    } elseif (isset($uploadData['pathname'])) {
                                        $baseUrl = rtrim($this->lskyConfig['url'], '/');
                                        $url = $baseUrl . '/' . $uploadData['pathname'];
                                    }
                                    
                                    $result = [
                                        'ok' => true,
                                        'url' => $url,
                                        'name' => $uploadData['name'] ?? $fileName,
                                        'key' => $uploadData['key'] ?? ($uploadData['id'] ?? ''),
                                        'size' => $uploadData['size'] ?? filesize($filePath)
                                    ];
                                    return $this->addThumbnailToResult($result, $fileName, $uploadData, $compressionResult, $processedFilePath);
                                } else {
                                }
                            }
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                }
            }
            
            foreach ($uploadEndpoints as $endpoint) {
                foreach ($paramNames as $paramName) {
                    try {
                        $url = $baseUrl . $endpoint;
                        
                        // 兰空图床可能需要不同的参数名，尝试多种方式
                        // 确保文件路径有效且可读
                        if (!file_exists($filePath) || !is_readable($filePath)) {
                            continue;
                        }
                        
                        // 创建CURLFile对象，确保文件名是UTF-8编码
                        $safeFileName = basename($fileName);
                        $curlFile = new \CURLFile($processedFilePath, $mimeType, $safeFileName);
                        
                        $postData = [
                            $paramName => $curlFile
                        ];
                        
                        // 调试信息：记录CURLFile详情
                        
                        // 根据官方API文档设置正确的参数
                        if (strpos($endpoint, '/v1/') !== false) {
                            // V1 API参数（根据官方文档）
                            // 确保始终包含strategy_id参数（新版本兰空图床可能需要）
                            $postData['strategy_id'] = !empty($this->lskyConfig['strategyId']) ? $this->lskyConfig['strategyId'] : '1';
                            if (!empty($this->lskyConfig['albumId'])) {
                                $postData['album_id'] = $this->lskyConfig['albumId'];
                            }
                            // V1可选参数
                            $postData['permission'] = 1; // 1=公开，0=私有
                        } elseif (strpos($endpoint, '/v2/') !== false) {
                            // V2 API参数（根据官方文档，修复数据类型和存储策略ID）
                            $validStorageId = $this->getValidStorageId();
                            $postData['storage_id'] = !empty($this->lskyConfig['strategyId']) ? intval($this->lskyConfig['strategyId']) : intval($validStorageId);
                            if (!empty($this->lskyConfig['albumId'])) {
                                $postData['album_id'] = intval($this->lskyConfig['albumId']);
                            }
                            // V2可选参数：修复布尔值类型
                            $postData['is_public'] = true; // 布尔值，不是字符串
                            
                        }
                        
                        // 对V2接口再额外尝试一次显式Bearer头
                        $headers = ['Expect:'];
                        if (strpos($endpoint, '/v2/') !== false) {
                            $headers[] = $this->buildAuthHeader($this->lskyConfig['token']);
                        }
                        $response = $this->makeRequest($url, 'POST', $headers, $postData);
                        
                        
                        $data = json_decode($response, true);
                        
                        if (!$data || !isset($data['status'])) {
                            continue;
                        }
                        
                        // 兼容v1和v2的不同status格式
                        $isSuccess = ($data['status'] === true || $data['status'] === 'success');
                        if ($isSuccess) {
                            // 上传成功
                            if (!isset($data['data'])) {
                                continue;
                            }
                            
                            $uploadData = $data['data'];
                            
                            
                            // 根据官方API文档解析URL
                            $url = '';
                            if (isset($uploadData['links']['url'])) {
                                // V1 API格式：data.links.url
                                $url = $uploadData['links']['url'];
                            } elseif (isset($uploadData['public_url'])) {
                                // V2 API格式：data.public_url
                                $url = $uploadData['public_url'];
                            } elseif (isset($uploadData['pathname'])) {
                                // 备用：根据pathname构建URL
                                $baseUrl = rtrim($this->lskyConfig['url'], '/');
                                $url = $baseUrl . '/' . $uploadData['pathname'];
                            } elseif (isset($uploadData['url'])) {
                                // 直接URL
                                $url = $uploadData['url'];
                            }
                            
                            $result = [
                                'ok' => true,
                                'url' => $url,
                                'name' => $uploadData['name'] ?? $uploadData['filename'] ?? $fileName,
                                'key' => $uploadData['key'] ?? $uploadData['id'] ?? '',
                                'size' => $uploadData['size'] ?? filesize($filePath)
                            ];
                            return $this->addThumbnailToResult($result, $fileName, $uploadData, $compressionResult, $processedFilePath);
                        } else {
                            $errorMsg = isset($data['message']) ? $data['message'] : 'Upload failed';
                            
                            // 如果是参数相关错误，尝试下一个参数名
                            if (strpos($errorMsg, 'file 不能为空') !== false || 
                                strpos($errorMsg, 'image 不能为空') !== false ||
                                strpos($errorMsg, '服务异常') !== false ||
                                strpos($errorMsg, 'HTTP 422') !== false) {
                                continue; // 尝试下一个参数名或端点
                            } else {
                                // 其他类型的错误，可能是权限或配置问题
                                return ['ok' => false, 'msg' => $errorMsg];
                            }
                        }
                    } catch (\Exception $e) {
                        
                        // 如果是HTTP 419 CSRF错误，尝试获取CSRF token并重试
                        if (strpos($e->getMessage(), 'HTTP 419') !== false && !isset($postData['_token'])) {
                            // HTTP 419 CSRF error, trying to get CSRF token
                            $csrfToken = $this->getCSRFToken();
                            if ($csrfToken) {
                                try {
                                    $postData['_token'] = $csrfToken;
                                    // error_log("Lsky: Retrying with CSRF token: $csrfToken");
                                    
                                    $response = $this->makeRequest($url, 'POST', [], $postData);
                                    $data = json_decode($response, true);
                                    
                                    if ($data && isset($data['status']) && ($data['status'] === true || $data['status'] === 'success') && isset($data['data'])) {
                                        $uploadData = $data['data'];
                                        // error_log("Lsky: CSRF retry SUCCESS via $endpoint with $paramName");
                                        
                                        // 根据官方API文档解析URL
                                        $url = '';
                                        if (isset($uploadData['links']['url'])) {
                                            // V1 API格式：data.links.url
                                            $url = $uploadData['links']['url'];
                                        } elseif (isset($uploadData['public_url'])) {
                                            // V2 API格式：data.public_url
                                            $url = $uploadData['public_url'];
                                        } elseif (isset($uploadData['pathname'])) {
                                            // 备用：根据pathname构建URL
                                            $baseUrl = rtrim($this->lskyConfig['url'], '/');
                                            $url = $baseUrl . '/' . $uploadData['pathname'];
                                        } elseif (isset($uploadData['url'])) {
                                            $url = $uploadData['url'];
                                        }
                                        
                                        $result = [
                                            'ok' => true,
                                            'url' => $url,
                                            'name' => $uploadData['name'] ?? $uploadData['filename'] ?? $fileName,
                                            'key' => $uploadData['key'] ?? $uploadData['id'] ?? '',
                                            'size' => $uploadData['size'] ?? filesize($filePath)
                                        ];
                                        return $this->addThumbnailToResult($result, $fileName, $uploadData, $compressionResult, $processedFilePath);
                                    }
                                } catch (\Exception $e2) {
                                    // error_log("Lsky: CSRF retry failed: " . $e2->getMessage());
                                }
                            }
                        }
                        
                        // 如果是HTTP 404或500错误，继续尝试下一个组合
                        if (strpos($e->getMessage(), 'HTTP 404') !== false || 
                            strpos($e->getMessage(), 'HTTP 500') !== false) {
                            continue;
                        }
                        
                        // 其他错误，继续下一个组合
                        continue;
                    }
                }
            }
            
            // 如果所有基本端点都失败，返回通用错误
            return ['ok' => false, 'msg' => 'All upload endpoints failed'];
            
        } catch (\Exception $e) {
            // 清理临时文件
            \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::cleanupTempFile($processedFilePath);
            // error_log('Lsky Upload Error: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Upload failed: ' . $e->getMessage()];
        }
    }

    private function makeRequest($url, $method = 'GET', $headers = [], $data = null)
    {
        $token = $this->lskyConfig['token'];

        if (empty($token)) {
            throw new \Exception('Lsky API Token is required');
        }

        $authHeader = $this->buildAuthHeader($token);

        $defaultHeaders = [
            $authHeader,
            'Accept: application/json',
            'User-Agent: TEMediaFolder-Plugin/1.0'
        ];

        $isFileUpload = ($method === 'POST' && is_array($data) && $this->containsCURLFile($data));

        if ($isFileUpload) {
            $headers = array_merge([
                $authHeader,
                'Accept: application/json',
                'Expect:'
            ], $headers);
        } else {
            $headers = array_merge($defaultHeaders, $headers);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $isFileUpload ? 180 : 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_VERBOSE => false
        ]);

        if ($method !== 'GET' && $data !== null) {
            if ($method === 'POST' && $isFileUpload) {
                $processedData = [];
                foreach ($data as $key => $value) {
                    if ($value instanceof \CURLFile) {
                        $processedData[$key] = $value;
                    } elseif (is_bool($value)) {
                        $processedData[$key] = $value ? '1' : '0';
                    } else {
                        $processedData[$key] = $value;
                    }
                }

                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $processedData);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                }
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            throw new \Exception('CURL Error: ' . ($error ?: 'Unknown network error'));
        }

        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMsg = 'HTTP ' . $httpCode;

            if ($errorData) {
                if (isset($errorData['message'])) {
                    $errorMsg .= ': ' . $errorData['message'];
                } elseif (isset($errorData['error'])) {
                    $errorMsg .= ': ' . $errorData['error'];
                } elseif (isset($errorData['errors'])) {
                    $errors = is_array($errorData['errors']) ? implode(', ', $errorData['errors']) : $errorData['errors'];
                    $errorMsg .= ': ' . $errors;
                }
            }

            if ($httpCode === 401 || $httpCode === 403) {
                $errorMsg .= ' (请检查API Token是否正确或已过期)';
            }

            throw new \Exception($errorMsg);
        }

        if (($response === '' || $response === null) && !in_array($httpCode, [204, 205], true)) {
            throw new \Exception('Empty response from server');
        }

        return $response ?? '';
    }

    private function resolveStrategyIdV1()
    {
        // 优先使用配置的 strategyId
        if (!empty($this->lskyConfig['strategyId'])) {
            return (string)$this->lskyConfig['strategyId'];
        }
        // 尝试获取第一个可用策略
        $sid = $this->fetchFirstStrategyIdV1();
        return $sid ?: '1';
    }

    private function fetchFirstStrategyIdV1()
    {
        try {
            $baseUrl = rtrim($this->lskyConfig['url'], '/');
            // 7bu 文档：GET /strategies 返回列表
            $url = $baseUrl . '/api/v1/strategies';
            $resp = $this->makeRequest($url, 'GET');
            $data = @json_decode($resp, true);
            if ($data && !empty($data['status']) && isset($data['data']['strategies']) && is_array($data['data']['strategies'])) {
                foreach ($data['data']['strategies'] as $s) {
                    if (isset($s['id'])) {
                        return (string)$s['id'];
                    }
                }
            }
        } catch (\Exception $e) {
        }
        return null;
    }

    private function isStrategyNotFound($data)
    {
        if (!is_array($data)) return false;
        $msg = isset($data['message']) ? (string)$data['message'] : '';
        // 兼容多种提示：策略不存在 / 选定的策略不存在 / strategy not found
        if ($msg && (mb_strpos($msg, '策略不存在') !== false || stripos($msg, 'strategy') !== false)) {
            return true;
        }
        if (isset($data['errors']) && is_array($data['errors'])) {
            $flat = json_encode($data['errors'], JSON_UNESCAPED_UNICODE);
            return (mb_strpos($flat, '策略') !== false || stripos($flat, 'strategy') !== false);
        }
        return false;
    }
    
    /**
     * 根据token格式构建认证头
     */
    private function buildAuthHeader($token)
    {
        // 兰空图床常见的token格式：
        // 1. Bearer token (标准JWT或API token)
        // 2. 简单字符串token
        
        if (strpos($token, 'Bearer ') === 0) {
            // 如果token已经包含Bearer前缀
            return 'Authorization: ' . $token;
        } elseif (preg_match('/^[a-zA-Z0-9\-_]{40,}$/', $token)) {
            // 长token，可能是API token
            return 'Authorization: Bearer ' . $token;
        } else {
            // 短token或其他格式，尝试Bearer
            return 'Authorization: Bearer ' . $token;
        }
    }
    
    /**
     * 获取CSRF Token（适用于Laravel框架的兰空图床）
     */
    private function getCSRFToken()
    {
        try {
            $baseUrl = rtrim($this->lskyConfig['url'], '/');
            
            // 尝试从主页和上传页面获取CSRF token
            $testUrls = [
                $baseUrl,
                $baseUrl . '/upload',
                $baseUrl . '/login'
            ];
            
            foreach ($testUrls as $testUrl) {
                // error_log("Lsky: Trying to get CSRF token from: $testUrl");
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $testUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200 && $response) {
                    // error_log("Lsky: Got response from $testUrl (length: " . strlen($response) . ")");
                    
                    // 尝试从HTML中提取CSRF token - 多种模式
                    $patterns = [
                        // Laravel meta标签
                        '/<meta\s+name="csrf-token"\s+content="([^"]+)"/i',
                        // 表单hidden input
                        '/<input[^>]*name="_token"[^>]*value="([^"]+)"/i',
                        // JavaScript变量
                        '/window\.Laravel\s*=\s*{[^}]*["\']csrfToken["\']\s*:\s*["\']([^"\']+)["\']/i',
                        // 其他可能的模式
                        '/_token["\']?\s*[:=]\s*["\']([^"\']+)["\']/i',
                        // Vue.js或其他框架
                        '/csrf[_-]?token["\']?\s*[:=]\s*["\']([^"\']+)["\']/i'
                    ];
                    
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $response, $matches)) {
                            $token = $matches[1];
                            // error_log("Lsky: Found CSRF token: $token (from $testUrl)");
                            return $token;
                        }
                    }
                }
            }
            
            // error_log("Lsky: Could not extract CSRF token from any URL");
            return null;
            
        } catch (\Exception $e) {
            // error_log('Lsky: CSRF token extraction error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 检查数据中是否包含文件上传
     */
    private function containsCURLFile($data)
    {
        if (!is_array($data)) {
            return false;
        }
        
        foreach ($data as $value) {
            if ($value instanceof \CURLFile) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 无认证请求方法（用于某些不需要Token的兰空图床实例）
     */
    private function makeRequestNoAuth($url, $method = 'GET', $headers = [], $data = null)
    {
        $defaultHeaders = [
            'Accept: application/json',
            'User-Agent: TEMediaFolder-Plugin/1.0'
        ];
        
        // 对于文件上传，不设置Content-Type让curl自动处理
        $isFileUpload = ($method === 'POST' && $data && $this->containsCURLFile($data));
        if ($isFileUpload) {
            // 文件上传时，只保留必要的头部，让CURL自动处理Content-Type
            $headers = [
                'Accept: application/json'
            ];
        } else {
            $headers = array_merge($defaultHeaders, $headers);
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_VERBOSE => false
        ]);
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            if ($isFileUpload) {
                curl_setopt($ch, CURLOPT_POST, true);
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // 调试信息记录
        // API请求完成
        
        if ($response === false || !empty($error)) {
            throw new \Exception('CURL Error: ' . ($error ?: 'Unknown network error'));
        }
        
        // 处理HTTP状态码
        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMsg = 'HTTP ' . $httpCode;
            
            if ($errorData) {
                if (isset($errorData['message'])) {
                    $errorMsg .= ': ' . $errorData['message'];
                } elseif (isset($errorData['error'])) {
                    $errorMsg .= ': ' . $errorData['error'];
                } elseif (isset($errorData['errors'])) {
                    $errors = is_array($errorData['errors']) ? implode(', ', $errorData['errors']) : $errorData['errors'];
                    $errorMsg .= ': ' . $errors;
                }
            }
            
            throw new \Exception($errorMsg);
        }
        
        return $response;
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
     * 获取兰空图床缩略图URL
     */
    private function getThumbnailUrl($originalUrl, $thumbnailData = null)
    {
        // 如果Lsky API返回了缩略图URL，直接使用
        if ($thumbnailData && isset($thumbnailData['thumbnail_url'])) {
            return $thumbnailData['thumbnail_url'];
        }
        
        // 否则，尝试添加缩略图参数
        // 注意：这取决于Lsky的具体配置，可能需要根据实际情况调整
        $thumbSize = $this->config->get('thumbSize', 120);
        $thumbParam = 'thumbnail=' . $thumbSize . 'x' . $thumbSize;
        
        if (strpos($originalUrl, '?') !== false) {
            return $originalUrl . '&' . $thumbParam;
        } else {
            return $originalUrl . '?' . $thumbParam;
        }
    }

    /**
     * 为上传结果添加缩略图和压缩信息
     */
    private function addThumbnailToResult($result, $fileName, $uploadData = null, $compressionResult = null, $processedFilePath = null)
    {
        if ($result['ok']) {
            if ($this->isImageFile($fileName)) {
                $result['thumbnail'] = $this->getThumbnailUrl($result['url'], $uploadData);
            }
            if ($compressionResult && $compressionResult['compressed']) {
                $result['compressed'] = true;
                $result['compression_info'] = $compressionResult;
            }
            // 清理临时文件
            if ($processedFilePath) {
                \TypechoPlugin\TEMediaFolder\Core\ImageCompressor::cleanupTempFile($processedFilePath);
            }
        }
        return $result;
    }
}
