<?php

namespace AIGateway\AI\DTO\Input;

class AnthropicRequestDTO implements AIRequestDTO
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
    private $system;

    public function __construct(
        string $model,
        array $messages,
        float $temperature = 0.7,
        int $maxTokens = 1024,
        bool $fresh = false,
        ?string $system = null
    ) {
        $this->model = $model;
        $this->messages = $messages;
        $this->temperature = $temperature;
        $this->maxTokens = $maxTokens;
        $this->fresh = $fresh;
        $this->system = $system;
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

    public function getSystem(): ?string
    {
        return $this->system;
    }

    public function toApiPayload(): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $this->messages,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens
        ];

        if ($this->system !== null) {
            $payload['system'] = $this->system;
        }

        return $payload;
    }
}
