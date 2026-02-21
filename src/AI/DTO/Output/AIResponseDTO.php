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

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'model' => $this->model,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->getTotalTokens(),
            'from_cache' => $this->fromCache
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
