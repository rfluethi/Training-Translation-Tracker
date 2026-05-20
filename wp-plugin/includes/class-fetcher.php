<?php
/**
 * Datenholer für die tracker.json.
 *
 * Cached den geparsten Payload als WordPress-Transient (TTL aus den Settings).
 * Hält parallel einen separaten „last-good"-Transient, der bei Fetch-Fehlern
 * verwendet wird, damit der Tracker nicht stumm bleibt (A.5.3).
 *
 * @package training-translation-tracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetcher-Klasse — keine Instanzen nötig, nur statische Helpers.
 */
class TTT_Fetcher {

	/**
	 * Liefert den Tracker-Payload (Dictionary aus der gecachten oder frisch geholten JSON).
	 *
	 * Reihenfolge:
	 *   1. Aktiver Cache (Transient TTT_TRANSIENT_KEY).
	 *   2. Frischer HTTP-Fetch. Bei Erfolg: in Cache + last-good schreiben.
	 *   3. Fehler / unsupported schema → last-good zurückgeben + interne Fehlernotiz.
	 *
	 * @return array {
	 *     @type array|null $payload  Geparste JSON oder null.
	 *     @type string     $source   'cache' | 'fresh' | 'last_good' | 'none'.
	 *     @type string     $error    Optionale Fehlermeldung (intern, fürs Backend).
	 * }
	 */
	public static function get() {
		$cached = get_transient( TTT_TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return self::result( $cached, 'cache' );
		}

		$url = TTT_Settings::get( 'tracker_url' );
		if ( empty( $url ) ) {
			return self::result( self::get_last_good(), 'last_good', __( 'Keine tracker.json-URL konfiguriert.', 'training-translation-tracker' ) );
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
	 * Hilfsmethode für die Rückgabe.
	 *
	 * @param array|null $payload Payload-Daten.
	 * @param string     $source  Quelle.
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
	 * Liest die last-good-Kopie (ohne TTL — bleibt liegen, bis erneut überschrieben).
	 *
	 * @return array|null
	 */
	private static function get_last_good() {
		$payload = get_transient( TTT_LAST_GOOD_KEY );
		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Speichert frischen Payload in beiden Slots (kurzlebigen Cache + last-good).
	 *
	 * @param array $payload Decodierte JSON.
	 * @return void
	 */
	private static function store( $payload ) {
		$hours = (int) TTT_Settings::get( 'cache_hours' );
		if ( $hours < 1 ) {
			$hours = TTT_DEFAULT_CACHE_HOURS;
		}
		set_transient( TTT_TRANSIENT_KEY, $payload, $hours * HOUR_IN_SECONDS );

		// last-good ohne TTL (wird nur überschrieben bei Erfolg). Wir geben ihm aber
		// einen sehr langen TTL, damit WordPress ihn nicht überraschend räumt.
		set_transient( TTT_LAST_GOOD_KEY, $payload, 30 * DAY_IN_SECONDS );
	}

	// -------------------------------------------------------------------- HTTP

	/**
	 * GET-Request → geparste JSON oder WP_Error.
	 *
	 * @param string $url Endpunkt.
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
			/* translators: %d: HTTP status code. */
			return new WP_Error(
				'ttt_http_error',
				sprintf(
					/* translators: %d HTTP status. */
					__( 'HTTP-Fehler beim Laden der tracker.json: %d', 'training-translation-tracker' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'ttt_json_error',
				/* translators: %s: JSON decode error message. */
				sprintf(
					__( 'tracker.json konnte nicht geparst werden: %s', 'training-translation-tracker' ),
					json_last_error_msg()
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ttt_json_shape', __( 'tracker.json hat unerwartete Struktur (kein Top-Level-Objekt).', 'training-translation-tracker' ) );
		}

		return $data;
	}

	// ------------------------------------------------------------------ schema

	/**
	 * Validiert die wichtigsten Pflichtfelder. Keine vollständige JSON-Schema-Validation —
	 * der Builder validiert ohnehin schon vor dem Commit.
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
					__( 'Schema-Version passt nicht. Erwartet: %1$d, gefunden: %2$s', 'training-translation-tracker' ),
					TTT_TRACKER_SCHEMA_VERSION,
					isset( $payload['schema_version'] ) ? (string) $payload['schema_version'] : 'fehlt'
				)
			);
		}

		foreach ( array( 'generated_at', 'stats', 'groups' ) as $required ) {
			if ( ! isset( $payload[ $required ] ) ) {
				return new WP_Error(
					'ttt_field_missing',
					sprintf(
						/* translators: %s: missing field name. */
						__( 'Pflichtfeld fehlt in tracker.json: %s', 'training-translation-tracker' ),
						$required
					)
				);
			}
		}

		if ( ! is_array( $payload['groups'] ) ) {
			return new WP_Error( 'ttt_groups_shape', __( '`groups` in tracker.json ist kein Array.', 'training-translation-tracker' ) );
		}

		return true;
	}
}
