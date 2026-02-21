<?php

namespace AIGateway\Tests\FunctionalTest;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\Cache\FileCache;
use AIGateway\AI\OpenAI\OpenAIConnector;
use AIGateway\AI\DTO\Input\OpenAIRequestDTO;

class OpenAIFunctionalTest extends TestCase
{
    /** @var string */
    private $cacheDir;

    /** @var FileCache */
    private $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ai_gateway_functional_test_' . uniqid();
        $this->cache = new FileCache($this->cacheDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->cacheDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
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

    /**
     * @test
     */
    public function it_completes_full_chat_flow_with_openai(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'created' => 1677652288,
            'model' => 'gpt-4',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'PHP is a server-side scripting language.'
                    ],
                    'finish_reason' => 'stop'
                ]
            ],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 10,
                'total_tokens' => 25
            ]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse]);
        $connector = new OpenAIConnector($httpClient, $this->cache);
        $connector->setApiKey('test-api-key');

        $request = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'What is PHP?']],
            0.7,
            1024
        );

        $response = $connector->chat($request);

        self::assertEquals('PHP is a server-side scripting language.', $response->getContent());
        self::assertEquals('gpt-4', $response->getModel());
        self::assertEquals(15, $response->getPromptTokens());
        self::assertEquals(10, $response->getCompletionTokens());
        self::assertEquals(25, $response->getTotalTokens());
        self::assertFalse($response->isFromCache());
    }

    /**
     * @test
     */
    public function it_caches_response_and_returns_from_cache_on_second_call(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'chatcmpl-123',
            'model' => 'gpt-4',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Cached response content'
                    ]
                ]
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15
            ]
        ]));

        // Only one mock response - second call should use cache
        $httpClient = $this->createMockHttpClient([$mockResponse]);
        $connector = new OpenAIConnector($httpClient, $this->cache);
        $connector->setApiKey('test-api-key');

        $request = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'Test message']],
            0.7,
            1024,
            false
        );

        // First call - should hit API
        $response1 = $connector->chat($request);
        self::assertFalse($response1->isFromCache());
        self::assertEquals('Cached response content', $response1->getContent());

        // Second call - should return from cache
        $response2 = $connector->chat($request);
        self::assertTrue($response2->isFromCache());
        self::assertEquals('Cached response content', $response2->getContent());
    }

    /**
     * @test
     */
    public function it_bypasses_cache_when_fresh_flag_is_set(): void
    {
        $mockResponse1 = new Response(200, [], json_encode([
            'id' => 'chatcmpl-123',
            'model' => 'gpt-4',
            'choices' => [['message' => ['content' => 'First response']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]
        ]));

        $mockResponse2 = new Response(200, [], json_encode([
            'id' => 'chatcmpl-456',
            'model' => 'gpt-4',
            'choices' => [['message' => ['content' => 'Fresh response']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse1, $mockResponse2]);
        $connector = new OpenAIConnector($httpClient, $this->cache);
        $connector->setApiKey('test-api-key');

        // First call without fresh
        $request1 = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'Test']],
            0.7,
            1024,
            false
        );
        $response1 = $connector->chat($request1);
        self::assertEquals('First response', $response1->getContent());

        // Second call with fresh=true should bypass cache
        $request2 = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'Test']],
            0.7,
            1024,
            true
        );
        $response2 = $connector->chat($request2);
        self::assertEquals('Fresh response', $response2->getContent());
        self::assertFalse($response2->isFromCache());
    }

    /**
     * @test
     */
    public function it_includes_system_prompt_in_request(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'chatcmpl-123',
            'model' => 'gpt-4',
            'choices' => [['message' => ['content' => 'Response with system context']]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5, 'total_tokens' => 25]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse]);
        $connector = new OpenAIConnector($httpClient);
        $connector->setApiKey('test-api-key');

        $request = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            false,
            'You are a helpful coding assistant.'
        );

        // Verify the payload includes system message
        $payload = $request->toApiPayload();
        self::assertCount(2, $payload['messages']);
        self::assertEquals('system', $payload['messages'][0]['role']);
        self::assertEquals('You are a helpful coding assistant.', $payload['messages'][0]['content']);

        $response = $connector->chat($request);
        self::assertEquals('Response with system context', $response->getContent());
    }

    /**
     * @test
     */
    public function it_returns_valid_json_response(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'chatcmpl-123',
            'model' => 'gpt-4',
            'choices' => [['message' => ['content' => 'JSON test response']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse]);
        $connector = new OpenAIConnector($httpClient);
        $connector->setApiKey('test-api-key');

        $request = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'Test']],
            0.7,
            1024
        );

        $response = $connector->chat($request);
        $json = $response->toJson();
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('content', $decoded);
        self::assertArrayHasKey('model', $decoded);
        self::assertArrayHasKey('prompt_tokens', $decoded);
        self::assertArrayHasKey('completion_tokens', $decoded);
        self::assertArrayHasKey('total_tokens', $decoded);
        self::assertArrayHasKey('from_cache', $decoded);
    }

    /**
     * @test
     */
    public function it_handles_multi_turn_conversation(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'chatcmpl-123',
            'model' => 'gpt-4',
            'choices' => [['message' => ['content' => 'The capital of France is Paris.']]],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse]);
        $connector = new OpenAIConnector($httpClient);
        $connector->setApiKey('test-api-key');

        $messages = [
            ['role' => 'user', 'content' => 'Hello, I have a question.'],
            ['role' => 'assistant', 'content' => 'Of course! What would you like to know?'],
            ['role' => 'user', 'content' => 'What is the capital of France?']
        ];

        $request = new OpenAIRequestDTO('gpt-4', $messages, 0.7, 1024);
        $response = $connector->chat($request);

        self::assertEquals('The capital of France is Paris.', $response->getContent());
        self::assertEquals(50, $response->getPromptTokens());
    }
}
