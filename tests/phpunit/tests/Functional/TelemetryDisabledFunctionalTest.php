<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests\Functional;

use WP_UnitTestCase;

/**
 * Telemetry plugin active but disabled — no WP AI Client hooks registered.
 *
 * @group functional
 */
class TelemetryDisabledFunctionalTest extends WP_UnitTestCase {

	public function test_wp_ai_client_telemetry_hooks_not_registered_when_disabled(): void {
		self::assertFalse(
			(bool) has_action( 'wp_ai_client_before_generate_result' ),
			'Expected no handlers on wp_ai_client_before_generate_result when telemetry is disabled.'
		);
		self::assertFalse(
			(bool) has_action( 'wp_ai_client_after_generate_result' ),
			'Expected no handlers on wp_ai_client_after_generate_result when telemetry is disabled.'
		);
	}
}
