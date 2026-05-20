<?php
/**
 * Wird ausgeführt, wenn das Plugin per WordPress-Admin entfernt wird.
 * Räumt Options und Transients auf.
 *
 * @package training-translation-tracker
 */

// Sicherheitsabbruch: nur über WordPress aufrufen.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ttt_settings' );
delete_transient( 'ttt_tracker_payload' );
delete_transient( 'ttt_last_good_payload' );
