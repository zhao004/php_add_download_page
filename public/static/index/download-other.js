(() => {
    'use strict';

    const COPY_FEEDBACK_DURATION_MS = 2000;
    const COPY_STATE_DEFAULT = 'default';
    const COPY_STATE_SUCCESS = 'success';
    const COPY_STATE_FAILURE = 'failure';

    /**
     * 使用受限的临时文本框完成兼容复制。
     * 副作用：短暂插入并移除 textarea，失败时不会抛出浏览器内部异常。
     *
     * @param {string} value 待复制的文本
     * @returns {boolean} 是否复制成功
     */
    const copyWithLegacyInput = (value) => {
        const input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', '');
        input.setAttribute('aria-hidden', 'true');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        input.style.pointerEvents = 'none';
        document.body.appendChild(input);

        try {
            input.select();
            return document.execCommand('copy');
        } catch (error) {
            return false;
        } finally {
            input.remove();
        }
    };

    /**
     * 优先通过异步剪贴板 API 复制，受权限或安全上下文限制时回退到兼容方案。
     * 副作用：可能向系统剪贴板写入文本。
     *
     * @param {string} value 待复制的文本
     * @returns {Promise<boolean>} 是否复制成功
     */
    const copyText = async (value) => {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value);
                return true;
            } catch (error) {
                // 权限被拒绝时继续使用兼容回退，避免将浏览器错误暴露给用户。
            }
        }

        return copyWithLegacyInput(value);
    };

    /**
     * 将按钮恢复为默认文案和可访问名称。
     * 副作用：更新按钮与状态播报区域。
     *
     * @param {HTMLButtonElement} button 复制按钮
     * @param {HTMLElement | null} label 按钮文案节点
     * @param {HTMLElement} status 状态播报节点
     * @returns {void}
     */
    const resetCopyButton = (button, label, status) => {
        button.dataset.copyState = COPY_STATE_DEFAULT;
        button.setAttribute('aria-label', '复制访问密码');
        button.title = '复制访问密码';
        status.textContent = '';
        if (label instanceof HTMLElement) {
            label.textContent = '复制';
        }
    };

    /**
     * 展示独立的复制成功 Toast，并在限定时间后自动隐藏。
     * 副作用：更新 Toast 可见性并创建或替换延迟隐藏计时器。
     *
     * @param {HTMLElement} toast Toast 节点
     * @param {number | undefined} timerId 当前计时器标识
     * @returns {number} 新的计时器标识
     */
    const showSuccessToast = (toast, timerId) => {
        if (typeof timerId === 'number') {
            window.clearTimeout(timerId);
        }

        toast.hidden = false;
        toast.setAttribute('aria-hidden', 'false');
        window.requestAnimationFrame(() => {
            toast.classList.add('is-visible');
        });

        return window.setTimeout(() => {
            toast.classList.remove('is-visible');
            toast.setAttribute('aria-hidden', 'true');
            toast.hidden = true;
        }, COPY_FEEDBACK_DURATION_MS);
    };

    /**
     * 隐藏正在展示的 Toast，并终止其旧计时器。
     * 副作用：更新 Toast 可见性与计时器。
     *
     * @param {HTMLElement | null} toast Toast 节点
     * @param {number | undefined} timerId 当前计时器标识
     * @returns {undefined} 不保留计时器
     */
    const hideSuccessToast = (toast, timerId) => {
        if (typeof timerId === 'number') {
            window.clearTimeout(timerId);
        }
        if (!(toast instanceof HTMLElement)) {
            return undefined;
        }

        toast.classList.remove('is-visible');
        toast.setAttribute('aria-hidden', 'true');
        toast.hidden = true;
        return undefined;
    };

    /**
     * 初始化复制交互；仅在密码节点完整存在时绑定事件，避免无密码页面产生无效行为。
     * 副作用：注册点击事件，并在复制后更新状态、Toast 和延迟重置计时器。
     *
     * @returns {void}
     */
    const initializeCopyFlow = () => {
        const button = document.querySelector('[data-copy-share-password]');
        const value = document.getElementById('download-other-password-value');
        const status = document.querySelector('[data-copy-share-password-status]');
        const toast = document.querySelector('[data-copy-success-toast]');
        if (!(button instanceof HTMLButtonElement)
            || !(value instanceof HTMLElement)
            || !(status instanceof HTMLElement)) {
            return;
        }

        const label = button.querySelector('[data-copy-label]');
        let isCopying = false;
        let buttonResetTimerId;
        let toastTimerId;

        button.addEventListener('click', async () => {
            if (isCopying) {
                return;
            }

            const password = value.textContent || '';
            if (!password.trim()) {
                button.dataset.copyState = COPY_STATE_FAILURE;
                button.setAttribute('aria-label', '访问密码为空，无法复制');
                button.title = '访问密码为空';
                status.textContent = '访问密码为空，无法复制。';
                return;
            }

            isCopying = true;
            if (typeof buttonResetTimerId === 'number') {
                window.clearTimeout(buttonResetTimerId);
            }

            let copied = false;
            try {
                copied = await copyText(password);
            } catch (error) {
                copied = false;
            } finally {
                isCopying = false;
            }

            const copyState = copied ? COPY_STATE_SUCCESS : COPY_STATE_FAILURE;
            button.dataset.copyState = copyState;
            button.setAttribute('aria-label', copied ? '访问密码已复制' : '复制访问密码失败');
            button.title = copied ? '已复制' : '复制失败';
            status.textContent = copied ? '访问密码已复制。' : '复制失败，请手动选择访问密码。';
            if (label instanceof HTMLElement) {
                label.textContent = copied ? '已复制' : '复制';
            }

            if (copied && toast instanceof HTMLElement) {
                toastTimerId = showSuccessToast(toast, toastTimerId);
            } else {
                toastTimerId = hideSuccessToast(toast, toastTimerId);
            }

            buttonResetTimerId = window.setTimeout(() => {
                if (button.dataset.copyState === copyState) {
                    resetCopyButton(button, label, status);
                }
            }, COPY_FEEDBACK_DURATION_MS);
        });
    };

    document.addEventListener('DOMContentLoaded', initializeCopyFlow);
})();
