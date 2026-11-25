<?php

namespace webdna\cookiebotembeds\tests\unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use webdna\cookiebotembeds\models\RewriteResult;
use webdna\cookiebotembeds\services\Classifier;
use webdna\cookiebotembeds\services\Config;
use webdna\cookiebotembeds\services\Report;
use webdna\cookiebotembeds\services\Rewriter;
use webdna\cookiebotembeds\services\Vendors;

/**
 * The report recorder (BR-23, BR-24, BR-26; TN-11, TN-12, TN-18). A fake cache with a clock stands
 * in for Craft's, and the upsert is captured rather than run.
 */
final class ReportTest extends TestCase
{
    /** @var array<string, int> Cache key => expiry time */
    private array $cache = [];

    private int $now = 1_000_000;

    /** @var list<list<array<string, mixed>>> Each upsert call's rows */
    private array $upserts = [];

    /** @var list<string> */
    private array $errors = [];

    private ?\Throwable $upsertFailure = null;

    private ?\Throwable $claimFailure = null;

    /**
     * A new Report is a new request; the cache and the clock persist between them.
     */
    private function request(): Report
    {
        return new Report(
            claim: function(string $key, int $ttl): bool {
                if ($this->claimFailure !== null) {
                    throw $this->claimFailure;
                }
                if (($this->cache[$key] ?? 0) > $this->now) {
                    return false;
                }
                $this->cache[$key] = $this->now + $ttl;
                return true;
            },
            upsert: function(array $rows): void {
                if ($this->upsertFailure !== null) {
                    throw $this->upsertFailure;
                }
                $this->upserts[] = $rows;
            },
            onError: function(string $message): void {
                $this->errors[] = $message;
            },
        );
    }

    private static function rewrite(string $html): RewriteResult
    {
        $config = (new Config())->resolve(['allow' => ['widgets.example.org']], 'default', ['default']);
        $vendors = new Vendors();
        return (new Rewriter(new Classifier($config, $vendors, ['www.example.com'], ['assets.example-cdn.net']), $vendors, $config))
            ->rewrite($html);
    }

    /**
     * @param list<array{string, string|null, string, string, string}> $sightings host, vendor, category, action, kind
     */
    private static function sightings(array $sightings): RewriteResult
    {
        return new RewriteResult('', array_map(
            fn(array $s): array => ['host' => $s[0], 'vendor' => $s[1], 'category' => $s[2], 'action' => $s[3], 'kind' => $s[4]],
            $sightings,
        ));
    }

    /**
     * TN-12: the same unknown host 500 times on one page is one row, written once, and not again
     * for an hour on that site.
     */
    public function testTheSameHostManyTimesIsOneRowOncePerHour(): void
    {
        $result = self::rewrite(str_repeat('<script src="https://cdn.example.net/widget.js"></script>', 500));

        $this->assertSame(1, $this->request()->record($result, 1, 'news/a-story'));
        $this->assertSame([[[
            'siteId' => 1,
            'host' => 'cdn.example.net',
            'vendor' => null,
            'category' => 'marketing',
            'action' => 'gated',
            'kind' => 'script',
            'examplePath' => '/news/a-story',
        ]]], $this->upserts);

        // Later requests in the same hour write nothing.
        $this->now += 3599;
        $this->assertSame(0, $this->request()->record($result, 1, 'other-page'));
        // Another site has its own hour.
        $this->assertSame(1, $this->request()->record($result, 2, 'other-page'));
        // An hour on, it is written again (the upsert refreshes last seen).
        $this->now += 1;
        $this->assertSame(1, $this->request()->record($result, 1, 'later'));
        $this->assertCount(3, $this->upserts);
        $this->assertSame([], $this->errors);
    }

    /**
     * BR-23: every outcome is recorded, not only gated ones, handed to the upsert together.
     */
    public function testEveryOutcomeOnThePageGoesInOneUpsert(): void
    {
        $result = self::sightings([
            ['www.youtube.com', 'YouTube', 'marketing', 'gated', 'iframe'],
            ['widgets.example.org', null, 'marketing', 'allowed', 'script'],
            ['www.googletagmanager.com', 'Google Tag Manager', 'infra', 'left', 'script'],
            ['cdn.example.net', null, 'marketing', 'already', 'script'],
            ['esm.example.net', null, 'marketing', 'skipped', 'script'],
            ['', 'Some Pixel', 'marketing', 'gated', 'inline'],
        ]);

        $this->assertSame(6, $this->request()->record($result, 1, '/'));
        $this->assertCount(1, $this->upserts);
        $this->assertSame(
            ['gated', 'allowed', 'left', 'already', 'skipped', 'gated'],
            array_column($this->upserts[0], 'action'),
        );
    }

    /**
     * Only the rows not claimed in the last hour are written; the rest of the page still is.
     */
    public function testOnlyUnclaimedRowsAreWritten(): void
    {
        $this->request()->record(self::sightings([['a.example.net', null, 'marketing', 'gated', 'script']]), 1, '/');

        $this->assertSame(1, $this->request()->record(self::sightings([
            ['a.example.net', null, 'marketing', 'gated', 'script'],
            ['b.example.net', null, 'marketing', 'gated', 'iframe'],
        ]), 1, '/'));
        $this->assertSame(['b.example.net'], array_column($this->upserts[1], 'host'));
    }

    /**
     * Two sightings that become the same row once cut to fit are one row, claimed and written once.
     */
    public function testSightingsThatMapToOneRowAreWrittenOnce(): void
    {
        $long = str_repeat('a', 260);
        $this->assertSame(1, $this->request()->record(self::sightings([
            [$long . '.example.net', null, 'marketing', 'gated', 'script'],
            [$long . '.example.org', null, 'marketing', 'gated', 'script'],
        ]), 1, '/'));
        $this->assertCount(1, $this->upserts[0]);
    }

    public function testAPageWithNoSightingsWritesNothing(): void
    {
        $this->assertSame(0, $this->request()->record(new RewriteResult('<p>Hi</p>'), 1, '/'));
        $this->assertSame([], $this->upserts);
        $this->assertSame([], $this->cache);
    }

    /**
     * TN-11 / BR-24: the table missing or the database down is reported and swallowed. The claim
     * stands, so a broken table is tried, and reported, once an hour rather than on every view.
     */
    public function testADatabaseFailureIsReportedAndSwallowed(): void
    {
        $this->upsertFailure = new RuntimeException("Table 'cookiebotembeds_sightings' doesn't exist");
        $result = self::sightings([['cdn.example.net', null, 'marketing', 'gated', 'script']]);

        $this->assertSame(0, $this->request()->record($result, 1, '/'));
        $this->assertSame(["The report could not be recorded: Table 'cookiebotembeds_sightings' doesn't exist"], $this->errors);

        $this->assertSame(0, $this->request()->record($result, 1, '/'));
        $this->assertCount(1, $this->errors);
    }

    /**
     * A cache that fails cannot throttle, so nothing is written rather than a write per view (B3).
     */
    public function testAFailingThrottleWritesNothing(): void
    {
        $this->claimFailure = new RuntimeException('cache down');

        $this->assertSame(0, $this->request()->record(self::sightings([
            ['a.example.net', null, 'marketing', 'gated', 'script'],
            ['b.example.net', null, 'marketing', 'gated', 'script'],
        ]), 1, '/'));
        $this->assertSame([], $this->upserts);
        $this->assertSame(['The report throttle failed, so sightings were not recorded: cache down'], $this->errors);
    }

    public function testAnErrorReporterThatThrowsIsSwallowedToo(): void
    {
        $report = new Report(
            claim: fn(): bool => true,
            upsert: function(): void {
                throw new RuntimeException('db down');
            },
            onError: function(): void {
                throw new RuntimeException('log down');
            },
        );
        $this->assertSame(0, $report->record(self::sightings([['a.example.net', null, 'marketing', 'gated', 'script']]), 1, '/'));
    }

    /**
     * TN-18 / BR-26: a row last seen 31 days ago is before the cutoff; one seen yesterday is not.
     */
    public function testRowsUnseenFor30DaysArePruned(): void
    {
        $now = new DateTimeImmutable('2026-11-23 12:00:00');
        $cutoff = Report::pruneBefore($now);

        $this->assertSame('2026-10-24 12:00:00', $cutoff->format('Y-m-d H:i:s'));
        $this->assertLessThan($cutoff, $now->modify('-31 days'));
        $this->assertGreaterThan($cutoff, $now->modify('-1 day'));
    }
}
