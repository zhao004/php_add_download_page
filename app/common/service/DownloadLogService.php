<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;
use think\facade\Log;
use think\Request;
use Throwable;

/**
 * 记录下载入口命中与最终结果；日志异常不阻断下载主流程。
 */
class DownloadLogService
{
    private const ALLOWED_STATUSES = [
        'pending',
        'success',
        'fail_missing_file',
        'fail_lanzou',
        'fail_other',
    ];

    /** @var ClientIpService */
    private $clientIp;

    /** @var IpRegionService */
    private $ipRegion;

    /** @var DeviceDetectorService */
    private $deviceDetector;

    public function __construct(
        ClientIpService $clientIp,
        IpRegionService $ipRegion,
        DeviceDetectorService $deviceDetector
    ) {
        $this->clientIp = $clientIp;
        $this->ipRegion = $ipRegion;
        $this->deviceDetector = $deviceDetector;
    }

    /**
     * @param Request $request 下载请求
     * @param string $mode local/other（历史日志可能仍含 lanzou）
     * @return int 日志 ID，写入失败时为 0
     */
    public function start(Request $request, string $mode): int
    {
        try {
            $ip = $this->clientIp->resolve($request);
            $region = $this->ipRegion->parse($ip);
            $userAgent = mb_substr((string) $request->header('user-agent', ''), 0, 512);

            return (int) Db::name('download_click_log')->insertGetId(array_merge($region, [
                'ip' => $ip,
                'user_agent' => $userAgent,
                'device_type' => $this->deviceDetector->detect($userAgent),
                'referer' => mb_substr((string) $request->header('referer', ''), 0, 512),
                'download_mode' => in_array($mode, ['local', 'other', 'lanzou'], true) ? $mode : 'local',
                'status' => 'pending',
                'fail_reason' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]));
        } catch (Throwable $exception) {
            Log::warning('下载日志创建失败：' . mb_substr($exception->getMessage(), 0, 180));
            return 0;
        }
    }

    /**
     * @param int $id 日志 ID
     * @param string $status 最终状态
     * @param string|null $reason 脱敏失败摘要
     * @return void
     */
    public function finish(int $id, string $status, ?string $reason = null): void
    {
        if ($id <= 0) {
            return;
        }
        if (!in_array($status, self::ALLOWED_STATUSES, true) || $status === 'pending') {
            $status = 'fail_other';
        }

        try {
            Db::name('download_click_log')->where('id', $id)->update([
                'status' => $status,
                'fail_reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            ]);
        } catch (Throwable $exception) {
            Log::warning('下载日志更新失败：' . mb_substr($exception->getMessage(), 0, 180));
        }
    }
}
