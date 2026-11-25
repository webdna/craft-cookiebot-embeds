<?php

namespace webdna\cookiebotembeds\services;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;
use webdna\cookiebotembeds\models\RewriteResult;
use webdna\cookiebotembeds\records\Sighting;

/**
 * Records what each rewritten page did, for the report (BR-23, BR-24, BR-26).
 *
 * Each site/host/action/kind row is written at most once per hour, claimed through Craft's cache,
 * so a busy uncached page is not a database write per view; the rows a page does write go in one
 * batched upsert. A failure is reported and swallowed: the page is still served, rewritten.
 *
 * Pure: the hourly claim (Craft's cache, live), the upsert (Craft's database, live) and the error
 * reporter (the plugin's throttled log) are injected.
 *
 * @internal Not part of the plugin's public API; it may change in any release.
 */
final class Report
{
    /** How long a row stays claimed, in seconds. */
    public const TTL = 3600;

    /** Rows not seen for this many days are pruned by garbage collection (BR-26). */
    public const PRUNE_DAYS = 30;

    /**
     * The cache key holding the throttle's generation. The live claim keys include it, so changing
     * it (the report's clear action) frees every claim at once and the next page views record again.
     */
    public const GENERATION_KEY = 'cookiebot-embeds:report:generation';

    /**
     * @param Closure(string $key, int $ttl): bool $claim True when the key was free and is now taken
     *        for `$ttl` seconds (Craft's `$cache->add()`)
     * @param Closure(non-empty-list<array{siteId: int, host: string, vendor: string|null, category: string, action: string, kind: string, examplePath: string}> $rows): void $upsert
     *        Inserts the rows, or updates the existing row under the unique key (one statement)
     * @param Closure(string $message): void $onError
     */
    public function __construct(
        private readonly Closure $claim,
        private readonly Closure $upsert,
        private readonly Closure $onError,
    ) {
    }

    /**
     * Records the page's sightings on the site, with the page's path as each row's example.
     * Returns how many rows were written. Never throws (BR-24).
     */
    public function record(RewriteResult $result, int $siteId, string $path): int
    {
        try {
            $rows = [];
            foreach ($result->sightings as $sighting) {
                $row = Sighting::row($siteId, $sighting, $path);
                // Cutting a value to fit its column could make two sightings one row; a batched
                // upsert must not name the same row twice (Postgres refuses it).
                $rows[self::key($row)] ??= $row;
            }

            $claimed = [];
            $failure = null;
            foreach ($rows as $key => $row) {
                try {
                    if (($this->claim)($key, self::TTL)) {
                        $claimed[] = $row;
                    }
                } catch (Throwable $e) {
                    // Without the throttle every view would write, so the row waits (B3).
                    $failure ??= $e;
                }
            }
            if ($failure !== null) {
                ($this->onError)('The report throttle failed, so sightings were not recorded: ' . $failure->getMessage());
            }
            if ($claimed === []) {
                return 0;
            }

            ($this->upsert)($claimed);
            return count($claimed);
        } catch (Throwable $e) {
            try {
                ($this->onError)('The report could not be recorded: ' . $e->getMessage());
            } catch (Throwable) {
            }
            return 0;
        }
    }

    /**
     * The row's unique key (spec section 4), which is also its throttle key.
     *
     * @param array{siteId: int, host: string, action: string, kind: string} $row
     */
    public static function key(array $row): string
    {
        return implode('|', [$row['siteId'], $row['host'], $row['action'], $row['kind']]);
    }

    /**
     * BR-26: rows last seen before this moment are pruned.
     */
    public static function pruneBefore(DateTimeInterface $now): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($now)->sub(new DateInterval('P' . self::PRUNE_DAYS . 'D'));
    }

    /**
     * The clause that turns a batch insert into an upsert on the unique key: a row seen again takes
     * the latest vendor, category, example page and time, and one more hit. `dateCreated` and `uid`
     * keep their first values. Table and column names are in Yii's `{{%}}` and `[[ ]]` form, for
     * `quoteSql()`.
     *
     * @param bool $mysql MySQL or MariaDB; otherwise Postgres (the two databases Craft 5 supports)
     */
    public static function onConflictSql(bool $mysql): string
    {
        $updated = ['vendor', 'category', 'examplePath', 'dateUpdated'];

        if ($mysql) {
            // VALUES() rather than a row alias, which MariaDB does not support.
            $set = array_map(fn(string $c): string => "[[$c]] = VALUES([[$c]])", $updated);
            return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $set);
        }

        $set = array_map(fn(string $c): string => "[[$c]] = EXCLUDED.[[$c]]", $updated);
        $key = implode(', ', array_map(fn(string $c): string => "[[$c]]", Sighting::UNIQUE_KEY));
        return " ON CONFLICT ($key) DO UPDATE SET " . implode(', ', $set);
    }
}
