<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use webdna\cookiebotembeds\services\Log;

/**
 * Throttled logging (BR-29). A fake cache with a clock stands in for Craft's.
 */
final class LogTest extends TestCase
{
    /** @var array<string, int> Cache key => expiry time */
    private array $cache = [];

    private int $now = 1_000_000;

    /** @var list<array{string, string}> Lines written: [level, message] */
    private array $lines = [];

    /**
     * A new Log is a new request; the cache and the clock persist between them.
     */
    private function request(): Log
    {
        return new Log(
            function(string $key, int $ttl): bool {
                if (($this->cache[$key] ?? 0) > $this->now) {
                    return false;
                }
                $this->cache[$key] = $this->now + $ttl;
                return true;
            },
            function(string $level, string $message): void {
                $this->lines[] = [$level, $message];
            },
        );
    }

    /**
     * Any line repeated within the hour is written once; repeats in one request do not even ask
     * the cache. After the hour it is written again.
     */
    public function testOnceWritesEachLineOncePerHour(): void
    {
        $log = $this->request();

        $this->assertTrue($log->once(Log::WARNING, 'Site `default`: bad mode'));
        $this->assertFalse($log->once(Log::WARNING, 'Site `default`: bad mode'));
        $this->assertTrue($log->once(Log::ERROR, 'Site `default`: bad mode'), 'Another level is another line');
        $this->assertFalse($this->request()->once(Log::WARNING, 'Site `default`: bad mode'));
        $this->assertSame([[Log::WARNING, 'Site `default`: bad mode'], [Log::ERROR, 'Site `default`: bad mode']], $this->lines);

        $this->now += Log::TTL + 1;
        $this->assertTrue($this->request()->once(Log::WARNING, 'Site `default`: bad mode'));
    }

    /**
     * A cache that fails means a repeated line, never a lost one, and never an exception.
     */
    public function testAFailingCacheStillLogs(): void
    {
        $log = new Log(
            fn(string $key, int $ttl): bool => throw new RuntimeException('cache down'),
            function(string $level, string $message): void {
                $this->lines[] = [$level, $message];
            },
        );

        $this->assertTrue($log->once(Log::ERROR, 'Something failed'));
        $this->assertSame([[Log::ERROR, 'Something failed']], $this->lines);
    }
}
