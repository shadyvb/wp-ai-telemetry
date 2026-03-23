<?php
/**
 * OpenTelemetry TracerProvider factory and OTLP export wiring.
 *
 * @package WP_AI_Telemetry
 */

declare(strict_types=1);

namespace WP_AI_Telemetry\Exporters;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;

/**
 * Factory that builds and globally registers an OTLP-based TracerProvider.
 *
 * Stateless — all methods are static. Use {@see create_and_register()} as the
 * single entry point.
 *
 * @since 0.1.0
 */
class OtlpTracerProvider {

	/**
	 * Milliseconds — batch processor export timeout (SDK default is 30000).
	 */
	private const BATCH_EXPORT_TIMEOUT_MS = 15000;

	/**
	 * Milliseconds — low delay for time-based flush when a batch is not yet full.
	 */
	private const BATCH_SCHEDULE_DELAY_MS = 50;

	/**
	 * Export after each span (batch size 1).
	 *
	 * The SDK default batch size (512) buffers spans until the batch fills or {@see TracerProvider::forceFlush()}.
	 * PHPUnit and other long-lived CLI processes only run PHP shutdown handlers once at process exit, so
	 * functional tests polled Langfuse before any OTLP export — traces appeared only after the suite ended.
	 * Size 1 restores export-on-span-end timing (as with the former simple span processor) while keeping HTTP timeouts
	 * and the batch processor pipeline.
	 */
	private const BATCH_MAX_EXPORT_SPANS = 1;

	/**
	 * Builds a TracerProvider configured for OTLP HTTP protobuf export and registers it globally.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return TracerProvider|null The provider, or null on failure.
	 */
	public static function create_and_register( array $settings ): ?TracerProvider {
		$endpoint = self::resolve_endpoint( $settings );
		if ( $endpoint === null ) {
			return null;
		}
		try {
			$exporter = self::build_exporter( $endpoint, $settings );
			$sampler  = self::build_sampler( $settings );
			$resource = self::build_resource();
			$provider = self::build_provider( $exporter, $sampler, $resource );
			self::register_globally( $provider );
			Logging::disable();
			return $provider;
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						/* translators: %s: exception message. */
						__( 'WP AI Telemetry: Failed to create TracerProvider — %s', 'wp-ai-telemetry' ),
						$e->getMessage()
					)
				);
			}
			return null;
		}
	}

	/**
	 * Resolves and validates the OTLP base endpoint URL from settings.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return string|null Trimmed endpoint URL, or null if absent/invalid.
	 */
	private static function resolve_endpoint( array $settings ): ?string {
		$raw = $settings['otlp_endpoint'] ?? '';
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$endpoint = rtrim( trim( $raw ), '/' );
		if ( $endpoint === '' ) {
			return null;
		}

		// Allow only http/https — blocks file:// and other schemes that could
		// confuse the transport or enable unexpected local access patterns.
		$parsed = wp_parse_url( $endpoint );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] )
			|| ! in_array( strtolower( (string) $parsed['scheme'] ), [ 'http', 'https' ], true ) ) {
			return null;
		}

		return $endpoint;
	}

	/**
	 * Builds the Authorization header array from Basic Auth credentials in settings.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array<string, string> Header map, or empty array if credentials are absent.
	 */
	private static function build_auth_headers( array $settings ): array {
		$pub = $settings['auth_public_key'] ?? '';
		$sec = $settings['auth_secret_key'] ?? '';
		if ( is_string( $pub ) && $pub !== '' && is_string( $sec ) && $sec !== '' ) {
			return [ 'Authorization' => 'Basic ' . base64_encode( $pub . ':' . $sec ) ];
		}
		return [];
	}

	/**
	 * Builds an OTLP HTTP protobuf SpanExporter pointed at the given endpoint.
	 *
	 * JSON uses protobuf-JSON encoding where int64 values are strings e.g.
	 * {"intValue":"42"}, which some OTLP backends mishandle. Protobuf avoids this.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $endpoint Base OTLP endpoint URL (no trailing slash).
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return SpanExporter
	 */
	private static function build_exporter( string $endpoint, array $settings ): SpanExporter {
		$raw     = $settings['otlp_http_timeout'] ?? 10.0;
		$timeout = is_numeric( $raw ) ? (float) $raw : 10.0;

		$transport = ( new OtlpHttpTransportFactory() )->create(
			$endpoint . '/v1/traces',
			ContentTypes::PROTOBUF,
			self::build_auth_headers( $settings ),
			null,
			$timeout
		);

		return new SpanExporter( $transport );
	}

	/**
	 * Builds the resource describing this WordPress site.
	 *
	 * @since 0.1.0
	 *
	 * @return ResourceInfo
	 */
	private static function build_resource(): ResourceInfo {
		$resource_attrs = [
			ServiceAttributes::SERVICE_NAME    => 'wordpress',
			ServiceAttributes::SERVICE_VERSION => get_bloginfo( 'version' ),
		];
		if ( ! defined( 'WP_AI_TELEMETRY_RESOURCE_INCLUDE_SITE_URL' ) || WP_AI_TELEMETRY_RESOURCE_INCLUDE_SITE_URL ) {
			$resource_attrs['wp.site_url'] = get_site_url();
		}

		return ResourceInfoFactory::emptyResource()->merge(
			ResourceInfo::create( Attributes::create( $resource_attrs ) )
		);
	}

	/**
	 * Creates a TracerProvider wired with the given exporter, sampler, and resource.
	 *
	 * @since 0.1.0
	 *
	 * @param SpanExporter     $exporter Configured span exporter.
	 * @param SamplerInterface $sampler  Configured sampler.
	 * @param ResourceInfo     $resource_info Service resource descriptor.
	 * @return TracerProvider
	 */
	private static function build_provider( SpanExporter $exporter, SamplerInterface $sampler, ResourceInfo $resource_info ): TracerProvider {
		$span_processor = new BatchSpanProcessor(
			$exporter,
			Clock::getDefault(),
			BatchSpanProcessor::DEFAULT_MAX_QUEUE_SIZE,
			self::BATCH_SCHEDULE_DELAY_MS,
			self::BATCH_EXPORT_TIMEOUT_MS,
			self::BATCH_MAX_EXPORT_SPANS,
			true,
			null
		);

		return new TracerProvider( $span_processor, $sampler, $resource_info );
	}

	/**
	 * Registers the given TracerProvider as the global SDK instance.
	 *
	 * @since 0.1.0
	 *
	 * @param TracerProvider $provider Provider to register.
	 * @return void
	 */
	private static function register_globally( TracerProvider $provider ): void {
		Sdk::builder()
			->setTracerProvider( $provider )
			->buildAndRegisterGlobal();
	}

	/**
	 * Builds a sampler based on the configured sampling rate.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return SamplerInterface
	 */
	private static function build_sampler( array $settings ): SamplerInterface {
		$raw  = $settings['sampling_rate'] ?? 1.0;
		$rate = is_numeric( $raw ) ? (float) $raw : 1.0;
		$rate = max( 0.0, min( 1.0, $rate ) );
		return new ParentBased( new TraceIdRatioBasedSampler( $rate ) );
	}
}
