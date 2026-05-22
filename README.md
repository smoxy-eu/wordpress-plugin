# smoxy for WordPress & WooCommerce

[![Lint](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/lint.yml/badge.svg)](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/lint.yml)
[![PHPUnit](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/phpunit.yml/badge.svg)](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/phpunit.yml)
[![Plugin Check](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/plugin-check.yml/badge.svg)](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/plugin-check.yml)
[![Smoke](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/smoke.yml/badge.svg)](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/smoke.yml)
[![Release](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/release.yml/badge.svg)](https://github.com/smoxy-eu/wordpress-plugin/actions/workflows/release.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net/)

> **Turn your WooCommerce shop into a speed machine — without rebuilding it.**
> smoxy makes WordPress and WooCommerce shops load **50–800% faster** at the edge, serves smaller images automatically, and shields your origin from malicious traffic. This plugin is the glue that keeps your shop and the smoxy edge in lock-step — so prices, stock, product images and content updates always show up the moment you save them.

[**smoxy.eu**](https://www.smoxy.eu) · [**Docs**](https://docs.smoxy.eu) · [**Hub / Dashboard**](https://hub.smoxy.eu) · [**Open a free account**](https://hub.smoxy.eu) · [Plugin issues](https://github.com/smoxy-eu/wordpress-plugin/issues)

---

## Why WooCommerce shops choose smoxy

Most caching plugins force you to choose: cache everything and break the cart, or cache nothing and stay slow. smoxy doesn't. It runs in front of your shop at the edge, caches what's safe to cache, and **lets the cart, checkout, my-account and admin areas fall straight through to the origin**. The plugin in this repo wires WooCommerce's own save events into smoxy so cached pages refresh the instant a product, variation, stock level or category changes.

The result, in numbers shop owners have reported back:

- **3 second page loads down to 0.4 seconds.**
- **Product images shrunk from 132 KB to 19 KB** with no visible quality loss.
- **Up to 800% faster page rendering** — felt in faster Core Web Vitals, higher SEO rankings, and measurably better conversion.

Brands running smoxy in production include **trigema**, **ETERNA**, **Stadt-Parfümerie Pieper**, **WM24**, **babyone**, **Topperz Store**, **Jeans-Fritz** and **foun10**.

---

## What you get

### Edge caching that actually fits a shop

Full-page HTML caching at the edge, with the WooCommerce flows that *must* be live carved out by default — no manual configuration to get safe behaviour on day one:

- **Logged-in users**, **cart**, **checkout**, **my-account**, **add-to-cart** links, **Woo REST endpoints**, **wp-admin** — bypassed automatically via four [Conditional Rules](https://docs.smoxy.eu/rules/conditional-rules) the plugin installs and keeps in sync on your zone.
- **Tag-based invalidation** — when a product changes, only the affected pages are refreshed (the product, every category/tag/attribute archive it appears on, the shop page). A bulk edit of ten products is one cache refresh, not ten.
- **Variant-price edits** — a classic gotcha with other cache plugins, where saving a variation skips the standard WordPress hook and leaves the parent product page stale. smoxy listens for WooCommerce's own update events, so variant changes always invalidate the parent product.
- **Stock & stock-status changes** flowing through Woo's data store (admin fields, REST writes, order-driven decrement) refresh the product and its archives immediately.

### Image optimization on autopilot

smoxy optimises every image served through your shop — automatically, without you re-uploading anything:

- **Modern formats per visitor.** JPEG and PNG are converted to **WebP** (≈25–35% smaller) and **AVIF** (smaller still) for browsers that support them; older browsers receive the optimised original. The URL never changes — format negotiation happens via the `Accept` header.
- **Lossless-looking compression.** smoxy uses the **SSIM** (Structural Similarity Index) method, defaulting to an SSIM target of `0.9997` — nearly indistinguishable from the original.
- **Thumbnail-aware invalidation.** When you upload, replace, regenerate or delete a media item, this plugin BANs every known size at the edge in parallel — the full image, the pre-`-scaled` original (WP 5.3+), and every thumbnail under `metadata['sizes']`.
- **URI-only cache key for images.** The plugin installs a top-priority conditional rule that narrows the image cache key to URI only, so every visitor reuses the same cached file and your image cache hit rate stays near 100%.

Full reference: [Image Optimization in the docs](https://docs.smoxy.eu/sites/image-optimization).

### Security & WAF at the edge

The same edge that serves your pages also protects them:

- **Web Application Firewall** that blocks known malicious patterns automatically — safe to switch on for any site, no per-rule tuning required, ruleset maintained by smoxy.
- **Access Rules** for IP, country, user-agent, header or path-based allow/block/challenge decisions, evaluated before WAF.
- **Basic Auth** for staging environments or restricted areas, with per-path overrides.
- **Under Attack Mode** — an emergency dial that adds stricter verification across the board during a live DDoS or abuse spike.

All of this lives at the edge, in front of your origin, so attack traffic never reaches WordPress. Full reference: [Security & WAF in the docs](https://docs.smoxy.eu/sites/security-and-waf).

---

## What this plugin specifically does

1. **One-shot setup wizard.** Paste a smoxy hub API token, pick (or create) a zone and origin, and the plugin registers your hostname, fetches the BAN secret, and installs the four managed conditional rules — no manual copy-paste between WordPress and the hub.
2. **Keeps the edge cache fresh automatically.** Posts, pages, comments, terms, menus, widgets, theme switches, customizer saves, plugin (de)activation, site-option changes — all of it tagged and invalidated in the background. See the [WordPress integration guide](https://docs.smoxy.eu/developer-guide/wordpress) for the full event matrix.
3. **WooCommerce-aware out of the box.** Listeners stay inert when WooCommerce isn't installed; activate it and product saves, variation saves, stock changes and stock-status changes all flow into the edge cache invalidation pipeline.
4. **Attachment-driven image invalidation.** Every thumbnail size for a changed image is purged in one parallel batch.
5. **Manual controls.**
   - **Purge smoxy cache** button in the WordPress admin bar.
   - **smoxy → Settings** with **Purge all**, **Purge by URL** (works for any cacheable asset — pages, images, JS, CSS, fonts), and **Purge by tag** (e.g. `p-42, t-7, home`).
6. **Audit & one-click repair** of the four managed rules. If a rule drifts (expression, action, stop flag, or the images-rule's position), the settings page surfaces it and the **Fix** button restores the default.
7. **Extensibility hooks.** Re-broadcasts core WP events as `smoxy_*` actions (`smoxy_post_updated`, `smoxy_post_deleted`, `smoxy_comment_changed`, `smoxy_term_changed`, `smoxy_author_changed`, `smoxy_flush_all`) and a `smoxy_pre_purge_urls` filter so other plugins can join the invalidation pipeline without touching core hooks.

---

## Requirements

- WordPress 6.0 or newer (tested up to 7.0)
- PHP 8.0 or newer
- A [smoxy account](https://hub.smoxy.eu) — free to create — with a Zone configured for your domain

---

## Installation

### From a GitHub release (recommended)

1. Download the latest `smoxy-X.Y.Z.zip` from the [Releases page](https://github.com/smoxy-eu/wordpress-plugin/releases).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate **smoxy**.
4. Open **smoxy → Settings** (cloud icon in the sidebar) and paste an **API token** from the [smoxy hub](https://hub.smoxy.eu).
5. Pick (or create) a zone — the wizard takes care of origin creation, hostname registration, the BAN secret, and the four conditional rules.

From this point on, the plugin keeps your edge cache in sync. No further setup required.

### From source (for development)

```bash
git clone git@github.com:smoxy-eu/wordpress-plugin.git smoxy
mv smoxy /path/to/wp-content/plugins/
cd /path/to/wp-content/plugins/smoxy
make install   # composer install — runs inside Docker
```

Then activate **smoxy** from the WordPress admin.

### Updates

Once installed, the plugin checks the [GitHub Releases page](https://github.com/smoxy-eu/wordpress-plugin/releases) for new versions on WordPress's normal update schedule (roughly every 12 hours). New releases show up under **Plugins → Installed Plugins** with the standard one-click update — exactly like a plugin from wordpress.org.

---

## Using the plugin

### Purge everything

- **Admin bar:** click **Purge smoxy cache** at the top of any admin or front-end page.
- **Settings page:** open **smoxy → Settings** and click **Purge all cache**.

### Purge a single URL

In **smoxy → Settings**, enter a URL in the **Purge by URL** box. Works for any cacheable asset — a product page, a category, a product image, a CSS file, a font — anything served through smoxy.

### Purge by tag

The plugin emits cache tags on every cacheable response (`p-{post_id}`, `t-{term_id}`, `a-{author_id}`, `home`, `feed`). Enter one or more tags (comma-separated) to refresh every page carrying them — useful for third-party integrations that emit their own tags.

### Let it run automatically

You don't need to click anything for normal editing. The plugin watches for changes in WordPress and clears the right pages from smoxy for you — including posts, comments, categories, menus, widgets, theme changes, site settings, product/variation saves, stock movements, and image uploads.

---

## Documentation

Full product documentation lives at **[docs.smoxy.eu](https://docs.smoxy.eu)**:

- [Getting started](https://docs.smoxy.eu/getting-started) and account setup
- [WordPress integration guide](https://docs.smoxy.eu/developer-guide/wordpress) (English & German)
- [Image Optimization](https://docs.smoxy.eu/sites/image-optimization)
- [Security & WAF](https://docs.smoxy.eu/sites/security-and-waf)
- [Conditional Rules](https://docs.smoxy.eu/rules/conditional-rules)
- [Cache Invalidation](https://docs.smoxy.eu/developer-guide/cache-invalidation)

---

## Support

- **Product docs:** <https://docs.smoxy.eu>
- **Account / billing:** <https://hub.smoxy.eu>
- **Plugin bugs / feature requests:** [open an issue](https://github.com/smoxy-eu/wordpress-plugin/issues)
- **Security:** see [`SECURITY.md`](SECURITY.md) for responsible disclosure

---

## Contributing

Contributions are welcome. Read [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) before opening a pull request.

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md) for the full release history.

## License

Released under the [MIT License](LICENSE). © smoxy.
