<?php

declare(strict_types=1);

namespace app\common\service;

use DateInterval;
use DateTimeImmutable;
use think\facade\Db;

/**
 * 聚合后台概览所需的访问、下载与配置健康数据。
 */
class DashboardService
{
    /** @var SiteConfigService */
    private $configService;

    /**
     * @param SiteConfigService $configService 站点配置服务
     */
    public function __construct(SiteConfigService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * @return array<string, mixed> 仪表盘数据
     */
    public function data(): array
    {
        $today = new DateTimeImmutable('today');
        $tomorrow = $today->add(new DateInterval('P1D'));
        $yesterday = $today->sub(new DateInterval('P1D'));
        $todayVisits = $this->countBetween('visit_log', $today, $tomorrow);
        $todayDownloads = $this->countBetween('download_click_log', $today, $tomorrow);
        $yesterdayVisits = $this->countBetween('visit_log', $yesterday, $today);
        $todayUniqueIps = (int) Db::name('visit_log')
            ->where('created_at', '>=', $today->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $tomorrow->format('Y-m-d H:i:s'))
            ->distinct(true)
            ->count('ip');

        $trendStart = $today->sub(new DateInterval('P6D'));
        $visitTrend = $this->dailyTotals('visit_log', $trendStart, $tomorrow);
        $downloadTrend = $this->dailyTotals('download_click_log', $trendStart, $tomorrow);
        $labels = [];
        $visits = [];
        $downloads = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $date = $trendStart->add(new DateInterval('P' . $offset . 'D'));
            $key = $date->format('Y-m-d');
            $labels[] = $date->format('m-d');
            $visits[] = $visitTrend[$key] ?? 0;
            $downloads[] = $downloadTrend[$key] ?? 0;
        }

        return [
            'metrics' => [
                'today_visits' => $todayVisits,
                'today_downloads' => $todayDownloads,
                'today_unique_ips' => $todayUniqueIps,
                'conversion_rate' => $todayVisits > 0 ? round($todayDownloads / $todayVisits * 100, 1) : 0.0,
                'yesterday_visits' => $yesterdayVisits,
            ],
            'trend' => ['labels' => $labels, 'visits' => $visits, 'downloads' => $downloads],
            'health' => $this->downloadHealth(),
        ];
    }

    /**
     * @param string $table 表名
     * @param DateTimeImmutable $start 起始时间
     * @param DateTimeImmutable $end 结束时间（不含）
     * @return int 数量
     */
    private function countBetween(string $table, DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) Db::name($table)
            ->where('created_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $end->format('Y-m-d H:i:s'))
            ->count();
    }

    /**
     * @param string $table 表名
     * @param DateTimeImmutable $start 起始时间
     * @param DateTimeImmutable $end 结束时间（不含）
     * @return array<string, int> 日期到数量的映射
     */
    private function dailyTotals(string $table, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = Db::name($table)
            ->fieldRaw('DATE(`created_at`) AS `day`, COUNT(*) AS `total`')
            ->where('created_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $end->format('Y-m-d H:i:s'))
            ->group('day')
            ->select()
            ->toArray();
        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['day']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * @return array{mode:string,ready:bool,message:string} 下载配置健康状态
     */
    private function downloadHealth(): array
    {
        $config = $this->configService->get();
        $mode = (string) $config['download_mode'];
        $mode = $this->configService->normalizeDownloadMode($mode);
        if ($mode === 'local') {
            $relativePath = ltrim((string) $config['local_apk_path'], '/\\');
            $ready = $relativePath !== '' && is_file(public_path() . $relativePath);
            return [
                'mode' => '本地 APK',
                'ready' => $ready,
                'message' => $ready ? 'APK 文件可用' : '尚未上传可下载的 APK',
            ];
        }

        $shareUrl = trim((string) ($config['other_url'] ?? ''));
        if ($shareUrl === '') {
            $shareUrl = trim((string) ($config['lanzou_url'] ?? ''));
        }
        $ready = $shareUrl !== '';
        return [
            'mode' => '网盘下载',
            'ready' => $ready,
            'message' => $ready ? '分享地址已配置' : '尚未填写网盘分享地址',
        ];
    }
}
