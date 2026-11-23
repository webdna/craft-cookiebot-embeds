# Cookiebot Embeds Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## Unreleased
### Added
- Package scaffold: plugin class, icons, ECS, PHPStan level 6 and an app-free PHPUnit setup.
- `config/cookiebot-embeds.php` settings (`enabled`, `mode`, `allow`, `vendors`, `fingerprints`, `placeholderTemplate`, `injectCookiebot`, `cbid`, `cookiebotDomain`, `report`), each optionally keyed by site handle with `*` as the fallback. An invalid value is logged and replaced by its default; loading the config never throws.
