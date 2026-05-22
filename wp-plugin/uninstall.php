<?php
/**
 * Runs when the plugin is removed via the WordPress admin.
 * Cleans up options and transients.
 *
 * @package training-translation-tracker
 */

// Safety guard: only execute when invoked by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ttt_settings' );
delete_transient( 'ttt_tracker_payload' );
delete_transient( 'ttt_last_good_payload' );
