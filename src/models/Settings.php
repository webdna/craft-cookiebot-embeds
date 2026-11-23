<?php

namespace webdna\cookiebotembeds\models;

use craft\base\Model;

/**
 * Cookiebot Embeds settings, set in `config/cookiebot-embeds.php`, never in the CP (BR-27).
 *
 * Every property is the raw value from the config file, typed `mixed` on purpose: Craft assigns
 * the file's values straight onto this model on every request, and a typed property given the
 * wrong type would throw and take the site down (profile, Traps). `services\Config` turns these
 * into a typed `SiteConfig`, replacing anything invalid with its default.
 *
 * `null` means "not set: use the default". Any key may instead be an array keyed by site handle,
 * with `*` as the fallback for sites not listed.
 */
class Settings extends Model
{
    /** Rewrite pages at all. Default `true`; `false` returns every page unchanged (BR-2). */
    public mixed $enabled = null;

    /** `standard` (default) or `vue` (BR-19). */
    public mixed $mode = null;

    /** Host substrings never gated on this site, e.g. `['booking.example.com']` (BR-6). */
    public mixed $allow = null;

    /** Extra or reclassified providers: a list of `['match' => ..., 'vendor' => ..., 'category' => ...]`. */
    public mixed $vendors = null;

    /** Extra fingerprints: a list of `['pattern' => ..., 'vendor' => ..., 'category' => ...]` (BR-14). */
    public mixed $fingerprints = null;

    /** A site template that renders the placeholder instead of the plugin's own (BR-17). */
    public mixed $placeholderTemplate = null;

    /** Insert the Cookiebot banner script into every page. Default `false` (BR-21). */
    public mixed $injectCookiebot = null;

    /** The Cookiebot domain group ID, needed when `injectCookiebot` is on (BR-21). */
    public mixed $cbid = null;

    /** `consent.cookiebot.com` (default) or `consent.cookiebot.eu` (BR-21). */
    public mixed $cookiebotDomain = null;

    /** Record what was gated or left for the CP report. Default `true` (BR-23). */
    public mixed $report = null;
}
