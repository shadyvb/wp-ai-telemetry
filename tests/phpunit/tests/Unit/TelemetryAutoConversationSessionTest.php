<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests\Unit;

use ReflectionMethod;
use WP_AI_Telemetry\Session;
use WP_AI_Telemetry\Telemetry;
use WP_UnitTestCase;

/**
 * @covers \WP_AI_Telemetry\Telemetry
 */
class TelemetryAutoConversationSessionTest extends WP_UnitTestCase {

	private ReflectionMethod $ensure;

	protected function setUp(): void {
		parent::setUp();
		Session::end();
		$this->ensure = new ReflectionMethod( Telemetry::class, 'maybe_auto_start_conversation_session' );
		$this->ensure->setAccessible( true );
	}

	protected function tearDown(): void {
		remove_all_filters( Telemetry::FILTER_AUTO_CONVERSATION_SESSION );
		Session::end();
		parent::tearDown();
	}

	private function invoke_ensure( Telemetry $telemetry ): void {
		$this->ensure->invoke( $telemetry );
	}

	public function test_filter_false_leaves_session_null(): void {
		add_filter( Telemetry::FILTER_AUTO_CONVERSATION_SESSION, '__return_false' );
		$telemetry = new Telemetry( [ 'log_prompts' => true ] );
		$this->invoke_ensure( $telemetry );
		self::assertNull( Session::get_id() );
	}

	public function test_default_starts_random_session(): void {
		$telemetry = new Telemetry( [ 'log_prompts' => true ] );
		$this->invoke_ensure( $telemetry );
		$id = Session::get_id();
		self::assertNotNull( $id );
		self::assertSame( 32, strlen( (string) $id ) );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', (string) $id );
	}

	public function test_preserves_existing_session(): void {
		Session::start( 'my-fixed-session' );
		$telemetry = new Telemetry( [ 'log_prompts' => true ] );
		$this->invoke_ensure( $telemetry );
		self::assertSame( 'my-fixed-session', Session::get_id() );
	}

	public function test_second_invoke_does_not_replace_id(): void {
		$telemetry = new Telemetry( [ 'log_prompts' => true ] );
		$this->invoke_ensure( $telemetry );
		$first = Session::get_id();
		$this->invoke_ensure( $telemetry );
		self::assertSame( $first, Session::get_id() );
	}
}
