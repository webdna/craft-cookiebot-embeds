<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use webdna\cookiebotembeds\models\RewriteResult;
use webdna\cookiebotembeds\services\Classifier;
use webdna\cookiebotembeds\services\Config;
use webdna\cookiebotembeds\services\Log;
use webdna\cookiebotembeds\services\Rewriter;
use webdna\cookiebotembeds\services\Vendors;

/**
 * Throttled logging (BR-29; TN-12). A fake cache with a clock stands in for Craft's.
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

    private static function rewrite(string $html, string $allow = 'widgets.example.org'): RewriteResult
    {
        $config = (new Config())->resolve(['allow' => [$allow]], 'default', ['default']);
        $vendors = new Vendors();
        return (new Rewriter(new Classifier($config, $vendors, ['www.example.com'], ['assets.example-cdn.net']), $vendors, $config))
            ->rewrite($html);
    }

    /**
     * TN-12: the same unknown host 500 times on one page is one info line, and no more for an
     * hour on that site, however many pages show it.
     */
    public function testTheSameUnknownHostIsOneLinePerHourPerSite(): void
    {
        $page = str_repeat('<script src="https://cdn.example.net/widget.js"></script><iframe src="https://cdn.example.net/frame"></iframe>', 250);
        $result = self::rewrite($page);

        $this->assertSame(1, $this->request()->unknownHosts($result, 'default'));
        $this->assertSame([[
            Log::INFO,
            'Unknown host `cdn.example.net` on site `default` (script gated, iframe gated), treated as marketing. '
            . 'Name it under `vendors`, or add it to `allow`, in config/cookiebot-embeds.php.',
        ]], $this->lines);

        // Later requests in the same hour: nothing.
        $this->now += 3599;
        $this->assertSame(0, $this->request()->unknownHosts($result, 'default'));
        // Another site has its own hour.
        $this->assertSame(1, $this->request()->unknownHosts($result, 'other'));
        // An hour on, it is logged again.
        $this->now += 1;
        $this->assertSame(1, $this->request()->unknownHosts($result, 'default'));
        $this->assertCount(3, $this->lines);
    }

    /**
     * Only hosts listed nowhere: providers (named), Cookiebot and Tag Manager (infra), allowed
     * and filesystem hosts, first-party URLs and fingerprint-only inline scripts are not unknown.
     * A host the developer marked themselves is still unknown.
     */
    public function testOnlyUnlistedHostsAreUnknown(): void
    {
        $result = self::rewrite(implode('', [
            '<script src="https://www.google-analytics.com/analytics.js"></script>',
            '<script src="https://consent.cookiebot.com/uc.js" id="Cookiebot"></script>',
            '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-0000000"></script>',
            '<script src="https://widgets.example.org/w.js"></script>',
            '<script src="https://assets.example-cdn.net/a.js"></script>',
            '<script src="https://www.example.com/app.js"></script>',
            '<script>fbq(\'init\', \'000\');</script>',
            '<script data-cookieconsent="ignore" src="https://marked.example.net/x.js"></script>',
            '<iframe src="https://booking.example.net/frame"></iframe>',
        ]));

        $this->assertSame(['marked.example.net', 'booking.example.net'], array_keys(Log::unknownSightings($result)));
        $this->assertSame(2, $this->request()->unknownHosts($result, 'default'));
        $this->assertStringContainsString('`marked.example.net` on site `default` (script already)', $this->lines[0][1]);
    }

    public function testAPageWithoutUnknownHostsLogsNothing(): void
    {
        $this->assertSame(0, $this->request()->unknownHosts(new RewriteResult('<p></p>'), 'default'));
        $this->assertSame([], $this->lines);
    }

    /**
     * Any line repeated within the hour is written once; repeats in one request do not even ask
     * the cache.
     */
    public function testOnceWritesEachLineOncePerHour(): void
    {
        $log = $this->request();

        $this->assertTrue($log->once(Log::WARNING, 'Site `default`: bad mode'));
        $this->assertFalse($log->once(Log::WARNING, 'Site `default`: bad mode'));
        $this->assertTrue($log->once(Log::ERROR, 'Site `default`: bad mode'), 'Another level is another line');
        $this->assertFalse($this->request()->once(Log::WARNING, 'Site `default`: bad mode'));
        $this->assertSame([[Log::WARNING, 'Site `default`: bad mode'], [Log::ERROR, 'Site `default`: bad mode']], $this->lines);
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
