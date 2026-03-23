<?php
/**
 * Sanitizes custom span attributes for root inference spans.
 *
 * @package WP_AI_Telemetry
 */

declare(strict_types=1);

namespace WP_AI_Telemetry;

/**
 * Stateless utility — call {@see sanitize()} to normalize and validate attribute maps before OTLP export.
 *
 * Enforces type, key count, namespace, and string size limits.
 *
 * @since 0.2.0
 */
final class RootSpanAttributes {

	/**
	 * Maximum number of custom attribute keys after merge (deterministic truncation).
	 *
	 * @since 0.2.0
	 */
	public const MAX_KEYS = 16;

	/**
	 * Maximum UTF-8 byte length for string values.
	 *
	 * @since 0.2.0
	 */
	public const MAX_STRING_BYTES = 2048;

	/**
	 * Key prefixes blocked from custom attributes (reserved for GenAI semantic conventions).
	 *
	 * @var non-empty-string[]
	 */
	private const BLOCKED_PREFIXES = [ 'gen_ai.' ];

	/**
	 * Normalizes and caps a merged attribute map for safe export.
	 *
	 * Drops keys starting with `gen_ai.`, non-string keys, empty keys, and values
	 * that are not string|int|float|bool. Sorts keys alphabetically and keeps the
	 * first {@see MAX_KEYS} entries.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed, mixed> $attributes Raw attributes from session + filter.
	 * @return array<string, string|int|float|bool>
	 */
	public static function sanitize( array $attributes ): array {
		$out = [];

		foreach ( $attributes as $key => $value ) {
			if ( ! is_string( $key ) || $key === '' ) {
				continue;
			}
			foreach ( self::BLOCKED_PREFIXES as $prefix ) {
				if ( str_starts_with( $key, $prefix ) ) {
					continue 2;
				}
			}
			if ( is_string( $value ) ) {
				$out[ $key ] = self::truncate_utf8( $value, self::MAX_STRING_BYTES );
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$out[ $key ] = $value;
			}
		}

		if ( count( $out ) <= self::MAX_KEYS ) {
			return $out;
		}

		$keys = array_keys( $out );
		sort( $keys, SORT_STRING );
		$keys = array_slice( $keys, 0, self::MAX_KEYS );

		$limited = [];
		foreach ( $keys as $k ) {
			$limited[ $k ] = $out[ $k ];
		}

		return $limited;
	}

	/**
	 * Truncates UTF-8 text to a maximum byte length.
	 *
	 * @param string $value     Raw UTF-8.
	 * @param int    $max_bytes Maximum bytes.
	 * @return string
	 */
	private static function truncate_utf8( string $value, int $max_bytes ): string {
		return StringUtils::truncate_utf8( $value, $max_bytes );
	}
}
