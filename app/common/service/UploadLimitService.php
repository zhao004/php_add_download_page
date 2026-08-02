<?php

declare(strict_types=1);

namespace app\common\service;

use think\App;
use Throwable;

/**
 * 集中解析上传限制，并向服务端校验与后台视图提供同一组配置。
 */
class UploadLimitService
{
    public const DEFAULT_APK_MAX_MB = 300;
    public const DEFAULT_IMAGE_MAX_MB = 5;
    public const DEFAULT_SVG_MAX_MB = 1;
    public const MAXIMUM_APK_MAX_MB = 1024;
    public const MAXIMUM_IMAGE_MAX_MB = 50;
    public const MAXIMUM_SVG_MAX_MB = 10;

    private const MINIMUM_MAX_MB = 1;
    private const BYTES_PER_MEGABYTE = 1048576;

    /** @var int */
    private $apkMaxMb;

    /** @var int */
    private $imageMaxMb;

    /** @var int */
    private $svgMaxMb;

    /**
     * @param array<string, mixed> $configuration 已加载的上传配置；缺失或非法值回退到默认值
     */
    public function __construct(array $configuration = [])
    {
        $this->apkMaxMb = self::normalizeMegabytes(
            $configuration['apk_max_mb'] ?? self::DEFAULT_APK_MAX_MB,
            self::DEFAULT_APK_MAX_MB,
            self::MAXIMUM_APK_MAX_MB
        );
        $this->imageMaxMb = self::normalizeMegabytes(
            $configuration['image_max_mb'] ?? self::DEFAULT_IMAGE_MAX_MB,
            self::DEFAULT_IMAGE_MAX_MB,
            self::MAXIMUM_IMAGE_MAX_MB
        );
        $this->svgMaxMb = self::normalizeMegabytes(
            $configuration['svg_max_mb'] ?? self::DEFAULT_SVG_MAX_MB,
            self::DEFAULT_SVG_MAX_MB,
            self::MAXIMUM_SVG_MAX_MB
        );
    }

    /**
     * 从应用配置创建限制对象；未初始化应用仅用于离线测试时安全回退默认值。
     *
     * @param App $app ThinkPHP 应用实例
     * @return self
     */
    public static function fromApplication(App $app): self
    {
        $configuration = [];
        try {
            $loaded = $app->config->get('upload', []);
            if (is_array($loaded)) {
                $configuration = $loaded;
            }
        } catch (Throwable $exception) {
            // 离线单元测试不会初始化配置容器，此时使用与生产配置一致的默认值。
        }

        return new self($configuration);
    }

    /**
     * 规范化 MB 配置。非整数回退默认值，整数则限制在 1 到对应安全上限之间。
     *
     * @param mixed $value 原始配置值
     * @param int $default 默认值
     * @param int $maximum 安全上限
     * @return int 规范化后的 MB 数
     */
    public static function normalizeMegabytes($value, int $default, int $maximum): int
    {
        if ($default < self::MINIMUM_MAX_MB || $maximum < $default) {
            throw new \InvalidArgumentException('上传限制的默认值或上限无效。');
        }

        if (is_int($value)) {
            $candidate = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            $candidate = (int) trim($value);
        } else {
            return $default;
        }

        return max(self::MINIMUM_MAX_MB, min($maximum, $candidate));
    }

    /**
     * @return int APK 最大 MB 数
     */
    public function apkMaxMb(): int
    {
        return $this->apkMaxMb;
    }

    /**
     * @return int 栅格图片最大 MB 数
     */
    public function imageMaxMb(): int
    {
        return $this->imageMaxMb;
    }

    /**
     * @return int SVG 最大 MB 数
     */
    public function svgMaxMb(): int
    {
        return $this->svgMaxMb;
    }

    /**
     * @return int APK 最大字节数
     */
    public function apkMaxBytes(): int
    {
        return $this->apkMaxMb * self::BYTES_PER_MEGABYTE;
    }

    /**
     * @return int 栅格图片最大字节数
     */
    public function imageMaxBytes(): int
    {
        return $this->imageMaxMb * self::BYTES_PER_MEGABYTE;
    }

    /**
     * @return int SVG 最大字节数
     */
    public function svgMaxBytes(): int
    {
        return $this->svgMaxMb * self::BYTES_PER_MEGABYTE;
    }

    /**
     * @return array{apk_max_mb:int,apk_max_bytes:int,image_max_mb:int,image_max_bytes:int,svg_max_mb:int,svg_max_bytes:int} 后台模板安全数值
     */
    public function viewData(): array
    {
        return [
            'apk_max_mb' => $this->apkMaxMb(),
            'apk_max_bytes' => $this->apkMaxBytes(),
            'image_max_mb' => $this->imageMaxMb(),
            'image_max_bytes' => $this->imageMaxBytes(),
            'svg_max_mb' => $this->svgMaxMb(),
            'svg_max_bytes' => $this->svgMaxBytes(),
        ];
    }
}
