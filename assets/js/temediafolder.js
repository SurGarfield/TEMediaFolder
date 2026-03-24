/**
 * TEMediaFolder JavaScript
 * 媒体文件夹插件前端交互
 */
(function () {
    'use strict';

    // 防止与其他插件冲突的兼容性检查
    if (typeof window.TEMediaFolder !== 'undefined') {
        // already initialized
        return;
    }

    // 标记插件已初始化
    window.TEMediaFolder = { initialized: true };

    // 检查必要的全局配置
    if (typeof TEMF_CONF === 'undefined') {
        // missing config
        return;
    }

    function byId(id) {
        try {
            return document.getElementById(id);
        } catch (e) {
            // getElementById error
            return null;
        }
    }

    function getSelectHolder(id) {
        var el = byId(id);
        if (!el) return null;
        if (typeof el.closest === 'function') {
            return el.closest('.temf-select-holder');
        }
        return null;
    }

    function formatFileSize(bytes) {
        var value = Number(bytes);
        if (!isFinite(value) || value <= 0) {
            return '';
        }

        var units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        var index = 0;

        while (value >= 1024 && index < units.length - 1) {
            value = value / 1024;
            index++;
        }

        if (index === 0) {
            return Math.round(value) + ' ' + units[index];
        }

        var precision = value >= 100 ? 0 : (value >= 10 ? 1 : 2);
        var formatted = value.toFixed(precision);
        if (precision > 0) {
            formatted = formatted.replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '');
        }

        return formatted + ' ' + units[index];
    }

    function formatDirectoryLabel(directoryValue, sizeValue) {
        var formattedSize = formatFileSize(sizeValue);
        return formattedSize || '大小未知';
    }

    function parseSizeCandidate(value) {
        if (value == null) {
            return 0;
        }

        if (typeof value === 'number') {
            return value > 0 && isFinite(value) ? value : 0;
        }

        if (typeof value === 'string') {
            var cleaned = value.trim();
            if (cleaned === '') {
                return 0;
            }

            cleaned = cleaned.replace(/,/g, '');

            if (/^\d+$/.test(cleaned)) {
                var intVal = parseInt(cleaned, 10);
                return isFinite(intVal) && intVal > 0 ? intVal : 0;
            }

            var match = cleaned.match(/^([0-9]+(?:\.[0-9]+)?)\s*([a-zA-Z]{1,4})?$/);
            if (match) {
                var base = parseFloat(match[1]);
                if (!isFinite(base) || base <= 0) {
                    return 0;
                }

                var unitKey = (match[2] || 'b').toLowerCase();
                var unitMap = {
                    b: 1,
                    byte: 1,
                    bytes: 1,
                    k: 1024,
                    kb: 1024,
                    kib: 1024,
                    m: 1024 * 1024,
                    mb: 1024 * 1024,
                    mib: 1024 * 1024,
                    g: 1024 * 1024 * 1024,
                    gb: 1024 * 1024 * 1024,
                    gib: 1024 * 1024 * 1024,
                    t: Math.pow(1024, 4),
                    tb: Math.pow(1024, 4),
                    tib: Math.pow(1024, 4),
                    p: Math.pow(1024, 5),
                    pb: Math.pow(1024, 5),
                    pib: Math.pow(1024, 5)
                };

                var multiplier = unitMap[unitKey];
                if (multiplier) {
                    return base * multiplier;
                }
            }

            var fallback = parseFloat(cleaned);
            if (isFinite(fallback) && fallback > 0) {
                return fallback;
            }
        }

        return 0;
    }

    function resolveFileSize(meta) {
        if (!meta || typeof meta !== 'object') {
            return 0;
        }

        var keys = ['size', 'fileSize', 'filesize', 'sizeReadable', 'size_readable', 'sizeHuman', 'size_human'];
        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            if (Object.prototype.hasOwnProperty.call(meta, key)) {
                var parsed = parseSizeCandidate(meta[key]);
                if (parsed > 0) {
                    return parsed;
                }
            }
        }

        return 0;
    }

    function toggleDeleteButtons(showDelete) {
        var buttons = document.querySelectorAll('[data-temf-copy]');
        buttons.forEach(function (btn) {
            var url = btn.getAttribute('data-url');
            var shouldDelete = showDelete && url && state.selected.has(url);

            if (shouldDelete) {
                if (!btn.dataset.originalLabel) {
                    btn.dataset.originalLabel = btn.textContent;
                }
                btn.textContent = '删除';
                btn.classList.add('temf-delete-btn');
                btn.setAttribute('data-temf-delete', 'true');
            } else {
                if (btn.dataset.originalLabel) {
                    btn.textContent = btn.dataset.originalLabel;
                }
                btn.classList.remove('temf-delete-btn');
                btn.removeAttribute('data-temf-delete');
            }
        });
    }

    function getMetaForUrl(url) {
        if (!url) {
            return {};
        }

        if (state.selectedMeta && state.selectedMeta.has(url)) {
            return state.selectedMeta.get(url) || {};
        }

        var item = findItemByUrl(url);
        if (item) {
            var checkbox = item.querySelector('.temf-pick');
            if (checkbox) {
                return {
                    id: checkbox.getAttribute('data-meta-id') || null
                };
            }
        }

        return {};
    }

    function performDelete(urls) {
        if (!urls || urls.length === 0) {
            return Promise.resolve({ ok: true });
        }

        var endpoint = getDeleteEndpoint(urls[0]);
        if (!endpoint) {
            return Promise.resolve({ ok: false, msg: '未配置删除接口' });
        }

        var body = new URLSearchParams();
        if (endpoint.storage) {
            body.append('storage_type', endpoint.storage);
        }
        urls.forEach(function (url, index) {
            body.append('file_urls[]', url);
            if (index === 0) {
                body.append('file_url', url);
            }
            var meta = getMetaForUrl(url);
            if (meta && meta.id) {
                body.append('file_ids[]', meta.id);
            }
        });

        return fetch(endpoint.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (res) {
            return res.text().then(function (text) {
                if (!text) {
                    return { ok: false, msg: 'EMPTY_RESPONSE' };
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    return { ok: false, msg: 'INVALID_JSON:' + e.message };
                }
            });
        });
    }

    function insertAtCursor(field, text) {
        if (!field) {
            // no field
            return;
        }

        try {
            var start = field.selectionStart;
            var end = field.selectionEnd;
            var val = field.value;

            if (typeof start === "number" && typeof end === "number") {
                field.value = val.slice(0, start) + text + val.slice(end);
                field.selectionStart = field.selectionEnd = start + text.length;
            } else {
                field.value += text;
            }

            field.dispatchEvent(new Event("input", { bubbles: true }));
            field.focus();
        } catch (e) {
            // insertAtCursor error
            // 降级处理：直接在末尾添加文本
            try {
                field.value += text;
                field.focus();
            } catch (fallbackError) {
                // insert fallback error
            }
        }
    }

    function getEditor() {
        return document.getElementById("text");
    }

    var state = {
        cosLoaded: false,
        ossLoaded: false,
        bitifulLoaded: false,
        upyunLoaded: false,
        lskyLoaded: false,
        multiLoaded: false,
        currentStorage: null,
        availableStorages: [],
        selected: new Set(),
        selectedMeta: new Map(),
        // 请求缓存机制 - 避免重复请求
        requestCache: {},
        cacheTimeout: 5 * 60 * 1000, // 5分钟缓存
        // 分页状态
        pagination: {
            currentPage: 1,
            pageSize: 0,   // 动态计算（行数 × 列数）
            totalItems: 0,
            allFiles: []   // 存储所有文件
        },
        remotePagination: {
            enabled: false,
            storage: '',
            path: '',
            currentToken: '',
            nextToken: '',
            hasMore: false,
            pageSize: 0,
            pageNumber: 1,
            history: []
        },
        folderStatsCache: {},
        currentFolders: [],
        currentPath: '',
        rootFolderHints: {}
    };

    var DEFAULT_THUMB_SIZE = 120;
    var lazyInitTimer = null;
    var folderStatsTimer = null;

    var customSelects = (function () {
        var registry = new Map();

        function closeAll(exceptId) {
            registry.forEach(function (info, id) {
                if (exceptId && id === exceptId) {
                    return;
                }
                info.holder.classList.remove('open');
                info.trigger.setAttribute('aria-expanded', 'false');
            });
        }

        function focusOption(info, strategy) {
            if (!info) return;
            var options = Array.from(info.dropdown.querySelectorAll('.temf-select-option'));
            if (!options.length) return;

            var target = null;
            if (strategy === 'selected') {
                target = options.find(function (btn) { return btn.classList.contains('selected'); });
            } else if (strategy === 'last') {
                target = options[options.length - 1];
            }
            if (!target) {
                target = options[0];
            }
            if (target && typeof target.focus === 'function') {
                target.focus();
            }
        }

        document.addEventListener('click', function (e) {
            var target = e.target;
            if (!target) {
                closeAll();
                return;
            }
            if (typeof target.closest !== 'function') {
                closeAll();
                return;
            }
            if (!target.closest('.temf-select-holder')) {
                closeAll();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAll();
            }
        });

        return {
            initAll: function () {
                var holders = document.querySelectorAll('.temf-select-holder');
                holders.forEach(function (holder) {
                    var select = holder.querySelector('select');
                    if (!select || !select.id) {
                        return;
                    }
                    this.initSelect(select);
                    if (holder.getAttribute('data-initial-hidden') === 'true') {
                        holder.classList.add('hidden');
                        this.setDisabled(select.id, true);
                    }
                }, this);
            },

            initSelect: function (select) {
                if (!select || !select.id || registry.has(select.id)) {
                    return;
                }

                var holder = select.closest('.temf-select-holder');
                if (!holder) {
                    return;
                }

                var trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.className = 'temf-select-trigger';
                trigger.setAttribute('data-target', select.id);
                trigger.setAttribute('aria-haspopup', 'listbox');
                trigger.setAttribute('aria-expanded', 'false');
                trigger.setAttribute('tabindex', '0');

                var label = document.createElement('span');
                label.className = 'temf-select-label';
                label.textContent = '请选择';

                var caret = document.createElement('span');
                caret.className = 'temf-select-caret';

                trigger.appendChild(label);
                trigger.appendChild(caret);
                holder.appendChild(trigger);

                var dropdown = document.createElement('div');
                dropdown.className = 'temf-select-dropdown';
                dropdown.setAttribute('role', 'listbox');
                holder.appendChild(dropdown);

                var self = this;

                trigger.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    if (select.disabled) {
                        return;
                    }
                    if (holder.classList.contains('open')) {
                        self.close(select.id);
                    } else {
                        self.open(select.id);
                    }
                });

                trigger.addEventListener('keydown', function (ev) {
                    if (select.disabled) {
                        return;
                    }
                    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                        ev.preventDefault();
                        self.open(select.id);
                        focusOption(registry.get(select.id), ev.key === 'ArrowUp' ? 'last' : 'selected');
                    } else if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        self.open(select.id);
                        focusOption(registry.get(select.id), 'selected');
                    }
                });

                holder.addEventListener('keydown', function (ev) {
                    if (!holder.classList.contains('open')) {
                        return;
                    }
                    var info = registry.get(select.id);
                    if (!info) return;

                    var options = Array.from(info.dropdown.querySelectorAll('.temf-select-option'));
                    if (!options.length) {
                        return;
                    }

                    var currentIndex = options.indexOf(document.activeElement);
                    if (ev.key === 'ArrowDown') {
                        ev.preventDefault();
                        var nextIndex = currentIndex >= 0 ? Math.min(currentIndex + 1, options.length - 1) : 0;
                        options[nextIndex].focus();
                    } else if (ev.key === 'ArrowUp') {
                        ev.preventDefault();
                        var prevIndex = currentIndex >= 0 ? Math.max(currentIndex - 1, 0) : options.length - 1;
                        options[prevIndex].focus();
                    } else if (ev.key === 'Home') {
                        ev.preventDefault();
                        options[0].focus();
                    } else if (ev.key === 'End') {
                        ev.preventDefault();
                        options[options.length - 1].focus();
                    } else if (ev.key === 'Enter' || ev.key === ' ') {
                        if (document.activeElement && document.activeElement.classList.contains('temf-select-option')) {
                            ev.preventDefault();
                            document.activeElement.click();
                        }
                    } else if (ev.key === 'Escape') {
                        ev.preventDefault();
                        self.close(select.id);
                        trigger.focus();
                    }
                });

                registry.set(select.id, {
                    select: select,
                    holder: holder,
                    trigger: trigger,
                    label: label,
                    dropdown: dropdown
                });

                this.sync(select.id);
                this.setDisabled(select.id, select.disabled);
            },

            sync: function (id) {
                var info = registry.get(id);
                if (!info) {
                    return;
                }
                var select = info.select;
                var dropdown = info.dropdown;
                dropdown.innerHTML = '';

                var options = Array.from(select.options);
                if (!options.length) {
                    var empty = document.createElement('div');
                    empty.className = 'temf-select-empty';
                    empty.textContent = '暂无选项';
                    dropdown.appendChild(empty);
                } else {
                    options.forEach(function (option) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'temf-select-option';
                        btn.setAttribute('data-value', option.value);
                        btn.setAttribute('role', 'option');
                        btn.setAttribute('tabindex', '-1');
                        btn.textContent = option.textContent || option.value || '-';
                        if (option.disabled) {
                            btn.disabled = true;
                            btn.setAttribute('aria-disabled', 'true');
                        }
                        if (option.selected) {
                            btn.classList.add('selected');
                        }
                        btn.addEventListener('click', function (ev) {
                            ev.preventDefault();
                            ev.stopPropagation();
                            if (option.disabled || select.disabled) {
                                return;
                            }
                            customSelects.selectOption(id, option.value, true);
                        });
                        dropdown.appendChild(btn);
                    });
                }

                this.updateLabel(id);
            },

            updateLabel: function (id) {
                var info = registry.get(id);
                if (!info) {
                    return;
                }
                var select = info.select;
                var label = info.label;
                var selectedOption = select.options[select.selectedIndex];
                label.textContent = selectedOption ? (selectedOption.textContent || selectedOption.value || '请选择') : '请选择';

                var buttons = info.dropdown.querySelectorAll('.temf-select-option');
                buttons.forEach(function (btn) {
                    var value = btn.getAttribute('data-value');
                    var isSelected = selectedOption && value === selectedOption.value;
                    btn.classList.toggle('selected', isSelected);
                    if (isSelected) {
                        btn.setAttribute('aria-selected', 'true');
                    } else {
                        btn.removeAttribute('aria-selected');
                    }
                });
            },

            selectOption: function (id, value, dispatchEvent) {
                var info = registry.get(id);
                if (!info) return;
                var select = info.select;
                var previous = select.value;
                select.value = value;
                this.updateLabel(id);
                this.close(id);
                if (dispatchEvent && previous !== value) {
                    var evt = new Event('change', { bubbles: true });
                    select.dispatchEvent(evt);
                }
            },

            setValue: function (id, value, options) {
                var info = registry.get(id);
                if (!info) return;
                var select = info.select;
                var previous = select.value;
                select.value = value;
                this.updateLabel(id);
                if (!(options && options.silent) && previous !== value) {
                    var evt = new Event('change', { bubbles: true });
                    select.dispatchEvent(evt);
                }
            },

            setDisabled: function (id, disabled) {
                var info = registry.get(id);
                if (!info) return;
                var isDisabled = !!disabled;
                info.select.disabled = isDisabled;
                info.holder.dataset.disabled = isDisabled ? 'true' : 'false';
                info.trigger.setAttribute('aria-disabled', isDisabled ? 'true' : 'false');
                if (isDisabled) {
                    this.close(id);
                }
            },

            open: function (id) {
                var info = registry.get(id);
                if (!info || info.select.disabled) return;
                closeAll(id);
                info.holder.classList.add('open');
                info.trigger.setAttribute('aria-expanded', 'true');
                focusOption(info, 'selected');
            },

            close: function (id) {
                var info = registry.get(id);
                if (!info) return;
                info.holder.classList.remove('open');
                info.trigger.setAttribute('aria-expanded', 'false');
            },

            closeAll: function () {
                closeAll();
            }
        };
    })();

    function setSelectVisibility(id, visible) {
        var holder = getSelectHolder(id);
        if (!holder) return;
        if (visible) {
            holder.classList.remove('hidden');
            customSelects.setDisabled(id, false);
        } else {
            holder.classList.add('hidden');
            customSelects.setDisabled(id, true);
        }
    }

    function normalizeDirectoryPath(path) {
        if (!path && path !== 0) {
            return '';
        }
        var value = String(path);
        if (!value) return '';
        value = value.replace(/\\/g, '/');
        var queryIndex = value.indexOf('?');
        if (queryIndex !== -1) {
            value = value.slice(0, queryIndex);
        }
        value = value.replace(/\/+/g, '/');
        value = value.replace(/^\/+/, '').replace(/\/+$/, '');
        if (!value) return '';
        var parts = value.split('/');
        var uploadsIndex = parts.lastIndexOf('uploads');
        if (uploadsIndex !== -1 && uploadsIndex < parts.length - 1) {
            return parts.slice(uploadsIndex + 1).join('/');
        }
        return value;
    }

    function setCurrentPath(path) {
        state.currentPath = normalizeDirectoryPath(path);
        updateBreadcrumb();
    }

    function getEffectiveStorageSource() {
        var source = TEMF_CONF.source;
        if (isMultiMode() && state.currentStorage) {
            source = state.currentStorage;
        }
        return String(source || '').toLowerCase();
    }

    function isMultiMode() {
        return String(TEMF_CONF.source || '').toLowerCase() === 'multi';
    }

    function isServerPagedStorage(source) {
        var entry = storageRegistry[String(source || '').toLowerCase()];
        return !!(entry && entry.serverPaged);
    }

    function resetRemotePagination(storage, path, options) {
        options = options || {};
        state.remotePagination.enabled = !!options.enabled;
        state.remotePagination.storage = String(storage || '').toLowerCase();
        state.remotePagination.path = normalizeDirectoryPath(path || '');
        state.remotePagination.currentToken = options.currentToken || '';
        state.remotePagination.nextToken = options.nextToken || '';
        state.remotePagination.hasMore = !!options.hasMore;
        state.remotePagination.pageSize = options.pageSize || 0;
        state.remotePagination.pageNumber = options.pageNumber || 1;
        state.remotePagination.history = Array.isArray(options.history) ? options.history.slice() : [];
    }

    function isHierarchicalStorage(source) {
        var entry = storageRegistry[String(source || '').toLowerCase()];
        return !!(entry && entry.hierarchical);
    }

    function isMonthPath(path) {
        return /^\d{4}\/\d{2}$/.test(normalizeDirectoryPath(path || ''));
    }

    function getParentPath(path, source) {
        var normalized = normalizeDirectoryPath(path || '');
        if (!normalized) return '';
        if (isLocalStorage(source) && isMonthPath(normalized)) {
            return '';
        }
        var parts = normalized.split('/');
        parts.pop();
        return parts.join('/');
    }

    function parseLocalYearMonthFromPath(path) {
        var normalized = normalizeDirectoryPath(path || '');
        var match = normalized.match(/^(\d{4})\/(\d{2})(?:\/|$)/);
        if (!match) return null;
        return { year: match[1], month: match[2] };
    }

    function updateBreadcrumb() {
        var el = byId('temf-breadcrumb');
        if (!el) return;

        function esc(text) {
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        var source = getEffectiveStorageSource();
        if (!isHierarchicalStorage(source)) {
            el.style.display = 'none';
            el.innerHTML = '';
            return;
        }

        var path = normalizeDirectoryPath(state.currentPath || '');
        var parts = path ? path.split('/') : [];
        var html = [];

        html.push('<button type="button" class="temf-crumb' + (parts.length === 0 ? ' active' : '') + '" data-temf-crumb="1" data-path="">/</button>');
        var startIndex = 0;
        if (parts.length > 2) {
            var collapsedPath = parts.slice(0, parts.length - 2).join('/');
            html.push('<span class="temf-crumb-sep">/</span>');
            html.push('<button type="button" class="temf-crumb" data-temf-crumb="1" data-path="' + esc(collapsedPath) + '">...</button>');
            startIndex = parts.length - 2;
        }

        var chain = [];
        for (var i = 0; i < parts.length; i++) {
            chain.push(parts[i]);
            if (i < startIndex) {
                continue;
            }
            html.push('<span class="temf-crumb-sep">/</span>');
            var chainPath = chain.join('/');
            html.push('<button type="button" class="temf-crumb' + (i === parts.length - 1 ? ' active' : '') + '" data-temf-crumb="1" data-path="' + esc(chainPath) + '">' + esc(parts[i]) + '</button>');
        }

        el.innerHTML = html.join('');
        el.style.display = 'flex';
    }

    function getNowYearMonth() {
        var now = new Date();
        return {
            year: String(now.getFullYear()),
            month: String(now.getMonth() + 1).padStart(2, '0')
        };
    }

    function extractFileDirectory(file) {
        if (!file || typeof file !== 'object') {
            return '';
        }
        var dir = file.directory || file.dir || file.path || file.folder || '';
        var normalized = normalizeDirectoryPath(dir);
        if (normalized) {
            return normalized;
        }

        var url = file.url || file.thumbnail || '';
        if (url) {
            var pathname = '';
            try {
                pathname = new URL(url, window.location.origin).pathname;
            } catch (e) {
                var doubleSlash = url.indexOf('//');
                if (doubleSlash !== -1) {
                    var start = url.indexOf('/', doubleSlash + 2);
                    pathname = start !== -1 ? url.slice(start) : url;
                } else {
                    pathname = url;
                }
            }
            pathname = pathname || '';
            var withoutQuery = pathname.split('?')[0];
            var segments = withoutQuery.replace(/\\/g, '/').split('/');
            if (segments.length > 1) {
                segments.pop();
                var joined = segments.join('/');
                normalized = normalizeDirectoryPath(joined);
                if (normalized) {
                    return normalized;
                }
            }
        }

        return '';
    }

    var deleteOps = {
        running: false,
        start: function (urls) {
            if (this.running) {
                return;
            }
            urls = urls && urls.length ? urls : Array.from(state.selected);
            if (!urls || urls.length === 0) {
                return;
            }

            if (!window.confirm('确定要删除选中的图片吗？此操作不可恢复。')) {
                return;
            }

            this.running = true;
            performDelete(urls)
                .then(function (result) {
                    if (!result || result.ok === false) {
                        var msg = (result && result.msg) ? result.msg : '删除失败';
                        window.alert(msg);
                        return;
                    }

                    invalidateDirectoryCaches();
                    var removedSet = new Set(urls);
                    state.pagination.allFiles = (state.pagination.allFiles || []).filter(function (file) {
                        return !removedSet.has(file.url);
                    });

                    urls.forEach(function (url) {
                        var item = findItemByUrl(url);
                        if (item && item.parentNode) {
                            item.parentNode.removeChild(item);
                        }
                        state.selected.delete(url);
                        state.selectedMeta.delete(url);
                        removeFromLocalData(url);
                    });

                    selection.updateButton();
                    ui.renderCurrentPage();
                })
                .catch(function (err) {
                    window.alert('删除失败: ' + (err && err.message ? err.message : err));
                })
                .finally(function () {
                    deleteOps.running = false;
                });
        }
    };

    // 暴露到全局作用域供调试使用
    window.TEMF_STATE = state;

    /**
     * 防抖函数
     * 延迟执行函数，如果在延迟期间再次调用，则重新计时
     */
    function debounce(func, wait) {
        var timeout;
        return function () {
            var context = this;
            var args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function () {
                func.apply(context, args);
            }, wait);
        };
    }

    /**
     * 节流函数
     * 限制函数执行频率，在指定时间内最多执行一次
     */
    function throttle(func, limit) {
        var inThrottle;
        return function () {
            var context = this;
            var args = arguments;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(function () {
                    inThrottle = false;
                }, limit);
            }
        };
    }

    /**
     * 带缓存的 fetch 函数
     * 自动缓存 GET 请求，避免重复请求
     */
    function cachedFetch(url, options) {
        options = options || {};
        var fetchOptions = Object.assign({}, options);
        var bustCache = !!fetchOptions.bustCache;
        delete fetchOptions.bustCache;

        var method = fetchOptions.method || 'GET';
        var cacheKey = method + ':' + url;

        if (method === 'GET' && !fetchOptions.cache) {
            fetchOptions.cache = 'no-store';
        }

        // 只缓存 GET 请求
        if (method === 'GET') {
            if (bustCache) {
                delete state.requestCache[cacheKey];
            } else {
                var cached = state.requestCache[cacheKey];
                if (cached) {
                    var now = Date.now();
                    // 检查缓存是否过期
                    if (now - cached.timestamp < state.cacheTimeout) {
                        return Promise.resolve(cached.data);
                    }
                    // 缓存过期，删除
                    delete state.requestCache[cacheKey];
                }
            }
        }

        // 发起请求
        return fetch(url, fetchOptions)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text().then(function (text) {
                    var normalized = (text || '').trim();
                    if (!normalized) {
                        return {};
                    }

                    if (normalized.charAt(0) !== '{' && normalized.charAt(0) !== '[') {
                        throw new Error('Non-JSON response: ' + normalized.substring(0, 120));
                    }

                    try {
                        return JSON.parse(normalized);
                    } catch (e) {
                        throw new Error('Invalid JSON: ' + normalized.substring(0, 120));
                    }
                });
            })
            .then(function (data) {
                // 缓存 GET 请求的成功响应
                if (method === 'GET' && data && data.ok !== false) {
                    state.requestCache[cacheKey] = {
                        data: data,
                        timestamp: Date.now()
                    };
                }
                return data;
            })
            .catch(function (error) {
                console.error('[TEMF] 请求失败:', url, error);
                throw error;
            });
    }

    function invalidateDirectoryCaches() {
        state.requestCache = {};
        state.folderStatsCache = {};
    }

    function rememberRootFolderHint(storageType, directoryPath) {
        var storage = String(storageType || '').toLowerCase();
        var normalized = normalizeDirectoryPath(directoryPath || '');
        if (!storage || !normalized || normalized.indexOf('/') === -1 || !isCloudDirectoryStorage(storage)) {
            return;
        }

        var rootFolder = normalized.split('/')[0];
        if (!rootFolder) {
            return;
        }

        if (!state.rootFolderHints[storage]) {
            state.rootFolderHints[storage] = {};
        }
        state.rootFolderHints[storage][rootFolder] = true;
    }

    function buildCloudPageRequestOptions(storageType, path, options) {
        var requestOptions = Object.assign({}, options || {});
        var normalizedStorage = String(storageType || '').toLowerCase();
        var normalizedPath = normalizeDirectoryPath(path || '');

        if (isServerPagedStorage(normalizedStorage)) {
            var pageSize = parseInt(requestOptions.pageSize, 10);
            if (!pageSize || pageSize <= 0) {
                pageSize = calculatePageSize();
            }
            requestOptions.pageSize = Math.max(1, pageSize);
            requestOptions.pageToken = typeof requestOptions.pageToken === 'string' ? requestOptions.pageToken : '';
        } else {
            delete requestOptions.pageSize;
            delete requestOptions.pageToken;
        }

        requestOptions._normalizedPath = normalizedPath;
        return requestOptions;
    }

    function buildFoldersOnlyUrl(base, path, extraParams, bustCache) {
        var parts = [];
        if (path) {
            parts.push('temf_path=' + encodeURIComponent(path));
        }
        if (Array.isArray(extraParams) && extraParams.length) {
            parts = parts.concat(extraParams);
        }
        parts.push('temf_folders_only=1');
        if (bustCache) {
            parts.push('_ts=' + Date.now());
        }
        return base + (base.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
    }

    function mergeFoldersOnlyResult(base, path, data, callback, options) {
        options = options || {};
        var storageType = options.storageType || '';
        if (!isServerPagedStorage(storageType) || options.foldersOnly) {
            callback && callback(data);
            return;
        }

        var folderUrl = buildFoldersOnlyUrl(base, path, options.extraParams || [], !!options.bustCache);
        cachedFetch(folderUrl, { bustCache: !!options.bustCache, cache: 'no-store' })
            .then(function (folderData) {
                if (folderData && Array.isArray(folderData.folders) && folderData.folders.length) {
                    data.folders = folderData.folders;
                }
                callback && callback(data);
            })
            .catch(function () {
                callback && callback(data);
            });
    }

    function resetCloudRootState(storageType) {
        setCurrentPath('');
        resetRemotePagination(storageType, '', { enabled: isServerPagedStorage(storageType) });

        var dir = byId('temf-dir');
        if (dir) {
            dir.value = '';
            if (typeof customSelects !== 'undefined' && customSelects && typeof customSelects.setValue === 'function') {
                customSelects.setValue('temf-dir', '', { silent: true });
            }
        }
    }

    function getMeasuredGridColumns() {
        var body = document.querySelector('#temf-modal .temf-body');
        if (!body || body.clientWidth <= 0) {
            return 0;
        }

        var probe = document.createElement('ul');
        probe.className = 'temf-grid';
        probe.style.position = 'absolute';
        probe.style.visibility = 'hidden';
        probe.style.pointerEvents = 'none';
        probe.style.left = '0';
        probe.style.right = '0';
        probe.style.top = '0';
        probe.style.width = '100%';

        var item = document.createElement('li');
        item.className = 'temf-item';
        probe.appendChild(item);
        body.appendChild(probe);

        var styles = window.getComputedStyle(probe);
        var columns = 0;
        var template = styles.gridTemplateColumns || '';
        if (template && template !== 'none') {
            columns = template.split(' ').filter(function (part) {
                return !!String(part).trim();
            }).length;
        }

        if (probe.parentNode) {
            probe.parentNode.removeChild(probe);
        }

        return Math.max(0, columns);
    }

    /**
     * 动态计算每页显示的图片数量
     * 基于容器宽度、缩略图大小和配置的行数
     */
    function calculatePageSize() {
        var rows = parseInt(TEMF_CONF.paginationRows) || 4;  // 默认4行
        var cols = getMeasuredGridColumns();
        if (!cols || cols <= 0) {
            var body = document.querySelector('#temf-modal .temf-body');
            if (!body || body.clientWidth <= 0) {
                return rows * 4;
            }
            cols = Math.max(1, Math.floor((body.clientWidth - 24) / 132));
        }

        // 总数 = 行数 × 列数
        var pageSize = rows * cols;
        return Math.max(pageSize, cols); // 至少一行
    }

    function findItemByUrl(url) {
        if (!url) {
            return null;
        }
        var nodes = document.querySelectorAll('.temf-item');
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].getAttribute('data-url') === url) {
                return nodes[i];
            }
        }
        return null;
    }

    function removeFromLocalData(url) {
        if (!url || !TEMF_CONF || !TEMF_CONF.data) {
            return;
        }
        var yearKeys = Object.keys(TEMF_CONF.data);
        for (var i = 0; i < yearKeys.length; i++) {
            var year = yearKeys[i];
            var monthMap = TEMF_CONF.data[year];
            var months = Object.keys(monthMap);
            for (var j = 0; j < months.length; j++) {
                var month = months[j];
                monthMap[month] = monthMap[month].filter(function (item) {
                    return item.url !== url;
                });
                if (monthMap[month].length === 0) {
                    delete monthMap[month];
                }
            }
            if (Object.keys(monthMap).length === 0) {
                delete TEMF_CONF.data[year];
            }
        }
    }

    var modal = {
        open: function () {
            try {
                var m = byId("temf-modal");
                if (!m) {
                    // modal not found
                    return;
                }

                m.classList.add("open");
                m.removeAttribute("inert");
                m.setAttribute("aria-hidden", "false");

                var title = byId("temf-title");
                if (title) {
                    if (!title.hasAttribute("tabindex")) {
                        title.setAttribute("tabindex", "-1");
                    }
                    title.focus();
                }

                if (isMultiMode() && !state.multiLoaded) {
                    multi.init();
                    state.multiLoaded = true;
                } else if (!isMultiMode()) {
                    ensureSingleStorageLoaded(TEMF_CONF.source);
                }

                dragUpload.init();
            } catch (e) {
                // open modal error
            }
        },

        close: function () {
            try {
                var m = byId("temf-modal");
                if (!m) {
                    // modal not found
                    return;
                }

                m.classList.remove("open");
                m.setAttribute("aria-hidden", "true");
                m.setAttribute("inert", "");
                dragUpload.hideDropzone();
                if (upload && typeof upload.cancelStagePanel === 'function') {
                    upload.cancelStagePanel();
                }

                if (document.activeElement && m.contains(document.activeElement)) {
                    document.activeElement.blur();
                }

                var trigger = byId("temf-open");
                if (trigger) {
                    trigger.focus();
                }
            } catch (e) {
                // close modal error
            }
        }
    };

    function getDeleteEndpoint(url) {
        var sec = byId('temediafolder');
        if (!sec || !url) {
            return null;
        }

        var source = TEMF_CONF.source;
        if (isMultiMode()) {
            var storage = state.currentStorage;
            if (!storage) {
                return null;
            }
            if (!sec.getAttribute('data-multi-delete')) {
                return null;
            }
            return {
                url: sec.getAttribute('data-multi-delete'),
                storage: storage
            };
        }

        var entry = storageRegistry[source];
        if (!entry || !entry.deleteAttr) {
            return null;
        }

        var deleteUrl = sec.getAttribute(entry.deleteAttr);
        if (deleteUrl) {
            return {
                url: deleteUrl,
                storage: source
            };
        }

        return null;
    }

    function getStorageUploadAttr(storageType) {
        var entry = storageRegistry[storageType];
        return entry && entry.uploadAttr ? entry.uploadAttr : '';
    }

    function getStorageEntry(storageType) {
        return storageRegistry[String(storageType || '').toLowerCase()] || null;
    }

    function isLocalStorage(storageType) {
        return String(storageType || '').toLowerCase() === 'local';
    }

    function isLskyStorage(storageType) {
        return String(storageType || '').toLowerCase() === 'lsky';
    }

    function isCloudDirectoryStorage(storageType) {
        var entry = getStorageEntry(storageType);
        return !!(entry && entry.cloudDirectory);
    }

    function getThumbnailMode(storageType) {
        var entry = getStorageEntry(storageType);
        return entry && entry.thumbnailMode ? entry.thumbnailMode : 'none';
    }

    function getStorageRenameMeta(storageType) {
        var entry = storageRegistry[storageType];
        return {
            attr: entry && entry.renameAttr ? entry.renameAttr : '',
            supported: !!(entry && entry.supportsRename)
        };
    }

    function getComputedUploadPath(storageType, path) {
        var normalized = normalizeDirectoryPath(path || '');
        if (!TEMF_CONF.networkYearMonthFolders || isLocalStorage(storageType) || isLskyStorage(storageType)) {
            return normalized ? '/' + normalized : '/';
        }

        var now = new Date();
        var yearMonth = now.getFullYear() + '/' + String(now.getMonth() + 1).padStart(2, '0');
        return '/' + yearMonth;
    }

    function createLocalSelectorModule(localDataApi, localBrowserApi) {
        return {
        buildYearMonth: function () {
            try {
                var ySel = byId("temf-year");
                var mSel = byId("temf-month");
                if (!ySel || !mSel) {
                    // selector not found
                    return;
                }

                ySel.innerHTML = '';
                mSel.innerHTML = '';

                // 确保数据存在
                if (!TEMF_CONF.data || typeof TEMF_CONF.data !== 'object') {
                    // no local data
                    return;
                }

                var years = Object.keys(TEMF_CONF.data).sort(function (a, b) {
                    return b - a;
                });

                var nowYM = getNowYearMonth();
                if (years.indexOf(nowYM.year) === -1) {
                    years.push(nowYM.year);
                    years.sort(function (a, b) {
                        return b - a;
                    });
                }

                if (years.length === 0) {
                    // no years data
                    return;
                }

                years.forEach(function (year) {
                    var opt = document.createElement('option');
                    opt.value = year;
                    opt.textContent = year;
                    ySel.appendChild(opt);
                });
                customSelects.sync('temf-year');

                var latest = TEMF_CONF.latest ? TEMF_CONF.latest.split('-') : null;
                var curYear = nowYM.year;
                customSelects.setValue('temf-year', curYear, { silent: true });

            this.buildMonths(curYear);

                if (nowYM && nowYM.month) {
                    customSelects.setValue('temf-month', nowYM.month, { silent: true });
                } else if (latest && latest.length > 1) {
                    customSelects.setValue('temf-month', latest[1], { silent: true });
                }
            } catch (e) {
                // build selectors error
            }
        },

        buildMonths: function (year) {
            var mSel = byId("temf-month");
            if (!mSel) return;

            mSel.innerHTML = '';
            var months = Object.keys(TEMF_CONF.data[year] || {}).sort().reverse();

            var nowYM = getNowYearMonth();
            if (String(year) === nowYM.year && months.indexOf(nowYM.month) === -1) {
                months.push(nowYM.month);
                months.sort().reverse();
            }

            months.forEach(function (month) {
                var opt = document.createElement('option');
                opt.value = month;
                opt.textContent = month;
                mSel.appendChild(opt);
            });

            customSelects.sync('temf-month');
        },

        renderCurrentMonth: function () {
            try {
                var ySel = byId("temf-year");
                var mSel = byId("temf-month");
                var body = document.querySelector('#temf-modal .temf-body');

                if (!ySel || !mSel || !body) {
                    // required elements missing
                    return;
                }

                var year = ySel.value;
                var month = mSel.value;

                if (!year || !month) {
                    // ym not selected
                    return;
                }

                setCurrentPath(year + '/' + month);

                // 零填充月份以匹配后端标准化的数据键（如 "1" -> "01"）
                var paddedMonth = month.length === 1 ? '0' + month : month;

                var items = [];
                if (TEMF_CONF.data && TEMF_CONF.data[year] && TEMF_CONF.data[year][paddedMonth]) {
                    items = TEMF_CONF.data[year][paddedMonth];
                }

                // 使用统一的 renderFiles 方法，支持分页
                ui.renderFiles(items);
            } catch (e) {
                // render error
            }
        },

        updateDataFromResponse: function (payload) {
            if (!payload || !Array.isArray(payload.files)) {
                return;
            }

            var grouped = {};
            var latestMtime = 0;
            var latestYear = null;
            var latestMonth = null;

            if (payload.groups && typeof payload.groups === 'object') {
                Object.keys(payload.groups).forEach(function (ym) {
                    var parts = ym.split('-');
                    if (parts.length !== 2) return;
                    var year = parts[0];
                    var month = parts[1].padStart(2, '0');
                    grouped[year] = grouped[year] || {};
                    grouped[year][month] = (payload.groups[ym] || []).map(function (item) {
                        var copy = Object.assign({}, item);
                        copy.group = copy.group || (year + '-' + month);
                        copy.mtime = copy.mtime || 0;

                        // 标准化目录路径并零填充月份（与Renderer.php逻辑一致）
                        var directory = normalizeDirectoryPath(copy.directory || '');
                        if (directory) {
                            var parts = directory.split('/');
                            if (parts.length >= 2 && /^\d{4}$/.test(parts[0]) && /^\d{1,2}$/.test(parts[1])) {
                                parts[1] = parts[1].padStart(2, '0');
                                directory = parts.join('/');
                            }
                        }
                        copy.directory = directory;

                        copy.thumbnail = copy.thumbnail || '';
                        copy.id = copy.id || '';
                        return copy;
                    });
                });
            }

            if (Object.keys(grouped).length === 0) {
                payload.files.forEach(function (item) {
                    if (!item || !item.group) return;
                    var parts = String(item.group).split('-');
                    if (parts.length !== 2) return;
                    var year = parts[0];
                    var month = parts[1].padStart(2, '0');
                    grouped[year] = grouped[year] || {};
                    grouped[year][month] = grouped[year][month] || [];
                    var clone = Object.assign({}, item);

                    // 标准化目录路径并零填充月份（与Renderer.php逻辑一致）
                    var directory = normalizeDirectoryPath(clone.directory || '');
                    if (directory) {
                        var parts = directory.split('/');
                        if (parts.length >= 2 && /^\d{4}$/.test(parts[0]) && /^\d{1,2}$/.test(parts[1])) {
                            parts[1] = parts[1].padStart(2, '0');
                            directory = parts.join('/');
                        }
                    }
                    clone.directory = directory;

                    clone.thumbnail = clone.thumbnail || '';
                    clone.id = clone.id || '';
                    grouped[year][month].push(clone);
                });
            }

            Object.keys(grouped).forEach(function (year) {
                Object.keys(grouped[year]).forEach(function (month) {
                    grouped[year][month].forEach(function (item) {
                        var ts = item.mtime || 0;
                        if (ts > latestMtime) {
                            latestMtime = ts;
                            latestYear = year;
                            latestMonth = month;
                        }
                    });
                });
            });

            TEMF_CONF.data = grouped;
            TEMF_CONF.latest = latestYear && latestMonth ? (latestYear + '-' + latestMonth) : '';

            if (payload.paginationRows) {
                TEMF_CONF.paginationRows = payload.paginationRows;
            }
        },

        fetchLatest: function (options) {
            var sec = byId('temediafolder');
            if (!sec) return Promise.resolve();
            var url = sec.getAttribute('data-local-list');
            if (!url) return Promise.resolve();
            var requestUrl = url;

            // 保存当前选择的年月
            var ySel = byId('temf-year');
            var mSel = byId('temf-month');
            var currentYear = ySel ? ySel.value : null;
            var currentMonth = mSel ? mSel.value : null;

            if (options && options.rebuildIndex) {
                requestUrl += (requestUrl.indexOf('?') >= 0 ? '&' : '?') + 'temf_rebuild_index=1';
            }

            var fetchOptions = options && options.bustCache ? { bustCache: true } : {};
            return cachedFetch(requestUrl, fetchOptions)
                .then(function (resp) {
                    if (!resp || resp.ok === false) {
                        return;
                    }
                    this.updateDataFromResponse(resp);
                    if (isLocalStorage(getEffectiveStorageSource())) {
                        localBrowserApi.refresh();
                        return;
                    }

                    this.buildYearMonth();
                    if (currentYear && currentMonth) {
                        var ySel = byId('temf-year');
                        var mSel = byId('temf-month');
                        if (ySel && TEMF_CONF.data && TEMF_CONF.data[currentYear]) {
                            customSelects.setValue('temf-year', currentYear, { silent: true });
                            this.buildMonths(currentYear);
                            var paddedMonth = currentMonth.length === 1 ? '0' + currentMonth : currentMonth;
                            if (mSel && TEMF_CONF.data[currentYear][paddedMonth]) {
                                customSelects.setValue('temf-month', currentMonth, { silent: true });
                            }
                        }
                    }
                    this.renderCurrentMonth();
                }.bind(this))
                .catch(function (err) {
                    console.error('[TEMF] Failed to refresh local files', err);
                });
        }
        };
    }

    // 通用云存储处理器
    var cloudStorage = {
        /**
         * 初始化目录选择器
         * @param {string} storageType - 'cos' 或 'oss'
         */
        init: function (storageType, options) {
            var dir = byId('temf-dir');
            if (!dir) return;

            setSelectVisibility('temf-dir', false);
            this.initDirectorySelector(dir);
            setCurrentPath('');
            resetRemotePagination(storageType, '', { enabled: isServerPagedStorage(storageType) });

            var self = this;
            this.fetch(storageType, '', function (data) {
                self.syncDirectorySelector(dir, '', data.folders || []);
                ui.renderFiles(data.files || [], data.folders || [], data);
            }, options);
        },

        /**
         * 初始化目录选择器
         */
        initDirectorySelector: function (dir) {
            dir.innerHTML = '';
            var optRoot = document.createElement('option');
            optRoot.value = '';
            optRoot.textContent = '/';
            dir.appendChild(optRoot);
        },

        /**
         * 同步目录选择器（根目录 + 当前目录 + 子目录）
         */
        syncDirectorySelector: function (dirElement, currentPath, folders) {
            if (!dirElement) return;

            dirElement.innerHTML = '';
            var unique = {};

            function appendOption(path, label) {
                var normalized = normalizeDirectoryPath(path);
                if (unique[normalized]) return;
                unique[normalized] = true;
                var opt = document.createElement('option');
                opt.value = normalized;
                opt.textContent = label || (normalized ? ('/' + normalized) : '/');
                dirElement.appendChild(opt);
            }

            appendOption('', '/');

            var normalizedCurrent = normalizeDirectoryPath(currentPath);
            if (normalizedCurrent) {
                var parts = normalizedCurrent.split('/');
                var chain = [];
                for (var i = 0; i < parts.length; i++) {
                    chain.push(parts[i]);
                    var chainPath = chain.join('/');
                    appendOption(chainPath, '/' + chainPath);
                }
            }

            folders.forEach(function (folder) {
                var folderPath = normalizeDirectoryPath(folder.path || folder.name || '');
                if (!folderPath) return;
                appendOption(folderPath, '/' + folderPath);
            });

            customSelects.sync('temf-dir');
            customSelects.setValue('temf-dir', normalizedCurrent, { silent: true });
        },

        /**
         * 通用fetch方法（带缓存）
         */
        fetch: function (storageType, path, callback, options) {
            var sec = byId('temediafolder');
            if (!sec) return;

            var attrName = 'data-' + storageType + '-list';
            var base = sec.getAttribute(attrName);
            if (!base) return;

            var requestOptions = buildCloudPageRequestOptions(storageType, path, options);
            var url = base;
            if (path) {
                url += (base.indexOf('?') >= 0 ? '&' : '?') + 'temf_path=' + encodeURIComponent(path);
            }
            if (requestOptions.pageToken) {
                url += (url.indexOf('?') >= 0 ? '&' : '?') + 'temf_page_token=' + encodeURIComponent(requestOptions.pageToken);
            }
            if (requestOptions.pageSize) {
                url += (url.indexOf('?') >= 0 ? '&' : '?') + 'temf_page_size=' + encodeURIComponent(String(requestOptions.pageSize));
            }

            // 使用统一的 cachedFetch（自动处理缓存）
            var fetchOptions = requestOptions.bustCache ? { bustCache: true } : {};
            cachedFetch(url, fetchOptions)
                .then(function (data) {
                    mergeFoldersOnlyResult(base, path, data, callback, {
                        storageType: storageType,
                        foldersOnly: requestOptions.foldersOnly,
                        bustCache: requestOptions.bustCache
                    });
                })
                .catch(function () {
                    callback && callback({ folders: [], files: [] });
                });
        }
    };

    function createCloudProvider(type) {
        return {
            init: function () { cloudStorage.init(type); },
            fetch: function (path, callback) { cloudStorage.fetch(type, path, callback); }
        };
    }

    var cloudProviders = {
        cos: createCloudProvider('cos'),
        oss: createCloudProvider('oss'),
        bitiful: createCloudProvider('bitiful'),
        upyun: createCloudProvider('upyun')
    };

    var storageRegistry = {
        local: {
            label: '本地存储',
            hierarchical: true,
            serverPaged: false,
            cloudDirectory: false,
            thumbnailMode: 'none',
            deleteAttr: 'data-local-delete',
            uploadAttr: 'data-local-upload',
            renameAttr: 'data-local-rename',
            supportsRename: true,
            ensureLoaded: function () {
                localBrowser.init();
                return true;
            },
            switchHandler: function () {
                multi.hideDirectorySelectors();
                multi.showLocalSelectors();
                multi.loadLocalData();
            }
        },
        cos: {
            label: '腾讯云COS',
            hierarchical: true,
            serverPaged: true,
            cloudDirectory: true,
            thumbnailMode: 'query-thumb',
            loadKey: 'cosLoaded',
            deleteAttr: 'data-cos-delete',
            uploadAttr: 'data-cos-upload',
            renameAttr: 'data-cos-rename',
            supportsRename: true,
            provider: cloudProviders.cos
        },
        oss: {
            label: '阿里云OSS',
            hierarchical: true,
            serverPaged: true,
            cloudDirectory: true,
            thumbnailMode: 'query-thumb',
            loadKey: 'ossLoaded',
            deleteAttr: 'data-oss-delete',
            uploadAttr: 'data-oss-upload',
            renameAttr: 'data-oss-rename',
            supportsRename: true,
            provider: cloudProviders.oss
        },
        bitiful: {
            label: '缤纷云存储',
            hierarchical: true,
            serverPaged: true,
            cloudDirectory: true,
            thumbnailMode: 'bitiful',
            loadKey: 'bitifulLoaded',
            deleteAttr: 'data-bitiful-delete',
            uploadAttr: 'data-bitiful-upload',
            renameAttr: 'data-bitiful-rename',
            supportsRename: true,
            provider: cloudProviders.bitiful
        },
        upyun: {
            label: '又拍云',
            hierarchical: true,
            serverPaged: true,
            cloudDirectory: true,
            thumbnailMode: 'none',
            loadKey: 'upyunLoaded',
            deleteAttr: 'data-upyun-delete',
            uploadAttr: 'data-upyun-upload',
            renameAttr: 'data-upyun-rename',
            supportsRename: true,
            provider: cloudProviders.upyun
        },
        lsky: {
            label: '兰空图床',
            hierarchical: false,
            serverPaged: false,
            cloudDirectory: false,
            thumbnailMode: 'query-thumb',
            loadKey: 'lskyLoaded',
            deleteAttr: 'data-lsky-delete',
            uploadAttr: 'data-lsky-upload',
            renameAttr: 'data-lsky-rename',
            supportsRename: false,
            ensureLoaded: function () {
                if (!state.lskyLoaded) {
                    lsky.init();
                    state.lskyLoaded = true;
                    return true;
                }
                return false;
            },
            switchHandler: function () {
                multi.hideLocalSelectors();
                multi.initLskySelectors();
                multi.loadLskyData();
            }
        }
    };

    function createDragUploadModule(uploadApi) {
        return {
            initialized: false,
            active: false,

            init: function () {
                if (this.initialized) return;
                var dialog = document.querySelector('#temf-modal .temf-dialog');
                if (!dialog) return;

                var self = this;
                this.ensureDropzone();

                dialog.addEventListener('dragenter', function (e) {
                    if (!self.hasFiles(e)) return;
                    e.preventDefault();
                    self.showDropzone();
                });
                dialog.addEventListener('dragover', function (e) {
                    if (!self.hasFiles(e)) return;
                    e.preventDefault();
                    if (e.dataTransfer) {
                        e.dataTransfer.dropEffect = 'copy';
                    }
                    self.showDropzone();
                });
                dialog.addEventListener('dragleave', function (e) {
                    if (!self.hasFiles(e)) return;
                    e.preventDefault();
                    if (self.isLeavingViewport(e)) {
                        self.hideDropzone();
                    }
                });
                dialog.addEventListener('drop', function (e) {
                    if (!self.hasFiles(e)) return;
                    e.preventDefault();
                    self.hideDropzone();
                    var files = e.dataTransfer && e.dataTransfer.files ? Array.from(e.dataTransfer.files) : [];
                    uploadApi.stageFiles(files);
                });

                document.addEventListener('dragleave', function (e) {
                    if (!byId('temf-modal') || !byId('temf-modal').classList.contains('open')) return;
                    if (!self.hasFiles(e)) return;
                    if (self.isLeavingViewport(e)) {
                        self.hideDropzone();
                    }
                });
                document.addEventListener('dragend', function () {
                    self.hideDropzone();
                });

                document.addEventListener('dragover', function (e) {
                    if (!byId('temf-modal') || !byId('temf-modal').classList.contains('open')) return;
                    if (!self.hasFiles(e)) return;
                    e.preventDefault();
                });
                document.addEventListener('drop', function (e) {
                    if (!byId('temf-modal') || !byId('temf-modal').classList.contains('open')) return;
                    if (!self.hasFiles(e)) return;
                    e.preventDefault();
                });
                this.initialized = true;
            },

            hasFiles: function (e) {
                var dt = e.dataTransfer;
                return !!(dt && dt.types && Array.prototype.indexOf.call(dt.types, 'Files') !== -1);
            },

            ensureDropzone: function () {
                var dialog = document.querySelector('#temf-modal .temf-dialog');
                if (!dialog) return null;
                var zone = dialog.querySelector('.temf-dropzone');
                if (!zone) {
                    zone = document.createElement('div');
                    zone.className = 'temf-dropzone';
                    zone.innerHTML = '<div class="temf-dropzone-card"><div class="temf-dropzone-title">拖拽图片到这里</div><div class="temf-dropzone-subtitle">松开后加入待上传列表，确认无误后再开始上传。</div><div class="temf-dropzone-target"></div></div>';
                    ['dragenter', 'dragover', 'dragleave', 'wheel', 'mousedown', 'click'].forEach(function (eventName) {
                        zone.addEventListener(eventName, function (e) {
                            e.preventDefault();
                            e.stopPropagation();
                        });
                    });
                    zone.addEventListener('drop', function (e) {
                        if (!this.hasFiles(e)) return;
                        e.preventDefault();
                        e.stopPropagation();
                        this.hideDropzone();
                        var files = e.dataTransfer && e.dataTransfer.files ? Array.from(e.dataTransfer.files) : [];
                        uploadApi.stageFiles(files);
                    }.bind(this));
                    dialog.appendChild(zone);
                }
                return zone;
            },

            showDropzone: function () {
                var zone = this.ensureDropzone();
                this.active = true;
                if (zone) {
                    var target = zone.querySelector('.temf-dropzone-target');
                    if (target) {
                        target.textContent = '当前目标：' + getComputedUploadPath(upload.getCurrentStorageType(), upload.getCurrentPath());
                    }
                    zone.classList.add('show');
                }
                var dialog = document.querySelector('#temf-modal .temf-dialog');
                if (dialog) dialog.classList.add('temf-dragging');
            },

            hideDropzone: function () {
                this.active = false;
                var zone = document.querySelector('#temf-modal .temf-dropzone');
                if (zone) zone.classList.remove('show');
                var dialog = document.querySelector('#temf-modal .temf-dialog');
                if (dialog) dialog.classList.remove('temf-dragging');
            },

            isLeavingViewport: function (e) {
                return e.clientX <= 0 || e.clientY <= 0 || e.clientX >= window.innerWidth || e.clientY >= window.innerHeight;
            }
        };
    }

    var lsky = {
        init: function (options) {
            var dir = byId('temf-dir');
            if (!dir) return;
            setSelectVisibility('temf-dir', false);

            setCurrentPath('');

            // 兰空图床使用特殊的选择器
            dir.innerHTML = '';

            // 添加"全部"选项
            var optAll = document.createElement('option');
            optAll.value = '';
            optAll.textContent = '全部';
            dir.appendChild(optAll);

            // 添加"相册"选项（如果配置了相册ID）
            // 单模式下检查TEMF_CONF配置
            var hasAlbumId = TEMF_CONF.lskyAlbumId && TEMF_CONF.lskyAlbumId !== '';
            if (hasAlbumId) {
                var optAlbum = document.createElement('option');
                optAlbum.value = 'album';
                optAlbum.textContent = '相册';
                dir.appendChild(optAlbum);

                // 如果配置了相册ID，默认选中相册
                dir.value = 'album';
            }

            // 根据当前选择加载数据
            var currentSelection = dir.value || '';
            var fetchPath = currentSelection === 'album' ? 'album' : '';

            this.fetch(fetchPath, function (data) {
                ui.renderFiles(data.files || [], data.folders || [], data);
                customSelects.sync('temf-dir');
            }, options);
        },

        fetch: function (path, callback, options) {
            var sec = byId('temediafolder');
            if (!sec) return;

            var base = sec.getAttribute('data-lsky-list');
            if (!base) return;

            var url = base;

            // 处理兰空图床的特殊参数
            if (path === 'album') {
                // 使用相册ID参数
                url += (base.indexOf('?') >= 0 ? '&' : '?') + 'use_album=1';
            } else if (path) {
                url += (base.indexOf('?') >= 0 ? '&' : '?') + 'temf_path=' + encodeURIComponent(path);
            }

            var fetchOptions = (options && options.bustCache) ? { bustCache: true } : {};
            cachedFetch(url, fetchOptions)
                .then(function (data) {
                    if (isServerPagedStorage(state.currentStorage) && !requestOptions.foldersOnly) {
                        var folderUrl = base;
                        var folderParams = ['storage_type=' + encodeURIComponent(state.currentStorage)];

                        if (isLskyStorage(state.currentStorage) && path === 'album') {
                            folderParams.push('use_album=1');
                        } else if (path) {
                            folderParams.push('temf_path=' + encodeURIComponent(path));
                        }
                        folderParams.push('temf_folders_only=1');
                        if (fetchOptions.bustCache) {
                            folderParams.push('_ts=' + Date.now());
                        }

                        folderUrl += (base.indexOf('?') >= 0 ? '&' : '?') + folderParams.join('&');
                        cachedFetch(folderUrl, { bustCache: !!requestOptions.bustCache, cache: 'no-store' })
                            .then(function (folderData) {
                                if (folderData && Array.isArray(folderData.folders) && folderData.folders.length) {
                                    data.folders = folderData.folders;
                                }
                                callback && callback(data);
                            })
                            .catch(function () {
                                callback && callback(data);
                            });
                        return;
                    }
                    callback && callback(data);
                })
                .catch(function () {
                    callback && callback({ folders: [], files: [] });
                });
        }
    };

    var multi = {
        init: function () {
            var sec = byId('temediafolder');
            if (!sec) return;

            var storageTypesUrl = sec.getAttribute('data-storage-types');
            if (!storageTypesUrl) return;

            // 获取可用的存储类型
            cachedFetch(storageTypesUrl)
                .then(function (data) {
                    if (data.ok && data.types) {
                        state.availableStorages = data.types;
                        multi.buildSwitcher();

                        // 默认选择第一个可用存储
                        if (data.types.length > 0) {
                            multi.switchTo(data.types[0].key);
                        }
                    }
                })
                .catch(function () {
                });
        },

        buildSwitcher: function () {
            var switcher = byId('temf-storage-switcher');
            if (!switcher || state.availableStorages.length === 0) return;

            switcher.innerHTML = '';

            state.availableStorages.forEach(function (storage) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'temf-storage-btn';
                btn.setAttribute('data-storage', storage.key);
                btn.textContent = storage.name;

                btn.addEventListener('click', function () {
                    multi.switchTo(storage.key);
                });

                switcher.appendChild(btn);
            });

            // 添加标题点击事件
            var title = byId('temf-title');
            var container = document.querySelector('.temf-title-container');
            if (title && container) {
                title.addEventListener('click', function (e) {
                    e.stopPropagation();
                    container.classList.toggle('expanded');
                    switcher.classList.toggle('show');
                });
            }

            // 添加点击外部区域自动收起功能
            document.addEventListener('click', function (e) {
                var titleWrapper = document.querySelector('.temf-title-wrapper');
                if (titleWrapper && !titleWrapper.contains(e.target)) {
                    var switcher = byId('temf-storage-switcher');
                    var container = document.querySelector('.temf-title-container');
                    if (switcher && container) {
                        switcher.classList.remove('show');
                        container.classList.remove('expanded');
                    }
                }
            });
        },

        switchTo: function (storageType) {
            state.currentStorage = storageType;
            resetCloudRootState(storageType);

            // 性能优化：切换模式时清除缓存
            uiRender.clearCache();

            // 切换存储时重置已选择集合，避免跨模式残留
            if (selection && typeof selection.clear === 'function') {
                selection.clear();
            }

            // 重置分页状态
            state.pagination.currentPage = 1;
            state.pagination.totalItems = 0;
            state.pagination.allFiles = [];

            // 切换存储时移除隐藏的文件输入，确保重新绑定change事件
            var oldInput = document.getElementById('temf-hidden-file');
            if (oldInput && oldInput.parentNode) {
                oldInput.parentNode.removeChild(oldInput);
            }

            // 自动收起存储切换器
            var switcher = byId('temf-storage-switcher');
            var container = document.querySelector('.temf-title-container');
            if (switcher && container) {
                switcher.classList.remove('show');
                container.classList.remove('expanded');
            }

            // 获取存储类型的中文名称
            var storageName = storageRegistry[storageType] && storageRegistry[storageType].label ? storageRegistry[storageType].label : storageType;

            // 更新标题显示当前存储类型（只显示存储名称）
            var title = byId('temf-title');
            if (title) {
                title.textContent = storageName;
            }

            // 添加切换动画和加载提示
            var body = document.querySelector('#temf-modal .temf-body');
            var dialog = document.querySelector('#temf-modal .temf-dialog');
            if (body) {
                body.classList.add('temf-content-switching');
            }
            if (dialog) {
                dialog.classList.add('temf-switching-active');
                var existingOverlay = dialog.querySelector('.temf-switching-overlay');
                if (existingOverlay && existingOverlay.parentNode) {
                    existingOverlay.parentNode.removeChild(existingOverlay);
                }
                var overlay = document.createElement('div');
                overlay.className = 'temf-switching-overlay';
                overlay.innerHTML = '<div class="temf-switching-spinner"></div>' +
                    '<div class="temf-switching-text">正在切换到 ' + storageName + '...</div>';
                dialog.appendChild(overlay);
            }

            // 更新按钮状态
            var buttons = document.querySelectorAll('.temf-storage-btn');
            buttons.forEach(function (btn) {
                if (btn.getAttribute('data-storage') === storageType) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            // 延迟执行切换逻辑，让动画效果更平滑
            setTimeout(function () {
                multi.handleSwitch(storageType);
            }, 150);
        },

        handleSwitch: function (storageType) {
            var handler = storageRegistry[storageType] && storageRegistry[storageType].switchHandler;
            if (handler) {
                handler();
                return;
            }

            this.showDirectorySelectors();
            this.hideLocalSelectors();
            this.initDirectorySelectors();
            resetCloudRootState(storageType);

            this.fetch('', function (data) {
                resetCloudRootState(storageType);
                var dir = byId('temf-dir');
                if (dir) {
                    cloudStorage.syncDirectorySelector(dir, '', data.folders || []);
                    customSelects.setValue('temf-dir', '', { silent: true });
                }
                ui.renderFiles(data.files || [], data.folders || [], data);
                multi.finishSwitchAnimation();
            });
        },

        finishSwitchAnimation: function () {
            var body = document.querySelector('#temf-modal .temf-body');
            var dialog = document.querySelector('#temf-modal .temf-dialog');
            if (body) {
                body.classList.remove('temf-content-switching');
                body.classList.add('temf-content-switched');
                setTimeout(function () {
                    body.classList.remove('temf-content-switched');
                }, 200);
            }
            if (dialog) {
                dialog.classList.remove('temf-switching-active');
                var overlay = dialog.querySelector('.temf-switching-overlay');
                if (overlay && overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
            }
        },

        initDirectorySelectors: function () {
            var dir = byId('temf-dir');
            if (dir) {
                dir.innerHTML = '';
                var optRoot = document.createElement('option');
                optRoot.value = '';
                optRoot.textContent = '/';
                dir.appendChild(optRoot);
                customSelects.sync('temf-dir');
            }
        },

        showDirectorySelectors: function () {
            setSelectVisibility('temf-dir', false);
        },

        hideDirectorySelectors: function () {
            setSelectVisibility('temf-dir', false);
        },

        showLocalSelectors: function () {
            setSelectVisibility('temf-year', true);
            setSelectVisibility('temf-month', true);
        },

        hideLocalSelectors: function () {
            setSelectVisibility('temf-year', false);
            setSelectVisibility('temf-month', false);
        },

        initLskySelectors: function () {
            var dir = byId('temf-dir');

            if (dir) {
                setSelectVisibility('temf-dir', false);
                dir.innerHTML = '';

                // 添加"全部"选项
                var optAll = document.createElement('option');
                optAll.value = '';
                optAll.textContent = '全部';
                dir.appendChild(optAll);

                // 添加"相册"选项（如果配置了相册ID）
                var hasAlbumId = this.hasLskyAlbumConfig();
                if (hasAlbumId) {
                    var optAlbum = document.createElement('option');
                    optAlbum.value = 'album';
                    optAlbum.textContent = '相册';
                    dir.appendChild(optAlbum);

                    // 如果配置了相册ID，默认选中相册
                    customSelects.setValue('temf-dir', 'album', { silent: true });
                } else {
                    customSelects.setValue('temf-dir', '', { silent: true });
                }
                customSelects.sync('temf-dir');
            }

        },

        hasLskyAlbumConfig: function () {
            // 检查是否配置了兰空图床的相册ID
            // 从可用存储列表中获取兰空图床的配置信息
            var lskyStorage = state.availableStorages.find(function (storage) {
                return isLskyStorage(storage.key);
            });

            return lskyStorage && lskyStorage.hasAlbumId;
        },

        loadLskyData: function (options) {
            // 根据当前选择加载数据
            var dir = byId('temf-dir');
            var currentSelection = dir ? dir.value : '';
            var fetchPath = currentSelection === 'album' ? 'album' : '';
            setCurrentPath('');

            this.fetch(fetchPath, function (data) {
                ui.renderFiles(data.files || [], data.folders || [], data);
                multi.finishSwitchAnimation();
            }, options);
        },

        loadLocalData: function (options) {
            // 通过API获取本地文件数据
            this.fetch('', function (data) {
                if (data.ok && data.files) {
                    // 将本地文件数据转换为TEMF_CONF.data格式
                    multi.buildLocalDataStructure(data.files);
                    localBrowser.init();
                } else {
                    // 没有数据时显示空状态
                    ui.renderFiles([]);
                }

                // 完成切换动画
                multi.finishSwitchAnimation();
            });
        },

        buildLocalDataStructure: function (files) {
            // 初始化数据结构
            TEMF_CONF.data = {};
            TEMF_CONF.latest = null;

            var latestMtime = 0;
            var latestYear = null;
            var latestMonth = null;

            // 按年月分组文件
            files.forEach(function (file) {
                if (!file.group) return; // group格式应该是 "YYYY-MM"

                var parts = file.group.split('-');
                if (parts.length !== 2) return;

                var year = parts[0];
                var month = parts[1];

                // 初始化年月结构
                if (!TEMF_CONF.data[year]) {
                    TEMF_CONF.data[year] = {};
                }
                if (!TEMF_CONF.data[year][month]) {
                    TEMF_CONF.data[year][month] = [];
                }

                // 添加文件到对应年月
                TEMF_CONF.data[year][month].push({
                    url: file.url,
                    name: file.name,
                    mtime: file.mtime || 0,
                    size: file.size || 0,
                    directory: normalizeDirectoryPath(file.directory || ''),
                    thumbnail: file.thumbnail || '',
                    id: file.id || ''
                });

                // 记录最新的文件时间
                var fileMtime = file.mtime || 0;
                if (fileMtime > latestMtime) {
                    latestMtime = fileMtime;
                    latestYear = year;
                    latestMonth = month;
                }
            });

            // 设置最新的年月
            if (latestYear && latestMonth) {
                TEMF_CONF.latest = latestYear + '-' + latestMonth;
            }

            // 对每个月的文件按时间排序（最新的在前）
            Object.keys(TEMF_CONF.data).forEach(function (year) {
                Object.keys(TEMF_CONF.data[year]).forEach(function (month) {
                    TEMF_CONF.data[year][month].sort(function (a, b) {
                        return (b.mtime || 0) - (a.mtime || 0);
                    });
                });
            });
        },

        fetch: function (path, callback, options) {
            var sec = byId('temediafolder');
            if (!sec || !state.currentStorage) return;

            var base = sec.getAttribute('data-multi-list');
            if (!base) return;

            var requestOptions = buildCloudPageRequestOptions(state.currentStorage, path, options);
            var url = base;
            var hasQuery = base.indexOf('?') >= 0;

            var params = [];
            params.push('storage_type=' + encodeURIComponent(state.currentStorage));

            if (isLskyStorage(state.currentStorage) && path === 'album') {
                params.push('use_album=1');
            } else if (path) {
                params.push('temf_path=' + encodeURIComponent(path));
            }
            if (requestOptions.pageToken) {
                params.push('temf_page_token=' + encodeURIComponent(requestOptions.pageToken));
            }
            if (requestOptions.pageSize) {
                params.push('temf_page_size=' + encodeURIComponent(String(requestOptions.pageSize)));
            }

            var effectivePath = path || '';
            if (isLskyStorage(state.currentStorage)) {
                effectivePath = '';
            }
            setCurrentPath(effectivePath);

            var fetchOptions = { bustCache: !!requestOptions.bustCache };

            if (fetchOptions.bustCache) {
                // 强制绕过缓存时生成唯一查询参数
                params.push('_ts=' + Date.now());
            }

            url += (hasQuery ? '&' : '?') + params.join('&');

            cachedFetch(url, fetchOptions)
                .then(function (data) {
                    mergeFoldersOnlyResult(base, path, data, callback, {
                        storageType: state.currentStorage,
                        foldersOnly: requestOptions.foldersOnly,
                        bustCache: requestOptions.bustCache,
                        extraParams: ['storage_type=' + encodeURIComponent(state.currentStorage)]
                    });
                })
                .catch(function (error) {
                    console.error('[TEMF] 多存储 fetch 失败', error);
                    callback && callback({ folders: [], files: [] });
                });
        },
    };

    var rename = {
        active: null,
        start: function (nameSpan) {
            if (!nameSpan || nameSpan.classList.contains('editing')) {
                return;
            }

            var item = nameSpan.closest('.temf-item');
            if (!item) {
                return;
            }

            var currentText = nameSpan.dataset.fullName || nameSpan.textContent || '';
            if (!currentText) {
                currentText = nameSpan.textContent || '';
            }
            var lastDot = currentText.lastIndexOf('.');
            var base = lastDot > 0 ? currentText.slice(0, lastDot) : currentText;
            var extension = lastDot > 0 ? currentText.slice(lastDot) : '';

            nameSpan.dataset.originalName = currentText;
            nameSpan.classList.add('editing', 'temf-name-editing');
            nameSpan.textContent = '';

            var editor = document.createElement('div');
            editor.className = 'temf-rename-editor';

            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'temf-rename-input';
            input.value = base;
            input.setAttribute('aria-label', '重命名');

            var hint = document.createElement('div');
            hint.className = 'temf-rename-hint';
            hint.style.display = 'none';
            editor.appendChild(input);
            nameSpan.appendChild(editor);
            nameSpan.appendChild(hint);

            input.focus();
            input.select();

            var objectId = null;
            var checkbox = item.querySelector('.temf-pick');
            if (checkbox) {
                objectId = checkbox.getAttribute('data-meta-id') || null;
            }

            if (!objectId) {
                var files = state.pagination && Array.isArray(state.pagination.allFiles) ? state.pagination.allFiles : [];
                var match = files.find(function (file) { return file.url === item.getAttribute('data-url'); });
                if (match && match.id != null) {
                    objectId = String(match.id);
                }
            }

            var self = this;
            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    self.commit();
                } else if (ev.key === 'Escape') {
                    ev.preventDefault();
                    self.cancel();
                }
            });

            input.addEventListener('blur', function () {
                setTimeout(function () {
                    if (self.active && self.active.input === input && !self.active.submitting) {
                        self.cancel();
                    }
                }, 120);
            });

            this.active = {
                item: item,
                span: nameSpan,
                input: input,
                hint: hint,
                extension: extension,
                oldUrl: item.getAttribute('data-url') || '',
                oldPreviewUrl: (item.querySelector('.temf-thumb img') && item.querySelector('.temf-thumb img').dataset)
                    ? (item.querySelector('.temf-thumb img').dataset.original || '')
                    : '',
                originalName: currentText,
                objectId: objectId,
                submitting: false
            };
        },
        cancel: function (silent) {
            if (!this.active) return;

            var span = this.active.span;
            if (span) {
                span.classList.remove('editing', 'temf-name-editing');
                span.classList.remove('temf-rename-success');
                span.textContent = this.active.originalName;
                span.removeAttribute('data-original-name');
            }

            this.active = null;

            if (!silent) {
                selection.updateButton();
            }
        },
        showError: function (message) {
            if (!this.active) return;
            var hint = this.active.hint;
            var input = this.active.input;
            if (hint) {
                hint.textContent = message || '重命名失败';
                hint.style.display = 'block';
            }
            if (input) {
                input.classList.add('has-error');
            }
        },
        hideError: function () {
            if (!this.active) return;
            var hint = this.active.hint;
            var input = this.active.input;
            if (hint) {
                hint.style.display = 'none';
            }
            if (input) {
                input.classList.remove('has-error');
            }
        },
        commit: function () {
            if (!this.active) return;

            var input = this.active.input;
            var baseName = input.value.trim();
            if (baseName === '') {
                this.showError('文件名不能为空');
                return;
            }

            var originalName = this.active.originalName;
            var extension = this.active.extension || '';
            if (baseName + extension === originalName) {
                this.cancel();
                return;
            }

            var endpointInfo = this.getRenameEndpoint();
            if (!endpointInfo.supported) {
                this.showError(endpointInfo.message || '当前存储不支持重命名');
                return;
            }

            this.hideError();
            this.active.submitting = true;
            input.disabled = true;

            var self = this;
            fetch(endpointInfo.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                credentials: 'same-origin',
                body: endpointInfo.body(baseName, this.active)
            })
                .then(function (res) {
                    return res.text().then(function (text) {
                        var data = null;
                        if (text) {
                            try {
                                data = JSON.parse(text);
                            } catch (parseErr) {
                                throw new Error('INVALID_JSON:' + parseErr.message);
                            }
                        }
                        if (!data) {
                            throw new Error('EMPTY_RESPONSE');
                        }
                        return data;
                    });
                })
                .then(function (result) {
                    if (!result || result.ok === false) {
                        self.showError(result && result.msg ? result.msg : '重命名失败');
                        if (self.active) {
                            self.active.submitting = false;
                        }
                        input.disabled = false;
                        input.focus();
                        input.select();
                        return;
                    }
                    self.applySuccess(result);
                })
                .catch(function (err) {
                    console.error('[TEMF] Rename failed', err);
                    var msg = '重命名请求失败';
                    if (err && typeof err.message === 'string') {
                        if (err.message.indexOf('INVALID_JSON') === 0 || err.message === 'EMPTY_RESPONSE') {
                            msg = '重命名接口返回数据异常';
                        }
                    }
                    self.showError(msg);
                    if (self.active) {
                        self.active.submitting = false;
                    }
                    input.disabled = false;
                    input.focus();
                });
        },
        getRenameEndpoint: function () {
            if (!this.active) {
                return { supported: false };
            }

            var sec = byId('temediafolder');
            if (!sec) {
                return { supported: false, message: '缺少配置' };
            }

            var oldUrl = this.active.oldUrl;
            if (!oldUrl) {
                return { supported: false, message: '无效的文件地址' };
            }

            var source = TEMF_CONF.source;
            if (isLocalStorage(source)) {
                var localRenameMeta = getStorageRenameMeta('local');
                var localRename = localRenameMeta.attr ? sec.getAttribute(localRenameMeta.attr) : '';
                if (!localRename) {
                    return { supported: false, message: '未配置重命名接口' };
                }
                return {
                    supported: true,
                    url: localRename,
                    body: function (baseName, active) {
                        return new URLSearchParams({
                            file_url: oldUrl,
                            new_name: baseName
                        }).toString();
                    }
                };
            }

            var singleRenameMeta = getStorageRenameMeta(source);
            if (singleRenameMeta.supported) {
                var singleRename = singleRenameMeta.attr ? sec.getAttribute(singleRenameMeta.attr) : '';
                if (!singleRename) {
                    return { supported: false, message: '未配置重命名接口' };
                }
                return {
                    supported: true,
                    url: singleRename,
                    body: function (baseName, active) {
                        var params = new URLSearchParams({
                            file_url: oldUrl,
                            new_name: baseName
                        });
                        if (active && active.objectId) {
                            params.append('file_id', active.objectId);
                        }
                        return params.toString();
                    }
                };
            }

            if (isMultiMode()) {
                var currentStorage = state.currentStorage;
                if (!currentStorage) {
                    return { supported: false, message: '请先选择存储类型' };
                }
                var multiRename = sec.getAttribute('data-multi-rename');
                if (!multiRename) {
                    return { supported: false, message: '未配置重命名接口' };
                }
                if (!getStorageRenameMeta(currentStorage).supported) {
                    return { supported: false, message: '当前存储暂不支持重命名' };
                }
                return {
                    supported: true,
                    url: multiRename,
                    body: function (baseName, active) {
                        var params = new URLSearchParams({
                            storage_type: currentStorage,
                            file_url: oldUrl,
                            new_name: baseName
                        });
                        if (active && active.objectId) {
                            params.append('file_id', active.objectId);
                        }
                        return params.toString();
                    }
                };
            }

            return { supported: false, message: '当前存储暂不支持重命名' };
        },
        applySuccess: function (result) {
            if (!this.active) return;

            var active = this.active;
            var item = active.item;
            var span = active.span;
            var oldUrl = active.oldUrl;
            var newUrl = result.url || oldUrl;
            var newPreviewUrl = result.preview_url || result.previewUrl || newUrl;
            var newName = result.name || (active.input.value.trim() + (active.extension || ''));
            invalidateDirectoryCaches();
            var displayName = newName;
            var lastDot = newName.lastIndexOf('.');
            if (lastDot > 0) {
                displayName = newName.slice(0, lastDot);
            }

            item.setAttribute('data-url', newUrl);
            if (typeof result.directory === 'string') {
                var normalizedResultDirectory = result.directory ? result.directory.replace(/^\/+|\/+$/g, '') : '';
                item.setAttribute('data-directory', normalizedResultDirectory);
            }

            var checkbox = item.querySelector('.temf-pick');
            if (checkbox) {
                checkbox.value = newUrl;
                if (result.id != null) {
                    checkbox.setAttribute('data-meta-id', result.id);
                } else if (active.objectId) {
                    checkbox.setAttribute('data-meta-id', active.objectId);
                }
            }

            var img = item.querySelector('.temf-thumb img');
            if (img) {
                if (img.dataset) {
                    img.dataset.original = newPreviewUrl;
                    if (img.dataset.thumbnail !== undefined) {
                        img.dataset.thumbnail = result.thumbnail || newPreviewUrl;
                    }
                    if (img.dataset.src !== undefined) {
                        img.dataset.src = result.thumbnail || newPreviewUrl;
                    }
                }
                if (img.src && (img.src === oldUrl || img.src === active.oldPreviewUrl)) {
                    img.src = result.thumbnail || newPreviewUrl;
                }
            }

            var insertBtn = item.querySelector('[data-temf-insert]');
            if (insertBtn) {
                insertBtn.setAttribute('data-url', newUrl);
            }
            var copyBtn = item.querySelector('[data-temf-copy]');
            if (copyBtn) {
                copyBtn.setAttribute('data-url', newUrl);
            }

            if (span) {
                var editorNode = span.querySelector('.temf-rename-editor');
                if (editorNode && editorNode.parentNode) {
                    editorNode.parentNode.removeChild(editorNode);
                }

                var hintNode = this.active.hint;
                if (hintNode && hintNode.parentNode) {
                    hintNode.parentNode.removeChild(hintNode);
                }

                span.textContent = displayName;
                span.title = displayName;
                span.classList.remove('temf-name-editing');
                span.classList.add('temf-rename-success');
                span.setAttribute('data-full-name', newName);
            }

            var directoryValue = typeof result.directory === 'string'
                ? result.directory
                : (item.getAttribute('data-directory') || '');
            var normalizedDir = directoryValue ? directoryValue.replace(/^\/+/, '').replace(/\/+$/g, '') : '';
            var directorySpan = item.querySelector('.temf-directory');
            var existingSizeAttr = item.getAttribute('data-size') || (directorySpan ? directorySpan.getAttribute('data-size') : '');
            var sizeValue = existingSizeAttr ? Number(existingSizeAttr) : 0;
            if (!isFinite(sizeValue) || sizeValue <= 0) {
                sizeValue = 0;
            }
            var displayDirectory = formatDirectoryLabel(normalizedDir, sizeValue);
            if (directorySpan) {
                directorySpan.textContent = displayDirectory;
                directorySpan.setAttribute('title', displayDirectory);
                directorySpan.setAttribute('data-directory', normalizedDir);
                if (existingSizeAttr) {
                    directorySpan.setAttribute('data-size', existingSizeAttr);
                } else {
                    directorySpan.removeAttribute('data-size');
                }
            }
            if (existingSizeAttr) {
                item.setAttribute('data-size', existingSizeAttr);
            } else {
                item.removeAttribute('data-size');
            }

            var files = state.pagination.allFiles || [];
            var index = files.findIndex(function (file) { return file.url === oldUrl; });
            if (index !== -1) {
                invalidateDirectoryCaches();
                files[index] = Object.assign({}, files[index], {
                    url: newUrl,
                    preview_url: newPreviewUrl,
                    name: newName,
                    thumbnail: result.thumbnail || files[index].thumbnail,
                    size: files[index].size,
                    directory: normalizedDir,
                    id: result.id != null ? result.id : files[index].id
                });
            }

            if (isLocalStorage(TEMF_CONF.source) && TEMF_CONF.data) {
                var yearSel = byId('temf-year');
                var monthSel = byId('temf-month');
                if (yearSel && monthSel && yearSel.value && monthSel.value) {
                    var year = yearSel.value;
                    var month = monthSel.value;
                    if (TEMF_CONF.data[year] && Array.isArray(TEMF_CONF.data[year][month])) {
                        var monthFiles = TEMF_CONF.data[year][month];
                        for (var i = 0; i < monthFiles.length; i++) {
                            if (monthFiles[i].url === oldUrl) {
                                monthFiles[i] = Object.assign({}, monthFiles[i], {
                                    url: newUrl,
                                    name: newName
                                });
                                break;
                            }
                        }
                    }
                }
            }

            if (state.selected.has(oldUrl)) {
                var meta = state.selectedMeta.get(oldUrl) || {};
                if (result.id != null) {
                    meta.id = result.id;
                } else if (active.objectId) {
                    meta.id = active.objectId;
                }
                state.selected.delete(oldUrl);
                state.selected.add(newUrl);
                state.selectedMeta.delete(oldUrl);
                state.selectedMeta.set(newUrl, meta);
            }

            this.active = null;
            selection.updateButton();

            setTimeout(function () {
                span.classList.remove('temf-rename-success');
            }, 1200);
        }
    };

    function createUiDataModule() {
        return {
            normalizeFiles: function (files) {
                var list = Array.isArray(files) ? files : [];
                list = list.map(function (file) {
                    if (file && typeof file === 'object') {
                        var resolvedSize = resolveFileSize(file);
                        if (resolvedSize > 0) {
                            file.size = resolvedSize;
                        }
                    }
                    return file;
                });

                var seenKeys = new Set();
                var uniqueFiles = [];
                list.forEach(function (file) {
                    if (!file) return;
                    var key = file.url ? ('url:' + file.url) : (file.name ? ('name:' + file.name) : null);
                    if (!key) {
                        try {
                            key = JSON.stringify(file);
                        } catch (e) {
                            key = String(uniqueFiles.length);
                        }
                    }
                    if (!seenKeys.has(key)) {
                        seenKeys.add(key);
                        uniqueFiles.push(file);
                    }
                });
                return uniqueFiles;
            },

            filterFilesForCurrentPath: function (files) {
                var activeStorage = isMultiMode()
                    ? String(state.currentStorage || '').toLowerCase()
                    : String(TEMF_CONF.source || '').toLowerCase();

                var targetPath = state.currentPath ? normalizeDirectoryPath(state.currentPath).toLowerCase() : '';
                var shouldFilter = !!targetPath && !isLskyStorage(activeStorage);

                return shouldFilter ? files.filter(function (file) {
                    var fileDir = extractFileDirectory(file);
                    var normalizedFileDir = normalizeDirectoryPath(fileDir).toLowerCase();
                    return normalizedFileDir === targetPath || normalizedFileDir.indexOf(targetPath + '/') === 0;
                }) : files;
            },

            normalizeFolders: function (folders, files) {
                var normalizedFolders = Array.isArray(folders) ? folders.filter(function (folder) {
                    return folder && (folder.path || folder.name);
                }).map(function (folder) {
                    return {
                        name: folder.name || folder.path || '',
                        path: normalizeDirectoryPath(folder.path || folder.name || ''),
                        folderCount: (typeof folder.folderCount === 'number') ? folder.folderCount : null,
                        fileCount: (typeof folder.fileCount === 'number') ? folder.fileCount : null
                    };
                }) : [];

                var currentPath = normalizeDirectoryPath(state.currentPath || '');
                var source = getEffectiveStorageSource();
                if (currentPath === '' && isCloudDirectoryStorage(source)) {
                    var hints = state.rootFolderHints[source] || {};
                    Object.keys(hints).forEach(function (rootFolder) {
                        var exists = normalizedFolders.some(function (folder) {
                            return folder.path === rootFolder;
                        });
                        if (!exists) {
                            normalizedFolders.push({
                                name: rootFolder,
                                path: rootFolder,
                                folderCount: null,
                                fileCount: null
                            });
                        }
                    });

                }

                normalizedFolders.sort(function (a, b) {
                    return String(a.name || '').localeCompare(String(b.name || ''), 'zh-Hans-CN', { numeric: true, sensitivity: 'base' });
                });

                return normalizedFolders;
            },

            getEmptyTip: function () {
                var source = getEffectiveStorageSource();
                var currentPath = normalizeDirectoryPath(state.currentPath || '');
                if (isLocalStorage(source) && isMonthPath(currentPath)) {
                    return '当前目录无内容（当前为 ' + currentPath + '，上传后将自动创建并展示该月目录）';
                }
                return '当前目录无内容';
            },

            updatePaginationState: function (filteredFiles, meta) {
                if (meta && meta.server_paged) {
                    var currentSource = getEffectiveStorageSource();
                    state.remotePagination.enabled = true;
                    state.remotePagination.storage = currentSource;
                    state.remotePagination.path = normalizeDirectoryPath(state.currentPath || '');
                    state.remotePagination.currentToken = meta.page_token || '';
                    state.remotePagination.nextToken = meta.next_token || '';
                    state.remotePagination.hasMore = !!meta.has_more;
                    state.remotePagination.pageSize = parseInt(meta.page_size, 10) || calculatePageSize();
                    state.remotePagination.pageNumber = state.remotePagination.history.length + 1;

                    state.pagination.pageSize = Math.max(filteredFiles.length, 1);
                    state.pagination.allFiles = filteredFiles;
                    state.pagination.totalItems = filteredFiles.length;
                    state.pagination.currentPage = 1;
                    return;
                }

                state.remotePagination.enabled = false;
                state.remotePagination.nextToken = '';
                state.remotePagination.hasMore = false;
                state.remotePagination.currentToken = '';
                state.remotePagination.history = [];
                state.remotePagination.pageNumber = 1;
                state.remotePagination.path = normalizeDirectoryPath(state.currentPath || '');
                state.remotePagination.storage = getEffectiveStorageSource();
                state.pagination.pageSize = calculatePageSize();
                state.pagination.allFiles = filteredFiles;
                state.pagination.totalItems = filteredFiles.length;
                state.pagination.currentPage = 1;
            },

            getPageFiles: function () {
                var files = state.pagination.allFiles;
                var currentPage = state.pagination.currentPage;
                var pageSize = state.pagination.pageSize;
                if (!pageSize || pageSize <= 0) {
                    pageSize = files.length || 1;
                }
                if (state.remotePagination.enabled) {
                    return files.slice();
                }
                var startIndex = (currentPage - 1) * pageSize;
                var endIndex = Math.min(startIndex + pageSize, files.length);
                return files.slice(startIndex, endIndex);
            },

            buildFolderItems: function (folders) {
                var folderItems = folders.slice();
                var source = getEffectiveStorageSource();
                var effectivePath = normalizeDirectoryPath(state.currentPath || '');
                if (isCloudDirectoryStorage(source)) {
                    var dirSelect = byId('temf-dir');
                    if (dirSelect) {
                        effectivePath = normalizeDirectoryPath(dirSelect.value || effectivePath);
                    }
                }
                if (effectivePath !== '') {
                    folderItems.unshift({
                        name: '..',
                        path: getParentPath(effectivePath, source),
                        isUp: true
                    });
                }
                return folderItems;
            }
        };
    }

    var uiData = createUiDataModule();

    function createUiRenderModule() {
        return {
            escapeHtml: function (text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            },

            renderFolderItem: function (folder, getFolderSubtitle) {
                var name = folder && folder.name ? String(folder.name) : '/';
                var path = folder && folder.path ? normalizeDirectoryPath(folder.path) : '';
                var isUp = !!(folder && folder.isUp);
                var safePath = this.escapeHtml(path);
                var displayName = isUp ? '返回上级' : name;
                var safeName = this.escapeHtml(displayName);
                var subtitle = isUp ? '' : getFolderSubtitle(folder);
                var safeSubtitle = this.escapeHtml(subtitle);

                return '<li class="temf-item temf-folder-item" data-temf-folder="1" data-path="' + safePath + '">' +
                    '<div class="temf-folder-icon" aria-hidden="true"></div>' +
                    '<span class="temf-folder-name" title="' + safeName + '">' + safeName + '</span>' +
                    (safeSubtitle ? '<span class="temf-directory" title="' + safeSubtitle + '">' + safeSubtitle + '</span>' : '') +
                    '</li>';
            },

            getLoadingGifUrl: function () {
                if (!this._loadingGifUrl) {
                    var sec = byId('temediafolder');
                    if (sec) {
                        var pluginUrl = sec.getAttribute('data-plugin-url');
                        if (pluginUrl) {
                            this._loadingGifUrl = pluginUrl + '/assets/loading.gif';
                        } else {
                            var scripts = document.getElementsByTagName('script');
                            for (var i = 0; i < scripts.length; i++) {
                                var src = scripts[i].src;
                                if (src && src.indexOf('temediafolder.js') !== -1) {
                                    this._loadingGifUrl = src.replace('js/temediafolder.js', 'loading.gif');
                                    break;
                                }
                            }
                        }
                    }
                    if (!this._loadingGifUrl) {
                        this._loadingGifUrl = '../assets/loading.gif';
                    }
                }
                return this._loadingGifUrl;
            },

            getCachedStorageType: function () {
                if (!this._cachedStorageType) {
                    this._cachedStorageType = getEffectiveStorageSource();
                }
                return this._cachedStorageType;
            },

            clearCache: function () {
                this._cachedStorageType = null;
            },

            isImageFile: function (fileName) {
                if (!fileName) return false;
                if (!this._imageExtensions) {
                    this._imageExtensions = {
                        'jpg': true, 'jpeg': true, 'png': true,
                        'gif': true, 'webp': true, 'bmp': true
                    };
                }
                var lastDot = fileName.lastIndexOf('.');
                if (lastDot === -1) return false;
                var extension = fileName.substring(lastDot + 1).toLowerCase();
                return !!this._imageExtensions[extension];
            },

            getThumbnailUrl: function (item) {
                if (item._cachedThumbnail) {
                    return item._cachedThumbnail;
                }

                var url = item.preview_url || item.previewUrl || item.url || '';
                var thumbnail = item.thumbnail;
                if (thumbnail && thumbnail !== url) {
                    item._cachedThumbnail = thumbnail;
                    return thumbnail;
                }

                var currentSource = this.getCachedStorageType();
                if (this.isImageFile(item.name || '')) {
                    var result;
                    var thumbnailMode = getThumbnailMode(currentSource);
                    if (thumbnailMode === 'query-thumb' || thumbnailMode === 'bitiful') {
                        var thumbSize = DEFAULT_THUMB_SIZE;
                        var thumbParam = thumbnailMode === 'bitiful' ? '' : ('thumbnail=' + thumbSize + 'x' + thumbSize);
                        if (!thumbParam) {
                            result = url;
                        } else if (url.indexOf('?') !== -1) {
                            result = url + '&' + thumbParam;
                        } else {
                            result = url + '?' + thumbParam;
                        }
                    } else {
                        result = url;
                    }
                    item._cachedThumbnail = result;
                    return result;
                }

                item._cachedThumbnail = url;
                return url;
            },

            renderFileItem: function (item) {
                var url = item.url || '';
                var previewUrl = item.preview_url || item.previewUrl || url;
                var thumbnail = this.getThumbnailUrl(item);
                var name = item.name || 'unknown';
                var directory = typeof item.directory === 'string' ? item.directory : '';

                var safeUrl = this.escapeHtml(url);
                var safePreviewUrl = this.escapeHtml(previewUrl);
                var safeThumbnail = this.escapeHtml(thumbnail);
                var safeNameFull = this.escapeHtml(name);
                var normalizedDirectory = directory ? directory.replace(/^\/+|\/+$/g, '') : '';
                var safeDirectoryValue = this.escapeHtml(normalizedDirectory);
                var displayName = safeNameFull;
                var lastDot = name.lastIndexOf('.');
                if (lastDot > 0) {
                    displayName = this.escapeHtml(name.slice(0, lastDot));
                }

                var sizeValue = resolveFileSize(item);
                var sizeAttr = sizeValue > 0 ? String(sizeValue) : '';
                var directoryDisplay = formatDirectoryLabel(normalizedDirectory, sizeValue);
                var safeDirectoryDisplay = this.escapeHtml(directoryDisplay);
                var safeSizeAttr = this.escapeHtml(sizeAttr);
                var safeLoader = this.escapeHtml(this.getLoadingGifUrl());

                return '<li class="temf-item" data-url="' + safeUrl + '" data-directory="' + safeDirectoryValue + '" data-size="' + safeSizeAttr + '">' +
                    '<div class="temf-thumb">' +
                    '<input type="checkbox" class="temf-pick" value="' + safeUrl + '" data-meta-id="' + (item.id != null ? String(item.id).replace(/"/g, '&quot;') : '') + '">' +
                    '<img src="' + safeLoader + '" data-src="' + safeThumbnail + '" alt="' + safeNameFull + '" ' +
                    'class="temf-lazy-img" referrerpolicy="no-referrer" decoding="async" ' +
                    'data-original="' + safePreviewUrl + '" data-thumbnail="' + safeThumbnail + '" data-loader="' + safeLoader + '" ' +
                    'onerror="this.src=\'' + safePreviewUrl + '\';this.onerror=null;"/>' +
                    '</div>' +
                    '<div class="temf-meta">' +
                    '<span class="temf-name" data-full-name="' + safeNameFull + '" title="' + displayName + '">' + displayName + '</span>' +
                    '<span class="temf-directory" data-directory="' + safeDirectoryValue + '" data-size="' + safeSizeAttr + '" title="' + safeDirectoryDisplay + '">' + safeDirectoryDisplay + '</span>' +
                    '<div class="temf-actions">' +
                    '<button type="button" class="btn btn-xs temf-action-btn" data-temf-insert data-url="' + safeUrl + '">插入</button>' +
                    '<button type="button" class="btn btn-xs temf-action-btn" data-temf-copy data-url="' + safeUrl + '">复制</button>' +
                    '</div>' +
                    '</div>' +
                    '</li>';
            }
        };
    }

    var uiRender = createUiRenderModule();

    var ui = {
        renderFiles: function (files, folders, meta) {
            var body = document.querySelector('#temf-modal .temf-body');
            if (!body) {
                return;
            }

            meta = meta || {};

            var uniqueFiles = uiData.normalizeFiles(files);
            var filteredFiles = uiData.filterFilesForCurrentPath(uniqueFiles);
            state.currentFolders = uiData.normalizeFolders(folders, filteredFiles);

            if (filteredFiles.length === 0 && state.currentFolders.length === 0) {
                body.innerHTML = '<p class="description">' + uiData.getEmptyTip() + '</p>';
                selection.clear();
                pagination.hide();
                return;
            }

            uiData.updatePaginationState(filteredFiles, meta);
            this.renderCurrentPage();
        },

        renderCurrentPage: function () {
            var body = document.querySelector('#temf-modal .temf-body');
            if (!body) return;

            var files = state.pagination.allFiles;
            var folders = state.currentFolders || [];
            if ((!files || !files.length) && folders.length === 0) return;
            var pageFiles = uiData.getPageFiles();

            // 清空并创建网格
            if (rename && typeof rename.cancel === 'function') {
                rename.cancel(true);
            }
            var fragment = document.createDocumentFragment();
            body.innerHTML = '';

            var folderItems = uiData.buildFolderItems(folders);

            var folderGrid = null;
            if (folderItems.length) {
                folderGrid = document.createElement('ul');
                folderGrid.className = 'temf-grid temf-folder-grid';
                fragment.appendChild(folderGrid);
            }

            var grid = document.createElement('ul');
            grid.className = 'temf-grid temf-file-grid';
            fragment.appendChild(grid);
            body.appendChild(fragment);

            for (var f = 0; f < folderItems.length; f++) {
                folderGrid.insertAdjacentHTML('beforeend', uiRender.renderFolderItem(folderItems[f], this.getFolderSubtitle.bind(this)));
                this.requestFolderStats(folderItems[f]);
            }

            this.appendFileItemsInBatches(grid, pageFiles, 24);

            selection.clear();
            requestAnimationFrame(function () {
                grid.classList.add('temf-grid-loaded');
                if (!pageFiles.length) {
                    scheduleInitLazyLoading();
                }
            });

            if (upload && upload.stagedFiles && upload.stagedFiles.length) {
                upload.stagePanel.render();
                upload.stagePanel.show();
            }

            // 更新分页控件
            pagination.update();
        },

        appendFileItemsInBatches: function (grid, pageFiles, batchSize) {
            if (!grid || !pageFiles || !pageFiles.length) {
                return;
            }

            var index = 0;
            var size = Math.max(1, batchSize || 24);

            function appendBatch() {
                var end = Math.min(index + size, pageFiles.length);
                var htmlParts = [];
                for (var i = index; i < end; i++) {
                    htmlParts.push(uiRender.renderFileItem(pageFiles[i]));
                }

                if (htmlParts.length) {
                    var div = document.createElement('div');
                    div.innerHTML = htmlParts.join('');
                    while (div.firstChild) {
                        grid.appendChild(div.firstChild);
                    }
                }

                index = end;
                if (index < pageFiles.length) {
                    requestAnimationFrame(appendBatch);
                } else {
                    scheduleInitLazyLoading();
                }
            }

            appendBatch();
        },

        getFolderSubtitle: function (folder) {
            var folderCount = folder && typeof folder.folderCount === 'number' ? folder.folderCount : null;
            var fileCount = folder && typeof folder.fileCount === 'number' ? folder.fileCount : null;
            var source = getEffectiveStorageSource();
            var key = source + ':' + normalizeDirectoryPath(folder && folder.path ? folder.path : '');

            if (isServerPagedStorage(source)) {
                return '';
            }

            if ((folderCount === null || fileCount === null) && state.folderStatsCache[key] && !state.folderStatsCache[key].loading) {
                folderCount = state.folderStatsCache[key].folderCount;
                fileCount = state.folderStatsCache[key].fileCount;
            }

            if (folderCount === null || fileCount === null) {
                return '加载统计中';
            }

            return folderCount + ' 文件夹 / ' + fileCount + ' 图片';
        },

        requestFolderStats: function (folder) {
            if (!folder || folder.isUp) return;

            var source = getEffectiveStorageSource();
            var folderPath = normalizeDirectoryPath(folder.path || '');
            if (!folderPath) return;

            if (typeof folder.folderCount === 'number' && typeof folder.fileCount === 'number') {
                return;
            }

            if (!isServerPagedStorage(source)) {
                return;
            }

            var key = source + ':' + folderPath;
            if (state.folderStatsCache[key]) {
                return;
            }

            state.folderStatsCache[key] = { loading: true };
            if (folderStatsTimer) {
                clearTimeout(folderStatsTimer);
            }
            var fetchFn = isMultiMode()
                ? multi.fetch
                : function (path, callback, options) { cloudStorage.fetch(source, path, callback, options); };

            folderStatsTimer = setTimeout(function () {
                folderStatsTimer = null;
                fetchFn(folderPath, function (data) {
                    state.folderStatsCache[key] = {
                        loading: false,
                        folderCount: Array.isArray(data && data.folders) ? data.folders.length : 0,
                        fileCount: Array.isArray(data && data.files) ? data.files.length : 0
                    };
                    ui.renderCurrentPage();
                });
            }, 120);
        },

        prependFile: function (fileItem) {
            // 检查文件是否已存在（避免重复显示）
            if (state.pagination.allFiles && state.pagination.allFiles.length > 0) {
                var existingIndex = state.pagination.allFiles.findIndex(function (file) {
                    // 通过 URL 或文件名判断是否为同一文件
                    return file.url === fileItem.url || file.name === fileItem.name;
                });

                if (existingIndex !== -1) {
                    // 文件已存在，更新而不是添加
                    state.pagination.allFiles[existingIndex] = fileItem;
                } else {
                    // 文件不存在，添加到开头
                    state.pagination.allFiles.unshift(fileItem);
                    state.pagination.totalItems = state.pagination.allFiles.length;
                }
            } else {
                // 首次添加文件
                state.pagination.allFiles = [fileItem];
                state.pagination.totalItems = 1;
            }

            // 如果在第一页，需要重新渲染当前页来显示新文件
            if (state.pagination.currentPage === 1) {
                this.renderCurrentPage();
            } else {
                // 不在第一页，只更新分页信息
                pagination.update();
            }

            selection.clear();
        }
    };

    var selection = {
        clear: function () {
            state.selected.clear();
            state.selectedMeta.clear();
            this.updateButton();
        },

        add: function (url, meta) {
            state.selected.add(url);
            state.selectedMeta.set(url, meta || {});
            this.updateButton();
        },

        remove: function (url) {
            state.selected.delete(url);
            state.selectedMeta.delete(url);
            this.updateButton();
        },

        updateButton: function () {
            var btn = byId("temf-insert-selected");
            if (!btn) return;

            btn.disabled = state.selected.size === 0;
            toggleDeleteButtons(state.selected.size > 0);
        }
    };

    var fileOps = {
        insert: function (url) {
            var name = url.split('/').pop().split('?')[0];
            var alt = decodeURIComponent(name).replace(/\.[a-z0-9]+$/i, '');
            var text = '![' + alt + '](' + url + ')';

            insertAtCursor(getEditor(), text);
            modal.close();
        },

        insertSelected: function () {
            if (state.selected.size === 0) return;

            var urls = Array.from(state.selected);
            var block = '[photos]\n' + urls.map(function (url) {
                var name = url.split('/').pop().split('?')[0];
                var alt = decodeURIComponent(name).replace(/\.[a-z0-9]+$/i, '');
                return '![' + alt + '](' + url + ')';
            }).join('\n') + '\n[/photos]';

            insertAtCursor(getEditor(), block);
            selection.clear();
            modal.close();
        },

        copy: function (url, button) {
            if (button && button.hasAttribute('data-temf-delete')) {
                deleteOps.start([url]);
                return;
            }

            var showCopied = function (btn) {
                try {
                    var originalText = btn.textContent;
                    btn.textContent = TEMF_CONF && TEMF_CONF.labels && TEMF_CONF.labels.copied ? TEMF_CONF.labels.copied : '已复制';
                    setTimeout(function () {
                        btn.textContent = originalText;
                    }, 1200);
                } catch (e) { }
            };

            var fallbackCopy = function () {
                try {
                    var temp = document.createElement('input');
                    temp.style.position = 'fixed';
                    temp.style.opacity = '0';
                    temp.value = url;
                    document.body.appendChild(temp);
                    temp.select();
                    document.execCommand('copy');
                    document.body.removeChild(temp);
                    showCopied(button);
                } catch (err) { }
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    showCopied(button);
                }).catch(fallbackCopy);
            } else {
                fallbackCopy();
            }
        },

        upload: function () {
            // 多模式下必须先选择目标存储
            if (TEMF_CONF && isMultiMode()) {
                if (!state.currentStorage) {
                    console.warn('[TEMF] 多模式下未选择存储类型');
                    alert('请先选择存储类型');
                    return;
                }
            }

            // 每次点击都重新创建输入，避免在某些浏览器/切换后不触发change
            var old = document.getElementById('temf-hidden-file');
            if (old && old.parentNode) {
                old.parentNode.removeChild(old);
            }

            var fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.id = 'temf-hidden-file';
            fileInput.style.display = 'none';
            fileInput.accept = 'image/*';
            fileInput.multiple = true; // 支持多选
            document.body.appendChild(fileInput);

            fileInput.addEventListener('change', function () {
                if (this.files && this.files.length > 0) {
                    upload.stageFiles(Array.from(this.files));
                } else {
                    console.warn('[TEMF] 未选择文件');
                }
            });

            fileInput.click();
        }
    };

    function createUploadStagePanelModule(uploadApi) {
        return {
            getCurrentTargetLabel: function () {
                var path = normalizeDirectoryPath(uploadApi.getCurrentPath());
                var storage = uploadApi.getCurrentStorageType();
                var storageName = storageRegistry[storage] && storageRegistry[storage].label ? storageRegistry[storage].label : storage;
                return storageName + ' / 将上传到 ' + getComputedUploadPath(storage, path);
            },

            show: function () {
                var panel = document.querySelector('#temf-modal .temf-upload-panel');
                if (panel) panel.classList.add('show');
                var dialog = document.querySelector('#temf-modal .temf-dialog');
                if (dialog) dialog.classList.add('temf-upload-staging');
            },

            hide: function () {
                var panel = document.querySelector('#temf-modal .temf-upload-panel');
                if (panel) panel.classList.remove('show');
                var dialog = document.querySelector('#temf-modal .temf-dialog');
                if (dialog) dialog.classList.remove('temf-upload-staging');
            },

            render: function () {
                var dialog = document.querySelector('#temf-modal .temf-dialog');
                if (!dialog) return;

                var panel = dialog.querySelector('.temf-upload-panel');
                if (!panel) {
                    panel = document.createElement('div');
                    panel.className = 'temf-upload-panel';
                    panel.innerHTML = '' +
                        '<div class="temf-upload-panel-header">' +
                            '<div>' +
                                '<div class="temf-upload-panel-title">待上传文件</div>' +
                                '<div class="temf-upload-panel-subtitle"></div>' +
                            '</div>' +
                            '<div class="temf-upload-panel-actions">' +
                                '<button type="button" class="btn btn-xs" data-temf-stage-pick>继续选择</button>' +
                                '<button type="button" class="btn btn-xs" data-temf-stage-clear>清空列表</button>' +
                                '<button type="button" class="temf-upload-panel-close" data-temf-stage-cancel aria-label="取消上传">&times;</button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="temf-upload-list"></div>' +
                        '<div class="temf-upload-panel-footer">' +
                            '<span class="temf-upload-panel-subtitle" data-temf-stage-count></span>' +
                            '<button type="button" class="btn primary" data-temf-stage-start>开始上传</button>' +
                        '</div>';
                    dialog.appendChild(panel);
                }

                var subtitle = panel.querySelector('.temf-upload-panel-subtitle');
                if (subtitle) subtitle.textContent = '上传到 ' + this.getCurrentTargetLabel();

                var count = panel.querySelector('[data-temf-stage-count]');
                if (count) count.textContent = '共 ' + uploadApi.stagedFiles.length + ' 个文件';

                var list = panel.querySelector('.temf-upload-list');
                if (!list) return;
                list.innerHTML = '';

                if (!uploadApi.stagedFiles.length) {
                    var empty = document.createElement('div');
                    empty.className = 'temf-upload-panel-empty';
                    empty.textContent = '拖拽图片到素材库，或点击继续选择';
                    list.appendChild(empty);
                } else {
                    for (var i = 0; i < uploadApi.stagedFiles.length; i++) {
                        var staged = uploadApi.stagedFiles[i];
                        var file = staged.file;
                        var item = document.createElement('div');
                        item.className = 'temf-upload-item';
                        item.innerHTML = '' +
                            '<span class="temf-upload-thumb">' + (staged.previewUrl ? ('<img src="' + uploadApi.escapeHtml(staged.previewUrl) + '" alt="">') : '') + '</span>' +
                            '<span class="temf-upload-name" title="' + uploadApi.escapeHtml(file.name) + '">' + uploadApi.escapeHtml(file.name) + '</span>' +
                            '<span class="temf-upload-meta">' + uploadApi.formatBytes(file.size || 0) + '</span>' +
                            '<span class="temf-upload-status">等待上传</span>' +
                            '<button type="button" class="temf-upload-remove" data-temf-stage-remove="' + i + '">取消</button>';
                        list.appendChild(item);
                    }
                }

                var startBtn = panel.querySelector('[data-temf-stage-start]');
                if (startBtn) startBtn.disabled = !uploadApi.stagedFiles.length;
            }
        };
    }

    function createUploadModule() {
        return {
        stagePanel: null,
        stagedFiles: [],
        queue: [],
        currentIndex: 0,
        totalFiles: 0,
        currentXhr: null,
        isCancelled: false,

        stageFiles: function (files) {
            if (!files || !files.length) {
                return;
            }

            var validFiles = files.filter(function (file) {
                return !!(file && file.type && file.type.indexOf('image/') === 0);
            });

            if (!validFiles.length) {
                try { alert('仅支持图片文件'); } catch (e) {}
                return;
            }

            for (var i = 0; i < validFiles.length; i++) {
                var file = validFiles[i];
                this.stagedFiles.push({
                    file: file,
                    previewUrl: this.createPreviewUrl(file)
                });
            }
            this.stagePanel.render();
            this.stagePanel.show();
        },

        clearStagedFiles: function () {
            this.releaseStagePreviewUrls();
            this.stagedFiles = [];
            this.stagePanel.render();
            this.stagePanel.hide();
        },

        cancelStagePanel: function () {
            this.clearStagedFiles();
        },

        removeStagedFile: function (index) {
            if (index < 0 || index >= this.stagedFiles.length) return;
            this.revokePreviewUrl(this.stagedFiles[index]);
            this.stagedFiles.splice(index, 1);
            this.stagePanel.render();
            if (!this.stagedFiles.length) {
                this.stagePanel.hide();
            }
        },

        startStagedUpload: function () {
            if (!this.stagedFiles.length) return;
            if (!this.validateMultiModeState()) {
                try { alert('请先选择可用的存储类型'); } catch (e) {}
                return;
            }
            var files = this.stagedFiles.map(function (item) { return item.file; });
            this.releaseStagePreviewUrls();
            this.stagedFiles = [];
            this.stagePanel.render();
            this.stagePanel.hide();
            this.handleMultipleFiles(files);
        },

        createPreviewUrl: function (file) {
            if (!file || typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function') {
                return '';
            }
            try {
                return URL.createObjectURL(file);
            } catch (e) {
                return '';
            }
        },

        revokePreviewUrl: function (item) {
            if (!item || !item.previewUrl || typeof URL === 'undefined' || typeof URL.revokeObjectURL !== 'function') {
                return;
            }
            try {
                URL.revokeObjectURL(item.previewUrl);
            } catch (e) {}
            item.previewUrl = '';
        },

        releaseStagePreviewUrls: function () {
            for (var i = 0; i < this.stagedFiles.length; i++) {
                this.revokePreviewUrl(this.stagedFiles[i]);
            }
        },

        escapeHtml: function (text) {
            var div = document.createElement('div');
            div.textContent = String(text || '');
            return div.innerHTML;
        },

        formatBytes: function (bytes) {
            bytes = Number(bytes || 0);
            if (bytes <= 0) return '0 B';
            var units = ['B', 'KB', 'MB', 'GB'];
            var i = 0;
            while (bytes >= 1024 && i < units.length - 1) {
                bytes /= 1024;
                i++;
            }
            return (i === 0 ? Math.round(bytes) : bytes.toFixed(1)) + ' ' + units[i];
        },

        dispatchUploadBySource: function (file, currentSource, isBatch) {
            if (isMultiMode()) {
                this.uploadToCloudStorage(file, isBatch, 'multi');
                return;
            }

            if (getStorageUploadAttr(currentSource)) {
                this.uploadToCloudStorage(file, isBatch, currentSource);
            } else if (isLskyStorage(currentSource)) {
                this.uploadToLsky(file, isBatch);
            } else {
                this.uploadLocal(file, isBatch);
            }
        },

        sendUploadRequest: function (uploadUrl, formData, file, isBatch, handlers) {
            var self = this;
            var xhr = new XMLHttpRequest();
            xhr.timeout = 120000;

            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                    var percent = (e.loaded / e.total) * 100;
                    progress.updateFileProgress(percent);
                }
            });

            xhr.addEventListener('load', function () {
                if (handlers && typeof handlers.load === 'function') {
                    handlers.load(xhr);
                }
            });

            xhr.addEventListener('error', function () {
                if (handlers && typeof handlers.error === 'function') {
                    handlers.error(xhr);
                } else {
                    self.handleUploadError('网络错误', file, isBatch);
                }
            });

            xhr.addEventListener('timeout', function () {
                if (handlers && typeof handlers.timeout === 'function') {
                    handlers.timeout(xhr);
                } else {
                    self.handleUploadError('上传超时', file, isBatch);
                }
            });

            xhr.open('POST', uploadUrl);
            xhr.send(formData);
            return xhr;
        },

        handleLskyUploadResponse: function (xhr, file, isBatch) {
            var text = (xhr && typeof xhr.responseText === 'string') ? xhr.responseText : '';
            var result = null;
            var success = false;
            if (text && text.trim().charAt(0) === '{') {
                try {
                    result = JSON.parse(text);
                    success = !!(result && result.ok && result.url);
                } catch (e) {
                    result = { ok: false, msg: '解析响应失败' };
                }
            } else {
                result = { ok: false, msg: (text ? '非JSON响应' : '空响应') };
            }

            if (success) {
                progress.updateFileProgress(100);
                ui.prependFile({
                    name: result.name || file.name,
                    url: result.url,
                    id: result.id || result.key || '',
                    thumbnail: result.thumbnail,
                    size: result.size || 0
                });
            } else {
                var msg = (result && (result.msg || result.message)) ? (result.msg || result.message) : '上传失败';
                progress.setError && progress.setError(msg);
                progress.hide();
            }

            if (isBatch) {
                this.onUploadComplete(success, file.name);
            }
        },

        handleLskyUploadFailure: function (message, file, isBatch) {
            progress.setError && progress.setError(message);
            progress.hide();
            if (isBatch) {
                this.onUploadComplete(false, file.name);
            }
        },

        resolveLocalUploadTarget: function (result) {
            var year = result.year;
            var month = result.month;

            if (!year || !month) {
                var ySel = byId('temf-year');
                var mSel = byId('temf-month');
                if (ySel && ySel.value && mSel && mSel.value) {
                    year = ySel.value;
                    var monthValue = mSel.value;
                    month = monthValue.length === 1 ? '0' + monthValue : monthValue;
                } else {
                    var nowYM = getNowYearMonth();
                    year = nowYM.year;
                    month = nowYM.month;
                }
            }

            return { year: year, month: month };
        },

        handleLocalUploadSuccess: function (result) {
            invalidateDirectoryCaches();
            progress.updateFileProgress(100);

            var target = this.resolveLocalUploadTarget(result);
            var year = target.year;
            var month = target.month;

            TEMF_CONF.data = TEMF_CONF.data || {};
            TEMF_CONF.data[year] = TEMF_CONF.data[year] || {};
            TEMF_CONF.data[year][month] = TEMF_CONF.data[year][month] || [];

            var newItem = {
                url: result.url,
                name: result.name,
                mtime: Math.floor(Date.now() / 1000),
                size: resolveFileSize(result) || 0,
                size_human: result.size_human || formatFileSize(resolveFileSize(result) || 0)
            };

            var list = TEMF_CONF.data[year][month];
            list = [newItem].concat(list.filter(function (item) {
                return item.url !== result.url;
            }));
            TEMF_CONF.data[year][month] = list;

            var modalEl = document.getElementById('temf-modal');
            if (modalEl && modalEl.classList.contains('open') && isLocalStorage(getEffectiveStorageSource())) {
                var targetMonthPath = year + '/' + month;
                var normalizedCurrent = normalizeDirectoryPath(state.currentPath || '');
                if (normalizedCurrent === targetMonthPath || normalizedCurrent.indexOf(targetMonthPath + '/') === 0) {
                    ui.prependFile(newItem);
                } else {
                    localBrowser.navigate(targetMonthPath);
                }
            }

            return true;
        },

        handleLocalUploadFailure: function (message, file, isBatch) {
            if (!isBatch) {
                alert('上传失败: ' + message);
            } else if (progress.setError) {
                progress.setError(message);
            }
            if (isBatch) {
                this.onUploadComplete(false, file.name);
            }
        },

        handleFile: function (file) {
            if (!this.validateMultiModeState()) {
                // invalid multi-mode
                return;
            }

            var currentSource = this.getCurrentStorageType();
            this.dispatchUploadBySource(file, currentSource, false);
        },

        handleMultipleFiles: function (files) {
            this.queue = files;
            this.currentIndex = 0;
            this.totalFiles = files.length;
            this.isCancelled = false;
            this.currentXhr = null;
            this.uploadFailedCount = 0;  // 重置失败计数

            if (!this.totalFiles) {
                return;
            }

            var firstFile = files[0] || null;
            var firstName = firstFile && firstFile.name ? firstFile.name : '上传文件';

            progress.show(firstName, this.totalFiles);
            progress.update(0, this.totalFiles, firstName);
            progress.setCancelHandler(this.cancel.bind(this));

            this.uploadNext();
        },

        cancel: function () {
            var xhr = this.currentXhr;
            if (xhr) {
                try {
                    xhr.abort();
                } catch (e) {
                    console.warn('[TEMF] 取消上传失败', e);
                }
            }
            this.isCancelled = true;
            this.queue = [];
            this.currentXhr = null;
            progress.setStatusMessage('上传已取消', { variant: 'warning' });
            progress.setCancelHandler(null);
            setTimeout(function () {
                progress.hide();
            }, 500);
        },

        finishAfterCancel: function () {
            progress.hide();
        },

        uploadNext: function () {
            if (this.isCancelled) {
                this.finishAfterCancel();
                return;
            }

            if (this.currentIndex >= this.queue.length) {
                progress.finish();
                return;
            }

            if (!this.validateMultiModeState()) {
                console.error('[TEMF] 多模式状态验证失败');
                progress.hide();
                return;
            }

            var file = this.queue[this.currentIndex];

            progress.update(this.currentIndex, this.totalFiles, file.name);

            var currentSource = this.getCurrentStorageType();
            this.dispatchUploadBySource(file, currentSource, true);
        },

        getCurrentStorageType: function () {
            // 多模式下返回当前选中的存储类型，单模式下返回配置的存储类型
            if (isMultiMode() && state.currentStorage) {
                return state.currentStorage;
            }
            return TEMF_CONF.source;
        },

        validateMultiModeState: function () {
            // 验证多模式状态是否有效
            if (isMultiMode()) {
                if (!state.currentStorage) {
                    // multi no storage
                    return false;
                }

                if (!state.availableStorages || state.availableStorages.length === 0) {
                    // multi no storages
                    return false;
                }

                // 检查当前存储是否在可用列表中
                var isValidStorage = state.availableStorages.some(function (storage) {
                    return storage.key === state.currentStorage;
                });

                if (!isValidStorage) {
                    // multi storage invalid
                    return false;
                }
            }

            return true;
        },

        onUploadComplete: function (success, fileName) {
            this.currentXhr = null;
            this.currentIndex++;

            // 记录上传失败次数
            if (!success) {
                this.uploadFailedCount = (this.uploadFailedCount || 0) + 1;
            } else {
                state.uploadErrors = 0;
            }

            var self = this;
            setTimeout(function () {
                self.uploadNext();
            }, 300);
        },

        /**
         * 通用云存储上传方法 - 合并COS/OSS/多模式上传逻辑
         */
        uploadToCloudStorage: function (file, isBatch, storageType) {
            var self = this;
            var sec = byId('temediafolder');
            var attrName = getStorageUploadAttr(storageType === 'multi' ? state.currentStorage : storageType) || 'data-multi-upload';
            if (storageType === 'multi') {
                attrName = 'data-multi-upload';
            }

            var uploadUrl = sec ? sec.getAttribute(attrName) : null;

            if (!uploadUrl) {
                console.error('[TEMF] 无法获取上传URL，属性名:', attrName);
                alert('上传失败: 无法获取上传URL');
                if (isBatch) self.onUploadComplete(false, file.name);
                return;
            }

            var formData = new FormData();
            formData.append('file', file);

            // 多模式需要额外参数
            if (storageType === 'multi') {
                formData.append('storage_type', state.currentStorage);
            }

            var path = this.getCurrentPath();
            formData.append('temf_path', path);

            this.currentXhr = this.sendUploadRequest(uploadUrl, formData, file, isBatch, {
                load: function (xhr) {
                    self.handleUploadResponse(xhr, file, isBatch);
                }
            });
        },

        /**
         * 处理上传响应
         */
        handleUploadResponse: function (xhr, file, isBatch) {
            try {
                var parsedResult = this.parseCloudUploadResponse(xhr);
                if (!parsedResult.ok) {
                    this.handleCloudUploadFailure(parsedResult.message, file, isBatch);
                    return;
                }

                var result = parsedResult.result;
                var success = parsedResult.success;

                if (success) {
                    this.handleCloudUploadSuccess(result, file, isBatch);
                } else {
                    var msg = (result && (result.msg || result.message)) ? (result.msg || result.message) : '上传失败';
                    this.handleCloudUploadFailure(msg, file, isBatch);
                }

                if (isBatch) {
                    this.onUploadComplete(success, file.name);
                }
            } catch (e) {
                this.handleCloudUploadFailure('上传失败: ' + e.message, file, isBatch);
            }
        },

        parseCloudUploadResponse: function (xhr) {
            var text = (xhr && typeof xhr.responseText === 'string') ? xhr.responseText : '';
            var parsed = null;
            if (text && text.trim().charAt(0) === '{') {
                try {
                    parsed = JSON.parse(text);
                } catch (e) {
                    parsed = null;
                }
            }

            if (xhr.status < 200 || xhr.status >= 300) {
                var errorMsg = '上传失败 (HTTP ' + xhr.status + ')';
                if (parsed && (parsed.msg || parsed.message)) {
                    errorMsg = parsed.msg || parsed.message;
                } else if (text) {
                    var normalized = text.replace(/\s+/g, ' ').trim();
                    errorMsg += ': ' + normalized.substring(0, 120);
                }
                return { ok: false, message: errorMsg };
            }

            if (!parsed) {
                return { ok: false, message: '服务器返回非 JSON 响应' };
            }

            return {
                ok: true,
                result: parsed,
                success: !!(parsed.ok && parsed.url)
            };
        },

        handleCloudUploadSuccess: function (result, file, isBatch) {
            invalidateDirectoryCaches();
            progress.updateFileProgress(100);

            var resolvedSize = resolveFileSize(result) || (file && file.size) || 0;

            var newFile = {
                name: result.name || file.name,
                url: result.url,
                preview_url: result.preview_url || result.previewUrl || result.url,
                thumbnail: result.thumbnail,
                size: resolvedSize,
                size_human: result.size_human || formatFileSize(resolvedSize),
                directory: normalizeDirectoryPath(result.directory || '')
            };

            var currentSource = this.getCurrentStorageType();
            var currentPath = normalizeDirectoryPath(this.getCurrentPath());
            var targetDirectory = normalizeDirectoryPath(newFile.directory || '');
            rememberRootFolderHint(currentSource, targetDirectory);

            if (isCloudDirectoryStorage(currentSource) && targetDirectory !== currentPath) {
                navigateCloudPath(targetDirectory, currentSource, { bustCache: true, forceRefresh: true });
            } else {
                ui.prependFile(newFile);
            }

            if (!isBatch) {
                setTimeout(function () {
                    progress.hide();
                }, 1000);
            }
        },

        handleCloudUploadFailure: function (message, file, isBatch) {
            this.handleUploadError(message, file, isBatch);
        },

        /**
         * 处理上传错误
         */
        handleUploadError: function (msg, file, isBatch) {
            console.error('[Upload Error]', msg, file.name);

            var message = msg || '未知错误';

            if (progress.setError) {
                progress.setError(message);
            }

            if (!isBatch) {
                try {
                    alert('上传失败: ' + message);
                } catch (e) { }
                setTimeout(function () {
                    progress.hide();
                }, 2000);
            } else {
                this.onUploadComplete(false, file.name);
            }
        },

        uploadToLsky: function (file, isBatch) {
            var self = this;
            var sec = byId('temediafolder');
            var uploadUrl = sec ? sec.getAttribute(getStorageUploadAttr('lsky')) : null;
            if (!uploadUrl) {
                if (isBatch) self.onUploadComplete(false, file.name);
                return;
            }

            var path = this.getCurrentPath();
            var formData = new FormData();
            formData.append('file', file);
            formData.append('temf_path', path);

            this.currentXhr = this.sendUploadRequest(uploadUrl, formData, file, isBatch, {
                load: function (xhr) {
                    self.handleLskyUploadResponse(xhr, file, isBatch);
                },
                error: function () {
                    self.handleLskyUploadFailure('网络错误', file, isBatch);
                },
                timeout: function () {
                    self.handleLskyUploadFailure('上传超时', file, isBatch);
                }
            });
        },

        uploadLocal: function (file, isBatch) {
            var self = this;
            var sec = byId('temediafolder');
            var uploadUrl = sec ? sec.getAttribute(getStorageUploadAttr('local')) : null;

            if (!uploadUrl) {
                if (isBatch) self.onUploadComplete(false, file.name);
                return;
            }

            var formData = new FormData();
            formData.append('file', file);

            // 优先从当前路径推断年月（本地浏览器模式）
            var ym = parseLocalYearMonthFromPath(state.currentPath || '');
            if (ym) {
                formData.append('temf_year', ym.year);
                formData.append('temf_month', ym.month);
            } else {
                // 兼容旧的年月选择器模式
                var ySel = byId('temf-year');
                var mSel = byId('temf-month');
                if (ySel && ySel.value && mSel && mSel.value) {
                    var month = mSel.value;
                    var paddedMonth = month.length === 1 ? '0' + month : month;
                    formData.append('temf_year', ySel.value);
                    formData.append('temf_month', paddedMonth);
                }
            }

            this.currentXhr = this.sendUploadRequest(uploadUrl, formData, file, isBatch, {
                load: function (xhr) {
                    try {
                        var result = JSON.parse(xhr.responseText);
                        var success = result.ok && result.url;

                        if (success) {
                            self.handleLocalUploadSuccess(result);
                        } else {
                            var msg = (result && (result.msg || result.message)) ? (result.msg || result.message) : '上传失败';
                            self.handleLocalUploadFailure(msg, file, isBatch);
                        }

                        if (isBatch) {
                            self.onUploadComplete(success, file.name);
                        }
                    } catch (e) {
                        if (isBatch) {
                            self.onUploadComplete(false, file.name);
                        }
                    }
                },
                error: function () {
                    self.handleLocalUploadFailure('网络错误', file, isBatch);
                },
                timeout: function () {
                    self.handleLocalUploadFailure('上传超时', file, isBatch);
                }
            });
        },

        getCurrentPath: function () {
            var dir = byId('temf-dir');
            if (!dir) return '';
            return normalizeDirectoryPath(dir.value || '');
        }
        };
    }

    var upload = createUploadModule();
    upload.stagePanel = createUploadStagePanelModule(upload);
    var dragUpload = createDragUploadModule(upload);

    function createLocalDataModule() {
        return {
        getAllFiles: function () {
            var result = [];
            var data = TEMF_CONF.data || {};
            Object.keys(data).forEach(function (year) {
                var months = data[year] || {};
                Object.keys(months).forEach(function (month) {
                    (months[month] || []).forEach(function (item) {
                        var clone = Object.assign({}, item);
                        clone.directory = normalizeDirectoryPath(clone.directory || (year + '/' + month));
                        clone.group = year + '-' + month;
                        result.push(clone);
                    });
                });
            });
            return result;
        },

        getMonthFolders: function (allFiles) {
            var data = TEMF_CONF.data || {};
            var folders = [];
            var self = this;
            var seen = {};

            function appendMonthFolder(year, month) {
                var monthKey = String(month).padStart(2, '0');
                var monthPath = year + '/' + monthKey;
                if (seen[monthPath]) {
                    return;
                }
                seen[monthPath] = true;

                var stats = self.getFolderStats(allFiles, monthPath);
                folders.push({
                    name: monthPath,
                    path: monthPath,
                    folderCount: stats.folderCount,
                    fileCount: stats.fileCount
                });
            }

            Object.keys(data).sort(function (a, b) { return Number(b) - Number(a); }).forEach(function (year) {
                Object.keys(data[year] || {}).sort().reverse().forEach(function (month) {
                    appendMonthFolder(year, month);
                });
            });

            // 人性化：根目录始终显示当前月份（即使没有任何文件）
            var nowYM = getNowYearMonth();
            appendMonthFolder(nowYM.year, nowYM.month);

            folders.sort(function (a, b) {
                return String(b.path).localeCompare(String(a.path));
            });

            return folders;
        },

        getFolderStats: function (allFiles, folderPath) {
            var normalized = normalizeDirectoryPath(folderPath || '');
            var fileCount = 0;
            var folderSet = {};
            allFiles.forEach(function (file) {
                var dir = normalizeDirectoryPath(extractFileDirectory(file));
                if (dir === normalized) {
                    fileCount++;
                    return;
                }
                if (normalized && dir.indexOf(normalized + '/') === 0) {
                    var rest = dir.slice(normalized.length + 1);
                    var seg = rest.split('/')[0];
                    if (seg) folderSet[seg] = true;
                }
            });
            return {
                folderCount: Object.keys(folderSet).length,
                fileCount: fileCount
            };
        },

        buildView: function (path) {
            var normalized = normalizeDirectoryPath(path || '');
            var allFiles = this.getAllFiles();
            if (!normalized) {
                return {
                    files: allFiles.filter(function (file) {
                        return normalizeDirectoryPath(extractFileDirectory(file)) === '';
                    }),
                    folders: this.getMonthFolders(allFiles)
                };
            }

            var files = [];
            var folderMap = {};
            var self = this;

            allFiles.forEach(function (file) {
                var dir = normalizeDirectoryPath(extractFileDirectory(file));
                if (dir === normalized) {
                    files.push(file);
                    return;
                }
                if (dir.indexOf(normalized + '/') === 0) {
                    var rest = dir.slice(normalized.length + 1);
                    var seg = rest.split('/')[0];
                    if (!seg) return;
                    var childPath = normalizeDirectoryPath(normalized + '/' + seg);
                    folderMap[childPath] = {
                        name: seg,
                        path: childPath
                    };
                }
            });

            var folders = Object.keys(folderMap).sort().map(function (key) {
                var folder = folderMap[key];
                var stats = self.getFolderStats(allFiles, folder.path);
                folder.folderCount = stats.folderCount;
                folder.fileCount = stats.fileCount;
                return folder;
            });

            return { files: files, folders: folders };
        }
        };
    }

    var localBrowser = {

        init: function () {
            setSelectVisibility('temf-year', false);
            setSelectVisibility('temf-month', false);

            // 人性化：本地模式始终默认进入当前月份，首次上传会自动创建该月目录
            var nowYM = getNowYearMonth();
            var defaultPath = nowYM.year + '/' + nowYM.month;

            this.navigate(defaultPath);
        },

        navigate: function (path) {
            setCurrentPath(path || '');
            var view = localData.buildView(state.currentPath);
            ui.renderFiles(view.files || [], view.folders || []);
        },

        refresh: function () {
            this.navigate(state.currentPath || '');
        }
    };

    var localData = createLocalDataModule();
    var local = createLocalSelectorModule(localData, localBrowser);

    function ensureSingleStorageLoaded(storageType) {
        var entry = storageRegistry[storageType];
        if (!entry) {
            return false;
        }

        if (typeof entry.ensureLoaded === 'function') {
            return entry.ensureLoaded();
        }

        if (entry.provider && entry.loadKey && !state[entry.loadKey]) {
            entry.provider.init();
            state[entry.loadKey] = true;
            return true;
        }

        return false;
    }

    /**
     * 分页控制器
     */
    var pagination = {
        element: null,
        scrollListener: null,

        show: function () {
            if (!this.element) {
                this.create();
            }
            this.element.style.display = 'flex';
        },

        hide: function () {
            if (this.element) {
                this.element.style.display = 'none';
            }
            this.removeScrollListener();
        },


        /**
         * 移除滚动监听
         */
        removeScrollListener: function () {
            if (this.scrollListener) {
                var body = document.querySelector('#temf-modal .temf-body');
                if (body) {
                    body.removeEventListener('scroll', this.scrollListener);
                }
                this.scrollListener = null;
            }
        },

        update: function () {
            if (!this.element) {
                this.create();
                if (!this.element) {
                    return;
                }
            }

            if (state.remotePagination.enabled) {
                if (state.remotePagination.pageNumber <= 1 && !state.remotePagination.hasMore) {
                    this.hide();
                    return;
                }

                this.show();

                var remotePrevBtn = this.element.querySelector('.temf-page-prev');
                var remoteNextBtn = this.element.querySelector('.temf-page-next');
                var remoteInfo = this.element.querySelector('.temf-page-info');

                if (remotePrevBtn) {
                    remotePrevBtn.disabled = state.remotePagination.pageNumber <= 1;
                }

                if (remoteNextBtn) {
                    remoteNextBtn.disabled = !state.remotePagination.hasMore || !state.remotePagination.nextToken;
                }

                if (remoteInfo) {
                    var count = Array.isArray(state.pagination.allFiles) ? state.pagination.allFiles.length : 0;
                    var label = '第 ' + state.remotePagination.pageNumber + ' 页';
                    if (count > 0) {
                        label += ' / 本页 ' + count + ' 项';
                    }
                    remoteInfo.textContent = label;
                }
                return;
            }

            var currentPage = state.pagination.currentPage;
            var totalItems = state.pagination.totalItems;
            var pageSize = state.pagination.pageSize;

            if (!pageSize || pageSize <= 0) {
                return;
            }

            var totalPages = Math.ceil(totalItems / pageSize);

            if (totalPages <= 1) {
                this.hide();
                return;
            }

            // 直接显示分页控件（不使用滚动显示模式）
            this.show();

            var prevBtn = this.element.querySelector('.temf-page-prev');
            var nextBtn = this.element.querySelector('.temf-page-next');
            var info = this.element.querySelector('.temf-page-info');

            if (prevBtn) {
                prevBtn.disabled = currentPage <= 1;
            }

            if (nextBtn) {
                nextBtn.disabled = currentPage >= totalPages;
            }

            if (info) {
                var start = (currentPage - 1) * pageSize + 1;
                var end = Math.min(currentPage * pageSize, totalItems);
                info.textContent = start + '-' + end + ' / ' + totalItems;
            }
        },

        prevPage: function () {
            if (state.remotePagination.enabled) {
                if (state.remotePagination.pageNumber <= 1 || state.remotePagination.history.length === 0) {
                    return;
                }

                var previousToken = state.remotePagination.history[state.remotePagination.history.length - 1] || '';
                state.remotePagination.history = state.remotePagination.history.slice(0, -1);
                navigateCloudPath(state.remotePagination.path || '', state.remotePagination.storage || getEffectiveStorageSource(), {
                    pageToken: previousToken,
                    pageNumber: Math.max(1, state.remotePagination.pageNumber - 1),
                    history: state.remotePagination.history.slice(),
                    pageSize: state.remotePagination.pageSize,
                    keepRemoteState: true
                });
                this.scrollToTop();
                return;
            }

            if (state.pagination.currentPage > 1) {
                state.pagination.currentPage--;
                ui.renderCurrentPage();
                this.scrollToTop();
            }
        },

        nextPage: function () {
            if (state.remotePagination.enabled) {
                if (!state.remotePagination.hasMore || !state.remotePagination.nextToken) {
                    return;
                }

                var nextHistory = state.remotePagination.history.slice();
                nextHistory.push(state.remotePagination.currentToken || '');
                navigateCloudPath(state.remotePagination.path || '', state.remotePagination.storage || getEffectiveStorageSource(), {
                    pageToken: state.remotePagination.nextToken,
                    pageNumber: state.remotePagination.pageNumber + 1,
                    history: nextHistory,
                    pageSize: state.remotePagination.pageSize,
                    keepRemoteState: true
                });
                this.scrollToTop();
                return;
            }

            var totalPages = Math.ceil(state.pagination.totalItems / state.pagination.pageSize);
            if (state.pagination.currentPage < totalPages) {
                state.pagination.currentPage++;
                ui.renderCurrentPage();
                this.scrollToTop();
            }
        },

        scrollToTop: function () {
            var body = document.querySelector('#temf-modal .temf-body');
            if (body) {
                body.scrollTop = 0;
            }
        },

        create: function () {
            this.element = document.createElement('div');
            this.element.className = 'temf-pagination';
            this.element.style.display = 'none'; // 初始隐藏
            this.element.innerHTML =
                '<button type="button" class="btn btn-xs temf-page-prev">上一页</button>' +
                '<span class="temf-page-info">1-50 / 100</span>' +
                '<button type="button" class="btn btn-xs temf-page-next">下一页</button>';

            // 插入到 .temf-dialog 中，作为 .temf-body 的兄弟元素
            var dialog = document.querySelector('#temf-modal .temf-dialog');
            if (dialog) {
                dialog.appendChild(this.element);
            }

            // 绑定事件
            var self = this;
            var prevBtn = this.element.querySelector('.temf-page-prev');
            var nextBtn = this.element.querySelector('.temf-page-next');

            if (prevBtn) {
                prevBtn.addEventListener('click', function () {
                    self.prevPage();
                });
            }

            if (nextBtn) {
                nextBtn.addEventListener('click', function () {
                    self.nextPage();
                });
            }
        }
    };

    // 暴露到全局作用域供调试使用
    window.TEMF_PAGINATION = pagination;

    var progress = {
        overlay: null,
        titleEl: null,
        statusEl: null,
        barEl: null,
        cardEl: null,
        closeBtn: null,
        cancelHandler: null,
        currentFileIndex: 0,
        totalFiles: 0,

        ensureCreated: function () {
            var dialog = document.querySelector('#temf-modal .temf-dialog');
            if (!dialog) return;

            if (this.overlay) {
                if (!this.overlay.isConnected) {
                    dialog.appendChild(this.overlay);
                }

                if (!this.titleEl || !this.statusEl || !this.barEl) {
                    this.titleEl = this.overlay.querySelector('.temf-progress-title');
                    this.statusEl = this.overlay.querySelector('.temf-progress-status');
                    this.barEl = this.overlay.querySelector('.temf-progress-bar');
                    this.cardEl = this.overlay.querySelector('.temf-progress-card');
                    this.closeBtn = this.overlay.querySelector('.temf-progress-close');
                    this.bindClose();
                    this.updateCancelButton();
                }
                return;
            }

            var overlay = document.createElement('div');
            overlay.className = 'temf-progress-overlay';
            overlay.innerHTML = '' +
                '<div class="temf-progress-card">' +
                '<button type="button" class="temf-progress-close" aria-label="取消上传">×</button>' +
                '<div class="temf-progress-title">上传文件</div>' +
                '<div class="temf-progress-bar-track"><div class="temf-progress-bar"></div></div>' +
                '<div class="temf-progress-status">上传中... 0% (0/0)</div>' +
                '</div>';

            dialog.appendChild(overlay);

            this.overlay = overlay;
            this.titleEl = overlay.querySelector('.temf-progress-title');
            this.statusEl = overlay.querySelector('.temf-progress-status');
            this.barEl = overlay.querySelector('.temf-progress-bar');
            this.cardEl = overlay.querySelector('.temf-progress-card');
            this.closeBtn = overlay.querySelector('.temf-progress-close');
            this.bindClose();
            this.updateCancelButton();
        },

        bindClose: function () {
            var self = this;
            if (this.closeBtn) {
                this.closeBtn.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    if (typeof self.cancelHandler === 'function') {
                        self.cancelHandler();
                    }
                });
            }
        },

        setCancelHandler: function (handler) {
            this.cancelHandler = typeof handler === 'function' ? handler : null;
            this.updateCancelButton();
        },

        updateCancelButton: function () {
            if (!this.closeBtn) {
                return;
            }
            if (this.cancelHandler) {
                this.closeBtn.style.display = '';
                this.closeBtn.disabled = false;
            } else {
                this.closeBtn.style.display = 'none';
                this.closeBtn.disabled = true;
            }
        },

        setStatusMessage: function (message, options) {
            this.ensureCreated();
            if (!this.overlay || !this.statusEl) return;

            var opts = options || {};
            var variant = opts.variant || 'info';

            this.statusEl.textContent = message || '';

            this.statusEl.classList.remove('error', 'success', 'warning');
            if (variant === 'error') {
                this.statusEl.classList.add('error');
                this.overlay.classList.add('temf-progress-error');
                if (this.cardEl) this.cardEl.classList.add('temf-progress-error');
            } else {
                if (variant === 'success') {
                    this.statusEl.classList.add('success');
                } else if (variant === 'warning') {
                    this.statusEl.classList.add('warning');
                }
                this.overlay.classList.remove('temf-progress-error');
                if (this.cardEl) this.cardEl.classList.remove('temf-progress-error');
            }
        },

        show: function (initialTitle, totalFiles) {
            this.ensureCreated();
            if (!this.overlay) return;

            this.overlay.style.display = 'flex';
            this.overlay.classList.remove('temf-progress-error');
            if (this.cardEl) {
                this.cardEl.classList.remove('temf-progress-error');
            }
            this.setCancelHandler(null);

            var titleText = initialTitle || '上传文件';
            if (this.titleEl) {
                this.titleEl.textContent = titleText;
                this.titleEl.title = titleText;
            }
            if (this.barEl) {
                this.barEl.style.width = '0%';
            }
            var total = Number.isFinite(totalFiles) && totalFiles > 0 ? Math.max(0, totalFiles) : 0;
            var suffix = total ? ' (0/' + total + ')' : '';
            this.setStatusMessage('上传中... 0%' + suffix, { variant: 'info' });
            this.currentFileIndex = 0;
            this.totalFiles = Number.isFinite(totalFiles) && totalFiles > 0 ? totalFiles : 0;
        },

        hide: function () {
            if (this.overlay) {
                this.overlay.style.display = 'none';
                if (this.statusEl) {
                    this.statusEl.classList.remove('error');
                    this.statusEl.classList.remove('success');
                    this.statusEl.classList.remove('warning');
                }
                if (this.cardEl) {
                    this.cardEl.classList.remove('temf-progress-error');
                }
                this.setCancelHandler(null);
            }
        },

        update: function (current, total, fileName) {
            this.ensureCreated();
            if (!this.overlay) return;

            if (this.titleEl && fileName) {
                this.titleEl.textContent = fileName;
                this.titleEl.title = fileName;
            }
            if (typeof total === 'number' && total >= 0) {
                this.totalFiles = total;
            }
            if (typeof current === 'number') {
                if (this.totalFiles > 0) {
                    this.currentFileIndex = Math.min(current + 1, this.totalFiles);
                } else {
                    this.currentFileIndex = 0;
                }
            }
            if (this.cardEl) {
                this.cardEl.classList.remove('temf-progress-error');
            }
            this.updateFileProgress(0);
        },

        updateFileProgress: function (percent) {
            if (!this.overlay) return;

            percent = Math.max(0, Math.min(100, Number.isFinite(percent) ? percent : 0));
            if (this.barEl) {
                this.barEl.style.width = percent + '%';
            }

            var displayTotal = this.totalFiles || 0;
            var displayCurrent = displayTotal ? Math.min(Math.max(this.currentFileIndex, 1), displayTotal) : 0;
            var suffix = displayTotal ? ' (' + displayCurrent + '/' + displayTotal + ')' : '';
            this.setStatusMessage('上传中... ' + Math.round(percent) + '%' + suffix, { variant: 'info' });
        },

        setError: function (msg) {
            var message = msg || '上传失败';
            this.setStatusMessage(message, { variant: 'error' });
            this.setCancelHandler(this.hide.bind(this));
        },

        finish: function () {
            this.ensureCreated();
            if (!this.overlay) return;

            if (this.totalFiles > 0) {
                this.currentFileIndex = this.totalFiles;
            }

            if (this.barEl) {
                this.barEl.style.width = '100%';
            }

            // 检查是否有上传失败的文件
            var hasErrors = fileOps.uploadFailedCount > 0;
            var isError = this.statusEl && this.statusEl.classList.contains('error');

            if (this.statusEl && !isError) {
                var total = this.totalFiles || 0;
                var suffix = total ? ' (' + total + '/' + total + ')' : '';
                this.setStatusMessage('上传完成 100%' + suffix, { variant: hasErrors ? 'warning' : 'success' });
            }

            this.setCancelHandler(this.hide.bind(this));

            // 如果全部成功，自动关闭；如果有错误，保持打开由用户手动关闭
            if (!hasErrors && !isError) {
                var self = this;
                setTimeout(function () {
                    self.hide();
                }, 1500);
            }
        }
    };

    function isElementVisible(el) {
        if (!el) return false;
        var style = window.getComputedStyle ? window.getComputedStyle(el) : null;
        if (style && (style.display === 'none' || style.visibility === 'hidden')) {
            return false;
        }
        var rect = el.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }

    function resolveToolbarHost() {
        var uploadSelectors = [
            '.upload-panel',
            '#upload-panel',
            '.editor-preview .upload-panel',
            '.editor-preview [class*="upload-panel"]',
            '.editor-preview [class*="uploadPanel"]',
            '[class*="upload-panel"]',
            '[class*="uploadPanel"]'
        ];

        for (var i = 0; i < uploadSelectors.length; i++) {
            var nodes = document.querySelectorAll(uploadSelectors[i]);
            for (var j = 0; j < nodes.length; j++) {
                if (isElementVisible(nodes[j])) {
                    return { element: nodes[j], mode: 'upload' };
                }
            }
        }

        var tab = byId('tab-files');
        if (tab) {
            return { element: tab, mode: 'tab' };
        }

        var buttonRow = byId('wmd-button-row');
        if (buttonRow && buttonRow.parentElement && isElementVisible(buttonRow.parentElement)) {
            return { element: buttonRow.parentElement, mode: 'toolbar' };
        }

        return null;
    }

    var mountScheduled = false;
    var mounting = false;

    function mount() {
        if (mounting) {
            return;
        }
        mounting = true;

        try {
            var toolbar = byId("temediafolder");
            var modalEl = byId("temf-modal");
            var hostInfo = resolveToolbarHost();
            var host = hostInfo ? hostInfo.element : null;

            if (toolbar) {
                toolbar.classList.remove('temf-inline');
                toolbar.classList.remove('temf-floating');
                toolbar.style.top = '';
                toolbar.style.right = '';
            }

            if (host && toolbar) {
                if (hostInfo && hostInfo.mode === 'upload') {
                    if (toolbar.parentElement !== host || toolbar !== host.firstChild) {
                        host.insertBefore(toolbar, host.firstChild || null);
                    }
                } else if (toolbar.parentElement !== host) {
                    host.insertBefore(toolbar, host.firstChild);
                }
                toolbar.classList.add('temf-inline');
            } else if (toolbar) {
                if (toolbar.parentElement !== document.body) {
                    document.body.appendChild(toolbar);
                }
                toolbar.classList.add('temf-floating');
            }

            if (modalEl && !modalEl.parentElement) {
                document.body.appendChild(modalEl);
            }
        } finally {
            mounting = false;
        }
    }

    function scheduleMount() {
        if (mountScheduled) {
            return;
        }
        mountScheduled = true;
        setTimeout(function () {
            mountScheduled = false;
            mount();
        }, 80);
    }

    var toolbarMountObserver = null;
    function setupToolbarMountObserver() {
        if (!window.MutationObserver || toolbarMountObserver) return;

        toolbarMountObserver = new MutationObserver(function () {
            scheduleMount();
        });

        toolbarMountObserver.observe(document.body, {
            childList: true,
            subtree: true
        });

        window.addEventListener('resize', scheduleMount);
        document.addEventListener('click', function (e) {
            var target = e.target;
            if (!target) return;
            if (target.closest && (target.closest('[data-action="fullscreen"]') || target.closest('.wmd-fullscreen') || target.closest('.typecho-tab-nav') || target.closest('#wmd-button-row'))) {
                scheduleMount();
            }
        });
    }

    // 创建防抖的上传处理器
    var debouncedUpload = debounce(function () {
        fileOps.upload();
    }, 300);

    function refreshMultiStorage() {
        var storage = state.currentStorage;
        if (!storage && state.availableStorages && state.availableStorages.length > 0) {
            storage = state.availableStorages[0].key;
        }

        if (!storage) {
            return;
        }

        if (state.currentStorage !== storage) {
            state.currentStorage = storage;
        }

        if (isLocalStorage(storage)) {
            multi.loadLocalData({ bustCache: true });
            return;
        }

        if (isLskyStorage(storage)) {
            multi.loadLskyData({ bustCache: true });
            return;
        }

        if (isCloudDirectoryStorage(storage)) {
            var dirSelect = byId('temf-dir');
            if (dirSelect) {
                handleCloudStorageDirectoryChange(dirSelect, storage, { bustCache: true, forceRefresh: true });
                return;
            }

            multi.fetch('', function (data) {
                ui.renderFiles(data.files || [], data.folders || [], data);
            }, { bustCache: true });
            return;
        }

        multi.fetch('', function (data) {
            ui.renderFiles(data.files || [], data.folders || [], data);
        }, { bustCache: true });
    }

    function refreshCurrentDirectory() {
        try {
            var source = TEMF_CONF.source;

            if (isMultiMode()) {
                refreshMultiStorage();
                return;
            }

            if (isLocalStorage(source)) {
                local.fetchLatest({ bustCache: true, rebuildIndex: true });
                return;
            }

            if (isLskyStorage(source)) {
                lsky.init({ bustCache: true });
                return;
            }

            if (isCloudDirectoryStorage(source)) {
                var dirSelect = byId('temf-dir');
                if (dirSelect) {
                    handleCloudStorageDirectoryChange(dirSelect, source, { bustCache: true, forceRefresh: true });
                    return;
                }

                cloudStorage.init(source, { bustCache: true });
            }
        } catch (err) {
            console.error('[TEMF] 刷新目录失败', err);
        }
    }

    document.addEventListener('click', function (e) {
        var target = e.target;

        if (target && target.id === "temf-open") {
            modal.open();
            e.preventDefault();
        }

        if (target && (target.id === "temf-close" || target.hasAttribute("data-temf-close"))) {
            modal.close();
            e.preventDefault();
        }

        var folderItem = target && typeof target.closest === 'function'
            ? target.closest('[data-temf-folder="1"]')
            : null;
        if (folderItem) {
            var folderPath = normalizeDirectoryPath(folderItem.getAttribute('data-path') || '');
            var currentSource = isMultiMode()
                ? (state.currentStorage || '')
                : (TEMF_CONF.source || '');

            if (isCloudDirectoryStorage(currentSource)) {
                navigateCloudPath(folderPath, currentSource);
                e.preventDefault();
                return;
            } else if (isLocalStorage(currentSource)) {
                localBrowser.navigate(folderPath);
                e.preventDefault();
                return;
            }
        }

        var crumb = target && typeof target.closest === 'function'
            ? target.closest('[data-temf-crumb="1"]')
            : null;
        if (crumb) {
            var crumbPath = normalizeDirectoryPath(crumb.getAttribute('data-path') || '');
            var crumbSource = getEffectiveStorageSource();
            if (isCloudDirectoryStorage(crumbSource)) {
                navigateCloudPath(crumbPath, crumbSource);
                e.preventDefault();
                return;
            } else if (isLocalStorage(crumbSource)) {
                localBrowser.navigate(crumbPath);
                e.preventDefault();
                return;
            }
        }

        if (target && target.matches(".temf-pick")) {
            var url = target.value;
            if (target.checked) {
                var meta = {
                    id: target.getAttribute('data-meta-id') || null
                };
                selection.add(url, meta);
            } else {
                selection.remove(url);
            }
            return;
        }

        if (target && target.matches("[data-temf-insert]")) {
            var url = target.getAttribute("data-url");
            fileOps.insert(url);
            e.preventDefault();
        }

        if (target && target.matches("#temf-insert-selected")) {
            fileOps.insertSelected();
            e.preventDefault();
        }

        if (target && target.matches('[data-temf-stage-pick]')) {
            fileOps.upload();
            e.preventDefault();
            return;
        }

        if (target && target.matches('[data-temf-stage-clear]')) {
            upload.clearStagedFiles();
            e.preventDefault();
            return;
        }

        if (target && target.matches('[data-temf-stage-start]')) {
            upload.startStagedUpload();
            e.preventDefault();
            return;
        }

        if (target && target.matches('[data-temf-stage-cancel]')) {
            upload.cancelStagePanel();
            e.preventDefault();
            return;
        }

        if (target && target.matches('[data-temf-stage-remove]')) {
            upload.removeStagedFile(parseInt(target.getAttribute('data-temf-stage-remove'), 10) || 0);
            e.preventDefault();
            return;
        }

        var copyBtn = null;
        if (target) {
            if (typeof target.closest === 'function') {
                copyBtn = target.closest("[data-temf-copy]");
            }
            if (!copyBtn && target.matches && target.matches("[data-temf-copy]")) {
                copyBtn = target;
            }
        }
        if (copyBtn) {
            var url = copyBtn.getAttribute("data-url");
            fileOps.copy(url, copyBtn);
            e.preventDefault();
        }

        if (target && target.id === "temf-upload") {
            debouncedUpload();
            e.preventDefault();
        }

        var refreshBtn = null;
        if (target) {
            if (target.id === 'temf-refresh') {
                refreshBtn = target;
            } else if (typeof target.closest === 'function') {
                refreshBtn = target.closest('#temf-refresh');
            }
        }

        if (refreshBtn) {
            e.preventDefault();
            refreshCurrentDirectory();
            return;
        }
    });

    document.addEventListener('dblclick', function (e) {
        var target = e.target;
        if (!target || !target.classList) return;
        if (target.classList.contains('temf-name')) {
            e.preventDefault();
            rename.start(target);
        }
    });

    /**
     * 处理云存储目录切换（合并COS/OSS逻辑）
     */
    function navigateCloudPath(path, currentSource, options) {
        options = options || {};
        var isMulti = isMultiMode();
        var normalizedPath = normalizeDirectoryPath(path || '');
        var normalizedSource = String(currentSource || '').toLowerCase();

        if (options.forceRefresh && isMulti && normalizedSource && state.currentStorage !== normalizedSource) {
            state.currentStorage = normalizedSource;
        }

        if (!options.keepRemoteState || state.remotePagination.path !== normalizedPath || state.remotePagination.storage !== normalizedSource) {
            resetRemotePagination(normalizedSource, normalizedPath, { enabled: isServerPagedStorage(normalizedSource) });
        }

        if (typeof options.pageToken === 'string') {
            state.remotePagination.currentToken = options.pageToken;
        }
        if (options.history) {
            state.remotePagination.history = options.history.slice();
        }
        if (options.pageNumber) {
            state.remotePagination.pageNumber = options.pageNumber;
        }
        if (options.pageSize) {
            state.remotePagination.pageSize = options.pageSize;
        }

        var fetchFunction = isMulti
            ? function (nextPath, callback, opts) { multi.fetch(nextPath, callback, Object.assign({}, opts, { bustCache: options.bustCache })); }
            : function (nextPath, callback, opts) { cloudStorage.fetch(normalizedSource, nextPath, callback, Object.assign({}, opts, { bustCache: options.bustCache })); };

        setCurrentPath(normalizedPath);
        fetchFunction(normalizedPath, function (data) {
            var dirSelect = byId('temf-dir');
            if (dirSelect) {
                cloudStorage.syncDirectorySelector(dirSelect, normalizedPath, data.folders || []);
            }
            ui.renderFiles(data.files || [], data.folders || [], data);
        }, Object.assign({}, options, {
            pageToken: state.remotePagination.currentToken || '',
            pageSize: state.remotePagination.pageSize || calculatePageSize()
        }));
    }

    function handleCloudStorageDirectoryChange(target, currentSource, options) {
        if (target.id === 'temf-dir') {
            navigateCloudPath(target.value || '', currentSource, options);
        }
    }

    document.addEventListener('change', function (e) {
        var target = e.target;
        if (!target) return;

        // 获取当前实际使用的存储类型
        var currentSource = TEMF_CONF.source;
        if (currentSource === 'multi' && state.currentStorage) {
            currentSource = state.currentStorage;
        }

        // 处理云存储（COS/OSS/UPYUN）目录切换
        if (isCloudDirectoryStorage(currentSource) &&
            target.id === 'temf-dir') {
            handleCloudStorageDirectoryChange(target, currentSource);
        } else if (isLskyStorage(currentSource)) {
            if (target && target.id === 'temf-dir') {
                var selection = target.value;
                setCurrentPath('');

                // 多模式下使用multi.fetch，单模式下使用lsky.fetch
                var fetchFunction = isMultiMode() ? multi.fetch : lsky.fetch;

                if (selection === 'album') {
                    // 选择相册：使用相册ID过滤
                    fetchFunction('album', function (data) {
                        ui.renderFiles(data.files || [], data.folders || [], data);
                    });
                } else {
                    // 选择全部：显示所有图片
                    fetchFunction('', function (data) {
                        ui.renderFiles(data.files || [], data.folders || [], data);
                    });
                }
            }
        } else if (isLocalStorage(currentSource)) {
            if (target && target.id === 'temf-year') {
                local.buildMonths(target.value);
                local.renderCurrentMonth();
            }

            if (target && target.id === 'temf-month') {
                local.renderCurrentMonth();
            }
        }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            modal.close();
        }
    });


    /**
     * 图片懒加载 - 使用Intersection Observer提升性能
     * 使用 loading.gif 作为占位图，加载完成后平滑过渡
     */
    function initLazyLoading() {
        if (!window.IntersectionObserver) {
            // 降级处理：不支持IntersectionObserver时直接加载所有图片
            document.querySelectorAll('.temf-lazy-img').forEach(function (img) {
                if (img.dataset.src) {
                    loadImageWithTransition(img);
                }
            });
            return;
        }

        var imageObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var img = entry.target;
                    if (img.dataset.src && !img.classList.contains('temf-loading')) {
                        loadImageWithTransition(img);
                        observer.unobserve(img);
                    }
                }
            });
        }, {
            rootMargin: '300px', // 提前300px开始加载，减少滚动卡顿
            threshold: 0.01 // 只要1%进入视口就开始加载
        });

        // 观察所有懒加载图片
        document.querySelectorAll('.temf-lazy-img').forEach(function (img) {
            // 检查是否已经是 loading.gif，如果是则需要懒加载
            var currentSrc = img.src || '';
            if (currentSrc.indexOf('loading.gif') !== -1 || !img.complete) {
                imageObserver.observe(img);
            }
        });
    }

    /**
     * 加载图片并应用平滑过渡效果
     */
    function loadImageWithTransition(img) {
        // 标记为加载中，避免重复加载
        if (img.classList.contains('temf-loading') || img.classList.contains('temf-loaded')) {
            return;
        }

        img.classList.add('temf-loading');
        if (img.dataset.loader) {
            img.style.backgroundImage = 'url(' + img.dataset.loader + ')';
            img.style.backgroundRepeat = 'no-repeat';
            img.style.backgroundPosition = 'center';
            img.style.backgroundSize = '28px 28px';
        }

        var targetSrc = img.dataset.src;
        if (!targetSrc) {
            return;
        }

        // 创建新图片对象预加载
        var tempImg = new Image();

        tempImg.onload = function () {
            // 图片预加载成功，但还要等实际元素加载完成
            img.src = targetSrc;

            // 等待实际 img 元素加载完成
            img.onload = function () {
                img.classList.remove('temf-loading');
                img.classList.add('temf-loaded');
                img.style.opacity = '';
                img.style.transition = '';

                requestAnimationFrame(function () {
                    img.style.backgroundImage = 'none';
                });
            };

            // 如果图片已经缓存，立即触发
            if (img.complete) {
                img.onload();
            }
        };

        tempImg.onerror = function () {
            // 加载失败，尝试使用原始URL
            img.classList.remove('temf-loading');
            img.style.backgroundImage = 'none';
            if (img.dataset.original) {
                img.src = img.dataset.original;
                img.classList.add('temf-loaded');
            }
        };

        // 开始预加载
        tempImg.src = targetSrc;
    }

    /**
     * 监听窗口大小变化，重新计算分页大小
     */
    var resizeTimer = null;
    function setupResizeListener() {
        window.addEventListener('resize', function () {
            // 防抖处理
            if (resizeTimer) {
                clearTimeout(resizeTimer);
            }

            resizeTimer = setTimeout(function () {
                // 只有在有文件显示时才重新计算
                if (state.pagination.allFiles && state.pagination.allFiles.length > 0) {
                    var oldPageSize = state.pagination.pageSize;
                    var newPageSize = calculatePageSize();

                    // 如果页面大小变化，重新渲染
                    if (oldPageSize !== newPageSize) {
                        state.pagination.pageSize = newPageSize;
                        ui.renderCurrentPage();
                    }
                }
            }, 300); // 300ms 防抖
        });
    }

    // 监听DOM变化，自动初始化新添加的图片
    var lazyImageMutationObserver = null;
    function setupLazyImageObserver() {
        if (!window.MutationObserver) return;

        var targetNode = document.querySelector('#temf-modal .temf-body');
        if (!targetNode) return;

        lazyImageMutationObserver = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.addedNodes.length > 0) {
                    scheduleInitLazyLoading();
                }
            });
        });

        lazyImageMutationObserver.observe(targetNode, {
            childList: true,
            subtree: true
        });
    }

    if (document.readyState !== "loading") {
        scheduleMount();
        setupToolbarMountObserver();
        customSelects.initAll();
        setupLazyImageObserver();
        setupResizeListener();
    } else {
        document.addEventListener("DOMContentLoaded", function () {
            scheduleMount();
            setupToolbarMountObserver();
            customSelects.initAll();
            setupLazyImageObserver();
            setupResizeListener();
        });
    }

    function scheduleInitLazyLoading() {
        if (lazyInitTimer) {
            clearTimeout(lazyInitTimer);
        }
        lazyInitTimer = setTimeout(function () {
            lazyInitTimer = null;
            initLazyLoading();
        }, 50);
    }

})(window, document);
