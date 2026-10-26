(function () {
	'use strict';
	wp.domReady(function () {
		const ids = new Set((window.aaaDummyIds || []).map(Number));
		let shown = false;
		const scan = function (blocks) { return blocks.some(function (block) { return (block.name === 'core/image' && ids.has(Number(block.attributes.id))) || scan(block.innerBlocks || []); }); };
		const update = function () {
			const editor = wp.data.select('core/editor');
			const blocks = wp.data.select('core/block-editor');
			if (!editor || !blocks) return;
			const remaining = scan(blocks.getBlocks()) || ids.has(Number(editor.getEditedPostAttribute('featured_media')));
			if (remaining === shown) return;
			shown = remaining;
			if (remaining) wp.data.dispatch('core/notices').createNotice('warning', 'ダミー画像が残っています。画像ブロックの「置換」やアイキャッチ設定で差し替え、公開前に確認してください。公開操作は妨げません。', { id: 'aaa-dummy-warning', isDismissible: true });
			else wp.data.dispatch('core/notices').removeNotice('aaa-dummy-warning');
		};
		wp.data.subscribe(update);
		update();
	});
}());
