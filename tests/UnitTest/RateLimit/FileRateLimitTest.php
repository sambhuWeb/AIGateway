<?php

namespace AIGateway\Tests\UnitTest\RateLimit;

use PHPUnit\Framework\TestCase;
use AIGateway\RateLimit\FileRateLimit;
use AIGateway\RateLimit\Exception\RateLimitExceededException;

class FileRateLimitTest extends TestCase
{
    /** @var string */
    private $rateLimitDir;

    protected function setUp(): void
    {
        $this->rateLimitDir = sys_get_temp_dir() . '/ai_gateway_rl_unit_' . uniqid();
    }

    protected function tearDown(): void
    {
        $files = glob($this->rateLimitDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->rateLimitDir)) {
            rmdir($this->rateLimitDir);
        }
    }

    /**
     * @test
     */
    public function it_allows_requests_within_limit(): void
    {
        $limiter = new FileRateLimit($this->rateLimitDir, 'test', 5, 60);

        self::assertTrue($limiter->isAllowed('user_1'));
    }

    /**
     * @test
     */
    public function it_consume_reduces_tries_remaining(): void
    {
        $limiter = new FileRateLimit($this->rateLimitDir, 'test', 5, 60);

        $remaining = $limiter->consume('user_1');

        self::assertEquals(4, $remaining);
    }

    /**
     * @test
     */
    public function it_decrements_tries_remaining_on_each_consume(): void
    {
        $limiter = new FileRateLimit($this->rateLimitDir, 'test', 3, 60);

        self::assertEquals(2, $limiter->consume('user_1'));
        self::assertEquals(1, $limiter->consume('user_1'));
        self::assertEquals(0, $limiter->consume('user_1'));
    }

    /**
     * @test
     */
    public function it_blocks_when_limit_is_reached(): void
    {
        $limiter = new FileRateLimit($this->rateLimitDir, 'test', 2, 60);

        $limiter->consume('user_1');
        $limiter->consume('user_1');

        self::assertFalse($limiter->isAllowed('user_1'));
    }

    /**
     * @test
     */
    public function it_isolates_counters_per_identifier(): void
    {
        $limiter = new FileRateLimit($this->rateLimitDir, 'test', 2, 60);

        $limiter->consume('user_1');
        $limiter->consume('user_1');

        self::assertFalse($limiter->isAllowed('user_1'));
        self::assertTrue($limiter->isAllowed('user_2'));
    }

    /**
     * @test
     */
    public function it_isolates_counters_per_rate_limit_id(): void
    {
        $limiter1 = new FileRateLimit($this->rateLimitDir, 'endpoint_a', 2, 60);
        $limiter2 = new FileRateLimit($this->rateLimitDir, 'endpoint_b', 5, 60);

        $limiter1->consume('user_1');
        $limiter1->consume('user_1');

        self::assertFalse($limiter1->isAllowed('user_1'));
        self::assertTrue($limiter2->isAllowed('user_1'));
    }

    /**
     * @test
     */
    public function it_resets_counter_after_window_expires(): void
    {
        $limiter = new FileRateLimit($this->rateLimitDir, 'test', 2, 1);

        $limiter->consume('user_1');
        $limiter->consume('user_1');
        self::assertFalse($limiter->isAllowed('user_1'));

        sleep(2);

        self::assertTrue($limiter->isAllowed('user_1'));
    }

    /**
     * @test
     */
    public function it_can_be_created_from_config(): void
    {
        $limiter = FileRateLimit::fromConfig([
            'path' => $this->rateLimitDir,
            'rate_limit_id' => 'api',
            'max_requests' => 10,
            'window_seconds' => 60
        ]);

        self::assertInstanceOf(FileRateLimit::class, $limiter);
        self::assertTrue($limiter->isAllowed('user_1'));
    }

    /**
     * @test
     */
    public function it_generates_rate_limit_exceeded_exception_with_code_429(): void
    {
        $exception = new RateLimitExceededException();

        self::assertEquals(429, $exception->getCode());
        self::assertEquals('Rate limit exceeded', $exception->getMessage());
    }
}
