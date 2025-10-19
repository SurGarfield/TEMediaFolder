/**
 * TEMediaFolder JavaScript
 * 媒体文件夹插件前端交互
 */
(function() {
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
            
                field.dispatchEvent(new Event("input", {bubbles: true}));
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
        upyunLoaded: false,
        lskyLoaded: false,
        multiLoaded: false,
        currentStorage: null,
        availableStorages: [],
        selected: new Set(),
        // 请求缓存机制 - 避免重复请求
        requestCache: {},
        cacheTimeout: 5 * 60 * 1000, // 5分钟缓存
        // 分页状态
        pagination: {
            currentPage: 1,
            pageSize: 0,   // 动态计算（行数 × 列数）
            totalItems: 0,
            allFiles: []   // 存储所有文件
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
        return function() {
            var context = this;
            var args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
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
        return function() {
            var context = this;
            var args = arguments;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(function() {
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
        var method = options.method || 'GET';
        var cacheKey = method + ':' + url;
        
        // 只缓存 GET 请求
        if (method === 'GET') {
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
        
        // 发起请求
        return fetch(url, options)
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                // 缓存 GET 请求的成功响应
                if (method === 'GET' && data && data.ok !== false) {
                    state.requestCache[cacheKey] = {
                        data: data,
                        timestamp: Date.now()
                    };
                }
                return data;
            })
            .catch(function(error) {
                console.error('[TEMF] 请求失败:', url, error);
                throw error;
            });
    }
    
    /**
     * 动态计算每页显示的图片数量
     * 基于容器宽度、缩略图大小和配置的行数
     */
    function calculatePageSize() {
        var rows = parseInt(TEMF_CONF.paginationRows) || 4;  // 默认4行
        var thumbSize = parseInt(TEMF_CONF.thumbSize) || 120; // 缩略图大小
        var gap = 8; // grid gap
        
        // 获取容器宽度
        var body = document.querySelector('#temf-modal .temf-body');
        if (!body) {
            // 如果容器不存在，使用默认值
            return rows * 5;
        }
        
        var containerWidth = body.clientWidth - 24; // 减去padding
        
        // 计算每行可以放多少张图片
        var itemWidth = thumbSize + gap;
        var cols = Math.floor(containerWidth / itemWidth);
        cols = Math.max(1, cols); // 至少1列
        
        // 总数 = 行数 × 列数
        var pageSize = rows * cols;
        return Math.max(pageSize, 10); // 至少10张
    }
    
    var modal = {
        open: function() {
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
            
            if (TEMF_CONF.source === 'multi' && !state.multiLoaded) {
                multi.init();
                state.multiLoaded = true;
            } else if (TEMF_CONF.source === 'cos' && !state.cosLoaded) {
                cos.init();
                state.cosLoaded = true;
            } else if (TEMF_CONF.source === 'oss' && !state.ossLoaded) {
                oss.init();
                state.ossLoaded = true;
            } else if (TEMF_CONF.source === 'upyun' && !state.upyunLoaded) {
                upyun.init();
                state.upyunLoaded = true;
            } else if (TEMF_CONF.source === 'lsky' && !state.lskyLoaded) {
                lsky.init();
                state.lskyLoaded = true;
            } else if (TEMF_CONF.source === 'local') {
                    local.buildYearMonth();
                    local.renderCurrentMonth();
            }
            } catch (e) {
                // open modal error
            }
        },
        
        close: function() {
            try {
            var m = byId("temf-modal");
                if (!m) {
                    // modal not found
                    return;
                }
            
            m.classList.remove("open");
            m.setAttribute("aria-hidden", "true");
            m.setAttribute("inert", "");
            
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
    
    var local = {
        buildYearMonth: function() {
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
            
            var years = Object.keys(TEMF_CONF.data).sort(function(a, b) {
                return b - a;
            });
                
                if (years.length === 0) {
                    // no years data
                    return;
                }
            
            years.forEach(function(year) {
                var opt = document.createElement('option');
                opt.value = year;
                opt.textContent = year;
                ySel.appendChild(opt);
            });
            
            var latest = TEMF_CONF.latest ? TEMF_CONF.latest.split('-') : null;
            var curYear = latest ? latest[0] : years[0];
            ySel.value = curYear;
            
            this.buildMonths(curYear);
            
                if (latest && latest.length > 1) {
                mSel.value = latest[1];
                }
            } catch (e) {
                // build selectors error
            }
        },
        
        buildMonths: function(year) {
            var mSel = byId("temf-month");
            if (!mSel) return;
            
            mSel.innerHTML = '';
            var months = Object.keys(TEMF_CONF.data[year] || {}).sort().reverse();
            
            months.forEach(function(month) {
                var opt = document.createElement('option');
                opt.value = month;
                opt.textContent = month;
                mSel.appendChild(opt);
            });
        },
        
        renderCurrentMonth: function() {
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
                
                var items = [];
                if (TEMF_CONF.data && TEMF_CONF.data[year] && TEMF_CONF.data[year][month]) {
                    items = TEMF_CONF.data[year][month];
                }
            
            // 使用统一的 renderFiles 方法，支持分页
            ui.renderFiles(items);
            } catch (e) {
                // render error
            }
        }
    };
    
    // 通用云存储处理器 - 合并 COS 和 OSS 的重复逻辑
    var cloudStorage = {
        /**
         * 初始化目录选择器
         * @param {string} storageType - 'cos' 或 'oss'
         */
        init: function(storageType) {
            var dir = byId('temf-dir');
            var sub = byId('temf-subdir');
            if (!dir || !sub) return;
            
            this.initDirectorySelectors(dir, sub);
            
            var self = this;
            this.fetch(storageType, '', function(data) {
                self.populateFolders(dir, data.folders || []);
                ui.renderFiles(data.files || []);
            });
        },
        
        /**
         * 初始化目录选择器
         */
        initDirectorySelectors: function(dir, sub) {
            dir.innerHTML = '';
            sub.innerHTML = '';
            
            var optRoot = document.createElement('option');
            optRoot.value = '';
            optRoot.textContent = '/';
            dir.appendChild(optRoot);
            
            var optRoot2 = document.createElement('option');
            optRoot2.value = '';
            optRoot2.textContent = '/';
            sub.appendChild(optRoot2);
        },
        
        /**
         * 填充文件夹选项
         */
        populateFolders: function(dirElement, folders) {
                folders.forEach(function(folder) {
                    var opt = document.createElement('option');
                    opt.value = folder.path || folder.name;
                    opt.textContent = folder.name || folder.path;
                dirElement.appendChild(opt);
            });
        },
        
        /**
         * 通用fetch方法（带缓存）
         */
        fetch: function(storageType, path, callback) {
            var sec = byId('temediafolder');
            if (!sec) return;
            
            var attrName = 'data-' + storageType + '-list';
            var base = sec.getAttribute(attrName);
            if (!base) return;
            
            var url = base;
            if (path) {
                url += (base.indexOf('?') >= 0 ? '&' : '?') + 'temf_path=' + encodeURIComponent(path);
            }

            // 使用统一的 cachedFetch（自动处理缓存）
            cachedFetch(url)
                .then(function(data) {
                    callback && callback(data);
                })
                .catch(function() { 
                    callback && callback({folders: [], files: []}); 
                });
        }
    };
    
    // COS 和 OSS 使用共享的云存储处理器
    var cos = {
        init: function() { cloudStorage.init('cos'); },
        fetch: function(path, callback) { cloudStorage.fetch('cos', path, callback); }
    };
    
    var oss = {
        init: function() { cloudStorage.init('oss'); },
        fetch: function(path, callback) { cloudStorage.fetch('oss', path, callback); }
    };
    
    var upyun = {
        init: function() { cloudStorage.init('upyun'); },
        fetch: function(path, callback) { cloudStorage.fetch('upyun', path, callback); }
    };
    
    var lsky = {
        init: function() {
            var dir = byId('temf-dir');
            var sub = byId('temf-subdir');
            if (!dir || !sub) return;
            
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
            
            // 隐藏子目录选择器
            sub.style.display = 'none';
            
            // 根据当前选择加载数据
            var currentSelection = dir.value || '';
            var fetchPath = currentSelection === 'album' ? 'album' : '';
            
            this.fetch(fetchPath, function(data) {
                ui.renderFiles(data.files || []);
            });
        },
        
        fetch: function(path, callback) {
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

            cachedFetch(url)
                .then(function(data) {
                    callback && callback(data);
                })
                .catch(function() {
                    callback && callback({folders: [], files: []});
                });
        }
    };
    
    var multi = {
        init: function() {
            var sec = byId('temediafolder');
            if (!sec) return;
            
            var storageTypesUrl = sec.getAttribute('data-storage-types');
            if (!storageTypesUrl) return;
            
            // 获取可用的存储类型
            cachedFetch(storageTypesUrl)
                .then(function(data) {
                    if (data.ok && data.types) {
                        state.availableStorages = data.types;
                        multi.buildSwitcher();
                        
                        // 默认选择第一个可用存储
                        if (data.types.length > 0) {
                            multi.switchTo(data.types[0].key);
                        }
                    }
                })
                .catch(function() {
                });
        },
        
        buildSwitcher: function() {
            var switcher = byId('temf-storage-switcher');
            if (!switcher || state.availableStorages.length === 0) return;
            
            switcher.innerHTML = '';
            
            state.availableStorages.forEach(function(storage) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'temf-storage-btn';
                btn.setAttribute('data-storage', storage.key);
                btn.textContent = storage.name; 
                
                btn.addEventListener('click', function() {
                    multi.switchTo(storage.key);
                });
                
                switcher.appendChild(btn);
            });
            
            // 添加标题点击事件
            var title = byId('temf-title');
            var container = document.querySelector('.temf-title-container');
            if (title && container) {
                title.addEventListener('click', function(e) {
                    e.stopPropagation();
                    container.classList.toggle('expanded');
                    switcher.classList.toggle('show');
                });
            }
            
            // 添加点击外部区域自动收起功能
            document.addEventListener('click', function(e) {
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
        
        switchTo: function(storageType) {
            state.currentStorage = storageType;
            
            // 性能优化：切换模式时清除缓存
            ui._clearCache();

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
            var storageNames = {
                'local': '本地存储',
                'cos': '腾讯云COS',
                'oss': '阿里云OSS',
                'upyun': '又拍云',
                'lsky': '兰空图床'
            };
            var storageName = storageNames[storageType] || storageType;
            
            // 更新标题显示当前存储类型（只显示存储名称）
            var title = byId('temf-title');
            if (title) {
                title.textContent = storageName;
            }
            
            // 添加切换动画和加载提示
            var body = document.querySelector('#temf-modal .temf-body');
            if (body) {
                body.classList.add('temf-content-switching');
                // 移除已有遮罩
                var existed = body.querySelector('.temf-switching-overlay');
                if (existed && existed.parentNode) existed.parentNode.removeChild(existed);
                // 叠加白色遮罩，不清空原有网格，避免闪烁
                var overlay = document.createElement('div');
                overlay.className = 'temf-switching-overlay';
                overlay.innerHTML = '<div class="temf-switching-spinner"></div>' +
                    '<div class="temf-switching-text">正在切换到 ' + storageName + '...</div>';
                body.appendChild(overlay);
            }
            
            // 更新按钮状态
            var buttons = document.querySelectorAll('.temf-storage-btn');
            buttons.forEach(function(btn) {
                if (btn.getAttribute('data-storage') === storageType) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            
            // 延迟执行切换逻辑，让动画效果更平滑
            setTimeout(function() {
                // 根据存储类型初始化相应的选择器
                if (storageType === 'local') {
                    multi.hideDirectorySelectors();
                    multi.showLocalSelectors();
                    multi.loadLocalData();
                } else if (storageType === 'lsky') {
                    multi.hideLocalSelectors();
                    multi.initLskySelectors();
                    multi.loadLskyData();
                } else {
                    multi.showDirectorySelectors();
                    multi.hideLocalSelectors();
                    multi.initDirectorySelectors();
                    
                    multi.fetch('', function(data) {
                        var dir = byId('temf-dir');
                        if (dir && data.folders) {
                            data.folders.forEach(function(folder) {
                                var opt = document.createElement('option');
                                opt.value = folder.path || folder.name;
                                opt.textContent = folder.name || folder.path;
                                dir.appendChild(opt);
                            });
                        }
                        
                        ui.renderFiles(data.files || []);
                        multi.finishSwitchAnimation();
                    });
                }
            }, 150);
        },
        
        finishSwitchAnimation: function() {
            var body = document.querySelector('#temf-modal .temf-body');
            if (body) {
                body.classList.remove('temf-content-switching');
                body.classList.add('temf-content-switched');
                setTimeout(function() {
                    body.classList.remove('temf-content-switched');
                }, 200);
            }
        },
        
        initDirectorySelectors: function() {
            var dir = byId('temf-dir');
            var sub = byId('temf-subdir');
            if (dir && sub) {
                dir.innerHTML = '';
                var optRoot = document.createElement('option');
                optRoot.value = '';
                optRoot.textContent = '/';
                dir.appendChild(optRoot);
                
                sub.innerHTML = '';
                var optRoot2 = document.createElement('option');
                optRoot2.value = '';
                optRoot2.textContent = '/';
                sub.appendChild(optRoot2);
            }
        },
        
        showDirectorySelectors: function() {
            var dir = byId('temf-dir');
            var sub = byId('temf-subdir');
            if (dir) dir.style.display = '';
            if (sub) sub.style.display = '';
        },
        
        hideDirectorySelectors: function() {
            var dir = byId('temf-dir');
            var sub = byId('temf-subdir');
            if (dir) dir.style.display = 'none';
            if (sub) sub.style.display = 'none';
        },
        
        showLocalSelectors: function() {
            var year = byId('temf-year');
            var month = byId('temf-month');
            if (year) year.style.display = '';
            if (month) month.style.display = '';
        },
        
        hideLocalSelectors: function() {
            var year = byId('temf-year');
            var month = byId('temf-month');
            if (year) year.style.display = 'none';
            if (month) month.style.display = 'none';
        },
        
        initLskySelectors: function() {
            var dir = byId('temf-dir');
            var sub = byId('temf-subdir');
            
            if (dir) {
                dir.style.display = '';
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
                    dir.value = 'album';
                }
            }
            
            // 隐藏子目录选择器
            if (sub) {
                sub.style.display = 'none';
            }
        },
        
        hasLskyAlbumConfig: function() {
            // 检查是否配置了兰空图床的相册ID
            // 从可用存储列表中获取兰空图床的配置信息
            var lskyStorage = state.availableStorages.find(function(storage) {
                return storage.key === 'lsky';
            });
            
            return lskyStorage && lskyStorage.hasAlbumId;
        },
        
        loadLskyData: function() {
            // 根据当前选择加载数据
            var dir = byId('temf-dir');
            var currentSelection = dir ? dir.value : '';
            var fetchPath = currentSelection === 'album' ? 'album' : '';
            
            this.fetch(fetchPath, function(data) {
                    ui.renderFiles(data.files || []);
                    multi.finishSwitchAnimation();
            });
        },
        
        loadLocalData: function() {
            // 通过API获取本地文件数据
            this.fetch('', function(data) {
                if (data.ok && data.files) {
                    // 将本地文件数据转换为TEMF_CONF.data格式
                    multi.buildLocalDataStructure(data.files);
                    
                    // 构建年份/月份选择器
                    local.buildYearMonth();
                    local.renderCurrentMonth();
                } else {
                    // 没有数据时显示空状态
                    ui.renderFiles([]);
                }
                
                // 完成切换动画
                multi.finishSwitchAnimation();
            });
        },
        
        buildLocalDataStructure: function(files) {
            // 初始化数据结构
            TEMF_CONF.data = {};
            TEMF_CONF.latest = null;
            
            var latestMtime = 0;
            var latestYear = null;
            var latestMonth = null;
            
            // 按年月分组文件
            files.forEach(function(file) {
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
                    size: file.size || 0
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
            Object.keys(TEMF_CONF.data).forEach(function(year) {
                Object.keys(TEMF_CONF.data[year]).forEach(function(month) {
                    TEMF_CONF.data[year][month].sort(function(a, b) {
                        return (b.mtime || 0) - (a.mtime || 0);
                    });
                });
            });
        },
        
        fetch: function(path, callback) {
            var sec = byId('temediafolder');
            if (!sec || !state.currentStorage) return;
            
            var base = sec.getAttribute('data-multi-list');
            if (!base) return;
            
            var url = base;
            url += (base.indexOf('?') >= 0 ? '&' : '?') + 'storage_type=' + encodeURIComponent(state.currentStorage);
            
            // 处理兰空图床的特殊参数
            if (state.currentStorage === 'lsky' && path === 'album') {
                // 使用相册ID参数
                url += '&use_album=1';
            } else if (path) {
                url += '&temf_path=' + encodeURIComponent(path);
            }
            
            cachedFetch(url)
                .then(function(data) {
                    callback && callback(data);
                })
                .catch(function() {
                    callback && callback({folders: [], files: []});
                });
        }
    };
    
    var ui = {
        renderFiles: function(files) {
            var body = document.querySelector('#temf-modal .temf-body');
            if (!body) {
                return;
            }
            
            if (!files || !files.length) {
                body.innerHTML = '<p class="description">无图片</p>';
                selection.clear();
                pagination.hide();
                return;
            }
            
            // 动态计算每页显示数量（确保在容器存在后计算）
            state.pagination.pageSize = calculatePageSize();
            
            // 保存所有文件到状态
            state.pagination.allFiles = files;
            state.pagination.totalItems = files.length;
            
            // 重置到第一页
            state.pagination.currentPage = 1;
            
            // 渲染当前页
            this.renderCurrentPage();
        },
        
        renderCurrentPage: function() {
            var body = document.querySelector('#temf-modal .temf-body');
            if (!body) return;
            
            var files = state.pagination.allFiles;
            var currentPage = state.pagination.currentPage;
            var pageSize = state.pagination.pageSize;
            
            if (!files || !files.length) return;
            
            if (!pageSize || pageSize <= 0) {
                pageSize = files.length;
            }
            
            // 计算当前页的文件范围
            var startIndex = (currentPage - 1) * pageSize;
            var endIndex = Math.min(startIndex + pageSize, files.length);
            var pageFiles = files.slice(startIndex, endIndex);
            
            // 清空并创建网格
            var fragment = document.createDocumentFragment();
            var grid = document.createElement('ul');
            grid.className = 'temf-grid';
            fragment.appendChild(grid);
            body.innerHTML = '';
            body.appendChild(fragment);
            
            // 渲染当前页的文件
            var self = this;
            var htmlParts = [];
            for (var i = 0; i < pageFiles.length; i++) {
                htmlParts.push(self.renderFileItem(pageFiles[i]));
            }
            
            if (htmlParts.length) {
                var div = document.createElement('div');
                div.innerHTML = htmlParts.join('');
                while (div.firstChild) {
                    grid.appendChild(div.firstChild);
                }
            }
            
            selection.clear();
            requestAnimationFrame(function() {
                grid.classList.add('temf-grid-loaded');
                // 初始化懒加载
                initLazyLoading();
            });
            
            // 更新分页控件
            pagination.update();
        },
        
        renderFileItem: function(item) {
            var url = item.url || '';
            var thumbnail = this.getThumbnailUrl(item); // 智能获取缩略图URL
            var name = item.name || 'unknown';
            
            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            var safeUrl = escapeHtml(url);
            var safeThumbnail = escapeHtml(thumbnail);
            var safeName = escapeHtml(name);
            
            // 获取 loading.gif 路径
            var loadingGif = this.getLoadingGifUrl();
            
            // 优化图片加载：使用loading.gif作为占位图，通过Intersection Observer进行懒加载
            return '<li class="temf-item" data-url="' + safeUrl + '">' +
                '<div class="temf-thumb">' +
                '<input type="checkbox" class="temf-pick" value="' + safeUrl + '">' +
                '<img src="' + loadingGif + '" data-src="' + thumbnail + '" alt="' + safeName + '" ' +
                'class="temf-lazy-img" referrerpolicy="no-referrer" decoding="async" ' +
                'data-original="' + safeUrl + '" data-thumbnail="' + safeThumbnail + '" ' +
                'onerror="this.src=\'' + safeUrl + '\';this.onerror=null;"/>' +
                '</div>' +
                '<div class="temf-meta">' +
                '<span class="temf-name" title="' + safeName + '">' + safeName + '</span>' +
                '<div class="temf-actions">' +
                '<button type="button" class="btn btn-xs" data-temf-insert data-url="' + safeUrl + '">插入</button>' +
                '<button type="button" class="btn btn-xs" data-temf-copy data-url="' + safeUrl + '">复制</button>' +
                '</div>' +
                '</div>' +
                '</li>';
        },
        
        /**
         * 获取 loading.gif 的 URL
         */
        getLoadingGifUrl: function() {
            // 缓存 loading.gif URL
            if (!this._loadingGifUrl) {
                var sec = byId('temediafolder');
                if (sec) {
                    // 从插件路径获取
                    var pluginUrl = sec.getAttribute('data-plugin-url');
                    if (pluginUrl) {
                        this._loadingGifUrl = pluginUrl + '/assets/loading.gif';
                    } else {
                        // 降级：尝试从当前脚本路径推断
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
                
                // 如果还是没找到，使用相对路径
                if (!this._loadingGifUrl) {
                    this._loadingGifUrl = '../assets/loading.gif';
                }
            }
            return this._loadingGifUrl;
        },
        
        getThumbnailUrl: function(item) {
            // 性能优化：缓存结果避免重复计算
            if (item._cachedThumbnail) {
                return item._cachedThumbnail;
            }

            var url = item.url || '';
            var thumbnail = item.thumbnail;
            
            // 如果已经有缩略图URL，直接使用
            if (thumbnail && thumbnail !== url) {
                item._cachedThumbnail = thumbnail;
                return thumbnail;
            }
            
            // 性能优化：缓存存储类型判断
            var currentSource = this._getCachedStorageType();
            
            // 检查是否为图片文件
            if (this.isImageFile(item.name || '')) {
                var result;
                // 只有云存储服务（COS/OSS/兰空图床）才使用URL参数生成缩略图
                if (currentSource === 'cos' || currentSource === 'oss' || currentSource === 'lsky') {
                    // 获取配置的缩略图尺寸
                    var thumbSize = TEMF_CONF.thumbSize || 120;
                    var thumbParam = 'thumbnail=' + thumbSize + 'x' + thumbSize;
                    
                    if (url.indexOf('?') !== -1) {
                        result = url + '&' + thumbParam;
                    } else {
                        result = url + '?' + thumbParam;
                    }
                    } else {
                    // 本地存储如果没有缩略图就使用原图
                    result = url;
                }
                
                item._cachedThumbnail = result;
                return result;
            }
            
            // 非图片文件返回原URL
            item._cachedThumbnail = url;
                        return url;
        },

        // 性能优化：缓存存储类型判断结果
        _getCachedStorageType: function() {
            if (!this._cachedStorageType) {
                var currentSource = TEMF_CONF.source;
                if (currentSource === 'multi' && state.currentStorage) {
                    currentSource = state.currentStorage;
                }
                this._cachedStorageType = currentSource;
            }
            return this._cachedStorageType;
        },

        // 清除缓存（在模式切换时调用）
        _clearCache: function() {
            this._cachedStorageType = null;
        },
        
        isImageFile: function(fileName) {
            // 性能优化：缓存扩展名检查
            if (!fileName) return false;
            
            // 性能优化：使用静态哈希表而不是数组查找
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
        
        prependFile: function(fileItem) {
            // 检查文件是否已存在（避免重复显示）
            if (state.pagination.allFiles && state.pagination.allFiles.length > 0) {
                var existingIndex = state.pagination.allFiles.findIndex(function(file) {
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
        clear: function() {
            state.selected.clear();
            this.updateButton();
        },
        
        add: function(url) {
            state.selected.add(url);
            this.updateButton();
        },
        
        remove: function(url) {
            state.selected.delete(url);
            this.updateButton();
        },
        
        updateButton: function() {
            var btn = byId("temf-insert-selected");
            if (!btn) return;
            
            btn.disabled = state.selected.size === 0;
        }
    };
    
    var fileOps = {
        insert: function(url) {
            var name = url.split('/').pop().split('?')[0];
            var alt = decodeURIComponent(name).replace(/\.[a-z0-9]+$/i, '');
            var text = '![' + alt + '](' + url + ')';
            
            insertAtCursor(getEditor(), text);
            modal.close();
        },
        
        insertSelected: function() {
            if (state.selected.size === 0) return;
            
            var urls = Array.from(state.selected);
            var block = '[photos]\n' + urls.map(function(url) {
                var name = url.split('/').pop().split('?')[0];
                var alt = decodeURIComponent(name).replace(/\.[a-z0-9]+$/i, '');
                return '![' + alt + '](' + url + ')';
            }).join('\n') + '\n[/photos]';
            
            insertAtCursor(getEditor(), block);
            selection.clear();
            modal.close();
        },
        
        copy: function(url, button) {
			var showCopied = function(btn) {
				try {
					var originalText = btn.textContent;
					btn.textContent = TEMF_CONF && TEMF_CONF.labels && TEMF_CONF.labels.copied ? TEMF_CONF.labels.copied : '已复制';
					setTimeout(function() {
						btn.textContent = originalText;
					}, 1200);
				} catch (e) {}
			};

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(function() {
					showCopied(button);
				}).catch(function() {
					// fallback below
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
					} catch (err) {}
				});
			} else {
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
				} catch (err) {}
			}
        },
        
        upload: function() {
            // 多模式下必须先选择目标存储
            if (TEMF_CONF && TEMF_CONF.source === 'multi') {
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
                
                fileInput.addEventListener('change', function() {
                    if (this.files && this.files.length > 0) {
                        upload.handleMultipleFiles(Array.from(this.files));
                    } else {
                        console.warn('[TEMF] 未选择文件');
                    }
                });
            
            fileInput.click();
        }
    };
    
    var upload = {
        queue: [],
        currentIndex: 0,
        totalFiles: 0,
        
        handleFile: function(file) {
            if (!this.validateMultiModeState()) {
                // invalid multi-mode
                return;
            }
            
            var currentSource = this.getCurrentStorageType();
            
            if (currentSource === 'multi') {
                this.uploadToMulti(file, false);
            } else if (currentSource === 'cos') {
                    if (TEMF_CONF.source === 'multi') {
                    this.uploadToMulti(file, false);
                    } else {
                    this.uploadToCos(file, false);
                }
            } else if (currentSource === 'oss') {
                if (TEMF_CONF.source === 'multi') {
                    this.uploadToMulti(file, false);
                } else {
                    this.uploadToOss(file, false);
                }
            } else if (currentSource === 'upyun') {
                if (TEMF_CONF.source === 'multi') {
                    this.uploadToMulti(file, false);
                } else {
                    this.uploadToUpyun(file, false);
                }
            } else if (currentSource === 'lsky') {
                if (TEMF_CONF.source === 'multi') {
                    this.uploadToMulti(file, false);
                } else {
                    this.uploadToLsky(file, false);
                }
            } else {
                if (TEMF_CONF.source === 'multi') {
                    this.uploadToMulti(file, false);
                } else {
                    this.uploadLocal(file, false);
                    }
            }
        },
        
        handleMultipleFiles: function(files) {
            this.queue = files;
            this.currentIndex = 0;
            this.totalFiles = files.length;
            
            progress.show();
            progress.update(0, this.totalFiles);
            
            this.uploadNext();
        },
        
        uploadNext: function() {
            if (this.currentIndex >= this.queue.length) {
                progress.hide();
                return;
            }
            
            if (!this.validateMultiModeState()) {
                console.error('[TEMF] 多模式状态验证失败');
                progress.hide();
                return;
            }
            
            var file = this.queue[this.currentIndex];
            
            progress.update(this.currentIndex, this.totalFiles, file.name);
            
            // 优化多模式下的上传逻辑：根据当前激活的存储类型选择上传方法
            var currentSource = this.getCurrentStorageType();
            
            if (currentSource === 'multi') {
                this.uploadToMulti(file, true);
            } else if (currentSource === 'cos') {
                // 多模式下切换到COS时，使用多模式上传接口
                if (TEMF_CONF.source === 'multi') {
                    this.uploadToMulti(file, true);
                } else {
                    this.uploadToCos(file, true);
                }
            } else if (currentSource === 'oss') {
                // 多模式下切换到OSS时，使用多模式上传接口
                if (TEMF_CONF.source === 'multi') {
                    this.uploadToMulti(file, true);
                } else {
                    this.uploadToOss(file, true);
                }
            } else if (currentSource === 'upyun') {
                // 多模式下切换到又拍云时，使用多模式上传接口
                if (TEMF_CONF.source === 'multi') {
                    this.uploadToMulti(file, true);
                } else {
                    this.uploadToUpyun(file, true);
                }
            } else if (currentSource === 'lsky') {
                // 多模式下切换到兰空图床时，使用多模式上传接口
                if (TEMF_CONF.source === 'multi') {
                    this.uploadToMulti(file, true);
                } else {
                    this.uploadToLsky(file, true);
                }
            } else {
                // 本地存储或默认情况
                    if (TEMF_CONF.source === 'multi') {
                        this.uploadToMulti(file, true);
                    } else {
                        this.uploadLocal(file, true);
                    }
            }
        },
        
        getCurrentStorageType: function() {
            // 多模式下返回当前选中的存储类型，单模式下返回配置的存储类型
            if (TEMF_CONF.source === 'multi' && state.currentStorage) {
                return state.currentStorage;
            }
            return TEMF_CONF.source;
        },
        
        validateMultiModeState: function() {
            // 验证多模式状态是否有效
            if (TEMF_CONF.source === 'multi') {
                if (!state.currentStorage) {
                    // multi no storage
                    return false;
                }
                
                if (!state.availableStorages || state.availableStorages.length === 0) {
                    // multi no storages
                    return false;
                }
                
                // 检查当前存储是否在可用列表中
                var isValidStorage = state.availableStorages.some(function(storage) {
                    return storage.key === state.currentStorage;
                });
                
                if (!isValidStorage) {
                    // multi storage invalid
                    return false;
                }
            }
            
            return true;
        },
        
        onUploadComplete: function(success, fileName) {
            this.currentIndex++;
            
            if (success) {
            } else {
            }
            
            var self = this;
            setTimeout(function() {
                self.uploadNext();
            }, 300);
        },
        
        /**
         * 通用云存储上传方法 - 合并COS/OSS/多模式上传逻辑
         */
        uploadToCloudStorage: function(file, isBatch, storageType, attrName) {
            var self = this;
            var sec = byId('temediafolder');
            
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
            
            var xhr = new XMLHttpRequest();
            xhr.timeout = 120000;
            
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    var percent = (e.loaded / e.total) * 100;
                    progress.updateFileProgress(percent);
                }
            });
            
            xhr.addEventListener('load', function() {
                self.handleUploadResponse(xhr, file, isBatch);
            });
            
            xhr.addEventListener('error', function() {
                self.handleUploadError('网络错误', file, isBatch);
            });
            
            xhr.addEventListener('timeout', function() {
                self.handleUploadError('上传超时', file, isBatch);
            });
            
            xhr.open('POST', uploadUrl);
            xhr.send(formData);
        },
        
        /**
         * 处理上传响应
         */
        handleUploadResponse: function(xhr, file, isBatch) {
            try {
                // 检查 HTTP 状态码
                if (xhr.status < 200 || xhr.status >= 300) {
                    var errorMsg = '上传失败 (HTTP ' + xhr.status + ')';
                    try {
                        var errorResult = JSON.parse(xhr.responseText);
                        if (errorResult && (errorResult.msg || errorResult.message)) {
                            errorMsg = errorResult.msg || errorResult.message;
                        }
                    } catch (e) {
                        // 无法解析错误响应，使用默认错误消息
                        if (xhr.responseText) {
                            errorMsg += ': ' + xhr.responseText.substring(0, 100);
                        }
                    }
                    this.handleUploadError(errorMsg, file, isBatch);
                    return;
                }
                
                var result = JSON.parse(xhr.responseText);
                var success = result.ok && result.url;
                
                if (success) {
                    progress.updateFileProgress(100);
                    ui.prependFile({
                        name: result.name || file.name,
                        url: result.url,
                        thumbnail: result.thumbnail
                    });
                    
                    // 非批量模式，上传成功后隐藏进度条
                    if (!isBatch) {
                        setTimeout(function() {
                            progress.hide();
                        }, 1000);
                    }
                } else {
                    var msg = (result && (result.msg || result.message)) || '上传失败';
                    this.handleUploadError(msg, file, isBatch);
                }
                
                if (isBatch) {
                    this.onUploadComplete(success, file.name);
                }
            } catch (e) {
                this.handleUploadError('上传失败: ' + e.message, file, isBatch);
            }
        },
        
        /**
         * 处理上传错误
         */
        handleUploadError: function(msg, file, isBatch) {
            console.error('[Upload Error]', msg, file.name);
            
            // 显示错误信息
            if (!isBatch) {
                alert('上传失败: ' + msg);
            }
            
            if (progress.setError) progress.setError(msg);
            
            // 延迟隐藏进度条，让用户看到错误信息
            setTimeout(function() {
                progress.hide();
            }, 2000);
            
            if (isBatch) {
                this.onUploadComplete(false, file.name);
            }
        },
        
        uploadToCos: function(file, isBatch) {
            this.uploadToCloudStorage(file, isBatch, 'cos', 'data-cos-upload');
        },
        
        uploadToOss: function(file, isBatch) {
            this.uploadToCloudStorage(file, isBatch, 'oss', 'data-oss-upload');
        },
        
        uploadToUpyun: function(file, isBatch) {
            this.uploadToCloudStorage(file, isBatch, 'upyun', 'data-upyun-upload');
        },
        
        uploadToLsky: function(file, isBatch) {
            var self = this;
            var sec = byId('temediafolder');
            var uploadUrl = sec ? sec.getAttribute('data-lsky-upload') : null;
            if (!uploadUrl) {
                if (isBatch) self.onUploadComplete(false, file.name);
                return;
            }
            
            var path = this.getCurrentPath();
            var formData = new FormData();
            formData.append('file', file);
            formData.append('temf_path', path);
            
            var xhr = new XMLHttpRequest();
            xhr.timeout = 120000;
            xhr.timeout = 120000;
            
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    var percent = (e.loaded / e.total) * 100;
                    progress.updateFileProgress(percent);
                }
            });
            
            xhr.addEventListener('load', function() {
                var text = (xhr && typeof xhr.responseText === 'string') ? xhr.responseText : '';
                var result = null;
                var success = false;
                if (text && text.trim().charAt(0) === '{') {
                    try {
                        result = JSON.parse(text);
                        success = !!(result && result.ok && result.url);
                    } catch (e) {
                        // 保底：按失败处理
                        result = { ok: false, msg: '解析响应失败' };
                    }
                } else {
                    // 空响应或非JSON响应
                    result = { ok: false, msg: (text ? '非JSON响应' : '空响应') };
                }
                    
                    if (success) {
                        progress.updateFileProgress(100);
                        
                        var newFile = {
                            name: result.name || file.name,
                            url: result.url
                        };
                        ui.prependFile(newFile);
                    } else {
                    var msg = (result && (result.msg || result.message)) ? (result.msg || result.message) : '上传失败';
                    // uploadToLsky error
                    progress.setError && progress.setError(msg);
                    progress.hide();
                    }
                    
                    if (isBatch) {
                        self.onUploadComplete(success, file.name);
                    }
            });
            
            xhr.addEventListener('error', function() {
                // uploadToLsky network error
                progress.setError && progress.setError('网络错误');
                progress.hide();
                    if (isBatch) {
                        self.onUploadComplete(false, file.name);
                    }
            });
            
            xhr.addEventListener('timeout', function() {
                // uploadToLsky timeout
                progress.setError && progress.setError('上传超时');
                progress.hide();
                if (isBatch) {
                    self.onUploadComplete(false, file.name);
                }
            });
            
            xhr.addEventListener('error', function() {
                if (isBatch) {
                    self.onUploadComplete(false, file.name);
                }
            });
            xhr.addEventListener('timeout', function() {
                if (isBatch) {
                    self.onUploadComplete(false, file.name);
                }
            });
            
            xhr.open('POST', uploadUrl);
            xhr.send(formData);
        },
        
        uploadToMulti: function(file, isBatch) {
            if (!state.currentStorage) {
                if (isBatch) this.onUploadComplete(false, file.name);
                return;
            }
            this.uploadToCloudStorage(file, isBatch, 'multi', 'data-multi-upload');
        },
        
        uploadLocal: function(file, isBatch) {
            var self = this;
            var sec = byId('temediafolder');
            var uploadUrl = sec ? sec.getAttribute('data-local-upload') : null;
            
            if (!uploadUrl) {
                if (isBatch) self.onUploadComplete(false, file.name);
                return;
            }
            
            var formData = new FormData();
            formData.append('file', file);
            
            var xhr = new XMLHttpRequest();
            xhr.timeout = 120000;
            
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    var percent = (e.loaded / e.total) * 100;
                    progress.updateFileProgress(percent);
                }
            });
            
            xhr.addEventListener('load', function() {
                try {
                    var result = JSON.parse(xhr.responseText);
                var success = result.ok && result.url;
                
                if (success) {
                    progress.updateFileProgress(100);
                    
                    var now = new Date();
                    var year = now.getFullYear().toString();
                    var month = (now.getMonth() + 1).toString().padStart(2, '0');
                    
                    TEMF_CONF.data = TEMF_CONF.data || {};
                    TEMF_CONF.data[year] = TEMF_CONF.data[year] || {};
                    TEMF_CONF.data[year][month] = TEMF_CONF.data[year][month] || [];
                    
                    var newItem = {
                        url: result.url,
                        name: result.name,
                        mtime: Math.floor(Date.now() / 1000)
                    };
                    
                    var list = TEMF_CONF.data[year][month];
                    list = [newItem].concat(list.filter(function(item) {
                        return item.url !== result.url;
                    }));
                    TEMF_CONF.data[year][month] = list;
                    
                    var modalEl = document.getElementById('temf-modal');
                    if (modalEl && modalEl.classList.contains('open')) {
                        var ySel = byId('temf-year');
                        var mSel = byId('temf-month');
                        
                        if (ySel && mSel) {
                            // 如果选择器未初始化，构建它们
                            if (!ySel.value || !mSel.value) {
                                local.buildYearMonth();
                                ySel.value = year;
                                local.buildMonths(year);
                                mSel.value = month;
                            }
                            
                            // 检查是否在当前显示的年月
                            if (ySel.value === year && mSel.value === month) {
                                // 只添加新图片到顶部，而不是重新渲染所有
                                ui.prependFile(newItem);
                            } else {
                                // 如果不是当前年月，更新选择器并重新渲染
                                ySel.value = year;
                                local.buildMonths(year);
                                mSel.value = month;
                                local.renderCurrentMonth();
                            }
                        }
                    }
                } else {
                    if (!isBatch) {
                        alert('上传失败: ' + (result.msg || '未知错误'));
                    }
                }
                
                if (isBatch) {
                    self.onUploadComplete(success, file.name);
                }
                } catch (e) {
                    if (isBatch) {
                        self.onUploadComplete(false, file.name);
                    }
                }
            });
            
            xhr.addEventListener('error', function() {
                if (isBatch) {
                    self.onUploadComplete(false, file.name);
                }
            });
            xhr.addEventListener('timeout', function() {
                if (isBatch) {
                    self.onUploadComplete(false, file.name);
                }
            });
            
            xhr.open('POST', uploadUrl);
            xhr.send(formData);
        },
        
        getCurrentPath: function() {
            var dir = byId('temf-dir');
            var subdir = byId('temf-subdir');
            
            if (!dir || !subdir) return '';
            
            var p1 = dir.value || '';
            var p2 = subdir.value || '';
            
            return p2 ? (p1 ? p1 + '/' + p2 : p2) : p1;
        }
    };
    
    /**
     * 分页控制器
     */
    var pagination = {
        element: null,
        scrollListener: null,
        
        show: function() {
            if (!this.element) {
                this.create();
            }
            this.element.style.display = 'flex';
        },
        
        hide: function() {
            if (this.element) {
                this.element.style.display = 'none';
            }
            this.removeScrollListener();
        },
        
        
        /**
         * 移除滚动监听
         */
        removeScrollListener: function() {
            if (this.scrollListener) {
                var body = document.querySelector('#temf-modal .temf-body');
                if (body) {
                    body.removeEventListener('scroll', this.scrollListener);
                }
                this.scrollListener = null;
            }
        },
        
        update: function() {
            if (!this.element) {
                this.create();
                if (!this.element) {
                    return;
                }
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
        
        prevPage: function() {
            if (state.pagination.currentPage > 1) {
                state.pagination.currentPage--;
                ui.renderCurrentPage();
                this.scrollToTop();
            }
        },
        
        nextPage: function() {
            var totalPages = Math.ceil(state.pagination.totalItems / state.pagination.pageSize);
            if (state.pagination.currentPage < totalPages) {
                state.pagination.currentPage++;
                ui.renderCurrentPage();
                this.scrollToTop();
            }
        },
        
        scrollToTop: function() {
            var body = document.querySelector('#temf-modal .temf-body');
            if (body) {
                body.scrollTop = 0;
            }
        },
        
        create: function() {
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
                prevBtn.addEventListener('click', function() {
                    self.prevPage();
                });
            }
            
            if (nextBtn) {
                nextBtn.addEventListener('click', function() {
                    self.nextPage();
                });
            }
        }
    };
    
    // 暴露到全局作用域供调试使用
    window.TEMF_PAGINATION = pagination;
    
    var progress = {
        element: null,
        
        show: function() {
            if (!this.element) {
                this.create();
            }
            this.element.style.display = 'flex';
            
            // 重置错误状态
            var label = this.element.querySelector('.temf-progress-label');
            if (label) {
                label.style.color = '';
            }
            
            var progressBar = this.element.querySelector('.temf-progress-bar');
            if (progressBar) {
                progressBar.style.background = '';
            }
        },
        
        hide: function() {
            if (this.element) {
                this.element.style.display = 'none';
            }
        },
        
        update: function(current, total, fileName) {
            if (!this.element) return;
            
            var counter = this.element.querySelector('.temf-progress-counter');
            if (counter) {
                counter.textContent = current + '/' + total;
            }
            
            var label = this.element.querySelector('.temf-progress-label');
            if (label && fileName) {
                label.textContent = fileName;
                label.title = fileName;
            }
            
            this.updateFileProgress(0);
        },
        
        updateFileProgress: function(percent) {
            if (!this.element) return;
            
            percent = Math.max(0, Math.min(100, percent));
            var progressBar = this.element.querySelector('.temf-progress-bar');
            var progressPercent = this.element.querySelector('.temf-progress-percent');
            
            if (progressBar) {
                progressBar.style.width = percent + '%';
            }
            
            if (progressPercent) {
                progressPercent.textContent = Math.round(percent) + '%';
            }
        },
        
        setError: function(msg) {
            if (!this.element) return;
            
            var label = this.element.querySelector('.temf-progress-label');
            if (label) {
                label.textContent = '错误: ' + msg;
                label.style.color = '#d63638';
            }
            
            var progressBar = this.element.querySelector('.temf-progress-bar');
            if (progressBar) {
                progressBar.style.background = '#d63638';
            }
        },
        
        create: function() {
            this.element = document.createElement('div');
            this.element.className = 'temf-progress';
            this.element.innerHTML = 
                '<div class="temf-progress-counter">0/0</div>' +
                '<div class="temf-progress-container">' +
                    '<div class="temf-progress-label">正在准备...</div>' +
                    '<div class="temf-progress-bg">' +
                        '<div class="temf-progress-bar"></div>' +
                    '</div>' +
                '</div>' +
                '<div class="temf-progress-percent">0%</div>';
            
            var title = document.getElementById('temf-title');
            if (title && title.parentNode) {
                title.parentNode.insertBefore(this.element, title.nextSibling);
            }
        }
    };
    
    function mount() {
        var toolbar = byId("temediafolder");
        var tab = byId("tab-files");
        var modalEl = byId("temf-modal");
        
        if (tab && toolbar) {
            tab.insertBefore(toolbar, tab.firstChild);
        }
        
        if (modalEl && !modalEl.parentElement) {
            document.body.appendChild(modalEl);
        }
    }
    
    // 创建防抖的上传处理器
    var debouncedUpload = debounce(function() {
        fileOps.upload();
    }, 300);
    
    document.addEventListener('click', function(e) {
        var target = e.target;
        
            if (target && target.id === "temf-open") {
                modal.open();
                e.preventDefault();
            }
            
            if (target && (target.id === "temf-close" || target.hasAttribute("data-temf-close"))) {
                modal.close();
                e.preventDefault();
            }
            
        if (target && target.matches(".temf-pick")) {
                var url = target.value;
                if (target.checked) {
                    selection.add(url);
                } else {
                    selection.remove(url);
                }
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
    });
    
    /**
     * 处理云存储目录切换（合并COS/OSS逻辑）
     */
    function handleCloudStorageDirectoryChange(target, currentSource) {
        var fetchFunction = TEMF_CONF.source === 'multi' ? multi.fetch : cloudStorage.fetch.bind(cloudStorage, currentSource);
        
        if (target.id === 'temf-dir') {
                    var path = target.value || '';
                    var sub = byId('temf-subdir');
                    sub.innerHTML = '';
                    
                    var opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = '/';
                    sub.appendChild(opt);
                    
                    fetchFunction(path, function(data) {
                        var folders = data.folders || [];
                        folders.forEach(function(folder) {
                            var opt = document.createElement('option');
                            opt.value = folder.path || folder.name;
                            opt.textContent = folder.name || folder.path;
                            sub.appendChild(opt);
                        });
                ui.renderFiles(data.files || []);
                    });
        } else if (target.id === 'temf-subdir') {
                    var p1 = (byId('temf-dir').value || '');
                    var p2 = target.value || '';
                    var path = p2 ? (p1 ? p1 + '/' + p2 : p2) : p1;
                    
                    fetchFunction(path, function(data) {
                ui.renderFiles(data.files || []);
            });
        }
    }
    
    document.addEventListener('change', function(e) {
        var target = e.target;
        if (!target) return;
        
        // 获取当前实际使用的存储类型
        var currentSource = TEMF_CONF.source;
        if (currentSource === 'multi' && state.currentStorage) {
            currentSource = state.currentStorage;
        }
        
        // 处理云存储（COS/OSS/UPYUN）目录切换
        if ((currentSource === 'cos' || currentSource === 'oss' || currentSource === 'upyun') && 
            (target.id === 'temf-dir' || target.id === 'temf-subdir')) {
            handleCloudStorageDirectoryChange(target, currentSource);
            } else if (currentSource === 'lsky') {
                if (target && target.id === 'temf-dir') {
                    var selection = target.value;
                    
                    // 多模式下使用multi.fetch，单模式下使用lsky.fetch
                    var fetchFunction = TEMF_CONF.source === 'multi' ? multi.fetch : lsky.fetch;
                    
                    if (selection === 'album') {
                        // 选择相册：使用相册ID过滤
                        fetchFunction('album', function(data) {
                            ui.renderFiles(data.files || []);
                        });
                    } else {
                        // 选择全部：显示所有图片
                        fetchFunction('', function(data) {
                            ui.renderFiles(data.files || []);
                        });
                    }
                }
                
                // 兰空图床不需要处理 temf-subdir，因为已经隐藏了
            } else if (currentSource === 'local') {
                if (target && target.id === 'temf-year') {
                    local.buildMonths(target.value);
                    local.renderCurrentMonth();
                }
                
                if (target && target.id === 'temf-month') {
                    local.renderCurrentMonth();
                }
            }
    });
    
    document.addEventListener("keydown", function(e) {
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
            document.querySelectorAll('.temf-lazy-img').forEach(function(img) {
                if (img.dataset.src) {
                    loadImageWithTransition(img);
                }
            });
            return;
        }
        
        var imageObserver = new IntersectionObserver(function(entries, observer) {
            entries.forEach(function(entry) {
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
        document.querySelectorAll('.temf-lazy-img').forEach(function(img) {
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
        
        var targetSrc = img.dataset.src;
        if (!targetSrc) {
            return;
        }
        
        // 创建新图片对象预加载
        var tempImg = new Image();
        
        tempImg.onload = function() {
            // 图片预加载成功，但还要等实际元素加载完成
            img.src = targetSrc;
            
            // 等待实际 img 元素加载完成
            img.onload = function() {
                // 真正加载完成，开始过渡
                img.classList.remove('temf-loading');
                img.classList.add('temf-loaded');
                
                // 淡入动画
                requestAnimationFrame(function() {
                    img.style.transition = 'opacity 0.3s ease-in-out';
                    img.style.opacity = '0';
                    
                    requestAnimationFrame(function() {
                        img.style.opacity = '1';
                    });
                });
            };
            
            // 如果图片已经缓存，立即触发
            if (img.complete) {
                img.onload();
            }
        };
        
        tempImg.onerror = function() {
            // 加载失败，尝试使用原始URL
            img.classList.remove('temf-loading');
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
        window.addEventListener('resize', function() {
            // 防抖处理
            if (resizeTimer) {
                clearTimeout(resizeTimer);
            }
            
            resizeTimer = setTimeout(function() {
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
        
        lazyImageMutationObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) {
                    initLazyLoading();
                }
            });
        });
        
        lazyImageMutationObserver.observe(targetNode, {
            childList: true,
            subtree: true
        });
    }
    
    if (document.readyState !== "loading") {
        mount();
        setupLazyImageObserver();
        setupResizeListener();
    } else {
        document.addEventListener("DOMContentLoaded", function() {
            mount();
            setupLazyImageObserver();
            setupResizeListener();
        });
    }
    
})();
