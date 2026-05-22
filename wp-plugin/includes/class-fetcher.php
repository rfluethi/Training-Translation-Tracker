<?php
/**
 * Data fetcher for the tracker.json.
 *
 * Caches the parsed payload as a WordPress transient (TTL from the settings).
 * Holds a separate "last-good" transient that is used on fetch errors so the
 * tracker does not go silent (A.5.3).
 *
 * @package training-translation-tracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetcher class. No instances needed, static helpers only.
 */
class TTT_Fetcher {

	/**
	 * Returns the tracker payload (dictionary parsed from the cached or freshly fetched JSON).
	 *
	 * Order:
	 *   1. Active cache (transient TTT_TRANSIENT_KEY).
	 *   2. Fresh HTTP fetch. On success: write to cache + last-good.
	 *   3. Error / unsupported schema, return last-good + internal error note.
	 *
	 * @return array {
	 *     @type array|null $payload  Parsed JSON or null.
	 *     @type string     $source   'cache' | 'fresh' | 'last_good' | 'none'.
	 *     @type string     $error    Optional error message (internal, for the backend).
	 * }
	 */
	public static function get() {
		$cached = get_transient( TTT_TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return self::result( $cached, 'cache' );
		}

		$url = TTT_Settings::get( 'tracker_url' );
		if ( empty( $url ) ) {
			return self::result( self::get_last_good(), 'last_good', __( 'No tracker.json URL configured.', 'training-translation-tracker' ) );
		}

		$fetch = self::http_get_json( $url );
		if ( is_wp_error( $fetch ) ) {
			return self::result( self::get_last_good(), 'last_good', $fetch->get_error_message() );
		}

		$check = self::validate_payload( $fetch );
		if ( is_wp_error( $check ) ) {
			return self::result( self::get_last_good(), 'last_good', $check->get_error_message() );
		}

		self::store( $fetch );
		return self::result( $fetch, 'fresh' );
	}

	/**
	 * Helper method for the return value.
	 *
	 * @param array|null $payload Payload data.
	 * @param string     $source  Source.
	 * @param string     $error   Optional.
	 * @return array
	 */
	private static function result( $payload, $source, $error = '' ) {
		return array(
			'payload' => is_array( $payload ) ? $payload : null,
			'source'  => is_array( $payload ) ? $source : 'none',
			'error'   => $error,
		);
	}

	/**
	 * Reads the last-good copy (no TTL, kept until overwritten by a new success).
	 *
	 * @return array|null
	 */
	private static function get_last_good() {
		$payload = get_transient( TTT_LAST_GOOD_KEY );
		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Stores a fresh payload in both slots (short-lived cache + last-good).
	 *
	 * @param array $payload Decoded JSON.
	 * @return void
	 */
	private static function store( $payload ) {
		$hours = (int) TTT_Settings::get( 'cache_hours' );
		if ( $hours < 1 ) {
			$hours = TTT_DEFAULT_CACHE_HOURS;
		}
		set_transient( TTT_TRANSIENT_KEY, $payload, $hours * HOUR_IN_SECONDS );

		// last-good has no real TTL (it is only overwritten on success). We do
		// give it a very long TTL so WordPress does not reap it unexpectedly.
		set_transient( TTT_LAST_GOOD_KEY, $payload, 30 * DAY_IN_SECONDS );
	}

	// -------------------------------------------------------------------- HTTP

	/**
	 * GET request, returns parsed JSON or WP_Error.
	 *
	 * @param string $url Endpoint.
	 * @return array|WP_Error
	 */
	private static function http_get_json( $url ) {
		$args = array(
			'timeout'    => 15,
			'redirection' => 3,
			'user-agent' => 'training-translation-tracker/' . TTT_VERSION . ' (+wp-plugin)',
			'headers'    => array(
				'Accept' => 'application/json',
			),
		);

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'ttt_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'HTTP error while loading tracker.json: %d', 'training-translation-tracker' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'ttt_json_error',
				sprintf(
					/* translators: %s: JSON decode error message. */
					__( 'tracker.json could not be parsed: %s', 'training-translation-tracker' ),
					json_last_error_msg()
				)
			);
		}

		// Reject both non-array shapes and JSON top-level arrays. The tracker
		// schema requires an object at the top level (with keys like
		// schema_version, generated_at, stats, groups). PHP arrays double
		// as both maps and lists, so we use array_is_list() to detect the
		// list case explicitly.
		if ( ! is_array( $data ) || ( function_exists( 'array_is_list' ) && array_is_list( $data ) ) ) {
			return new WP_Error( 'ttt_json_shape', __( 'tracker.json has unexpected structure (no top-level object).', 'training-translation-tracker' ) );
		}

		return $data;
	}

	// ------------------------------------------------------------------ schema

	/**
	 * Validates the most important required fields. Not a full JSON Schema validation,
	 * the builder already validates before committing.
	 *
	 * @param array $payload Decoded JSON.
	 * @return true|WP_Error
	 */
	private static function validate_payload( $payload ) {
		if ( ! isset( $payload['schema_version'] ) || (int) $payload['schema_version'] !== TTT_TRACKER_SCHEMA_VERSION ) {
			return new WP_Error(
				'ttt_schema_mismatch',
				sprintf(
					/* translators: 1: expected schema version, 2: actual. */
					__( 'Schema version mismatch. Expected: %1$d, found: %2$s', 'training-translation-tracker' ),
					TTT_TRACKER_SCHEMA_VERSION,
					isset( $payload['schema_version'] ) ? (string) $payload['schema_version'] : __( 'missing', 'training-translation-tracker' )
				)
			);
		}

		foreach ( array( 'generated_at', 'stats', 'groups' ) as $required ) {
			if ( ! isset( $payload[ $required ] ) ) {
				return new WP_Error(
					'ttt_field_missing',
					sprintf(
						/* translators: %s: missing field name. */
						__( 'Required field missing in tracker.json: %s', 'training-translation-tracker' ),
						$required
					)
				);
			}
		}

		if ( ! is_array( $payload['groups'] ) ) {
			return new WP_Error( 'ttt_groups_shape', __( '`groups` in tracker.json is not an array.', 'training-translation-tracker' ) );
		}

		return true;
	}
}
