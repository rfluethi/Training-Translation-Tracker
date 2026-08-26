<?php
/**
 * Unit tests for the pure logic inside TTT_Renderer (status normalization,
 * stats counting, component ordering, filters). Private methods are reached
 * via reflection; HTML emission is covered by the snapshot harness.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RendererLogicTest extends TestCase {

	private TTT_Renderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_shortcode' )->justReturn( true );
		$this->renderer = new TTT_Renderer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call( string $method, ...$args ) {
		$ref = new ReflectionMethod( TTT_Renderer::class, $method );
		return $ref->invoke( $this->renderer, ...$args );
	}

	private static function item( string $status, array $components = array() ): array {
		return array(
			'type'           => 'lesson',
			'slug'           => 's',
			'title_en'       => 'T',
			'url_en'         => 'https://learn.wordpress.org/lesson/t/',
			'overall_status' => $status,
			'components'     => $components,
		);
	}

	private static function orphan_group( array $items ): array {
		return array(
			'type'  => 'orphan',
			'label' => 'Other',
			'items' => $items,
		);
	}

	// ---------------------------------------------------------- normalization

	public function test_done_is_displayed_as_published(): void {
		$this->assertSame( 'published', $this->call( 'normalize_overall_status', 'done' ) );
	}

	public function test_known_statuses_pass_through(): void {
		foreach ( array( 'published', 'review', 'wip', 'open', 'na' ) as $status ) {
			$this->assertSame( $status, $this->call( 'normalize_overall_status', $status ) );
		}
	}

	public function test_unknown_status_falls_back_to_open(): void {
		$this->assertSame( 'open', $this->call( 'normalize_overall_status', 'banana' ) );
		$this->assertSame( 'open', $this->call( 'normalize_overall_status', '' ) );
	}

	// ------------------------------------------------------------------ stats

	public function test_stats_count_published_and_unknown_consistently(): void {
		$groups = array(
			self::orphan_group(
				array(
					self::item( 'published' ),
					self::item( 'done' ),      // legacy -> published
					self::item( 'review' ),
					self::item( 'banana' ),    // unknown -> open
				)
			),
		);
		$stats = $this->call( 'calculate_stats_from_groups', $groups );
		$this->assertSame( 4, $stats['total_items'] );
		$this->assertSame( 2, $stats['published'] );
		$this->assertSame( 1, $stats['review'] );
		$this->assertSame( 1, $stats['open'] );
	}

	public function test_stats_untouched_counts_all_unset_items_server_side(): void {
		$unset_components = array(
			array( 'name' => 'text', 'status' => 'unset' ),
			array( 'name' => 'video', 'status' => 'unset' ),
		);
		$mixed_components = array(
			array( 'name' => 'text', 'status' => 'unset' ),
			array( 'name' => 'video', 'status' => 'open' ),
		);
		$groups = array(
			self::orphan_group(
				array(
					self::item( 'open', $unset_components ),
					self::item( 'open', $mixed_components ),
					self::item( 'open' ), // no components at all -> not untouched
				)
			),
		);
		$stats = $this->call( 'calculate_stats_from_groups', $groups );
		$this->assertSame( 1, $stats['untouched'] );
	}

	// ------------------------------------------------------------- components

	public function test_component_order_is_canonical_then_first_seen(): void {
		$groups = array(
			self::orphan_group(
				array(
					self::item(
						'open',
						array(
							array( 'name' => 'podcast', 'status' => 'open' ), // unknown to the plugin
							array( 'name' => 'video', 'status' => 'open' ),
						)
					),
					self::item(
						'open',
						array(
							array( 'name' => 'text', 'status' => 'open' ),
						)
					),
				)
			),
		);
		$names = $this->call( 'collect_component_names', $groups );
		$this->assertSame( array( 'text', 'video', 'podcast' ), $names, 'canonical order first, new components appended' );
	}

	public function test_component_order_falls_back_to_canonical_when_empty(): void {
		$names = $this->call( 'collect_component_names', array( self::orphan_group( array( self::item( 'open' ) ) ) ) );
		$this->assertSame(
			array( 'thumbnails', 'text', 'subtitles', 'exercise', 'quiz', 'audio', 'video' ),
			$names
		);
	}

	// ---------------------------------------------------------------- filters

	public function test_bool_attr_accepts_all_documented_spellings(): void {
		foreach ( array( 'no', 'false', '0', 'off' ) as $v ) {
			$this->assertFalse( $this->call( 'bool_attr', $v, true ) );
		}
		foreach ( array( 'yes', 'true', '1', 'on' ) as $v ) {
			$this->assertTrue( $this->call( 'bool_attr', $v, false ) );
		}
		$this->assertTrue( $this->call( 'bool_attr', 'garbage', true ) );
	}

	public function test_pathway_filter_parsing(): void {
		$this->assertNull( $this->call( 'parse_pathway_filter', '' ) );
		$this->assertNull( $this->call( 'parse_pathway_filter', 'all' ) );
		$this->assertSame( array( 'user', 'lesson-plans' ), $this->call( 'parse_pathway_filter', ' user , lesson-plans ' ) );
	}

	public function test_group_filter_matches_slug_and_label(): void {
		Functions\when( 'sanitize_title' )->alias(
			fn( $s ) => strtolower( str_replace( ' ', '-', trim( $s ) ) )
		);
		$group = array( 'type' => 'pathway', 'slug' => 'user', 'label' => 'Beginner WordPress User', 'courses' => array() );
		$this->assertTrue( $this->call( 'group_passes_filter', $group, array( 'user' ), true, true, true ) );
		$this->assertTrue( $this->call( 'group_passes_filter', $group, array( 'beginner-wordpress-user' ), true, true, true ) );
		$this->assertFalse( $this->call( 'group_passes_filter', $group, array( 'other' ), true, true, true ) );
		$this->assertFalse( $this->call( 'group_passes_filter', $group, null, true, true, false ), 'show_pathways=no hides all pathways' );
	}

	// ---------------------------------------------------------------- markers

	public function test_markers_collected_for_all_flag_fields(): void {
		$item = array(
			'parse_error'      => true,
			'orphan_reason'    => 'outside_scope',
			'duplicate_issues' => array( array( 'number' => 2 ) ),
			'draft_original'   => true,
		);
		$keys = array_column( $this->call( 'collect_markers', $item ), 'key' );
		$this->assertSame( array( 'parse-error', 'outside-scope', 'duplicate', 'draft-original' ), $keys );
	}
}
