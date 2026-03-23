<?php
/**
 * Static session ID for gen_ai.conversation.id.
 *
 * @package WP_AI_Telemetry
 */

declare(strict_types=1);

namespace WP_AI_Telemetry;

/**
 * Manages the active session ID for grouping AI calls.
 *
 * Calling code (plugins, themes, WP-CLI commands) can wrap a sequence of AI
 * calls in Session::start() / Session::end(). All OTel spans created during
 * that window will carry the gen_ai.conversation.id attribute, enabling
 * OTLP-compatible backends to group them under a single conversation or session.
 *
 * This class uses a static property because PHP is synchronous: at most one
 * session is active at a time within a single request/process.
 *
 * Usage:
 *   $session_id = \WP_AI_Telemetry\Session::start();
 *   // ... make AI calls ...
 *   \WP_AI_Telemetry\Session::end();
 *
 * When no session is active, telemetry may auto-start a random conversation id on
 * the first instrumented AI call (default). Disable via the
 * {@see \WP_AI_Telemetry\Telemetry::FILTER_AUTO_CONVERSATION_SESSION} filter.
 *
 * Call {@see Session::merge_metadata()} after {@see Session::start()} to attach
 * session-scoped attributes to the root inference span only.
 *
 * @since 0.2.0
 */
class Session {

	/** Maximum byte length for caller-supplied session IDs. */
	private const MAX_SESSION_ID_BYTES = 256;

	/** Number of random bytes used to generate an automatic session ID (produces 32 hex chars). */
	private const RANDOM_ID_BYTES = 16;

	/**
	 * The currently active session ID, or null if no session is open.
	 *
	 * @var string|null
	 */
	private static ?string $session_id = null;

	/**
	 * Optional metadata merged onto the root inference span for the active session.
	 *
	 * Cleared on {@see Session::start()} and {@see Session::end()}. Set after
	 * `start()` if you need session-scoped attributes.
	 *
	 * @var array<string, string|int|float|bool>
	 */
	private static array $metadata = [];

	/**
	 * Opens a new session, optionally with a caller-supplied ID.
	 *
	 * If $session_id is null, a cryptographically random 32-character hex
	 * string is generated. Caller-supplied IDs are trimmed, stripped of ASCII
	 * control characters, and capped at 256 UTF-8 bytes. Empty values after
	 * sanitization fall back to a random id. Calling start() while a session
	 * is already open replaces it silently to keep the API simple.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $session_id Caller-supplied session ID, or null to auto-generate.
	 * @return string The active session ID.
	 */
	public static function start( ?string $session_id = null ): string {
		if ( self::is_active() && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'WP AI Telemetry: Session::start() called while a session is already active — replacing silently.' );
		}

		self::$metadata = [];

		if ( $session_id === null ) {
			self::$session_id = bin2hex( random_bytes( self::RANDOM_ID_BYTES ) );
			return self::$session_id;
		}

		$clean = trim( StringUtils::strip_control_chars( $session_id ) );
		if ( $clean === '' ) {
			self::$session_id = bin2hex( random_bytes( self::RANDOM_ID_BYTES ) );
			return self::$session_id;
		}

		self::$session_id = StringUtils::truncate_utf8( $clean, self::MAX_SESSION_ID_BYTES );
		return self::$session_id;
	}

	/**
	 * Closes the current session.
	 *
	 * @since 0.2.0
	 */
	public static function end(): void {
		self::$session_id = null;
		self::$metadata   = [];
	}

	/**
	 * Returns true when a session is currently open.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return self::$session_id !== null;
	}

	/**
	 * Merges attributes into the session metadata bag for the active session.
	 *
	 * Only string, int, float, and bool values are stored; other types are ignored.
	 * Keys must be non-empty strings. Later calls overwrite the same keys.
	 * The bag is applied only to the **root** inference span of each trace (see
	 * {@see Telemetry::FILTER_ROOT_SPAN_ATTRIBUTES}).
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $attributes Attributes to merge.
	 */
	public static function merge_metadata( array $attributes ): void {
		foreach ( $attributes as $key => $value ) {
			if ( ! is_string( $key ) || $key === '' ) {
				continue;
			}
			if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				self::$metadata[ $key ] = $value;
			}
		}
	}

	/**
	 * Returns a copy of the session metadata bag.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string|int|float|bool>
	 */
	public static function get_metadata(): array {
		return self::$metadata;
	}

	/**
	 * Returns the current session ID, or null if no session is open.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null
	 */
	public static function get_id(): ?string {
		return self::$session_id;
	}
}
