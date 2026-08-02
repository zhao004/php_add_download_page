(() => {
    'use strict';

    /**
     * 复制文本；剪贴板 API 不可用时回退到受限的临时文本框。
     * 副作用：会暂时向文档插入文本框，并更新复制按钮的可访问状态。
     *
     * @param {string} value 待复制内容
     * @returns {Promise<boolean>} 是否复制成功
     */
    const copyText = async (value) => {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value);
                return true;
            } catch (error) {
                // 继续使用兼容性回退，不向页面暴露浏览器内部错误。
            }
        }

        const input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        const copied = document.execCommand('copy');
        input.remove();
        return copied;
    };

    document.addEventListener('DOMContentLoaded', () => {
        const button = document.querySelector('[data-copy-share-password]');
        const value = document.getElementById('download-other-password-value');
        const status = document.querySelector('[data-copy-share-password-status]');
        if (!(button instanceof HTMLButtonElement)
            || !(value instanceof HTMLElement)
            || !(status instanceof HTMLElement)) {
            return;
        }

        const label = button.querySelector('.download-other-copy-text');

        button.addEventListener('click', async () => {
            const copied = await copyText(value.textContent || '');
            const message = copied ? '访问密码已复制。' : '复制失败，请手动选择访问密码。';

            status.textContent = message;
            button.dataset.copyState = copied ? 'success' : 'failure';
            button.setAttribute('aria-label', copied ? '访问密码已复制' : '复制访问密码失败');
            button.title = copied ? '已复制' : '复制失败';
            if (label instanceof HTMLElement) {
                label.textContent = copied ? '已复制' : '复制';
            }

            if (copied) {
                window.setTimeout(() => {
                    if (button.dataset.copyState !== 'success') {
                        return;
                    }
                    button.dataset.copyState = '';
                    button.setAttribute('aria-label', '复制访问密码');
                    button.title = '复制访问密码';
                    if (label instanceof HTMLElement) {
                        label.textContent = '复制';
                    }
                }, 1600);
            }
        });
    });
})();
