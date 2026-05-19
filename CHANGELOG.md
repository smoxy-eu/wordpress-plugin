# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-05-19

### Added

- Initial public release: connects WordPress to the smoxy proxy edge cache service.
- Automatic edge-cache invalidation when content changes (posts, comments, terms, menus, widgets, theme, site settings).
- WooCommerce-aware invalidation: variation saves purge the parent product, and stock / stock-status changes (admin, REST, order-driven decrement) purge the affected product or its parent.
- "Purge smoxy Proxy cache" button in the WordPress admin bar.
- Settings page under **smoxy Proxy → Settings** with purge-all, purge-by-URL, and purge-by-tag tools.
- Internationalisation via the `smoxy` text domain (POT in `languages/`).
- `Makefile` with `release-{patch,minor,major}` targets that bump the version in `smoxy.php` and `readme.txt`, commit, tag, and push.
- GitHub Actions workflows: `lint`, `phpunit`, `plugin-check`, `smoke`, and `release` (which builds zip + tar.gz on `v*` tag push and publishes a GitHub Release).
- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, `CHANGELOG.md`.

[Unreleased]: https://github.com/smoxy-eu/wordpress-plugin/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/smoxy-eu/wordpress-plugin/releases/tag/v1.0.0
