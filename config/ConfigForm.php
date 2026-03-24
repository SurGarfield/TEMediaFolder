<?php

namespace TypechoPlugin\TEMediaFolder\Config;

class ConfigForm
{
    public static function build(\Typecho\Widget\Helper\Form $form)
    {
        self::addEnvCheck($form);
        self::addBasicConfig($form);
        
        self::addCosConfig($form);
        
        self::addOssConfig($form);

        self::addBitifulConfig($form);
        
        self::addUpyunConfig($form);
        
        self::addLskyConfig($form);
        
        self::addAdvancedConfig($form);
        
        self::addAssets();
    }
    
    private static function addEnvCheck(\Typecho\Widget\Helper\Form $form)
    {
        try {
            $gdLoaded = extension_loaded('gd');
            $gdInfo = $gdLoaded && function_exists('gd_info') ? gd_info() : [];
            $webpSupported = $gdLoaded && !empty($gdInfo['WebP Support']);

            $curlLoaded = extension_loaded('curl');
            $tmpDir = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '/tmp';
            $tmpWritable = @is_writable($tmpDir);

            $uploadMax = ini_get('upload_max_filesize');
            $postMax = ini_get('post_max_size');
            $memoryLimit = ini_get('memory_limit');

            // 计算上传目录路径（避免未定义常量导致致命错误）
            $rootDir = defined('__TYPECHO_ROOT_DIR__') ? __TYPECHO_ROOT_DIR__ : @realpath(dirname(__DIR__, 4));
            if (!$rootDir) {
                $rootDir = dirname(__DIR__, 4);
            }
            $uploadDir = $rootDir . DIRECTORY_SEPARATOR . 'usr' . DIRECTORY_SEPARATOR . 'uploads';
            $uploadWritable = @is_dir($uploadDir) ? @is_writable($uploadDir) : @is_writable(dirname($uploadDir));

            echo '<div class="temf-card temf-collapse-card temf-env-wrap">';
            echo '<details class="temf-collapse">';
            echo '<summary>环境自检</summary>';
            echo '<div class="temf-collapse-body temf-env">';
            echo '<style>
                .temf-env .temf-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:8px 0}
                .temf-env .temf-kv{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 10px;border:1px solid #e7ebf0;border-radius:8px;background:#fff}
                .temf-env .temf-kv span:first-child{color:#3b4351}
                .temf-env .temf-badge{display:inline-block;min-width:18px;text-align:center;padding:2px 8px;border-radius:999px;font-size:12px}
                .temf-env .ok{background:#edf7f0;color:#246b45;border:1px solid #d6eadc}
                .temf-env .no{background:#fff2f0;color:#b5473c;border:1px solid #f3d2cd}
                .temf-env .muted{color:#666;font-size:12px}
                @media (max-width: 900px){.temf-env .temf-row{grid-template-columns:1fr}}
            </style>';
            $badge = function($b){ return $b ? '<span class="temf-badge ok">OK</span>' : '<span class="temf-badge no">NO</span>'; };
            echo '<div class="temf-row">';
            echo '<div class="temf-kv"><span>GD 扩展</span><span>' . $badge($gdLoaded) . '</span></div>';
            echo '<div class="temf-kv"><span>WebP 支持</span><span>' . $badge($webpSupported) . '</span></div>';
            echo '<div class="temf-kv"><span>cURL 扩展（兰空）</span><span>' . $badge($curlLoaded) . '</span></div>';
            echo '<div class="temf-kv"><span>临时目录可写</span><span>' . $badge($tmpWritable) . '</span></div>';
            echo '<div class="temf-kv"><span>上传目录可写</span><span>' . $badge($uploadWritable) . '</span></div>';
            echo '<div class="temf-kv"><span>allow_url_fopen</span><span>' . $badge(!!ini_get('allow_url_fopen')) . '</span></div>';
            echo '<div class="temf-kv"><span>PHP 版本</span><span class="temf-badge ok" style="background:#eef6ff;color:#0b62bd;border:1px solid #d6e9ff">' . htmlspecialchars(PHP_VERSION) . '</span></div>';
            echo '<div class="temf-kv"><span>upload_max_filesize</span><span class="temf-badge ok" style="background:#eef6ff;color:#0b62bd;border:1px solid #d6e9ff">' . htmlspecialchars($uploadMax) . '</span></div>';
            echo '<div class="temf-kv"><span>post_max_size</span><span class="temf-badge ok" style="background:#eef6ff;color:#0b62bd;border:1px solid #d6e9ff">' . htmlspecialchars($postMax) . '</span></div>';
            echo '<div class="temf-kv"><span>memory_limit</span><span class="temf-badge ok" style="background:#eef6ff;color:#0b62bd;border:1px solid #d6e9ff">' . htmlspecialchars($memoryLimit) . '</span></div>';
            echo '</div>';

            echo '<p class="description" style="margin:6px 0 0;">建议：上传限制不低于 20M，内存限制不低于 256M。</p>';
            echo '</div>';
            echo '</details>';
            echo '</div>';
        } catch (\Exception $e) {
            // 安静失败，避免影响设置页
        }
    }
    
    private static function addBasicConfig(\Typecho\Widget\Helper\Form $form)
    {
        $enabled = new \Typecho\Widget\Helper\Form\Element\Radio(
            'enabled',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '1',
            _t('是否启用媒体插件'),
            _t('关闭后，不会在插件页面显示媒体文件夹')
        );
        $form->addInput($enabled);
        
        $storage = new \Typecho\Widget\Helper\Form\Element\Radio(
            'storage',
            [
                'local' => _t('本地'), 
                'cos' => _t('腾讯COS'), 
                'oss' => _t('阿里云OSS'), 
                'bitiful' => _t('缤纷云存储'),
                'upyun' => _t('又拍云'),
                'lsky' => _t('兰空图床'),
            'multi' => _t('我全都要')
            ],
            'local',
            _t('存储方式'),
            _t('单一模式使用一个存储；“我全都要”会同时启用已配置的存储，并在素材库中切换。')
        );
        $form->addInput($storage);
    }
    
    private static function addCosConfig(\Typecho\Widget\Helper\Form $form)
    {
        echo '<p class="description temf-cos-desc" style="margin:8px 0;">' . _t('填写腾讯云 COS 配置。') . '</p>';
        
        $cosBucket = new \Typecho\Widget\Helper\Form\Element\Text(
            'cosBucket', 
            null, 
            '', 
            _t('存储桶名称（Bucket）'), 
            _t('输入 COS 存储桶名称，例如 example-1250000000。')
        );
        $cosBucket->setAttribute('class', 'temf-cos-field');
        $form->addInput($cosBucket);
        
        $cosRegion = new \Typecho\Widget\Helper\Form\Element\Text(
            'cosRegion', 
            null, 
            'ap-beijing', 
            _t('所属地域（Region）'), 
            _t('输入存储桶所属地域，例如 ap-beijing。')
        );
        $cosRegion->setAttribute('class', 'temf-cos-field');
        $form->addInput($cosRegion);
        
        $cosSecretId = new \Typecho\Widget\Helper\Form\Element\Text(
            'cosSecretId', 
            null, 
            '', 
            _t('访问密钥 ID（SecretId）'), 
            _t('输入腾讯云访问密钥 ID。')
        );
        $cosSecretId->setAttribute('class', 'temf-cos-field');
        $form->addInput($cosSecretId);
        
        $cosSecretKey = new \Typecho\Widget\Helper\Form\Element\Text(
            'cosSecretKey', 
            null, 
            '', 
            _t('访问密钥（SecretKey）'), 
            _t('输入腾讯云访问密钥。')
        );
        $cosSecretKey->setAttribute('class', 'temf-cos-field');
        $form->addInput($cosSecretKey);
        
        $cosPrefix = new \Typecho\Widget\Helper\Form\Element\Text(
            'cosPrefix', 
            null, 
            '', 
            _t('路径前缀（Prefix）'), 
            _t('留空为根目录；直接填写如 uploads 或 uploads/2026，不需要以 / 开头或结尾。')
        );
        $cosPrefix->setAttribute('class', 'temf-cos-field');
        $form->addInput($cosPrefix);
        
        $cosDomain = new \Typecho\Widget\Helper\Form\Element\Text(
            'cosDomain', 
            null, 
            '', 
            _t('Domain（自定义域名，可选）'), 
            _t('例如: https://oxxx.cn,不带"/"')
        );
        $cosDomain->setAttribute('class', 'temf-cos-field');
        $form->addInput($cosDomain);
    }
    
    private static function addOssConfig(\Typecho\Widget\Helper\Form $form)
    {
        echo '<p class="description temf-oss-desc" style="margin:8px 0;">' . _t('填写阿里云 OSS 配置。') . '</p>';
        
        $ossBucket = new \Typecho\Widget\Helper\Form\Element\Text(
            'ossBucket', 
            null, 
            '', 
            _t('存储桶名称（Bucket）'), 
            _t('输入 OSS 存储桶名称。')
        );
        $ossBucket->setAttribute('class', 'temf-oss-field');
        $form->addInput($ossBucket);
        
        $ossEndpoint = new \Typecho\Widget\Helper\Form\Element\Text(
            'ossEndpoint', 
            null, 
            '', 
            _t('服务地址（Endpoint）'), 
            _t('输入 OSS 服务地址，例如 https://oss-cn-beijing.aliyuncs.com。')
        );
        $ossEndpoint->setAttribute('class', 'temf-oss-field');
        $form->addInput($ossEndpoint);
        
        $ossAccessKeyId = new \Typecho\Widget\Helper\Form\Element\Text(
            'ossAccessKeyId', 
            null, 
            '', 
            _t('访问密钥 ID（AccessKeyId）'), 
            _t('输入阿里云访问密钥 ID。')
        );
        $ossAccessKeyId->setAttribute('class', 'temf-oss-field');
        $form->addInput($ossAccessKeyId);
        
        $ossAccessKeySecret = new \Typecho\Widget\Helper\Form\Element\Text(
            'ossAccessKeySecret', 
            null, 
            '', 
            _t('访问密钥（AccessKeySecret）'), 
            _t('输入阿里云访问密钥。')
        );
        $ossAccessKeySecret->setAttribute('class', 'temf-oss-field');
        $form->addInput($ossAccessKeySecret);
        
        $ossPrefix = new \Typecho\Widget\Helper\Form\Element\Text(
            'ossPrefix', 
            null, 
            '', 
            _t('路径前缀（Prefix）'), 
            _t('留空为根目录；直接填写如 uploads 或 uploads/2026，不需要以 / 开头或结尾。')
        );
        $ossPrefix->setAttribute('class', 'temf-oss-field');
        $form->addInput($ossPrefix);
        
        $ossDomain = new \Typecho\Widget\Helper\Form\Element\Text(
            'ossDomain', 
            null, 
            '', 
            _t('Domain（自定义域名，可选）'), 
            _t('例如: https://oxxx.cn,不带"/"')
        );
        $ossDomain->setAttribute('class', 'temf-oss-field');
        $form->addInput($ossDomain);
    }

    private static function addBitifulConfig(\Typecho\Widget\Helper\Form $form)
    {
        $testBitifulUrl = \Widget\Security::alloc()->getIndex('/action/temf-test-bitiful');
        echo '<p class="description temf-bitiful-desc" style="margin:8px 0;" data-test-bitiful-url="' . htmlspecialchars($testBitifulUrl) . '">' . _t('填写缤纷云 S4 配置。') . '</p>';

        $bitifulBucket = new \Typecho\Widget\Helper\Form\Element\Text(
            'bitifulBucket',
            null,
            '',
            _t('存储桶名称（Bucket）'),
            _t('输入缤纷云存储桶名称。')
        );
        $bitifulBucket->setAttribute('class', 'temf-bitiful-field');
        $form->addInput($bitifulBucket);

        $bitifulRegion = new \Typecho\Widget\Helper\Form\Element\Text(
            'bitifulRegion',
            null,
            'cn-east-1',
            _t('所属地域（Region）'),
            _t('输入缤纷云地域，默认 cn-east-1。')
        );
        $bitifulRegion->setAttribute('class', 'temf-bitiful-field');
        $form->addInput($bitifulRegion);

        $bitifulEndpoint = new \Typecho\Widget\Helper\Form\Element\Text(
            'bitifulEndpoint',
            null,
            'https://s3.bitiful.net',
            _t('服务地址（Endpoint）'),
            _t('输入缤纷云服务地址，默认 https://s3.bitiful.net。')
        );
        $bitifulEndpoint->setAttribute('class', 'temf-bitiful-field');
        $form->addInput($bitifulEndpoint);

        $bitifulAccessKey = new \Typecho\Widget\Helper\Form\Element\Text(
            'bitifulAccessKey',
            null,
            '',
            _t('访问密钥 ID（AccessKey）'),
            _t('输入缤纷云 AccessKey。')
        );
        $bitifulAccessKey->setAttribute('class', 'temf-bitiful-field');
        $form->addInput($bitifulAccessKey);

        $bitifulSecretKey = new \Typecho\Widget\Helper\Form\Element\Text(
            'bitifulSecretKey',
            null,
            '',
            _t('访问密钥（SecretKey）'),
            _t('输入缤纷云 SecretKey。<button type="button" id="test-bitiful-connection" class="btn btn-xs">测试连接</button>')
        );
        $bitifulSecretKey->setAttribute('class', 'temf-bitiful-field');
        $bitifulSecretKey->input->setAttribute('type', 'password');
        $form->addInput($bitifulSecretKey);

        $bitifulPrefix = new \Typecho\Widget\Helper\Form\Element\Text(
            'bitifulPrefix',
            null,
            '',
            _t('路径前缀（Prefix）'),
            _t('留空为根目录；直接填写如 uploads 或 uploads/2026，不需要以 / 开头或结尾。')
        );
        $bitifulPrefix->setAttribute('class', 'temf-bitiful-field');
        $form->addInput($bitifulPrefix);

        $bitifulDomain = new \Typecho\Widget\Helper\Form\Element\Text(
            'bitifulDomain',
            null,
            '',
            _t('Domain（自定义域名，可选）'),
            _t('例如: https://cdn.example.com，不带最后的斜杠')
        );
        $bitifulDomain->setAttribute('class', 'temf-bitiful-field');
        $form->addInput($bitifulDomain);
    }
    
    private static function addUpyunConfig(\Typecho\Widget\Helper\Form $form)
    {
        // 生成测试 URL
        $testUpyunUrl = \Widget\Security::alloc()->getIndex('/action/temf-test-upyun');
        
        echo '<div class="temf-upyun-desc" style="margin:8px 0; padding:10px; background:#f8f8f8; " data-test-upyun-url="' . htmlspecialchars($testUpyunUrl) . '">';
        echo '<p style="margin:0;">填写又拍云的服务名称、操作员账号、操作员密码和加速域名，保存前可先测试连接。</p>';
        echo '</div>';
        
        $upyunBucket = new \Typecho\Widget\Helper\Form\Element\Text(
            'upyunBucket',
            null,
            '',
            _t('服务名称'),
            _t('输入又拍云服务名称，也就是 Bucket 名称。')
        );
        $upyunBucket->setAttribute('class', 'temf-upyun-field');
        $form->addInput($upyunBucket);
        
        $upyunOperator = new \Typecho\Widget\Helper\Form\Element\Text(
            'upyunOperator',
            null,
            '',
            _t('操作员账号'),
            _t('输入已授权到该服务的操作员账号。')
        );
        $upyunOperator->setAttribute('class', 'temf-upyun-field');
        $form->addInput($upyunOperator);
        
        $upyunPassword = new \Typecho\Widget\Helper\Form\Element\Text(
            'upyunPassword',
            null,
            '',
            _t('操作员密码'),
            _t('输入操作员密码。<button type="button" id="test-upyun-connection" class="btn btn-xs">测试连接</button>')
        );
        $upyunPassword->setAttribute('class', 'temf-upyun-field');
        $upyunPassword->input->setAttribute('type', 'password');
        $form->addInput($upyunPassword);
        
        $upyunDomain = new \Typecho\Widget\Helper\Form\Element\Text(
            'upyunDomain',
            null,
            '',
            _t('加速域名'),
            _t('输入已绑定的加速域名，例如 https://cdn.example.com，不带最后的斜杠。')
        );
        $upyunDomain->setAttribute('class', 'temf-upyun-field');
        $form->addInput($upyunDomain);
    }
    
    private static function addLskyConfig(\Typecho\Widget\Helper\Form $form)
    {
        echo '<div class="temf-lsky-desc" style="margin:8px 0; padding:10px; background:#f8f8f8; ">';
        echo '<p style="margin:0;">填写兰空图床地址和 API Token。相册 ID、存储策略 ID 可按需填写；不确定时留空即可。兰空图床不支持目录结构，因此“网络存储按年月上传”对它无效。</p>';
        echo '</div>';
        
        $lskyUrl = new \Typecho\Widget\Helper\Form\Element\Text(
            'lskyUrl', 
            null, 
            '', 
            _t('兰空图床地址'), 
            _t('输入兰空图床完整地址，例如 https://img.example.com，不带最后的斜杠。')
        );
        $lskyUrl->setAttribute('class', 'temf-lsky-field');
        $form->addInput($lskyUrl);
        
        $lskyToken = new \Typecho\Widget\Helper\Form\Element\Text(
            'lskyToken', 
            null, 
            '', 
            _t('API Token'), 
            _t('输入在兰空图床后台生成的 API Token。<button type="button" id="test-lsky-token" class="btn btn-xs">测试连接</button>')
        );
        $lskyToken->setAttribute('class', 'temf-lsky-field');
        $form->addInput($lskyToken);
        
        $lskyAlbumId = new \Typecho\Widget\Helper\Form\Element\Text(
            'lskyAlbumId', 
            null, 
            '', 
            _t('相册ID（可选）'), 
            _t('可选；留空时显示全部图片。')
        );
        $lskyAlbumId->setAttribute('class', 'temf-lsky-field');
        $form->addInput($lskyAlbumId);
        
        $lskyStrategyId = new \Typecho\Widget\Helper\Form\Element\Text(
            'lskyStrategyId', 
            null, 
            '', 
            _t('存储策略ID（可选）'), 
            _t('可选；指定上传使用的存储策略，留空则使用默认策略。')
        );
        $lskyStrategyId->setAttribute('class', 'temf-lsky-field');
        $form->addInput($lskyStrategyId);
    }
    
    private static function addAdvancedConfig(\Typecho\Widget\Helper\Form $form)
    {
        $paginationRows = new \Typecho\Widget\Helper\Form\Element\Text(
            'paginationRows',
            null,
            '4',
            _t('每页显示行数'),
            _t('默认 4。建议设置为 3-6。')
        );
        $paginationRows->setAttribute('class', 'temf-advanced-field');
        $form->addInput($paginationRows);

        $networkYearMonthFolders = new \Typecho\Widget\Helper\Form\Element\Radio(
            'networkYearMonthFolders',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '0',
            _t('网络存储按年月上传'),
            _t('启用后，目录型网络存储上传会按当前年月自动写入，如 /2026/03。兰空图床不支持目录结构，此选项对兰空图床无效。')
        );
        $networkYearMonthFolders->setAttribute('class', 'temf-advanced-field');
        $form->addInput($networkYearMonthFolders);
        
        $extensions = new \Typecho\Widget\Helper\Form\Element\Text(
            'extensions',
            null,
            'jpg,jpeg,png,gif,webp,svg',
            _t('允许的扩展名'),
            _t('以逗号分隔，例如: jpg,png,webp')
        );
        $extensions->setAttribute('class', 'temf-advanced-field');
        $form->addInput($extensions);
        
        $enableWebpCompression = new \Typecho\Widget\Helper\Form\Element\Radio(
            'enableWebpCompression',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '1',
            _t('WebP图片压缩(强烈建议打开）'),
            _t('上传时自动转换为 WebP 以减小体积。需要服务器支持 GD 的 WebP 功能。')
        );
        $enableWebpCompression->setAttribute('class', 'temf-advanced-field');
        $form->addInput($enableWebpCompression);
        
        $webpQuality = new \Typecho\Widget\Helper\Form\Element\Text(
            'webpQuality',
            null,
            '80',
            _t('压缩质量'),
            _t('图片压缩质量（1-100），数值越高质量越好但文件越大，建议70-90')
        );
        $webpQuality->setAttribute('class', 'temf-advanced-field');
        $form->addInput($webpQuality);

        
    }
    
    private static function addAssets()
    {
        echo <<<HTML
<style>
.temf-card {
    margin: 16px 0;
    padding: 12px 14px;
    border: 1px dashed #d7ddd6;
    border-radius: 12px;
    background: #f7f8f5;
}
.temf-card h4 {
    margin: 0 0 10px;
    font-size: 14px;
    color: #2f3a34;
}
.temf-env-wrap,
#temf-advanced-group {
    margin-top: 18px;
    padding: 12px 14px 0;
    border: 1px dashed #d7ddd6;
    border-radius: 12px;
    background: #f7f8f5;
    box-shadow: none;
}
.temf-env-wrap {
    border-color: #d3ddd8;
    background: #f6faf7;
}
.temf-config-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 8px 0 14px;
}
.temf-config-tab {
    border: 1px dashed #cfd7cf;
    border-radius: 10px;
    background: #fff;
    color: #4d5952;
    padding: 6px 12px;
    font-size: 12px;
    line-height: 1;
    cursor: pointer;
    position: relative;
    padding-right: 20px;
}
.temf-config-tab.active {
    border-style: solid;
    border-color: #4f7f73;
    background: #edf4f1;
    color: #2f3a34;
    box-shadow: inset 0 0 0 1px rgba(79, 127, 115, 0.14);
}
.temf-config-tab::after {
    content: '';
    position: absolute;
    top: 7px;
    right: 8px;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #c2c8c2;
}
.temf-config-tab.temf-config-tab-ready::after {
    background: #4f8a63;
    box-shadow: 0 0 0 2px #eef5f0;
}
.temf-config-tab.temf-config-tab-empty {
    color: #8a7563;
    border-color: #dccfbe;
    background: #fdfaf6;
    position: relative;
}
.temf-config-tab.temf-config-tab-empty::after {
    background: #d14343;
    box-shadow: 0 0 0 2px #fdfaf6;
}
.temf-config-tab.active.temf-config-tab-ready::after {
    background: #3f7a56;
    box-shadow: 0 0 0 2px #edf4f1;
}
.temf-config-tab.active.temf-config-tab-empty::after {
    box-shadow: 0 0 0 2px #edf4f1;
}
.temf-config-hint {
    margin: 8px 0 0;
    color: #64716a;
    font-size: 12px;
    line-height: 1.6;
}
.temf-config-hint strong {
    color: #2f3a34;
}
.typecho-option {
    margin: 0 0 14px;
    list-style: none !important;
    padding-left: 0 !important;
}
.typecho-option li {
    padding: 12px 0;
    border-bottom: 1px solid #eef1ee;
    list-style: none !important;
    padding-left: 0 !important;
}
.typecho-option li::marker {
    content: none !important;
    font-size: 0 !important;
}
.typecho-option > li:last-child {
    border-bottom: none;
}
.typecho-label {
    display: inline-block;
    margin-bottom: 8px;
    color: #2f3a34;
    font-weight: 600;
}
.typecho-option > li > span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 0 18px 8px 0;
}
.typecho-option input[type="text"],
.typecho-option input[type="password"],
.typecho-option textarea,
.typecho-option select {
    margin-top: 6px;
}
.typecho-option .description {
    margin-top: 8px;
    color: #6b7280;
    line-height: 1.7;
}
#test-upyun-connection,
#test-lsky-token,
#test-bitiful-connection {
    margin-left: 6px;
    padding: 2px 8px;
    border: 1px solid #d5ddd6;
    border-radius: 999px;
    background: #fff;
    color: #4d5952;
    font-size: 12px;
    line-height: 1.4;
    cursor: pointer;
}
#test-upyun-connection:hover,
#test-lsky-token:hover,
#test-bitiful-connection:hover {
    border-color: #bfcac2;
    background: #f7faf8;
}
#test-upyun-connection:disabled,
#test-lsky-token:disabled,
#test-bitiful-connection:disabled {
    opacity: .65;
    cursor: not-allowed;
}
.temf-env-wrap .temf-collapse > summary,
#temf-advanced-group .temf-collapse > summary {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 0 0 12px 18px;
    font-size: 14px;
    font-weight: 600;
    color: #2f3a34;
}
.temf-env-wrap .temf-collapse > summary::before,
#temf-advanced-group .temf-collapse > summary::before {
    content: '';
    position: absolute;
    left: 0;
    top: 5px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #8aa6c1;
}
#temf-advanced-group .temf-collapse > summary::before {
    background: #9a8b73;
}
.temf-env-wrap .temf-collapse-body,
#temf-advanced-group .temf-collapse-body {
    padding: 6px 0 12px;
}
.temf-env-wrap .description,
#temf-advanced-group .description {
    color: #666;
}
.temf-env-wrap .temf-collapse[open] > summary,
#temf-advanced-group .temf-collapse[open] > summary {
    border-bottom: 1px solid #edf0f3;
    margin-bottom: 10px;
}
.temf-config-status {
    margin: 6px 0 10px;
    padding: 7px 9px;
    border-radius: 4px;
    font-size: 11px;
    line-height: 1.7;
}
.temf-test-result {
    margin-top: 8px;
    padding: 8px 10px;
    border-radius: 4px;
    font-size: 11px;
    line-height: 1.5;
    border: 1px solid rgba(72, 69, 61, 0.06);
    background: #faf8f5;
    color: var(--temf-ink);
    white-space: pre-line;
}
.temf-test-result.ok {
    border-color: #cfe7d6;
    background: #edf9f1;
    color: #1f6f43;
}
.temf-test-result.error {
    border-color: #f3c2c2;
    background: #fff1f1;
    color: #b42318;
}
.temf-collapse-card {
    padding: 12px 14px 0;
}
.temf-collapse {
    border: none;
}
.temf-collapse > summary {
    list-style: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 12px;
    font-size: 13px;
    font-weight: 600;
    color: #2f3a34;
    user-select: none;
    border-bottom: 0;
}
.temf-collapse > summary::-webkit-details-marker {
    display: none;
}
.temf-collapse > summary::after {
    content: '展开';
    flex: 0 0 auto;
    font-size: 12px;
    font-weight: 400;
    color: #667085;
}
.temf-collapse[open] > summary::after {
    content: '收起';
}
.temf-collapse-body {
    padding: 8px 12px 12px;
}
.temf-upyun-desc,
.temf-lsky-desc,
.temf-bitiful-desc,
.temf-oss-desc,
.temf-cos-desc {
    padding: 8px 10px;
    border-radius: 4px;
    background: #fff;
    border: 1px dashed #dde3dc;
}
.temf-storage-layout {
    margin: 8px 0 0;
}
.temf-storage-section-title {
    margin: 10px 0 6px;
    color: #64716a;
    font-size: 12px;
    font-weight: 600;
}
.temf-storage-section-title:first-child {
    margin-top: 0;
}
.temf-storage-row {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px 12px;
    margin-bottom: 8px;
}
.temf-storage-choice {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 38px;
    width: 100%;
    padding: 8px 10px;
    border: 1px dashed #d9dfd8;
    border-radius: 10px;
    background: #fff;
    box-sizing: border-box;
    cursor: pointer;
}
.temf-storage-choice input {
    margin: 0;
}
.temf-storage-choice label {
    margin: 0;
    flex: 1 1 auto;
    cursor: pointer;
}
.temf-storage-choice.temf-storage-choice-active {
    border-style: solid;
    border-color: #4f7f73;
    background: #edf4f1;
}
.temf-storage-multi {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 10px 12px;
    border: 1px dashed #d7ddd6;
    border-radius: 10px;
    background: #fff;
    margin-bottom: 8px;
}
.temf-storage-multi label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    font-weight: 600;
    cursor: pointer;
}
.temf-storage-multi-note {
    color: #64716a;
    font-size: 12px;
    line-height: 1.5;
}
.temf-storage-multi-note strong {
    color: #2f3a34;
}
.temf-storage-hidden {
    display: none !important;
}
#temf-advanced-group ul {
    margin-bottom: 10px;
}
.temf-advanced-field + .description {
    color: #666;
}
@media (max-width: 900px) {
    .temf-storage-row {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .temf-storage-multi {
        flex-direction: column;
        align-items: flex-start;
    }
}
@media (max-width: 640px) {
    .temf-storage-row {
        grid-template-columns: 1fr;
    }
}
.submit,
p.submit,
.typecho-option-submit {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
    padding: 8px 0 0;
    background: transparent;
}
.submit .btn,
p.submit .btn,
.typecho-option-submit .btn,
.submit button,
p.submit button,
.typecho-option-submit button,
.submit input[type="submit"],
p.submit input[type="submit"],
.typecho-option-submit input[type="submit"] {
    min-width: 112px;
}
.temf-upyun-desc p,
.temf-lsky-desc p {
    color: #666;
}
</style>
<script>
(function() {
    var radios;
    
    function ready() {
        radios = document.querySelectorAll('input[name="storage"]');
        var groupMap = {};
        var activeConfigTab = 'cos';

        function enhancePageFrame() {
            return;
        }

        function enhanceStorageLayout() {
            var storageInput = document.querySelector('input[name="storage"]');
            var multiRadio = document.querySelector('input[name="storage"][value="multi"]');
            if (!storageInput || !multiRadio) return;
            var storageWrap = storageInput.closest ? storageInput.closest('li, .typecho-option, tr') : null;
            if (!storageWrap || storageWrap.getAttribute('data-temf-storage-ready') === '1') return;

            var optionMap = {};
            Array.prototype.forEach.call(document.querySelectorAll('input[name="storage"]'), function (radio) {
                if (!radio || !radio.value) return;
                var container = radio.parentNode;
                if (!container) return;
                optionMap[radio.value] = { container: container };
            });

            var row1Values = ['local', 'cos', 'oss'];
            var row2Values = ['bitiful', 'upyun', 'lsky'];
            var layout = document.createElement('div');
            layout.className = 'temf-storage-layout';

            function addSectionTitle(text) {
                var title = document.createElement('div');
                title.className = 'temf-storage-section-title';
                title.textContent = text;
                layout.appendChild(title);
            }

            function buildRow(values) {
                var row = document.createElement('div');
                row.className = 'temf-storage-row';
                values.forEach(function (value) {
                    var option = optionMap[value];
                    if (!option || !option.container) return;
                    option.container.classList.add('temf-storage-choice');
                    option.container.addEventListener('click', function (e) {
                        if (e.target && e.target.tagName && e.target.tagName.toLowerCase() === 'input') {
                            return;
                        }
                        var input = option.container.querySelector('input[type="radio"]');
                        if (input && !input.checked) {
                            input.checked = true;
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });
                    row.appendChild(option.container);
                });
                return row;
            }

            addSectionTitle('单一存储');
            layout.appendChild(buildRow(row1Values));
            addSectionTitle('更多存储');
            layout.appendChild(buildRow(row2Values));

            var multiWrap = document.createElement('div');
            multiWrap.className = 'temf-storage-multi';
            multiWrap.innerHTML = '' +
                '<label for="temf-storage-multi-toggle"><input type="checkbox" id="temf-storage-multi-toggle"> <span>我全都要</span></label>' +
                '<div class="temf-storage-multi-note">勾选后，将启用已配置的存储，并在素材库中切换。当前已生效 <strong id="temf-storage-active-count">0</strong> 项。</div>';
            multiWrap.addEventListener('click', function (e) {
                var labelEl = multiWrap.querySelector('label');
                if (labelEl && labelEl.contains(e.target)) {
                    return;
                }
                var input = document.getElementById('temf-storage-multi-toggle');
                if (input) {
                    input.checked = !input.checked;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            layout.appendChild(multiWrap);

            if (optionMap.multi && optionMap.multi.container) {
                optionMap.multi.container.classList.add('temf-storage-hidden');
            }
            var description = storageWrap.querySelector('.description');
            if (description) {
                storageWrap.insertBefore(layout, description);
            } else {
                storageWrap.appendChild(layout);
            }

            var multiToggle = document.getElementById('temf-storage-multi-toggle');
            var activeCount = document.getElementById('temf-storage-active-count');
            var visibleRadios = [];
            ['local', 'cos', 'oss', 'bitiful', 'upyun', 'lsky'].forEach(function (value) {
                var input = document.querySelector('input[name="storage"][value="' + value + '"]');
                if (input) visibleRadios.push(input);
            });
            var lastSingleValue = 'local';

            function syncStorageChoiceState() {
                Array.prototype.forEach.call(layout.querySelectorAll('.temf-storage-choice'), function (label) {
                    var input = label.querySelector('input[type="radio"]');
                    label.classList.toggle('temf-storage-choice-active', !!(input && input.checked && !multiToggle.checked));
                });
                if (activeCount) {
                    var count = 0;
                    ['cos', 'oss', 'bitiful', 'upyun', 'lsky'].forEach(function (type) {
                        if (checkConfigExists(type)) count++;
                    });
                    if (checkConfigExists('local')) {
                        count++;
                    }
                    activeCount.textContent = String(count);
                }
            }

            Array.prototype.forEach.call(visibleRadios, function (radio) {
                if (radio.checked) {
                    lastSingleValue = radio.value;
                }
                radio.addEventListener('change', function () {
                    lastSingleValue = radio.value;
                    if (multiToggle.checked) {
                        multiToggle.checked = false;
                        multiRadio.checked = false;
                    }
                    syncStorageChoiceState();
                    toggle();
                });
            });

            multiToggle.checked = multiRadio.checked;
            multiToggle.addEventListener('change', function () {
                if (multiToggle.checked) {
                    multiRadio.checked = true;
                } else {
                    multiRadio.checked = false;
                    var target = document.querySelector('input[name="storage"][value="' + lastSingleValue + '"]') || visibleRadios[0];
                    if (target) target.checked = true;
                }
                syncStorageChoiceState();
                toggle();
            });

            var form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function () {
                    if (multiToggle.checked) {
                        multiRadio.checked = true;
                    } else {
                        multiRadio.checked = false;
                        var selected = document.querySelector('input[name="storage"][value="' + lastSingleValue + '"]') || visibleRadios[0];
                        if (selected) selected.checked = true;
                    }
                });
            }

            storageWrap.setAttribute('data-temf-storage-ready', '1');
            syncStorageChoiceState();
        }

        function ensureTestResult(buttonEl) {
            if (!buttonEl || !buttonEl.parentNode) return null;
            var panel = buttonEl.parentNode.querySelector('.temf-test-result');
            if (!panel) {
                panel = document.createElement('div');
                panel.className = 'temf-test-result';
                buttonEl.parentNode.appendChild(panel);
            }
            return panel;
        }

        function showTestResult(buttonEl, message, type) {
            var panel = ensureTestResult(buttonEl);
            if (!panel) return;
            panel.className = 'temf-test-result' + (type ? (' ' + type) : '');
            panel.textContent = message;
        }

        function createConfigNav() {
            var storageInput = document.querySelector('input[name="storage"]');
            if (!storageInput) return;
            var storageWrap = storageInput.closest ? storageInput.closest('ul') : null;
            if (!storageWrap || document.getElementById('temf-config-nav')) return;

            var nav = document.createElement('div');
            nav.id = 'temf-config-nav';
            nav.className = 'temf-config-nav';

            [
                { key: 'cos', label: 'COS' },
                { key: 'oss', label: 'OSS' },
                { key: 'bitiful', label: '缤纷云' },
                { key: 'upyun', label: '又拍云' },
                { key: 'lsky', label: '兰空图床' }
            ].forEach(function(item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'temf-config-tab';
                btn.setAttribute('data-target', item.key);
                btn.textContent = item.label;
                btn.addEventListener('click', function() {
                    var multiToggle = document.getElementById('temf-storage-multi-toggle');
                    if (multiToggle && !multiToggle.checked) {
                        multiToggle.checked = true;
                        if (multiRadio) {
                            multiRadio.checked = true;
                        }
                    }
                    activeConfigTab = item.key;
                    toggle();
                });
                nav.appendChild(btn);
            });

            var hint = document.createElement('p');
            hint.id = 'temf-config-hint';
            hint.className = 'temf-config-hint';
            hint.innerHTML = '多存储模式下，请通过这里切换并完成各存储配置。<strong>标记“未配置”的存储尚未生效。</strong>';

            storageWrap.parentNode.insertBefore(nav, storageWrap.nextSibling);
            nav.parentNode.insertBefore(hint, nav.nextSibling);
        }

        function getFirstAvailableMultiTab() {
            var candidates = ['cos', 'oss', 'bitiful', 'upyun', 'lsky'];
            for (var i = 0; i < candidates.length; i++) {
                if (checkConfigExists(candidates[i])) {
                    return candidates[i];
                }
            }
            return 'cos';
        }
        
        function ensureGroup(selector, groupId, title, descSelector) {
            var items = Array.prototype.slice.call(document.querySelectorAll(selector));
            if (!items.length) return null;
            
            var uls = [];
            items.forEach(function(el) {
                var ul = el.closest ? el.closest('ul') : null;
                if (ul && uls.indexOf(ul) === -1) {
                    uls.push(ul);
                }
            });
            
            if (!uls.length) return null;
            
            var first = uls[0];
            var existed = document.getElementById(groupId);
            if (existed) return existed;
            
            var wrap = document.createElement('div');
            wrap.className = 'temf-card';
            wrap.id = groupId;
            
            var h = document.createElement('h4');
            h.textContent = title;
            wrap.appendChild(h);
            
            if (descSelector) {
                var desc = document.querySelector(descSelector);
                if (desc) {
                    wrap.appendChild(desc);
                }
            }
            
            first.parentNode.insertBefore(wrap, first);
            uls.forEach(function(ul) {
                wrap.appendChild(ul);
            });
            
            return wrap;
        }

        function makeGroupCollapsible(group, defaultOpen) {
            if (!group || group.getAttribute('data-collapse-ready') === '1') {
                return;
            }

            var titleNode = group.querySelector('h4');
            var title = titleNode ? titleNode.textContent : '高级设置';
            var details = document.createElement('details');
            details.className = 'temf-collapse';
            if (defaultOpen) {
                details.open = true;
            }

            var summary = document.createElement('summary');
            summary.textContent = title;
            details.appendChild(summary);

            var body = document.createElement('div');
            body.className = 'temf-collapse-body';

            var children = Array.prototype.slice.call(group.childNodes);
            children.forEach(function(child) {
                if (titleNode && child === titleNode) {
                    return;
                }
                body.appendChild(child);
            });

            details.appendChild(body);

            if (titleNode && titleNode.parentNode === group) {
                titleNode.parentNode.removeChild(titleNode);
            }

            group.appendChild(details);
            group.classList.add('temf-collapse-card');
            group.setAttribute('data-collapse-ready', '1');
        }
        
        var cosGroup = ensureGroup('.temf-cos-field', 'temf-cos-group', '腾讯 COS', '.temf-cos-desc');
        var ossGroup = ensureGroup('.temf-oss-field', 'temf-oss-group', '阿里云 OSS', '.temf-oss-desc');
        var bitifulGroup = ensureGroup('.temf-bitiful-field', 'temf-bitiful-group', '缤纷云存储', '.temf-bitiful-desc');
        var upyunGroup = ensureGroup('.temf-upyun-field', 'temf-upyun-group', '又拍云', '.temf-upyun-desc');
        var lskyGroup = ensureGroup('.temf-lsky-field', 'temf-lsky-group', '兰空图床', '.temf-lsky-desc');
        var advancedGroup = ensureGroup('.temf-advanced-field', 'temf-advanced-group', '高级设置', null);
        groupMap = { cos: cosGroup, oss: ossGroup, bitiful: bitifulGroup, upyun: upyunGroup, lsky: lskyGroup };

        enhanceStorageLayout();
        createConfigNav();

        if (advancedGroup) {
            makeGroupCollapsible(advancedGroup, false);
        }
        
        function toggle() {
            var val = 'local';
            for (var i = 0; i < radios.length; i++) {
                if (radios[i].checked) {
                    val = radios[i].value;
                    break;
                }
            }
            
            var cosInputs = document.querySelectorAll('.temf-cos-field');
            var ossInputs = document.querySelectorAll('.temf-oss-field');
            var bitifulInputs = document.querySelectorAll('.temf-bitiful-field');
            var upyunInputs = document.querySelectorAll('.temf-upyun-field');
            var lskyInputs = document.querySelectorAll('.temf-lsky-field');
            var cosShow = (val === 'cos') || (val === 'multi');
            var ossShow = (val === 'oss') || (val === 'multi');
            var bitifulShow = (val === 'bitiful') || (val === 'multi');
            var upyunShow = (val === 'upyun') || (val === 'multi');
            var lskyShow = (val === 'lsky') || (val === 'multi');
            var nav = document.getElementById('temf-config-nav');

            if (val === 'multi') {
                if (['cos', 'oss', 'bitiful', 'upyun', 'lsky'].indexOf(activeConfigTab) === -1) {
                    activeConfigTab = 'cos';
                }
            } else {
                activeConfigTab = val;
            }

            if (nav) {
                nav.style.display = val === 'multi' ? '' : 'none';
            }
            var hint = document.getElementById('temf-config-hint');
            if (hint) {
                hint.style.display = val === 'multi' ? '' : 'none';
            }

            if (val === 'multi') {
                cosShow = activeConfigTab === 'cos';
                ossShow = activeConfigTab === 'oss';
                bitifulShow = activeConfigTab === 'bitiful';
                upyunShow = activeConfigTab === 'upyun';
                lskyShow = activeConfigTab === 'lsky';
            }
            
            // 显示/隐藏配置组，但保持所有输入字段启用状态以确保数据提交
            for (var j = 0; j < cosInputs.length; j++) {
                var el = cosInputs[j];
                var ul = el.closest ? el.closest('ul') : null;
                if (ul) {
                    ul.style.display = cosShow ? '' : 'none';
                    // 确保隐藏的输入字段仍然可以提交
                    el.disabled = false;
                }
            }
            
            for (var k = 0; k < ossInputs.length; k++) {
                var el2 = ossInputs[k];
                var ul2 = el2.closest ? el2.closest('ul') : null;
                if (ul2) {
                    ul2.style.display = ossShow ? '' : 'none';
                    // 确保隐藏的输入字段仍然可以提交
                    el2.disabled = false;
                }
            }

            for (var kb = 0; kb < bitifulInputs.length; kb++) {
                var elBitiful = bitifulInputs[kb];
                var ulBitiful = elBitiful.closest ? elBitiful.closest('ul') : null;
                if (ulBitiful) {
                    ulBitiful.style.display = bitifulShow ? '' : 'none';
                    elBitiful.disabled = false;
                }
            }
            
            for (var l = 0; l < upyunInputs.length; l++) {
                var el3 = upyunInputs[l];
                var ul3 = el3.closest ? el3.closest('ul') : null;
                if (ul3) {
                    ul3.style.display = upyunShow ? '' : 'none';
                    // 确保隐藏的输入字段仍然可以提交
                    el3.disabled = false;
                }
            }
            
            for (var m = 0; m < lskyInputs.length; m++) {
                var el4 = lskyInputs[m];
                var ul4 = el4.closest ? el4.closest('ul') : null;
                if (ul4) {
                    ul4.style.display = lskyShow ? '' : 'none';
                    // 确保隐藏的输入字段仍然可以提交
                    el4.disabled = false;
                }
            }
            
            var cg = document.getElementById('temf-cos-group');
            if (cg) {
                cg.style.display = cosShow ? '' : 'none';
                // 添加配置状态指示
                updateConfigStatus(cg, 'cos', cosShow);
            }
            
            var og = document.getElementById('temf-oss-group');
            if (og) {
                og.style.display = ossShow ? '' : 'none';
                // 添加配置状态指示
                updateConfigStatus(og, 'oss', ossShow);
            }

            var bg = document.getElementById('temf-bitiful-group');
            if (bg) {
                bg.style.display = bitifulShow ? '' : 'none';
                updateConfigStatus(bg, 'bitiful', bitifulShow);
            }
            
            var ug = document.getElementById('temf-upyun-group');
            if (ug) {
                ug.style.display = upyunShow ? '' : 'none';
                // 添加配置状态指示
                updateConfigStatus(ug, 'upyun', upyunShow);
            }
            
            var lg = document.getElementById('temf-lsky-group');
            if (lg) {
                lg.style.display = lskyShow ? '' : 'none';
                // 添加配置状态指示
                updateConfigStatus(lg, 'lsky', lskyShow);
            }

            var tabs = document.querySelectorAll('.temf-config-tab');
            for (var t = 0; t < tabs.length; t++) {
                var key = tabs[t].getAttribute('data-target');
                var isConfigured = checkConfigExists(key);
                tabs[t].style.display = val === 'multi' ? '' : 'none';
                tabs[t].classList.toggle('temf-config-tab-ready', isConfigured);
                tabs[t].classList.toggle('temf-config-tab-empty', !isConfigured);
                tabs[t].classList.toggle('active', val === 'multi' ? key === activeConfigTab : ((key === 'cos' && cosShow) || (key === 'oss' && ossShow) || (key === 'bitiful' && bitifulShow) || (key === 'upyun' && upyunShow) || (key === 'lsky' && lskyShow)));
            }
        }
        
        // 更新配置状态指示器
        function updateConfigStatus(group, type, isActive) {
            var statusEl = group.querySelector('.temf-config-status');
            if (!statusEl) {
                statusEl = document.createElement('div');
                statusEl.className = 'temf-config-status';
                statusEl.style.cssText = 'margin: 5px 0; padding: 5px 8px; border-radius: 3px; font-size: 12px;';
                var title = group.querySelector('h4');
                if (title && title.nextSibling) {
                    group.insertBefore(statusEl, title.nextSibling);
                }
            }
            
            // 检查该类型的配置是否已填写
            var hasConfig = checkConfigExists(type);
            
            var currentMode = 'local';
            for (var i = 0; i < radios.length; i++) {
                if (radios[i].checked) {
                    currentMode = radios[i].value;
                    break;
                }
            }
            
            if (currentMode === 'multi') {
                // 多模式下的状态显示
                if (hasConfig) {
                    statusEl.style.backgroundColor = '#d4edda';
                    statusEl.style.color = '#155724';
                    statusEl.style.border = '1px solid #c3e6cb';
                    statusEl.innerHTML = '多模式已启用，此配置生效中';
                    statusEl.style.display = 'block';
                } else {
                    statusEl.style.backgroundColor = '#f8d7da';
                    statusEl.style.color = '#721c24';
                    statusEl.style.border = '1px solid #f5c6cb';
                    var missing = getMissingFields(type);
                    statusEl.innerHTML = '配置未完成：' + (missing.length ? ('缺少 ' + missing.join('、')) : '请填写必要参数');
                    statusEl.style.display = 'block';
                }
            } else if (isActive) {
                statusEl.style.display = 'none';
            } else if (hasConfig) {
                statusEl.style.backgroundColor = '#e8f4fd';
                statusEl.style.color = '#0073aa';
                statusEl.style.border = '1px solid #c5d9ed';
                statusEl.innerHTML = '已保存配置，随时可切换使用';
                statusEl.style.display = 'block';
            } else {
                statusEl.style.display = 'none';
            }
        }

        function getMissingFields(type) {
            var requiredMap = {
                cos: [['cosBucket', '存储桶名称'], ['cosRegion', '所属地域'], ['cosSecretId', '访问密钥 ID'], ['cosSecretKey', '访问密钥']],
                oss: [['ossBucket', '存储桶名称'], ['ossEndpoint', '服务地址'], ['ossAccessKeyId', '访问密钥 ID'], ['ossAccessKeySecret', '访问密钥']],
                bitiful: [['bitifulBucket', '存储桶名称'], ['bitifulRegion', '所属地域'], ['bitifulEndpoint', '服务地址'], ['bitifulAccessKey', '访问密钥 ID'], ['bitifulSecretKey', '访问密钥']],
                upyun: [['upyunBucket', '服务名称'], ['upyunOperator', '操作员账号'], ['upyunPassword', '操作员密码'], ['upyunDomain', '加速域名']],
                lsky: [['lskyUrl', '图床地址'], ['lskyToken', '接口令牌']]
            };

            var required = requiredMap[type] || [];
            var missing = [];
            required.forEach(function(item) {
                var input = document.querySelector('input[name="' + item[0] + '"]');
                if (!input || !input.value.trim()) {
                    missing.push(item[1]);
                }
            });
            return missing;
        }
        
        // 检查指定类型的配置是否存在
        function checkConfigExists(type) {
            var fields = [];
            if (type === 'local') {
                return true;
            } else if (type === 'cos') {
                fields = ['cosBucket', 'cosRegion', 'cosSecretId', 'cosSecretKey'];
            } else if (type === 'oss') {
                fields = ['ossBucket', 'ossEndpoint', 'ossAccessKeyId', 'ossAccessKeySecret'];
            } else if (type === 'bitiful') {
                fields = ['bitifulBucket', 'bitifulRegion', 'bitifulEndpoint', 'bitifulAccessKey', 'bitifulSecretKey'];
            } else if (type === 'upyun') {
                fields = ['upyunBucket', 'upyunOperator', 'upyunPassword', 'upyunDomain'];
            } else if (type === 'lsky') {
                fields = ['lskyUrl', 'lskyToken'];
            }
            
            return fields.length > 0 && fields.every(function(fieldName) {
                var input = document.querySelector('input[name="' + fieldName + '"]');
                return input && input.value.trim() !== '';
            });
        }
        
        for (var i = 0; i < radios.length; i++) {
            radios[i].addEventListener('change', toggle);
        }
        
        // 表单提交前确保所有配置字段都启用
        var form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function() {
                // 确保所有隐藏的配置字段都被启用，以便提交
                var allConfigInputs = document.querySelectorAll('.temf-cos-field, .temf-oss-field, .temf-bitiful-field, .temf-upyun-field, .temf-lsky-field');
                for (var i = 0; i < allConfigInputs.length; i++) {
                    allConfigInputs[i].disabled = false;
                }
            });
        }
        
        // 添加兰空Token测试功能
        var testBtn = document.getElementById('test-lsky-token');
        if (testBtn) {
            testBtn.addEventListener('click', function() {
                var urlField = document.querySelector('input[name="lskyUrl"]');
                var tokenField = document.querySelector('input[name="lskyToken"]');
                
                if (!urlField || !tokenField) return;
                
                var url = urlField.value.trim();
                var token = tokenField.value.trim();
                
                if (!url || !token) {
                    showTestResult(testBtn, '请先填写兰空图床地址和 API Token', 'error');
                    return;
                }
                
                testBtn.disabled = true;
                testBtn.textContent = '测试中...';
                
                // 构造测试请求（自动补全协议，避免被当作 /admin 相对路径）
                if (!/^https?:\/\//i.test(url)) {
                    url = 'https://' + url.replace(/^\/+/, '');
                }
                var testUrl = url.replace(/\/$/, '') + '/api/v1/images?page=1&per_page=1';
                
                fetch(testUrl, {
                    method: 'GET',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Accept': 'application/json'
                    }
                })
                .then(function(response) {
                    return response.json().then(function(data) {
                        return { status: response.status, data: data };
                    });
                })
                .then(function(result) {
                    testBtn.disabled = false;
                    testBtn.textContent = '测试连接';
                    
                    if (result.status === 200 && result.data.status) {
                        showTestResult(testBtn, 'API 连接成功，图床配置正确。', 'ok');
                    } else if (result.status === 401 || result.status === 403) {
                        showTestResult(testBtn, '认证失败，请检查 API Token 是否正确。\\n错误：' + (result.data.message || '授权错误'), 'error');
                    } else {
                        showTestResult(testBtn, '连接失败\\n状态码：' + result.status + '\\n错误：' + (result.data.message || '未知错误'), 'error');
                    }
                })
                .catch(function(error) {
                    testBtn.disabled = false;
                    testBtn.textContent = '测试连接';
                    showTestResult(testBtn, '网络错误，请检查兰空图床地址是否正确。\\n错误：' + error.message, 'error');
                });
            });
        }
        
        // 添加又拍云测试连接功能
        var testUpyunBtn = document.getElementById('test-upyun-connection');
        if (testUpyunBtn) {
            testUpyunBtn.addEventListener('click', function() {
                var bucketField = document.querySelector('input[name="upyunBucket"]');
                var operatorField = document.querySelector('input[name="upyunOperator"]');
                var passwordField = document.querySelector('input[name="upyunPassword"]');
                
                if (!bucketField || !operatorField || !passwordField) return;
                
                var bucket = bucketField.value.trim();
                var operator = operatorField.value.trim();
                var password = passwordField.value.trim();
                
                if (!bucket || !operator || !password) {
                    showTestResult(testUpyunBtn, '请先填写完整的又拍云配置信息（服务名称、操作员账号、操作员密码）', 'error');
                    return;
                }
                
                testUpyunBtn.disabled = true;
                testUpyunBtn.textContent = '测试中...';
                
                // 获取测试 URL
                var upyunDesc = document.querySelector('.temf-upyun-desc');
                var testUrl = upyunDesc ? upyunDesc.getAttribute('data-test-upyun-url') : '';
                
                if (!testUrl) {
                    testUpyunBtn.disabled = false;
                    testUpyunBtn.textContent = '测试连接';
                    showTestResult(testUpyunBtn, '错误：无法获取测试接口地址', 'error');
                    return;
                }
                
                // 使用 AJAX 调用后端测试接口
                var testData = new FormData();
                testData.append('bucket', bucket);
                testData.append('operator', operator);
                testData.append('password', password);
                
                fetch(testUrl, {
                    method: 'POST',
                    body: testData,
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    // 检查响应的 Content-Type
                    var contentType = response.headers.get('content-type');
                    if (!contentType || contentType.indexOf('application/json') === -1) {
                        throw new Error('服务器返回了非 JSON 响应，可能是权限错误或路由配置问题');
                    }
                    return response.json();
                })
                .then(function(result) {
                    testUpyunBtn.disabled = false;
                    testUpyunBtn.textContent = '测试连接';
                    
                    if (result.success) {
                        showTestResult(testUpyunBtn, '又拍云连接成功\\n\\n' + result.message + '\\n\\n配置信息：\\n' +
                              '• 服务名称：' + bucket + '\\n' +
                              '• 操作员：' + operator + '\\n' +
                              '• 权限：' + (result.permissions || '读取、写入'), 'ok');
                    } else {
                        showTestResult(testUpyunBtn, '又拍云连接失败\\n\\n' + result.message + '\\n\\n' + 
                              '请检查：\\n' +
                              '1. 服务名称是否正确\\n' +
                              '2. 操作员账号和密码是否匹配\\n' +
                              '3. 操作员是否已授权到该服务\\n' +
                              '4. 操作员是否有读取和写入权限', 'error');
                    }
                })
                .catch(function(error) {
                    testUpyunBtn.disabled = false;
                    testUpyunBtn.textContent = '测试连接';
                    showTestResult(testUpyunBtn, '测试失败\\n\\n' + error.message + '\\n\\n' +
                          '可能的原因：\\n' +
                          '1. 插件需要重新激活（禁用后重新启用）\\n' +
                          '2. 路由配置有问题\\n' +
                          '3. 权限不足\\n\\n' +
                          '请尝试：\\n' +
                          '• 禁用插件后重新启用\\n' +
                          '• 检查 Typecho 是否开启了伪静态', 'error');
                });
            });
        }

        var testBitifulBtn = document.getElementById('test-bitiful-connection');
        if (testBitifulBtn) {
            testBitifulBtn.addEventListener('click', function() {
                var bucketField = document.querySelector('input[name="bitifulBucket"]');
                var regionField = document.querySelector('input[name="bitifulRegion"]');
                var endpointField = document.querySelector('input[name="bitifulEndpoint"]');
                var accessKeyField = document.querySelector('input[name="bitifulAccessKey"]');
                var secretKeyField = document.querySelector('input[name="bitifulSecretKey"]');

                if (!bucketField || !regionField || !endpointField || !accessKeyField || !secretKeyField) return;

                var bucket = bucketField.value.trim();
                var region = regionField.value.trim();
                var endpoint = endpointField.value.trim();
                var accessKey = accessKeyField.value.trim();
                var secretKey = secretKeyField.value.trim();

                if (!bucket || !region || !endpoint || !accessKey || !secretKey) {
                    showTestResult(testBitifulBtn, '请先填写完整的缤纷云配置信息', 'error');
                    return;
                }

                var desc = document.querySelector('.temf-bitiful-desc');
                var testUrl = desc ? desc.getAttribute('data-test-bitiful-url') : '';
                if (!testUrl) {
                    showTestResult(testBitifulBtn, '无法获取测试接口地址', 'error');
                    return;
                }

                testBitifulBtn.disabled = true;
                testBitifulBtn.textContent = '测试中...';

                var formData = new FormData();
                formData.append('bucket', bucket);
                formData.append('region', region);
                formData.append('endpoint', endpoint);
                formData.append('accessKey', accessKey);
                formData.append('secretKey', secretKey);
                formData.append('provider', 'bitiful');

                fetch(testUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    var contentType = response.headers.get('content-type') || '';
                    if (contentType.indexOf('application/json') === -1) {
                        return response.text().then(function(text) {
                            throw new Error('服务器返回了非 JSON 响应，可能是路由未生效、权限不足或 PHP 报错。' + (text ? '\\n\\n' + text.substring(0, 200) : ''));
                        });
                    }
                    return response.json();
                })
                .then(function(result) {
                    testBitifulBtn.disabled = false;
                    testBitifulBtn.textContent = '测试连接';

                    if (result.success) {
                        showTestResult(testBitifulBtn, '缤纷云连接成功\\n\\n' + result.message, 'ok');
                    } else {
                        showTestResult(testBitifulBtn, '缤纷云连接失败\\n\\n' + (result.message || '未知错误'), 'error');
                    }
                })
                .catch(function(error) {
                    testBitifulBtn.disabled = false;
                    testBitifulBtn.textContent = '测试连接';
                    showTestResult(testBitifulBtn, '测试失败\\n\\n' + error.message, 'error');
                });
            });
        }
        
        toggle();
    }
    
    if (document.readyState !== 'loading') {
        ready();
    } else {
        document.addEventListener('DOMContentLoaded', ready);
    }
})();
</script>
HTML;
    }
}
