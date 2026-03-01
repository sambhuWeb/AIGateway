<?php

namespace AIGateway\Tests\UnitTest\Middleware;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use AIGateway\AI\AIConnector;
use AIGateway\AI\DTO\Input\AIRequestDTO;
use AIGateway\AI\DTO\Output\AIResponseDTO;
use AIGateway\Cache\CacheInterface;
use AIGateway\Middleware\RateLimitMiddleware;
use AIGateway\RateLimit\RateLimitInterface;
use AIGateway\RateLimit\Exception\RateLimitExceededException;

class RateLimitMiddlewareTest extends TestCase
{
    /** @var MockObject|AIConnector */
    private $connector;

    /** @var MockObject|RateLimitInterface */
    private $rateLimiter;

    /** @var MockObject|CacheInterface */
    private $cache;

    /** @var AIResponseDTO */
    private $fakeResponse;

    protected function setUp(): void
    {
        $this->connector = $this->createMock(AIConnector::class);
        $this->rateLimiter = $this->createMock(RateLimitInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->fakeResponse = new AIResponseDTO('Hello', 'gpt-4', 10, 5, false);
    }

    private function makeRequest(bool $fresh = false): AIRequestDTO
    {
        $request = $this->createMock(AIRequestDTO::class);
        $request->method('getMessages')->willReturn([['role' => 'user', 'content' => 'Hello']]);
        $request->method('isFresh')->willReturn($fresh);
        return $request;
    }

    /**
     * @test
     */
    public function it_throws_rate_limit_exceeded_when_not_allowed(): void
    {
        $this->expectException(RateLimitExceededException::class);

        $this->rateLimiter->method('isAllowed')->willReturn(false);
        $this->cache->expects($this->never())->method('has');
        $this->connector->expects($this->never())->method('chat');

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $middleware->handle($this->makeRequest(), '1.2.3.4');
    }

    /**
     * @test
     */
    public function it_returns_cached_response_without_consuming_when_cache_hit(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->never())->method('consume');

        $cachedJson = json_encode([
            'content' => 'Cached',
            'model' => 'gpt-4',
            'prompt_tokens' => 5,
            'completion_tokens' => 3
        ]);
        $this->cache->method('has')->willReturn(true);
        $this->cache->method('get')->willReturn($cachedJson);

        $this->connector->expects($this->never())->method('chat');

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $response = $middleware->handle($this->makeRequest(false), '1.2.3.4');

        self::assertTrue($response->isFromCache());
        self::assertEquals('Cached', $response->getContent());
    }

    /**
     * @test
     */
    public function it_calls_connector_and_consumes_on_cache_miss(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('consume')->willReturn(4);

        $this->cache->method('has')->willReturn(false);
        $this->cache->expects($this->once())->method('set');

        $this->connector->expects($this->once())->method('chat')->willReturn($this->fakeResponse);

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $response = $middleware->handle($this->makeRequest(false), '1.2.3.4');

        self::assertFalse($response->isFromCache());
        self::assertEquals(4, $response->getTriesRemaining());
    }

    /**
     * @test
     */
    public function it_uses_sha256_for_cache_key(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $expectedKey = hash('sha256', json_encode($messages));

        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('consume')->willReturn(9);

        $this->cache->expects($this->once())
            ->method('has')
            ->with($expectedKey)
            ->willReturn(false);

        $this->cache->expects($this->once())
            ->method('set')
            ->with($expectedKey, $this->anything(), $this->anything());

        $this->connector->method('chat')->willReturn($this->fakeResponse);

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $middleware->handle($this->makeRequest(false), '1.2.3.4');
    }

    /**
     * @test
     */
    public function it_bypasses_cache_when_fresh_is_true(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('consume')->willReturn(9);

        $this->cache->expects($this->never())->method('has');
        $this->cache->expects($this->once())->method('set');

        $this->connector->expects($this->once())->method('chat')->willReturn($this->fakeResponse);

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $response = $middleware->handle($this->makeRequest(true), '1.2.3.4');

        self::assertFalse($response->isFromCache());
    }

    /**
     * @test
     */
    public function it_skips_rate_limiter_when_not_set(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->cache->method('set');
        $this->connector->method('chat')->willReturn($this->fakeResponse);

        $middleware = new RateLimitMiddleware($this->connector, null, $this->cache);
        $response = $middleware->handle($this->makeRequest(false), '1.2.3.4');

        self::assertNull($response->getTriesRemaining());
    }

    /**
     * @test
     */
    public function it_skips_cache_when_not_set(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('consume')->willReturn(9);

        $this->connector->expects($this->once())->method('chat')->willReturn($this->fakeResponse);

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, null);
        $response = $middleware->handle($this->makeRequest(false), '1.2.3.4');

        self::assertFalse($response->isFromCache());
        self::assertEquals(9, $response->getTriesRemaining());
    }

    /**
     * @test
     */
    public function it_does_not_include_tries_remaining_in_toarray_when_null(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->cache->method('set');
        $this->connector->method('chat')->willReturn($this->fakeResponse);

        $middleware = new RateLimitMiddleware($this->connector, null, $this->cache);
        $response = $middleware->handle($this->makeRequest(false), '1.2.3.4');

        $array = $response->toArray();
        self::assertArrayNotHasKey('tries_remaining', $array);
    }

    /**
     * @test
     */
    public function it_includes_tries_remaining_in_toarray_when_set(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('consume')->willReturn(7);
        $this->cache->method('has')->willReturn(false);
        $this->cache->method('set');
        $this->connector->method('chat')->willReturn($this->fakeResponse);

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $response = $middleware->handle($this->makeRequest(false), '1.2.3.4');

        $array = $response->toArray();
        self::assertArrayHasKey('tries_remaining', $array);
        self::assertEquals(7, $array['tries_remaining']);
    }
}
