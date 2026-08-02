<?php

declare(strict_types=1);

namespace app\common\exception;

use RuntimeException;

/**
 * 后台可安全展示的验证或业务异常。
 */
class AdminException extends RuntimeException
{
}
