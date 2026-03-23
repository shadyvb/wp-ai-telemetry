<?php
/**
 * PHPUnit bootstrap for wp-ai-telemetry functional tests.
 *
 * Requires wp-env `tests-cli` (or a WordPress test library) with WP_TESTS_DIR set.
 * Run from repo root, e.g.:
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-telemetry ./vendor/bin/phpunit -c phpunit.xml.dist
 *
 * @package WP_AI_Telemetry\Tests
 */

define( 'WP_AI_TELEMETRY_TESTS_DIR', dirname( __DIR__ ) );
define( 'WP_AI_TELEMETRY_PLUGIN_DIR', dirname( __DIR__, 2 ) );

// Allow real HTTP for OpenAI, OTLP export, and observations REST (PHPUnit xml alone does not run for record script / ad-hoc CLI).
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
	define( 'WP_HTTP_BLOCK_EXTERNAL', false );
}

require_once WP_AI_TELEMETRY_PLUGIN_DIR . '/vendor/autoload.php';
require_once WP_AI_TELEMETRY_PLUGIN_DIR . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

if ( ! \WP_AI_Telemetry\Tests\TestEnv::isUnitOrMultisiteOnlyRun() ) {
	\WP_AI_Telemetry\Tests\TestEnv::requireKeysForFunctional();
}

// Prime options before WordPress loads (WP test harness merges wp_tests_options).
$GLOBALS['wp_tests_options'] = array_merge(
	(array) ( $GLOBALS['wp_tests_options'] ?? [] ),
	[
		'active_plugins'                    => [
			'ai-provider-for-openai/plugin.php',
			'wp-ai-telemetry/plugin.php',
		],
		'wp_ai_client_provider_credentials' => \WP_AI_Telemetry\Tests\TestEnv::openAiCredentialsOption(),
		'wp_ai_telemetry_settings'          => \WP_AI_Telemetry\Tests\TestEnv::wpAiTelemetrySettings( true ),
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

tests_add_filter(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'wp-ai-telemetry-tests',
			[
				'label'       => 'WP AI Telemetry tests',
				'description' => 'Abilities for functional OTLP trace regression tests.',
			]
		);
	}
);

tests_add_filter(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'wp-ai-telemetry-tests/return-constant',
			[
				'label'               => 'Return constant',
				'description'         => 'Returns a fixed string for tool-call tests.',
				'category'            => 'wp-ai-telemetry-tests',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'noop' => [
							'type'        => 'string',
							'description' => 'Unused; OpenAI requires a non-empty properties map.',
						],
					],
				],
				'execute_callback'    => static function (): string {
					return 'forty-two';
				},
			]
		);

		wp_register_ability(
			'wp-ai-telemetry-tests/add-numbers',
			[
				'label'               => 'Add numbers',
				'description'         => 'Returns the sum of two integers.',
				'category'            => 'wp-ai-telemetry-tests',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'a' => [
							'type'        => 'integer',
							'description' => 'First operand',
						],
						'b' => [
							'type'        => 'integer',
							'description' => 'Second operand',
						],
					],
					'required'   => [ 'a', 'b' ],
				],
				'execute_callback'    => static function ( array $input ): int {
					return (int) $input['a'] + (int) $input['b'];
				},
			]
		);
	}
);

require $wp_tests_dir . '/includes/bootstrap.php';
