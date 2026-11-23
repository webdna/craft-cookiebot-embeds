---
spec: Cookiebot embeds
slug: cookiebot-embeds
status: draft
version: 0.2
date: 2026-11-20
author: Claude (for Sam Birch)
client: webdna (internal, used across client sites)
approver: Sam Birch
profile: _PROFILE.craft-cookiebot-embeds.md
related: []
---

# Cookiebot embeds

> **Status:** draft - **Version:** 0.2 - **Profile:** `_PROFILE.craft-cookiebot-embeds.md`
> Third-party videos, maps, widgets and tracking scripts on a Craft site wait for the visitor's
> Cookiebot consent automatically, without an editor or developer marking each one up by hand.

---

## 1. How it works

Today, anything a third party serves into a page (a pasted YouTube video, a map, a review widget, a
tracking script added to the layout) runs the moment the page loads unless someone has remembered to
mark it up for Cookiebot. Editors never remember, and developers miss the ones added years ago. Once
this plugin is installed, **every page the site serves is checked on its way out**: each third-party
embed or script is rewritten so Cookiebot holds it back until the visitor accepts the matching
category of cookies, and a short message with a "change cookie settings" link stands in for any
blocked video or map until then.

The one idea it rests on is that **the decision is made from where the content comes from, not from
who added it.** A shared list maps known providers to Cookiebot's categories (YouTube and Google Maps
are marketing, Hotjar is statistics, reCAPTCHA and payment providers are necessary). Anything from a
provider nobody has listed **waits for marketing consent**: the plugin fails closed. The site's own
addresses, its file storage, providers marked necessary, and anything a developer has explicitly
allowed are never touched, and anything already marked up for Cookiebot is left exactly as it is.
Cookiebot itself and Google Tag Manager are never held back.

A developer can see what the plugin has been doing in a report in the control panel: which providers
it held back, under which category, on which page, and when it last saw them. That is where an
unknown provider is spotted and either classified or allowed.

Two site setups need more than the default. Sites built with **Vue 2**, which rebuilds the page after
it loads, get a mode in which the plugin's own small script releases blocked content once Vue has
finished, so consented videos still work. Sites that **do not load Cookiebot through Google Tag
Manager** can switch on an option that adds the Cookiebot banner to every page.

**Rejected:** rewriting embeds when an entry is saved. The rich-text editor removes the attributes
Cookiebot needs on the next edit, existing content would need re-saving, and a change to the provider
list would need every entry rewritten again. **Rejected:** checking only rich-text fields, because
layout-level trackers are the ones most often missed. **Rejected:** Cookiebot's own automatic
blocking mode, which conflicts with loading Cookiebot through Tag Manager and is known to misfire.

**Vocabulary**

| Term | Means |
|---|---|
| Embed | Anything on a page that loads content from another company: a video player, map, widget or script |
| Gated | Rewritten so it does nothing until the visitor consents to its category |
| Category | One of Cookiebot's four: necessary, preferences, statistics, marketing |
| Provider list | The shared list of known providers and the category each belongs to |
| Allowed | A provider a developer has told the plugin never to hold back on this site |
| Placeholder | The message shown where a blocked video, map or widget would be |
| Fail closed | An unknown provider is treated as marketing until someone says otherwise |

---

## 2. Scope

**In scope**

- Checking every page the public site serves and gating third-party embedded frames and scripts:
  scripts loaded from another site, inline scripts that load one, and scripts recognised by a known
  fingerprint even when their addresses are disguised
- The shared provider list, published with the plugin, plus per-site additions, reclassifications and
  an allow list
- Failing closed on unknown providers
- A placeholder for blocked frames and widgets, with a plain default message and a per-site override
- A Vue 2 mode
- An optional switch that adds the Cookiebot banner script, and a "Cookie settings" link developers
  can place in a footer
- A control-panel report of what was gated or left, with an empty state and a clear button
- A site-wide off switch
- An icon, README, licence and changelog; public on GitHub and Packagist

**Out of scope**

| Not doing | Why |
|---|---|
| Changes to Google Tag Manager, Consent Mode or the Cookiebot account | Those live outside the site; the cookiebot-check skill writes the admin steps |
| Editing the provider list in the control panel | Config is versioned and reviewed with the site; a CP editor is a second source of truth |
| Warning editors when they save an entry | Render-time gating already covers it; a warning adds editor friction for no extra protection |
| Gating images | Most remote images set no cookies, and failing closed would blank legitimate content images |
| Embeds created later by the page's own JavaScript | The plugin only sees the page as the server sends it |
| JSON, XML, feeds, sitemaps, emails and control-panel pages | Not pages a visitor's browser runs scripts from |
| Cookiebot's automatic blocking mode | See section 1 |

**Later**

- A Craft Plugin Store listing (store copy, screenshots, Craft Console)
- Gating third-party images behind an opt-in setting
- Gating `type="module"` scripts through the plugin's own activator (Cookiebot re-runs every
  released script as a classic script, so it cannot release a module; spike 0.1)
- The cookiebot-check skill reading the plugin's provider list instead of its own copy

---

## 3. User journeys

**Who this is for**

| Group | What they get |
|---|---|
| Visitors | Nothing from a third party runs until they consent; blocked content says why and how to allow it |
| Editors | Can paste an embed code and it is handled; nothing new to learn |
| webdna developers | One install covers a site; a report shows what to classify; one list to maintain across sites |
| Site owners | Fewer consent gaps on pages nobody re-checks |
| Affected: developers on Vue 2 sites | Must call one function when their app has mounted (section 6) |
| Affected: whoever adds a necessary widget from an unknown provider | It is held back until they allow it, so they must check the report after adding one |

**1. An editor pastes a video**
   1. The editor pastes a YouTube embed code into a page and saves it.
   2. A new visitor opens the page and sees the cookie banner and, where the video would be, a
      message asking them to accept marketing cookies, with a link that reopens the banner.
   3. They accept marketing cookies; the message disappears and the video loads in its place.

**2. A developer installs the plugin on an existing site**
   1. The developer installs the plugin and deploys.
   2. Every tracking script and widget already in the site's templates, from known or unknown
      providers, now waits for the right consent; the site's own scripts, its forms' spam protection
      and Cookiebot itself still load immediately.
   3. They open the plugin's report in the control panel after browsing a few pages.

**3. A developer classifies an unknown provider**
   1. The report lists a provider marked "unknown, gated as marketing" and the page it was seen on.
   2. If it is a necessary service (a booking or payment widget), the developer adds it to the
      site's allow list; if it is analytics, they reclassify it as statistics.
   3. They deploy, clear the report, browse the page again, and the report shows the new treatment.

**4. A visitor on a Vue 2 site**
   1. The visitor accepts marketing cookies on a page with a video.
   2. The video loads after the page's Vue app has finished building, and keeps working when they
      navigate around the page.

**5. A site that does not load Cookiebot through Tag Manager**
   1. The developer switches on the banner option and sets the site's Cookiebot ID.
   2. Every page now shows the Cookiebot banner, and the developer places the "Cookie settings" link
      in the footer.

**First run - the empty state.** Installed with no configuration, the plugin is active on the public
site with the shared provider list, fails closed, and shows the default placeholder; the banner
option is off. The report says "No third-party embeds seen yet. Browse the site's pages and they will
appear here." until the first page is served.

---

## 4. Data model

**`{{%cookiebotembeds_sightings}}`** - one row per site, provider host and treatment

| Field | Type | Notes |
|---|---|---|
| `id` | int, PK | |
| `siteId` | int, FK to sites, cascade delete | |
| `host` | string(255) | The provider's host, lower-case, no port |
| `vendor` | string(100), null | Provider name from the list; null when unknown |
| `category` | string(20) | One of the four categories, or `infra` |
| `action` | string(20) | `gated`, `left`, `allowed`, `already` (already marked up), `skipped` (e.g. module script) |
| `kind` | string(10) | `script`, `inline`, `iframe` |
| `examplePath` | string(255) | Path of the most recent page it was seen on, no query string |
| `hits` | int | Times seen since first recorded |
| `dateCreated`, `dateUpdated`, `uid` | standard | `dateUpdated` is "last seen" |

Unique key: `siteId, host, action, kind`.

**Why this shape.** The report answers "what is this plugin doing to my site", which is a question
about providers, not pages, so the table is bounded by the number of providers a site uses rather
than by its page count. One example page per row is enough to go and look. Nothing about the visitor
is stored: rows come from the page content, not from who requested it.

**On deletion.** Rows go with their site (cascade). Rows not seen for 30 days are pruned by Craft's
garbage collection. The report's clear button deletes all rows. Uninstall drops the table.

**Configuration** lives in the site's `config/cookiebot-embeds.php`, not the database (section 5).

---

## 5. Rules

| # | Rule |
|---|---|
| BR-1 | The plugin rewrites only the output of page templates rendered for a **site** request with an HTML response. Control-panel, console and action-controller requests, and JSON, XML or other non-HTML responses, are never touched. Live Preview pages are site pages and are rewritten like any other. |
| BR-2 | When the `enabled` setting is false, the page output is returned **byte-for-byte unchanged**. Default `true`. |
| BR-3 | Output depends only on the page HTML and configuration, **never on the request's cookies, consent state, user or headers**, so a statically cached page is correct for every visitor. |
| BR-4 | Only matched tags are rewritten. Every byte outside a rewritten tag (and its inserted placeholder) is unchanged. The page is never re-serialised by an HTML parser (no `DOMDocument`), because that mangles HTML5 and Alpine attributes (`@click`, `x-on:`, `:src`). |
| BR-5 | Fast exit: when the output contains neither `<iframe` nor `<script` with a `src` attribute or an inline body that names a URL or matches a fingerprint, it is returned unchanged without further scanning. |
| BR-6 | Classification order for a URL, first match wins: (1) the site's own hosts (every site's base URL host, and their subdomains); (2) hosts of the site's filesystems' public URLs; (3) the per-site `allow` list; (4) per-site `vendors` overrides; (5) the shipped provider list; (6) otherwise **unknown, category marketing**. (1)-(3) and providers in category `necessary` or `infra` are never gated. |
| BR-7 | Matching against a provider entry is a case-insensitive substring match on `host + path` of the URL, with protocol-relative (`//`) URLs treated as `https:`. Relative and `data:` URLs are first-party. |
| BR-8 | Any `<script>` or `<iframe>` that already has a `data-cookieconsent` attribute, or a `<script>` whose `type` is already `text/plain`, is left exactly as it is and recorded as `already`. This is the developer's opt-out (e.g. `data-cookieconsent="ignore"`). |
| BR-9 | Cookiebot's own scripts (any `consent.cookiebot.` or `consentcdn.cookiebot.` host, `id="Cookiebot"`, `id="CookieDeclaration"`) and Google Tag Manager (`googletagmanager.com/gtm.js`, `googletagmanager.com/ns.html`) are category `infra` and never gated. |
| BR-10 | **External script** classified as preferences, statistics or marketing: set `type="text/plain"` (replacing any existing `type`) and add `data-cookieconsent="<category>"`. All other attributes are kept in their original order and quoting. |
| BR-11 | **Inline script** (no `src`): if its body contains a URL that classifies as gated under BR-6, or matches a fingerprint, gate it as in BR-10 with the strictest category found (marketing over statistics over preferences). An inline script naming only first-party, allowed, necessary or infra URLs is left. |
| BR-12 | Scripts whose `type` is anything other than empty, `text/javascript`, `application/javascript` or `module` are never touched: `application/ld+json`, `application/json`, `importmap`, `text/x-template`, `text/template` and the like. |
| BR-13 | Scripts with `type="module"` are left and recorded as `skipped`. Cookiebot (uc.js 2.146.0) releases a gated script by cloning it and forcing `type="text/javascript"`, so a gated module would run as a classic script and fail with a `SyntaxError` on its first `import` (spike 0.1). |
| BR-14 | **Fingerprints** are regular expressions matched against inline script bodies, each with a vendor and category; shipped ones live in the provider list, sites can add their own under `fingerprints`. A fingerprint that fails to compile is logged once and ignored, never thrown. |
| BR-15 | **iframe** with a `src` that classifies as gated: rename `src` to `data-cookieblock-src`, add `data-cookieconsent="<category>"`, and insert the placeholder immediately after the iframe. An iframe with no `src`, an empty `src`, `about:blank`, or `srcdoc` is left. |
| BR-16 | **Widget script in the body**: a gated external or inline script that appears after `<body` gets the placeholder inserted immediately before it. Scripts in `<head>` get no placeholder. |
| BR-17 | The **placeholder** is rendered from the plugin's template, or from the site template named in `placeholderTemplate`, with variables `category`, `vendor` (may be null), `kind`, `width` and `height` (copied from the iframe's attributes when present). It carries the class `cookieconsent-optout-<category>` so Cookiebot hides it once that category is accepted. The default message is translatable in the `cookiebot-embeds` category and links to `javascript:Cookiebot.renew()`. |
| BR-18 | The default placeholder has no inline `style` attribute (strict CSP sites) and no dependency on the site's CSS framework; it uses `cbe-placeholder` classes that a site may style. |
| BR-19 | **Vue mode** (`mode` = `vue`): instead of Cookiebot's attributes, gated elements get the plugin's own (`data-cbe-consent`, `data-cbe-src`, with `type="text/plain"` on scripts) so Cookiebot does not activate them before Vue has rebuilt the page. The plugin registers its activator script in the page head. The activator releases elements whose category is consented once both (a) the site has called `window.CookiebotEmbeds.ready()` and (b) Cookiebot has reported consent, and again on every later Cookiebot consent event; it shows and hides placeholders itself. Released scripts are replaced by live copies with all attributes and inline body kept. Confirmed by spike 0.1: for a returning visitor Cookiebot releases scripts as soon as it loads, which can be before Vue mounts, and Vue's rebuild then removes the listeners the script attached. An element Vue creates again later (for example under a `v-if`) comes back gated and is released on the next `ready()` call or consent event, not at once. |
| BR-20 | *Superseded by spike 0.1 (2026-11-22); BR-19 stands.* Cookiebot's own attributes survive Vue's rebuild for iframes but not for scripts, because Cookiebot's release timing races Vue's mount (section 8, spike notes). |
| BR-21 | **Banner option**, a switch in the plugin config (`injectCookiebot`, default `false`, for sites that do not load Cookiebot through Tag Manager): when true and `cbid` is set, a Cookiebot `uc.js` script with `data-cbid` and no `async`, `defer` or `data-blockingmode` is inserted as the first child of `<head>`. It is not inserted when the page already contains a `consent.cookiebot` `uc.js` script. `cookiebotDomain` chooses `consent.cookiebot.com` (default) or `consent.cookiebot.eu`. With `injectCookiebot` true and no `cbid`, nothing is inserted and a warning is logged once per hour. |
| BR-22 | The Twig function `cookiebotSettingsLink(text = null, attrs = {})` returns an `<a href="javascript:Cookiebot.renew()">` with translatable default text "Cookie settings". The plugin never inserts it into the page by itself. |
| BR-23 | **Report**: when `report` is true (default), each rewritten page records its sightings (BR-8, BR-10 to BR-16 outcomes, plus left first-party/allowed/necessary third parties as `allowed`/`left`) in one batched upsert. A given site/host/action/kind row is written at most once per hour per PHP process cache window (throttled through Craft's cache), so busy uncached pages do not write on every request. |
| BR-24 | A failure to record the report (database down, lock, missing table) is logged and swallowed; the page is still served, rewritten. |
| BR-25 | The report is a Craft utility, visible only to users with access to that utility (admins by default). Its clear action is a POST with CSRF, same permission. |
| BR-26 | Rows not seen for 30 days are deleted during Craft garbage collection. |
| BR-27 | Configuration is read only from `config/cookiebot-embeds.php` (with `App::env()` values where the user writes them); there is no CP settings page. Invalid values (unknown category, unknown mode, non-array lists) are logged once and replaced by the default for that key; loading the config never throws. |
| BR-28 | The shipped provider list is `src/data/vendors.json`: an ordered array of `{match, vendor, category}` plus a `fingerprints` array of `{pattern, vendor, category}`. Categories are `necessary`, `preferences`, `statistics`, `marketing` or `infra`. It is the single source of truth shared with the cookiebot-check skill; the skill's copy is synced from it, not the other way round. |
| BR-29 | Each unknown host is logged at `info` level in the `cookiebot-embeds` log category at most once per hour per site. Nothing is ever sent anywhere: the plugin makes no outbound requests and sends no email or notification. |
| BR-30 | The repo contains no client names, client domains, or real Cookiebot, Tag Manager, analytics or CRM IDs, in code, tests, fixtures or docs. Fixtures use `example.com`, `example.org` and made-up IDs. Enforced in CI (profile V5). |
| BR-31 | The README states that the plugin is not affiliated with or endorsed by Cookiebot or Usercentrics, and that it reduces but does not guarantee compliance: it only sees what the server renders. |

**Non-functional**

- **Performance:** on the 300 KB benchmark fixture with 40 scripts and 6 iframes, the rewrite adds
  under 5 ms at the median and 15 ms at p95 on CI hardware; the fast exit (BR-5) adds under 0.5 ms.
- **Privacy:** no personal data is stored or sent (BR-3, BR-29).
- **Compatibility:** Craft 5.0+, PHP 8.2 to 8.4; works behind Servd static cache and Blitz because of
  BR-3.

---

## 6. Interfaces

| Method | Path | Purpose | Auth | Returns |
|---|---|---|---|---|
| GET | `admin/utilities/cookiebot-embeds` | The report | Utility permission (BR-25) | CP page |
| POST | `actions/cookiebot-embeds/report/clear` | Delete all report rows for all sites | Utility permission + CSRF | Redirect with flash notice |

**Configuration** (`config/cookiebot-embeds.php`, template shipped as `src/config.php`):
`enabled`, `mode` (`standard` or `vue`), `allow` (array of host substrings), `vendors` (array of
`{match, vendor, category}`, checked before the shipped list), `fingerprints` (same shape with
`pattern`), `placeholderTemplate` (site template path or null), `injectCookiebot`, `cbid`,
`cookiebotDomain`, `report`. Multi-site arrays keyed by site handle are accepted for every key.

**Twig:** `cookiebotSettingsLink()` (BR-22).

**JavaScript (Vue mode only):** `window.CookiebotEmbeds.ready()`, called by the site from its root
Vue instance's `mounted()` hook. Idempotent.

**Screens**

| Surface | New or reuse | Notes |
|---|---|---|
| Report utility | New, Craft's native utility layout and admin table | Columns: site, host, provider, category, action, kind, example page (link), hits, last seen. Filter by site. Each unknown row shows the config line to allow or classify it. Clear button. Empty state per section 3. |
| Placeholder | New, plugin template | Message plus "change cookie settings" link; sized from the iframe's width and height attributes via data attributes only |

**Design source.** None: the report reuses Craft's own CP components; the placeholder is unstyled by
design (BR-18) and sites style or override it.

**Copy ownership.** The developer writes the default placeholder text, the settings-link text, the
report's labels and its empty state, all translatable. No client copy is owed.

---

## 7. Test plan

### Client-verified criteria

| # | Given / When / Then | Proved by |
|---|---|---|
| AC-1 | Given a page with a pasted YouTube embed, when a new visitor opens it, then no request goes to YouTube, a placeholder with a "change cookie settings" link shows, and after accepting marketing the video plays in its place | TS-1 |
| AC-2 | Given a layout script from a provider on the list and one from an unlisted provider, when a new visitor opens any page, then neither loads until the right category is accepted (statistics or marketing respectively) | TS-2 |
| AC-3 | Given the site's own scripts, a form with reCAPTCHA, and a provider on the site's allow list, when a new visitor opens the page, then all of them load immediately and the form submits | TS-3 |
| AC-4 | Given Cookiebot and Tag Manager on the page and a script a developer marked `data-cookieconsent="ignore"`, when the page is served, then all three are unchanged | TS-4 |
| AC-5 | Given a site placeholder template in config, when a blocked video shows, then the site's message is shown instead of the default | TS-5 |
| AC-6 | Given a Vue 2 site in Vue mode, when a visitor accepts marketing, then a pasted video loads after the app mounts and still plays after interacting with Vue components on the page | TS-6 |
| AC-7 | Given a fresh install, the report shows the empty-state message; after browsing pages with embeds it lists each provider with category, action and an example page; clear empties it | TS-7 |
| AC-8 | Given a statically cached page, when one visitor accepts marketing and another declines, then each sees the behaviour matching their own choice | TS-8 |
| AC-9 | Given `enabled` false, when any page is served, then its HTML is identical to the HTML without the plugin installed | TS-9 |
| AC-10 | Given a site without Cookiebot in Tag Manager and the banner option on with an ID, every page shows the Cookiebot banner exactly once and the footer settings link reopens it; with the option off, nothing is added | TS-10 |

### Test data and preconditions

- **HTML fixtures** in `tests/fixtures/` (to be made), each an input and expected output pair:
  `iframes`, `scripts-external`, `scripts-inline`, `fingerprint`, `already-marked`, `infra`,
  `non-js-types`, `alpine-attributes`, `malformed`, `vue-mode`, `widget-body`, `benchmark` (300 KB,
  generated, committed). All hosts `example.com`/`example.org` or real public provider hosts with
  made-up IDs (BR-30).
- **Staging site A**: plain Craft 5 + Alpine, Cookiebot via the Tag Manager template, Servd static
  cache. Exists (a webdna client staging site; named only in that site's repo).
- **Staging site B**: Craft 5 + Vue 2 over the page, Cookiebot via Tag Manager. Exists likewise.
- **Staging site C** for AC-10: any Craft 5 site with no Cookiebot in Tag Manager and a test
  Cookiebot domain group ID. **Does not exist yet** (Appendix A).
- A clean private browser window per consent state.

### Scenarios

**TS-1 - Pasted video is held back** - *covers AC-1; BR-15, BR-17* - Type: unit + manual (site A)

| # | Step | Expected |
|---|---|---|
| 1 | Unit: rewrite the `iframes` fixture | Output equals expected: YouTube iframe has `data-cookieblock-src`, `data-cookieconsent="marketing"`, no `src`; placeholder follows it |
| 2 | Site A: paste a YouTube embed code into an entry, save, open in a private window | Network panel shows no youtube.com request; placeholder visible |
| 3 | Click the placeholder's link, accept marketing | Banner reopens; after accepting, placeholder hides and the video loads |

*Success criterion: steps 1-3 pass.*

**TS-2 - Layout trackers wait for consent** - *covers AC-2; BR-10, BR-11, BR-14, BR-16* - Type: unit + manual (site A)

| # | Step | Expected |
|---|---|---|
| 1 | Unit: `scripts-external` and `scripts-inline` fixtures | A listed statistics provider gets `text/plain` + `statistics`; an unlisted host gets `text/plain` + `marketing`; an inline loader naming a marketing host is gated as marketing |
| 2 | Unit: `fingerprint` and `widget-body` fixtures | An obfuscated inline loader matching a shipped fingerprint is gated with its category; a gated script in the body has the placeholder inserted before it |
| 3 | Site A: add a statistics provider script and an unlisted provider script to the layout; private window | Neither host appears in the network panel |
| 4 | Accept statistics only | Statistics provider loads; unlisted provider does not |
| 5 | Accept marketing | Unlisted provider loads |
| 6 | Run `check-cookiebot.py live` on the page | No FAIL for either script |

*Success criterion: all six steps pass.*

**TS-3 - Necessary and allowed things are untouched** - *covers AC-3* - Type: unit + manual (site A)

| # | Step | Expected |
|---|---|---|
| 1 | Unit: fixture with first-party relative and absolute scripts, a filesystem-URL script, reCAPTCHA, and a host in `allow` | All unchanged byte-for-byte |
| 2 | Site A: private window on a page with a form using reCAPTCHA | reCAPTCHA loads before consent; form submits successfully |

*Success criterion: both steps pass.*

**TS-4 - Infra and already-marked are left** - *covers AC-4* - Type: unit

| # | Step | Expected |
|---|---|---|
| 1 | Rewrite `infra` fixture (Cookiebot `uc.js`, CookieDeclaration, GTM snippet and noscript iframe) | Unchanged |
| 2 | Rewrite `already-marked` fixture (`data-cookieconsent="ignore"`, `"marketing"`, and a `text/plain` script) | Unchanged; sightings recorded as `already` |

*Success criterion: both outputs equal their inputs.*

**TS-5 - Site placeholder** - *covers AC-5; BR-17, BR-18* - Type: unit + manual (site A)

| # | Step | Expected |
|---|---|---|
| 0 | Unit: default placeholder render | Has class `cookieconsent-optout-marketing`, `data-cbe-placeholder`, no `style` attribute, a `javascript:Cookiebot.renew()` link |
| 1 | Set `placeholderTemplate` to a site template that prints `category` and `vendor`; private window on the TS-1 page | Site template's markup shows with `marketing` and `YouTube` |
| 2 | Accept marketing | Site placeholder hides; video loads |

*Success criterion: both steps pass.*

**TS-6 - Vue 2 site** - *covers AC-6; BR-19 (or BR-20)* - Type: unit + manual (site B)

| # | Step | Expected |
|---|---|---|
| 1 | Unit: `vue-mode` fixture | Gated elements carry `data-cbe-consent`/`data-cbe-src`, not Cookiebot's attributes; activator script registered in head |
| 2 | Site B, Vue mode, site calls `CookiebotEmbeds.ready()` in `mounted()`; private window on a page with a pasted video | No provider request; placeholder visible after Vue mounts |
| 3 | Accept marketing | Video loads; placeholder hides |
| 4 | Interact with a Vue component that re-renders part of the page | Video still present and playable |
| 5 | Reload with consent already given | Video loads after mount without any banner interaction |

*Success criterion: steps 1-5 pass.*

**TS-7 - Report** - *covers AC-7* - Type: manual (site A)

| # | Step | Expected |
|---|---|---|
| 1 | Fresh install, open the utility | Empty-state message |
| 2 | Browse the TS-1 and TS-2 pages, reopen the utility | Rows for YouTube (gated, marketing), the statistics provider (gated, statistics), the unlisted host (gated, marketing, provider blank) with example paths |
| 3 | Clear | Empty-state message again |

*Success criterion: all three steps pass.*

**TS-8 - Static cache** - *covers AC-8* - Type: manual (site A, static cache on)

| # | Step | Expected |
|---|---|---|
| 1 | Purge the cache; open the TS-1 page in window X and accept marketing | Video plays |
| 2 | Open the same page (now a cache hit) in private window Y and decline | Placeholder shows; no provider request |
| 3 | Compare page source of both | Identical |

*Success criterion: all three steps pass.*

**TS-9 - Off switch** - *covers AC-9; BR-2* - Type: unit + manual

| # | Step | Expected |
|---|---|---|
| 1 | Unit: every fixture with `enabled` false | Output identical to input |
| 2 | Site A: set `enabled` false, compare a page's source with the plugin uninstalled | Identical |

*Success criterion: both steps pass.*

**TS-10 - Banner option** - *covers AC-10; BR-21, BR-22* - Type: unit + manual (site C)

| # | Step | Expected |
|---|---|---|
| 1 | Unit: option on with ID | `uc.js` is the first child of head, with `data-cbid`, no async/defer/blockingmode |
| 2 | Unit: option on, page already has `uc.js` | Not inserted a second time |
| 3 | Unit: option on, no ID | Nothing inserted; warning logged |
| 4 | Site C: option on; footer uses `cookiebotSettingsLink()` | Banner shows once; footer link reopens it |
| 5 | Option off | Page source has no `uc.js` added by the plugin |

*Success criterion: steps 1-5 pass.*

### Negative and edge cases

| # | Condition | Expected behaviour |
|---|---|---|
| TN-1 | Control-panel, console, action and JSON/XML responses | Untouched (BR-1) |
| TN-2 | Attributes with `>` inside quotes, single quotes, unquoted values, upper-case tags, attributes split over lines | Parsed correctly; only the intended attributes change (BR-4) |
| TN-3 | Inline script containing the string `</script` inside a JS string, and an unclosed `<iframe` at end of document | No corruption of surrounding HTML; the unclosed tag is left |
| TN-4 | Alpine attributes (`@click`, `x-on:click`, `:src`, `x-bind:src`) on and around rewritten tags | Preserved exactly (BR-4) |
| TN-5 | `application/ld+json`, `importmap`, `text/x-template` scripts naming third-party URLs | Untouched (BR-12) |
| TN-6 | `type="module"` third-party script | Untouched, recorded `skipped` (BR-13) |
| TN-7 | iframe with no `src`, empty `src`, `about:blank`, `srcdoc` | Untouched (BR-15) |
| TN-8 | Protocol-relative, upper-case-host, and port-bearing URLs | Classified as their host (BR-7) |
| TN-9 | A subdomain of the site's own host, and a host that merely contains the site's domain as a substring (`example.com.evil.net`) | First is first-party; second is unknown and gated (BR-6) |
| TN-10 | Invalid config: unknown category, unknown mode, a non-compiling fingerprint, a string where an array is expected | Logged once, default used, page still served (BR-14, BR-27) |
| TN-11 | Report table missing or database unavailable | Page served rewritten; error logged (BR-24) |
| TN-12 | The same unknown host 500 times on one page | One sighting row, one log line per hour (BR-23, BR-29) |
| TN-13 | `vendors.json` missing or unreadable | Logged; every third party treated as unknown (fail closed), infra rule BR-9 still applies because it is coded, not listed |
| TN-14 | Live Preview request | Rewritten like a site page (BR-1) |
| TN-15 | Utility or clear action requested by a logged-in user without utility access, and clear without CSRF | 403 / 400 (BR-25) |
| TN-16 | Vue mode on a site that never calls `ready()` | Nothing gated is ever released; documented in README as the failure mode |
| TN-17 | Page with no `<head>` or `<body>` tags (fragment) | Rewrites tags; banner option and head registration skip silently |
| TN-18 | Report rows last seen 31 days ago when garbage collection runs | Deleted; rows seen yesterday kept (BR-26) |

### Automated checks

- **PHPUnit unit suite** (`tests/unit/`): the rewriter as a pure service, driven by the fixtures;
  classifier tables for BR-6 to BR-9 and TN-8/TN-9; config normalisation (TN-10); `vendors.json`
  shape and categories (BR-28); the benchmark and fast exit (BR-5, non-functional) as a test that
  fails above the budget.
- **Static analysis and style**: ecs and PHPStan level 6 (profile V2, V3).
- **Repo hygiene**: client-name grep and dash check (profile V5, V6) in CI; a grep that the README
  carries both disclaimers (BR-31).
- **Manual**: TS-1 to TS-3, TS-5 to TS-8, TS-10 steps on real browsers, because Cookiebot's banner and
  consent events only run in a browser against a registered domain.
- **Test hooks the build must add:** `data-cbe-placeholder` on every placeholder root;
  `data-cbe-gated="<category>"` on every element the plugin rewrote (lets a site's own e2e suite and
  the cookiebot-check skill tell plugin-gated from hand-gated); `id="cookiebot-embeds-activator"` on
  the Vue-mode script.

### Regression checks

- **Forms with spam protection submit** - reCAPTCHA and Formie's own scripts are third-party and
  first-party respectively; a wrong classification breaks every form.
- **Tag Manager still loads and Consent Mode defaults still fire first** - the plugin touches the
  head, where GTM lives; GTM Preview's Consent tab must still show defaults on the first event.
- **Alpine components on rewritten pages still work** - BR-4 is the only thing protecting them.
- **Servd static cache purge and hit** - output must stay deterministic (BR-3).
- **Live Preview still renders** - it is a site request and is rewritten.
- **Modals or components that set an iframe's `src` from JavaScript** - the plugin cannot see them;
  confirm they are unchanged, not half-rewritten.

---

## 8. Build order

| Phase | What it delivers | Depends on |
|---|---|---|
| 0 | Spike: how Cookiebot-activated iframes and `text/plain` scripts behave under Vue 2's rebuild, and how Cookiebot treats a `text/plain` `type="module"` script | - |
| 1 | Package scaffold, settings and config, provider list, CI | - |
| 2 | The rewriter as a pure service, fixture-tested | 1 |
| 3 | The render hook, skip rules, off switch, logging | 2 |
| 4 | Report: table, recorder, utility, pruning | 3 |
| 5 | Vue mode, banner option, settings link | 0, 3 |
| 6 | Staging verification on sites A, B and C; README; 1.0.0 | 4, 5 |

Phase 0 runs first although it ships nothing, because it decides BR-19/BR-20 and BR-13; building
Vue mode on a guess is the most expensive way to be wrong.

### Spike 0.1 notes (2026-11-22)

**Method.** Read Cookiebot's live `uc.js` (2.146.0, built 2026-11-19), then ran a throwaway local
page under headless Chrome: Vue 2.7.16 mounted over a root holding a gated inline widget script, a
plain script, a gated iframe and a gated iframe under a `v-if`, plus a stub that copies `uc.js`'s
release logic exactly. Two timings: consent given after Vue mounts, and a returning visitor whose
consent is released before Vue mounts.

**What `uc.js` does.** `Cookiebot.runScripts()` takes every
`script[type=text/plain][data-cookieconsent]` not already processed (the flag is a property on the
DOM node), replaces each with a clone that copies all attributes and the inline body and then
forces `type="text/javascript"`, and then calls `RunSrcTags` for `iframe`, `img`, `embed`, `video`,
`audio`, `picture` and `source`. That moves `data-cookieblock-src` to `src` when the category is
consented and hides the element otherwise. It rescans the live DOM on every call, and Cookiebot
calls it on every accept. An original `type` is restored only for scripts Cookiebot blocked itself
in automatic mode (`origScriptType`), never for a server-rendered `text/plain` script.

**Results.**

| Case | Consent after mount | Consent before mount (returning visitor) |
|---|---|---|
| Plain `<script>` inside the Vue root | Dropped by Vue | Dropped by Vue |
| Gated `text/plain` script inside the root | Kept by Vue; runs once; its DOM output and listeners work | Runs once, but Vue's rebuild then **kills its listeners** |
| Gated iframe | `src` set; survives a reactive re-render | Released before compile, so Vue builds it with `src`; works |
| Gated iframe under a `v-if` that remounts | Comes back gated (`data-cookieblock-src`, no `src`) until the next `runScripts()` | Comes back with `src` |
| `text/plain` script with module syntax, released | `SyntaxError: Cannot use import statement outside a module` | Same |

**Decisions.** BR-19 stands and BR-20 is superseded: Cookiebot's own attributes are safe for
iframes but not for scripts inside a Vue root, because Cookiebot can release scripts before Vue
mounts. BR-13 is kept; gating modules moves to Later through the plugin's own activator.

**Not yet seen on staging site B.** The real banner, real consent events and real `uc.js` timing
against a registered domain were not exercised, because no staging URL was available. TS-6
steps 2-5 exercise exactly that during phase 6. Task 5.1 must still check them on site B before
it is ticked.

### Tasks

- [x] **0.1 Vue and module spike** - throwaway page on staging site B and a local HTML file; result
      written into this spec (BR-19 or BR-20 superseded, BR-13 kept or moved to Later)
      Rules: BR-13, BR-19, BR-20 - Verify: spike notes in the change log
      *Done 2026-11-22 with the local page and a read of `uc.js`; site B confirmation carried by
      TS-6 (see spike notes).*
- [x] **1.1 Package scaffold** - `composer.json`, `src/CookiebotEmbeds.php`, `src/icon.svg`,
      `src/icon-mask.svg`, `README.md`, `LICENSE.md`, `CHANGELOG.md`, `ecs.php`, `phpstan.neon`,
      `phpunit.xml.dist`, `tests/bootstrap.php`, `.gitattributes`
      Rules: BR-31 - Verify: B5 #1-#3
      *Done 2026-11-23. Also `.gitignore` and `.ddev/config.yaml`: B5 runs through DDEV (profile
      section 2). The README carries both BR-31 disclaimers now; 6.1 adds the rest.*
- [x] **1.2 Settings and config** - `src/models/Settings.php`, `src/config.php`,
      `src/services/Config.php` (normalisation, multi-site keys)
      Rules: BR-2, BR-27 - Verify: B5 #4, TN-10
      *Done 2026-11-23, with `src/models/SiteConfig.php` (the typed result, and the only place the
      defaults are written). Decisions: `Settings` properties are `mixed` and `null` means unset,
      because Craft assigns the file's values onto the model and a typed property would throw. A
      per-site array has only site handles and `*` (fallback) as keys. A bad entry in `vendors`,
      `fingerprints` or `allow` drops that entry only, not the whole key. Fingerprints are written
      without delimiters (as in the cookiebot-check skill) and match case-sensitively;
      `Config::compilePattern()` is shared with 1.3. A missing `vendor` name falls back to the
      match. Warnings are logged once per request per site by `CookiebotEmbeds::getSiteConfig()`;
      the hourly throttle is 3.2's.*
- [x] **1.3 Provider list** - `src/data/vendors.json` seeded from the cookiebot-check skill's
      `VENDORS`, plus shipped fingerprints; `src/services/Vendors.php`
      Rules: BR-9, BR-14, BR-28 - Verify: B5 #4
      *Done 2026-11-23. The file is `{"vendors": [...], "fingerprints": [...]}`, 58 providers and 17
      fingerprints. The skill's static-maps entry (category `unknown`, not a BR-28 category) was
      dropped: images are not gated. Fingerprints were authored (the skill has none) and there is
      deliberately no `gtag(` one, because the Consent Mode defaults snippet calls
      `gtag('consent', 'default', ...)` and must load first; a test proves the GTM and Consent Mode
      snippets match nothing. BR-9 is coded as `Vendors::INFRA` (plus `INFRA_IDS` for the tag-level
      ids, applied in 2.1/2.2) and a test keeps it identical to the file's infra entries. The file
      is validated by `Config::normaliseEntries()`, so it follows the same rules as a site's
      `vendors` and `fingerprints`. `Vendors::firstMatch()` and `matchingFingerprints()` are static
      so 2.1 reuses them for site overrides. `CookiebotEmbeds::getVendors()` memoises the list and
      logs its warnings at `error` (TN-13).*
- [x] **1.4 CI and hygiene** - `.github/workflows/ci.yml` (PHP 8.2, 8.4: validate, check, hygiene),
      `.gitignore` entry for `.github/forbidden-names.txt`
      Rules: BR-30 - Verify: B5 #5, #6
      *Done 2026-11-23. Two jobs: `check` (PHP 8.2 and 8.4 on the runner's own PHP via
      setup-php: install, validate, `composer check`) and `hygiene` once (client names, dashes,
      the BR-31 README disclaimers). The name list cannot be in a public repo, so CI writes
      `.github/forbidden-names.txt` from the `FORBIDDEN_NAMES` repository secret (one pattern per
      line; blank lines dropped, since one would match everything). A hit prints file names only
      (`grep -l`), never the line, so a failure cannot publish a name in the log. Without the
      secret the step fails, except on pull requests from forks, which get no secrets and skip
      with a notice. **Owed by Sam before the first push: add the `FORBIDDEN_NAMES` secret and the
      local `.github/forbidden-names.txt`** (B5 #5 exits 2 without it). Each hygiene step was run
      locally under `bash -e` in every branch (secret missing on push and on a fork, clean list,
      blank lines, a hit, a removed disclaimer); the workflow itself is unproved until GitHub runs it.*
- [x] **2.1 Classifier** - `src/services/Classifier.php`
      Rules: BR-6, BR-7, BR-8, BR-9 - Verify: B5 #4, TN-8, TN-9
      *Done 2026-11-23, with `src/models/Classification.php` (host, vendor, category, and `source`:
      which BR-6 step decided). `new Classifier(SiteConfig, Vendors, siteHosts, filesystemHosts)`,
      then `classify(url)`, memoised per URL. Decisions: Cookiebot and Tag Manager are checked after
      `allow` but before the site's `vendors`, so no override gates them (B3). A site host loses a
      leading `www.`, so the apex and every subdomain of it are first-party; filesystem hosts match
      exactly, because a shared storage host must not make every bucket first-party. `allow`
      entries match `host + path` as a substring, like provider matches, so one path can be allowed;
      an allowed listed provider keeps its name for the report. URLs are entity-decoded and `\` is
      read as `/` (as browsers do), so `https:\\evil.net` and `https:&#x2F;&#x2F;evil.net` are
      evil.net; user info is not the host. Non-http schemes and an http URL with no host load
      nothing from elsewhere and count as first-party. BR-8 and the BR-9 `id` rule are tag-level,
      so they are static helpers (`isAlreadyMarked(tag, attributes)`, `isInfraById(attributes)`,
      attribute names lower-case, null for a bare attribute) that 2.2's parser calls. BR-11's
      inline-body scan is 2.2's.*
- [x] **2.2 Tag scanner and rewriter** - `src/services/Rewriter.php`, fixtures `iframes`,
      `scripts-external`, `scripts-inline`, `fingerprint`, `already-marked`, `infra`,
      `non-js-types`, `alpine-attributes`, `malformed`, `widget-body`
      Rules: BR-4, BR-5, BR-10 to BR-16 - Verify: B5 #4, TS-1 #1, TS-2 #1-2, TS-4, TN-2 to TN-8
      *Done 2026-11-23, with `src/models/RewriteResult.php` (the page plus de-duplicated sightings
      `{host, vendor, category, action, kind}` for 4.2). `new Rewriter(Classifier, Vendors,
      SiteConfig, ?Closure placeholder)`, then `rewrite(html)`; the placeholder callback takes
      `(category, vendor, kind, width, height)` and 2.3 supplies the real one. Each fixture has a
      `<name>.expected.html`, regenerated with `CBE_UPDATE_FIXTURES=1` and reviewed by diff; the
      suite also checks every fixture is idempotent and that only rewritten tags change (BR-4).
      Decisions: the scanner skips comments, `<textarea>` contents and script and iframe contents,
      and ends a script at the first `</script` as a browser does; an unclosed start tag or script
      ends the scan with the rest untouched. A page with `<head>` or `<html>` is in the body only
      after `<body`; a fragment is all body (TN-17). BR-12's JavaScript types are the HTML
      standard's full list (legacy ones such as `application/x-javascript` run, so they are
      gated); a type with parameters (`text/javascript; charset=utf-8`) does not run and is left.
      New attributes go at the end of the tag, after the last attribute, so `/>` and spacing stay;
      a missing `type` is added first. Every `src` on a gated iframe is renamed, because a browser
      falls back to a second one. An inline body that names Cookiebot or Tag Manager is never
      gated. In inline bodies a protocol-relative `//host` counts only straight after a quote, so a
      `//comment.like.this` is not a URL; `\/` counts as `/`. Sightings: first-party is not
      recorded; `allow` and filesystem hosts are `allowed`; other ungated third parties `left`;
      modules are `skipped` only when they would have been gated; an inline script known only by a
      fingerprint has an empty host. Fixture hosts besides `example.com/.org/.net` are made up
      (`example-unlisted.org`, `example-cdn.net`, `evil.net`) and name no client; BR-30's wording
      asks for example domains only, so Sam may want them moved under `.example` or `.test`.*
- [x] **2.3 Placeholder** - `src/templates/_placeholder.twig`, renderer callback injected into the
      rewriter, `src/translations/en/cookiebot-embeds.php`
      Rules: BR-17, BR-18 - Verify: B5 #4, TS-5
      *Done 2026-11-23, with `src/services/Placeholder.php` (pure; `new Placeholder(siteTemplate,
      renderSite, translate, onError, cache)`, then `renderer()` for the rewriter) and
      `CookiebotEmbeds::createPlaceholder(SiteConfig)`, which 3.1 calls. TS-5 step 0 is
      `PlaceholderTest`. Decisions: the plugin's template is rendered by a small Twig environment of
      the service's own, with a `t` filter of Craft's call shape, so the unit test runs the same
      render as a live site; live, `t` is `Craft::t` and Twig compiles to Craft's compiled-templates
      path. A `placeholderTemplate` goes through Craft's view in site mode; if it throws, the error
      is logged and the default is used, and if the default fails no placeholder is inserted (the
      embed stays gated). Renders are memoised per request by their variables. The root is a
      `<span>`, not a `<div>`, because a pasted iframe often sits in a `<p>` that a div would split;
      uc.js 2.146.0 hides `cookieconsent-optout-*` with a `data-cookieconsent-display-none`
      attribute and shows it by removing that, never by setting `display`, so the element keeps its
      natural display and a site styles `.cbe-placeholder` as it likes. Root attributes:
      `class="cbe-placeholder cookieconsent-optout-<category>"`, `data-cbe-placeholder="<category>"`,
      `data-cbe-kind`, and `data-cbe-width`/`data-cbe-height` only when given (section 6). Copy, all in
      the `cookiebot-embeds` category: "{vendor} content is blocked until you accept {category}
      cookies." (or "This content ..." with no vendor), the category name itself, and the link text
      "Change cookie settings".*
- [x] **2.4 Benchmark** - `tests/fixtures/benchmark.html`, budget test
      Rules: BR-5 - Verify: B5 #4
      *Done 2026-11-23, as `tests/unit/BenchmarkTest.php`. The fixture (309 KB, 40 scripts, 6
      iframes) is generated by the test's `page()` and committed; the test fails if the two differ,
      and `CBE_UPDATE_FIXTURES=1` rewrites it. It is also in RewriterTest's fixture list, so it has an
      `.expected.html` and gets the idempotency and BR-4 byte checks. Its scripts cover every branch:
      Cookiebot and Tag Manager (with the noscript iframe), external and inline statistics and
      marketing, three fingerprints, JSON-LD, an importmap, a module, first-party, necessary,
      x-templates, already-marked, and body widgets; hosts are `example.*` domains or real
      providers with zeroed IDs. The rewrite budget times the whole per-request cost (provider
      list, config, services, rewrite, real Twig placeholder) after five warm-up runs, over 60 runs.
      Measured under ddev: median 0.40 ms, p95 0.44 ms (budget 5 and 15); the fast exit, on the same
      page with every script and iframe removed, 0.04 ms (budget 0.5). `CBE_BENCH=1` prints the
      timings. For 3.1: the fast-exit figure is `rewrite()` alone, so the hook should make that
      check before it loads the provider list or builds the services.*
- [x] **3.1 Render hook** - `src/CookiebotEmbeds.php` (event on
      `View::EVENT_AFTER_RENDER_PAGE_TEMPLATE`), request and response-format guards, site hosts and
      filesystem URL hosts gathered once per request
      Rules: BR-1, BR-2, BR-3 - Verify: TS-9, TN-1, TN-14, TN-17
      *Done 2026-11-23, with `src/services/PageFilter.php` (pure: `isSitePage()`, `isHtml()`,
      `hosts()`, `filter()`) and `Rewriter::mightRewrite()` (BR-5, public so the hook can call it
      first). `PageFilterTest` covers TS-9 step 1 (every fixture with `enabled` false), TN-1, TN-14
      and TN-17. Decisions: the hook is registered only for web requests. A page is a site page when
      the request is not CP or console, the template mode is `site`, and it is not an action
      request unless it is a preview (TN-14). HTML is decided as Craft's TemplateResponseFormatter
      does after the render: a Content-Type set by `{% header %}` wins, otherwise the template
      file's extension once `.twig` is removed, and no extension is HTML. Order: request guard,
      `enabled`, the fast exit, then the content type, so a disabled site or a page with nothing to
      gate costs no provider list and no template lookup. Site hosts are every site's base URL host
      and filesystem hosts every filesystem's root URL host, gathered on first use per request; the
      Host header is never read (BR-3), though a base URL built from `@web` carries the host Craft
      resolved. Any throw is logged and the original output served (B3). The unit bootstrap now loads
      the `Craft` class and sets `@app`, without an app, because Craft's `FileHelper` reads its MIME
      map from there. A broken `placeholderTemplate` is now tried once per request, so it logs one
      line per page (2.3's Placeholder retried it for each distinct placeholder).
      Smoke-tested in a throwaway local Craft 5 site (ddev, not committed): a page is rewritten, with
      a `registerJsFile()` script gated (so the hook sees output after `endPage()`), an absolute
      first-party script left and Alpine attributes byte for byte; `.json.twig`, `.xml.twig` and a
      `{% header %}` JSON page are identical to the uninstalled site (TN-1); a fragment is rewritten
      (TN-17); with `enabled` false the page is byte-identical to the uninstalled site (TS-9 step 2,
      locally; site A still owed in 6.1); `placeholderTemplate` renders the site's template with the
      right variables, and a missing one falls back and logs. Live Preview (TN-14) was not exercised
      live: Craft routes a preview token to a route, not through the action trigger, so it is a
      plain site page; the `isPreview` allowance covers the action case. To be seen on site A in 6.1.*
- [x] **3.2 Logging** - log target category `cookiebot-embeds`, hourly throttle
      Rules: BR-29 - Verify: TN-12
      *Done 2026-11-23, with `src/services/Log.php` (pure: `once(level, message, key)` and
      `unknownHosts(RewriteResult, siteHandle)`; the hourly claim and the writer are injected) and
      `CookiebotEmbeds::getLog()`, which claims through Craft's cache (`add()`, one hour) and writes
      in the `cookiebot-embeds` category. `LogTest` covers TN-12 with a fake cache and clock.
      Decisions: the plugin registers its own Monolog target (`cookiebot-embeds`, level info, no
      context), so unknown hosts reach `storage/logs/cookiebot-embeds-<date>.log` even where Craft's
      default target keeps only warnings. An unknown sighting is one with a host, no vendor and an
      action other than `allowed` (first-party is never recorded, providers and infra carry a
      vendor, allow and filesystem hosts are `allowed`, fingerprint-only inline scripts have no
      host); a host the developer marked themselves is still unknown. One line per host per site per
      hour, naming how it was seen and the config keys that would name or allow it. Every other line
      the plugin writes (config and `vendors.json` warnings, placeholder and rewrite failures) goes
      through the same throttle, keyed by level and text, so BR-27's "logged once" holds across
      requests rather than once per request. Repeats within a request do not ask the cache; a cache
      that fails means the line is written. Logging runs after the rewrite in its own `try`, so a
      logging failure never costs the page its rewrite. Smoke-tested in the local Craft 5 site:
      three requests to a page with an unknown host wrote one `[cookiebot-embeds.INFO]` line to the
      plugin's own file, and clearing the data cache let the next request write it again.*
- [x] **4.1 Table** - `src/migrations/Install.php`, `src/records/Sighting.php`
      Rules: BR-23 - Verify: B5 #4 (record mapping), TS-7
      *Done 2026-11-23. `Sighting` owns the table name (`Sighting::TABLE`), the column lengths,
      `UNIQUE_KEY`, and the allowed `ACTIONS`, `KINDS` and `CATEGORIES` (taken from
      `RewriteResult` and `Config::ENTRY_CATEGORIES`, so they cannot drift); the migration builds
      from those constants. Static `Sighting::row(siteId, sighting, path)` is the pure mapping 4.2
      upserts: host lower-cased, an empty vendor stored as null, the path given a leading slash
      with any query string or fragment dropped, and every string cut by characters to fit its
      column. `hits` and the dates are left to the recorder. Decisions: `host` is NOT NULL with
      default `''`, because an inline script known only by a fingerprint has no host but still
      needs a row under the unique key; `hits` is unsigned, default 0; an extra index on
      `dateUpdated` serves the 30-day prune (BR-26); index and key names are Craft-generated, and
      go with the prefixed table on uninstall. `SightingTest` covers the mapping. Smoke-tested in
      the local Craft 5 site (MySQL): install created the table with the unique key and the
      cascading foreign key to `sites`, a row written through `row()` and the record read back
      normalised, a duplicate was refused by the unique key, and uninstall dropped the table.
      TS-7 itself needs the utility, so it is proved in 4.3.*
- [x] **4.2 Recorder** - `src/services/Report.php` (batched upsert, cache throttle, failures
      swallowed, GC hook)
      Rules: BR-23, BR-24, BR-26 - Verify: TN-11, TN-12
      *Done 2026-11-23, with `src/services/Report.php` (pure: the claim, the upsert and the error
      reporter are injected) and, in `CookiebotEmbeds`, `getReport()`, the live upsert and the
      prune. `ReportTest` covers TN-11, TN-12 and TN-18 with a fake cache and clock. Decisions:
      every sighting is recorded, whatever its action; each row is claimed for an hour with the
      cache's `add()` under a key of site, host, action and kind, and only the claimed rows of a
      page go in its one upsert, so `hits` counts the hours a row was seen, not page views. A cache
      that throws writes nothing (logged), because without the throttle every view would write. A
      failed upsert keeps its claims, so a missing table is tried and logged once an hour. Rows
      that become one once cut to fit are merged before the upsert (Postgres refuses a row twice in
      one statement). Yii's `upsert()` takes one row, so the live writer is its `batchInsert()` plus
      `Report::onConflictSql()` (`ON DUPLICATE KEY UPDATE` with `VALUES()`, which MariaDB also
      accepts, or `ON CONFLICT ... DO UPDATE`), set with `setRawSql()` because the batch insert
      inlines its values and `quoteSql()` would rewrite a `{{ }}` or `[[ ]]` in a stored path. The
      claim keys carry a generation read from the cache (`Report::GENERATION_KEY`): a cache
      dependency cannot free them, because `add()` ignores dependencies, so 4.3's clear changes the
      generation instead. The example path is `getFullPath()`, the only part of the request the
      plugin reads besides its type. Pruning hangs off `Gc::EVENT_RUN`, registered before the
      console return so `craft gc` prunes too. Smoke-tested on MySQL in the local Craft 5 site:
      the first view wrote 3 rows, two more views wrote nothing, a data-cache clear let the next view
      add a hit (uid and `dateCreated` kept); with the table dropped, pages were served rewritten
      and one error line was logged for two views; `craft gc` deleted the row last seen 31 days ago
      and kept the two seen yesterday. The Postgres clause is unit-tested only.*
- [x] **4.3 Utility and clear** - `src/utilities/Report.php`, `src/templates/_utility.twig`,
      `src/controllers/ReportController.php`
      Rules: BR-25 - Verify: TS-7, TN-15
      *Done 2026-11-23. The utility (id `cookiebot-embeds`, so `admin/utilities/cookiebot-embeds`)
      is registered in `init()` and shown by Craft's own utilities page, which enforces the
      `utility:cookiebot-embeds` permission. Static `Report::present()` (pure, `ReportUtilityTest`)
      turns rows into the table: site name, example link (site base URL plus path), hits, last seen
      (stored UTC, shown in the system time zone), and for an unknown host the two config lines that
      allow or name it (`configLines()`). "Unknown" is `Log::isUnknown()`, now shared by the log and
      the report so they cannot disagree. The site filter is a GET form, shown only on multi-site
      installs. `ReportController::actionClear` sits behind Craft's `UtilityAccess` filter (403) and
      Craft's CSRF validation (400), deletes every row and writes a new
      `Report::GENERATION_KEY`, so the next page views record at once instead of an hour later.
      Labels, actions, kinds and the empty state are in the `cookiebot-embeds` translation file.
      Smoke-tested over HTTP in the local Craft 5 site (switched to Pro, because Solo allows one
      user): TS-7 steps 1 to 3 pass (empty state, four rows with the unknown host's config line and
      example links, clear back to empty), browsing after a clear records again at once; TN-15: a
      clear without CSRF is 400, a non-admin with CP access gets 403 for the utility and for the
      clear, and the rows survive. Not seen in a browser yet; TS-7 on site A is in 6.1.*
- [ ] **5.1 Vue mode** - `src/web/assets/activator/activator.js`, asset bundle, rewriter mode switch,
      `vue-mode` fixture
      Rules: BR-19 (or BR-20) - Verify: TS-6, TN-16
- [ ] **5.2 Banner option and settings link** - head insertion in the hook, Twig extension
      `src/twig/Extension.php`
      Rules: BR-21, BR-22 - Verify: TS-10
- [ ] **6.1 Staging verification and docs** - README sections (install, config, Vue mode, allow
      list, report, not affiliated, not a compliance guarantee), `CHANGELOG.md` 1.0.0
      Rules: BR-31 - Verify: TS-1 to TS-10 manual steps, B4

---

# Appendix A - Open questions

| # | Question | Owner | State |
|---|---|---|---|
| 1 | Is the handle `cookiebot-embeds` unused on the Plugin Store? Craft's update check matches by handle even for plugins not listed there | Developer | **Open - blocking release** |
| 2 | Vue mode's final design (BR-19 vs BR-20) and module-script handling (BR-13) | Developer, via task 0.1 | Resolved 2026-11-22: BR-19 stands, BR-20 superseded, BR-13 kept (section 8, spike notes) |
| 3 | Staging site C with no Cookiebot in Tag Manager, and a test Cookiebot domain group ID for it | Sam | **Open - blocking release** (TS-10 manual steps) |

**Assumptions**

- Sam is the approver and the only sign-off.
- The developer writes all default copy; no client copy is owed.
- The footer "Cookie settings" link is a Twig function the site places, not something the plugin
  inserts, because there is no reliable footer location to insert into (BR-22).
- Craft `^5.0` is a sufficient minimum; nothing used needs a later 5.x.
- Staging sites A and B exist with Cookiebot configured for their domains.
- 30 days is long enough to keep report rows (BR-26).
- The cookiebot-check skill will be changed to read `vendors.json`; that change lives in the skill's
  own repo and is not part of this build.

---

# Appendix B - build scaffolding

## B1. Before writing anything

Read `_PROFILE.craft-cookiebot-embeds.md`. Everything in it applies, including that this repo is
public (BR-30) and that commits are never pushed by the builder.

## B2. Context to load

1. This spec, sections 5 and 8 - the rules and the task order.
2. `vendor/craftcms/cms/src/web/View.php:641-670` (after `composer install`) - `renderPageTemplate`
   calls `endPage()` before firing `EVENT_AFTER_RENDER_PAGE_TEMPLATE`, so registered head and body
   scripts (SEOmatic, `{% js %}`) are already in `$event->output`.
3. `../plugins/email-content-editor/src/EmailContentEditor.php` around line 155 - the one webdna
   plugin already rewriting `$event->output` in that event; note it guards on template mode.
4. `../plugins/query-boost/composer.json`, `ecs.php`, `phpstan.neon` - the house package shape and
   script names to copy.
5. `../plugins/scheduler/src/config.php`, `src/models/Settings.php`, `tests/bootstrap.php`,
   `phpunit.xml.dist` - config-file pattern and app-free PHPUnit setup.
6. `../plugins/auditor/.github/workflows/ci.yml` - CI shape including the hygiene grep.
7. The craftcms plugin's `skills/cookiebot-check/scripts/check-cookiebot.py`, the `VENDORS` list
   (lines 26-86) and `vendor_of()` (lines 104-120) - the seed data and matching semantics for
   BR-7 and BR-28.
8. The same skill's `references/markup.md` - the exact Cookiebot manual-mode markup the rewriter must
   produce, and the placeholder pattern.
9. Cookiebot developer documentation on manual implementation (`data-cookieconsent`,
   `data-cookieblock-src`, `cookieconsent-optout-*`, `CookiebotOnConsentReady`, `Cookiebot.consent`)
   - confirm current behaviour before task 0.1.

## B3. Guardrails

- **Do not parse and re-serialise the page with `DOMDocument` or any HTML parser** - it rewrites
  HTML5 void elements and drops or renames Alpine attributes, breaking every interactive component on
  every page (BR-4).
- **Do not read cookies, headers, the user or consent state in the rewrite path** - static caching
  would serve one visitor's consent state to everyone (BR-3).
- **Do not let anything in the render hook throw** - an exception there blanks every page on the
  site; catch, log, and return the original output.
- **Do not gate Cookiebot or Tag Manager, even if a config override says to** - the banner and
  Consent Mode defaults would never load, so nothing would ever be consented (BR-9).
- **Do not make outbound HTTP requests** from the plugin - it runs on every uncached render (BR-29).
- **Do not write a client name, domain or real ID anywhere in the repo**, including commit messages
  and fixtures - the repo is public (BR-30).
- **Do not add a CP settings page** - config is the single source of truth and is reviewed with
  each site (BR-27).
- **Do not write to the database on every request** - the recorder is throttled (BR-23); an
  unthrottled upsert on uncached pages is a write per page view.
- **Do not throw from config loading** - the config file loads on every request (profile, Traps).

## B4. Definition of done

- [ ] Every AC in section 7 passes
- [ ] Every BR in section 5 is enforced, not merely intended
- [ ] TN-1 to TN-18 behave as specified
- [ ] The regression checks in section 7 pass on staging sites A and B
- [ ] The test hooks in section 7 exist (`data-cbe-placeholder`, `data-cbe-gated`,
      `cookiebot-embeds-activator`)
- [ ] B5 below runs clean
- [ ] Appendix A has no row blocking release

## B5. Verification

```bash
# 1. Package metadata is valid
composer validate --strict --no-plugins
# expect: ./composer.json is valid

# 2. Code style
composer cs
# expect: [OK] No errors found

# 3. Static analysis, level 6
composer analyse
# expect: [OK] No errors

# 4. Unit suite: classifier, rewriter fixtures, config normalisation, benchmark
composer test
# expect: OK (N tests, M assertions) with no failures, skipped or risky

# 5. No client names or domains in the public repo
grep -rniE -f .github/forbidden-names.txt src tests docs README.md CHANGELOG.md
# expect: no output, exit status 1

# 6. No en or em dashes
LC_ALL=C grep -rn -e $'\xe2\x80\x93' -e $'\xe2\x80\x94' src tests docs README.md CHANGELOG.md
# expect: no output, exit status 1
```

**Manual checks** (staging, private windows, browser network panel):

| Check | Proves | Passes when |
|---|---|---|
| TS-1 steps 2-3 | AC-1 | No provider request before consent; video plays after |
| TS-2 steps 3-6 | AC-2 | Hosts appear only after their category is accepted; checker shows no FAIL |
| TS-3 step 2 | AC-3 | Form submits before any consent |
| TS-5 | AC-5 | Site placeholder shows and hides |
| TS-6 steps 2-5 | AC-6 | Video plays after mount, survives re-render and reload |
| TS-7 | AC-7 | Empty state, rows, clear |
| TS-8 | AC-8 | Same source, behaviour per visitor |
| TS-9 step 2 | AC-9 | Source identical to uninstalled |
| TS-10 steps 4-5 | AC-10 | One banner; link reopens it; nothing when off |

AC-4 is fully proved by B5 #4.

## B6. Out of bounds

- Any client site's repo, templates or config, except adding the plugin as a path repository on
  staging for phase 6.
- The cookiebot-check skill and its `VENDORS` list (changed separately, in its own repo).
- Google Tag Manager containers and Cookiebot accounts.
- Craft's core files and other plugins' output beyond what BR-10 to BR-16 rewrite.

---

## Change log

| Date | Version | Change | By |
|---|---|---|---|
| 2026-11-20 | 0.1 | First draft from the interview | Claude for Sam Birch |
| 2026-11-22 | 0.2 | Spike 0.1: read `uc.js` 2.146.0 and ran a local Vue 2.7 page. Cookiebot releases scripts by cloning them as `text/javascript` and can do so before Vue mounts, which kills a widget's listeners; iframes survive. BR-19 stands, BR-20 superseded, BR-13 kept with its reason, module gating in Later, Appendix A #2 resolved. Site B browser check carried by TS-6. Notes in section 8 | Claude for Sam Birch |
