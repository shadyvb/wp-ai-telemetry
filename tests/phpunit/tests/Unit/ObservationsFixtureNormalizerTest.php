<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests\Unit;

use WP_AI_Telemetry\Tests\ObservationsFixtureNormalizer;
use WP_UnitTestCase;

class ObservationsFixtureNormalizerTest extends WP_UnitTestCase {

	public function test_strips_volatile_keys_and_normalizes_models(): void {
		$obs = [
			[
				'id'        => 'obs-1',
				'traceId'   => 'trace-1',
				'type'      => 'GENERATION',
				'name'      => 'chat openai/gpt-4o-mini',
				'startTime' => '2026-01-01T00:00:00.000Z',
				'input'     => [
					'messages' => [
						[
							'role'    => 'user',
							'content' => 'Hi',
						],
					],
				],
				'output'    => 'Hello world',
				'metadata'  => [
					'attributes' => [
						'gen_ai.request.model'      => 'gpt-4o-mini',
						'gen_ai.response.model'     => 'gpt-4o-mini',
						'gen_ai.usage.input_tokens' => 10,
						'gen_ai.output.messages'    => '[]',
					],
				],
			],
		];
		ObservationsFixtureNormalizer::assertFinalGenerationOutputExists( $obs );
		$json = ObservationsFixtureNormalizer::normalizeObservationsList( $obs );
		self::assertStringNotContainsString( 'obs-1', $json );
		self::assertStringNotContainsString( 'trace-1', $json );
		self::assertStringNotContainsString( 'gpt-4o-mini', $json );
		self::assertStringContainsString( '__MODEL__', $json );
		self::assertStringContainsString( ObservationsFixtureNormalizer::OUTPUT_PLACEHOLDER, $json );
		self::assertStringNotContainsString( 'Hello world', $json );
	}

	public function test_redacts_test_marker_from_input(): void {
		$marker = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
		$obs    = [
			[
				'type'      => 'GENERATION',
				'name'      => 'chat openai/__MODEL__',
				'startTime' => '2026-01-01T00:00:00.000Z',
				'input'     => [ 'text' => "Hello\n\nTest run id: {$marker}" ],
				'output'    => 'x',
				'metadata'  => [ 'attributes' => [] ],
			],
		];
		$json   = ObservationsFixtureNormalizer::normalizeObservationsList( $obs );
		self::assertStringNotContainsString( $marker, $json );
	}
}
