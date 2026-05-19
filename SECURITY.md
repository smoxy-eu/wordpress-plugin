# Security Policy

We take security of the smoxy Proxy WordPress plugin seriously. Thank you for helping keep our users safe.

## Reporting a vulnerability

**Do not open a public GitHub issue for security vulnerabilities.** Public issues are indexed and can be exploited before a fix ships.

Instead, report privately through **GitHub Private Vulnerability Reporting**:

➡️ <https://github.com/smoxy-eu/wordpress-plugin/security/advisories/new>

This creates a private advisory that only repository maintainers can see. You'll need a GitHub account.

### What to include

A useful report has:

- The affected version(s) — plugin version, WordPress version, PHP version.
- A clear description of the issue and its impact (what an attacker can do).
- Reproduction steps, ideally with a minimal proof of concept.
- Any suggested mitigation or patch, if you have one.

The more concrete the report, the faster we can validate and fix.

## What to expect

- **Acknowledgement:** within 5 business days of receiving the report.
- **Triage:** we'll confirm whether we can reproduce the issue and classify severity.
- **Fix timeline:** depends on severity — critical issues are patched as quickly as possible; lower-severity issues are bundled into the next regular release.
- **Coordinated disclosure:** we aim to publish a fix and a security advisory within **90 days** of the initial report. We'll keep you updated and credit you in the advisory unless you prefer to stay anonymous.
- **CVE assignment:** GitHub Security Advisories can request a CVE on our behalf when appropriate.

## Supported versions

Only the latest minor release line receives security fixes.

| Version  | Status              |
| -------- | ------------------- |
| `0.1.x`  | ✅ Supported         |
| `< 0.1`  | ❌ Not supported     |

If you're running an unsupported version, the fix is to upgrade.

## Scope

In scope:

- The PHP code in this repository (`smoxy.php`, `src/`, `uninstall.php`).
- The CI workflows under `.github/workflows/` insofar as they could enable supply-chain attacks.

Out of scope:

- Vulnerabilities in WordPress core itself — report those to <https://hackerone.com/wordpress>.
- Vulnerabilities in the smoxy edge service (`ingress.smoxy.eu`, hub, etc.) — report those through the product channels at <https://www.smoxy.eu>.
- Issues that require attacker-controlled WordPress admin access (e.g., "an admin can break the plugin"); WordPress admins are already trusted.
- Denial of service caused by intentionally malformed requests with valid admin credentials.

## Safe-harbor

We will not pursue legal action against researchers who:

- Make a good-faith effort to follow this policy.
- Avoid privacy violations, data destruction, and service degradation.
- Give us a reasonable opportunity to fix the issue before public disclosure.

Thank you for helping keep smoxy and our users safe.
