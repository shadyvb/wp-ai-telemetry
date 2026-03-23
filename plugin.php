<?php
/**
 * Plugin Name: WP AI Telemetry
 * Plugin URI:  https://github.com/shadyvb/wp-ai-telemetry
 * Description: Logs WP AI Client calls (requests, responses, tokens, cost, timing) to OpenTelemetry-compatible backends via OTLP.
 * Version:     0.1.0
 * Author:      Shady Sharaf
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-telemetry
 * Domain Path: /languages
 *
 * @package WP_AI_Telemetry
 */

declare(strict_types=1);

namespace WP_AI_Telemetry;

use OpenTelemetry\SDK\Trace\TracerProvider as OtelTracerProvider;

defined( 'ABSPATH' ) || exit;

defined( 'WP_AI_TELEMETRY_VERSION' ) || define( 'WP_AI_TELEMETRY_VERSION', '0.1.0' );
defined( 'WP_AI_TELEMETRY_DIR' ) || define( 'WP_AI_TELEMETRY_DIR', plugin_dir_path( __FILE__ ) );
defined( 'WP_AI_TELEMETRY_FILE' ) || define( 'WP_AI_TELEMETRY_FILE', __FILE__ );

/**
 * Loads Composer / bundled dependencies and registers WordPress hooks for this plugin.
 */
function bootstrap_plugin(): void {
	$dir = WP_AI_TELEMETRY_DIR;

	if ( file_exists( $dir . 'vendor/autoload.php' ) ) {
		require_once $dir . 'vendor/autoload.php';
	}

	maybe_load_standalone_ai_client();

	add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );
}

/**
 * Loads the bundled WP AI Client if it is not yet available as a standalone plugin.
 *
 * @todo Remove once WP AI Client ships with WordPress core.
 * @see https://github.com/WordPress/wp-ai-client/commit/6e65c8423e96a4f5237e852838a08a54c420d765
 */
function maybe_load_standalone_ai_client(): void {
	$dir = WP_AI_TELEMETRY_DIR;

	if ( class_exists( \WordPress\AI_Client\AI_Client::class, false ) ) {
		return;
	}

	if ( ! file_exists( $dir . 'vendor/wordpress/wp-ai-client/autoload.php' ) ) {
		return;
	}

	require_once $dir . 'vendor/wordpress/wp-ai-client/autoload.php';
	add_action( 'init', [ \WordPress\AI_Client\AI_Client::class, 'init' ] );
}

/**
 * Initializes the plugin.
 */
function init(): void {
	init_admin();
	load_plugin_textdomain(
		'wp-ai-telemetry',
		false,
		dirname( plugin_basename( WP_AI_TELEMETRY_FILE ) ) . '/languages'
	);

	if ( ! class_exists( \OpenTelemetry\SDK\Trace\TracerProvider::class ) ) {
		return;
	}

	$settings = Settings::get_merged();

	if ( empty( $settings['enabled'] ) ) {
		return;
	}

	$provider = Exporters\OtlpTracerProvider::create_and_register( $settings );
	if ( $provider === null ) {
		return;
	}

	$telemetry = new Telemetry( $settings );
	$telemetry->register_hooks();

	register_shutdown_handlers( $provider, $telemetry );
}

/**
 * Initializes the admin UI when in the admin context.
 */
function init_admin(): void {
	if ( is_admin() ) {
		Admin::init();
	}
}

/**
 * Registers the WordPress shutdown action and PHP shutdown function to flush spans.
 *
 * @param OtelTracerProvider $provider  The tracer provider to flush on shutdown.
 * @param Telemetry          $telemetry The telemetry instance to clean up on shutdown.
 */
function register_shutdown_handlers( OtelTracerProvider $provider, Telemetry $telemetry ): void {
	// End of each web request: drain the span batch so OTLP sees traces before the worker handles another request.
	add_action(
		'shutdown',
		static function () use ( $provider ): void {
			safe_flush( $provider );
		},
		9999
	);

	register_shutdown_function(
		static function () use ( $provider, $telemetry ): void {
			try {
				$telemetry->shutdown_cleanup();
			} catch ( \Throwable ) {
				// Never crash the host — telemetry failures must be silent.
			}
			safe_flush( $provider );
		}
	);
}

/**
 * Flushes the span batch, swallowing any export errors.
 *
 * Telemetry failures must never crash the host application.
 *
 * @param OtelTracerProvider $provider The provider to flush.
 */
function safe_flush( OtelTracerProvider $provider ): void {
	try {
		$provider->forceFlush();
	} catch ( \Throwable ) {
		// Never crash the host application on telemetry export failure.
	}
}

bootstrap_plugin();
