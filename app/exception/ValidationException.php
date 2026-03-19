<?php

namespace app\exception;

use think\Exception;

class ValidationException extends Exception
{
    protected $errors = [];

    public function __construct($message = "Validation failed", $errors = [], $code = 422)
    {
        $this->errors = $errors;
        parent::__construct($message, $code);
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
