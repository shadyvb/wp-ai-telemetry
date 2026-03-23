# wp-ai-telemetry tests

## Prerequisites

- Docker and [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).
- **Standalone clone:** from this plugin directory run `composer install`, `npm ci`, and `npx wp-env start`. The local `.wp-env.json` mounts this plugin and clones **`WordPress/ai-provider-for-openai`** from GitHub (full plugin with `plugin.php`); see `CONTRIBUTING.md`.
- **Monorepo:** you can keep using the parent `.wp-env.json` that mounts `./wp-ai-client` and `./ai-provider-for-openai` as siblings instead of vendor paths.
- A `.env` file in the **plugin root** (next to `composer.json`). Copy `.env.example` and set variables for functional tests (`OPENAI_API_KEY`, `OTLP_ENDPOINT`, `OTLP_AUTH_USERNAME`, `OTLP_AUTH_PASSWORD`, `OTEL_BACKEND_PUBLIC_BASE_URL`).
- **PHPUnit 9.x** + **Yoast PHPUnit Polyfills** (see `composer.json`).

## Commands

From the **plugin directory** (where `composer.json` lives):

```bash
composer install
```

Run PHPUnit **inside** wp-env `tests-cli`:

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-telemetry ./vendor/bin/phpunit -c phpunit.xml.dist --testsuite=unit
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-telemetry ./vendor/bin/phpunit -c phpunit.xml.dist --testsuite=functional
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-telemetry ./vendor/bin/phpunit -c phpunit.xml.dist
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-telemetry ./vendor/bin/phpunit -c tests/phpunit/multisite.xml
```

Telemetry-disabled case (separate bootstrap):

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-telemetry ./vendor/bin/phpunit -c phpunit-disabled.xml.dist
```

Regenerate observation golden files after intentional telemetry or mapping changes:

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-telemetry php tests/bin/record-observations-fixtures.php
# or
composer record-functional-fixtures
```

Then review `git diff tests/fixtures/observations/` and commit.

## Layout

| Path | Role |
|------|------|
| `tests/phpunit/bootstrap.php` | WordPress test bootstrap, `.env` → options, test abilities |
| `tests/phpunit/bootstrap-telemetry-disabled.php` | Same stack, telemetry `enabled` off |
| `tests/phpunit/multisite.xml` | Multisite harness (`@group ms-required`) |
| `tests/phpunit/includes/` | `TestEnv`, observations REST client + normalizer |
| `tests/phpunit/tests/` | PHPUnit test classes |
| `tests/fixtures/observations/*.json` | Normalized observation snapshots |
| `tests/bin/record-observations-fixtures.php` | Refreshes fixtures |

Functional tests hit **real** OpenAI and your OTLP-backed observability stack; they are slow and require valid credentials.

## Tracing / functional tests failing?

1. **`.env` location** — File must be `wp-ai-telemetry/.env` (next to `composer.json`), not the monorepo root. wp-env mounts that folder as `wp-content/plugins/wp-ai-telemetry/`.

2. **All five variables** — `OPENAI_API_KEY`, `OTLP_ENDPOINT`, `OTLP_AUTH_USERNAME`, `OTLP_AUTH_PASSWORD`, `OTEL_BACKEND_PUBLIC_BASE_URL` must be non-empty or the bootstrap throws before tests run.

3. **Langfuse: two different bases**
   - **`OTLP_ENDPOINT`** — Ingestion base (plugin adds `/v1/traces`). Example: `https://cloud.langfuse.com/api/public/otel` (EU) or `https://us.cloud.langfuse.com/api/public/otel` (US).
   - **`OTEL_BACKEND_PUBLIC_BASE_URL`** — API origin for **listing** traces (no path suffix). Example: `https://cloud.langfuse.com` or `https://us.cloud.langfuse.com`.
   - **Auth** — Use Langfuse **public key** as username and **secret key** as password for both OTLP and REST.

4. **Region mismatch** — EU keys with US host URLs (or the reverse) will fail export or REST lookups.

5. **wp-config constants** — If you define `WP_AI_TELEMETRY_*` in a config loaded by the test site, they **override** options merged from `.env` and can break functional tests. Unset or align them with `.env`.

6. **Polling** — Tests poll the public API at most **`ObservationsRestClient::PUBLIC_API_POLL_MAX_ATTEMPTS` (5)** times (2s apart). If Langfuse is slow to index, temporarily raise that constant in `tests/phpunit/includes/ObservationsRestClient.php`.

7. **Manual wp-admin** — For a real site (not PHPUnit), enable telemetry and set the same OTLP base under **Settings → AI Telemetry**; mismatched or empty endpoint disables export.
