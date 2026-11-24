# Cookiebot Embeds

Third-party videos, maps, widgets and tracking scripts on a Craft CMS 5 site wait for the
visitor's Cookiebot consent automatically, without an editor or developer marking each one up by
hand.

Every page the site serves is checked on its way out. Each third-party `<iframe>` or `<script>` is
rewritten into Cookiebot's manual markup, so Cookiebot holds it back until the visitor accepts the
matching category, and a short message with a "Change cookie settings" link stands in for a blocked
video or map until then. The decision is made from **where the content comes from**, not from who
added it: a shipped provider list maps known providers to Cookiebot's categories, and anything from
a provider nobody has listed **waits for marketing consent** (the plugin fails closed).

## Not affiliated with Cookiebot

This plugin is made by webdna. It is not affiliated with, endorsed by or supported by Cookiebot
or Usercentrics. "Cookiebot" is used only to say which consent platform the plugin works with.

## Not a compliance guarantee

The plugin reduces the chance of a third party loading before consent, but it does not guarantee
compliance with GDPR, PECR or any other law. It only sees the HTML the server renders: anything
added later by JavaScript in the browser, by Google Tag Manager, or by a script it has been told
to leave alone is outside its reach. Check the site's consent setup yourself.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- Cookiebot already on the site (through Google Tag Manager, your own template, or the plugin's
  [banner option](#adding-the-cookiebot-banner))

## Installation

```bash
composer require webdna/craft-cookiebot-embeds
php craft plugin/install cookiebot-embeds
```

Installed with no configuration, the plugin is on for every site: known providers wait for their
category, unknown ones wait for marketing, and nothing else changes.

## What it changes, and what it leaves

On site pages with an HTML response (Live Preview included):

| Found | Becomes |
|---|---|
| An external script from a gated provider | `type="text/plain" data-cookieconsent="<category>"`, other attributes untouched |
| An inline script that loads a gated URL, or matches a fingerprint | The same, with the strictest category it names |
| An iframe from a gated provider | `src` renamed to `data-cookieblock-src`, plus `data-cookieconsent`, with a placeholder after it |
| A gated script in the body (a widget) | The same as an external script, with a placeholder before it |

Left exactly as they are:

- The site's own hosts (every site's base URL and its subdomains) and its filesystems' public URLs.
- Hosts in the [allow list](#allow-list), and providers in the `necessary` category.
- Cookiebot itself and Google Tag Manager, whatever the config says.
- Anything already marked up: a `data-cookieconsent` attribute or `type="text/plain"`. Use
  `data-cookieconsent="ignore"` on a tag to keep the plugin away from it.
- Non-JavaScript scripts (`application/ld+json`, `importmap`, templates) and `type="module"`
  scripts, which Cookiebot cannot release (they show in the report as skipped).
- Control-panel, console and action requests, and JSON, XML and other non-HTML responses.

Only the matched tags change; every other byte of the page is kept, so Alpine attributes and
HTML5 markup survive. The output depends only on the page and the config, never on the visitor, so
static and CDN caching stay correct.

## Configuration

Copy `vendor/webdna/craft-cookiebot-embeds/src/config.php` to `config/cookiebot-embeds.php`. It is
the only place the plugin is configured; there is no settings page. Every setting is optional, and
an invalid value is logged and replaced by its default, so a mistake never breaks the site.

| Setting | Default | Does |
|---|---|---|
| `enabled` | `true` | `false` returns every page byte for byte as rendered |
| `mode` | `standard` | `vue` for Vue 2 sites, see [Vue mode](#vue-mode) |
| `allow` | `[]` | Hosts never held back on this site, see [Allow list](#allow-list) |
| `vendors` | `[]` | Providers to add or reclassify, checked before the shipped list |
| `fingerprints` | `[]` | Regular expressions for inline trackers whose addresses are disguised |
| `placeholderTemplate` | `null` | A site template to use instead of the plugin's placeholder |
| `injectCookiebot` | `false` | Add the Cookiebot banner script to every page |
| `cbid` | `null` | Your Cookiebot domain group ID, needed by `injectCookiebot` |
| `cookiebotDomain` | `consent.cookiebot.com` | Or `consent.cookiebot.eu` |
| `report` | `true` | Record what was held back or left, for the report |

Any setting may be an array keyed by site handle, with `'*'` for every other site:

```php
return [
    'enabled' => ['default' => true, 'intranet' => false],
    'allow' => ['*' => ['booking.example.com'], 'shop' => ['booking.example.com', 'pay.example.org']],
];
```

### Allow list

`allow` takes host substrings, matched case-insensitively against the URL's host and path. Use it
for a third party the site needs before consent, such as a booking or payment widget:

```php
'allow' => ['booking.example.com'],
```

### Classifying providers

The shipped list is `src/data/vendors.json`. To name an unknown provider, or move a listed one to
another category (`necessary`, `preferences`, `statistics` or `marketing`):

```php
'vendors' => [
    ['match' => 'widgets.example.org', 'vendor' => 'Example Reviews', 'category' => 'preferences'],
],
'fingerprints' => [
    // No delimiters, case-sensitive.
    ['pattern' => 'exampleTracker\\.init\\(', 'vendor' => 'Example Tracker', 'category' => 'statistics'],
],
```

A provider marked `necessary` is never held back.

### Placeholder

The default placeholder is a `<span class="cbe-placeholder">` with a message and a "Change cookie
settings" link. It has no inline styles and no framework classes, so style `.cbe-placeholder`,
`.cbe-placeholder__message` and `.cbe-placeholder__link` in the site. Cookiebot hides it once the
category is accepted, through its `cookieconsent-optout-<category>` class.

To replace it, set `placeholderTemplate` to a site template. It receives `category`, `vendor`
(may be null), `kind` (`iframe` or `script`), and `width` and `height` (the iframe's own
attributes, or null). Keep the `cookieconsent-optout-{{ category }}` class on its root so
Cookiebot still hides it.

The messages are in the `cookiebot-embeds` translation category: copy
`src/translations/en/cookiebot-embeds.php` to `translations/<language>/cookiebot-embeds.php` to
change or translate them.

## Vue mode

Vue 2 rebuilds the page after it loads. A script Cookiebot releases before that loses its event
listeners in the rebuild, so in Vue mode the plugin marks gated content with its own attributes
(`data-cbe-consent`, `data-cbe-src`) and adds a small activator script to the head that releases it
once Vue has finished.

```php
'mode' => 'vue',
```

The site must tell the activator when Vue has mounted, from its root instance:

```js
new Vue({
    el: '#app',
    mounted() {
        window.CookiebotEmbeds && window.CookiebotEmbeds.ready();
    },
});
```

Content is released when both `ready()` has been called and Cookiebot has reported the visitor's
consent, and again on every later `ready()` call and consent change, so an embed Vue renders later
(under a `v-if`) is released too. A site that never calls `ready()` releases nothing. The activator
is an external file, so a strict Content Security Policy needs no `unsafe-inline`.

## Adding the Cookiebot banner

For sites that do not load Cookiebot through Google Tag Manager:

```php
'injectCookiebot' => true,
'cbid' => App::env('COOKIEBOT_CBID'),
```

Cookiebot's `uc.js` is then added as the first thing in every page's `<head>`, unless the page
already loads it. Without a `cbid` nothing is added and a warning is logged. Do not turn this on
for a site that also loads Cookiebot through Tag Manager: the plugin cannot see Tag Manager's copy,
and the banner would load twice.

Visitors need a way to reopen the banner. Put the settings link in the footer:

```twig
{{ cookiebotSettingsLink() }}
{{ cookiebotSettingsLink('Privacy and cookies', {class: 'footer-link'}) }}
```

It renders `<a href="javascript:Cookiebot.renew()">Cookie settings</a>`; the default text is
translatable. The plugin never inserts it by itself.

## Report

**Utilities > Cookiebot Embeds** lists every third party the plugin has seen: host, provider,
category, what it did (gated, left, allowed, already marked up, skipped), an example page, hit count
and when it was last seen. Unknown hosts come with the config line to allow or classify them. This
is where a new provider is spotted after it appears on the site.

Rows are written at most once an hour each, in one batched query, so busy uncached pages do not
write to the database on every view. Rows not seen for 30 days are removed by Craft's garbage
collection, and **Clear report** empties it so recording starts again at once. The utility is
visible to admins, and to anyone given the utility's permission. Set `report` to `false` to stop
recording.

## Logs

The plugin writes to `storage/logs/cookiebot-embeds-<date>.log`: each unknown host at `info`, at
most once an hour per site, and any config warning or internal error once an hour. A failure inside
the plugin is logged and the page is served unchanged; the plugin never takes a page down. It
makes no outbound requests and sends nothing anywhere.

## License

MIT. See [LICENSE.md](LICENSE.md).
