<?php

namespace webdna\cookiebotembeds\services;

use JsonException;

/**
 * The shipped provider list, `src/data/vendors.json` (BR-28), and first-match lookups over it.
 *
 * A pure service: it reads one file and makes no `Craft::$app` calls, so it is unit-tested without
 * an app. It never throws. A missing, unreadable or invalid file gives an empty list and a warning
 * (TN-13), so every third party is then unknown and gated as marketing (fail closed). Cookiebot and
 * Tag Manager stay `infra` either way, because BR-9 is coded here in `INFRA`, not only listed.
 *
 * The file is `{"vendors": [{match, vendor, category}], "fingerprints": [{pattern, vendor,
 * category}]}`. Vendors are checked in order and the first match wins (BR-7: a case-insensitive
 * substring of the URL's `host + path`). Fingerprints are regular expressions written without
 * delimiters and matched case-sensitively against inline script bodies (BR-14). The cookiebot-check
 * skill's copy is synced from this file, never the other way round.
 *
 * @internal Not part of the plugin's public API; it may change in any release.
 */
final class Vendors
{
    /**
     * Cookiebot and Google Tag Manager (BR-9). Checked before any list, site overrides included,
     * and never gated: gating them means nothing could ever be consented (B3).
     *
     * @var list<array{match: string, vendor: string, category: string}>
     */
    public const INFRA = [
        ['match' => 'consent.cookiebot.', 'vendor' => 'Cookiebot', 'category' => 'infra'],
        ['match' => 'consentcdn.cookiebot.', 'vendor' => 'Cookiebot', 'category' => 'infra'],
        ['match' => 'googletagmanager.com/gtm.js', 'vendor' => 'Google Tag Manager', 'category' => 'infra'],
        ['match' => 'googletagmanager.com/ns.html', 'vendor' => 'Google Tag Manager', 'category' => 'infra'],
    ];

    /**
     * Element ids that mark Cookiebot's own scripts whatever their URL (BR-9), and the plugin's own
     * Vue mode activator (BR-19), whose URL is Craft's resource URL: a CDN, or whatever host the
     * request came in on, either of which can classify as unknown. Gating it would stop Vue mode
     * ever releasing anything.
     */
    public const INFRA_IDS = ['Cookiebot', 'CookieDeclaration', 'cookiebot-embeds-activator'];

    private const SOURCE = 'vendors.json';

    /** @var list<array{match: string, vendor: string, category: string}> */
    private array $vendors = [];

    /** @var list<array{pattern: string, vendor: string, category: string}> */
    private array $fingerprints = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param string|null $path The provider list to load; the shipped one when null
     */
    public function __construct(?string $path = null)
    {
        $path ??= self::defaultPath();
        $data = $this->read($path);
        if ($data === null) {
            return;
        }

        $config = new Config();
        $vendors = $config->normaliseEntries(self::SOURCE, 'vendors', 'match', $data['vendors'] ?? null);
        $fingerprints = $config->normaliseEntries(self::SOURCE, 'fingerprints', 'pattern', $data['fingerprints'] ?? null);

        /** @var list<array{match: string, vendor: string, category: string}> $vendorEntries */
        $vendorEntries = $vendors['entries'];
        /** @var list<array{pattern: string, vendor: string, category: string}> $fingerprintEntries */
        $fingerprintEntries = $fingerprints['entries'];

        $this->vendors = $vendorEntries;
        $this->fingerprints = $fingerprintEntries;
        $this->warnings = [...$vendors['warnings'], ...$fingerprints['warnings']];
    }

    public static function defaultPath(): string
    {
        return dirname(__DIR__) . '/data/vendors.json';
    }

    /**
     * The first entry whose `match` is a substring of `$hostPath`, case-insensitively (BR-7).
     * Shared with the classifier for a site's own `vendors` overrides.
     *
     * @param list<array{match: string, vendor: string, category: string}> $entries Lower-case matches
     * @param string $hostPath The URL's host and path, without scheme, port or query
     * @return array{match: string, vendor: string, category: string}|null
     */
    public static function firstMatch(array $entries, string $hostPath): ?array
    {
        $hostPath = strtolower($hostPath);
        foreach ($entries as $entry) {
            if (str_contains($hostPath, $entry['match'])) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Every fingerprint that matches `$body`, in list order (BR-11, BR-14). Shared with the
     * classifier for a site's own `fingerprints`.
     *
     * @param list<array{pattern: string, vendor: string, category: string}> $fingerprints Compiled patterns
     * @return list<array{pattern: string, vendor: string, category: string}>
     */
    public static function matchingFingerprints(array $fingerprints, string $body): array
    {
        $matches = [];
        foreach ($fingerprints as $fingerprint) {
            // Suppressed: a backtrack-limit failure returns false, and is treated as no match.
            if (@preg_match($fingerprint['pattern'], $body) === 1) {
                $matches[] = $fingerprint;
            }
        }
        return $matches;
    }

    /**
     * The coded infra entry for `$hostPath`, if it is Cookiebot or Tag Manager (BR-9).
     *
     * @return array{match: string, vendor: string, category: string}|null
     */
    public function infra(string $hostPath): ?array
    {
        return self::firstMatch(self::INFRA, $hostPath);
    }

    /**
     * The shipped list's entry for `$hostPath` (BR-6 step 5), or null when it is not listed.
     *
     * @return array{match: string, vendor: string, category: string}|null
     */
    public function vendor(string $hostPath): ?array
    {
        return self::firstMatch($this->vendors, $hostPath);
    }

    /**
     * @return list<array{pattern: string, vendor: string, category: string}>
     */
    public function fingerprintsIn(string $body): array
    {
        return self::matchingFingerprints($this->fingerprints, $body);
    }

    /**
     * @return list<array{match: string, vendor: string, category: string}>
     */
    public function vendors(): array
    {
        return $this->vendors;
    }

    /**
     * @return list<array{pattern: string, vendor: string, category: string}>
     */
    public function fingerprints(): array
    {
        return $this->fingerprints;
    }

    /**
     * What was wrong with the file, one line per problem, for the caller to log.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function read(string $path): ?array
    {
        // Suppressed: a missing file raises a warning, which Craft turns into an exception in dev
        // mode, and this runs on every page (TN-13).
        $json = is_file($path) ? @file_get_contents($path) : false;
        if ($json === false) {
            $this->warnings[] = sprintf('%s could not be read at %s. Every third party is gated as marketing.', self::SOURCE, $path);
            return null;
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->warnings[] = sprintf('%s is not valid JSON (%s). Every third party is gated as marketing.', self::SOURCE, $e->getMessage());
            return null;
        }

        if (!is_array($data) || array_is_list($data)) {
            $this->warnings[] = sprintf('%s must be an object with "vendors" and "fingerprints". Every third party is gated as marketing.', self::SOURCE);
            return null;
        }
        return $data;
    }
}
