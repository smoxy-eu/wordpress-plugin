# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Attachment-driven edge-cache invalidation: when an image is uploaded, regenerated, edited, or deleted, every known size variant (full image, pre-`-scaled` original, and each entry under `metadata['sizes']`) is BAN'd at the edge. Listens to `wp_update_attachment_metadata` and `delete_attachment`, deduplicates URLs across the request, and dispatches at `shutdown`. Non-image attachments are skipped.
- `Purger::purge_urls( array $urls )` — parallel BAN dispatcher that issues all URL invalidations in one batch via the WordPress-bundled `Requests::request_multiple()` (curl_multi under the hood), instead of looping `wp_remote_request`. Exposes a `smoxy_pre_purge_urls` filter as a test/extension seam.
- Fourth managed conditional rule "WordPress: cache images on URI only" (`RuleDefinitions::KEY_IMAGES`). Matches requests whose URI ends in `.png`, `.jpeg`, `.jpg`, `.gif`, `.webp`, `.avif`, or `.svg`, narrows the cache key to URI only via the v2 `vary_cache` action (`host_vary_enabled=false`, `cookie_vary_params=[]`), and sets `stop=true` so downstream conditional rules are skipped for image responses. Pinned to position 1 on the zone via the dedicated `/conditional-rule/{id}/position` PATCH endpoint (the hub assigns positions sequentially on create and ignores `position` in the create payload); the existing WordPress bypass rules shift to positions 2–4.
- `Client::patch_conditional_rule_position()` for the position endpoint, and a `reconcile_position()` step in `Bootstrap::ensure_rules()` and the per-rule "fix" action in `Settings` that aligns rules with a declared `expected_position` after create or patch.

### Changed

- `make install` and `make build-dev` now run `composer install` inside a `composer:2` Docker container instead of on the host. The container is bind-mounted to the project directory only, runs as the host UID/GID, and pins `COMPOSER_HOME`/`COMPOSER_CACHE_DIR` inside the container — so a malicious package's post-install scripts cannot reach the host shell or read host secrets.
- `Audit::find_drift()` now also compares the `stop` flag and (when the rule definition declares `expected_position`) the rule's position between the expected payload and the remote rule, so the images rule is flagged as drifted if either is changed on the hub. Rules without an `expected_position` (the three bypass rules) keep the previous behavior — users may reorder them freely without triggering drift alerts.
- `Audit` report now includes `remote_position` per rule so the settings panel and bootstrap can pin a rule's slot without re-listing.

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

[Unreleased]: https://github.com/smoxy-eu/wordpress-plugin/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/smoxy-eu/wordpress-plugin/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/smoxy-eu/wordpress-plugin/releases/tag/v1.0.0
