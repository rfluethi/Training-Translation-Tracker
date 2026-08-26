<?php
/**
 * Inline frontend CSS for the tracker (extracted from TTT_Renderer in 0.5.2).
 *
 * Single source of truth for the frontend CSS. Maintenance happens here,
 * nowhere else. Tokens (--ttt-*) are defined at the top; updates are made
 * via the token values, not via dozens of scattered rules. The block is
 * emitted inline with the shortcode output on purpose — see the rationale
 * in TTT_Renderer::render_inline_styles().
 *
 * @package training-translation-tracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Styles class. Static only; no state.
 */
class TTT_Styles {

	/**
	 * Prints the complete inline <style> block.
	 *
	 * @return void
	 */
	public static function render() {
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
	/* Icon foreground darkened to amber-700 for WCAG-AA contrast (5.2:1
	   on white). Older 0.4.x used #d4a017 (2.4:1) which failed AA for
	   graphics. The pill background pair (-fg/-bg) is unchanged because
	   the text-on-light-bg pairing already met AA. */
	--ttt-color-review:    #b45309;
	--ttt-color-review-border: #ffc107;
	--ttt-color-wip-fg:    #004085;
	--ttt-color-wip-bg:    #cce5ff;
	--ttt-color-wip:       #1c7ed6;
	--ttt-color-wip-border: #007bff;
	/* Open is highlighted yellow since 0.4.4. Foreground stays neutral
	   dark gray because the same token is reused for non-status UI
	   chrome (section titles, dropdown text, collapse-all button).
	   The icon foreground (--ttt-color-open) was darkened in 0.4.7
	   from #facc15 (1.5:1 on white, failed AA) to #ca8a04 (3.8:1,
	   passes AA for graphics). The bright yellow tint is preserved
	   in -bg for pills. */
	--ttt-color-open-fg:   #495057;
	--ttt-color-open-bg:   #fef9c3;
	--ttt-color-open:      #ca8a04;
	--ttt-color-na-fg:     #6c757d;
	--ttt-color-na-bg:     #e9ecef;
	--ttt-color-na:        #ced4da;
	/* Unset (0.4.5): components without a recorded status (no status
	   table parsed in the issue body). Neutral light gray so they do
	   not confuse the eye with the bright "open" yellow. */
	--ttt-color-unset-fg:  #495057;
	--ttt-color-unset-bg:  #f1f3f5;
	--ttt-color-unset:     #adb5bd;
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
.ttt-tracker .ttt-stat-published { background: var(--ttt-color-done-bg); color: var(--ttt-color-done-fg); }
.ttt-tracker .ttt-stat-done   { background: var(--ttt-color-done-bg);   color: var(--ttt-color-done-fg); }
.ttt-tracker .ttt-stat-review { background: var(--ttt-color-review-bg); color: var(--ttt-color-review-fg); }
.ttt-tracker .ttt-stat-wip    { background: var(--ttt-color-wip-bg);    color: var(--ttt-color-wip-fg); }
.ttt-tracker .ttt-stat-open   { background: var(--ttt-color-open-bg);   color: var(--ttt-color-open-fg); }
.ttt-tracker .ttt-stat-unset  { background: var(--ttt-color-unset-bg);  color: var(--ttt-color-unset-fg); }
.ttt-tracker .ttt-stat-na     { background: var(--ttt-color-na-bg);     color: var(--ttt-color-na-fg); cursor: default !important; }
.ttt-tracker .ttt-stat-total  { background: var(--ttt-color-total-bg);  color: var(--ttt-color-total-fg); }
.ttt-tracker .ttt-filter-bar { display: flex !important; flex-wrap: wrap; gap: var(--ttt-space-md); align-items: center; margin: 0.5rem 0; }
.ttt-tracker .ttt-visually-hidden { position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
.ttt-tracker .ttt-component-filter-caption { display: inline-flex; align-items: center; padding: var(--ttt-space-sm) var(--ttt-space-md); font-size: var(--ttt-font-size-xs); text-transform: uppercase; letter-spacing: 0.05em; color: var(--ttt-color-text-subtle); background: var(--ttt-color-surface-subtle); border-right: var(--ttt-border-width) solid var(--ttt-color-border-input); }
.ttt-tracker .ttt-comp-popover-avatar-initials { display: inline-flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; color: var(--ttt-color-text-muted); }
.ttt-tracker .ttt-search-input { flex: 1 1 220px; max-width: 320px; padding: var(--ttt-space-sm) 0.7rem; border: var(--ttt-border-width) solid var(--ttt-color-border-input); border-radius: var(--ttt-radius-md); font-size: 0.9rem; font-family: inherit; line-height: 1.3; background: var(--ttt-color-bg); color: var(--ttt-color-text-strong); box-sizing: border-box; }
.ttt-tracker .ttt-search-input:focus { outline: none; border-color: var(--ttt-color-primary); box-shadow: var(--ttt-shadow-focus); }
.ttt-tracker .ttt-collapse-all-btn { padding: var(--ttt-space-sm) 0.85rem; border: var(--ttt-border-width) solid var(--ttt-color-border-input); background: var(--ttt-color-bg); color: var(--ttt-color-open-fg); border-radius: var(--ttt-radius-md); font-size: var(--ttt-font-size-sm); cursor: pointer; font-family: inherit; line-height: 1.3; transition: background 0.15s ease, border-color 0.15s ease; flex-shrink: 0; }
.ttt-tracker .ttt-project-status-select { padding: var(--ttt-space-sm) var(--ttt-space-md); border: var(--ttt-border-width) solid var(--ttt-color-border-input); background: var(--ttt-color-bg); color: var(--ttt-color-open-fg); border-radius: var(--ttt-radius-md); font-size: var(--ttt-font-size-sm); cursor: pointer; font-family: inherit; line-height: 1.3; flex-shrink: 0; max-width: 200px; }

/* Compound component filter: two dependent selects share one border so
   they read as a single, grouped control. Internal divider between the
   two selects clarifies the relationship. The status select is disabled
   while no component is chosen (handled by JS). */
.ttt-tracker .ttt-component-filter-group { display: inline-flex; align-items: stretch; border: var(--ttt-border-width) solid var(--ttt-color-border-input); border-radius: var(--ttt-radius-md); background: var(--ttt-color-bg); overflow: hidden; flex-shrink: 0; }
.ttt-tracker .ttt-component-filter-group .ttt-component-select,
.ttt-tracker .ttt-component-filter-group .ttt-component-status-select { padding: var(--ttt-space-sm) var(--ttt-space-md); border: 0; background: transparent; color: var(--ttt-color-open-fg); font-size: var(--ttt-font-size-sm); cursor: pointer; font-family: inherit; line-height: 1.3; max-width: 200px; appearance: auto; }
.ttt-tracker .ttt-component-filter-group .ttt-component-select { border-right: var(--ttt-border-width) solid var(--ttt-color-border-input); }
.ttt-tracker .ttt-component-filter-group .ttt-component-status-select[disabled] { cursor: not-allowed; opacity: 0.55; }
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
.ttt-tracker .ttt-overall-published { border-left-color: var(--ttt-color-done) !important; }
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
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon.ttt-comp-unset  { color: var(--ttt-color-unset); opacity: 0.55; }
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
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-open   { background: var(--ttt-color-open-bg);   color: var(--ttt-color-open-fg); }
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-unset  { background: var(--ttt-color-unset-bg);  color: var(--ttt-color-unset-fg); }
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
}
