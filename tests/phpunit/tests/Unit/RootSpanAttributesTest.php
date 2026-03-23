<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests\Unit;

use WP_AI_Telemetry\RootSpanAttributes;
use WP_UnitTestCase;

/**
 * @covers \WP_AI_Telemetry\RootSpanAttributes
 */
class RootSpanAttributesTest extends WP_UnitTestCase {

	public function test_drops_gen_ai_prefix_keys(): void {
		$out = RootSpanAttributes::sanitize(
			[
				'gen_ai.foo' => 'x',
				'my.key'     => 'ok',
			]
		);
		self::assertSame( [ 'my.key' => 'ok' ], $out );
	}

	public function test_keeps_wp_ai_keys(): void {
		$out = RootSpanAttributes::sanitize(
			[
				'wp_ai.workflow' => 'checkout',
			]
		);
		self::assertSame( [ 'wp_ai.workflow' => 'checkout' ], $out );
	}

	public function test_accepts_scalar_types(): void {
		$out = RootSpanAttributes::sanitize(
			[
				's' => 'a',
				'i' => 7,
				'f' => 1.5,
				'b' => false,
			]
		);
		self::assertSame( 'a', $out['s'] );
		self::assertSame( 7, $out['i'] );
		self::assertSame( 1.5, $out['f'] );
		self::assertFalse( $out['b'] );
	}

	public function test_drops_invalid_types(): void {
		$out = RootSpanAttributes::sanitize(
			[
				'ok'   => 'yes',
				'bad'  => [ 1 ],
				'bad2' => new \stdClass(),
			]
		);
		self::assertSame( [ 'ok' => 'yes' ], $out );
	}

	public function test_caps_key_count_sorted(): void {
		$in = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$in[ 'k' . sprintf( '%02d', $i ) ] = $i;
		}
		$out = RootSpanAttributes::sanitize( $in );
		self::assertCount( RootSpanAttributes::MAX_KEYS, $out );
		self::assertArrayHasKey( 'k00', $out );
		self::assertArrayHasKey( 'k15', $out );
		self::assertArrayNotHasKey( 'k16', $out );
	}

	public function test_truncates_long_strings(): void {
		$long    = str_repeat( 'x', RootSpanAttributes::MAX_STRING_BYTES + 100 );
		$out     = RootSpanAttributes::sanitize( [ 'k' => $long ] );
		$trimmed = $out['k'];
		self::assertLessThanOrEqual( RootSpanAttributes::MAX_STRING_BYTES, strlen( $trimmed ) );
		self::assertStringContainsString( 'truncated', $trimmed );
	}

	public function test_drops_empty_string_keys(): void {
		$out = RootSpanAttributes::sanitize(
			[
				''   => 'should-be-dropped',
				'ok' => 'kept',
			] 
		);
		self::assertSame( [ 'ok' => 'kept' ], $out );
	}
}
