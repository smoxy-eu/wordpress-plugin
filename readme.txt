=== smoxy ===
Contributors: smoxy
Tags: woocommerce, cache, cdn, performance, image-optimization, security, waf, edge-cache, purge
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Edge caching, image optimization and WAF for WordPress and WooCommerce shops — 50–800% faster pages, with the cart, checkout and admin always live.

== Description ==

smoxy makes WordPress and WooCommerce shops load **50–800% faster** at the edge, serves smaller images automatically, and shields your origin from malicious traffic. This plugin keeps your shop and the smoxy edge in lock-step — so prices, stock levels, product images and content updates show up the moment you save them.

= Built for WooCommerce =

Most caching plugins force you to choose: cache everything and break the cart, or cache nothing and stay slow. smoxy doesn't. It runs in front of your shop at the edge, caches what's safe to cache, and lets the cart, checkout, my-account and admin areas fall straight through to the origin.

* **Logged-in users, cart, checkout, my-account, add-to-cart links, Woo REST endpoints and wp-admin** are bypassed automatically via four Conditional Rules the plugin installs and keeps in sync on your zone.
* **Tag-based invalidation** — when a product changes, only the affected pages refresh (the product, every category/tag/attribute archive it appears on, the shop page). A bulk edit of ten products is one refresh, not ten.
* **Variant-price edits** — a classic gotcha with other cache plugins, where saving a variation skips the standard WordPress hook and leaves the parent product page stale. smoxy listens for WooCommerce's own update events, so variant changes always invalidate the parent product.
* **Stock & stock-status changes** flowing through Woo's data store (admin fields, REST writes, order-driven decrement) refresh the product and its archives immediately.

= Image optimization on autopilot =

* JPEG and PNG are converted to **WebP** (≈25–35% smaller) and **AVIF** (smaller still) for browsers that support them — the URL never changes.
* Lossless-looking quality via the **SSIM** method, defaulting to nearly indistinguishable from the original.
* When you upload, replace, regenerate (e.g. via *Regenerate Thumbnails*) or delete a media item, the plugin BANs every known size in one parallel batch — full image, pre-`-scaled` original (WP 5.3+), and every entry under the attachment's `sizes` metadata.
* A top-priority conditional rule narrows the image cache key to URI only, so every visitor reuses the same cached file and your image cache hit rate stays near 100%.

= Edge security & WAF =

* **Web Application Firewall** that blocks known malicious patterns automatically — safe to enable, ruleset maintained by smoxy.
* **Access Rules** for IP, country, user-agent, header or path-based allow/block/challenge decisions.
* **Basic Auth** for staging environments or restricted areas, with per-path overrides.
* **Under Attack Mode** — emergency dial that adds stricter verification during a live DDoS or abuse spike.

All of it lives at the edge, in front of your origin, so attack traffic never reaches WordPress.

= What the plugin does =

* One-shot setup wizard — paste an API token from the smoxy hub, pick (or create) a zone and origin, and the plugin registers your hostname, fetches the BAN secret, and installs the four managed conditional rules.
* Automatic edge-cache invalidation on every content change — posts, comments, terms, menus, widgets, theme switches, customizer saves, plugin (de)activation, site-option changes, WooCommerce product/variation/stock changes, and media uploads.
* **Purge smoxy cache** button in the WordPress admin bar.
* **smoxy → Settings** with Purge all, Purge by URL (works for any cacheable asset), and Purge by tag.
* Audit & one-click repair of the four managed rules if they drift on the hub side.
* `smoxy_*` action hooks so other plugins can join the invalidation pipeline.

= Brands running smoxy =

trigema, ETERNA, Stadt-Parfümerie Pieper, WM24, babyone, Topperz Store, Jeans-Fritz, foun10.

= Learn more =

* Website: https://www.smoxy.eu
* Product docs: https://docs.smoxy.eu
* WordPress integration guide: https://docs.smoxy.eu/developer-guide/wordpress
* Plugin source, contribution guidelines, screenshots: https://github.com/smoxy-eu/wordpress-plugin
* Free account: https://hub.smoxy.eu

== External services ==

This plugin contacts the smoxy edge service. Details:

= smoxy ingress API (`https://ingress.smoxy.eu`) =

Whenever cached content needs to be invalidated — after a post is saved, a comment is approved, a term is changed, the theme is switched, or you trigger a manual purge — the plugin sends an authenticated HTTPS request to `https://ingress.smoxy.eu`.

What is sent with each request:

* The site's `Host` header (host portion of the WordPress home URL), so smoxy knows which Zone the purge applies to.
* Your smoxy secret token in the `secret:` header, for authentication.
* Either a list of cache tags to invalidate, or a `flushall` directive when a full purge is requested.

No personal data, analytics, or telemetry is sent — only what smoxy needs to identify which content to purge.

= Terms and privacy =

By using this plugin you are sending requests to a smoxy-operated service. Review the [smoxy privacy policy](https://www.smoxy.eu/datenschutz) for details on how smoxy handles this data.

== Changelog ==

See [CHANGELOG.md](https://github.com/smoxy-eu/wordpress-plugin/blob/main/CHANGELOG.md) for the full release history.
