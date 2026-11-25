<?php

namespace webdna\cookiebotembeds\services;

use Closure;
use Throwable;
use webdna\cookiebotembeds\models\RewriteResult;

/**
 * The plugin's throttled logging (BR-29): each distinct line is written at most once per hour,
 * however many requests repeat it, so a busy site's log is not one line per page view.
 *
 * Pure: the hourly claim (Craft's cache, live) and the writer (Craft's logger, live) are injected.
 * Nothing is ever sent anywhere; the plugin only writes its own log.
 *
 * @internal Not part of the plugin's public API; it may change in any release.
 */
final class Log
{
    /** How long a line stays claimed, in seconds. */
    public const TTL = 3600;

    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** @var array<string, true> Keys already handled this request */
    private array $seen = [];

    /**
     * @param Closure(string $key, int $ttl): bool $claim True when the key was free and is now
     *        taken for `$ttl` seconds (Craft's `$cache->add()`)
     * @param Closure(string $level, string $message): void $write Writes one line
     */
    public function __construct(
        private readonly Closure $claim,
        private readonly Closure $write,
    ) {
    }

    /**
     * Writes the line unless the same key was written in the last hour. The key defaults to the
     * message. If the claim itself fails (cache down), the line is written: better a repeat than
     * silence. Returns whether it was written.
     */
    public function once(string $level, string $message, ?string $key = null): bool
    {
        $key ??= "$level|$message";
        if (isset($this->seen[$key])) {
            return false;
        }
        $this->seen[$key] = true;

        try {
            $claimed = ($this->claim)($key, self::TTL);
        } catch (Throwable) {
            $claimed = true;
        }
        if ($claimed) {
            ($this->write)($level, $message);
        }
        return $claimed;
    }

    /**
     * BR-29: each unknown host on the page, at `info`, at most once per hour per site (TN-12).
     * Returns how many lines were written.
     */
    public function unknownHosts(RewriteResult $result, string $siteHandle): int
    {
        $written = 0;
        foreach (self::unknownSightings($result) as $host => $sightings) {
            $seen = implode(', ', array_unique(array_map(
                fn(array $s): string => "{$s['kind']} {$s['action']}",
                $sightings,
            )));
            $message = "Unknown host `$host` on site `$siteHandle` ($seen), treated as {$sightings[0]['category']}. "
                . "Name it under `vendors`, or add it to `allow`, in config/cookiebot-embeds.php.";
            if ($this->once(self::INFO, $message, "unknown|$siteHandle|$host")) {
                $written++;
            }
        }
        return $written;
    }

    /**
     * The page's sightings of hosts listed nowhere (BR-6 step 6), by host. A sighting is unknown
     * when it has a host but no vendor and was not allowed: first-party URLs are never recorded,
     * Cookiebot, Tag Manager and listed providers carry a vendor, and `allow` and filesystem hosts
     * are `allowed`.
     *
     * @return array<string, non-empty-list<array{host: string, vendor: string|null, category: string, action: string, kind: string}>>
     */
    public static function unknownSightings(RewriteResult $result): array
    {
        $unknown = [];
        foreach ($result->sightings as $sighting) {
            if (self::isUnknown($sighting)) {
                $unknown[$sighting['host']][] = $sighting;
            }
        }
        return $unknown;
    }

    /**
     * Whether a sighting, or a report row, is of a host listed nowhere. Shared with the report, so
     * the log and the report never disagree about what is unknown.
     *
     * @param array{host: string, vendor: string|null, action: string} $sighting
     */
    public static function isUnknown(array $sighting): bool
    {
        return $sighting['host'] !== '' && $sighting['vendor'] === null && $sighting['action'] !== RewriteResult::ACTION_ALLOWED;
    }
}
