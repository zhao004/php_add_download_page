<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\DashboardService;

/**
 * 后台概览与基础统计。
 */
class Dashboard extends AdminController
{
    /**
     * @return string 仪表盘页面
     */
    public function index(): string
    {
        /** @var DashboardService $dashboardService */
        $dashboardService = $this->app->make(DashboardService::class);
        $dashboard = $dashboardService->data();
        $trendJson = json_encode($dashboard['trend'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->render('dashboard/index', [
            'pageTitle' => '仪表盘',
            'pageDescription' => '访问、下载与分发配置的实时概览',
            'activeNav' => 'dashboard',
            'metrics' => $dashboard['metrics'],
            'health' => $dashboard['health'],
            'hasTrend' => array_sum($dashboard['trend']['visits']) + array_sum($dashboard['trend']['downloads']) > 0,
            'trendJson' => $trendJson === false ? '{"labels":[],"visits":[],"downloads":[]}' : $trendJson,
        ]);
    }
}
