/**
 * 后台仪表盘图表生命周期。
 *
 * 副作用：按需加载 Chart.js，并在页面主内容替换时创建或销毁趋势图。
 */
(() => {
    'use strict';

    /** @type {{destroy: () => void}|undefined} */
    let trendChart;
    let chartLoader;

    /**
     * 按需加载 Chart.js，首次完整加载和无刷新进入仪表盘共用同一 Promise。
     *
     * @returns {Promise<void>}
     */
    const loadChartLibrary = () => {
        if (typeof window.Chart === 'function') {
            return Promise.resolve();
        }
        if (chartLoader) {
            return chartLoader;
        }

        chartLoader = new Promise((resolve, reject) => {
            const existing = document.querySelector('script[data-admin-chart-library]');
            if (existing instanceof HTMLScriptElement) {
                existing.addEventListener('load', () => resolve(), {once: true});
                existing.addEventListener('error', () => reject(new Error('Chart.js 加载失败。')), {once: true});
                return;
            }

            const script = document.createElement('script');
            script.src = '/static/vendor/chartjs/chart.umd.min.js';
            script.dataset.adminChartLibrary = 'true';
            script.async = true;
            script.addEventListener('load', () => resolve(), {once: true});
            script.addEventListener('error', () => reject(new Error('Chart.js 加载失败。')), {once: true});
            document.head.appendChild(script);
        }).catch((error) => {
            chartLoader = undefined;
            throw error;
        });
        return chartLoader;
    };

    /**
     * 将 #rrggbb 转为带透明度的 rgba 字符串。
     *
     * @param {string} hex 十六进制色
     * @param {number} alpha 透明度 0-1
     * @returns {string}
     */
    const hexToRgba = (hex, alpha) => {
        const raw = String(hex || '').trim();
        const normalized = raw.charAt(0) === '#' ? raw.slice(1) : raw;
        if (!/^[0-9a-fA-F]{6}$/.test(normalized)) {
            return `rgba(74, 159, 216, ${alpha})`;
        }
        const r = Number.parseInt(normalized.slice(0, 2), 16);
        const g = Number.parseInt(normalized.slice(2, 4), 16);
        const b = Number.parseInt(normalized.slice(4, 6), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    };

    /**
     * 读取设计令牌颜色。
     *
     * @returns {{accent:string,success:string,muted:string,border:string,text:string,surface:string}}
     */
    const readThemeColors = () => {
        const styles = window.getComputedStyle(document.documentElement);
        const read = (name, fallback) => {
            const value = styles.getPropertyValue(name).trim();
            return value || fallback;
        };
        return {
            accent: read('--nova-accent', '#4a9fd8'),
            success: read('--nova-success', '#17855b'),
            muted: read('--nova-text-muted', '#64748b'),
            border: read('--nova-border', '#e2e8f0'),
            text: read('--nova-admin-text', '#1e293b'),
            surface: read('--nova-surface-primary', '#ffffff'),
        };
    };

    /**
     * 创建垂直方向的区域填充渐变。
     *
     * @param {CanvasRenderingContext2D} ctx 画布上下文
     * @param {number} chartAreaTop 绘图区顶部
     * @param {number} chartAreaBottom 绘图区底部
     * @param {string} color 主色
     * @returns {CanvasGradient|string}
     */
    const createAreaGradient = (ctx, chartAreaTop, chartAreaBottom, color) => {
        if (!Number.isFinite(chartAreaTop) || !Number.isFinite(chartAreaBottom) || chartAreaBottom <= chartAreaTop) {
            return hexToRgba(color, 0.12);
        }
        const gradient = ctx.createLinearGradient(0, chartAreaTop, 0, chartAreaBottom);
        gradient.addColorStop(0, hexToRgba(color, 0.22));
        gradient.addColorStop(0.55, hexToRgba(color, 0.08));
        gradient.addColorStop(1, hexToRgba(color, 0));
        return gradient;
    };

    /**
     * 悬停时绘制竖直参考线，便于对齐日期读数。
     */
    const crosshairPlugin = {
        id: 'novaTrendCrosshair',
        afterDraw(chart) {
            const active = chart.tooltip?.getActiveElements?.() || [];
            if (active.length === 0) {
                return;
            }
            const x = active[0].element?.x;
            const yAxis = chart.scales?.y;
            if (!Number.isFinite(x) || !yAxis) {
                return;
            }
            const {ctx} = chart;
            ctx.save();
            ctx.beginPath();
            ctx.moveTo(x, yAxis.top);
            ctx.lineTo(x, yAxis.bottom);
            ctx.lineWidth = 1;
            ctx.strokeStyle = 'rgba(15, 23, 42, 0.10)';
            ctx.setLineDash([4, 4]);
            ctx.stroke();
            ctx.restore();
        },
    };

    /**
     * 更新面板上的 HTML 图例色点，与主题色保持一致。
     *
     * @param {ParentNode} root 页面内容根
     * @param {{accent:string,success:string}} colors 主题色
     * @returns {void}
     */
    const syncLegendSwatches = (root, colors) => {
        root.querySelectorAll('[data-trend-swatch="visits"]').forEach((node) => {
            if (node instanceof HTMLElement) {
                node.style.background = colors.accent;
                node.style.boxShadow = `0 0 0 3px ${hexToRgba(colors.accent, 0.16)}`;
            }
        });
        root.querySelectorAll('[data-trend-swatch="downloads"]').forEach((node) => {
            if (node instanceof HTMLElement) {
                node.style.background = colors.success;
                node.style.boxShadow = `0 0 0 3px ${hexToRgba(colors.success, 0.16)}`;
            }
        });
    };

    /**
     * 销毁当前图表，释放 Canvas 监听器和响应式资源。
     *
     * @returns {void}
     */
    const unmount = () => {
        if (trendChart) {
            trendChart.destroy();
            trendChart = undefined;
        }
    };

    /**
     * 在指定页面内容中创建趋势图。
     *
     * @param {ParentNode} root 页面内容根节点
     * @returns {Promise<void>}
     */
    const mount = async (root = document) => {
        unmount();
        const canvas = root.querySelector('#trendChart');
        const dataElement = root.querySelector('#trendData');
        if (!(canvas instanceof HTMLCanvasElement) || !(dataElement instanceof HTMLScriptElement)) {
            return;
        }

        let trend;
        try {
            trend = JSON.parse(dataElement.textContent || '{}');
        } catch (error) {
            return;
        }

        await loadChartLibrary();
        if (!canvas.isConnected || typeof window.Chart !== 'function') {
            return;
        }

        const labels = Array.isArray(trend.labels) ? trend.labels : [];
        const visits = Array.isArray(trend.visits) ? trend.visits : [];
        const downloads = Array.isArray(trend.downloads) ? trend.downloads : [];
        const colors = readThemeColors();
        syncLegendSwatches(root, colors);

        const context = canvas.getContext('2d');
        if (!context) {
            return;
        }

        trendChart = new window.Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: '访问',
                        data: visits,
                        borderColor: colors.accent,
                        backgroundColor: (contextArg) => {
                            const chart = contextArg.chart;
                            const {ctx, chartArea} = chart;
                            if (!chartArea) {
                                return hexToRgba(colors.accent, 0.12);
                            }
                            return createAreaGradient(ctx, chartArea.top, chartArea.bottom, colors.accent);
                        },
                        borderWidth: 2.5,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointBackgroundColor: colors.surface,
                        pointBorderColor: colors.accent,
                        pointBorderWidth: 2,
                        pointHoverBackgroundColor: colors.surface,
                        pointHoverBorderColor: colors.accent,
                        pointHoverBorderWidth: 2,
                        fill: true,
                        tension: 0.36,
                        cubicInterpolationMode: 'monotone',
                    },
                    {
                        label: '下载',
                        data: downloads,
                        borderColor: colors.success,
                        backgroundColor: (contextArg) => {
                            const chart = contextArg.chart;
                            const {ctx, chartArea} = chart;
                            if (!chartArea) {
                                return hexToRgba(colors.success, 0.10);
                            }
                            return createAreaGradient(ctx, chartArea.top, chartArea.bottom, colors.success);
                        },
                        borderWidth: 2.5,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointBackgroundColor: colors.surface,
                        pointBorderColor: colors.success,
                        pointBorderWidth: 2,
                        pointHoverBackgroundColor: colors.surface,
                        pointHoverBorderColor: colors.success,
                        pointHoverBorderWidth: 2,
                        fill: true,
                        tension: 0.36,
                        cubicInterpolationMode: 'monotone',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 520,
                    easing: 'easeOutQuart',
                },
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                layout: {
                    padding: {
                        top: 8,
                        right: 4,
                        bottom: 0,
                        left: 0,
                    },
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: colors.surface,
                        titleColor: colors.text,
                        bodyColor: colors.text,
                        borderColor: colors.border,
                        borderWidth: 1,
                        cornerRadius: 10,
                        padding: 12,
                        boxPadding: 6,
                        usePointStyle: true,
                        displayColors: true,
                        titleFont: {
                            family: 'IBM Plex Mono, Cascadia Mono, Consolas, monospace',
                            size: 11,
                            weight: '600',
                        },
                        bodyFont: {
                            family: 'Geist, Microsoft YaHei, sans-serif',
                            size: 12,
                            weight: '500',
                        },
                        titleMarginBottom: 8,
                        caretSize: 6,
                        caretPadding: 8,
                        boxWidth: 8,
                        boxHeight: 8,
                        callbacks: {
                            title(items) {
                                const label = items[0]?.label || '';
                                return label ? `${label}` : '';
                            },
                            label(context) {
                                const value = context.parsed?.y;
                                const amount = Number.isFinite(value) ? value : 0;
                                return ` ${context.dataset.label}  ${amount}`;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                            drawBorder: false,
                        },
                        border: {
                            display: false,
                        },
                        ticks: {
                            color: colors.muted,
                            font: {
                                family: 'IBM Plex Mono, Cascadia Mono, Consolas, monospace',
                                size: 11,
                                weight: '500',
                            },
                            padding: 10,
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 7,
                        },
                    },
                    y: {
                        beginAtZero: true,
                        grace: '8%',
                        border: {
                            display: false,
                        },
                        grid: {
                            color: 'rgba(148, 163, 184, 0.18)',
                            drawTicks: false,
                            lineWidth: 1,
                        },
                        ticks: {
                            precision: 0,
                            color: colors.muted,
                            padding: 10,
                            font: {
                                family: 'IBM Plex Mono, Cascadia Mono, Consolas, monospace',
                                size: 11,
                                weight: '500',
                            },
                            // 避免大量刻度挤在一起；Chart 会按数据范围自动选择。
                            maxTicksLimit: 6,
                        },
                    },
                },
            },
            plugins: [crosshairPlugin],
        });
    };

    window.NovaAdminDashboard = {mount, unmount};
})();
