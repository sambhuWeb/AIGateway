<?php

namespace AIGateway\Tests\UnitTest\OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\Cache\CacheInterface;
use AIGateway\AI\DTO\Input\OpenAIRequestDTO;
use AIGateway\AI\Exception\AIGatewayException;
use AIGateway\AI\OpenAI\OpenAIConnector;

class OpenAIConnectorTest extends TestCase
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

    /** @var OpenAIConnector */
    private $openAIConnector;

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

        $this->openAIConnector = new OpenAIConnector($this->mockedGuzzleHTTPClient, $this->mockedCache);
    }

    /**
     * @test
     */
    public function it_correctly_sends_chat_request_and_returns_response(): void
    {
        $mockedResponseContent = [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'created' => 1677652288,
            'model' => 'gpt-4',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello! How can I help you today?'
                    ],
                    'finish_reason' => 'stop'
                ]
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 8,
                'total_tokens' => 18
            ]
        ];

        $this->mockedContent
            ->method('getContents')
            ->willReturn(json_encode($mockedResponseContent));

        $this->mockedCache
            ->method('has')
            ->willReturn(false);

        $request = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            false
        );

        $response = $this->openAIConnector
            ->setApiKey('test-api-key')
            ->chat($request);

        self::assertEquals('Hello! How can I help you today?', $response->getContent());
        self::assertEquals('gpt-4', $response->getModel());
        self::assertEquals(10, $response->getPromptTokens());
        self::assertEquals(8, $response->getCompletionTokens());
        self::assertEquals(18, $response->getTotalTokens());
        self::assertFalse($response->isFromCache());
    }

    /**
     * @test
     */
    public function it_returns_cached_response_when_available(): void
    {
        $cachedData = [
            'content' => 'Cached response',
            'model' => 'gpt-4',
            'prompt_tokens' => 5,
            'completion_tokens' => 3
        ];

        $this->mockedCache
            ->method('has')
            ->willReturn(true);

        $this->mockedCache
            ->method('get')
            ->willReturn(json_encode($cachedData));

        $request = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            false
        );

        $response = $this->openAIConnector
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
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'created' => 1677652288,
            'model' => 'gpt-4',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Fresh response'
                    ],
                    'finish_reason' => 'stop'
                ]
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 8,
                'total_tokens' => 18
            ]
        ];

        $this->mockedContent
            ->method('getContents')
            ->willReturn(json_encode($mockedResponseContent));

        $request = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            true
        );

        $response = $this->openAIConnector
            ->setApiKey('test-api-key')
            ->chat($request);

        self::assertEquals('Fresh response', $response->getContent());
        self::assertFalse($response->isFromCache());
    }

    /**
     * @test
     */
    public function it_includes_system_prompt_in_messages(): void
    {
        $mockedResponseContent = [
            'id' => 'chatcmpl-123',
            'model' => 'gpt-4',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Response with system prompt'
                    ]
                ]
            ],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 5,
                'total_tokens' => 20
            ]
        ];

        $this->mockedContent
            ->method('getContents')
            ->willReturn(json_encode($mockedResponseContent));

        $this->mockedCache
            ->method('has')
            ->willReturn(false);

        $request = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            false,
            'You are a helpful assistant.'
        );

        $payload = $request->toApiPayload();

        self::assertEquals('system', $payload['messages'][0]['role']);
        self::assertEquals('You are a helpful assistant.', $payload['messages'][0]['content']);
    }

    /**
     * @test
     */
    public function it_returns_response_as_json(): void
    {
        $mockedResponseContent = [
            'id' => 'chatcmpl-123',
            'model' => 'gpt-4',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Test response'
                    ]
                ]
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15
            ]
        ];

        $this->mockedContent
            ->method('getContents')
            ->willReturn(json_encode($mockedResponseContent));

        $this->mockedCache
            ->method('has')
            ->willReturn(false);

        $request = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => 'Hello']],
            0.7,
            1024,
            false
        );

        $response = $this->openAIConnector
            ->setApiKey('test-api-key')
            ->chat($request);

        $json = $response->toJson();
        $decoded = json_decode($json, true);

        self::assertEquals('Test response', $decoded['content']);
        self::assertEquals('gpt-4', $decoded['model']);
        self::assertEquals(10, $decoded['prompt_tokens']);
        self::assertEquals(5, $decoded['completion_tokens']);
        self::assertEquals(15, $decoded['total_tokens']);
        self::assertFalse($decoded['from_cache']);
    }
}
