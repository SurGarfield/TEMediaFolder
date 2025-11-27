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
            $cosDeleteUrl = \Widget\Security::alloc()->getIndex('/action/temf-cos-delete');
            $cosRenameUrl = \Widget\Security::alloc()->getIndex('/action/temf-cos-rename');
            echo ' data-cos-list="' . htmlspecialchars($cosListUrl) . '"';
            echo ' data-cos-upload="' . htmlspecialchars($cosUploadUrl) . '"';
            echo ' data-cos-delete="' . htmlspecialchars($cosDeleteUrl) . '"';
            echo ' data-cos-rename="' . htmlspecialchars($cosRenameUrl) . '"';
        } elseif ($storage === 'oss') {
            $ossListUrl = \Widget\Security::alloc()->getIndex('/action/temf-oss-list');
            $ossUploadUrl = \Widget\Security::alloc()->getIndex('/action/temf-oss-upload');
            $ossDeleteUrl = \Widget\Security::alloc()->getIndex('/action/temf-oss-delete');
            $ossRenameUrl = \Widget\Security::alloc()->getIndex('/action/temf-oss-rename');
            echo ' data-oss-list="' . htmlspecialchars($ossListUrl) . '"';
            echo ' data-oss-upload="' . htmlspecialchars($ossUploadUrl) . '"';
            echo ' data-oss-delete="' . htmlspecialchars($ossDeleteUrl) . '"';
            echo ' data-oss-rename="' . htmlspecialchars($ossRenameUrl) . '"';
        } elseif ($storage === 'lsky') {
            $lskyListUrl = \Widget\Security::alloc()->getIndex('/action/temf-lsky-list');
            $lskyUploadUrl = \Widget\Security::alloc()->getIndex('/action/temf-lsky-upload');
            $lskyDeleteUrl = \Widget\Security::alloc()->getIndex('/action/temf-lsky-delete');
            echo ' data-lsky-list="' . htmlspecialchars($lskyListUrl) . '"';
            echo ' data-lsky-upload="' . htmlspecialchars($lskyUploadUrl) . '"';
            echo ' data-lsky-delete="' . htmlspecialchars($lskyDeleteUrl) . '"';
        } elseif ($storage === 'upyun') {
            $upyunListUrl = \Widget\Security::alloc()->getIndex('/action/temf-upyun-list');
            $upyunUploadUrl = \Widget\Security::alloc()->getIndex('/action/temf-upyun-upload');
            $upyunDeleteUrl = \Widget\Security::alloc()->getIndex('/action/temf-upyun-delete');
            $upyunRenameUrl = \Widget\Security::alloc()->getIndex('/action/temf-upyun-rename');
            echo ' data-upyun-list="' . htmlspecialchars($upyunListUrl) . '"';
            echo ' data-upyun-upload="' . htmlspecialchars($upyunUploadUrl) . '"';
            echo ' data-upyun-delete="' . htmlspecialchars($upyunDeleteUrl) . '"';
            echo ' data-upyun-rename="' . htmlspecialchars($upyunRenameUrl) . '"';
        } elseif ($storage === 'multi') {
            $multiListUrl = \Widget\Security::alloc()->getIndex('/action/temf-multi-list');
            $multiUploadUrl = \Widget\Security::alloc()->getIndex('/action/temf-multi-upload');
            $storageTypesUrl = \Widget\Security::alloc()->getIndex('/action/temf-storage-types');
            $multiRenameUrl = \Widget\Security::alloc()->getIndex('/action/temf-multi-rename');
            $multiDeleteUrl = \Widget\Security::alloc()->getIndex('/action/temf-multi-delete');
            echo ' data-multi-list="' . htmlspecialchars($multiListUrl) . '"';
            echo ' data-multi-upload="' . htmlspecialchars($multiUploadUrl) . '"';
            echo ' data-storage-types="' . htmlspecialchars($storageTypesUrl) . '"';
            echo ' data-multi-rename="' . htmlspecialchars($multiRenameUrl) . '"';
            echo ' data-multi-delete="' . htmlspecialchars($multiDeleteUrl) . '"';
        } else {
            $localUploadUrl = \Widget\Security::alloc()->getIndex('/action/temf-local-upload');
            $localRenameUrl = \Widget\Security::alloc()->getIndex('/action/temf-local-rename');
            $localDeleteUrl = \Widget\Security::alloc()->getIndex('/action/temf-local-delete');
            echo ' data-local-upload="' . htmlspecialchars($localUploadUrl) . '"';
            echo ' data-local-rename="' . htmlspecialchars($localRenameUrl) . '"';
            echo ' data-local-delete="' . htmlspecialchars($localDeleteUrl) . '"';
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
        echo '<div class="temf-select-group">';
        if ($storage === 'multi') {
            // 多模式：渲染所有选择器，由JavaScript控制显示/隐藏
            echo '<div class="temf-select-holder" data-select="temf-dir"><select id="temf-dir" class="temf-native-select" data-initial-hidden="true"></select></div>';
            echo '<div class="temf-select-holder" data-select="temf-subdir"><select id="temf-subdir" class="temf-native-select" data-initial-hidden="true"></select></div>';
            echo '<div class="temf-select-holder" data-select="temf-year"><select id="temf-year" class="temf-native-select" data-initial-hidden="true"></select></div>';
            echo '<div class="temf-select-holder" data-select="temf-month"><select id="temf-month" class="temf-native-select" data-initial-hidden="true"></select></div>';
        } elseif ($storage === 'cos' || $storage === 'oss' || $storage === 'upyun' || $storage === 'lsky') {
            echo '<div class="temf-select-holder" data-select="temf-dir"><select id="temf-dir" class="temf-native-select"></select></div>';
            echo '<div class="temf-select-holder" data-select="temf-subdir"><select id="temf-subdir" class="temf-native-select"></select></div>';
        } else {
            echo '<div class="temf-select-holder" data-select="temf-year"><select id="temf-year" class="temf-native-select"></select></div>';
            echo '<div class="temf-select-holder" data-select="temf-month"><select id="temf-month" class="temf-native-select"></select></div>';
        }
        echo '</div>';

        echo '<div class="temf-button-group">';
        echo '<button type="button" class="btn btn-xs" id="temf-upload" title="' . _t('支持多选上传') . '">' . _t('上传图片') . '</button>';
        echo '<button type="button" class="btn btn-xs primary" id="temf-insert-selected" disabled>' . _t('插入所选') . '</button>';
        echo '<button type="button" class="btn btn-xs" id="temf-close" aria-label="' . _t('关闭') . '">×</button>';
        echo '</div>';
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
        $safeFullName = htmlspecialchars($item['name']);
        $displayName = $safeFullName;
        $dotPos = strrpos($item['name'], '.');
        if ($dotPos !== false && $dotPos > 0) {
            $displayName = htmlspecialchars(substr($item['name'], 0, $dotPos));
        }
        $safeUrl = htmlspecialchars($item['url']);
        $directory = isset($item['directory']) ? $item['directory'] : '';
        $normalizedDirectory = trim(str_replace('\\', '/', $directory), '/');
        $directoryDisplay = $normalizedDirectory === '' ? '/' : '/' . $normalizedDirectory;
        $safeDirectoryValue = htmlspecialchars($normalizedDirectory);
        $safeDirectoryDisplay = htmlspecialchars($directoryDisplay);

        echo '<li class="temf-item" data-url="' . $safeUrl . '" data-directory="' . $safeDirectoryValue . '">';
        echo '<div class="temf-thumb">';
        echo '<input type="checkbox" class="temf-pick" value="' . $safeUrl . '">';
        echo '<img src="' . $safeUrl . '" alt="' . $safeFullName . '" loading="lazy" referrerpolicy="no-referrer"/>';
        echo '</div>';
        echo '<div class="temf-meta">';
        echo '<span class="temf-name" data-full-name="' . $safeFullName . '" title="' . $displayName . '">' . $displayName . '</span>';
        echo '<span class="temf-directory" data-directory="' . $safeDirectoryValue . '" title="' . $safeDirectoryDisplay . '">' . $safeDirectoryDisplay . '</span>';
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
        echo '#temediafolder{margin-top:12px;padding:20px;border:1px solid rgba(0,0,0,.12);border-radius:3px;background:#f9f9f9;color:#1f1f1f;font-size:.92857em;}';
        echo '#temediafolder .temf-toolbar{display:flex;justify-content:center;gap:10px}';
        echo '#temf-open{min-width:140px;padding:10px 18px;border-radius:3px;font-size:14px;font-weight:500;}';
        echo '.temf-modal{position:fixed;inset:0;display:none;z-index:9999}';
        echo '.temf-modal.open{display:block}';
        echo '.temf-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.4)}';
        echo '.temf-dialog{position:absolute;left:50%;top:10%;transform:translateX(-50%);background:#fff;border:1px solid rgba(0,0,0,.12);border-radius:3px;box-shadow:0 18px 48px rgba(0,0,0,.16);max-width:1024px;width:92%;max-height:88vh;display:flex;flex-direction:column;}';
        echo '.temf-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:nowrap;padding:14px 18px;border-bottom:1px solid rgba(0,0,0,.08);gap:12px;background:#f5f5f5;border-top-left-radius:3px;border-top-right-radius:3px;}';
        echo '.temf-actions-bar{display:flex;gap:12px;align-items:center;flex-shrink:0;flex-wrap:nowrap;justify-content:space-between;}';
        echo '.temf-select-group,.temf-button-group{display:flex;gap:10px;align-items:center;flex-wrap:nowrap;}';
        echo '.temf-select-holder{position:relative;flex:1 1 150px;min-width:130px;display:flex;}';
        echo '.temf-select-holder.hidden{display:none;}';
        echo '.temf-select-holder[data-select="temf-month"]{flex:0 1 110px;}';
        echo '.temf-button-group .btn{flex:0 0 auto;}';
        echo '.temf-native-select{position:absolute;inset:0;width:100%;height:100%;opacity:0;pointer-events:none;}';
        echo '.temf-select-trigger{display:flex;align-items:center;justify-content:space-between;width:100%;border:1px solid rgba(0,0,0,.18);background:#fff;padding:8px 36px 8px 14px;border-radius:6px;color:#1f1f1f;font-size:13px;min-height:38px;transition:border-color .2s ease, box-shadow .2s ease, color .2s ease;cursor:pointer;text-align:left;gap:12px;}';
        echo '.temf-select-holder[data-disabled="true"] .temf-select-trigger{opacity:.6;cursor:not-allowed;}';
        echo '.temf-select-trigger:hover{border-color:rgba(0,0,0,.45);color:#000;}';
        echo '.temf-select-holder.open .temf-select-trigger{border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.08);}';
        echo '.temf-select-trigger:focus-visible{outline:3px solid rgba(0,0,0,.18);}';
        echo '.temf-select-label{flex:1 1 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}';
        echo '.temf-select-caret{flex:0 0 auto;width:0;height:0;border-left:5px solid transparent;border-right:5px solid transparent;border-top:6px solid #1f1f1f;transition:transform .2s ease;}';
        echo '.temf-select-holder.open .temf-select-caret{transform:rotate(180deg);}';
        echo '.temf-select-dropdown{position:absolute;top:calc(100% + 6px);left:0;right:0;background:#fff;border:1px solid rgba(0,0,0,.16);box-shadow:0 16px 32px rgba(0,0,0,.18);border-radius:6px;padding:6px 0;display:none;flex-direction:column;max-height:240px;overflow:auto;z-index:1050;}';
        echo '.temf-select-holder.open .temf-select-dropdown{display:flex;}';
        echo '.temf-select-option{background:transparent;border:none;padding:8px 14px;text-align:left;font-size:13px;color:#1f1f1f;cursor:pointer;transition:background .15s ease,color .15s ease;display:flex;align-items:center;gap:8px;}';
        echo '.temf-select-option:hover{background:#f0f0f0;color:#000;}';
        echo '.temf-select-option.selected{background:#1f1f1f;color:#fff;}';
        echo '.temf-select-option.selected:hover{background:#1f1f1f;color:#fff;}';
        echo '.temf-select-empty{padding:10px 14px;font-size:13px;color:#666;}';
        echo '.temf-thumb img{background:#f2f2f2}';
        echo '.temf-thumb img[referrerpolicy]{referrerpolicy:no-referrer}';
        echo '.temf-body{position:relative;padding:18px;overflow:auto;flex:1 1 auto;background:#f9f9f9;border-bottom-left-radius:3px;border-bottom-right-radius:3px;scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.35) transparent;}';
        echo '.temf-body::-webkit-scrollbar{width:6px;height:6px;}';
        echo '.temf-body::-webkit-scrollbar-track{background:transparent;}';
        echo '.temf-body::-webkit-scrollbar-thumb{background:rgba(0,0,0,.35);border-radius:3px;}';
        echo '.temf-grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(' . $thumbSize . 'px,1fr));gap:12px;contain:content}';
        echo '.temf-item{border:1px solid rgba(0,0,0,.12);border-radius:3px;background:#fff;display:flex;flex-direction:column;overflow:hidden;transition:border-color .18s ease,background-color .18s ease;}';
        echo '.temf-thumb{position:relative;width:100%;background:#f0f0f0;contain:paint}';
        echo '.temf-thumb:before{content:"";display:block;padding-top:100%}';
        echo '.temf-item:hover{border-color:rgba(0,0,0,.28);background:#fdfdfd;}';
        echo '.temf-thumb img{position:absolute;left:0;top:0;right:0;bottom:0;max-width:100%;max-height:100%;width:100%;height:100%;object-fit:cover;border-bottom:1px solid rgba(0,0,0,.08);z-index:1;border-top-left-radius:3px;border-top-right-radius:3px;}';
        echo '.temf-pick{position:absolute;right:10px;top:10px;width:20px;height:20px;z-index:2;cursor:pointer;background:rgba(255,255,255,.96);border-radius:3px;border:1px solid rgba(0,0,0,.24);box-shadow:0 2px 6px rgba(0,0,0,.12);}';
        echo '.temf-meta{display:flex;flex-direction:column;gap:6px;padding:12px;align-items:center;text-align:center;}';
        echo '.temf-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:10px;font-weight:500;color:#1f1f1f;cursor:text;transition:color .2s ease;max-width:100%;}';
        echo '.temf-directory{display:block;font-size:10px;color:#666;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;}';
        echo '.temf-name:hover{color:#000}';
        echo '.temf-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px;}';
        echo '.temf-actions .btn{display:flex;align-items:center;justify-content:center;padding:6px 8px;min-width:0;margin:0;font-size:12px;line-height:1;white-space:nowrap;}';
        echo '#temediafolder .btn,.temf-dialog .btn{background:#fff;border:1px solid rgba(0,0,0,.24);color:#1f1f1f;border-radius:3px;padding:10px 18px;transition:all .2s ease;font-size:13px;line-height:1.2;min-height:36px;font-weight:500;}';
        echo '#temediafolder .btn:hover,.temf-dialog .btn:hover{border-color:#000;background:#f0f0f0;color:#000}';
        echo '#temediafolder .btn.primary,.temf-dialog .btn.primary{background:#1f1f1f;border-color:#000;color:#fff;box-shadow:none;}';
        echo '#temediafolder .btn.primary:hover,.temf-dialog .btn.primary:hover{background:#000;border-color:#000;color:#fff;}';
        echo '#temediafolder .btn:disabled,.temf-dialog .btn:disabled{opacity:.55;cursor:not-allowed;box-shadow:none}';
        echo '// 图片懒加载样式 - loading.gif 在容器内居中显示 30px';
        echo '.temf-lazy-img{transition:opacity 0.3s ease-in-out}';
        echo '.temf-lazy-img:not(.temf-loaded){width:30px !important;height:30px !important;max-width:30px !important;max-height:30px !important;object-fit:contain !important;left:50% !important;top:50% !important;transform:translate(-50%, -50%) !important;border:none !important}';
        echo '.temf-lazy-img.temf-loading{opacity:1}';
        echo '.temf-lazy-img.temf-loaded{width:100% !important;height:100% !important;max-width:100% !important;max-height:100% !important;object-fit:cover !important;left:0 !important;top:0 !important;transform:none !important}';
        echo '.temf-rename-hint{font-size:11px;color:#555;margin-top:4px;line-height:1.2;}';
        echo '.temf-name-editing{pointer-events:none;}';
        echo '.temf-name.temf-rename-success{animation:temf-rename-flash .8s ease-in-out;background:linear-gradient(90deg,rgba(0,0,0,.12),rgba(0,0,0,0));padding:0 4px;border-radius:3px;}';
        echo '.temf-rename-editor{display:flex;align-items:center;gap:6px;width:100%;max-width:100%;}';
        echo '.temf-rename-input{flex:1 1 auto;min-width:0;border:1px solid rgba(0,0,0,.25);border-radius:3px;padding:4px 6px;font-size:10px;line-height:1.3;box-sizing:border-box;}';
        echo '.temf-rename-input:focus{outline:2px solid rgba(31,31,31,.35);border-color:rgba(0,0,0,.45);}';
        echo '.temf-rename-hint{max-width:100%;text-align:center;}';
        echo '@keyframes temf-rename-flash{0%{background:linear-gradient(90deg,rgba(0,0,0,.18),rgba(0,0,0,0))}100%{background:transparent}}';
        echo '.temf-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;}';
        echo '.temf-lazy-img{transition:opacity 0.3s ease-in-out}';
        echo '.temf-lazy-img:not(.temf-loaded){width:30px !important;height:30px !important;max-width:30px !important;max-height:30px !important;object-fit:contain !important;left:50% !important;top:50% !important;transform:translate(-50%, -50%) !important;border:none !important}';
        echo '.temf-lazy-img.temf-loading{opacity:1}';
        echo '.temf-lazy-img.temf-loaded{width:100% !important;height:100% !important;max-width:100% !important;max-height:100% !important;object-fit:cover !important;left:0 !important;top:0 !important;transform:none !important}';
        // 优化"插入所选"按钮的垂直对齐
        echo '#temf-insert-selected{align-items:center;position:relative;top:-1px;margin-top:0px}';
        echo '.temf-check{margin-right:auto;font-size:12px;color:#666;display:flex;align-items:center;gap:4px}';
        
        // 分页控件样式 - 滚动到底部时显示
        echo '.temf-pagination{display:none;align-items:center;justify-content:center;gap:16px;padding:14px 18px;border-top:1px solid rgba(0,0,0,.08);background:#f5f5f5;flex-shrink:0;border-bottom-left-radius:3px;border-bottom-right-radius:3px;}';
        echo '.temf-pagination .temf-page-info{font-size:13px;color:#1f1f1f;min-width:100px;text-align:center;font-weight:500}';
        echo '.temf-pagination .btn{cursor:pointer}';
        echo '.temf-pagination .btn:disabled{opacity:0.5;cursor:not-allowed}';

        echo '.temf-progress-overlay{position:absolute;inset:0;background:rgba(18,18,18,0.55);backdrop-filter:saturate(160%) blur(1.5px);display:none;align-items:center;justify-content:center;z-index:20;}';
        echo '.temf-progress-card{background:#fff;border-radius:10px;padding:24px 32px;box-shadow:0 18px 48px rgba(0,0,0,0.22);width:min(340px,85%);display:flex;flex-direction:column;gap:18px;text-align:center;}';
        echo '.temf-progress-title{font-size:15px;font-weight:600;color:#1f1f1f;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}';
        echo '.temf-progress-bar-track{height:8px;background:rgba(0,0,0,0.08);border-radius:999px;overflow:hidden;position:relative;}';
        echo '.temf-progress-bar{height:100%;width:0%;background:#1f1f1f;border-radius:inherit;transition:width 0.3s ease;}';
        echo '.temf-progress-status{font-size:13px;color:#555;font-weight:500;}';
        echo '.temf-progress-status.error{color:#d63638;}';
        echo '.temf-progress-card.temf-progress-error .temf-progress-bar{background:#d63638;}';
        
        echo '.temf-title-container{flex:1;min-width:0}';
        echo '.temf-title-wrapper{position:relative;display:inline-flex;align-items:center;gap:8px}';
        echo '.temf-title-container #temf-title{cursor:pointer;position:relative;user-select:none}';
        // 进度条由蒙版居中展示
        echo '.temf-title-container #temf-title:hover{color:#000}';
        echo '.temf-title-container #temf-title:after{content:" ▼";font-size:10px;margin-left:5px;transition:transform 0.2s}';
        echo '.temf-title-container.expanded #temf-title:after{transform:rotate(-180deg)}';
        echo '.temf-storage-switcher{position:absolute;top:0;left:100%;margin-left:10px;background:white;border:1px solid rgba(0,0,0,.12);border-radius:3px;box-shadow:0 16px 32px rgba(0,0,0,.18);display:none;gap:0;flex-direction:column;min-width:140px;z-index:1000;padding:6px}';
        echo '.temf-storage-switcher.show{display:flex}';
        echo '.temf-storage-btn{padding:10px 12px;border:none;background:#f7f7f7;cursor:pointer;font-size:13px;color:#1f1f1f;text-decoration:none;display:flex;align-items:center;gap:6px;transition:all 0.2s;border-radius:3px;margin:2px 0;}';
        echo '.temf-storage-btn:hover{background:#e9e9e9;color:#000}';
        echo '.temf-storage-btn.active{background:#1f1f1f;color:#fff;box-shadow:none;}';
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
        
        // 新的切换覆盖层样式
        echo '.temf-switching-overlay{position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.96);backdrop-filter:saturate(140%) blur(1px);display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:200px;z-index:10;pointer-events:none}';
        echo '.temf-switching-spinner{width:32px;height:32px;border:3px solid #f3f3f3;border-top:3px solid #555;border-radius:50%;animation:temf-spin 1s linear infinite;margin-bottom:16px}';
        echo '.temf-switching-text{color:#666;font-size:14px;text-align:center}';
        echo '@media (max-width: 640px){';
        echo '.temf-dialog{width:96%;max-width:96%;top:6%;}';
        echo '.temf-header{flex-direction:column;align-items:stretch;gap:12px;}';
        echo '.temf-title-container,.temf-header>strong{width:100%;text-align:left;}';
        echo '.temf-actions-bar{width:100%;flex-direction:column;gap:10px;}';
        echo '.temf-select-group,.temf-button-group{width:100%;gap:8px;flex-wrap:nowrap;}';
        echo '.temf-select-group select{flex:1 1 auto;min-width:0;}';
        echo '.temf-button-group{justify-content:flex-start;}';
        echo '.temf-button-group .btn{flex:1 1 auto;min-width:0;}';
        echo '}';
        
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
                    $directory = isset($item['directory']) ? trim(str_replace('\\', '/', $item['directory']), '/') : '';
                    return [
                        'url' => $item['url'],
                        'name' => $item['name'],
                        'mtime' => $item['mtime'],
                        'directory' => $directory
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
