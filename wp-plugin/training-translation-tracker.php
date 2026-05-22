<?php
/**
 * Plugin Name:       Training Translation Tracker
 * Plugin URI:        https://github.com/rfluethi/learn-wp-dach-sitzungen
 * Description:       Dashboard for the translation progress of the Learn WP DACH Team.
 * Version:           0.4.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Learn WP DACH Team
 * Author URI:        https://github.com/rfluethi/learn-wp-dach-team
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       training-translation-tracker
 * Domain Path:       /languages
 *
 * @package           training-translation-tracker
 */

defined( 'ABSPATH' ) || exit;

// -----------------------------------------------------------------------------
// Konstanten
// -----------------------------------------------------------------------------

define( 'TTT_VERSION', '0.4.1' );
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
// GitHub-basiertes Auto-Update via plugin-update-checker (YahnisElsts, MIT).
// Die Library liegt unter lib/plugin-update-checker/ und prüft regelmäßig
// die GitHub-Releases-API auf neue Tags. Wenn ein neuer Tag (z. B. `v0.4.0`)
// existiert UND die darin enthaltene Plugin-Version höher ist als die
// installierte, zeigt WordPress den vertrauten "Update verfügbar"-Hinweis.
// Der Endbenutzer braucht keine zusätzlichen Plugins, der Updater ist
// Teil des Plugin-ZIPs.
// -----------------------------------------------------------------------------

require_once TTT_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ttt_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/',
	__FILE__,
	'training-translation-tracker'
);
// Wir veröffentlichen Releases als GitHub-Release-Assets (ZIPs vom
// release-plugin.yml-Workflow). Dadurch lädt PUC die offizielle ZIP statt
// einen automatisch generierten Tarball, der den falschen Ordnernamen hätte.
$ttt_update_checker->getVcsApi()->enableReleaseAssets();

// -----------------------------------------------------------------------------
// Initialisierung
// -----------------------------------------------------------------------------

/**
 * Lädt die Übersetzungs-Dateien aus dem languages/-Unterordner des Plugins.
 *
 * Wird für GitHub-Distribution gebraucht: WordPress 4.6+ lädt Translations
 * automatisch nur, wenn das Plugin auf wordpress.org gehostet ist oder die
 * .mo-Dateien unter wp-content/languages/plugins/ liegen. Bei unserem
 * GitHub-only-Vertrieb sitzen die Dateien hingegen unter dem Plugin-Ordner
 * selbst (wp-content/plugins/training-translation-tracker/languages/), und
 * dafür braucht es den expliziten Aufruf.
 *
 * @return void
 */
function ttt_load_textdomain() {
	load_plugin_textdomain(
		'training-translation-tracker',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'ttt_load_textdomain' );

/**
 * Initialisiert die Hauptkomponenten.
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
