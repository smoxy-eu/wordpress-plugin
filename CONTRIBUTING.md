# Contributing to smoxy

Thanks for your interest in improving the smoxy WordPress plugin! This document explains how to set up a local development environment, the conventions we follow, and how changes get shipped.

By participating you agree to abide by our [Code of Conduct](CODE_OF_CONDUCT.md).

## Getting help and reporting issues

- **Bug reports & feature requests:** open a [GitHub Issue](https://github.com/smoxy-eu/wordpress-plugin/issues). Please include WordPress version, PHP version, and steps to reproduce.
- **Security issues:** do **not** open a public issue. Follow the process in [`SECURITY.md`](SECURITY.md).
- **Product questions** (smoxy itself, accounts, billing): see <https://hub.smoxy.eu> and <https://docs.smoxy.eu>.

## Development environment

You need:

- PHP 8.0 or newer
- Composer 2.x
- A local MySQL 5.7+/8.0 (only needed for PHPUnit; the test runner script bootstraps the WordPress test library)
- WP-CLI (optional, but recommended for the `plugin-check` workflow)

Clone and install:

```bash
git clone git@github.com:smoxy-eu/wordpress-plugin.git
cd wordpress-plugin
make install        # composer install
```

Common targets — run `make` with no arguments for the full list:

| Target              | What it does                                                       |
| ------------------- | ------------------------------------------------------------------ |
| `make install`      | `composer install`                                                 |
| `make lint`         | Run PHPStan (level 8) and WPCS (`phpcs`)                           |
| `make test`         | Run PHPUnit (requires the WP test suite — see below)               |
| `make release-*`    | Bump the version, tag, and push (maintainers only — see *Releasing*) |

### Running the test suite locally

The PHPUnit suite uses the canonical `wp-cli/scaffold` bootstrap. Install the WordPress test library once per environment:

```bash
bash tests/bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
```

Then:

```bash
make test
```

CI runs the full PHP × WP matrix on every push and pull request — see the badges at the top of the [README](README.md).

## Coding standards

- **PSR-4** autoloading from `src/` under the `Smoxy\WP\` namespace.
- **PHPStan level 8** must pass — config in [`phpstan.neon.dist`](phpstan.neon.dist).
- **WordPress Coding Standards (`WordPress-Extra`)** with the project overrides in [`phpcs.xml.dist`](phpcs.xml.dist). Run `vendor/bin/phpcs` (read-only) or `vendor/bin/phpcbf` (auto-fix) before pushing.
- **PHP 8.0 compatibility** — `PHPCompatibilityWP` runs in CI and must stay green.
- **Text domain:** all user-facing strings go through WordPress i18n (`__()`, `_e()`, etc.) with the `smoxy` text domain.
- **Don't refactor adjacent code in unrelated PRs.** Match existing style, even if you'd do it differently. Every changed line should trace back to the PR's purpose.

## Pull request checklist

Before opening a PR:

1. The branch is rebased on the latest `main`.
2. `make lint` is green.
3. `make test` is green (or you've explained why a test failure is unrelated).
4. New behavior has a corresponding test in `tests/phpunit/`.
5. User-facing changes are noted in [`CHANGELOG.md`](CHANGELOG.md) under the `## [Unreleased]` section.
6. The PR description explains the *why*, not just the *what*.

## Commit style

We follow a light [Conventional Commits](https://www.conventionalcommits.org/) flavor:

- `feat:` — new user-visible behavior
- `fix:` — bug fix
- `chore:` — tooling, deps, repo plumbing
- `docs:` — docs only
- `refactor:` — internal change, no behavior delta
- `test:` — test-only change
- `ci:` — CI config

Examples from the repo history:

```
ci(plugin-check): wait for db TCP readiness before wp-cli calls
docs(readme): bump minimum WP to 6.0 and note tested up to 6.9
```

## Releasing (maintainers)

Releases are fully automated by the `Makefile` + GitHub Actions:

1. Make sure `main` is green, your working tree is clean, and you're on `main`.
2. Choose a bump:
   ```bash
   make release-patch   # 0.1.0 -> 0.1.1
   make release-minor   # 0.1.0 -> 0.2.0
   make release-major   # 0.1.0 -> 1.0.0
   ```
3. The target will:
   - Rewrite the version in `smoxy.php` (header + `SMOXY_VERSION` constant) and `readme.txt` (`Stable tag:`)
   - Commit (`chore(release): vX.Y.Z`)
   - Create an annotated tag (`vX.Y.Z`)
   - Push the commit and the tag to `origin`
4. The push of the tag triggers `.github/workflows/release.yml`, which builds `smoxy-X.Y.Z.zip` + `smoxy-X.Y.Z.tar.gz`, generates `SHA256SUMS.txt`, and publishes a GitHub Release.

Before releasing, move the `## [Unreleased]` entries in [`CHANGELOG.md`](CHANGELOG.md) under a new `## [X.Y.Z] - YYYY-MM-DD` heading and commit that change.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
