<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests\Unit;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use ReflectionMethod;
use WordPress\AiClient\Events\BeforeGenerateResultEvent;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WP_AI_Telemetry\Session;
use WP_AI_Telemetry\Telemetry;
use WP_UnitTestCase;

/**
 * @covers \WP_AI_Telemetry\Telemetry
 */
class TelemetryRootSpanAttributesTest extends WP_UnitTestCase {

	private ReflectionMethod $apply;

	protected function setUp(): void {
		parent::setUp();
		Session::end();
		$this->apply = new ReflectionMethod( Telemetry::class, 'apply_root_span_custom_attributes' );
		$this->apply->setAccessible( true );
	}

	protected function tearDown(): void {
		remove_all_filters( Telemetry::FILTER_ROOT_SPAN_ATTRIBUTES );
		Session::end();
		parent::tearDown();
	}

	public function test_filter_merges_onto_builder(): void {
		$model = $this->createMock( ModelInterface::class );

		$event = new BeforeGenerateResultEvent( [], $model, null );

		$captured = [];
		$builder  = $this->createMock( SpanBuilderInterface::class );
		$builder->method( 'setAttribute' )->willReturnCallback(
			function ( string $k, mixed $v ) use ( &$captured, $builder ) {
				$captured[ $k ] = $v;
				return $builder;
			}
		);

		add_filter(
			Telemetry::FILTER_ROOT_SPAN_ATTRIBUTES,
			static function ( array $attrs ) {
				$attrs['from_filter'] = 'yes';
				return $attrs;
			},
			10,
			1
		);

		$telemetry = new Telemetry( [ 'log_prompts' => true ] );
		$this->apply->invoke(
			$telemetry,
			$builder,
			$event,
			42,
			'chat',
			'chat openai/gpt-4o',
			'openai',
			'gpt-4o'
		);

		self::assertSame( 'yes', $captured['from_filter'] );
	}

	public function test_session_metadata_seed_passed_to_filter(): void {
		Session::start();
		Session::merge_metadata( [ 'wp_ai.seed' => 'session' ] );

		$model = $this->createMock( ModelInterface::class );
		$event = new BeforeGenerateResultEvent( [], $model, null );

		$received = null;
		add_filter(
			Telemetry::FILTER_ROOT_SPAN_ATTRIBUTES,
			static function ( array $attrs ) use ( &$received ) {
				$received = $attrs;
				return $attrs;
			},
			10,
			1
		);

		$builder = $this->createMock( SpanBuilderInterface::class );
		$builder->method( 'setAttribute' )->willReturnSelf();

		$telemetry = new Telemetry( [ 'log_prompts' => true ] );
		$this->apply->invoke(
			$telemetry,
			$builder,
			$event,
			1,
			'chat',
			'x',
			'p',
			'm'
		);

		self::assertIsArray( $received );
		self::assertSame( 'session', $received['wp_ai.seed'] );
	}

	public function test_context_includes_expected_keys(): void {
		$model = $this->createMock( ModelInterface::class );
		$event = new BeforeGenerateResultEvent( [], $model, null );

		$ctx = null;
		add_filter(
			Telemetry::FILTER_ROOT_SPAN_ATTRIBUTES,
			static function ( array $attrs, array $context ) use ( &$ctx ) {
				$ctx = $context;
				return $attrs;
			},
			10,
			2
		);

		$builder = $this->createMock( SpanBuilderInterface::class );
		$builder->method( 'setAttribute' )->willReturnSelf();

		$telemetry = new Telemetry( [ 'log_prompts' => true ] );
		$this->apply->invoke(
			$telemetry,
			$builder,
			$event,
			99,
			'chat',
			'chat p/m',
			'prov',
			'mod'
		);

		self::assertIsArray( $ctx );
		self::assertSame( $event, $ctx['event'] );
		self::assertSame( $model, $ctx['model'] );
		self::assertSame( 99, $ctx['correlation_id'] );
		self::assertTrue( $ctx['is_trace_root'] );
		self::assertSame( 'chat', $ctx['operation'] );
		self::assertSame( 'prov', $ctx['provider_id'] );
		self::assertSame( 'mod', $ctx['request_model_id'] );
		self::assertSame( 'chat p/m', $ctx['span_name'] );
	}

	public function test_filter_throw_does_not_rethrow(): void {
		$model = $this->createMock( ModelInterface::class );
		$event = new BeforeGenerateResultEvent( [], $model, null );

		add_filter(
			Telemetry::FILTER_ROOT_SPAN_ATTRIBUTES,
			static function () {
				throw new \RuntimeException( 'boom' );
			},
			10,
			2
		);

		$builder = $this->createMock( SpanBuilderInterface::class );
		$builder->expects( self::never() )->method( 'setAttribute' );

		$telemetry = new Telemetry( [ 'log_prompts' => true ] );
		$this->apply->invoke(
			$telemetry,
			$builder,
			$event,
			1,
			'chat',
			'x',
			'p',
			'm'
		);
		self::assertTrue( true );
	}
}
