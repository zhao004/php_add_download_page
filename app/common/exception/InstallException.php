<?php

declare(strict_types=1);

namespace app\common\exception;

use RuntimeException;

/**
 * 安装流程中可安全展示给用户的业务异常。
 */
class InstallException extends RuntimeException
{
}
