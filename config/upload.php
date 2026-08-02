<?php

declare(strict_types=1);

use app\common\service\UploadLimitService;

return [
    // 配置值会被限制在安全范围内，避免错误环境变量导致无限制上传或整数溢出。
    'apk_max_mb' => UploadLimitService::normalizeMegabytes(
        env('upload.apk_max_mb', UploadLimitService::DEFAULT_APK_MAX_MB),
        UploadLimitService::DEFAULT_APK_MAX_MB,
        UploadLimitService::MAXIMUM_APK_MAX_MB
    ),
    'image_max_mb' => UploadLimitService::normalizeMegabytes(
        env('upload.image_max_mb', UploadLimitService::DEFAULT_IMAGE_MAX_MB),
        UploadLimitService::DEFAULT_IMAGE_MAX_MB,
        UploadLimitService::MAXIMUM_IMAGE_MAX_MB
    ),
    'svg_max_mb' => UploadLimitService::normalizeMegabytes(
        env('upload.svg_max_mb', UploadLimitService::DEFAULT_SVG_MAX_MB),
        UploadLimitService::DEFAULT_SVG_MAX_MB,
        UploadLimitService::MAXIMUM_SVG_MAX_MB
    ),
];
