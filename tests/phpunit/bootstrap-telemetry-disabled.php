<?php
/**
 * PHPUnit bootstrap: telemetry plugin active but OTLP + hooks disabled (S6).
 *
 * @package WP_AI_Telemetry\Tests
 */

define( 'WP_AI_TELEMETRY_TESTS_DIR', dirname( __DIR__ ) );
define( 'WP_AI_TELEMETRY_PLUGIN_DIR', dirname( __DIR__, 2 ) );

if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
	define( 'WP_HTTP_BLOCK_EXTERNAL', false );
}

require_once WP_AI_TELEMETRY_PLUGIN_DIR . '/vendor/autoload.php';
require_once WP_AI_TELEMETRY_PLUGIN_DIR . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

\WP_AI_Telemetry\Tests\TestEnv::loadDotenv();

$GLOBALS['wp_tests_options'] = array_merge(
	(array) ( $GLOBALS['wp_tests_options'] ?? [] ),
	[
		'active_plugins'                    => [
			'ai-provider-for-openai/plugin.php',
			'wp-ai-telemetry/plugin.php',
		],
		'wp_ai_client_provider_credentials' => [
			'openai' => ( static function (): string {
				$key = getenv( 'OPENAI_API_KEY' );
				return ( is_string( $key ) && $key !== '' ) ? $key : 'dummy-key-not-used';
			} )(),
		],
		'wp_ai_telemetry_settings'          => \WP_AI_Telemetry\Tests\TestEnv::telemetryDisabledSettings(),
	]
);

if ( false !== getenv( 'WP_TESTS_DIR' ) ) {
	$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
} elseif ( false !== getenv( 'WP_DEVELOP_DIR' ) ) {
	$wp_tests_dir = getenv( 'WP_DEVELOP_DIR' ) . '/tests/phpunit';
} else {
	$wp_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $wp_tests_dir . '/includes/functions.php';
require $wp_tests_dir . '/includes/bootstrap.php';
