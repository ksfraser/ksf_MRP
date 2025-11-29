<?php
/**
 * MRP Exception Class
 */

namespace FA\Modules\MRP;

/**
 * MRP-specific exception
 */
class MRPException extends \Exception
{
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}