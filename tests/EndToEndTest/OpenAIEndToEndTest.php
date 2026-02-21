<?php

namespace AIGateway\Tests\EndToEndTest;

use PHPUnit\Framework\TestCase;
use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\Cache\FileCache;
use AIGateway\AI\OpenAI\OpenAIConnector;
use AIGateway\AI\DTO\Input\OpenAIRequestDTO;
use AIGateway\AI\Exception\AIGatewayException;

/**
 * End-to-end tests for OpenAI API integration.
 *
 * These tests make real API calls and require a valid API key.
 * Set the OPENAI_API_KEY environment variable to run these tests.
 *
 * Run with: OPENAI_API_KEY=your-key vendor/bin/phpunit tests/EndToEndTest/OpenAIEndToEndTest.php
 */
class OpenAIEndToEndTest extends TestCase
{
    /** @var string|null */
    private $apiKey;

    /** @var OpenAIConnector */
    private $connector;

    /** @var string */
    private $cacheDir;

    /** @var FileCache */
    private $cache;

    protected function setUp(): void
    {
        $this->apiKey = getenv('OPENAI_API_KEY');

        if (empty($this->apiKey)) {
            $this->markTestSkipped(
                'OpenAI API key not provided. Set OPENAI_API_KEY environment variable to run E2E tests.'
            );
        }

        $this->cacheDir = sys_get_temp_dir() . '/ai_gateway_e2e_test_' . uniqid();
        $this->cache = new FileCache($this->cacheDir);

        $httpClient = new GuzzleHTTPClient();
        $this->connector = new OpenAIConnector($httpClient, $this->cache);
        $this->connector->setApiKey($this->apiKey);
    }

    protected function tearDown(): void
    {
        if (isset($this->cacheDir) && is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->cacheDir);
        }
    }

    /**
     * @test
     * @group e2e
     */
    public function it_sends_real_request_to_openai_api(): void
    {
        $request = new OpenAIRequestDTO(
            'gpt-3.5-turbo',
            [['role' => 'user', 'content' => 'Say "Hello" and nothing else.']],
            0.0,
            10,
            true
        );

        $response = $this->connector->chat($request);

        self::assertNotEmpty($response->getContent());
        self::assertStringContainsStringIgnoringCase('hello', $response->getContent());
        self::assertNotEmpty($response->getModel());
        self::assertGreaterThan(0, $response->getPromptTokens());
        self::assertGreaterThan(0, $response->getCompletionTokens());
        self::assertFalse($response->isFromCache());
    }

    /**
     * @test
     * @group e2e
     */
    public function it_caches_response_from_real_api_call(): void
    {
        $request = new OpenAIRequestDTO(
            'gpt-3.5-turbo',
            [['role' => 'user', 'content' => 'What is 2+2? Answer with just the number.']],
            0.0,
            5,
            false
        );

        // First call - should hit API
        $response1 = $this->connector->chat($request);
        self::assertFalse($response1->isFromCache());
        $content1 = $response1->getContent();

        // Second call - should return from cache
        $response2 = $this->connector->chat($request);
        self::assertTrue($response2->isFromCache());
        self::assertEquals($content1, $response2->getContent());
    }

    /**
     * @test
     * @group e2e
     */
    public function it_handles_system_prompt_in_real_request(): void
    {
        $request = new OpenAIRequestDTO(
            'gpt-3.5-turbo',
            [['role' => 'user', 'content' => 'What language are you specialized in?']],
            0.0,
            50,
            true,
            'You are a PHP expert. Always mention PHP in your responses.'
        );

        $response = $this->connector->chat($request);

        self::assertNotEmpty($response->getContent());
        self::assertStringContainsStringIgnoringCase('PHP', $response->getContent());
    }

    /**
     * @test
     * @group e2e
     */
    public function it_handles_multi_turn_conversation_with_real_api(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Remember the number 42.'],
            ['role' => 'assistant', 'content' => 'I will remember the number 42.'],
            ['role' => 'user', 'content' => 'What number did I ask you to remember? Reply with just the number.']
        ];

        $request = new OpenAIRequestDTO(
            'gpt-3.5-turbo',
            $messages,
            0.0,
            10,
            true
        );

        $response = $this->connector->chat($request);

        self::assertStringContainsString('42', $response->getContent());
    }

    /**
     * @test
     * @group e2e
     */
    public function it_returns_valid_json_from_real_api(): void
    {
        $request = new OpenAIRequestDTO(
            'gpt-3.5-turbo',
            [['role' => 'user', 'content' => 'Say "test"']],
            0.0,
            5,
            true
        );

        $response = $this->connector->chat($request);
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
     * @group e2e
     */
    public function it_throws_exception_for_invalid_api_key(): void
    {
        $httpClient = new GuzzleHTTPClient();
        $connector = new OpenAIConnector($httpClient);
        $connector->setApiKey('invalid-api-key');

        $request = new OpenAIRequestDTO(
            'gpt-3.5-turbo',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            10
        );

        $this->expectException(AIGatewayException::class);
        $connector->chat($request);
    }
}
