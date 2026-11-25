<?php

namespace webdna\cookiebotembeds\models;

/**
 * What the classifier decided for one URL (BR-6): its host, the provider it matched, the category
 * and which step of BR-6 decided it.
 *
 * @internal Not part of the plugin's public API; it may change in any release.
 */
final class Classification
{
    /** The site's own hosts and their subdomains, relative URLs and non-http schemes (step 1). */
    public const SOURCE_FIRST_PARTY = 'first-party';

    /** A host of one of the site's filesystems' public URLs (step 2). */
    public const SOURCE_FILESYSTEM = 'filesystem';

    /** The site's `allow` list (step 3). */
    public const SOURCE_ALLOW = 'allow';

    /** Cookiebot or Tag Manager, coded (BR-9). */
    public const SOURCE_INFRA = 'infra';

    /** The site's own `vendors` (step 4). */
    public const SOURCE_SITE = 'site';

    /** The shipped provider list (step 5). */
    public const SOURCE_LIST = 'list';

    /** Listed nowhere: marketing, fail closed (step 6). */
    public const SOURCE_UNKNOWN = 'unknown';

    /** The categories that are gated; `necessary` and `infra` never are. */
    public const GATED_CATEGORIES = ['preferences', 'statistics', 'marketing'];

    /**
     * @param string $host Lower-case, no port; empty for a relative or non-http URL
     * @param string|null $vendor The provider's name from a list; null when first-party, from a
     *        filesystem, or listed nowhere
     * @param string $category One of the four categories, or `infra`
     * @param string $source One of the `SOURCE_*` constants
     */
    public function __construct(
        public readonly string $host,
        public readonly ?string $vendor,
        public readonly string $category,
        public readonly string $source,
    ) {
    }

    public function isGated(): bool
    {
        return in_array($this->category, self::GATED_CATEGORIES, true);
    }
}
