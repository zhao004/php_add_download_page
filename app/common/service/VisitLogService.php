<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;
use think\facade\Log;
use think\facade\Session;
use think\Request;
use Throwable;

/**
 * 记录成功渲染的前台访问；日志失败不影响页面响应。
 */
class VisitLogService
{
    /** @var ClientIpService */
    private $clientIp;

    /** @var IpRegionService */
    private $ipRegion;

    /** @var DeviceDetectorService */
    private $deviceDetector;

    /** @var SiteConfigService */
    private $configService;

    public function __construct(
        ClientIpService $clientIp,
        IpRegionService $ipRegion,
        DeviceDetectorService $deviceDetector,
        SiteConfigService $configService
    ) {
        $this->clientIp = $clientIp;
        $this->ipRegion = $ipRegion;
        $this->deviceDetector = $deviceDetector;
        $this->configService = $configService;
    }

    /**
     * @param Request $request 前台请求
     * @return void
     */
    public function record(Request $request): void
    {
        try {
            $config = $this->configService->get();
            $ip = $this->clientIp->resolve($request);
            $userAgent = mb_substr((string) $request->header('user-agent', ''), 0, 512);
            $deviceType = $this->deviceDetector->detect($userAgent);
            if ($deviceType === 'bot' && (int) $config['record_bots'] !== 1) {
                return;
            }

            $path = '/' . ltrim((string) $request->pathinfo(), '/');
            $dedupeSeconds = max(0, min(3600, (int) $config['log_dedupe_seconds']));
            if ($dedupeSeconds > 0) {
                $threshold = date('Y-m-d H:i:s', time() - $dedupeSeconds);
                $exists = Db::name('visit_log')
                    ->where('ip', $ip)
                    ->where('path', $path)
                    ->where('created_at', '>=', $threshold)
                    ->find();
                if ($exists) {
                    return;
                }
            }

            $region = $this->ipRegion->parse($ip);
            Db::name('visit_log')->insert(array_merge($region, [
                'ip' => $ip,
                'user_agent' => $userAgent,
                'device_type' => $deviceType,
                'referer' => mb_substr((string) $request->header('referer', ''), 0, 512),
                'path' => mb_substr($path, 0, 255),
                'method' => mb_substr(strtoupper((string) $request->method()), 0, 16),
                'session_id' => mb_substr((string) Session::getId(), 0, 64) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]));
        } catch (Throwable $exception) {
            Log::warning('访问日志写入失败：' . mb_substr($exception->getMessage(), 0, 180));
        }
    }
}
