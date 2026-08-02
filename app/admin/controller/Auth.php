<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\AdminException;
use app\common\service\AdminAuthService;
use app\common\service\AdminFlashService;
use app\common\service\CaptchaService;
use app\common\service\CsrfTokenService;
use app\common\service\SiteConfigService;
use think\App;
use think\facade\Log;
use think\facade\View;
use think\Response;
use Throwable;

/**
 * 管理后台登录与退出。
 */
class Auth extends BaseController
{
    /** @var AdminAuthService */
    private $auth;

    /** @var CsrfTokenService */
    private $csrf;

    /** @var CaptchaService */
    private $captcha;

    /**
     * @param App $app ThinkPHP 应用实例
     * @param AdminAuthService $auth 后台认证服务
     * @param CsrfTokenService $csrf CSRF 服务
     * @param CaptchaService $captcha 验证码服务
     */
    public function __construct(App $app, AdminAuthService $auth, CsrfTokenService $csrf, CaptchaService $captcha)
    {
        parent::__construct($app);
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->captcha = $captcha;
    }

    /**
     * @return Response|string 登录页或登录后的跳转
     */
    public function login()
    {
        $redirect = $this->safeRedirect((string) $this->request->param('redirect', '/admin'));
        if ($this->auth->isAuthenticated()) {
            return redirect($redirect);
        }

        $error = '';
        $username = '';
        if ($this->request->isPost()) {
            $username = trim((string) $this->request->post('username', ''));
            try {
                $this->csrf->assertValid('admin_login', (string) $this->request->post('_token', ''));
                if (!$this->captcha->verify((string) $this->request->post('captcha', ''))) {
                    throw new AdminException('验证码错误或已过期，请刷新后重试。');
                }
                $this->auth->login(
                    $username,
                    (string) $this->request->post('password', ''),
                    (string) $this->request->ip()
                );

                if ($this->wantsJson()) {
                    return json(['success' => true, 'redirect' => $redirect]);
                }

                return redirect($redirect);
            } catch (AdminException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                Log::error('后台登录异常：' . $exception->getMessage());
                $error = '登录服务暂时不可用，请检查服务器日志。';
            }

            if ($this->wantsJson()) {
                return json([
                    'success' => false,
                    'message' => $error,
                    'csrfToken' => $this->csrf->token('admin_login'),
                ], 422);
            }
        }

        /** @var AdminFlashService $flashService */
        $flashService = $this->app->make(AdminFlashService::class);
        // 登录失败文案并入 Toast 队列，与退出等 flash 统一展示。
        if ($error !== '') {
            $flashService->push($error, 'danger');
        }
        $flashes = $flashService->pull();

        $themeStyle = '';
        $faviconUrl = SiteConfigService::DEFAULT_FAVICON_PATH;
        $siteName = '管理后台';
        try {
            /** @var SiteConfigService $configService */
            $configService = $this->app->make(SiteConfigService::class);
            $themeStyle = $configService->themeStyleTag();
            $faviconUrl = $configService->faviconUrl();
            $configuredName = trim((string) ($configService->get()['site_name'] ?? ''));
            if ($configuredName !== '') {
                $siteName = $configuredName;
            }
        } catch (Throwable $exception) {
            // 登录页在配置暂不可用时仍应可渲染，使用 tokens 默认色。
        }

        $siteNameInitial = $this->resolveSiteNameInitial($siteName);

        return View::fetch('auth/login', [
            'pageTitle' => '登录管理后台',
            'csrfToken' => $this->csrf->token('admin_login'),
            'redirect' => $redirect,
            'username' => $username,
            // 错误已转为 Toast，模板不再渲染内联 alert。
            'error' => '',
            'flashes' => $flashes,
            'flash' => $flashes === [] ? null : $flashes[count($flashes) - 1],
            'captchaUrl' => '/admin/captcha?t=' . rawurlencode((string) microtime(true)),
            'themeStyle' => $themeStyle,
            'faviconUrl' => $faviconUrl,
            'siteName' => $siteName,
            'siteNameInitial' => $siteNameInitial,
        ]);
    }

    /**
     * 取站点名称首字，用于登录页品牌标识。
     *
     * @param string $siteName 站点名称
     * @return string 单个展示字符
     */
    private function resolveSiteNameInitial(string $siteName): string
    {
        $trimmed = trim($siteName);
        if ($trimmed === '') {
            return 'N';
        }

        if (function_exists('mb_substr')) {
            $initial = (string) mb_substr($trimmed, 0, 1, 'UTF-8');
            if (function_exists('mb_strtoupper') && preg_match('/^[A-Za-z]$/', $initial)) {
                return (string) mb_strtoupper($initial, 'UTF-8');
            }

            return $initial !== '' ? $initial : 'N';
        }

        $initial = substr($trimmed, 0, 1);
        return $initial !== '' ? strtoupper($initial) : 'N';
    }

    /**
     * 输出登录验证码图片。
     *
     * @return Response SVG 图片响应
     */
    public function captcha(): Response
    {
        try {
            return response($this->captcha->issue(), 200)->header([
                'Content-Type' => 'image/svg+xml; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]);
        } catch (Throwable $exception) {
            Log::error('登录验证码生成异常：' . $exception->getMessage());

            return response('验证码暂时不可用，请刷新页面重试。', 503)->header([
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }
    }

    /**
     * @return Response 退出后的跳转
     */
    public function logout(): Response
    {
        try {
            $this->csrf->assertValid('admin', (string) $this->request->post('_token', ''));
            $this->auth->logout();
            /** @var AdminFlashService $flashService */
            $flashService = $this->app->make(AdminFlashService::class);
            $flashService->push('已安全退出。', 'success');

            return redirect('/admin/login');
        } catch (AdminException $exception) {
            /** @var AdminFlashService $flashService */
            $flashService = $this->app->make(AdminFlashService::class);
            $flashService->push($exception->getMessage(), 'danger');
            return redirect('/admin');
        }
    }

    /**
     * @param string $redirect 候选地址
     * @return string 仅限后台站内地址
     */
    private function safeRedirect(string $redirect): string
    {
        if (preg_match('#^/admin(?:/[^\r\n]*)?$#', $redirect) && strpos($redirect, '//') !== 0) {
            return $redirect;
        }

        return '/admin';
    }

    /**
     * 判断当前登录请求是否要求 JSON 响应。
     *
     * @return bool 是否返回 JSON
     */
    private function wantsJson(): bool
    {
        $accept = strtolower((string) $this->request->header('accept', ''));

        return $this->request->isAjax() || strpos($accept, 'application/json') !== false;
    }
}
