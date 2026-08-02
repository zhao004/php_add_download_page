/**
 * 后台通用交互。
 *
 * 副作用：自动展示服务端 Toast（最多同时 3 条）、复制 IP，
 * 并在危险表单真正提交前打开确认 Modal。
 */
(() => {
    'use strict';

    /** Toast 默认最大同时展示条数。 */
    const DEFAULT_TOAST_MAX = 3;

    /** Toast 自动关闭时长（毫秒），需与 CSS 进度条动画保持一致。 */
    const TOAST_DELAY_MS = 4200;

    /** 允许的 Toast 语义类型。 */
    const TOAST_TYPES = new Set(['success', 'danger', 'warning', 'info']);

    /** 各类型对应的 Bootstrap Icons 图标。 */
    const TOAST_ICONS = {
        success: 'bi-check-circle-fill',
        danger: 'bi-x-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill',
    };

    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebarLinks = document.querySelectorAll('.admin-sidebar .sidebar-link');

    /**
     * 同步桌面侧栏外观与无障碍状态。
     *
     * 副作用：更新根元素类名、按钮属性；仅在用户主动折叠时启用过渡，避免切页抖动。
     *
     * @param {boolean} collapsed 是否收起
     * @param {{enableTransitions?: boolean}} [options] 是否允许过渡动画
     * @returns {void}
     */
    const applySidebarState = (collapsed, options = {}) => {
        const root = document.documentElement;
        if (options.enableTransitions) {
            root.classList.add('sidebar-transitions-ready');
        }

        root.classList.toggle('sidebar-collapsed', collapsed);
        if (sidebarToggle instanceof HTMLButtonElement) {
            const label = collapsed ? '展开侧栏' : '收起侧栏';
            sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
            sidebarToggle.setAttribute('aria-label', label);
            sidebarToggle.title = label;
            // 图标类名保持不变，收起态通过 CSS scaleX 镜像表达方向，避免切换时字形跳动。
            const icon = sidebarToggle.querySelector('i');
            if (icon instanceof HTMLElement) {
                icon.className = 'bi bi-layout-sidebar-inset';
            }
        }

        sidebarLinks.forEach((link) => {
            const label = link.querySelector('span')?.textContent?.trim() || '';
            if (collapsed && label) {
                link.setAttribute('title', label);
            } else {
                link.removeAttribute('title');
            }
        });
    };

    // 首屏只恢复折叠状态，不启用过渡，避免整页刷新时侧栏闪动。
    const initiallyCollapsed = document.documentElement.classList.contains('sidebar-collapsed');
    applySidebarState(initiallyCollapsed, {enableTransitions: false});
    if (sidebarToggle instanceof HTMLButtonElement) {
        sidebarToggle.addEventListener('click', () => {
            const collapsed = !document.documentElement.classList.contains('sidebar-collapsed');
            applySidebarState(collapsed, {enableTransitions: true});
            try {
                localStorage.setItem('nova.admin.sidebarCollapsed', String(collapsed));
            } catch (error) {
                // 隐私模式禁用本地存储时仅保留当前页面状态。
            }
        });
    }

    // 首屏绘制完成后再允许导航 hover 过渡，避免切页时按钮颜色过渡抖动。
    const enableSidebarNavInteraction = () => {
        document.documentElement.classList.add('sidebar-nav-interactive');
    };
    if (document.readyState === 'complete') {
        window.setTimeout(enableSidebarNavInteraction, 0);
    } else {
        window.addEventListener('load', () => {
            window.setTimeout(enableSidebarNavInteraction, 0);
        }, {once: true});
    }

    /**
     * 获取或创建全局 Toast 容器。
     *
     * @returns {HTMLElement}
     */
    const getToastContainer = () => {
        const existing = document.getElementById('adminToastContainer');
        if (existing instanceof HTMLElement) {
            return existing;
        }

        const created = document.createElement('div');
        created.id = 'adminToastContainer';
        created.className = 'toast-container position-fixed top-0 end-0 p-3';
        created.dataset.toastMax = String(DEFAULT_TOAST_MAX);
        created.setAttribute('aria-live', 'polite');
        created.setAttribute('aria-relevant', 'additions');
        document.body.appendChild(created);
        return created;
    };

    /**
     * 读取容器配置的最大 Toast 数量。
     *
     * @param {HTMLElement} container Toast 容器
     * @returns {number}
     */
    const resolveToastMax = (container) => {
        const configured = Number.parseInt(container.dataset.toastMax || '', 10);
        if (Number.isFinite(configured) && configured > 0) {
            return Math.min(configured, 10);
        }
        return DEFAULT_TOAST_MAX;
    };

    /**
     * 仅保留最新 N 条 Toast，移除更早的条目以避免刷屏。
     *
     * @param {HTMLElement} container Toast 容器
     * @returns {void}
     */
    const enforceToastLimit = (container) => {
        const toasts = Array.from(container.querySelectorAll('.admin-toast'));
        const overflow = toasts.length - resolveToastMax(container);
        if (overflow <= 0) {
            return;
        }

        toasts.slice(0, overflow).forEach((toastElement) => {
            if (!(toastElement instanceof HTMLElement)) {
                return;
            }
            const instance = bootstrap.Toast.getInstance(toastElement);
            if (instance) {
                instance.dispose();
            }
            toastElement.remove();
        });
    };

    /**
     * 绑定 Toast 关闭后自动清理 DOM。
     *
     * @param {HTMLElement} toastElement Toast 节点
     * @returns {void}
     */
    const bindToastLifecycle = (toastElement) => {
        toastElement.addEventListener('hidden.bs.toast', () => {
            const instance = bootstrap.Toast.getInstance(toastElement);
            if (instance) {
                instance.dispose();
            }
            toastElement.remove();
        }, {once: true});
    };

    /**
     * 规范化 Toast 语义类型。
     *
     * @param {string} type 原始类型
     * @returns {'success'|'danger'|'warning'|'info'}
     */
    const resolveToastType = (type) => (TOAST_TYPES.has(type) ? type : 'info');

    /**
     * 从 Toast 节点解析语义类型（兼容新旧 class / data 属性）。
     *
     * @param {HTMLElement} toastElement Toast 节点
     * @returns {string}
     */
    const readToastType = (toastElement) => {
        const fromData = toastElement.dataset.toastType || '';
        if (TOAST_TYPES.has(fromData)) {
            return fromData;
        }
        const toneClass = Array.from(toastElement.classList).find((className) => (
            className.startsWith('admin-toast--') || className.startsWith('text-bg-')
        ));
        if (!toneClass) {
            return 'info';
        }
        const tone = toneClass
            .replace('admin-toast--', '')
            .replace('text-bg-', '');
        return resolveToastType(tone);
    };

    /**
     * 构建一条美化后的 Toast DOM。
     *
     * @param {string} message 提示文案
     * @param {string} type 语义类型
     * @returns {HTMLElement}
     */
    const createToastElement = (message, type) => {
        const tone = resolveToastType(type);
        const iconClass = TOAST_ICONS[tone] || TOAST_ICONS.info;
        const toastElement = document.createElement('div');
        toastElement.className = `toast admin-toast admin-toast--${tone}`;
        toastElement.setAttribute('role', 'status');
        toastElement.setAttribute('aria-atomic', 'true');
        toastElement.dataset.toastType = tone;
        toastElement.dataset.bsDelay = String(TOAST_DELAY_MS);
        toastElement.dataset.bsAutohide = 'true';
        toastElement.style.setProperty('--admin-toast-duration', `${TOAST_DELAY_MS}ms`);
        toastElement.innerHTML = [
            '<div class="admin-toast-inner">',
            `  <span class="admin-toast-icon" aria-hidden="true"><i class="bi ${iconClass}"></i></span>`,
            '  <div class="toast-body"></div>',
            '  <button type="button" class="admin-toast-close" data-bs-dismiss="toast" aria-label="关闭">',
            '    <i class="bi bi-x-lg" aria-hidden="true"></i>',
            '  </button>',
            '</div>',
            '<div class="admin-toast-progress" aria-hidden="true"></div>',
        ].join('');

        const body = toastElement.querySelector('.toast-body');
        if (body instanceof HTMLElement) {
            body.textContent = message;
        }

        return toastElement;
    };

    /**
     * 展示一条后台 Toast；超过上限时丢弃最早的条目。
     *
     * @param {string} message 提示文案
     * @param {string} [type='success'] success/danger/warning/info
     * @returns {void}
     */
    const showAdminToast = (message, type = 'success') => {
        const text = typeof message === 'string' ? message.trim() : '';
        if (!text) {
            return;
        }

        const container = getToastContainer();
        const toastElement = createToastElement(text, type);
        container.appendChild(toastElement);
        enforceToastLimit(container);
        bindToastLifecycle(toastElement);
        bootstrap.Toast.getOrCreateInstance(toastElement, {
            delay: TOAST_DELAY_MS,
            autohide: true,
        }).show();
    };

    // 展示服务端下发的 Toast，并保证不超过最大条数。
    const toastContainer = getToastContainer();
    enforceToastLimit(toastContainer);
    toastContainer.querySelectorAll('.admin-toast').forEach((element) => {
        if (!(element instanceof HTMLElement)) {
            return;
        }
        // 服务端渲染的 Toast 同步写入时长变量，驱动底部进度条。
        element.style.setProperty('--admin-toast-duration', `${TOAST_DELAY_MS}ms`);
        if (!element.dataset.toastType) {
            element.dataset.toastType = readToastType(element);
        }
        bindToastLifecycle(element);
        bootstrap.Toast.getOrCreateInstance(element, {
            delay: TOAST_DELAY_MS,
            autohide: true,
        }).show();
    });

    // 供同页脚本复用（例如异步操作成功提示）。
    window.showAdminToast = showAdminToast;

    document.addEventListener('click', async (event) => {
        const button = event.target instanceof Element
            ? event.target.closest('[data-copy-text]')
            : null;
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        const value = button.dataset.copyText || '';
        if (!value || !navigator.clipboard) {
            return;
        }

        try {
            await navigator.clipboard.writeText(value);
            const icon = button.querySelector('i');
            if (icon instanceof HTMLElement) {
                icon.className = 'bi bi-check2';
                window.setTimeout(() => {
                    icon.className = 'bi bi-copy';
                }, 1200);
            }
        } catch (error) {
            button.title = '复制失败，请手动选择 IP';
        }
    });

    /**
     * 在上传容器内解析目标输入框，优先使用容器作用域，避免弹窗场景下 ID 查找失败。
     *
     * @param {HTMLElement} container 图片上传容器
     * @param {string} targetId 目标输入框 ID
     * @returns {HTMLInputElement|null}
     */
    const resolveImageTargetInput = (container, targetId) => {
        if (!targetId) {
            return null;
        }

        const escapedId = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
            ? CSS.escape(targetId)
            : targetId.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
        const scoped = container.querySelector('#' + escapedId);
        if (scoped instanceof HTMLInputElement) {
            return scoped;
        }

        const global = document.getElementById(targetId);
        return global instanceof HTMLInputElement ? global : null;
    };

    /**
     * 初始化单个图片上传控件。
     *
     * 副作用：点击“上传”打开系统文件选择器，选中后自动上传并回填路径与预览。
     * 动态弹窗会重复调用本函数，因此以数据属性避免重复监听。
     *
     * @param {HTMLElement} container 图片上传容器
     * @returns {void}
     */
    const initializeImageUpload = (container) => {
        if (container.dataset.imageUploadReady === 'true') {
            return;
        }

        const fileInput = container.querySelector('[data-image-file]');
        const submitButton = container.querySelector('[data-image-submit]');
        const feedback = container.querySelector('[data-image-feedback]');
        const preview = container.querySelector('[data-image-preview]');
        const targetId = container.getAttribute('data-target') || '';
        const targetInput = resolveImageTargetInput(container, targetId);
        const imageMaxBytes = Number.parseInt(container.dataset.imageMaxBytes || '', 10);
        const imageMaxMb = Number.parseInt(container.dataset.imageMaxMb || '', 10);
        const svgMaxBytes = Number.parseInt(container.dataset.svgMaxBytes || '', 10);
        const svgMaxMb = Number.parseInt(container.dataset.svgMaxMb || '', 10);
        if (!(fileInput instanceof HTMLInputElement)
            || !(submitButton instanceof HTMLButtonElement)
            || !(feedback instanceof HTMLElement)
            || !(preview instanceof HTMLImageElement)
            || !(targetInput instanceof HTMLInputElement)) {
            return;
        }

        container.dataset.imageUploadReady = 'true';
        let uploading = false;
        const idleLabel = (submitButton.textContent || '上传').trim() || '上传';

        const setFeedback = (message, state = '') => {
            feedback.textContent = message;
            feedback.classList.toggle('is-success', state === 'success');
            feedback.classList.toggle('is-error', state === 'error');
        };

        /**
         * 上传当前选中的图片文件。
         *
         * @returns {Promise<void>}
         */
        const uploadSelectedFile = async () => {
            const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
            if (!file || uploading) {
                return;
            }

            const isSvg = file.name.toLowerCase().endsWith('.svg');
            const maximumBytes = isSvg ? svgMaxBytes : imageMaxBytes;
            const maximumMegabytes = isSvg ? svgMaxMb : imageMaxMb;
            if (!Number.isSafeInteger(maximumBytes) || maximumBytes <= 0
                || !Number.isSafeInteger(maximumMegabytes) || maximumMegabytes <= 0) {
                setFeedback('上传限制配置无效，请联系管理员。', 'error');
                return;
            }
            if (file.size <= 0 || file.size > maximumBytes) {
                setFeedback(`${isSvg ? 'SVG ' : '图片'}大小必须在 1 字节到 ${maximumMegabytes}MB 之间。`, 'error');
                return;
            }

            const formData = new FormData();
            formData.append('_token', container.getAttribute('data-token') || '');
            formData.append('category', container.getAttribute('data-category') || '');
            formData.append('image', file);
            uploading = true;
            submitButton.disabled = true;
            fileInput.disabled = true;
            submitButton.textContent = '上传中…';
            submitButton.setAttribute('aria-busy', 'true');
            setFeedback('正在上传...');

            try {
                const response = await fetch('/admin/media/image', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                });
                const payload = await response.json().catch(() => null);
                if (!response.ok || !payload || payload.success !== true || typeof payload.path !== 'string') {
                    throw new Error((payload && payload.message) || '图片上传失败。');
                }

                targetInput.value = payload.path;
                targetInput.dispatchEvent(new Event('input', {bubbles: true}));
                targetInput.dispatchEvent(new Event('change', {bubbles: true}));
                preview.src = payload.path;
                preview.classList.remove('d-none');
                fileInput.value = '';
                setFeedback('图片已上传。', 'success');
            } catch (error) {
                setFeedback(error instanceof Error ? error.message : '图片上传失败。', 'error');
            } finally {
                uploading = false;
                submitButton.disabled = false;
                fileInput.disabled = false;
                submitButton.textContent = idleLabel;
                submitButton.removeAttribute('aria-busy');
            }
        };

        // 选中文件后自动上传。
        fileInput.addEventListener('change', () => {
            const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
            if (!file) {
                return;
            }
            setFeedback(file.name);
            void uploadSelectedFile();
        });

        // 点击“上传”文字按钮打开系统文件选择器。
        submitButton.addEventListener('click', () => {
            if (uploading) {
                return;
            }
            fileInput.click();
        });
    };

    /**
     * 为指定范围内的图片上传控件绑定行为。
     *
     * @param {ParentNode} root 搜索根节点
     * @returns {void}
     */
    const initializeImageUploads = (root = document) => {
        root.querySelectorAll('[data-image-upload]').forEach((container) => {
            if (container instanceof HTMLElement) {
                initializeImageUpload(container);
            }
        });
    };

    /**
     * 初始化表格批量选择与批量删除。
     *
     * 副作用：同步全选半选状态、选中计数、删除按钮可用性，并刷新确认文案中的条数。
     * 行内操作表单与批量表单通过 HTML form 属性关联，避免嵌套 form。
     *
     * @param {ParentNode} root 搜索根节点
     * @returns {void}
     */
    const initializeBatchTables = (root = document) => {
        root.querySelectorAll('[data-batch-table]').forEach((panel) => {
            if (!(panel instanceof HTMLElement) || panel.dataset.batchTableReady === 'true') {
                return;
            }

            const form = panel.querySelector('[data-batch-form]');
            const selectAll = panel.querySelector('[data-batch-select-all]');
            const deleteButton = panel.querySelector('[data-batch-delete]');
            const countElement = panel.querySelector('[data-batch-count]');
            if (!(form instanceof HTMLFormElement)
                || !(selectAll instanceof HTMLInputElement)
                || !(deleteButton instanceof HTMLButtonElement)
                || !(countElement instanceof HTMLElement)) {
                return;
            }

            panel.dataset.batchTableReady = 'true';
            const confirmTemplate = form.dataset.confirmTemplate
                || '确认删除选中的 {count} 条记录？删除后无法恢复。';

            /**
             * @returns {HTMLInputElement[]} 当前页可选行
             */
            const itemInputs = () => Array.from(panel.querySelectorAll('[data-batch-item]'))
                .filter((input) => input instanceof HTMLInputElement);

            /**
             * 根据勾选状态刷新工具栏与行高亮。
             *
             * @returns {void}
             */
            const syncBatchState = () => {
                const items = itemInputs();
                const selected = items.filter((input) => input.checked);
                const selectedCount = selected.length;
                const totalCount = items.length;

                deleteButton.disabled = selectedCount === 0;
                countElement.hidden = selectedCount === 0;
                countElement.textContent = selectedCount > 0 ? `已选 ${selectedCount} 项` : '';
                form.dataset.confirm = confirmTemplate.replace(/\{count\}/g, String(selectedCount));

                selectAll.checked = totalCount > 0 && selectedCount === totalCount;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < totalCount;
                selectAll.disabled = totalCount === 0;

                items.forEach((input) => {
                    const row = input.closest('[data-batch-row]');
                    if (row instanceof HTMLElement) {
                        row.classList.toggle('is-selected', input.checked);
                    }
                });
            };

            selectAll.addEventListener('change', () => {
                const checked = selectAll.checked;
                itemInputs().forEach((input) => {
                    input.checked = checked;
                });
                syncBatchState();
            });

            panel.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLInputElement) || !target.matches('[data-batch-item]')) {
                    return;
                }
                syncBatchState();
            });

            form.addEventListener('submit', (event) => {
                if (itemInputs().some((input) => input.checked)) {
                    return;
                }
                event.preventDefault();
                syncBatchState();
            });

            syncBatchState();
        });
    };

    /**
     * 初始化 APK 上传表单。
     *
     * 页面主内容可能由侧栏异步导航替换；用数据属性避免重复绑定。
     *
     * @param {ParentNode} root 搜索根节点
     * @returns {void}
     */
    const initializeApkUploads = (root = document) => {
        root.querySelectorAll('[data-apk-upload]').forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.apkUploadReady === 'true') {
                return;
            }

            const fileInput = form.querySelector('[data-apk-file]');
            const submitButton = form.querySelector('[data-apk-submit]');
            const feedback = form.querySelector('[data-apk-feedback]');
            const maximumBytes = Number.parseInt(form.dataset.maxBytes || '', 10);
            const maximumMegabytes = Number.parseInt(form.dataset.maxMb || '', 10);
            if (!(fileInput instanceof HTMLInputElement)
                || !(submitButton instanceof HTMLButtonElement)
                || !(feedback instanceof HTMLElement)) {
                return;
            }

            form.dataset.apkUploadReady = 'true';
            form.addEventListener('submit', (event) => {
                const file = fileInput.files?.[0];
                if (!file) {
                    event.preventDefault();
                    event.stopPropagation();
                    feedback.textContent = '请选择 APK 文件。';
                    feedback.classList.add('is-error');
                    return;
                }
                if (!Number.isSafeInteger(maximumBytes) || maximumBytes <= 0
                    || !Number.isSafeInteger(maximumMegabytes) || maximumMegabytes <= 0) {
                    event.preventDefault();
                    event.stopPropagation();
                    feedback.textContent = '上传限制配置无效，请联系管理员。';
                    feedback.classList.add('is-error');
                    return;
                }
                if (file.size <= 0 || file.size > maximumBytes) {
                    event.preventDefault();
                    event.stopPropagation();
                    feedback.textContent = `APK 大小必须在 1 字节到 ${maximumMegabytes}MB 之间。`;
                    feedback.classList.add('is-error');
                    return;
                }

                feedback.textContent = '正在上传...';
                feedback.classList.remove('is-error');
                // 静默上传由 document 级 data-admin-ajax-form 处理；此处只做校验与反馈。
                if (form.matches('[data-admin-ajax-form]')) {
                    return;
                }

                submitButton.disabled = true;
                const icon = submitButton.querySelector('i');
                if (icon instanceof HTMLElement) {
                    icon.className = 'spinner-border spinner-border-sm me-2';
                }
            });
        });
    };

    /** @type {WeakMap<HTMLFormElement, string>} */
    const dirtyFormSnapshots = new WeakMap();

    /**
     * 生成表单当前可编辑字段的稳定快照。
     *
     * 隐藏 CSRF 字段不会参与比较，避免令牌刷新造成误报。
     *
     * @param {HTMLFormElement} form 目标表单
     * @returns {string}
     */
    const dirtyFormSnapshot = (form) => Array.from(form.elements).map((element, index) => {
        if (!(element instanceof HTMLInputElement)
            && !(element instanceof HTMLSelectElement)
            && !(element instanceof HTMLTextAreaElement)) {
            return '';
        }
        if (element.disabled || element.type === 'hidden') {
            return '';
        }

        if (element instanceof HTMLInputElement && (element.type === 'checkbox' || element.type === 'radio')) {
            return `${index}:${element.name}:${element.type}:${element.checked ? '1' : '0'}:${element.value}`;
        }
        if (element instanceof HTMLInputElement && element.type === 'file') {
            const file = element.files?.[0];
            return `${index}:${element.name}:file:${file ? `${file.name}:${file.size}:${file.lastModified}` : ''}`;
        }

        return `${index}:${element.name}:${element.value}`;
    }).join('\u001f');

    /**
     * 将 dirty 状态同步到配置页工作区与吸底保存条。
     *
     * 仅影响带 data-config-dirty-root / data-config-form-actions 的配置页；
     * 其它后台表单保持 dataset.adminDirty 行为不变。
     *
     * @param {HTMLFormElement} form 目标表单
     * @returns {void}
     */
    const syncConfigDirtyUi = (form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const isDirty = form.dataset.adminDirty === 'true';
        const isPrimary = form.matches('[data-config-primary-form]')
            || form.id === 'downloadConfigForm';
        if (!isPrimary) {
            return;
        }

        const workspace = form.closest('[data-config-dirty-root]')
            || document.querySelector('[data-config-dirty-root]');
        if (workspace instanceof HTMLElement) {
            workspace.classList.toggle('is-dirty', isDirty);
        }

        const actionBars = [];
        // 站点配置：保存条嵌在主表单内。
        const nestedActions = form.querySelector('[data-config-form-actions]');
        if (nestedActions instanceof HTMLElement && form.contains(nestedActions)) {
            actionBars.push(nestedActions);
        }
        // 下载配置：保存条在主表单外，用 form id 关联。
        if (form.id) {
            document.querySelectorAll(`[data-config-actions-for="${form.id}"]`).forEach((node) => {
                if (node instanceof HTMLElement) {
                    actionBars.push(node);
                }
            });
        }
        if (actionBars.length === 0 && workspace instanceof HTMLElement) {
            workspace.querySelectorAll('[data-config-form-actions]').forEach((node) => {
                if (node instanceof HTMLElement) {
                    actionBars.push(node);
                }
            });
        }

        actionBars.forEach((bar) => {
            bar.classList.toggle('is-dirty', isDirty);
            const badge = bar.querySelector('[data-config-dirty-badge]');
            if (badge instanceof HTMLElement) {
                badge.hidden = !isDirty;
            }
            const title = bar.querySelector('[data-config-save-title]');
            if (title instanceof HTMLElement) {
                if (!title.dataset.defaultText) {
                    title.dataset.defaultText = title.textContent || '';
                }
                title.textContent = isDirty
                    ? '有未保存的更改'
                    : title.dataset.defaultText;
            }
        });
    };

    /**
     * 标记并追踪会阻止侧栏离开的表单。
     *
     * @param {ParentNode} root 搜索根节点
     * @returns {void}
     */
    const initializeDirtyForms = (root = document) => {
        root.querySelectorAll('[data-admin-dirty-form]').forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.adminDirtyReady === 'true') {
                return;
            }

            form.dataset.adminDirtyReady = 'true';
            form.dataset.adminDirty = 'false';
            dirtyFormSnapshots.set(form, dirtyFormSnapshot(form));
            const refreshDirtyState = () => {
                form.dataset.adminDirty = String(dirtyFormSnapshots.get(form) !== dirtyFormSnapshot(form));
                syncConfigDirtyUi(form);
            };

            form.addEventListener('input', refreshDirtyState);
            form.addEventListener('change', refreshDirtyState);
            form.addEventListener('reset', () => {
                window.setTimeout(() => {
                    dirtyFormSnapshots.set(form, dirtyFormSnapshot(form));
                    form.dataset.adminDirty = 'false';
                    syncConfigDirtyUi(form);
                }, 0);
            });

            // 通过 form 属性关联、但不在表单 DOM 内的控件，事件不会冒泡到 form，需单独监听。
            Array.from(form.elements).forEach((element) => {
                if (!(element instanceof HTMLElement) || form.contains(element) || element.form !== form) {
                    return;
                }
                element.addEventListener('input', refreshDirtyState);
                element.addEventListener('change', refreshDirtyState);
            });

            syncConfigDirtyUi(form);
        });
    };

    /**
     * 读取当前路径下上次激活的配置选项卡 ID。
     *
     * @param {string} storageKey 会话存储键
     * @returns {string} 选项卡按钮选择器目标，空字符串表示无记录
     */
    const readStoredConfigTab = (storageKey) => {
        try {
            const value = sessionStorage.getItem(storageKey);
            return typeof value === 'string' ? value : '';
        } catch (error) {
            return '';
        }
    };

    /**
     * 持久化当前配置选项卡，便于保存后回跳与同页刷新恢复。
     *
     * @param {string} storageKey 会话存储键
     * @param {string} tabId 选项卡面板 ID
     * @returns {void}
     */
    const storeConfigTab = (storageKey, tabId) => {
        if (!tabId) {
            return;
        }
        try {
            sessionStorage.setItem(storageKey, tabId);
        } catch (error) {
            // 隐私模式禁用存储时仅保留当前页状态。
        }
    };

    /**
     * 下载配置单页：按来源下拉显隐本地或网盘下载参数区。
     *
     * @param {ParentNode} root 搜索根节点
     * @returns {void}
     */
    const initializeDownloadConfigPage = (root = document) => {
        root.querySelectorAll('[data-download-config]').forEach((workspace) => {
            if (!(workspace instanceof HTMLElement) || workspace.dataset.downloadConfigReady === 'true') {
                return;
            }

            const modeSelect = workspace.querySelector('[data-download-mode-select]');
            const modeHint = workspace.querySelector('[data-download-mode-hint]');
            const panels = Array.from(workspace.querySelectorAll('[data-download-panel]'))
                .filter((panel) => panel instanceof HTMLElement);
            if (!(modeSelect instanceof HTMLSelectElement) || panels.length === 0) {
                return;
            }

            workspace.dataset.downloadConfigReady = 'true';

            const modeHints = {
                local: '由本站直接提供文件，适合自有带宽与完整控制。',
                other: '展示访问密码后直接打开原分享链接，不解析第三方网盘。',
            };

            /**
             * 同步参数面板与说明文案。
             *
             * @returns {void}
             */
            const applyMode = () => {
                const mode = modeSelect.value === 'other' ? 'other' : 'local';
                panels.forEach((panel) => {
                    const active = panel.dataset.downloadPanel === mode;
                    panel.hidden = !active;
                });
                if (modeHint instanceof HTMLElement) {
                    modeHint.textContent = modeHints[mode] || modeHints.local;
                }
            };

            modeSelect.addEventListener('change', applyMode);
            applyMode();
        });
    };

    /**
     * 初始化配置页功能区选项卡。
     *
     * 副作用：恢复上次选中的选项卡，并在切换时写入 sessionStorage。
     *
     * @param {ParentNode} root 搜索根节点
     * @returns {void}
     */
    const initializeConfigTabs = (root = document) => {
        root.querySelectorAll('[data-config-tabs]').forEach((container) => {
            if (!(container instanceof HTMLElement) || container.dataset.configTabsReady === 'true') {
                return;
            }

            const tabButtons = Array.from(container.querySelectorAll('[data-bs-toggle="tab"]'))
                .filter((button) => button instanceof HTMLElement);
            if (tabButtons.length === 0) {
                return;
            }

            container.dataset.configTabsReady = 'true';
            const storageKey = `nova.admin.configTab:${window.location.pathname}`;
            const storedTarget = readStoredConfigTab(storageKey);
            const preferredButton = storedTarget
                ? tabButtons.find((button) => button.getAttribute('data-bs-target') === storedTarget)
                : null;
            const tabsList = container.querySelector('.config-tabs');
            const tabsShell = container.querySelector('[data-config-tabs-shell]')
                || tabsList?.parentElement
                || null;

            /**
             * 根据横向滚动位置更新选项卡边缘渐隐提示。
             *
             * @returns {void}
             */
            const updateTabsEdgeFade = () => {
                if (!(tabsShell instanceof HTMLElement) || !(tabsList instanceof HTMLElement)) {
                    return;
                }
                const maxScroll = tabsList.scrollWidth - tabsList.clientWidth;
                if (maxScroll <= 2) {
                    tabsShell.classList.remove('is-scrollable-start', 'is-scrollable-end');
                    return;
                }
                tabsShell.classList.toggle('is-scrollable-start', tabsList.scrollLeft > 4);
                tabsShell.classList.toggle(
                    'is-scrollable-end',
                    tabsList.scrollLeft < maxScroll - 4
                );
            };

            /**
             * 将激活选项卡滚入可视区域，避免移动端被边缘裁切。
             *
             * @param {HTMLElement} button 选项卡按钮
             * @returns {void}
             */
            const scrollTabIntoView = (button) => {
                if (!(button instanceof HTMLElement) || !(tabsList instanceof HTMLElement)) {
                    return;
                }
                if (typeof button.scrollIntoView === 'function') {
                    button.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest',
                        inline: 'center',
                    });
                }
                window.requestAnimationFrame(updateTabsEdgeFade);
            };

            /**
             * 激活指定选项卡按钮，并同步会话存储。
             *
             * @param {HTMLElement} button 选项卡按钮
             * @returns {void}
             */
            const activateTabButton = (button) => {
                if (!(button instanceof HTMLElement)) {
                    return;
                }
                bootstrap.Tab.getOrCreateInstance(button).show();
                storeConfigTab(storageKey, button.getAttribute('data-bs-target') || '');
                scrollTabIntoView(button);
            };

            if (preferredButton instanceof HTMLElement && !preferredButton.classList.contains('active')) {
                activateTabButton(preferredButton);
            }

            tabButtons.forEach((button) => {
                button.addEventListener('shown.bs.tab', () => {
                    const target = button.getAttribute('data-bs-target') || '';
                    storeConfigTab(storageKey, target);
                    scrollTabIntoView(button);
                });
            });

            // invalid 不冒泡：用捕获阶段拦截，切到含未通过校验字段的选项卡。
            container.addEventListener('invalid', (event) => {
                const field = event.target;
                if (!(field instanceof HTMLElement)) {
                    return;
                }
                const pane = field.closest('.tab-pane');
                if (!(pane instanceof HTMLElement) || pane.classList.contains('active') || !pane.id) {
                    return;
                }
                const button = tabButtons.find(
                    (item) => item.getAttribute('data-bs-target') === `#${pane.id}`
                );
                if (button instanceof HTMLElement) {
                    activateTabButton(button);
                }
            }, true);

            if (tabsList instanceof HTMLElement) {
                tabsList.addEventListener('scroll', updateTabsEdgeFade, {passive: true});
                window.addEventListener('resize', updateTabsEdgeFade);
                updateTabsEdgeFade();
            }

            const activeButton = container.querySelector('.config-tabs .nav-link.active');
            if (activeButton instanceof HTMLElement) {
                storeConfigTab(storageKey, activeButton.getAttribute('data-bs-target') || '');
                scrollTabIntoView(activeButton);
            }
        });
    };

    let closeContentModal = () => {};
    let closeLogDetailModal = () => {};

    /** 默认主题色，与后端 ThemeColorService::DEFAULT_COLOR 保持一致。 */
    const DEFAULT_THEME_COLOR = '#4a9fd8';

    /**
     * 将用户输入规范为 #rrggbb；无效时返回 null。
     *
     * @param {string} value 原始颜色
     * @returns {string|null}
     */
    const normalizeThemeColor = (value) => {
        const raw = String(value || '').trim().toLowerCase();
        if (!raw) {
            return null;
        }
        const withHash = raw.charAt(0) === '#' ? raw : `#${raw}`;
        if (/^#[0-9a-f]{6}$/.test(withHash)) {
            return withHash;
        }
        if (/^#[0-9a-f]{3}$/.test(withHash)) {
            return `#${withHash[1]}${withHash[1]}${withHash[2]}${withHash[2]}${withHash[3]}${withHash[3]}`;
        }
        return null;
    };

    /**
     * 由主色推导预览用的浅底色与文字色（与 ThemeColorService::palette 对齐）。
     *
     * @param {string} hex #rrggbb
     * @returns {{accent:string,soft:string,softStrong:string,border:string,ink:string,onAccent:string,rgb:string}}
     */
    const deriveThemePreviewPalette = (hex) => {
        const r = Number.parseInt(hex.slice(1, 3), 16);
        const g = Number.parseInt(hex.slice(3, 5), 16);
        const b = Number.parseInt(hex.slice(5, 7), 16);
        const mix = (whiteRatio) => {
            const channel = (value) => Math.round(255 * whiteRatio + value * (1 - whiteRatio));
            return `#${[channel(r), channel(g), channel(b)]
                .map((value) => value.toString(16).padStart(2, '0'))
                .join('')}`;
        };
        const darken = (amount) => {
            const channel = (value) => Math.max(0, Math.round(value * (1 - amount)));
            return `#${[channel(r), channel(g), channel(b)]
                .map((value) => value.toString(16).padStart(2, '0'))
                .join('')}`;
        };
        // 与服务端 relativeLuminance 一致，决定按钮前景深浅。
        const channelLuma = (value) => {
            const srgb = value / 255;
            return srgb <= 0.03928 ? srgb / 12.92 : ((srgb + 0.055) / 1.055) ** 2.4;
        };
        const luminance = 0.2126 * channelLuma(r) + 0.7152 * channelLuma(g) + 0.0722 * channelLuma(b);

        return {
            accent: hex,
            soft: mix(0.88),
            softStrong: mix(0.78),
            border: mix(0.68),
            ink: darken(0.55),
            onAccent: luminance > 0.55 ? '#0f172a' : '#ffffff',
            rgb: `${r}, ${g}, ${b}`,
        };
    };

    /**
     * 初始化站点配置中的主题色选择器。
     *
     * 副作用：同步 hidden 字段、预设选中态与实时预览样式。
     *
     * @param {ParentNode} root 页面内容根节点
     * @returns {void}
     */
    const initializeThemeColorPickers = (root = document) => {
        root.querySelectorAll('[data-theme-color-picker]').forEach((panel) => {
            if (!(panel instanceof HTMLElement) || panel.dataset.themeColorReady === 'true') {
                return;
            }

            const hiddenInput = panel.querySelector('[data-theme-color-value]');
            const pickerInput = panel.querySelector('[data-theme-color-picker-input]');
            const hexInput = panel.querySelector('[data-theme-color-hex]');
            const resetButton = panel.querySelector('[data-theme-color-reset]');
            const presetButtons = Array.from(panel.querySelectorAll('[data-theme-preset]'))
                .filter((button) => button instanceof HTMLButtonElement);
            if (!(hiddenInput instanceof HTMLInputElement)
                || !(pickerInput instanceof HTMLInputElement)
                || !(hexInput instanceof HTMLInputElement)) {
                return;
            }

            panel.dataset.themeColorReady = 'true';

            /**
             * 将选中色写回表单控件与预览区域。
             *
             * @param {string} color 规范化颜色
             * @returns {void}
             */
            const applyColor = (color) => {
                const normalized = normalizeThemeColor(color) || DEFAULT_THEME_COLOR;
                hiddenInput.value = normalized;
                pickerInput.value = normalized;
                hexInput.value = normalized;

                presetButtons.forEach((button) => {
                    const preset = normalizeThemeColor(button.dataset.themePreset || '');
                    const selected = preset === normalized;
                    button.classList.toggle('is-selected', selected);
                    button.setAttribute('aria-selected', selected ? 'true' : 'false');
                });

                const palette = deriveThemePreviewPalette(normalized);
                const previewDot = panel.querySelector('[data-theme-preview-accent]');
                const previewHex = panel.querySelector('[data-theme-preview-hex]');
                const previewBtn = panel.querySelector('[data-theme-preview-btn]');
                const previewChip = panel.querySelector('[data-theme-preview-chip]');
                const previewSoft = panel.querySelector('[data-theme-preview-soft]');

                if (previewDot instanceof HTMLElement) {
                    previewDot.style.background = palette.accent;
                    previewDot.style.boxShadow = `0 0 0 3px ${palette.soft}`;
                }
                if (previewHex instanceof HTMLElement) {
                    previewHex.textContent = palette.accent;
                }
                if (previewBtn instanceof HTMLElement) {
                    previewBtn.style.background = palette.accent;
                    previewBtn.style.color = palette.onAccent;
                    previewBtn.style.boxShadow = `0 10px 28px rgba(${palette.rgb}, 0.28)`;
                }
                if (previewChip instanceof HTMLElement) {
                    previewChip.style.color = palette.ink;
                    previewChip.style.background = palette.soft;
                }
                if (previewSoft instanceof HTMLElement) {
                    previewSoft.style.color = palette.ink;
                    previewSoft.style.background = palette.softStrong;
                    previewSoft.style.borderColor = palette.border;
                }

                // 触发 dirty-form 监听，避免仅改色时保存按钮无提示。
                hiddenInput.dispatchEvent(new Event('input', {bubbles: true}));
                hiddenInput.dispatchEvent(new Event('change', {bubbles: true}));
            };

            presetButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    applyColor(button.dataset.themePreset || DEFAULT_THEME_COLOR);
                });
            });

            pickerInput.addEventListener('input', () => {
                applyColor(pickerInput.value);
            });

            hexInput.addEventListener('change', () => {
                const normalized = normalizeThemeColor(hexInput.value);
                if (!normalized) {
                    hexInput.value = hiddenInput.value || DEFAULT_THEME_COLOR;
                    return;
                }
                applyColor(normalized);
            });

            hexInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    hexInput.dispatchEvent(new Event('change', {bubbles: true}));
                }
            });

            if (resetButton instanceof HTMLButtonElement) {
                resetButton.addEventListener('click', () => {
                    applyColor(DEFAULT_THEME_COLOR);
                });
            }

            applyColor(hiddenInput.value || DEFAULT_THEME_COLOR);
        });
    };

    /**
     * 将表单标记为“已与当前值同步”，避免侧栏离开拦截误报。
     *
     * @param {HTMLFormElement} form 目标表单
     * @returns {void}
     */
    const markFormClean = (form) => {
        dirtyFormSnapshots.set(form, dirtyFormSnapshot(form));
        form.dataset.adminDirty = 'false';
        syncConfigDirtyUi(form);
    };


    /**
     * 同步异步保存后返回的 CSRF 令牌到页面内相关隐藏域。
     *
     * @param {string} token 新令牌
     * @returns {void}
     */
    const syncCsrfTokens = (token) => {
        if (typeof token !== 'string' || token === '') {
            return;
        }
        document.querySelectorAll('input[name="_token"]').forEach((input) => {
            if (input instanceof HTMLInputElement) {
                input.value = token;
            }
        });
        document.querySelectorAll('[data-image-upload][data-token]').forEach((node) => {
            if (node instanceof HTMLElement) {
                node.dataset.token = token;
            }
        });
    };

    /**
     * 应用服务端返回的主题 CSS 覆盖，无需整页刷新。
     *
     * @param {string} cssText 覆盖样式文本
     * @returns {void}
     */
    const applyThemeCss = (cssText) => {
        if (typeof cssText !== 'string' || cssText === '') {
            return;
        }
        let style = document.getElementById('nova-theme-vars');
        if (!(style instanceof HTMLStyleElement)) {
            style = document.createElement('style');
            style.id = 'nova-theme-vars';
            document.head.appendChild(style);
        }
        style.textContent = cssText;
    };

    /**
     * 同步当前页面的浏览器图标，无需整页刷新。
     *
     * @param {string} faviconUrl 服务端确认的网站图标地址
     * @returns {void}
     */
    const applySiteFavicon = (faviconUrl) => {
        if (typeof faviconUrl !== 'string' || faviconUrl === '') {
            return;
        }

        let faviconLink = document.querySelector('link[data-site-favicon]');
        if (!(faviconLink instanceof HTMLLinkElement)) {
            faviconLink = document.createElement('link');
            faviconLink.rel = 'icon';
            faviconLink.dataset.siteFavicon = 'true';
            document.head.appendChild(faviconLink);
        }
        faviconLink.setAttribute('href', faviconUrl);
    };

    /**
     * 同步侧栏与移动端抽屉中的软件名称、应用图标展示。
     *
     * @param {string} siteName 软件名称
     * @param {string} siteNameInitial 无图标时的首字回退
     * @param {string} [appIcon] 应用图标地址，空字符串表示回退首字
     * @returns {void}
     */
    const applySiteBrand = (siteName, siteNameInitial, appIcon = '') => {
        if (typeof siteName === 'string' && siteName !== '') {
            document.querySelectorAll('.admin-brand-name').forEach((node) => {
                if (node instanceof HTMLElement) {
                    node.textContent = siteName;
                }
            });
            document.querySelectorAll('.admin-brand').forEach((node) => {
                if (node instanceof HTMLElement) {
                    node.setAttribute('title', siteName);
                }
            });
            const titleParts = document.title.split(' · ');
            if (titleParts.length >= 2) {
                titleParts[titleParts.length - 1] = siteName;
                document.title = titleParts.join(' · ');
            }
        }

        const iconUrl = typeof appIcon === 'string' ? appIcon.trim() : '';
        const initialText = typeof siteNameInitial === 'string' && siteNameInitial !== ''
            ? siteNameInitial
            : '管';

        document.querySelectorAll('.admin-brand-mark').forEach((node) => {
            if (!(node instanceof HTMLElement)) {
                return;
            }

            if (iconUrl !== '') {
                node.classList.remove('is-fallback');
                let image = node.querySelector('img');
                if (!(image instanceof HTMLImageElement)) {
                    node.replaceChildren();
                    image = document.createElement('img');
                    image.alt = '';
                    node.appendChild(image);
                }
                image.src = iconUrl;
                return;
            }

            node.classList.add('is-fallback');
            let initial = node.querySelector('.admin-brand-initial');
            if (!(initial instanceof HTMLElement)) {
                node.replaceChildren();
                initial = document.createElement('span');
                initial.className = 'admin-brand-initial';
                node.appendChild(initial);
            }
            initial.textContent = initialText;
        });
    };

    /**
     * 清除配置面板内可能残留的旧版内联错误提示。
     * 当前错误已统一走 Toast，此方法仅作兼容清理。
     *
     * @param {HTMLFormElement} form 配置表单
     * @returns {void}
     */
    const clearConfigFormError = (form) => {
        const panel = form.closest('.config-panel') || form.closest('.form-panel') || form.parentElement;
        if (!(panel instanceof HTMLElement)) {
            return;
        }
        panel.querySelectorAll('.alert.alert-danger[role="alert"]').forEach((alertNode) => {
            alertNode.remove();
        });
    };

    /**
     * 下载配置保存成功后的表单收尾：同步访问密码明文回显。
     *
     * @param {HTMLFormElement} form 下载配置表单
     * @param {{other_pwd?: string}} payload 服务端回包
     * @returns {void}
     */
    const finalizeDownloadConfigForm = (form, payload) => {
        const passwordInput = document.getElementById('other_pwd');
        if (passwordInput instanceof HTMLInputElement) {
            passwordInput.value = typeof payload.other_pwd === 'string' ? payload.other_pwd : '';
        }
    };

    /**
     * 软刷新实现由异步导航模块注入；未就绪时回退整页刷新。
     *
     * @type {((url: string, historyMode?: 'push'|'replace') => Promise<boolean>)|null}
     */
    let softReloadAdminPageImpl = null;

    /**
     * 静默替换后台主内容区，保持壳层（侧栏/顶栏框架）不闪断。
     *
     * @param {string} [rawUrl] 目标地址，默认当前页
     * @param {'push'|'replace'} [historyMode='replace'] 历史策略
     * @returns {Promise<boolean>}
     */
    const softReloadAdminPage = async (rawUrl = window.location.href, historyMode = 'replace') => {
        if (typeof softReloadAdminPageImpl === 'function') {
            const completed = await softReloadAdminPageImpl(rawUrl, historyMode);
            if (completed) {
                return true;
            }
        }
        window.location.assign(rawUrl);
        return false;
    };

    /**
     * 收集表单相关提交按钮（含 form 属性外挂按钮）。
     *
     * @param {HTMLFormElement} form 目标表单
     * @param {Element|null} [submitter] 事件提交者
     * @returns {Array<HTMLButtonElement|HTMLInputElement>}
     */
    const collectFormSubmitters = (form, submitter = null) => {
        /** @type {Array<HTMLButtonElement|HTMLInputElement>} */
        const submitters = [];
        if (submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement) {
            submitters.push(submitter);
        }
        form.querySelectorAll('[type="submit"]').forEach((button) => {
            if ((button instanceof HTMLButtonElement || button instanceof HTMLInputElement)
                && !submitters.includes(button)) {
                submitters.push(button);
            }
        });
        if (form.id !== '') {
            const escapedId = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
                ? CSS.escape(form.id)
                : form.id.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
            document.querySelectorAll(
                `button[type="submit"][form="${escapedId}"], input[type="submit"][form="${escapedId}"]`
            ).forEach((button) => {
                if ((button instanceof HTMLButtonElement || button instanceof HTMLInputElement)
                    && !submitters.includes(button)) {
                    submitters.push(button);
                }
            });
        }
        return submitters;
    };

    /**
     * 静默提交后台变更表单（配置保存、表格增删改、上传等）。
     *
     * @param {HTMLFormElement} form 目标表单
     * @param {{submitter?: Element|null}} [options] 可选提交者
     * @returns {Promise<boolean>} 是否成功
     */
    const submitAdminAjaxForm = async (form, options = {}) => {
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-ajax-form]')) {
            return false;
        }
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return false;
        }

        const submitters = collectFormSubmitters(form, options.submitter || null);
        const shouldReload = form.hasAttribute('data-admin-ajax-reload');
        submitters.forEach((button) => {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        });

        try {
            const response = await fetch(form.action || window.location.href, {
                method: (form.method || 'POST').toUpperCase(),
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json().catch(() => null);
            if (!payload || typeof payload.success !== 'boolean') {
                throw new Error('服务端未返回有效结果，请刷新后重试。');
            }
            if (!payload.success) {
                showAdminToast(
                    typeof payload.message === 'string' && payload.message !== ''
                        ? payload.message
                        : '操作失败，请检查输入后重试。',
                    'danger'
                );
                return false;
            }

            clearConfigFormError(form);
            if (typeof payload.csrf_token === 'string') {
                syncCsrfTokens(payload.csrf_token);
            }
            if (typeof payload.theme_css === 'string') {
                applyThemeCss(payload.theme_css);
            }
            if (typeof payload.site_name === 'string') {
                applySiteBrand(
                    payload.site_name,
                    payload.site_name_initial || '',
                    typeof payload.app_icon === 'string' ? payload.app_icon : ''
                );
            }
            if (typeof payload.favicon_url === 'string') {
                applySiteFavicon(payload.favicon_url);
            }
            if (form.id === 'downloadConfigForm') {
                finalizeDownloadConfigForm(form, payload);
            }
            if (typeof payload.local_apk_path === 'string') {
                const pathInput = document.getElementById('local_apk_path');
                if (pathInput instanceof HTMLInputElement) {
                    pathInput.value = payload.local_apk_path !== ''
                        ? payload.local_apk_path
                        : '尚未上传';
                }
            }
            markFormClean(form);
            showAdminToast(
                typeof payload.message === 'string' && payload.message !== ''
                    ? payload.message
                    : '操作已完成。',
                'success'
            );

            if (shouldReload) {
                let redirectUrl = window.location.href;
                if (typeof payload.redirect === 'string' && payload.redirect !== '') {
                    const current = new URL(window.location.href);
                    const target = new URL(payload.redirect, window.location.origin);
                    // 仍在同一列表路径时保留分页/筛选；跨路径（如编辑页回列表）才跳转。
                    if (target.pathname !== current.pathname) {
                        redirectUrl = target.href;
                    }
                }
                await softReloadAdminPage(redirectUrl, 'replace');
            }
            return true;
        } catch (error) {
            showAdminToast(
                error instanceof Error ? error.message : '操作失败，请检查网络后重试。',
                'danger'
            );
            return false;
        } finally {
            submitters.forEach((button) => {
                if (button.isConnected) {
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                }
            });
        }
    };

    /**
     * 初始化内容列表状态开关。
     *
     * 副作用：开关变化后立即提交目标状态；请求期间禁用控件，失败时恢复原状态。
     *
     * @param {ParentNode} root 搜索根节点
     * @returns {void}
     */
    const initializeContentStatusSwitches = (root = document) => {
        root.querySelectorAll('[data-content-status-switch]').forEach((switchInput) => {
            if (!(switchInput instanceof HTMLInputElement)
                || switchInput.dataset.contentStatusSwitchReady === 'true') {
                return;
            }

            const form = switchInput.closest('[data-content-status-form]');
            const valueInput = form?.querySelector('[data-content-status-value]');
            if (!(form instanceof HTMLFormElement) || !(valueInput instanceof HTMLInputElement)) {
                return;
            }

            switchInput.dataset.contentStatusSwitchReady = 'true';
            switchInput.addEventListener('change', async () => {
                const currentStatus = switchInput.dataset.currentStatus === '1' ? '1' : '0';
                const targetStatus = switchInput.checked ? '1' : '0';
                if (targetStatus === currentStatus) {
                    return;
                }

                valueInput.value = targetStatus;
                switchInput.disabled = true;
                switchInput.setAttribute('aria-busy', 'true');

                try {
                    const completed = await submitAdminAjaxForm(form);
                    if (completed) {
                        switchInput.dataset.currentStatus = targetStatus;
                        return;
                    }

                    if (switchInput.isConnected) {
                        switchInput.checked = currentStatus === '1';
                        valueInput.value = currentStatus;
                    }
                } finally {
                    if (switchInput.isConnected) {
                        switchInput.disabled = false;
                        switchInput.removeAttribute('aria-busy');
                    }
                }
            });
        });
    };

    // 后台变更表单静默提交：document 级委托，兼容侧栏异步换页后的新节点。
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-ajax-form]')) {
            return;
        }
        // 需要确认的表单先走确认框，确认后再调用 submitAdminAjaxForm。
        if (form.dataset.confirm) {
            return;
        }
        event.preventDefault();
        void submitAdminAjaxForm(form, {
            submitter: event instanceof SubmitEvent ? event.submitter : null,
        });
    });

    /**
     * 初始化可被侧栏异步导航替换的页面内容。
     *
     * @param {ParentNode} root 新页面内容根节点
     * @returns {void}
     */
    const initializePageContent = (root = document) => {
        initializeImageUploads(root);
        initializeBatchTables(root);
        initializeContentStatusSwitches(root);
        initializeApkUploads(root);
        initializeDirtyForms(root);
        initializeConfigTabs(root);
        initializeDownloadConfigPage(root);
        initializeThemeColorPickers(root);
        if (window.NovaAdminDashboard && typeof window.NovaAdminDashboard.mount === 'function') {
            const dashboardMount = window.NovaAdminDashboard.mount(root);
            if (dashboardMount && typeof dashboardMount.catch === 'function') {
                void dashboardMount.catch(() => {
                    showAdminToast('趋势图暂时无法加载。', 'warning');
                });
            }
        }
    };

    initializePageContent(document);

    const contentModalElement = document.getElementById('contentFormModal');
    const contentModalTitle = document.getElementById('contentFormModalTitle');
    const contentModalBody = contentModalElement?.querySelector('[data-content-modal-body]');
    if (contentModalElement instanceof HTMLElement
        && contentModalTitle instanceof HTMLElement
        && contentModalBody instanceof HTMLElement) {
        const contentModal = bootstrap.Modal.getOrCreateInstance(contentModalElement);
        let contentFormRequestController = null;

        /**
         * 在主内容替换前关闭内容编辑弹窗，避免旧表单残留在新页面上。
         *
         * @returns {void}
         */
        closeContentModal = () => {
            contentFormRequestController?.abort();
            contentFormRequestController = null;
            contentModal.hide();
        };

        /**
         * 构造带弹窗标记的同源 URL，后端据此返回片段或 JSON。
         *
         * @param {string} rawUrl 原始表单地址
         * @returns {string} 弹窗请求地址
         */
        const contentModalUrl = (rawUrl) => {
            const url = new URL(rawUrl, window.location.origin);
            url.searchParams.set('modal', '1');
            return url.toString();
        };

        /**
         * 显示加载状态，避免旧表单在切换记录时继续可操作。
         *
         * @returns {void}
         */
        const showContentModalLoading = () => {
            const loading = document.createElement('div');
            loading.className = 'content-modal-loading';
            loading.textContent = '正在加载表单...';
            contentModalBody.replaceChildren(loading);
        };

        /**
         * 内容弹窗错误统一走 Toast；加载失败时在弹窗内保留可恢复占位。
         *
         * @param {string} message 错误信息
         * @param {boolean} [replaceBody=false] 是否用占位替换弹窗内容
         * @returns {void}
         */
        const showContentModalError = (message, replaceBody = false) => {
            const text = typeof message === 'string' ? message.trim() : '';
            const safeMessage = text || '操作失败，请稍后重试。';
            showAdminToast(safeMessage, 'danger');

            if (!replaceBody) {
                return;
            }

            const placeholder = document.createElement('div');
            placeholder.className = 'content-modal-loading';
            placeholder.setAttribute('data-content-modal-error', '');
            placeholder.textContent = '表单暂时无法加载，请关闭后重试。';
            contentModalBody.replaceChildren(placeholder);
        };

        /**
         * 注入后端表单片段并重新绑定动态上传控件。
         *
         * @param {string} html 服务端渲染的表单 HTML
         * @returns {void}
         * @throws {Error} 响应不含预期表单片段时抛出异常
         */
        const renderContentForm = (html) => {
            const parsedDocument = new DOMParser().parseFromString(html, 'text/html');
            const fragment = parsedDocument.querySelector('[data-content-form-fragment]');
            if (!fragment) {
                throw new Error('服务端未返回可用表单。');
            }

            contentModalBody.innerHTML = fragment.outerHTML;
            contentModalTitle.textContent = fragment.getAttribute('data-title') || '编辑内容';
            initializeImageUploads(contentModalBody);
            initializeDirtyForms(contentModalBody);
            const firstInput = contentModalBody.querySelector('input:not([type="hidden"]), select, textarea');
            if (firstInput instanceof HTMLElement) {
                window.setTimeout(() => firstInput.focus(), 0);
            }
        };

        /**
         * 异步加载内容表单。失败时保留在弹窗中并给出可恢复提示。
         *
         * @param {string} rawUrl 原始创建或编辑地址
         * @returns {Promise<void>}
         */
        const loadContentForm = async (rawUrl) => {
            contentFormRequestController?.abort();
            const requestController = new AbortController();
            contentFormRequestController = requestController;
            showContentModalLoading();
            contentModal.show();
            try {
                const response = await fetch(contentModalUrl(rawUrl), {
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    signal: requestController.signal,
                });
                if (!response.ok) {
                    throw new Error('表单暂时无法加载，请刷新后重试。');
                }

                renderContentForm(await response.text());
            } catch (error) {
                if (error instanceof Error && error.name === 'AbortError') {
                    return;
                }
                showContentModalError(
                    error instanceof Error ? error.message : '表单暂时无法加载，请刷新后重试。',
                    true
                );
            } finally {
                if (contentFormRequestController === requestController) {
                    contentFormRequestController = null;
                }
            }
        };

        document.addEventListener('click', (event) => {
            const trigger = event.target instanceof Element
                ? event.target.closest('[data-content-modal-url]')
                : null;
            if (!(trigger instanceof HTMLAnchorElement)) {
                return;
            }
            if (event.defaultPrevented
                || (event instanceof MouseEvent
                    && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey))) {
                return;
            }

            event.preventDefault();
            void loadContentForm(trigger.href);
        });

        document.addEventListener('submit', async (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)
                || !form.matches('[data-content-form]')
                || !form.closest('#contentFormModal')) {
                return;
            }

            event.preventDefault();
            const submitButton = form.querySelector('[type="submit"]');
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = true;
                submitButton.setAttribute('aria-busy', 'true');
            }

            try {
                const response = await fetch(contentModalUrl(form.action), {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json().catch(() => null);
                if (!payload || typeof payload.success !== 'boolean') {
                    throw new Error('服务端未返回有效结果，请刷新后重试。');
                }
                if (payload.success) {
                    form.dataset.adminDirty = 'false';
                    contentModal.hide();
                    showAdminToast(
                        typeof payload.message === 'string' && payload.message !== ''
                            ? payload.message
                            : '内容已保存。',
                        'success'
                    );
                    if (typeof payload.csrf_token === 'string') {
                        syncCsrfTokens(payload.csrf_token);
                    }
                    const redirectUrl = typeof payload.redirect === 'string' && payload.redirect !== ''
                        ? payload.redirect
                        : window.location.href;
                    await softReloadAdminPage(redirectUrl, 'replace');
                    return;
                }

                // 校验失败时优先回填表单，错误文案统一 Toast 展示。
                showAdminToast(
                    typeof payload.message === 'string' && payload.message !== ''
                        ? payload.message
                        : '保存失败，请检查输入后重试。',
                    'danger'
                );
                if (typeof payload.html === 'string') {
                    renderContentForm(payload.html);
                }
            } catch (error) {
                showContentModalError(
                    error instanceof Error ? error.message : '保存失败，请检查网络后重试。'
                );
            } finally {
                if (submitButton instanceof HTMLButtonElement && submitButton.isConnected) {
                    submitButton.disabled = false;
                    submitButton.removeAttribute('aria-busy');
                }
            }
        });

        contentModalElement.addEventListener('hidden.bs.modal', () => {
            contentFormRequestController?.abort();
            contentFormRequestController = null;
            contentModalTitle.textContent = '编辑内容';
            showContentModalLoading();
        });
    }

    const logDetailModalElement = document.getElementById('logDetailModal');
    const logDetailModalTitle = document.getElementById('logDetailModalTitle');
    const logDetailModalBody = logDetailModalElement?.querySelector('[data-log-detail-body]');
    if (logDetailModalElement instanceof HTMLElement
        && logDetailModalTitle instanceof HTMLElement
        && logDetailModalBody instanceof HTMLElement) {
        const logDetailModal = bootstrap.Modal.getOrCreateInstance(logDetailModalElement);
        const LOG_DETAIL_TYPES = new Set(['visit', 'download']);
        const LOG_DETAIL_TITLES = {
            visit: '访问日志详情',
            download: '下载日志详情',
        };
        const LOG_DETAIL_FIELDS = {
            visit: [
                {key: 'id', label: '记录 ID', mono: true},
                {key: 'created_at', label: '记录时间', mono: true},
                {key: 'ip', label: 'IP 地址', mono: true},
                {key: 'country', label: '国家'},
                {key: 'region', label: '地区'},
                {key: 'city', label: '城市'},
                {key: 'isp', label: '运营商'},
                {key: 'region_raw', label: '原始归属地', wide: true},
                {key: 'device_type', label: '设备类型'},
                {key: 'method', label: '请求方法', mono: true},
                {key: 'path', label: '请求路径', mono: true, wide: true},
                {key: 'referer', label: '来源地址', wide: true},
                {key: 'user_agent', label: 'User-Agent', mono: true, wide: true},
                {key: 'session_id', label: '会话标识', mono: true, wide: true},
            ],
            download: [
                {key: 'id', label: '记录 ID', mono: true},
                {key: 'created_at', label: '记录时间', mono: true},
                {key: 'ip', label: 'IP 地址', mono: true},
                {key: 'country', label: '国家'},
                {key: 'region', label: '地区'},
                {key: 'city', label: '城市'},
                {key: 'isp', label: '运营商'},
                {key: 'region_raw', label: '原始归属地', wide: true},
                {key: 'device_type', label: '设备类型'},
                {key: 'download_mode', label: '下载模式'},
                {key: 'status', label: '处理状态'},
                {key: 'fail_reason', label: '失败原因', wide: true},
                {key: 'referer', label: '来源地址', wide: true},
                {key: 'user_agent', label: 'User-Agent', mono: true, wide: true},
            ],
        };
        const LOG_DETAIL_VALUE_LABELS = {
            device_type: {
                mobile: '移动端',
                desktop: '桌面端',
                tablet: '平板',
                bot: '机器人',
                unknown: '未知',
            },
            download_mode: {
                local: '本地 APK',
                other: '网盘下载',
                lanzou: '蓝奏云（历史）',
            },
            status: {
                pending: '处理中',
                success: '成功',
                fail_missing_file: '文件缺失',
                fail_lanzou: '网盘失败（历史）',
                fail_other: '其他失败',
            },
        };
        let logDetailRequestController = null;
        let activeLogDetailTrigger = null;

        /**
         * 将详情字段规范为可展示文本；空值统一使用占位符。
         *
         * @param {unknown} value 原始字段值
         * @returns {string}
         */
        const normalizeLogDetailText = (value) => {
            if (value === null || value === undefined) {
                return '-';
            }

            const text = String(value);
            return text.trim() === '' ? '-' : text;
        };

        /**
         * 将枚举值展示为中文含义及原始值，未知值原样保留以便排查。
         *
         * @param {string} key 字段键
         * @param {unknown} value 原始字段值
         * @returns {string}
         */
        const formatLogDetailValue = (key, value) => {
            const text = normalizeLogDetailText(value);
            if (text === '-') {
                return text;
            }

            const labels = LOG_DETAIL_VALUE_LABELS[key];
            if (labels && Object.prototype.hasOwnProperty.call(labels, text)) {
                return `${labels[text]} (${text})`;
            }

            return text;
        };

        /**
         * 校验接口返回值是否是可读取的单条日志对象。
         *
         * @param {unknown} value 接口字段值
         * @returns {boolean}
         */
        const isLogDetailRecord = (value) => value !== null
            && typeof value === 'object'
            && !Array.isArray(value);

        /**
         * 从详情按钮读取并验证请求参数，拒绝无效类型和非安全整数 ID。
         *
         * @param {HTMLButtonElement} trigger 详情按钮
         * @returns {{type:string, id:number}|null}
         */
        const resolveLogDetailRequest = (trigger) => {
            const type = trigger.dataset.logType || '';
            const rawId = trigger.dataset.logId || '';
            if (!LOG_DETAIL_TYPES.has(type) || !/^[1-9]\d*$/.test(rawId)) {
                return null;
            }

            const id = Number(rawId);
            return Number.isSafeInteger(id) && id > 0 ? {type, id} : null;
        };

        /**
         * 切换触发按钮的忙碌状态，防止同一记录被重复请求。
         *
         * @param {HTMLButtonElement} trigger 详情按钮
         * @param {boolean} busy 是否正在加载
         * @returns {void}
         */
        const setLogDetailTriggerBusy = (trigger, busy) => {
            if (!trigger.isConnected) {
                return;
            }

            trigger.disabled = busy;
            if (busy) {
                trigger.setAttribute('aria-busy', 'true');
            } else {
                trigger.removeAttribute('aria-busy');
            }
        };

        /**
         * 显示详情加载占位，避免打开新记录时继续展示旧数据。
         *
         * @returns {void}
         */
        const showLogDetailLoading = () => {
            const loading = document.createElement('div');
            loading.className = 'log-detail-loading';
            loading.textContent = '正在加载详情...';
            logDetailModalBody.setAttribute('aria-busy', 'true');
            logDetailModalBody.replaceChildren(loading);
        };

        /**
         * 日志详情错误走 Toast，弹窗内仅保留中性可恢复占位。
         *
         * @param {string} message 错误提示
         * @returns {void}
         */
        const showLogDetailError = (message) => {
            const text = typeof message === 'string' ? message.trim() : '';
            const safeMessage = text ? text.slice(0, 240) : '详情暂时无法加载，请刷新后重试。';
            showAdminToast(safeMessage, 'danger');

            const placeholder = document.createElement('div');
            placeholder.className = 'log-detail-loading';
            placeholder.textContent = '详情暂时无法加载，请关闭后重试。';
            logDetailModalBody.removeAttribute('aria-busy');
            logDetailModalBody.replaceChildren(placeholder);
        };

        /**
         * 使用安全文本节点构建详情定义列表。
         *
         * @param {string} type 日志类型
         * @param {Record<string, unknown>} item 详情数据
         * @returns {void}
         */
        const renderLogDetail = (type, item) => {
            const definitions = LOG_DETAIL_FIELDS[type] || [];
            if (definitions.length === 0) {
                showLogDetailError('日志类型无效。');
                return;
            }

            const list = document.createElement('dl');
            list.className = 'log-detail-grid';
            definitions.forEach((definition) => {
                const field = document.createElement('div');
                field.className = 'log-detail-field';
                if (definition.wide) {
                    field.classList.add('log-detail-field--wide');
                }
                if (definition.mono) {
                    field.classList.add('log-detail-field--mono');
                }

                const label = document.createElement('dt');
                label.textContent = definition.label;
                const value = document.createElement('dd');
                value.textContent = formatLogDetailValue(definition.key, item[definition.key]);
                field.append(label, value);
                list.appendChild(field);
            });

            logDetailModalBody.removeAttribute('aria-busy');
            logDetailModalBody.replaceChildren(list);
        };

        /**
         * 构造同源详情接口地址；调用方已完成类型和 ID 校验。
         *
         * @param {string} type 日志类型
         * @param {number} id 日志 ID
         * @returns {string}
         */
        const logDetailUrl = (type, id) => `/admin/logs/${encodeURIComponent(type)}/detail/${encodeURIComponent(String(id))}`;

        /**
         * 取消请求并恢复弹窗初始状态，供关闭和软导航复用。
         *
         * @returns {void}
         */
        const resetLogDetailState = () => {
            logDetailRequestController?.abort();
            logDetailRequestController = null;
            if (activeLogDetailTrigger instanceof HTMLButtonElement) {
                setLogDetailTriggerBusy(activeLogDetailTrigger, false);
            }
            activeLogDetailTrigger = null;
            logDetailModalTitle.textContent = '日志详情';
            showLogDetailLoading();
        };

        /**
         * 异步加载日志详情；并发点击时只保留最后一次请求。
         *
         * 副作用：打开弹窗、切换按钮状态并替换弹窗正文。
         *
         * @param {HTMLButtonElement} trigger 详情按钮
         * @returns {Promise<void>}
         */
        const loadLogDetail = async (trigger) => {
            const request = resolveLogDetailRequest(trigger);
            if (!request) {
                resetLogDetailState();
                logDetailModalTitle.textContent = '日志详情';
                showLogDetailError('日志详情请求参数无效。');
                logDetailModal.show();
                return;
            }

            logDetailRequestController?.abort();
            if (activeLogDetailTrigger instanceof HTMLButtonElement
                && activeLogDetailTrigger !== trigger) {
                setLogDetailTriggerBusy(activeLogDetailTrigger, false);
            }

            const requestController = new AbortController();
            logDetailRequestController = requestController;
            activeLogDetailTrigger = trigger;
            setLogDetailTriggerBusy(trigger, true);
            logDetailModalTitle.textContent = LOG_DETAIL_TITLES[request.type] || '日志详情';
            showLogDetailLoading();
            logDetailModal.show();

            try {
                const response = await fetch(logDetailUrl(request.type, request.id), {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: requestController.signal,
                });
                const payload = await response.json().catch(() => null);
                if (logDetailRequestController !== requestController) {
                    return;
                }
                if (!response.ok || !payload || payload.success !== true) {
                    const message = payload && typeof payload.message === 'string'
                        ? payload.message
                        : '详情暂时无法加载，请刷新后重试。';
                    throw new Error(message);
                }
                if (payload.type !== request.type || !isLogDetailRecord(payload.item)) {
                    throw new Error('服务端返回的详情数据无效，请刷新后重试。');
                }

                const responseId = Number(payload.item.id);
                if (!Number.isSafeInteger(responseId) || responseId !== request.id) {
                    throw new Error('服务端返回的详情记录无效，请刷新后重试。');
                }

                renderLogDetail(request.type, payload.item);
            } catch (error) {
                if (error instanceof Error && error.name === 'AbortError') {
                    return;
                }
                if (logDetailRequestController === requestController) {
                    showLogDetailError(
                        error instanceof Error ? error.message : '详情暂时无法加载，请刷新后重试。'
                    );
                }
            } finally {
                if (logDetailRequestController === requestController) {
                    logDetailRequestController = null;
                    if (activeLogDetailTrigger === trigger) {
                        setLogDetailTriggerBusy(trigger, false);
                        activeLogDetailTrigger = null;
                    }
                }
            }
        };

        /**
         * 在主内容替换前关闭详情弹窗，避免旧记录保留在新页面。
         *
         * @returns {void}
         */
        closeLogDetailModal = () => {
            resetLogDetailState();
            logDetailModal.hide();
        };

        document.addEventListener('click', (event) => {
            const trigger = event.target instanceof Element
                ? event.target.closest('[data-log-detail-trigger]')
                : null;
            if (!(trigger instanceof HTMLButtonElement) || trigger.disabled) {
                return;
            }

            event.preventDefault();
            void loadLogDetail(trigger);
        });

        logDetailModalElement.addEventListener('hidden.bs.modal', () => {
            resetLogDetailState();
        });
    }

    const modalElement = document.getElementById('confirmModal');
    const messageElement = modalElement?.querySelector('[data-confirm-message]');
    const confirmSubmitButton = modalElement?.querySelector('[data-confirm-submit]');
    if (modalElement instanceof HTMLElement
        && messageElement instanceof HTMLElement
        && confirmSubmitButton instanceof HTMLButtonElement) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        let pendingForm = null;

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
                return;
            }

            event.preventDefault();
            pendingForm = form;
            messageElement.textContent = form.dataset.confirm;
            modal.show();
        });

        confirmSubmitButton.addEventListener('click', () => {
            if (!(pendingForm instanceof HTMLFormElement)) {
                return;
            }

            const form = pendingForm;
            pendingForm = null;
            modal.hide();
            if (form.matches('[data-admin-dirty-form]')) {
                form.dataset.adminDirty = 'false';
            }
            // 危险操作确认后：优先静默提交，避免整页刷新。
            if (form.matches('[data-admin-ajax-form]')) {
                void submitAdminAjaxForm(form);
                return;
            }
            form.submit();
        });

        modalElement.addEventListener('hidden.bs.modal', () => {
            pendingForm = null;
        });
    }

    // 仅允许继续原生提交的表单视为已处理；异步提交和确认取消都保留脏状态。
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)
            || !form.matches('[data-admin-dirty-form]')
            || event.defaultPrevented) {
            return;
        }
        form.dataset.adminDirty = 'false';
        syncConfigDirtyUi(form);
    });

    /**
     * 判断当前主内容是否存在未保存的表单修改。
     *
     * @returns {boolean}
     */
    const hasUnsavedChanges = () => Array.from(document.querySelectorAll('[data-admin-dirty-form]'))
        .some((form) => form instanceof HTMLFormElement && form.dataset.adminDirty === 'true');

    /**
     * 确认管理员是否允许丢弃未保存修改。
     *
     * @returns {boolean}
     */
    const confirmDiscardChanges = () => !hasUnsavedChanges()
        || window.confirm('当前页面有未保存的修改，确认离开吗？');

    /**
     * 判断 URL 是否仍位于后台路由前缀内。
     *
     * @param {URL} url 待判断地址
     * @returns {boolean}
     */
    const isAdminUrl = (url) => url.origin === window.location.origin
        && (url.pathname === '/admin' || url.pathname.startsWith('/admin/'));

    /**
     * 判断链接是否符合桌面侧栏异步导航条件。
     *
     * @param {HTMLAnchorElement} link 点击的侧栏链接
     * @param {MouseEvent} event 原始点击事件
     * @returns {boolean}
     */
    const isAsyncNavigationClick = (link, event) => {
        if (window.matchMedia('(max-width: 991.98px)').matches
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
            || link.target
            || link.hasAttribute('download')) {
            return false;
        }

        const target = new URL(link.href, window.location.href);
        return isAdminUrl(target)
            && target.pathname !== '/admin/login';
    };

    let currentHistoryIndex = 0;
    const initialHistoryState = history.state && typeof history.state === 'object' ? history.state : {};
    if (Number.isInteger(initialHistoryState.novaAdminIndex)) {
        currentHistoryIndex = initialHistoryState.novaAdminIndex;
    } else {
        history.replaceState(
            {...initialHistoryState, novaAdmin: true, novaAdminIndex: currentHistoryIndex, scrollTop: 0},
            '',
            window.location.href
        );
    }

    /**
     * 将当前内部滚动位置写入当前历史条目。
     *
     * @returns {void}
     */
    const saveCurrentScrollPosition = () => {
        const main = document.querySelector('[data-admin-main]');
        if (!(main instanceof HTMLElement)) {
            return;
        }

        const state = history.state && typeof history.state === 'object' ? history.state : {};
        history.replaceState(
            {...state, novaAdmin: true, novaAdminIndex: currentHistoryIndex, scrollTop: main.scrollTop},
            '',
            window.location.href
        );
    };

    /**
     * 从响应文档解析后台页面替换所需的完整节点。
     *
     * @param {string} html 服务端完整 HTML
     * @returns {{content: HTMLElement, pageTitle: HTMLElement, pageActions: HTMLElement, activeHref: string, title: string, flashes: Array<{message: string, type: string}>}|null}
     */
    const parseAdminPage = (html) => {
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const content = parsed.querySelector('[data-admin-content]');
        const pageTitle = parsed.querySelector('[data-admin-page-title]');
        const pageActions = parsed.querySelector('[data-admin-page-actions]');
        const activeLink = parsed.querySelector('[data-admin-nav-link][aria-current="page"]');
        if (!(content instanceof HTMLElement)
            || !(pageTitle instanceof HTMLElement)
            || !(pageActions instanceof HTMLElement)
            || !parsed.documentElement.classList.contains('admin-layout')) {
            return null;
        }

        const flashes = Array.from(parsed.querySelectorAll('#adminToastContainer .admin-toast'))
            .map((toast) => {
                if (!(toast instanceof HTMLElement)) {
                    return null;
                }
                const message = toast.querySelector('.toast-body')?.textContent?.trim() || '';
                return message === '' ? null : {
                    message,
                    type: readToastType(toast),
                };
            })
            .filter((flash) => flash !== null);

        return {
            content,
            pageTitle,
            pageActions,
            activeHref: activeLink instanceof HTMLAnchorElement
                ? new URL(activeLink.getAttribute('href') || '', window.location.origin).href
                : '',
            title: parsed.title,
            flashes,
        };
    };

    /**
     * 同步本页侧栏的激活状态，不替换任何侧栏节点。
     *
     * @param {string} activeHref 新页面声明的当前链接；没有对应侧栏项时为空
     * @returns {void}
     */
    const updateActiveNavigation = (activeHref) => {
        document.querySelectorAll('[data-admin-nav-link]').forEach((link) => {
            if (!(link instanceof HTMLAnchorElement)) {
                return;
            }
            const active = new URL(link.href, window.location.origin).href === activeHref;
            link.classList.toggle('active', active);
            if (active) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    /**
     * 创建并切换顶栏细进度条状态。
     *
     * @param {boolean} loading 是否正在加载
     * @returns {void}
     */
    const setNavigationLoading = (loading) => {
        const progress = document.querySelector('[data-admin-navigation-progress]');
        const status = document.querySelector('[data-admin-navigation-status]');
        const main = document.querySelector('[data-admin-main]');
        if (progress instanceof HTMLElement) {
            progress.classList.toggle('is-loading', loading);
        }
        if (status instanceof HTMLElement) {
            status.textContent = loading ? '正在加载页面' : '';
        }
        if (main instanceof HTMLElement) {
            main.setAttribute('aria-busy', String(loading));
        }
    };

    /** @type {AbortController|null} */
    let navigationRequestController = null;
    /** @type {number|null} */
    let restoringHistoryIndex = null;

    /**
     * 加载并替换后台主内容；异常时调用方使用常规跳转回退。
     *
     * @param {string} rawUrl 目标地址
     * @param {{historyMode: 'push'|'replace'|'none', scrollTop?: number}} options 导航选项
     * @returns {Promise<boolean>} 是否完成局部替换
     */
    const navigateAdminPage = async (rawUrl, options) => {
        const targetUrl = new URL(rawUrl, window.location.href);
        if (!isAdminUrl(targetUrl)) {
            return false;
        }

        navigationRequestController?.abort();
        const requestController = new AbortController();
        navigationRequestController = requestController;
        setNavigationLoading(true);
        try {
            const response = await fetch(targetUrl.href, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                redirect: 'follow',
                signal: requestController.signal,
            });
            const responseUrl = new URL(response.url, window.location.href);
            if (!response.ok || !isAdminUrl(responseUrl) || responseUrl.pathname === '/admin/login') {
                return false;
            }

            const page = parseAdminPage(await response.text());
            const currentContent = document.querySelector('[data-admin-content]');
            const currentPageTitle = document.querySelector('[data-admin-page-title]');
            const currentPageActions = document.querySelector('[data-admin-page-actions]');
            const main = document.querySelector('[data-admin-main]');
            if (!page
                || !(currentContent instanceof HTMLElement)
                || !(currentPageTitle instanceof HTMLElement)
                || !(currentPageActions instanceof HTMLElement)
                || !(main instanceof HTMLElement)) {
                return false;
            }

            if (window.NovaAdminDashboard && typeof window.NovaAdminDashboard.unmount === 'function') {
                window.NovaAdminDashboard.unmount();
            }
            closeContentModal();
            closeLogDetailModal();
            currentContent.replaceWith(page.content);
            currentPageTitle.replaceWith(page.pageTitle);
            currentPageActions.replaceWith(page.pageActions);
            updateActiveNavigation(page.activeHref);
            if (page.title) {
                document.title = page.title;
            }
            page.flashes.forEach((flash) => {
                showAdminToast(flash.message, flash.type);
            });

            const scrollTop = Number.isFinite(options.scrollTop) ? Math.max(0, options.scrollTop) : 0;
            main.scrollTop = scrollTop;
            const replacementContent = document.querySelector('[data-admin-content]');
            if (replacementContent instanceof HTMLElement) {
                initializePageContent(replacementContent);
                replacementContent.focus({preventScroll: true});
            }

            const state = {novaAdmin: true, novaAdminIndex: currentHistoryIndex, scrollTop};
            if (options.historyMode === 'push') {
                currentHistoryIndex += 1;
                state.novaAdminIndex = currentHistoryIndex;
                history.pushState(state, '', responseUrl.href);
            } else if (options.historyMode === 'replace') {
                history.replaceState(state, '', responseUrl.href);
            }
            return true;
        } catch (error) {
            return error instanceof Error && error.name === 'AbortError';
        } finally {
            if (navigationRequestController === requestController) {
                navigationRequestController = null;
                setNavigationLoading(false);
            }
        }
    };

    softReloadAdminPageImpl = async (rawUrl, historyMode = 'replace') => {
        const main = document.querySelector('[data-admin-main]');
        const scrollTop = main instanceof HTMLElement ? main.scrollTop : 0;
        return navigateAdminPage(rawUrl, {historyMode, scrollTop});
    };

    /**
     * 主内容区内的筛选 GET 表单改为软导航，保留查询结果页的壳层不刷新。
     */
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)
            || !form.closest('[data-admin-content]')
            || form.matches('[data-admin-ajax-form]')
            || form.matches('[data-content-form]')
            || (form.method || 'get').toLowerCase() !== 'get') {
            return;
        }
        if (window.matchMedia('(max-width: 991.98px)').matches) {
            return;
        }

        event.preventDefault();
        const actionUrl = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        const params = new URLSearchParams();
        new FormData(form).forEach((value, key) => {
            if (typeof value === 'string' && value !== '') {
                params.append(key, value);
            }
        });
        const query = params.toString();
        actionUrl.search = query === '' ? '' : `?${query}`;
        saveCurrentScrollPosition();
        void softReloadAdminPage(actionUrl.href, 'push').then((completed) => {
            if (!completed) {
                window.location.assign(actionUrl.href);
            }
        });
    });

    /**
     * 主内容区分页与筛选重置链接改为软导航。
     */
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element
            ? event.target.closest([
                '[data-admin-content] .pagination-wrap a',
                '[data-admin-content] .filter-panel a.btn',
            ].join(','))
            : null;
        if (!(link instanceof HTMLAnchorElement)
            || link.classList.contains('disabled')
            || !isAdminUrl(new URL(link.href, window.location.href))) {
            return;
        }
        if (event.defaultPrevented
            || (event instanceof MouseEvent
                && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey))) {
            return;
        }
        if (window.matchMedia('(max-width: 991.98px)').matches) {
            return;
        }

        event.preventDefault();
        saveCurrentScrollPosition();
        void softReloadAdminPage(link.href, 'push').then((completed) => {
            if (!completed) {
                window.location.assign(link.href);
            }
        });
    });

    /**
     * 侧栏主导航仅在桌面端接管，其他链接维持浏览器默认行为。
     */
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element
            ? event.target.closest('[data-admin-nav-link]')
            : null;
        if (!(link instanceof HTMLAnchorElement) || !isAsyncNavigationClick(link, event)) {
            return;
        }
        if (link.getAttribute('aria-current') === 'page') {
            event.preventDefault();
            return;
        }
        if (!confirmDiscardChanges()) {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        saveCurrentScrollPosition();
        void navigateAdminPage(link.href, {historyMode: 'push'}).then((completed) => {
            if (!completed) {
                window.location.assign(link.href);
            }
        });
    });

    window.addEventListener('popstate', (event) => {
        if (window.matchMedia('(max-width: 991.98px)').matches) {
            window.location.reload();
            return;
        }
        const targetHistoryIndex = event.state && Number.isInteger(event.state.novaAdminIndex)
            ? event.state.novaAdminIndex
            : null;
        if (restoringHistoryIndex !== null && targetHistoryIndex === restoringHistoryIndex) {
            restoringHistoryIndex = null;
            return;
        }
        if (targetHistoryIndex === null) {
            if (!confirmDiscardChanges()) {
                restoringHistoryIndex = currentHistoryIndex;
                history.go(1);
                return;
            }
            window.location.reload();
            return;
        }
        if (!confirmDiscardChanges()) {
            const reversal = currentHistoryIndex - targetHistoryIndex;
            if (reversal === 0) {
                return;
            }
            restoringHistoryIndex = currentHistoryIndex;
            history.go(reversal);
            return;
        }

        currentHistoryIndex = targetHistoryIndex;
        const scrollTop = event.state && Number.isFinite(event.state.scrollTop) ? event.state.scrollTop : 0;
        void navigateAdminPage(window.location.href, {historyMode: 'none', scrollTop}).then((completed) => {
            if (!completed) {
                window.location.reload();
            }
        });
    });

    window.addEventListener('beforeunload', (event) => {
        if (!hasUnsavedChanges()) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });

    const main = document.querySelector('[data-admin-main]');
    if (main instanceof HTMLElement) {
        main.addEventListener('scroll', () => {
            saveCurrentScrollPosition();
        }, {passive: true});
    }
    saveCurrentScrollPosition();
})();
