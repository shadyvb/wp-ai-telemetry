<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests;

use Dotenv\Dotenv;

/**
 * Loads .env from the plugin root and exposes required keys for functional tests.
 */
final class TestEnv {

	private static bool $loaded = false;

	/**
	 * @var array<string, string>
	 */
	private static array $required = [
		'OPENAI_API_KEY',
		'OTLP_ENDPOINT',
		'OTLP_AUTH_USERNAME',
		'OTLP_AUTH_PASSWORD',
		'OTEL_BACKEND_PUBLIC_BASE_URL',
	];

	public static function pluginRoot(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Whether PHPUnit was started in a mode that does not hit OpenAI or the OTLP backend.
	 */
	public static function isUnitOrMultisiteOnlyRun(): bool {
		$argv = $_SERVER['argv'] ?? [];
		for ( $i = 0, $n = count( $argv ); $i < $n; $i++ ) {
			$a = $argv[ $i ];
			if ( $a === '--testsuite' && isset( $argv[ $i + 1 ] ) && $argv[ $i + 1 ] === 'unit' ) {
				return true;
			}
			if ( strncmp( $a, '--testsuite=', 12 ) === 0 && substr( $a, 12 ) === 'unit' ) {
				return true;
			}
			if ( $a === '-c' && isset( $argv[ $i + 1 ] ) && str_contains( (string) $argv[ $i + 1 ], 'multisite.xml' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function loadDotenv(): void {
		if ( self::$loaded ) {
			return;
		}
		$root = self::pluginRoot();
		if ( is_readable( $root . '/.env' ) ) {
			Dotenv::createImmutable( $root )->load();
		}
		self::$loaded = true;
	}

	public static function envString( string $key ): string {
		self::loadDotenv();
		$v = $_ENV[ $key ] ?? $_SERVER[ $key ] ?? getenv( $key );
		if ( $v === false || $v === null ) {
			return '';
		}
		return (string) $v;
	}

	public static function requireKeysForFunctional(): void {
		self::loadDotenv();
		$missing = [];
		foreach ( self::$required as $key ) {
			if ( self::envString( $key ) === '' ) {
				$missing[] = $key;
			}
		}
		if ( $missing !== [] ) {
			throw new \RuntimeException(
				'Missing required environment variables for functional tests: ' . implode( ', ', $missing )
				. '. Copy .env.example to .env in the plugin root and set values.'
			);
		}
	}

	/**
	 * OTLP HTTP base URL for the plugin (OtlpTracerProvider appends `/v1/traces`).
	 */
	public static function otlpEndpoint(): string {
		self::loadDotenv();
		return rtrim( self::envString( 'OTLP_ENDPOINT' ), '/' );
	}

	/**
	 * Base URL for the backend’s public HTTP API used to list traces/observations in functional tests.
	 * Not part of OTLP; must match whatever service exposes `/api/public/traces` and `/api/public/observations`.
	 */
	public static function otelBackendPublicBaseUrl(): string {
		self::loadDotenv();
		return rtrim( self::envString( 'OTEL_BACKEND_PUBLIC_BASE_URL' ), '/' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function wpAiTelemetrySettings( bool $enabled = true ): array {
		self::loadDotenv();
		return [
			'enabled'         => $enabled,
			'otlp_endpoint'   => self::otlpEndpoint(),
			'auth_public_key' => self::envString( 'OTLP_AUTH_USERNAME' ),
			'auth_secret_key' => self::envString( 'OTLP_AUTH_PASSWORD' ),
			'log_prompts'     => true,
			'sampling_rate'   => 1.0,
		];
	}

	/**
	 * @return array<string, string>
	 */
	public static function openAiCredentialsOption(): array {
		self::loadDotenv();
		return [
			'openai' => self::envString( 'OPENAI_API_KEY' ),
		];
	}

	/**
	 * HTTP Basic Authorization value for OTLP export and for the public traces/observations API in functional tests.
	 *
	 * Functional tests require non-empty `OTLP_AUTH_USERNAME` / `OTLP_AUTH_PASSWORD` (see `requireKeysForFunctional()`).
	 */
	public static function otlpBasicAuthHeader(): string {
		self::loadDotenv();
		$user = self::envString( 'OTLP_AUTH_USERNAME' );
		$pass = self::envString( 'OTLP_AUTH_PASSWORD' );
		return 'Basic ' . base64_encode( $user . ':' . $pass );
	}

	/**
	 * Settings merged with defaults when telemetry must be off (no OTLP credentials required).
	 *
	 * @return array<string, mixed>
	 */
	public static function telemetryDisabledSettings(): array {
		return [
			'enabled'         => false,
			'otlp_endpoint'   => '',
			'auth_public_key' => '',
			'auth_secret_key' => '',
			'log_prompts'     => true,
			'sampling_rate'   => 1.0,
		];
	}
}
