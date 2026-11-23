<?php
/**
 * Hublog 自定义异常基类
 */

namespace Hublog\Core\Exception;

use Exception;

class HublogException extends Exception
{
    public function __construct(string $message = '', int $code = 400, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
