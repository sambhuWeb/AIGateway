# AI-Gateway — IP-Based Rate Limiting & Caching

I want to implement **IP-based Rate Limiting** in AI-Gateway. The functionality should accept a configurable maximum number of requests per day, passed as a parameter from the host application.

---

## Rate Limiting Behaviour

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
    'max_requests'   => 100,           // Maximum requests allowed per day
    'window_seconds' => 86400,         // Time window in seconds (86400 = 1 day)
    'storage_path'   => storage_path('ai-gateway/rate-limit'), // Host app storage path for IP tracking files
],
// Add change in Cache (CacheInterface.phhp and FileCache.php to pass the ttl in addition to stoarage path) from calling External Repository
```

---

## Rules

- The rate limit logic and cache logic should both live inside **AI-Gateway**
- Storage paths and config values are always provided by the host application at bootstrap time
- AI-Gateway should **never** hardcode any limits or paths
