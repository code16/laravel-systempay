<?php

namespace Code16\Systempay\Exceptions;

use Exception;

class SystemPayApiException extends Exception
{
    /**
     * @param  array  $response  The decoded JSON response from the Systempay REST API
     */
    public function __construct(string $message, protected array $response = [])
    {
        parent::__construct($message);
    }

    public function response(): array
    {
        return $this->response;
    }
}
