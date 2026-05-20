<?php
/**
 * Settings-Seite unter Einstellungen → Translation Tracker.
 *
 * - Feld: URL der tracker.json
 * - Feld: Cache-Dauer in Stunden (1–168)
 * - Button: Cache jetzt leeren (AJAX)
 * - Anzeige: zuletzt erfolgreicher generated_at-Stempel
 *
 * @package training-translation-tracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings-Klasse.
 */
class TTT_Settings {

	/**
	 * Konstruktor: Hooks registrieren.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_ttt_clear_cache', array( $this, 'handle_clear_cache' ) );
	}

	/**
	 * Eintrag im Settings-Menü.
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
	 * Registriert die Settings-Felder über die WP Settings API.
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
			__( 'Datenquelle & Cache', 'training-translation-tracker' ),
			'__return_null',
			'training-translation-tracker'
		);

		add_settings_field(
			'ttt_tracker_url',
			__( 'URL der tracker.json', 'training-translation-tracker' ),
			array( $this, 'field_tracker_url' ),
			'training-translation-tracker',
			'ttt_section_main'
		);

		add_settings_field(
			'ttt_cache_hours',
			__( 'Cache-Dauer (Stunden)', 'training-translation-tracker' ),
			array( $this, 'field_cache_hours' ),
			'training-translation-tracker',
			'ttt_section_main'
		);
	}

	/**
	 * Wert-Bereinigung beim Speichern.
	 *
	 * @param array $input Roh-Eingaben aus dem Formular.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$tracker_url = isset( $input['tracker_url'] ) ? esc_url_raw( trim( (string) $input['tracker_url'] ) ) : '';
		$cache_hours = isset( $input['cache_hours'] ) ? absint( $input['cache_hours'] ) : TTT_DEFAULT_CACHE_HOURS;

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
	 * Bequemer Getter für Settings-Werte.
	 *
	 * @param string $key Welche Setting.
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
	 * Eingabefeld: tracker.json-URL.
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
			'Direkter Link zur tracker.json — typischerweise der raw.githubusercontent.com-Link auf den data-Branch der Inventory-Action.',
			'training-translation-tracker'
		);
		echo '</p>';
	}

	/**
	 * Eingabefeld: Cache-Stunden.
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
		echo esc_html__( '1–168 Stunden. Empfohlen: 12 (passt zum Action-Intervall).', 'training-translation-tracker' );
		echo '</span>';
	}

	// ----------------------------------------------------------------- render

	/**
	 * Rendert die komplette Settings-Seite inkl. Cache-Status und Clear-Button.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'training-translation-tracker' ) );
		}

		$last_good   = get_transient( TTT_LAST_GOOD_KEY );
		$generated   = $this->extract_generated_at( $last_good );
		$cache_state = false !== get_transient( TTT_TRANSIENT_KEY );

		// Inline-<style>-Block bewusst statt eines separaten admin-Stylesheets,
		// um den enqueue-Overhead für eine Handvoll Settings-spezifischer Regeln
		// zu vermeiden. Wenn die Settings-Seite mal wachsen sollte: in eigene
		// CSS-Datei umstellen und per wp_enqueue_style laden.
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		?>
		<style>
			/* Kleine Styles nur für diese Settings-Seite. */
			.ttt-settings-status-active   { color: #2271b1; }
			.ttt-settings-status-inactive { color: #999; }
			.ttt-settings-clear-msg       { margin-left: 10px; }
			.ttt-settings-shortcode-table { max-width: 800px; }
			.ttt-settings-shortcode-note  { margin-top: 0.75rem; }
			/* Status-Farben für die AJAX-Rückmeldung aus admin.js */
			.ttt-clear-msg-pending { color: #666; }
			.ttt-clear-msg-success { color: #46b450; }
			.ttt-clear-msg-error   { color: #dc3232; }
		</style>
		<?php // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Translation Tracker', 'training-translation-tracker' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'ttt_settings_group' );
				do_settings_sections( 'training-translation-tracker' );
				submit_button( __( 'Einstellungen speichern', 'training-translation-tracker' ) );
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Cache-Status', 'training-translation-tracker' ); ?></h2>
			<p>
				<?php if ( $cache_state ) : ?>
					<span class="ttt-settings-status-active">●</span>
					<?php esc_html_e( 'Cache ist aktiv.', 'training-translation-tracker' ); ?>
				<?php else : ?>
					<span class="ttt-settings-status-inactive">○</span>
					<?php esc_html_e( 'Cache ist leer — beim nächsten Shortcode-Aufruf wird neu geladen.', 'training-translation-tracker' ); ?>
				<?php endif; ?>
			</p>
			<?php if ( $generated ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: UTC timestamp from tracker.json. */
						esc_html__( 'Letzter erfolgreicher Stand der tracker.json: %s (UTC)', 'training-translation-tracker' ),
						'<code>' . esc_html( $generated ) . '</code>'
					);
					?>
				</p>
			<?php endif; ?>

			<p>
				<button type="button" id="ttt-clear-cache" class="button button-secondary">
					<?php esc_html_e( 'Cache jetzt leeren', 'training-translation-tracker' ); ?>
				</button>
				<span id="ttt-clear-cache-msg" class="ttt-settings-clear-msg"></span>
			</p>

			<hr>

			<h2><?php esc_html_e( 'Shortcodes', 'training-translation-tracker' ); ?></h2>
			<p>
				<?php esc_html_e( 'Auf einer beliebigen WordPress-Seite einfügen. Mehrere Attribute beliebig kombinierbar.', 'training-translation-tracker' ); ?>
			</p>
			<table class="widefat striped ttt-settings-shortcode-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Shortcode', 'training-translation-tracker' ); ?></th>
						<th><?php esc_html_e( 'Wirkung', 'training-translation-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>[translation_tracker]</code></td>
						<td><?php esc_html_e( 'Alles — alle Pathways, Handbuch (falls vorhanden), Orphan-Gruppe, Stats-Header.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker pathway="user"]</code></td>
						<td><?php esc_html_e( 'Nur die Pathway mit Slug "user" anzeigen (z. B. Start using WordPress).', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker pathway="lesson-plans"]</code></td>
						<td><?php esc_html_e( 'Nur die Pathway "Lesson Plans".', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker pathway="user,lesson-plans"]</code></td>
						<td><?php esc_html_e( 'Mehrere Pathways gleichzeitig — Komma getrennt.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker show_orphans="no"]</code></td>
						<td><?php esc_html_e( 'Orphan-Gruppe ("Sonstige") ausblenden.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker show_handbook="no"]</code></td>
						<td><?php esc_html_e( 'Handbook-Gruppe ausblenden (nützlich sobald Handbook-Inhalte dazukommen).', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker show_stats="no"]</code></td>
						<td><?php esc_html_e( 'Stats-Header oben ausblenden.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td><code>[translation_tracker show_pathways="no"]</code></td>
						<td><?php esc_html_e( 'Alle Pathway-Gruppen ausblenden — übrig bleiben Handbook und Orphan-Gruppe.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td>
							<code>[translation_tracker show_pathways="no" show_orphans="no"]</code>
						</td>
						<td><?php esc_html_e( 'Nur das Training Handbook anzeigen — typisch für eine eigene Handbook-Seite.', 'training-translation-tracker' ); ?></td>
					</tr>
					<tr>
						<td>
							<code>[translation_tracker pathway="lesson-plans" show_stats="no" show_orphans="no"]</code>
						</td>
						<td><?php esc_html_e( 'Kombination: nur Lesson Plans, ohne Stats und ohne Orphans — typisch für eine eigene Lesson-Plans-Seite.', 'training-translation-tracker' ); ?></td>
					</tr>
				</tbody>
			</table>

			<p class="ttt-settings-shortcode-note">
				<?php esc_html_e( 'Werte "yes/no", "true/false" und "1/0" werden alle akzeptiert.', 'training-translation-tracker' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Holt generated_at aus dem letzten guten Payload.
	 *
	 * @param mixed $payload Cached payload (oder false).
	 * @return string Empty string, wenn nichts da.
	 */
	private function extract_generated_at( $payload ) {
		if ( is_array( $payload ) && isset( $payload['generated_at'] ) ) {
			return (string) $payload['generated_at'];
		}
		return '';
	}

	// ------------------------------------------------------------------- AJAX

	/**
	 * Admin-AJAX: Cache leeren.
	 *
	 * @return void
	 */
	public function handle_clear_cache() {
		check_ajax_referer( 'ttt_clear_cache', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Keine Berechtigung.', 'training-translation-tracker' ) ),
				403
			);
		}

		delete_transient( TTT_TRANSIENT_KEY );
		// Den last-good-Eintrag lassen wir stehen, damit das Frontend bei Fetch-Fehlern weiter etwas zeigt.

		wp_send_json_success(
			array(
				'message' => __( 'Cache geleert. Beim nächsten Seitenaufruf wird neu geladen.', 'training-translation-tracker' ),
			)
		);
	}

	/**
	 * Lädt das Admin-JS nur auf unserer Settings-Seite.
	 *
	 * @param string $hook Hook-Name der aktuellen Admin-Seite.
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
				'clearing'  => __( 'Cache wird geleert…', 'training-translation-tracker' ),
				'errorText' => __( 'Fehler beim Leeren — versuche es nochmal.', 'training-translation-tracker' ),
			)
		);
	}
}
