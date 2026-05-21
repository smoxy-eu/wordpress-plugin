# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- In-WordPress update notifications: the plugin now checks the GitHub Releases for new versions on WP's normal update schedule and surfaces the standard "new version available" notice on the Plugins screen, with one-click install. Powered by [`yahnis-elsts/plugin-update-checker`](https://github.com/YahnisElsts/plugin-update-checker), configured to pull the published `smoxy-X.Y.Z.zip` release asset.

### Changed

- Release/plugin-check workflows and `make build-dev` now run `composer install --no-dev --optimize-autoloader` before packaging so the production `vendor/` directory ships inside the distributable zip (previously `vendor/` was excluded entirely).

## [1.1.0] - 2026-05-21

### Added

- API-driven setup wizard: paste an API token from the smoxy hub and the plugin lists organizations, lets you pick or create a zone (with origin pick-or-create using the server's IP), and registers this site's hostname automatically. The BAN secret is read from `zone.configurations.token` — no manual copy-paste.
- Auto-creation and audit of three conditional bypass rules on the bound zone — for logged-in WordPress users (matched via the `wordpress_logged_in_<md5(siteurl)>` cookie), WooCommerce/account paths (cart, checkout, my-account, product, `add-to-cart`, `wc-api`), and the wp-admin backend — so HTML caching no longer serves the admin bar or transactional flows to authenticated users.
- Status panel that audits the three bypass rules against the bound zone and offers per-rule one-click re-create / fix.
- Hostname conflict handling: when this site's hostname is already attached to a different smoxy zone after creating a new one, the connected view shows a warning with a "Move hostname to this zone" action.
- WooCommerce hooks for `save_post_product`, `woocommerce_update_product`, `woocommerce_new_product` and `woocommerce_update_product_variation` so price-only and other meta-only product changes invalidate the parent product cache (previously only stock changes did).
- `make build-dev` target that produces an upload-ready dev zip in `dist/`, mirroring the release workflow.

### Changed

- Plugin display name from "smoxy Proxy" to "smoxy" across the menu, admin bar, settings page, plugin headers, readme and translations.
- Composer package name from `smoxy/smoxy-proxy` to `smoxy/smoxy`.
- New zones are created with `enabled`, `acceleration_enabled` and `security_enabled` set to `true` so they go live immediately.
- Step 2 (zone picker) is dynamic: choosing "Use existing zone" hides the origin picker entirely; choosing "Create a new zone" reveals it (existing dropdown or create-new toggle, collapsing the radio entirely when the organization has no origins yet).
- New-origin "address" field is prefilled with the server IP (`$_SERVER['SERVER_ADDR']`) instead of the public hostname; the request-hostname (Host header) still defaults to the WordPress host.

### Fixed

- Settings sanitize callback no longer strips API-driven option keys (regression that wiped `api_token` on every `update_option`).
- API client sends `Authorization: Bearer <token>` (which the hub's Symfony `access_token` firewall actually reads) instead of `X-API-TOKEN` (listed in the OpenAPI but never read by the firewall).
- Token validation probes `/api/v2/organizations` — which accepts API tokens and we need on the next wizard step anyway — instead of `/api/internal/me`, which requires a JWT and 401s on API tokens.
- Conditional-rule audit normalizes `""` ↔ `null` for `target` / `value`, so rules just created by the plugin no longer show as "Drifted".
- Conditional-rule PATCH no longer sends the `name` field — works around a hub-side unique-validator that strict-compares a string URL `id` against an int entity id and trips "name already exists" on every self-update.

## [1.0.1] - 2026-05-21

### Changed

- Mark plugin as tested up to WordPress 7.0 (verified locally against the WP 7.0 PHPUnit test library).

## [1.0.0] - 2026-05-19

### Added

- Initial public release: connects WordPress to the smoxy edge cache service.
- Automatic edge-cache invalidation when content changes (posts, comments, terms, menus, widgets, theme, site settings).
- WooCommerce-aware invalidation: variation saves purge the parent product, and stock / stock-status changes (admin, REST, order-driven decrement) purge the affected product or its parent.
- "Purge smoxy cache" button in the WordPress admin bar.
- Settings page under **smoxy → Settings** with purge-all, purge-by-URL, and purge-by-tag tools.
- Internationalisation via the `smoxy` text domain (POT in `languages/`).
- `Makefile` with `release-{patch,minor,major}` targets that bump the version in `smoxy.php` and `readme.txt`, commit, tag, and push.
- GitHub Actions workflows: `lint`, `phpunit`, `plugin-check`, `smoke`, and `release` (which builds zip + tar.gz on `v*` tag push and publishes a GitHub Release).
- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, `CHANGELOG.md`.

[Unreleased]: https://github.com/smoxy-eu/wordpress-plugin/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/smoxy-eu/wordpress-plugin/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/smoxy-eu/wordpress-plugin/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/smoxy-eu/wordpress-plugin/releases/tag/v1.0.0
