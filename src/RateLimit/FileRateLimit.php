<?php

namespace AIGateway\RateLimit;

class FileRateLimit implements RateLimitInterface
{
    /** @var string */
    private $path;

    /** @var string */
    private $rateLimitId;

    /** @var int */
    private $maxRequests;

    /** @var int */
    private $windowSeconds;

    public function __construct(string $path, string $rateLimitId, int $maxRequests, int $windowSeconds)
    {
        $this->path = $path;
        $this->rateLimitId = $rateLimitId;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;

        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    public function isAllowed(string $identifier): bool
    {
        $filePath = $this->getFilePath($identifier);
        $fh = fopen($filePath, 'c+');
        if ($fh === false) {
            return true;
        }

        flock($fh, LOCK_EX);
        $data = $this->readData($fh);

        if (time() > $data['window_start'] + $this->windowSeconds) {
            $data = ['count' => 0, 'window_start' => time()];
        }

        $allowed = $data['count'] < $this->maxRequests;
        flock($fh, LOCK_UN);
        fclose($fh);

        return $allowed;
    }

    public function consume(string $identifier): int
    {
        $filePath = $this->getFilePath($identifier);
        $fh = fopen($filePath, 'c+');
        if ($fh === false) {
            return $this->maxRequests;
        }

        flock($fh, LOCK_EX);
        $data = $this->readData($fh);

        if (time() > $data['window_start'] + $this->windowSeconds) {
            $data = ['count' => 0, 'window_start' => time()];
        }

        $data['count']++;
        $this->writeData($fh, $data);
        flock($fh, LOCK_UN);
        fclose($fh);

        return max(0, $this->maxRequests - $data['count']);
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            $config['path'],
            $config['rate_limit_id'],
            $config['max_requests'],
            $config['window_seconds']
        );
    }

    private function getFilePath(string $identifier): string
    {
        return $this->path . '/' . md5($this->rateLimitId . '_' . $identifier) . '.rl';
    }

    private function readData($fh): array
    {
        rewind($fh);
        $content = '';
        while (!feof($fh)) {
            $content .= fread($fh, 8192);
        }

        if (empty($content)) {
            return ['count' => 0, 'window_start' => time()];
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['count'], $data['window_start'])) {
            return ['count' => 0, 'window_start' => time()];
        }

        return $data;
    }

    private function writeData($fh, array $data): void
    {
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, json_encode($data));
    }
}
