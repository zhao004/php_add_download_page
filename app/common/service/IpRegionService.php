<?php

declare(strict_types=1);

namespace app\common\service;

use think\App;
use think\facade\Log;
use Throwable;

/**
 * 使用项目内置 xdb 离线解析 IPv4 归属地与运营商。
 */
class IpRegionService
{
    /** @var App */
    private $app;

    /** @var \XdbSearcher|null */
    private $searcher;

    /**
     * @param App $app ThinkPHP 应用实例
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->searcher = null;
    }

    /**
     * @param string $ip IP 地址
     * @return array{country:string,region:string,city:string,isp:string,region_raw:string}
     */
    public function parse(string $ip): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->unknown('无效 IP');
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->unknown('IPv6 暂不支持');
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [
                'country' => '内网',
                'region' => '未知',
                'city' => '未知',
                'isp' => '',
                'region_raw' => '内网或保留地址',
            ];
        }

        try {
            $raw = (string) $this->searcher()->search($ip);
            if ($raw === '') {
                return $this->unknown('');
            }

            $parts = array_pad(explode('|', $raw), 4, '');
            if (count($parts) >= 5) {
                $country = $parts[0];
                $region = $parts[2];
                $city = $parts[3];
                $isp = $parts[4];
            } else {
                $country = $parts[0];
                $region = $parts[1];
                $city = $parts[2];
                $isp = $parts[3];
            }

            return [
                'country' => $this->normalize($country, '未知'),
                'region' => $this->normalize($region, '未知'),
                'city' => $this->normalize($city, '未知'),
                'isp' => $this->normalize($isp, ''),
                'region_raw' => mb_substr($raw, 0, 255),
            ];
        } catch (Throwable $exception) {
            Log::warning('ip2region 解析失败：' . mb_substr($exception->getMessage(), 0, 180));
            return $this->unknown('解析失败');
        }
    }

    /**
     * @return \XdbSearcher xdb 查询器
     * @throws \RuntimeException 数据文件不可读时抛出
     */
    private function searcher(): \XdbSearcher
    {
        if ($this->searcher instanceof \XdbSearcher) {
            return $this->searcher;
        }

        $databasePath = $this->app->getRootPath() . 'data' . DIRECTORY_SEPARATOR . 'ip2region.xdb';
        if (!is_file($databasePath) || !is_readable($databasePath)) {
            throw new \RuntimeException('data/ip2region.xdb 不存在或不可读。');
        }

        $this->searcher = \XdbSearcher::newWithFileOnly($databasePath);
        return $this->searcher;
    }

    /**
     * @param string $value 原始字段
     * @param string $fallback 空值替代
     * @return string 规范化字段
     */
    private function normalize(string $value, string $fallback): string
    {
        $value = trim($value);
        return $value === '' || $value === '0' ? $fallback : mb_substr($value, 0, 128);
    }

    /**
     * @param string $raw 原始说明
     * @return array{country:string,region:string,city:string,isp:string,region_raw:string}
     */
    private function unknown(string $raw): array
    {
        return ['country' => '未知', 'region' => '未知', 'city' => '未知', 'isp' => '', 'region_raw' => $raw];
    }

    public function __destruct()
    {
        if ($this->searcher instanceof \XdbSearcher) {
            $this->searcher->close();
        }
    }
}
