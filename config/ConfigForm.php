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

            $ok = function($b){ return $b ? '<span style="color:#1a7f37">✓</span>' : '<span style="color:#d63638">✗</span>'; };

            // 折叠容器（与高级设置同风格）
            echo '<div class="temf-card temf-collapse-card temf-env-wrap">';
            echo '<details class="temf-collapse">';
            echo '<summary>环境自检</summary>';
            echo '<div class="temf-collapse-body temf-env">';
            echo '<style>
                .temf-env .temf-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px;margin:6px 0}
                .temf-env .temf-kv{display:flex;justify-content:space-between;gap:8px;padding:6px 8px;border:1px solid #eee;border-radius:4px;background:#fafafa}
                .temf-env .temf-badge{display:inline-block;min-width:16px;text-align:center;padding:0 6px;border-radius:10px;font-size:12px}
                .temf-env .ok{background:#e7f6ed;color:#1a7f37;border:1px solid #c9e7d4}
                .temf-env .no{background:#fdecea;color:#d63638;border:1px solid #f5c6cb}
                .temf-env .muted{color:#666;font-size:12px}
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

            // 关键函数自检
            $checks = [
                'JSON 函数' => [
                    'json_encode' => function_exists('json_encode'),
                    'json_decode' => function_exists('json_decode'),
                ],
                '哈希/编码' => [
                    'hash_hmac' => function_exists('hash_hmac'),
                    'base64_encode' => function_exists('base64_encode'),
                ],
                'XML 解析' => [
                    'simplexml_load_string' => function_exists('simplexml_load_string'),
                ],
                '文件/流' => [
                    'file_get_contents' => function_exists('file_get_contents'),
                    'fopen' => function_exists('fopen'),
                ],
                'GD 图片' => [
                    'imagecreatefromjpeg' => function_exists('imagecreatefromjpeg'),
                    'imagecreatefrompng' => function_exists('imagecreatefrompng'),
                    'imagecreatefromgif' => function_exists('imagecreatefromgif'),
                    'imagecreatefromwebp' => function_exists('imagecreatefromwebp'),
                    'imagejpeg' => function_exists('imagejpeg'),
                    'imagepng' => function_exists('imagepng'),
                    'imagegif' => function_exists('imagegif'),
                    'imagewebp' => function_exists('imagewebp'),
                ],
                'cURL（兰空图床）' => [
                    'curl_init' => function_exists('curl_init'),
                    'curl_setopt' => function_exists('curl_setopt'),
                    'CURLFile 类' => class_exists('CURLFile'),
                ],
            ];

            echo '<div style="margin-top:6px" class="temf-row">';
            foreach ($checks as $group => $items) {
                echo '<div class="temf-kv" style="flex-direction:column;align-items:flex-start">';
                echo '<strong style="font-size:12px;color:#333;margin-bottom:4px">' . htmlspecialchars($group) . '</strong>';
                echo '<div class="temf-funcs" style="display:flex;flex-wrap:wrap;gap:6px">';
                foreach ($items as $name => $pass) {
                    echo '<span class="temf-badge ' . ($pass ? 'ok' : 'no') . '" title="' . htmlspecialchars($name) . '">' . htmlspecialchars($name) . '</span>';
                }
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
            echo '<p class="description" style="margin:6px 0 0;">建议：upload_max_filesize/post_max_size ≥ 20M，memory_limit ≥ 256M；弱机可依赖1080px上限与内存防护稳定运行。</p>';
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
                'upyun' => _t('又拍云'),
                'lsky' => _t('兰空图床'),
                'multi' => _t('我全都要')
            ],
            'local',
            _t('存储方式'),
            _t('选择图片来源：<br>• <strong>单一模式</strong>：本地上传目录、腾讯COS、阿里云OSS、又拍云 或兰空图床<br>• <strong>我全都要</strong>：同时启用所有已配置的存储方式，可在素材库中动态切换<br><span style="color:#0073aa;font-size:12px;">提示：切换存储方式时，其他模式的配置会自动保留，不会丢失嘟</span>')
        );
        $form->addInput($storage);
    }
    
    private static function addCosConfig(\Typecho\Widget\Helper\Form $form)
    {
        echo '<p class="description temf-cos-desc" style="margin:8px 0;">' . _t('选择 "存储方式：腾讯COS" 后，填写以下 COS 配置') . '</p>';
        
        $cosBucket = new \Typecho\Widget\Helper\Form\Element\Text(
            'cosBucket', 
            null, 
            '', 
            _t('Bucket（存储桶名称）'), 
            _t('格式：senmu-bucket-1250000000')
        );
        $cosBucket->setAttribute('class', 'temf-cos-field');
        $form->addInput($cosBucket);
        
        $cosRegion = new \Typecho\Widget\Helper\Form\Element\Text(
            'cosRegion', 
            null, 
            'ap-beijing', 
            _t('Region（地域）'), 
            _t('如 ap-beijing, ap-shanghai')
        );
        $cosRegion->setAttribute('class', 'temf-cos-field');
        $form->addInput($cosRegion);
        
        $cosSecretId = new \Typecho\Widget\Helper\Form\Element\Text(
            'cosSecretId', 
            null, 
            '', 
            _t('SecretId（访问密钥Id）'), 
            _t('腾讯云访问密钥 SecretId')
        );
        $cosSecretId->setAttribute('class', 'temf-cos-field');
        $form->addInput($cosSecretId);
        
        $cosSecretKey = new \Typecho\Widget\Helper\Form\Element\Text(
            'cosSecretKey', 
            null, 
            '', 
            _t('SecretKey（访问密钥）'), 
            _t('腾讯云访问密钥 SecretKey')
        );
        $cosSecretKey->setAttribute('class', 'temf-cos-field');
        $form->addInput($cosSecretKey);
        
        $cosPrefix = new \Typecho\Widget\Helper\Form\Element\Text(
            'cosPrefix', 
            null, 
            '', 
            _t('Prefix（路径前缀）'), 
            _t('拼接到 COS 域名后，如留空则为根目录')
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
        echo '<p class="description temf-oss-desc" style="margin:8px 0;">' . _t('选择 "存储方式：阿里云OSS" 后，填写以下 OSS 配置') . '</p>';
        
        $ossBucket = new \Typecho\Widget\Helper\Form\Element\Text(
            'ossBucket', 
            null, 
            '', 
            _t('Bucket（存储桶名称）'), 
            _t('OSS 存储桶名称')
        );
        $ossBucket->setAttribute('class', 'temf-oss-field');
        $form->addInput($ossBucket);
        
        $ossEndpoint = new \Typecho\Widget\Helper\Form\Element\Text(
            'ossEndpoint', 
            null, 
            '', 
            _t('Endpoint（服务地址）'), 
            _t('例如: https://oss-cn-beijing.aliyuncs.com')
        );
        $ossEndpoint->setAttribute('class', 'temf-oss-field');
        $form->addInput($ossEndpoint);
        
        $ossAccessKeyId = new \Typecho\Widget\Helper\Form\Element\Text(
            'ossAccessKeyId', 
            null, 
            '', 
            _t('AccessKeyId（访问密钥Id）'), 
            _t('阿里云访问密钥 AccessKeyId')
        );
        $ossAccessKeyId->setAttribute('class', 'temf-oss-field');
        $form->addInput($ossAccessKeyId);
        
        $ossAccessKeySecret = new \Typecho\Widget\Helper\Form\Element\Text(
            'ossAccessKeySecret', 
            null, 
            '', 
            _t('AccessKeySecret（访问密钥）'), 
            _t('阿里云访问密钥 AccessKeySecret')
        );
        $ossAccessKeySecret->setAttribute('class', 'temf-oss-field');
        $form->addInput($ossAccessKeySecret);
        
        $ossPrefix = new \Typecho\Widget\Helper\Form\Element\Text(
            'ossPrefix', 
            null, 
            '', 
            _t('Prefix（路径前缀）'), 
            _t('拼接到自定义域名或 Endpoint/Bucket 后')
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
    
    private static function addUpyunConfig(\Typecho\Widget\Helper\Form $form)
    {
        // 生成测试 URL
        $testUpyunUrl = \Widget\Security::alloc()->getIndex('/action/temf-test-upyun');
        
        echo '<div class="temf-upyun-desc" style="margin:8px 0; padding:10px; background:#f8f8f8; " data-test-upyun-url="' . htmlspecialchars($testUpyunUrl) . '">';
        echo '<p style="margin:0 0 5px;">1. 登录 <a href="https://console.upyun.com" target="_blank">又拍云控制台</a></p>';
        echo '<p style="margin:0 0 5px;">2. 创建云存储服务（选择"网页图片"）</p>';
        echo '<p style="margin:0 0 5px;">3. 在"操作员授权"中创建操作员并授权</p>';
        echo '<p style="margin:0;"><strong>注意：</strong>域名必须配置并已绑定加速域名</p>';
        echo '</div>';
        
        $upyunBucket = new \Typecho\Widget\Helper\Form\Element\Text(
            'upyunBucket',
            null,
            '',
            _t('服务名称'),
            _t('又拍云存储服务名称（即Bucket名称）')
        );
        $upyunBucket->setAttribute('class', 'temf-upyun-field');
        $form->addInput($upyunBucket);
        
        $upyunOperator = new \Typecho\Widget\Helper\Form\Element\Text(
            'upyunOperator',
            null,
            '',
            _t('操作员账号'),
            _t('授权的操作员账号名')
        );
        $upyunOperator->setAttribute('class', 'temf-upyun-field');
        $form->addInput($upyunOperator);
        
        $upyunPassword = new \Typecho\Widget\Helper\Form\Element\Text(
            'upyunPassword',
            null,
            '',
            _t('操作员密码'),
            _t('操作员的密码（明文保存，请注意安全），<button type="button" id="test-upyun-connection" class="btn btn-xs">测试连接</button>')
        );
        $upyunPassword->setAttribute('class', 'temf-upyun-field');
        $upyunPassword->input->setAttribute('type', 'password');
        $form->addInput($upyunPassword);
        
        $upyunDomain = new \Typecho\Widget\Helper\Form\Element\Text(
            'upyunDomain',
            null,
            '',
            _t('加速域名'),
            _t('又拍云绑定的加速域名，例如: https://cdn.example.com （不带最后的斜杠）')
        );
        $upyunDomain->setAttribute('class', 'temf-upyun-field');
        $form->addInput($upyunDomain);
    }
    
    private static function addLskyConfig(\Typecho\Widget\Helper\Form $form)
    {
        echo '<div class="temf-lsky-desc" style="margin:8px 0; padding:10px; background:#f8f8f8; ">';
        echo '<p style="margin:0 0 5px;">1. 登录您的兰空图床后台</p>';
        echo '<p style="margin:0 0 5px;">2. 在"用户中心" → "令牌管理"中创建新的API Token</p>';
        echo '<p style="margin:0 0 5px;">3. 复制生成的Token并填入下方配置</p>';
        echo '<p style="margin:0;"><strong>注意：</strong>Token格式通常为长字符串，如：1|aaa111aaa111...</p>';
        echo '<p style="margin:5px 0 0; color:#d63638;"><strong>重要：</strong>相册ID如果不存在会导致获取图片失败，建议留空</p>';
        echo '</div>';
        
        $lskyUrl = new \Typecho\Widget\Helper\Form\Element\Text(
            'lskyUrl', 
            null, 
            '', 
            _t('兰空图床地址'), 
            _t('兰空图床的完整地址，例如: https://oxxx.cn,不带"/"')
        );
        $lskyUrl->setAttribute('class', 'temf-lsky-field');
        $form->addInput($lskyUrl);
        
        $lskyToken = new \Typecho\Widget\Helper\Form\Element\Text(
            'lskyToken', 
            null, 
            '', 
            _t('API Token'), 
            _t('在兰空图床后台生成的API Token，<button type="button" id="test-lsky-token" class="btn btn-xs">测试连接</button>')
        );
        $lskyToken->setAttribute('class', 'temf-lsky-field');
        $form->addInput($lskyToken);
        
        $lskyAlbumId = new \Typecho\Widget\Helper\Form\Element\Text(
            'lskyAlbumId', 
            null, 
            '', 
            _t('相册ID（可选）'), 
            _t('指定上传到特定相册，留空则显示所有图片。<strong>注意：</strong>如果相册不存在会导致获取图片失败')
        );
        $lskyAlbumId->setAttribute('class', 'temf-lsky-field');
        $form->addInput($lskyAlbumId);
        
        $lskyStrategyId = new \Typecho\Widget\Helper\Form\Element\Text(
            'lskyStrategyId', 
            null, 
            '', 
            _t('存储策略ID（可选）'), 
            _t('指定使用的存储策略，留空则使用默认策略')
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
            _t('设置每页显示的图片行数（默认4行）。实际显示数量 = 行数 × 每行图片数（自适应屏幕宽度）。<br><span style="color:#0073aa;">提示：行数越多，初始加载时间越长，建议3-6行</span>')
        );
        $paginationRows->setAttribute('class', 'temf-advanced-field');
        $form->addInput($paginationRows);
        
        $thumb = new \Typecho\Widget\Helper\Form\Element\Text(
            'thumb',
            null,
            '120',
            _t('缩略图边长(px)'),
            _t('默认 120')
        );
        $thumb->setAttribute('class', 'temf-advanced-field');
        $form->addInput($thumb);
        
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
            _t('上传图片时自动转换为WebP格式以减小文件大小<br><span style="color:#0073aa;">兰空图床可能不支持WEBP会自动转换为JPG格式</span><br><span style="color:#666;">需要服务器支持GD扩展的WebP功能</span>')
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

        echo '<div class="temf-card" id="temf-telemetry-group">';
        echo '<h4>' . _t('匿名使用数据（说明）') . '</h4>';
        echo '<p class="description" style="margin:6px 0">' .
             _t('本插件在“激活”时会向开发者服务器一次性上报匿名环境信息，用于改进产品体验：') . '</p>';
        echo '</ul>';
        echo '</div>';
    }
    
    private static function addAssets()
    {
        echo <<<HTML
<style>
.temf-config-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 10px 0 14px;
}
.temf-config-tab {
    border: 1px solid #d0d7de;
    background: #fff;
    color: #24292f;
    border-radius: 999px;
    padding: 4px 12px;
    font-size: 12px;
    cursor: pointer;
}
.temf-config-tab.active {
    background: #24292f;
    color: #fff;
    border-color: #24292f;
}
.temf-card {
    border: 1px solid #d8dee4;
    border-radius: 6px;
    padding: 10px 14px;
    margin: 12px 0;
    background: #fff;
}
.temf-card h4 {
    margin: 0 0 8px;
    font-size: 14px;
    color: #1f2328;
}
.temf-oss-field, 
.temf-cos-field,
.temf-upyun-field,
.temf-lsky-field {
    list-style-type: none;
    padding: 4px 0;
}
.temf-card .description,
.temf-env-wrap .description {
    padding: 0;
    margin: .1em;
    font-size: 12px;
    color: #929292;
}
.temf-config-status {
    margin: 6px 0;
    padding: 6px 8px;
    border-radius: 4px;
    font-size: 12px;
}
.temf-test-result {
    margin-top: 8px;
    padding: 8px 10px;
    border-radius: 4px;
    font-size: 12px;
    line-height: 1.5;
    border: 1px solid #d0d7de;
    background: #f6f8fa;
    color: #24292f;
    white-space: pre-line;
}
.temf-test-result.ok {
    border-color: #b7dfc8;
    background: #edf9f1;
    color: #1f6f43;
}
.temf-test-result.error {
    border-color: #f3c2c2;
    background: #fff1f1;
    color: #b42318;
}
.temf-collapse-card {
    padding: 0;
}
.temf-collapse {
    border: none;
}
.temf-collapse > summary {
    list-style: none;
    cursor: pointer;
    padding: 10px 14px;
    font-size: 14px;
    font-weight: 600;
    color: #1f2328;
    user-select: none;
    border-bottom: 1px solid #e5e9ef;
}
.temf-collapse > summary::-webkit-details-marker {
    display: none;
}
.temf-collapse > summary::after {
    content: '展开';
    float: right;
    font-size: 12px;
    font-weight: 400;
    color: #667085;
}
.temf-collapse[open] > summary::after {
    content: '收起';
}
.temf-collapse-body {
    padding: 10px 14px;
}
</style>
<script>
(function() {
    var radios;
    
    function ready() {
        radios = document.querySelectorAll('input[name="storage"]');
        var groupMap = {};

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
                { key: 'upyun', label: '又拍云' },
                { key: 'lsky', label: '兰空图床' }
            ].forEach(function(item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'temf-config-tab';
                btn.setAttribute('data-target', item.key);
                btn.textContent = item.label;
                btn.addEventListener('click', function() {
                    var target = groupMap[item.key];
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
                nav.appendChild(btn);
            });

            storageWrap.parentNode.insertBefore(nav, storageWrap.nextSibling);
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
        
        var cosGroup = ensureGroup('.temf-cos-field', 'temf-cos-group', 'COS 配置', '.temf-cos-desc');
        var ossGroup = ensureGroup('.temf-oss-field', 'temf-oss-group', 'OSS 配置', '.temf-oss-desc');
        var upyunGroup = ensureGroup('.temf-upyun-field', 'temf-upyun-group', '又拍云配置', '.temf-upyun-desc');
        var lskyGroup = ensureGroup('.temf-lsky-field', 'temf-lsky-group', '兰空图床配置', '.temf-lsky-desc');
        var advancedGroup = ensureGroup('.temf-advanced-field', 'temf-advanced-group', '高级设置', null);
        groupMap = { cos: cosGroup, oss: ossGroup, upyun: upyunGroup, lsky: lskyGroup };
        createConfigNav();

        if (advancedGroup) {
            var telemetryCard = document.getElementById('temf-telemetry-group');
            if (telemetryCard && telemetryCard.parentNode && telemetryCard.parentNode !== advancedGroup) {
                advancedGroup.appendChild(telemetryCard);
            }
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
            var upyunInputs = document.querySelectorAll('.temf-upyun-field');
            var lskyInputs = document.querySelectorAll('.temf-lsky-field');
            var cosShow = (val === 'cos') || (val === 'multi');
            var ossShow = (val === 'oss') || (val === 'multi');
            var upyunShow = (val === 'upyun') || (val === 'multi');
            var lskyShow = (val === 'lsky') || (val === 'multi');
            
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
                var active = (key === 'cos' && cosShow) || (key === 'oss' && ossShow) || (key === 'upyun' && upyunShow) || (key === 'lsky' && lskyShow);
                tabs[t].classList.toggle('active', active);
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
                cos: [['cosBucket', 'Bucket'], ['cosSecretId', 'SecretId'], ['cosSecretKey', 'SecretKey']],
                oss: [['ossBucket', 'Bucket'], ['ossAccessKeyId', 'AccessKeyId'], ['ossAccessKeySecret', 'AccessKeySecret']],
                upyun: [['upyunBucket', '服务名称'], ['upyunOperator', '操作员账号'], ['upyunPassword', '操作员密码'], ['upyunDomain', '加速域名']],
                lsky: [['lskyUrl', '图床地址'], ['lskyToken', 'API Token']]
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
            if (type === 'cos') {
                fields = ['cosBucket', 'cosSecretId', 'cosSecretKey'];
            } else if (type === 'oss') {
                fields = ['ossBucket', 'ossAccessKeyId', 'ossAccessKeySecret'];
            } else if (type === 'upyun') {
                fields = ['upyunBucket', 'upyunOperator', 'upyunPassword', 'upyunDomain'];
            } else if (type === 'lsky') {
                fields = ['lskyUrl', 'lskyToken'];
            }
            
            return fields.some(function(fieldName) {
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
                var allConfigInputs = document.querySelectorAll('.temf-cos-field, .temf-oss-field, .temf-upyun-field, .temf-lsky-field');
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
