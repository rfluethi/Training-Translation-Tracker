<?php
/**
 * Unit tests for TTT_Settings: avatar default, sanitize (allow-list, clamps,
 * checkbox).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	private TTT_Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'add_settings_error' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );
		$this->settings = new TTT_Settings();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_show_avatars_defaults_to_true_for_legacy_options(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'tracker_url' => TTT_DEFAULT_TRACKER_URL, 'cache_hours' => 12 )
		);
		$this->assertTrue( TTT_Settings::show_avatars(), 'options saved before 0.5.1 have no key -> default on' );
	}

	public function test_show_avatars_respects_stored_zero(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'tracker_url' => TTT_DEFAULT_TRACKER_URL, 'cache_hours' => 12, 'show_avatars' => 0 )
		);
		$this->assertFalse( TTT_Settings::show_avatars() );
	}

	public function test_sanitize_keeps_allowed_https_url(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$out = $this->settings->sanitize_settings(
			array( 'tracker_url' => 'https://raw.githubusercontent.com/foo/bar/data/tracker.json', 'cache_hours' => 12 )
		);
		$this->assertSame( 'https://raw.githubusercontent.com/foo/bar/data/tracker.json', $out['tracker_url'] );
	}

	public function test_sanitize_rejects_http_and_foreign_hosts(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'tracker_url' => 'https://raw.githubusercontent.com/kept/previous.json' )
		);
		foreach ( array( 'http://raw.githubusercontent.com/x.json', 'https://evil.example.com/x.json' ) as $bad ) {
			$out = $this->settings->sanitize_settings( array( 'tracker_url' => $bad, 'cache_hours' => 12 ) );
			$this->assertSame(
				'https://raw.githubusercontent.com/kept/previous.json',
				$out['tracker_url'],
				"must keep the previous value for: $bad"
			);
		}
	}

	public function test_sanitize_clamps_cache_hours(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$low  = $this->settings->sanitize_settings( array( 'tracker_url' => '', 'cache_hours' => 0 ) );
		$high = $this->settings->sanitize_settings( array( 'tracker_url' => '', 'cache_hours' => 999 ) );
		$this->assertSame( 1, $low['cache_hours'] );
		$this->assertSame( 168, $high['cache_hours'] );
	}

	public function test_sanitize_checkbox_absent_means_off(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$off = $this->settings->sanitize_settings( array( 'tracker_url' => '', 'cache_hours' => 12 ) );
		$on  = $this->settings->sanitize_settings( array( 'tracker_url' => '', 'cache_hours' => 12, 'show_avatars' => '1' ) );
		$this->assertSame( 0, $off['show_avatars'] );
		$this->assertSame( 1, $on['show_avatars'] );
	}
}
