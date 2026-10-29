(function () {
	'use strict';

	var cfg = window.mtMcpAdmin || {};
	var statusEl = document.querySelector('.mt-mcp-save-status');
	var statusTimer = null;

	function setStatus(text, isError) {
		if (!statusEl) return;
		statusEl.textContent = text || '';
		statusEl.classList.toggle('is-error', !!isError);
		statusEl.classList.toggle('is-ok', !!text && !isError);
		clearTimeout(statusTimer);
		if (text && !isError) {
			statusTimer = setTimeout(function () {
				statusEl.textContent = '';
				statusEl.classList.remove('is-ok', 'is-error');
			}, 1800);
		}
	}

	function saveSetting(key, value) {
		setStatus('Saving…');
		var body = new FormData();
		body.append('action', 'mt_mcp_save_setting');
		body.append('nonce', cfg.nonce || '');
		body.append('key', key);
		if (typeof value === 'object') {
			body.append('value', JSON.stringify(value));
		} else {
			body.append('value', value);
		}

		return fetch(cfg.ajaxUrl || (window.ajaxurl || ''), {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
			.then(function (res) {
				return res.json().then(function (data) {
					if (!res.ok || !data || !data.success) {
						var msg = (data && data.data && data.data.message) || 'Save failed.';
						throw new Error(msg);
					}
					return data.data || {};
				});
			})
			.then(function (data) {
				setStatus('Saved');
				updatePills(key, data);
				return data;
			})
			.catch(function (err) {
				setStatus(err.message || 'Save failed.', true);
				throw err;
			});
	}

	function updatePills(key, data) {
		var pill;
		if (key === 'enabled') {
			pill = document.querySelector('[data-pill="enabled"]');
			if (!pill) return;
			var on = !!data.value;
			pill.textContent = on ? 'Server on' : 'Server off';
			pill.classList.toggle('is-on', on);
			pill.classList.toggle('is-off', !on);
		} else if (key === 'allow_writes') {
			pill = document.querySelector('[data-pill="writes"]');
			if (!pill) return;
			var writes = !!data.value;
			pill.textContent = 'Writes ' + (writes ? 'on' : 'off');
			pill.classList.toggle('is-warn', writes);
		} else if (key === 'environment') {
			pill = document.querySelector('[data-pill="environment"]');
			if (!pill) return;
			pill.textContent = data.label || ((cfg.envLabels || {})[data.value]) || data.value;
		}
	}

	document.querySelectorAll('.mt-mcp-radio').forEach(function (label) {
		var input = label.querySelector('input[type="radio"]');
		if (!input) return;
		function sync() {
			label.classList.toggle('is-selected', input.checked);
		}
		input.addEventListener('change', function () {
			document.querySelectorAll('.mt-mcp-radio').forEach(function (el) {
				el.classList.remove('is-selected');
			});
			sync();
		});
		sync();
	});

	document.querySelectorAll('.mt-mcp-copy').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var target = btn.getAttribute('data-copy-target') === 'prev'
				? btn.previousElementSibling
				: document.querySelector(btn.getAttribute('data-copy-target'));
			if (!target) return;
			var text = target.value || target.textContent || '';
			function done() {
				var original = btn.textContent;
				btn.textContent = 'Copied';
				setTimeout(function () {
					btn.textContent = original;
				}, 1400);
			}
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done).catch(function () {
					target.select();
					document.execCommand('copy');
					done();
				});
			} else {
				target.select();
				document.execCommand('copy');
				done();
			}
		});
	});

	document.querySelectorAll('.mt-mcp-autosave').forEach(function (el) {
		el.addEventListener('change', function () {
			var key = el.getAttribute('data-key');
			if (!key) return;
			var value;
			if (el.type === 'checkbox') {
				value = el.checked ? '1' : '0';
			} else {
				value = el.value;
			}
			saveSetting(key, value);
		});
	});

	var toolRoot = document.getElementById('mt-mcp-tool-groups');
	if (toolRoot) {
		toolRoot.addEventListener('change', function (e) {
			if (!e.target.classList.contains('mt-mcp-tool-group')) return;
			var groups = {};
			toolRoot.querySelectorAll('.mt-mcp-tool-group').forEach(function (cb) {
				groups[cb.getAttribute('data-group')] = cb.checked ? '1' : '';
			});
			saveSetting('tool_groups', groups);
		});
	}

	var webhookInput = document.getElementById('mt-mcp-webhook-url');
	var webhookSave = document.querySelector('.mt-mcp-webhook-save');
	if (webhookInput && webhookSave) {
		function syncWebhookDirty() {
			var dirty = webhookInput.value !== (webhookInput.getAttribute('data-saved') || '');
			webhookSave.hidden = !dirty;
		}
		webhookInput.addEventListener('input', syncWebhookDirty);
		webhookInput.addEventListener('change', syncWebhookDirty);
		webhookSave.addEventListener('click', function () {
			webhookSave.disabled = true;
			saveSetting('webhook_url', webhookInput.value)
				.then(function (data) {
					webhookInput.setAttribute('data-saved', data.value || '');
					webhookInput.value = data.value || '';
					syncWebhookDirty();
				})
				.catch(function () { /* status already set */ })
				.finally(function () {
					webhookSave.disabled = false;
				});
		});
		syncWebhookDirty();
	}
})();
