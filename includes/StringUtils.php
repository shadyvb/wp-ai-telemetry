<?php
/**
 * Shared string utility functions.
 *
 * @package WP_AI_Telemetry
 */

declare(strict_types=1);

namespace WP_AI_Telemetry;

/**
 * Stateless string utilities for sanitization and truncation.
 *
 * @since 0.3.0
 */
final class StringUtils {

	/**
	 * Truncates a UTF-8 string to a maximum byte length, appending a marker.
	 *
	 * The marker is included in the byte budget (i.e., total output <= $max_bytes).
	 *
	 * @since 0.3.0
	 *
	 * @param string $value     Raw UTF-8 text.
	 * @param int    $max_bytes Maximum total bytes including the truncation marker.
	 * @param string $marker    Appended when truncated. Default '…[truncated]'.
	 * @return string
	 */
	public static function truncate_utf8( string $value, int $max_bytes, string $marker = '…[truncated]' ): string {
		if ( strlen( $value ) <= $max_bytes ) {
			return $value;
		}
		$budget = $max_bytes - strlen( $marker );
		if ( $budget < 1 ) {
			return substr( $marker, 0, $max_bytes );
		}
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, $budget, 'UTF-8' ) . $marker;
		}
		return substr( $value, 0, $budget ) . $marker;
	}

	/**
	 * Strips ASCII control characters (0x00–0x1F and 0x7F) from a string.
	 *
	 * @since 0.3.0
	 *
	 * @param string $value Input string.
	 * @return string String with control characters removed.
	 */
	public static function strip_control_chars( string $value ): string {
		$result = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		return is_string( $result ) ? $result : '';
	}
}
