/**
 * 前台应用商店风格预览画廊、PhotoSwipe 放大预览、顶栏粘性下载与区块入场显现。
 *
 * 副作用：横向滚动吸附切换截图；多图时首尾克隆实现 1→2→3→1… 无缝轮询；
 * 自动播放、圆点、箭头、方向键；点击截图通过 PhotoSwipe 放大，灯箱切图同步画廊。
 * 用户交互、悬停、焦点、灯箱打开或页面不可见时暂停自动播放；
 * 系统开启「减少动态效果」时不自动播放。
 * 主下载按钮滚出视口后显示顶栏下载，避免首屏双按钮冗余。
 * 视口外特性卡片等通过 IntersectionObserver 添加 is-visible 类触发入场。
 */
(() => {
    'use strict';

    /** 默认自动切换间隔（毫秒）。 */
    const DEFAULT_AUTO_INTERVAL_MS = 4500;

    /** 自动播放最短间隔，避免配置过小导致闪烁。 */
    const MIN_AUTO_INTERVAL_MS = 1500;

    /** 滚动结束后判定居中项的防抖时长（毫秒）。 */
    const SCROLL_SETTLE_MS = 80;

    /** 点击与拖拽区分阈值（像素）。 */
    const CLICK_MOVE_THRESHOLD_PX = 8;

    /** 无法读取真实尺寸时的回退宽高（竖屏应用截图常见比例）。 */
    const FALLBACK_IMAGE_WIDTH = 1170;
    const FALLBACK_IMAGE_HEIGHT = 2532;

    /** PhotoSwipe 静态资源路径。 */
    const PHOTOSWIPE_LIGHTBOX_MODULE = '/static/vendor/photoswipe/photoswipe-lightbox.esm.min.js';
    const PHOTOSWIPE_CORE_MODULE = '/static/vendor/photoswipe/photoswipe.esm.min.js';

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /** @type {Promise<{PhotoSwipeLightbox: Function}>|null} */
    let photoSwipeLoader = null;

    /**
     * 按需加载 PhotoSwipe Lightbox 模块（核心由 pswpModule 懒加载）。
     *
     * @returns {Promise<{PhotoSwipeLightbox: Function}>}
     */
    const loadPhotoSwipeLightbox = () => {
        if (photoSwipeLoader) {
            return photoSwipeLoader;
        }

        photoSwipeLoader = import(PHOTOSWIPE_LIGHTBOX_MODULE)
            .then((module) => {
                const PhotoSwipeLightbox = module.default;
                if (typeof PhotoSwipeLightbox !== 'function') {
                    throw new Error('PhotoSwipe Lightbox 模块导出无效。');
                }
                return {PhotoSwipeLightbox};
            })
            .catch((error) => {
                photoSwipeLoader = null;
                throw error;
            });

        return photoSwipeLoader;
    };

    /**
     * 读取图片自然尺寸；失败时回退到约定竖屏尺寸。
     *
     * @param {string} src 图片地址
     * @param {HTMLImageElement|null} [existingImage] 已存在的 img，可复用 natural 尺寸
     * @returns {Promise<{width: number, height: number}>}
     */
    const resolveImageSize = (src, existingImage = null) => {
        if (existingImage instanceof HTMLImageElement
            && existingImage.complete
            && existingImage.naturalWidth > 0
            && existingImage.naturalHeight > 0) {
            return Promise.resolve({
                width: existingImage.naturalWidth,
                height: existingImage.naturalHeight,
            });
        }

        return new Promise((resolve) => {
            if (!src) {
                resolve({width: FALLBACK_IMAGE_WIDTH, height: FALLBACK_IMAGE_HEIGHT});
                return;
            }

            const probe = new Image();
            let settled = false;

            const finish = (width, height) => {
                if (settled) {
                    return;
                }
                settled = true;
                resolve({
                    width: width > 0 ? width : FALLBACK_IMAGE_WIDTH,
                    height: height > 0 ? height : FALLBACK_IMAGE_HEIGHT,
                });
            };

            probe.onload = () => {
                finish(probe.naturalWidth, probe.naturalHeight);
            };
            probe.onerror = () => {
                finish(FALLBACK_IMAGE_WIDTH, FALLBACK_IMAGE_HEIGHT);
            };
            probe.src = src;

            // 缓存命中且已完成时，部分浏览器不会再触发 onload。
            if (probe.complete && probe.naturalWidth > 0) {
                finish(probe.naturalWidth, probe.naturalHeight);
            }
        });
    };

    /**
     * 为画廊构建 PhotoSwipe dataSource，并尽量补齐宽高。
     *
     * @param {HTMLImageElement[]} images 预览图片节点
     * @param {HTMLElement[]} shots 截图卡片节点（提供标题回退）
     * @returns {Promise<Array<{src: string, width: number, height: number, alt: string, title: string}>>}
     */
    const buildDataSource = async (images, shots) => {
        const items = await Promise.all(images.map(async (image, index) => {
            const shot = shots[index];
            const src = image.dataset.lightboxSrc || image.currentSrc || image.src || '';
            const title = image.dataset.lightboxTitle
                || image.alt
                || shot?.dataset.title
                || '';
            const size = await resolveImageSize(src, image);

            return {
                src,
                width: size.width,
                height: size.height,
                alt: title || '预览图片',
                title: title || '',
            };
        }));

        return items.filter((item) => Boolean(item.src));
    };

    /**
     * 注册 PhotoSwipe 底部标题元素，并在切图时更新文案。
     *
     * @param {{pswp: object|null, on: Function}} lightbox PhotoSwipeLightbox 实例
     * @returns {void}
     */
    const registerCaptionUi = (lightbox) => {
        lightbox.on('uiRegister', () => {
            if (!lightbox.pswp?.ui?.registerElement) {
                return;
            }

            lightbox.pswp.ui.registerElement({
                name: 'custom-caption',
                order: 9,
                isButton: false,
                appendTo: 'root',
                onInit: (element, pswp) => {
                    element.className = 'pswp__custom-caption';

                    const updateCaption = () => {
                        const title = pswp.currSlide?.data?.title
                            || pswp.currSlide?.data?.alt
                            || '';
                        element.textContent = title;
                        element.hidden = title === '';
                    };

                    pswp.on('change', updateCaption);
                    updateCaption();
                },
            });
        });
    };

    document.querySelectorAll('[data-carousel]').forEach((carousel) => {
        const shots = Array.from(carousel.querySelectorAll('.preview-shot[data-slide-index]'))
            .filter((node) => node instanceof HTMLElement);
        const images = shots
            .map((shot) => shot.querySelector('.preview-slide'))
            .filter((node) => node instanceof HTMLImageElement);
        const dots = Array.from(carousel.querySelectorAll('[data-slide]'))
            .filter((node) => node instanceof HTMLElement);
        const title = carousel.querySelector('[data-preview-title]');
        const scroller = carousel.querySelector('[data-preview-scroller]');
        const track = carousel.querySelector('[data-preview-track]');
        const prevButton = carousel.querySelector('[data-preview-prev]');
        const nextButton = carousel.querySelector('[data-preview-next]');
        const dotsWrap = carousel.querySelector('[data-preview-dots]');

        if (shots.length === 0 || images.length !== shots.length) {
            return;
        }

        // 单张时隐藏圆点；多张时再校验圆点数量一致。
        if (shots.length === 1) {
            if (dotsWrap instanceof HTMLElement) {
                dotsWrap.classList.add('is-single');
                dotsWrap.hidden = true;
            }
        } else if (dots.length !== shots.length) {
            return;
        }

        const slideCount = shots.length;
        const canNavigate = slideCount > 1;

        /**
         * 多图时在轨道首尾各放一张克隆，实现 1→2→3→1 正向无缝循环，
         * 以及 1→3 反向无缝循环；逻辑索引仍映射到真实截图 0..n-1。
         *
         * @type {HTMLElement|null}
         */
        let startClone = null;
        /** @type {HTMLElement|null} */
        let endClone = null;

        if (canNavigate && track instanceof HTMLElement) {
            const firstShot = shots[0];
            const lastShot = shots[slideCount - 1];

            startClone = /** @type {HTMLElement} */ (lastShot.cloneNode(true));
            startClone.classList.remove('is-active', 'is-near');
            startClone.removeAttribute('data-slide-index');
            startClone.dataset.previewClone = 'start';
            startClone.setAttribute('aria-hidden', 'true');
            const startImage = startClone.querySelector('.preview-slide');
            if (startImage instanceof HTMLImageElement) {
                startImage.removeAttribute('data-lightbox-src');
                startImage.removeAttribute('data-lightbox-title');
                startImage.loading = 'lazy';
                startImage.alt = '';
            }

            endClone = /** @type {HTMLElement} */ (firstShot.cloneNode(true));
            endClone.classList.remove('is-active', 'is-near');
            endClone.removeAttribute('data-slide-index');
            endClone.dataset.previewClone = 'end';
            endClone.setAttribute('aria-hidden', 'true');
            const endImage = endClone.querySelector('.preview-slide');
            if (endImage instanceof HTMLImageElement) {
                endImage.removeAttribute('data-lightbox-src');
                endImage.removeAttribute('data-lightbox-title');
                endImage.loading = 'lazy';
                endImage.alt = '';
            }

            track.insertBefore(startClone, firstShot);
            track.appendChild(endClone);
        }

        let activeIndex = 0;
        let autoTimerId = null;
        let autoplayPaused = false;
        let lightboxOpen = false;
        let openingLightbox = false;
        let shouldRestoreScrollerFocusAfterLightbox = false;
        let isProgrammaticScroll = false;
        /** 无缝循环动画结束后，用于跳回真实节点的定时器。 */
        /** @type {number|null} */
        let loopJumpTimerId = null;
        /** @type {number|null} */
        let scrollSettleTimerId = null;
        /** @type {number|null} */
        let titleSwitchTimerId = null;
        /** @type {object|null} */
        let activePhotoSwipeLightbox = null;

        /** 指针拖拽状态：用于区分点击打开与拖动滚动。 */
        let pointerTracking = false;
        let pointerMoved = false;
        let pointerStartX = 0;
        let pointerStartY = 0;
        let pointerStartScrollLeft = 0;
        /** @type {number|null} */
        let activePointerId = null;
        /**
         * 按下时命中的截图节点。
         * 说明：setPointerCapture 后 pointerup 的 event.target 会变成 scroller，
         * 必须在 pointerdown 时缓存真实截图，否则桌面点击无法打开灯箱。
         *
         * @type {HTMLElement|null}
         */
        let pointerTargetShot = null;
        /** 抑制 pointer 流程后紧随的 click，避免桌面双开灯箱。 */
        let suppressClickUntil = 0;
        /** 触控按下时的滚动位置，用于判断是否发生了滑动。 */
        let touchStartScrollLeft = 0;
        let touchStartX = 0;

        const configuredInterval = Number.parseInt(
            carousel.getAttribute('data-autoplay-interval') || '',
            10
        );
        const autoIntervalMs = Number.isFinite(configuredInterval) && configuredInterval >= MIN_AUTO_INTERVAL_MS
            ? configuredInterval
            : DEFAULT_AUTO_INTERVAL_MS;
        const canAutoplay = canNavigate && !prefersReducedMotion;

        carousel.style.setProperty('--autoplay-ms', `${autoIntervalMs}ms`);

        if (prevButton instanceof HTMLButtonElement) {
            prevButton.hidden = !canNavigate;
        }
        if (nextButton instanceof HTMLButtonElement) {
            nextButton.hidden = !canNavigate;
        }

        /**
         * 同步预览标题，并在允许动效时做轻微淡入切换。
         *
         * @param {string} nextTitle 下一帧标题
         * @returns {void}
         */
        const updateTitles = (nextTitle) => {
            if (!(title instanceof HTMLElement)) {
                return;
            }

            const applyText = () => {
                title.textContent = nextTitle;
                title.classList.remove('is-switching');
            };

            if (prefersReducedMotion || title.textContent === nextTitle) {
                applyText();
                return;
            }

            title.classList.add('is-switching');
            if (titleSwitchTimerId !== null) {
                window.clearTimeout(titleSwitchTimerId);
            }
            titleSwitchTimerId = window.setTimeout(() => {
                applyText();
                titleSwitchTimerId = null;
            }, 140);
        };

        /**
         * 刷新圆点进度动画：仅在自动播放进行中给当前点加上进度条。
         *
         * @param {boolean} withProgress 是否展示进度填充
         * @returns {void}
         */
        const refreshDotProgress = (withProgress) => {
            dots.forEach((dot, dotIndex) => {
                const active = dotIndex === activeIndex;
                dot.classList.toggle('is-active', active);
                dot.classList.remove('is-progressing');
                if (active) {
                    dot.setAttribute('aria-current', 'true');
                } else {
                    dot.removeAttribute('aria-current');
                }

                if (active && withProgress && canAutoplay) {
                    void dot.offsetWidth;
                    dot.classList.add('is-progressing');
                }
            });
        };

        /**
         * 更新截图卡片激活 / 邻近态；克隆节点跟随对应逻辑索引。
         *
         * @param {number} index 当前逻辑索引
         * @returns {void}
         */
        const updateShotStates = (index) => {
            shots.forEach((shot, shotIndex) => {
                const distance = Math.abs(shotIndex - index);
                shot.classList.toggle('is-active', shotIndex === index);
                shot.classList.toggle('is-near', distance === 1);
            });

            if (startClone instanceof HTMLElement) {
                // 首部克隆对应最后一张，便于反向循环时视觉一致。
                startClone.classList.toggle('is-active', index === slideCount - 1);
                startClone.classList.toggle(
                    'is-near',
                    index === 0 || (slideCount > 2 && index === slideCount - 2)
                );
            }
            if (endClone instanceof HTMLElement) {
                // 尾部克隆对应第一张，便于正向 3→1 无缝衔接。
                endClone.classList.toggle('is-active', index === 0);
                endClone.classList.toggle(
                    'is-near',
                    index === 1 || (slideCount > 2 && index === slideCount - 1)
                );
            }
        };

        /**
         * 将任意截图节点滚到滚动容器中心。
         *
         * @param {HTMLElement} element 目标节点（真实截图或克隆）
         * @param {'auto'|'smooth'} [behavior] 滚动行为
         * @param {{holdProgrammatic?: boolean}} [options] holdProgrammatic 为 true 时由调用方负责解除锁定
         * @returns {void}
         */
        const scrollElementToCenter = (element, behavior = 'smooth', options = {}) => {
            if (!(scroller instanceof HTMLElement) || !(element instanceof HTMLElement)) {
                return;
            }

            const scrollerRect = scroller.getBoundingClientRect();
            const shotRect = element.getBoundingClientRect();
            const shotCenter = shotRect.left + shotRect.width / 2;
            const scrollerCenter = scrollerRect.left + scrollerRect.width / 2;
            const delta = shotCenter - scrollerCenter;
            const nextLeft = scroller.scrollLeft + delta;
            const maxLeft = Math.max(0, scroller.scrollWidth - scroller.clientWidth);
            const clamped = Math.min(Math.max(0, nextLeft), maxLeft);
            const useBehavior = prefersReducedMotion ? 'auto' : behavior;
            const holdProgrammatic = options.holdProgrammatic === true;

            isProgrammaticScroll = true;

            // CSS scroll-behavior:smooth 会覆盖 scrollTo(auto)，瞬时跳转前临时关闭。
            if (useBehavior === 'auto') {
                scroller.style.scrollBehavior = 'auto';
            }

            if (typeof scroller.scrollTo === 'function') {
                scroller.scrollTo({
                    left: clamped,
                    behavior: useBehavior,
                });
            } else {
                scroller.scrollLeft = clamped;
            }

            if (useBehavior === 'auto') {
                window.requestAnimationFrame(() => {
                    scroller.style.scrollBehavior = '';
                    if (!holdProgrammatic) {
                        isProgrammaticScroll = false;
                    }
                });
                return;
            }

            if (!holdProgrammatic) {
                window.setTimeout(() => {
                    isProgrammaticScroll = false;
                }, 420);
            }
        };

        /**
         * 将指定逻辑索引的真实截图滚到中心。
         *
         * @param {number} index 目标索引
         * @param {'auto'|'smooth'} [behavior] 滚动行为
         * @returns {void}
         */
        const scrollShotToCenter = (index, behavior = 'smooth') => {
            const shot = shots[index];
            if (shot instanceof HTMLElement) {
                scrollElementToCenter(shot, behavior);
            }
        };

        /**
         * 取消待执行的无缝循环跳转。
         *
         * @returns {void}
         */
        const clearLoopJump = () => {
            if (loopJumpTimerId !== null) {
                window.clearTimeout(loopJumpTimerId);
                loopJumpTimerId = null;
            }
        };

        /**
         * 平滑滚到克隆节点后，瞬时跳回对应真实截图，完成无缝循环。
         *
         * @param {HTMLElement} cloneElement 首/尾克隆
         * @param {number} realIndex 对应真实索引
         * @returns {void}
         */
        const scrollViaCloneThenJump = (cloneElement, realIndex) => {
            clearLoopJump();
            scrollElementToCenter(cloneElement, 'smooth', {holdProgrammatic: true});

            loopJumpTimerId = window.setTimeout(() => {
                loopJumpTimerId = null;
                const realShot = shots[realIndex];
                if (realShot instanceof HTMLElement) {
                    scrollElementToCenter(realShot, 'auto', {holdProgrammatic: true});
                }
                // 再等一帧，避免瞬时跳转触发的 scroll 事件改写索引。
                window.requestAnimationFrame(() => {
                    isProgrammaticScroll = false;
                });
            }, 420);
        };

        /**
         * 解析截图节点对应的逻辑索引（含克隆映射）。
         *
         * @param {HTMLElement} targetShot 截图或克隆节点
         * @returns {number|null}
         */
        const resolveLogicalIndex = (targetShot) => {
            if (!(targetShot instanceof HTMLElement)) {
                return null;
            }
            if (targetShot.dataset.previewClone === 'end') {
                return 0;
            }
            if (targetShot.dataset.previewClone === 'start') {
                return slideCount - 1;
            }
            const index = Number.parseInt(targetShot.dataset.slideIndex || '', 10);
            return Number.isInteger(index) ? index : null;
        };

        /**
         * 根据滚动位置找到最接近中心的逻辑索引；若落在克隆上同时标记需回跳。
         *
         * @returns {{index: number, onClone: boolean, cloneKind: 'start'|'end'|null}}
         */
        const findClosestShot = () => {
            if (!(scroller instanceof HTMLElement) || slideCount === 0) {
                return {index: 0, onClone: false, cloneKind: null};
            }

            const scrollerRect = scroller.getBoundingClientRect();
            const centerX = scrollerRect.left + scrollerRect.width / 2;
            /** @type {Array<{element: HTMLElement, index: number, onClone: boolean, cloneKind: 'start'|'end'|null}>} */
            const candidates = shots.map((shot, index) => ({
                element: shot,
                index,
                onClone: false,
                cloneKind: null,
            }));

            if (startClone instanceof HTMLElement) {
                candidates.push({
                    element: startClone,
                    index: slideCount - 1,
                    onClone: true,
                    cloneKind: 'start',
                });
            }
            if (endClone instanceof HTMLElement) {
                candidates.push({
                    element: endClone,
                    index: 0,
                    onClone: true,
                    cloneKind: 'end',
                });
            }

            let best = candidates[0];
            let bestDistance = Number.POSITIVE_INFINITY;

            candidates.forEach((candidate) => {
                const rect = candidate.element.getBoundingClientRect();
                const shotCenter = rect.left + rect.width / 2;
                const distance = Math.abs(shotCenter - centerX);
                if (distance < bestDistance) {
                    bestDistance = distance;
                    best = candidate;
                }
            });

            return {
                index: best.index,
                onClone: best.onClone,
                cloneKind: best.cloneKind,
            };
        };

        /**
         * 根据滚动位置找到最接近中心的逻辑索引。
         *
         * @returns {number}
         */
        const findClosestShotIndex = () => findClosestShot().index;

        /**
         * 切换到指定索引的预览图；相邻跨边界时走克隆无缝路径。
         *
         * @param {number} requestedIndex 目标索引（可越界，内部取模）
         * @param {{animate?: boolean, syncScroll?: boolean, force?: boolean}} [options] 切换选项
         * @returns {void}
         */
        const show = (requestedIndex, options = {}) => {
            const fromIndex = activeIndex;
            const index = ((requestedIndex % slideCount) + slideCount) % slideCount;
            const animate = options.animate !== false && !prefersReducedMotion;
            const syncScroll = options.syncScroll !== false;

            if (index !== activeIndex || options.force === true) {
                activeIndex = index;
                updateShotStates(index);
                updateTitles(
                    shots[index].dataset.title
                        || images[index].dataset.lightboxTitle
                        || images[index].alt
                        || ''
                );
                refreshDotProgress(canAutoplay && !autoplayPaused && !lightboxOpen && !document.hidden);
            }

            if (!syncScroll) {
                return;
            }

            // 相邻步进且跨越首尾：经克隆平滑滚动，再瞬时回到真实节点 → 视觉上 123,123… 正向轮询。
            const steppedForward = requestedIndex === fromIndex + 1;
            const steppedBackward = requestedIndex === fromIndex - 1;
            const wrapForward = canNavigate
                && animate
                && steppedForward
                && fromIndex === slideCount - 1
                && index === 0
                && endClone instanceof HTMLElement;
            const wrapBackward = canNavigate
                && animate
                && steppedBackward
                && fromIndex === 0
                && index === slideCount - 1
                && startClone instanceof HTMLElement;

            if (wrapForward) {
                scrollViaCloneThenJump(endClone, 0);
                return;
            }
            if (wrapBackward) {
                scrollViaCloneThenJump(startClone, slideCount - 1);
                return;
            }

            clearLoopJump();
            scrollShotToCenter(index, animate ? 'smooth' : 'auto');
        };

        /**
         * 停止自动轮询计时器。
         *
         * @returns {void}
         */
        const stopAutoplay = () => {
            if (autoTimerId === null) {
                return;
            }
            window.clearInterval(autoTimerId);
            autoTimerId = null;
            carousel.classList.add('is-autoplay-paused');
            dots.forEach((dot) => {
                dot.classList.remove('is-progressing');
            });
        };

        /**
         * 在允许自动播放时启动或重启轮询。
         *
         * @returns {void}
         */
        const startAutoplay = () => {
            stopAutoplay();
            if (!canAutoplay || autoplayPaused || lightboxOpen || document.hidden) {
                carousel.classList.add('is-autoplay-paused');
                return;
            }

            carousel.classList.remove('is-autoplay-paused');
            refreshDotProgress(true);

            autoTimerId = window.setInterval(() => {
                show(activeIndex + 1, {animate: true, syncScroll: true});
            }, autoIntervalMs);
        };

        /**
         * 用户手动切换后重置轮询节奏。
         *
         * @param {number} requestedIndex 目标索引
         * @returns {void}
         */
        const showFromUser = (requestedIndex) => {
            show(requestedIndex, {animate: true, syncScroll: true});
            if (!autoplayPaused && !lightboxOpen) {
                startAutoplay();
            }
        };

        /**
         * 根据交互暂停或恢复自动播放。
         *
         * @param {boolean} paused 是否暂停
         * @returns {void}
         */
        const setAutoplayPaused = (paused) => {
            autoplayPaused = paused;
            carousel.classList.toggle('is-autoplay-paused', paused || lightboxOpen || document.hidden);
            if (paused || lightboxOpen) {
                stopAutoplay();
                refreshDotProgress(false);
                return;
            }
            startAutoplay();
        };

        /**
         * 关闭 PhotoSwipe 后恢复画廊与焦点。
         *
         * @returns {void}
         */
        const handleLightboxClosed = () => {
            lightboxOpen = false;
            openingLightbox = false;
            activePhotoSwipeLightbox = null;

            const shouldRestoreFocus = shouldRestoreScrollerFocusAfterLightbox;
            shouldRestoreScrollerFocusAfterLightbox = false;
            if (shouldRestoreFocus && scroller instanceof HTMLElement) {
                scroller.focus({preventScroll: true});
            }

            const stillInteracting = carousel.matches(':hover')
                || carousel.contains(document.activeElement);
            autoplayPaused = stillInteracting;
            if (stillInteracting) {
                stopAutoplay();
                return;
            }
            startAutoplay();
        };

        /**
         * 打开当前页的 PhotoSwipe 放大预览。
         *
         * @param {boolean} restoreFocusAfterClose 是否在关闭后恢复滚动容器焦点
         * @returns {Promise<void>}
         */
        const openActiveLightbox = async (restoreFocusAfterClose = false) => {
            if (lightboxOpen || openingLightbox) {
                return;
            }

            openingLightbox = true;
            shouldRestoreScrollerFocusAfterLightbox = false;
            stopAutoplay();

            try {
                const dataSource = await buildDataSource(images, shots);
                if (dataSource.length === 0) {
                    openingLightbox = false;
                    startAutoplay();
                    return;
                }

                const startIndex = Math.min(Math.max(activeIndex, 0), dataSource.length - 1);
                const {PhotoSwipeLightbox} = await loadPhotoSwipeLightbox();

                if (activePhotoSwipeLightbox && typeof activePhotoSwipeLightbox.destroy === 'function') {
                    activePhotoSwipeLightbox.destroy();
                }

                const lightbox = new PhotoSwipeLightbox({
                    dataSource,
                    pswpModule: () => import(PHOTOSWIPE_CORE_MODULE),
                    index: startIndex,
                    // 多图时放大预览也支持首尾循环，与画廊 123,123… 一致。
                    loop: dataSource.length > 1,
                    showHideAnimationType: prefersReducedMotion ? 'none' : 'fade',
                    bgOpacity: 0.86,
                    spacing: 0.08,
                    padding: {top: 24, bottom: 56, left: 16, right: 16},
                    wheelToZoom: false,
                });

                registerCaptionUi(lightbox);

                lightbox.on('change', () => {
                    const nextIndex = lightbox.pswp?.currIndex;
                    if (Number.isInteger(nextIndex)) {
                        show(nextIndex, {animate: false, syncScroll: true});
                    }
                });

                lightbox.on('close', () => {
                    handleLightboxClosed();
                });

                lightbox.on('destroy', () => {
                    if (activePhotoSwipeLightbox === lightbox) {
                        activePhotoSwipeLightbox = null;
                    }
                });

                lightbox.init();
                activePhotoSwipeLightbox = lightbox;
                lightboxOpen = true;
                shouldRestoreScrollerFocusAfterLightbox = restoreFocusAfterClose;
                lightbox.loadAndOpen(startIndex);
            } catch (error) {
                openingLightbox = false;
                lightboxOpen = false;
                activePhotoSwipeLightbox = null;
                shouldRestoreScrollerFocusAfterLightbox = false;
                console.error('打开预览放大失败：', error);
                startAutoplay();
            } finally {
                openingLightbox = false;
            }
        };

        /**
         * 滚动停止后同步激活项；若停在克隆上则瞬时跳回真实截图。
         *
         * @returns {void}
         */
        const settleActiveFromScroll = () => {
            if (isProgrammaticScroll) {
                return;
            }
            const closest = findClosestShot();
            if (closest.onClone) {
                // 用户拖到首/尾克隆：跳回对应真实节点，保持无限轮询轨道位置正确。
                activeIndex = closest.index;
                updateShotStates(closest.index);
                updateTitles(
                    shots[closest.index].dataset.title
                        || images[closest.index].dataset.lightboxTitle
                        || images[closest.index].alt
                        || ''
                );
                refreshDotProgress(false);
                scrollShotToCenter(closest.index, 'auto');
                return;
            }
            if (closest.index === activeIndex) {
                updateShotStates(closest.index);
                return;
            }
            show(closest.index, {animate: false, syncScroll: false});
        };

        dots.forEach((dot) => {
            dot.addEventListener('click', () => {
                const index = Number.parseInt(dot.dataset.slide || '', 10);
                if (Number.isInteger(index)) {
                    showFromUser(index);
                }
            });
        });

        if (prevButton instanceof HTMLButtonElement) {
            prevButton.addEventListener('click', () => {
                showFromUser(activeIndex - 1);
            });
        }
        if (nextButton instanceof HTMLButtonElement) {
            nextButton.addEventListener('click', () => {
                showFromUser(activeIndex + 1);
            });
        }

        if (scroller instanceof HTMLElement) {
            scroller.addEventListener('scroll', () => {
                if (scrollSettleTimerId !== null) {
                    window.clearTimeout(scrollSettleTimerId);
                }
                // 滚动过程中先弱更新邻近态，停稳后再定激活项。
                const liveIndex = findClosestShotIndex();
                updateShotStates(liveIndex);
                scrollSettleTimerId = window.setTimeout(() => {
                    scrollSettleTimerId = null;
                    settleActiveFromScroll();
                }, SCROLL_SETTLE_MS);
            }, {passive: true});

            scroller.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    showFromUser(activeIndex + 1);
                } else if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    showFromUser(activeIndex - 1);
                } else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    void openActiveLightbox(true);
                }
            });

            /**
             * 从事件或坐标解析命中的截图卡片。
             *
             * @param {Event} event 指针/点击事件
             * @param {number} [clientX] 可选客户端 X
             * @param {number} [clientY] 可选客户端 Y
             * @returns {HTMLElement|null}
             */
            const resolveShotFromEvent = (event, clientX, clientY) => {
                if (event.target instanceof Element) {
                    const fromTarget = event.target.closest('.preview-shot');
                    if (fromTarget instanceof HTMLElement) {
                        return fromTarget;
                    }
                }

                // pointer capture 后 target 可能是 scroller，改用坐标命中。
                if (Number.isFinite(clientX) && Number.isFinite(clientY)) {
                    const hit = document.elementFromPoint(clientX, clientY);
                    if (hit instanceof Element) {
                        const fromPoint = hit.closest('.preview-shot');
                        if (fromPoint instanceof HTMLElement && scroller.contains(fromPoint)) {
                            return fromPoint;
                        }
                    }
                }

                return null;
            };

            /**
             * 处理截图卡片激活或打开灯箱（克隆映射到对应真实索引）。
             *
             * @param {HTMLElement} targetShot 目标截图卡片
             * @returns {void}
             */
            const activateOrOpenPhone = (targetShot) => {
                const index = resolveLogicalIndex(targetShot);
                if (index === null) {
                    return;
                }
                // 点到非当前项或克隆：先切到对应真实图；当前项：打开 PhotoSwipe。
                if (index !== activeIndex || Boolean(targetShot.dataset.previewClone)) {
                    showFromUser(index);
                    return;
                }
                void openActiveLightbox(false);
            };

            // 桌面端支持按住拖拽横向滚动；移动端保留原生惯性滑动。
            scroller.addEventListener('pointerdown', (event) => {
                const hitShot = resolveShotFromEvent(event, event.clientX, event.clientY);
                pointerTargetShot = hitShot;

                if (event.pointerType === 'touch') {
                    touchStartX = event.clientX;
                    touchStartScrollLeft = scroller.scrollLeft;
                    setAutoplayPaused(true);
                    return;
                }
                if (event.button !== 0) {
                    return;
                }

                pointerTracking = true;
                pointerMoved = false;
                pointerStartX = event.clientX;
                pointerStartY = event.clientY;
                pointerStartScrollLeft = scroller.scrollLeft;
                activePointerId = event.pointerId;
                setAutoplayPaused(true);

                try {
                    scroller.setPointerCapture(event.pointerId);
                } catch (error) {
                    // 部分浏览器对非主指针不支持捕获，忽略即可。
                }
            });

            scroller.addEventListener('pointermove', (event) => {
                if (!pointerTracking || activePointerId !== event.pointerId) {
                    return;
                }

                const deltaX = event.clientX - pointerStartX;
                const deltaY = event.clientY - pointerStartY;
                if (!pointerMoved
                    && (Math.abs(deltaX) > CLICK_MOVE_THRESHOLD_PX
                        || Math.abs(deltaY) > CLICK_MOVE_THRESHOLD_PX)) {
                    pointerMoved = true;
                }

                if (pointerMoved && Math.abs(deltaX) >= Math.abs(deltaY)) {
                    scroller.scrollLeft = pointerStartScrollLeft - deltaX;
                    event.preventDefault();
                }
            });

            const endPointerGesture = (event) => {
                if (event.pointerType === 'touch') {
                    const scrolled = Math.abs(scroller.scrollLeft - touchStartScrollLeft) > CLICK_MOVE_THRESHOLD_PX;
                    const moved = Math.abs(event.clientX - touchStartX) > CLICK_MOVE_THRESHOLD_PX;
                    const targetShot = pointerTargetShot
                        || resolveShotFromEvent(event, event.clientX, event.clientY);
                    pointerTargetShot = null;

                    if (!scrolled && !moved && targetShot instanceof HTMLElement) {
                        // 仅在已处理激活时抑制后续 click，避免吞掉未处理的点击。
                        suppressClickUntil = Date.now() + 400;
                        activateOrOpenPhone(targetShot);
                        return;
                    }
                    if (!lightboxOpen) {
                        setAutoplayPaused(false);
                    }
                    return;
                }

                if (!pointerTracking || (activePointerId !== null && event.pointerId !== activePointerId)) {
                    return;
                }

                const wasClick = !pointerMoved;
                // 优先使用 pointerdown 缓存的截图；capture 后 event.target 往往是 scroller。
                const targetShot = pointerTargetShot
                    || resolveShotFromEvent(event, event.clientX, event.clientY);

                pointerTracking = false;
                activePointerId = null;
                pointerTargetShot = null;

                if (wasClick && targetShot instanceof HTMLElement) {
                    suppressClickUntil = Date.now() + 400;
                    activateOrOpenPhone(targetShot);
                    return;
                }

                if (!lightboxOpen) {
                    setAutoplayPaused(false);
                }
            };

            scroller.addEventListener('pointerup', endPointerGesture);
            scroller.addEventListener('pointercancel', () => {
                pointerTracking = false;
                activePointerId = null;
                pointerTargetShot = null;
                if (!lightboxOpen) {
                    setAutoplayPaused(false);
                }
            });

            // 兜底 click：pointer 流程未处理（如未触发 capture）时仍可打开。
            scroller.addEventListener('click', (event) => {
                if (Date.now() < suppressClickUntil) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }
                const targetShot = resolveShotFromEvent(event, event.clientX, event.clientY);
                if (!(targetShot instanceof HTMLElement)) {
                    return;
                }
                event.preventDefault();
                activateOrOpenPhone(targetShot);
            });
        }

        carousel.addEventListener('mouseenter', () => {
            setAutoplayPaused(true);
        });
        carousel.addEventListener('mouseleave', () => {
            if (lightboxOpen || carousel.contains(document.activeElement)) {
                return;
            }
            setAutoplayPaused(false);
        });
        carousel.addEventListener('focusin', () => {
            setAutoplayPaused(true);
        });
        carousel.addEventListener('focusout', (event) => {
            const nextTarget = event.relatedTarget;
            if (lightboxOpen || (nextTarget instanceof Node && carousel.contains(nextTarget))) {
                return;
            }
            setAutoplayPaused(false);
        });

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopAutoplay();
                return;
            }
            if (!lightboxOpen) {
                startAutoplay();
            }
        });

        window.addEventListener('resize', () => {
            scrollShotToCenter(activeIndex, 'auto');
        });

        // 首帧定位到第一项中心，避免左右留白不对称。
        updateShotStates(0);
        updateTitles(
            shots[0].dataset.title
                || images[0].dataset.lightboxTitle
                || images[0].alt
                || ''
        );
        window.requestAnimationFrame(() => {
            scrollShotToCenter(0, 'auto');
            startAutoplay();
        });
    });

    /**
     * 主下载仍在视口内时隐藏顶栏下载；滚过后显示，兼顾首屏简洁与长页可达。
     *
     * @returns {void}
     */
    const initStickyDownload = () => {
        const primaryDownload = document.querySelector('[data-primary-download]');
        const stickyDownload = document.querySelector('[data-sticky-download]');
        if (!(primaryDownload instanceof HTMLElement) || !(stickyDownload instanceof HTMLElement)) {
            return;
        }

        /**
         * @param {boolean} visible 顶栏下载是否可见
         * @returns {void}
         */
        const setStickyVisible = (visible) => {
            if (visible) {
                stickyDownload.hidden = false;
                // 下一帧再加 class，确保从 hidden 切到显示时过渡生效。
                window.requestAnimationFrame(() => {
                    stickyDownload.classList.add('is-visible');
                });
                return;
            }

            stickyDownload.classList.remove('is-visible');
            if (prefersReducedMotion) {
                stickyDownload.hidden = true;
                return;
            }

            window.setTimeout(() => {
                if (!stickyDownload.classList.contains('is-visible')) {
                    stickyDownload.hidden = true;
                }
            }, 240);
        };

        if (typeof IntersectionObserver !== 'function') {
            // 无 IntersectionObserver 时不显示顶栏下载，避免与主按钮长期并存。
            setStickyVisible(false);
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    // 主按钮仍可见（含部分可见）→ 隐藏顶栏；完全离开后显示。
                    setStickyVisible(!entry.isIntersecting);
                });
            },
            {
                root: null,
                // 顶栏高度附近即可切换，避免主按钮刚被挡住仍不出现顶栏 CTA。
                rootMargin: '-64px 0px 0px 0px',
                threshold: 0,
            }
        );

        observer.observe(primaryDownload);
    };

    /**
     * 为首屏外的 reveal 目标启用滚动入场。
     * 首屏已用 CSS animation-delay 编排；此处避免视口外元素在未看到时提前播完。
     *
     * @returns {void}
     */
    const initScrollReveals = () => {
        if (prefersReducedMotion) {
            document.querySelectorAll('.reveal-item, .feature-card, .feature-zone-head').forEach((node) => {
                node.classList.add('is-visible');
            });
            return;
        }

        const hero = document.querySelector('[data-hero]');
        const scrollTargets = Array.from(
            document.querySelectorAll('.feature-zone-head, .feature-card, .site-bottom-block')
        ).filter((node) => !(hero instanceof HTMLElement && hero.contains(node)));

        if (scrollTargets.length === 0 || typeof IntersectionObserver !== 'function') {
            return;
        }

        scrollTargets.forEach((node, index) => {
            node.classList.add('scroll-reveal');
            node.style.setProperty('--reveal-delay', `${Math.min(index * 40, 280)}ms`);
        });

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                });
            },
            {
                root: null,
                // 提前一点触发，避免用户滚到区块中央才突然显现。
                rootMargin: '0px 0px -48px 0px',
                threshold: 0.01,
            }
        );

        scrollTargets.forEach((node) => {
            observer.observe(node);
        });

        // 首屏已在视口内的目标（短页/大屏）立即补一次检测，避免依赖滚动才显现。
        window.requestAnimationFrame(() => {
            scrollTargets.forEach((node) => {
                if (node.classList.contains('is-visible')) {
                    return;
                }
                const rect = node.getBoundingClientRect();
                const viewHeight = window.innerHeight || document.documentElement.clientHeight;
                const inView = rect.top < viewHeight - 48 && rect.bottom > 48 && rect.height > 0;
                if (inView) {
                    node.classList.add('is-visible');
                    observer.unobserve(node);
                }
            });
        });
    };

    initStickyDownload();
    initScrollReveals();
})();
