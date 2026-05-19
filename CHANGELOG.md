# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial plugin functionality: connects WordPress to the smoxy proxy edge cache service.
- Automatic edge-cache invalidation when content changes (posts, comments, terms, menus, widgets, theme, site settings).
- "Purge smoxy Proxy cache" button in the WordPress admin bar.
- Settings page under **smoxy Proxy → Settings** with purge-all, purge-by-URL, and purge-by-tag tools.
- Live connection-status panel that detects whether the site is fronted by smoxy.
- Internationalisation via the `smoxy` text domain (POT in `languages/`).
- `Makefile` with `release-{patch,minor,major}` targets that bump the version in `smoxy.php` and `readme.txt`, commit, tag, and push.
- GitHub Actions workflows: `lint`, `phpunit`, `plugin-check`, `smoke`, and `release` (which builds zip + tar.gz on `v*` tag push and publishes a GitHub Release).
- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, `CHANGELOG.md`.

### Changed

- Relicensed from GPL-2.0-or-later to MIT.

[Unreleased]: https://github.com/smoxy-eu/wordpress-plugin/compare/HEAD...HEAD
