# AIGateway

A PHP Composer package that provides a unified interface for connecting to AI chat services (OpenAI and Anthropic). It accepts configuration parameters (temperature, model, max_tokens, API key) and returns responses in JSON format.

## Requirements

- PHP 7.4 or higher
- Composer
- ext-json

## Installation

```bash
composer require elt/ai-gateway
```

Or add to your `composer.json`:

```json
{
    "require": {
        "elt/ai-gateway": "^1.0"
    }
}
```

Then run:

```bash
composer install
```

## Quick Start

### OpenAI Example

```php
<?php

require_once 'vendor/autoload.php';

use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\AI\OpenAI\OpenAIConnector;
use AIGateway\AI\DTO\Input\OpenAIRequestDTO;

// Create the connector
$httpClient = new GuzzleHTTPClient();
$openAI = new OpenAIConnector($httpClient);

// Set your API key
$openAI->setApiKey('your-openai-api-key');

// Create a request
$request = new OpenAIRequestDTO(
    'gpt-4',                                          // model
    [['role' => 'user', 'content' => 'Hello!']],     // messages
    0.7,                                              // temperature
    1024,                                             // max_tokens
    false,                                            // fresh (bypass cache)
    'You are a helpful assistant.'                    // system prompt (optional)
);

// Send the request
$response = $openAI->chat($request);

// Get the response
echo $response->getContent();
echo $response->getModel();
echo $response->getPromptTokens();
echo $response->getCompletionTokens();
echo $response->getTotalTokens();
echo $response->isFromCache() ? 'Yes' : 'No';

// Get response as JSON
echo $response->toJson();
```

### Anthropic Example

```php
<?php

require_once 'vendor/autoload.php';

use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\AI\Anthropic\AnthropicConnector;
use AIGateway\AI\DTO\Input\AnthropicRequestDTO;

// Create the connector
$httpClient = new GuzzleHTTPClient();
$anthropic = new AnthropicConnector($httpClient);

// Set your API key
$anthropic->setApiKey('your-anthropic-api-key');

// Create a request
$request = new AnthropicRequestDTO(
    'claude-3-opus-20240229',                         // model
    [['role' => 'user', 'content' => 'Hello!']],     // messages
    0.7,                                              // temperature
    1024,                                             // max_tokens
    false,                                            // fresh (bypass cache)
    'You are a helpful assistant.'                    // system (optional)
);

// Send the request
$response = $anthropic->chat($request);

// Get the response
echo $response->getContent();
```

## Using Caching

The package includes a file-based caching system to avoid redundant API calls for identical requests.

### Enable Caching

```php
<?php

use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\Cache\FileCache;
use AIGateway\AI\OpenAI\OpenAIConnector;
use AIGateway\AI\DTO\Input\OpenAIRequestDTO;

// Create cache instance (optional: specify cache directory)
$cache = new FileCache('/path/to/cache/directory');

// Or use default temp directory
$cache = new FileCache();

// Create connector with cache
$httpClient = new GuzzleHTTPClient();
$openAI = new OpenAIConnector($httpClient, $cache);
$openAI->setApiKey('your-openai-api-key');

// First request - hits API
$request = new OpenAIRequestDTO(
    'gpt-4',
    [['role' => 'user', 'content' => 'What is PHP?']],
    0.7,
    1024,
    false  // Use cache if available
);
$response = $openAI->chat($request);
echo $response->isFromCache(); // false

// Second identical request - returns cached response
$response = $openAI->chat($request);
echo $response->isFromCache(); // true
```

### Bypass Cache (Fresh Request)

```php
<?php

// Set fresh = true to bypass cache
$request = new OpenAIRequestDTO(
    'gpt-4',
    [['role' => 'user', 'content' => 'What is PHP?']],
    0.7,
    1024,
    true  // Force fresh API call, ignore cache
);

$response = $openAI->chat($request);
echo $response->isFromCache(); // false (always)
```

### Custom Cache TTL

```php
<?php

use AIGateway\Cache\FileCache;

$cache = new FileCache('/path/to/cache');

// Set cache with custom TTL (in seconds)
$cache->set('my_key', 'my_value', 7200); // 2 hours

// Check if key exists
if ($cache->has('my_key')) {
    $value = $cache->get('my_key');
}

// Delete a cache entry
$cache->delete('my_key');
```

### Custom Cache Implementation

You can implement your own cache (Redis, Memcached, etc.) by implementing `CacheInterface`:

```php
<?php

use AIGateway\Cache\CacheInterface;

class RedisCache implements CacheInterface
{
    private $redis;

    public function __construct(\Redis $redis)
    {
        $this->redis = $redis;
    }

    public function get(string $key): ?string
    {
        $value = $this->redis->get($key);
        return $value === false ? null : $value;
    }

    public function set(string $key, string $value, int $ttl = 3600): void
    {
        $this->redis->setex($key, $ttl, $value);
    }

    public function has(string $key): bool
    {
        return $this->redis->exists($key);
    }

    public function delete(string $key): void
    {
        $this->redis->del($key);
    }
}

// Use with connector
$cache = new RedisCache($redisInstance);
$openAI = new OpenAIConnector($httpClient, $cache);
```

## API Reference

### OpenAIRequestDTO

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| model | string | required | OpenAI model (e.g., 'gpt-4', 'gpt-3.5-turbo') |
| messages | array | required | Array of message objects with 'role' and 'content' |
| temperature | float | 0.7 | Sampling temperature (0.0 - 2.0) |
| maxTokens | int | 1024 | Maximum tokens in response |
| fresh | bool | false | Bypass cache when true |
| systemPrompt | string | null | System message prepended to messages |

### AnthropicRequestDTO

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| model | string | required | Anthropic model (e.g., 'claude-3-opus-20240229') |
| messages | array | required | Array of message objects with 'role' and 'content' |
| temperature | float | 0.7 | Sampling temperature (0.0 - 1.0) |
| maxTokens | int | 1024 | Maximum tokens in response |
| fresh | bool | false | Bypass cache when true |
| system | string | null | System instruction (sent as separate parameter) |

### AIResponseDTO

| Method | Return Type | Description |
|--------|-------------|-------------|
| getContent() | string | The AI response text |
| getModel() | string | Model used for generation |
| getPromptTokens() | int | Tokens in the prompt |
| getCompletionTokens() | int | Tokens in the response |
| getTotalTokens() | int | Total tokens used |
| isFromCache() | bool | Whether response was from cache |
| toArray() | array | Response as associative array |
| toJson() | string | Response as JSON string |

### JSON Response Format

```json
{
    "content": "The AI response text here...",
    "model": "gpt-4",
    "prompt_tokens": 25,
    "completion_tokens": 150,
    "total_tokens": 175,
    "from_cache": false
}
```

## Message Format

Messages should be an array of objects with `role` and `content`:

```php
$messages = [
    ['role' => 'user', 'content' => 'Hello, how are you?'],
    ['role' => 'assistant', 'content' => 'I am doing well, thank you!'],
    ['role' => 'user', 'content' => 'What can you help me with?']
];
```

Valid roles:
- `user` - Human messages
- `assistant` - AI responses (for conversation history)
- `system` - System instructions (OpenAI only, use systemPrompt parameter instead)

## Error Handling

```php
<?php

use AIGateway\AI\Exception\AIGatewayException;

try {
    $response = $openAI->chat($request);
} catch (AIGatewayException $e) {
    echo "AI Gateway Error: " . $e->getMessage();
    echo "Error Code: " . $e->getCode();
}
```

## Available Models

### OpenAI Models
- `gpt-4`
- `gpt-4-turbo`
- `gpt-4o`
- `gpt-3.5-turbo`

### Anthropic Models
- `claude-3-opus-20240229`
- `claude-3-sonnet-20240229`
- `claude-3-haiku-20240307`
- `claude-3-5-sonnet-20241022`

## Running Tests

```bash
# Install dependencies
composer install

# Run all tests
composer run-script test

# Or run directly
vendor/bin/phpunit

# Run a specific test file
vendor/bin/phpunit tests/UnitTest/OpenAI/OpenAIConnectorTest.php
```

## Project Structure

```
AIGateway/
├── src/
│   ├── Client/
│   │   └── GuzzleHTTPClient.php
│   ├── Cache/
│   │   ├── CacheInterface.php
│   │   └── FileCache.php
│   └── AI/
│       ├── AIConnector.php
│       ├── DTO/
│       │   ├── Input/
│       │   │   ├── AIRequestDTO.php
│       │   │   ├── OpenAIRequestDTO.php
│       │   │   └── AnthropicRequestDTO.php
│       │   └── Output/
│       │       └── AIResponseDTO.php
│       ├── Exception/
│       │   └── AIGatewayException.php
│       ├── OpenAI/
│       │   └── OpenAIConnector.php
│       └── Anthropic/
│           └── AnthropicConnector.php
├── tests/
│   └── UnitTest/
│       ├── OpenAI/
│       │   └── OpenAIConnectorTest.php
│       ├── Anthropic/
│       │   └── AnthropicConnectorTest.php
│       └── Cache/
│           └── FileCacheTest.php
├── composer.json
├── phpunit.xml
└── README.md
```

## License

MIT License

## Author

Sambhu Singh <sambhu.raj.singh@gmail.com>
