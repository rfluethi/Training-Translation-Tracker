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
	 * Canonical order of components in the card footer row.
	 * Components not present in the item are skipped.
	 *
	 * @var array<int,string>
	 */
	private const COMPONENT_ORDER = array(
		'thumbnails', 'text', 'subtitles', 'exercise', 'quiz', 'audio', 'video',
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
			'collapseAll'      => __( 'Collapse all', 'training-translation-tracker' ),
			'expandAll'        => __( 'Expand all', 'training-translation-tracker' ),
			'creator'          => __( 'Creator', 'training-translation-tracker' ),
			'reviewer'         => __( 'Reviewer', 'training-translation-tracker' ),
			'notAssigned'      => __( 'not yet assigned', 'training-translation-tracker' ),
			'componentDetails' => __( 'Component details', 'training-translation-tracker' ),
			'statusLabels'    => array(
				'done'   => __( 'done', 'training-translation-tracker' ),
				'review' => __( 'Review', 'training-translation-tracker' ),
				'wip'    => __( 'in progress', 'training-translation-tracker' ),
				'open'   => __( 'open', 'training-translation-tracker' ),
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
		// Intentional deviation from wp_enqueue_style(): page builders, cache
		// plugins, and some theme setups do not load external stylesheets
		// reliably when the shortcode comes from a builder meta field rather
		// than $post->post_content. An inline <style> tag in the shortcode
		// output avoids this completely; same rationale as for the <script src>
		// tag in render_inline_script().
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		?>
<style id="ttt-inline-critical">
/* Training Translation Tracker, inline styles (<?php echo esc_html( TTT_VERSION ); ?>)
 *
 * Single source of truth for the frontend CSS. Maintenance happens here,
 * nowhere else. Tokens (--ttt-*) are defined at the top; updates are made
 * via the token values, not via dozens of scattered rules.
 *
 * Brand colors fall back to theme.json variables; status colors are
 * intentionally fixed (semantics).
 */

.ttt-tracker {
	/* --- Brand colors (overridable by the theme) --- */
	--ttt-color-primary: var(--wp--preset--color--primary, #2271b1);
	--ttt-color-primary-soft-bg: rgba(34,113,177,0.18);
	--ttt-color-primary-ring: rgba(34,113,177,0.25);
	--ttt-color-text: var(--wp--preset--color--foreground, #222);
	--ttt-color-text-strong: #212529;
	--ttt-color-text-muted: #6c757d;
	--ttt-color-text-subtle: #868e96;
	--ttt-color-text-faint: #adb5bd;
	--ttt-color-bg: var(--wp--preset--color--base, #fff);
	--ttt-color-border: #e5e5e5;
	--ttt-color-border-subtle: #e9ecef;
	--ttt-color-border-input: #d0d4d9;
	--ttt-color-surface-subtle: #f1f3f5;
	/* --- Status semantics (plugin-fixed) --- */
	--ttt-color-done-fg:   #155724;
	--ttt-color-done-bg:   #d4edda;
	--ttt-color-done:      #28a745;
	--ttt-color-review-fg: #856404;
	--ttt-color-review-bg: #fff3cd;
	--ttt-color-review:    #d4a017;
	--ttt-color-review-border: #ffc107;
	--ttt-color-wip-fg:    #004085;
	--ttt-color-wip-bg:    #cce5ff;
	--ttt-color-wip:       #1c7ed6;
	--ttt-color-wip-border: #007bff;
	/* Open is highlighted yellow since 0.4.4. Foreground stays neutral
	   dark gray because the same token is reused for non-status UI
	   chrome (section titles, dropdown text, collapse-all button). */
	--ttt-color-open-fg:   #495057;
	--ttt-color-open-bg:   #fef9c3;
	--ttt-color-open:      #facc15;
	--ttt-color-na-fg:     #6c757d;
	--ttt-color-na-bg:     #e9ecef;
	--ttt-color-na:        #ced4da;
	--ttt-color-total-fg:  #fff;
	--ttt-color-total-bg:  #343a40;
	/* --- Markers --- */
	--ttt-color-marker-warn-fg: #c92a2a;
	--ttt-color-marker-warn-bg: #ffe3e3;
	--ttt-color-warn-strong: #d63638;
	/* --- Project status pills --- */
	--ttt-color-ps-default-fg: #1c4f86;
	--ttt-color-ps-default-bg: #e7f1fb;
	--ttt-color-ps-triage-fg: #842029;
	--ttt-color-ps-triage-bg: #fde2e2;
	--ttt-color-ps-looking-fg: #8a5a00;
	--ttt-color-ps-looking-bg: #ffe8d1;
	--ttt-color-ps-prep-fg: #0c5460;
	--ttt-color-ps-prep-bg: #d1ecf1;
	/* --- Spacing scale --- */
	--ttt-space-xs:  0.25rem;
	--ttt-space-sm:  0.4rem;
	--ttt-space-md:  0.6rem;
	--ttt-space-lg:  0.75rem;
	--ttt-space-xl:  1rem;
	--ttt-space-2xl: 1.5rem;
	--ttt-space-3xl: 2rem;
	/* --- Typography --- */
	--ttt-font-size-xs:   0.7rem;
	--ttt-font-size-sm:   0.85rem;
	--ttt-font-size-base: 0.95rem;
	--ttt-font-size-md:   1.05rem;
	--ttt-font-size-lg:   1.2rem;
	--ttt-font-size-xl:   1.4rem;
	--ttt-line-height: 1.5;
	/* --- Borders and radii --- */
	--ttt-border-width: 1px;
	--ttt-radius-md: 6px;
	--ttt-radius-lg: 8px;
	--ttt-radius-pill: 999px;
	--ttt-card-border-width: 4px;
	/* --- Icons --- */
	--ttt-icon-comp:    22px;
	--ttt-icon-svg:     18px;
	--ttt-icon-avatar:  32px;
	/* --- Shadows --- */
	--ttt-shadow-popover: 0 6px 20px rgba(0,0,0,0.18);
	--ttt-shadow-focus:   0 0 0 3px var(--ttt-color-primary-soft-bg);
	--ttt-shadow-active:  0 0 0 2px var(--ttt-color-primary), 0 0 0 4px var(--ttt-color-primary-ring);
}

.ttt-tracker { font-family: inherit; line-height: var(--ttt-line-height); margin: var(--ttt-space-2xl) 0; color: var(--ttt-color-text); position: relative; }
.ttt-tracker .ttt-header { margin-bottom: var(--ttt-space-2xl); padding-bottom: var(--ttt-space-lg); border-bottom: var(--ttt-border-width) solid var(--ttt-color-border); }
.ttt-tracker .ttt-stats { display: flex !important; flex-wrap: wrap; gap: 0.5rem var(--ttt-space-lg); font-size: var(--ttt-font-size-base); margin-bottom: var(--ttt-space-lg); }
.ttt-tracker .ttt-stat { display: inline-flex !important; align-items: center; gap: 0.35rem; padding: var(--ttt-space-xs) var(--ttt-space-md); border-radius: var(--ttt-radius-pill); background: var(--ttt-color-surface-subtle); color: #333; font-weight: 600; border: none; cursor: pointer; font-size: inherit; font-family: inherit; line-height: 1.2; transition: opacity 0.15s ease, box-shadow 0.15s ease; }
.ttt-tracker .ttt-stat[data-filter-status]:hover { opacity: 0.85; }
.ttt-tracker .ttt-stat-active { box-shadow: var(--ttt-shadow-active); }
.ttt-tracker .ttt-stat-count { font-weight: 700; }
.ttt-tracker .ttt-stat-done   { background: var(--ttt-color-done-bg);   color: var(--ttt-color-done-fg); }
.ttt-tracker .ttt-stat-review { background: var(--ttt-color-review-bg); color: var(--ttt-color-review-fg); }
.ttt-tracker .ttt-stat-wip    { background: var(--ttt-color-wip-bg);    color: var(--ttt-color-wip-fg); }
.ttt-tracker .ttt-stat-open   { background: var(--ttt-color-open-bg);   color: var(--ttt-color-open-fg); }
.ttt-tracker .ttt-stat-na     { background: var(--ttt-color-na-bg);     color: var(--ttt-color-na-fg); cursor: default !important; }
.ttt-tracker .ttt-stat-total  { background: var(--ttt-color-total-bg);  color: var(--ttt-color-total-fg); }
.ttt-tracker .ttt-filter-bar { display: flex !important; flex-wrap: wrap; gap: var(--ttt-space-md); align-items: center; margin: 0.5rem 0; }
.ttt-tracker .ttt-search-input { flex: 1 1 220px; max-width: 320px; padding: var(--ttt-space-sm) 0.7rem; border: var(--ttt-border-width) solid var(--ttt-color-border-input); border-radius: var(--ttt-radius-md); font-size: 0.9rem; font-family: inherit; line-height: 1.3; background: var(--ttt-color-bg); color: var(--ttt-color-text-strong); box-sizing: border-box; }
.ttt-tracker .ttt-search-input:focus { outline: none; border-color: var(--ttt-color-primary); box-shadow: var(--ttt-shadow-focus); }
.ttt-tracker .ttt-collapse-all-btn { padding: var(--ttt-space-sm) 0.85rem; border: var(--ttt-border-width) solid var(--ttt-color-border-input); background: var(--ttt-color-bg); color: var(--ttt-color-open-fg); border-radius: var(--ttt-radius-md); font-size: var(--ttt-font-size-sm); cursor: pointer; font-family: inherit; line-height: 1.3; transition: background 0.15s ease, border-color 0.15s ease; flex-shrink: 0; }
.ttt-tracker .ttt-project-status-select,
.ttt-tracker .ttt-component-select,
.ttt-tracker .ttt-component-status-select { padding: var(--ttt-space-sm) var(--ttt-space-md); border: var(--ttt-border-width) solid var(--ttt-color-border-input); background: var(--ttt-color-bg); color: var(--ttt-color-open-fg); border-radius: var(--ttt-radius-md); font-size: var(--ttt-font-size-sm); cursor: pointer; font-family: inherit; line-height: 1.3; flex-shrink: 0; max-width: 200px; }
.ttt-tracker .ttt-project-status-select:focus { outline: none; border-color: var(--ttt-color-primary); box-shadow: var(--ttt-shadow-focus); }
.ttt-tracker .ttt-project-status { display: inline-block; padding: 0.05rem 0.5rem; border-radius: var(--ttt-radius-pill); font-size: var(--ttt-font-size-xs); font-weight: 600; background: var(--ttt-color-ps-default-bg); color: var(--ttt-color-ps-default-fg); white-space: nowrap; }
/* Color variants per status slug. For unknown values the default above applies. */
.ttt-tracker .ttt-project-status-awaiting-triage         { background: var(--ttt-color-ps-triage-bg);  color: var(--ttt-color-ps-triage-fg); }
.ttt-tracker .ttt-project-status-looking-for-translator  { background: var(--ttt-color-ps-looking-bg); color: var(--ttt-color-ps-looking-fg); }
.ttt-tracker .ttt-project-status-translation-in-progress { background: var(--ttt-color-wip-bg);       color: var(--ttt-color-wip-fg); }
.ttt-tracker .ttt-project-status-ready-for-review        { background: var(--ttt-color-review-bg);    color: var(--ttt-color-review-fg); }
.ttt-tracker .ttt-project-status-preparing-to-publish    { background: var(--ttt-color-ps-prep-bg);   color: var(--ttt-color-ps-prep-fg); }
.ttt-tracker .ttt-project-status-published-or-closed     { background: var(--ttt-color-done-bg);      color: var(--ttt-color-done-fg); }
.ttt-tracker .ttt-collapse-all-btn:hover { background: var(--ttt-color-surface-subtle); border-color: var(--ttt-color-text-faint); }
.ttt-tracker .ttt-collapse-all-btn:focus { outline: none; border-color: var(--ttt-color-primary); box-shadow: var(--ttt-shadow-focus); }
.ttt-tracker .ttt-generated { margin: 0.5rem 0 0; color: var(--ttt-color-text-muted); font-size: var(--ttt-font-size-sm); }
.ttt-tracker .ttt-warn { color: var(--ttt-color-warn-strong); }
.ttt-tracker .ttt-no-results { padding: var(--ttt-space-2xl); text-align: center; color: var(--ttt-color-text-muted); font-style: italic; }
.ttt-tracker .ttt-cards { display: flex !important; flex-direction: column; gap: var(--ttt-space-md); }
.ttt-tracker .ttt-card { background: var(--ttt-color-bg) !important; border: var(--ttt-border-width) solid var(--ttt-color-border) !important; border-left: var(--ttt-card-border-width) solid var(--ttt-color-text-faint) !important; border-radius: var(--ttt-radius-md); padding: var(--ttt-space-lg) 0.9rem var(--ttt-space-md); display: block; margin-bottom: var(--ttt-space-md); }
/* Hide rules with elevated specificity ([attr] selector beats .class); wins
   against .ttt-card { display: block } so that JS-set [hidden] attributes apply. */
.ttt-tracker .ttt-card[hidden] { display: none !important; }
.ttt-tracker .ttt-section[hidden] { display: none !important; }
.ttt-tracker .ttt-course[hidden] { display: none !important; }
.ttt-tracker .ttt-group[hidden] { display: none !important; }
.ttt-tracker .ttt-no-results[hidden] { display: none !important; }
.ttt-tracker .ttt-overall-done   { border-left-color: var(--ttt-color-done) !important; }
.ttt-tracker .ttt-overall-review { border-left-color: var(--ttt-color-review-border) !important; }
.ttt-tracker .ttt-overall-wip    { border-left-color: var(--ttt-color-wip-border) !important; }
.ttt-tracker .ttt-overall-open   { border-left-color: var(--ttt-color-open) !important; }
.ttt-tracker .ttt-overall-na     { border-left-color: var(--ttt-color-na-bg) !important; }
.ttt-tracker .ttt-card-cols { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: var(--ttt-space-2xl); width: 100%; }
.ttt-tracker .ttt-card-col-en, .ttt-tracker .ttt-card-col-de { min-width: 0; display: block !important; }
.ttt-tracker .ttt-card-label { font-size: var(--ttt-font-size-xs); letter-spacing: 0.08em; text-transform: uppercase; color: var(--ttt-color-text-subtle); margin-bottom: 0.15rem; }
.ttt-tracker .ttt-card-title { font-size: var(--ttt-font-size-base); font-weight: 600; color: var(--ttt-color-text-strong); line-height: 1.3; }
.ttt-tracker .ttt-card-title a { color: inherit; text-decoration: none; }
.ttt-tracker .ttt-card-title a:hover { color: var(--ttt-color-primary); text-decoration: underline; }
.ttt-tracker .ttt-card-col-placeholder .ttt-card-title { color: var(--ttt-color-text-faint); font-style: italic; }
.ttt-tracker .ttt-card-media { margin-top: var(--ttt-space-xs); display: flex !important; flex-wrap: wrap; gap: var(--ttt-space-sm); }
.ttt-tracker .ttt-card-media-link { display: inline-block !important; font-size: 0.78rem; color: var(--ttt-color-primary); text-decoration: none; }
.ttt-tracker .ttt-card-media-link:hover { text-decoration: underline; }
.ttt-tracker .ttt-card-media-youtube { color: var(--ttt-color-marker-warn-fg); }
.ttt-tracker .ttt-card-footer { display: flex !important; flex-direction: row; justify-content: space-between; align-items: center; gap: 0.5rem; margin-top: 0.7rem; padding-top: 0.5rem; border-top: var(--ttt-border-width) dashed var(--ttt-color-border-subtle); }
.ttt-tracker .ttt-card-footer-left { display: flex !important; flex-wrap: wrap; gap: var(--ttt-space-sm); align-items: center; font-size: var(--ttt-font-size-sm); }
.ttt-tracker .ttt-card-footer-right { display: flex !important; flex-direction: row !important; flex-wrap: nowrap !important; gap: var(--ttt-space-xs); align-items: center; flex-shrink: 0; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon { width: var(--ttt-icon-comp) !important; height: var(--ttt-icon-comp) !important; min-width: var(--ttt-icon-comp) !important; max-width: var(--ttt-icon-comp) !important; display: inline-flex !important; align-items: center; justify-content: center; cursor: help; flex-shrink: 0; line-height: 1; margin: 0; padding: 0; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon svg { width: var(--ttt-icon-svg) !important; height: var(--ttt-icon-svg) !important; max-width: var(--ttt-icon-svg) !important; max-height: var(--ttt-icon-svg) !important; min-width: 0 !important; display: block !important; flex-shrink: 0; margin: 0; padding: 0; fill: currentColor; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon.ttt-comp-done   { color: var(--ttt-color-done); }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon.ttt-comp-review { color: var(--ttt-color-review); }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon.ttt-comp-wip    { color: var(--ttt-color-wip); }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon.ttt-comp-open   { color: var(--ttt-color-open); }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon.ttt-comp-na     { color: var(--ttt-color-na); opacity: 0.45; }
/* Component popover (positioned dynamically by the JS). Always on a higher
   stacking layer than the cards. */
.ttt-tracker .ttt-comp-popover { position: absolute !important; z-index: 9999; background: var(--ttt-color-bg) !important; border: var(--ttt-border-width) solid var(--ttt-color-border-input); border-radius: var(--ttt-radius-lg); box-shadow: var(--ttt-shadow-popover); padding: var(--ttt-space-md) var(--ttt-space-lg); min-width: 200px; max-width: 280px; font-size: var(--ttt-font-size-sm); color: var(--ttt-color-text) !important; line-height: 1.4; }
.ttt-tracker .ttt-comp-popover[hidden] { display: none !important; }
.ttt-tracker .ttt-comp-popover-header { font-weight: 700; font-size: var(--ttt-font-size-base); text-transform: capitalize; margin: 0 0 var(--ttt-space-xs); padding-bottom: var(--ttt-space-xs); border-bottom: var(--ttt-border-width) solid var(--ttt-color-border-subtle); }
.ttt-tracker .ttt-comp-popover-status { display: inline-block; padding: 0.05rem 0.45rem; border-radius: var(--ttt-radius-pill); font-size: 0.72rem; font-weight: 600; margin-bottom: var(--ttt-space-sm); }
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-done   { background: var(--ttt-color-done-bg);    color: var(--ttt-color-done-fg); }
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-review { background: var(--ttt-color-review-bg);  color: var(--ttt-color-review-fg); }
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-wip    { background: var(--ttt-color-wip-bg);     color: var(--ttt-color-wip-fg); }
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-open   { background: var(--ttt-color-surface-subtle); color: var(--ttt-color-open-fg); }
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-na     { background: var(--ttt-color-na-bg);      color: var(--ttt-color-na-fg); }
.ttt-tracker .ttt-comp-popover-person { display: flex !important; align-items: center; gap: 0.5rem; padding: 0.3rem 0; }
.ttt-tracker .ttt-comp-popover-person + .ttt-comp-popover-person { border-top: var(--ttt-border-width) dashed var(--ttt-color-border-subtle); }
.ttt-tracker .ttt-comp-popover-avatar { width: var(--ttt-icon-avatar) !important; height: var(--ttt-icon-avatar) !important; border-radius: 50%; flex-shrink: 0; background: var(--ttt-color-surface-subtle); }
.ttt-tracker .ttt-comp-popover-text { display: flex !important; flex-direction: column; line-height: 1.2; }
.ttt-tracker .ttt-comp-popover-role { font-size: 0.72rem; color: var(--ttt-color-text-subtle); text-transform: uppercase; letter-spacing: 0.05em; }
.ttt-tracker .ttt-comp-popover-username { font-weight: 600; }
.ttt-tracker .ttt-comp-popover-username a { color: var(--ttt-color-primary); text-decoration: none; }
.ttt-tracker .ttt-comp-popover-username a:hover { text-decoration: underline; }
.ttt-tracker .ttt-comp-popover-unassigned { color: var(--ttt-color-text-subtle); font-style: italic; padding: 0.3rem 0; font-size: 0.85rem; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon { position: relative; }
.ttt-tracker .ttt-issue-number { color: var(--ttt-color-primary); text-decoration: none; font-weight: 600; }
.ttt-tracker .ttt-issue-state { display: inline-block; padding: 0.05rem 0.45rem; border-radius: var(--ttt-radius-pill); font-size: var(--ttt-font-size-xs); background: var(--ttt-color-na-bg); color: var(--ttt-color-open-fg); }
.ttt-tracker .ttt-issue-state-open   { background: var(--ttt-color-done-bg); color: var(--ttt-color-done-fg); }
.ttt-tracker .ttt-issue-state-closed { background: var(--ttt-color-na-bg);   color: var(--ttt-color-na-fg); }
.ttt-tracker .ttt-marker { display: inline-block; padding: 0.1rem 0.5rem; border-radius: var(--ttt-radius-pill); font-size: 0.72rem; font-weight: 600; background: var(--ttt-color-marker-warn-bg); color: var(--ttt-color-marker-warn-fg); }
.ttt-tracker .ttt-group { margin: var(--ttt-space-3xl) 0; }
.ttt-tracker .ttt-group-title { margin: 0 0 var(--ttt-space-xl); font-size: var(--ttt-font-size-xl); border-bottom: 2px solid var(--ttt-color-primary); padding-bottom: var(--ttt-space-xs); }
.ttt-tracker .ttt-course { margin: var(--ttt-space-2xl) 0; }
.ttt-tracker .ttt-course-title { font-size: var(--ttt-font-size-lg); margin: 0 0 var(--ttt-space-lg); color: var(--ttt-color-primary); }
.ttt-tracker .ttt-section { margin: var(--ttt-space-xl) 0; }
.ttt-tracker .ttt-section-heading { margin: 0 0 var(--ttt-space-md); font-size: var(--ttt-font-size-md); font-weight: 600; line-height: 1.3; }
.ttt-tracker .ttt-section-title { font: inherit; color: var(--ttt-color-open-fg); background: none; border: 0; padding: 0; margin: 0; cursor: pointer; user-select: none; display: flex; align-items: center; gap: var(--ttt-space-sm); text-align: left; width: 100%; }
.ttt-tracker .ttt-section-title:hover { color: var(--ttt-color-primary); }
.ttt-tracker .ttt-section-title:focus-visible { outline: 2px solid var(--ttt-color-primary); outline-offset: 2px; border-radius: 2px; }
.ttt-tracker .ttt-section-toggle { display: inline-block; width: 1em; color: var(--ttt-color-text-subtle); font-size: 0.9em; transition: transform 0.15s ease; }
.ttt-tracker .ttt-section-collapsed .ttt-section-body { display: none !important; }
.ttt-tracker .ttt-section-collapsed .ttt-section-title { color: var(--ttt-color-text-subtle); }
.ttt-tracker .ttt-section-collapsed .ttt-section-toggle { transform: rotate(-90deg); }
@media (max-width: 480px) {
  .ttt-tracker .ttt-card-cols { grid-template-columns: 1fr !important; gap: var(--ttt-space-md); }
  .ttt-tracker .ttt-card-col-de { padding-top: var(--ttt-space-sm); border-top: var(--ttt-border-width) dashed var(--ttt-color-border-subtle); }
  .ttt-tracker .ttt-card-footer { flex-direction: column; align-items: flex-start; }
  .ttt-tracker .ttt-filter-bar { max-width: none; }
  .ttt-tracker .ttt-stats { font-size: var(--ttt-font-size-sm); }
  .ttt-tracker .ttt-header { margin-bottom: var(--ttt-space-lg) !important; padding-bottom: 0.5rem; }
}
</style>
		<?php
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
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
	private function calculate_stats_from_groups( $groups ) {
		$stats = array(
			'total_items' => 0,
			'done'        => 0,
			'review'      => 0,
			'wip'         => 0,
			'open'        => 0,
			'na'          => 0,
		);

		$count_item = function ( $item ) use ( &$stats ) {
			$status = (string) ( $item['overall_status'] ?? 'open' );
			if ( ! isset( $stats[ $status ] ) ) {
				$status = 'open';
			}
			$stats[ $status ]++;
			$stats['total_items']++;
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

		// Collect distinct project status values from the visible items so the
		// dropdown only shows options that actually occur.
		$project_status_values = $this->collect_project_statuses( $visible_groups );

		?>
		<div class="ttt-tracker" id="<?php echo esc_attr( $tracker_id ); ?>" data-tracker-id="<?php echo esc_attr( $tracker_id ); ?>">
			<header class="ttt-header">
				<?php if ( $show_stats ) : ?>
					<?php $this->render_stats( $stats ); ?>
				<?php endif; ?>
				<?php $this->render_filter_bar( $project_status_values ); ?>
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

			<div class="ttt-no-results" hidden>
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
	private function render_filter_bar( $project_status_values = array() ) {
		?>
		<div class="ttt-filter-bar">
			<input
				type="search"
				class="ttt-search-input"
				placeholder="<?php esc_attr_e( 'Search titles…', 'training-translation-tracker' ); ?>"
				aria-label="<?php esc_attr_e( 'Search titles', 'training-translation-tracker' ); ?>"
			/>
			<?php if ( ! empty( $project_status_values ) ) : ?>
				<select
					class="ttt-project-status-select"
					aria-label="<?php esc_attr_e( 'Filter by Project status', 'training-translation-tracker' ); ?>"
				>
					<option value=""><?php esc_html_e( 'All statuses', 'training-translation-tracker' ); ?></option>
					<?php foreach ( $project_status_values as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<select
				class="ttt-component-select"
				aria-label="<?php esc_attr_e( 'Filter by component', 'training-translation-tracker' ); ?>"
			>
				<option value=""><?php esc_html_e( 'All components', 'training-translation-tracker' ); ?></option>
				<option value="thumbnails"><?php esc_html_e( 'thumbnails', 'training-translation-tracker' ); ?></option>
				<option value="text"><?php esc_html_e( 'text', 'training-translation-tracker' ); ?></option>
				<option value="subtitles"><?php esc_html_e( 'subtitles', 'training-translation-tracker' ); ?></option>
				<option value="exercise"><?php esc_html_e( 'exercise', 'training-translation-tracker' ); ?></option>
				<option value="quiz"><?php esc_html_e( 'quiz', 'training-translation-tracker' ); ?></option>
				<option value="audio"><?php esc_html_e( 'audio', 'training-translation-tracker' ); ?></option>
				<option value="video"><?php esc_html_e( 'video', 'training-translation-tracker' ); ?></option>
			</select>
			<select
				class="ttt-component-status-select"
				aria-label="<?php esc_attr_e( 'Filter component by status', 'training-translation-tracker' ); ?>"
			>
				<option value=""><?php esc_html_e( 'Any status', 'training-translation-tracker' ); ?></option>
				<option value="open"><?php esc_html_e( 'open', 'training-translation-tracker' ); ?></option>
				<option value="wip"><?php esc_html_e( 'in progress', 'training-translation-tracker' ); ?></option>
				<option value="review"><?php esc_html_e( 'Review', 'training-translation-tracker' ); ?></option>
				<option value="done"><?php esc_html_e( 'done', 'training-translation-tracker' ); ?></option>
				<option value="na"><?php esc_html_e( 'n/a', 'training-translation-tracker' ); ?></option>
			</select>
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
	 * Collects all distinct project status values from the visible groups.
	 * Returns a slug-to-label map (slug for robust filter matching, label for
	 * the UI). Sorted alphabetically by label.
	 *
	 * @param array $groups Filtered group list.
	 * @return array<string,string>
	 */
	private function collect_project_statuses( $groups ) {
		$found = array();

		$collect_item = function ( $item ) use ( &$found ) {
			if ( empty( $item['issue']['project_status'] ) ) {
				return;
			}
			$label = (string) $item['issue']['project_status'];
			$slug  = sanitize_title( $label );
			if ( '' !== $slug && ! isset( $found[ $slug ] ) ) {
				$found[ $slug ] = $label;
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

		asort( $found, SORT_NATURAL | SORT_FLAG_CASE );
		return $found;
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
		$total  = (int) ( $stats['total_items'] ?? 0 );
		$done   = (int) ( $stats['done'] ?? 0 );
		$review = (int) ( $stats['review'] ?? 0 );
		$wip    = (int) ( $stats['wip'] ?? 0 );
		$open   = (int) ( $stats['open'] ?? 0 );
		$na     = (int) ( $stats['na'] ?? 0 );

		?>
		<div class="ttt-stats">
			<button type="button" class="ttt-stat ttt-stat-total" data-filter-status="all" title="<?php esc_attr_e( 'Show all', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $total; ?></span>
				<?php esc_html_e( 'Items', 'training-translation-tracker' ); ?>
			</button>
			<button type="button" class="ttt-stat ttt-stat-done" data-filter-status="done" title="<?php esc_attr_e( 'Show only done', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $done; ?></span>
				<?php esc_html_e( 'done', 'training-translation-tracker' ); ?>
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
		$overall        = (string) ( $item['overall_status'] ?? 'open' );
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
				echo ' <span class="ttt-issue-state ttt-issue-state-' . esc_attr( $state ) . '">' . esc_html( $state ) . '</span>';
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

		// Right side: component icons in canonical order
		echo '<div class="ttt-card-footer-right">';
		foreach ( self::COMPONENT_ORDER as $comp_name ) {
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

		// Fallback tooltip for no-JS browsers and screen readers.
		$tooltip = $name . ' · ' . $status;
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
		echo ' title="' . esc_attr( $tooltip ) . '"';
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

	/**
	 * Collects visible markers for an item (orphan, parse error, duplicate, draft).
	 *
	 * @param array $item Item.
	 * @return array<array{key:string,label:string}>
	 */
	private function collect_markers( $item ) {
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
