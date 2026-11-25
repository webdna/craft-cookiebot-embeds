<?php

namespace webdna\cookiebotembeds\services;

use Closure;
use Throwable;

/**
 * The plugin's throttled logging (BR-29): each distinct warning or error is written at most once
 * per hour, however many requests repeat it, so a busy site's log is not one line per page view.
 * Which third parties a site has is the report's job, not the log's.
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
}
