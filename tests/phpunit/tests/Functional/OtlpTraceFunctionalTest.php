<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests\Functional;

use WP_AI_Telemetry\Session;
use WP_AI_Telemetry\Tests\ObservationsFixtureNormalizer;
use WP_AI_Telemetry\Tests\ObservationsRestClient;
use WP_AI_Telemetry\Tests\Support\AgenticPromptRunner;
use WP_UnitTestCase;

/**
 * @group functional
 * @group otlp
 */
class OtlpTraceFunctionalTest extends WP_UnitTestCase {

	private ObservationsRestClient $observations_client;

	protected function setUp(): void {
		parent::setUp();
		$admins = get_users(
			[
				'role'   => 'administrator',
				'number' => 1,
			]
		);
		self::assertNotEmpty( $admins, 'Need an administrator user in the test database.' );
		wp_set_current_user( $admins[0]->ID );
		$this->observations_client = new ObservationsRestClient();
		Session::end();
	}

	protected function tearDown(): void {
		Session::end();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_s1_single_turn_openai_matches_fixture(): void {
		$marker = wp_generate_uuid4();
		$prompt = AgenticPromptRunner::withTestMarker( 'Reply with exactly the word: pong', $marker );
		$result = AgenticPromptRunner::runSingleTurnText( $prompt, 'openai' );
		$this->assertGeneratesString( $result );
		$obs = $this->waitForObservationsByMarker( $marker );
		ObservationsFixtureNormalizer::assertFinalGenerationOutputExists( $obs );
		$this->assertMatchesFixture( 's1-single-turn.json', $obs );
	}

	public function test_s2_single_ability_openai_matches_fixture(): void {
		$marker = wp_generate_uuid4();
		$prompt = AgenticPromptRunner::withTestMarker(
			'You must call the return_constant tool once (no arguments). Then reply using only the tool output, one word.',
			$marker
		);
		$result = AgenticPromptRunner::runWithAbilities(
			$prompt,
			'openai',
			[ 'wp-ai-telemetry-tests/return-constant' ]
		);
		$this->assertGeneratesString( $result );
		$obs = $this->waitForObservationsByMarker( $marker );
		ObservationsFixtureNormalizer::assertFinalGenerationOutputExists( $obs );
		$this->assertMatchesFixture( 's2-one-ability.json', $obs );
	}

	public function test_s3_two_abilities_openai_matches_fixture(): void {
		$marker = wp_generate_uuid4();
		$prompt = AgenticPromptRunner::withTestMarker(
			'Use the add_numbers tool with a=3 and b=5 only. Reply with the single integer result, digits only.',
			$marker
		);
		$result = AgenticPromptRunner::runWithAbilities(
			$prompt,
			'openai',
			[
				'wp-ai-telemetry-tests/return-constant',
				'wp-ai-telemetry-tests/add-numbers',
			]
		);
		$this->assertGeneratesString( $result );
		$obs = $this->waitForObservationsByMarker( $marker );
		ObservationsFixtureNormalizer::assertFinalGenerationOutputExists( $obs );
		$this->assertMatchesFixture( 's3-two-abilities.json', $obs );
	}

	public function test_s4_session_two_single_turns_match_fixture(): void {
		$session_id = 'functional-s4-' . wp_generate_uuid4();
		Session::start( $session_id );
		$m1 = wp_generate_uuid4();
		$m2 = wp_generate_uuid4();
		$r1 = AgenticPromptRunner::runSingleTurnText(
			AgenticPromptRunner::withTestMarker( 'Reply with exactly: alpha', $m1 ),
			'openai'
		);
		$this->assertGeneratesString( $r1 );
		$r2 = AgenticPromptRunner::runSingleTurnText(
			AgenticPromptRunner::withTestMarker( 'Reply with exactly: beta', $m2 ),
			'openai'
		);
		$this->assertGeneratesString( $r2 );
		Session::end();
		// Two separate exports; the REST index may surface the first observation before the second.
		$obs = $this->waitForObservationsByConversation( $session_id, 2 );
		self::assertNotEmpty( $obs );
		ObservationsFixtureNormalizer::assertFinalGenerationOutputExists( $obs );
		$this->assertMatchesFixture( 's4-session-two-calls.json', $obs );
	}

	public function test_s5_session_agentic_matches_fixture(): void {
		$session_id = 'functional-s5-' . wp_generate_uuid4();
		Session::start( $session_id );
		$marker = wp_generate_uuid4();
		$result = AgenticPromptRunner::runWithAbilities(
			AgenticPromptRunner::withTestMarker(
				'Call return_constant once, then answer with that constant only.',
				$marker
			),
			'openai',
			[ 'wp-ai-telemetry-tests/return-constant' ]
		);
		$this->assertGeneratesString( $result );
		Session::end();
		$obs = $this->waitForObservationsByConversation( $session_id );
		self::assertNotEmpty( $obs );
		ObservationsFixtureNormalizer::assertFinalGenerationOutputExists( $obs );
		$this->assertMatchesFixture( 's5-session-agentic.json', $obs );
	}

	/**
	 * @param list<array<string, mixed>> $observations
	 */
	private function assertMatchesFixture( string $fixture_file, array $observations ): void {
		$path = dirname( __DIR__, 4 ) . '/tests/fixtures/observations/' . $fixture_file;
		self::assertFileExists( $path, 'Missing fixture: ' . $fixture_file . ' — run composer record-functional-fixtures' );
		$expected = rtrim( (string) file_get_contents( $path ), "\n" );
		$actual   = ObservationsFixtureNormalizer::normalizeObservationsList( $observations );
		self::assertTrue(
			$expected === $actual,
			'Normalized observations mismatch for ' . $fixture_file
				. ' (expected bytes ' . strlen( $expected ) . ', actual ' . strlen( $actual ) . ')'
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function waitForObservationsByMarker( string $marker ): array {
		$max = ObservationsRestClient::PUBLIC_API_POLL_MAX_ATTEMPTS;
		for ( $i = 0; $i < $max; $i++ ) {
			$trace_id = $this->observations_client->findTraceIdByMarker( $marker );
			if ( $trace_id !== null ) {
				$obs = $this->observations_client->fetchObservationsByTraceId( $trace_id );
				if ( $obs !== [] ) {
					return $obs;
				}
			}
			sleep( 2 );
		}
		self::fail(
			sprintf(
				'No exported trace with marker after %d poll attempts (Langfuse / public API).',
				$max
			)
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function waitForObservationsByConversation( string $conversation_id, int $min_count = 1 ): array {
		$max = ObservationsRestClient::PUBLIC_API_POLL_MAX_ATTEMPTS;
		for ( $i = 0; $i < $max; $i++ ) {
			$obs = $this->observations_client->collectObservationsByConversationId( $conversation_id );
			if ( count( $obs ) >= $min_count ) {
				return $obs;
			}
			sleep( 2 );
		}
		self::fail(
			sprintf(
				'Fewer than %d observations for conversation id after %d poll attempts (Langfuse / public API).',
				$min_count,
				$max
			)
		);
	}

	/**
	 * @param list<array<string, mixed>> $observations
	 */
	private function assertGeneratesString( mixed $value ): void {
		if ( $value instanceof \WP_Error ) {
			self::fail( $value->get_error_message() );
		}
		self::assertIsString( $value );
	}
}
