(function () {
	'use strict';
	const root = document.querySelector('.aaa-admin');
	if (!root) return;
	const source = root.querySelector('[data-aaa-profile-config]');
	const profile = root.querySelector('#aaa-profile');
	if (!source || !profile) return;
	let config;
	try { config = JSON.parse(source.textContent); } catch (_) { return; }
	const type = root.querySelector('#aaa-post-type');
	const container = root.querySelector('[data-aaa-profile-questions]');
	const adopt = root.querySelector('[name="data[adopt_settings]"]');
	const answers = new Map();
	let previous = (adopt && adopt.checked ? 'latest:' : 'current:') + profile.value;
	const remember = function () {
		const values = Object.create(null);
		container.querySelectorAll('[data-aaa-answer]').forEach(function (input) { values[input.dataset.aaaAnswer] = input.value; });
		answers.set(previous, values);
	};
	const update = function (applyDefaults) {
		remember();
		const data = (adopt && adopt.checked ? config.latest : config.profiles)[profile.value];
		if (!data) return;
		previous = (adopt && adopt.checked ? 'latest:' : 'current:') + profile.value;
		for (const [selector, key] of [['[data-aaa-profile-description]', 'description'], ['[data-aaa-profile-purpose]', 'purpose'], ['[data-aaa-overview-help]', 'overview_help'], ['[data-aaa-materials-hint]', 'materials_hint']]) {
			root.querySelector(selector).textContent = data[key] || '';
		}
		const values = answers.get(previous) || Object.create(null);
		container.replaceChildren();
		data.questions.forEach(function (question) {
			const p = document.createElement('p');
			const label = document.createElement('label');
			label.htmlFor = 'aaa-answer-' + question.key;
			label.textContent = question.label + (question.required ? '（執筆前に確認）' : '');
			p.append(label, document.createElement('br'));
			const input = document.createElement(question.type === 'select' ? 'select' : question.type === 'textarea' ? 'textarea' : 'input');
			input.id = label.htmlFor;
			input.name = 'data[answers][' + question.key + ']';
			input.dataset.aaaAnswer = question.key;
			if (question.type === 'select') {
				const empty = document.createElement('option'); empty.value = ''; empty.textContent = 'AIと相談する・未回答'; input.append(empty);
				question.options.forEach(function (value) { const option = document.createElement('option'); option.value = value; option.textContent = value; input.append(option); });
			} else { input.className = question.type === 'textarea' ? 'large-text' : 'regular-text'; input.maxLength = 10000; if (question.type === 'textarea') input.rows = 2; }
			input.value = values[question.key] || '';
			p.append(input);
			const help = document.createElement('span'); help.className = 'description'; help.textContent = question.help || ''; p.append(help);
			container.append(p);
		});
		const rules = root.querySelector('[data-aaa-profile-rules]'); rules.replaceChildren();
		Object.entries(data.rules).forEach(function ([key, value]) {
			const field = config.rules[key]; if (!field) return;
			const title = document.createElement('dt'); title.textContent = field[0];
			const detail = document.createElement('dd'); detail.textContent = field[4] && field[4][value] !== undefined ? field[4][value] : String(value);
			rules.append(title, detail);
		});
		if (type && type.options) {
			Array.from(type.options).forEach(function (option) { option.disabled = Boolean(data.default_post_type) && option.value !== data.default_post_type; });
		}
		if (applyDefaults && type) {
			if (data.default_post_type) type.value = data.default_post_type;
			type.dispatchEvent(new Event('change', { bubbles: true }));
			root.querySelectorAll('input[name="data[terms][]"]').forEach(function (input) { input.checked = !input.disabled && data.default_terms.includes(input.value); });
		}
	};
	profile.addEventListener('change', function () { update(true); });
	if (adopt) adopt.addEventListener('change', function () { update(true); });
	update(false);
}());
