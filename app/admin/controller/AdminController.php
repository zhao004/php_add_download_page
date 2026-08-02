<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\AdminAuthService;
use app\common\service\AdminFlashService;
use app\common\service\CsrfTokenService;
use app\common\service\SiteConfigService;
use app\common\service\UploadLimitService;
use think\App;
use think\facade\View;
use think\Response;
use Throwable;

/**
 * 后台控制器基类，统一视图上下文、CSRF 与操作反馈。
 */
abstract class AdminController extends BaseController
{
    /** @var AdminAuthService */
    protected $auth;

    /** @var CsrfTokenService */
    protected $csrf;

    /**
     * @param App $app ThinkPHP 应用实例
     */
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->auth = $app->make(AdminAuthService::class);
        $this->csrf = $app->make(CsrfTokenService::class);
    }

    /**
     * @param string $template 模板路径
     * @param array<string, mixed> $data 页面数据
     * @return string 渲染结果
     */
    protected function render(string $template, array $data = []): string
    {
        // 表单校验等页面内错误统一并入 Toast 队列，避免再渲染内联 alert。
        $pageError = trim((string) ($data['error'] ?? ''));
        if ($pageError !== '') {
            $this->flashService()->push($pageError, 'danger');
        }

        $flashes = $this->flashService()->pull();
        $uploadLimits = UploadLimitService::fromApplication($this->app);
        $brand = $this->resolveBrandContext();
        $themeStyle = $this->resolveThemeStyleTag();
        $faviconUrl = $this->resolveFaviconUrl();

        return View::fetch($template, array_merge([
            'pageTitle' => '管理后台',
            'pageDescription' => '',
            'activeNav' => '',
            'adminUser' => $this->auth->currentAdmin(),
            'csrfToken' => $this->csrf->token('admin'),
            // flashes：最多 3 条；flash：兼容旧模板，取最新一条。
            'flashes' => $flashes,
            'flash' => $flashes === [] ? null : $flashes[count($flashes) - 1],
            'uploadLimits' => $uploadLimits->viewData(),
            'siteName' => $brand['siteName'],
            'siteNameInitial' => $brand['siteNameInitial'],
            'appIcon' => $brand['appIcon'],
            'themeStyle' => $themeStyle,
            'faviconUrl' => $faviconUrl,
            // 错误已转为 Toast，模板侧不再依赖内联提示。
            'error' => '',
        ], $data, [
            // 强制清空，防止业务层 $data 再次覆盖为非空错误文案。
            'error' => '',
        ]));
    }

    /**
     * 读取站点主题覆盖样式，失败时回退默认色。
     *
     * @return string style 标签 HTML
     */
    private function resolveThemeStyleTag(): string
    {
        try {
            /** @var SiteConfigService $configService */
            $configService = $this->app->make(SiteConfigService::class);
            return $configService->themeStyleTag();
        } catch (Throwable $exception) {
            return '';
        }
    }

    /**
     * 读取站点浏览器图标；配置暂不可用时保留内置图标。
     *
     * @return string 浏览器图标地址
     */
    private function resolveFaviconUrl(): string
    {
        try {
            /** @var SiteConfigService $configService */
            $configService = $this->app->make(SiteConfigService::class);
            return $configService->faviconUrl();
        } catch (Throwable $exception) {
            return SiteConfigService::DEFAULT_FAVICON_PATH;
        }
    }

    /**
     * 读取侧栏品牌上下文：软件名称、首字与应用图标。
     *
     * @return array{siteName: string, siteNameInitial: string, appIcon: string}
     */
    private function resolveBrandContext(): array
    {
        $siteName = '管理后台';
        $appIcon = '';

        try {
            /** @var SiteConfigService $configService */
            $configService = $this->app->make(SiteConfigService::class);
            $config = $configService->get();
            $name = trim((string) ($config['site_name'] ?? ''));
            if ($name !== '') {
                $siteName = $name;
            }
            $appIcon = trim((string) ($config['app_icon'] ?? ''));
        } catch (Throwable $exception) {
            // 配置暂不可用时回退默认文案，避免后台布局整体失败。
        }

        return [
            'siteName' => $siteName,
            'siteNameInitial' => $this->resolveSiteNameInitial($siteName),
            'appIcon' => $appIcon,
        ];
    }

    /**
     * 取软件名称首字，用于无图标时的侧栏回退展示与异步保存后的品牌同步。
     *
     * @param string $siteName 软件名称
     * @return string 单个展示字符
     */
    protected function resolveSiteNameInitial(string $siteName): string
    {
        $trimmed = trim($siteName);
        if ($trimmed === '') {
            return '管';
        }

        if (function_exists('mb_substr')) {
            $initial = (string) mb_substr($trimmed, 0, 1, 'UTF-8');
            if (function_exists('mb_strtoupper') && preg_match('/^[A-Za-z]$/', $initial)) {
                return (string) mb_strtoupper($initial, 'UTF-8');
            }

            return $initial !== '' ? $initial : '管';
        }

        $initial = substr($trimmed, 0, 1);
        return $initial !== '' ? strtoupper($initial) : '管';
    }

    /**
     * @return void
     */
    protected function assertCsrf(): void
    {
        $this->csrf->assertValid('admin', (string) $this->request->post('_token', ''));
    }

    /**
     * @param string $path 跳转地址
     * @param string $message 提示内容
     * @param string $type success/danger/warning/info
     * @return Response
     */
    protected function redirectWithFlash(string $path, string $message, string $type = 'success'): Response
    {
        $this->flashService()->push($message, $type);
        return redirect($path);
    }

    /**
     * 判断是否应返回 JSON（异步保存/弹窗等）。
     *
     * 直接浏览器访问原 URL 时仍走完整页面或重定向回退。
     *
     * @return bool
     */
    protected function wantsJson(): bool
    {
        $accept = strtolower((string) $this->request->header('accept', ''));

        return $this->request->isAjax() || strpos($accept, 'application/json') !== false;
    }

    /**
     * @param string $message 成功提示
     * @param array<string, mixed> $data 附加数据
     * @return Response
     */
    protected function jsonSuccess(string $message, array $data = []): Response
    {
        return json(array_merge(['success' => true, 'message' => $message], $data));
    }

    /**
     * @param string $message 错误提示
     * @param int $statusCode HTTP 状态码
     * @param array<string, mixed> $data 附加数据
     * @return Response
     */
    protected function jsonError(string $message, int $statusCode = 422, array $data = []): Response
    {
        return json(array_merge(['success' => false, 'message' => $message], $data), $statusCode);
    }

    /**
     * @return AdminFlashService Flash 队列服务
     */
    protected function flashService(): AdminFlashService
    {
        return $this->app->make(AdminFlashService::class);
    }

    /**
     * @param mixed $value 原始 ID
     * @return int 正整数 ID
     */
    protected function positiveId($value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? 0 : (int) $id;
    }
}
