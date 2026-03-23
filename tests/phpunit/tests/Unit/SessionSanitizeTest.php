<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests\Unit;

use WP_AI_Telemetry\Session;
use WP_UnitTestCase;

/**
 * @covers \WP_AI_Telemetry\Session
 */
class SessionSanitizeTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		Session::end();
		parent::tearDown();
	}

	public function test_strips_control_characters_from_custom_id(): void {
		Session::start( "ab\x00c\td" );
		self::assertSame( 'abcd', Session::get_id() );
	}

	public function test_caps_custom_id_length(): void {
		$long = str_repeat( 'a', 400 );
		Session::start( $long );
		self::assertLessThanOrEqual( 256, strlen( (string) Session::get_id() ) );
	}

	public function test_empty_after_sanitization_falls_back_to_random(): void {
		Session::start( "\x00\x01" );
		$id = Session::get_id();
		self::assertNotNull( $id );
		self::assertSame( 32, strlen( (string) $id ) );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', (string) $id );
	}
}
