# AI-Gateway — IP-Based Rate Limiting & Cache-Aware Decrementing

Implement a configurable IP-based Rate Limiting middleware and response caching inside AI-Gateway. The functionality should accept configuration passed as a parameter from the host application at bootstrap time.

---

## Rate Limiting Behaviour

- Rate limiting is an optional feature, disabled by default
- Every **non-cached** response should decrement the remaining tries counter and return `tries_remaining` appended at the end of the response body
- For example, if `max_requests` is 100 and a request is made that does **not** hit the cache, the response should include `"tries_remaining": 99`
- **Cached** responses should **not** decrement the counter, since no AI provider call was made
- When the limit is reached, return a `429 Too Many Requests` error
- Streaming responses are **out of scope** — buffered responses only
- Rate Limiter should be implemented as Middleware

---

## Cache Behaviour

- Caching is an optional feature, disabled by default
- The cache key is a **SHA256 hash of the request prompt**
- On a cache hit, return the cached response without calling the AI provider and without decrementing the rate limit counter
- On a cache miss, call the AI provider, cache the response with the configured TTL, and decrement the rate limit counter
- Cache and rate limit storage can be configured independently

---

## Middleware Execution Order

Request → Rate Limiter → Cache Check → AI Provider → Cache Store → Response

- If the rate limit is exceeded, return 429 before checking the cache
- If the cache hits, return immediately without calling the AI provider
- Only on a cache miss does the AI provider get called and the counter decremented

---

## Configuration Structure
```php
'rate_limit' => [
    'enabled'        => false, // OPTIONAL — disabled by default
    'rate_limit_id'  => 'ai_gateway_default', // namespace key for isolating counters per application
    'identifier'     => 'ip',
    'max_requests'   => 100,
    'window_seconds' => 86400, // sliding window from the time of the first request

    'storage' => [
        'driver'     => 'file', // file | redis | database
        'path'       => storage_path('ai-gateway/rate-limit'),
        'connection' => 'default',
    ],
],

'cache' => [
    'enabled' => false, // OPTIONAL — disabled by default
    'ttl'     => 3600,  // seconds

    'storage' => [
        'driver'     => 'file', // file | redis | database
        'path'       => storage_path('ai-gateway/cache'),
        'connection' => 'default',
    ],
],
```

---

## Rules

- The rate limit logic and cache logic should both live inside **AI-Gateway**
- Storage paths and config values are always provided by the host application at bootstrap time
- AI-Gateway should **never** hardcode any limits, TTLs, or paths
- File driver should use **atomic read-write locking** to prevent race conditions on concurrent requests
- `rate_limit_id` namespaces the counter storage key, allowing multiple applications to share the same gateway with isolated counters

---

## Tests

### Unit Tests
- Counter decrements on cache miss
- Counter does not decrement on cache hit
- Counter returns 429 when limit is reached
- SHA256 hash is generated correctly from the prompt
- Rate limiting disabled when `enabled` is false
- Cache disabled when `enabled` is false

### Functional Tests
- Full request cycle with cache miss decrements counter and caches response
- Full request cycle with cache hit returns cached response and does not decrement
- Counter resets after `window_seconds` sliding window expires
- 429 is returned after `max_requests` is exhausted
- Each `rate_limit_id` maintains an isolated counter

### End-to-End Tests
- Two identical prompts: second returns cached response with same `tries_remaining`
- Exhaust limit then verify 429 with correct response body
- Separate `rate_limit_id` values do not share counters under the same IP
- Concurrent requests do not over-decrement due to file locking
