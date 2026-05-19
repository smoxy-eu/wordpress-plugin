# Smoxy Proxy plugin — developer tasks.
#
# Run `make` (no target) for a list of commands.
#
# Release flow:
#   make release-patch   # 0.1.0 -> 0.1.1
#   make release-minor   # 0.1.0 -> 0.2.0
#   make release-major   # 0.1.0 -> 1.0.0
#
# Each release-* target bumps the version string in the three places
# that must stay in lockstep (smoxy.php header, SMOXY_VERSION
# constant, readme.txt "Stable tag:"), commits the bump, creates an
# annotated `vX.Y.Z` tag, and pushes both the commit and the tag.
# Pushing the tag fires the GitHub Actions release workflow, which
# builds the distributable zip + tar.gz and publishes them to a
# GitHub Release of the same name.

SHELL := /usr/bin/env bash
.SHELLFLAGS := -eu -o pipefail -c

.DEFAULT_GOAL := help

CURRENT_VERSION := $(shell awk '/^[[:space:]]*\*[[:space:]]*Version:/ { print $$3; exit }' smoxy.php)

##@ General

.PHONY: help
help: ## Show this help.
	@awk 'BEGIN { \
		FS = ":.*##"; \
		printf "Smoxy Proxy plugin — Makefile targets\n\nCurrent version: $(CURRENT_VERSION)\n\nUsage:\n  make \033[36m<target>\033[0m\n"; \
	} \
	/^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 } \
	/^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)

##@ Development

.PHONY: install
install: ## Install composer dependencies.
	composer install --no-interaction --prefer-dist

.PHONY: test
test: ## Run PHPUnit (requires `composer install` and a configured WP test env).
	vendor/bin/phpunit --colors=always

.PHONY: lint
lint: ## Run phpstan + phpcs.
	vendor/bin/phpstan analyse --no-progress
	vendor/bin/phpcs -q --report=full

##@ Release

.PHONY: release-patch
release-patch: ## Bump patch version (X.Y.Z -> X.Y.Z+1), tag, push.
	@$(MAKE) --no-print-directory _release BUMP=patch

.PHONY: release-minor
release-minor: ## Bump minor version (X.Y.Z -> X.Y+1.0), tag, push.
	@$(MAKE) --no-print-directory _release BUMP=minor

.PHONY: release-major
release-major: ## Bump major version (X.Y.Z -> X+1.0.0), tag, push.
	@$(MAKE) --no-print-directory _release BUMP=major

# Internal: shared release implementation. Not exposed in help (no `##`).
.PHONY: _release
_release:
	@if [ -z "$(CURRENT_VERSION)" ]; then \
		echo "error: could not parse current version from smoxy.php"; exit 1; \
	fi
	@if [ -n "$$(git status --porcelain)" ]; then \
		echo "error: working tree is dirty — commit or stash before releasing"; \
		git status --short; \
		exit 1; \
	fi
	@BRANCH=$$(git rev-parse --abbrev-ref HEAD); \
	if [ "$$BRANCH" != "main" ]; then \
		echo "error: releases must be cut from main (current branch: $$BRANCH)"; exit 1; \
	fi
	@git fetch --tags --quiet
	@CURRENT="$(CURRENT_VERSION)"; \
	IFS=. read -r MAJOR MINOR PATCH <<<"$$CURRENT"; \
	case "$(BUMP)" in \
		major) NEW="$$((MAJOR + 1)).0.0" ;; \
		minor) NEW="$$MAJOR.$$((MINOR + 1)).0" ;; \
		patch) NEW="$$MAJOR.$$MINOR.$$((PATCH + 1))" ;; \
		*) echo "error: unknown BUMP=$(BUMP)"; exit 1 ;; \
	esac; \
	if git rev-parse "v$$NEW" >/dev/null 2>&1; then \
		echo "error: tag v$$NEW already exists"; exit 1; \
	fi; \
	echo "Releasing $$CURRENT -> $$NEW"; \
	sed -i.bak -E "s/^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).+/\1$$NEW/" smoxy.php; \
	sed -i.bak -E "s/(define\([[:space:]]*'SMOXY_VERSION',[[:space:]]*')[^']+('[[:space:]]*\);)/\1$$NEW\2/" smoxy.php; \
	sed -i.bak -E "s/^(Stable tag:[[:space:]]*).+/\1$$NEW/" readme.txt; \
	rm -f smoxy.php.bak readme.txt.bak; \
	if ! grep -qE "^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*$$NEW$$" smoxy.php \
	   || ! grep -qE "SMOXY_VERSION',[[:space:]]*'$$NEW'" smoxy.php \
	   || ! grep -qE "^Stable tag:[[:space:]]*$$NEW$$" readme.txt; then \
		echo "error: version rewrite did not produce expected output"; \
		git checkout -- smoxy.php readme.txt; exit 1; \
	fi; \
	git add smoxy.php readme.txt; \
	git commit -m "chore(release): v$$NEW"; \
	git tag -a "v$$NEW" -m "Release v$$NEW"; \
	git push origin main; \
	git push origin "v$$NEW"; \
	echo; \
	echo "Released v$$NEW. GitHub Actions will build and publish the artifacts."
