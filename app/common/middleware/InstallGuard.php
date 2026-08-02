<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\service\InstallStateService;
use Closure;
use think\App;
use think\Request;
use think\Response;

/**
 * 在业务应用运行前检查安装锁。
 *
 * 静态文件由 Web 服务器直接处理；所有进入 PHP 的未安装请求都会跳转安装向导。
 */
class InstallGuard
{
    /** @var InstallStateService */
    private $installState;

    /** @var App */
    private $app;

    /**
     * @param InstallStateService $installState 安装状态服务
     * @param App $app ThinkPHP 应用实例
     */
    public function __construct(InstallStateService $installState, App $app)
    {
        $this->installState = $installState;
        $this->app = $app;
    }

    /**
     * @param Request $request 当前请求
     * @param Closure $next 后续中间件
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->pathinfo(), '/');
        $root = rtrim($request->root(), '/');
        $isInstallRequest = $path === 'install'
            || strpos($path, 'install/') === 0
            || $root === '/install'
            || $this->app->http->getName() === 'install';

        if (!$this->installState->isInstalled() && !$isInstallRequest) {
            return redirect('/install');
        }

        return $next($request);
    }
}
