<?php

namespace AIGateway\Tests\UnitTest\Anthropic;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\Cache\CacheInterface;
use AIGateway\AI\DTO\Input\AnthropicRequestDTO;
use AIGateway\AI\Exception\AIGatewayException;
use AIGateway\AI\Anthropic\AnthropicConnector;

class AnthropicConnectorTest extends TestCase
{
    /** @var MockObject|GuzzleHTTPClient */
    private $mockedGuzzleHTTPClient;

    /** @var MockObject|Client */
    private $mockedClient;

    /** @var MockObject|Response */
    private $mockedResponse;

    /** @var MockObject|Stream */
    private $mockedContent;

    /** @var MockObject|CacheInterface */
    private $mockedCache;

    /** @var AnthropicConnector */
    private $anthropicConnector;

    protected function setUp(): void
    {
        $this->mockedGuzzleHTTPClient = $this
            ->getMockBuilder(GuzzleHTTPClient::class)
            ->onlyMethods(['getClient'])
            ->getMock();

        $this->mockedClient = $this
            ->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();

        $this->mockedResponse = $this
            ->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBody'])
            ->getMock();

        $this->mockedContent = $this
            ->getMockBuilder(Stream::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getContents'])
            ->getMock();

        $this->mockedCache = $this
            ->getMockBuilder(CacheInterface::class)
            ->getMock();

        $this->mockedClient->method('request')->willReturn($this->mockedResponse);
        $this->mockedResponse->method('getBody')->willReturn($this->mockedContent);
        $this->mockedGuzzleHTTPClient->method('getClient')->willReturn($this->mockedClient);

        $this->anthropicConnector = new AnthropicConnector($this->mockedGuzzleHTTPClient, $this->mockedCache);
    }

    /**
     * @test
     */
    public function it_correctly_sends_chat_request_and_returns_response(): void
    {
        $mockedResponseContent = [
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-3-opus-20240229',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Hello! How can I help you today?'
                ]
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 9
            ]
        ];

        $this->mockedContent
            ->method('getContents')
            ->willReturn(json_encode($mockedResponseContent));

        $this->mockedCache
            ->method('has')
            ->willReturn(false);

        $request = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            false
        );

        $response = $this->anthropicConnector
            ->setApiKey('test-api-key')
            ->chat($request);

        self::assertEquals('Hello! How can I help you today?', $response->getContent());
        self::assertEquals('claude-3-opus-20240229', $response->getModel());
        self::assertEquals(12, $response->getPromptTokens());
        self::assertEquals(9, $response->getCompletionTokens());
        self::assertEquals(21, $response->getTotalTokens());
        self::assertFalse($response->isFromCache());
    }

    /**
     * @test
     */
    public function it_returns_cached_response_when_available(): void
    {
        $cachedData = [
            'content' => 'Cached response',
            'model' => 'claude-3-opus-20240229',
            'prompt_tokens' => 5,
            'completion_tokens' => 3
        ];

        $this->mockedCache
            ->method('has')
            ->willReturn(true);

        $this->mockedCache
            ->method('get')
            ->willReturn(json_encode($cachedData));

        $request = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            false
        );

        $response = $this->anthropicConnector
            ->setApiKey('test-api-key')
            ->chat($request);

        self::assertEquals('Cached response', $response->getContent());
        self::assertTrue($response->isFromCache());
    }

    /**
     * @test
     */
    public function it_bypasses_cache_when_fresh_is_true(): void
    {
        $mockedResponseContent = [
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-3-opus-20240229',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Fresh response'
                ]
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 8
            ]
        ];

        $this->mockedContent
            ->method('getContents')
            ->willReturn(json_encode($mockedResponseContent));

        $request = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            true
        );

        $response = $this->anthropicConnector
            ->setApiKey('test-api-key')
            ->chat($request);

        self::assertEquals('Fresh response', $response->getContent());
        self::assertFalse($response->isFromCache());
    }

    /**
     * @test
     */
    public function it_includes_system_parameter_in_payload(): void
    {
        $request = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            false,
            'You are a helpful assistant.'
        );

        $payload = $request->toApiPayload();

        self::assertEquals('You are a helpful assistant.', $payload['system']);
        self::assertEquals('claude-3-opus-20240229', $payload['model']);
        self::assertEquals(0.7, $payload['temperature']);
        self::assertEquals(1024, $payload['max_tokens']);
    }

    /**
     * @test
     */
    public function it_handles_multiple_content_blocks(): void
    {
        $mockedResponseContent = [
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-3-opus-20240229',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'First part. '
                ],
                [
                    'type' => 'text',
                    'text' => 'Second part.'
                ]
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 15
            ]
        ];

        $this->mockedContent
            ->method('getContents')
            ->willReturn(json_encode($mockedResponseContent));

        $this->mockedCache
            ->method('has')
            ->willReturn(false);

        $request = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            false
        );

        $response = $this->anthropicConnector
            ->setApiKey('test-api-key')
            ->chat($request);

        self::assertEquals('First part. Second part.', $response->getContent());
    }

    /**
     * @test
     */
    public function it_returns_response_as_json(): void
    {
        $mockedResponseContent = [
            'id' => 'msg_123',
            'model' => 'claude-3-opus-20240229',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Test response'
                ]
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5
            ]
        ];

        $this->mockedContent
            ->method('getContents')
            ->willReturn(json_encode($mockedResponseContent));

        $this->mockedCache
            ->method('has')
            ->willReturn(false);

        $request = new AnthropicRequestDTO(
            'claude-3-opus-20240229',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            false
        );

        $response = $this->anthropicConnector
            ->setApiKey('test-api-key')
            ->chat($request);

        $json = $response->toJson();
        $decoded = json_decode($json, true);

        self::assertEquals('Test response', $decoded['content']);
        self::assertEquals('claude-3-opus-20240229', $decoded['model']);
        self::assertEquals(10, $decoded['prompt_tokens']);
        self::assertEquals(5, $decoded['completion_tokens']);
        self::assertEquals(15, $decoded['total_tokens']);
        self::assertFalse($decoded['from_cache']);
    }
}
