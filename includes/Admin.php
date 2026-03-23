<?php
/**
 * WordPress admin bootstrap: settings screen and admin notices.
 *
 * @package WP_AI_Telemetry
 */

declare(strict_types=1);

namespace WP_AI_Telemetry;

/**
 * Registers wp-admin hooks for this plugin.
 *
 * @since 0.1.0
 */
final class Admin {

	public const OPTION_KEY    = Settings::OPTION_KEY;
	private const MENU_SLUG    = 'wp-ai-telemetry';
	private const NONCE_KEY    = 'wp_ai_telemetry_settings_nonce';
	private const NONCE_ACTION = 'wp_ai_telemetry_save_settings';

	/**
	 * Hooks admin UI when running in wp-admin.
	 *
	 * @since 0.1.0
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_settings_page' ] );
		add_action( 'plugins_loaded', [ self::class, 'maybe_register_missing_vendor_notice' ], 5 );
	}

	/**
	 * Registers the options submenu under Settings.
	 *
	 * @since 0.1.0
	 */
	public static function register_settings_page(): void {
		\add_options_page(
			__( 'WP AI Telemetry', 'wp-ai-telemetry' ),
			__( 'AI Telemetry', 'wp-ai-telemetry' ),
			'manage_options',
			self::MENU_SLUG,
			[ self::class, 'render_settings_page' ]
		);
	}

	/**
	 * Renders and processes the settings page.
	 *
	 * @since 0.1.0
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this page.', 'wp-ai-telemetry' ) );
		}
		$saved    = self::process_form_submission();
		$settings = self::load_stored_settings();
		self::render_settings_form( $settings, $saved );
	}

	/**
	 * Processes the settings form submission.
	 *
	 * @return bool Whether a valid form was submitted and saved.
	 */
	private static function process_form_submission(): bool {
		$nonce_raw = isset( $_POST[ self::NONCE_KEY ] ) ? wp_unslash( $_POST[ self::NONCE_KEY ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified via wp_verify_nonce below after sanitize_text_field.
		$nonce_key = is_string( $nonce_raw ) ? sanitize_text_field( $nonce_raw ) : '';
		if ( $nonce_key === '' || ! wp_verify_nonce( $nonce_key, self::NONCE_ACTION ) ) {
			return false;
		}
		$post = array_filter( (array) wp_unslash( $_POST ), 'is_string', ARRAY_FILTER_USE_KEY );
		self::sanitize_and_save_settings( $post );
		return true;
	}

	/**
	 * Loads stored settings from the database, filtered to string keys.
	 *
	 * @return array<string, mixed>
	 */
	private static function load_stored_settings(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return array_filter( is_array( $stored ) ? $stored : [], 'is_string', ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Sanitizes POST data and saves it to the database.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $post POST data.
	 * @return array<string, mixed> Sanitized settings.
	 */
	private static function sanitize_and_save_settings( array $post ): array {
		$existing = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		$otlp = $post['otlp_endpoint'] ?? '';
		$pub  = $post['auth_public_key'] ?? '';
		$sec  = $post['auth_secret_key'] ?? '';
		$sec  = self::sanitize_otlp_secret( is_string( $sec ) ? $sec : '' );

		$existing_secret = is_string( $existing['auth_secret_key'] ?? null ) ? $existing['auth_secret_key'] : '';
		$sec             = self::resolve_secret( $sec, $existing_secret, ! empty( $post['auth_secret_clear'] ) );

		$rate     = $post['sampling_rate'] ?? 1.0;
		$settings = [
			'enabled'         => ! empty( $post['enabled'] ),
			'otlp_endpoint'   => esc_url_raw( is_string( $otlp ) ? $otlp : '' ),
			'auth_public_key' => sanitize_text_field( is_string( $pub ) ? $pub : '' ),
			'auth_secret_key' => $sec,
			'sampling_rate'   => min( 1.0, max( 0.0, is_numeric( $rate ) ? (float) $rate : 1.0 ) ),
			'log_prompts'     => ! empty( $post['log_prompts'] ),
		];

		update_option( self::OPTION_KEY, $settings );
		return $settings;
	}

	/**
	 * Resolves the OTLP secret for storage, applying the clear/keep/replace logic.
	 *
	 * @param string $new_value      Sanitized value from the form (may be empty).
	 * @param string $existing_value Currently stored secret.
	 * @param bool   $clear          Whether the user ticked "Remove saved password".
	 * @return string
	 */
	private static function resolve_secret( string $new_value, string $existing_value, bool $clear ): string {
		if ( $clear ) {
			return '';
		}
		if ( $new_value === '' && $existing_value !== '' ) {
			return $existing_value;
		}
		return $new_value;
	}

	/**
	 * Normalizes OTLP Basic Auth password from POST without HTML entity mangling.
	 *
	 * {@see sanitize_text_field()} strips tags and decodes entities, which corrupts
	 * secrets containing special characters such as angle brackets or ampersands.
	 *
	 * @since 0.2.0
	 *
	 * @param string $raw Raw password from the form.
	 * @return string
	 */
	private static function sanitize_otlp_secret( string $raw ): string {
		return str_replace( [ "\0", "\r", "\n" ], '', $raw );
	}

	/**
	 * Renders the HTML form.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @param bool                 $saved    Whether settings were just saved.
	 */
	private static function render_settings_form( array $settings, bool $saved ): void {
		$enabled           = ! empty( $settings['enabled'] );
		$otlp_endpoint     = is_string( $settings['otlp_endpoint'] ?? null ) ? $settings['otlp_endpoint'] : '';
		$auth_public_key   = is_string( $settings['auth_public_key'] ?? null ) ? $settings['auth_public_key'] : '';
		$has_stored_secret = is_string( $settings['auth_secret_key'] ?? null ) && $settings['auth_secret_key'] !== '';
		$raw_rate          = $settings['sampling_rate'] ?? 1.0;
		$sampling_rate     = is_numeric( $raw_rate ) ? (float) $raw_rate : 1.0;
		$log_prompts       = ! empty( $settings['log_prompts'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP AI Telemetry', 'wp-ai-telemetry' ); ?></h1>
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: Opening <strong>, 2: Closing </strong>, 3: Opening <a> for OpenTelemetry, 4: Closing </a>. */
						__( 'Log AI calls from %1$sWP AI Client%2$s to any %3$sOpenTelemetry%4$s-compatible backend.', 'wp-ai-telemetry' ),
						'<strong>',
						'</strong>',
						'<a href="https://opentelemetry.io/" target="_blank" rel="noopener noreferrer">',
						'</a>'
					),
					[
						'strong' => [],
						'a'      => [
							'href'   => [],
							'target' => [],
							'rel'    => [],
						],
					]
				);
				?>
			</p>

			<?php self::render_saved_notice( $saved ); ?>
			<?php self::render_missing_vendor_notice_inline(); ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_KEY ); ?>

				<table class="form-table" role="presentation">
					<?php self::render_field_enabled( $enabled ); ?>
					<?php self::render_field_endpoint( $otlp_endpoint ); ?>
					<?php self::render_field_auth_public_key( $auth_public_key ); ?>
					<?php self::render_field_auth_secret( $has_stored_secret ); ?>
					<?php self::render_field_sampling_rate( $sampling_rate ); ?>
					<?php self::render_field_log_prompts( $log_prompts ); ?>
				</table>

				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'wp-ai-telemetry' ); ?>">
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Outputs the "Settings saved" notice when $saved is true.
	 *
	 * @param bool $saved Whether settings were just saved.
	 */
	private static function render_saved_notice( bool $saved ): void {
		if ( ! $saved ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Settings saved.', 'wp-ai-telemetry' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Outputs the missing-vendor error notice inline on the settings page.
	 *
	 * Distinct from {@see render_notice_missing_vendor()} which is the admin_notices hook callback.
	 */
	private static function render_missing_vendor_notice_inline(): void {
		if ( class_exists( \OpenTelemetry\SDK\Trace\TracerProvider::class ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: plugin directory path. */
						__( '<strong>Composer dependencies missing.</strong> Run <code>composer install</code> in <code>%s</code>.', 'wp-ai-telemetry' ),
						esc_html( (string) WP_AI_TELEMETRY_DIR )
					),
					[
						'strong' => [],
						'code'   => [],
					]
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the "Enable Telemetry" checkbox row.
	 *
	 * @param bool $enabled Current value.
	 */
	private static function render_field_enabled( bool $enabled ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Telemetry', 'wp-ai-telemetry' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>>
					<?php esc_html_e( 'Enable logging AI calls to the OTLP backend', 'wp-ai-telemetry' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the OTLP HTTP endpoint URL field row.
	 *
	 * @param string $value Current value.
	 */
	private static function render_field_endpoint( string $value ): void {
		?>
		<tr>
			<th scope="row">
				<label for="otlp_endpoint"><?php esc_html_e( 'OTLP HTTP endpoint', 'wp-ai-telemetry' ); ?></label>
			</th>
			<td>
				<input type="url" id="otlp_endpoint" name="otlp_endpoint"
					value="<?php echo esc_attr( $value ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'https://collector.example.com', 'wp-ai-telemetry' ); ?>">
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: <code>/v1/traces</code>, 2: Opening <a>, 3: Closing </a>, 4: <code>http://localhost:4318</code>. */
							__( 'Base URL of the OTLP over HTTP receiver. The plugin appends %1$s per the %2$sOTLP HTTP%3$s specification (e.g. local collector: %4$s).', 'wp-ai-telemetry' ),
							'<code>/v1/traces</code>',
							'<a href="https://opentelemetry.io/docs/specs/otlp/#otlphttp-request" target="_blank" rel="noopener noreferrer">',
							'</a>',
							'<code>http://localhost:4318</code>'
						),
						[
							'code' => [],
							'a'    => [
								'href'   => [],
								'target' => [],
								'rel'    => [],
							],
						]
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the OTLP Basic Auth username field row.
	 *
	 * @param string $value Current value.
	 */
	private static function render_field_auth_public_key( string $value ): void {
		?>
		<tr>
			<th scope="row">
				<label for="auth_public_key"><?php esc_html_e( 'OTLP Basic Auth username', 'wp-ai-telemetry' ); ?></label>
			</th>
			<td>
				<input type="text" id="auth_public_key" name="auth_public_key"
					value="<?php echo esc_attr( $value ); ?>"
					class="regular-text"
					placeholder="">
				<p class="description">
					<?php esc_html_e( 'Optional. Sent as the HTTP Basic Auth username when your OTLP endpoint requires it.', 'wp-ai-telemetry' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the OTLP Basic Auth password field row.
	 *
	 * @param bool $has_stored_secret Whether a password is currently saved.
	 */
	private static function render_field_auth_secret( bool $has_stored_secret ): void {
		?>
		<tr>
			<th scope="row">
				<label for="auth_secret_key"><?php esc_html_e( 'OTLP Basic Auth password', 'wp-ai-telemetry' ); ?></label>
			</th>
			<td>
				<input type="password" id="auth_secret_key" name="auth_secret_key" value=""
					class="regular-text" autocomplete="new-password"
					placeholder="<?php echo esc_attr( $has_stored_secret ? __( 'Leave blank to keep current password', 'wp-ai-telemetry' ) : '' ); ?>">
				<p class="description">
					<?php esc_html_e( 'Optional. Sent as the HTTP Basic Auth password when your OTLP endpoint requires it. The saved password is never shown here; leave the field blank when saving other settings to keep it unchanged.', 'wp-ai-telemetry' ); ?>
				</p>
				<?php if ( $has_stored_secret ) : ?>
					<p>
						<label>
							<input type="checkbox" name="auth_secret_clear" value="1">
							<?php esc_html_e( 'Remove saved password', 'wp-ai-telemetry' ); ?>
						</label>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the Sampling Rate number field row.
	 *
	 * @param float $rate Current sampling rate.
	 */
	private static function render_field_sampling_rate( float $rate ): void {
		?>
		<tr>
			<th scope="row">
				<label for="sampling_rate"><?php esc_html_e( 'Sampling Rate', 'wp-ai-telemetry' ); ?></label>
			</th>
			<td>
				<input type="number" id="sampling_rate" name="sampling_rate"
					value="<?php echo esc_attr( (string) $rate ); ?>"
					min="0" max="1" step="0.01"
					style="width:80px">
				<p class="description">
					<?php esc_html_e( 'Fraction of AI calls to trace (0.0–1.0). Use 1.0 to trace all calls (recommended for development).', 'wp-ai-telemetry' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the "Log prompts & responses" checkbox row.
	 *
	 * @param bool $enabled Current value.
	 */
	private static function render_field_log_prompts( bool $enabled ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Log prompts & responses', 'wp-ai-telemetry' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="log_prompts" value="1" <?php checked( $enabled ); ?>>
					<?php esc_html_e( 'Include prompt text and AI response content in traces', 'wp-ai-telemetry' ); ?>
				</label>
				<p class="description">
					<?php
					echo wp_kses(
						__( '<strong>Privacy notice:</strong> Enabling this sends user prompt and AI response text to your telemetry backend. Disable if you handle sensitive data.', 'wp-ai-telemetry' ),
						[ 'strong' => [] ]
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Registers the missing-Composer admin notice when OTel SDK is unavailable.
	 *
	 * @since 0.1.0
	 */
	public static function maybe_register_missing_vendor_notice(): void {
		if ( ! class_exists( \OpenTelemetry\SDK\Trace\TracerProvider::class ) ) {
			add_action( 'admin_notices', [ self::class, 'render_notice_missing_vendor' ] );
		}
	}

	/**
	 * Admin notice when vendor directory is missing.
	 *
	 * @since 0.1.0
	 */
	public static function render_notice_missing_vendor(): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: plugin directory path */
						__( '<strong>WP AI Telemetry:</strong> Composer dependencies not installed. Run <code>composer install</code> in <code>%s</code>.', 'wp-ai-telemetry' ),
						esc_html( WP_AI_TELEMETRY_DIR )
					),
					[
						'strong' => [],
						'code'   => [],
					]
				);
				?>
			</p>
		</div>
		<?php
	}
}
