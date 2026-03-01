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

## Rate Limiting & Cache-Aware Middleware

`RateLimitMiddleware` sits in front of your connector and enforces the flow:

```
Request → Rate Check → Cache Lookup → AI Call → Cache Store → Consume → Response
```

- If the identifier (e.g. IP address) has exceeded its request quota → throws `RateLimitExceededException` (HTTP 429)
- If the prompt is cached and `fresh` is `false` → returns the cached response immediately (counter is **not** decremented)
- On a real API call → caches the response, decrements the counter, and includes `tries_remaining` in the response

### Minimal setup

```php
<?php

require_once 'vendor/autoload.php';

use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\AI\OpenAI\OpenAIConnector;
use AIGateway\AI\DTO\Input\OpenAIRequestDTO;
use AIGateway\Cache\FileCache;
use AIGateway\Middleware\RateLimitMiddleware;
use AIGateway\RateLimit\FileRateLimit;
use AIGateway\RateLimit\Exception\RateLimitExceededException;

// 1. Build the connector (no cache passed — middleware owns caching)
$httpClient = new GuzzleHTTPClient();
$connector  = new OpenAIConnector($httpClient);
$connector->setApiKey('your-openai-api-key');

// 2. File-based rate limiter: 100 requests per 60 seconds per identifier
$rateLimiter = new FileRateLimit(
    '/path/to/storage/rate_limiting',   // directory for .rl files
    'my_app',                           // rate_limit_id — isolates this limiter from others
    100,                                // max requests
    60                                  // window in seconds
);

// 3. File-based cache (SHA256 keys, atomic writes)
$cache = new FileCache('/path/to/storage/cache');

// 4. Wrap everything in the middleware
$middleware = new RateLimitMiddleware($connector, $rateLimiter, $cache, 3600);

// 5. Send requests — pass the caller's IP (or any unique identifier)
$request = new OpenAIRequestDTO(
    'gpt-4',
    [['role' => 'user', 'content' => 'What is PHP?']],
    0.7,
    1024
);

try {
    $response = $middleware->handle($request, $_SERVER['REMOTE_ADDR']);

    echo $response->getContent();
    echo $response->getTriesRemaining(); // e.g. 99
    echo $response->isFromCache();       // false on first call
} catch (RateLimitExceededException $e) {
    http_response_code(429);
    echo 'Too many requests. Try again later.';
}
```

### Second identical request uses cache (no counter decrement)

```php
// First call — hits the API, counter goes from 100 → 99
$response1 = $middleware->handle($request, '1.2.3.4');
echo $response1->isFromCache();       // false
echo $response1->getTriesRemaining(); // 99

// Second call with same messages — served from cache, counter stays at 99
$response2 = $middleware->handle($request, '1.2.3.4');
echo $response2->isFromCache();       // true
echo $response2->getTriesRemaining(); // null (no consume on cache hit)
```

### Force a fresh API call (bypass cache)

```php
$freshRequest = new OpenAIRequestDTO(
    'gpt-4',
    [['role' => 'user', 'content' => 'What is PHP?']],
    0.7,
    1024,
    true   // fresh = true — skip cache, always call the API
);

$response = $middleware->handle($freshRequest, '1.2.3.4');
echo $response->isFromCache(); // always false
```

### Create from config array

The `fromConfig` factory makes wiring easy in framework service containers:

```php
$middleware = RateLimitMiddleware::fromConfig($connector, [
    'rate_limit' => [
        'enabled'        => true,
        'path'           => '/path/to/storage/rate_limiting',
        'rate_limit_id'  => 'my_app',
        'max_requests'   => 100,
        'window_seconds' => 60,
    ],
    'cache' => [
        'enabled' => true,
        'path'    => '/path/to/storage/cache',
        'ttl'     => 3600,
    ],
]);
```

Set `enabled: false` to disable rate limiting or caching independently:

```php
$middleware = RateLimitMiddleware::fromConfig($connector, [
    'rate_limit' => ['enabled' => false],   // no rate limiting
    'cache'      => ['enabled' => true, 'path' => '/tmp/cache', 'ttl' => 3600],
]);
```

### Multiple isolated rate limiters

Use a different `rate_limit_id` per endpoint or service so they maintain separate counters for the same identifier:

```php
$chatLimiter   = new FileRateLimit('/tmp/rl', 'chat',      50,  60);
$searchLimiter = new FileRateLimit('/tmp/rl', 'search',   200,  60);

$chatMiddleware   = new RateLimitMiddleware($connector, $chatLimiter,   $cache);
$searchMiddleware = new RateLimitMiddleware($connector, $searchLimiter, $cache);

// Exhausting chat quota does not affect the search quota
```

### Handling the 429 exception

```php
use AIGateway\RateLimit\Exception\RateLimitExceededException;

try {
    $response = $middleware->handle($request, $clientIp);
} catch (RateLimitExceededException $e) {
    // $e->getCode() === 429
    echo $e->getMessage(); // "Rate limit exceeded"
}
```

### Custom rate limiter

Implement `RateLimitInterface` to use Redis, a database, or any other backend:

```php
use AIGateway\RateLimit\RateLimitInterface;

class RedisRateLimit implements RateLimitInterface
{
    public function isAllowed(string $identifier): bool
    {
        $count = $this->redis->get("rl:{$identifier}") ?? 0;
        return (int)$count < $this->maxRequests;
    }

    public function consume(string $identifier): int
    {
        $count = $this->redis->incr("rl:{$identifier}");
        if ($count === 1) {
            $this->redis->expire("rl:{$identifier}", $this->windowSeconds);
        }
        return max(0, $this->maxRequests - $count);
    }
}

$middleware = new RateLimitMiddleware($connector, new RedisRateLimit($redis), $cache);
```

---

## Publish new version

- git tag v1.0.1
- git push origin v1.0.1

## Using Caching

The package includes a file-based caching system to avoid redundant API calls for identical requests. By default, cache files are stored in `storage/cache/` directory within the package.

### Enable Caching

```php
<?php

use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\Cache\FileCache;
use AIGateway\AI\OpenAI\OpenAIConnector;
use AIGateway\AI\DTO\Input\OpenAIRequestDTO;

// Create cache instance - uses storage/cache/ directory by default
$cache = new FileCache();

// Or specify a custom cache directory
$cache = new FileCache('/path/to/custom/cache/directory');

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
|---|---|---|---|
| model | string | required | OpenAI model (e.g., 'gpt-4', 'gpt-3.5-turbo') |
| messages | array | required | Array of message objects with 'role' and 'content' |
| temperature | float | 0.7 | Sampling temperature (0.0 - 2.0) |
| maxTokens | int | 1024 | Maximum tokens in response |
| fresh | bool | false | Bypass cache when true |
| systemPrompt | string | null | System message prepended to messages |

### AnthropicRequestDTO

| Parameter | Type | Default | Description |
|---|---|---|---|
| model | string | required | Anthropic model (e.g., 'claude-3-opus-20240229') |
| messages | array | required | Array of message objects with 'role' and 'content' |
| temperature | float | 0.7 | Sampling temperature (0.0 - 1.0) |
| maxTokens | int | 1024 | Maximum tokens in response |
| fresh | bool | false | Bypass cache when true |
| system | string | null | System instruction (sent as separate parameter) |

### AIResponseDTO

| Method | Return Type | Description |
|---|---|---|
| getContent() | string | The AI response text |
| getModel() | string | Model used for generation |
| getPromptTokens() | int | Tokens in the prompt |
| getCompletionTokens() | int | Tokens in the response |
| getTotalTokens() | int | Total tokens used |
| isFromCache() | bool | Whether response was from cache |
| getTriesRemaining() | int\|null | Requests left in the current window (set by middleware; null on cache hits or when no limiter is used) |
| withTriesRemaining(?int) | self | Returns a clone with tries_remaining set (used internally by middleware) |
| toArray() | array | Response as associative array |
| toJson() | string | Response as JSON string |

### JSON Response Format

Without rate limiting:

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

With rate limiting middleware (cache miss):

```json
{
    "content": "The AI response text here...",
    "model": "gpt-4",
    "prompt_tokens": 25,
    "completion_tokens": 150,
    "total_tokens": 175,
    "from_cache": false,
    "tries_remaining": 99
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

The package includes three types of tests:

- **Unit Tests** — Test individual components in isolation with mocked dependencies. No API keys required.
- **Functional Tests** — Test component integration with mocked HTTP responses. No API keys required.
- **End-to-End Tests** — Test real API calls or full system integration. Most require API keys; the rate limit E2E test does not.

### Quick commands

```bash
# Install dependencies
composer install

# Run default suite (unit + functional — no API calls needed)
composer run-script test
# or
vendor/bin/phpunit

# Unit tests only
vendor/bin/phpunit --testsuite unit

# Functional tests only
vendor/bin/phpunit --testsuite functional

# All tests including E2E
OPENAI_API_KEY=your-key ANTHROPIC_API_KEY=your-key vendor/bin/phpunit --testsuite all
```

---

### End-to-End Test Files

#### `OpenAIEndToEndTest.php` — Real OpenAI API calls

**Requires:** `OPENAI_API_KEY`

```bash
OPENAI_API_KEY=your-key vendor/bin/phpunit tests/EndToEndTest/OpenAIEndToEndTest.php
```

| Test | What it verifies |
|---|---|
| `it_sends_real_request_to_openai_api` | Calls the live API and checks content, model, token counts, `from_cache=false` |
| `it_caches_response_from_real_api_call` | First call hits API (`from_cache=false`), second returns from cache (`from_cache=true`) |
| `it_handles_system_prompt_in_real_request` | System prompt is sent correctly and influences the response |
| `it_handles_multi_turn_conversation_with_real_api` | Conversation history is preserved across turns |
| `it_returns_valid_json_from_real_api` | `toJson()` returns valid JSON with all expected keys |
| `it_throws_exception_for_invalid_api_key` | `AIGatewayException` is raised when the key is wrong |

---

#### `AnthropicEndToEndTest.php` — Real Anthropic API calls

**Requires:** `ANTHROPIC_API_KEY`

```bash
ANTHROPIC_API_KEY=your-key vendor/bin/phpunit tests/EndToEndTest/AnthropicEndToEndTest.php
```

| Test | What it verifies |
|---|---|
| `it_sends_real_request_to_anthropic_api` | Calls the live API and checks content, model, token counts, `from_cache=false` |
| `it_caches_response_from_real_api_call` | First call hits API, second returns from cache |
| `it_handles_system_parameter_in_real_request` | System parameter influences the response |
| `it_handles_multi_turn_conversation_with_real_api` | Conversation history is preserved |
| `it_returns_valid_json_from_real_api` | `toJson()` returns valid JSON with all expected keys |
| `it_throws_exception_for_invalid_api_key` | `AIGatewayException` is raised when the key is wrong |
| `it_uses_different_claude_models` | Verifies the correct model identifier is returned |

---

#### `DictionarySearchTest.php` — Structured JSON output via real APIs

**Requires:** `OPENAI_API_KEY` and/or `ANTHROPIC_API_KEY` (each test skips gracefully if its key is absent)

```bash
# Run with both providers
OPENAI_API_KEY=your-key ANTHROPIC_API_KEY=your-key \
  vendor/bin/phpunit tests/EndToEndTest/DictionarySearchTest.php

# Run only the OpenAI variant
OPENAI_API_KEY=your-key vendor/bin/phpunit tests/EndToEndTest/DictionarySearchTest.php \
  --filter it_returns_structured_dictionary_response_from_openai

# Run only the Anthropic variant
ANTHROPIC_API_KEY=your-key vendor/bin/phpunit tests/EndToEndTest/DictionarySearchTest.php \
  --filter it_returns_structured_dictionary_response_from_anthropic
```

| Test | What it verifies |
|---|---|
| `it_returns_structured_dictionary_response_from_openai` | Sends a structured JSON prompt to OpenAI and validates the response matches the expected dictionary schema including Hindi definitions |
| `it_returns_structured_dictionary_response_from_anthropic` | Same scenario via Anthropic |
| `it_caches_dictionary_response` | First dictionary call hits API, second returns from cache with identical content |

---

#### `RateLimitEndToEndTest.php` — Rate limiting full cycle

**No API keys required** — uses mocked HTTP, real `FileRateLimit` and `FileCache` on disk.

```bash
vendor/bin/phpunit tests/EndToEndTest/RateLimitEndToEndTest.php
```

| Test | What it verifies |
|---|---|
| `second_identical_prompt_returns_cached_without_decrement` | First call hits API and decrements counter; second identical call returns from cache without decrementing |
| `exhausting_limit_raises_rate_limit_exceeded` | After `max_requests` API calls, the next request throws `RateLimitExceededException` (code 429) |
| `separate_rate_limit_ids_do_not_share_counters` | Exhausting `service_a` quota for an IP does not affect `service_b` quota |
| `concurrent_requests_do_not_over_decrement` | Forked processes each call `consume()` concurrently; atomic file locking prevents double-counting (skipped if `pcntl_fork` is unavailable) |

---

### Test Structure summary

| Test Type | Location | API Calls | Default suite |
|---|---|---|---|
| Unit | `tests/UnitTest/` | No (mocked) | Yes |
| Functional | `tests/FunctionalTest/` | No (mocked) | Yes |
| E2E — OpenAI | `tests/EndToEndTest/OpenAIEndToEndTest.php` | Yes | No |
| E2E — Anthropic | `tests/EndToEndTest/AnthropicEndToEndTest.php` | Yes | No |
| E2E — Dictionary | `tests/EndToEndTest/DictionarySearchTest.php` | Yes | No |
| E2E — Rate Limit | `tests/EndToEndTest/RateLimitEndToEndTest.php` | No (mocked) | No |

## Project Structure

```
AIGateway/
├── src/
│   ├── Client/
│   │   └── GuzzleHTTPClient.php
│   ├── Cache/
│   │   ├── CacheInterface.php
│   │   └── FileCache.php              # SHA256 keys, atomic flock writes
│   ├── RateLimit/
│   │   ├── RateLimitInterface.php     # isAllowed() / consume() contract
│   │   ├── FileRateLimit.php          # File-based sliding window limiter
│   │   └── Exception/
│   │       └── RateLimitExceededException.php  # HTTP 429
│   ├── Middleware/
│   │   └── RateLimitMiddleware.php    # Rate check → cache → AI → store → consume
│   └── AI/
│       ├── AIConnector.php
│       ├── DTO/
│       │   ├── Input/
│       │   │   ├── AIRequestDTO.php
│       │   │   ├── OpenAIRequestDTO.php
│       │   │   └── AnthropicRequestDTO.php
│       │   └── Output/
│       │       └── AIResponseDTO.php  # + tries_remaining field
│       ├── Exception/
│       │   └── AIGatewayException.php
│       ├── OpenAI/
│       │   └── OpenAIConnector.php
│       └── Anthropic/
│           └── AnthropicConnector.php
├── storage/
│   └── cache/                         # Default cache directory
├── tests/
│   ├── UnitTest/
│   │   ├── OpenAI/
│   │   │   └── OpenAIConnectorTest.php
│   │   ├── Anthropic/
│   │   │   └── AnthropicConnectorTest.php
│   │   ├── Cache/
│   │   │   └── FileCacheTest.php
│   │   ├── RateLimit/
│   │   │   └── FileRateLimitTest.php
│   │   └── Middleware/
│   │       └── RateLimitMiddlewareTest.php
│   ├── FunctionalTest/
│   │   ├── OpenAIFunctionalTest.php
│   │   ├── AnthropicFunctionalTest.php
│   │   └── RateLimitFunctionalTest.php
│   └── EndToEndTest/
│       ├── OpenAIEndToEndTest.php
│       ├── AnthropicEndToEndTest.php
│       ├── DictionarySearchTest.php
│       └── RateLimitEndToEndTest.php
├── composer.json
├── phpunit.xml
└── README.md
```

## License

MIT License

## How external Repo use this

# EasyLanguageTyping — Structure & Flow

## Directory Structure

```
EasyLanguageTyping/
├── vendor/
│   └── your-org/ai-gateway/       ← logic lives here (read-only)
├── storage or tmp/
│   ├── cache/                     ← cache files written here
│   └── rate_limiting/             ← IP tracking written here
```

---

## The Flow End-to-End

```
EasyLanguageTyping
    │
    ├── Passes config (max=100, window=60s)
    ├── Passes storage path
    │
    ▼
AI-Gateway (RateLimiter)
    │
    ├── Reads IP count from → EasyLanguageTyping/storage/
    ├── Compares against max_requests (from config)
    ├── Increments count    → EasyLanguageTyping/storage/
    │
    ├── ✅ Under limit → proceed to cache/AI call
    └── ❌ Over limit  → throw 429
```


## Author

Sambhu Singh <sambhu.raj.singh@gmail.com>

