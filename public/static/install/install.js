/**
 * 安装页交互：在不提交表单的情况下检测数据库连通性。
 *
 * 副作用：向安装器同源接口发送一次 POST；失败只展示消息，不修改表单或安装状态。
 */
(() => {
    'use strict';

    const form = document.querySelector('[data-database-form]');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const button = form.querySelector('[data-test-connection]');
    const result = form.querySelector('[data-connection-result]');
    if (!(button instanceof HTMLButtonElement) || !(result instanceof HTMLElement)) {
        return;
    }

    button.addEventListener('click', async () => {
        if (!form.reportValidity()) {
            return;
        }

        const endpoint = form.dataset.testUrl;
        if (!endpoint) {
            result.className = 'connection-result is-error';
            result.textContent = '连接检测地址缺失。';
            return;
        }

        button.disabled = true;
        result.className = 'connection-result';
        result.textContent = '正在连接数据库...';

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
            });
            const payload = await response.json();
            const success = response.ok && payload.success === true;

            result.className = `connection-result ${success ? 'is-success' : 'is-error'}`;
            result.textContent = typeof payload.message === 'string'
                ? payload.message
                : '服务器返回了无法识别的检测结果。';
        } catch (error) {
            result.className = 'connection-result is-error';
            result.textContent = '无法完成连接检测，请确认 PHP 服务正常后重试。';
        } finally {
            button.disabled = false;
        }
    });
})();
