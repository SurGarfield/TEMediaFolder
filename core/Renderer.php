<?php

namespace TypechoPlugin\TEMediaFolder\Core;

use TypechoPlugin\TEMediaFolder\Services\LocalFileService;

class Renderer
{
    private $config;
    private $fileService;

    public function __construct(ConfigManager $config, LocalFileService $fileService = null)
    {
        $this->config = $config;
        $this->fileService = $fileService ?: new LocalFileService($config);
    }

    public function render()
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        echo "\n<!-- TEMediaFolder start -->\n";
        $this->renderContainer();
        $this->renderModal();
        $this->renderStyles();
        $this->renderScript();
        echo "<!-- TEMediaFolder end -->\n";
    }

    private function renderContainer()
    {
        $storage = $this->config->getStorage();
        $isMarkdown = $this->config->isMarkdownEnabled() ? '1' : '0';
        $pluginUrl = \Widget\Options::alloc()->pluginUrl . '/TEMediaFolder';

        echo '<section id="temediafolder" class="typecho-post-option" data-temf-md="' . $isMarkdown . '" data-plugin-url="' . htmlspecialchars($pluginUrl) . '"';
        
        if ($storage === 'cos') {
            $cosListUrl = \Widget\Security::alloc()->getIndex('/action/temf-cos-list');
            $cosUploadUrl = \Widget\Security::alloc()->getIndex('/action/temf-cos-upload');
            echo ' data-cos-list="' . htmlspecialchars($cosListUrl) . '"';
            echo ' data-cos-upload="' . htmlspecialchars($cosUploadUrl) . '"';
        } elseif ($storage === 'oss') {
            $ossListUrl = \Widget\Security::alloc()->getIndex('/action/temf-oss-list');
            $ossUploadUrl = \Widget\Security::alloc()->getIndex('/action/temf-oss-upload');
            echo ' data-oss-list="' . htmlspecialchars($ossListUrl) . '"';
            echo ' data-oss-upload="' . htmlspecialchars($ossUploadUrl) . '"';
        } elseif ($storage === 'lsky') {
            $lskyListUrl = \Widget\Security::alloc()->getIndex('/action/temf-lsky-list');
            $lskyUploadUrl = \Widget\Security::alloc()->getIndex('/action/temf-lsky-upload');
            echo ' data-lsky-list="' . htmlspecialchars($lskyListUrl) . '"';
            echo ' data-lsky-upload="' . htmlspecialchars($lskyUploadUrl) . '"';
        } elseif ($storage === 'upyun') {
            $upyunListUrl = \Widget\Security::alloc()->getIndex('/action/temf-upyun-list');
            $upyunUploadUrl = \Widget\Security::alloc()->getIndex('/action/temf-upyun-upload');
            echo ' data-upyun-list="' . htmlspecialchars($upyunListUrl) . '"';
            echo ' data-upyun-upload="' . htmlspecialchars($upyunUploadUrl) . '"';
        } elseif ($storage === 'multi') {
            $multiListUrl = \Widget\Security::alloc()->getIndex('/action/temf-multi-list');
            $multiUploadUrl = \Widget\Security::alloc()->getIndex('/action/temf-multi-upload');
            $storageTypesUrl = \Widget\Security::alloc()->getIndex('/action/temf-storage-types');
            echo ' data-multi-list="' . htmlspecialchars($multiListUrl) . '"';
            echo ' data-multi-upload="' . htmlspecialchars($multiUploadUrl) . '"';
            echo ' data-storage-types="' . htmlspecialchars($storageTypesUrl) . '"';
        } else {
            $localUploadUrl = \Widget\Security::alloc()->getIndex('/action/temf-local-upload');
            echo ' data-local-upload="' . htmlspecialchars($localUploadUrl) . '"';
        }
        
        echo '>';
        echo '<div class="temf-toolbar">';
        echo '<button type="button" id="temf-open" class="btn btn-xs">' . _t('素材库') . '</button>';
        echo '</div>';
        echo '</section>';
    }

    private function renderModal()
    {
        $storage = $this->config->getStorage();
        
        echo '<div id="temf-modal" class="temf-modal" aria-hidden="true">';
        echo '<div class="temf-backdrop" data-temf-close></div>';
        echo '<div class="temf-dialog" role="dialog" aria-modal="true" aria-labelledby="temf-title">';
        
        echo '<div class="temf-header">';
        if ($storage === 'multi') {
            echo '<div class="temf-title-container">';
            echo '<div class="temf-title-wrapper">';
            echo '<strong id="temf-title">' . _t('素材库') . '</strong>';
            echo '<div id="temf-storage-switcher" class="temf-storage-switcher"></div>';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<strong id="temf-title">' . _t('素材库') . '</strong>';
        }
        echo '<div class="temf-actions-bar">';
        
        if ($storage === 'multi') {
            // 多模式：渲染所有选择器，由JavaScript控制显示/隐藏
            echo '<select id="temf-dir" class="btn btn-xs" style="display:none;"></select>';
            echo '<select id="temf-subdir" class="btn btn-xs" style="display:none;"></select>';
            echo '<select id="temf-year" class="btn btn-xs" style="display:none;"></select>';
            echo '<select id="temf-month" class="btn btn-xs" style="display:none;"></select>';
        } else if ($storage === 'cos' || $storage === 'oss' || $storage === 'upyun' || $storage === 'lsky') {
            echo '<select id="temf-dir" class="btn btn-xs"></select>';
            echo '<select id="temf-subdir" class="btn btn-xs"></select>';
        } else {
            echo '<select id="temf-year" class="btn btn-xs"></select>';
            echo '<select id="temf-month" class="btn btn-xs"></select>';
        }
        
        echo '<button type="button" class="btn btn-xs" id="temf-upload" title="' . _t('支持多选上传') . '">' . _t('上传图片') . '</button>';
        echo '<button type="button" class="btn btn-xs primary" id="temf-insert-selected" disabled>' . _t('插入所选') . '</button>';
        echo '<button type="button" class="btn btn-xs" id="temf-close" aria-label="' . _t('关闭') . '">×</button>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="temf-body">';
        $this->renderContent($storage);
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
    }

    private function renderContent($storage)
    {
        if ($storage === 'multi') {
            echo '<div id="temf-multi-content" class="temf-content temf-multi-content">';
            echo '<div class="temf-loading">正在加载存储类型...</div>';
            echo '</div>';
        } elseif ($storage === 'cos') {
            echo '<p class="description">' . _t('正在加载 COS 列表...') . '</p>';
        } elseif ($storage === 'oss') {
            echo '<p class="description">' . _t('正在加载 OSS 列表...') . '</p>';
        } elseif ($storage === 'lsky') {
            echo '<p class="description">' . _t('正在加载兰空图床列表...') . '</p>';
        } elseif ($storage === 'upyun') {
            echo '<p class="description">' . _t('正在加载又拍云列表...') . '</p>';
        } else {
            $this->renderLocalFiles();
        }
    }

    private function renderLocalFiles()
    {
        $groups = $this->fileService->getFileGroups();
        
        if (empty($groups)) {
            $uploadDir = $this->config->getUploadDir();
            if (!is_dir($uploadDir)) {
                echo '<p class="description">' . _t('未找到上传目录: %s', htmlspecialchars($uploadDir)) . '</p>';
            } else {
                echo '<p class="description">' . _t('尚未发现可用图片文件') . '</p>';
            }
            return;
        }

        foreach ($groups as $ym => $items) {
            $count = count($items);
            echo '<details class="temf-group" open>';
            echo '<summary class="btn btn-xs">' . htmlspecialchars($ym) . ' (' . $count . ')</summary>';
            echo '<ul class="temf-grid">';
            
            foreach ($items as $item) {
                $this->renderFileItem($item);
            }
            
            echo '</ul>';
            echo '</details>';
        }
    }

    private function renderFileItem($item)
    {
        $safeName = htmlspecialchars($item['name']);
        $safeUrl = htmlspecialchars($item['url']);
        
        echo '<li class="temf-item" data-url="' . $safeUrl . '">';
        echo '<div class="temf-thumb">';
        echo '<input type="checkbox" class="temf-pick" value="' . $safeUrl . '">';
        echo '<img src="' . $safeUrl . '" alt="' . $safeName . '" loading="lazy" referrerpolicy="no-referrer"/>';
        echo '</div>';
        echo '<div class="temf-meta">';
        echo '<span class="temf-name" title="' . $safeName . '">' . $safeName . '</span>';
        echo '<div class="temf-actions">';
        echo '<button type="button" class="btn btn-xs" data-temf-insert data-url="' . $safeUrl . '">' . _t('插入') . '</button>';
        echo '<button type="button" class="btn btn-xs" data-temf-copy data-url="' . $safeUrl . '">' . _t('复制') . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</li>';
    }

    private function renderStyles()
    {
        $thumbSize = $this->config->get('thumbSize', 120);
        
        echo '<style>';
        echo '#temediafolder{margin-top: 12px; padding: 15px; border: 1px dashed #D9D9D6; background-color: #FFF; color: #999; font-size: .92857em;}';
        echo '#temediafolder .temf-toolbar{display:flex;justify-content:center}';
        echo '#temf-open{min-width:auto;padding:4px 10px}';
        echo '.temf-modal{position:fixed;inset:0;display:none;z-index:9999}';
        echo '.temf-modal.open{display:block}';
        echo '.temf-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.4)}';
        echo '.temf-dialog{position:absolute;left:50%;top:8%;transform:translateX(-50%);background:#fff;border:1px solid #e3e3e3;border-radius:4px;box-shadow:0 8px 24px rgba(0,0,0,.15);max-width:1024px;width:92%;max-height:84vh;display:flex;flex-direction:column}';
        echo '.temf-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:nowrap;padding:8px 12px;border-bottom:1px solid #f0f0f0;gap:8px}';
        echo '.temf-actions-bar{display:flex;gap:8px;align-items:center;flex-shrink:0}';
        echo '#temf-year,#temf-month,#temf-dir,#temf-subdir{border:1px solid #e3e3e3;background:#fff;padding:3px 6px;border-radius:3px}';
        echo '.temf-thumb img{background:#f6f6f6}';
        echo '.temf-thumb img[referrerpolicy]{referrerpolicy:no-referrer}';
        echo '.temf-body{padding:12px;overflow:auto}';
        echo '.temf-grid{list-style:none;margin:8px 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(' . $thumbSize . 'px,1fr));gap:8px;contain:content}';
        echo '.temf-item{border:1px solid #c4c4c4;border-radius:4px;background:#fff;display:flex;flex-direction:column;overflow:hidden;will-change:transform}';
        echo '.temf-thumb{position:relative;width:100%;background:#fafafa;contain:paint}';
        echo '.temf-thumb:before{content:"";display:block;padding-top:100%}';
        echo '.temf-thumb img{position:absolute;left:0;top:0;right:0;bottom:0;max-width:100%;max-height:100%;width:100%;height:100%;object-fit:cover;border-bottom:1px solid #f0f0f0;z-index:1}';
        echo '.temf-pick{position:absolute;right:6px;top:6px;width:18px;height:18px;z-index:2;cursor:pointer;background:rgba(255,255,255,.9);border-radius:3px}';
        echo '.temf-meta{display:flex;flex-direction:column;gap:6px;padding:6px}';
        echo '.temf-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:#666}';
        echo '.temf-actions{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}';
        // 图片懒加载样式 - loading.gif 在容器内居中显示 30px
        echo '.temf-lazy-img{transition:opacity 0.3s ease-in-out}';
        echo '.temf-lazy-img:not(.temf-loaded){width:30px !important;height:30px !important;max-width:30px !important;max-height:30px !important;object-fit:contain !important;left:50% !important;top:50% !important;transform:translate(-50%, -50%) !important;border:none !important}';
        echo '.temf-lazy-img.temf-loading{opacity:1}';
        echo '.temf-lazy-img.temf-loaded{width:100% !important;height:100% !important;max-width:100% !important;max-height:100% !important;object-fit:cover !important;left:0 !important;top:0 !important;transform:none !important}';
        // 优化"插入所选"按钮的垂直对齐
        echo '#temf-insert-selected{align-items:center;position:relative;top:-1px;margin-top:0px}';
        echo '.temf-check{margin-right:auto;font-size:12px;color:#666;display:flex;align-items:center;gap:4px}';
        
        // 分页控件样式 - 滚动到底部时显示
        echo '.temf-pagination{display:none;align-items:center;justify-content:center;gap:12px;padding:12px;border-top:1px solid #e0e0e0;background:#fafafa;flex-shrink:0}';
        echo '.temf-pagination .temf-page-info{font-size:13px;color:#666;min-width:100px;text-align:center}';
        echo '.temf-pagination .btn{cursor:pointer}';
        echo '.temf-pagination .btn:disabled{opacity:0.5;cursor:not-allowed}';
        
        echo '.temf-progress{display:none;align-items:center;gap:8px;margin-left:12px;flex:0 0 auto;max-width:280px}';
        echo '.temf-progress-counter{font-size:11px;color:#666;white-space:nowrap;min-width:35px}';
        echo '.temf-progress-container{flex:1;display:flex;flex-direction:column;gap:2px}';
        echo '.temf-progress-label{font-size:10px;color:#999;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px}';
        echo '.temf-progress-bg{width:100%;height:6px;background:#f0f0f0;border-radius:3px;overflow:hidden}';
        echo '.temf-progress-bar{height:100%;background:linear-gradient(90deg,#4CAF50,#45a049);border-radius:3px;width:0%;transition:width 0.3s ease}';
        echo '.temf-progress-percent{font-size:10px;color:#666;text-align:right;min-width:30px}';
        
        echo '.temf-title-container{flex:1;min-width:0}';
        echo '.temf-title-wrapper{position:relative;display:inline-flex;align-items:center;gap:8px}';
        echo '.temf-title-container #temf-title{cursor:pointer;position:relative;user-select:none}';
        // 进度条在多模式标题右侧固定定位
        echo '.temf-title-wrapper .temf-progress{position:absolute;left:100%;top:50%;transform:translateY(-50%);margin-left:8px;max-width:320px}';
        echo '.temf-title-container #temf-title:hover{color:#0073aa}';
        echo '.temf-title-container #temf-title:after{content:" ▼";font-size:10px;margin-left:5px;transition:transform 0.2s}';
        echo '.temf-title-container.expanded #temf-title:after{transform:rotate(-180deg)}';
        echo '.temf-storage-switcher{position:absolute;top:0;left:100%;margin-left:10px;background:white;border:1px solid #ddd;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.1);display:none;gap:0;flex-direction:column;min-width:120px;z-index:1000}';
        echo '.temf-storage-switcher.show{display:flex}';
        echo '.temf-storage-btn{padding:8px 12px;border:none;border-bottom:1px solid #eee;background:white;cursor:pointer;font-size:12px;color:#666;text-decoration:none;display:flex;align-items:center;gap:6px;transition:all 0.2s;border-radius:0}';
        echo '.temf-storage-btn:first-child{border-top-left-radius:4px;border-top-right-radius:4px}';
        echo '.temf-storage-btn:last-child{border-bottom-left-radius:4px;border-bottom-right-radius:4px;border-bottom:none}';
        echo '.temf-storage-btn:hover{background:#f5f5f5}';
        echo '.temf-storage-btn.active{background:#0073aa;color:white}';
        echo '.temf-storage-btn.disabled{opacity:0.5;cursor:not-allowed}';
        echo '.temf-multi-content{position:relative}';
        echo '.temf-loading{text-align:center;padding:50px;color:#666}';
        
        // 切换动画样式
        echo '.temf-content-switching{position:relative;overflow:hidden}';
        echo '.temf-content-switched{opacity:1;transform:scale(1);transition:all 0.3s ease}';
        echo '.temf-storage-switcher{transition:all 0.2s ease}';
        echo '.temf-storage-btn{transition:all 0.2s ease;transform:scale(1)}';
        echo '.temf-storage-btn:hover{transform:scale(1.05)}';
        echo '.temf-storage-btn:active{transform:scale(0.95)}';
        echo '.temf-grid{opacity:1;transition:opacity 0.3s ease;contain:layout style paint}';
        echo '.temf-grid.temf-loading{opacity:0.5}';
        echo '.temf-grid-loaded{animation:fadeInUp 0.3s ease-out}';
        echo '@keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}';
        echo '.temf-item{will-change:transform;contain:layout style paint}';
        echo '.temf-thumb img{will-change:opacity}';
        echo '.temf-item:hover{transform:translateY(-2px);transition:transform 0.2s ease}';
        
        // 新的切换覆盖层样式
        echo '.temf-switching-overlay{position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.96);backdrop-filter:saturate(140%) blur(1px);display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:200px;z-index:10;pointer-events:none}';
        echo '.temf-switching-spinner{width:32px;height:32px;border:3px solid #f3f3f3;border-top:3px solid #0073aa;border-radius:50%;animation:temf-spin 1s linear infinite;margin-bottom:16px}';
        echo '.temf-switching-text{color:#666;font-size:14px;text-align:center}';
        echo '@keyframes temf-spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}';
        
        echo '</style>';
    }

    private function renderScript()
    {
        $dataMap = [];
        $latestYm = null;
        
        if ($this->config->getStorage() === 'local') {
            $groups = $this->fileService->getFileGroups();
            foreach ($groups as $ym => $items) {
                if ($latestYm === null) {
                    $latestYm = $ym;
                }
                [$year, $month] = explode('-', $ym);
                if (!isset($dataMap[$year])) {
                    $dataMap[$year] = [];
                }
                $dataMap[$year][$month] = array_map(function($item) {
                    return [
                        'url' => $item['url'],
                        'name' => $item['name'],
                        'mtime' => $item['mtime']
                    ];
                }, $items);
            }
        }

        // 获取兰空图床配置
        $lskyConfig = $this->config->getLskyConfig();
        
        $conf = json_encode([
            'md' => $this->config->isMarkdownEnabled(),
            'labels' => [
                'copied' => _t('已复制'),
                'copyLink' => _t('复制'),
                'insertSelected' => _t('插入所选'),
                'uploadImage' => _t('上传图片'),
            ],
            'data' => $dataMap,
            'latest' => $latestYm,
            'source' => $this->config->getStorage(),
            'thumbSize' => $this->config->get('thumbSize', 120),
            'lskyAlbumId' => $lskyConfig['albumId'] ?? '',
            'paginationRows' => max(1, intval($this->config->get('paginationRows', 4))),
        ], JSON_UNESCAPED_UNICODE);

        echo '<script>var TEMF_CONF = ' . $conf . ';</script>';
        echo '<script src="' . $this->getAssetUrl('js/temediafolder.js') . '"></script>';
    }

    private function getAssetUrl($path)
    {
        $options = \Widget\Options::alloc();
        $pluginUrl = $options->pluginUrl . '/TEMediaFolder/assets/' . $path;
        return $pluginUrl;
    }
}
