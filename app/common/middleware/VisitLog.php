<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\service\VisitLogService;
use Closure;
use think\Request;
use think\Response;

/**
 * 在前台 GET 页面成功响应后记录访问。
 */
class VisitLog
{
    /** @var VisitLogService */
    private $visitLogService;

    public function __construct(VisitLogService $visitLogService)
    {
        $this->visitLogService = $visitLogService;
    }

    /**
     * @param Request $request 当前请求
     * @param Closure $next 后续中间件
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isGet() && $response->getCode() === 200) {
            $this->visitLogService->record($request);
        }

        return $response;
    }
}
