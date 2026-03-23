<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests;

/**
 * Normalizes observation REST payloads for stable golden-file comparison.
 *
 * Strips volatile ids, times, costs, latency, and token usage; redacts model output
 * and functional-test correlation markers.
 */
final class ObservationsFixtureNormalizer {

	/**
	 * Keys removed at any nesting level (exact match).
	 */
	private const VOLATILE_KEYS = [
		'id'                   => true,
		'traceId'              => true,
		'parentObservationId'  => true,
		'startTime'            => true,
		'endTime'              => true,
		'createdAt'            => true,
		'updatedAt'            => true,
		'timestamp'            => true,
		'modelId'              => true,
		'promptId'             => true,
		'promptName'           => true,
		'promptVersion'        => true,
		'latency'              => true,
		'timeToFirstToken'     => true,
		'completionStartTime'  => true,
		'sessionId'            => true,
		'workspaceId'          => true,
		'userId'               => true,
		'projectId'            => true,
		'inputPrice'           => true,
		'outputPrice'          => true,
		'totalPrice'           => true,
		'calculatedInputCost'  => true,
		'calculatedOutputCost' => true,
		'calculatedTotalCost'  => true,
		'costDetails'          => true,
		'usage'                => true,
	];

	/**
	 * Attribute keys (dot notation) to remove from metadata.attributes.
	 */
	private const VOLATILE_ATTRIBUTE_PREFIXES = [
		'gen_ai.usage.',
	];

	private const VOLATILE_ATTRIBUTE_KEYS = [
		'wp_ai.duration_ms' => true,
	];

	public const OUTPUT_PLACEHOLDER = '__STRIPPED__';

	public const MARKER_PATTERN = '/\s*Test run id:\s*[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\s*/i';

	/**
	 * @param list<array<string, mixed>> $observations Raw observation rows from API `data` array.
	 */
	public static function normalizeObservationsList( array $observations ): string {
		$clean = [];
		foreach ( $observations as $obs ) {
			if ( is_array( $obs ) ) {
				$clean[] = self::normalizeObservation( $obs );
			}
		}
		usort(
			$clean,
			static function ( array $a, array $b ): int {
				$ta = (string) ( $a['_type'] ?? '' );
				$tb = (string) ( $b['_type'] ?? '' );
				if ( $ta !== $tb ) {
					return $ta <=> $tb;
				}
				$na = (string) ( $a['_name'] ?? '' );
				$nb = (string) ( $b['_name'] ?? '' );
				if ( $na !== $nb ) {
					return $na <=> $nb;
				}
				return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
			}
		);
		foreach ( $clean as $i => $row ) {
			unset( $clean[ $i ]['_type'], $clean[ $i ]['_name'] );
		}
		$json = wp_json_encode( $clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return $json !== false ? $json : '[]';
	}

	/**
	 * @param array<string, mixed> $observation
	 * @return array<string, mixed>
	 */
	public static function normalizeObservation( array $observation ): array {
		$type = (string) ( $observation['type'] ?? '' );
		$name = self::normalizeSpanName( (string) ( $observation['name'] ?? '' ) );

		$meta = [];
		if ( isset( $observation['metadata'] ) && is_array( $observation['metadata'] ) ) {
			$meta = self::normalizeMetadataBlock( $observation['metadata'] );
		}

		$input  = $observation['input'] ?? null;
		$output = $observation['output'] ?? null;

		$input  = self::redactMarkersDeep( $input );
		$output = self::redactGenerationOutput( $type, $output );
		$meta   = self::redactAttributesOutput( $meta );
		if ( $type === 'TOOL' && is_array( $input ) ) {
			$input = self::redactToolInputValues( $input );
		}
		if ( $type === 'TOOL' && $output !== null && $output !== '' ) {
			$output = '__TOOL_OUTPUT__';
		}

		$row = [
			'type'     => $type,
			'name'     => $name,
			'input'    => $input,
			'output'   => $output,
			'metadata' => $meta,
		];

		$row          = self::stripVolatileKeysDeep( $row );
		$row['_type'] = $type;
		$row['_name'] = $name;
		return $row;
	}

	private static function normalizeSpanName( string $name ): string {
		if ( preg_match( '#^chat openai/.+$#', $name ) === 1 ) {
			return 'chat openai/__MODEL__';
		}
		return $name;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	private static function normalizeMetadataBlock( array $metadata ): array {
		$out = $metadata;
		if ( isset( $out['attributes'] ) && is_array( $out['attributes'] ) ) {
			$attrs = $out['attributes'];
			foreach ( [ 'gen_ai.request.model', 'gen_ai.response.model' ] as $model_key ) {
				if ( array_key_exists( $model_key, $attrs ) ) {
					$attrs[ $model_key ] = '__MODEL__';
				}
			}
			if ( array_key_exists( 'gen_ai.conversation.id', $attrs ) ) {
				$attrs['gen_ai.conversation.id'] = '__CONVERSATION_ID__';
			}
			if ( array_key_exists( 'gen_ai.tool.call.id', $attrs ) ) {
				$attrs['gen_ai.tool.call.id'] = '__TOOL_CALL_ID__';
			}
			foreach ( [ 'gen_ai.tool.call.arguments', 'gen_ai.tool.call.result' ] as $tool_attr ) {
				if ( array_key_exists( $tool_attr, $attrs ) ) {
					$attrs[ $tool_attr ] = '__TOOL_ATTR__';
				}
			}
			foreach ( array_keys( $attrs ) as $key ) {
				$ks = (string) $key;
				if ( isset( self::VOLATILE_ATTRIBUTE_KEYS[ $ks ] ) ) {
					unset( $attrs[ $key ] );
					continue;
				}
				foreach ( self::VOLATILE_ATTRIBUTE_PREFIXES as $prefix ) {
					if ( str_starts_with( $ks, $prefix ) ) {
						unset( $attrs[ $key ] );
						break;
					}
				}
			}
			$out['attributes'] = $attrs;
		}
		if ( isset( $out['resourceAttributes'] ) && is_array( $out['resourceAttributes'] ) ) {
			$ra = $out['resourceAttributes'];
			if ( array_key_exists( 'wp.site_url', $ra ) ) {
				$ra['wp.site_url'] = '__WP_SITE_URL__';
			}
			if ( array_key_exists( 'service.version', $ra ) ) {
				$ra['service.version'] = '__WP_VERSION__';
			}
			$out['resourceAttributes'] = $ra;
		}
		if ( isset( $out['scope'] ) && is_array( $out['scope'] ) ) {
			$sc = $out['scope'];
			if ( array_key_exists( 'attributes', $sc ) ) {
				$sa = $sc['attributes'];
				if ( is_array( $sa ) && $sa === [] ) {
					$sc['attributes'] = [];
				} elseif ( is_object( $sa ) && (array) $sa === [] ) {
					$sc['attributes'] = [];
				}
			}
			$out['scope'] = $sc;
		}
		return $out;
	}

	/**
	 * Model-filled tool arguments (e.g. optional "noop") vary per run — stabilize for fixtures.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function redactToolInputValues( array $input ): array {
		$out = [];
		foreach ( $input as $k => $v ) {
			if ( is_string( $v ) ) {
				$out[ $k ] = '__TOOL_ARG__';
			} elseif ( is_array( $v ) ) {
				$out[ $k ] = self::redactToolInputValues( $v );
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $output
	 * @return mixed
	 */
	private static function redactGenerationOutput( string $type, $output ) {
		if ( $type !== 'GENERATION' ) {
			return self::redactMarkersDeep( $output );
		}
		if ( $output === null || $output === '' ) {
			return $output;
		}
		return self::OUTPUT_PLACEHOLDER;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	private static function redactAttributesOutput( array $metadata ): array {
		if ( ! isset( $metadata['attributes'] ) || ! is_array( $metadata['attributes'] ) ) {
			return $metadata;
		}
		$attrs = $metadata['attributes'];
		if ( isset( $attrs['gen_ai.output.messages'] ) ) {
			$attrs['gen_ai.output.messages'] = wp_json_encode(
				[
					[
						'role'    => 'assistant',
						'content' => self::OUTPUT_PLACEHOLDER,
					],
				],
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		}
		$metadata['attributes'] = $attrs;
		return $metadata;
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	private static function stripVolatileKeysDeep( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$is_list = array_is_list( $data );
		$out     = [];
		foreach ( $data as $k => $v ) {
			if ( is_string( $k ) && isset( self::VOLATILE_KEYS[ $k ] ) ) {
				continue;
			}
			$out[ $k ] = self::stripVolatileKeysDeep( $v );
		}
		return $is_list ? array_values( $out ) : $out;
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	private static function redactMarkersDeep( $data ) {
		if ( is_string( $data ) ) {
			$s = preg_replace( self::MARKER_PATTERN, ' ', $data );
			return trim( $s !== null ? $s : $data );
		}
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $k => $v ) {
			$data[ $k ] = self::redactMarkersDeep( $v );
		}
		return $data;
	}

	/**
	 * Asserts the last GENERATION observation (by original order) has non-empty output before normalization.
	 *
	 * @param list<array<string, mixed>> $observations
	 */
	public static function assertFinalGenerationOutputExists( array $observations ): void {
		$generations = array_values(
			array_filter(
				$observations,
				static fn( $o ) => is_array( $o ) && ( ( $o['type'] ?? '' ) === 'GENERATION' )
			)
		);
		if ( $generations !== [] ) {
			usort(
				$generations,
				static function ( array $a, array $b ): int {
					return strcmp( (string) ( $a['startTime'] ?? '' ), (string) ( $b['startTime'] ?? '' ) );
				}
			);
			$last = $generations[ count( $generations ) - 1 ];
			$out  = $last['output'] ?? null;
			if ( self::isNonEmptyOutput( $out ) ) {
				return;
			}
			throw new \RuntimeException( 'Final GENERATION observation has empty output.' );
		}
		foreach ( $observations as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			if ( self::isNonEmptyOutput( $o['output'] ?? null ) ) {
				return;
			}
		}
		throw new \RuntimeException( 'No observation with non-empty output.' );
	}

	private static function isNonEmptyOutput( mixed $out ): bool {
		if ( $out === null || $out === '' ) {
			return false;
		}
		if ( is_string( $out ) ) {
			return trim( $out ) !== '';
		}
		if ( is_array( $out ) ) {
			return $out !== [];
		}
		return true;
	}
}
