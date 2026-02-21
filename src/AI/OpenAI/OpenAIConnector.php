<?php

namespace AIGateway\AI\OpenAI;

use GuzzleHttp\Exception\GuzzleException;
use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\Cache\CacheInterface;
use AIGateway\Cache\FileCache;
use AIGateway\AI\AIConnector;
use AIGateway\AI\DTO\Input\AIRequestDTO;
use AIGateway\AI\DTO\Input\OpenAIRequestDTO;
use AIGateway\AI\DTO\Output\AIResponseDTO;
use AIGateway\AI\Exception\AIGatewayException;

class OpenAIConnector implements AIConnector
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

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
        if (!$request instanceof OpenAIRequestDTO) {
            throw new AIGatewayException('OpenAIConnector requires OpenAIRequestDTO');
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

        $content = $responseBody['choices'][0]['message']['content'] ?? '';
        $model = $responseBody['model'] ?? $request->getModel();
        $promptTokens = $responseBody['usage']['prompt_tokens'] ?? 0;
        $completionTokens = $responseBody['usage']['completion_tokens'] ?? 0;

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
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json'
            ]
        ];
    }

    private function generateCacheKey(OpenAIRequestDTO $request): string
    {
        return FileCache::generateCacheKey([
            'provider' => 'openai',
            'model' => $request->getModel(),
            'messages' => $request->getMessages(),
            'temperature' => $request->getTemperature(),
            'max_tokens' => $request->getMaxTokens(),
            'system_prompt' => $request->getSystemPrompt()
        ]);
    }
}
