<?php
/**
 * Shortcode [translation_tracker] und HTML-Output.
 *
 * Für die Alpha-Variante reicht ein semantisch sauberer Listen-Output mit
 * Status-Pillen. Karten-Layout, Filter, Suche und Sortierung folgen in Phase 2.3.
 *
 * @package training-translation-tracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderer-Klasse.
 */
class TTT_Renderer {

	/**
	 * Material-Icons-Pfade (Apache-2.0) für die Komponenten-Anzeige.
	 * Jede Komponente bekommt ein eindeutiges Icon. Größe 24x24-viewBox.
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
	 * Kanonische Reihenfolge der Komponenten in der Karten-Footer-Zeile.
	 * Nicht im Item enthaltene Komponenten werden übersprungen.
	 *
	 * @var array<int,string>
	 */
	private const COMPONENT_ORDER = array(
		'thumbnails', 'text', 'subtitles', 'exercise', 'quiz', 'audio', 'video',
	);

	/**
	 * Zählt Shortcode-Aufrufe pro Page-Render. Wird Teil der tracker_id und
	 * sorgt damit für stabile localStorage-Keys über Reloads hinweg.
	 *
	 * @var int
	 */
	private static $instance_counter = 0;

	/**
	 * Konstruktor: Shortcode + Frontend-CSS registrieren.
	 *
	 * Hinweis: Das CSS wird nicht mehr über `wp_enqueue_scripts` und
	 * `has_shortcode()` enqueued, sondern direkt im Shortcode-Handler.
	 * Grund: Page-Builder wie Elementor/Divi/Beaver speichern den
	 * Shortcode nicht in `post_content`, sondern in eigenen Meta-Feldern.
	 * `has_shortcode($post->post_content)` liefert dann false und das CSS
	 * wird nie geladen, obwohl der Shortcode tatsächlich rendert.
	 */
	public function __construct() {
		add_shortcode( 'translation_tracker', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Lädt das Frontend-Stylesheet. Wird vom Shortcode-Handler aufgerufen,
	 * sobald wir wissen, dass der Shortcode tatsächlich rendert.
	 *
	 * WordPress dedupliziert die enqueue automatisch — auch wenn der
	 * Shortcode mehrfach auf einer Seite vorkommt, wird die CSS nur einmal
	 * geladen.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		// Externes CSS bleibt als Backup enqueued. Die kritischen Layout-Styles
		// werden zusätzlich inline ausgegeben (siehe render_inline_styles), damit
		// das Layout auch bei Page-Buildern und Cache-Plugins garantiert greift.
		// Das JS wird ebenfalls inline ausgegeben, NICHT mehr enqueued, weil
		// Page-Builder oft wp_footer() nicht aufrufen und das Skript sonst nie
		// lädt.
		wp_enqueue_style(
			'ttt-frontend',
			TTT_PLUGIN_URL . 'assets/style.css',
			array(),
			TTT_VERSION
		);
	}

	/**
	 * Shortcode-Handler. Liefert das fertige HTML.
	 *
	 * Akzeptiert Attribute zum Filtern:
	 *   pathway       — Slug einer Pathway (z. B. "user", "lesson-plans"). Nur diese wird angezeigt.
	 *                   Mehrere durch Komma trennen.
	 *   show_orphans  — "no"/"false" blendet die Orphan-Gruppe aus.
	 *   show_handbook — "no"/"false" blendet die Handbook-Gruppe aus.
	 *   show_stats    — "no"/"false" blendet den Stats-Header aus.
	 *
	 * Beispiele:
	 *   [translation_tracker]
	 *   [translation_tracker pathway="user"]
	 *   [translation_tracker pathway="lesson-plans" show_stats="no"]
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return string
	 */
	public function render_shortcode( $atts = array() ) {
		// CSS immer dann laden, wenn der Shortcode tatsächlich ausgeführt wird —
		// unabhängig davon, ob er in post_content steht oder in einem Page-Builder-Meta.
		// Zusätzlich werden die kritischen Layout-Styles inline ausgegeben, damit
		// das Layout auch bei Page-Caches / Theme-Override / externen Style-Loaders
		// garantiert greift.
		$this->enqueue_frontend_assets();

		// Was der User explizit gesetzt hat — *bevor* shortcode_atts die Defaults
		// einsetzt. Wird unten verwendet, um zu entscheiden, ob `show_orphans`
		// und `show_handbook` als "Default-yes" oder als "explizit-yes" zu lesen
		// sind. So können wir bei `pathway="user"` automatisch auch orphan/handbook
		// ausblenden, ohne dass der User das explizit angeben muss.
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
		// Marker mitgeben, damit `render_payload` weiß, was explizit gesetzt war.
		$atts['_explicit'] = $explicit_atts;

		$result  = TTT_Fetcher::get();
		$payload = $result['payload'];

		ob_start();
		$this->render_inline_styles();

		if ( null === $payload ) {
			echo $this->render_empty( $result['error'] );
			return ob_get_clean();
		}

		$this->render_payload( $payload, $result, $atts );
		$this->render_inline_script();
		return ob_get_clean();
	}

	/**
	 * Gibt einen `<script src="…">`-Tag aus, der tracker.js lädt.
	 *
	 * Hintergrund: Inline-`<script>`-Blocks werden in manchen Themes/Page-
	 * Buildern durch wpautop oder ähnliche Content-Filter zerstört (Newlines
	 * werden zu <br>, das JS hat dann Syntaxfehler). Ein `<script src>`-Tag
	 * ist EINE Zeile, wpautop lässt ihn in Ruhe, und der Browser lädt die
	 * Datei ganz normal über den Plugin-URL.
	 *
	 * Ein statischer Marker verhindert mehrfache Ausgabe, falls der Shortcode
	 * auf einer Seite mehrmals vorkommt.
	 *
	 * @return void
	 */
	private function render_inline_script() {
		static $already_printed = false;
		if ( $already_printed ) {
			return;
		}
		$already_printed = true;

		$src = TTT_PLUGIN_URL . 'assets/tracker.js?ver=' . rawurlencode( TTT_VERSION );
		echo '<script src="' . esc_url( $src ) . '" defer></script>';
	}

	/**
	 * Gibt die kritischen Layout-Styles als Inline-`<style>`-Block aus.
	 *
	 * Hintergrund: Externe CSS-Dateien laden nicht zuverlässig, wenn der
	 * Shortcode aus einem Page-Builder, Custom-Block oder caching-Plugin
	 * gerendert wird. Inline-Styles im Output umgehen das komplett.
	 *
	 * Bewusst nur die "structural"-Styles (Grid, Flex, Größen) — alles
	 * andere (Farben, Pillen, Hover) kommt weiterhin aus assets/style.css.
	 *
	 * @return void
	 */
	private function render_inline_styles() {
		?>
<style id="ttt-inline-critical">
/* Training Translation Tracker — Critical Inline Styles (<?php echo esc_html( TTT_VERSION ); ?>) */
.ttt-tracker { font-family: inherit; line-height: 1.5; margin: 1.5rem 0; color: #222; position: relative; }
.ttt-tracker .ttt-header { margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e5e5e5; }
.ttt-tracker .ttt-stats { display: flex !important; flex-wrap: wrap; gap: 0.5rem 0.75rem; font-size: 0.95rem; margin-bottom: 0.75rem; }
.ttt-tracker .ttt-stat { display: inline-flex !important; align-items: center; gap: 0.35rem; padding: 0.25rem 0.6rem; border-radius: 999px; background: #f1f3f5; color: #333; font-weight: 600; border: none; cursor: pointer; font-size: inherit; font-family: inherit; line-height: 1.2; transition: opacity 0.15s ease, box-shadow 0.15s ease; }
.ttt-tracker .ttt-stat[data-filter-status]:hover { opacity: 0.85; }
.ttt-tracker .ttt-stat-active { box-shadow: 0 0 0 2px #2271b1, 0 0 0 4px rgba(34,113,177,0.25); }
.ttt-tracker .ttt-stat-count { font-weight: 700; }
.ttt-tracker .ttt-stat-done { background: #d4edda; color: #155724; }
.ttt-tracker .ttt-stat-review { background: #fff3cd; color: #856404; }
.ttt-tracker .ttt-stat-wip { background: #cce5ff; color: #004085; }
.ttt-tracker .ttt-stat-open { background: #f8f9fa; color: #495057; }
.ttt-tracker .ttt-stat-na { background: #e9ecef; color: #6c757d; cursor: default !important; }
.ttt-tracker .ttt-stat-total { background: #343a40; color: #fff; }
.ttt-tracker .ttt-filter-bar { display: flex !important; flex-wrap: wrap; gap: 0.6rem; align-items: center; margin: 0.5rem 0; }
.ttt-tracker .ttt-search-input { flex: 1 1 220px; max-width: 320px; padding: 0.4rem 0.7rem; border: 1px solid #d0d4d9; border-radius: 6px; font-size: 0.9rem; font-family: inherit; line-height: 1.3; background: #fff; color: #212529; box-sizing: border-box; }
.ttt-tracker .ttt-search-input:focus { outline: none; border-color: #2271b1; box-shadow: 0 0 0 3px rgba(34,113,177,0.18); }
.ttt-tracker .ttt-collapse-all-btn { padding: 0.4rem 0.85rem; border: 1px solid #d0d4d9; background: #fff; color: #495057; border-radius: 6px; font-size: 0.85rem; cursor: pointer; font-family: inherit; line-height: 1.3; transition: background 0.15s ease, border-color 0.15s ease; flex-shrink: 0; }
.ttt-tracker .ttt-project-status-select { padding: 0.4rem 0.6rem; border: 1px solid #d0d4d9; background: #fff; color: #495057; border-radius: 6px; font-size: 0.85rem; cursor: pointer; font-family: inherit; line-height: 1.3; flex-shrink: 0; max-width: 200px; }
.ttt-tracker .ttt-project-status-select:focus { outline: none; border-color: #2271b1; box-shadow: 0 0 0 3px rgba(34,113,177,0.18); }
.ttt-tracker .ttt-project-status { display: inline-block; padding: 0.05rem 0.5rem; border-radius: 999px; font-size: 0.7rem; font-weight: 600; background: #e7f1fb; color: #1c4f86; white-space: nowrap; }
/* Farbvarianten pro Status-Slug. Bei unbekannten Werten greift der Default oben. */
.ttt-tracker .ttt-project-status-awaiting-triage         { background: #fde2e2; color: #842029; }
.ttt-tracker .ttt-project-status-looking-for-translator  { background: #ffe8d1; color: #8a5a00; }
.ttt-tracker .ttt-project-status-translation-in-progress { background: #cce5ff; color: #004085; }
.ttt-tracker .ttt-project-status-ready-for-review        { background: #fff3cd; color: #856404; }
.ttt-tracker .ttt-project-status-preparing-to-publish    { background: #d1ecf1; color: #0c5460; }
.ttt-tracker .ttt-project-status-published-or-closed     { background: #d4edda; color: #155724; }
.ttt-tracker .ttt-collapse-all-btn:hover { background: #f1f3f5; border-color: #adb5bd; }
.ttt-tracker .ttt-collapse-all-btn:focus { outline: none; border-color: #2271b1; box-shadow: 0 0 0 3px rgba(34,113,177,0.18); }
.ttt-tracker .ttt-generated { margin: 0.5rem 0 0; color: #6c757d; font-size: 0.85rem; }
.ttt-tracker .ttt-warn { color: #d63638; }
.ttt-tracker .ttt-no-results { padding: 1.5rem; text-align: center; color: #6c757d; font-style: italic; }
.ttt-tracker .ttt-cards { display: flex !important; flex-direction: column; gap: 0.6rem; }
.ttt-tracker .ttt-card { background: #fff !important; border: 1px solid #e5e5e5 !important; border-left: 4px solid #adb5bd !important; border-radius: 6px; padding: 0.75rem 0.9rem 0.6rem; display: block; margin-bottom: 0.6rem; }
/* Hide-Regeln mit erhöhter Specificity ([attr]-Selektor schlägt .class) — gewinnt
   gegen .ttt-card { display: block }, damit JS-gesetzte [hidden]-Attribute greifen. */
.ttt-tracker .ttt-card[hidden] { display: none !important; }
.ttt-tracker .ttt-section[hidden] { display: none !important; }
.ttt-tracker .ttt-course[hidden] { display: none !important; }
.ttt-tracker .ttt-group[hidden] { display: none !important; }
.ttt-tracker .ttt-no-results[hidden] { display: none !important; }
.ttt-tracker .ttt-overall-done   { border-left-color: #28a745 !important; }
.ttt-tracker .ttt-overall-review { border-left-color: #ffc107 !important; }
.ttt-tracker .ttt-overall-wip    { border-left-color: #007bff !important; }
.ttt-tracker .ttt-overall-open   { border-left-color: #adb5bd !important; }
.ttt-tracker .ttt-overall-na     { border-left-color: #e9ecef !important; }
.ttt-tracker .ttt-card-cols { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 1.5rem; width: 100%; }
.ttt-tracker .ttt-card-col-en, .ttt-tracker .ttt-card-col-de { min-width: 0; display: block !important; }
.ttt-tracker .ttt-card-label { font-size: 0.7rem; letter-spacing: 0.08em; text-transform: uppercase; color: #868e96; margin-bottom: 0.15rem; }
.ttt-tracker .ttt-card-title { font-size: 0.95rem; font-weight: 600; color: #212529; line-height: 1.3; }
.ttt-tracker .ttt-card-title a { color: inherit; text-decoration: none; }
.ttt-tracker .ttt-card-title a:hover { color: #2271b1; text-decoration: underline; }
.ttt-tracker .ttt-card-col-placeholder .ttt-card-title { color: #adb5bd; font-style: italic; }
.ttt-tracker .ttt-card-media { margin-top: 0.25rem; display: flex !important; flex-wrap: wrap; gap: 0.4rem; }
.ttt-tracker .ttt-card-media-link { display: inline-block !important; font-size: 0.78rem; color: #2271b1; text-decoration: none; }
.ttt-tracker .ttt-card-media-link:hover { text-decoration: underline; }
.ttt-tracker .ttt-card-media-youtube { color: #c92a2a; }
.ttt-tracker .ttt-card-footer { display: flex !important; flex-direction: row; justify-content: space-between; align-items: center; gap: 0.5rem; margin-top: 0.7rem; padding-top: 0.5rem; border-top: 1px dashed #e9ecef; }
.ttt-tracker .ttt-card-footer-left { display: flex !important; flex-wrap: wrap; gap: 0.4rem; align-items: center; font-size: 0.85rem; }
.ttt-tracker .ttt-card-footer-right { display: flex !important; flex-direction: row !important; flex-wrap: nowrap !important; gap: 0.25rem; align-items: center; flex-shrink: 0; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon { width: 22px !important; height: 22px !important; min-width: 22px !important; max-width: 22px !important; display: inline-flex !important; align-items: center; justify-content: center; cursor: help; flex-shrink: 0; line-height: 1; margin: 0; padding: 0; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon svg { width: 18px !important; height: 18px !important; max-width: 18px !important; max-height: 18px !important; min-width: 0 !important; display: block !important; flex-shrink: 0; margin: 0; padding: 0; fill: currentColor; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon.ttt-comp-done   { color: #28a745; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon.ttt-comp-review { color: #d4a017; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon.ttt-comp-wip    { color: #1c7ed6; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon.ttt-comp-open   { color: #adb5bd; opacity: 0.65; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon.ttt-comp-na     { color: #ced4da; opacity: 0.45; }
/* Komponenten-Popover (vom JS dynamisch positioniert). Liegt immer auf einer
   höheren Stacking-Ebene als die Karten. */
.ttt-tracker .ttt-comp-popover { position: absolute !important; z-index: 9999; background: #fff !important; border: 1px solid #d0d4d9; border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.18); padding: 0.6rem 0.75rem; min-width: 200px; max-width: 280px; font-size: 0.85rem; color: #222 !important; line-height: 1.4; }
.ttt-tracker .ttt-comp-popover[hidden] { display: none !important; }
.ttt-tracker .ttt-comp-popover-header { font-weight: 700; font-size: 0.95rem; text-transform: capitalize; margin: 0 0 0.25rem; padding-bottom: 0.25rem; border-bottom: 1px solid #e9ecef; }
.ttt-tracker .ttt-comp-popover-status { display: inline-block; padding: 0.05rem 0.45rem; border-radius: 999px; font-size: 0.72rem; font-weight: 600; margin-bottom: 0.4rem; }
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-done   { background: #d4edda; color: #155724; }
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-review { background: #fff3cd; color: #856404; }
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-wip    { background: #cce5ff; color: #004085; }
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-open   { background: #f1f3f5; color: #495057; }
.ttt-tracker .ttt-comp-popover-status.ttt-comp-status-na     { background: #e9ecef; color: #6c757d; }
.ttt-tracker .ttt-comp-popover-person { display: flex !important; align-items: center; gap: 0.5rem; padding: 0.3rem 0; }
.ttt-tracker .ttt-comp-popover-person + .ttt-comp-popover-person { border-top: 1px dashed #e9ecef; }
.ttt-tracker .ttt-comp-popover-avatar { width: 32px !important; height: 32px !important; border-radius: 50%; flex-shrink: 0; background: #f1f3f5; }
.ttt-tracker .ttt-comp-popover-text { display: flex !important; flex-direction: column; line-height: 1.2; }
.ttt-tracker .ttt-comp-popover-role { font-size: 0.72rem; color: #868e96; text-transform: uppercase; letter-spacing: 0.05em; }
.ttt-tracker .ttt-comp-popover-username { font-weight: 600; }
.ttt-tracker .ttt-comp-popover-username a { color: #2271b1; text-decoration: none; }
.ttt-tracker .ttt-comp-popover-username a:hover { text-decoration: underline; }
.ttt-tracker .ttt-card-footer-right .ttt-comp-icon { position: relative; }
.ttt-tracker .ttt-issue-number { color: #2271b1; text-decoration: none; font-weight: 600; }
.ttt-tracker .ttt-issue-state { display: inline-block; padding: 0.05rem 0.45rem; border-radius: 999px; font-size: 0.7rem; background: #e9ecef; color: #495057; }
.ttt-tracker .ttt-issue-state-open   { background: #d4edda; color: #155724; }
.ttt-tracker .ttt-issue-state-closed { background: #e9ecef; color: #6c757d; }
.ttt-tracker .ttt-marker { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.72rem; font-weight: 600; background: #ffe3e3; color: #c92a2a; }
.ttt-tracker .ttt-group { margin: 2rem 0; }
.ttt-tracker .ttt-group-title { margin: 0 0 1rem; font-size: 1.4rem; border-bottom: 2px solid #2271b1; padding-bottom: 0.25rem; }
.ttt-tracker .ttt-course { margin: 1.5rem 0; }
.ttt-tracker .ttt-course-title { font-size: 1.2rem; margin: 0 0 0.75rem; color: #2271b1; }
.ttt-tracker .ttt-section { margin: 1rem 0; }
.ttt-tracker .ttt-section-title { font-size: 1.05rem; margin: 0 0 0.6rem; color: #495057; font-weight: 600; cursor: pointer; user-select: none; display: flex; align-items: center; gap: 0.4rem; }
.ttt-tracker .ttt-section-title:hover { color: #2271b1; }
.ttt-tracker .ttt-section-title:focus { outline: 2px solid #2271b1; outline-offset: 2px; }
.ttt-tracker .ttt-section-toggle { display: inline-block; width: 1em; color: #868e96; font-size: 0.9em; transition: transform 0.15s ease; }
.ttt-tracker .ttt-section-collapsed .ttt-section-body { display: none !important; }
.ttt-tracker .ttt-section-collapsed .ttt-section-title { color: #868e96; }
@media (max-width: 480px) {
  .ttt-tracker .ttt-card-cols { grid-template-columns: 1fr !important; gap: 0.6rem; }
  .ttt-tracker .ttt-card-col-de { padding-top: 0.4rem; border-top: 1px dashed #e9ecef; }
  .ttt-tracker .ttt-card-footer { flex-direction: column; align-items: flex-start; }
  .ttt-tracker .ttt-filter-bar { max-width: none; }
  .ttt-tracker .ttt-stats { font-size: 0.85rem; }
  .ttt-tracker .ttt-header { margin-bottom: 0.75rem !important; padding-bottom: 0.5rem; }
}
</style>
		<?php
	}

	/**
	 * Rechnet die Item-Stats aus einer beliebigen Gruppen-Liste neu.
	 *
	 * Zählt pro overall_status (done/review/wip/open/na) plus das Gesamttotal.
	 * Wird vor dem Rendern aufgerufen mit den per Shortcode-Attribute
	 * gefilterten Gruppen — die Pillen oben spiegeln also die tatsächlich
	 * angezeigte Menge.
	 *
	 * @param array $groups Gefilterte Gruppen-Liste.
	 * @return array Stats-Dict (total_items, done, review, wip, open, na).
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
	 * Hilfsmethode: parst "yes"/"no"/"true"/"false"/"1"/"0" zu bool.
	 *
	 * @param string $value Eingabe.
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
	 * Fallback-Output, wenn (noch) keine Daten vorhanden sind.
	 *
	 * @param string $error Optionale interne Fehlermeldung.
	 * @return string
	 */
	private function render_empty( $error ) {
		ob_start();
		?>
		<div class="ttt-empty">
			<p>
				<?php
				esc_html_e(
					'Tracker-Daten werden vorbereitet. Bitte später erneut prüfen.',
					'training-translation-tracker'
				);
				?>
			</p>
			<?php if ( $error && current_user_can( 'manage_options' ) ) : ?>
				<p class="ttt-empty-detail">
					<?php
					/* translators: %s: error message. */
					printf(
						esc_html__( 'Fehler: %s', 'training-translation-tracker' ),
						esc_html( $error )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Rendert den eigentlichen Tracker.
	 *
	 * @param array $payload tracker.json-Inhalt.
	 * @param array $result  Result-Dict vom Fetcher (für Header-Info).
	 * @param array $atts    Shortcode-Attribute (für Filter).
	 * @return void
	 */
	private function render_payload( $payload, $result, $atts ) {
		$generated = isset( $payload['generated_at'] ) ? (string) $payload['generated_at'] : '';
		$groups    = isset( $payload['groups'] ) && is_array( $payload['groups'] ) ? $payload['groups'] : array();

		$pathway_filter = $this->parse_pathway_filter( $atts['pathway'] ?? '' );
		$has_pathway    = null !== $pathway_filter;
		$explicit       = isset( $atts['_explicit'] ) && is_array( $atts['_explicit'] ) ? $atts['_explicit'] : array();

		$show_stats     = $this->bool_attr( $atts['show_stats'] ?? 'yes', true );
		$show_pathways  = $this->bool_attr( $atts['show_pathways'] ?? 'yes', true );

		// Smarte Defaults: Wenn der Shortcode ein pathway-Attribut hat, wollen
		// 99% der User *nur* diesen Pathway sehen — also Orphan und Handbook
		// per Default ausblenden. Wer sie trotzdem braucht, schreibt explizit
		// show_orphans="yes"/show_handbook="yes".
		$default_show_orphans  = $has_pathway ? false : true;
		$default_show_handbook = $has_pathway ? false : true;
		$show_orphans  = isset( $explicit['show_orphans'] )
			? $this->bool_attr( $atts['show_orphans'], $default_show_orphans )
			: $default_show_orphans;
		$show_handbook = isset( $explicit['show_handbook'] )
			? $this->bool_attr( $atts['show_handbook'], $default_show_handbook )
			: $default_show_handbook;

		// Stabile ID pro Tracker-Instanz auf einer Seite. Wichtig:
		//   1. Eindeutig wenn mehrere Shortcodes auf derselben Seite stehen → Counter.
		//   2. Stabil über Reloads, damit localStorage-State (Filter, Collapse)
		//      erhalten bleibt → keine UUID, sondern Post-ID + Counter.
		// Die statische Property zählt pro Page-Render hoch und resettet bei
		// jedem neuen WordPress-Request — bei stabiler Shortcode-Position auf
		// der Seite ergibt sich derselbe Counter beim Reload.
		self::$instance_counter++;
		$post_id    = (int) ( get_the_ID() ?: 0 );
		$tracker_id = 'ttt-post' . $post_id . '-' . self::$instance_counter;

		// Erst die per Shortcode-Attribute gefilterte Gruppen-Liste bestimmen,
		// dann Stats AUS DIESER LISTE berechnen. So zeigt die Pillen-Reihe
		// die tatsächlich angezeigten Item-Zahlen, nicht den Gesamt-Wert aus
		// payload.stats.
		$visible_groups = array();
		foreach ( $groups as $group ) {
			if ( $this->group_passes_filter( $group, $pathway_filter, $show_orphans, $show_handbook, $show_pathways ) ) {
				$visible_groups[] = $group;
			}
		}
		$stats = $this->calculate_stats_from_groups( $visible_groups );

		// Distinkte Project-Status-Werte aus den sichtbaren Items sammeln,
		// damit das Dropdown nur Optionen zeigt, die wirklich vorkommen.
		$project_status_values = $this->collect_project_statuses( $visible_groups );

		?>
		<div class="ttt-tracker" id="<?php echo esc_attr( $tracker_id ); ?>" data-tracker-id="<?php echo esc_attr( $tracker_id ); ?>">
			<header class="ttt-header">
				<?php if ( $show_stats ) : ?>
					<?php $this->render_stats( $stats ); ?>
				<?php endif; ?>
				<?php $this->render_filter_bar( $project_status_values ); ?>
				<?php
				// "Stand: …" erscheint nicht im Frontend — Zeitstempel steht in der
				// Settings-Seite (Einstellungen → Translation Tracker). Last-Good-
				// Fallback-Hinweis sehen Admins weiterhin im Tracker, damit ein
				// stiller API-Ausfall nicht unbemerkt bleibt.
				if ( 'last_good' === $result['source'] && current_user_can( 'manage_options' ) ) :
					?>
					<p class="ttt-generated">
						<span class="ttt-warn"><?php esc_html_e( '(letzter erfolgreich gespeicherter Stand — aktueller Fetch schlug fehl)', 'training-translation-tracker' ); ?></span>
					</p>
				<?php endif; ?>
			</header>

			<?php foreach ( $visible_groups as $group ) : ?>
				<?php $this->render_group( $group ); ?>
			<?php endforeach; ?>

			<div class="ttt-no-results" hidden>
				<?php esc_html_e( 'Keine Treffer für die aktuelle Filter-/Suchkombination.', 'training-translation-tracker' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Suchfeld unter den Stats. Status-Filter findet über die klickbaren
	 * Stats-Pillen oben (data-filter-status auf .ttt-stat) statt — kein
	 * doppelter Button-Row mehr.
	 *
	 * @return void
	 */
	private function render_filter_bar( $project_status_values = array() ) {
		?>
		<div class="ttt-filter-bar">
			<input
				type="search"
				class="ttt-search-input"
				placeholder="<?php esc_attr_e( 'Titel suchen (DE oder EN)…', 'training-translation-tracker' ); ?>"
				aria-label="<?php esc_attr_e( 'In Titeln suchen', 'training-translation-tracker' ); ?>"
			/>
			<?php if ( ! empty( $project_status_values ) ) : ?>
				<select
					class="ttt-project-status-select"
					aria-label="<?php esc_attr_e( 'Nach Project-Status filtern', 'training-translation-tracker' ); ?>"
				>
					<option value=""><?php esc_html_e( 'Alle Status', 'training-translation-tracker' ); ?></option>
					<?php foreach ( $project_status_values as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<button
				type="button"
				class="ttt-collapse-all-btn"
				data-collapse-all-state="expanded"
				aria-label="<?php esc_attr_e( 'Alle Sections einklappen oder ausklappen', 'training-translation-tracker' ); ?>"
			><?php esc_html_e( 'Alle einklappen', 'training-translation-tracker' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Sammelt alle distinkten Project-Status-Werte aus den sichtbaren Gruppen.
	 * Liefert ein Slug → Label Map (Slug für robustes Filter-Matching, Label
	 * fürs UI). Sortiert alphabetisch nach Label.
	 *
	 * @param array $groups Gefilterte Gruppen-Liste.
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
	 * Parst das pathway-Attribut zu einem Set von erlaubten Slugs.
	 * Leerer String oder "all" => alle Pathways.
	 *
	 * @param string $value Komma-getrennte Slugs oder leer.
	 * @return array|null Array von Slugs oder null wenn unrestricted.
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
	 * Entscheidet, ob eine Gruppe entsprechend der Shortcode-Filter angezeigt wird.
	 *
	 * @param array      $group           Gruppe.
	 * @param array|null $pathway_filter  Erlaubte Pathway-Slugs oder null.
	 * @param bool       $show_orphans    Orphan-Gruppe anzeigen?
	 * @param bool       $show_handbook   Handbook-Gruppe anzeigen?
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
			// show_pathways="no" blendet *alle* Pathways aus — unabhängig vom
			// pathway-Attribut. Praktisch für `[translation_tracker
			// show_pathways="no" show_orphans="no"]` → nur Handbook.
			if ( ! $show_pathways ) {
				return false;
			}
			if ( null === $pathway_filter ) {
				return true;
			}
			// Mehrere Match-Strategien: roher Slug, Label-zu-Slug und Lowercase-
			// Slug. Damit funktioniert sowohl pathway="user" als auch
			// pathway="beginner-wordpress-user" oder pathway="Beginner WordPress User".
			$slug       = strtolower( (string) ( $group['slug'] ?? '' ) );
			$label_slug = sanitize_title( (string) ( $group['label'] ?? '' ) );
			$filter_lc  = array_map( 'strtolower', $pathway_filter );
			return in_array( $slug, $filter_lc, true )
				|| in_array( $label_slug, $filter_lc, true );
		}
		return true;
	}

	/**
	 * Stats-Header (X items · Y done · Z review · …).
	 *
	 * @param array $stats Stats-Dict.
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
			<button type="button" class="ttt-stat ttt-stat-total" data-filter-status="all" title="<?php esc_attr_e( 'Alle anzeigen', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $total; ?></span>
				<?php esc_html_e( 'Items', 'training-translation-tracker' ); ?>
			</button>
			<button type="button" class="ttt-stat ttt-stat-done" data-filter-status="done" title="<?php esc_attr_e( 'Nur fertige anzeigen', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $done; ?></span>
				<?php esc_html_e( 'fertig', 'training-translation-tracker' ); ?>
			</button>
			<button type="button" class="ttt-stat ttt-stat-review" data-filter-status="review" title="<?php esc_attr_e( 'Nur Review anzeigen', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $review; ?></span>
				<?php esc_html_e( 'Review', 'training-translation-tracker' ); ?>
			</button>
			<button type="button" class="ttt-stat ttt-stat-wip" data-filter-status="wip" title="<?php esc_attr_e( 'Nur in Arbeit anzeigen', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $wip; ?></span>
				<?php esc_html_e( 'in Arbeit', 'training-translation-tracker' ); ?>
			</button>
			<button type="button" class="ttt-stat ttt-stat-open" data-filter-status="open" title="<?php esc_attr_e( 'Nur offene anzeigen', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $open; ?></span>
				<?php esc_html_e( 'offen', 'training-translation-tracker' ); ?>
			</button>
			<span class="ttt-stat ttt-stat-na" title="<?php esc_attr_e( 'n/a — nicht filterbar', 'training-translation-tracker' ); ?>">
				<span class="ttt-stat-count"><?php echo (int) $na; ?></span>
				<?php esc_html_e( 'n/a', 'training-translation-tracker' ); ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Rendert eine Top-Level-Gruppe (pathway / handbook / orphan).
	 *
	 * @param array $group Gruppe.
	 * @return void
	 */
	private function render_group( $group ) {
		$type  = (string) ( $group['type'] ?? '' );
		$label = (string) ( $group['label'] ?? '' );
		$slug  = (string) ( $group['slug'] ?? sanitize_title( $label ) );
		$key   = $type . '-' . $slug;

		echo '<section class="ttt-group ttt-group-' . esc_attr( $type ) . '" data-group-key="' . esc_attr( $key ) . '">';
		// Group-Titel ist ein fester Anker, nicht klickbar — Einklappen passiert
		// nur auf Section-Ebene. So bleiben die Hauptüberschriften (Beginner
		// WordPress User, Lesson Plans, Training Handbook, Sonstige) immer
		// als visuelles Inhaltsverzeichnis sichtbar.
		echo '<h2 class="ttt-group-title">' . esc_html( $label ) . '</h2>';
		echo '<div class="ttt-group-body">';

		if ( 'pathway' === $type ) {
			foreach ( (array) ( $group['courses'] ?? array() ) as $course ) {
				// Group-Label als Parent mitgeben — wenn Course-Label gleich
				// ist, blendet render_course den h3 aus (redundant).
				$this->render_course( $course, $key, $label );
			}
		} elseif ( 'handbook' === $type ) {
			foreach ( (array) ( $group['sections'] ?? array() ) as $section ) {
				// Group-Label als Parent — wenn Section-Label gleich ist,
				// blendet render_section den h4 aus.
				$this->render_section( $section, $key, $label );
			}
		} elseif ( 'orphan' === $type ) {
			// Pseudo-Section um die Items, damit sie über die Section-Collapse-
			// Mechanik klappbar werden (analog zu Lesson Plans und Handbook).
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
	 * Rendert einen Course-Block (innerhalb eines Pathway).
	 *
	 * @param array $course Course.
	 * @return void
	 */
	private function render_course( $course, $parent_key = '', $parent_group_label = '' ) {
		$label = (string) ( $course['label'] ?? '' );
		$slug  = (string) ( $course['slug'] ?? sanitize_title( $label ) );
		$key   = trim( $parent_key . '-' . $slug, '-' );

		// Wenn das Course-Label identisch zum Group-Label ist, ist der Course-
		// Titel redundant (kommt bei „Lesson Plans" vor, wo Pathway → Course →
		// Section alle denselben Namen tragen). Dann den h3 weglassen.
		$is_redundant = ( '' !== $parent_group_label && $label === $parent_group_label );

		echo '<div class="ttt-course" data-course-key="' . esc_attr( $key ) . '">';
		if ( $label && ! $is_redundant ) {
			echo '<h3 class="ttt-course-title">' . esc_html( $label ) . '</h3>';
		}
		// Effektives Parent-Label für die Section: bei redundantem Course
		// vergleicht die Section direkt mit dem Group-Label.
		$effective_parent = $is_redundant ? $parent_group_label : $label;
		foreach ( (array) ( $course['sections'] ?? array() ) as $section ) {
			$this->render_section( $section, $key, $effective_parent );
		}
		echo '</div>';
	}

	/**
	 * Rendert eine Section (Module-Ebene).
	 *
	 * @param array $section Section.
	 * @return void
	 */
	private function render_section( $section, $parent_key = '', $parent_label = '' ) {
		$label = (string) ( $section['label'] ?? '' );
		$slug  = (string) ( $section['slug'] ?? sanitize_title( $label ) );
		$key   = trim( $parent_key . '-' . $slug, '-' );

		// Section-Header wird immer angezeigt (sofern Label nicht leer), auch
		// wenn er das Group-Label wiederholt. Grund: er ist der einzige
		// klickbare Toggle für Collapse — ohne ihn wäre die Section nicht ein-
		// klappbar. Die Course-Ebene unterdrücken wir weiterhin bei Redundanz
		// (siehe render_course), damit nicht drei Stufen denselben Namen tragen.
		echo '<div class="ttt-section" data-section-key="' . esc_attr( $key ) . '">';
		if ( $label ) {
			// Klickbarer Section-Titel zum Auf-/Zuklappen. role="button" + tabindex
			// macht ihn keyboard-zugänglich, aria-expanded zeigt den Zustand an.
			echo '<h4 class="ttt-section-title" role="button" tabindex="0" aria-expanded="true">';
			echo '<span class="ttt-section-toggle" aria-hidden="true">▾</span> ';
			echo esc_html( $label );
			echo '</h4>';
		}
		echo '<div class="ttt-section-body">';
		$this->render_item_list( (array) ( $section['items'] ?? array() ) );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Rendert eine flache Liste von Items.
	 *
	 * @param array $items Liste.
	 * @return void
	 */
	private function render_item_list( $items ) {
		if ( empty( $items ) ) {
			echo '<p class="ttt-empty-section">' . esc_html__( 'Keine Items in dieser Gruppe.', 'training-translation-tracker' ) . '</p>';
			return;
		}

		echo '<div class="ttt-cards">';
		foreach ( $items as $item ) {
			$this->render_item( $item );
		}
		echo '</div>';
	}

	/**
	 * Rendert ein einzelnes Item.
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

		// Komponenten-Lookup nach Name, damit wir sie in kanonischer Reihenfolge ausgeben.
		$components_by_name = array();
		foreach ( $components as $comp ) {
			$name = (string) ( $comp['name'] ?? '' );
			if ( $name ) {
				$components_by_name[ $name ] = $comp;
			}
		}

		// Übersetzungs-Titel: nicht hardcoden — wenn kein DE-Titel da ist, das EN als
		// Platzhalter verwenden, in gedeckter Farbe. Die Karte zeigt dann sofort an,
		// wo eine Übersetzung noch fehlt.
		$translation_text = $title_de ?: $title;
		$translation_is_placeholder = ! $title_de;

		// data-search: ein einziger lowercase-String mit allem, was wir durchsuchen.
		// Reduziert das JS auf ein simples `dataset.search.includes(query)`.
		$issue_number   = $issue && isset( $issue['number'] ) ? '#' . (int) $issue['number'] : '';
		$project_status = $issue ? (string) ( $issue['project_status'] ?? '' ) : '';
		$search_haystack = strtolower( trim(
			$title . ' ' . $title_de . ' ' . $issue_number . ' ' . $project_status
		) );

		// data-project-status: für den Dropdown-Filter im Header.
		// Der Slug (sanitize_title) macht das Matching robust gegen Großbuchstaben
		// und Sonderzeichen.
		$project_status_slug = $project_status !== '' ? sanitize_title( $project_status ) : '';

		echo '<article class="ttt-card ttt-overall-' . esc_attr( $overall )
			. '" data-status="' . esc_attr( $overall )
			. '" data-search="' . esc_attr( $search_haystack )
			. '" data-project-status="' . esc_attr( $project_status_slug )
			. '">';

		// ------- Zwei Spalten: Original / Translation -------
		echo '<div class="ttt-card-cols">';

		// Original-Spalte
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

		// Translation-Spalte
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

		// ------- Footer-Zeile: Issue + Marker links, Komponenten-Icons rechts -------
		echo '<div class="ttt-card-footer">';

		// Linke Seite: Issue-Nummer + State + Project-Status + Marker
		echo '<div class="ttt-card-footer-left">';
		if ( $issue && isset( $issue['url'], $issue['number'] ) ) {
			echo '<a class="ttt-issue-number" href="' . esc_url( (string) $issue['url'] ) . '" target="_blank" rel="noopener noreferrer">';
			echo '#' . (int) $issue['number'];
			echo '</a>';
			$state = (string) ( $issue['state'] ?? '' );
			if ( $state ) {
				echo ' <span class="ttt-issue-state ttt-issue-state-' . esc_attr( $state ) . '">' . esc_html( $state ) . '</span>';
			}
			// Project-V2-Status-Pill (z. B. "Translation in Progress"). Slug-Klasse
			// für gezielte Farbe pro Status.
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

		// Rechte Seite: Komponenten-Icons in kanonischer Reihenfolge
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
	 * Rendert die kleine Medien-Zeile innerhalb einer Karten-Spalte (Original oder Translation).
	 *
	 * @param string $wptv_url    WP.tv-Link oder leer.
	 * @param string $youtube_url YouTube-Link oder leer.
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
	 * Rendert ein einzelnes Komponenten-Icon (SVG) mit Status-Klasse und Tooltip.
	 *
	 * @param string $name Komponenten-Name (z.B. "text").
	 * @param array  $comp Komponenten-Eintrag aus tracker.json.
	 * @return void
	 */
	private function render_component_icon( $name, $comp ) {
		$status   = (string) ( $comp['status'] ?? 'open' );
		$creator  = (string) ( $comp['creator'] ?? '' );
		$reviewer = (string) ( $comp['reviewer'] ?? '' );

		// Fallback-Tooltip für No-JS-Browser und Screen-Reader.
		$tooltip = $name . ' · ' . $status;
		if ( $creator ) {
			$tooltip .= ' · ' . __( 'Creator', 'training-translation-tracker' ) . ': ' . $creator;
		}
		if ( $reviewer ) {
			$tooltip .= ' · ' . __( 'Reviewer', 'training-translation-tracker' ) . ': ' . $reviewer;
		}

		$icon_path = self::COMPONENT_ICONS[ $name ] ?? '';
		if ( '' === $icon_path ) {
			return;
		}

		// data-Attribute füttern das JS-Popover (Komponenten-Name, Status,
		// Personen). Bei Hover/Klick rendert das JS ein Custom-Popover mit
		// Avataren und GitHub-Profil-Links.
		// Inline width/height auf dem <svg>, damit Theme-Regeln wie
		// `svg { max-width: 100% }` die Icons nicht zu Block-Elementen aufblähen.
		echo '<span class="ttt-comp-icon ttt-comp-' . esc_attr( $status ) . '"';
		echo ' title="' . esc_attr( $tooltip ) . '"';
		echo ' aria-label="' . esc_attr( $tooltip ) . '"';
		echo ' role="button" tabindex="0"';
		echo ' data-comp-name="' . esc_attr( $name ) . '"';
		echo ' data-comp-status="' . esc_attr( $status ) . '"';
		echo ' data-comp-creator="' . esc_attr( $creator ) . '"';
		echo ' data-comp-reviewer="' . esc_attr( $reviewer ) . '">';
		echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false" style="width:18px;height:18px;display:block;flex-shrink:0;">';
		echo '<path d="' . esc_attr( $icon_path ) . '" fill="currentColor"/>';
		echo '</svg>';
		echo '</span>';
	}

	/**
	 * Sammelt sichtbare Marker für ein Item (Orphan, Parse-Error, Duplikat, Draft).
	 *
	 * @param array $item Item.
	 * @return array<array{key:string,label:string}>
	 */
	private function collect_markers( $item ) {
		$out = array();

		if ( ! empty( $item['parse_error'] ) ) {
			$out[] = array(
				'key'   => 'parse-error',
				'label' => __( 'Keine Tabelle', 'training-translation-tracker' ),
			);
		}

		$orphan_reason = $item['orphan_reason'] ?? '';
		if ( 'outside_scope' === $orphan_reason ) {
			$out[] = array(
				'key'   => 'outside-scope',
				'label' => __( 'Außerhalb Scope', 'training-translation-tracker' ),
			);
		} elseif ( 'missing_in_inventory' === $orphan_reason ) {
			$out[] = array(
				'key'   => 'missing-in-inventory',
				'label' => __( 'Verwaist', 'training-translation-tracker' ),
			);
		}

		if ( ! empty( $item['duplicate_issues'] ) ) {
			$out[] = array(
				'key'   => 'duplicate',
				'label' => __( 'Doppelt', 'training-translation-tracker' ),
			);
		}

		if ( ! empty( $item['draft_original'] ) ) {
			$out[] = array(
				'key'   => 'draft-original',
				'label' => __( 'Original Entwurf', 'training-translation-tracker' ),
			);
		}

		return $out;
	}
}
