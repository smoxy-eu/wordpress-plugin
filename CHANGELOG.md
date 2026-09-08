# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Zone-level cache settings are now managed alongside the conditional rules, in `Setup\ZoneSettings`. Zones the plugin creates during setup get `cachingAdditionalContentTypes: ["css", "js", "font", "xml_text"]`, `cachingManagedIgnoredUrlParamsEnabled: true` and `stripCacheTagHeaders: true`. Caching stylesheets, JavaScript and fonts at the edge removes a large share of origin traffic on a typical WordPress site; the managed ignored-URL-param list collapses campaign-tagged URLs (`utm_*`, `gclid`, `fbclid`, ...) onto one cache entry; stripping the tag headers keeps the plugin's `X-Cache-Tags` out of visitor-facing responses. `json` is deliberately not enabled — WP REST responses are frequently per-user.
- A zone the user picked during setup rather than created is never modified silently. `Audit` now reports each managed cache setting as OK or drifted, the settings page shows them in a new "Zone cache settings" table, and an "Apply recommended settings" button (new `smoxy_sync_zone_settings` admin-post action) applies them on request. `cachingAdditionalContentTypes` is compared as a subset rather than for equality, so a class the user enabled themselves is not drift and the repair sends the union instead of overwriting the list.
- `Client::patch_zone()` — merge-PATCH against `/api/zones/{id}`, used by the one-click repair.
- `tests/phpunit/ZoneSettingsTest.php` — first test coverage of the hub API client and audit layer (12 tests), stubbing the hub through `pre_http_request`.

### Fixed

- The `feed` cache tag had nothing to purge. The plugin tags feed responses and invalidates the tag on content changes, but RSS/Atom is only cacheable when the zone enables the `xml_text` content class, which the plugin never set. New zones now enable it, and existing zones surface it as drift.

### Changed

- Full-cache invalidation now sends `type: all` instead of `type: flushall`. `flushall` is the legacy spelling and keeps working upstream, so this is not a behavior change.
- Zone creation in `Setup\Bootstrap` builds its cache settings from `Setup\ZoneSettings` rather than an inline payload, so creating a zone and repairing one cannot drift apart.

### Compatibility

- Verified against **WordPress 7.1** and **PHP 8.4 / 8.5**; `Tested up to` raised from 7.0 to 7.1. No code changes were needed. Each version was checked with a full activate/exercise/uninstall run under `WP_DEBUG` (no plugin diagnostics logged), the PHPUnit suite, PHPStan level 8, WPCS and PHPCompatibility. The plugin calls 44 PHP internal functions, none deprecated on either version, and neither the plugin nor the bundled `plugin-update-checker` emits the PHP 8.4 implicit-nullable deprecation.
- CI now covers both floors the plugin declares. The PHPUnit and smoke matrices run PHP 8.0 through 8.5 (`Requires PHP: 8.0` was previously never tested — the lowest job was 8.1), plus a WordPress 6.0 job for `Requires at least: 6.0`, which every matrix entry had been skipping in favour of `latest`. PHP 8.0 and 8.1 are EOL upstream but still run roughly 15% of WordPress installs, and `Requires PHP` gates update delivery, so both stay supported and tested.
- The WordPress 6.0 job also exercises the `\Requests` legacy-alias branch in `Purger::purge_urls()` for the first time; WordPress 6.2+ only reaches the `\WpOrg\Requests\Requests` path, so that fallback had no coverage.
- Tooling jobs moved off PHP 8.1, which reached end of life in December 2025: PHPStan, WPCS, PHP-Compatibility and the release build now run on 8.4. Output is unchanged — PHPStan takes `phpVersion` from its config and dependency resolution is pinned by `composer.platform.php`. The two WordPress-trunk canaries moved from 8.3 to 8.5 so the forward-looking job pairs newest WordPress with newest PHP.
- Re-checked the plugin's 13 hub API endpoints against the current OpenAPI spec: no breaking changes, none deprecated, and request/response shapes, query parameters and condition enums all still match.
- Purging deliberately stays on the edge BAN protocol. The hub's `POST /api/zones/{zoneId}/cache/clear` endpoint is rate-limited to one clear per zone every 5 seconds and is documented as unsuited to routine, application-driven invalidation.

### Docs

- `readme.txt`: new "Behind Cloudflare?" section — Cloudflare blocks `PURGE` outright and its DDoS protection may block `BAN`, so proxied sites need a DNS-only subdomain for invalidation traffic. Also corrected the External Services section, which still described the old `flushall` directive.

## [2.0.0] - 2026-07-05

Major release: the plugin now talks to the new Smoxy Hub API. The previous `hub.smoxy.eu/api/v2` API was shut off upstream, so older plugin versions can no longer run the setup wizard, rule audit, or hostname management — updating is required. Edge-cache purging (BAN) is unaffected and keeps working on old and new versions alike.

### Changed

- Migrated the hub API client to the new Smoxy Hub API at `https://api.smoxy.eu` (the previous `https://hub.smoxy.eu/api/v2` API was replaced wholesale). Authentication now uses the documented `X-API-TOKEN` header instead of the `Authorization: Bearer` workaround.
- Conditional rules are now managed through the `/api/zones/{zoneId}/configuration-rules` resource: rule ids are UUIDs, `expressions`/`rules` became `conditions` (with `logic`, `field`, `operator`, `target`, `value`) and `settingsOverrides`, `stop` became `stopOnMatch`, and the images rule narrows the static cache key via `settingsOverrides.cachingStaticCacheKey.varyByHostname = false`. Condition fields renamed: `cookies` → `cookie`, `args` → `queryParam`.
- Rule ordering is a plain `order` field (1-based, honored on create, re-sequenced on update) — the dedicated `/conditional-rule/{id}/position` PATCH endpoint and `Client::patch_conditional_rule_position()` are gone; the images rule pins `order: 1` directly in its payload.
- Zone creation sends `organization` as an IRI, the lowercase `tag` enum (`prod`/`stage`/`dev`), a `defaultBackend` (`{type: "origin", id: <uuid>}`) instead of the old `origin` field, and flat `enabled`/`securityEnabled`/`cachingDynamicEnabled`/`cachingStaticEnabled` flags instead of the `configurations` object. The BAN secret is read from the zone's top-level `banToken` (was `configurations.token`).
- Hostnames are org- and zone-scoped now: lookup via `GET /api/organizations/{organizationId}/hostnames?q=`, creation via `POST /api/zones/{zoneId}/hostnames` with `name` (was a flat `/api/v2/hostnames` collection with `hostname`), and a zone-to-zone move is a merge-PATCH within the hostname's current zone with the target zone as an IRI. Hostname and origin-server ids are UUIDs.
- The edge BAN protocol (`ingress.smoxy.eu`, `secret`/`tags`/`type: flushall` headers) is unchanged and the `Purger` is untouched — only the hub API integration moved.

## [1.1.0] - 2026-05-22

### Added

- Attachment-driven edge-cache invalidation: when an image is uploaded, regenerated, edited, or deleted, every known size variant (full image, pre-`-scaled` original, and each entry under `metadata['sizes']`) is BAN'd at the edge. Listens to `wp_update_attachment_metadata` and `delete_attachment`, deduplicates URLs across the request, and dispatches at `shutdown`. Non-image attachments are skipped.
- `Purger::purge_urls( array $urls )` — parallel BAN dispatcher that issues all URL invalidations in one batch via the WordPress-bundled `Requests::request_multiple()` (curl_multi under the hood), instead of looping `wp_remote_request`. Exposes a `smoxy_pre_purge_urls` filter as a test/extension seam.
- Fourth managed conditional rule "WordPress: cache images on URI only" (`RuleDefinitions::KEY_IMAGES`). Matches requests whose URI ends in `.png`, `.jpeg`, `.jpg`, `.gif`, `.webp`, `.avif`, or `.svg`, narrows the cache key to URI only via the v2 `vary_cache` action (`host_vary_enabled=false`, `cookie_vary_params=[]`), and sets `stop=true` so downstream conditional rules are skipped for image responses. Pinned to position 1 on the zone via the dedicated `/conditional-rule/{id}/position` PATCH endpoint (the hub assigns positions sequentially on create and ignores `position` in the create payload); the existing WordPress bypass rules shift to positions 2–4.
- `Client::patch_conditional_rule_position()` for the position endpoint, and a `reconcile_position()` step in `Bootstrap::ensure_rules()` and the per-rule "fix" action in `Settings` that aligns rules with a declared `expected_position` after create or patch.

### Changed

- `make install` and `make build-dev` now run `composer install` inside a `composer:2` Docker container instead of on the host. The container is bind-mounted to the project directory only, runs as the host UID/GID, and pins `COMPOSER_HOME`/`COMPOSER_CACHE_DIR` inside the container — so a malicious package's post-install scripts cannot reach the host shell or read host secrets.
- `Audit::find_drift()` now also compares the `stop` flag and (when the rule definition declares `expected_position`) the rule's position between the expected payload and the remote rule, so the images rule is flagged as drifted if either is changed on the hub. Rules without an `expected_position` (the three bypass rules) keep the previous behavior — users may reorder them freely without triggering drift alerts.
- `Audit` report now includes `remote_position` per rule so the settings panel and bootstrap can pin a rule's slot without re-listing.

### Docs

- Rewrote `README.md` and `readme.txt` to lead with the WooCommerce value proposition (cart/checkout always live, variant-price-edit handling, stock invalidation) and the three smoxy pillars — edge caching, image optimization (WebP/AVIF/SSIM), and the WAF/security layer. Added the headline performance numbers and customer references from smoxy.eu.

## [1.0.1] - 2026-05-21

No functional changes — version bump to exercise the in-WordPress update flow against an existing v1.0.0 install.

## [1.0.0] - 2026-05-21

Initial public release. Connects WordPress to the [smoxy](https://www.smoxy.eu) edge cache service and keeps the cache in sync with site changes.

### Added

- Connects WordPress to smoxy's ingress API and authenticates with a per-zone secret token.
- Automatic edge-cache invalidation when content changes — posts, comments, terms, menus, widgets, theme, site settings.
- WooCommerce-aware invalidation: variation saves purge the parent product; stock and stock-status changes (admin, REST, order-driven decrement) purge the affected product or its parent; `save_post_product`, `woocommerce_update_product`, `woocommerce_new_product` and `woocommerce_update_product_variation` invalidate the parent product cache so price-only and meta-only changes are picked up.
- "Purge smoxy cache" button in the WordPress admin bar.
- Settings page under **smoxy → Settings** with purge-all, purge-by-URL and purge-by-tag tools.
- API-driven setup wizard: paste an API token from the [smoxy hub](https://hub.smoxy.eu) and the plugin lists organizations, lets you pick or create a zone (with origin pick-or-create using the server's IP), and registers this site's hostname automatically. The BAN secret is read from `zone.configurations.token` — no manual copy-paste.
- Auto-creation and audit of three conditional bypass rules on the bound zone — for logged-in WordPress users (matched via the `wordpress_logged_in_<md5(siteurl)>` cookie), WooCommerce/account paths (cart, checkout, my-account, product, `add-to-cart`, `wc-api`), and the wp-admin backend — so HTML caching no longer serves the admin bar or transactional flows to authenticated users.
- Status panel that audits the three bypass rules against the bound zone and offers per-rule one-click re-create / fix.
- Hostname conflict handling: when this site's hostname is already attached to a different smoxy zone after creating a new one, the connected view shows a warning with a "Move hostname to this zone" action.
- In-WordPress update notifications: the plugin checks the GitHub Releases for new versions on WordPress's normal update schedule and surfaces the standard "new version available" notice on the Plugins screen with one-click install. Powered by [`yahnis-elsts/plugin-update-checker`](https://github.com/YahnisElsts/plugin-update-checker), configured to pull the published `smoxy-X.Y.Z.zip` release asset.
- Internationalisation via the `smoxy` text domain (POT in `languages/`).
- Tested up to WordPress 7.0 (verified against the WP 7.0 PHPUnit test library).
- `Makefile` with `release-{patch,minor,major}` targets and a `build-dev` target that produces an upload-ready dev zip in `dist/`, mirroring the release workflow.
- GitHub Actions workflows: `lint`, `phpunit`, `plugin-check`, `smoke`, and `release` (builds zip + tar.gz on `v*` tag push and publishes a GitHub Release).
- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, `CHANGELOG.md`.

[Unreleased]: https://github.com/smoxy-eu/wordpress-plugin/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/smoxy-eu/wordpress-plugin/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/smoxy-eu/wordpress-plugin/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/smoxy-eu/wordpress-plugin/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/smoxy-eu/wordpress-plugin/releases/tag/v1.0.0
