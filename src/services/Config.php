<?php

namespace webdna\cookiebotembeds\services;

use webdna\cookiebotembeds\models\SiteConfig;

/**
 * Turns the raw values of `config/cookiebot-embeds.php` into one site's `SiteConfig` (BR-27).
 *
 * A pure function of its arguments: no `Craft::$app`, no logging, so it is unit-tested without an
 * app. It never throws. Each invalid value is replaced by its default and described in
 * `SiteConfig::$warnings` for the caller to log; an invalid entry in a list is dropped on its own,
 * so one bad provider entry does not discard the site's others.
 */
final class Config
{
    /** Cookiebot's four consent categories. */
    public const CATEGORIES = ['necessary', 'preferences', 'statistics', 'marketing'];

    /** Categories a provider or fingerprint entry may carry: the four plus `infra` (BR-9, BR-28). */
    public const ENTRY_CATEGORIES = [...self::CATEGORIES, 'infra'];

    public const MODES = ['standard', 'vue'];

    public const COOKIEBOT_DOMAINS = ['consent.cookiebot.com', 'consent.cookiebot.eu'];

    /** The fallback key in a per-site array, for sites it does not list. */
    public const ALL_SITES = '*';

    /** @var list<string> */
    private array $warnings = [];

    /** What the warnings name as the source of a bad value. */
    private string $source = 'Config';

    /**
     * Resolves one site's configuration.
     *
     * Any key may be an array keyed by site handle (with `*` as the fallback): such an array is
     * recognised by having only site handles and `*` as keys. A site it does not list, with no
     * `*`, gets the default. `null` means "not set".
     *
     * @param array<array-key, mixed> $raw The config file's values (unknown keys are ignored)
     * @param string $siteHandle The site being resolved
     * @param list<string> $siteHandles Every site's handle, disabled sites included
     */
    public function resolve(array $raw, string $siteHandle, array $siteHandles): SiteConfig
    {
        $this->warnings = [];
        $this->source = 'Config';
        $defaults = new SiteConfig();

        $value = function(string $key) use ($raw, $siteHandle, $siteHandles): mixed {
            $value = $raw[$key] ?? null;
            if (self::isPerSite($value, $siteHandles)) {
                /** @var array<string, mixed> $value */
                return $value[$siteHandle] ?? $value[self::ALL_SITES] ?? null;
            }
            return $value;
        };

        return new SiteConfig(
            enabled: $this->bool('enabled', $value('enabled'), $defaults->enabled),
            mode: $this->oneOf('mode', $value('mode'), self::MODES, $defaults->mode),
            allow: $this->allow($value('allow')),
            vendors: $this->entries('vendors', 'match', $value('vendors')),
            fingerprints: $this->entries('fingerprints', 'pattern', $value('fingerprints')),
            placeholderTemplate: $this->optionalString('placeholderTemplate', $value('placeholderTemplate')),
            injectCookiebot: $this->bool('injectCookiebot', $value('injectCookiebot'), $defaults->injectCookiebot),
            cbid: $this->optionalString('cbid', $value('cbid')),
            cookiebotDomain: $this->oneOf('cookiebotDomain', $value('cookiebotDomain'), self::COOKIEBOT_DOMAINS, $defaults->cookiebotDomain),
            report: $this->bool('report', $value('report'), $defaults->report),
            warnings: $this->warnings,
        );
    }

    /**
     * Normalises a list of provider entries (`$keyField` `match`) or fingerprints (`pattern`) from
     * outside a site's config, by the same rules as the config's `vendors` and `fingerprints`. Used
     * for the shipped provider list (BR-28). Never throws; bad entries are dropped and described.
     *
     * @param string $source What the warnings name as the source, e.g. `vendors.json`
     * @return array{entries: list<array<string, string>>, warnings: list<string>}
     */
    public function normaliseEntries(string $source, string $key, string $keyField, mixed $value): array
    {
        $this->warnings = [];
        $this->source = $source;
        if ($value === null) {
            $this->warn($key, 'is missing', null, 'empty list');
        }
        $entries = $this->entries($key, $keyField, $value);

        return ['entries' => $entries, 'warnings' => $this->warnings];
    }

    /**
     * Compiles a fingerprint written without delimiters (the form the shipped provider list and the
     * cookiebot-check skill share) into a PCRE pattern, or returns null when it does not compile.
     * Matching is case-sensitive: fingerprints match JavaScript, which is.
     */
    public static function compilePattern(string $pattern): ?string
    {
        if (trim($pattern) === '') {
            return null;
        }
        $compiled = '~' . str_replace('~', '\~', $pattern) . '~';

        // Suppressed: a bad pattern raises a warning, which Craft turns into an exception in
        // dev mode, and config loading must never throw (BR-14, BR-27).
        return @preg_match($compiled, '') === false ? null : $compiled;
    }

    /**
     * @param list<string> $siteHandles
     */
    private static function isPerSite(mixed $value, array $siteHandles): bool
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return false;
        }
        foreach (array_keys($value) as $key) {
            if ($key !== self::ALL_SITES && !in_array($key, $siteHandles, true)) {
                return false;
            }
        }
        return true;
    }

    private function bool(string $key, mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_string($value)) {
            // App::env() already turns "true"/"false" into booleans; this also takes 1/0, yes/no, on/off.
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool !== null) {
                return $bool;
            }
        }
        $this->warn($key, 'must be true or false', $value, $default ? 'true' : 'false');
        return $default;
    }

    /**
     * @param list<string> $allowed
     */
    private function oneOf(string $key, mixed $value, array $allowed, string $default): string
    {
        if ($value === null) {
            return $default;
        }
        if (is_string($value) && in_array(strtolower(trim($value)), $allowed, true)) {
            return strtolower(trim($value));
        }
        $this->warn($key, 'must be one of ' . implode(', ', $allowed), $value, $default);
        return $default;
    }

    private function optionalString(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }
        $this->warn($key, 'must be a string', $value, 'not set');
        return null;
    }

    /**
     * @return list<string>
     */
    private function allow(mixed $value): array
    {
        if (!$this->isList('allow', $value)) {
            return [];
        }
        /** @var list<mixed> $value */
        $hosts = [];
        foreach ($value as $i => $host) {
            if (!is_string($host) || trim($host) === '') {
                $this->warn("allow[$i]", 'must be a non-empty host string', $host, 'dropped');
                continue;
            }
            $hosts[] = strtolower(trim($host));
        }
        return array_values(array_unique($hosts));
    }

    /**
     * A list of `{<keyField>, vendor, category}` entries: `vendors` (keyField `match`) or
     * `fingerprints` (keyField `pattern`).
     *
     * @return list<array{match: string, vendor: string, category: string}>|list<array{pattern: string, vendor: string, category: string}>
     */
    private function entries(string $key, string $keyField, mixed $value): array
    {
        if (!$this->isList($key, $value)) {
            return [];
        }
        /** @var list<mixed> $value */
        $entries = [];
        foreach ($value as $i => $entry) {
            $at = "{$key}[$i]";
            if (!is_array($entry)) {
                $this->warn($at, "must be an array with '$keyField', 'vendor' and 'category'", $entry, 'dropped');
                continue;
            }

            $field = $entry[$keyField] ?? null;
            if (!is_string($field) || trim($field) === '') {
                $this->warn("{$at}.$keyField", 'must be a non-empty string', $field, 'entry dropped');
                continue;
            }

            $category = $entry['category'] ?? null;
            $category = is_string($category) ? strtolower(trim($category)) : $category;
            if (!is_string($category) || !in_array($category, self::ENTRY_CATEGORIES, true)) {
                $this->warn("{$at}.category", 'must be one of ' . implode(', ', self::ENTRY_CATEGORIES), $category, 'entry dropped');
                continue;
            }

            if ($keyField === 'pattern') {
                $field = self::compilePattern($field);
                if ($field === null) {
                    $this->warn("{$at}.pattern", 'is not a valid regular expression', $entry['pattern'], 'entry dropped');
                    continue;
                }
            } else {
                $field = strtolower(trim($field));
            }

            // The vendor name is only shown in the report and the placeholder: the match stands in.
            $vendor = $entry['vendor'] ?? null;
            $vendor = is_string($vendor) && trim($vendor) !== '' ? trim($vendor) : trim((string)$entry[$keyField]);

            $entries[] = [$keyField => $field, 'vendor' => $vendor, 'category' => $category];
        }
        /** @var list<array{match: string, vendor: string, category: string}>|list<array{pattern: string, vendor: string, category: string}> $entries */
        return $entries;
    }

    private function isList(string $key, mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_array($value) && array_is_list($value)) {
            return true;
        }
        $this->warn($key, 'must be a list (an array without keys)', $value, 'empty list');
        return false;
    }

    private function warn(string $key, string $problem, mixed $value, string $using): void
    {
        $this->warnings[] = sprintf('%s `%s` %s; got %s. Using %s.', $this->source, $key, $problem, self::describe($value), $using);
    }

    private static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . (strlen($value) > 60 ? substr($value, 0, 57) . '...' : $value) . '"';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return get_debug_type($value);
    }
}
