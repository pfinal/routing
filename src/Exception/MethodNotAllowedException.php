<?php

namespace PFinal\Routing\Exception;

/**
 * HTTP 405 response
 */
class MethodNotAllowedException extends \RuntimeException implements ExceptionInterface
{
    protected $allowedMethods = array();

    public function __construct(array $allowedMethods, $message = null, $code = 0, $previous = null)
    {
        $this->allowedMethods = array_map('strtoupper', $allowedMethods);

        parent::__construct($message, $code, $previous);
    }

    public function getAllowedMethods()
    {
        return $this->allowedMethods;
    }
}
