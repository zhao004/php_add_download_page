<?php

declare(strict_types=1);

namespace app\admin\middleware;

use app\common\service\AdminAuthService;
use Closure;
use think\Request;
use think\Response;

/**
 * 保护后台路由，仅登录页和验证码接口允许匿名访问。
 */
class AdminAuth
{
    /** @var AdminAuthService */
    private $auth;

    /**
     * @param AdminAuthService $auth 后台认证服务
     */
    public function __construct(AdminAuthService $auth)
    {
        $this->auth = $auth;
    }

    /**
     * @param Request $request 当前请求
     * @param Closure $next 后续中间件
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->pathinfo(), '/');
        if (in_array($path, ['login', 'captcha'], true)) {
            return $next($request);
        }

        if (!$this->auth->isAuthenticated()) {
            $redirect = '/admin' . ($path !== '' ? '/' . $path : '');
            return redirect('/admin/login?redirect=' . rawurlencode($redirect));
        }

        return $next($request);
    }
}
