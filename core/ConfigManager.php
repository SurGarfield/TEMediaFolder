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

    private function loadConfig()
    {
        $this->config = [
            'enabled' => $this->getBoolConfig('enabled', true),
            'storage' => $this->getStringConfig('storage', 'local'),
            'maxPerMonth' => $this->getIntConfig('maxPerMonth', 200),
            'thumbSize' => $this->getIntConfig('thumb', 120),
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
                'name' => '本地存储',
                'icon' => '💻'
            ];
        }
        
        if ($this->isStorageConfigured('cos')) {
            $types[] = [
                'key' => 'cos',
                'name' => '腾讯COS',
                'icon' => '☁️'
            ];
        }
        
        if ($this->isStorageConfigured('oss')) {
            $types[] = [
                'key' => 'oss',
                'name' => '阿里云OSS',
                'icon' => '🌐'
            ];
        }
        
        if ($this->isStorageConfigured('lsky')) {
            $lskyConfig = $this->getLskyConfig();
            $types[] = [
                'key' => 'lsky',
                'name' => '兰空图床',
                'icon' => '🖼️',
                'hasAlbumId' => !empty($lskyConfig['albumId'])
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
        return __TYPECHO_ROOT_DIR__ . DIRECTORY_SEPARATOR . 'usr' . DIRECTORY_SEPARATOR . 'uploads';
    }

    public function getUploadUrl()
    {
        return \Typecho\Common::url('usr/uploads/', $this->options->siteUrl);
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
