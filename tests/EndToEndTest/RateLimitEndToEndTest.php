<?php

namespace AIGateway\Tests\EndToEndTest;

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

class RateLimitEndToEndTest extends TestCase
{
    /** @var string */
    private $cacheDir;

    /** @var string */
    private $rateLimitDir;

    protected function setUp(): void
    {
        $id = uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/ai_gateway_e2e_cache_' . $id;
        $this->rateLimitDir = sys_get_temp_dir() . '/ai_gateway_e2e_rl_' . $id;
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

    private function buildApiResponse(string $content, int $promptTokens = 10, int $completionTokens = 5): Response
    {
        return new Response(200, [], json_encode([
            'id' => 'chatcmpl-' . uniqid(),
            'model' => 'gpt-4',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens
            ]
        ]));
    }

    private function createConnectorWithMockedHttp(array $responses): OpenAIConnector
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $httpClient = $this->createMock(GuzzleHTTPClient::class);
        $httpClient->method('getClient')->willReturn($client);

        $connector = new OpenAIConnector($httpClient);
        $connector->setApiKey('test-api-key');

        return $connector;
    }

    /**
     * @test
     * Two identical prompts: second returns cached with same tries_remaining untouched.
     */
    public function second_identical_prompt_returns_cached_without_decrement(): void
    {
        $connector = $this->createConnectorWithMockedHttp([$this->buildApiResponse('Paris')]);
        $cache = new FileCache($this->cacheDir);
        $rateLimiter = new FileRateLimit($this->rateLimitDir, 'geo', 10, 60);
        $middleware = new RateLimitMiddleware($connector, $rateLimiter, $cache);

        $request = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'Capital of France?']],
            0.7,
            1024,
            false
        );

        // First call: API call, consume fires → 9 remaining
        $response1 = $middleware->handle($request, '10.0.0.1');
        self::assertFalse($response1->isFromCache());
        self::assertEquals('Paris', $response1->getContent());
        self::assertEquals(9, $response1->getTriesRemaining());

        // Second call: cache hit, no consume → tries_remaining is null (no decrement)
        $response2 = $middleware->handle($request, '10.0.0.1');
        self::assertTrue($response2->isFromCache());
        self::assertEquals('Paris', $response2->getContent());
        self::assertNull($response2->getTriesRemaining());

        // Confirm counter is at 1, not 2: this consume makes it 2, so remaining = max(10) - 2 = 8
        self::assertEquals(8, $rateLimiter->consume('10.0.0.1'));
    }

    /**
     * @test
     * Exhaust limit then verify 429.
     */
    public function exhausting_limit_raises_rate_limit_exceeded(): void
    {
        $this->expectException(RateLimitExceededException::class);
        $this->expectExceptionCode(429);

        $responses = array_fill(0, 3, $this->buildApiResponse('Response'));
        $connector = $this->createConnectorWithMockedHttp($responses);
        $cache = new FileCache($this->cacheDir);
        $rateLimiter = new FileRateLimit($this->rateLimitDir, 'exhaust', 2, 60);
        $middleware = new RateLimitMiddleware($connector, $rateLimiter, $cache);

        $request = new OpenAIRequestDTO('gpt-4', [['role' => 'user', 'content' => 'Query']], 0.7, 1024, true);

        $middleware->handle($request, 'ip_1');  // consume: 1 remaining
        $middleware->handle($request, 'ip_1');  // consume: 0 remaining
        $middleware->handle($request, 'ip_1');  // should throw
    }

    /**
     * @test
     * Separate rate_limit_id values do not share counters.
     */
    public function separate_rate_limit_ids_do_not_share_counters(): void
    {
        $responsesA = [$this->buildApiResponse('A1'), $this->buildApiResponse('A2')];
        $responsesB = [$this->buildApiResponse('B1')];

        $connectorA = $this->createConnectorWithMockedHttp($responsesA);
        $connectorB = $this->createConnectorWithMockedHttp($responsesB);

        $cache = new FileCache($this->cacheDir);

        $limiterA = new FileRateLimit($this->rateLimitDir, 'service_a', 2, 60);
        $limiterB = new FileRateLimit($this->rateLimitDir, 'service_b', 5, 60);

        $middlewareA = new RateLimitMiddleware($connectorA, $limiterA, $cache);
        $middlewareB = new RateLimitMiddleware($connectorB, $limiterB, $cache);

        $requestA = new OpenAIRequestDTO('gpt-4', [['role' => 'user', 'content' => 'A query']], 0.7, 1024, true);
        $requestB = new OpenAIRequestDTO('gpt-4', [['role' => 'user', 'content' => 'B query']], 0.7, 1024, true);

        // Exhaust service_a for ip_1
        $r1 = $middlewareA->handle($requestA, 'ip_1');
        self::assertEquals(1, $r1->getTriesRemaining());

        $r2 = $middlewareA->handle($requestA, 'ip_1');
        self::assertEquals(0, $r2->getTriesRemaining());

        // service_a exhausted for ip_1
        self::assertFalse($limiterA->isAllowed('ip_1'));

        // service_b unaffected
        $r3 = $middlewareB->handle($requestB, 'ip_1');
        self::assertEquals(4, $r3->getTriesRemaining());
        self::assertTrue($limiterB->isAllowed('ip_1'));
    }

    /**
     * @test
     * Concurrent requests should not over-decrement (uses pcntl_fork if available).
     */
    public function concurrent_requests_do_not_over_decrement(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork is not available on this platform.');
        }

        $maxRequests = 5;
        $concurrentProcesses = 3;

        // Build a shared rate limiter using the shared temp dirs
        $rateLimitDir = $this->rateLimitDir;
        $identifier = 'concurrent_user';
        $rateLimitId = 'concurrent_test';

        // Pre-create the directory before forking
        if (!is_dir($rateLimitDir)) {
            mkdir($rateLimitDir, 0755, true);
        }

        $pids = [];
        for ($i = 0; $i < $concurrentProcesses; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process
                $limiter = new FileRateLimit($rateLimitDir, $rateLimitId, $maxRequests, 60);
                $limiter->consume($identifier);
                exit(0);
            } else {
                $pids[] = $pid;
            }
        }

        // Wait for all children
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Read final count: should be exactly $concurrentProcesses
        $limiter = new FileRateLimit($rateLimitDir, $rateLimitId, $maxRequests, 60);
        $remaining = $limiter->consume($identifier);

        // $concurrentProcesses + 1 (this call) consumed, so remaining = max - (processes + 1)
        $expected = $maxRequests - ($concurrentProcesses + 1);
        self::assertEquals($expected, $remaining);
    }
}
