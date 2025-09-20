<?php

/**
 * 媒体预览（支持本地、腾讯COS、阿里云OSS和兰空图床）
 * @package TEMediaFolder
 * @author 森木志
 * @version 2.3.0
 * @link https://oxxx.cn
 */

namespace TypechoPlugin\TEMediaFolder;

use Typecho\Plugin\PluginInterface;
use TypechoPlugin\TEMediaFolder\Core\ConfigManager;
use TypechoPlugin\TEMediaFolder\Core\Renderer;
use TypechoPlugin\TEMediaFolder\Services\LocalFileService;
use TypechoPlugin\TEMediaFolder\Config\ConfigForm;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 自动加载类文件
spl_autoload_register(function ($class) {
    if (strpos($class, 'TypechoPlugin\\TEMediaFolder\\') === 0) {
        $path = str_replace('TypechoPlugin\\TEMediaFolder\\', '', $class);
        $path = str_replace('\\', DIRECTORY_SEPARATOR, $path);
        
        // 处理目录名大小写映射
        $dirMap = [
            'core' => 'core',
            'services' => 'services', 
            'config' => 'config'
        ];
        
        $dir = dirname($path);
        $dirLower = strtolower($dir);
        if (isset($dirMap[$dirLower])) {
            $dir = $dirMap[$dirLower];
        }
        
        $file = __DIR__ . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . basename($path) . '.php';
        
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

class Plugin implements PluginInterface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
  
        \Typecho\Plugin::factory('admin/write-post.php')->bottom = [__CLASS__, 'render'];
        \Typecho\Plugin::factory('admin/write-page.php')->bottom = [__CLASS__, 'render'];
        
    
        \Utils\Helper::addAction('temf-cos-list', 'TEMediaFolder_Action');
        \Utils\Helper::addAction('temf-cos-upload', 'TEMediaFolder_Action');
        \Utils\Helper::addAction('temf-oss-list', 'TEMediaFolder_Action');
        \Utils\Helper::addAction('temf-oss-upload', 'TEMediaFolder_Action');
        \Utils\Helper::addAction('temf-lsky-list', 'TEMediaFolder_Action');
        \Utils\Helper::addAction('temf-lsky-upload', 'TEMediaFolder_Action');
        \Utils\Helper::addAction('temf-local-upload', 'TEMediaFolder_Action');
        \Utils\Helper::addAction('temf-storage-types', 'TEMediaFolder_Action');
        \Utils\Helper::addAction('temf-multi-list', 'TEMediaFolder_Action');
        \Utils\Helper::addAction('temf-multi-upload', 'TEMediaFolder_Action');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
      
        if (class_exists('Utils\\Helper')) {
            \Utils\Helper::removeAction('temf-cos-list');
            \Utils\Helper::removeAction('temf-cos-upload');
            \Utils\Helper::removeAction('temf-oss-list');
            \Utils\Helper::removeAction('temf-oss-upload');
            \Utils\Helper::removeAction('temf-lsky-list');
            \Utils\Helper::removeAction('temf-lsky-upload');
            \Utils\Helper::removeAction('temf-local-upload');
            \Utils\Helper::removeAction('temf-storage-types');
            \Utils\Helper::removeAction('temf-multi-list');
            \Utils\Helper::removeAction('temf-multi-upload');
        }
    }

    /**
     * 插件配置
     */
    public static function config(\Typecho\Widget\Helper\Form $form)
    {
        ConfigForm::build($form);
    }

    /**
     * 个性化配置
     */
    public static function personalConfig(\Typecho\Widget\Helper\Form $form)
    {
        // 先留着，方便后续拓展，暂时没有想到啥个性化配置
    }


    public static function render($post)
    {
        try {
            $config = ConfigManager::getInstance();

            if (!$config->isEnabled()) {
            return;
        }

            $fileService = null;
            if ($config->getStorage() === 'local') {
                $fileService = new LocalFileService($config);
            }

            $renderer = new Renderer($config, $fileService);
            $renderer->render();
        } catch (\Exception $e) {
            // 渲染错误处理
        }
    }

}