/**
 * Training Translation Tracker — Frontend-Interaktivität.
 *
 * Bindet pro `.ttt-tracker`-Container:
 *   - Status-Filter-Buttons (data-filter-status="all|done|review|wip|open")
 *   - Stats-Pills (haben dieselben data-filter-status-Werte)
 *   - Suchfeld (.ttt-search-input) → Live-Search über data-search auf jeder Karte
 *   - Collapse-Toggle pro Section (Klick auf .ttt-section-title)
 *
 * State pro Tracker-Instanz wird in localStorage gespeichert.
 *
 * Vanilla-JS, kein jQuery, läuft auf allen modernen Browsern (ES2015+).
 */

(function () {
	'use strict';

	// Doppel-Init verhindern, falls das Skript versehentlich mehrfach geladen wird.
	if (window.__tttTrackerInitialized) {
		return;
	}
	window.__tttTrackerInitialized = true;

	// Sprach-Strings für den Collapse-Alle-Button. Müssen VOR init() definiert
	// sein, weil bei `defer`-Skripten init() synchron läuft, sobald wir den
	// if/else-Block unten erreichen.
	var LABEL_COLLAPSE_ALL = 'Alle einklappen';
	var LABEL_EXPAND_ALL = 'Alle ausklappen';

	// Eine globale Initialisierung beim DOMContentLoaded — bindet alle Tracker auf
	// der Seite. Falls der Shortcode mehrfach vorkommt (z.B. einmal pro Pathway),
	// werden alle separat gehandhabt.
	function init() {
		var trackers = document.querySelectorAll('.ttt-tracker');
		for (var i = 0; i < trackers.length; i++) {
			setupTracker(trackers[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		// Script lief mit `defer`, DOM ist schon parsed — direkt initialisieren.
		init();
	}

	// ------------------------------------------------------------------
	// Setup pro Tracker-Container
	// ------------------------------------------------------------------

	function setupTracker(root) {
		var trackerId = root.getAttribute('data-tracker-id') || 'ttt-default';
		var state = {
			status: 'all',
			query: '',
			projectStatus: '', // leer = alle Project-Status
		};

		// Stats-Pills sind die einzige Filter-UI (Pillen sind <button>, klickbar).
		var statButtons = root.querySelectorAll('.ttt-stat[data-filter-status]');
		// Search-Input
		var searchInput = root.querySelector('.ttt-search-input');
		// Project-Status-Dropdown (optional — nur wenn Items mit project_status existieren)
		var projectStatusSelect = root.querySelector('.ttt-project-status-select');

		// State aus localStorage wiederherstellen, falls vorhanden.
		var saved = loadState(trackerId);
		if (saved) {
			state.status = saved.status || 'all';
			state.query = saved.query || '';
			state.projectStatus = saved.projectStatus || '';
			if (searchInput && state.query) {
				searchInput.value = state.query;
			}
			if (projectStatusSelect && state.projectStatus) {
				projectStatusSelect.value = state.projectStatus;
			}
		}

		// Initiale Active-Pille synchronisieren
		setActiveStatus(root, state.status);

		// Click-Handler: Stats-Pillen sind die Filter
		for (var j = 0; j < statButtons.length; j++) {
			statButtons[j].addEventListener('click', function (e) {
				state.status = e.currentTarget.getAttribute('data-filter-status') || 'all';
				setActiveStatus(root, state.status);
				applyFilters(root, state);
				saveState(trackerId, state);
			});
		}

		// Search-Input: live, debounced (150ms)
		if (searchInput) {
			var debounceTimer = null;
			searchInput.addEventListener('input', function (e) {
				clearTimeout(debounceTimer);
				debounceTimer = setTimeout(function () {
					state.query = e.target.value.trim().toLowerCase();
					applyFilters(root, state);
					saveState(trackerId, state);
				}, 150);
			});
		}

		// Project-Status-Dropdown: Change-Event
		if (projectStatusSelect) {
			projectStatusSelect.addEventListener('change', function (e) {
				state.projectStatus = e.target.value || '';
				applyFilters(root, state);
				saveState(trackerId, state);
			});
		}

		// Komponenten-Popover (Avatar + Profil-Link) bei Hover/Klick
		setupCompPopover(root);

		// Collapse-Toggles pro Section (Group-Titel bleiben feste Anker)
		setupCollapse(root, trackerId);

		// "Alle einklappen / ausklappen"-Button
		setupCollapseAll(root, trackerId);

		// Erste Anwendung der Filter (für den Fall, dass aus localStorage was kam)
		applyFilters(root, state);
	}

	// ------------------------------------------------------------------
	// "Alle einklappen / ausklappen"-Toggle
	//
	// LABEL_COLLAPSE_ALL / LABEL_EXPAND_ALL sind oben in der IIFE definiert.
	// ------------------------------------------------------------------

	function setupCollapseAll(root, trackerId) {
		var btn = root.querySelector('.ttt-collapse-all-btn');
		if (!btn) return;

		// Initialen Button-Zustand basierend auf den Sections setzen.
		refreshCollapseAllLabel(root, btn);

		btn.addEventListener('click', function () {
			// Welche Aktion macht der Button gerade? "expanded" → einklappen; alles andere → ausklappen.
			var current = btn.getAttribute('data-collapse-all-state') || 'expanded';
			var collapseAll = (current === 'expanded');
			setAllCollapsed(root, trackerId, collapseAll);
			refreshCollapseAllLabel(root, btn);
		});
	}

	// Klappt alle Sections ein/aus. Top-Level-Groups bleiben unverändert —
	// die sind feste Anker im Inhaltsverzeichnis.
	function setAllCollapsed(root, trackerId, collapsed) {
		var sections = root.querySelectorAll('.ttt-section');
		for (var i = 0; i < sections.length; i++) {
			var section = sections[i];
			var titleEl = section.querySelector('.ttt-section-title');
			if (!titleEl) continue;
			var key = section.getAttribute('data-section-key') || '';
			applyCollapsedState(section, titleEl, collapsed);
			saveCollapse(trackerId, key, collapsed);
		}
	}

	function refreshCollapseAllLabel(root, btn) {
		// Wenn mindestens eine Section noch aufgeklappt ist → "Alle einklappen".
		// Sonst "Alle ausklappen".
		var anyExpanded = false;
		var sections = root.querySelectorAll('.ttt-section');
		for (var i = 0; i < sections.length; i++) {
			if (!sections[i].classList.contains('ttt-section-collapsed')) {
				anyExpanded = true;
				break;
			}
		}
		if (anyExpanded) {
			btn.textContent = LABEL_COLLAPSE_ALL;
			btn.setAttribute('data-collapse-all-state', 'expanded');
		} else {
			btn.textContent = LABEL_EXPAND_ALL;
			btn.setAttribute('data-collapse-all-state', 'collapsed');
		}
	}

	// ------------------------------------------------------------------
	// Filter anwenden
	// ------------------------------------------------------------------

	function applyFilters(root, state) {
		var cards = root.querySelectorAll('.ttt-card');
		var visibleCount = 0;

		for (var i = 0; i < cards.length; i++) {
			var card = cards[i];
			var status = card.getAttribute('data-status') || 'open';
			var search = card.getAttribute('data-search') || '';
			var projectStatus = card.getAttribute('data-project-status') || '';

			// Status-Filter (Component-Overall-Status)
			var matchStatus = (state.status === 'all') || (status === state.status);

			// Such-Filter
			var matchQuery = (state.query === '') || (search.indexOf(state.query) !== -1);

			// Project-Status-Filter (Slug-Match)
			var matchProjectStatus = (state.projectStatus === '') || (projectStatus === state.projectStatus);

			var visible = matchStatus && matchQuery && matchProjectStatus;
			// Verstecken über das [hidden]-Attribut. Die zugehörige CSS-Regel
			// `.ttt-tracker .ttt-card[hidden] { display: none !important }` hat
			// höhere Specificity als die Display-Regel der Karte und gewinnt.
			if (visible) {
				card.removeAttribute('hidden');
				visibleCount++;
			} else {
				card.setAttribute('hidden', '');
			}
		}

		// Sections, Courses, Groups verstecken, wenn keine sichtbaren Cards drin sind.
		hideEmptyContainers(root, '.ttt-section');
		hideEmptyContainers(root, '.ttt-course');
		hideEmptyContainers(root, '.ttt-group');

		// Stats-Pillen live updaten — die Counts spiegeln die Karten, die zur
		// aktuellen Suche passen (unabhängig vom aktiven Status-Filter), damit
		// der User sieht, wohin er noch wechseln könnte.
		updateStatsPills(root, state);

		// Empty-State auf Tracker-Ebene
		var emptyMsg = root.querySelector('.ttt-no-results');
		if (emptyMsg) {
			if (visibleCount === 0) {
				emptyMsg.removeAttribute('hidden');
			} else {
				emptyMsg.setAttribute('hidden', '');
			}
		}
	}

	function updateStatsPills(root, state) {
		var counts = { done: 0, review: 0, wip: 0, open: 0, na: 0 };
		var total = 0;
		var cards = root.querySelectorAll('.ttt-card');
		for (var i = 0; i < cards.length; i++) {
			var card = cards[i];
			var search = card.getAttribute('data-search') || '';
			var projectStatus = card.getAttribute('data-project-status') || '';
			var matchQuery = (state.query === '') || (search.indexOf(state.query) !== -1);
			var matchProjectStatus = (state.projectStatus === '') || (projectStatus === state.projectStatus);
			if (!matchQuery || !matchProjectStatus) continue;
			var status = card.getAttribute('data-status') || 'open';
			if (counts.hasOwnProperty(status)) {
				counts[status]++;
			}
			total++;
		}
		setStatCount(root, 'ttt-stat-total', total);
		for (var s in counts) {
			if (counts.hasOwnProperty(s)) {
				setStatCount(root, 'ttt-stat-' + s, counts[s]);
			}
		}
	}

	function setStatCount(root, className, value) {
		var pill = root.querySelector('.' + className);
		if (!pill) return;
		var span = pill.querySelector('.ttt-stat-count');
		if (span) span.textContent = String(value);
	}

	function hideEmptyContainers(root, selector) {
		var containers = root.querySelectorAll(selector);
		for (var i = 0; i < containers.length; i++) {
			var visibleInside = containers[i].querySelectorAll('.ttt-card:not([hidden])');
			if (visibleInside.length === 0) {
				containers[i].setAttribute('hidden', '');
			} else {
				containers[i].removeAttribute('hidden');
			}
		}
	}

	// ------------------------------------------------------------------
	// Active-State der Filter-Buttons aktualisieren
	// ------------------------------------------------------------------

	function setActiveStatus(root, status) {
		// Stats-Pills sind die einzige Filter-UI. Active-Class für visuelles Feedback.
		var stats = root.querySelectorAll('.ttt-stat[data-filter-status]');
		for (var j = 0; j < stats.length; j++) {
			var statStatus = stats[j].getAttribute('data-filter-status');
			if (statStatus === status) {
				stats[j].classList.add('ttt-stat-active');
			} else {
				stats[j].classList.remove('ttt-stat-active');
			}
		}
	}

	// ------------------------------------------------------------------
	// Collapse-Toggle pro Section
	// ------------------------------------------------------------------

	// ------------------------------------------------------------------
	// Komponenten-Popover — Avatar + Profil-Link bei Hover/Klick
	// ------------------------------------------------------------------
	//
	// Jeder Tracker bekommt EIN einziges Popover-Element, das beim Hover
	// (Maus) oder Klick (Touch/Tastatur) auf ein Komponenten-Icon mit
	// Daten gefüllt und unter dem Icon positioniert wird.
	//
	// Datenquelle: data-comp-* Attribute am Icon-Element (vom PHP gesetzt).
	// Avatare kommen direkt von github.com/<username>.png ohne API-Call.

	function setupCompPopover(root) {
		var icons = root.querySelectorAll('.ttt-card-footer-right .ttt-comp-icon');
		if (icons.length === 0) return;

		// Ein gemeinsames Popover-Element pro Tracker, lazy beim ersten Hover erzeugt.
		var popover = null;
		var hideTimer = null;
		var currentIcon = null;

		function ensurePopover() {
			if (popover) return popover;
			popover = document.createElement('div');
			popover.className = 'ttt-comp-popover';
			popover.setAttribute('hidden', '');
			popover.setAttribute('role', 'dialog');
			// Beim Hover über das Popover selbst nicht schließen — der User
			// will den Profil-Link anklicken können.
			popover.addEventListener('mouseenter', function () {
				if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
			});
			popover.addEventListener('mouseleave', scheduleHide);
			root.appendChild(popover);
			return popover;
		}

		function fillPopover(icon) {
			var name = icon.getAttribute('data-comp-name') || '';
			var status = icon.getAttribute('data-comp-status') || 'open';
			var creator = icon.getAttribute('data-comp-creator') || '';
			var reviewer = icon.getAttribute('data-comp-reviewer') || '';

			var html = '<div class="ttt-comp-popover-header">' + escapeHtml(name) + '</div>';
			html += '<span class="ttt-comp-popover-status ttt-comp-status-' + escapeAttr(status) + '">' + escapeHtml(status) + '</span>';

			// Beide Personen anzeigen (User-Vorgabe: auch wenn identisch — sollte
			// nie vorkommen, weil Creator ≠ Reviewer im Workflow). Leere Personen
			// werden übersprungen — wenn z. B. niemand reviewed hat.
			if (creator) {
				html += renderPerson('Creator', creator);
			}
			if (reviewer) {
				html += renderPerson('Reviewer', reviewer);
			}
			if (!creator && !reviewer && status !== 'na') {
				html += '<div style="color:#868e96;font-style:italic;padding:0.3rem 0;">noch nicht zugewiesen</div>';
			}

			popover.innerHTML = html;
		}

		function renderPerson(role, username) {
			var avatarUrl = 'https://github.com/' + encodeURIComponent(username) + '.png?size=64';
			var profileUrl = 'https://github.com/' + encodeURIComponent(username);
			return '<div class="ttt-comp-popover-person">'
				+ '<img class="ttt-comp-popover-avatar" src="' + avatarUrl + '" alt="" loading="lazy" referrerpolicy="no-referrer">'
				+ '<div class="ttt-comp-popover-text">'
				+ '<span class="ttt-comp-popover-role">' + escapeHtml(role) + '</span>'
				+ '<span class="ttt-comp-popover-username"><a href="' + profileUrl + '" target="_blank" rel="noopener noreferrer">@' + escapeHtml(username) + '</a></span>'
				+ '</div></div>';
		}

		function positionPopover(icon) {
			// Positioniert das Popover unterhalb des Icons, relativ zum
			// Tracker-Container. Wenn rechts kein Platz, nach links versetzen.
			var rootRect = root.getBoundingClientRect();
			var iconRect = icon.getBoundingClientRect();
			popover.style.top = (iconRect.bottom - rootRect.top + 6) + 'px';
			// Erst sichtbar machen, damit wir die Breite kennen.
			popover.removeAttribute('hidden');
			var popoverWidth = popover.offsetWidth;
			var leftPreferred = iconRect.left - rootRect.left;
			// Über den rechten Rand des Trackers schauen — bei Bedarf einrücken.
			if (leftPreferred + popoverWidth > rootRect.width) {
				leftPreferred = rootRect.width - popoverWidth - 8;
			}
			if (leftPreferred < 0) leftPreferred = 0;
			popover.style.left = leftPreferred + 'px';
		}

		function showFor(icon) {
			if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
			if (currentIcon && currentIcon !== icon) {
				currentIcon.setAttribute('aria-expanded', 'false');
			}
			currentIcon = icon;
			icon.setAttribute('aria-expanded', 'true');
			ensurePopover();
			fillPopover(icon);
			positionPopover(icon);
		}

		function hideNow() {
			if (popover) popover.setAttribute('hidden', '');
			if (currentIcon) currentIcon.setAttribute('aria-expanded', 'false');
			currentIcon = null;
		}

		function scheduleHide() {
			if (hideTimer) clearTimeout(hideTimer);
			hideTimer = setTimeout(hideNow, 200);
		}

		function bindIcon(icon) {
			icon.addEventListener('mouseenter', function () { showFor(icon); });
			icon.addEventListener('mouseleave', scheduleHide);
			// Klick / Tap (für Touch und Tastatur)
			icon.addEventListener('click', function (e) {
				e.preventDefault();
				if (currentIcon === icon && popover && !popover.hasAttribute('hidden')) {
					hideNow();
				} else {
					showFor(icon);
				}
			});
			icon.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					showFor(icon);
				} else if (e.key === 'Escape') {
					hideNow();
				}
			});
		}

		for (var i = 0; i < icons.length; i++) {
			bindIcon(icons[i]);
		}

		// Klick außerhalb → schließen
		document.addEventListener('click', function (e) {
			if (!popover || popover.hasAttribute('hidden')) return;
			if (popover.contains(e.target)) return;
			// Wenn auf ein Icon im selben Tracker geklickt wurde, lass den
			// Icon-Handler die Arbeit machen.
			if (e.target.closest && e.target.closest('.ttt-comp-icon')) return;
			hideNow();
		});
	}

	// Mini-Escape-Helfer (kein dependency-heavy Framework nötig).
	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function escapeAttr(s) {
		return String(s).replace(/[^a-zA-Z0-9_-]/g, '');
	}

	function setupCollapse(root, trackerId) {
		var titles = root.querySelectorAll('.ttt-section-title');
		for (var i = 0; i < titles.length; i++) {
			var title = titles[i];
			var section = title.closest('.ttt-section');
			if (!section) continue;
			var key = section.getAttribute('data-section-key') || '';

			// Initialzustand aus localStorage
			var collapsed = loadCollapse(trackerId, key);
			applyCollapsedState(section, title, collapsed);

			// Click + Keyboard
			title.addEventListener('click', function (e) {
				toggleSection(e.currentTarget, trackerId);
			});
			title.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					toggleSection(e.currentTarget, trackerId);
				}
			});
		}
	}

	function toggleSection(titleEl, trackerId) {
		var section = titleEl.closest('.ttt-section');
		if (!section) return;
		var key = section.getAttribute('data-section-key') || '';
		var collapsed = !section.classList.contains('ttt-section-collapsed');
		applyCollapsedState(section, titleEl, collapsed);
		saveCollapse(trackerId, key, collapsed);

		// Alle-Toggle-Button-Label aktualisieren — kann nicht im Tracker-Scope
		// gehalten werden, weil die Funktion auch aus dem Keyboard-Handler
		// kommt. Über das DOM raussuchen.
		var tracker = section.closest('.ttt-tracker');
		if (tracker) {
			var btn = tracker.querySelector('.ttt-collapse-all-btn');
			if (btn) refreshCollapseAllLabel(tracker, btn);
		}
	}

	function applyCollapsedState(section, titleEl, collapsed) {
		if (collapsed) {
			section.classList.add('ttt-section-collapsed');
			titleEl.setAttribute('aria-expanded', 'false');
			var toggle = titleEl.querySelector('.ttt-section-toggle');
			if (toggle) toggle.textContent = '▸';
		} else {
			section.classList.remove('ttt-section-collapsed');
			titleEl.setAttribute('aria-expanded', 'true');
			var t = titleEl.querySelector('.ttt-section-toggle');
			if (t) t.textContent = '▾';
		}
	}

	// ------------------------------------------------------------------
	// localStorage-Helfer (mit Fallback wenn localStorage gesperrt ist)
	// ------------------------------------------------------------------

	function storageKey(trackerId, suffix) {
		return 'ttt:' + trackerId + ':' + suffix;
	}

	function safeStorageGet(key) {
		try {
			return window.localStorage.getItem(key);
		} catch (e) {
			return null;
		}
	}

	function safeStorageSet(key, value) {
		try {
			window.localStorage.setItem(key, value);
		} catch (e) {
			// Quota überschritten oder Storage gesperrt — still ignorieren.
		}
	}

	function loadState(trackerId) {
		var raw = safeStorageGet(storageKey(trackerId, 'state'));
		if (!raw) return null;
		try {
			return JSON.parse(raw);
		} catch (e) {
			return null;
		}
	}

	function saveState(trackerId, state) {
		safeStorageSet(storageKey(trackerId, 'state'), JSON.stringify(state));
	}

	function loadCollapse(trackerId, sectionKey) {
		var raw = safeStorageGet(storageKey(trackerId, 'collapse:' + sectionKey));
		return raw === '1';
	}

	function saveCollapse(trackerId, sectionKey, collapsed) {
		safeStorageSet(storageKey(trackerId, 'collapse:' + sectionKey), collapsed ? '1' : '0');
	}

})();
