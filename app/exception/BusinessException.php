<?php

namespace app\exception;

use think\Exception;

class BusinessException extends Exception
{
    protected $data = [];

    public function __construct($message = "", $code = 400, $data = [])
    {
        $this->data = $data;
        parent::__construct($message, $code);
    }

    public function getData()
    {
        return $this->data;
    }
}
