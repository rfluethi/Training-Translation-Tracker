<?php
/**
 * Plugin Name:       Training Translation Tracker
 * Plugin URI:        https://github.com/rfluethi/learn-wp-dach-sitzungen
 * Description:       Dashboard für den Übersetzungsfortschritt des Learn WP DACH Teams.
 * Version:           0.2.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Learn WP DACH Team
 * Author URI:        https://github.com/rfluethi/learn-wp-dach-team
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       training-translation-tracker
 *
 * @package           training-translation-tracker
 */

defined( 'ABSPATH' ) || exit;

// -----------------------------------------------------------------------------
// Konstanten
// -----------------------------------------------------------------------------

define( 'TTT_VERSION', '0.2.4' );
define( 'TTT_PLUGIN_FILE', __FILE__ );
define( 'TTT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TTT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Diese Schema-Version muss zu schemas/tracker.schema.json passen. Wenn das
// tracker.json eine andere `schema_version` trägt, lehnt das Plugin den Inhalt ab.
define( 'TTT_TRACKER_SCHEMA_VERSION', 1 );

// Default-Quelle und Cache-Stunden — über die Settings-Seite überschreibbar.
define(
	'TTT_DEFAULT_TRACKER_URL',
	'https://raw.githubusercontent.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/data/tracker.json'
);
define( 'TTT_DEFAULT_CACHE_HOURS', 12 );

// Option-Keys (eine Option, dict-artig — vermeidet wp_options-Wildwuchs).
define( 'TTT_OPTION_KEY', 'ttt_settings' );
// Transient für die geparste tracker.json (gilt für TTT_DEFAULT_CACHE_HOURS Stunden).
define( 'TTT_TRANSIENT_KEY', 'ttt_tracker_payload' );
// Transient für den letzten erfolgreichen Stand (wird *nicht* automatisch invalidiert) —
// fallback bei API-Fehlern (A.5.3).
define( 'TTT_LAST_GOOD_KEY', 'ttt_last_good_payload' );

// -----------------------------------------------------------------------------
// Klassen-Loader
// -----------------------------------------------------------------------------

require_once TTT_PLUGIN_DIR . 'includes/class-settings.php';
require_once TTT_PLUGIN_DIR . 'includes/class-fetcher.php';
require_once TTT_PLUGIN_DIR . 'includes/class-renderer.php';

// -----------------------------------------------------------------------------
// Initialisierung
// -----------------------------------------------------------------------------

/**
 * Initialisiert die Hauptkomponenten.
 *
 * Hinweis: load_plugin_textdomain() wird nicht aufgerufen — seit WordPress 4.6
 * lädt WordPress die Übersetzungen automatisch, sobald das Plugin auf
 * WordPress.org gehostet wird oder die Sprach-Dateien unter wp-content/languages/
 * liegen.
 *
 * @return void
 */
function ttt_init() {
	new TTT_Settings();
	new TTT_Renderer();
}
add_action( 'plugins_loaded', 'ttt_init' );

// -----------------------------------------------------------------------------
// Aktivierung / Deaktivierung / Uninstall
// -----------------------------------------------------------------------------

/**
 * Wird einmalig bei Plugin-Aktivierung aufgerufen.
 * Setzt Default-Werte für die Settings, falls noch keine vorhanden sind.
 *
 * @return void
 */
function ttt_activate() {
	$existing = get_option( TTT_OPTION_KEY );
	if ( false === $existing ) {
		add_option(
			TTT_OPTION_KEY,
			array(
				'tracker_url' => TTT_DEFAULT_TRACKER_URL,
				'cache_hours' => TTT_DEFAULT_CACHE_HOURS,
			)
		);
	}
}
register_activation_hook( __FILE__, 'ttt_activate' );

/**
 * Wird bei Plugin-Deaktivierung aufgerufen — räumt nur den Cache auf,
 * lässt aber die Settings stehen (für den Fall einer Re-Aktivierung).
 *
 * @return void
 */
function ttt_deactivate() {
	delete_transient( TTT_TRANSIENT_KEY );
}
register_deactivation_hook( __FILE__, 'ttt_deactivate' );
