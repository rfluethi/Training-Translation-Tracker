<?php
/**
 * Plugin Name:       Training Translation Tracker
 * Plugin URI:        https://github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin
 * Description:       Dashboard for the translation progress of the Learn WP DACH Team.
 * Version:           0.4.9
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
// Constants
// -----------------------------------------------------------------------------

define( 'TTT_VERSION', '0.4.9' );
define( 'TTT_PLUGIN_FILE', __FILE__ );
define( 'TTT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TTT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// This schema version must match schemas/tracker.schema.json. If the
// tracker.json carries a different `schema_version`, the plugin rejects it.
define( 'TTT_TRACKER_SCHEMA_VERSION', 1 );

// Default source and cache hours, overridable via the settings page.
define(
	'TTT_DEFAULT_TRACKER_URL',
	'https://raw.githubusercontent.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/data/tracker.json'
);
define( 'TTT_DEFAULT_CACHE_HOURS', 12 );

// Option keys (one option, dict-style, avoids wp_options sprawl).
define( 'TTT_OPTION_KEY', 'ttt_settings' );
// Transient for the parsed tracker.json (valid for TTT_DEFAULT_CACHE_HOURS hours).
define( 'TTT_TRANSIENT_KEY', 'ttt_tracker_payload' );
// Transient for the last successful state (NOT auto-invalidated),
// fallback on API errors (A.5.3).
define( 'TTT_LAST_GOOD_KEY', 'ttt_last_good_payload' );

// -----------------------------------------------------------------------------
// Class loader
// -----------------------------------------------------------------------------

require_once TTT_PLUGIN_DIR . 'includes/class-settings.php';
require_once TTT_PLUGIN_DIR . 'includes/class-fetcher.php';
require_once TTT_PLUGIN_DIR . 'includes/class-renderer.php';

// -----------------------------------------------------------------------------
// Initialization
// -----------------------------------------------------------------------------

/**
 * Loads translation files from the plugin's languages/ subfolder.
 *
 * Required for GitHub distribution: WordPress 4.6+ only auto-loads
 * translations when the plugin is hosted on wordpress.org or when the
 * .mo files live under wp-content/languages/plugins/. With our
 * GitHub-only distribution the files instead live inside the plugin
 * folder (wp-content/plugins/training-translation-tracker/languages/),
 * which requires this explicit call.
 *
 * @return void
 */
function ttt_load_textdomain() {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- GitHub-distributed plugin; auto-load via the wp.org convention does not apply here.
	load_plugin_textdomain(
		'training-translation-tracker',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'ttt_load_textdomain' );

/**
 * Initializes the main components.
 *
 * @return void
 */
function ttt_init() {
	new TTT_Settings();
	new TTT_Renderer();
}
add_action( 'plugins_loaded', 'ttt_init' );

// -----------------------------------------------------------------------------
// Activation / deactivation / uninstall
// -----------------------------------------------------------------------------

/**
 * Runs once on plugin activation.
 * Seeds default values for the settings if none exist yet.
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
 * Runs on plugin deactivation. Only clears the cache,
 * the settings are kept (in case of re-activation).
 *
 * @return void
 */
function ttt_deactivate() {
	delete_transient( TTT_TRANSIENT_KEY );
}
register_deactivation_hook( __FILE__, 'ttt_deactivate' );
