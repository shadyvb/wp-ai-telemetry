<?php
/**
 * Merges stored options with defaults and wp-config.php constant overrides.
 *
 * @package WP_AI_Telemetry
 */

declare(strict_types=1);

namespace WP_AI_Telemetry;

/**
 * Resolves effective plugin settings for runtime and exporters.
 *
 * Constants (all optional) override values from the database when defined:
 * - WP_AI_TELEMETRY_ENABLED (bool)
 * - WP_AI_TELEMETRY_OTLP_ENDPOINT (non-empty string)
 * - WP_AI_TELEMETRY_OTLP_AUTH_USER (string)
 * - WP_AI_TELEMETRY_OTLP_AUTH_SECRET (string) — prefer over DB for secrets
 * - WP_AI_TELEMETRY_SAMPLING_RATE (float 0.0–1.0)
 * - WP_AI_TELEMETRY_LOG_PROMPTS (bool)
 * - WP_AI_TELEMETRY_OTLP_HTTP_TIMEOUT (float seconds, > 0)
 *
 * @since 0.2.0
 */
final class Settings {

	/**
	 * WordPress option key for stored settings.
	 */
	public const OPTION_KEY = 'wp_ai_telemetry_settings';

	/**
	 * Default OTLP HTTP client timeout in seconds (transport layer).
	 */
	private const DEFAULT_OTLP_HTTP_TIMEOUT = 10.0;

	/**
	 * Maps wp-config.php constant names to settings keys and their expected types.
	 * Supported types: 'bool', 'non_empty_string', 'string', 'numeric_float', 'positive_clamped_float'.
	 *
	 * @var array<string, array{key: string, type: string}>
	 */
	private const CONSTANT_OVERRIDES = [
		'WP_AI_TELEMETRY_ENABLED'           => [
			'key'  => 'enabled',
			'type' => 'bool',
		],
		'WP_AI_TELEMETRY_OTLP_ENDPOINT'     => [
			'key'  => 'otlp_endpoint',
			'type' => 'non_empty_string',
		],
		'WP_AI_TELEMETRY_OTLP_AUTH_USER'    => [
			'key'  => 'auth_public_key',
			'type' => 'string',
		],
		'WP_AI_TELEMETRY_OTLP_AUTH_SECRET'  => [
			'key'  => 'auth_secret_key',
			'type' => 'string',
		],
		'WP_AI_TELEMETRY_SAMPLING_RATE'     => [
			'key'  => 'sampling_rate',
			'type' => 'numeric_float',
		],
		'WP_AI_TELEMETRY_LOG_PROMPTS'       => [
			'key'  => 'log_prompts',
			'type' => 'bool',
		],
		'WP_AI_TELEMETRY_OTLP_HTTP_TIMEOUT' => [
			'key'  => 'otlp_http_timeout',
			'type' => 'positive_clamped_float',
		],
	];

	/**
	 * Returns settings merged from options, defaults, and wp-config constants.
	 *
	 * @since 0.2.0
	 *
	 * @phpstan-return array{
	 *     enabled: bool,
	 *     otlp_endpoint: string,
	 *     auth_public_key: string,
	 *     auth_secret_key: string,
	 *     log_prompts: bool,
	 *     sampling_rate: float,
	 *     otlp_http_timeout: float,
	 * }
	 */
	public static function get_merged(): array {
		$stored_raw = get_option( self::OPTION_KEY, [] );
		$stored     = is_array( $stored_raw ) ? self::only_string_keys( $stored_raw ) : [];

		$defaults = [
			'enabled'           => false,
			'otlp_endpoint'     => '',
			'auth_public_key'   => '',
			'auth_secret_key'   => '',
			'log_prompts'       => true,
			'sampling_rate'     => 1.0,
			'otlp_http_timeout' => self::DEFAULT_OTLP_HTTP_TIMEOUT,
		];

		$settings = array_merge( $defaults, $stored );

		// Clamp timeout to valid range regardless of source.
		if ( isset( $settings['otlp_http_timeout'] ) && is_numeric( $settings['otlp_http_timeout'] ) ) {
			$settings['otlp_http_timeout'] = max( 0.5, min( 120.0, (float) $settings['otlp_http_timeout'] ) );
		} else {
			$settings['otlp_http_timeout'] = self::DEFAULT_OTLP_HTTP_TIMEOUT;
		}

		$settings = self::apply_constant_overrides( $settings );

		return self::coerce_merged_settings( $settings );
	}

	/**
	 * Drops non-string keys so merged options stay a string-keyed map for PHPStan and callers.
	 *
	 * @param array<mixed> $arr Raw array from get_option().
	 * @return array<string, mixed>
	 */
	private static function only_string_keys( array $arr ): array {
		$out = [];
		foreach ( $arr as $key => $value ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Normalizes merged settings to the runtime shape (options + constants may leave mixed types in the bag).
	 *
	 * @param array<string, mixed> $settings Settings after defaults, DB merge, timeout clamp, and constant overrides.
	 * @phpstan-return array{
	 *     enabled: bool,
	 *     otlp_endpoint: string,
	 *     auth_public_key: string,
	 *     auth_secret_key: string,
	 *     log_prompts: bool,
	 *     sampling_rate: float,
	 *     otlp_http_timeout: float,
	 * }
	 */
	private static function coerce_merged_settings( array $settings ): array {
		$timeout_raw = $settings['otlp_http_timeout'] ?? self::DEFAULT_OTLP_HTTP_TIMEOUT;
		$timeout     = is_numeric( $timeout_raw )
			? max( 0.5, min( 120.0, (float) $timeout_raw ) )
			: self::DEFAULT_OTLP_HTTP_TIMEOUT;

		$rate_raw = $settings['sampling_rate'] ?? 1.0;
		$rate     = is_numeric( $rate_raw ) ? (float) $rate_raw : 1.0;

		return [
			'enabled'           => (bool) ( $settings['enabled'] ?? false ),
			'otlp_endpoint'     => isset( $settings['otlp_endpoint'] ) && is_string( $settings['otlp_endpoint'] )
				? $settings['otlp_endpoint']
				: '',
			'auth_public_key'   => isset( $settings['auth_public_key'] ) && is_string( $settings['auth_public_key'] )
				? $settings['auth_public_key']
				: '',
			'auth_secret_key'   => isset( $settings['auth_secret_key'] ) && is_string( $settings['auth_secret_key'] )
				? $settings['auth_secret_key']
				: '',
			'log_prompts'       => (bool) ( $settings['log_prompts'] ?? true ),
			'sampling_rate'     => $rate,
			'otlp_http_timeout' => $timeout,
		];
	}

	/**
	 * Applies wp-config.php constant overrides to the settings array.
	 *
	 * @param array<string, mixed> $settings Settings with defaults and stored options merged (timeout clamped).
	 * @return array<string, mixed>
	 */
	private static function apply_constant_overrides( array $settings ): array {
		foreach ( self::CONSTANT_OVERRIDES as $constant => $spec ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$value = self::cast_override_value( constant( $constant ), $spec['type'] );
			if ( null !== $value ) {
				$settings[ $spec['key'] ] = $value;
			}
		}

		return $settings;
	}

	/**
	 * Casts a raw constant value to the appropriate type for a settings key.
	 *
	 * Returns null when the value does not satisfy the type constraint and
	 * should therefore not override the existing setting.
	 *
	 * @param mixed  $raw  Raw constant value.
	 * @param string $type One of: 'bool', 'non_empty_string', 'string', 'numeric_float', 'positive_clamped_float'.
	 * @return mixed Cast value, or null if the override should be skipped.
	 */
	private static function cast_override_value( mixed $raw, string $type ): mixed {
		switch ( $type ) {
			case 'bool':
				return (bool) $raw;

			case 'non_empty_string':
				if ( ! is_string( $raw ) ) {
					return null;
				}
				$trimmed = trim( $raw );
				return $trimmed !== '' ? $trimmed : null;

			case 'string':
				return is_string( $raw ) ? $raw : null;

			case 'numeric_float':
				return is_numeric( $raw ) ? (float) $raw : null;

			case 'positive_clamped_float':
				if ( ! is_numeric( $raw ) || (float) $raw <= 0.0 ) {
					return null;
				}
				return max( 0.5, min( 120.0, (float) $raw ) );

			default:
				return null;
		}
	}
}
