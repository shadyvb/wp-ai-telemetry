<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests;

/**
 * HTTP client for functional tests: fetches traces and observations via REST.
 *
 * Uses `/api/public/traces` and `/api/public/observations` under the configured
 * base URL (HTTP Basic auth; required for the functional test harness). Backends that expose this shape can be used to verify
 * exported OTLP data end-to-end; it is not part of the OTLP specification.
 */
final class ObservationsRestClient {

	/**
	 * How many times functional tests poll traces/observations (e.g. Langfuse public API) before failing.
	 */
	public const PUBLIC_API_POLL_MAX_ATTEMPTS = 5;

	/**
	 * Max paginated `/api/public/traces` requests per {@see findTraceIdByMarker()} call (100 traces per page).
	 */
	public const PUBLIC_TRACES_LIST_MAX_PAGES = 5;

	private string $base_url;

	private string $auth_header;

	public function __construct( ?string $base_url = null, ?string $auth_header = null ) {
		$this->base_url    = $base_url ?? TestEnv::otelBackendPublicBaseUrl();
		$this->auth_header = $auth_header ?? TestEnv::otlpBasicAuthHeader();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function fetchObservationsByTraceId( string $trace_id ): array {
		// Public API caps `limit` at 100.
		$url  = $this->base_url . '/api/public/observations?' . http_build_query(
			[
				'traceId' => $trace_id,
				'limit'   => 100,
			]
		);
		$data = $this->request_json( $url );
		$list = $data['data'] ?? [];
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : [];
	}

	/**
	 * Locates a trace id by searching recent traces for a correlation marker string.
	 *
	 * Uses the traces API (not observations): user text including the marker appears on
	 * the trace `input` payload; observation rows may not embed the full prompt verbatim.
	 */
	public function findTraceIdByMarker( string $marker, ?int $max_pages = null ): ?string {
		$max_pages = $max_pages ?? self::PUBLIC_TRACES_LIST_MAX_PAGES;
		for ( $page = 1; $page <= $max_pages; $page++ ) {
			$url  = $this->base_url . '/api/public/traces?' . http_build_query(
				array_filter(
					[
						'limit'   => 100,
						'page'    => $page > 1 ? $page : null,
						'orderBy' => 'timestamp.desc',
					]
				)
			);
			$data = $this->request_json( $url );
			$list = $data['data'] ?? [];
			if ( ! is_array( $list ) || $list === [] ) {
				break;
			}
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$encoded = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( is_string( $encoded ) && str_contains( $encoded, $marker ) ) {
					$tid = $row['id'] ?? null;
					if ( is_string( $tid ) ) {
						return $tid;
					}
				}
			}
			if ( count( $list ) < 100 ) {
				break;
			}
		}
		return null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function collectObservationsByConversationId( string $conversation_id, int $max_pages = 5 ): array {
		$matches = [];
		$page    = 1;
		for ( $p = 0; $p < $max_pages; $p++ ) {
			$url  = $this->base_url . '/api/public/observations?' . http_build_query(
				array_filter(
					[
						'limit'   => 100,
						'page'    => $page > 1 ? $page : null,
						'orderBy' => 'startTime.desc',
					]
				)
			);
			$data = $this->request_json( $url );
			$list = $data['data'] ?? [];
			if ( ! is_array( $list ) || $list === [] ) {
				break;
			}
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$attrs = $row['metadata']['attributes'] ?? null;
				if ( ! is_array( $attrs ) ) {
					continue;
				}
				$cid = $attrs['gen_ai.conversation.id'] ?? null;
				if ( $cid === $conversation_id ) {
					$matches[] = $row;
				}
			}
			++$page;
		}
		return array_values( $matches );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function request_json( string $url ): array {
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 60,
				'headers' => [
					'Authorization' => $this->auth_header,
					'Accept'        => 'application/json',
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Observations REST HTTP error: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException(
				sprintf( 'Observations REST API HTTP %d: %s', $code, mb_substr( $body, 0, 500 ) )
			);
		}
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Observations REST API returned non-JSON body.' );
		}
		return $decoded;
	}
}
