<?php

namespace AIGateway\AI;

use AIGateway\AI\DTO\Input\AIRequestDTO;
use AIGateway\AI\DTO\Output\AIResponseDTO;

interface AIConnector
{
    public function chat(AIRequestDTO $request): AIResponseDTO;
}
