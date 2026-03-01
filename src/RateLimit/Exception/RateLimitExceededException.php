<?php

namespace AIGateway\RateLimit\Exception;

class RateLimitExceededException extends \RuntimeException
{
    public function __construct(
        string $message = 'Rate limit exceeded',
        int $code = 429,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
