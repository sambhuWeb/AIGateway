<?php

namespace AIGateway\AI\DTO\Input;

class OpenAIRequestDTO implements AIRequestDTO
{
    /** @var string */
    private $model;

    /** @var array */
    private $messages;

    /** @var float */
    private $temperature;

    /** @var int */
    private $maxTokens;

    /** @var bool */
    private $fresh;

    /** @var string|null */
    private $systemPrompt;

    public function __construct(
        string $model,
        array $messages,
        float $temperature = 0.7,
        int $maxTokens = 1024,
        bool $fresh = false,
        ?string $systemPrompt = null
    ) {
        $this->model = $model;
        $this->messages = $messages;
        $this->temperature = $temperature;
        $this->maxTokens = $maxTokens;
        $this->fresh = $fresh;
        $this->systemPrompt = $systemPrompt;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getTemperature(): float
    {
        return $this->temperature;
    }

    public function getMaxTokens(): int
    {
        return $this->maxTokens;
    }

    public function isFresh(): bool
    {
        return $this->fresh;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    public function toApiPayload(): array
    {
        $messages = $this->messages;

        if ($this->systemPrompt !== null) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => $this->systemPrompt
            ]);
        }

        return [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens
        ];
    }
}
