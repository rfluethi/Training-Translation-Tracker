<?php
/**
 * Shortcode [translation_tracker] and HTML output.
 *
 * For the alpha variant, a semantically clean list output with status pills is
 * sufficient. Card layout, filter, search and sorting will follow in phase 2.3.
 *
 * @package training-translation-tracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderer class.
 */
class TTT_Renderer {

	/**
	 * Material Icons paths (Apache-2.0) for the component display.
	 * Each component gets a unique icon. Size: 24x24 viewBox.
	 *
	 * @var array<string,string>
	 */
	private const COMPONENT_ICONS = array(
		'text'       => 'M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z',
		'thumbnails' => 'M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z',
		'video'      => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z',
		'subtitles'  => 'M19 4H5c-1.11 0-2 .9-2 2v12c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zM11 11H9.5v-.5h-2v3h2V13H11v1c0 .55-.45 1-1 1H7c-.55 0-1-.45-1-1v-4c0-.55.45-1 1-1h3c.55 0 1 .45 1 1v1zm7 0h-1.5v-.5h-2v3h2V13H18v1c0 .55-.45 1-1 1h-3c-.55 0-1-.45-1-1v-4c0-.55.45-1 1-1h3c.55 0 1 .45 1 1v1z',
		'quiz'       => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z',
		'exercise'   => 'M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z',
		'audio'      => 'M12 14c1.66 0 2.99-1.34 2.99-3L15 5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z',
	);


	/**
	 * Counts shortcode invocations per page render. Becomes part of the
	 * tracker_id, which ensures stable localStorage keys across reloads.
	 *
	 * @var int
	 */
	private static $instance_counter = 0;

	/**
	 * Cached icon mapping per render cycle.
	 *
	 * Populated in render_payload() from payload['component_icons'] (if
	 * available) and read in render_component_icon(). This way we do not need
	 * to reapply the `ttt_component_icons` filter on every icon render.
	 *
	 * @var array<string,string>|null
	 */
	private $icon_map = null;

	/**
	 * Cached frontend i18n bundle for the current request.
	 *
	 * render_component_icon() reads the translated component and status
	 * labels from here for the aria-label, so screen readers get the same
	 * localized strings as the JS popover instead of raw English tokens.
	 *
	 * @var array|null
	 */
	private $i18n_cache = null;

	/**
	 * Ordered component names for the current render cycle (0.5.0).
	 *
	 * Derived from the actually present components in the visible items:
	 * canonical COMPONENT_ORDER first, then any additional components from
	 * tracker.json in order of first appearance. Used for both the icon
	 * order on the cards and the options of the component filter, so a
	 * new component introduced by the action shows up without a plugin
	 * release.
	 *
	 * @var array<int,string>|null
	 */
	private $component_names_cache = null;

	/**
	 * Constructor: register the shortcode.
	 *
	 * Since 0.3.2, all CSS is emitted exclusively inline with the shortcode
	 * output (see `render_inline_styles`). This leaves a single CSS source,
	 * with no duplicate maintenance against an external `style.css`.
	 */
	public function __construct() {
		add_shortcode( 'translation_tracker', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Shortcode handler. Returns the finished HTML.
	 *
	 * Accepts attributes for filtering:
	 *   pathway       - slug of a pathway (e.g. "user", "lesson-plans"). Only this one is shown.
	 *                   Separate multiple values with commas.
	 *   show_orphans  - "no"/"false" hides the orphan group.
	 *   show_handbook - "no"/"false" hides the handbook group.
	 *   show_stats    - "no"/"false" hides the stats header.
	 *
	 * Examples:
	 *   [translation_tracker]
	 *   [translation_tracker pathway="user"]
	 *   [translation_tracker pathway="lesson-plans" show_stats="no"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts = array() ) {
		// CSS is emitted entirely inline with the shortcode output
		// (render_inline_styles); no separate wp_enqueue_style anymore.
		// Rationale: page builders and cache plugins load external stylesheets
		// unreliably, and since 0.3.2 the inline block is the only CSS source
		// (single source of truth).

		// What the user set explicitly, *before* shortcode_atts applies the
		// defaults. Used below to decide whether `show_orphans` and
		// `show_handbook` should be read as "default-yes" or as
		// "explicitly-yes". This lets us also hide orphan/handbook automatically
		// when `pathway="user"` is set, without the user having to specify it.
		$explicit_atts = is_array( $atts ) ? $atts : array();

		$atts = shortcode_atts(
			array(
				'pathway'       => '',
				'show_pathways' => 'yes',
				'show_orphans'  => 'yes',
				'show_handbook' => 'yes',
				'show_stats'    => 'yes',
			),
			$atts,
			'translation_tracker'
		);
		// Pass along a marker so `render_payload` knows what was set explicitly.
		$atts['_explicit'] = $explicit_atts;

		$result  = TTT_Fetcher::get();
		$payload = $result['payload'];

		ob_start();
		$this->render_inline_styles();

		if ( null === $payload ) {
			$this->render_empty( $result['error'] );
			return ob_get_clean();
		}

		$this->render_payload( $payload, $result, $atts );
		$this->render_inline_script();
		return ob_get_clean();
	}

	/**
	 * Emits a `<script src="…">` tag that loads tracker.js.
	 *
	 * Background: inline `<script>` blocks get destroyed by wpautop or similar
	 * content filters in some themes/page builders (newlines become <br>, which
	 * gives the JS syntax errors). A `<script src>` tag is ONE line, wpautop
	 * leaves it alone, and the browser loads the file normally via the plugin
	 * URL.
	 *
	 * A static marker prevents multiple output if the shortcode appears
	 * multiple times on a page.
	 *
	 * @return void
	 */
	private function render_inline_script() {
		static $already_printed = false;
		if ( $already_printed ) {
			return;
		}
		$already_printed = true;

		// i18n data for the frontend: all strings displayed by the JS, as a
		// global object window.tttI18n. Must come BEFORE the tracker.js script
		// tag so the JS can already read the values on DOMContentLoaded.
		// Conceptually like wp_localize_script(), but without
		// wp_enqueue_script() (see the comment below about the <script src>
		// tag).
		// JSON_HEX_TAG escapes < > as < >, so the JSON is safe to
		// embed inside a <script> tag (no risk of `</script>` injection).
		// Plugin-Check still flags the variable, so the inline phpcs:ignore
		// documents that the escape happens via wp_json_encode + flags.
		$i18n = wp_json_encode( $this->get_frontend_i18n(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
		echo '<script id="ttt-i18n">window.tttI18n=' . $i18n . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with JSON_HEX_TAG produces script-safe JSON.
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$src = TTT_PLUGIN_URL . 'assets/tracker.js?ver=' . rawurlencode( TTT_VERSION );
		// Intentional deviation from wp_enqueue_script():
		// The standard route via the `wp_enqueue_scripts` hook plus a
		// has_shortcode() check does not work reliably in page builders
		// (Elementor, Divi, etc.) because the shortcode is not stored in
		// $post->post_content there but in builder-specific meta fields.
		// has_shortcode() returns false, the script is never enqueued, and the
		// tracker stays non-functional.
		// A direct <script src> tag in the shortcode output avoids the problem.
		echo '<script src="' . esc_url( $src ) . '" defer></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}

	/**
	 * Returns all i18n strings required by the frontend JS as an array.
	 *
	 * Passed to the JS as window.tttI18n. Instead of having individual
	 * hardcoded strings in the JS, the JS routes all displayable strings
	 * through here, so they are translatable via .po/.mo.
	 *
	 * @return array
	 */
	private function get_frontend_i18n() {
		return array(
			// Not a translation: tells the JS whether to render GitHub
			// avatars or initials in the popover (0.5.1, privacy setting).
			'showAvatars'      => TTT_Settings::show_avatars(),
			'collapseAll'      => __( 'Collapse all', 'training-translation-tracker' ),
			'expandAll'        => __( 'Expand all', 'training-translation-tracker' ),
			'creator'          => __( 'Creator', 'training-translation-tracker' ),
			'reviewer'         => __( 'Reviewer', 'training-translation-tracker' ),
			'notAssigned'      => __( 'not yet assigned', 'training-translation-tracker' ),
			'componentDetails' => __( 'Component details', 'training-translation-tracker' ),
			'statusLabels'    => array(
				'published' => __( 'published', 'training-translation-tracker' ),
				'done'   => __( 'done', 'training-translation-tracker' ),
				'review' => __( 'Review', 'training-translation-tracker' ),
				'wip'    => __( 'in progress', 'training-translation-tracker' ),
				'open'   => __( 'open', 'training-translation-tracker' ),
				'unset'  => __( 'unspecified', 'training-translation-tracker' ),
				'na'     => __( 'n/a', 'training-translation-tracker' ),
			),
			'componentLabels' => array(
				'text'       => __( 'text', 'training-translation-tracker' ),
				'thumbnails' => __( 'thumbnails', 'training-translation-tracker' ),
				'video'      => __( 'video', 'training-translation-tracker' ),
				'subtitles'  => __( 'subtitles', 'training-translation-tracker' ),
				'exercise'   => __( 'exercise', 'training-translation-tracker' ),
				'quiz'       => __( 'quiz', 'training-translation-tracker' ),
				'audio'      => __( 'audio', 'training-translation-tracker' ),
			),
		);
	}

	/**
	 * Emits the critical layout styles as an inline `<style>` block.
	 *
	 * Background: external CSS files do not load reliably when the shortcode
	 * is rendered from a page builder, custom block, or caching plugin.
	 * Inline styles in the output avoid that entirely.
	 *
	 * Single source of truth: since 0.3.2 this block contains the complete
	 * frontend CSS; there is no longer an external assets/style.css.
	 *
	 * @return void
	 */
	private function render_inline_styles() {
		// The CSS is identical for every tracker instance and the block
		// carries an id attribute, so print it once per page even when the
		// shortcode appears multiple times (same guard pattern as in
		// render_inline_script()).
		static $already_printed = false;
		if ( $already_printed ) {
			return;
		}
		$already_printed = true;

		// Intentional deviation from wp_enqueue_style(): page builders, cache
		// plugins, and some theme setups do not load external stylesheets
		// reliably when the shortcode comes from a builder meta field rather
		// than $post->post_content. An inline <style> tag in the shortcode
		// output avoids this completely; same rationale as for the <script src>
		// tag in render_inline_script().
		// The CSS itself lives in TTT_Styles (includes/class-styles.php)
		// since 0.5.2 — same single-source-of-truth idea, one class per
		// concern. This method only keeps the print-once guard.
		TTT_Styles::render();
	}

	/**
	 * Delegates to TTT_Status (logic extracted in 0.5.2). The private
	 * wrappers keep the renderer's internal call sites and the existing
	 * test reflection points stable.
	 *
	 * @param array $groups Filtered group list.
	 * @return array
	 */
	private function calculate_stats_from_groups( $groups ) {
		return TTT_Status::calculate_stats( $groups );
	}

	/**
	 * Delegate, see TTT_Status::collect_component_names().
	 *
	 * @param array $groups Filtered group list.
	 * @return array<int,string>
	 */
	private function collect_component_names( $groups ) {
		return TTT_Status::collect_component_names( $groups );
	}

	/**
	 * Delegate, see TTT_Status::normalize().
	 *
	 * @param string $status Raw overall_status from tracker.json.
	 * @return string
	 */
	private function normalize_overall_status( $status ) {
		return TTT_Status::normalize( $status );
	}

	/**
	 * Delegate, see TTT_Status::collect_markers().
	 *
	 * @param array $item Item.
	 * @return array<array{key:string,label:string}>
	 */
	private function collect_markers( $item ) {
		return TTT_Status::collect_markers( $item );
	}


	/**
	 * Helper: parses "yes"/"no"/"true"/"false"/"1"/"0" to bool.
	 *
	 * @param string $value Input.
	 * @param bool   $default Fallback.
	 * @return bool
	 */
	private function bool_attr( $value, $default = true ) {
		$normalized = strtolower( trim( (string) $value ) );
		if ( in_array( $normalized, array( 'no', 'false', '0', 'off' ), true ) ) {
			return false;
		}
		if ( in_array( $normalized, array( 'yes', 'true', '1', 'on' ), true ) ) {
			return true;
		}
		return $default;
	}

	/**
	 * Fallback output when (no) data is yet available.
	 *
	 * @param string $error Optional internal error message.
	 * @return string
	 */
	private function render_empty( $error ) {
		?>
		<div class="ttt-empty">
			<p>
				<?php
				esc_html_e(
					'Tracker data is being prepared. Please check back later.',
					'training-translation-tracker'
				);
				?>
			</p>
			<?php if ( $error && current_user_can( 'manage_options' ) ) : ?>
				<p class="ttt-empty-detail">
					<?php
					printf(
						/* translators: %s: error message. */
						esc_html__( 'Error: %s', 'training-translation-tracker' ),
						esc_html( $error )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the actual tracker.
	 *
	 * @param array $payload tracker.json content.
	 * @param array $result  Result dict from the fetcher (for header info).
	 * @param array $atts    Shortcode attributes (for filters).
	 * @return void
	 */
	private function render_payload( $payload, $result, $atts ) {
		$generated = isset( $payload['generated_at'] ) ? (string) $payload['generated_at'] : '';
		$groups    = isset( $payload['groups'] ) && is_array( $payload['groups'] ) ? $payload['groups'] : array();

		// Precompute the icon mapping per render cycle.
		// Priority (from low to high): COMPONENT_ICONS (PHP fallback)
		// < payload['component_icons'] (from tracker.json) < filter hook
		// `ttt_component_icons` (final override by theme or plugin).
		$from_payload = isset( $payload['component_icons'] ) && is_array( $payload['component_icons'] )
			? $payload['component_icons']
			: array();
		$merged       = array_merge( self::COMPONENT_ICONS, $from_payload );
		/** This filter is documented in render_component_icon(). */
		$this->icon_map = apply_filters( 'ttt_component_icons', $merged );

		$pathway_filter = $this->parse_pathway_filter( $atts['pathway'] ?? '' );
		$has_pathway    = null !== $pathway_filter;
		$explicit       = isset( $atts['_explicit'] ) && is_array( $atts['_explicit'] ) ? $atts['_explicit'] : array();

		$show_stats     = $this->bool_attr( $atts['show_stats'] ?? 'yes', true );
		$show_pathways  = $this->bool_attr( $atts['show_pathways'] ?? 'yes', true );

		// Smart defaults: when the shortcode has a pathway attribute, 99% of
		// users want to see *only* that pathway, so hide orphan and handbook
		// by default. Anyone who still needs them writes
		// show_orphans="yes"/show_handbook="yes" explicitly.
		$default_show_orphans  = $has_pathway ? false : true;
		$default_show_handbook = $has_pathway ? false : true;
		$show_orphans  = isset( $explicit['show_orphans'] )
			? $this->bool_attr( $atts['show_orphans'], $default_show_orphans )
			: $default_show_orphans;
		$show_handbook = isset( $explicit['show_handbook'] )
			? $this->bool_attr( $atts['show_handbook'], $default_show_handbook )
			: $default_show_handbook;

		// Stable ID per tracker instance on a page. Important:
		//   1. Unique when several shortcodes appear on the same page -> counter.
		//   2. Stable across reloads so localStorage state (filter, collapse)
		//      is preserved -> not a UUID, but post ID + counter.
		// The static property increments per page render and resets on every
		// new WordPress request; with a stable shortcode position on the page,
		// the same counter results on reload.
		self::$instance_counter++;
		$post_id    = (int) ( get_the_ID() ?: 0 );
		$tracker_id = 'ttt-post' . $post_id . '-' . self::$instance_counter;

		// First determine the group list filtered by shortcode attributes,
		// then compute the stats FROM THAT LIST. This makes the pill row show
		// the actually displayed item counts, not the overall value from
		// payload.stats.
		$visible_groups = array();
		foreach ( $groups as $group ) {
			if ( $this->group_passes_filter( $group, $pathway_filter, $show_orphans, $show_handbook, $show_pathways ) ) {
				$visible_groups[] = $group;
			}
		}
		$stats = $this->calculate_stats_from_groups( $visible_groups );

		// Component order and filter options, derived from the data (0.5.0).
		$this->component_names_cache = $this->collect_component_names( $visible_groups );

		?>
		<div class="ttt-tracker" id="<?php echo esc_attr( $tracker_id ); ?>" data-tracker-id="<?php echo esc_attr( $tracker_id ); ?>">
			<header class="ttt-header">
				<?php if ( $show_stats ) : ?>
					<?php $this->render_stats( $stats ); ?>
				<?php endif; ?>
				<?php $this->render_filter_bar( $tracker_id ); ?>
				<?php
				// "As of: ..." does not appear on the frontend; the timestamp
				// lives on the settings page (Settings > Translation Tracker).
				// Admins still see the last-good fallback notice in the tracker
				// so a silent API failure does not go unnoticed.
				if ( 'last_good' === $result['source'] && current_user_can( 'manage_options' ) ) :
					?>
					<p class="ttt-generated">
						<span class="ttt-warn"><?php esc_html_e( '(last successfully cached state — current fetch failed)', 'training-translation-tracker' ); ?></span>
					</p>
				<?php endif; ?>
			</header>

			<?php foreach ( $visible_groups as $group ) : ?>
				<?php $this->render_group( $group ); ?>
			<?php endforeach; ?>

			<?php
			// role="status" + aria-live: screen readers announce when a
			// filter or search combination yields no results (0.5.0).
			?>
			<div class="ttt-no-results" role="status" aria-live="polite" hidden>
				<?php esc_html_e( 'No results for the current filter/search combination.', 'training-translation-tracker' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Search field below the stats. Status filtering happens via the clickable
	 * stats pills above (data-filter-status on .ttt-stat); no duplicate button
	 * row anymore.
	 *
	 * @return void
	 */
	private function render_filter_bar( $tracker_id = '' ) {
		// The separate Project-status dropdown was removed in 0.5.0: since
		// the board status leads the stats pills and the card border, the
		// dropdown duplicated the pill filter and confused users with a
		// second status dimension.
		$search_id = $tracker_id ? $tracker_id . '-search' : '';
		?>
		<div class="ttt-filter-bar">
			<?php if ( $search_id ) : ?>
				<label class="ttt-visually-hidden" for="<?php echo esc_attr( $search_id ); ?>"><?php esc_html_e( 'Search titles', 'training-translation-tracker' ); ?></label>
			<?php endif; ?>
			<input
				type="search"
				class="ttt-search-input"
				<?php if ( $search_id ) : ?>id="<?php echo esc_attr( $search_id ); ?>"<?php endif; ?>
				placeholder="<?php esc_attr_e( 'Search titles…', 'training-translation-tracker' ); ?>"
				aria-label="<?php esc_attr_e( 'Search titles', 'training-translation-tracker' ); ?>"
			/>
			<div
				class="ttt-component-filter-group"
				role="group"
				aria-label="<?php esc_attr_e( 'Component filter', 'training-translation-tracker' ); ?>"
			>
				<?php
				// Visible caption naming the group's dimension (0.5.1), so
				// the status values in the second select are not mistaken
				// for the card status counted by the pills.
				?>
				<span class="ttt-component-filter-caption" aria-hidden="true"><?php esc_html_e( 'Component', 'training-translation-tracker' ); ?></span>
				<select
					class="ttt-component-select"
					aria-label="<?php esc_attr_e( 'Filter by component', 'training-translation-tracker' ); ?>"
				>
					<option value=""><?php esc_html_e( 'All components', 'training-translation-tracker' ); ?></option>
					<?php
					// Options are derived from the components actually present
					// in tracker.json (0.5.0). Labels come from the shared i18n
					// bundle; a component unknown to the bundle falls back to
					// its raw name until the translations catch up.
					if ( null === $this->i18n_cache ) {
						$this->i18n_cache = $this->get_frontend_i18n();
					}
					$component_names = $this->component_names_cache ? $this->component_names_cache : TTT_Status::COMPONENT_ORDER;
					foreach ( $component_names as $comp_name ) :
						$comp_label = (string) ( $this->i18n_cache['componentLabels'][ $comp_name ] ?? $comp_name );
						?>
						<option value="<?php echo esc_attr( $comp_name ); ?>"><?php echo esc_html( $comp_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select
					class="ttt-component-status-select"
					aria-label="<?php esc_attr_e( 'Filter component by status', 'training-translation-tracker' ); ?>"
					disabled
				>
					<?php
					// "Component status: any" instead of "Any status" (0.5.0):
					// the option names its dimension, so the values below are
					// not mistaken for the card status counted by the pills.
					?>
					<option value=""><?php esc_html_e( 'Component status: any', 'training-translation-tracker' ); ?></option>
					<option value="unset"><?php esc_html_e( 'unspecified', 'training-translation-tracker' ); ?></option>
					<option value="open"><?php esc_html_e( 'open', 'training-translation-tracker' ); ?></option>
					<option value="wip"><?php esc_html_e( 'in progress', 'training-translation-tracker' ); ?></option>
					<option value="review"><?php esc_html_e( 'Review', 'training-translation-tracker' ); ?></option>
					<option value="done"><?php esc_html_e( 'done', 'training-translation-tracker' ); ?></option>
					<option value="na"><?php esc_html_e( 'n/a', 'training-translation-tracker' ); ?></option>
				</select>
			</div>
			<button
				type="button"
				class="ttt-collapse-all-btn"
				data-collapse-all-state="expanded"
				aria-label="<?php esc_attr_e( 'Collapse or expand all sections', 'training-translation-tracker' ); ?>"
			><?php esc_html_e( 'Collapse all', 'training-translation-tracker' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Parses the pathway attribute into a set of allowed slugs.
	 * Empty string or "all" => all pathways.
	 *
	 * @param string $value Comma-separated slugs or empty.
	 * @return array|null Array of slugs, or null when unrestricted.
	 */
	private function parse_pathway_filter( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || 'all' === strtolower( $value ) ) {
			return null;
		}
		$out = array();
		foreach ( explode( ',', $value ) as $slug ) {
			$slug = trim( $slug );
			if ( $slug !== '' ) {
				$out[] = $slug;
			}
		}
		return $out ?: null;
	}

	/**
	 * Decides whether a group is displayed according to the shortcode filters.
	 *
	 * @param array      $group           Group.
	 * @param array|null $pathway_filter  Allowed pathway slugs, or null.
	 * @param bool       $show_orphans    Show the orphan group?
	 * @param bool       $show_handbook   Show the handbook group?
	 * @return bool
	 */
	private function group_passes_filter( $group, $pathway_filter, $show_orphans, $show_handbook, $show_pathways = true ) {
		$type = (string) ( $group['type'] ?? '' );

		if ( 'orphan' === $type ) {
			return $show_orphans;
		}
		if ( 'handbook' === $type ) {
			return $show_handbook;
		}
		if ( 'pathway' === $type ) {
			// show_pathways="no" hides *all* pathways, independent of the
			// pathway attribute. Useful for `[translation_tracker
			// show_pathways="no" show_orphans="no"]`, which leaves only the
			// handbook.
			if ( ! $show_pathways ) {
				return false;
			}
			if ( null === $pathway_filter ) {
				return true;
			}
			// Multiple match strategies: raw slug, label-to-slug, and lowercase
			// slug. That way pathway="user" works just as well as
			// pathway="beginner-wordpress-user" or
			// pathway="Beginner WordPress User".
			$slug       = strtolower( (string) ( $group['slug'] ?? '' ) );
			$label_slug = sanitize_title( (string) ( $group['label'] ?? '' ) );
			$filter_lc  = array_map( 'strtolower', $pathway_filter );
			return in_array( $slug, $filter_lc, true )
				|| in_array( $label_slug, $filter_lc, true );
		}
		return true;
	}

	/**
	 * Stats header (X items, Y done, Z review, ...).
	 *
	 * @param array $stats Stats dict.
	 * @return void
	 */
	private function render_stats( $stats ) {
		$total     = (int) ( $stats['total_items'] ?? 0 );
		$published = (int) ( $stats['published'] ?? 0 );
		$review    = (int) ( $stats['review'] ?? 0 );
		$wip       = (int) ( $stats['wip'] ?? 0 );
		$open      = (int) ( $stats['open'] ?? 0 );
		$na        = (int) ( $stats['na'] ?? 0 );
		$untouched = (int) ( $stats['untouched'] ?? 0 );

		?>
		<div class="ttt-stats">
			<button type="button" class="ttt-stat ttt-stat-total" data-filter-status="all" title="<?php esc_attr_e( 'Show all', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $total; ?></span>
				<?php esc_html_e( 'Items', 'training-translation-tracker' ); ?>
			</button>
			<button type="button" class="ttt-stat ttt-stat-published" data-filter-status="published" title="<?php esc_attr_e( 'Show only published', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $published; ?></span>
				<?php esc_html_e( 'published', 'training-translation-tracker' ); ?>
			</button>
			<button type="button" class="ttt-stat ttt-stat-review" data-filter-status="review" title="<?php esc_attr_e( 'Show only Review', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $review; ?></span>
				<?php esc_html_e( 'Review', 'training-translation-tracker' ); ?>
			</button>
			<button type="button" class="ttt-stat ttt-stat-wip" data-filter-status="wip" title="<?php esc_attr_e( 'Show only in progress', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $wip; ?></span>
				<?php esc_html_e( 'in progress', 'training-translation-tracker' ); ?>
			</button>
			<button type="button" class="ttt-stat ttt-stat-open" data-filter-status="open" title="<?php esc_attr_e( 'Show only open', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $open; ?></span>
				<?php esc_html_e( 'open', 'training-translation-tracker' ); ?>
			</button>
			<button type="button" class="ttt-stat ttt-stat-unset" data-filter-status="unspecified" title="<?php esc_attr_e( 'Show only items whose status table is empty', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $untouched; ?></span>
				<?php esc_html_e( 'unspecified', 'training-translation-tracker' ); ?>
			</button>
			<span class="ttt-stat ttt-stat-na" title="<?php esc_attr_e( 'n/a — not filterable', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $na; ?></span>
				<?php esc_html_e( 'n/a', 'training-translation-tracker' ); ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Renders a top-level group (pathway / handbook / orphan).
	 *
	 * @param array $group Group.
	 * @return void
	 */
	private function render_group( $group ) {
		$type  = (string) ( $group['type'] ?? '' );
		$label = (string) ( $group['label'] ?? '' );
		$slug  = (string) ( $group['slug'] ?? sanitize_title( $label ) );
		$key   = $type . '-' . $slug;

		echo '<section class="ttt-group ttt-group-' . esc_attr( $type ) . '" data-group-key="' . esc_attr( $key ) . '">';
		// The group title is a fixed anchor, not clickable; collapsing happens
		// only at the section level. This keeps the main headings (Beginner
		// WordPress User, Lesson Plans, Training Handbook, Other) always
		// visible as a visual table of contents.
		echo '<h2 class="ttt-group-title">' . esc_html( $label ) . '</h2>';
		echo '<div class="ttt-group-body">';

		if ( 'pathway' === $type ) {
			foreach ( (array) ( $group['courses'] ?? array() ) as $course ) {
				// Pass the group label as parent; if the course label is the
				// same, render_course hides the h3 (redundant).
				$this->render_course( $course, $key, $label );
			}
		} elseif ( 'handbook' === $type ) {
			foreach ( (array) ( $group['sections'] ?? array() ) as $section ) {
				// Group label as parent; if the section label is the same,
				// render_section hides the h4.
				$this->render_section( $section, $key, $label );
			}
		} elseif ( 'orphan' === $type ) {
			// Pseudo-section wrapping the items so they become collapsible via
			// the section collapse mechanism (analogous to Lesson Plans and
			// Handbook).
			$fake_section = array(
				'slug'  => 'all',
				'label' => $label,
				'items' => (array) ( $group['items'] ?? array() ),
			);
			$this->render_section( $fake_section, $key, '' );
		}

		echo '</div>'; // .ttt-group-body
		echo '</section>';
	}

	/**
	 * Renders a course block (within a pathway).
	 *
	 * @param array $course Course.
	 * @return void
	 */
	private function render_course( $course, $parent_key = '', $parent_group_label = '' ) {
		$label = (string) ( $course['label'] ?? '' );
		$slug  = (string) ( $course['slug'] ?? sanitize_title( $label ) );
		$key   = trim( $parent_key . '-' . $slug, '-' );

		// If the course label is identical to the group label, the course
		// title is redundant (this occurs for "Lesson Plans", where pathway,
		// course, and section all share the same name). In that case, omit
		// the h3.
		$is_redundant = ( '' !== $parent_group_label && $label === $parent_group_label );

		echo '<div class="ttt-course" data-course-key="' . esc_attr( $key ) . '">';
		if ( $label && ! $is_redundant ) {
			echo '<h3 class="ttt-course-title">' . esc_html( $label ) . '</h3>';
		}
		// Effective parent label for the section: when the course is
		// redundant, the section compares directly against the group label.
		$effective_parent = $is_redundant ? $parent_group_label : $label;
		foreach ( (array) ( $course['sections'] ?? array() ) as $section ) {
			$this->render_section( $section, $key, $effective_parent );
		}
		echo '</div>';
	}

	/**
	 * Renders a section (module level).
	 *
	 * @param array $section Section.
	 * @return void
	 */
	private function render_section( $section, $parent_key = '', $parent_label = '' ) {
		$label = (string) ( $section['label'] ?? '' );
		$slug  = (string) ( $section['slug'] ?? sanitize_title( $label ) );
		$key   = trim( $parent_key . '-' . $slug, '-' );

		// The section header is always shown (as long as the label is not
		// empty), even if it repeats the group label. Reason: it is the only
		// clickable toggle for collapse; without it, the section could not be
		// collapsed. We still suppress the course level on redundancy (see
		// render_course) so that three levels do not all carry the same name.
		echo '<div class="ttt-section" data-section-key="' . esc_attr( $key ) . '">';
		if ( $label ) {
			// Section header: heading hierarchy via <h4> plus a real <button>
			// as the toggle element. aria-expanded reflects the collapse
			// state and is maintained by the JS.
			echo '<h4 class="ttt-section-heading">';
			echo '<button type="button" class="ttt-section-title" aria-expanded="true">';
			echo '<span class="ttt-section-toggle" aria-hidden="true">▾</span> ';
			echo esc_html( $label );
			echo '</button>';
			echo '</h4>';
		}
		echo '<div class="ttt-section-body">';
		$this->render_item_list( (array) ( $section['items'] ?? array() ) );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Renders a flat list of items.
	 *
	 * @param array $items List.
	 * @return void
	 */
	private function render_item_list( $items ) {
		if ( empty( $items ) ) {
			echo '<p class="ttt-empty-section">' . esc_html__( 'No items in this group.', 'training-translation-tracker' ) . '</p>';
			return;
		}

		echo '<div class="ttt-cards">';
		foreach ( $items as $item ) {
			$this->render_item( $item );
		}
		echo '</div>';
	}

	/**
	 * Renders a single item.
	 *
	 * @param array $item Item.
	 * @return void
	 */
	private function render_item( $item ) {
		$title          = (string) ( $item['title_en'] ?? '' );
		$title_de       = (string) ( $item['title_de'] ?? '' );
		$url_en         = (string) ( $item['url_en'] ?? '' );
		$url_de         = (string) ( $item['url_de'] ?? '' );
		$url_wptv_en    = (string) ( $item['url_wptv_en'] ?? '' );
		$url_wptv_de    = (string) ( $item['url_wptv_de'] ?? ( $item['url_wptv'] ?? '' ) );
		$url_youtube_en = (string) ( $item['url_youtube_en'] ?? '' );
		$url_youtube_de = (string) ( $item['url_youtube_de'] ?? ( $item['url_youtube'] ?? '' ) );

		// Guard against issue #2: a translated recording is by definition a
		// different video than the original. When the DE URL equals the EN
		// URL (older tracker.json data whose parser auto-detected the
		// original link into the DE slot, or a copy-paste in the issue
		// body), treat the translation slot as empty so the Translation
		// column does not claim a German recording that is not one.
		if ( '' !== $url_wptv_de && $url_wptv_de === $url_wptv_en ) {
			$url_wptv_de = '';
		}
		if ( '' !== $url_youtube_de && $url_youtube_de === $url_youtube_en ) {
			$url_youtube_de = '';
		}
		$overall        = $this->normalize_overall_status( $item['overall_status'] ?? 'open' );
		$components     = (array) ( $item['components'] ?? array() );
		$issue          = isset( $item['issue'] ) && is_array( $item['issue'] ) ? $item['issue'] : null;
		$markers        = $this->collect_markers( $item );

		// Component lookup by name so we can emit them in canonical order.
		$components_by_name = array();
		foreach ( $components as $comp ) {
			$name = (string) ( $comp['name'] ?? '' );
			if ( $name ) {
				$components_by_name[ $name ] = $comp;
			}
		}

		// Translation title: do not hardcode; if there is no DE title, use the
		// EN one as a placeholder in a muted color. The card then immediately
		// shows where a translation is still missing.
		$translation_text = $title_de ?: $title;
		$translation_is_placeholder = ! $title_de;

		// data-search: a single lowercase string with everything we search.
		// Reduces the JS to a simple `dataset.search.includes(query)`.
		$issue_number   = $issue && isset( $issue['number'] ) ? '#' . (int) $issue['number'] : '';
		$project_status = $issue ? (string) ( $issue['project_status'] ?? '' ) : '';
		$search_haystack = strtolower( trim(
			$title . ' ' . $title_de . ' ' . $issue_number . ' ' . $project_status
		) );

		// data-project-status: for the dropdown filter in the header.
		// The slug (sanitize_title) makes matching robust against uppercase
		// letters and special characters.
		$project_status_slug = $project_status !== '' ? sanitize_title( $project_status ) : '';

		echo '<article class="ttt-card ttt-overall-' . esc_attr( $overall )
			. '" data-status="' . esc_attr( $overall )
			. '" data-search="' . esc_attr( $search_haystack )
			. '" data-project-status="' . esc_attr( $project_status_slug )
			. '">';

		// ------- Two columns: Original / Translation -------
		echo '<div class="ttt-card-cols">';

		// Original column
		echo '<div class="ttt-card-col ttt-card-col-en">';
		echo '<div class="ttt-card-label">' . esc_html__( 'Original', 'training-translation-tracker' ) . '</div>';
		echo '<div class="ttt-card-title">';
		if ( $url_en ) {
			echo '<a href="' . esc_url( $url_en ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a>';
		} else {
			echo esc_html( $title );
		}
		echo '</div>';
		$this->render_card_media_row( $url_wptv_en, $url_youtube_en );
		echo '</div>';

		// Translation column
		echo '<div class="ttt-card-col ttt-card-col-de' . ( $translation_is_placeholder ? ' ttt-card-col-placeholder' : '' ) . '">';
		echo '<div class="ttt-card-label">' . esc_html__( 'Translation', 'training-translation-tracker' ) . '</div>';
		echo '<div class="ttt-card-title">';
		if ( $url_de ) {
			echo '<a href="' . esc_url( $url_de ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $translation_text ) . '</a>';
		} else {
			echo esc_html( $translation_text );
		}
		echo '</div>';
		$this->render_card_media_row( $url_wptv_de, $url_youtube_de );
		echo '</div>';

		echo '</div>'; // .ttt-card-cols

		// ------- Footer row: issue and markers on the left, component icons on the right -------
		echo '<div class="ttt-card-footer">';

		// Left side: issue number + state + project status + markers
		echo '<div class="ttt-card-footer-left">';
		if ( $issue && isset( $issue['url'], $issue['number'] ) ) {
			echo '<a class="ttt-issue-number" href="' . esc_url( (string) $issue['url'] ) . '" target="_blank" rel="noopener noreferrer">';
			echo '#' . (int) $issue['number'];
			echo '</a>';
			$state = (string) ( $issue['state'] ?? '' );
			if ( $state ) {
				// Translated label (0.5.1); the raw value stays in the class.
				if ( 'open' === $state ) {
					$state_label = __( 'open', 'training-translation-tracker' );
				} elseif ( 'closed' === $state ) {
					$state_label = __( 'closed', 'training-translation-tracker' );
				} else {
					$state_label = $state;
				}
				echo ' <span class="ttt-issue-state ttt-issue-state-' . esc_attr( $state ) . '">' . esc_html( $state_label ) . '</span>';
			}
			// Projects V2 status pill (e.g. "Translation in Progress"). Slug
			// class used for targeted coloring per status.
			$project_status = (string) ( $issue['project_status'] ?? '' );
			if ( $project_status !== '' ) {
				$ps_slug = sanitize_title( $project_status );
				echo ' <span class="ttt-project-status ttt-project-status-' . esc_attr( $ps_slug ) . '">'
					. esc_html( $project_status ) . '</span>';
			}
		}
		foreach ( $markers as $marker ) {
			echo ' <span class="ttt-marker ttt-marker-' . esc_attr( $marker['key'] ) . '">' . esc_html( $marker['label'] ) . '</span>';
		}
		echo '</div>';

		// Right side: component icons in canonical-then-data-driven order (0.5.0)
		echo '<div class="ttt-card-footer-right">';
		$icon_order = $this->component_names_cache ? $this->component_names_cache : TTT_Status::COMPONENT_ORDER;
		foreach ( $icon_order as $comp_name ) {
			if ( ! isset( $components_by_name[ $comp_name ] ) ) {
				continue;
			}
			$this->render_component_icon( $comp_name, $components_by_name[ $comp_name ] );
		}
		echo '</div>';

		echo '</div>'; // .ttt-card-footer

		echo '</article>';
	}

	/**
	 * Renders the small media row inside a card column (Original or Translation).
	 *
	 * @param string $wptv_url    WP.tv link or empty.
	 * @param string $youtube_url YouTube link or empty.
	 * @return void
	 */
	private function render_card_media_row( $wptv_url, $youtube_url ) {
		if ( ! $wptv_url && ! $youtube_url ) {
			return;
		}
		echo '<div class="ttt-card-media">';
		if ( $wptv_url ) {
			echo '<a class="ttt-card-media-link ttt-card-media-wptv" href="' . esc_url( $wptv_url ) . '" target="_blank" rel="noopener noreferrer">WordPress.tv</a>';
		}
		if ( $youtube_url ) {
			echo '<a class="ttt-card-media-link ttt-card-media-youtube" href="' . esc_url( $youtube_url ) . '" target="_blank" rel="noopener noreferrer">YouTube</a>';
		}
		echo '</div>';
	}

	/**
	 * Renders a single component icon (SVG) with a status class and tooltip.
	 *
	 * @param string $name Component name (e.g. "text").
	 * @param array  $comp Component entry from tracker.json.
	 * @return void
	 */
	private function render_component_icon( $name, $comp ) {
		$status   = (string) ( $comp['status'] ?? 'open' );
		$creator  = (string) ( $comp['creator'] ?? '' );
		$reviewer = (string) ( $comp['reviewer'] ?? '' );

		// Fallback tooltip for no-JS browsers and screen readers. Uses the
		// same translated labels as the JS popover (via the shared i18n
		// bundle), so assistive tech does not get raw English tokens on a
		// localized site.
		if ( null === $this->i18n_cache ) {
			$this->i18n_cache = $this->get_frontend_i18n();
		}
		$name_label   = (string) ( $this->i18n_cache['componentLabels'][ $name ] ?? $name );
		$status_label = (string) ( $this->i18n_cache['statusLabels'][ $status ] ?? $status );
		$tooltip      = $name_label . ' · ' . $status_label;
		if ( $creator ) {
			$tooltip .= ' · ' . __( 'Creator', 'training-translation-tracker' ) . ': ' . $creator;
		}
		if ( $reviewer ) {
			$tooltip .= ' · ' . __( 'Reviewer', 'training-translation-tracker' ) . ': ' . $reviewer;
		}

		/**
		 * Filter: ttt_component_icons.
		 *
		 * Allows themes and companion plugins to override the icon SVG path
		 * data per component without touching the plugin code.
		 *
		 * Example in a theme:
		 *
		 *     add_filter( 'ttt_component_icons', function( $icons ) {
		 *         $icons['text']  = 'M3 5h18v2H3V5...'; // custom SVG path
		 *         $icons['video'] = 'M8 5v14l11-7...';
		 *         return $icons;
		 *     } );
		 *
		 * Priority: COMPONENT_ICONS (fallback) is overridden by the
		 * `component_icons` value from `tracker.json`, which in turn can be
		 * overridden by this filter. The filter is the final authority.
		 *
		 * Unknown component names are defensively ignored; invalid SVG path
		 * data does not produce a render error, only an empty SVG shape.
		 *
		 * @since 0.3.0
		 *
		 * @param array<string,string> $icons Map: component name to SVG path d attribute.
		 */
		if ( null !== $this->icon_map ) {
			$icons = $this->icon_map;
		} else {
			// Defensive fallback: render_component_icon is normally only
			// called from render_payload, where icon_map is populated.
			$icons = apply_filters( 'ttt_component_icons', self::COMPONENT_ICONS );
		}

		$icon_path = isset( $icons[ $name ] ) ? (string) $icons[ $name ] : '';
		if ( '' === $icon_path ) {
			return;
		}

		// data attributes feed the JS popover (component name, status,
		// people). On hover/click, the JS renders a custom popover with
		// avatars and GitHub profile links.
		//
		// SVG size: the HTML attributes `width="18" height="18"` set the
		// intrinsic size; the final rendering comes from the CSS rules in
		// style.css and render_inline_styles (with !important to defeat
		// theme resets like `svg { max-width: 100% }`). Both sources use
		// the token `--ttt-icon-svg`.
		echo '<span class="ttt-comp-icon ttt-comp-' . esc_attr( $status ) . '"';
		// aria-label is the accessible name for screen readers. We
		// deliberately do NOT also set title=, the two attributes would be
		// redundant and double-announced by some screen readers. Browser
		// tooltips on hover are still useful, but the popover (opened on
		// click / hover via JS) is the primary affordance for sighted
		// users, so the tooltip is not essential.
		echo ' aria-label="' . esc_attr( $tooltip ) . '"';
		echo ' role="button" tabindex="0"';
		echo ' aria-haspopup="dialog" aria-expanded="false"';
		echo ' data-comp-name="' . esc_attr( $name ) . '"';
		echo ' data-comp-status="' . esc_attr( $status ) . '"';
		echo ' data-comp-creator="' . esc_attr( $creator ) . '"';
		echo ' data-comp-reviewer="' . esc_attr( $reviewer ) . '">';
		echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">';
		echo '<path d="' . esc_attr( $icon_path ) . '" fill="currentColor"/>';
		echo '</svg>';
		echo '</span>';
	}

}
