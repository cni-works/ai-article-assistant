<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ReferenceScreen {
	public function __construct() {
		foreach ( array( 'settings', 'batch', 'select' ) as $action ) { add_action( 'admin_post_aaa_reference_' . $action, array( $this, $action ) ); }
	}
	private function guard( string $action ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POSTで操作してください。', '', array( 'response' => 405 ) ); }
		check_admin_referer( 'aaa_reference_' . $action, 'aaa_nonce' );
		if ( ! Reference::allowed() || ( 'select' !== $action && ! current_user_can( 'manage_options' ) ) ) { wp_die( 'この操作を利用する権限がありません。', '', array( 'response' => 403 ) ); }
	}
	private function finish( $result ): void {
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '既存記事の確認を保存できませんでした', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( Admin::url( 'references', array( 'saved' => '1' ) ) ); exit;
	}
	public function settings(): void { $this->guard( 'settings' ); $this->finish( Reference::save_settings( wp_unslash( $_POST ) ) ); }
	public function batch(): void { $this->guard( 'batch' ); $this->finish( Reference::batch( '1' === ( $_POST['restart'] ?? '' ), Store::text( wp_unslash( $_POST['revision'] ?? '' ) ) ) ); }
	public function select(): void { $this->guard( 'select' ); $this->finish( Reference::save_selection( wp_unslash( $_POST ) ) ); }
	private static function form( string $action ): void {
		echo '<form class="aaa-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aaa_reference_' . esc_attr( $action ) . '">'; wp_nonce_field( 'aaa_reference_' . $action, 'aaa_nonce' );
	}
	private static function get( string $name, string $default = '' ): string { return is_string( $_GET[ $name ] ?? null ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : $default; }
	private static function admin_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$settings = Reference::settings(); $progress = Reference::progress();
		echo '<details><summary>管理者：参照範囲と索引作成</summary><p>投稿先とは別に、比較に使う投稿タイプを指定します。公開済み・パスワードなしの記事のみ対象です。外部AIへ自動送信しません。会員限定表示等を別プラグインで設定している記事は、選択内容を確認して除外してください。参照設定・索引・選択内容は設定移行JSONに含みません。</p>';
		self::form( 'settings' ); echo '<input type="hidden" name="revision" value="' . esc_attr( Reference::hash( $settings ) ) . '"><label>サイトの既存記事確認方針 <select name="policy">';
		foreach ( array( 'off' => '使わない', 'optional' => '任意で確認する', 'required' => '執筆前に確認する' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $settings['policy'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><p>記事の種類ごとに変更できます。ただしサイト全体で無効の場合は利用できません。</p>';
		foreach ( Reference::types() as $type => $object ) { echo '<p><label><input type="checkbox" name="types[]" value="' . esc_attr( $type ) . '" ' . checked( in_array( $type, $settings['types'], true ), true, false ) . '> ' . esc_html( $object->labels->name ) . '</label></p>'; }
		submit_button( '参照範囲を保存' ); echo '</form>';
		if ( 'off' !== $settings['policy'] && $settings['types'] ) {
			echo '<p>索引は初回50件ずつ作成します。公開記事の保存時にも更新します。大きなサイトでは「次の50件」を繰り返してください。同時に大量の記事を追加・削除した場合は最初から再作成してください。完了は索引作成範囲の完了で、内容の比較完了ではありません。</p><p>作成状況：' . esc_html( ! $progress || ( $progress['scope'] ?? '' ) !== Reference::hash( $settings ) ? '未作成・参照範囲変更後の再作成待ち' : ( ! empty( $progress['complete'] ) ? '一巡完了' : '作成途中' ) . ' / 処理件数 ' . ( $progress['processed'] ?? 0 ) . ' / ' . ( $progress['updated_at'] ?? '' ) ) . '</p>';
			self::form( 'batch' ); echo '<input type="hidden" name="revision" value="' . esc_attr( Reference::hash( array( $settings, $progress ) ) ) . '"><input type="hidden" name="restart" value="1">'; submit_button( '最初の50件から索引を作成する' ); echo '</form>';
			if ( $progress && empty( $progress['complete'] ) && ( $progress['scope'] ?? '' ) === Reference::hash( $settings ) ) { self::form( 'batch' ); echo '<input type="hidden" name="revision" value="' . esc_attr( Reference::hash( array( $settings, $progress ) ) ) . '">'; submit_button( '次の50件を作成する' ); echo '</form>'; }
		}
		echo '</details>';
	}
	public static function render(): void {
		if ( ! Reference::allowed() ) { wp_die( '既存記事を参照する権限がありません。' ); }
		self::admin_settings();
		if ( 'off' === Reference::policy() ) { echo '<p>既存記事の参照は無効です。管理者が参照範囲と方針を設定してください。現在の記事の種類で無効にしている場合もあります。</p>'; return; }
		$article = Store::article();
		if ( empty( $article['article_key'] ) ) { echo '<p>先に「記事を作成」でテーマと今回の記事条件を保存してください。企画相談の場合は「この分野で次に書く記事を相談」等でも構いません。</p>'; return; }
		$query = self::get( 'q', Reference::clip( $article['theme'] ?? '', 100 ) ); $type = self::get( 'ref_type' ); $category = self::get( 'ref_category' ); $page_raw = self::get( 'ref_page', '1' ); $page = ctype_digit( $page_raw ) ? (int) $page_raw : 0;
		echo '<p>現在の記事：' . esc_html( $article['theme'] ?? '' ) . '。関連しそうな記事を最大5件選び、見出し・抜粋だけか、抽出本文も渡すかを確認します。検索語は短い語句へ変えて検索できます。空欄なら新しい記事から一覧表示します。</p><p class="aaa-warning">意味的な重複判定ではありません。未索引・対象外・検索語の表記違い等で見つからない記事があります。候補が0件でも重複なしとは判断しません。本文以外のACF・PDF・画像内文字は比較できません。</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( Config::SLUG . '-references' ) . '"><p><label>検索語 <input name="q" value="' . esc_attr( $query ) . '"></label> <label>参照タイプ <select name="ref_type"><option value="">すべて</option>';
		foreach ( Reference::settings()['types'] as $slug ) { echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $type, $slug, false ) . '>' . esc_html( Reference::types()[ $slug ]->labels->name ) . '</option>'; }
		echo '</select></label> <label>分類 <select name="ref_category"><option value="">すべて</option>';
		$shown = array(); foreach ( Reference::settings()['types'] as $slug ) { foreach ( get_object_taxonomies( $slug, 'objects' ) as $tax => $object ) { if ( isset( $shown[ $tax ] ) || empty( $object->public ) ) { continue; } $shown[ $tax ] = true; $terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 100 ) ); if ( is_wp_error( $terms ) ) { continue; } foreach ( $terms as $term ) { $value = $tax . ':' . $term->term_id; echo '<option value="' . esc_attr( $value ) . '" ' . selected( $category, $value, false ) . '>' . esc_html( $term->name . ' / ' . $tax ) . '</option>'; } } }
		echo '</select></label> <button class="button">候補を検索</button></p><p><label>追加で確認する投稿ID <input name="manual_id" inputmode="numeric" value="' . esc_attr( self::get( 'manual_id' ) ) . '"></label> <button class="button">IDを確認</button>（分類の選択肢は各100件まで）</p></form>';
		$result = Reference::search( $query, $page, $type, $category ); if ( is_wp_error( $result ) ) { echo '<p class="aaa-warning">' . esc_html( $result->get_error_message() ) . '</p>'; return; }
		$saved = Reference::selection(); $selected = array(); foreach ( $saved['items'] ?? array() as $item ) { if ( ( $saved['article_key'] ?? '' ) === $article['article_key'] ) { $selected[ $item['id'] ] = $item['mode']; } }
		$items = array(); foreach ( $result['items'] as $item ) { $items[ $item['id'] ] = $item; }
		foreach ( $selected as $id => $mode ) { if ( ! isset( $items[ $id ] ) ) { $item = Reference::material( (int) $id ); if ( $item ) { $item['reasons'] = array( '前回の選択' ); $items[ $id ] = $item; } } }
		$manual = self::get( 'manual_id' ); if ( '' !== $manual ) { $item = preg_match( '/^[1-9][0-9]{0,9}$/D', $manual ) ? Reference::material( (int) $manual ) : null; if ( $item ) { $item['reasons'] = array( 'IDを指定して追加' ); $items[ $item['id'] ] = $item; } else { echo '<p class="aaa-warning">指定IDは公開・参照範囲内の記事として利用できません。</p>'; } }
		echo '<p>索引の検索候補：' . esc_html( $result['found'] ) . '件 / ' . esc_html( $page ) . 'ページ目。保存済みの選択とID指定の記事を追加表示する場合があります。</p>';
		foreach ( array( $page - 1 => '前のページ', $page + 1 => '次のページ' ) as $number => $label ) { if ( $number >= 1 && $number <= $result['pages'] ) { echo '<a class="button" href="' . esc_url( Admin::url( 'references', array( 'q' => $query, 'ref_type' => $type, 'ref_category' => $category, 'ref_page' => $number ) ) ) . '">' . esc_html( $label ) . '</a> '; } }
		$context = Reference::context(); if ( is_wp_error( $context ) ) { echo '<p class="aaa-warning">' . esc_html( $context->get_error_message() ) . '</p>'; }
		self::form( 'select' );
		foreach ( array( 'revision' => Reference::revision(), 'query' => $query, 'type' => $type, 'category' => $category ) as $name => $value ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">'; }
		echo '<p><label>相談内容 <select name="intent"><option value="compare" ' . selected( $saved['intent'] ?? '', 'compare', false ) . '>今回のテーマを新規・追記・別の切り口で比較</option><option value="ideas" ' . selected( $saved['intent'] ?? '', 'ideas', false ) . '>選んだ記事を踏まえて次の記事を提案</option></select></label></p>';
		foreach ( $items as $id => $item ) {
			$material = $item; unset( $material['reasons'] ); echo '<input type="hidden" name="hashes[' . esc_attr( $id ) . ']" value="' . esc_attr( Reference::hash( $material ) ) . '">';
			echo '<details><summary><label><input type="checkbox" name="ids[]" value="' . esc_attr( $id ) . '" ' . checked( isset( $selected[ $id ] ), true, false ) . '> ' . esc_html( $item['title'] . '（ID ' . $id . '）' ) . '</label></summary><p><a href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener noreferrer">元の記事を開く</a> / 公開 ' . esc_html( $item['published_at'] ) . ' / 更新 ' . esc_html( $item['modified_at'] ) . '</p><p>候補の理由：' . esc_html( implode( '、', $item['reasons'] ) ) . '</p><label>AIへ渡す範囲 <select name="details[' . esc_attr( $id ) . ']"><option value="summary" ' . selected( $selected[ $id ] ?? '', 'summary', false ) . '>タイトル・見出し・抜粋等</option><option value="body" ' . selected( $selected[ $id ] ?? '', 'body', false ) . '>抽出本文も含める（最大8000文字）</option></select></label><p>分類：' . esc_html( wp_json_encode( $item['terms'], JSON_UNESCAPED_UNICODE ) ) . '</p><p>見出し：' . esc_html( implode( ' / ', $item['headings'] ) ) . '</p><p>抜粋：' . esc_html( $item['excerpt'] ) . '</p><details><summary>本文を渡す場合の内容を確認</summary><pre class="aaa-json">' . esc_html( $item['text'] ) . '</pre></details><p>' . esc_html( $item['extraction'] . ( $item['body_truncated'] ? ' 本文は上限で省略しています。' : '' ) . ( $item['unsupported_blocks_omitted'] ? ' 対象外のブロックを省略しています。' : '' ) ) . '</p></details>';
		}
		echo '<p>ページを移動する前に選択を保存すると、次のページでも選択済みの記事が表示されます。選択なしでの確認保存も可能です。</p><p><label><input type="checkbox" name="confirmed" value="1" required> 検索範囲とAIへ渡す記事内容を確認しました</label></p>'; submit_button( '今回の参照記事を保存' ); echo '</form><p>保存後は<a href="' . esc_url( Admin::url( '', array( 'step' => '4' ) ) ) . '">確認・プロンプト出力へ戻り、相談プロンプトを生成</a>してください。既存記事への追記を選ぶ場合の編集は標準エディターで行います。</p>';
	}
}
