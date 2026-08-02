/**
 * 登录页交互：Toast 提示、密码显隐、验证码过滤、记住用户名、
 * 异步提交与浏览器凭据管理器。
 *
 * 副作用：调用 Bootstrap Toast、fetch 登录接口、可能写入 localStorage 用户名。
 * 应用不把明文密码写入 Cookie 或 Web Storage，密码保存由浏览器安全存储负责。
 */
(function () {
    'use strict';

    /** Toast 默认最大同时展示条数。 */
    var DEFAULT_TOAST_MAX = 3;
    /** Toast 自动关闭时长（毫秒）。 */
    var TOAST_DELAY_MS = 4200;
    /** 允许的 Toast 语义类型。 */
    var TOAST_TYPES = {
        success: 'bi-check-circle-fill',
        danger: 'bi-x-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill',
    };

    var form = document.querySelector('[data-login-form]');
    if (!form) {
        return;
    }

    var username = document.getElementById('username');
    var password = document.getElementById('password');
    var remember = document.querySelector('[data-remember-login]');
    var captchaInput = document.querySelector('[data-captcha-input]') || document.getElementById('captcha');
    var captchaImage = document.querySelector('[data-captcha-image]');
    var passwordToggle = document.querySelector('[data-password-toggle]');
    var passwordToggleIcon = document.querySelector('[data-password-toggle-icon]');
    var csrfInput = form.querySelector('[data-login-csrf]') || form.querySelector('input[name="_token"]');
    var submitButton = form.querySelector('[data-login-submit]') || form.querySelector('[type="submit"]');
    var submitLabel = form.querySelector('[data-login-submit-label]');
    var submitSpinner = form.querySelector('[data-login-submit-spinner]');
    var storageKey = 'nova.admin.remembered-username';
    /** 验证码仅允许英文大小写字母与数字。 */
    var captchaAllowedPattern = /[^A-Za-z0-9]/g;
    /** 防止重复提交。 */
    var submitting = false;

    /**
     * 获取或创建 Toast 容器。
     *
     * @returns {HTMLElement}
     */
    function getToastContainer() {
        var existing = document.getElementById('adminToastContainer');
        if (existing) {
            return existing;
        }

        var created = document.createElement('div');
        created.id = 'adminToastContainer';
        created.className = 'toast-container position-fixed top-0 end-0 p-3';
        created.setAttribute('data-toast-max', String(DEFAULT_TOAST_MAX));
        created.setAttribute('aria-live', 'polite');
        created.setAttribute('aria-relevant', 'additions');
        document.body.appendChild(created);
        return created;
    }

    /**
     * @param {HTMLElement} container Toast 容器
     * @returns {number}
     */
    function resolveToastMax(container) {
        var configured = Number.parseInt(container.getAttribute('data-toast-max') || '', 10);
        if (Number.isFinite(configured) && configured > 0) {
            return Math.min(configured, 10);
        }
        return DEFAULT_TOAST_MAX;
    }

    /**
     * @param {string} type 原始类型
     * @returns {string}
     */
    function resolveToastType(type) {
        return Object.prototype.hasOwnProperty.call(TOAST_TYPES, type) ? type : 'info';
    }

    /**
     * @param {HTMLElement} container Toast 容器
     * @returns {void}
     */
    function enforceToastLimit(container) {
        var toasts = Array.prototype.slice.call(container.querySelectorAll('.admin-toast'));
        var overflow = toasts.length - resolveToastMax(container);
        if (overflow <= 0 || typeof bootstrap === 'undefined' || !bootstrap.Toast) {
            return;
        }

        toasts.slice(0, overflow).forEach(function (toastElement) {
            var instance = bootstrap.Toast.getInstance(toastElement);
            if (instance) {
                instance.dispose();
            }
            toastElement.remove();
        });
    }

    /**
     * @param {HTMLElement} toastElement Toast 节点
     * @returns {void}
     */
    function bindToastLifecycle(toastElement) {
        toastElement.addEventListener('hidden.bs.toast', function () {
            if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
                var instance = bootstrap.Toast.getInstance(toastElement);
                if (instance) {
                    instance.dispose();
                }
            }
            toastElement.remove();
        }, {once: true});
    }

    /**
     * 构建一条 Toast DOM。
     *
     * @param {string} message 文案
     * @param {string} type 语义类型
     * @returns {HTMLElement}
     */
    function createToastElement(message, type) {
        var tone = resolveToastType(type);
        var iconClass = TOAST_TYPES[tone] || TOAST_TYPES.info;
        var toastElement = document.createElement('div');
        toastElement.className = 'toast admin-toast admin-toast--' + tone;
        toastElement.setAttribute('role', 'status');
        toastElement.setAttribute('aria-atomic', 'true');
        toastElement.setAttribute('data-toast-type', tone);
        toastElement.setAttribute('data-bs-delay', String(TOAST_DELAY_MS));
        toastElement.setAttribute('data-bs-autohide', 'true');
        toastElement.style.setProperty('--admin-toast-duration', TOAST_DELAY_MS + 'ms');
        toastElement.innerHTML = [
            '<div class="admin-toast-inner">',
            '  <span class="admin-toast-icon" aria-hidden="true"><i class="bi ' + iconClass + '"></i></span>',
            '  <div class="toast-body"></div>',
            '  <button type="button" class="admin-toast-close" data-bs-dismiss="toast" aria-label="关闭">',
            '    <i class="bi bi-x-lg" aria-hidden="true"></i>',
            '  </button>',
            '</div>',
            '<div class="admin-toast-progress" aria-hidden="true"></div>',
        ].join('');

        var body = toastElement.querySelector('.toast-body');
        if (body) {
            body.textContent = message;
        }

        return toastElement;
    }

    /**
     * 展示一条 Toast。
     *
     * @param {string} message 提示文案
     * @param {string} [type='info'] success/danger/warning/info
     * @returns {void}
     */
    function showLoginToast(message, type) {
        var text = typeof message === 'string' ? message.trim() : '';
        if (!text) {
            return;
        }
        if (typeof bootstrap === 'undefined' || !bootstrap.Toast) {
            // Bootstrap 未加载时退化为控制台，避免阻断登录流程。
            return;
        }

        var container = getToastContainer();
        var toastElement = createToastElement(text, type || 'info');
        container.appendChild(toastElement);
        enforceToastLimit(container);
        bindToastLifecycle(toastElement);
        bootstrap.Toast.getOrCreateInstance(toastElement, {
            delay: TOAST_DELAY_MS,
            autohide: true,
        }).show();
    }

    /**
     * 启动服务端预渲染的 Toast。
     *
     * @returns {void}
     */
    function bootServerToasts() {
        if (typeof bootstrap === 'undefined' || !bootstrap.Toast) {
            return;
        }

        var container = getToastContainer();
        enforceToastLimit(container);
        Array.prototype.forEach.call(container.querySelectorAll('.admin-toast'), function (element) {
            element.style.setProperty('--admin-toast-duration', TOAST_DELAY_MS + 'ms');
            bindToastLifecycle(element);
            bootstrap.Toast.getOrCreateInstance(element, {
                delay: TOAST_DELAY_MS,
                autohide: true,
            }).show();
        });
    }

    /**
     * 读取本地记住的用户名。
     *
     * @returns {string}
     */
    function readRememberedUsername() {
        try {
            return window.localStorage.getItem(storageKey) || '';
        } catch (error) {
            return '';
        }
    }

    /**
     * 按勾选状态保存或清除记住的用户名。
     *
     * @returns {void}
     */
    function saveRememberedUsername() {
        try {
            if (remember && remember.checked && username && username.value.trim() !== '') {
                window.localStorage.setItem(storageKey, username.value.trim());
            } else {
                window.localStorage.removeItem(storageKey);
            }
        } catch (error) {
            // 隐私模式或禁用存储时不影响正常登录。
        }
    }

    /**
     * 在支持时把凭据交给浏览器密码管理器。
     *
     * @returns {void}
     */
    function storeBrowserCredential() {
        if (!remember || !remember.checked || !window.PasswordCredential || !navigator.credentials || !navigator.credentials.store) {
            return;
        }
        if (!username || !password || username.value.trim() === '' || password.value === '') {
            return;
        }

        try {
            var credential = new PasswordCredential({
                id: username.value.trim(),
                password: password.value,
                name: username.value.trim(),
            });
            navigator.credentials.store(credential).catch(function () {
                // 浏览器拒绝存储时忽略。
            });
        } catch (error) {
            // PasswordCredential 构造失败时忽略。
        }
    }

    /**
     * 刷新验证码图片并清空输入。
     *
     * @param {boolean} [focusInput=true] 是否聚焦验证码输入框
     * @returns {void}
     */
    function refreshCaptcha(focusInput) {
        if (!captchaImage) {
            return;
        }

        var baseUrl = captchaImage.src.split('?')[0];
        captchaImage.src = baseUrl + '?t=' + Date.now();
        if (captchaInput) {
            captchaInput.value = '';
            if (focusInput !== false) {
                captchaInput.focus();
            }
        }
    }

    /**
     * 将验证码输入限制为英文大小写字母与数字，最多 4 位。
     *
     * @returns {void}
     */
    function sanitizeCaptchaInput() {
        if (!captchaInput) {
            return;
        }

        var cleaned = String(captchaInput.value || '')
            .replace(captchaAllowedPattern, '')
            .slice(0, 4);
        if (captchaInput.value !== cleaned) {
            captchaInput.value = cleaned;
        }
    }

    /**
     * 切换密码明文/密文显示，并同步图标与无障碍属性。
     *
     * @returns {void}
     */
    function togglePasswordVisibility() {
        if (!password || !passwordToggle) {
            return;
        }

        var willShow = password.type === 'password';
        password.type = willShow ? 'text' : 'password';
        passwordToggle.setAttribute('aria-pressed', willShow ? 'true' : 'false');
        passwordToggle.setAttribute('aria-label', willShow ? '隐藏密码' : '显示密码');
        passwordToggle.setAttribute('title', willShow ? '隐藏密码' : '显示密码');

        if (passwordToggleIcon) {
            passwordToggleIcon.classList.toggle('bi-eye', !willShow);
            passwordToggleIcon.classList.toggle('bi-eye-slash', willShow);
        }
    }

    /**
     * 设置提交按钮忙碌状态。
     *
     * @param {boolean} busy 是否忙碌
     * @returns {void}
     */
    function setSubmitting(busy) {
        submitting = busy;
        if (!(submitButton instanceof HTMLButtonElement)) {
            return;
        }

        submitButton.disabled = busy;
        if (busy) {
            submitButton.setAttribute('aria-busy', 'true');
        } else {
            submitButton.removeAttribute('aria-busy');
        }

        if (submitLabel) {
            submitLabel.hidden = busy;
        }
        if (submitSpinner) {
            submitSpinner.hidden = !busy;
        }
    }

    /**
     * 同步服务端下发的 CSRF Token。
     *
     * @param {string} token 新 token
     * @returns {void}
     */
    function syncCsrfToken(token) {
        if (!csrfInput || typeof token !== 'string' || token === '') {
            return;
        }
        csrfInput.value = token;
    }

    /**
     * 客户端基础校验，失败时 Toast 提示。
     *
     * @returns {boolean} 是否通过
     */
    function validateForm() {
        var name = username ? username.value.trim() : '';
        var pass = password ? password.value : '';
        var captcha = captchaInput ? captchaInput.value.trim() : '';

        if (name === '') {
            showLoginToast('请输入用户名。', 'warning');
            if (username) {
                username.focus();
            }
            return false;
        }
        if (pass === '') {
            showLoginToast('请输入密码。', 'warning');
            if (password) {
                password.focus();
            }
            return false;
        }
        if (!/^[A-Za-z0-9]{4}$/.test(captcha)) {
            showLoginToast('请输入 4 位英文或数字验证码。', 'warning');
            if (captchaInput) {
                captchaInput.focus();
            }
            return false;
        }

        return true;
    }

    /**
     * 异步提交登录表单。
     *
     * @returns {Promise<void>}
     */
    function submitLogin() {
        if (submitting) {
            return Promise.resolve();
        }
        if (!validateForm()) {
            return Promise.resolve();
        }

        setSubmitting(true);
        saveRememberedUsername();

        return fetch(form.action || '/admin/login', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).then(function (response) {
            return response.json().catch(function () {
                return null;
            }).then(function (payload) {
                return {response: response, payload: payload};
            });
        }).then(function (result) {
            var payload = result.payload;
            if (!payload || typeof payload.success !== 'boolean') {
                throw new Error('登录服务响应异常，请刷新后重试。');
            }

            if (payload.success) {
                storeBrowserCredential();
                var redirectUrl = typeof payload.redirect === 'string' && payload.redirect !== ''
                    ? payload.redirect
                    : '/admin';
                window.location.assign(redirectUrl);
                return;
            }

            showLoginToast(
                typeof payload.message === 'string' && payload.message !== ''
                    ? payload.message
                    : '登录失败，请检查账号信息。',
                'danger'
            );
            if (typeof payload.csrfToken === 'string') {
                syncCsrfToken(payload.csrfToken);
            }
            refreshCaptcha(true);
            setSubmitting(false);
        }).catch(function (error) {
            showLoginToast(
                error instanceof Error ? error.message : '网络异常，请稍后重试。',
                'danger'
            );
            refreshCaptcha(false);
            setSubmitting(false);
        });
    }

    bootServerToasts();

    var rememberedUsername = readRememberedUsername();
    if (username && username.value.trim() === '' && rememberedUsername !== '') {
        username.value = rememberedUsername;
        if (remember) {
            remember.checked = true;
        }
    }

    if (passwordToggle) {
        passwordToggle.addEventListener('click', function () {
            togglePasswordVisibility();
        });
    }

    if (captchaInput) {
        captchaInput.addEventListener('input', sanitizeCaptchaInput);
        captchaInput.addEventListener('blur', sanitizeCaptchaInput);
        // 拦截粘贴内容，避免非字母数字字符进入输入框。
        captchaInput.addEventListener('paste', function () {
            window.setTimeout(sanitizeCaptchaInput, 0);
        });
        sanitizeCaptchaInput();
    }

    if (captchaImage) {
        captchaImage.addEventListener('click', function () {
            refreshCaptcha(true);
        });
        captchaImage.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                refreshCaptcha(true);
            }
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        void submitLogin();
    });

    if (window.PasswordCredential && navigator.credentials && navigator.credentials.get && password) {
        navigator.credentials.get({password: true, mediation: 'optional'}).then(function (credential) {
            if (!credential) {
                return;
            }
            if (username && username.value.trim() === '' && credential.id) {
                username.value = credential.id;
            }
            if (credential.password && password.value === '') {
                password.value = credential.password;
                if (remember) {
                    remember.checked = true;
                }
            }
        }).catch(function () {
            // 浏览器不支持凭据读取时保留原生表单行为。
        });
    }
}());
