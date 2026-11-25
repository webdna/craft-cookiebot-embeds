# Profile: craft-cookiebot-embeds

The half of every spec's build contract that is true of this whole repo. Written 2026-11-20, before
the first line of code, from the conventions of webdna's existing Craft 5 plugins (query-boost,
schedule-it, auditor, typesense-sync, email-content-editor). Correct it as soon as the repo
disagrees: every spec here inherits it.

**This repo is public.** Nothing in it, specs included, names a client, a client domain, or a real
container, portal or site ID.

## 1. Stack

| Layer | Choice |
|---|---|
| Package | `webdna/craft-cookiebot-embeds`, type `craft-plugin`, MIT |
| Plugin handle | `cookiebot-embeds` |
| Namespace | `webdna\cookiebotembeds\` => `src/`; tests `webdna\cookiebotembeds\tests\` => `tests/` |
| PHP | `^8.2`, `config.platform.php` 8.2; CI also runs 8.4 |
| Craft | `craftcms/cms ^5.0` |
| Tests | PHPUnit 10 (`phpunit/phpunit ^10.5`), no database, no running Craft app |
| Code style | `craftcms/ecs` (`SetList::CRAFT_CMS_4`, parallel) |
| Static analysis | `craftcms/phpstan` + `phpstan/phpstan ^2.1`, level 6 on `src` and `tests` |
| Front-end | One small vanilla JS file, no build step, no npm |
| CI | GitHub Actions, PHP 8.2 and 8.4 on ubuntu-latest |

## 2. Commands

Always run through DDEV, never host PHP or Composer (Sam, 23 Nov 2026). The repo carries its own
`.ddev/config.yaml`: project `cookiebot-embeds`, type `php`, PHP 8.2, no database container (the
plugin has no database of its own to test against). `ddev start` once, then:

| Purpose | Command |
|---|---|
| Install | `ddev composer install` |
| Code style | `ddev composer cs` (fix: `ddev composer cs-fix`) |
| Static analysis | `ddev composer analyse` |
| Unit tests | `ddev composer test` |
| Everything CI runs | `ddev composer check` (cs + analyse + test) |
| Validate package | `ddev composer validate --strict --no-plugins` |

CI runs the same `composer` scripts directly on the runner's PHP. The V1-V4 rows below name the
bare script; prefix `ddev` locally.

Testing inside a real site: add the repo as a Composer `path` repository in that site's
`composer.json`, `ddev composer require webdna/craft-cookiebot-embeds:@dev`, then
`ddev craft plugin/install cookiebot-embeds`.

## 3. Where things go

| Kind | Directory |
|---|---|
| Plugin class | `src/CookiebotEmbeds.php` |
| Settings model | `src/models/Settings.php` |
| Config template users copy | `src/config.php` (copied to the site's `config/cookiebot-embeds.php`) |
| Services | `src/services/` |
| Migrations | `src/migrations/Install.php` |
| CP utility | `src/utilities/` + `src/templates/` |
| Web controllers | `src/controllers/` |
| Shipped data | `src/data/` |
| Front-end asset | `src/web/assets/` |
| Translations | `src/translations/en/cookiebot-embeds.php` |
| Icons | `src/icon.svg`, `src/icon-mask.svg` |
| Tests and fixtures | `tests/unit/`, `tests/fixtures/` |
| Specs | `docs/specs/` |

Placement rule: anything that can be a pure function of input strings lives in a service with no
`Craft::$app` calls, so PHPUnit can test it without an app.

## 4. Verification

| # | Command | Passes when |
|---|---|---|
| V1 | `composer validate --strict --no-plugins` | `./composer.json is valid` |
| V2 | `composer cs` | `[OK] No errors found` |
| V3 | `composer analyse` | `[OK] No errors` |
| V4 | `composer test` | `OK (N tests, M assertions)`, no failures, no risky, no skipped |
| V5 | *Removed 2026-11-25 (Sam): the client-name grep against a private list* | - |
| V6 | `LC_ALL=C grep -rn -e $'\xe2\x80\x93' -e $'\xe2\x80\x94' src tests docs README.md CHANGELOG.md` | no output |

The plugin has no reason to name a project, so there is no client-name list to check against: the
no-project-details rule is kept by reviewing each diff (code, docs, fixtures and the commit
message) before committing.

## 5. Traps

From evidence in webdna's other plugins. Every one has bitten before.

- **Never assume the config file has been merged at install time.** Craft merges a plugin's config
  file only when building an installed plugin, so `afterInstall()` sees defaults. Read
  `Config::getConfigFromFile()` yourself if install-time code needs settings (query-boost commit log).
- **The config file is loaded on every request, web included. It must never throw.** A throw there
  takes the whole site down, not just the plugin (schedule-it `src/Job.php`).
- **Check the handle against the Plugin Store before the first release.** Craft's update check
  matches by handle; schedule-it shipped as `scheduler`, collided with another vendor's plugin, and
  every install was offered that plugin's updates.
- **PHPUnit without an app still needs Yii and Craft classes loaded.** The bootstrap requires
  `vendor/autoload.php` and `vendor/yiisoft/yii2/Yii.php`; nothing may call `Craft::$app` in a unit
  test (schedule-it `tests/bootstrap.php`).
- **Owned database objects carry the plugin prefix**, so uninstall drops only what the plugin made
  (query-boost `src/indexes/Declaration.php`).
- **A public repo never names a project.** Findings from testing on a client site are written up
  generically (made-up hosts and IDs, no site name) before they reach a doc, fixture or commit.

## 6. Working conventions

- Branch per feature off `main`; commit, never push. Sam pushes and tags.
- Commit subjects lead with the version when releasing (`1.0.0: ...`); bodies are prose and cite
  `BR-n` / `TS-n` / `TN-n`.
- `CHANGELOG.md` follows Keep a Changelog, `## Unreleased` until tagged.
- No en or em dashes anywhere (V6).
