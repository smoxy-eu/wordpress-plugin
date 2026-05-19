=== smoxy Proxy ===
Contributors: smoxy
Tags: cache, cdn, performance, purge, edge-cache
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Connects WordPress to smoxy Proxy. Keeps the edge cache in sync — purging pages automatically when content changes.

== Description ==

Full documentation, screenshots, and contribution guidelines live in the project README on GitHub:
https://github.com/smoxy-eu/wordpress-plugin

Product docs: https://docs.smoxy.eu

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
