# smoxy Proxy for WordPress

[![Lint](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/lint.yml/badge.svg)](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/lint.yml)
[![PHPUnit](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/phpunit.yml/badge.svg)](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/phpunit.yml)
[![Plugin Check](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/plugin-check.yml/badge.svg)](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/plugin-check.yml)
[![Smoke](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/smoke.yml/badge.svg)](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/smoke.yml)
[![Release](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/release.yml/badge.svg)](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/release.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net/)

Connects WordPress to [smoxy Proxy](https://www.smoxy.eu). Your pages stay fast at the edge, and your content stays fresh — the plugin keeps smoxy Proxy in sync with your site automatically.

- **Website:** <https://www.smoxy.eu>
- **Documentation:** <https://docs.smoxy.eu>
- **Hub / Dashboard:** <https://hub.smoxy.eu>
- **Issues:** <https://github.com/smoxy-eu/wordpress-plugin/issues>

---

## What is smoxy?

smoxy is a performance and security platform built for e-commerce shops and brand websites. It combines full-page HTML caching, automatic image optimization and edge-level protection to deliver pages **50–800% faster** — improving user experience, SEO rankings and conversion rates. The dashboard is simple, and integration requires minimal setup.

Learn more at [smoxy.eu](https://www.smoxy.eu) or read the full product docs at [docs.smoxy.eu](https://docs.smoxy.eu).

## What this plugin does

- **Keeps the edge cache fresh.** When you publish, edit, or delete content — or change menus, widgets, your theme or site settings — the affected pages are purged from smoxy Proxy automatically.
- **Gives you one-click control.**
  - A **Purge smoxy Proxy cache** button is available right in the WordPress admin bar.
  - A dedicated **smoxy Proxy** menu provides a settings page with manual purge tools.
- **Shows your connection status.** See at a glance whether your site is actually being served through smoxy Proxy.
- **Purges individual pages or assets on demand.** Clear a specific page, image, JS, CSS or other file by its URL.

## Features

- Automatic cache invalidation on content changes
- "Purge all" from the admin bar or settings page
- "Purge by URL" for any cacheable resource (pages, images, JS, CSS, fonts, …)
- Live connection status with on-demand recheck
- Clean, native WordPress admin experience — no external dashboards to learn

## Requirements

- WordPress 6.0 or newer (tested up to 6.9)
- PHP 8.0 or newer
- A [smoxy account](https://hub.smoxy.eu) with a Zone configured for your domain

## Installation

### From a GitHub release (recommended)

1. Download the latest `smoxy-X.Y.Z.zip` from the [Releases page](https://github.com/smoxy-eu/wordpress-plugin/releases).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate **smoxy Proxy**.
4. Open **smoxy Proxy → Settings** (cloud icon in the sidebar), paste your **secret token**, and save.

### From source (for development)

```bash
git clone git@github.com:smoxy-eu/wordpress-plugin.git smoxy
mv smoxy /path/to/wp-content/plugins/
cd /path/to/wp-content/plugins/smoxy
composer install
```

Then activate **smoxy Proxy** from the WordPress admin.

### Getting your secret token

Log in to the [smoxy hub](https://hub.smoxy.eu) — or create a **free account** if you don't have one yet. Open your Zone, go to the **Basic configuration** menu, and copy the token shown there. See the [setup guide in the docs](https://docs.smoxy.eu) for a walkthrough.

## Using the plugin

### Check your connection

The Settings page shows **Connecting to smoxy Proxy: Yes / No**. The status refreshes automatically, and you can click **Check connection** at any time to re-test.

### Purge everything

- **From the admin bar:** click **Purge smoxy Proxy cache** at the top of any admin or front-end page.
- **From the settings page:** open **smoxy Proxy → Settings** and click **Purge all cache**.

### Purge a single URL

On the Settings page, enter a URL in the **Purge by URL** box and click **Purge URL**. This works for any cacheable asset: a blog post, a product image, a CSS file, a font — anything served through smoxy Proxy.

### Automatic purges

You don't need to click anything for normal editing. The plugin watches for changes in WordPress and clears the right pages from smoxy Proxy for you — including posts, comments, categories, menus, widgets, theme changes, and site settings.

## Development

Common tasks are wrapped in the `Makefile`. Run `make` (no target) for a list:

```bash
make install        # composer install
make lint           # phpstan + phpcs
make test           # phpunit (requires a configured WP test env)
make release-patch  # bump 0.0.X, tag, push — fires the release workflow
make release-minor  # bump 0.X.0, ...
make release-major  # bump X.0.0, ...
```

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for the full dev setup, CI overview, and release flow.

## Documentation

Full product documentation lives at **[docs.smoxy.eu](https://docs.smoxy.eu)**:

- Getting started and account setup
- Zone configuration and DNS
- Cache rules and behavior
- Image optimization
- Edge security and access control
- API reference

## Support

- **Docs:** <https://docs.smoxy.eu>
- **Account / billing:** <https://hub.smoxy.eu>
- **Plugin bugs / feature requests:** [open an issue](https://github.com/smoxy-eu/wordpress-plugin/issues)
- **Security:** see [`SECURITY.md`](SECURITY.md) for responsible disclosure

## Contributing

Contributions are welcome! Please read [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) before opening a pull request.

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md) for a full release history.

## License

Released under the [MIT License](LICENSE). © smoxy.
