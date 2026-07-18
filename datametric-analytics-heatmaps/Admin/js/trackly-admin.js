/**
 * DataMetric Analytics Dashboard Script
 */
(function($) {
	'use strict';

	let mainChart = null;
	let sourceChart = null;
	let deviceChart = null;
	let nvrChart = null;
	let realtimeSpark = null;
	let realtimeInterval = null;

	// Site locale (e.g. "tr-TR") for date formatting; falls back to the browser locale.
	const LOCALE = (typeof datametricData !== 'undefined' && datametricData.locale) ? datametricData.locale : undefined;

	// Translatable strings with English fallbacks.
	const I18N = (typeof datametricData !== 'undefined' && datametricData.i18n) ? datametricData.i18n : {};
	function t(key, fallback) {
		return (I18N && I18N[key]) ? I18N[key] : fallback;
	}

	// Run a render step in isolation; log (in debug) but never let it abort sibling renders.
	function safe(fn) {
		try {
			fn();
		} catch (e) {
			if (typeof datametricData !== 'undefined' && datametricData.debug) {
				console.error('DataMetric render error: ', e);
			}
		}
	}

	// Extract the first dimension value from a GA4 row, tolerating malformed/empty rows.
	function dimValue(row) {
		return (row && row.dimensionValues && row.dimensionValues[0]) ? row.dimensionValues[0].value : null;
	}
	// Safe metric accessor: metric value at index, as float/int.
	function metric(row, i) {
		return (row && row.metricValues && row.metricValues[i]) ? row.metricValues[i].value : 0;
	}
	function intOf(v) { return parseInt(v, 10) || 0; }
	function num(v) { return intOf(v).toLocaleString(); }
	function pct(ratio) { return (parseFloat(ratio) * 100).toFixed(1) + '%'; }

	// Format a duration given in seconds as m:ss (consistent with the frontend panel).
	function formatDuration(totalSeconds) {
		const s = intOf(totalSeconds);
		const mins = Math.floor(s / 60);
		const secs = s % 60;
		return mins + ':' + (secs < 10 ? '0' : '') + secs;
	}

	// Only allow same-document relative paths as hrefs (blocks javascript:, data:, absolute URLs).
	function safeHref(path) {
		return /^\/[^/]/.test(path) || path === '/' ? path : '#';
	}

	function escapeHtml(string) {
		return String(string).replace(/[&<>"']/g, function(s) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;'
			}[s];
		});
	}

	$(document).ready(function() {
		initTabs();
		initCharts();
		loadDashboardData(7);
		startRealtimePolling();

		// Chart range filters
		$('.trackly-chart-filter-btn').on('click', function() {
			$('.trackly-chart-filter-btn').removeClass('active');
			$(this).addClass('active');
			const days = $(this).data('days');
			loadDashboardData(days);
		});
	});

	/**
	 * Tab switching mechanism
	 */
	function initTabs() {
		$('.trackly-tab-btn').on('click', function() {
			const target = $(this).data('target');

			$('.trackly-tab-btn').removeClass('active');
			$('.trackly-tab-content').removeClass('active');

			$(this).addClass('active');
			$('#' + target).addClass('active');
		});
	}

	/**
	 * Initialize empty ApexCharts instances with loading states
	 */
	function initCharts() {
		const donutBase = {
			chart: { type: 'donut', height: 280, fontFamily: 'inherit' },
			series: [],
			labels: [],
			legend: { position: 'bottom' },
			noData: { text: t('loading', 'Loading...') }
		};

		const mainOptions = {
			chart: {
				type: 'area',
				height: 350,
				toolbar: { show: false },
				zoom: { enabled: false },
				fontFamily: 'inherit',
				foreColor: '#64748b'
			},
			colors: ['#8b5cf6', '#06b6d4', '#10b981'],
			fill: {
				type: 'gradient',
				gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05, stops: [0, 100] }
			},
			dataLabels: { enabled: false },
			stroke: { curve: 'smooth', width: 3 },
			series: [],
			xaxis: { categories: [] },
			noData: { text: t('loading', 'Loading...'), align: 'center', verticalAlign: 'middle', style: { color: '#64748b', fontSize: '14px' } }
		};

		mainChart = new ApexCharts(document.querySelector('#trackly-main-chart'), mainOptions);
		sourceChart = new ApexCharts(document.querySelector('#trackly-source-chart'), Object.assign({}, donutBase, { colors: ['#8b5cf6', '#06b6d4', '#10b981', '#f43f5e', '#f59e0b', '#e2e8f0'] }));
		deviceChart = new ApexCharts(document.querySelector('#trackly-device-chart'), Object.assign({}, donutBase, { colors: ['#4f46e5', '#06b6d4', '#cbd5e1'] }));
		nvrChart = new ApexCharts(document.querySelector('#trackly-nvr-chart'), Object.assign({}, donutBase, { colors: ['#8b5cf6', '#10b981'] }));

		mainChart.render();
		sourceChart.render();
		deviceChart.render();
		nvrChart.render();

		// Realtime sparkline (small, no axes)
		const sparkEl = document.querySelector('#trackly-realtime-spark');
		if (sparkEl) {
			realtimeSpark = new ApexCharts(sparkEl, {
				chart: { type: 'area', height: 90, sparkline: { enabled: true }, animations: { enabled: false } },
				stroke: { curve: 'smooth', width: 2 },
				fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
				colors: ['#ffffff'],
				series: [{ name: t('users', 'Users'), data: [] }],
				tooltip: { enabled: true, x: { show: false }, theme: 'dark' }
			});
			realtimeSpark.render();
		}
	}

	/**
	 * Pull dashboard statistics via WordPress REST API
	 */
	function loadDashboardData(days) {
		$.ajax({
			url: datametricData.rest_url + '/stats',
			method: 'GET',
			data: { days: days },
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', datametricData.rest_nonce);
			},
			success: function(res) {
				if (!res.success) {
					if (typeof datametricData !== 'undefined' && datametricData.debug) {
						console.error('GA Data retrieval failed: ', res.error);
					}
					return;
				}
				// Isolate each section so a single malformed report never blanks the whole dashboard.
				safe(function() { updateSummary(res.summary); });
				safe(function() { updateMainChart(res.chart); });
				safe(function() { updateSourceChart(res.sources); });
				safe(function() { updateDeviceChart(res.devices); });
				safe(function() { updatePagesTable(res.pages); });
				safe(function() { renderAcquisitionTable(res.acquisition); });
				safe(function() { renderLandingTable(res.landing_pages); });
				safe(function() { renderGeoList(res.geography); });
				safe(function() { renderEventsTable(res.events); });
				safe(function() { renderNewVsReturning(res.new_vs_returning); });
				safe(function() { updateRealtimeValue(res.realtime_users); });
				safe(function() { updateRealtimeSpark(res.realtime_series); });
			},
			error: function(err) {
				if (typeof datametricData !== 'undefined' && datametricData.debug) {
					console.error('AJAX Error: ', err);
				}
			}
		});
	}

	/**
	 * Periodically fetch active visitor count + sparkline
	 */
	function startRealtimePolling() {
		realtimeInterval = setInterval(function() {
			if (document.visibilityState === 'hidden') {
				return;
			}
			$.ajax({
				url: datametricData.rest_url + '/realtime',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', datametricData.rest_nonce);
				},
				success: function(res) {
					if (res.success) {
						safe(function() { updateRealtimeValue(res.realtime_users); });
						safe(function() { updateRealtimeSpark(res.realtime_series); });
					}
				}
			});
		}, 20000);
	}

	function updateRealtimeValue(count) {
		const $counter = $('#trackly-active-users');
		// -1 is the "unavailable" sentinel (API error / not connected) — never render it as a real 0.
		const display = (parseInt(count, 10) < 0) ? t('unavailable', 'N/A') : count;
		$counter.fadeOut(150, function() {
			$(this).text(display).fadeIn(150);
		});
	}

	function updateRealtimeSpark(series) {
		if (!realtimeSpark || !Array.isArray(series)) return;
		const data = series.map(function(pt) { return intOf(pt.users); });
		realtimeSpark.updateSeries([{ name: t('users', 'Users'), data: data }]);
	}

	/**
	 * Update aggregate KPI cards.
	 * Metric order: screenPageViews, activeUsers, sessions, engagementRate, bounceRate, averageSessionDuration
	 */
	function updateSummary(summaryData) {
		if (!summaryData.rows || summaryData.rows.length === 0) return;
		const r = summaryData.rows[0];

		$('#trackly-stat-views').text(num(metric(r, 0)));
		$('#trackly-stat-users').text(num(metric(r, 1)));
		$('#trackly-stat-sessions').text(num(metric(r, 2)));
		$('#trackly-stat-engagement').text(pct(metric(r, 3)));
		$('#trackly-stat-bounce').text(pct(metric(r, 4)));
		$('#trackly-stat-duration').text(formatDuration(metric(r, 5)));
	}

	/**
	 * Map time series rows to ApexCharts series (Pageviews, Users, Sessions)
	 */
	function updateMainChart(chartData) {
		const categories = [];
		const viewsSeries = [];
		const usersSeries = [];
		const sessionsSeries = [];

		if (chartData.rows && chartData.rows.length > 0) {
			chartData.rows.forEach(function(row) {
				const rawDate = dimValue(row);
				if (!rawDate) return;
				const year = rawDate.substring(0, 4);
				const month = rawDate.substring(4, 6);
				const day = rawDate.substring(6, 8);
				const dateObj = new Date(year, month - 1, day);
				const formattedDate = dateObj.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' });

				categories.push(formattedDate);
				viewsSeries.push(intOf(metric(row, 0)));
				usersSeries.push(intOf(metric(row, 1)));
				sessionsSeries.push(intOf(metric(row, 2)));
			});
		}

		mainChart.updateOptions({ xaxis: { categories: categories } });
		mainChart.updateSeries([
			{ name: t('pageviews', 'Pageviews'), data: viewsSeries },
			{ name: t('users', 'Users'), data: usersSeries },
			{ name: t('sessions', 'Sessions'), data: sessionsSeries }
		]);
	}

	/**
	 * Generic donut renderer.
	 */
	function renderDonut(chart, rows, labelMap) {
		const series = [];
		const labels = [];
		if (rows && rows.length > 0) {
			rows.forEach(function(row) {
				const label = dimValue(row);
				if (label === null) return;
				labels.push(labelMap ? labelMap(label) : label);
				series.push(intOf(metric(row, 0)));
			});
		}
		chart.updateOptions({ labels: labels });
		chart.updateSeries(series);
	}

	function updateSourceChart(sourcesData) {
		renderDonut(sourceChart, sourcesData && sourcesData.rows);
	}

	function updateDeviceChart(devicesData) {
		renderDonut(deviceChart, devicesData && devicesData.rows, function(dev) {
			return dev === 'desktop' ? t('desktop', 'Desktop') : (dev === 'mobile' ? t('mobile', 'Mobile') : (dev === 'tablet' ? t('tablet', 'Tablet') : dev));
		});
	}

	function renderNewVsReturning(data) {
		renderDonut(nvrChart, data && data.rows, function(v) {
			return v === 'new' ? t('newVisitors', 'New') : (v === 'returning' ? t('returningVisitors', 'Returning') : v);
		});
	}

	/**
	 * Render Top Pages table.
	 * Metric order: screenPageViews, activeUsers, engagementRate, averageSessionDuration
	 */
	function updatePagesTable(pagesData) {
		const $tbody = $('#trackly-pages-table tbody');
		$tbody.empty();
		const rows = pagesData && pagesData.rows;
		if (!rows || rows.length === 0) {
			$tbody.append('<tr><td colspan="5" class="loading-td">' + escapeHtml(t('noData', 'No data found.')) + '</td></tr>');
			return;
		}
		rows.forEach(function(row) {
			const path = dimValue(row);
			if (path === null) return;
			const escPath = escapeHtml(path);
			const escHref = escapeHtml(safeHref(path));
			$tbody.append(
				'<tr>' +
					'<td><a href="' + escHref + '" target="_blank" rel="noopener nofollow" class="trackly-page-link"><code>' + escPath + '</code></a></td>' +
					'<td><strong>' + num(metric(row, 0)) + '</strong></td>' +
					'<td>' + num(metric(row, 1)) + '</td>' +
					'<td>' + pct(metric(row, 2)) + '</td>' +
					'<td>' + formatDuration(metric(row, 3)) + '</td>' +
				'</tr>'
			);
		});
	}

	/**
	 * Traffic acquisition table (source/medium).
	 * Metric order: sessions, activeUsers, engagementRate, keyEvents
	 */
	function renderAcquisitionTable(data) {
		const $tbody = $('#trackly-acquisition-table tbody');
		$tbody.empty();
		const rows = data && data.rows;
		if (!rows || rows.length === 0) {
			$tbody.append('<tr><td colspan="5" class="loading-td">' + escapeHtml(t('noData', 'No data found.')) + '</td></tr>');
			return;
		}
		rows.forEach(function(row) {
			const label = dimValue(row);
			if (label === null) return;
			$tbody.append(
				'<tr>' +
					'<td>' + escapeHtml(label) + '</td>' +
					'<td><strong>' + num(metric(row, 0)) + '</strong></td>' +
					'<td>' + num(metric(row, 1)) + '</td>' +
					'<td>' + pct(metric(row, 2)) + '</td>' +
					'<td>' + num(metric(row, 3)) + '</td>' +
				'</tr>'
			);
		});
	}

	/**
	 * Landing pages table.
	 * Metric order: sessions, engagementRate, screenPageViews
	 */
	function renderLandingTable(data) {
		const $tbody = $('#trackly-landing-table tbody');
		$tbody.empty();
		const rows = data && data.rows;
		if (!rows || rows.length === 0) {
			$tbody.append('<tr><td colspan="4" class="loading-td">' + escapeHtml(t('noData', 'No data found.')) + '</td></tr>');
			return;
		}
		rows.forEach(function(row) {
			const path = dimValue(row);
			if (path === null) return;
			const escPath = escapeHtml(path);
			const escHref = escapeHtml(safeHref(path));
			$tbody.append(
				'<tr>' +
					'<td><a href="' + escHref + '" target="_blank" rel="noopener nofollow" class="trackly-page-link"><code>' + escPath + '</code></a></td>' +
					'<td><strong>' + num(metric(row, 0)) + '</strong></td>' +
					'<td>' + pct(metric(row, 1)) + '</td>' +
					'<td>' + num(metric(row, 2)) + '</td>' +
				'</tr>'
			);
		});
	}

	/**
	 * Top countries as a horizontal bar list (avoids a heavy map dependency).
	 * Metric order: activeUsers
	 */
	function renderGeoList(data) {
		const $wrap = $('#trackly-geo-list');
		$wrap.empty();
		const rows = data && data.rows;
		if (!rows || rows.length === 0) {
			$wrap.append('<p class="loading-td">' + escapeHtml(t('noData', 'No data found.')) + '</p>');
			return;
		}
		let max = 0;
		rows.forEach(function(row) { max = Math.max(max, intOf(metric(row, 0))); });
		max = max || 1;
		rows.forEach(function(row) {
			const label = dimValue(row);
			if (label === null) return;
			const val = intOf(metric(row, 0));
			const width = Math.max(2, Math.round((val / max) * 100));
			$wrap.append(
				'<div class="trackly-bar-row">' +
					'<span class="trackly-bar-label">' + escapeHtml(label) + '</span>' +
					'<span class="trackly-bar-track"><span class="trackly-bar-fill" style="width:' + width + '%"></span></span>' +
					'<span class="trackly-bar-value">' + num(val) + '</span>' +
				'</div>'
			);
		});
	}

	/**
	 * Top events table.
	 * Metric order: eventCount
	 */
	function renderEventsTable(data) {
		const $tbody = $('#trackly-events-table tbody');
		$tbody.empty();
		const rows = data && data.rows;
		if (!rows || rows.length === 0) {
			$tbody.append('<tr><td colspan="2" class="loading-td">' + escapeHtml(t('noData', 'No data found.')) + '</td></tr>');
			return;
		}
		rows.forEach(function(row) {
			const label = dimValue(row);
			if (label === null) return;
			$tbody.append(
				'<tr>' +
					'<td><code>' + escapeHtml(label) + '</code></td>' +
					'<td><strong>' + num(metric(row, 0)) + '</strong></td>' +
				'</tr>'
			);
		});
	}

})(jQuery);
