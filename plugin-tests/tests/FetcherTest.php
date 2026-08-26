<?php
/**
 * Unit tests for TTT_Fetcher: cache, backoff, error fallback, storage.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FetcherTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_settings( string $url = TTT_DEFAULT_TRACKER_URL, int $hours = 12 ): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'tracker_url' => $url,
				'cache_hours' => $hours,
			)
		);
	}

	private function valid_payload(): array {
		return array(
			'schema_version' => 1,
			'generated_at'   => '2026-08-26T00:00:00Z',
			'stats'          => array( 'total_items' => 0 ),
			'groups'         => array(),
		);
	}

	public function test_cache_hit_returns_cached_payload_without_http(): void {
		$payload = $this->valid_payload();
		Functions\when( 'get_transient' )->alias(
			fn( $key ) => TTT_TRANSIENT_KEY === $key ? $payload : false
		);
		Functions\expect( 'wp_remote_get' )->never();

		$result = TTT_Fetcher::get();
		$this->assertSame( 'cache', $result['source'] );
		$this->assertSame( $payload, $result['payload'] );
	}

	public function test_active_backoff_serves_last_good_without_http(): void {
		$last_good = $this->valid_payload();
		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( $last_good ) {
				if ( TTT_BACKOFF_KEY === $key ) {
					return 1;
				}
				if ( TTT_LAST_GOOD_KEY === $key ) {
					return $last_good;
				}
				return false;
			}
		);
		Functions\expect( 'wp_remote_get' )->never();

		$result = TTT_Fetcher::get();
		$this->assertSame( 'last_good', $result['source'] );
		$this->assertSame( $last_good, $result['payload'] );
	}

	public function test_http_error_starts_backoff_and_returns_last_good(): void {
		$last_good = $this->valid_payload();
		$set       = array();
		$this->stub_settings();
		Functions\when( 'get_transient' )->alias(
			fn( $key ) => TTT_LAST_GOOD_KEY === $key ? $last_good : false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$set ) {
				$set[ $key ] = array( $value, $ttl );
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http', 'boom' ) );

		$result = TTT_Fetcher::get();
		$this->assertSame( 'last_good', $result['source'] );
		$this->assertSame( 'boom', $result['error'] );
		$this->assertArrayHasKey( TTT_BACKOFF_KEY, $set, 'a failed fetch must start the backoff window' );
		$this->assertSame( 5 * MINUTE_IN_SECONDS, $set[ TTT_BACKOFF_KEY ][1] );
	}

	public function test_fetch_uses_five_second_timeout(): void {
		$this->stub_settings();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$captured = null;
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) use ( &$captured ) {
				$captured = $args;
				return new WP_Error( 'x', 'x' );
			}
		);

		TTT_Fetcher::get();
		$this->assertSame( 5, $captured['timeout'], 'timeout must be 5 s so a slow GitHub cannot stall the page' );
	}

	public function test_successful_fetch_stores_cache_and_last_good(): void {
		$payload = $this->valid_payload();
		$set     = array();
		$this->stub_settings( TTT_DEFAULT_TRACKER_URL, 7 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$set ) {
				$set[ $key ] = array( $value, $ttl );
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => wp_json_encode_fixture( $payload ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $payload ) );

		$result = TTT_Fetcher::get();
		$this->assertSame( 'fresh', $result['source'] );
		$this->assertSame( 7 * HOUR_IN_SECONDS, $set[ TTT_TRANSIENT_KEY ][1], 'cache TTL follows the cache_hours setting' );
		$this->assertSame( 30 * DAY_IN_SECONDS, $set[ TTT_LAST_GOOD_KEY ][1] );
		$this->assertArrayNotHasKey( TTT_BACKOFF_KEY, $set, 'a successful fetch must not start a backoff' );
	}

	public function test_schema_version_mismatch_starts_backoff(): void {
		$payload                   = $this->valid_payload();
		$payload['schema_version'] = 99;
		$set                       = array();
		$this->stub_settings();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$set ) {
				$set[ $key ] = true;
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $payload ) );

		$result = TTT_Fetcher::get();
		$this->assertSame( 'none', $result['source'], 'no last_good stored -> none' );
		$this->assertStringContainsString( 'Schema', $result['error'] );
		$this->assertArrayHasKey( TTT_BACKOFF_KEY, $set );
	}

	public function test_top_level_json_array_is_rejected(): void {
		$this->stub_settings();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '[1,2,3]' );

		$result = TTT_Fetcher::get();
		$this->assertSame( 'none', $result['source'] );
		$this->assertStringContainsString( 'unexpected structure', $result['error'] );
	}
}

/** Helper used once above; kept trivial on purpose. */
function wp_json_encode_fixture( $data ) {
	return json_encode( $data );
}
