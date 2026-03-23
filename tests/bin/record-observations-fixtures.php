#!/usr/bin/env php
<?php
/**
 * Regenerate tests/fixtures/observations/*.json from a live OTLP-backed project.
 *
 * Run inside wp-env tests-cli from the plugin directory, e.g. from monorepo root:
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-ai-telemetry php tests/bin/record-observations-fixtures.php
 *
 * @package WP_AI_Telemetry\Tests
 */

declare(strict_types=1);

$plugin_root = dirname( __DIR__, 2 );
require $plugin_root . '/tests/phpunit/bootstrap.php';

$admins = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
	]
);
if ( empty( $admins ) ) {
	fwrite( STDERR, "No administrator user in test DB.\n" );
	exit( 1 );
}
wp_set_current_user( $admins[0]->ID );

$client      = new \WP_AI_Telemetry\Tests\ObservationsRestClient();
$fixture_dir = $plugin_root . '/tests/fixtures/observations';
if ( ! is_dir( $fixture_dir ) && ! mkdir( $fixture_dir, 0755, true ) && ! is_dir( $fixture_dir ) ) {
	fwrite( STDERR, "Cannot create {$fixture_dir}\n" );
	exit( 1 );
}

$scenarios = [
	's1-single-turn.json'       => static function () use ( $client ): array {
		$marker = wp_generate_uuid4();
		$prompt = \WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::withTestMarker( 'Reply with exactly the word: pong', $marker );
		$r      = \WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::runSingleTurnText( $prompt, 'openai' );
		if ( is_wp_error( $r ) ) {
			throw new RuntimeException( $r->get_error_message() );
		}
		return wait_observations( $client, $marker );
	},
	's2-one-ability.json'       => static function () use ( $client ): array {
		$marker = wp_generate_uuid4();
		$prompt = \WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::withTestMarker(
			'You must call the return_constant tool once (no arguments). Then reply using only the tool output, one word.',
			$marker
		);
		$r      = \WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::runWithAbilities(
			$prompt,
			'openai',
			[ 'wp-ai-telemetry-tests/return-constant' ]
		);
		if ( is_wp_error( $r ) ) {
			throw new RuntimeException( $r->get_error_message() );
		}
		return wait_observations( $client, $marker );
	},
	's3-two-abilities.json'     => static function () use ( $client ): array {
		$marker = wp_generate_uuid4();
		$prompt = \WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::withTestMarker(
			'Use the add_numbers tool with a=3 and b=5 only. Reply with the single integer result, digits only.',
			$marker
		);
		$r      = \WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::runWithAbilities(
			$prompt,
			'openai',
			[
				'wp-ai-telemetry-tests/return-constant',
				'wp-ai-telemetry-tests/add-numbers',
			]
		);
		if ( is_wp_error( $r ) ) {
			throw new RuntimeException( $r->get_error_message() );
		}
		return wait_observations( $client, $marker );
	},
	's4-session-two-calls.json' => static function () use ( $client ): array {
		\WP_AI_Telemetry\Session::start( 'functional-session-s4-' . wp_generate_uuid4() );
		$m1 = wp_generate_uuid4();
		$m2 = wp_generate_uuid4();
		$r1 = \WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::runSingleTurnText(
			\WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::withTestMarker( 'Reply with exactly: alpha', $m1 ),
			'openai'
		);
		if ( is_wp_error( $r1 ) ) {
			throw new RuntimeException( $r1->get_error_message() );
		}
		$r2 = \WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::runSingleTurnText(
			\WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::withTestMarker( 'Reply with exactly: beta', $m2 ),
			'openai'
		);
		if ( is_wp_error( $r2 ) ) {
			throw new RuntimeException( $r2->get_error_message() );
		}
		$sid = \WP_AI_Telemetry\Session::get_id();
		\WP_AI_Telemetry\Session::end();
		if ( ! is_string( $sid ) ) {
			throw new RuntimeException( 'Session id missing' );
		}
		return wait_conversation( $client, $sid );
	},
	's5-session-agentic.json'   => static function () use ( $client ): array {
		\WP_AI_Telemetry\Session::start( 'functional-session-s5-' . wp_generate_uuid4() );
		$marker = wp_generate_uuid4();
		$r      = \WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::runWithAbilities(
			\WP_AI_Telemetry\Tests\Support\AgenticPromptRunner::withTestMarker(
				'Call return_constant once, then answer with that constant only.',
				$marker
			),
			'openai',
			[ 'wp-ai-telemetry-tests/return-constant' ]
		);
		if ( is_wp_error( $r ) ) {
			throw new RuntimeException( $r->get_error_message() );
		}
		$sid = \WP_AI_Telemetry\Session::get_id();
		\WP_AI_Telemetry\Session::end();
		if ( ! is_string( $sid ) ) {
			throw new RuntimeException( 'Session id missing' );
		}
		return wait_conversation( $client, $sid );
	},
];

foreach ( $scenarios as $filename => $fn ) {
	echo "Recording {$filename}...\n";
	\WP_AI_Telemetry\Session::end();
	$obs = $fn();
	\WP_AI_Telemetry\Tests\ObservationsFixtureNormalizer::assertFinalGenerationOutputExists( $obs );
	$out  = \WP_AI_Telemetry\Tests\ObservationsFixtureNormalizer::normalizeObservationsList( $obs );
	$path = $fixture_dir . '/' . $filename;
	if ( file_put_contents( $path, $out . "\n" ) === false ) {
		fwrite( STDERR, "Failed to write {$path}\n" );
		exit( 1 );
	}
	echo "Wrote {$path}\n";
	sleep( 3 );
}

echo "\nDone. Review diffs and commit updated JSON fixtures.\n";

/**
 * @return list<array<string, mixed>>
 */
function wait_observations( \WP_AI_Telemetry\Tests\ObservationsRestClient $client, string $marker ): array {
	$max = \WP_AI_Telemetry\Tests\ObservationsRestClient::PUBLIC_API_POLL_MAX_ATTEMPTS;
	for ( $i = 0; $i < $max; $i++ ) {
		$trace_id = $client->findTraceIdByMarker( $marker );
		if ( $trace_id !== null ) {
			$obs = $client->fetchObservationsByTraceId( $trace_id );
			if ( $obs !== [] ) {
				return $obs;
			}
		}
		sleep( 2 );
	}
	throw new RuntimeException(
		sprintf( 'No trace for marker after %d poll attempts (Langfuse / public API).', $max )
	);
}

/**
 * @return list<array<string, mixed>>
 */
function wait_conversation( \WP_AI_Telemetry\Tests\ObservationsRestClient $client, string $conversation_id ): array {
	$max = \WP_AI_Telemetry\Tests\ObservationsRestClient::PUBLIC_API_POLL_MAX_ATTEMPTS;
	for ( $i = 0; $i < $max; $i++ ) {
		$obs = $client->collectObservationsByConversationId( $conversation_id );
		if ( $obs !== [] ) {
			return $obs;
		}
		sleep( 2 );
	}
	throw new RuntimeException(
		sprintf( 'No conversation observations after %d poll attempts (Langfuse / public API).', $max )
	);
}
