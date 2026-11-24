<?php

namespace webdna\cookiebotembeds\services;

use Closure;
use webdna\cookiebotembeds\models\SiteConfig;

/**
 * BR-21, the banner option: Cookiebot's `uc.js` as the first child of `<head>`, for sites that do
 * not load Cookiebot through Tag Manager. A pure string edit on the page, like the rewriter: the
 * page is never parsed (BR-4), and nothing about the visitor is read (BR-3).
 */
final class Banner
{
    /** The id Cookiebot's own snippet uses; it also makes the script infra by id (BR-9). */
    public const SCRIPT_ID = 'Cookiebot';

    /** Logged (once per hour) when the option is on without an ID. */
    public const MISSING_CBID = '`injectCookiebot` is on but `cbid` is not set, so the Cookiebot banner was not added.';

    /**
     * The page with `uc.js` inserted, or null when it is left as it is: the plugin or the option
     * is off, there is no ID (`$onWarning` is told), the page already loads a `consent.cookiebot`
     * `uc.js`, or it has no `<head>` to put it in (TN-17).
     *
     * @param (Closure(string $message): void)|null $onWarning
     */
    public static function insert(string $html, SiteConfig $config, ?Closure $onWarning = null): ?string
    {
        if (!$config->enabled || !$config->injectCookiebot) {
            return null;
        }
        if ($config->cbid === null || $config->cbid === '') {
            if ($onWarning !== null) {
                $onWarning(self::MISSING_CBID);
            }
            return null;
        }
        if (self::hasUcJs($html)) {
            return null;
        }
        $at = self::headContentStart($html);
        if ($at === null) {
            return null;
        }
        return substr($html, 0, $at) . self::tag($config->cbid, $config->cookiebotDomain) . substr($html, $at);
    }

    /**
     * Cookiebot's manual-mode snippet: no `async`, `defer` or `data-blockingmode`, so it runs
     * before anything else in the head.
     */
    public static function tag(string $cbid, string $domain): string
    {
        return sprintf(
            '<script id="%s" src="https://%s/uc.js" data-cbid="%s" type="text/javascript"></script>',
            self::SCRIPT_ID,
            htmlspecialchars($domain, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5),
            htmlspecialchars($cbid, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5),
        );
    }

    /**
     * Whether any `<script>` tag on the page loads a `consent.cookiebot` `uc.js` (any of
     * Cookiebot's domains, any scheme).
     */
    public static function hasUcJs(string $html): bool
    {
        return preg_match('~<script\b[^>]*consent\.cookiebot\.[a-z]+/uc\.js~i', $html) === 1;
    }

    /**
     * The offset just past the page's `<head ...>` tag, or null when there is none before the
     * body (a fragment, or a `<head` that is only text further down).
     */
    public static function headContentStart(string $html): ?int
    {
        if (preg_match('~<(head|body)(?=[\s/>])[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        if (strtolower($m[1][0]) !== 'head') {
            return null;
        }
        return $m[0][1] + strlen($m[0][0]);
    }
}
