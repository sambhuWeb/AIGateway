<?php

namespace AIGateway\Cache;

class FileCache implements CacheInterface
{
    /** @var string */
    private $cacheDirectory;

    public function __construct(string $cacheDirectory = null)
    {
        if ($cacheDirectory === null) {
            // Default to storage/cache directory relative to package root
            $this->cacheDirectory = $this->getDefaultCacheDirectory();
        } else {
            $this->cacheDirectory = $cacheDirectory;
        }

        if (!is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0755, true);
        }
    }

    private function getDefaultCacheDirectory(): string
    {
        // Get the package root directory (3 levels up from this file)
        $packageRoot = dirname(__DIR__, 2);
        return $packageRoot . '/storage/cache';
    }

    public function get(string $key): ?string
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return null;
        }

        $data = json_decode(file_get_contents($filePath), true);

        if ($data === null) {
            return null;
        }

        if (isset($data['expires_at']) && time() > $data['expires_at']) {
            $this->delete($key);
            return null;
        }

        return $data['value'] ?? null;
    }

    public function set(string $key, string $value, int $ttl = 3600): void
    {
        $filePath = $this->getFilePath($key);

        $data = [
            'value' => $value,
            'expires_at' => time() + $ttl,
            'created_at' => time()
        ];

        file_put_contents($filePath, json_encode($data));
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): void
    {
        $filePath = $this->getFilePath($key);

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    private function getFilePath(string $key): string
    {
        return $this->cacheDirectory . '/' . md5($key) . '.cache';
    }

    public static function generateCacheKey(array $params): string
    {
        return md5(json_encode($params));
    }
}
