<?php

namespace AIGateway\Cache;

interface CacheInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttl = 3600): void;

    public function has(string $key): bool;

    public function delete(string $key): void;
}
