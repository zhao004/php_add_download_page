<?php

declare(strict_types=1);

namespace app\common\service;

/**
 * 通过 User-Agent 提供粗粒度设备分类，不用于安全决策。
 */
class DeviceDetectorService
{
    /**
     * @param string $userAgent User-Agent
     * @return string mobile/desktop/tablet/bot/unknown
     */
    public function detect(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'unknown';
        }
        if (preg_match('/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|headless/i', $userAgent)) {
            return 'bot';
        }
        if (preg_match('/ipad|tablet|kindle|silk|playbook/i', $userAgent)) {
            return 'tablet';
        }
        if (preg_match('/mobile|android|iphone|ipod|windows phone|opera mini/i', $userAgent)) {
            return 'mobile';
        }

        return 'desktop';
    }
}
