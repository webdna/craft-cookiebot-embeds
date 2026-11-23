<?php

namespace webdna\cookiebotembeds\models;

/**
 * A rewritten page and what the rewriter saw on it, for the report (BR-23).
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
     * @param string $html The page; identical to the input when nothing was gated
     * @param list<array{host: string, vendor: string|null, category: string, action: string, kind: string}> $sightings
     *        One per host, action and kind (the report's unique key, less the site); `host` is empty
     *        for an inline script recognised only by a fingerprint
     */
    public function __construct(
        public readonly string $html,
        public readonly array $sightings = [],
    ) {
    }
}
