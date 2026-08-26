<?php
/**
 * Test bootstrap: loads Brain Monkey and the plugin classes.
 *
 * The plugin files guard with `defined( 'ABSPATH' ) || exit;`, so ABSPATH
 * and the plugin constants are defined here before the includes load.
 * WordPress functions themselves are mocked per-test via Brain Monkey.
 */

require_once __DIR__ . '/../vendor/autoload.php';

define( 'ABSPATH', '/tmp/wordpress/' );

// Constants normally defined in training-translation-tracker.php. Defined
// here directly because loading the main file would also register hooks.
define( 'TTT_VERSION', '0.5.1' );
define( 'TTT_TRACKER_SCHEMA_VERSION', 1 );
define( 'TTT_DEFAULT_TRACKER_URL', 'https://raw.githubusercontent.com/rfluethi/Training-Translation-Tracker/data/tracker.json' );
define( 'TTT_DEFAULT_CACHE_HOURS', 12 );
define( 'TTT_OPTION_KEY', 'ttt_settings' );
define( 'TTT_TRANSIENT_KEY', 'ttt_tracker_payload' );
define( 'TTT_LAST_GOOD_KEY', 'ttt_last_good_payload' );
define( 'TTT_BACKOFF_KEY', 'ttt_fetch_backoff' );

// WordPress time constants.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// Minimal WP_Error stand-in (the plugin only uses get_error_message()).
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

// is_wp_error() must exist at load time for some code paths; defined as a
// real function (not a Brain Monkey stub) because its behavior is fixed.
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// Repo layout: plugin-tests/ sits next to wp-plugin/ at the repo root.
define( 'TTT_TEST_PLUGIN_DIR', realpath( dirname( __DIR__ ) . '/../wp-plugin' ) );

require_once TTT_TEST_PLUGIN_DIR . '/includes/class-settings.php';
require_once TTT_TEST_PLUGIN_DIR . '/includes/class-fetcher.php';
// Present since the 0.5.2 renderer split; guarded so the suite also runs
// against a 0.5.1 checkout.
foreach ( array( 'class-status.php', 'class-styles.php' ) as $ttt_split_file ) {
	if ( file_exists( TTT_TEST_PLUGIN_DIR . '/includes/' . $ttt_split_file ) ) {
		require_once TTT_TEST_PLUGIN_DIR . '/includes/' . $ttt_split_file;
	}
}
require_once TTT_TEST_PLUGIN_DIR . '/includes/class-renderer.php';
