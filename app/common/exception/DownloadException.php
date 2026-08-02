<?php

declare(strict_types=1);

namespace app\common\exception;

use RuntimeException;

/**
 * 下载链路中可安全展示给访客的业务异常。
 */
class DownloadException extends RuntimeException
{
    /** @var string */
    private $logStatus;

    /** @var int */
    private $httpStatus;

    /**
     * @param string $message 对外展示且可写入日志的脱敏摘要
     * @param string $logStatus 下载日志状态
     * @param int $httpStatus HTTP 状态码
     * @param \Throwable|null $previous 原始异常，仅供服务端排查
     */
    public function __construct(
        string $message,
        string $logStatus = 'fail_other',
        int $httpStatus = 503,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->logStatus = $logStatus;
        $this->httpStatus = $httpStatus;
    }

    /**
     * @return string 下载日志状态
     */
    public function getLogStatus(): string
    {
        return $this->logStatus;
    }

    /**
     * @return int HTTP 状态码
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
