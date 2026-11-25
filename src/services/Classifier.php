<?php

namespace webdna\cookiebotembeds\services;

use webdna\cookiebotembeds\models\Classification;
use webdna\cookiebotembeds\models\SiteConfig;

/**
 * Decides, for one site, which category a URL belongs to and whether it is gated (BR-6 to BR-9).
 *
 * A pure service: the site's hosts and its filesystems' hosts are passed in (the render hook
 * gathers them once per request, task 3.1), so it is unit-tested without an app. It never throws.
 *
 * Order, first match wins (BR-6): (1) the site's own hosts and their subdomains; (2) the
 * filesystems' hosts; (3) the site's `allow` list; then Cookiebot and Tag Manager, which are coded
 * and come before the site's own `vendors` so no override can gate them (BR-9, B3); (4) the site's
 * `vendors`; (5) the shipped list; (6) otherwise unknown, marketing.
 *
 * @internal Not part of the plugin's public API; it may change in any release.
 */
final class Classifier
{
    /**
     * Site hosts, lower-case, no port, without a leading `www.`: each covers itself and every
     * subdomain, so `www.example.com` makes `example.com` and `cdn.example.com` first-party.
     *
     * @var list<string>
     */
    private array $siteHosts;

    /** @var list<string> Filesystem hosts, matched exactly */
    private array $filesystemHosts;

    /** @var array<string, Classification> */
    private array $cache = [];

    /**
     * @param list<string> $siteHosts Every site's base URL host (a port is ignored)
     * @param list<string> $filesystemHosts The hosts of the site's filesystems' public URLs
     */
    public function __construct(
        private readonly SiteConfig $config,
        private readonly Vendors $vendors,
        array $siteHosts,
        array $filesystemHosts,
    ) {
        $this->siteHosts = self::hosts($siteHosts, stripWww: true);
        $this->filesystemHosts = self::hosts($filesystemHosts, stripWww: false);
    }

    /**
     * Classifies the URL in a `src` attribute, as written in the page (BR-6, BR-7).
     */
    public function classify(string $url): Classification
    {
        return $this->cache[$url] ??= $this->classifyUncached($url);
    }

    /**
     * BR-8: a tag a developer (or Cookiebot's own markup) has already marked up is left exactly as
     * it is: any `data-cookieconsent` attribute, or a script whose `type` is `text/plain`.
     *
     * @param string $tag The element name, any case
     * @param array<string, string|null> $attributes Lower-case names; null for a bare attribute
     */
    public static function isAlreadyMarked(string $tag, array $attributes): bool
    {
        // The plugin's own Vue-mode attribute counts too (BR-19), so a page is never gated twice.
        if (array_key_exists('data-cookieconsent', $attributes) || array_key_exists('data-cbe-consent', $attributes)) {
            return true;
        }
        return strtolower($tag) === 'script'
            && strtolower(trim($attributes['type'] ?? '')) === 'text/plain';
    }

    /**
     * BR-9: Cookiebot's own elements are infra by their `id`, whatever their URL.
     *
     * @param array<string, string|null> $attributes Lower-case names; null for a bare attribute
     */
    public static function isInfraById(array $attributes): bool
    {
        return in_array($attributes['id'] ?? null, Vendors::INFRA_IDS, true);
    }

    /**
     * Splits a URL into its host and `host + path`, both lower-case (BR-7), or null when it loads
     * nothing from another origin: relative, `data:`, `javascript:`, `about:` and the like.
     *
     * @return array{host: string, hostPath: string}|null
     */
    public static function parse(string $url): ?array
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Browsers read `\` as `/` in http(s) URLs, so `https:\\evil.net` and `\\evil.net` load
        // from evil.net. Only the part before the path matters here.
        $url = str_replace('\\', '/', $url);

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        if (!preg_match('~^https?:~i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(rtrim($parts['host'] ?? '', '.')) : '';
        if ($host === '') {
            return null;
        }

        // Lower-cased whole: every match against it is case-insensitive (BR-7).
        return ['host' => $host, 'hostPath' => strtolower($host . ($parts['path'] ?? ''))];
    }

    private function classifyUncached(string $url): Classification
    {
        $parsed = self::parse($url);
        if ($parsed === null) {
            return new Classification('', null, 'necessary', Classification::SOURCE_FIRST_PARTY);
        }
        ['host' => $host, 'hostPath' => $hostPath] = $parsed;

        foreach ($this->siteHosts as $siteHost) {
            if ($host === $siteHost || str_ends_with($host, '.' . $siteHost)) {
                return new Classification($host, null, 'necessary', Classification::SOURCE_FIRST_PARTY);
            }
        }

        if (in_array($host, $this->filesystemHosts, true)) {
            return new Classification($host, null, 'necessary', Classification::SOURCE_FILESYSTEM);
        }

        $site = Vendors::firstMatch($this->config->vendors, $hostPath);
        $shipped = $this->vendors->vendor($hostPath);

        // An allow entry is a substring of `host + path`, like a provider match, so a site can
        // allow one path of a host. The report still names the provider when one is listed.
        foreach ($this->config->allow as $allowed) {
            if (str_contains($hostPath, $allowed)) {
                $vendor = ($site ?? $shipped)['vendor'] ?? null;
                return new Classification($host, $vendor, 'necessary', Classification::SOURCE_ALLOW);
            }
        }

        $infra = $this->vendors->infra($hostPath);
        if ($infra !== null) {
            return new Classification($host, $infra['vendor'], 'infra', Classification::SOURCE_INFRA);
        }

        if ($site !== null) {
            return new Classification($host, $site['vendor'], $site['category'], Classification::SOURCE_SITE);
        }

        if ($shipped !== null) {
            return new Classification($host, $shipped['vendor'], $shipped['category'], Classification::SOURCE_LIST);
        }

        return new Classification($host, null, 'marketing', Classification::SOURCE_UNKNOWN);
    }

    /**
     * @param list<string> $hosts
     * @return list<string>
     */
    private static function hosts(array $hosts, bool $stripWww): array
    {
        $normalised = [];
        foreach ($hosts as $host) {
            $host = strtolower(trim($host));
            // A bare host may carry a port; parse it as a URL to drop it.
            $parsed = self::parse(str_contains($host, '//') ? $host : '//' . $host);
            $host = $parsed['host'] ?? '';
            if ($stripWww && str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }
            if ($host !== '') {
                $normalised[] = $host;
            }
        }
        return array_values(array_unique($normalised));
    }
}
