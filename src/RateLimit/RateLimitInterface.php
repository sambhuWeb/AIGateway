<?php

namespace AIGateway\RateLimit;

interface RateLimitInterface
{
    public function isAllowed(string $identifier): bool;

    public function consume(string $identifier): int;
}
