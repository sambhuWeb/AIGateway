<?php

namespace AIGateway\Tests\FunctionalTest;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use AIGateway\AI\OpenAI\OpenAIConnector;
use AIGateway\AI\DTO\Input\OpenAIRequestDTO;
use AIGateway\Cache\FileCache;
use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\Middleware\RateLimitMiddleware;
use AIGateway\RateLimit\FileRateLimit;
use AIGateway\RateLimit\Exception\RateLimitExceededException;

class RateLimitFunctionalTest extends TestCase
{
    /** @var string */
    private $cacheDir;

    /** @var string */
    private $rateLimitDir;

    protected function setUp(): void
    {
        $id = uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/ai_gateway_rl_func_cache_' . $id;
        $this->rateLimitDir = sys_get_temp_dir() . '/ai_gateway_rl_func_rl_' . $id;
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->cacheDir);
        $this->cleanDir($this->rateLimitDir);
    }

    private function cleanDir(string $dir): void
    {
        $files = glob($dir . '/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    private function buildApiResponse(string $content): Response
    {
        return new Response(200, [], json_encode([
            'id' => 'chatcmpl-test',
            'model' => 'gpt-4',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]
        ]));
    }

    private function createMockHttpClient(array $responses): GuzzleHTTPClient
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $httpClient = $this->createMock(GuzzleHTTPClient::class);
        $httpClient->method('getClient')->willReturn($client);

        return $httpClient;
    }

    private function buildMiddleware(
        array $apiResponses,
        int $maxRequests = 10,
        int $windowSeconds = 60,
        string $rateLimitId = 'test'
    ): RateLimitMiddleware {
        $httpClient = $this->createMockHttpClient($apiResponses);
        $connector = new OpenAIConnector($httpClient);
        $connector->setApiKey('test-key');

        $cache = new FileCache($this->cacheDir);
        $rateLimiter = new FileRateLimit($this->rateLimitDir, $rateLimitId, $maxRequests, $windowSeconds);

        return new RateLimitMiddleware($connector, $rateLimiter, $cache);
    }

    /**
     * @test
     */
    public function cache_miss_decrements_counter_and_caches_response(): void
    {
        $middleware = $this->buildMiddleware([$this->buildApiResponse('API response')]);

        $request = new OpenAIRequestDTO('gpt-4', [['role' => 'user', 'content' => 'Hello']], 0.7, 1024, false);
        $response = $middleware->handle($request, '127.0.0.1');

        self::assertFalse($response->isFromCache());
        self::assertEquals('API response', $response->getContent());
        self::assertEquals(9, $response->getTriesRemaining());
    }

    /**
     * @test
     */
    public function cache_hit_returns_cached_and_does_not_decrement(): void
    {
        $middleware = $this->buildMiddleware([$this->buildApiResponse('API response')], 5);

        $request = new OpenAIRequestDTO('gpt-4', [['role' => 'user', 'content' => 'Hello']], 0.7, 1024, false);

        // First call: API call + consume
        $response1 = $middleware->handle($request, '127.0.0.1');
        self::assertFalse($response1->isFromCache());
        self::assertEquals(4, $response1->getTriesRemaining());

        // Second call: cache hit, no consume
        $response2 = $middleware->handle($request, '127.0.0.1');
        self::assertTrue($response2->isFromCache());
        self::assertNull($response2->getTriesRemaining());
    }

    /**
     * @test
     */
    public function counter_resets_after_window_expires(): void
    {
        $middleware = $this->buildMiddleware(
            [$this->buildApiResponse('r1'), $this->buildApiResponse('r2')],
            2,
            1  // 1 second window
        );

        $request = new OpenAIRequestDTO('gpt-4', [['role' => 'user', 'content' => 'Hi']], 0.7, 1024, true);

        // Exhaust limit
        $middleware->handle($request, 'u1');
        $middleware->handle($request, 'u1');

        $limiter = new FileRateLimit($this->rateLimitDir, 'test', 2, 1);
        self::assertFalse($limiter->isAllowed('u1'));

        sleep(2);

        self::assertTrue($limiter->isAllowed('u1'));
    }

    /**
     * @test
     */
    public function rate_limit_exceeded_after_max_requests(): void
    {
        $this->expectException(RateLimitExceededException::class);

        $middleware = $this->buildMiddleware(
            [$this->buildApiResponse('r1'), $this->buildApiResponse('r2')],
            2,
            60
        );

        $request = new OpenAIRequestDTO('gpt-4', [['role' => 'user', 'content' => 'Hi']], 0.7, 1024, true);

        $middleware->handle($request, 'u1');
        $middleware->handle($request, 'u1');

        // Third call should throw
        $middleware->handle($request, 'u1');
    }

    /**
     * @test
     */
    public function each_rate_limit_id_maintains_isolated_counter(): void
    {
        $httpClient1 = $this->createMockHttpClient([$this->buildApiResponse('r1'), $this->buildApiResponse('r2')]);
        $connector1 = new OpenAIConnector($httpClient1);
        $connector1->setApiKey('key');

        $httpClient2 = $this->createMockHttpClient([$this->buildApiResponse('r3')]);
        $connector2 = new OpenAIConnector($httpClient2);
        $connector2->setApiKey('key');

        $cache = new FileCache($this->cacheDir);

        $limiterA = new FileRateLimit($this->rateLimitDir, 'endpoint_a', 2, 60);
        $limiterB = new FileRateLimit($this->rateLimitDir, 'endpoint_b', 5, 60);

        $middlewareA = new RateLimitMiddleware($connector1, $limiterA, $cache);
        $middlewareB = new RateLimitMiddleware($connector2, $limiterB, $cache);

        $request = new OpenAIRequestDTO('gpt-4', [['role' => 'user', 'content' => 'A']], 0.7, 1024, true);

        // Exhaust endpoint_a for user_1
        $middlewareA->handle($request, 'user_1');
        $middlewareA->handle($request, 'user_1');

        self::assertFalse($limiterA->isAllowed('user_1'));
        self::assertTrue($limiterB->isAllowed('user_1'));

        // endpoint_b still works
        $response = $middlewareB->handle($request, 'user_1');
        self::assertEquals(4, $response->getTriesRemaining());
    }
}
