<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TransferScreen {
	public function __construct() {
		foreach ( array( 'preview', 'commit', 'download' ) as $action ) { add_action( 'admin_post_aaa_transfer_' . $action, array( $this, $action ) ); }
	}
	private function guard( string $action ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POSTで操作してください。', '', array( 'response' => 405 ) ); }
		check_admin_referer( 'aaa_transfer_' . $action, 'aaa_nonce' );
		if ( ! Transfer::allowed() ) { wp_die( '設定移行には管理者権限が必要です。', '', array( 'response' => 403 ) ); }
	}
	private function finish( $result ): void {
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '設定移行を完了できませんでした', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( Admin::url( 'transfer' ) ); exit;
	}
	public function preview(): void {
		$this->guard( 'preview' );
		$json = '';
		if ( isset( $_FILES['settings_file'] ) && UPLOAD_ERR_NO_FILE !== ( $_FILES['settings_file']['error'] ?? null ) ) {
			$file = $_FILES['settings_file'];
			if ( UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) || ! is_string( $file['name'] ?? null ) || 'json' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) { $this->finish( new \WP_Error( 'file', '設定JSONファイルを選び直してください。' ) ); }
			// Do not trust the browser-reported size; never store this file in the media library.
			$source = file_get_contents( $file['tmp_name'], false, null, 0, Transfer::LIMIT + 1 );
			if ( false === $source ) { $this->finish( new \WP_Error( 'file', '設定ファイルを読み取れませんでした。' ) ); }
			$json = $source;
		} else { $json = is_string( $_POST['settings_json'] ?? null ) ? wp_unslash( $_POST['settings_json'] ) : ''; }
		$this->finish( Transfer::preview( $json ) );
	}
	public function commit(): void {
		$this->guard( 'commit' );
		if ( '1' !== ( $_POST['confirmed'] ?? '' ) ) { $this->finish( new \WP_Error( 'confirm', '変更内容と移行範囲を確認してください。' ) ); }
		$this->finish( Transfer::commit( Store::text( wp_unslash( $_POST['token'] ?? '' ) ) ) );
	}
	public function download(): void {
		$this->guard( 'download' );
		$backup = 'backup' === ( $_POST['kind'] ?? '' );
		$record = get_option( Transfer::BACKUP, array() );
		$json = $backup ? ( is_array( $record ) && is_string( $record['json'] ?? null ) ? $record['json'] : new \WP_Error( 'backup', '適用前の控えがありません。' ) ) : Transfer::export();
		if ( is_wp_error( $json ) ) { $this->finish( $json ); }
		nocache_headers(); header( 'Content-Type: application/json; charset=UTF-8' ); header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="aaa-settings-' . ( $backup ? 'before-import-' : '' ) . gmdate( 'Ymd-His' ) . '.json"' );
		// This response is a JSON download, not HTML.
		echo $json; exit;
	}
	private static function form( string $action ): void {
		echo '<form class="aaa-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aaa_transfer_' . esc_attr( $action ) . '">'; wp_nonce_field( 'aaa_transfer_' . $action, 'aaa_nonce' );
	}
	public static function render(): void {
		if ( ! Transfer::allowed() ) { wp_die( '設定移行には管理者権限が必要です。' ); }
		echo '<p>サイト共通設定・記事の種類・有効な投稿先をJSONで移行します。個人設定・記事・カテゴリー別設定・画像/PDFの対応表・ACF連携・既存記事の参照範囲/索引/選択は含みません。記事の種類ごとの既存記事確認方針は含みます。</p><p>サイト共通設定と投稿先は置き換えます。記事の種類はIDが同じものを更新し、それ以外を追加します。ファイルにない既存記事の種類は残します。同名でもIDが違えば別項目です。URL・団体名・連絡先は移行元の内容になるため、適用前に確認してください。</p>';
		self::form( 'download' ); submit_button( '現在の共有設定をJSONで保存' ); echo '</form>';
		$backup = get_option( Transfer::BACKUP, array() );
		if ( is_array( $backup ) && isset( $backup['json'] ) ) {
			echo '<p>直近の適用前の控え：' . esc_html( $backup['created_at'] ?? '' ) . '。再インポートで元の値を戻せますが、移行で新規追加した記事の種類は自動削除されません。</p>';
			self::form( 'download' ); echo '<input type="hidden" name="kind" value="backup">'; submit_button( '適用前の控えをJSONで保存' ); echo '</form>';
		}
		echo '<h2>設定の読み込み</h2><p>UTF-8・1MB以下・記事の種類100件まで。JSONファイルを優先し、未選択なら貼り付けた内容を使います。読み込み後に適用前後の値を確認します。未入力の設定項目は初期値になります。保存ファイルにはサイト共通情報が含まれます。</p>';
		self::form( 'preview' ); echo '<p><label>設定JSONファイル <input type="file" name="settings_file" accept=".json,application/json"></label></p><label for="aaa-settings-json">またはJSONを貼り付け</label><textarea id="aaa-settings-json" class="large-text code" name="settings_json" rows="8"></textarea>'; submit_button( '変更内容を確認' ); echo '</form>';
		$stage = Transfer::session(); if ( ! $stage ) { return; }
		if ( 'complete' === $stage['state'] ) { echo '<p class="notice notice-success">設定を適用しました。サイト共通設定・記事の種類・投稿先を確認してください。</p>'; return; }
		if ( 'preview' !== $stage['state'] ) { echo '<p class="aaa-warning">この確認は実行済みです。各設定画面と適用前の控えで結果を確認してください。</p>'; return; }
		echo '<h2>適用前後の設定（確認は30分間有効）</h2><p>既存のカテゴリー・記事が参照する同じIDの記事の種類にも変更が反映されます。投稿先を外すと、その投稿先への新たな取り込みはできなくなります。</p>';
		foreach ( array( Config::SITE => 'サイト共通設定', Config::PROFILES => '記事の種類（IDで照合）', Config::DESTINATIONS => '有効な投稿先' ) as $key => $label ) {
			echo '<details open><summary>' . esc_html( $label ) . ( $stage['before'][ $key ] === $stage['after'][ $key ] ? '：変更なし' : '：変更あり' ) . '</summary><label>適用前<textarea class="large-text code" readonly rows="6">' . esc_textarea( wp_json_encode( $stage['before'][ $key ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</textarea></label><label>適用後<textarea class="large-text code" readonly rows="6">' . esc_textarea( wp_json_encode( $stage['after'][ $key ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</textarea></label></details>';
		}
		self::form( 'commit' ); echo '<input type="hidden" name="token" value="' . esc_attr( $stage['token'] ) . '"><p><label><input type="checkbox" name="confirmed" value="1" required> 置き換える設定と記事の種類への影響を確認しました</label></p>'; submit_button( '控えを保存して設定を適用する' ); echo '</form>';
	}
}
