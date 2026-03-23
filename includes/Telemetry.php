<?php
/**
 * OpenTelemetry span lifecycle for WP AI Client events.
 *
 * @package WP_AI_Telemetry
 */

declare(strict_types=1);

namespace WP_AI_Telemetry;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use WordPress\AiClient\Events\AfterGenerateResultEvent;
use WordPress\AiClient\Events\BeforeGenerateResultEvent;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;

/**
 * Core telemetry class.
 *
 * Hooks into WP AI Client events and creates OpenTelemetry spans
 * for each AI call, following GenAI semantic conventions.
 *
 * @since 0.1.0
 */
class Telemetry {

	/**
	 * GenAI semconv key for correlating spans in a conversation/session/thread.
	 *
	 * Mirrors {@see TraceAttributes::GEN_AI_CONVERSATION_ID}. The `TraceAttributes`
	 * interface is deprecated by `open-telemetry/sem-conv` (replaced by smaller
	 * `OpenTelemetry\SemConv\Attributes\*` classes); GenAI keys are not yet published
	 * there, so we intentionally keep referencing this constant until upstream splits it out.
	 *
	 * @var non-empty-string
	 */
	// @phpstan-ignore-next-line classConstant.deprecatedInterface -- TraceAttributes is deprecated by sem-conv; GenAI keys are not yet on OpenTelemetry\SemConv\Attributes\*.
	private const GEN_AI_CONVERSATION_ID = TraceAttributes::GEN_AI_CONVERSATION_ID;

	/**
	 * Filter: whether to auto-start a random `gen_ai.conversation.id` on the first
	 * instrumented AI call in a load when no session is active.
	 *
	 * Default true. Return false to only set conversation id when `Session::start()` is used.
	 *
	 * @since 0.2.0
	 */
	public const FILTER_AUTO_CONVERSATION_SESSION = 'wp_ai_telemetry_auto_conversation_session';

	/**
	 * Filter: maximum byte length for large GenAI string attributes (JSON message blobs, system instructions, tool payloads).
	 *
	 * Default 65536 unless {@see WP_AI_TELEMETRY_GEN_AI_ATTR_MAX_BYTES} is defined (int, min 1024).
	 *
	 * @since 0.2.0
	 */
	public const FILTER_GEN_AI_ATTRIBUTE_MAX_BYTES = 'wp_ai_telemetry_gen_ai_attribute_max_bytes';

	/**
	 * Filter: extra attributes on the **root** inference span only (not child turns or tool spans).
	 *
	 * Default: session metadata snapshot from {@see Session::get_metadata()}. Callbacks receive
	 * `( array $attributes, array $context ): array`. The `$context` array includes: `event`,
	 * `model`, `correlation_id`, `session_id`, `is_trace_root`, `capability`, `operation`,
	 * `provider_id`, `request_model_id`, `span_name`. Values are sanitized (max keys, types,
	 * string size); keys starting with `gen_ai.` are dropped. Measured `wp_ai.duration_ms` in
	 * the after hook overwrites any value set here for that key.
	 *
	 * @since 0.2.0
	 */
	public const FILTER_ROOT_SPAN_ATTRIBUTES = 'wp_ai_telemetry_root_span_attributes';

	/**
	 * In-progress spans keyed by a correlation ID derived from request context.
	 * Since PHP is synchronous, we typically have at most one span at a time,
	 * but we use an array to support future async scenarios.
	 *
	 * @var array<int, array{span: SpanInterface, start: float}>
	 */
	private array $pending_spans = [];

	/**
	 * Open tool spans awaiting execution results, keyed by function call ID (or name).
	 *
	 * Spans are started in capture_function_calls() but not ended until the next
	 * on_before_generate() call, when FunctionResponse parts carry the actual results.
	 *
	 * @var array<string, SpanInterface>
	 */
	private array $pending_tool_spans = [];

	/**
	 * Carried-over OTel scope from a previous turn that returned tool calls.
	 *
	 * Activating an ended span's context after it is exported causes subsequent
	 * spans to be created as children of it (sharing the same trace ID). This
	 * keeps all turns of an agentic loop in a single distributed trace.
	 *
	 * @var ScopeInterface|null
	 */
	private ?ScopeInterface $carry_scope = null;

	/**
	 * The first (root) span of an agentic loop, kept open until the loop ends.
	 *
	 * When the model returns tool calls, the root span is NOT ended immediately.
	 * Instead, it stays open so its duration covers the full loop and its
	 * gen_ai.output.messages can be updated to the final text answer before it
	 * is closed — ensuring UIs that group by session see the final answer, not only the
	 * intermediate tool-call summary.
	 *
	 * @var SpanInterface|null
	 */
	private ?SpanInterface $agentic_root_span = null;

	/**
	 * Plugin settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Cached result of gen_ai_attribute_max_bytes() — resolved once per instance.
	 *
	 * @var int|null
	 */
	private ?int $cached_max_bytes = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers WordPress action hooks.
	 *
	 * @since 0.1.0
	 */
	public function register_hooks(): void {
		add_action( 'wp_ai_client_before_generate_result', [ $this, 'on_before_generate' ] );
		add_action( 'wp_ai_client_after_generate_result', [ $this, 'on_after_generate' ] );
	}

	/**
	 * Ends orphan spans when the PHP request ends without a matching after-generate (fatal, timeout, early exit).
	 *
	 * @since 0.2.0
	 */
	public function shutdown_cleanup(): void {
		if ( $this->carry_scope !== null ) {
			try {
				$this->carry_scope->detach();
			} catch ( \Throwable $e ) {
				// Ignore scope detach errors during shutdown.
				unset( $e );
			}
			$this->carry_scope = null;
		}

		foreach ( $this->pending_tool_spans as $span ) {
			try {
				$span->setStatus( StatusCode::STATUS_ERROR, 'request ended before tool result' );
				$span->end();
			} catch ( \Throwable $e ) {
				// Ignore span errors during shutdown.
				unset( $e );
			}
		}
		$this->pending_tool_spans = [];

		foreach ( $this->pending_spans as $pending ) {
			$span = $pending['span'];
			try {
				$span->setStatus( StatusCode::STATUS_ERROR, 'request ended before completion' );
				$span->end();
			} catch ( \Throwable $e ) {
				// Ignore span errors during shutdown.
				unset( $e );
			}
		}
		$this->pending_spans = [];

		if ( $this->agentic_root_span !== null ) {
			try {
				$this->agentic_root_span->setStatus( StatusCode::STATUS_ERROR, 'request ended before agentic loop completed' );
				$this->agentic_root_span->end();
			} catch ( \Throwable $e ) {
				// Ignore span errors during shutdown.
				unset( $e );
			}
			$this->agentic_root_span = null;
		}
	}

	/**
	 * Handles the before-generate event: opens an OTel span.
	 *
	 * @since 0.1.0
	 *
	 * @param BeforeGenerateResultEvent $event The event object.
	 */
	public function on_before_generate( BeforeGenerateResultEvent $event ): void {
		try {
			$this->do_before_generate( $event );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						/* translators: %s: exception message. */
						__( 'WP AI Telemetry: on_before_generate failed — %s', 'wp-ai-telemetry' ),
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Opens an OTel span for the before-generate hook (internal).
	 *
	 * @param BeforeGenerateResultEvent $event The event object.
	 */
	private function do_before_generate( BeforeGenerateResultEvent $event ): void {
		// Flush any pending tool spans from the previous turn by attaching results
		// from FunctionResponse parts now present in the message history.
		$this->flush_pending_tool_spans( $event->getMessages() );

		$this->maybe_auto_start_conversation_session();

		$model     = $event->getModel();
		$config    = $model->getConfig();
		$provider  = $model->providerMetadata();
		$metadata  = $model->metadata();
		$operation = $this->capability_to_operation( $event->getCapability() );

		$tracer = Globals::tracerProvider()->getTracer(
			'wp-ai-telemetry',
			(string) WP_AI_TELEMETRY_VERSION
		);

		// Span name pattern: "{operation} {provider}/{model}" e.g. "chat openai/gpt-4o".
		// Sanitize dynamic span name segments — Model metadata is attacker-influenced via API responses; stripping control chars and capping length reduces log/UI injection risk in backends.
		$span_name = sprintf(
			'%s %s/%s',
			$this->sanitize_span_label( $operation ),
			$this->sanitize_span_label( $provider->getId() ),
			$this->sanitize_span_label( $metadata->getId() )
		);

		$span_builder = $tracer->spanBuilder( $span_name )
			->setSpanKind( SpanKind::KIND_CLIENT )
			->setAttribute( 'gen_ai.provider.name', $provider->getId() )
			->setAttribute( 'gen_ai.operation.name', $operation )
			->setAttribute( 'gen_ai.request.model', $metadata->getId() );

		// Request parameters from ModelConfig.
		if ( $config->getTemperature() !== null ) {
			$span_builder->setAttribute( 'gen_ai.request.temperature', $config->getTemperature() );
		}
		if ( $config->getMaxTokens() !== null ) {
			$span_builder->setAttribute( 'gen_ai.request.max_tokens', $config->getMaxTokens() );
		}
		if ( $config->getTopP() !== null ) {
			$span_builder->setAttribute( 'gen_ai.request.top_p', $config->getTopP() );
		}

		// Always record system instructions and input messages (per GenAI semconv).
		// gen_ai.input.messages / gen_ai.output.messages are always set so OTLP backends
		// can display input/output in trace UIs. Tool call arguments and results
		// remain opt-in via the log_prompts setting (see flush_pending_tool_spans).
		if ( $config->getSystemInstruction() !== null ) {
			$span_builder->setAttribute(
				'gen_ai.system_instructions',
				$this->truncate_utf8_to_max_bytes( (string) $config->getSystemInstruction(), $this->gen_ai_attribute_max_bytes() )
			);
		}

		// Build gen_ai.input.messages as a JSON array per GenAI semconv.
		$messages   = $event->getMessages();
		$input_msgs = [];
		foreach ( $messages as $message ) {
			$role = $message->getRole();
			foreach ( $message->getParts() as $part ) {
				$text = $part->getText();
				if ( $text !== null ) {
					$input_msgs[] = [
						'role'    => $role->value,
						'content' => $text,
					];
					break;
				}
			}
		}
		if ( ! empty( $input_msgs ) ) {
			$encoded = $this->encode_json_gen_ai_array( $input_msgs );
			if ( $encoded !== null ) {
				$span_builder->setAttribute( 'gen_ai.input.messages', $encoded );
			}
		}

		// Attach session ID if a session is active.
		$session_id = Session::get_id();
		if ( $session_id !== null ) {
			$span_builder->setAttribute( self::GEN_AI_CONVERSATION_ID, $session_id );
		}

		$correlation_id = $this->make_correlation_id( $model );

		if ( $this->carry_scope === null ) {
			$this->apply_root_span_custom_attributes(
				$span_builder,
				$event,
				$correlation_id,
				$operation,
				$span_name,
				$provider->getId(),
				$metadata->getId()
			);
		}

		$span = $span_builder->startSpan();

		$this->pending_spans[ $correlation_id ] = [
			'span'  => $span,
			'start' => microtime( true ),
		];
	}

	/**
	 * Handles the after-generate event: closes the OTel span with result data.
	 *
	 * @since 0.1.0
	 *
	 * @param AfterGenerateResultEvent $event The event object.
	 */
	public function on_after_generate( AfterGenerateResultEvent $event ): void {
		try {
			$this->do_after_generate( $event );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						/* translators: %s: exception message. */
						__( 'WP AI Telemetry: on_after_generate failed — %s', 'wp-ai-telemetry' ),
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Closes the OTel span for the after-generate hook (internal).
	 *
	 * @param AfterGenerateResultEvent $event The event object.
	 */
	private function do_after_generate( AfterGenerateResultEvent $event ): void {
		$ctx = $this->resolve_pending_context( $event );
		if ( $ctx === null ) {
			return;
		}
		$this->set_response_attributes( $ctx['span'], $ctx['result'], $ctx['duration'] );
		$this->set_output_messages( $ctx['span'], $ctx['result'] );
		$this->capture_function_calls( $ctx['span'], $ctx['result'] );
		$ctx['span']->setStatus( StatusCode::STATUS_OK );
		$this->finalize_agentic_span( $ctx['span'], $ctx['result'] );
	}

	/**
	 * Resolves the pending span context for an after-generate event.
	 *
	 * @param AfterGenerateResultEvent $event The event object.
	 * @return array{span: SpanInterface, result: \WordPress\AiClient\Results\DTO\GenerativeAiResult, duration: float}|null
	 */
	private function resolve_pending_context( AfterGenerateResultEvent $event ): ?array {
		$model          = $event->getModel();
		$correlation_id = $this->make_correlation_id( $model );
		if ( ! isset( $this->pending_spans[ $correlation_id ] ) ) {
			return null;
		}
		$pending  = $this->pending_spans[ $correlation_id ];
		$duration = microtime( true ) - $pending['start'];
		unset( $this->pending_spans[ $correlation_id ] );
		return [
			'span'     => $pending['span'],
			'result'   => $event->getResult(),
			'duration' => $duration,
		];
	}

	/**
	 * Sets token usage, model, finish reason, and duration attributes on a span.
	 *
	 * @param SpanInterface                                      $span     The span to update.
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result   The AI result.
	 * @param float                                              $duration Elapsed seconds.
	 */
	private function set_response_attributes( SpanInterface $span, \WordPress\AiClient\Results\DTO\GenerativeAiResult $result, float $duration ): void {
		$token_usage = $result->getTokenUsage();
		$model_meta  = $result->getModelMetadata();
		$span->setAttribute( 'gen_ai.response.model', $model_meta->getId() );
		$span->setAttribute( 'gen_ai.usage.input_tokens', $token_usage->getPromptTokens() );
		$span->setAttribute( 'gen_ai.usage.output_tokens', $token_usage->getCompletionTokens() );

		// Thought tokens: wordpress/php-ai-client 1.3.0+ only (see TokenUsage::getThoughtTokens()).
		// @phpstan-ignore-next-line function.alreadyNarrowedType.
		if ( method_exists( $token_usage, 'getThoughtTokens' ) ) {
			$thought = $token_usage->getThoughtTokens();
			if ( $thought !== null ) {
				$span->setAttribute( 'gen_ai.usage.thought_tokens', $thought );
			}
		}

		// gen_ai.response.finish_reasons is a string array per spec; encode as JSON.
		$finish_reason = $this->get_finish_reason( $result );
		$span->setAttribute( 'gen_ai.response.finish_reasons', wp_json_encode( [ $finish_reason ] ) );

		if ( $duration > 0 ) {
			$span->setAttribute( 'wp_ai.duration_ms', (int) round( $duration * 1000 ) );
		}
	}

	/**
	 * Sets gen_ai.output.messages on a span from the result (text or tool-call summary).
	 *
	 * @param SpanInterface                                      $span   The span to update.
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result The AI result.
	 */
	private function set_output_messages( SpanInterface $span, \WordPress\AiClient\Results\DTO\GenerativeAiResult $result ): void {
		try {
			$text = $result->toText();
			if ( ! empty( $text ) ) {
				$this->set_assistant_output_on_span( $span, $text );
			}
		} catch ( \Throwable ) {
			// Not a text response — capture tool calls as output instead.
			$tool_calls_summary = $this->extract_tool_calls_summary( $result );
			if ( $tool_calls_summary !== null ) {
				$this->set_assistant_output_on_span( $span, $tool_calls_summary );
			}
		}
	}

	/**
	 * Handles carry_scope and agentic_root_span bookkeeping after a turn.
	 *
	 * @param SpanInterface                                      $span   The current turn's span.
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result The AI result.
	 */
	private function finalize_agentic_span( SpanInterface $span, \WordPress\AiClient\Results\DTO\GenerativeAiResult $result ): void {
		// Detach any scope carried over from a previous tool-call turn, now
		// that we're in the new turn's on_after_generate.
		if ( $this->carry_scope !== null ) {
			$this->carry_scope->detach();
			$this->carry_scope = null;
		}

		if ( $this->has_tool_calls( $result ) ) {
			// This turn requested tool calls — more turns are coming.
			// Keep the first-turn (root) span open so its duration covers the
			// full agentic loop and we can update its output to the final answer.
			if ( $this->agentic_root_span === null ) {
				// First turn: stash the span instead of ending it.
				$this->agentic_root_span = $span;
			} else {
				// Intermediate turn with more tool calls: end it normally.
				$this->safe_end_span( $span );
			}

			// Activate this turn's span so the next turn becomes its child.
			$this->carry_scope = $span->activate();
			return;
		}

		// Final turn (no tool calls): end this turn's span first.
		$this->safe_end_span( $span );
		$this->close_agentic_root_span( $result );
	}

	/**
	 * Closes the agentic root span (if open), updating its output to the final answer.
	 *
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result The final AI result.
	 */
	private function close_agentic_root_span( \WordPress\AiClient\Results\DTO\GenerativeAiResult $result ): void {
		if ( $this->agentic_root_span === null ) {
			return;
		}
		$root                    = $this->agentic_root_span;
		$this->agentic_root_span = null;

		try {
			$final_text = $result->toText();
			if ( ! empty( $final_text ) ) {
				$this->set_assistant_output_on_span( $root, $final_text );
			}
		} catch ( \Throwable $e ) {
			// toText() may throw for non-text results; root output is best-effort.
			unset( $e );
		}

		$this->safe_end_span( $root );
	}

	/**
	 * Closes any pending tool spans by attaching FunctionResponse results.
	 *
	 * Called at the start of on_before_generate() for each subsequent turn. The
	 * agentic loop appends FunctionResponse messages to the builder history after
	 * executing tools, so by the time the next generate call fires the responses
	 * are present in $event->getMessages().
	 *
	 * @param Message[] $messages Messages from the next-turn event.
	 */
	private function flush_pending_tool_spans( array $messages ): void {
		if ( empty( $this->pending_tool_spans ) ) {
			return;
		}

		foreach ( $messages as $message ) {
			foreach ( $message->getParts() as $part ) {
				$fr = $part->getFunctionResponse();
				if ( $fr === null ) {
					continue;
				}

				$key  = $fr->getId() ?? $fr->getName();
				$span = $this->pending_tool_spans[ $key ] ?? null;
				if ( $span === null ) {
					continue;
				}

				// Attach result (opt-in — may contain PII).
				if ( ! empty( $this->settings['log_prompts'] ) ) {
					$response = $fr->getResponse();
					$encoded  = wp_json_encode( $response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					if ( $encoded !== false ) {
						$span->setAttribute(
							'gen_ai.tool.call.result',
							$this->truncate_utf8_to_max_bytes( $encoded, $this->gen_ai_attribute_max_bytes() )
						);
					}
				}

				$span->setStatus( StatusCode::STATUS_OK );
				$span->end();
				unset( $this->pending_tool_spans[ $key ] );
			}
		}
	}

	/**
	 * Merges session metadata and filter output into the root inference span builder.
	 *
	 * @param SpanBuilderInterface      $span_builder      Span builder.
	 * @param BeforeGenerateResultEvent $event             Before event.
	 * @param int                       $correlation_id    Correlation id.
	 * @param string                    $operation         GenAI operation name.
	 * @param string                    $span_name         Sanitized span name.
	 * @param string                    $provider_id       Provider id.
	 * @param string                    $request_model_id  Request model id.
	 */
	private function apply_root_span_custom_attributes(
		SpanBuilderInterface $span_builder,
		BeforeGenerateResultEvent $event,
		int $correlation_id,
		string $operation,
		string $span_name,
		string $provider_id,
		string $request_model_id
	): void {
		$cap       = $event->getCapability();
		$cap_value = $cap !== null ? $cap->value : null;

		$context = [
			'event'            => $event,
			'model'            => $event->getModel(),
			'correlation_id'   => $correlation_id,
			'session_id'       => Session::get_id(),
			'is_trace_root'    => true,
			'capability'       => $cap_value,
			'operation'        => $operation,
			'provider_id'      => $provider_id,
			'request_model_id' => $request_model_id,
			'span_name'        => $span_name,
		];

		$seed = Session::get_metadata();

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- FILTER_ROOT_SPAN_ATTRIBUTES is wp_ai_telemetry_*.
			$merged = apply_filters( self::FILTER_ROOT_SPAN_ATTRIBUTES, $seed, $context );
			if ( ! is_array( $merged ) ) {
				$merged = $seed;
			}
			$sanitized = RootSpanAttributes::sanitize( $merged );
			foreach ( $sanitized as $key => $value ) {
				$span_builder->setAttribute( $key, $value );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						/* translators: %s: exception message. */
						__( 'WP AI Telemetry: root span attributes filter failed — %s', 'wp-ai-telemetry' ),
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Generates a correlation ID for matching before/after events.
	 *
	 * @param ModelInterface $model The model instance.
	 * @return int The correlation ID.
	 */
	private function make_correlation_id( ModelInterface $model ): int {
		return spl_object_id( $model );
	}

	/**
	 * Normalizes user- or API-derived strings used in span names for safe export.
	 *
	 * @param string $value Raw label fragment.
	 * @param int    $max   Maximum byte length after sanitization.
	 * @return string
	 */
	private function sanitize_span_label( string $value, int $max = 256 ): string {
		$s = StringUtils::strip_control_chars( $value );
		if ( $s === '' ) {
			return 'unknown';
		}
		return StringUtils::truncate_utf8( $s, $max );
	}

	/**
	 * Resolves max byte size for GenAI JSON/text attributes.
	 *
	 * @return int
	 */
	private function gen_ai_attribute_max_bytes(): int {
		if ( $this->cached_max_bytes !== null ) {
			return $this->cached_max_bytes;
		}
		$default = 65536;
		if ( defined( 'WP_AI_TELEMETRY_GEN_AI_ATTR_MAX_BYTES' ) ) {
			$raw = constant( 'WP_AI_TELEMETRY_GEN_AI_ATTR_MAX_BYTES' );
			if ( is_int( $raw ) && $raw >= 1024 ) {
				$default = $raw;
			}
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- FILTER_GEN_AI_ATTRIBUTE_MAX_BYTES is wp_ai_telemetry_*.
		$filtered = apply_filters( self::FILTER_GEN_AI_ATTRIBUTE_MAX_BYTES, $default );
		if ( is_int( $filtered ) ) {
			$as_int = $filtered;
		} elseif ( is_numeric( $filtered ) ) {
			$as_int = (int) $filtered;
		} else {
			$as_int = $default;
		}
		$this->cached_max_bytes = max( 1024, $as_int );
		return $this->cached_max_bytes;
	}

	/**
	 * Truncates a UTF-8 string to a maximum byte length (marker included in budget).
	 *
	 * @param string $value     Raw UTF-8 text.
	 * @param int    $max_bytes Maximum total bytes including suffix.
	 * @return string
	 */
	private function truncate_utf8_to_max_bytes( string $value, int $max_bytes ): string {
		return StringUtils::truncate_utf8( $value, $max_bytes );
	}

	/**
	 * JSON-encodes a structure for GenAI attributes, enforcing a byte cap (drops oldest list entries first).
	 *
	 * @param array<mixed> $data Serializable payload.
	 * @return string|null Encoded JSON or null on failure.
	 */
	private function encode_json_gen_ai_array( array $data ): ?string {
		$max     = $this->gen_ai_attribute_max_bytes();
		$encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $encoded === false ) {
			return null;
		}
		if ( strlen( $encoded ) <= $max ) {
			return $encoded;
		}
		if ( array_is_list( $data ) ) {
			$total = count( $data );
			for ( $drop = 1; $drop < $total; $drop++ ) {
				$list    = array_slice( $data, $drop );
				$encoded = wp_json_encode( $list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( $encoded === false ) {
					return null;
				}
				if ( strlen( $encoded ) <= $max ) {
					return $encoded;
				}
			}
		}
		return $this->truncate_utf8_to_max_bytes( $encoded, $max );
	}

	/**
	 * Opens a random session when the filter allows and no session is active.
	 *
	 * @since 0.2.0
	 */
	private function maybe_auto_start_conversation_session(): void {
		if ( Session::get_id() !== null ) {
			return;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- FILTER_AUTO_CONVERSATION_SESSION is wp_ai_telemetry_*.
		$enabled = (bool) apply_filters( self::FILTER_AUTO_CONVERSATION_SESSION, true );
		if ( ! $enabled ) {
			return;
		}
		Session::start();
	}

	/**
	 * Converts a CapabilityEnum to a GenAI operation name.
	 *
	 * @param \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum|null $capability Request capability, if any.
	 * @return string GenAI operation name for the span.
	 */
	private function capability_to_operation( ?\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum $capability ): string {
		if ( $capability === null ) {
			return 'chat';
		}
		return match ( $capability->value ) {
			'text_generation'  => 'chat',
			'image_generation' => 'image_generation',
			'audio_generation' => 'audio_generation',
			'video_generation' => 'video_generation',
			default            => $capability->value,
		};
	}

	/**
	 * Extracts finish reason from the result.
	 *
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result The model response.
	 * @return string Finish reason string from the first candidate.
	 */
	private function get_finish_reason( \WordPress\AiClient\Results\DTO\GenerativeAiResult $result ): string {
		$candidates = $result->getCandidates();
		if ( empty( $candidates ) ) {
			return 'unknown';
		}
		return $candidates[0]->getFinishReason()->value;
	}

	/**
	 * Returns true if any candidate part in the result contains a function call.
	 *
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result The AI result.
	 * @return bool
	 */
	private function has_tool_calls( \WordPress\AiClient\Results\DTO\GenerativeAiResult $result ): bool {
		foreach ( $result->getCandidates() as $candidate ) {
			foreach ( $candidate->getMessage()->getParts() as $part ) {
				if ( $part->getFunctionCall() !== null ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Builds a JSON summary of all tool calls in the result, or null if none.
	 *
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result The AI result.
	 * @return string|null JSON string summarising the tool calls, or null.
	 */
	private function extract_tool_calls_summary( \WordPress\AiClient\Results\DTO\GenerativeAiResult $result ): ?string {
		$tool_calls = [];

		foreach ( $result->getCandidates() as $candidate ) {
			foreach ( $candidate->getMessage()->getParts() as $part ) {
				$function_call = $part->getFunctionCall();
				if ( $function_call === null ) {
					continue;
				}
				$name = $function_call->getName();
				if ( $name === null ) {
					continue;
				}
				$entry = [ 'name' => $name ];
				$id    = $function_call->getId();
				if ( $id !== null ) {
					$entry['id'] = $id;
				}
				$args = $function_call->getArgs();
				if ( $args !== null ) {
					$entry['args'] = $args;
				}
				$tool_calls[] = $entry;
			}
		}

		if ( empty( $tool_calls ) ) {
			return null;
		}

		return $this->encode_json_gen_ai_array( $tool_calls );
	}

	/**
	 * Captures function/tool calls from the result and creates child spans.
	 *
	 * Span name follows the GenAI semconv pattern: "execute_tool {tool_name}".
	 * Attributes follow the GenAI semconv execute_tool span spec.
	 *
	 * @param \OpenTelemetry\API\Trace\SpanInterface             $parent_span The parent AI call span.
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result      The AI result.
	 */
	private function capture_function_calls( \OpenTelemetry\API\Trace\SpanInterface $parent_span, \WordPress\AiClient\Results\DTO\GenerativeAiResult $result ): void {
		$candidates = $result->getCandidates();
		if ( empty( $candidates ) ) {
			return;
		}

		$tracer = Globals::tracerProvider()->getTracer(
			'wp-ai-telemetry',
			(string) WP_AI_TELEMETRY_VERSION
		);

		// Activate parent span so all child tool spans inherit its context.
		$scope = $parent_span->activate();

		try {
			foreach ( $candidates as $candidate ) {
				$message = $candidate->getMessage();
				$parts   = $message->getParts();

				foreach ( $parts as $part ) {
					$function_call = $part->getFunctionCall();
					if ( $function_call === null ) {
						continue;
					}

					$function_name = $function_call->getName();
					$function_id   = $function_call->getId();

					if ( $function_name === null ) {
						continue;
					}

					// Span name: "execute_tool {tool_name}" per GenAI semconv.
					$tool_span = $tracer->spanBuilder( sprintf( 'execute_tool %s', $this->sanitize_span_label( $function_name ) ) )
						->setSpanKind( SpanKind::KIND_INTERNAL )
						->setAttribute( 'gen_ai.operation.name', 'execute_tool' )
						->setAttribute( 'gen_ai.tool.name', $function_name );

					if ( $function_id !== null ) {
						$tool_span->setAttribute( 'gen_ai.tool.call.id', $function_id );
					}

					// Propagate session ID to tool spans.
					$session_id = Session::get_id();
					if ( $session_id !== null ) {
						$tool_span->setAttribute( self::GEN_AI_CONVERSATION_ID, $session_id );
					}

					// Log function arguments if enabled (opt-in per spec — may contain PII).
					if ( ! empty( $this->settings['log_prompts'] ) ) {
						$args = $function_call->getArgs();
						if ( $args !== null ) {
							if ( is_array( $args ) ) {
								$encoded = $this->encode_json_gen_ai_array( $args );
							} else {
								$raw     = wp_json_encode( $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
								$encoded = ( $raw !== false )
									? $this->truncate_utf8_to_max_bytes( $raw, $this->gen_ai_attribute_max_bytes() )
									: null;
							}
							if ( $encoded !== null ) {
								$tool_span->setAttribute( 'gen_ai.tool.call.arguments', $encoded );
							}
						}
					}

					// Start but do NOT end the span yet. The execution result arrives in
					// the next turn's FunctionResponse parts, where flush_pending_tool_spans()
					// will attach gen_ai.tool.call.result and close the span.
					$started_span                          = $tool_span->startSpan();
					$span_key                              = $function_id ?? $function_name;
					$this->pending_tool_spans[ $span_key ] = $started_span;
				}
			}
		} finally {
			$scope->detach();
		}
	}

	/**
	 * Encodes an assistant message as gen_ai.output.messages and sets it on a span.
	 *
	 * @param SpanInterface $span    The span to update.
	 * @param string        $content The assistant text or tool-call summary.
	 */
	private function set_assistant_output_on_span( SpanInterface $span, string $content ): void {
		$encoded = $this->encode_json_gen_ai_array(
			[
				[
					'role'    => 'assistant',
					'content' => $content,
				],
			]
		);
		if ( $encoded !== null ) {
			$span->setAttribute( 'gen_ai.output.messages', $encoded );
		}
	}

	/**
	 * Ends a span, swallowing any exception.
	 *
	 * @param SpanInterface $span The span to end.
	 */
	private function safe_end_span( SpanInterface $span ): void {
		try {
			$span->end();
		} catch ( \Throwable $e ) {
			// Some OTLP HTTP servers respond with JSON to protobuf requests; the SDK
			// may fail to parse the response body even though the trace was accepted.
			unset( $e );
		}
	}
}
