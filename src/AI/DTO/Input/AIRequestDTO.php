<?php

namespace AIGateway\AI\DTO\Input;

interface AIRequestDTO
{
    public function getModel(): string;

    public function getMessages(): array;

    public function getTemperature(): ?float;

    public function getMaxTokens(): int;

    public function isFresh(): bool;
}
