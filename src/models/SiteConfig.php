<?php

namespace webdna\cookiebotembeds\models;

/**
 * One site's configuration after normalisation (BR-27): every value has its final type, and every
 * invalid value has already been replaced by its default. The constructor defaults are the
 * plugin's defaults, and the only place they are written down.
 *
 * @internal Not part of the plugin's public API; it may change in any release.
 */
final class SiteConfig
{
    /**
     * @param list<string> $allow Host substrings never gated on this site, lower-case (BR-6 step 3)
     * @param list<array{match: string, vendor: string, category: string}> $vendors Site provider
     *        entries, checked before the shipped list (BR-6 step 4); `match` is lower-case
     * @param list<array{pattern: string, vendor: string, category: string}> $fingerprints Site
     *        fingerprints (BR-14); `pattern` is a compiled PCRE pattern, delimiters included
     * @param list<string> $warnings What was wrong with the raw config, one line per problem
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly string $mode = 'standard',
        public readonly array $allow = [],
        public readonly array $vendors = [],
        public readonly array $fingerprints = [],
        public readonly ?string $placeholderTemplate = null,
        public readonly bool $injectCookiebot = false,
        public readonly ?string $cbid = null,
        public readonly string $cookiebotDomain = 'consent.cookiebot.com',
        public readonly bool $report = true,
        public readonly array $warnings = [],
    ) {
    }
}
