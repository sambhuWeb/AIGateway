<?php

namespace AIGateway\AI\DTO\Output;

class AIResponseDTO
{
    /** @var string */
    private $content;

    /** @var string */
    private $model;

    /** @var int */
    private $promptTokens;

    /** @var int */
    private $completionTokens;

    /** @var bool */
    private $fromCache;

    /** @var int|null */
    private $triesRemaining = null;

    public function __construct(
        string $content,
        string $model,
        int $promptTokens,
        int $completionTokens,
        bool $fromCache = false
    ) {
        $this->content = $content;
        $this->model = $model;
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->fromCache = $fromCache;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getTotalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function isFromCache(): bool
    {
        return $this->fromCache;
    }

    public function getTriesRemaining(): ?int
    {
        return $this->triesRemaining;
    }

    public function withTriesRemaining(?int $triesRemaining): self
    {
        $clone = clone $this;
        $clone->triesRemaining = $triesRemaining;
        return $clone;
    }

    public function toArray(): array
    {
        $data = [
            'content' => $this->content,
            'model' => $this->model,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->getTotalTokens(),
            'from_cache' => $this->fromCache
        ];

        if ($this->triesRemaining !== null) {
            $data['tries_remaining'] = $this->triesRemaining;
        }

        return $data;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
