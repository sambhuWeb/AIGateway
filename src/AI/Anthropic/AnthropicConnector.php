<?php

namespace AIGateway\AI\Anthropic;

use GuzzleHttp\Exception\GuzzleException;
use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\Cache\CacheInterface;
use AIGateway\Cache\FileCache;
use AIGateway\AI\AIConnector;
use AIGateway\AI\DTO\Input\AIRequestDTO;
use AIGateway\AI\DTO\Input\AnthropicRequestDTO;
use AIGateway\AI\DTO\Output\AIResponseDTO;
use AIGateway\AI\Exception\AIGatewayException;

class AnthropicConnector implements AIConnector
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';

    /** @var GuzzleHTTPClient */
    private $httpClient;

    /** @var string */
    private $apiKey;

    /** @var CacheInterface|null */
    private $cache;

    public function __construct(GuzzleHTTPClient $httpClient, ?CacheInterface $cache = null)
    {
        $this->httpClient = $httpClient;
        $this->cache = $cache;
    }

    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * @param AIRequestDTO $request
     * @return AIResponseDTO
     * @throws AIGatewayException
     */
    public function chat(AIRequestDTO $request): AIResponseDTO
    {
        if (!$request instanceof AnthropicRequestDTO) {
            throw new AIGatewayException('AnthropicConnector requires AnthropicRequestDTO');
        }

        $cacheKey = $this->generateCacheKey($request);

        if (!$request->isFresh() && $this->cache !== null && $this->cache->has($cacheKey)) {
            $cachedData = json_decode($this->cache->get($cacheKey), true);
            return new AIResponseDTO(
                $cachedData['content'],
                $cachedData['model'],
                $cachedData['prompt_tokens'],
                $cachedData['completion_tokens'],
                true
            );
        }

        $client = $this->httpClient->getClient($this->getClientConfig());

        try {
            $response = $client->request(
                'POST',
                self::API_URL,
                [
                    'json' => $request->toApiPayload()
                ]
            );
        } catch (GuzzleException $exception) {
            throw new AIGatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }

        $responseBody = json_decode($response->getBody()->getContents(), true);

        $content = '';
        if (isset($responseBody['content']) && is_array($responseBody['content'])) {
            foreach ($responseBody['content'] as $block) {
                if (isset($block['type']) && $block['type'] === 'text') {
                    $content .= $block['text'];
                }
            }
        }

        $model = $responseBody['model'] ?? $request->getModel();
        $promptTokens = $responseBody['usage']['input_tokens'] ?? 0;
        $completionTokens = $responseBody['usage']['output_tokens'] ?? 0;

        $aiResponse = new AIResponseDTO(
            $content,
            $model,
            $promptTokens,
            $completionTokens,
            false
        );

        if ($this->cache !== null) {
            $this->cache->set($cacheKey, json_encode([
                'content' => $content,
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens
            ]));
        }

        return $aiResponse;
    }

    private function getClientConfig(): array
    {
        return [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'Content-Type' => 'application/json'
            ]
        ];
    }

    private function generateCacheKey(AnthropicRequestDTO $request): string
    {
        return FileCache::generateCacheKey([
            'provider' => 'anthropic',
            'model' => $request->getModel(),
            'messages' => $request->getMessages(),
            'temperature' => $request->getTemperature(),
            'max_tokens' => $request->getMaxTokens(),
            'system' => $request->getSystem()
        ]);
    }
}
