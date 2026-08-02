<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\AdminException;

/**
 * 校验 IP/CIDR 配置并执行 IPv4、IPv6 网段匹配。
 */
class IpNetworkService
{
    /**
     * @param string $input 逗号或换行分隔的 IP/CIDR
     * @return string 去重后的逐行配置
     * @throws AdminException 任一网段格式无效时抛出
     */
    public function normalizeList(string $input): string
    {
        $items = preg_split('/[\s,]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = [];
        foreach ($items as $item) {
            if (!$this->isValidNetwork($item)) {
                throw new AdminException('可信代理格式无效：' . mb_substr($item, 0, 80));
            }
            $normalized[$item] = true;
        }

        return implode(PHP_EOL, array_keys($normalized));
    }

    /**
     * @param string $ip 待检测 IP
     * @param string $configuredNetworks 逐行或逗号分隔的网段
     * @return bool 是否命中任一网段
     */
    public function isTrusted(string $ip, string $configuredNetworks): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $networks = preg_split('/[\s,]+/', trim($configuredNetworks), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($networks as $network) {
            if ($this->contains($network, $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $network IP 或 CIDR
     * @return bool 格式是否有效
     */
    private function isValidNetwork(string $network): bool
    {
        if (strpos($network, '/') === false) {
            return (bool) filter_var($network, FILTER_VALIDATE_IP);
        }

        [$address, $prefix] = array_pad(explode('/', $network, 2), 2, '');
        if (!filter_var($address, FILTER_VALIDATE_IP) || !ctype_digit($prefix)) {
            return false;
        }

        $maximum = strpos($address, ':') !== false ? 128 : 32;
        return (int) $prefix >= 0 && (int) $prefix <= $maximum;
    }

    /**
     * @param string $network IP 或 CIDR
     * @param string $ip 待检测 IP
     * @return bool 是否属于该网段
     */
    private function contains(string $network, string $ip): bool
    {
        if (strpos($network, '/') === false) {
            return inet_pton($network) === inet_pton($ip);
        }

        [$address, $prefixValue] = explode('/', $network, 2);
        $networkBytes = inet_pton($address);
        $ipBytes = inet_pton($ip);
        if ($networkBytes === false || $ipBytes === false || strlen($networkBytes) !== strlen($ipBytes)) {
            return false;
        }

        $prefix = (int) $prefixValue;
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($fullBytes > 0 && substr($networkBytes, 0, $fullBytes) !== substr($ipBytes, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($networkBytes[$fullBytes]) & $mask) === (ord($ipBytes[$fullBytes]) & $mask);
    }
}
