<?php

namespace AIGateway\Tests\UnitTest\Cache;

use PHPUnit\Framework\TestCase;
use AIGateway\Cache\FileCache;

class FileCacheTest extends TestCase
{
    /** @var FileCache */
    private $cache;

    /** @var string */
    private $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ai_gateway_test_cache_' . uniqid();
        $this->cache = new FileCache($this->cacheDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->cacheDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    /**
     * @test
     */
    public function it_can_set_and_get_cache_value(): void
    {
        $this->cache->set('test_key', 'test_value');

        self::assertTrue($this->cache->has('test_key'));
        self::assertEquals('test_value', $this->cache->get('test_key'));
    }

    /**
     * @test
     */
    public function it_returns_null_for_non_existent_key(): void
    {
        self::assertNull($this->cache->get('non_existent_key'));
        self::assertFalse($this->cache->has('non_existent_key'));
    }

    /**
     * @test
     */
    public function it_can_delete_cache_entry(): void
    {
        $this->cache->set('delete_test', 'value');
        self::assertTrue($this->cache->has('delete_test'));

        $this->cache->delete('delete_test');
        self::assertFalse($this->cache->has('delete_test'));
    }

    /**
     * @test
     */
    public function it_returns_null_for_expired_cache(): void
    {
        $this->cache->set('expired_key', 'value', 1);

        sleep(2);

        self::assertNull($this->cache->get('expired_key'));
    }

    /**
     * @test
     */
    public function it_generates_consistent_cache_keys(): void
    {
        $params = [
            'model' => 'gpt-4',
            'messages' => [['role' => 'user', 'content' => 'Hello']]
        ];

        $key1 = FileCache::generateCacheKey($params);
        $key2 = FileCache::generateCacheKey($params);

        self::assertEquals($key1, $key2);
    }

    /**
     * @test
     */
    public function it_generates_different_keys_for_different_params(): void
    {
        $params1 = ['model' => 'gpt-4', 'content' => 'Hello'];
        $params2 = ['model' => 'gpt-3.5-turbo', 'content' => 'Hello'];

        $key1 = FileCache::generateCacheKey($params1);
        $key2 = FileCache::generateCacheKey($params2);

        self::assertNotEquals($key1, $key2);
    }
}
