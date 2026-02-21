<?php

namespace AIGateway\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class GuzzleHTTPClient
{
    public function getClient(array $config = []): ClientInterface
    {
        return new Client($config);
    }
}
