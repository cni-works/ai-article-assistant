(function () {
	'use strict';
	const root = document.querySelector('.aaa-admin');
	if (!root) return;
	const planning = root.querySelector('[data-aaa-choice="planning_mode"]');
	if (planning) {
		const form = planning.closest('form');
		const syncConsultation = function () {
			const purpose = form.querySelector('[name="data[planning_mode]"]:checked')?.value || 'topic';
			const interaction = form.querySelector('[name="data[interaction_mode]"]:checked')?.value || 'guided';
			const titles = purpose === 'titles', proofreading = !titles && interaction === 'proofread';
			const panels = { 'interaction': !titles, 'overview': !proofreading, 'proofread': proofreading, 'titles': titles, 'production': !titles, 'writing': !titles && !proofreading };
			Object.keys(panels).forEach(function (name) {
				const panel = form.querySelector('[data-aaa-' + name + '-fields]');
				if (!panel) return;
				panel.hidden = !panels[name];
				// Keep dormant values submitted/preserved. Only active controls can block HTML validation.
				panel.querySelectorAll('[required], [data-aaa-was-required]').forEach(function (input) {
					if (input.required) input.dataset.aaaWasRequired = '1';
					input.required = panels[name] && input.dataset.aaaWasRequired === '1';
				});
			});
			const proof = form.querySelector('[name="data[interaction_mode]"][value="proofread"]');
			if (proof) { proof.disabled = purpose === 'ideas'; proof.setAttribute('aria-disabled', purpose === 'ideas' ? 'true' : 'false'); }
			const source = form.querySelector('[name="data[source_draft]"]');
			if (source) source.required = proofreading;
		};
		form.addEventListener('change', function (event) {
			if (event.target.name === 'data[planning_mode]' && event.target.value === 'ideas') {
				const proof = form.querySelector('[name="data[interaction_mode]"][value="proofread"]');
				if (proof && proof.checked) { form.querySelector('[name="data[interaction_mode]"][value="guided"]').checked = true; }
			}
			syncConsultation();
		});
		form.addEventListener('click', function (event) {
			if (event.target.matches('[name="data[interaction_mode]"][value="proofread"]') && form.querySelector('[name="data[planning_mode]"]:checked').value === 'ideas') { event.preventDefault(); }
		});
		syncConsultation();
	}
	const setupChoice = root.querySelector('.aaa-setup-choice');
	if (setupChoice) {
		const form = setupChoice.closest('form'), scopes = form.querySelector('[data-aaa-setup-scope]'), help = form.querySelector('[data-aaa-setup-method-help]');
		const update = function () {
			const choice = form.querySelector('[name="consultation"]:checked')?.value || 'basic';
			const selected = Array.from(form.querySelectorAll('[name="scope[]"]:checked'));
			if (scopes) scopes.hidden = choice !== 'specific';
			form.querySelectorAll('[name="scope[]"]').forEach(function (input) { input.setCustomValidity(''); });
			if (choice === 'specific' && !selected.length) { const first = form.querySelector('[name="scope[]"]'); if (first) first.setCustomValidity('相談する項目を1つ以上選んでください。'); }
			const details = choice === 'details' || (choice === 'specific' && selected.length === 1 && selected[0].value === 'rules');
			if (help) help.textContent = details ? '詳細設定として、現在の文章・画像等の有効値を診断します。標準値で十分な項目は維持します。' : (choice === 'specific' ? '基本相談の形式で、選択した項目と必要な依存関係だけ相談します。基本・詳細にまたがる場合も差分CSVを1つ作成します。' : '基本初期設定として、サイト情報・カテゴリー・記事の種類を相談します。決定済みの内容は繰り返し質問しません。');
		};
		form.addEventListener('change', update); update();
	}
	root.querySelectorAll('[data-aaa-csv-drop]').forEach(function (zone) {
		const csvLabel = zone.dataset.aaaCsvLabel || '記事CSV';
		const input = zone.querySelector('input[type="file"]');
		const pick = zone.querySelector('[data-aaa-csv-pick]');
		const status = zone.querySelector('[data-aaa-csv-selection]');
		const error = zone.querySelector('[data-aaa-csv-error]');
		if (!input || !pick || !status || !error) return;
		pick.hidden = false; pick.tabIndex = 0; pick.setAttribute('role', 'button');
		input.classList.add('aaa-file-input'); input.tabIndex = -1;
		pick.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); input.click(); }
		});
		const problem = function (files) {
			if (files.length !== 1) return csvLabel + 'を1点だけ選んでください。';
			if (!/\.csv$/i.test(files[0].name)) return csvLabel + '（.csv）を選んでください。画像やZIPはここでは読み込めません。';
			if (files[0].size > 1048576) return 'CSVが1MBを超えています。記事を分けるか、作成担当者に確認してください。';
			if (files[0].size === 0) return '選んだCSVは空です。ChatGPTから受け取ったファイルを確認してください。';
			return '';
		};
		const tell = function (message) { error.textContent = message; error.hidden = !message; };
		input.addEventListener('change', function () {
			if (!input.files.length) { status.textContent = 'まだファイルを選んでいません。'; tell(''); return; }
			const message = problem(input.files);
			if (message) { input.value = ''; status.textContent = 'ファイルを選び直してください。'; tell(message); return; }
			tell(''); status.textContent = '選択したファイル：' + input.files[0].name + '（まだ読み込んでいません）';
		});
		let depth = 0;
		zone.addEventListener('dragenter', function (event) { event.preventDefault(); ++depth; zone.classList.add('is-dragging'); });
		zone.addEventListener('dragover', function (event) { event.preventDefault(); if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy'; });
		zone.addEventListener('dragleave', function (event) { event.preventDefault(); if (--depth <= 0) { depth = 0; zone.classList.remove('is-dragging'); } });
		zone.addEventListener('drop', function (event) {
			event.preventDefault(); event.stopPropagation(); depth = 0; zone.classList.remove('is-dragging');
			const files = event.dataTransfer && event.dataTransfer.files;
			const message = files ? problem(files) : 'ファイルを受け取れませんでした。選択ボタンを使ってください。';
			if (message) { tell(message + (input.files.length ? ' 前に選んだCSVは維持しています。' : '')); return; }
			try { input.files = files; input.dispatchEvent(new Event('change', { bubbles: true })); }
			catch (_) { tell('このブラウザーではドロップを選択欄へ反映できません。「' + csvLabel + 'を選ぶ」から選んでください。'); }
		});
	});
	const uploadedIds = new Set();
	// Record only upload events observed on this page, never infer origin from a filename/date.
	if (window.wp && window.wp.Uploader && window.wp.Uploader.queue) {
		window.wp.Uploader.queue.on('add', function (model) {
			if (!model.get('uploading')) return;
			const remember = function () { if (Number(model.id) > 0) uploadedIds.add(Number(model.id)); };
			remember(); model.on('change:id', remember);
		});
	}
	const previewImage = function (container, file) {
		if (!container) return;
		container.textContent = '';
		if (!file || !String(file.mime || '').startsWith('image/')) return;
		const url = (file.sizes && file.sizes.thumbnail && file.sizes.thumbnail.url) || file.url;
		try { if (!['https:', 'http:'].includes(new URL(url).protocol)) return; } catch (_) { return; }
		const img = document.createElement('img'); img.className = 'aaa-media-thumb'; img.src = url; img.alt = '選択画像の確認'; container.appendChild(img);
	};
	const showSelection = function (row, file) {
		previewImage(row.querySelector('[data-aaa-media-preview]'), file);
		const origin = row.querySelector('[data-aaa-media-origin]');
		if (origin) origin.textContent = uploadedIds.has(Number(file.id)) ? 'この画面を開いてからアップロードした画像・PDF' : '標準メディアから選択（追加時期は未記録）';
		const state = row.querySelector('[data-aaa-mapping-state]');
		if (state) state.textContent = '選択中・未保存：サムネイルと用途を確認して保存してください。';
		const mode = row.querySelector('[data-aaa-placement-mode]');
		if (mode && ['dummy', 'hold'].includes(mode.value)) { mode.value = 'real'; mode.dispatchEvent(new Event('change', { bubbles: true })); }
	};
	root.querySelectorAll('[data-aaa-media-id], [data-aaa-placement-mode]').forEach(function (input) {
		input.addEventListener('change', function () {
			const row = input.closest('[data-aaa-media-row]');
			const state = row && row.querySelector('[data-aaa-mapping-state]');
			if (state) state.textContent = '変更中・未保存：対応を確認して保存・プレビューを更新してください。';
			if (input.hasAttribute('data-aaa-media-id')) {
				previewImage(row.querySelector('[data-aaa-media-preview]'), null);
				const label = row.querySelector('[data-aaa-media-selection]');
				if (label) label.textContent = 'メディアID ' + input.value + ' を指定（保存して実ファイルを再確認してください）';
			}
		});
	});
	root.querySelectorAll('[data-aaa-media-batch]').forEach(function (button) {
		if (!window.wp || typeof window.wp.media !== 'function') return;
		button.hidden = false;
		const form = button.closest('form');
		let selectedFiles = [];
		const choices = new Map();
		const leadingNumber = function (name) {
			const match = String(name || '').match(/^(\d{1,3})[-_\s]/);
			return match && Number(match[1]) > 0 ? Number(match[1]) : null;
		};
		const suggestNumbers = function () {
			const rows = Array.from(form.querySelectorAll('[data-aaa-package-target]'));
			const groups = new Map();
			selectedFiles.forEach(function (file) {
				if (!uploadedIds.has(Number(file.id))) return;
				const number = leadingNumber(file.aaaOriginalName || file.filename);
				if (number === null) return;
				if (!groups.has(number)) groups.set(number, []);
				groups.get(number).push(file);
			});
			groups.forEach(function (files, number) {
				if (files.length !== 1) {
					files.forEach(function (file) {
						const select = choices.get(Number(file.id));
						if (select && select.dataset.aaaAutoTarget) {
							const target = rows.find(function (row) { return row.dataset.aaaPackageTarget === select.dataset.aaaAutoTarget; });
							const mode = target && target.querySelector('[data-aaa-placement-mode]');
							if (target && target.querySelector('[data-aaa-media-id]').value === String(file.id) && (!mode || mode.value === select.dataset.aaaAutoMode)) { select.value = ''; select.dispatchEvent(new Event('change', { bubbles: true })); }
						}
						if (select) select.options[0].textContent = '番号が重複：対応先を選んでください';
					});
					return;
				}
				const file = files[0], select = choices.get(Number(file.id));
				if (!select || select.value || select.dataset.aaaTouched) return;
				const matches = rows.filter(function (row) { return Number(row.dataset.aaaFileNumber) === number; });
				if (matches.length !== 1) return;
				const row = matches[0], mode = row.querySelector('[data-aaa-placement-mode]');
				if (Number(row.querySelector('[data-aaa-media-id]').value) || (mode && ['dummy', 'hold'].includes(mode.value))) return;
				if (!Array.from(select.options).some(function (option) { return option.value === row.dataset.aaaPackageTarget; })) return;
				select.value = row.dataset.aaaPackageTarget;
				select.dispatchEvent(new Event('change', { bubbles: true }));
				select.dataset.aaaAutoTarget = row.dataset.aaaPackageTarget;
				select.dataset.aaaAutoMode = mode ? mode.value : '';
				delete select.dataset.aaaTouched;
				row.querySelector('[data-aaa-mapping-state]').textContent = '番号一致で仮配置・未保存：この場所に配置します。サムネイルを確認してください。';
			});
		};
		const renderFiles = function (files) {

				const list = form.querySelector('[data-aaa-batch-selection]');
				if (!selectedFiles.length) list.textContent = '番号が一致した画像は下のカードに仮配置します。未配置の画像だけ対応先を選んでください。';
				const used = new Set(selectedFiles.map(function (file) { return Number(file.id); }));
				files.forEach(function (file) {
					if (used.has(Number(file.id))) return;
					used.add(Number(file.id)); selectedFiles.push(file);
					const p = document.createElement('div'); p.className = 'aaa-batch-file';
					previewImage(p, file);
					const label = document.createElement('label');
					const number = leadingNumber(file.aaaOriginalName || file.filename);
					label.textContent = number === null ? '番号なし → ' : 'ファイル ' + String(number).padStart(2, '0') + ' → '; label.title = file.filename || file.title || String(file.id);
					const select = document.createElement('select');
					choices.set(Number(file.id), select);
					const empty = document.createElement('option'); empty.value = ''; empty.textContent = '対応先を選ぶ（自動では保存しません）'; select.appendChild(empty);
					form.querySelectorAll('[data-aaa-package-target]').forEach(function (row) {
						const pdf = row.querySelector('[data-aaa-media-kind]').value === 'pdf';
						if (pdf ? file.mime !== 'application/pdf' : !['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.mime)) return;
						const option = document.createElement('option'); option.value = row.dataset.aaaPackageTarget; option.textContent = row.dataset.aaaPackageLabel + (file.filename === row.dataset.aaaSuggestedName ? '（名前一致候補・要確認）' : ''); select.appendChild(option);
					});
					let assignedRow = null;
					let previousId = '';
					let previousPreview = null, previousSelection = '', previousOrigin = '', previousState = '', previousMode = '';
					select.addEventListener('change', function () {
						select.dataset.aaaTouched = '1'; delete select.dataset.aaaAutoTarget;
						if (assignedRow) {
							const oldInput = assignedRow.querySelector('[data-aaa-media-id]');
							if (oldInput.value === String(file.id)) {
								oldInput.value = previousId; oldInput.dispatchEvent(new Event('change', { bubbles: true }));
								assignedRow.querySelector('[data-aaa-media-selection]').textContent = '対応先を選び直しました。現在のID欄を確認して保存してください。';
								const preview = assignedRow.querySelector('[data-aaa-media-preview]');
								if (preview) { preview.textContent = ''; if (previousPreview) preview.appendChild(previousPreview.cloneNode(true)); }
								assignedRow.querySelector('[data-aaa-media-selection]').textContent = previousSelection;
								const origin = assignedRow.querySelector('[data-aaa-media-origin]'); if (origin) origin.textContent = previousOrigin;
								const state = assignedRow.querySelector('[data-aaa-mapping-state]'); if (state) state.textContent = previousState;
								const mode = assignedRow.querySelector('[data-aaa-placement-mode]'); if (mode) mode.value = previousMode;
							}
							assignedRow = null;
						}
						const row = Array.from(form.querySelectorAll('[data-aaa-package-target]')).find(function (candidate) { return candidate.dataset.aaaPackageTarget === select.value; });
						if (!row) { p.classList.remove('is-assigned'); return; }
						list.querySelectorAll('select').forEach(function (other) {
							if (other !== select && other.value === select.value) { other.value = ''; other.dispatchEvent(new Event('change', { bubbles: true })); }
						});
						p.classList.add('is-assigned'); assignedRow = row; previousId = row.querySelector('[data-aaa-media-id]').value;
						previousPreview = row.querySelector('[data-aaa-media-preview] img');
						previousSelection = row.querySelector('[data-aaa-media-selection]').textContent;
						previousOrigin = row.querySelector('[data-aaa-media-origin]')?.textContent || '';
						previousState = row.querySelector('[data-aaa-mapping-state]')?.textContent || '';
						previousMode = row.querySelector('[data-aaa-placement-mode]')?.value || '';
						const input = row.querySelector('[data-aaa-media-id]'); input.value = String(file.id); input.dispatchEvent(new Event('change', { bubbles: true }));
						showSelection(row, file);
						row.querySelector('[data-aaa-media-selection]').textContent = '対応予定：' + (file.filename || file.id) + '（保存してプレビューを更新してください）';
					});
					label.appendChild(select); p.appendChild(label); list.appendChild(p);
				});
			suggestNumbers();
		};
		button.addEventListener('click', function () {
			const frame = window.wp.media({ title: '画像・PDFを登録して選択', button: { text: '選択したファイルの対応先を決める' }, library: { type: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'] }, multiple: true });
			frame.on('select', function () { renderFiles(frame.state().get('selection').toJSON()); });
			frame.open();
		});
		const zone = form.querySelector('[data-aaa-media-drop]');
		if (zone) {
			const status = zone.querySelector('[data-aaa-upload-status]');
			const errors = zone.querySelector('[data-aaa-upload-errors]');
			const nonce = form.querySelector('[name="aaa_media_upload_nonce"]');
			let busy = false, depth = 0;
			const report = function (message) { const p = document.createElement('p'); p.textContent = message; errors.appendChild(p); };
			form.addEventListener('submit', function (event) { if (busy) { event.preventDefault(); status.textContent = 'アップロードが終わるまで、この画面でお待ちください。'; } });
			zone.addEventListener('dragenter', function (event) { event.preventDefault(); ++depth; zone.classList.add('is-dragging'); });
			zone.addEventListener('dragover', function (event) { event.preventDefault(); if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy'; });
			zone.addEventListener('dragleave', function (event) { event.preventDefault(); if (--depth <= 0) { depth = 0; zone.classList.remove('is-dragging'); } });
			const uploadDrop = async function (event, targetRow) {
				event.preventDefault(); event.stopPropagation(); depth = 0; zone.classList.remove('is-dragging');
				if (busy) { report('追加処理中です。完了してから次のファイルをドロップしてください。'); return; }
				errors.textContent = '';
				const files = Array.from(event.dataTransfer && event.dataTransfer.files || []);
				if (targetRow && (files.length !== 1 || (targetRow.querySelector('[data-aaa-media-kind]').value === 'pdf' ? !/\.pdf$/i.test(files[0].name) : !/\.(jpe?g|png|gif|webp)$/i.test(files[0].name)))) { report('このカードに対応するファイル1点をドロップしてください。'); return; }
				if (!files.length || files.length > 20) { report('画像・PDFを1〜20点でドロップしてください。フォルダーやZIPは展開してから追加します。'); return; }
				if (!nonce || !nonce.value || typeof window.fetch !== 'function' || typeof window.FormData !== 'function') { report('直接アップロードを利用できません。選択ボタンから標準メディア画面をご利用ください。'); return; }
				const limit = Number(zone.dataset.aaaUploadLimit) || 0;
				const ready = files.filter(function (file) {
					if (!/\.(jpe?g|png|gif|webp|pdf)$/i.test(file.name) || (file.type && !['image/jpeg','image/png','image/gif','image/webp','application/pdf'].includes(file.type))) { report(file.name + '：対応する画像またはPDFを選んでください。'); return false; }
					if (!file.size || (limit && file.size > limit)) { report(file.name + '：空ファイル、またはサイトの容量上限を超えています。'); return false; }
					return true;
				});
				if (!ready.length) return;
				busy = true; zone.setAttribute('aria-busy', 'true');
				form.dispatchEvent(new Event('change', { bubbles: true }));
				let done = 0; const additions = [];
				try {
					for (let index = 0; index < ready.length; index++) {
						const file = ready[index]; status.textContent = '追加中 ' + (index + 1) + '/' + ready.length + '：' + file.name;
						try {
							const body = new window.FormData(); body.append('action', 'upload-attachment'); body.append('_wpnonce', nonce.value); body.append('async-upload', file, file.name);
							// WordPress core handles nonce, upload rights, real MIME and media registration.
							const response = await window.fetch(zone.dataset.aaaUploadUrl, { method: 'POST', credentials: 'same-origin', body: body });
							const result = await response.json();
							if (!response.ok || !result.success || !result.data || !Number.isSafeInteger(Number(result.data.id)) || Number(result.data.id) <= 0) throw new Error(result.data && result.data.message || '結果を確認できませんでした。標準メディアで登録状況を確認してください。');
							const attachment = result.data; attachment.aaaOriginalName = file.name; uploadedIds.add(Number(attachment.id)); ++done;
							if (targetRow) {
								const input = targetRow.querySelector('[data-aaa-media-id]'); input.value = String(attachment.id); input.dispatchEvent(new Event('change', { bubbles: true })); showSelection(targetRow, attachment); targetRow.querySelector('[data-aaa-media-selection]').textContent = attachment.filename || attachment.title || String(attachment.id);
							} else { additions.push(attachment); }
						} catch (error) { report(file.name + '：' + (error.message || '追加結果を確認できませんでした。') + ' 再試行する前に、標準メディアで登録状況をご確認ください。'); }
					}
				} finally {
					if (additions.length) renderFiles(additions);
					busy = false; zone.removeAttribute('aria-busy');
					status.textContent = done + '/' + ready.length + '点を追加しました。番号一致の仮配置をサムネイルで確認してください。未配置だけ選び、下のボタンでまとめてプレビューへ進みます。';
				}
			};
			zone.addEventListener('drop', function (event) { uploadDrop(event, null); });
			form.querySelectorAll('[data-aaa-package-target]').forEach(function (row) {
				row.addEventListener('dragover', function (event) { event.preventDefault(); row.classList.add('is-dragging'); });
				row.addEventListener('dragleave', function () { row.classList.remove('is-dragging'); });
				row.addEventListener('drop', function (event) { row.classList.remove('is-dragging'); uploadDrop(event, row); });
			});
		}
	});
	root.querySelectorAll('[data-aaa-media-pick]').forEach(function (button) {
		if (!window.wp || typeof window.wp.media !== 'function') return;
		button.hidden = false;
		button.addEventListener('click', function () {
			const row = button.closest('[data-aaa-media-row]');
			const kind = row.querySelector('[data-aaa-media-kind]').value;
			const frame = window.wp.media({ title: '今回の記事で使う資料を選択', button: { text: 'この資料を対応付ける' }, library: { type: kind === 'pdf' ? 'application/pdf' : 'image' }, multiple: false });
			frame.on('select', function () {
				const selected = frame.state().get('selection').first().toJSON();
				const input = row.querySelector('[data-aaa-media-id]');
				input.value = String(selected.id);
				input.dispatchEvent(new Event('change', { bubbles: true }));
				showSelection(row, selected);
				row.querySelector('[data-aaa-media-selection]').textContent = '選択中：' + (selected.filename || selected.title || selected.id) + '（対応表を保存してください）';
			});
			frame.open();
		});
	});
	root.querySelectorAll('[data-aaa-delete]').forEach(function (button) {
		button.closest('form').addEventListener('submit', function (event) {
			if (!window.confirm(button.dataset.aaaDelete)) event.preventDefault();
		});
	});
	const type = root.querySelector('#aaa-post-type');
	if (type) {
		const sync = function () {
			root.querySelectorAll('[data-aaa-types]').forEach(function (group) {
				const enabled = group.dataset.aaaTypes.split(' ').includes(type.value);
				group.hidden = !enabled;
				group.querySelectorAll('input').forEach(function (input) { input.disabled = !enabled; });
			});
		};
		type.addEventListener('change', sync);
		sync();
	}
	root.querySelectorAll('[data-aaa-term-filter]').forEach(function (search) {
		search.addEventListener('input', function () {
			const query = search.value.trim().toLocaleLowerCase();
			search.closest('fieldset').querySelectorAll('.aaa-term-choices label').forEach(function (label) {
				label.hidden = !label.textContent.toLocaleLowerCase().includes(query);
			});
		});
	});
	root.querySelectorAll('.aaa-form').forEach(function (form) {
		let dirty = false;
		const mark = function () {
			dirty = true;
			if (form.dataset && form.dataset.aaaOperation === 'article') {
				const warning = root.querySelector('[data-aaa-unsaved]');
				if (warning) warning.hidden = false;
				root.querySelectorAll('[data-aaa-generate], [data-aaa-copy]').forEach(function (button) { button.disabled = true; });
			}
		};
		form.addEventListener('input', mark);
		form.addEventListener('change', mark);
		form.addEventListener('submit', function (event) { if (!event.defaultPrevented) dirty = false; });
		window.addEventListener('beforeunload', function (event) {
			if (dirty) { event.preventDefault(); event.returnValue = ''; }
		});
	});
	root.querySelectorAll('form[data-aaa-operation="article"]').forEach(function (form) {
		form.addEventListener('invalid', function (event) {
			const details = event.target.closest('details');
			if (details) details.open = true;
		}, true);
	});
	const output = root.querySelector('#aaa-prompt-output');
	if (output) {
		root.querySelectorAll('[data-aaa-copy]').forEach(function (button) {
			button.hidden = false;
			button.addEventListener('click', async function () {
				const status = button.dataset.aaaCopyStatusTarget ? root.querySelector('#' + button.dataset.aaaCopyStatusTarget) : root.querySelector('[data-aaa-copy-status]');
				const target = button.dataset.aaaCopyTarget ? root.querySelector('#' + button.dataset.aaaCopyTarget) : output;
				if (!target) return;
				try {
					if (!window.isSecureContext || !window.navigator || !window.navigator.clipboard) throw new Error('manual');
					await window.navigator.clipboard.writeText(target.value);
					status.textContent = 'コピーしました。ChatGPTに貼り付けてください。';
				} catch (error) {
					const details = typeof target.closest === 'function' ? target.closest('details') : null;
					if (details) details.open = true;
					target.focus();
					target.select();
					status.textContent = '自動コピーを利用できません。選択された文章を手動でコピーしてください。';
				}
			});
		});
	}
}());
