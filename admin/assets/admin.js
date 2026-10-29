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
			var sel = btn.getAttribute('data-copy-target');
			var target = null;
			if (sel === 'prev') {
				target = btn.previousElementSibling;
			} else if (sel) {
				target = document.querySelector(sel);
			}
			if (!target) {
				var wrap = btn.closest('.mt-mcp-copy-row, .mt-mcp-code-wrap');
				if (wrap) {
					target = wrap.querySelector('input, textarea');
				}
			}
			if (!target) return;
			var text = ('value' in target ? target.value : target.textContent) || '';
			function done() {
				var original = btn.getAttribute('data-label') || btn.textContent;
				btn.setAttribute('data-label', original);
				btn.textContent = (cfg.i18n && cfg.i18n.copied) || 'Copied';
				btn.classList.add('is-copied');
				setTimeout(function () {
					btn.textContent = original;
					btn.classList.remove('is-copied');
				}, 1400);
			}
			function fallback() {
				if (typeof target.select === 'function') {
					target.focus();
					target.select();
				}
				try {
					document.execCommand('copy');
				} catch (e) { /* ignore */ }
				done();
			}
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done).catch(fallback);
			} else {
				fallback();
			}
		});
	});

	function saveGrantLabel(input) {
		var row = input.closest('tr');
		if (!row) return;
		var next = (input.value || '').trim();
		var prev = input.getAttribute('data-saved') || '';
		if (next === prev) return;
		var status = row.querySelector('.mt-mcp-grant-label-status');
		var body = new FormData();
		body.append('action', 'mt_mcp_save_grant_label');
		body.append('nonce', cfg.labelNonce || '');
		body.append('user_id', row.getAttribute('data-user-id') || '');
		body.append('client_id', row.getAttribute('data-client-id') || '');
		body.append('label', next);
		input.disabled = true;
		if (status) {
			status.textContent = '';
			status.classList.remove('is-error');
		}
		fetch(cfg.ajaxUrl || (window.ajaxurl || ''), {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
			.then(function (res) {
				return res.json().then(function (data) {
					if (!res.ok || !data || !data.success) {
						throw new Error((data && data.data && data.data.message) || 'Save failed.');
					}
					return data.data || {};
				});
			})
			.then(function () {
				input.setAttribute('data-saved', next);
				if (status) {
					status.textContent = (cfg.i18n && cfg.i18n.saved) || 'Saved';
					setTimeout(function () {
						if (status.textContent === ((cfg.i18n && cfg.i18n.saved) || 'Saved')) {
							status.textContent = '';
						}
					}, 1600);
				}
			})
			.catch(function (err) {
				if (status) {
					status.textContent = err.message || 'Save failed.';
					status.classList.add('is-error');
				}
			})
			.finally(function () {
				input.disabled = false;
			});
	}

	document.querySelectorAll('.mt-mcp-grant-label').forEach(function (input) {
		input.addEventListener('blur', function () {
			saveGrantLabel(input);
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				input.blur();
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

	/* Recipes cookbook: search + category + read/write filters */
	(function initRecipes() {
		var grid = document.getElementById('mt-mcp-recipes-grid');
		if (!grid) return;
		var q = document.getElementById('mt-mcp-recipes-q');
		var catRoot = document.getElementById('mt-mcp-recipes-cats');
		var writeRoot = document.getElementById('mt-mcp-recipes-writes');
		var countEl = document.getElementById('mt-mcp-recipes-count');
		var emptyEl = document.getElementById('mt-mcp-recipes-empty');
		var cat = '';
		var writes = '';

		function setChipGroup(root, attr, value) {
			if (!root) return;
			root.querySelectorAll('.mt-mcp-chip').forEach(function (btn) {
				btn.classList.toggle('is-active', (btn.getAttribute(attr) || '') === value);
			});
		}

		function apply() {
			var query = (q && q.value ? q.value : '').trim().toLowerCase();
			var cards = grid.querySelectorAll('.mt-mcp-recipe');
			var shown = 0;
			cards.forEach(function (card) {
				var okCat = !cat || card.getAttribute('data-cat') === cat;
				var okWrite = writes === '' || card.getAttribute('data-writes') === writes;
				var hay = card.getAttribute('data-search') || '';
				var okQ = !query || hay.indexOf(query) !== -1;
				var show = okCat && okWrite && okQ;
				card.hidden = !show;
				if (show) shown++;
			});
			if (countEl) {
				countEl.textContent = shown + ' recipe' + (shown === 1 ? '' : 's');
			}
			if (emptyEl) {
				emptyEl.hidden = shown > 0;
			}
		}

		if (catRoot) {
			catRoot.addEventListener('click', function (e) {
				var btn = e.target.closest('.mt-mcp-chip');
				if (!btn) return;
				cat = btn.getAttribute('data-cat') || '';
				setChipGroup(catRoot, 'data-cat', cat);
				apply();
			});
		}
		if (writeRoot) {
			writeRoot.addEventListener('click', function (e) {
				var btn = e.target.closest('.mt-mcp-chip');
				if (!btn) return;
				writes = btn.getAttribute('data-writes') || '';
				setChipGroup(writeRoot, 'data-writes', writes);
				apply();
			});
		}
		if (q) {
			q.addEventListener('input', apply);
		}
		apply();
	})();
})();
