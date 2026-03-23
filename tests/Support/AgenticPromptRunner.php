<?php

declare(strict_types=1);

namespace WP_AI_Telemetry\Tests\Support;

use WordPress\AI_Client\Builders\Helpers\Ability_Function_Resolver;

/**
 * Runs WP AI Client prompts for functional tests (no wp-ai-demo dependency).
 */
final class AgenticPromptRunner {

	public static function withTestMarker( string $prompt, string $marker ): string {
		return $prompt . "\n\nTest run id: " . $marker;
	}

	/**
	 * Single-turn text generation (no tools).
	 */
	public static function runSingleTurnText( string $prompt, string $provider = 'openai' ): string|\WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error( 'no_ai_client', 'wp_ai_client_prompt is not available.' );
		}
		$builder = wp_ai_client_prompt( $prompt )->using_provider( $provider )
			->using_temperature( 0.2 )
			->using_system_instruction(
				'You are a test harness assistant. Follow the user instructions exactly. Be brief.'
			);
		try {
			return $builder->generate_text();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'generation_error', $e->getMessage() );
		}
	}

	/**
	 * Agentic loop with given ability names (full wp-ai-telemetry-tests/... ids).
	 *
	 * @param list<string> $ability_ids
	 */
	public static function runWithAbilities(
		string $prompt,
		string $provider,
		array $ability_ids,
		int $max_iterations = 6
	): string|\WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error( 'no_ai_client', 'wp_ai_client_prompt is not available.' );
		}
		$builder  = wp_ai_client_prompt( $prompt )->using_provider( $provider )
			->using_temperature( 0.2 )
			->using_system_instruction(
				'You are a test harness assistant. When a tool is required to answer, call it exactly once with the parameters implied by the user. Then answer using the tool result. Be brief.'
			);
		$builder  = $builder->using_abilities( ...$ability_ids );
		$resolver = new Ability_Function_Resolver( ...$ability_ids );

		for ( $i = 0; $i < $max_iterations; $i++ ) {
			try {
				$result = $builder->generate_text_result();
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'generation_error', $e->getMessage() );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$candidates = $result->getCandidates();
			if ( empty( $candidates ) ) {
				return new \WP_Error( 'no_candidates', 'No candidates returned.' );
			}
			$message = $candidates[0]->getMessage();
			if ( ! $resolver->has_ability_calls( $message ) ) {
				try {
					return $result->toText();
				} catch ( \Throwable $e ) {
					return new \WP_Error( 'no_text', $e->getMessage() );
				}
			}
			$tool_response = $resolver->execute_abilities( $message );
			$builder       = $builder->with_history( $message )->with_history( $tool_response );
		}
		return new \WP_Error( 'max_iterations', 'Tool loop exceeded max iterations.' );
	}
}
