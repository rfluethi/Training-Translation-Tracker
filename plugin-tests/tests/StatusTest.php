<?php
/**
 * Direct tests for TTT_Status (the logic class extracted in 0.5.2).
 * Skipped automatically when running against a pre-split checkout —
 * the same logic is then covered through the TTT_Renderer wrappers
 * in RendererLogicTest.
 */

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'TTT_Status' ) ) {
			$this->markTestSkipped( 'TTT_Status does not exist yet (pre-0.5.2 checkout).' );
		}
		Monkey\setUp();
		Brain\Monkey\Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_normalize(): void {
		$this->assertSame( 'published', TTT_Status::normalize( 'done' ) );
		$this->assertSame( 'open', TTT_Status::normalize( 'whatever' ) );
		$this->assertSame( 'na', TTT_Status::normalize( 'na' ) );
	}

	public function test_calculate_stats_shape(): void {
		$stats = TTT_Status::calculate_stats( array() );
		$this->assertSame(
			array( 'total_items', 'published', 'review', 'wip', 'open', 'na', 'untouched' ),
			array_keys( $stats )
		);
		$this->assertSame( 0, array_sum( $stats ) );
	}

	public function test_component_order_constant_is_canonical(): void {
		$this->assertSame(
			array( 'thumbnails', 'text', 'subtitles', 'exercise', 'quiz', 'audio', 'video' ),
			TTT_Status::COMPONENT_ORDER
		);
	}

	public function test_collect_markers_empty_item(): void {
		$this->assertSame( array(), TTT_Status::collect_markers( array() ) );
	}
}
