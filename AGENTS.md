# Agent notes — WP AI Telemetry

## Commands (plugin root)

| Task | Command |
|------|---------|
| Install PHP deps | `composer install` (use `--prefer-source` only if you mount `vendor/wordpress/wp-ai-client` as a plugin and need `plugin.php` in that tree) |
| Install Node deps | `npm ci` |
| Start WordPress | `npx wp-env start` |
| Lint | `composer lint` |
| Unit tests (in env) | `npm run test-php -- --testsuite=unit` |
| Multisite smoke | `npm run test-php-multisite` |
| Full PHPUnit (host, needs WP test lib) | `composer test` |

## Filters

| Hook | Default | Purpose |
|------|---------|---------|
| `wp_ai_telemetry_auto_conversation_session` | `true` | When truthy and no session is open, the first instrumented AI call per load calls `Session::start()` with a random id. Return false to only set `gen_ai.conversation.id` when code calls `Session::start()`. Constant: `Telemetry::FILTER_AUTO_CONVERSATION_SESSION`. |
| `wp_ai_telemetry_root_span_attributes` | Session metadata | Root inference span only: `( array $attributes, array $context ): array`. Constant: `Telemetry::FILTER_ROOT_SPAN_ATTRIBUTES`. |

Session: `Session::merge_metadata()` after `Session::start()`; cleared on `start()` / `end()`. See `RootSpanAttributes` for key/value limits.

## Constraints

- Production code lives under `includes/`; bootstrap is `plugin.php`.
- Do **not** edit packages in `vendor/wordpress/` — consume them as dependencies only.
- Keep hook prefix `wp_ai_` / namespace `WP_AI_Telemetry` for new symbols.
- Functional tests require `.env`; default CI runs unit + multisite + telemetry-disabled only.

## wp-env (standalone)

- `.wp-env.json` pins **WordPress 6.9.1** (zip) and lists **`WordPress/ai-provider-for-openai`** in `plugins` so `@wordpress/env` clones the OpenAI provider from GitHub (includes `plugin.php`).

## Layout

- `includes/` — PSR-4 `WP_AI_Telemetry\`
- `tests/phpunit/bootstrap.php` — WordPress test bootstrap
- `tests/phpunit/includes/` — `TestEnv`, observations REST client, fixture normalizer
- `tests/phpunit/tests/` — PHPUnit test classes
