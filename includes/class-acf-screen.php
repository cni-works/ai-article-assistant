<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AcfScreen {
	public function __construct() { add_action( 'admin_post_aaa_acf_save', array( $this, 'save' ) ); }
	public function save(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POSTで操作してください。', '', array( 'response' => 405 ) ); }
		check_admin_referer( 'aaa_acf_save', 'aaa_nonce' );
		$result = Acf::save_settings( wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 'ACF連携を保存できませんでした', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( Admin::url( 'acf', array( 'saved' => '1', 'post_type' => Store::text( wp_unslash( $_POST['post_type'] ?? '' ) ) ) ) ); exit;
	}
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'ACF連携を設定する権限がありません。', '', array( 'response' => 403 ) ); }
		$types = Store::types();
		$type = isset( $_GET['post_type'] ) && is_string( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		if ( ! isset( $types[ $type ] ) ) { echo '<p>利用できる投稿先を選んでください。</p>'; return; }
		echo '<p>投稿先ごとに、CSVから保存してよいACF項目を選びます。初期状態はすべて無効です。選んだ項目の定義だけを相談プロンプトへ渡し、既存記事の値は取得しません。</p><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( Config::SLUG . '-acf' ) . '"><label for="aaa-acf-type">投稿先</label> <select id="aaa-acf-type" name="post_type">';
		foreach ( $types as $slug => $object ) { echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $type, $slug, false ) . '>' . esc_html( $object->labels->name ) . '</option>'; }
		echo '</select> <button class="button">項目を表示</button></form><p>文字・数値・選択・真偽値・画像・PDF・グループを扱います。投稿タイプだけで表示条件が決まる有効なグループが対象です。条件付き項目、繰り返し、関連付け等は対象外です。グループを許可すると、その対応済みの子項目すべてを許可します。</p>';
		$schema = Acf::discover( $type ); $current = Acf::context( $type );
		if ( is_wp_error( $schema ) ) { echo '<p class="aaa-warning">' . esc_html( $schema->get_error_message() ) . '</p>'; $schema = array(); }
		if ( isset( $current['unavailable'] ) ) { echo '<p class="aaa-warning">' . esc_html( $current['unavailable'] ) . '</p>'; }
		$selected = Acf::settings()[ $type ]['fields'] ?? array();
		echo '<form class="aaa-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aaa_acf_save"><input type="hidden" name="post_type" value="' . esc_attr( $type ) . '"><input type="hidden" name="revision" value="' . esc_attr( Acf::revision() ) . '"><input type="hidden" name="schema_hash" value="' . esc_attr( hash( 'sha256', wp_json_encode( $schema ) ) ) . '">';
		wp_nonce_field( 'aaa_acf_save', 'aaa_nonce' );
		if ( ! $schema ) { echo '<p>対応する項目はありません。保存するとこの投稿先の許可を解除します。ACFがなくても通常の記事作成は利用できます。</p>'; }
		foreach ( $schema as $key => $field ) {
			echo '<details><summary><label><input type="checkbox" name="fields[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $selected, true ), true, false ) . '> ' . esc_html( $field['label'] . ' / ' . $field['type'] . ( $field['required'] ? ' / 必須' : '' ) ) . '</label></summary><p>CSVのキー：<code>' . esc_html( $key ) . '</code></p><pre class="aaa-json">' . esc_html( wp_json_encode( $field, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>';
		}
		echo '<p>最大60項目まで許可できます。公開してよい情報を保存する項目だけを選んでください。型・選択肢等をACF側で変更したら、ここで定義を確認して保存し直してください。サイズ・拡張子等の制限付きメディア項目は今回対象外です。</p>'; submit_button( '許可するACF項目を保存' ); echo '</form>';
	}
}
