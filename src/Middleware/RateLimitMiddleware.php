<?php

namespace AIGateway\Middleware;

use AIGateway\AI\AIConnector;
use AIGateway\AI\DTO\Input\AIRequestDTO;
use AIGateway\AI\DTO\Output\AIResponseDTO;
use AIGateway\Cache\CacheInterface;
use AIGateway\Cache\FileCache;
use AIGateway\RateLimit\FileRateLimit;
use AIGateway\RateLimit\RateLimitInterface;
use AIGateway\RateLimit\Exception\RateLimitExceededException;

class RateLimitMiddleware
{
    /** @var AIConnector */
    private $connector;

    /** @var RateLimitInterface|null */
    private $rateLimiter;

    /** @var CacheInterface|null */
    private $cache;

    /** @var int */
    private $cacheTtl;

    public function __construct(
        AIConnector $connector,
        ?RateLimitInterface $rateLimiter = null,
        ?CacheInterface $cache = null,
        int $cacheTtl = 3600
    ) {
        $this->connector = $connector;
        $this->rateLimiter = $rateLimiter;
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    public function handle(AIRequestDTO $request, string $identifier = ''): AIResponseDTO
    {
        $systemPrompt = method_exists($request, 'getSystemPrompt') ? $request->getSystemPrompt() : null;
        $cacheKey = hash('sha256', json_encode([
            'model'         => $request->getModel(),
            'messages'      => $request->getMessages(),
            'system_prompt' => $systemPrompt,
            'temperature'   => $request->getTemperature(),
            'max_tokens'    => $request->getMaxTokens(),
        ]));

        $cached = ($this->cache !== null && !$request->isFresh()) ? $this->cache->get($cacheKey) : null;
        if ($cached !== null) {
            $cachedData = json_decode($cached, true);
            return new AIResponseDTO(
                $cachedData['content'],
                $cachedData['model'],
                $cachedData['prompt_tokens'],
                $cachedData['completion_tokens'],
                true
            );
        }

        if ($this->rateLimiter !== null && !$this->rateLimiter->isAllowed($identifier)) {
            throw new RateLimitExceededException();
        }

        $response = $this->connector->chat($request);

        if ($this->cache !== null) {
            $this->cache->set($cacheKey, json_encode([
                'content' => $response->getContent(),
                'model' => $response->getModel(),
                'prompt_tokens' => $response->getPromptTokens(),
                'completion_tokens' => $response->getCompletionTokens()
            ]), $this->cacheTtl);
        }

        $triesRemaining = null;
        if ($this->rateLimiter !== null) {
            $triesRemaining = $this->rateLimiter->consume($identifier);
        }

        return $response->withTriesRemaining($triesRemaining);
    }

    public static function fromConfig(AIConnector $connector, array $config): self
    {
        $rateLimiter = null;
        if (!empty($config['rate_limit']['enabled'])) {
            $rateLimiter = FileRateLimit::fromConfig($config['rate_limit']);
        }

        $cache = null;
        $cacheTtl = 3600;
        if (!empty($config['cache']['enabled'])) {
            $cache = new FileCache($config['cache']['path'] ?? null);
            $cacheTtl = $config['cache']['ttl'] ?? 3600;
        }

        return new self($connector, $rateLimiter, $cache, $cacheTtl);
    }
}
