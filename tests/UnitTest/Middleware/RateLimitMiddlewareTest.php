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
        $request->method('getModel')->willReturn('gpt-4');
        $request->method('getMessages')->willReturn([['role' => 'user', 'content' => 'Hello']]);
        $request->method('getTemperature')->willReturn(0.7);
        $request->method('getMaxTokens')->willReturn(100);
        $request->method('isFresh')->willReturn($fresh);
        return $request;
    }

    private function expectedCacheKey(): string
    {
        return hash('sha256', json_encode([
            'model'         => 'gpt-4',
            'messages'      => [['role' => 'user', 'content' => 'Hello']],
            'system_prompt' => null,
            'temperature'   => 0.7,
            'max_tokens'    => 100,
        ]));
    }

    private function cachedJson(): string
    {
        return json_encode([
            'content'           => 'Cached',
            'model'             => 'gpt-4',
            'prompt_tokens'     => 5,
            'completion_tokens' => 3,
        ]);
    }

    // -------------------------------------------------------------------------
    // Fix 1 — single get() call (no more has() + get())
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function it_reads_cache_with_single_get_call_not_has_then_get(): void
    {
        $this->cache->expects($this->never())->method('has');
        $this->cache->expects($this->once())->method('get')->willReturn($this->cachedJson());

        $this->rateLimiter->expects($this->never())->method('isAllowed');
        $this->connector->expects($this->never())->method('chat');

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $response = $middleware->handle($this->makeRequest(false), '1.2.3.4');

        self::assertTrue($response->isFromCache());
        self::assertEquals('Cached', $response->getContent());
    }

    /**
     * @test
     */
    public function it_returns_cached_response_on_cache_hit(): void
    {
        $this->cache->method('get')->willReturn($this->cachedJson());
        $this->rateLimiter->expects($this->never())->method('consume');
        $this->connector->expects($this->never())->method('chat');

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $response = $middleware->handle($this->makeRequest(false), '1.2.3.4');

        self::assertTrue($response->isFromCache());
        self::assertEquals('Cached', $response->getContent());
    }

    /**
     * @test
     */
    public function it_calls_connector_on_cache_miss(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects($this->once())->method('set');

        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('consume')->willReturn(4);
        $this->connector->expects($this->once())->method('chat')->willReturn($this->fakeResponse);

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $response = $middleware->handle($this->makeRequest(false), '1.2.3.4');

        self::assertFalse($response->isFromCache());
        self::assertEquals(4, $response->getTriesRemaining());
    }

    // -------------------------------------------------------------------------
    // Fix 2 — cache key includes temperature and max_tokens
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function it_includes_temperature_and_max_tokens_in_cache_key(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('consume')->willReturn(9);

        $this->cache->expects($this->once())
            ->method('get')
            ->with($this->expectedCacheKey())
            ->willReturn(null);

        $this->cache->expects($this->once())
            ->method('set')
            ->with($this->expectedCacheKey(), $this->anything(), $this->anything());

        $this->connector->method('chat')->willReturn($this->fakeResponse);

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $middleware->handle($this->makeRequest(false), '1.2.3.4');
    }

    /**
     * @test
     */
    public function it_produces_different_cache_key_for_different_temperature(): void
    {
        $request1 = $this->createMock(AIRequestDTO::class);
        $request1->method('getModel')->willReturn('gpt-4');
        $request1->method('getMessages')->willReturn([['role' => 'user', 'content' => 'Hello']]);
        $request1->method('getTemperature')->willReturn(0.0);
        $request1->method('getMaxTokens')->willReturn(100);
        $request1->method('isFresh')->willReturn(false);

        $request2 = $this->createMock(AIRequestDTO::class);
        $request2->method('getModel')->willReturn('gpt-4');
        $request2->method('getMessages')->willReturn([['role' => 'user', 'content' => 'Hello']]);
        $request2->method('getTemperature')->willReturn(1.0);
        $request2->method('getMaxTokens')->willReturn(100);
        $request2->method('isFresh')->willReturn(false);

        $key1 = hash('sha256', json_encode([
            'model' => 'gpt-4', 'messages' => [['role' => 'user', 'content' => 'Hello']],
            'system_prompt' => null, 'temperature' => 0.0, 'max_tokens' => 100,
        ]));
        $key2 = hash('sha256', json_encode([
            'model' => 'gpt-4', 'messages' => [['role' => 'user', 'content' => 'Hello']],
            'system_prompt' => null, 'temperature' => 1.0, 'max_tokens' => 100,
        ]));

        self::assertNotEquals($key1, $key2);

        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('consume')->willReturn(9);
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');
        $this->connector->method('chat')->willReturn($this->fakeResponse);

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);

        $getArgs = [];
        $this->cache->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $key) use (&$getArgs) {
                $getArgs[] = $key;
                return null;
            });

        $middleware->handle($request1, '1.2.3.4');
        $middleware->handle($request2, '1.2.3.4');

        self::assertCount(2, $getArgs);
        self::assertNotEquals($getArgs[0], $getArgs[1]);
    }

    /**
     * @test
     */
    public function it_produces_different_cache_key_for_different_max_tokens(): void
    {
        $key1 = hash('sha256', json_encode([
            'model' => 'gpt-4', 'messages' => [['role' => 'user', 'content' => 'Hello']],
            'system_prompt' => null, 'temperature' => 0.7, 'max_tokens' => 100,
        ]));
        $key2 = hash('sha256', json_encode([
            'model' => 'gpt-4', 'messages' => [['role' => 'user', 'content' => 'Hello']],
            'system_prompt' => null, 'temperature' => 0.7, 'max_tokens' => 500,
        ]));

        self::assertNotEquals($key1, $key2);
    }

    // -------------------------------------------------------------------------
    // Fix 3 — cache checked before rate limit
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function it_serves_cached_response_even_when_rate_limited(): void
    {
        $this->cache->method('get')->willReturn($this->cachedJson());

        // isAllowed must never be called — cache hit bypasses rate limiting
        $this->rateLimiter->expects($this->never())->method('isAllowed');
        $this->connector->expects($this->never())->method('chat');

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $response = $middleware->handle($this->makeRequest(false), '1.2.3.4');

        self::assertTrue($response->isFromCache());
    }

    /**
     * @test
     */
    public function it_throws_rate_limit_exceeded_only_on_cache_miss(): void
    {
        $this->expectException(RateLimitExceededException::class);

        // Cache miss → falls through to rate limit check
        $this->cache->method('get')->willReturn(null);
        $this->rateLimiter->method('isAllowed')->willReturn(false);
        $this->connector->expects($this->never())->method('chat');

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $middleware->handle($this->makeRequest(false), '1.2.3.4');
    }

    // -------------------------------------------------------------------------
    // Existing behaviour preserved
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function it_bypasses_cache_lookup_when_fresh_is_true(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('consume')->willReturn(9);

        // fresh=true → get() must never be called
        $this->cache->expects($this->never())->method('get');
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
        $this->cache->method('get')->willReturn(null);
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
        $this->cache->method('get')->willReturn(null);
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
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');
        $this->connector->method('chat')->willReturn($this->fakeResponse);

        $middleware = new RateLimitMiddleware($this->connector, $this->rateLimiter, $this->cache);
        $response = $middleware->handle($this->makeRequest(false), '1.2.3.4');

        $array = $response->toArray();
        self::assertArrayHasKey('tries_remaining', $array);
        self::assertEquals(7, $array['tries_remaining']);
    }
}
