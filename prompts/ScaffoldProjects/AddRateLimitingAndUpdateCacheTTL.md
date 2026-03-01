# AI-Gateway — IP-Based Rate Limiting & Cache-Aware Decrementing

Implement a configurable IP-based Rate Limiting middleware inside AI-Gateway. The functionality should accept a configurable maximum number of requests per day, passed as a parameter from the host application.

---

## Rate Limiting Behaviour

- This Rate limit should be optional parameter
- Every **non-cached** response should decrement the remaining tries counter and return it in the response
- For example, if `max_requests` is 100 and a request is made that does **not** hit the cache, the response should include `"tries_remaining": 99`
- **Cached** responses should **not** decrement the counter, since no AI provider call was made
- When the limit is reached, return a `429 Too Many Requests` error
- Add Rate Limiter as Middleware

---

## Configuration Structure

Passed from the host application at bootstrap time:

```php
'rate_limit' => [
    'enabled'        => true, // OPTIONAL — if false or missing, rate limiting is disabled. By default it is false
    'rate_limit_id'  => 'ai_gateway_default',
    'identifier'     => 'ip',
    'max_requests'   => 100,
    'window_seconds' => 86400,

    'storage' => [
        'driver'     => 'file', // file | redis | database
        'path'       => storage_path('ai-gateway/rate-limit'),
        'connection' => 'default',
    ],
],
```

---

## Rules

- The rate limit logic and cache logic should both live inside **AI-Gateway**
- Storage paths and config values are always provided by the host application at bootstrap time
- AI-Gateway should **never** hardcode any limits or paths
- Create unit test, functional test and end to end test
