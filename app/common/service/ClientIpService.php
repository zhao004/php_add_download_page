<?php

declare(strict_types=1);

namespace app\common\service;

use think\Request;

/**
 * 在显式可信代理边界内解析客户端真实 IP。
 */
class ClientIpService
{
    /** @var SiteConfigService */
    private $configService;

    /** @var IpNetworkService */
    private $networkService;

    /**
     * @param SiteConfigService $configService 站点配置服务
     * @param IpNetworkService $networkService IP 网段服务
     */
    public function __construct(SiteConfigService $configService, IpNetworkService $networkService)
    {
        $this->configService = $configService;
        $this->networkService = $networkService;
    }

    /**
     * @param Request $request 当前请求
     * @return string 规范化客户端 IP
     */
    public function resolve(Request $request): string
    {
        $remoteAddress = trim((string) $request->server('REMOTE_ADDR', ''));
        if (!filter_var($remoteAddress, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }

        $trustedNetworks = (string) ($this->configService->get()['trusted_proxy_ips'] ?? '');
        if ($trustedNetworks === '' || !$this->networkService->isTrusted($remoteAddress, $trustedNetworks)) {
            return $remoteAddress;
        }

        $forwarded = (string) $request->header('x-forwarded-for', '');
        $chain = [];
        foreach (explode(',', $forwarded) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $chain[] = $candidate;
            }
        }

        $resolved = $remoteAddress;
        for ($index = count($chain) - 1; $index >= 0; $index--) {
            if (!$this->networkService->isTrusted($resolved, $trustedNetworks)) {
                break;
            }
            $resolved = $chain[$index];
        }
        if ($chain !== []) {
            return $resolved;
        }

        $realIp = trim((string) $request->header('x-real-ip', ''));
        return filter_var($realIp, FILTER_VALIDATE_IP) ? $realIp : $remoteAddress;
    }
}
