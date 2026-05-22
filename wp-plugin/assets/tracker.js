/**
 * Training Translation Tracker - frontend interactivity.
 *
 * For each `.ttt-tracker` container, it binds:
 *   - Status filter buttons (data-filter-status="all|done|review|wip|open")
 *   - Stats pills (which share the same data-filter-status values)
 *   - Search field (.ttt-search-input) -> live search across data-search on each card
 *   - Collapse toggle per section (click on .ttt-section-title)
 *
 * State per tracker instance is persisted to localStorage.
 *
 * Vanilla JS, no jQuery, runs on all modern browsers (ES2015+).
 */

(function () {
	'use strict';

	// Prevent double init in case the script is loaded more than once by mistake.
	if (window.__tttTrackerInitialized) {
		return;
	}
	window.__tttTrackerInitialized = true;

	// i18n bundle from PHP via window.tttI18n. Fallbacks are in place for cases
	// where the bundle is missing for some reason (e.g. a caching plugin strips
	// inline scripts). The fallbacks are the original English strings, so that
	// nothing looks broken even without the translation bundle.
	var I18N = (window.tttI18n && typeof window.tttI18n === 'object') ? window.tttI18n : {};
	var LABEL_COLLAPSE_ALL = I18N.collapseAll || 'Collapse all';
	var LABEL_EXPAND_ALL = I18N.expandAll || 'Expand all';
	var STATUS_LABELS = (I18N.statusLabels && typeof I18N.statusLabels === 'object') ? I18N.statusLabels : {};
	var COMPONENT_LABELS = (I18N.componentLabels && typeof I18N.componentLabels === 'object') ? I18N.componentLabels : {};
	function tr(key, fallback) {
		return (I18N[key] != null && I18N[key] !== '') ? I18N[key] : fallback;
	}

	// One global initialization on DOMContentLoaded, which binds every tracker
	// on the page. If the shortcode appears multiple times (e.g. once per
	// pathway), each instance is handled separately.
	function init() {
		var trackers = document.querySelectorAll('.ttt-tracker');
		for (var i = 0; i < trackers.length; i++) {
			setupTracker(trackers[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		// Script ran with `defer`, the DOM is already parsed, so initialize directly.
		init();
	}

	// ------------------------------------------------------------------
	// Setup per tracker container
	// ------------------------------------------------------------------

	function setupTracker(root) {
		var trackerId = root.getAttribute('data-tracker-id') || 'ttt-default';
		var state = {
			status: 'all',
			query: '',
			projectStatus: '',     // empty = all project statuses
			component: '',         // empty = no component filter
			componentStatus: '',   // empty = any status (combined with component)
		};

		// Stats pills are the only filter UI (the pills are <button>, clickable).
		var statButtons = root.querySelectorAll('.ttt-stat[data-filter-status]');
		// Search input
		var searchInput = root.querySelector('.ttt-search-input');
		// Project status dropdown (optional, only if items with project_status exist)
		var projectStatusSelect = root.querySelector('.ttt-project-status-select');
		// Component + component-status dropdowns (combined filter, 0.4.4)
		var componentSelect = root.querySelector('.ttt-component-select');
		var componentStatusSelect = root.querySelector('.ttt-component-status-select');

		// Restore state from localStorage if available.
		var saved = loadState(trackerId);
		if (saved) {
			state.status = saved.status || 'all';
			state.query = saved.query || '';
			state.projectStatus = saved.projectStatus || '';
			state.component = saved.component || '';
			state.componentStatus = saved.componentStatus || '';
			if (searchInput && state.query) {
				searchInput.value = state.query;
			}
			if (projectStatusSelect && state.projectStatus) {
				projectStatusSelect.value = state.projectStatus;
			}
			if (componentSelect && state.component) {
				componentSelect.value = state.component;
			}
			if (componentStatusSelect && state.componentStatus) {
				componentStatusSelect.value = state.componentStatus;
			}
		}

		// Sync the initially active pill
		setActiveStatus(root, state.status);

		// Click handler: stats pills are the filters
		for (var j = 0; j < statButtons.length; j++) {
			statButtons[j].addEventListener('click', function (e) {
				state.status = e.currentTarget.getAttribute('data-filter-status') || 'all';
				setActiveStatus(root, state.status);
				applyFilters(root, state);
				saveState(trackerId, state);
			});
		}

		// Search input: live, debounced (150ms)
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

		// Project status dropdown: change event
		if (projectStatusSelect) {
			projectStatusSelect.addEventListener('change', function (e) {
				state.projectStatus = e.target.value || '';
				applyFilters(root, state);
				saveState(trackerId, state);
			});
		}

		// Component dropdown: change event (combined with componentStatus below)
		if (componentSelect) {
			componentSelect.addEventListener('change', function (e) {
				state.component = e.target.value || '';
				applyFilters(root, state);
				saveState(trackerId, state);
			});
		}

		// Component-status dropdown: change event
		if (componentStatusSelect) {
			componentStatusSelect.addEventListener('change', function (e) {
				state.componentStatus = e.target.value || '';
				applyFilters(root, state);
				saveState(trackerId, state);
			});
		}

		// Component popover (avatar + profile link) on hover/click
		setupCompPopover(root);

		// Collapse toggles per section (group titles remain fixed anchors)
		setupCollapse(root, trackerId);

		// "Collapse all / expand all" button
		setupCollapseAll(root, trackerId);

		// First application of filters (in case something came from localStorage)
		applyFilters(root, state);
	}

	// ------------------------------------------------------------------
	// "Collapse all / expand all" toggle
	//
	// LABEL_COLLAPSE_ALL / LABEL_EXPAND_ALL are defined above in the IIFE.
	// ------------------------------------------------------------------

	function setupCollapseAll(root, trackerId) {
		var btn = root.querySelector('.ttt-collapse-all-btn');
		if (!btn) return;

		// Set the initial button state based on the sections.
		refreshCollapseAllLabel(root, btn);

		btn.addEventListener('click', function () {
			// Which action is the button currently performing? "expanded" -> collapse; anything else -> expand.
			var current = btn.getAttribute('data-collapse-all-state') || 'expanded';
			var collapseAll = (current === 'expanded');
			setAllCollapsed(root, trackerId, collapseAll);
			refreshCollapseAllLabel(root, btn);
		});
	}

	// Collapses or expands all sections. Top-level groups stay unchanged,
	// since they are fixed anchors in the table of contents.
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
		// If at least one section is still expanded -> "Collapse all".
		// Otherwise -> "Expand all".
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
	// Apply filters
	// ------------------------------------------------------------------

	function applyFilters(root, state) {
		var cards = root.querySelectorAll('.ttt-card');
		var visibleCount = 0;

		for (var i = 0; i < cards.length; i++) {
			var card = cards[i];
			var status = card.getAttribute('data-status') || 'open';
			var search = card.getAttribute('data-search') || '';
			var projectStatus = card.getAttribute('data-project-status') || '';

			// Status filter (overall status). The pseudo-status "untouched"
			// (introduced in 0.4.5) is a sub-filter that matches cards where
			// every component icon is in state "unset".
			var matchStatus;
			if (state.status === 'all') {
				matchStatus = true;
			} else if (state.status === 'untouched') {
				var iconsAll = card.querySelectorAll('.ttt-comp-icon[data-comp-name]');
				if (iconsAll.length === 0) {
					matchStatus = false;
				} else {
					matchStatus = true;
					for (var u = 0; u < iconsAll.length; u++) {
						if (iconsAll[u].getAttribute('data-comp-status') !== 'unset') {
							matchStatus = false;
							break;
						}
					}
				}
			} else {
				matchStatus = (status === state.status);
			}

			// Search filter
			var matchQuery = (state.query === '') || (search.indexOf(state.query) !== -1);

			// Project status filter (slug match)
			var matchProjectStatus = (state.projectStatus === '') || (projectStatus === state.projectStatus);

			// Component (+ optional component-status) filter, 0.4.4.
			// When `state.component` is empty, no component filter applies and
			// `state.componentStatus` is ignored (it only makes sense as a
			// modifier on top of a chosen component).
			// When both are set: card must contain a `.ttt-comp-icon` whose
			// data-comp-name matches the component AND whose data-comp-status
			// matches the chosen component status.
			// When only the component is set: card must contain that component
			// (regardless of its status).
			var matchComponent = true;
			if (state.component) {
				var compIcons = card.querySelectorAll(
					'.ttt-comp-icon[data-comp-name="' + state.component + '"]'
				);
				if (compIcons.length === 0) {
					matchComponent = false;
				} else if (state.componentStatus) {
					matchComponent = false;
					for (var k = 0; k < compIcons.length; k++) {
						if (compIcons[k].getAttribute('data-comp-status') === state.componentStatus) {
							matchComponent = true;
							break;
						}
					}
				}
			}

			var visible = matchStatus && matchQuery && matchProjectStatus && matchComponent;
			// Hide via the [hidden] attribute. The associated CSS rule
			// `.ttt-tracker .ttt-card[hidden] { display: none !important }` has
			// higher specificity than the card's display rule and wins.
			if (visible) {
				card.removeAttribute('hidden');
				visibleCount++;
			} else {
				card.setAttribute('hidden', '');
			}
		}

		// Hide sections, courses, groups if they contain no visible cards.
		hideEmptyContainers(root, '.ttt-section');
		hideEmptyContainers(root, '.ttt-course');
		hideEmptyContainers(root, '.ttt-group');

		// Update the stats pills live: the counts mirror the cards matching
		// the current search (regardless of the active status filter), so the
		// user can see where they could still switch to.
		updateStatsPills(root, state);

		// Empty state at tracker level
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
		var untouched = 0;
		var total = 0;
		var cards = root.querySelectorAll('.ttt-card');
		for (var i = 0; i < cards.length; i++) {
			var card = cards[i];
			var search = card.getAttribute('data-search') || '';
			var projectStatus = card.getAttribute('data-project-status') || '';
			var matchQuery = (state.query === '') || (search.indexOf(state.query) !== -1);
			var matchProjectStatus = (state.projectStatus === '') || (projectStatus === state.projectStatus);

			// Mirror the component (+ component-status) filter applied in
			// applyFilters() so the pill counts reflect what is actually
			// visible (rather than the unfiltered totals).
			var matchComponent = true;
			if (state.component) {
				var compIcons = card.querySelectorAll(
					'.ttt-comp-icon[data-comp-name="' + state.component + '"]'
				);
				if (compIcons.length === 0) {
					matchComponent = false;
				} else if (state.componentStatus) {
					matchComponent = false;
					for (var k = 0; k < compIcons.length; k++) {
						if (compIcons[k].getAttribute('data-comp-status') === state.componentStatus) {
							matchComponent = true;
							break;
						}
					}
				}
			}

			if (!matchQuery || !matchProjectStatus || !matchComponent) continue;

			var status = card.getAttribute('data-status') || 'open';
			if (counts.hasOwnProperty(status)) {
				counts[status]++;
			}
			total++;

			// Untouched: card whose every component icon is "unset". This
			// is a sub-count, the same card is also counted in its overall
			// bucket above (typically "open").
			var iconsAll2 = card.querySelectorAll('.ttt-comp-icon[data-comp-name]');
			if (iconsAll2.length > 0) {
				var allUnset = true;
				for (var v = 0; v < iconsAll2.length; v++) {
					if (iconsAll2[v].getAttribute('data-comp-status') !== 'unset') {
						allUnset = false;
						break;
					}
				}
				if (allUnset) {
					untouched++;
				}
			}
		}
		setStatCount(root, 'ttt-stat-total', total);
		for (var s in counts) {
			if (counts.hasOwnProperty(s)) {
				setStatCount(root, 'ttt-stat-' + s, counts[s]);
			}
		}
		setStatCount(root, 'ttt-stat-unset', untouched);
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
	// Update the active state of the filter buttons
	// ------------------------------------------------------------------

	function setActiveStatus(root, status) {
		// Stats pills are the only filter UI. Active class for visual feedback.
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
	// Collapse toggle per section
	// ------------------------------------------------------------------

	// ------------------------------------------------------------------
	// Component popover: avatar + profile link on hover/click
	// ------------------------------------------------------------------
	//
	// Each tracker gets ONE single popover element, which on hover (mouse)
	// or click (touch/keyboard) on a component icon is filled with data
	// and positioned below the icon.
	//
	// Data source: data-comp-* attributes on the icon element (set by PHP).
	// Avatars come directly from github.com/<username>.png without an API call.

	function setupCompPopover(root) {
		var icons = root.querySelectorAll('.ttt-card-footer-right .ttt-comp-icon');
		if (icons.length === 0) return;

		// One shared popover element per tracker, created lazily on the first hover.
		var popover = null;
		var hideTimer = null;
		var currentIcon = null;

		function ensurePopover() {
			if (popover) return popover;
			popover = document.createElement('div');
			popover.className = 'ttt-comp-popover';
			popover.setAttribute('hidden', '');
			popover.setAttribute('role', 'dialog');
			popover.setAttribute('aria-label', tr('componentDetails', 'Component details'));
			// Do not close on hover over the popover itself, since the user
			// wants to be able to click the profile link.
			popover.addEventListener('mouseenter', function () {
				if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
			});
			popover.addEventListener('mouseleave', scheduleHide);
			// Keyboard handling in the open popover:
			//   Esc       -> close + return focus to the icon
			//   Tab       -> on the last element: close + focus the next icon
			//   Shift+Tab -> on the first element: close + return focus to the icon
			// This lets the user move cleanly through all component icons of a
			// card with the keyboard (otherwise Tab would jump out of the
			// popover into the WordPress footer, because the popover element is
			// appended at the end of .ttt-tracker).
			popover.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') {
					e.preventDefault();
					hideNow({ returnFocus: true });
					return;
				}
				if (e.key !== 'Tab') return;
				var focusables = popover.querySelectorAll('a, button, [tabindex]:not([tabindex="-1"])');
				if (focusables.length === 0) return;
				var first = focusables[0];
				var last = focusables[focusables.length - 1];
				if (e.shiftKey && document.activeElement === first) {
					// Shift+Tab on the first element -> close, focus back on the icon
					e.preventDefault();
					hideNow({ returnFocus: true });
				} else if (!e.shiftKey && document.activeElement === last) {
					// Tab on the last element -> move to the next component icon
					e.preventDefault();
					var icon = currentIcon;
					hideNow();
					if (!icon) return;
					var allIcons = Array.prototype.slice.call(
						root.querySelectorAll('.ttt-comp-icon')
					);
					var idx = allIcons.indexOf(icon);
					var next = allIcons[idx + 1];
					if (next) {
						next.focus();
					} else {
						// This was the last icon of the card, so return focus to the icon
						icon.focus();
					}
				}
			});
			root.appendChild(popover);
			return popover;
		}

		function fillPopover(icon) {
			var name = icon.getAttribute('data-comp-name') || '';
			var status = icon.getAttribute('data-comp-status') || 'open';
			var creator = icon.getAttribute('data-comp-creator') || '';
			var reviewer = icon.getAttribute('data-comp-reviewer') || '';

			// Run the component name and status through the i18n mapping so that
			// the popover appears in the WP locale instead of the raw English
			// tokens from tracker.json.
			var nameLabel = COMPONENT_LABELS[name] || name;
			var statusLabel = STATUS_LABELS[status] || status;

			var html = '<div class="ttt-comp-popover-header">' + escapeHtml(nameLabel) + '</div>';
			html += '<span class="ttt-comp-popover-status ttt-comp-status-' + escapeAttr(status) + '">' + escapeHtml(statusLabel) + '</span>';

			// Show both people (user requirement: even if identical, which should
			// never happen because Creator != Reviewer in the workflow). Empty
			// people are skipped, e.g. when nobody has reviewed.
			if (creator) {
				html += renderPerson(tr('creator', 'Creator'), creator);
			}
			if (reviewer) {
				html += renderPerson(tr('reviewer', 'Reviewer'), reviewer);
			}
			if (!creator && !reviewer && status !== 'na') {
				html += '<div class="ttt-comp-popover-unassigned">' + escapeHtml(tr('notAssigned', 'not yet assigned')) + '</div>';
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
			// Positions the popover below the icon, relative to the tracker
			// container. If there is no room on the right, shift it to the left.
			var rootRect = root.getBoundingClientRect();
			var iconRect = icon.getBoundingClientRect();
			popover.style.top = (iconRect.bottom - rootRect.top + 6) + 'px';
			// Make it visible first so we know its width.
			popover.removeAttribute('hidden');
			var popoverWidth = popover.offsetWidth;
			var leftPreferred = iconRect.left - rootRect.left;
			// Check past the right edge of the tracker, and shift in if needed.
			if (leftPreferred + popoverWidth > rootRect.width) {
				leftPreferred = rootRect.width - popoverWidth - 8;
			}
			if (leftPreferred < 0) leftPreferred = 0;
			popover.style.left = leftPreferred + 'px';
		}

		function showFor(icon, opts) {
			opts = opts || {};
			if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
			if (currentIcon && currentIcon !== icon) {
				currentIcon.setAttribute('aria-expanded', 'false');
			}
			currentIcon = icon;
			icon.setAttribute('aria-expanded', 'true');
			ensurePopover();
			fillPopover(icon);
			positionPopover(icon);
			// Keyboard a11y: if the popover was opened via the keyboard
			// (Enter/Space), move focus into the popover. Tab then reaches the
			// profile links; Esc closes and returns focus to the icon.
			if (opts.fromKeyboard) {
				var firstFocusable = popover.querySelector('a, button, [tabindex]:not([tabindex="-1"])');
				if (firstFocusable) {
					firstFocusable.focus();
				} else {
					// No interactive content, so make the popover itself focusable
					// so that Esc still works.
					popover.setAttribute('tabindex', '-1');
					popover.focus();
				}
			}
		}

		function hideNow(opts) {
			opts = opts || {};
			if (popover) popover.setAttribute('hidden', '');
			var iconToRefocus = currentIcon;
			if (currentIcon) currentIcon.setAttribute('aria-expanded', 'false');
			currentIcon = null;
			// Return focus to the triggering icon when invoked from Esc or Esc-in-popover.
			if (opts.returnFocus && iconToRefocus && typeof iconToRefocus.focus === 'function') {
				iconToRefocus.focus();
			}
		}

		function scheduleHide() {
			if (hideTimer) clearTimeout(hideTimer);
			hideTimer = setTimeout(hideNow, 200);
		}

		function bindIcon(icon) {
			icon.addEventListener('mouseenter', function () { showFor(icon); });
			icon.addEventListener('mouseleave', scheduleHide);
			// Click / tap (for touch and keyboard)
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
					showFor(icon, { fromKeyboard: true });
				} else if (e.key === 'Escape') {
					hideNow({ returnFocus: true });
				}
			});
		}

		for (var i = 0; i < icons.length; i++) {
			bindIcon(icons[i]);
		}

		// Click outside -> close
		document.addEventListener('click', function (e) {
			if (!popover || popover.hasAttribute('hidden')) return;
			if (popover.contains(e.target)) return;
			// If an icon in the same tracker was clicked, let the icon handler
			// do the work.
			if (e.target.closest && e.target.closest('.ttt-comp-icon')) return;
			hideNow();
		});
	}

	// Tiny escape helpers (no dependency-heavy framework needed).
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

			// Initial state from localStorage
			var collapsed = loadCollapse(trackerId, key);
			applyCollapsedState(section, title, collapsed);

			// Click + keyboard
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

		// Update the label of the collapse-all toggle button. It cannot be kept
		// in the tracker scope because this function is also invoked from the
		// keyboard handler, so look it up via the DOM.
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
	// localStorage helpers (with fallback when localStorage is blocked)
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
			// Quota exceeded or storage blocked, silently ignore.
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
