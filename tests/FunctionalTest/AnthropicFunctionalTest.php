<?php

namespace AIGateway\Tests\FunctionalTest;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\Cache\FileCache;
use AIGateway\AI\Anthropic\AnthropicConnector;
use AIGateway\AI\DTO\Input\AnthropicRequestDTO;

class AnthropicFunctionalTest extends TestCase
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
    public function it_completes_full_chat_flow_with_anthropic(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-3-opus-20240229',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'PHP is a popular server-side scripting language.'
                ]
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 15,
                'output_tokens' => 12
            ]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse]);
        $connector = new AnthropicConnector($httpClient, $this->cache);
        $connector->setApiKey('test-api-key');

        $request = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
            [['role' => 'user', 'content' => 'What is PHP?']],
            0.7,
            1024
        );

        $response = $connector->chat($request);

        self::assertEquals('PHP is a popular server-side scripting language.', $response->getContent());
        self::assertEquals('claude-3-opus-20240229', $response->getModel());
        self::assertEquals(15, $response->getPromptTokens());
        self::assertEquals(12, $response->getCompletionTokens());
        self::assertEquals(27, $response->getTotalTokens());
        self::assertFalse($response->isFromCache());
    }

    /**
     * @test
     */
    public function it_caches_response_and_returns_from_cache_on_second_call(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'msg_123',
            'model' => 'claude-3-opus-20240229',
            'content' => [['type' => 'text', 'text' => 'Cached Anthropic response']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse]);
        $connector = new AnthropicConnector($httpClient, $this->cache);
        $connector->setApiKey('test-api-key');

        $request = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
            [['role' => 'user', 'content' => 'Test message']],
            0.7,
            1024,
            false
        );

        // First call - should hit API
        $response1 = $connector->chat($request);
        self::assertFalse($response1->isFromCache());

        // Second call - should return from cache
        $response2 = $connector->chat($request);
        self::assertTrue($response2->isFromCache());
        self::assertEquals('Cached Anthropic response', $response2->getContent());
    }

    /**
     * @test
     */
    public function it_handles_multiple_content_blocks(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'msg_123',
            'model' => 'claude-3-opus-20240229',
            'content' => [
                ['type' => 'text', 'text' => 'Here is the first part. '],
                ['type' => 'text', 'text' => 'And here is the second part. '],
                ['type' => 'text', 'text' => 'Finally, the conclusion.']
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse]);
        $connector = new AnthropicConnector($httpClient);
        $connector->setApiKey('test-api-key');

        $request = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
            [['role' => 'user', 'content' => 'Give me a multi-part response']],
            0.7,
            1024
        );

        $response = $connector->chat($request);

        self::assertEquals(
            'Here is the first part. And here is the second part. Finally, the conclusion.',
            $response->getContent()
        );
    }

    /**
     * @test
     */
    public function it_includes_system_parameter_in_request(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'msg_123',
            'model' => 'claude-3-opus-20240229',
            'content' => [['type' => 'text', 'text' => 'Response with system context']],
            'usage' => ['input_tokens' => 25, 'output_tokens' => 5]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse]);
        $connector = new AnthropicConnector($httpClient);
        $connector->setApiKey('test-api-key');

        $request = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            false,
            'You are a helpful coding assistant specialized in PHP.'
        );

        // Verify the payload includes system as separate parameter
        $payload = $request->toApiPayload();
        self::assertArrayHasKey('system', $payload);
        self::assertEquals('You are a helpful coding assistant specialized in PHP.', $payload['system']);
        self::assertCount(1, $payload['messages']); // System is NOT in messages for Anthropic

        $response = $connector->chat($request);
        self::assertEquals('Response with system context', $response->getContent());
    }

    /**
     * @test
     */
    public function it_bypasses_cache_when_fresh_flag_is_set(): void
    {
        $mockResponse1 = new Response(200, [], json_encode([
            'id' => 'msg_123',
            'model' => 'claude-3-opus-20240229',
            'content' => [['type' => 'text', 'text' => 'First response']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5]
        ]));

        $mockResponse2 = new Response(200, [], json_encode([
            'id' => 'msg_456',
            'model' => 'claude-3-opus-20240229',
            'content' => [['type' => 'text', 'text' => 'Fresh response']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse1, $mockResponse2]);
        $connector = new AnthropicConnector($httpClient, $this->cache);
        $connector->setApiKey('test-api-key');

        // First call
        $request1 = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
            [['role' => 'user', 'content' => 'Test']],
            0.7,
            1024,
            false
        );
        $response1 = $connector->chat($request1);
        self::assertEquals('First response', $response1->getContent());

        // Second call with fresh=true
        $request2 = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
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
    public function it_handles_multi_turn_conversation(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'msg_123',
            'model' => 'claude-3-opus-20240229',
            'content' => [['type' => 'text', 'text' => 'The capital of Japan is Tokyo.']],
            'usage' => ['input_tokens' => 60, 'output_tokens' => 10]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse]);
        $connector = new AnthropicConnector($httpClient);
        $connector->setApiKey('test-api-key');

        $messages = [
            ['role' => 'user', 'content' => 'I want to learn about world capitals.'],
            ['role' => 'assistant', 'content' => 'Sure! I can help with that. Which country would you like to know about?'],
            ['role' => 'user', 'content' => 'What is the capital of Japan?']
        ];

        $request = new AnthropicRequestDTO('claude-3-opus-20240229', $messages, 0.7, 1024);
        $response = $connector->chat($request);

        self::assertEquals('The capital of Japan is Tokyo.', $response->getContent());
        self::assertEquals(60, $response->getPromptTokens());
    }

    /**
     * @test
     */
    public function it_returns_valid_json_response(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'msg_123',
            'model' => 'claude-3-opus-20240229',
            'content' => [['type' => 'text', 'text' => 'JSON test']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5]
        ]));

        $httpClient = $this->createMockHttpClient([$mockResponse]);
        $connector = new AnthropicConnector($httpClient);
        $connector->setApiKey('test-api-key');

        $request = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
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
        self::assertEquals(15, $decoded['total_tokens']);
    }
}
