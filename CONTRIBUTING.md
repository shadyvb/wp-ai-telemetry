# Contributing to WP AI Telemetry

**Developing this plugin?** Use this document for cloning the repository, Composer, wp-env, tests, and coding standards. For **installing a packaged release** on a site (download zip, drop into `wp-content/plugins`, configure OTLP), see **[README.md — Quick installation](README.md#quick-installation)**.

## Requirements

- PHP 8.1+
- [Composer](https://getcomposer.org/)
- Node.js 20+ (see `.nvmrc`) and npm
- Docker (for `@wordpress/env`)

## Setup

```bash
cd wp-ai-telemetry
composer install
npm ci
npx wp-env start
```

Use **`composer install --prefer-source`** if you need `vendor/wordpress/wp-ai-client` to include `plugin.php` for mounting that package as a WordPress plugin; the default Composer dist omits it (see that package’s `.gitattributes`). This project’s `.wp-env.json` does **not** mount OpenAI from Composer: **`WordPress/ai-provider-for-openai`** is cloned from GitHub by `@wordpress/env`, which includes `plugin.php`.

After install, **`post-autoload-dump`** runs `composer install` inside `vendor/wordpress/wp-ai-client` so that package has its own `vendor/autoload.php` (required when the plugin is mounted in wp-env). If that directory is missing, run `composer run install-wp-ai-client-vendor`.

**wp-env:** Strings like `WordPress/ai-provider-for-openai` in the `plugins` array are **GitHub** `owner/repo` clones (see [@wordpress/env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)). Paths starting with `.` or `/` are local mounts.

Standalone `.wp-env.json` pins **WordPress 6.9.1** so the OpenAI provider plugin meets its minimum. After changing `core` or `plugins` in `.wp-env.json`, run `printf 'y\\n' | npx wp-env destroy` then `npx wp-env start` so Docker volumes pick up the change.

## Upstream hook parity (Composer patch)

The wrapped SDK `PromptBuilder` was constructed without the WordPress `EventDispatcher`, so `wp_ai_client_before_generate_result` and `wp_ai_client_after_generate_result` did not fire ([wp-ai-client#66](https://github.com/WordPress/wp-ai-client/issues/66)). **This package applies a Composer patch** so the inner builder receives the same dispatcher as the client—see `composer.json` `extra.patches` and `patches/wp-ai-client-prompt-builder-event-dispatcher.patch`. **Upstream fix:** [WordPress/wp-ai-client#67](https://github.com/WordPress/wp-ai-client/pull/67) wires `AiClient::getEventDispatcher()` into `Prompt_Builder`; when that lands in a release you depend on, you can drop the patch from this repo’s Composer config.

## Linting

```bash
composer lint
```

Runs PHPCS (WPCS + PHPCompatibility + WordPressVIPMinimum) and PHPStan (`includes/` only).

## Tests

Inside wp-env `tests-cli` (from plugin root):

```bash
./vendor/bin/phpunit -c phpunit.xml.dist --testsuite=unit
./vendor/bin/phpunit -c phpunit.xml.dist --testsuite=functional   # needs .env secrets
./vendor/bin/phpunit -c tests/phpunit/multisite.xml
./vendor/bin/phpunit -c phpunit-disabled.xml.dist
```

Or from the host using npm (after `wp-env start`):

```bash
npm run test-php -- --testsuite=unit
npm run test-php-multisite
```

### Functional / OTLP

Functional tests call OpenAI and verify traces via HTTP against your configured backend (e.g. Langfuse public API: `/api/public/traces`, `/api/public/observations`). Copy `.env.example` to `.env` and set `OPENAI_API_KEY`, `OTLP_*`, and `OTEL_BACKEND_PUBLIC_BASE_URL`. Without secrets, run only the **unit** suite in CI; functional tests are for local or secret-configured workflows.

Polling is capped at **`ObservationsRestClient::PUBLIC_API_POLL_MAX_ATTEMPTS` (5)** rounds (2s apart); the test fails if data is still missing. Trace search uses at most **`PUBLIC_TRACES_LIST_MAX_PAGES` (5)** list pages per poll. Raise these constants only if your backend is consistently slower.

### Recording fixtures

After intentional telemetry or normalization changes:

```bash
composer record-functional-fixtures
```

## Development (quick reference)

After [Setup](#setup):

```bash
composer lint
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-telemetry ./vendor/bin/phpunit -c phpunit.xml.dist
```

Functional tests need a `.env` file (see `.env.example`) with API keys and OTLP/backend URLs. Default **CI** runs a **WordPress × PHP matrix** (see `.github/workflows/ci.yml`), plus a **non-blocking** trunk/nightly job: **unit**, **snapshot** (offline placeholder), **multisite**, and **telemetry-disabled** only—no live functional suite or secrets.

## Cutting a release

Push an **annotated** tag whose target commit is on **`main`**, matching `v*` (e.g. `v0.2.0`). **[`.github/workflows/release.yml`](.github/workflows/release.yml)** runs `composer install --no-dev`, asserts **`vendor/wordpress`** is absent, zips `plugin.php`, `includes/`, `vendor/`, `README.md`, and `LICENSE` under `wp-ai-telemetry/`, and publishes **`wp-ai-telemetry.zip`** on a GitHub Release. Optional: put release notes in the tag message; otherwise the workflow fills the body from `git log` since the previous `v*` tag.

## How to contribute

1. Open an issue or PR on [GitHub](https://github.com/shadyvb/wp-ai-telemetry/issues).
2. Follow this document for coding standards (WPCS + VIP minimum), linting, and tests.
3. Keep production code under `includes/` with namespace `WP_AI_Telemetry\` and hook prefix `wp_ai_` for new WordPress APIs.

Maintained by [Shady Sharaf](https://github.com/shadyvb). If you have run [Stream](https://wordpress.org/plugins/stream/) on WordPress—the activity log that records *who did what, when, in wp-admin*—the philosophy here is familiar: **durable, queryable evidence** of behavior on the site, extended to **AI traffic** in a vendor-neutral, OTel-shaped form.

## Coding standards

- WordPress Coding Standards with PSR-4 file names (`includes/`).
- VIP minimum ruleset is enabled; some test-only paths are excluded in `phpcs.xml`.
- Target WordPress 6.0+.

## Dependency versions (dev)

Composer `require-dev` pins `wordpress/wp-ai-client` for the test stack. The OpenAI provider is **not** a Composer dependency; wp-env installs **`WordPress/ai-provider-for-openai`** as a plugin. Bump the wp-ai-client constraint when you need newer AI client APIs.
