<?php
/**
 * Cookiebot Embeds config.php
 *
 * Copy this file to your project's `config/` folder as `cookiebot-embeds.php`. It is the only
 * place the plugin is configured: there is no settings page in the control panel.
 *
 * Every setting is optional; the values shown commented out are the defaults. An invalid value
 * is logged as a warning and replaced by its default, so a mistake here never breaks the site.
 *
 * Any setting may instead be an array keyed by site handle, with '*' for every other site:
 *
 *     'enabled' => ['default' => true, 'intranet' => false],
 *     'allow' => ['*' => ['booking.example.com'], 'shop' => ['booking.example.com', 'pay.example.org']],
 *
 * Craft's multi-environment keys ('*', 'production', 'dev') work around the whole file as usual.
 */

use craft\helpers\App;

return [
    // false returns every page exactly as rendered.
    // 'enabled' => true,

    // 'vue' for Vue 2 sites: gated content waits until the site calls
    // window.CookiebotEmbeds.ready() from its root instance's mounted() hook.
    // 'mode' => 'standard',

    // Hosts (or host substrings) never held back on this site, such as a booking or payment
    // widget the report shows as unknown. Matched case-insensitively against host and path.
    // 'allow' => [
    //     'booking.example.com',
    // ],

    // Providers to add or reclassify, checked before the shipped list. Category is one of
    // necessary, preferences, statistics, marketing. Unknown providers wait for marketing.
    // 'vendors' => [
    //     ['match' => 'widgets.example.org', 'vendor' => 'Example Reviews', 'category' => 'preferences'],
    // ],

    // Regular expressions (no delimiters, case-sensitive) matched against inline script bodies,
    // for trackers whose addresses are disguised.
    // 'fingerprints' => [
    //     ['pattern' => 'exampleTracker\\.init\\(', 'vendor' => 'Example Tracker', 'category' => 'statistics'],
    // ],

    // A site template rendered in place of the plugin's placeholder. It receives category,
    // vendor (may be null), kind, width and height.
    // 'placeholderTemplate' => '_partials/cookie-placeholder',

    // For sites that do not load Cookiebot through Google Tag Manager: insert the banner script
    // at the top of every page's <head>, unless the page already loads uc.js. Needs cbid.
    // Put {{ cookiebotSettingsLink() }} in the footer so visitors can reopen the banner.
    // 'injectCookiebot' => false,
    // 'cbid' => App::env('COOKIEBOT_CBID'),
    // 'cookiebotDomain' => 'consent.cookiebot.com', // or 'consent.cookiebot.eu'

    // Record what was held back or left, for the report under Utilities.
    // 'report' => true,
];
