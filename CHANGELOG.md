# Cookiebot Embeds Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 1.0.0 - 2026-11-25
### Added
- Initial release.
- Every site page with an HTML response, Live Preview included, is checked as it is rendered: third-party iframes, external scripts and inline scripts that load a third party are rewritten into Cookiebot's manual markup, so they wait for the visitor's consent to their category. Only the matched tags change; the page is never re-serialised, so HTML5 and Alpine attributes survive. Output depends only on the page and the config, never on the visitor, so static caching stays correct.
- Fail closed: a provider nobody has listed waits for marketing consent. The site's own hosts, its filesystems' public URLs, allowed hosts and `necessary` providers are left; Cookiebot and Google Tag Manager are never gated; anything already marked up (`data-cookieconsent`, `type="text/plain"`) is left exactly as it is. Non-JavaScript scripts and `type="module"` scripts are never touched.
- Shipped provider list `src/data/vendors.json`: 63 providers with their Cookiebot category, and 18 fingerprints for inline loaders that hide their URL. A missing or broken list is logged and every third party is then gated as marketing.
- `config/cookiebot-embeds.php` settings (`enabled`, `mode`, `allow`, `vendors`, `fingerprints`, `placeholderTemplate`, `injectCookiebot`, `cbid`, `cookiebotDomain`, `report`), each optionally keyed by site handle with `*` as the fallback. An invalid value is logged and replaced by its default; loading the config never throws. There is no settings page.
- A placeholder after a blocked iframe or before a blocked widget script (one per provider per page, so an iframe and its player script show one), with a translatable message and a "Change cookie settings" link, no inline styles, and a site template override (`placeholderTemplate`).
- README: a worked styling example for the placeholder (a centred panel, covering the empty frame in a responsive embed wrapper, CSS purging, and how Cookiebot hides it).
- Vue mode (`mode` = `vue`): gated content is released by the plugin's own activator script once the site calls `window.CookiebotEmbeds.ready()` from its root Vue instance and Cookiebot has reported consent, so released widgets keep their listeners.
- Banner option (`injectCookiebot`, `cbid`, `cookiebotDomain`) that adds Cookiebot's `uc.js` to the top of every page's head, for sites that do not load Cookiebot through Google Tag Manager.
- The `cookiebotSettingsLink()` Twig function, a link that reopens the Cookiebot banner.
- The Cookiebot Embeds report under Utilities: every third party seen, with category, action, example page and last seen, the config line for unknown hosts, and a clear button. Rows are throttled to one write per hour each and pruned after 30 days by garbage collection.
- The plugin's own log file, `storage/logs/cookiebot-embeds-<date>.log`, for its config warnings and errors, each once an hour. Any failure inside the plugin is logged and the page is served unchanged.
