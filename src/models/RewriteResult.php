<?php

namespace webdna\cookiebotembeds\models;

/**
 * A rewritten page and what the rewriter saw on it, for the report (BR-23).
 *
 * @internal Not part of the plugin's public API; it may change in any release.
 */
final class RewriteResult
{
    public const ACTION_GATED = 'gated';
    public const ACTION_LEFT = 'left';
    public const ACTION_ALLOWED = 'allowed';
    public const ACTION_ALREADY = 'already';
    public const ACTION_SKIPPED = 'skipped';

    public const KIND_SCRIPT = 'script';
    public const KIND_INLINE = 'inline';
    public const KIND_IFRAME = 'iframe';

    /**
     * The stand-in host of an inline script recognised only by a fingerprint, followed by its
     * lower-cased vendor, so two such trackers on a page stay two rows under the report's key.
     */
    public const FINGERPRINT_HOST = 'fingerprint:';

    /**
     * @param string $html The page; identical to the input when nothing was gated
     * @param list<array{host: string, vendor: string|null, category: string, action: string, kind: string}> $sightings
     *        One per host, action and kind (the report's unique key, less the site); `host` is
     *        {@see self::FINGERPRINT_HOST} plus the vendor for an inline script recognised only by
     *        a fingerprint
     */
    public function __construct(
        public readonly string $html,
        public readonly array $sightings = [],
    ) {
    }
}
