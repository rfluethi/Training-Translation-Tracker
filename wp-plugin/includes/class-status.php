<?php
/**
 * Pure status and data logic for the tracker (extracted from TTT_Renderer
 * in 0.5.2): status normalization, stats counting, component ordering and
 * item markers. No HTML, no WordPress state — fully unit-testable.
 *
 * @package training-translation-tracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status/data helper class. Static only; no state.
 */
class TTT_Status {

	/**
	 * Canonical order of components in the card footer row.
	 * Components not present in the item are skipped.
	 *
	 * @var array<int,string>
	 */
	public const COMPONENT_ORDER = array(
		'thumbnails', 'text', 'subtitles', 'exercise', 'quiz', 'audio', 'video',
	);

	/**
	 * Overall statuses the frontend knows how to display (0.5.0).
	 *
	 * Since 0.5.0 the Project V2 board status leads: the action maps
	 * "Published or Closed" to `published` (see status-map.yml). The
	 * legacy rollup value `done` is normalized to `published` for
	 * display, and unknown values fall back to `open`, so the stats
	 * count and the card border can never disagree.
	 *
	 * @var array<int,string>
	 */
	public const KNOWN_OVERALL_STATUSES = array( 'published', 'review', 'wip', 'open', 'na' );

	/**
	 * Normalizes an overall_status value to a displayable one (0.5.0).
	 *
	 * `done` (legacy rollup, pre-0.5.0 tracker.json) is treated as
	 * `published`; anything unknown falls back to `open`. Used for both
	 * the stats count and the card class/data-status, so the two can
	 * never disagree.
	 *
	 * @param string $status Raw overall_status from tracker.json.
	 * @return string One of KNOWN_OVERALL_STATUSES.
	 */
	public static function normalize( $status ) {
		$status = (string) $status;
		if ( 'done' === $status ) {
			return 'published';
		}
		return in_array( $status, self::KNOWN_OVERALL_STATUSES, true ) ? $status : 'open';
	}

	/**
	 * Recomputes the item stats from an arbitrary group list.
	 *
	 * Counts per overall_status (done/review/wip/open/na) plus the total.
	 * Called before rendering with the groups filtered by shortcode
	 * attributes, so the pills at the top reflect the actually visible set.
	 *
	 * @param array $groups Filtered group list.
	 * @return array Stats dict (total_items, done, review, wip, open, na).
	 */
	public static function calculate_stats( $groups ) {
		$stats = array(
			'total_items' => 0,
			'published'   => 0,
			'review'      => 0,
			'wip'         => 0,
			'open'        => 0,
			'na'          => 0,
			'untouched'   => 0,
		);

		$count_item = function ( $item ) use ( &$stats ) {
			$status = self::normalize( $item['overall_status'] ?? 'open' );
			$stats[ $status ]++;
			$stats['total_items']++;

			// Sub-count "untouched": every component is unset (no status
			// table in the issue). Computed server-side since 0.5.0 so the
			// "unspecified" pill is correct even without JavaScript.
			$components = (array) ( $item['components'] ?? array() );
			if ( ! empty( $components ) ) {
				$all_unset = true;
				foreach ( $components as $comp ) {
					if ( ( $comp['status'] ?? '' ) !== 'unset' ) {
						$all_unset = false;
						break;
					}
				}
				if ( $all_unset ) {
					$stats['untouched']++;
				}
			}
		};

		foreach ( $groups as $group ) {
			$type = (string) ( $group['type'] ?? '' );
			if ( 'pathway' === $type ) {
				foreach ( (array) ( $group['courses'] ?? array() ) as $course ) {
					foreach ( (array) ( $course['sections'] ?? array() ) as $section ) {
						foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
							$count_item( $item );
						}
					}
				}
			} elseif ( 'handbook' === $type ) {
				foreach ( (array) ( $group['sections'] ?? array() ) as $section ) {
					foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
						$count_item( $item );
					}
				}
			} elseif ( 'orphan' === $type ) {
				foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
					$count_item( $item );
				}
			}
		}

		return $stats;
	}

	/**
	 * Collects the ordered list of component names present in the visible
	 * items (0.5.0).
	 *
	 * Canonical COMPONENT_ORDER entries come first (only those actually
	 * present), followed by unknown components from tracker.json in order
	 * of first appearance. Falls back to COMPONENT_ORDER when no items
	 * carry components at all.
	 *
	 * @param array $groups Filtered group list.
	 * @return array<int,string>
	 */
	public static function collect_component_names( $groups ) {
		$seen = array();

		$collect_item = function ( $item ) use ( &$seen ) {
			foreach ( (array) ( $item['components'] ?? array() ) as $comp ) {
				$name = (string) ( $comp['name'] ?? '' );
				if ( '' !== $name && ! isset( $seen[ $name ] ) ) {
					$seen[ $name ] = true;
				}
			}
		};

		foreach ( $groups as $group ) {
			$type = (string) ( $group['type'] ?? '' );
			if ( 'pathway' === $type ) {
				foreach ( (array) ( $group['courses'] ?? array() ) as $course ) {
					foreach ( (array) ( $course['sections'] ?? array() ) as $section ) {
						foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
							$collect_item( $item );
						}
					}
				}
			} elseif ( 'handbook' === $type ) {
				foreach ( (array) ( $group['sections'] ?? array() ) as $section ) {
					foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
						$collect_item( $item );
					}
				}
			} elseif ( 'orphan' === $type ) {
				foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
					$collect_item( $item );
				}
			}
		}

		if ( empty( $seen ) ) {
			return self::COMPONENT_ORDER;
		}

		$ordered = array();
		foreach ( self::COMPONENT_ORDER as $name ) {
			if ( isset( $seen[ $name ] ) ) {
				$ordered[] = $name;
				unset( $seen[ $name ] );
			}
		}
		// Whatever remains is new to the plugin — append in first-seen order.
		foreach ( array_keys( $seen ) as $name ) {
			$ordered[] = $name;
		}
		return $ordered;
	}

	/**
	 * Collects visible markers for an item (orphan, parse error, duplicate, draft).
	 *
	 * @param array $item Item.
	 * @return array<array{key:string,label:string}>
	 */
	public static function collect_markers( $item ) {
		$out = array();

		if ( ! empty( $item['parse_error'] ) ) {
			$out[] = array(
				'key'   => 'parse-error',
				'label' => __( 'No table', 'training-translation-tracker' ),
			);
		}

		$orphan_reason = $item['orphan_reason'] ?? '';
		if ( 'outside_scope' === $orphan_reason ) {
			$out[] = array(
				'key'   => 'outside-scope',
				'label' => __( 'Outside scope', 'training-translation-tracker' ),
			);
		} elseif ( 'missing_in_inventory' === $orphan_reason ) {
			$out[] = array(
				'key'   => 'missing-in-inventory',
				'label' => __( 'Orphaned', 'training-translation-tracker' ),
			);
		}

		if ( ! empty( $item['duplicate_issues'] ) ) {
			$out[] = array(
				'key'   => 'duplicate',
				'label' => __( 'Duplicate', 'training-translation-tracker' ),
			);
		}

		if ( ! empty( $item['draft_original'] ) ) {
			$out[] = array(
				'key'   => 'draft-original',
				'label' => __( 'Original draft', 'training-translation-tracker' ),
			);
		}

		return $out;
	}
}
