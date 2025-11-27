<?php

namespace TypechoPlugin\TEMediaFolder\Core;

/**
 * 配置管理器
 * 统一管理插件配置
 */
class ConfigManager
{
    private static $instance = null;
    private $options = null;
    private $plugin = null;
    private $config = [];

    private function __construct()
    {
        $this->options = \Widget\Options::alloc();
        $this->plugin = $this->options->plugin('TEMediaFolder');
        $this->loadConfig();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 重置单例实例（用于测试或强制重新加载配置）
     */
    public static function resetInstance()
    {
        self::$instance = null;
    }

    /**
     * 重新加载配置
     */
    public function reload()
    {
        $this->options = \Widget\Options::alloc();
        $this->plugin = $this->options->plugin('TEMediaFolder');
        $this->loadConfig();
    }

    private function loadConfig()
    {
        $this->config = [
            'enabled' => $this->getBoolConfig('enabled', true),
            'storage' => $this->getStringConfig('storage', 'local'),
            'maxPerMonth' => $this->getIntConfig('maxPerMonth', 200),
            'thumbSize' => $this->getIntConfig('thumb', 120),
            'paginationRows' => $this->getIntConfig('paginationRows', 4),
            'extensions' => $this->getArrayConfig('extensions', ['jpg','jpeg','png','gif','webp','svg']),
            
            // COS配置
            'cos' => [
                'bucket' => $this->getStringConfig('cosBucket', ''),
                'region' => $this->getStringConfig('cosRegion', 'ap-beijing'),
                'secretId' => $this->getStringConfig('cosSecretId', ''),
                'secretKey' => $this->getStringConfig('cosSecretKey', ''),
                'prefix' => $this->getStringConfig('cosPrefix', ''),
                'domain' => $this->getStringConfig('cosDomain', ''),
            ],
            
            // OSS配置
            'oss' => [
                'bucket' => $this->getStringConfig('ossBucket', ''),
                'endpoint' => $this->getStringConfig('ossEndpoint', ''),
                'accessKeyId' => $this->getStringConfig('ossAccessKeyId', ''),
                'accessKeySecret' => $this->getStringConfig('ossAccessKeySecret', ''),
                'prefix' => $this->getStringConfig('ossPrefix', ''),
                'domain' => $this->getStringConfig('ossDomain', ''),
            ],
            
            // Lsky配置
            'lsky' => [
                'url' => $this->getStringConfig('lskyUrl', ''),
                'token' => $this->getStringConfig('lskyToken', ''),
                'albumId' => $this->getStringConfig('lskyAlbumId', ''),
                'strategyId' => $this->getStringConfig('lskyStrategyId', ''),
            ],
            
            // Upyun配置
            'upyun' => [
                'bucket' => $this->getStringConfig('upyunBucket', ''),
                'operator' => $this->getStringConfig('upyunOperator', ''),
                'password' => $this->getStringConfig('upyunPassword', ''),
                'domain' => $this->getStringConfig('upyunDomain', ''),
            ],

            // WebP压缩配置
            'enableWebpCompression' => $this->getStringConfig('enableWebpCompression', '1') === '1',
            'webpQuality' => (int)$this->getStringConfig('webpQuality', '80')
        ];
    }

    public function get($key, $default = null)
    {
        return $this->getNestedValue($this->config, $key, $default);
    }

    public function isEnabled()
    {
        return $this->config['enabled'];
    }

    public function getStorage()
    {
        return $this->config['storage'];
    }

    public function getCosConfig()
    {
        return $this->config['cos'];
    }

    public function getOssConfig()
    {
        return $this->config['oss'];
    }

    public function getLskyConfig()
    {
        return $this->config['lsky'];
    }

    public function getUpyunConfig()
    {
        return $this->config['upyun'];
    }

    /**
     * 检查指定存储类型是否已配置
     */
    public function isStorageConfigured($storageType)
    {
        switch ($storageType) {
            case 'cos':
                $config = $this->getCosConfig();
                return !empty($config['bucket']) && !empty($config['secretId']) && !empty($config['secretKey']);
            
            case 'oss':
                $config = $this->getOssConfig();
                return !empty($config['bucket']) && !empty($config['accessKeyId']) && !empty($config['accessKeySecret']);
            
            case 'lsky':
                $config = $this->getLskyConfig();
                return !empty($config['url']) && !empty($config['token']);
            
            case 'upyun':
                $config = $this->getUpyunConfig();
                return !empty($config['bucket']) && !empty($config['operator']) && !empty($config['password']) && !empty($config['domain']);
            
            case 'local':
                return true; // 本地存储总是可用
            
            default:
                return false;
        }
    }

    /**
     * 获取所有已配置的存储类型
     */
    public function getAvailableStorageTypes()
    {
        $types = [];
        
        if ($this->isStorageConfigured('local')) {
            $types[] = [
                'key' => 'local',
                'name' => '本地存储'
            ];
        }
        
        if ($this->isStorageConfigured('cos')) {
            $types[] = [
                'key' => 'cos',
                'name' => '腾讯COS'
            ];
        }
        
        if ($this->isStorageConfigured('oss')) {
            $types[] = [
                'key' => 'oss',
                'name' => '阿里云OSS'
            ];
        }
        
        if ($this->isStorageConfigured('lsky')) {
            $lskyConfig = $this->getLskyConfig();
            $types[] = [
                'key' => 'lsky',
                'name' => '兰空图床',
                'hasAlbumId' => !empty($lskyConfig['albumId'])
            ];
        }
        
        if ($this->isStorageConfigured('upyun')) {
            $types[] = [
                'key' => 'upyun',
                'name' => '又拍云'
            ];
        }
        
        return $types;
    }

    /**
     * 检查是否启用多模式
     */
    public function isMultiMode()
    {
        return $this->getStorage() === 'multi';
    }

    public function getUploadDir()
    {
        $rootDir = defined('TYPECHO_UPLOAD_ROOT_DIR')
            ? rtrim(TYPECHO_UPLOAD_ROOT_DIR, DIRECTORY_SEPARATOR)
            : __TYPECHO_ROOT_DIR__;

        $relativeDir = defined('__TYPECHO_UPLOAD_DIR__')
            ? trim(__TYPECHO_UPLOAD_DIR__, '\\/')
            : 'usr/uploads';

        if ($relativeDir === '') {
            return $rootDir;
        }

        $relativeDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDir);

        return $rootDir . DIRECTORY_SEPARATOR . $relativeDir;
    }

    public function getUploadUrl()
    {
        if (defined('__TYPECHO_UPLOAD_URL__')) {
            return rtrim(__TYPECHO_UPLOAD_URL__, '/') . '/';
        }

        $relativeDir = defined('__TYPECHO_UPLOAD_DIR__')
            ? trim(__TYPECHO_UPLOAD_DIR__, '/\\')
            : 'usr/uploads';

        if ($relativeDir !== '') {
            $relativeDir .= '/';
        }

        return \Typecho\Common::url($relativeDir, $this->options->siteUrl);
    }

    public function isMarkdownEnabled()
    {
        return (bool)$this->options->markdown;
    }

    private function getBoolConfig($key, $default = false)
    {
        return isset($this->plugin->$key) ? (bool)$this->plugin->$key : $default;
    }

    private function getStringConfig($key, $default = '')
    {
        return isset($this->plugin->$key) ? trim((string)$this->plugin->$key) : $default;
    }

    private function getIntConfig($key, $default = 0)
    {
        return isset($this->plugin->$key) ? (int)$this->plugin->$key : $default;
    }

    private function getArrayConfig($key, $default = [])
    {
        if (!isset($this->plugin->$key) || empty($this->plugin->$key)) {
            return $default;
        }
        
        $value = trim((string)$this->plugin->$key);
        return array_filter(array_map('trim', explode(',', strtolower($value))));
    }

    private function getNestedValue($array, $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $array;
        
        foreach ($keys as $k) {
            if (!is_array($value) || !isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
}
