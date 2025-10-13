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
        lskyLoaded: false,
        multiLoaded: false,
        currentStorage: null,
        availableStorages: [],
        selected: new Set()
    };
    
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
            
            var html = '';
            if (items.length === 0) {
                html = '<p class="description">无图片</p>';
            } else {
                html += '<ul class="temf-grid">';
                items.forEach(function(item) {
                    html += ui.renderFileItem(item);
                });
                html += '</ul>';
            }
            
            body.innerHTML = html;
            selection.clear();
            } catch (e) {
                // render error
            }
        }
    };
    
    var cos = {
        init: function() {
            var dir = byId('temf-dir');
            var sub = byId('temf-subdir');
            if (!dir || !sub) return;
            
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
            
            this.fetch('', function(data) {
                var folders = data.folders || [];
                folders.forEach(function(folder) {
                    var opt = document.createElement('option');
                    opt.value = folder.path || folder.name;
                    opt.textContent = folder.name || folder.path;
                    dir.appendChild(opt);
                });
                
                ui.renderFiles(data.files || []);
            });
        },
        
        fetch: function(path, callback) {
            var sec = byId('temediafolder');
            if (!sec) return;
            
            var base = sec.getAttribute('data-cos-list');
            if (!base) return;
            
            var url = base;
            if (path) {
                url += (base.indexOf('?') >= 0 ? '&' : '?') + 'temf_path=' + encodeURIComponent(path);
            }
            
            fetch(url)
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    callback && callback(data);
                })
                .catch(function() {
                    callback && callback({folders: [], files: []});
                });
        }
    };
    
    var oss = {
        init: function() {
            var dir = byId('temf-dir');
            var sub = byId('temf-subdir');
            if (!dir || !sub) return;
            
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
            
            this.fetch('', function(data) {
                var folders = data.folders || [];
                folders.forEach(function(folder) {
                    var opt = document.createElement('option');
                    opt.value = folder.path || folder.name;
                    opt.textContent = folder.name || folder.path;
                    dir.appendChild(opt);
                });
                
                ui.renderFiles(data.files || []);
            });
        },
        
        fetch: function(path, callback) {
            var sec = byId('temediafolder');
            if (!sec) return;
            
            var base = sec.getAttribute('data-oss-list');
            if (!base) return;
            
            var url = base;
            if (path) {
                url += (base.indexOf('?') >= 0 ? '&' : '?') + 'temf_path=' + encodeURIComponent(path);
            }
            
            fetch(url)
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    callback && callback(data);
                })
                .catch(function() {
                    callback && callback({folders: [], files: []});
                });
        }
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
            
            fetch(url)
                .then(function(response) {
                    return response.json();
                })
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
            fetch(storageTypesUrl)
                .then(function(response) {
                    return response.json();
                })
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
                btn.innerHTML = storage.icon + ' ' + storage.name;
                
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
                'lsky': '兰空图床'
            };
            var storageName = storageNames[storageType] || storageType;
            
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
                    // 本地模式：隐藏目录选择器，显示年份/月份选择器
                    multi.hideDirectorySelectors();
                    multi.showLocalSelectors();
                    
                    // 多模式下需要先获取本地文件数据来构建年份/月份选择器
                    multi.loadLocalData();
                } else if (storageType === 'lsky') {
                    // 兰空图床模式：特殊处理，显示全部/相册选择器
                    multi.hideLocalSelectors();
                    multi.initLskySelectors();
                    
                    // 加载兰空图床数据
                    multi.loadLskyData();
                } else {
                    // COS/OSS模式：显示目录选择器，隐藏年份/月份选择器
                    multi.showDirectorySelectors();
                    multi.hideLocalSelectors();
                    multi.initDirectorySelectors();
                    
                    // 加载文件列表
                    multi.fetch('', function(data) {
                            // 填充文件夹下拉选项
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
            }, 300);
        },
        
        finishSwitchAnimation: function() {
            var body = document.querySelector('#temf-modal .temf-body');
            if (body) {
                body.classList.remove('temf-content-switching');
                body.classList.add('temf-content-switched');
                setTimeout(function() {
                    body.classList.remove('temf-content-switched');
                }, 300);
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
            
            fetch(url)
                .then(function(response) {
                    return response.json();
                })
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
            if (!body) return;
            
            if (!files || !files.length) {
                body.innerHTML = '<p class="description">无图片</p>';
            selection.clear();
                return;
            }
            
            var fragment = document.createDocumentFragment();
            var grid = document.createElement('ul');
            grid.className = 'temf-grid';
            fragment.appendChild(grid);
            body.innerHTML = '';
            body.appendChild(fragment);
            
            // 分块渲染，避免一次性插入造成卡顿
            var i = 0;
            var chunk = 60;
            var self = this;
            function appendChunk() {
                var end = Math.min(i + chunk, files.length);
                var htmlParts = [];
                for (; i < end; i++) {
                    htmlParts.push(self.renderFileItem(files[i]));
                }
                if (htmlParts.length) {
                    var div = document.createElement('div');
                    div.innerHTML = htmlParts.join('');
                    // 将div的子元素(li)移入grid，避免重排
                    while (div.firstChild) {
                        grid.appendChild(div.firstChild);
                    }
                }
                if (i < files.length) {
                    if (window.requestIdleCallback) {
                        requestIdleCallback(appendChunk, { timeout: 50 });
                    } else {
                        requestAnimationFrame(appendChunk);
                    }
                } else {
                    selection.clear();
                    requestAnimationFrame(function() {
                        grid.classList.add('temf-grid-loaded');
                    });
                }
            }
            appendChunk();
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
            
            return '<li class="temf-item" data-url="' + safeUrl + '">' +
                '<div class="temf-thumb">' +
                '<input type="checkbox" class="temf-pick" value="' + safeUrl + '">' +
                '<img src="' + thumbnail + '" alt="' + safeName + '" loading="lazy" decoding="async" fetchpriority="low" referrerpolicy="no-referrer" ' +
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
            var body = document.querySelector('#temf-modal .temf-body');
            if (!body) return;
            
            var grid = body.querySelector('.temf-grid');
            if (!grid) {
                body.innerHTML = '<ul class="temf-grid"></ul>';
                grid = body.querySelector('.temf-grid');
            }
            
            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            var safeUrl = escapeHtml(fileItem.url);
            var existingItem = grid.querySelector('[data-url="' + safeUrl + '"]');
            if (existingItem) {
                existingItem.remove();
            }
            
            var itemHtml = this.renderFileItem(fileItem);
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = itemHtml;
            var newItem = tempDiv.firstChild;
            
            if (grid.firstChild) {
                grid.insertBefore(newItem, grid.firstChild);
            } else {
                grid.appendChild(newItem);
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
                    // choose storage first
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
                // invalid multi-mode stop
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
        
        uploadToCos: function(file, isBatch) {
            var self = this;
            var sec = byId('temediafolder');
            var uploadUrl = sec ? sec.getAttribute('data-cos-upload') : null;
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
                        
                        var newFile = {
                            name: result.name || file.name,
                            url: result.url
                        };
                        ui.prependFile(newFile);
                    } else {
                        // 显示错误信息并复位进度
                        var msg = (result && (result.msg || result.message)) ? (result.msg || result.message) : '上传失败';
                        // uploadToMulti error
                        progress.setError && progress.setError(msg);
                        progress.hide();
                    }
                    
                    if (isBatch) {
                        self.onUploadComplete(success, file.name);
                    }
                } catch (e) {
                    // uploadToMulti parse error
                    progress.setError && progress.setError('上传失败');
                    progress.hide();
                    if (isBatch) {
                        self.onUploadComplete(false, file.name);
                    }
                }
            });
            
            xhr.addEventListener('error', function() {
                // uploadToMulti network error
                progress.setError && progress.setError('网络错误');
                progress.hide();
                if (isBatch) {
                    self.onUploadComplete(false, file.name);
                }
            });
            xhr.addEventListener('timeout', function() {
                // uploadToMulti timeout
                progress.setError && progress.setError('上传超时');
                progress.hide();
                if (isBatch) {
                    self.onUploadComplete(false, file.name);
                }
            });
            
            xhr.open('POST', uploadUrl);
            xhr.send(formData);
        },
        
        uploadToOss: function(file, isBatch) {
            var self = this;
            var sec = byId('temediafolder');
            var uploadUrl = sec ? sec.getAttribute('data-oss-upload') : null;
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
                        
                        var newFile = {
                            name: result.name || file.name,
                            url: result.url
                        };
                        ui.prependFile(newFile);
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
            var self = this;
            var sec = byId('temediafolder');
            var uploadUrl = sec ? sec.getAttribute('data-multi-upload') : null;
            
            if (!uploadUrl || !state.currentStorage) {
                if (isBatch) self.onUploadComplete(false, file.name);
                return;
            }
            
            var path = this.getCurrentPath();
            var formData = new FormData();
            formData.append('file', file);
            formData.append('storage_type', state.currentStorage);
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
                try {
                    var result = JSON.parse(xhr.responseText);
                    var success = result.ok && result.url;
                    
                    if (success) {
                        progress.updateFileProgress(100);
                        
                        var newFile = {
                            name: result.name || file.name,
                            url: result.url
                        };
                        ui.prependFile(newFile);
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
                            if (!ySel.value || !mSel.value) {
                                local.buildYearMonth();
                            }
                            
                            ySel.value = year;
                            local.buildMonths(year);
                            mSel.value = month;
                            
                            local.renderCurrentMonth();
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
    
    var progress = {
        element: null,
        
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
                fileOps.upload();
                e.preventDefault();
            }
    });
    
    document.addEventListener('change', function(e) {
        var target = e.target;
        
            // 获取当前实际使用的存储类型
            var currentSource = TEMF_CONF.source;
            if (currentSource === 'multi' && state.currentStorage) {
                currentSource = state.currentStorage;
            }
            
            if (currentSource === 'cos') {
                if (target && target.id === 'temf-dir') {
                    var path = target.value || '';
                    var sub = byId('temf-subdir');
                    sub.innerHTML = '';
                    
                    var opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = '/';
                    sub.appendChild(opt);
                    
                    // 多模式下使用multi.fetch，单模式下使用cos.fetch
                    var fetchFunction = TEMF_CONF.source === 'multi' ? multi.fetch : cos.fetch;
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
                }
                
                if (target && target.id === 'temf-subdir') {
                    var p1 = (byId('temf-dir').value || '');
                    var p2 = target.value || '';
                    var path = p2 ? (p1 ? p1 + '/' + p2 : p2) : p1;
                    
                    // 多模式下使用multi.fetch，单模式下使用cos.fetch
                    var fetchFunction = TEMF_CONF.source === 'multi' ? multi.fetch : cos.fetch;
                    fetchFunction(path, function(data) {
                        ui.renderFiles(data.files || []);
                    });
                }
            } else if (currentSource === 'oss') {
                if (target && target.id === 'temf-dir') {
                    var path = target.value || '';
                    var sub = byId('temf-subdir');
                    sub.innerHTML = '';
                    
                    var opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = '/';
                    sub.appendChild(opt);
                    
                    // 多模式下使用multi.fetch，单模式下使用oss.fetch
                    var fetchFunction = TEMF_CONF.source === 'multi' ? multi.fetch : oss.fetch;
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
                }
                
                if (target && target.id === 'temf-subdir') {
                    var p1 = (byId('temf-dir').value || '');
                    var p2 = target.value || '';
                    var path = p2 ? (p1 ? p1 + '/' + p2 : p2) : p1;
                    
                    // 多模式下使用multi.fetch，单模式下使用oss.fetch
                    var fetchFunction = TEMF_CONF.source === 'multi' ? multi.fetch : oss.fetch;
                    fetchFunction(path, function(data) {
                        ui.renderFiles(data.files || []);
                    });
                }
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
    
    
    if (document.readyState !== "loading") {
        mount();
    } else {
        document.addEventListener("DOMContentLoaded", mount);
    }
    
})();
