/* Live refresh of the Sites table while backups or verifications run. */
(function ($) {
	var INTERVAL = 10000;
	var timer = null;

	function bytes(n) {
		var units = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
		n = parseInt(n, 10) || 0;
		while (n >= 1024 && i < units.length - 1) {
			n /= 1024;
			i++;
		}
		return (i ? n.toFixed(2) : n) + ' ' + units[i];
	}

	function stamp(ts, withYear) {
		var d = new Date(ts * 1000);
		var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		var hm = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
		return months[d.getMonth()] + ' ' + d.getDate() + (withYear ? ', ' + d.getFullYear() + ' ' + hm : '');
	}

	function badge(cls, text, title) {
		return $('<span>').addClass('badge badge-' + cls).text(text).attr('title', title || null);
	}

	function render(status) {
		var jobs = status._jobs || [];
		$('#kb-activity').toggleClass('d-none', jobs.length === 0);
		$('#kb-activity-list').text(jobs.map(function (j) { return j.replace(/-/g, ' '); }).join(', '));

		$('tr[data-site]').each(function () {
			var row = $(this), st = status[row.data('site')];
			if (!st) {
				return;
			}
			row.find('.kb-last').text(st.finished ? stamp(st.finished, true) : 'never');
			var result = row.find('.kb-result').empty();
			if (st.running) {
				var since = st.started ? ' since ' + stamp(st.started, true).split(' ').pop() : '';
				result.append(badge('info', 'running' + since));
			} else if (st.ok === true) {
				result.append(badge('success', 'ok'));
			} else if (st.ok === 'interrupted') {
				result.append(badge('warning', 'interrupted'));
			} else if (st.ok === false) {
				result.append(badge('danger', 'failed', st.error));
			}
			if (st.verifying) {
				result.append(' ').append(badge('info', 'verifying'));
			}
			var snaps = row.find('.kb-snapshots').empty();
			if (st.snapshots !== null && st.snapshots !== undefined) {
				snaps.text(st.snapshots).append($('<small>').addClass('text-muted').text(' since ' + stamp(st.oldest)));
			}
			row.find('.kb-stored').text(st.stored ? bytes(st.stored) : '');
			var verified = row.find('.kb-verified').empty();
			if (st.verified) {
				verified.text(stamp(st.verified) + ' ').append(badge(st.verify_ok ? 'success' : 'danger', st.verify_ok ? 'ok' : 'failed'));
			}
			row.find('button[name=backup]').prop('disabled', !!st.running);
			row.find('button[name=verify]').prop('disabled', !!st.running || !!st.verifying || !st.finished);
		});

		return jobs.length > 0 || Object.keys(status).some(function (k) { return k[0] !== '_' && (status[k].running || status[k].verifying); });
	}

	function poll() {
		apnscp.cmd('kopiabackup_status', [], {indicator: null}).then(function (resp) {
			if (!resp || !resp.success) {
				return;
			}
			var busy = render(resp.return || {});
			// slow down when idle so the page costs nothing while nothing runs
			timer = setTimeout(poll, busy ? INTERVAL : INTERVAL * 6);
		}, function () {
			timer = setTimeout(poll, INTERVAL * 6);
		});
	}

	$(function () {
		if ($('tr[data-site]').length) {
			timer = setTimeout(poll, INTERVAL);
		}
	});
})(jQuery);
