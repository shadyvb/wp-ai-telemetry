<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests\Unit;

use WP_AI_Telemetry\Session;
use WP_UnitTestCase;

/**
 * @covers \WP_AI_Telemetry\Session
 */
class SessionMetadataTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		Session::end();
		parent::tearDown();
	}

	public function test_set_and_get_metadata(): void {
		Session::start();
		Session::merge_metadata(
			[
				'wp_ai.workflow' => 'demo',
				'count'          => 3,
				'pi'             => 1.25,
				'flag'           => true,
			]
		);
		$meta = Session::get_metadata();
		self::assertSame( 'demo', $meta['wp_ai.workflow'] );
		self::assertSame( 3, $meta['count'] );
		self::assertSame( 1.25, $meta['pi'] );
		self::assertTrue( $meta['flag'] );
	}

	public function test_ignores_invalid_types_in_set_metadata(): void {
		Session::start();
		Session::merge_metadata(
			[
				'ok' => 'yes',
				'no' => [ 1 ],
			]
		);
		self::assertSame( [ 'ok' => 'yes' ], Session::get_metadata() );
	}

	public function test_start_clears_metadata(): void {
		Session::start();
		Session::merge_metadata( [ 'a' => 1 ] );
		Session::start();
		self::assertSame( [], Session::get_metadata() );
	}

	public function test_end_clears_metadata(): void {
		Session::start();
		Session::merge_metadata( [ 'a' => 1 ] );
		Session::end();
		self::assertSame( [], Session::get_metadata() );
	}

	public function test_get_metadata_returns_copy(): void {
		Session::start();
		Session::merge_metadata( [ 'a' => 1 ] );
		$one      = Session::get_metadata();
		$one['a'] = 99;
		self::assertSame( 1, Session::get_metadata()['a'] );
	}
}
