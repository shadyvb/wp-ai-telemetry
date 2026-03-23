<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests\Multisite;

use WP_UnitTestCase;

/**
 * @group ms-required
 */
class MultisiteSmokeTest extends WP_UnitTestCase {

	public function test_multisite_bootstraps_with_telemetry_plugin_loaded(): void {
		$this->assertTrue( is_multisite(), 'WordPress test harness should run with WP_MULTISITE enabled.' );
		$this->assertTrue(
			defined( 'WP_AI_TELEMETRY_VERSION' ),
			'WP AI Telemetry plugin should be loaded (bootstrap defines WP_AI_TELEMETRY_VERSION).'
		);
	}
}
