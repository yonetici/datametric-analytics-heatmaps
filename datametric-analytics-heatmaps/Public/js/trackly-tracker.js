/**
 * Trackly Analytics Click Tracker (Lightweight & GDPR/Consent Compliant)
 * Loaded for all visitors. Captures click coordinates normalized to viewport percentages.
 * Respects popular cookie consent plugins and features session-based sampling.
 */
(function() {
	'use strict';

	let clicksQueue = [];

	document.addEventListener('DOMContentLoaded', function() {
		// Bind any admin-configured custom GA4 events (independent of click sampling; gtag
		// enforces its own Consent Mode state before anything is actually sent).
		bindCustomEvents();

		// 1. Consent Check (GDPR/KVKK Compliance)
		if ( ! hasConsent() ) {
			return; // Abort immediately if consent is denied
		}

		// 2. Sampling Rate Evaluation (DB Table Bloat Protection)
		if ( ! isSampled() ) {
			return; // Abort if session is not sampled
		}

		initTracker();
	});

	/**
	 * Bind click handlers for admin-configured custom GA4 events. Each matching element
	 * fires a gtag('event', ...) on click. Mirrors the server-provided custom_events list.
	 */
	function bindCustomEvents() {
		const customEvents = window.datametricTrackerData && window.datametricTrackerData.custom_events;
		if ( ! customEvents || ! Array.isArray( customEvents ) ) {
			return;
		}

		customEvents.forEach(function(item) {
			// Isolate each selector: one malformed selector must not abort the whole loop.
			let nodes;
			try {
				nodes = document.querySelectorAll(item.selector);
			} catch (e) {
				return;
			}
			nodes.forEach(function(el) {
				el.addEventListener('click', function() {
					if ( typeof gtag === 'function' ) {
						gtag('event', item.event_name, {
							'event_category': 'datametric_custom',
							'event_label': item.selector
						});
					}
				});
			});
		});
	}

	/**
	 * Precise Cookie Reader Helper
	 */
	function getCookie(name) {
		const value = `; ${document.cookie}`;
		const parts = value.split(`; ${name}=`);
		if ( parts.length === 2 ) {
			return parts.pop().split(';').shift();
		}
		return null;
	}

	/**
	 * Verify Cookie Consent and Google Consent Mode flags
	 */
	function hasConsent() {
		let consentPluginDetected = false;

		// A. Check Google Consent Mode v2 (if analytics storage is explicitly denied)
		if ( window.google_tag_data && window.google_tag_data.ics && window.google_tag_data.ics.entries ) {
			consentPluginDetected = true;
			const storage = window.google_tag_data.ics.entries.analytics_storage;
			if ( storage && ( storage.current === false || storage.current === 'denied' ) ) {
				return false;
			}
		}

		// B. Complianz Cookie Consent plugin check
		const complianz = getCookie('complianz_consent_status');
		if ( complianz ) {
			consentPluginDetected = true;
			if ( complianz !== 'allow' ) {
				return false;
			}
		}

		// C. GDPR Cookie Consent (CookieLawInfo) check
		const cli = getCookie('viewed_cookie_policy');
		if ( cli ) {
			consentPluginDetected = true;
			if ( cli !== 'yes' ) {
				return false;
			}
		}

		// D. Borlabs Cookie check (must contain 'statistics' permission)
		const borlabs = getCookie('borlabs-cookie');
		if ( borlabs ) {
			consentPluginDetected = true;
			if ( borlabs.indexOf('statistics') === -1 ) {
				return false;
			}
		}

		// If strict GDPR consent is required and no consent plugin is active, deny tracking by default (Strict Opt-In)
		const requireConsent = parseInt( window.datametricTrackerData.require_consent ) === 1;
		if ( requireConsent && ! consentPluginDetected ) {
			return false;
		}

		return true; // Default to true if consent is not strictly required and no blocking cookies are detected
	}

	/**
	 * Evaluate session-based random sampling
	 */
	function isSampled() {
		const rate = parseInt( window.datametricTrackerData.sampling_rate ) || 100;
		if ( rate >= 100 ) {
			return true;
		}

		let sampled = sessionStorage.getItem('datametric_is_sampled');
		if ( sampled === null ) {
			const rand = Math.floor( Math.random() * 100 ) + 1;
			sampled = ( rand <= rate ) ? 'true' : 'false';
			sessionStorage.setItem('datametric_is_sampled', sampled);
		}

		return sampled === 'true';
	}

	function initTracker() {
		// Teardown existing listeners to prevent leaks if re-initialized (Step 5: Memory Leak Protection)
		if ( typeof window.datametricTrackerAbort === 'function' ) {
			try {
				window.datametricTrackerAbort();
			} catch(e) {}
		}

		const controller = new AbortController();
		const signalOption = { signal: controller.signal };

		window.datametricTrackerAbort = function() {
			controller.abort();
		};

		document.addEventListener('click', function(e) {
			// Do not log clicks inside the admin floating bar
			if ( e.target.closest('#trackly-stats-bar-wrapper') ) {
				return;
			}

			// Do not log clicks when element selector mode is active
			if ( window.datametricSelectorModeActive ) {
				return;
			}

			const selector = getUniqueSelector(e.target);
			const docWidth = Math.max(
				document.documentElement.clientWidth,
				document.body.scrollWidth,
				document.documentElement.scrollWidth,
				document.body.offsetWidth,
				document.documentElement.offsetWidth
			);
			const docHeight = Math.max(
				document.documentElement.clientHeight,
				document.body.scrollHeight,
				document.documentElement.scrollHeight,
				document.body.offsetHeight,
				document.documentElement.offsetHeight
			);

			const clickData = {
				page_url: window.datametricTrackerData.page_url,
				element_tag: e.target.tagName.toLowerCase(),
				element_selector: selector,
				click_x_pct: parseFloat(((e.pageX / docWidth) * 100).toFixed(2)),
				click_y_pct: parseFloat(((e.pageY / docHeight) * 100).toFixed(2))
			};

			clicksQueue.push(clickData);

			// Flush queue if it contains 3 or more clicks
			if ( clicksQueue.length >= 3 ) {
				flushClicks();
			}
		}, signalOption);

		// Flush remaining clicks on page hide or exit
		window.addEventListener('pagehide', flushClicks, signalOption);
		window.addEventListener('visibilitychange', function() {
			if ( document.visibilityState === 'hidden' ) {
				flushClicks();
			}
		}, signalOption);
	}

	function flushClicks() {
		if ( clicksQueue.length === 0 ) {
			return;
		}

		const batch = clicksQueue.slice();
		clicksQueue = [];

		// Send the whole batch in ONE request (this is the point of batching). The endpoint
		// authorizes anonymous writes by same-origin, so no nonce is needed (nonces break under
		// full-page caching for logged-out visitors).
		fetch(window.datametricTrackerData.rest_url + '/record-click', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json'
			},
			body: JSON.stringify({ clicks: batch }),
			keepalive: true
		}).catch(function() {
			// Fail silently
		});
	}

	/**
	 * CSS.escape wrapper with a safe fallback for very old browsers.
	 */
	function cssEscape(value) {
		if ( window.CSS && typeof window.CSS.escape === 'function' ) {
			return window.CSS.escape(value);
		}
		// Minimal fallback: escape characters that are invalid in an identifier.
		return String(value).replace(/([^a-zA-Z0-9_-])/g, '\\$1');
	}

	/**
	 * Unique CSS Selector builder. Works for HTML and SVG elements, and escapes ids/classes so
	 * framework class names (e.g. Tailwind's "md:flex", "w-1/2") produce valid, queryable selectors.
	 */
	function getUniqueSelector(el) {
		// SVG nodes are Element but NOT HTMLElement; use the broader Element check.
		if ( ! ( el instanceof Element ) ) {
			return '';
		}
		let path = [];
		while ( el && el.nodeType === Node.ELEMENT_NODE ) {
			let selector = el.nodeName.toLowerCase();
			if ( el.id ) {
				selector += '#' + cssEscape(el.id);
				path.unshift(selector);
				break;
			} else {
				let className = '';
				if ( typeof el.className === 'string' ) {
					className = el.className.trim();
				} else if ( el.getAttribute ) {
					// SVG elements expose className as an SVGAnimatedString, not a string.
					className = ( el.getAttribute('class') || '' ).trim();
				}

				className = className.replace('trackly-selector-hovered', '').trim();
				if ( className ) {
					const classes = className.split(/\s+/).filter(Boolean).map(cssEscape);
					if ( classes.length ) {
						selector += '.' + classes.join('.');
					}
				}

				let sib = el, nth = 1;
				while ( sib = sib.previousElementSibling ) {
					if ( sib.nodeName.toLowerCase() === el.nodeName.toLowerCase() ) {
						nth++;
					}
				}
				if ( nth !== 1 ) {
					selector += ':nth-of-type(' + nth + ')';
				}
			}
			path.unshift(selector);
			el = el.parentNode;
		}
		return path.join(' > ');
	}
	window.datametricGetUniqueSelector = getUniqueSelector;
})();
