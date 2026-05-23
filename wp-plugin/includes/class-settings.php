<?php
/**
 * Settings page under Settings → Translation Tracker.
 *
 * - Field: tracker.json URL
 * - Field: cache duration in hours (1 to 168)
 * - Button: clear cache now (AJAX)
 * - Display: last successful generated_at stamp
 *
 * @package training-translation-tracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings class.
 */
class TTT_Settings {

	/**
	 * Constructor: register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_ttt_clear_cache', array( $this, 'handle_clear_cache' ) );
	}

	/**
	 * Entry in the settings menu.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Translation Tracker', 'training-translation-tracker' ),
			__( 'Translation Tracker', 'training-translation-tracker' ),
			'manage_options',
			'training-translation-tracker',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers the settings fields via the WP Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'ttt_settings_group',
			TTT_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'tracker_url' => TTT_DEFAULT_TRACKER_URL,
					'cache_hours' => TTT_DEFAULT_CACHE_HOURS,
				),
			)
		);

		add_settings_section(
			'ttt_section_main',
			__( 'Data source & cache', 'training-translation-tracker' ),
			'__return_null',
			'training-translation-tracker'
		);

		add_settings_field(
			'ttt_tracker_url',
			__( 'tracker.json URL', 'training-translation-tracker' ),
			array( $this, 'field_tracker_url' ),
			'training-translation-tracker',
			'ttt_section_main'
		);

		add_settings_field(
			'ttt_cache_hours',
			__( 'Cache duration (hours)', 'training-translation-tracker' ),
			array( $this, 'field_cache_hours' ),
			'training-translation-tracker',
			'ttt_section_main'
		);
	}

	/**
	 * Value sanitization on save.
	 *
	 * The tracker URL is validated against a small allow-list of hosts as a
	 * defensive measure against accidental misconfiguration (typos pointing
	 * at the wrong domain) and against an admin pasting a URL that would
	 * trigger SSRF-style behavior from a misconfigured WordPress server.
	 * The capability check on the settings page already restricts who can
	 * change this, so the risk is small; the allow-list is just an extra
	 * layer.
	 *
	 * Themes and companion plugins can extend the allow-list via the
	 * `ttt_tracker_url_allowed_hosts` filter (e.g. when self-hosting the
	 * `tracker.json` on a custom domain).
	 *
	 * @param array $input Raw form input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$tracker_url = isset( $input['tracker_url'] ) ? esc_url_raw( trim( (string) $input['tracker_url'] ) ) : '';
		$cache_hours = isset( $input['cache_hours'] ) ? absint( $input['cache_hours'] ) : TTT_DEFAULT_CACHE_HOURS;

		if ( '' !== $tracker_url ) {
			/**
			 * Filter: ttt_tracker_url_allowed_hosts.
			 *
			 * Returns the list of hosts (without scheme, lower-case) that
			 * the tracker URL setting is allowed to point at. Default:
			 * just `raw.githubusercontent.com`, where the action publishes
			 * the JSON. Extend this to allow self-hosted mirrors.
			 *
			 * @param array $hosts Default list of allowed hosts.
			 */
			$allowed_hosts = apply_filters(
				'ttt_tracker_url_allowed_hosts',
				array( 'raw.githubusercontent.com' )
			);
			$allowed_hosts = array_map( 'strtolower', (array) $allowed_hosts );

			$scheme = wp_parse_url( $tracker_url, PHP_URL_SCHEME );
			$host   = strtolower( (string) wp_parse_url( $tracker_url, PHP_URL_HOST ) );

			if ( 'https' !== $scheme || '' === $host || ! in_array( $host, $allowed_hosts, true ) ) {
				// Reject the new URL and keep the existing saved value, so
				// a fat-fingered host or http:// pasted in the field does
				// not accidentally break a working tracker.
				$previous    = get_option( TTT_OPTION_KEY, array() );
				$tracker_url = isset( $previous['tracker_url'] ) && is_string( $previous['tracker_url'] )
					? $previous['tracker_url']
					: TTT_DEFAULT_TRACKER_URL;
				add_settings_error(
					'ttt_settings',
					'ttt_invalid_url',
					__( 'Invalid tracker URL. Must be HTTPS and on the allowed-hosts list. Reverted to previous value.', 'training-translation-tracker' )
				);
			}
		}

		if ( $cache_hours < 1 ) {
			$cache_hours = 1;
		} elseif ( $cache_hours > 168 ) {
			$cache_hours = 168;
		}

		return array(
			'tracker_url' => $tracker_url,
			'cache_hours' => $cache_hours,
		);
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * Convenience getter for settings values.
	 *
	 * @param string $key Which setting.
	 * @return mixed
	 */
	public static function get( $key ) {
		$opts = get_option(
			TTT_OPTION_KEY,
			array(
				'tracker_url' => TTT_DEFAULT_TRACKER_URL,
				'cache_hours' => TTT_DEFAULT_CACHE_HOURS,
			)
		);
		return $opts[ $key ] ?? null;
	}

	// ---------------------------------------------------------------- fields

	/**
	 * Input field: tracker.json URL.
	 *
	 * @return void
	 */
	public function field_tracker_url() {
		$value = self::get( 'tracker_url' );
		printf(
			'<input type="url" name="%1$s[tracker_url]" value="%2$s" class="regular-text code" placeholder="%3$s" />',
			esc_attr( TTT_OPTION_KEY ),
			esc_attr( $value ),
			esc_attr( TTT_DEFAULT_TRACKER_URL )
		);
		echo '<p class="description">';
		echo esc_html__(
			'Direct link to tracker.json, typically the raw.githubusercontent.com link to the data branch of the Inventory Action.',
			'training-translation-tracker'
		);
		echo '</p>';
	}

	/**
	 * Input field: cache hours.
	 *
	 * @return void
	 */
	public function field_cache_hours() {
		$value = (int) self::get( 'cache_hours' );
		printf(
			'<input type="number" name="%1$s[cache_hours]" value="%2$d" min="1" max="168" class="small-text" />',
			esc_attr( TTT_OPTION_KEY ),
			(int) $value
		);
		echo ' <span class="description">';
		echo esc_html__( '1–168 hours. Recommended: 12 (matches the Action interval).', 'training-translation-tracker' );
		echo '</span>';
	}

	// ----------------------------------------------------------------- render

	/**
	 * Renders the full settings page including cache status and clear-cache button.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'training-translation-tracker' ) );
		}

		$last_good   = get_transient( TTT_LAST_GOOD_KEY );
		$generated   = $this->extract_generated_at( $last_good );
		$cache_state = false !== get_transient( TTT_TRANSIENT_KEY );

		// Inline <style> block on purpose instead of a separate admin stylesheet,
		// to avoid the enqueue overhead for a handful of settings-specific rules.
		// If the settings page ever grows: move to a dedicated CSS file and load
		// it via wp_enqueue_style.
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		?>
		<style>
			/* Small styles scoped to this settings page only. */
			.ttt-settings-title           { display: flex; align-items: center; gap: 12px; }
			.ttt-settings-title-icon      { width: 40px; height: 40px; flex-shrink: 0; }
			.ttt-settings-status-active   { color: #2271b1; }
			.ttt-settings-status-inactive { color: #999; }
			.ttt-settings-clear-msg       { margin-left: 10px; }
			.ttt-settings-shortcode-table { max-width: 800px; }
			.ttt-settings-shortcode-note  { margin-top: 0.75rem; }
			/* Status colors for the AJAX response from admin.js */
			.ttt-clear-msg-pending { color: #666; }
			.ttt-clear-msg-success { color: #46b450; }
			.ttt-clear-msg-error   { color: #dc3232; }
		</style>
		<?php // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>
		<div class="wrap">
			<h1 class="ttt-settings-title">
				<img
					src="<?php echo esc_url( TTT_PLUGIN_URL . 'assets/icons/header-icon.svg' ); ?>"
					alt=""
					class="ttt-settings-title-icon"
				/>
				<?php esc_html_e( 'Translation Tracker', 'training-translation-tracker' ); ?>
			</h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'ttt_settings_group' );
				do_settings_sections( 'training-translation-tracker' );
				submit_button( __( 'Save settings', 'training-translation-tracker' ) );
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Cache status', 'training-translation-tracker' ); ?></h2>
			<p>
				<?php if ( $cache_state ) : ?>
					<span class="ttt-settings-status-active">●</span>
					<?php esc_html_e( 'Cache is active.', 'training-translation-tracker' ); ?>
				<?php else : ?>
					<span class="ttt-settings-status-inactive">○</span>
					<?php esc_html_e( 'Cache is empty — the next shortcode call will fetch fresh data.', 'training-translation-tracker' ); ?>
				<?php endif; ?>
			</p>
			<?php if ( $generated ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: UTC timestamp from tracker.json. */
						esc_html__( 'Last successful tracker.json state: %s (UTC)', 'training-translation-tracker' ),
						'<code>' . esc_html( $generated ) . '</code>'
					);
					?>
				</p>
			<?php endif; ?>

			<p>
				<button type="button" id="ttt-clear-cache" class="button button-secondary">
					<?php esc_html_e( 'Clear cache now', 'training-translation-tracker' ); ?>
				</button>
				<span id="ttt-clear-cache-msg" class="ttt-settings-clear-msg"></span>
			</p>

			<hr>

			<h2><?php esc_html_e( 'Shortcodes', 'training-translation-tracker' ); ?></h2>
			<p>
				<?php esc_html_e( 'Insert on any WordPress page. Multiple attributes can be combined freely.', 'training-translation-tracker' ); ?>
			</p>
			<table class="widefat striped ttt-settings-shortcode-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Shortcode', 'training-translation-tracker' ); ?></th>
						<th><?php esc_html_e( 'Effect', 'training-translation-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>[translation_tracker]</code></td>
						<td><?php esc_html_e( 'Everything — all pathways, handbook (if any), orphan group, stats header.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker pathway="user"]</code></td>
						<td><?php esc_html_e( 'Show only the pathway with slug "user" (e.g. Start using WordPress).', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker pathway="lesson-plans"]</code></td>
						<td><?php esc_html_e( 'Only the "Lesson Plans" pathway.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker pathway="user,lesson-plans"]</code></td>
						<td><?php esc_html_e( 'Multiple pathways at once — comma-separated.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker show_orphans="no"]</code></td>
						<td><?php esc_html_e( 'Hide the orphan group ("Other").', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker show_handbook="no"]</code></td>
						<td><?php esc_html_e( 'Hide the Handbook group (useful once handbook content is added).', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker show_stats="no"]</code></td>
						<td><?php esc_html_e( 'Hide the stats header at the top.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker show_pathways="no"]</code></td>
						<td><?php esc_html_e( 'Hide all pathway groups — Handbook and orphan group remain visible.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td>
							<code>[translation_tracker show_pathways="no" show_orphans="no"]</code>
						</td>
						<td><?php esc_html_e( 'Show only the Training Handbook — typical for a dedicated handbook page.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td>
							<code>[translation_tracker pathway="lesson-plans" show_stats="no" show_orphans="no"]</code>
						</td>
						<td><?php esc_html_e( 'Combination: only Lesson Plans, without stats and orphans — typical for a dedicated Lesson Plans page.', 'training-translation-tracker' ); ?></td>
					</tr>
				</tbody>
			</table>

			<p class="ttt-settings-shortcode-note">
				<?php esc_html_e( 'Values "yes/no", "true/false", and "1/0" are all accepted.', 'training-translation-tracker' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Extracts generated_at from the last good payload.
	 *
	 * @param mixed $payload Cached payload (or false).
	 * @return string Empty string when nothing is available.
	 */
	private function extract_generated_at( $payload ) {
		if ( is_array( $payload ) && isset( $payload['generated_at'] ) ) {
			return (string) $payload['generated_at'];
		}
		return '';
	}

	// ------------------------------------------------------------------- AJAX

	/**
	 * Admin AJAX: clear cache.
	 *
	 * @return void
	 */
	public function handle_clear_cache() {
		check_ajax_referer( 'ttt_clear_cache', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'training-translation-tracker' ) ),
				403
			);
			return;
		}

		delete_transient( TTT_TRANSIENT_KEY );
		// We keep the last-good entry so the frontend still shows something on fetch errors.

		wp_send_json_success(
			array(
				'message' => __( 'Cache cleared. The next page load will fetch fresh data.', 'training-translation-tracker' ),
			)
		);
	}

	/**
	 * Enqueues the admin JS only on our settings page.
	 *
	 * @param string $hook Hook name of the current admin page.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_training-translation-tracker' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'ttt-admin',
			TTT_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			TTT_VERSION,
			true
		);

		wp_localize_script(
			'ttt-admin',
			'tttAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'ttt_clear_cache' ),
				'clearing'  => __( 'Clearing cache…', 'training-translation-tracker' ),
				'errorText' => __( 'Error while clearing — please try again.', 'training-translation-tracker' ),
			)
		);
	}
}
