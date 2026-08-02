<?php

declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\service\DownloadService;
use app\common\service\LandingPageService;
use think\Response;
use think\facade\View;

/**
 * 前台落地页与统一下载入口。
 */
class Index extends BaseController
{
    /**
     * @return Response|string 前台页面或下载响应
     */
    public function index()
    {
        if (rtrim((string) $this->request->root(), '/') === '/download') {
            /** @var DownloadService $downloadService */
            $downloadService = $this->app->make(DownloadService::class);
            return $downloadService->handle($this->request);
        }

        /** @var LandingPageService $landingPageService */
        $landingPageService = $this->app->make(LandingPageService::class);
        return View::fetch('index', $landingPageService->data());
    }
}
